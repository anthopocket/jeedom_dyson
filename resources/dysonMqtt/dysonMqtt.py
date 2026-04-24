#!/usr/bin/env python3
"""Dyson MQTT Daemon pour Jeedom."""

import argparse
import json
import logging
import os
import signal
import socket
import sys
import threading
import time
from datetime import datetime, timezone
from typing import Dict, Optional

import paho.mqtt.client as mqtt
import requests

# ── Logging ────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s %(levelname)-8s %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
    stream=sys.stdout,
)
logger = logging.getLogger('dyson')

HEARTBEAT_INTERVAL = 10
SOCKET_BUFFER      = 4096
CALLBACK_TIMEOUT   = 5

ROBOT_TYPES = {'2PQ', '276'}

PRODUCT_STATE_MAP = {
    'fpwr': 'power_state',
    'fnsp': 'speed',
    'auto': 'auto_mode',
    'oson': 'oscillation',
    'fdir': 'direction',
    'nmod': 'night_mode',
    'sltm': 'sleep_timer',
    'hflr': 'hepa_filter_life',
    'cflr': 'carbon_filter_life',
    'fflr': 'hepa_filter_life',
    'hmod': 'heating_mode',
    'hmax': 'target_temperature',
    'mmod': 'humidification',
    'humt': 'target_humidity',
}

ENV_DATA_MAP = {
    'tact': 'temperature',
    'hact': 'humidity',
    'pact': 'pm10',
    'p25r': 'pm25',
    'va10': 'voc',
    'noxl': 'no2',
    'co2r': 'co2',
}


