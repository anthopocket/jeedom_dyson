#!/usr/bin/env python3
"""
Dyson cloud discovery - appel direct API Dyson sans libdyson.
Contourne les problèmes de User-Agent de libdyson.
"""

import sys
import json
import argparse
import os
import traceback
import tempfile
import stat
import requests
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
import base64
import hashlib

STATE_FILE = os.path.join(tempfile.gettempdir(), 'dyson_jeedom_auth.json')

BASE_URL        = "https://appapi.cp.dyson.com"
API_USER_STATUS = "/v3/userregistration/email/userstatus"
API_EMAIL_AUTH  = "/v3/userregistration/email/auth"
API_EMAIL_VERIFY= "/v3/userregistration/email/verify"
API_DEVICES     = "/v2/provisioningservice/manifest"

HEADERS = {
    "User-Agent": "android client",
    "Content-Type": "application/json",
}


def out(data):
    print(json.dumps(data, ensure_ascii=False), flush=True)


def fail(message):
    out({'error': True, 'message': str(message)})
    sys.exit(1)


def save_state(data):
    with open(STATE_FILE, 'w') as f:
        json.dump(data, f)
    try:
        os.chmod(STATE_FILE, stat.S_IRUSR | stat.S_IWUSR)
    except OSError:
        pass


def load_state():
    if not os.path.exists(STATE_FILE):
        return {}
    with open(STATE_FILE) as f:
        return json.load(f)


def decrypt_password(encrypted_password):
    """Déchiffre le mot de passe MQTT Dyson (AES)."""
    key = b'\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20'
    init_vector = b'\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00'
    try:
        cipher = Cipher(
            algorithms.AES(key),
            modes.CBC(init_vector),
            backend=default_backend()
        )
        decryptor = cipher.decryptor()
        decrypted = decryptor.update(base64.b64decode(encrypted_password)) + decryptor.finalize()
        json_password = decrypted[:-decrypted[-1]]
        return json.loads(json_password)
    except Exception:
        return encrypted_password


def do_auth_init(email, password, country):
    # Étape optionnelle : vérifier le statut du compte
    try:
        resp = requests.post(
            BASE_URL + API_USER_STATUS,
            params={"country": country},
            json={"email": email},
            headers=HEADERS,
            timeout=15,
        )
        if resp.status_code == 200:
            status_data = resp.json()
            account_status = status_data.get("accountStatus", "ACTIVE")
            if account_status not in ("ACTIVE", "UNKNOWN"):
                fail(
                    f"Compte Dyson inactif (status: {account_status}). "
                    "Vérifiez l'adresse email ou créez un compte sur dyson.com."
                )
    except requests.RequestException:
        pass  # On continue même si /userstatus échoue

    # Demande d'OTP
    try:
        resp = requests.post(
            BASE_URL + API_EMAIL_AUTH,
            params={"country": country, "culture": "fr-FR"},
            json={"email": email},
            headers=HEADERS,
            timeout=15,
        )
    except requests.RequestException as e:
        fail(f"Erreur réseau : {e}")

    if resp.status_code == 429:
        fail("Trop de demandes OTP. Attendez quelques minutes avant de réessayer.")

    if resp.status_code not in (200, 201):
        fail(
            f"Authentification refusée par Dyson (HTTP {resp.status_code}).\n"
            "Causes possibles :\n"
            "- Adresse email Dyson incorrecte\n"
            "- Compte créé via Apple/Google (pas de mot de passe Dyson)\n"
            "- IP bloquée temporairement par Dyson\n"
            f"Email utilisé : {email}\n"
            f"Réponse : {resp.text[:200]}"
        )

    try:
        challenge_id = resp.json()["challengeId"]
    except (KeyError, ValueError):
        fail(f"Réponse Dyson inattendue : {resp.text[:500]}")

    save_state({
        "email":        email,
        "password":     password,
        "country":      country,
        "challenge_id": challenge_id,
    })

    out({"challengeId": challenge_id})


