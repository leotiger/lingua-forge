#!/usr/bin/env bash
# make-pot.sh — regenerate languages/lingua-forge.pot
# Requires: php, curl (both standard on macOS / most Linux distros)
# No Docker, no global WP-CLI needed. wp-cli.phar is downloaded once to dev/
# and reused on subsequent runs.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEV_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_DIR="$(cd "$DEV_DIR/.." && pwd)"
PHAR="$DEV_DIR/wp-cli.phar"

if [ ! -f "$PHAR" ]; then
    echo "wp-cli.phar not found — downloading..."
    curl -sL "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" \
        -o "$PHAR"
    chmod +x "$PHAR"
    echo "Downloaded to dev/wp-cli.phar"
fi

echo "Generating POT file..."
php "$PHAR" i18n make-pot "$PLUGIN_DIR" \
    "$PLUGIN_DIR/languages/lingua-forge.pot" \
    --domain=lingua-forge \
    --exclude=dev

echo "Done: languages/lingua-forge.pot"
