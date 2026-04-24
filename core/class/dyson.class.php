<?php
/* Plugin Dyson pour Jeedom
 * Contrôle MQTT local des appareils Dyson avec découverte cloud automatique
 */

class dyson extends eqLogic {

    /* ================================================================
     * Constantes
     * ================================================================ */

    const PRODUCT_TYPES = array(
        '358'  => array('label' => 'Pure Humidify+Cool PH01',          'hot' => false, 'humid' => true,  'robot' => false),
        '358E' => array('label' => 'Pure Humidify+Cool Formaldehyde',   'hot' => false, 'humid' => true,  'robot' => false),
        '438'  => array('label' => 'Pure Cool Tower TP04',              'hot' => false, 'humid' => false, 'robot' => false),
        '438E' => array('label' => 'Pure Cool Tower TP07',              'hot' => false, 'humid' => false, 'robot' => false),
        '455'  => array('label' => 'Pure Hot+Cool Link HP02',           'hot' => true,  'humid' => false, 'robot' => false),
        '469'  => array('label' => 'Pure Cool Me BP01',                 'hot' => false, 'humid' => false, 'robot' => false),
        '475'  => array('label' => 'Pure Cool Fan DP04',                'hot' => false, 'humid' => false, 'robot' => false),
        '520'  => array('label' => 'Pure Cool+ TP02',                   'hot' => false, 'humid' => false, 'robot' => false),
        '527'  => array('label' => 'Pure Hot+Cool HP04',                'hot' => true,  'humid' => false, 'robot' => false),
        '664'  => array('label' => 'Purifier Hot+Cool HP09',            'hot' => true,  'humid' => false, 'robot' => false),
        '666'  => array('label' => 'Purifier Cool TP09',                'hot' => false, 'humid' => false, 'robot' => false),
        '677'  => array('label' => 'Purifier Humidify+Cool PH04',       'hot' => false, 'humid' => true,  'robot' => false),
        'N223' => array('label' => 'Purifier Hot+Cool Formaldehyde HP07','hot' => true,  'humid' => false, 'robot' => false),
        '2PQ'  => array('label' => '360 Heurist (Robot aspirateur)',    'hot' => false, 'humid' => false, 'robot' => true),
        '276'  => array('label' => '360 Eye (Robot aspirateur)',        'hot' => false, 'humid' => false, 'robot' => true),
    );

    /* ================================================================
     * Démon
     * ================================================================ */

    public static function deamon_info() {
        $return = array('log' => __CLASS__, 'state' => 'nok', 'launchable' => 'nok');
        $pid_file = jeedom::getTmpFolder('dyson') . '/deamon.pid';
        if (file_exists($pid_file)) {
            if (@posix_getsid(trim(file_get_contents($pid_file)))) {
                $return['state'] = 'ok';
            } else {
                shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 &');
            }
        }
        $return['launchable'] = 'ok';
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $path    = realpath(dirname(__FILE__) . '/../../resources/dysonMqtt');
        $venv    = dirname(dirname(dirname(__FILE__))) . '/resources/python_venv/bin/python3';
        $python3 = file_exists($venv) ? $venv : 'python3';
        $port    = config::byKey('socketport', 'dyson', '55005');

        $cmd  = $python3 . ' ' . $path . '/dysonMqtt.py';
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('dyson'));
        $cmd .= ' --socketport ' . $port;
        $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/dyson/core/php/dyson.inc.php';
        $cmd .= ' --apikey ' . config::byKey('api');
        $cmd .= ' --pid ' . jeedom::getTmpFolder('dyson') . '/deamon.pid';

        log::add('dyson', 'info', 'Lancement démon : ' . $cmd);
        exec($cmd . ' >> ' . log::getPathToLog('dyson') . ' 2>&1 &');

