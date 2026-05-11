#!/usr/bin/env python3
"""
Scan réseau local pour trouver les appareils Dyson via mDNS/Zeroconf.
Retourne la liste des appareils avec leur IP, port et serial.
"""

import json
import sys
import socket
import time

def out(data):
    print(json.dumps(data, ensure_ascii=False), flush=True)

def fail(message):
    out({'error': True, 'message': str(message)})
    sys.exit(1)

def scan(timeout=5):
    try:
        from zeroconf import Zeroconf, ServiceBrowser
    except ImportError:
        fail("zeroconf non installe. Lancez l'installation des dependances.")

    devices = {}

    class Listener:
        def add_service(self, zc, type_, name):
            info = zc.get_service_info(type_, name)
            if not info or not info.addresses:
                return
            try:
                ip   = socket.inet_ntoa(info.addresses[0])
                port = info.port
                # Nom format : "897_M4P-EU-UKA4182A._dyson_mqtt._tcp.local."
                parts      = name.replace('._dyson_mqtt._tcp.local.', '').split('_', 1)
                product_type = parts[0] if len(parts) >= 1 else ''
                serial       = parts[1] if len(parts) >= 2 else name
                devices[serial] = {
                    'serial':       serial,
                    'product_type': product_type,
                    'ip':           ip,
                    'port':         port,
                }
            except Exception:
                pass

        def update_service(self, zc, type_, name):
            self.add_service(zc, type_, name)

        def remove_service(self, zc, type_, name):
            pass

    zc       = Zeroconf()
    listener = Listener()
    browser  = ServiceBrowser(zc, '_dyson_mqtt._tcp.local.', listener)  # noqa
    time.sleep(timeout)
    zc.close()

    return list(devices.values())


if __name__ == '__main__':
    try:
        results = scan(timeout=5)
        out({'devices': results})
    except Exception as e:
        fail(str(e))