# ══════════════════════════════════════════════════════════════════════
class DysonDevice:

    def __init__(self, config: dict, callback_url: str, api_key: str):
        self.device_id    = config['id']
        self.serial       = config['serial']
        self.hostname     = config.get('hostname', '')
        self.username     = config.get('username', self.serial)
        self.password     = config.get('password', '')
        self.port         = int(config.get('port', 1883))
        self.product_type = config.get('product_type', '')
        self.use_tls      = bool(config.get('use_tls', False))
        self.callback_url = callback_url
        self.api_key      = api_key

        self._client: Optional[mqtt.Client] = None
        self._connected = False
        self._stop_event = threading.Event()
        self._heartbeat_thread: Optional[threading.Thread] = None

        self._cmd_topic    = f'{self.product_type}/{self.serial}/command'
        self._status_topic = f'{self.product_type}/{self.serial}/status/current'
        self._conn_topic   = f'{self.product_type}/{self.serial}/status/connection'
        self._faults_topic = f'{self.product_type}/{self.serial}/status/faults'

        logger.info('[%s] Appareil créé — type=%s hostname=%s port=%d tls=%s',
                    self.serial, self.product_type, self.hostname, self.port, self.use_tls)

    # ── Connexion ──────────────────────────────────────────────────────

    def connect(self):
        if not self.hostname:
            logger.warning('[%s] Pas d\'adresse IP configurée — connexion ignorée', self.serial)
            return

        logger.info('[%s] Initialisation client MQTT (username=%s)', self.serial, self.username)
        try:
            # paho-mqtt >= 2.0 — supprime le DeprecationWarning
            self._client = mqtt.Client(
                mqtt.CallbackAPIVersion.VERSION1,
                client_id=self.serial,
                protocol=mqtt.MQTTv311,
            )
        except AttributeError:
            # paho-mqtt < 2.0
            self._client = mqtt.Client(client_id=self.serial, protocol=mqtt.MQTTv311)
        self._client.username_pw_set(self.username, self._decode_password())
        self._client.on_connect    = self._on_connect
        self._client.on_disconnect = self._on_disconnect
        self._client.on_message    = self._on_message
        self._client.on_publish    = self._on_publish
        self._client.on_subscribe  = self._on_subscribe

        if self.use_tls:
            logger.info('[%s] Activation TLS', self.serial)
            self._client.tls_set()

        try:
            logger.info('[%s] Connexion à %s:%d ...', self.serial, self.hostname, self.port)
            self._client.connect(self.hostname, self.port, keepalive=60)
            self._client.loop_start()
            logger.info('[%s] Loop MQTT démarré', self.serial)
        except Exception as exc:
            logger.error('[%s] Erreur connexion MQTT : %s', self.serial, exc)

    def disconnect(self):
        logger.info('[%s] Déconnexion demandée', self.serial)
        self._stop_event.set()
        if self._client:
            self._client.loop_stop()
            self._client.disconnect()
            self._client = None
        self._connected = False
        logger.info('[%s] Déconnecté', self.serial)

    # ── Callbacks MQTT ─────────────────────────────────────────────────

    def _on_connect(self, client, userdata, flags, rc):
        RC_CODES = {
            0: 'Connexion acceptée',
            1: 'Protocole refusé',
            2: 'Identifiant refusé',
            3: 'Serveur indisponible',
            4: 'Identifiants incorrects',
            5: 'Non autorisé',
        }
        msg = RC_CODES.get(rc, f'Code inconnu {rc}')
        if rc == 0:
            logger.info('[%s] ✓ Connecté au broker MQTT (%s)', self.serial, msg)
            self._connected = True
            topics = [
                (self._status_topic, 0),
                (self._conn_topic, 0),
                (self._faults_topic, 0),
            ]
            logger.debug('[%s] Souscription aux topics : %s', self.serial, [t[0] for t in topics])
            client.subscribe(topics)
            self._request_current_state()
            self._send_to_jeedom([{'logical_id': '__connected__', 'value': 1}])
            self._start_heartbeat()
        else:
            logger.error('[%s] ✗ Échec connexion MQTT : %s', self.serial, msg)

    def _on_disconnect(self, client, userdata, rc):
        self._connected = False
        if rc == 0:
            logger.info('[%s] Déconnexion propre', self.serial)
        else:
            logger.warning('[%s] Déconnexion inattendue (rc=%d) — reconnexion automatique en cours', self.serial, rc)

    def _on_message(self, client, userdata, message):
        try:
            raw = message.payload.decode('utf-8')
            logger.debug('[%s] ← Message reçu sur %s : %s', self.serial, message.topic, raw[:300])
            payload  = json.loads(raw)
            msg_type = payload.get('msg', 'INCONNU')
            logger.info('[%s] Message type=%s', self.serial, msg_type)

            if msg_type in ('CURRENT-STATE', 'STATE-CHANGE'):
                self._handle_state(payload)
            elif msg_type == 'ENVIRONMENTAL-CURRENT-SENSOR-DATA':
                self._handle_env_data(payload)
            elif msg_type == 'CURRENT-FAULTS':
                self._handle_faults(payload)
            else:
                logger.debug('[%s] Type de message non géré : %s', self.serial, msg_type)
        except json.JSONDecodeError as exc:
            logger.error('[%s] JSON invalide dans le message MQTT : %s', self.serial, exc)
        except Exception as exc:
            logger.error('[%s] Erreur traitement message : %s', self.serial, exc, exc_info=True)

    def _on_publish(self, client, userdata, mid):
        logger.debug('[%s] ✓ Message publié (mid=%d)', self.serial, mid)

    def _on_subscribe(self, client, userdata, mid, granted_qos):
        logger.info('[%s] ✓ Souscription confirmée (mid=%d qos=%s)', self.serial, mid, granted_qos)

    # ── Traitement des états ───────────────────────────────────────────

    def _handle_state(self, payload: dict):
        product_state = payload.get('product-state', {})
        logger.debug('[%s] product-state brut : %s', self.serial, product_state)

        if payload.get('msg') == 'STATE-CHANGE':
            product_state = {k: v[1] if isinstance(v, list) else v
                             for k, v in product_state.items()}
            logger.debug('[%s] STATE-CHANGE après extraction nouvelle valeur : %s', self.serial, product_state)

        cmds = []
        for key, logical_id in PRODUCT_STATE_MAP.items():
            if key in product_state:
                raw_val = product_state[key]
                value   = self._convert_state_value(key, raw_val)
                logger.debug('[%s]   %s=%s → %s=%s', self.serial, key, raw_val, logical_id, value)
                if value is not None:
                    cmds.append({'logical_id': logical_id, 'value': value})

        logger.info('[%s] %d commande(s) à envoyer à Jeedom', self.serial, len(cmds))
        if cmds:
            self._send_to_jeedom(cmds)

    def _handle_env_data(self, payload: dict):
        data = payload.get('data', {})
        logger.debug('[%s] Données environnementales brutes : %s', self.serial, data)
        cmds = []
        for key, logical_id in ENV_DATA_MAP.items():
            if key in data:
                raw_val = data[key]
                value   = self._convert_env_value(key, raw_val)
                logger.debug('[%s]   %s=%s → %s=%s', self.serial, key, raw_val, logical_id, value)
                if value is not None:
                    cmds.append({'logical_id': logical_id, 'value': value})

        logger.info('[%s] %d capteur(s) environnementaux à envoyer à Jeedom', self.serial, len(cmds))
        if cmds:
            self._send_to_jeedom(cmds)

    def _handle_faults(self, payload: dict):
        faults = payload.get('faults', {})
        if faults:
            logger.warning('[%s] ⚠ Pannes détectées : %s', self.serial, faults)
        else:
            logger.debug('[%s] Aucune panne détectée', self.serial)

    # ── Conversions ────────────────────────────────────────────────────

    def _convert_state_value(self, key: str, raw):
        try:
            if key in ('fpwr', 'fdir', 'nmod', 'oson', 'rhtm'):
                return 1 if raw == 'ON' else 0
            if key == 'hmod':
                return 1 if raw == 'HEAT' else 0
            if key == 'mmod':
                return 1 if raw == 'HUMD' else 0
            if key == 'fnsp':
                return int(raw) if raw != 'AUTO' else 0
            if key == 'auto':
                return 1 if raw == 'ON' else 0
            if key in ('sltm', 'hflr', 'cflr', 'fflr', 'humt'):
                return 0 if raw == 'OFF' else int(raw)
            if key == 'hmax':
                return round(int(raw) / 10 - 273.15, 1)
        except (ValueError, TypeError) as exc:
            logger.warning('[%s] Impossible de convertir %s=%s : %s', self.serial, key, raw, exc)
        return None

    def _convert_env_value(self, key: str, raw):
        if raw in ('OFF', 'INIT', 'NONE', None):
            logger.debug('[%s] Valeur ignorée %s=%s', self.serial, key, raw)
            return None
        try:
            val = int(raw)
            if key == 'tact':
                return round(val / 10 - 273.15, 1)
            return val
        except (ValueError, TypeError) as exc:
            logger.warning('[%s] Impossible de convertir env %s=%s : %s', self.serial, key, raw, exc)
            return None

    # ── Envoi de commandes ─────────────────────────────────────────────

    def send_command(self, action: str, params: dict = None):
        logger.info('[%s] Commande reçue de Jeedom : action=%s params=%s', self.serial, action, params)
        if not self._connected or not self._client:
            logger.error('[%s] Impossible d\'envoyer : non connecté au broker MQTT', self.serial)
            return

        now_iso = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')
        data    = {}

        if action == 'set_power':
            data = {'fpwr': params.get('value', 'ON')}
        elif action == 'set_speed':
            speed = int(params.get('value', 1))
            data  = {'fpwr': 'ON', 'fnsp': str(speed).zfill(4)}
        elif action == 'set_auto':
            data = {'fpwr': 'ON', 'auto': params.get('value', 'ON')}
        elif action == 'set_oscillation':
            data = {'oson': params.get('value', 'ON')}
        elif action == 'set_night_mode':
            data = {'nmod': params.get('value', 'ON')}
        elif action == 'set_sleep_timer':
            data = {'sltm': str(int(params.get('value', 0)))}
        elif action == 'set_heating':
            data = {'hmod': params.get('value', 'HEAT')}
        elif action == 'set_target_temp':
            celsius = float(params.get('value', 20))
            kelvin  = round((celsius + 273.15) * 10)
            data    = {'hmax': str(kelvin)}
        elif action == 'set_humidification':
            data = {'mmod': params.get('value', 'HUMD')}
        elif action == 'set_target_humidity':
            data = {'humt': str(int(params.get('value', 50)))}
        elif action in ('robot_start', 'robot_pause', 'robot_resume', 'robot_abort'):
            msg_map = {'robot_start': 'START', 'robot_pause': 'PAUSE',
                       'robot_resume': 'RESUME', 'robot_abort': 'ABORT'}
            payload = {'msg': msg_map[action], 'time': now_iso}
            if action == 'robot_start':
                payload['fullCleanType'] = ''
            logger.info('[%s] → Publication robot : %s', self.serial, payload)
            self._publish(payload)
            return
        else:
            logger.warning('[%s] Action inconnue : %s', self.serial, action)
            return

        if data:
            payload = {'msg': 'STATE-SET', 'time': now_iso, 'mode-reason': 'LAPP', 'data': data}
            logger.info('[%s] → Publication STATE-SET : %s', self.serial, payload)
            self._publish(payload)

    def _publish(self, payload: dict):
        try:
            msg = json.dumps(payload)
            result = self._client.publish(self._cmd_topic, msg, qos=1)
            logger.debug('[%s] Publié sur %s (rc=%s)', self.serial, self._cmd_topic, result.rc)
        except Exception as exc:
            logger.error('[%s] Erreur publication MQTT : %s', self.serial, exc)

    # ── Requête d'état ────────────────────────────────────────────────

    def _request_current_state(self):
        now_iso = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')
        logger.info('[%s] Demande état courant (REQUEST-CURRENT-STATE + ENVIRONMENTAL)', self.serial)
        self._publish({'msg': 'REQUEST-CURRENT-STATE', 'time': now_iso})
        self._publish({'msg': 'REQUEST-PRODUCT-ENVIRONMENT-CURRENT-SENSOR-DATA', 'time': now_iso})

    # ── Heartbeat ─────────────────────────────────────────────────────

    def _start_heartbeat(self):
        if self._heartbeat_thread and self._heartbeat_thread.is_alive():
            return
        self._stop_event.clear()
        self._heartbeat_thread = threading.Thread(target=self._heartbeat_loop, daemon=True)
        self._heartbeat_thread.start()
        logger.info('[%s] Heartbeat démarré (intervalle %ds)', self.serial, HEARTBEAT_INTERVAL)

    def _heartbeat_loop(self):
        while not self._stop_event.wait(HEARTBEAT_INTERVAL):
            if self._connected:
                logger.debug('[%s] Heartbeat — demande état', self.serial)
                self._request_current_state()
            else:
                logger.debug('[%s] Heartbeat — non connecté, skip', self.serial)

    # ── Callback Jeedom ───────────────────────────────────────────────

    def _send_to_jeedom(self, cmds: list):
        payload = {
            'apikey':  self.api_key,
            'devices': [{'device_id': self.device_id, 'cmds': cmds}],
        }
        logger.debug('[%s] → Callback Jeedom : %s', self.serial, payload)
        try:
            r = requests.post(self.callback_url, json=payload, timeout=CALLBACK_TIMEOUT)
            if r.status_code == 200:
                logger.debug('[%s] ✓ Callback Jeedom OK (HTTP 200)', self.serial)
            else:
                logger.warning('[%s] ✗ Callback Jeedom HTTP %d : %s', self.serial, r.status_code, r.text[:200])
        except requests.exceptions.ConnectionError as exc:
            logger.error('[%s] Callback Jeedom — connexion refusée : %s', self.serial, exc)
        except requests.exceptions.Timeout:
            logger.error('[%s] Callback Jeedom — timeout (%ds)', self.serial, CALLBACK_TIMEOUT)
        except Exception as exc:
            logger.error('[%s] Callback Jeedom — erreur inattendue : %s', self.serial, exc)

    # ── Utilitaires ───────────────────────────────────────────────────

    def _decode_password(self) -> str:
        # libdyson already decodes LocalCredentials (base64 → JSON → apCredentials).
        # The credentials stored in Jeedom are already plain text — do not re-decode.
        return self.password