        for ($i = 0; $i < 20; $i++) {
            sleep(1);
            if (self::deamon_info()['state'] === 'ok') {
                break;
            }
        }
        if (self::deamon_info()['state'] !== 'ok') {
            log::add('dyson', 'error', 'Démon non démarré après 20s');
            return false;
        }
        foreach (eqLogic::byType('dyson', true) as $eq) {
            $eq->sendDeviceToDaemon();
        }
        return true;
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder('dyson') . '/deamon.pid';
        if (file_exists($pid_file)) {
            system::kill(intval(trim(file_get_contents($pid_file))));
        }
        system::kill('dysonMqtt.py');
        sleep(1);
    }

    /* ================================================================
     * Dépendances
     * ================================================================ */

    public static function dependancy_install() {
        log::clear('dyson_update');
        $return = array(
            'progress_file' => jeedom::getTmpFolder('dyson') . '/dependency',
            'state'         => 'ok',
        );
        $script  = dirname(dirname(dirname(__FILE__))) . '/resources/install_dep.sh';
        $logFile = log::getPathToLog('dyson_update');
        exec('sudo /bin/bash ' . escapeshellarg($script) . ' >> ' . $logFile . ' 2>&1 &');
        return $return;
    }

    public static function dependancy_info() {
        $return = array(
            'progress_file' => jeedom::getTmpFolder('dyson') . '/dependency',
            'state'         => 'nok',
        );
        $venv = dirname(dirname(dirname(__FILE__))) . '/resources/python_venv/bin/python3';
        if (!file_exists($venv)) {
            return $return;
        }
        exec(escapeshellarg($venv) . ' -c "import paho.mqtt.client, requests" 2>&1', $out, $rc);
        if ($rc === 0) {
            $return['state'] = 'ok';
        }
        return $return;
    }

    /* ================================================================
     * Cron
     * ================================================================ */

    public static function cron5() {
        foreach (eqLogic::byType('dyson', true) as $eq) {
            try { $eq->requestCurrentState(); } catch (Exception $e) {
                log::add('dyson', 'error', 'Cron : ' . $e->getMessage());
            }
        }
    }

    /* ================================================================
     * Découverte cloud Dyson (via libdyson Python)
     * ================================================================ */

    /**
     * Étape 1 : initie l'authentification Dyson via libdyson.
     * Envoie un OTP par email et retourne array('type'=>'otp', 'challengeId'=>'...').
     */
    public static function authInit($_email, $_password, $_country = 'FR') {
        $output = self::runDiscoverPy(array(
            '--action',   'auth_init',
            '--email',    $_email,
            '--password', $_password,
            '--country',  $_country,
        ));

        log::add('dyson', 'debug', 'authInit réponse Python : ' . $output);

        $result = json_decode($output, true);
        if (!is_array($result)) {
            throw new Exception('Réponse Python invalide : ' . $output);
        }
        if (!empty($result['error'])) {
            throw new Exception($result['message']);
        }
        if (empty($result['challengeId'])) {
            throw new Exception('challengeId absent de la réponse : ' . $output);
        }
        return array('type' => 'otp', 'challengeId' => $result['challengeId']);
    }

    /**
     * Étape 2 : valide l'OTP via libdyson et crée/met à jour les équipements.
     */
    public static function authVerify($_email, $_password, $_country, $_challengeId, $_otp) {
        $output = self::runDiscoverPy(array(
            '--action', 'auth_verify',
            '--otp',    trim($_otp),
        ));

        log::add('dyson', 'debug', 'authVerify réponse Python : ' . $output);

        $result = json_decode($output, true);
        if (!is_array($result)) {
            throw new Exception('Réponse Python invalide : ' . $output);
        }
        if (!empty($result['error'])) {
            throw new Exception($result['message']);
        }
        if (!isset($result['devices'])) {
            throw new Exception('Liste d\'appareils absente : ' . $output);
        }
        return self::processDiscoveredDevices($result['devices']);
    }

    /**
     * Crée ou met à jour les eqLogics à partir de la liste retournée par discover.py.
     */
    private static function processDiscoveredDevices(array $devices) {
        $result = array('created' => 0, 'updated' => 0, 'skipped' => 0);
        log::add('dyson', 'info', count($devices) . ' appareil(s) trouvé(s) dans le compte Dyson');

        foreach ($devices as $device) {
            if (empty($device['serial']) || empty($device['product_type'])) {
                log::add('dyson', 'warning', 'Appareil ignoré : ' . json_encode($device));
                $result['skipped']++;
                continue;
            }

            $serial      = $device['serial'];
            $productType = $device['product_type'];
            $name        = !empty($device['name']) ? $device['name'] : 'Dyson ' . $serial;
            $credentials = $device['credentials'] ?? '';

            $existing = eqLogic::byLogicalId($serial, 'dyson');
            $isNew    = !is_object($existing);
            $eq       = $isNew ? new dyson() : $existing;

            $eq->setName($name);
            $eq->setLogicalId($serial);
            $eq->setEqType_name('dyson');
            $eq->setIsEnable(1);
            $eq->setIsVisible(1);
            $eq->setConfiguration('serial_number', $serial);
            $eq->setConfiguration('product_type',  $productType);
            $eq->setConfiguration('mqtt_username',  $serial);
            $eq->setConfiguration('mqtt_password',  $credentials);
            if ($isNew || !$eq->getConfiguration('mqtt_port')) {
                $eq->setConfiguration('mqtt_port', 1883);
                $eq->setConfiguration('use_tls',   false);
            }
            $eq->save();

            $status = $isNew ? 'created' : 'updated';
            $result[$status]++;
            log::add('dyson', 'info', 'Appareil ' . $serial . ' (' . $productType . ') : ' . $status);
        }
        return $result;
    }

    /**
     * Exécute discover.py avec les arguments donnés et retourne la sortie stdout+stderr.
     */
    private static function runDiscoverPy(array $args) {
        $venv   = dirname(dirname(dirname(__FILE__))) . '/resources/python_venv/bin/python3';
        $python = file_exists($venv) ? $venv : 'python3';
        $script = dirname(dirname(dirname(__FILE__))) . '/resources/dysonMqtt/discover.py';

        if (!file_exists($script)) {
            throw new Exception('Script discover.py introuvable : ' . $script);
        }

        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        log::add('dyson', 'debug', 'Appel discover.py : python ' . implode(' ', $args));
        $output = shell_exec($cmd . ' 2>&1');

        if ($output === null) {
            throw new Exception('Exécution de discover.py échouée (aucune sortie)');
        }
        return trim($output);
    }

    /* ================================================================
     * Cycle de vie équipement
     * ================================================================ */

    public function postSave() {
        $this->createDefaultCommands();
        if (self::deamon_info()['state'] === 'ok') {
            $this->sendDeviceToDaemon();
        }
    }

    public function preRemove() {
        if (self::deamon_info()['state'] === 'ok') {
            $this->sendToDaemon(array('cmd' => 'remove', 'device_id' => $this->getId()));
        }
    }

    /* ================================================================
     * Création automatique des commandes
     * ================================================================ */

    public function createDefaultCommands() {
        $pt       = $this->getConfiguration('product_type', '');
        $typeMeta = self::PRODUCT_TYPES[$pt] ?? array('hot' => false, 'humid' => false, 'robot' => false);

        if ($typeMeta['robot']) {
            $this->buildCommands($this->robotCommands());
        } else {
            $cmds = $this->fanPurifierCommands();
            if ($typeMeta['hot'])   $cmds = array_merge($cmds, $this->hotCoolCommands());
            if ($typeMeta['humid']) $cmds = array_merge($cmds, $this->humidifierCommands());
            $this->buildCommands($cmds);
        }
    }

    private function fanPurifierCommands() {
        return array(
            // ── État opérationnel ─────────────────────────────────────
            array('name' => 'Connecté',          'logicalId' => 'connected',          'type' => 'info',   'subType' => 'binary',  'unite' => '',       'historize' => 0, 'isVisible' => 1),
            array('name' => 'Alimentation',      'logicalId' => 'power_state',        'type' => 'info',   'subType' => 'binary',  'unite' => '',       'historize' => 1, 'isVisible' => 1),
            array('name' => 'Mode auto',         'logicalId' => 'auto_mode',          'type' => 'info',   'subType' => 'binary',  'unite' => '',       'historize' => 0, 'isVisible' => 1),
            array('name' => 'Vitesse',           'logicalId' => 'speed',              'type' => 'info',   'subType' => 'numeric', 'unite' => '',       'historize' => 1, 'isVisible' => 1),
            array('name' => 'Oscillation',       'logicalId' => 'oscillation',        'type' => 'info',   'subType' => 'binary',  'unite' => '',       'historize' => 0, 'isVisible' => 1),
            array('name' => 'Direction',         'logicalId' => 'direction',          'type' => 'info',   'subType' => 'binary',  'unite' => '',       'historize' => 0, 'isVisible' => 1),
            array('name' => 'Mode nuit',         'logicalId' => 'night_mode',         'type' => 'info',   'subType' => 'binary',  'unite' => '',       'historize' => 0, 'isVisible' => 1),
            array('name' => 'Minuterie',         'logicalId' => 'sleep_timer',        'type' => 'info',   'subType' => 'numeric', 'unite' => 'min',    'historize' => 0, 'isVisible' => 1),
            array('name' => 'Filtre HEPA',       'logicalId' => 'hepa_filter_life',   'type' => 'info',   'subType' => 'numeric', 'unite' => '%',      'historize' => 1, 'isVisible' => 1),
            array('name' => 'Filtre Carbone',    'logicalId' => 'carbon_filter_life', 'type' => 'info',   'subType' => 'numeric', 'unite' => '%',      'historize' => 1, 'isVisible' => 1),
            // ── Qualité de l'air ──────────────────────────────────────
            array('name' => 'Température',       'logicalId' => 'temperature',        'type' => 'info',   'subType' => 'numeric', 'unite' => '°C',     'historize' => 1, 'isVisible' => 1),
            array('name' => 'Humidité',          'logicalId' => 'humidity',           'type' => 'info',   'subType' => 'numeric', 'unite' => '%',      'historize' => 1, 'isVisible' => 1),
            array('name' => 'PM2.5',             'logicalId' => 'pm25',               'type' => 'info',   'subType' => 'numeric', 'unite' => 'µg/m³',  'historize' => 1, 'isVisible' => 1),
            array('name' => 'PM10',              'logicalId' => 'pm10',               'type' => 'info',   'subType' => 'numeric', 'unite' => 'µg/m³',  'historize' => 1, 'isVisible' => 1),
            array('name' => 'COV',               'logicalId' => 'voc',                'type' => 'info',   'subType' => 'numeric', 'unite' => 'ppb',    'historize' => 1, 'isVisible' => 1),
            array('name' => 'NO2',               'logicalId' => 'no2',                'type' => 'info',   'subType' => 'numeric', 'unite' => 'µg/m³',  'historize' => 1, 'isVisible' => 1),
            array('name' => 'CO2',               'logicalId' => 'co2',                'type' => 'info',   'subType' => 'numeric', 'unite' => 'ppm',    'historize' => 1, 'isVisible' => 1),
            // ── Actions ───────────────────────────────────────────────
            array('name' => 'Allumer',           'logicalId' => 'cmd_power_on',       'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Éteindre',          'logicalId' => 'cmd_power_off',      'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Régler vitesse',    'logicalId' => 'cmd_set_speed',      'type' => 'action', 'subType' => 'slider',  'isVisible' => 1,
                'configuration' => array('minValue' => 1, 'maxValue' => 10)),
            array('name' => 'Auto ON',           'logicalId' => 'cmd_auto_on',        'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Auto OFF',          'logicalId' => 'cmd_auto_off',       'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Oscillation ON',    'logicalId' => 'cmd_oscillation_on', 'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Oscillation OFF',   'logicalId' => 'cmd_oscillation_off','type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Mode nuit ON',      'logicalId' => 'cmd_night_on',       'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Mode nuit OFF',     'logicalId' => 'cmd_night_off',      'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Régler minuterie',  'logicalId' => 'cmd_sleep_timer',    'type' => 'action', 'subType' => 'slider',  'isVisible' => 1,
                'configuration' => array('minValue' => 0, 'maxValue' => 540)),
        );
    }

    private function hotCoolCommands() {
        return array(
            array('name' => 'Chauffage',         'logicalId' => 'heating_mode',        'type' => 'info',   'subType' => 'binary',  'unite' => '',    'historize' => 1, 'isVisible' => 1),
            array('name' => 'Température cible', 'logicalId' => 'target_temperature',  'type' => 'info',   'subType' => 'numeric', 'unite' => '°C',  'historize' => 0, 'isVisible' => 1),
            array('name' => 'Chauffage ON',      'logicalId' => 'cmd_heat_on',         'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Chauffage OFF',     'logicalId' => 'cmd_heat_off',        'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Temp. cible',       'logicalId' => 'cmd_set_target_temp', 'type' => 'action', 'subType' => 'slider',  'isVisible' => 1,
                'configuration' => array('minValue' => 1, 'maxValue' => 37)),
        );
    }

    private function humidifierCommands() {
        return array(
            array('name' => 'Humidification',    'logicalId' => 'humidification',     'type' => 'info',   'subType' => 'binary',  'unite' => '',   'historize' => 0, 'isVisible' => 1),
            array('name' => 'Humidité cible',    'logicalId' => 'target_humidity',    'type' => 'info',   'subType' => 'numeric', 'unite' => '%',  'historize' => 0, 'isVisible' => 1),
            array('name' => 'Humidification ON', 'logicalId' => 'cmd_humid_on',       'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Humidification OFF','logicalId' => 'cmd_humid_off',      'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Régler humidité',   'logicalId' => 'cmd_set_humidity',   'type' => 'action', 'subType' => 'slider',  'isVisible' => 1,
                'configuration' => array('minValue' => 30, 'maxValue' => 70)),
        );
    }

    private function robotCommands() {
        return array(
            array('name' => 'Connecté',          'logicalId' => 'connected',    'type' => 'info',   'subType' => 'binary',  'unite' => '',  'historize' => 0, 'isVisible' => 1),
            array('name' => 'Statut',            'logicalId' => 'status',       'type' => 'info',   'subType' => 'string',  'unite' => '',  'historize' => 0, 'isVisible' => 1),
            array('name' => 'Batterie',          'logicalId' => 'battery',      'type' => 'info',   'subType' => 'numeric', 'unite' => '%', 'historize' => 1, 'isVisible' => 1),
            array('name' => 'Mode nettoyage',    'logicalId' => 'cleaning_mode','type' => 'info',   'subType' => 'string',  'unite' => '',  'historize' => 0, 'isVisible' => 1),
            array('name' => 'Démarrer',          'logicalId' => 'cmd_start',    'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Pause',             'logicalId' => 'cmd_pause',    'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Reprendre',         'logicalId' => 'cmd_resume',   'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
            array('name' => 'Retour base',       'logicalId' => 'cmd_abort',    'type' => 'action', 'subType' => 'other',   'isVisible' => 1),
        );
    }

    private function buildCommands($_cmds) {
        foreach ($_cmds as $def) {
            $cmd = $this->getCmd(null, $def['logicalId']);
            if (is_object($cmd)) {
                continue;
            }
            $cmd = new dysonCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($def['logicalId']);
            $cmd->setType($def['type']);
            $cmd->setSubType($def['subType']);
            $cmd->setName($def['name']);
            $cmd->setUnite($def['unite'] ?? '');
            $cmd->setIsVisible($def['isVisible'] ?? 1);
            $cmd->setIsHistorized($def['historize'] ?? 0);
            if (!empty($def['configuration'])) {
                foreach ($def['configuration'] as $k => $v) {
                    $cmd->setConfiguration($k, $v);
                }
            }
            $cmd->save();
        }
        log::add('dyson', 'info', '[' . $this->getLogicalId() . '] ' . count($_cmds) . ' commande(s) vérifiées/créées');
    }

    /* ================================================================
     * Callback démon → mise à jour des commandes
     * ================================================================ */

    public static function callbackDaemon($_data) {
        if (!isset($_data['device_id'])) {
            return;
        }
        $eq = eqLogic::byId($_data['device_id']);
        if (!is_object($eq)) {
            log::add('dyson', 'warning', 'callbackDaemon : équipement ' . $_data['device_id'] . ' introuvable');
            return;
        }
        if (empty($_data['cmds'])) {
            return;
        }
        foreach ($_data['cmds'] as $cmdData) {
            $logicalId = $cmdData['logical_id'];
            if ($logicalId === '__connected__') {
                $logicalId = 'connected';
            }
            $cmd = $eq->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                $cmd->event($cmdData['value']);
            }
        }
    }

    /* ================================================================
     * Communication avec le démon
     * ================================================================ */

    public function sendDeviceToDaemon() {
        $this->sendToDaemon(array(
            'cmd'    => 'add',
            'device' => array(
                'id'           => $this->getId(),
                'serial'       => $this->getConfiguration('serial_number'),
                'hostname'     => $this->getConfiguration('mqtt_hostname'),
                'username'     => $this->getConfiguration('mqtt_username'),
                'password'     => $this->getConfiguration('mqtt_password'),
                'port'         => intval($this->getConfiguration('mqtt_port', 1883)),
                'product_type' => $this->getConfiguration('product_type'),
                'use_tls'      => boolval($this->getConfiguration('use_tls', false)),
            ),
        ));
    }

    public function sendCommandToDaemon($_action, $_params = array()) {
        if (self::deamon_info()['state'] !== 'ok') {
            throw new Exception('Le démon Dyson n\'est pas démarré');
        }
        $this->sendToDaemon(array(
            'cmd'       => 'send',
            'device_id' => $this->getId(),
            'action'    => $_action,
            'params'    => $_params,
        ));
    }

    public function requestCurrentState() {
        if (self::deamon_info()['state'] !== 'ok') return;
        $this->sendToDaemon(array('cmd' => 'request_state', 'device_id' => $this->getId()));
    }

    private function sendToDaemon($_params) {
        $port   = config::byKey('socketport', 'dyson', '55005');
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) {
            log::add('dyson', 'error', 'Impossible de créer le socket');
            return;
        }
        if (!@socket_connect($socket, '127.0.0.1', intval($port))) {
            log::add('dyson', 'warning', 'Impossible de joindre le démon (port ' . $port . ')');
            socket_close($socket);
            return;
        }
        socket_write($socket, json_encode($_params) . "\n");
        socket_close($socket);
    }
}


