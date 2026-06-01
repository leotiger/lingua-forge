#!/usr/bin/env bash
# =============================================================================
# setup-coverage.sh — install pcov in the wp-env tests-cli container.
#
# Run once after `npm run env:start`. pcov is lost when the container is
# rebuilt; re-run this script whenever you restart the environment.
#
# Usage (from lingua-forge/dev/):
#   bash scripts/setup-coverage.sh
#   composer coverage:setup          ← calls this script
# =============================================================================

set -euo pipefail

# Locate the docker binary — Docker Desktop on macOS installs it here.
DOCKER="${DOCKER_BIN:-}"
if [[ -z "$DOCKER" ]]; then
    for candidate in \
        /Applications/Docker.app/Contents/Resources/bin/docker \
        /usr/local/bin/docker \
        /opt/homebrew/bin/docker \
        docker
    do
        if command -v "$candidate" &>/dev/null 2>&1 || [[ -x "$candidate" ]]; then
            DOCKER="$candidate"
            break
        fi
    done
fi

if [[ -z "$DOCKER" ]]; then
    echo "❌  docker not found. Start Docker Desktop and try again." >&2
    exit 1
fi

# Find the tests-cli container (wp-env names it with a project hash).
CONTAINER=$("$DOCKER" ps --filter "name=tests-cli" --format "{{.Names}}" 2>/dev/null | head -1)
if [[ -z "$CONTAINER" ]]; then
    echo "❌  wp-env tests-cli container is not running." >&2
    echo "    Run: npm run env:start" >&2
    exit 1
fi

echo "ℹ  Container: $CONTAINER"

# Check if pcov is already loaded.
if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "✅  pcov is already active — nothing to do."
    exit 0
fi

echo "ℹ  Installing pcov (this compiles from source, ~30 s)…"
"$DOCKER" exec --user root "$CONTAINER" bash -c '
    pecl install pcov 2>&1 | tail -3
    PHP_INI=/usr/local/etc/php/php.ini
    grep -q "extension=pcov.so" "$PHP_INI" || echo "extension=pcov.so"   >> "$PHP_INI"
    grep -q "pcov.enabled"      "$PHP_INI" || echo "pcov.enabled=1"       >> "$PHP_INI"
    grep -q "pcov.directory"    "$PHP_INI" || echo "pcov.directory=/var/www/html/wp-content/plugins/lingua-forge" >> "$PHP_INI"
    php -m | grep pcov
'

echo "✅  pcov installed and enabled in $CONTAINER"
