#!/usr/bin/env python3
"""
Dyson cloud discovery pour Jeedom via libdyson-rest.
Utilise begin_login() + complete_login() separes pour eviter
le DysonOTPTooFrequently et le 429.
"""

import sys
import json
import argparse
import os
import traceback
import tempfile
import stat
import time

STATE_FILE     = os.path.join(tempfile.gettempdir(), 'dyson_jeedom_auth.json')
RATELIMIT_FILE = os.path.join(tempfile.gettempdir(), 'dyson_jeedom_ratelimit.json')
MIN_DELAY      = 90


def out(data):
    print(json.dumps(data, ensure_ascii=False), flush=True)


def fail(message):
    out({'error': True, 'message': str(message)})
    sys.exit(1)


def save_json(path, data):
    with open(path, 'w') as f:
        json.dump(data, f)
    try:
        os.chmod(path, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IWGRP)
    except OSError:
        pass


def load_json(path):
    if not os.path.exists(path):
        return {}
    try:
        with open(path) as f:
            return json.load(f)
    except Exception:
        return {}


def remove_file(path):
    if os.path.exists(path):
        try:
            os.remove(path)
        except OSError:
            pass


def check_ratelimit():
    data    = load_json(RATELIMIT_FILE)
    last    = data.get('last_attempt', 0)
    elapsed = time.time() - last
    if elapsed < MIN_DELAY:
        remaining = int(MIN_DELAY - elapsed)
        fail(
            f"Patientez encore {remaining} secondes avant de redemander un code OTP.\n"
            "Dyson bloque les IPs qui font trop de demandes rapprochees."
        )


def get_client(email, password, country):
    from libdyson_rest import DysonClient
    return DysonClient(
        email=email,
        password=password,
        country=country,
        culture="fr-FR",
    )


def do_auth_init(email, password, country):
    try:
        from libdyson_rest.exceptions import DysonAuthError, DysonConnectionError, DysonAPIError
    except ImportError:
        fail("libdyson-rest non installe. Lancez l'installation des dependances.")

    check_ratelimit()

    try:
        client     = get_client(email, password, country)
        challenge  = client.begin_login()
        challenge_id = str(challenge.challenge_id)
    except DysonAuthError as e:
        fail(f"Authentification refusee : {str(e)}")
    except DysonAPIError as e:
        err = str(e)
        if '429' in err:
            fail("Trop de demandes OTP. Attendez quelques minutes avant de reessayer.")
        fail(f"Erreur API Dyson : {err}")
    except DysonConnectionError as e:
        fail(f"Erreur de connexion : {str(e)}")
    except Exception as e:
        fail(f"Erreur inattendue : {repr(e)}")

    save_json(RATELIMIT_FILE, {'last_attempt': time.time()})
    save_json(STATE_FILE, {
        'email':        email,
        'password':     password,
        'country':      country,
        'challenge_id': challenge_id,
        'ts':           time.time(),
    })

    out({'challengeId': challenge_id})


def do_auth_verify(otp):
    state = load_json(STATE_FILE)
    if not state:
        fail("Session perdue. Recommencez la decouverte depuis le debut.")

    if time.time() - state.get('ts', 0) > 600:
        remove_file(STATE_FILE)
        fail("OTP expire (10 minutes). Recommencez la decouverte.")

    email        = state['email']
    password     = state['password']
    country      = state['country']
    challenge_id = state['challenge_id']

    try:
        from libdyson_rest.exceptions import DysonAuthError, DysonConnectionError, DysonAPIError
    except ImportError:
        fail("libdyson-rest non installe.")

    try:
        client = get_client(email, password, country)
        # complete_login n'appelle PAS begin_login → pas de nouveau 429
        login_info = client.complete_login(
            challenge_id=challenge_id,
            otp_code=otp,
        )
    except DysonAuthError as e:
        err = str(e)
        if 'otp' in err.lower() or 'invalid' in err.lower() or 'expired' in err.lower():
            fail("Code OTP invalide ou expire. Recommencez la decouverte.")
        fail(f"Authentification refusee : {err}")
    except DysonAPIError as e:
        fail(f"Erreur API : {str(e)}")
    except DysonConnectionError as e:
        fail(f"Erreur connexion : {str(e)}")
    except Exception as e:
        fail(f"Erreur inattendue : {repr(e)}")

    # Recuperer les appareils avec le token obtenu
    try:
        devices_raw = client.get_devices()
    except Exception as e:
        fail(f"Erreur recuperation appareils : {str(e)}")

    devices = []
    for d in devices_raw:
        serial       = getattr(d, 'serial_number', '') or ''
        name         = getattr(d, 'name',          '') or 'Dyson ' + serial
        product_type = getattr(d, 'type',          '') or ''
        credential   = ''
        if getattr(d, 'connected_configuration', None) and d.connected_configuration.mqtt:
            credential = d.connected_configuration.mqtt.local_broker_credentials or ''
        devices.append({
            'serial':       serial,
            'name':         name,
            'product_type': product_type,
            'credentials':  str(credential),
        })

    remove_file(STATE_FILE)
    remove_file(RATELIMIT_FILE)

    out({'devices': devices})


def main():
    parser = argparse.ArgumentParser(description='Dyson cloud discovery pour Jeedom')
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
