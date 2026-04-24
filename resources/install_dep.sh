#!/bin/bash
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
REQUIREMENTS="$PLUGIN_DIR/dysonMqtt/requirements.txt"
VENV_DIR="$PLUGIN_DIR/python_venv"
PROGRESS_FILE="/tmp/jeedom/dyson/dependency"

mkdir -p "$(dirname "$PROGRESS_FILE")"
echo 0 > "$PROGRESS_FILE"

echo "=== Installation des dépendances Dyson ==="

PYTHON3=$(which python3 2>/dev/null)
if [ -z "$PYTHON3" ]; then
    echo "ERREUR : Python 3 non trouvé"
    echo 100 > "$PROGRESS_FILE"
    exit 1
fi

echo "Python système : $PYTHON3 ($($PYTHON3 --version 2>&1))"
echo 10 > "$PROGRESS_FILE"

# Création du virtualenv si absent
if [ ! -d "$VENV_DIR" ]; then
    echo "Création du virtualenv dans $VENV_DIR ..."
    $PYTHON3 -m venv "$VENV_DIR"
fi
echo 40 > "$PROGRESS_FILE"

# Installation des dépendances dans le venv
echo "Installation des paquets Python dans le venv..."
"$VENV_DIR/bin/pip" install --upgrade pip --quiet
echo 60 > "$PROGRESS_FILE"

"$VENV_DIR/bin/pip" install -r "$REQUIREMENTS" --upgrade --quiet
echo 90 > "$PROGRESS_FILE"

echo "Python venv : $($VENV_DIR/bin/python3 --version)"
echo "Paquets installés :"
"$VENV_DIR/bin/pip" list --format=columns

echo "=== Dépendances installées avec succès ==="
echo 100 > "$PROGRESS_FILE"