# ══════════════════════════════════════════════════════════════════════
class DysonDaemon:

    def __init__(self, args):
        self.callback_url = args.callback
        self.api_key      = args.apikey
        self.pid_file     = args.pid
        self.socket_port  = int(args.socketport)
        self.devices: Dict[int, DysonDevice] = {}
        self._lock    = threading.Lock()
        self._running = True

    def start(self):
        logger.info('=== Démon Dyson démarré ===')
        logger.info('Callback URL : %s', self.callback_url)
        logger.info('Port socket  : %d', self.socket_port)
        logger.info('Fichier PID  : %s', self.pid_file)
        self._write_pid()
        signal.signal(signal.SIGTERM, self._signal_handler)
        signal.signal(signal.SIGINT,  self._signal_handler)
        self._socket_loop()

    def _write_pid(self):
        os.makedirs(os.path.dirname(self.pid_file), exist_ok=True)
        with open(self.pid_file, 'w') as f:
            f.write(str(os.getpid()))
        logger.info('PID %d écrit dans %s', os.getpid(), self.pid_file)

    def _signal_handler(self, signum, frame):
        logger.info('Signal %d reçu — arrêt du démon', signum)
        self._running = False
        self._cleanup()
        sys.exit(0)

    def _cleanup(self):
        logger.info('Nettoyage de %d appareil(s)...', len(self.devices))
        with self._lock:
            for device in self.devices.values():
                try:
                    device.disconnect()
                except Exception as exc:
                    logger.error('Erreur déconnexion %s : %s', device.serial, exc)
        if os.path.exists(self.pid_file):
            os.remove(self.pid_file)
            logger.info('Fichier PID supprimé')

    # ── Serveur socket ────────────────────────────────────────────────

    def _socket_loop(self):
        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv.bind(('127.0.0.1', self.socket_port))
        srv.listen(10)
        srv.settimeout(1)
        logger.info('Écoute socket sur 127.0.0.1:%d', self.socket_port)

        while self._running:
            try:
                conn, addr = srv.accept()
                logger.debug('Connexion socket depuis %s', addr)
                threading.Thread(target=self._handle_socket_client, args=(conn,), daemon=True).start()
            except socket.timeout:
                continue
            except Exception as exc:
                logger.error('Erreur socket serveur : %s', exc)

        srv.close()
        logger.info('Socket serveur fermé')

    def _handle_socket_client(self, conn):
        try:
            data = b''
            while True:
                chunk = conn.recv(SOCKET_BUFFER)
                if not chunk:
                    break
                data += chunk
                if b'\n' in data:
                    break
            raw = data.decode('utf-8').strip()
            logger.debug('Données socket reçues : %s', raw[:500])
            payload = json.loads(raw)
            self._dispatch(payload)
        except json.JSONDecodeError as exc:
            logger.error('JSON invalide depuis socket : %s — données : %s', exc, data[:200])
        except Exception as exc:
            logger.error('Erreur traitement socket : %s', exc, exc_info=True)
        finally:
            conn.close()

    # ── Dispatch des commandes ─────────────────────────────────────────

    def _dispatch(self, payload: dict):
        cmd = payload.get('cmd', '')
        logger.info('Commande reçue de Jeedom : cmd=%s', cmd)

        if cmd == 'add':
            self._add_device(payload['device'])

        elif cmd == 'remove':
            device_id = int(payload['device_id'])
            logger.info('Suppression appareil id=%d', device_id)
            self._remove_device(device_id)

        elif cmd == 'send':
            device_id = int(payload['device_id'])
            action    = payload.get('action', '')
            params    = payload.get('params', {})
            logger.info('Envoi commande → appareil id=%d action=%s params=%s', device_id, action, params)
            with self._lock:
                device = self.devices.get(device_id)
            if device:
                device.send_command(action, params)
            else:
                logger.error('Appareil id=%d introuvable (appareils connus : %s)',
                             device_id, list(self.devices.keys()))

        elif cmd == 'request_state':
            device_id = int(payload['device_id'])
            logger.debug('Demande état pour appareil id=%d', device_id)
            with self._lock:
                device = self.devices.get(device_id)
            if device:
                if device._connected:
                    device._request_current_state()
                else:
                    logger.warning('Appareil id=%d non connecté — état non demandé', device_id)
            else:
                logger.warning('Appareil id=%d introuvable pour request_state', device_id)

        else:
            logger.warning('Commande inconnue reçue de Jeedom : %s', cmd)

    def _add_device(self, config: dict):
        device_id = int(config['id'])
        logger.info('Ajout/reconfiguration appareil id=%d serial=%s hostname=%s',
                    device_id, config.get('serial'), config.get('hostname'))
        with self._lock:
            if device_id in self.devices:
                logger.info('Appareil id=%d existant — déconnexion avant reconfiguration', device_id)
                self.devices[device_id].disconnect()
            device = DysonDevice(config, self.callback_url, self.api_key)
            self.devices[device_id] = device

        device.connect()
        logger.info('Appareils actifs : %d', len(self.devices))

    def _remove_device(self, device_id: int):
        with self._lock:
            device = self.devices.pop(device_id, None)
        if device:
            device.disconnect()
            logger.info('Appareil id=%d retiré. Appareils restants : %d', device_id, len(self.devices))
        else:
            logger.warning('Appareil id=%d introuvable pour suppression', device_id)


# ══════════════════════════════════════════════════════════════════════
def parse_args():
    parser = argparse.ArgumentParser(description='Dyson MQTT Daemon pour Jeedom')
    parser.add_argument('--loglevel',   default='info')
    parser.add_argument('--socketport', default='55005')
    parser.add_argument('--callback',   required=True)
    parser.add_argument('--apikey',     required=True)
    parser.add_argument('--pid',        default='/tmp/dyson_daemon.pid')
    return parser.parse_args()


def main():
    args = parse_args()
    numeric_level = getattr(logging, args.loglevel.upper(), logging.INFO)
    logging.getLogger().setLevel(numeric_level)

    logger.info('Arguments : loglevel=%s socketport=%s callback=%s pid=%s',
                args.loglevel, args.socketport, args.callback, args.pid)

    daemon = DysonDaemon(args)
    daemon.start()


if __name__ == '__main__':
    main()
