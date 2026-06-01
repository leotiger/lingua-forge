#!/usr/bin/env bash
# Copy integration coverage reports from the wp-env tests-cli container to
# the local dev/coverage/integration/ directory so coverage:merge can read them.

set -euo pipefail

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
    echo "❌  docker not found." >&2; exit 1
fi

CONTAINER=$("$DOCKER" ps --filter "name=tests-cli" --format "{{.Names}}" 2>/dev/null | head -1)
if [[ -z "$CONTAINER" ]]; then
    echo "❌  wp-env tests-cli container not running." >&2; exit 1
fi

CONTAINER_COVERAGE="/var/www/html/wp-content/plugins/lingua-forge/dev/coverage/integration"
LOCAL_COVERAGE="$(dirname "$0")/../coverage/integration"

mkdir -p "$LOCAL_COVERAGE"

"$DOCKER" cp "${CONTAINER}:${CONTAINER_COVERAGE}/clover.xml"   "${LOCAL_COVERAGE}/clover.xml"
"$DOCKER" cp "${CONTAINER}:${CONTAINER_COVERAGE}/coverage.txt" "${LOCAL_COVERAGE}/coverage.txt"

echo "✅  Integration coverage copied from ${CONTAINER}"
