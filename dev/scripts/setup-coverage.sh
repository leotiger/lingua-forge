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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
DEV_DIR="$SCRIPT_DIR/.."
WP_ENV_BIN="$DEV_DIR/node_modules/.bin/wp-env"

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

# Find THIS project's tests-cli container by asking wp-env itself, rather
# than reimplementing its own container-resolution logic.
#
# Attempt 1 (original bug): `docker ps --filter "name=tests-cli" | head -1`
# — wp-env names containers with a hash of each project's own .wp-env.json
# path, so every wp-env project running on the machine gets its own
# hash-prefixed "tests-cli" container, and this filter matches ALL of them
# via plain substring matching with no project scoping. `head -1` silently
# grabbed whichever project's container Docker happened to list first.
#
# Attempt 2 (also wrong, confirmed live 2026-07-06): disambiguate by bind
# mount destination (`/var/www/html/wp-content/plugins/lingua-forge`) instead
# of by name. This looked right — every tests-cli container mounts its OWN
# plugin source at that path — but it's not actually unique: a *different*
# wp-env project (one that integrates with lingua-forge and mounts it as a
# dependency plugin, alongside its own woocommerce mount) ALSO mounts
# lingua-forge at that exact destination. Two containers satisfied the same
# mount-destination check, and the loop silently picked whichever `docker ps`
# listed first — the exact same class of bug as attempt 1, just one level
# deeper. Confirmed by comparing the hostname `wp-env run tests-cli` actually
# used against the container our own mount-matching loop picked: they
# differed, and the "other" project's container had no pcov installed.
#
# Fix: don't rediscover which container wp-env would use — ask it directly.
# `wp-env run tests-cli` is already correctly, deterministically scoped to
# the current project's own .wp-env.json (that's the one thing we can trust
# here, since it's what `composer coverage:run` itself actually calls), so
# running a trivial command through it and reading back the container's own
# hostname (which Docker defaults to the container's short ID) tells us
# exactly which container every other command in this script must also
# target, with no ambiguity possible.
if [[ ! -x "$WP_ENV_BIN" ]]; then
    echo "❌  wp-env not found at $WP_ENV_BIN — run npm install (from lingua-forge/dev/) first." >&2
    exit 1
fi

CONTAINER="$(
    "$WP_ENV_BIN" run tests-cli bash -c 'hostname' 2>/dev/null \
        | grep -oE '^[0-9a-f]{12}$' \
        | tail -1
)"

if [[ -z "$CONTAINER" ]]; then
    echo "❌  Could not determine this project's tests-cli container via 'wp-env run tests-cli'." >&2
    echo "    Run: npm run env:start (from lingua-forge/dev/)" >&2
    exit 1
fi

echo "ℹ  Container: $CONTAINER (resolved via wp-env run tests-cli)"

# NOTE: the composer.json integration-coverage command also passes
# `-d pcov.enabled=1 -d pcov.directory=...` directly on the CLI, so it
# doesn't strictly *depend* on the ini state this script writes for that one
# invocation — but this script is kept idempotent and self-correcting too,
# since other invocations (e.g. running phpunit by hand inside the
# container) still rely on the ini file being right. Only short-circuiting
# on "extension loaded" (without checking pcov.directory's actual value) is
# exactly how the earlier container-mismatch bug went undetected for so
# long: this script kept saying "pcov is already active" while quietly
# checking the wrong container. Always re-asserting the ini directives here
# — never trusting a pre-existing line still has the correct value — closes
# the same class of gap on the container this script now correctly resolves.
if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "ℹ  pcov extension already loaded — verifying ini configuration is still correct…"
else
    echo "ℹ  Installing pcov (this compiles from source, ~30 s)…"
    "$DOCKER" exec --user root "$CONTAINER" bash -c 'pecl install pcov 2>&1 | tail -3'
fi

"$DOCKER" exec --user root "$CONTAINER" bash -c '
    PHP_INI=/usr/local/etc/php/php.ini
    EXPECTED_DIR=/var/www/html/wp-content/plugins/lingua-forge

    grep -q "^extension=pcov.so" "$PHP_INI" || echo "extension=pcov.so" >> "$PHP_INI"

    if grep -q "^pcov.enabled=" "$PHP_INI"; then
        sed -i "s|^pcov.enabled=.*|pcov.enabled=1|" "$PHP_INI"
    else
        echo "pcov.enabled=1" >> "$PHP_INI"
    fi

    if grep -q "^pcov.directory=" "$PHP_INI"; then
        sed -i "s|^pcov.directory=.*|pcov.directory=${EXPECTED_DIR}|" "$PHP_INI"
    else
        echo "pcov.directory=${EXPECTED_DIR}" >> "$PHP_INI"
    fi

    echo "--- Active pcov config ---"
    php -i | grep -i "^pcov" || echo "⚠️  pcov module still not visible in php -i — extension may need a server restart."
'

if "$DOCKER" exec "$CONTAINER" php -m 2>/dev/null | grep -q pcov; then
    echo "✅  pcov installed, enabled, and pointed at the plugin directory in $CONTAINER"
else
    echo "❌  pcov still not active after setup — coverage will silently read as ~0%. Investigate manually." >&2
    exit 1
fi
