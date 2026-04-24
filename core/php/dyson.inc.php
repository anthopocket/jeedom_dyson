<?php
/* Point d'entrée HTTP du démon Dyson → Jeedom.
 * Le démon envoie des POST JSON contenant les mises à jour d'état.
 * L'apikey est dans le corps JSON (pas en GET/POST car Content-Type: application/json).
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

    // Lire le corps JSON avant tout
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Données JSON invalides : ' . substr($raw, 0, 200));
    }

    // Apikey dans le JSON body (daemon envoie Content-Type: application/json)
    // Fallback sur init() pour les appels GET/POST classiques
    $apikey = isset($data['apikey']) ? $data['apikey'] : init('apikey');
    if ($apikey !== config::byKey('api')) {
        throw new Exception('Accès refusé (clé API invalide)');
    }

    if (isset($data['devices']) && is_array($data['devices'])) {
        foreach ($data['devices'] as $deviceData) {
            dyson::callbackDaemon($deviceData);
        }
    }

    echo json_encode(array('state' => 'ok'));
} catch (Exception $e) {
    log::add('dyson', 'error', 'dyson.inc.php : ' . $e->getMessage());
    echo json_encode(array('state' => 'error', 'result' => $e->getMessage()));
}
