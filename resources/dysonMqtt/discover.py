#!/usr/bin/env python3
"""
Dyson cloud discovery - uses libdyson's HTTP infrastructure directly.

login_email_otp() returns a closure (not a string), and calls /userstatus first
which now returns 401 on some accounts. We bypass both issues by calling
account.request() directly.
"""

import sys
import json
import argparse
import os
import traceback
import tempfile
import stat

STATE_FILE = os.path.join(tempfile.gettempdir(), 'dyson_jeedom_auth.json')

API_USER_STATUS  = "/v3/userregistration/email/userstatus"
API_EMAIL_AUTH   = "/v3/userregistration/email/auth"
API_EMAIL_VERIFY = "/v3/userregistration/email/verify"


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


def do_auth_init(email, password, country):
    from libdyson.cloud.account import DysonAccount
    from libdyson.exceptions import DysonInvalidAuth

    account = DysonAccount()

    # Some libdyson versions call /userstatus first (required precursor).
    # If it returns 401/403, skip it — the auth step will tell us if creds are wrong.
    try:
        resp = account.request(
            "POST", API_USER_STATUS,
            params={"country": country},
            data={"email": email},
            auth=False,
        )
        status_data = resp.json()
        account_status = status_data.get("accountStatus", "UNKNOWN")
        if account_status not in ("ACTIVE", "UNKNOWN"):
            fail(
                f"Compte Dyson inactif (status: {account_status}). "
                "Vérifiez l'adresse email ou créez un compte sur dyson.com."
            )
    except DysonInvalidAuth:
        # /userstatus returned 401/403 — skip and try auth directly
        pass

    # Request OTP (sends email to user)
    try:
        resp = account.request(
            "POST", API_EMAIL_AUTH,
            params={"country": country, "culture": "en-US"},
            data={"email": email},
            auth=False,
        )
    except DysonInvalidAuth:
        fail(
            "Authentification refusée par Dyson (HTTP 401/403).\n"
            "Causes possibles :\n"
            "- Adresse email Dyson incorrecte\n"
            "- Compte créé via Apple/Google (pas de mot de passe Dyson)\n"
            "- IP bloquée temporairement par Dyson\n"
            f"Email utilisé : {email}"
        )

    if resp.status_code == 429:
        fail("Trop de demandes OTP. Attendez quelques minutes avant de réessayer.")

    try:
        challenge_id = resp.json()["challengeId"]
    except (KeyError, ValueError) as e:
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

    from libdyson.cloud.account import DysonAccount
    from libdyson.exceptions import DysonInvalidAuth

    account = DysonAccount()

    try:
        resp = account.request(
            "POST", API_EMAIL_VERIFY,
            data={
                "email":       state["email"],
                "password":    state["password"],
                "challengeId": state["challenge_id"],
                "otpCode":     otp,
            },
            auth=False,
        )
    except DysonInvalidAuth:
        fail("Authentification refusée. Vérifiez votre mot de passe Dyson.")

    if resp.status_code == 400:
        fail("Code OTP invalide ou expiré. Recommencez la découverte pour obtenir un nouveau code.")

    auth_info = resp.json()
    if "token" not in auth_info and "Account" not in auth_info and "Password" not in auth_info:
        fail(f"Réponse verify inattendue : {resp.text[:500]}")

    # Inject auth_info so account.devices() can authenticate
    account._auth_info = auth_info

    devices_raw = account.devices()

    devices = []
    for d in devices_raw:
        # libdyson uses "credential" (singular) — already AES-decrypted apPasswordHash
        credential = getattr(d, "credential", None) or getattr(d, "credentials", "")
        devices.append({
            "serial":       getattr(d, "serial",       ""),
            "name":         getattr(d, "name",         ""),
            "product_type": getattr(d, "product_type", ""),
            "credentials":  credential or "",
        })

    if os.path.exists(STATE_FILE):
        try:
            os.remove(STATE_FILE)
        except OSError:
            pass

    out({"devices": devices})


def main():
    parser = argparse.ArgumentParser(description='Dyson cloud discovery via libdyson')
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
