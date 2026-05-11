<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    ajax::init();

    $action = init('action');

    /* ── Lecture d'un équipement ────────────────────────────────────── */
    if ($action == 'get') {
        $eq = eqLogic::byId(init('id'));
        if (!is_object($eq)) throw new Exception('Équipement introuvable');
        $data = utils::o2a($eq);
        $data['cmds'] = array();
        foreach ($eq->getCmd() as $cmd) {
            $arr = utils::o2a($cmd);
            $arr['currentValue'] = $cmd->getType() === 'info' ? $cmd->getCache('value', '') : '';
            $data['cmds'][] = $arr;
        }
        ajax::success($data);
    }

    /* ── Sauvegarde ─────────────────────────────────────────────────── */
    if ($action == 'save') {
        $json = json_decode(init('eqLogic'), true);
        if (!is_array($json)) throw new Exception('JSON invalide');
        $eq = eqLogic::byId($json['id']);
        if (!is_object($eq)) throw new Exception('Équipement introuvable');
        utils::a2o($eq, $json);
        $eq->save();
        ajax::success(array('id' => $eq->getId()));
    }

    /* ── Suppression ────────────────────────────────────────────────── */
    if ($action == 'remove') {
        $eq = eqLogic::byId(init('id'));
        if (!is_object($eq)) throw new Exception('Équipement introuvable');
        $eq->remove();
        ajax::success();
    }

    /* ── Étape 1 : init auth (demande OTP) ─────────────────────────── */
    if ($action == 'auth_init') {
        $email    = trim(init('email'));
        $password = trim(init('password'));
        $country  = trim(init('country', 'FR'));
        if ($email == '' || $password == '') throw new Exception('Email et mot de passe requis');
        $result = dyson::authInit($email, $password, $country);
        ajax::success($result);
    }

    /* ── Étape 2 : vérification OTP + découverte ────────────────────  */
    if ($action == 'auth_verify') {
        $email       = trim(init('email'));
        $password    = trim(init('password'));
        $country     = trim(init('country', 'FR'));
        $challengeId = trim(init('challengeId'));
        $otp         = trim(init('otp'));
        if ($otp == '') throw new Exception('Code OTP requis');
        $result = dyson::authVerify($email, $password, $country, $challengeId, $otp);
        ajax::success($result);
    }

    /* ── Saisie manuelle des credentials ────────────────────────────── */
    if ($action == 'apply_manual_credentials') {
        $id          = trim(init('id'));
        $serial      = trim(init('serial'));
        $productType = trim(init('product_type'));
        $credential  = trim(init('credential'));
        if ($id == '')          throw new Exception('ID équipement manquant');
        if ($serial == '')      throw new Exception('Numéro de série manquant');
        if ($productType == '') throw new Exception('Type produit manquant');
        if ($credential == '')  throw new Exception('Credential manquant');
        $result = dyson::applyManualCredentials($id, $serial, $productType, $credential);
        ajax::success($result);
    }

    /* ── Exécuter une commande action ──────────────────────────────── */
    if ($action == 'execute_cmd') {
        $cmd = cmd::byId(init('cmd_id'));
        if (!is_object($cmd)) throw new Exception('Commande introuvable');
        if ($cmd->getType() !== 'action') throw new Exception('Commande non exécutable (type info)');
        $options = json_decode(init('options', '{}'), true) ?: array();
        $result = $cmd->execCmd($options);
        ajax::success($result);
    }

    /* ── Recréer les commandes ──────────────────────────────────────── */
    if ($action == 'recreate_cmds') {
        $eq = eqLogic::byId(init('id'));
        if (!is_object($eq)) throw new Exception('Équipement introuvable');
        $eq->createDefaultCommands();
        ajax::success();
    }

    /* ── Scan réseau local pour trouver les IPs Dyson ───────────────── */
    if ($action == 'scan_network') {
        set_time_limit(30);
        $venv   = dirname(dirname(dirname(__FILE__))) . '/resources/python_venv/bin/python3';
        $python = file_exists($venv) ? $venv : 'python3';
        $script = dirname(dirname(dirname(__FILE__))) . '/resources/dysonMqtt/scan.py';
        if (!file_exists($script)) {
            throw new Exception('Script scan.py introuvable');
        }
        $output = shell_exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' 2>&1');
        $result = json_decode(trim($output), true);
        if (!is_array($result)) {
            throw new Exception('Reponse invalide : ' . $output);
        }
        if (!empty($result['error'])) {
            throw new Exception($result['message']);
        }
        ajax::success($result['devices']);
    }

    throw new Exception('Action inconnue : ' . $action);

} catch (Exception $e) {
    log::add('dyson', 'error', 'AJAX error [' . init('action') . '] : ' . $e->getMessage());
    ajax::error(displayException($e), $e->getCode());
}
