#!/bin/bash
# Installation des dépendances du plugin Dyson pour Jeedom

PLUGIN_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
REQUIREMENTS="$PLUGIN_DIR/dysonMqtt/requirements.txt"
VENV_DIR="$PLUGIN_DIR/python_venv"
PROGRESS_FILE="/tmp/jeedom/dyson/dependency"

mkdir -p "$(dirname "$PROGRESS_FILE")"
echo 0 > "$PROGRESS_FILE"

fail() {
    echo "ERREUR : $1"
    echo 100 > "$PROGRESS_FILE"
    exit 1
}

echo "=== Installation des dépendances Dyson ==="
echo "PLUGIN_DIR : $PLUGIN_DIR"

# ── Python 3 ──────────────────────────────────────────────────────────
PYTHON3=$(which python3 2>/dev/null)
if [ -z "$PYTHON3" ]; then
    fail "Python 3 non trouvé sur ce système"
fi
echo "Python système : $PYTHON3 ($($PYTHON3 --version 2>&1))"
echo 10 > "$PROGRESS_FILE"

# ── python3-venv (Debian 11 ne l'a pas toujours) ─────────────────────
if ! $PYTHON3 -m venv --help > /dev/null 2>&1; then
    echo "Installation de python3-venv..."
    apt-get install -y python3-venv 2>&1 || fail "Impossible d'installer python3-venv"
fi
echo 15 > "$PROGRESS_FILE"

# ── requirements.txt ─────────────────────────────────────────────────
if [ ! -f "$REQUIREMENTS" ]; then
    fail "Fichier requirements.txt introuvable : $REQUIREMENTS"
fi

# ── Permissions AVANT création du venv ───────────────────────────────
chown -R www-data:www-data "$PLUGIN_DIR" 2>/dev/null || true
echo 20 > "$PROGRESS_FILE"

# ── Virtualenv ────────────────────────────────────────────────────────
if [ ! -d "$VENV_DIR" ] || [ ! -f "$VENV_DIR/bin/python3" ]; then
    echo "Création du virtualenv dans $VENV_DIR ..."
    $PYTHON3 -m venv "$VENV_DIR" || fail "Impossible de créer le virtualenv"
    chown -R www-data:www-data "$VENV_DIR" 2>/dev/null || true
fi
echo 30 > "$PROGRESS_FILE"

# ── pip ───────────────────────────────────────────────────────────────
echo "Mise à jour de pip..."
"$VENV_DIR/bin/pip" install --upgrade pip --quiet 2>&1 \
    || fail "Impossible de mettre à jour pip"
echo 50 > "$PROGRESS_FILE"

# ── Paquets ───────────────────────────────────────────────────────────
echo "Installation des paquets Python..."
"$VENV_DIR/bin/pip" install -r "$REQUIREMENTS" --upgrade --quiet 2>&1 \
    || fail "Echec installation des paquets Python"
echo 80 > "$PROGRESS_FILE"

# ── Vérification ──────────────────────────────────────────────────────
echo "Vérification des imports..."
"$VENV_DIR/bin/python3" -c "import paho.mqtt.client, requests, cryptography, libdyson_rest" 2>&1 \
    || fail "Verification des imports echouee"
echo 90 > "$PROGRESS_FILE"

echo "Python venv : $("$VENV_DIR/bin/python3" --version)"
echo "Paquets installés :"
"$VENV_DIR/bin/pip" list --format=columns
echo "=== Dépendances installées avec succès ==="
echo 100 > "$PROGRESS_FILE"