/* ====================================================================
 * Commandes
 * ==================================================================== */

class dysonCmd extends cmd {

    public function execute($_options = array()) {
        $eq  = $this->getEqLogic();
        $lid = $this->getLogicalId();

        switch ($lid) {
            case 'cmd_power_on':        $eq->sendCommandToDaemon('set_power',          array('value' => 'ON'));  break;
            case 'cmd_power_off':       $eq->sendCommandToDaemon('set_power',          array('value' => 'OFF')); break;
            case 'cmd_set_speed':       $eq->sendCommandToDaemon('set_speed',          array('value' => max(1, min(10, intval($_options['slider'] ?? 1))))); break;
            case 'cmd_auto_on':         $eq->sendCommandToDaemon('set_auto',           array('value' => 'ON'));  break;
            case 'cmd_auto_off':        $eq->sendCommandToDaemon('set_auto',           array('value' => 'OFF')); break;
            case 'cmd_oscillation_on':  $eq->sendCommandToDaemon('set_oscillation',    array('value' => 'ON'));  break;
            case 'cmd_oscillation_off': $eq->sendCommandToDaemon('set_oscillation',    array('value' => 'OFF')); break;
            case 'cmd_night_on':        $eq->sendCommandToDaemon('set_night_mode',     array('value' => 'ON'));  break;
            case 'cmd_night_off':       $eq->sendCommandToDaemon('set_night_mode',     array('value' => 'OFF')); break;
            case 'cmd_sleep_timer':     $eq->sendCommandToDaemon('set_sleep_timer',    array('value' => max(0, min(540, intval($_options['slider'] ?? 0))))); break;
            case 'cmd_heat_on':         $eq->sendCommandToDaemon('set_heating',        array('value' => 'HEAT')); break;
            case 'cmd_heat_off':        $eq->sendCommandToDaemon('set_heating',        array('value' => 'OFF'));  break;
            case 'cmd_set_target_temp': $eq->sendCommandToDaemon('set_target_temp',   array('value' => max(1, min(37, floatval($_options['slider'] ?? 20))))); break;
            case 'cmd_humid_on':        $eq->sendCommandToDaemon('set_humidification', array('value' => 'HUMD')); break;
            case 'cmd_humid_off':       $eq->sendCommandToDaemon('set_humidification', array('value' => 'OFF'));  break;
            case 'cmd_set_humidity':    $eq->sendCommandToDaemon('set_target_humidity',array('value' => max(30, min(70, intval($_options['slider'] ?? 50))))); break;
            case 'cmd_start':           $eq->sendCommandToDaemon('robot_start');  break;
            case 'cmd_pause':           $eq->sendCommandToDaemon('robot_pause');  break;
            case 'cmd_resume':          $eq->sendCommandToDaemon('robot_resume'); break;
            case 'cmd_abort':           $eq->sendCommandToDaemon('robot_abort');  break;
            default: log::add('dyson', 'warning', 'Commande inconnue : ' . $lid);
        }
    }
}