def do_auth_verify(otp):
    state = load_state()
    if not state:
        fail("État d'authentification perdu. Recommencez la découverte depuis le début.")

    email        = state["email"]
    password     = state["password"]
    country      = state["country"]
    challenge_id = state["challenge_id"]

    # Vérification OTP
    try:
        resp = requests.post(
            BASE_URL + API_EMAIL_VERIFY,
            json={
                "email":       email,
                "password":    password,
                "challengeId": challenge_id,
                "otpCode":     otp,
            },
            headers=HEADERS,
            timeout=15,
        )
    except requests.RequestException as e:
        fail(f"Erreur réseau : {e}")

    if resp.status_code == 400:
        fail("Code OTP invalide ou expiré. Recommencez la découverte pour obtenir un nouveau code.")

    if resp.status_code not in (200, 201):
        fail(f"Vérification OTP refusée (HTTP {resp.status_code}) : {resp.text[:200]}")

    auth_info = resp.json()
    account   = auth_info.get("Account", "")
    token     = auth_info.get("token",   "") or auth_info.get("Token", "")

    if not account and not token:
        fail(f"Réponse verify inattendue : {resp.text[:500]}")

    # Récupération des appareils
    try:
        devices_resp = requests.get(
            BASE_URL + API_DEVICES,
            headers={**HEADERS, "Authorization": "Bearer " + token} if token else HEADERS,
            auth=(account, token) if account and token else None,
            timeout=15,
        )
    except requests.RequestException as e:
        fail(f"Erreur réseau lors de la récupération des appareils : {e}")

    if devices_resp.status_code not in (200, 201):
        fail(f"Impossible de récupérer les appareils (HTTP {devices_resp.status_code}) : {devices_resp.text[:200]}")

    raw_devices = devices_resp.json()
    if not isinstance(raw_devices, list):
        raw_devices = raw_devices.get("devices", raw_devices.get("Devices", []))

    devices = []
    for d in raw_devices:
        serial       = d.get("Serial",      d.get("serial",       ""))
        name         = d.get("Name",         d.get("name",         "Dyson " + serial))
        product_type = d.get("ProductType",  d.get("product_type", ""))
        encrypted_pw = d.get("LocalCredentials", d.get("credentials", ""))

        # Déchiffrement du mot de passe MQTT local
        credential = encrypted_pw
        if encrypted_pw:
            try:
                decrypted = decrypt_password(encrypted_pw)
                # decrypt_password retourne le JSON déchiffré
                # Le vrai mot de passe MQTT est apPasswordHash
                if isinstance(decrypted, dict):
                    credential = decrypted.get('apPasswordHash', decrypted.get('serial', encrypted_pw))
                else:
                    credential = decrypted
            except Exception:
                credential = encrypted_pw

        devices.append({
            "serial":       serial,
            "name":         name,
            "product_type": product_type,
            "credentials":  credential,
        })

    # Nettoyage du fichier état
    if os.path.exists(STATE_FILE):
        try:
            os.remove(STATE_FILE)
        except OSError:
            pass

    out({"devices": devices})


def main():
    parser = argparse.ArgumentParser(description='Dyson cloud discovery direct API')
    parser.add_argument('--action',   required=True, choices=['auth_init', 'auth_verify'])
    parser.add_argument('--email',    default='')
    parser.add_argument('--password', default='')
    parser.add_argument('--country',  default='FR')
    parser.add_argument('--otp',      default='')
    args = parser.parse_args()

    try:
        if args.action == 'auth_init':
            if not args.email:
                fail('--email requis')
            do_auth_init(args.email, args.password, args.country)
        else:
            if not args.otp:
                fail('--otp requis')
            do_auth_verify(args.otp)
    except SystemExit:
        raise
    except Exception as e:
        fail(str(e) + '\n\n' + traceback.format_exc())


if __name__ == '__main__':
    main()
