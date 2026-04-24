<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    ajax::init();

    if (!isConnect('admin') && config::byKey('api') != init('apikey')) {
        ajax::error('401 - Accès non autorisé', 4010);
    }

    $action = init('action');

    /* ── Ajout manuel ───────────────────────────────────────────────── */
    if ($action == 'add') {
        $name = trim(init('name'));
        if ($name == '') throw new Exception('Le nom est obligatoire');
        $eq = new dyson();
        $eq->setName($name);
        $eq->setEqType_name('dyson');
        $eq->setIsEnable(1);
        $eq->setIsVisible(1);
        $eq->save();
        ajax::success(array('id' => $eq->getId()));
    }

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

    /* ── Recréer les commandes ──────────────────────────────────────── */
    if ($action == 'recreate_cmds') {
        $eq = eqLogic::byId(init('id'));
        if (!is_object($eq)) throw new Exception('Équipement introuvable');
        $eq->createDefaultCommands();
        ajax::success();
    }

    throw new Exception('Action inconnue : ' . $action);

} catch (Exception $e) {
    log::add('dyson', 'error', 'AJAX error [' . init('action') . '] : ' . $e->getMessage());
    ajax::error(displayException($e), $e->getCode());
}
