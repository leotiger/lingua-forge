#!/usr/bin/env bash
# Make integration coverage reports available locally for coverage:merge.
#
# Two scenarios:
#   A) wp-env uses a bind-mount for the plugin directory — PHPUnit has already
#      written the files to the host filesystem; nothing to copy.
#   B) The container has its own overlay (non-bind-mount) — use docker cp.
#
# Either way, coverage:merge must be able to run, so this script exits 0 even
# when files cannot be located, printing a warning instead of aborting the
# pipeline.

set -uo pipefail   # -e intentionally omitted: we warn, not abort, on failure

LOCAL_COVERAGE="$(cd "$(dirname "$0")/.." && pwd)/coverage/integration"
mkdir -p "$LOCAL_COVERAGE"

FILES=(clover.xml coverage.txt)

# ------------------------------------------------------------------
# Scenario A: files already present locally (bind-mount).
# ------------------------------------------------------------------
ALL_LOCAL=true
for f in "${FILES[@]}"; do
    [[ -f "${LOCAL_COVERAGE}/${f}" ]] || { ALL_LOCAL=false; break; }
done

if [[ "$ALL_LOCAL" == true ]]; then
    echo "✅  Integration coverage already present locally (bind-mount) at ${LOCAL_COVERAGE}"
    exit 0
fi

# ------------------------------------------------------------------
# Scenario B: try docker cp from the tests-cli container.
# ------------------------------------------------------------------
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

CONTAINER=""
if [[ -n "$DOCKER" ]]; then
    CONTAINER=$("$DOCKER" ps --filter "name=tests-cli" --format "{{.Names}}" 2>/dev/null | head -1)
fi

CONTAINER_COVERAGE="/var/www/html/wp-content/plugins/lingua-forge/dev/coverage/integration"
COPIED=0

if [[ -n "$CONTAINER" && -n "$DOCKER" ]]; then
    for f in "${FILES[@]}"; do
        if "$DOCKER" cp "${CONTAINER}:${CONTAINER_COVERAGE}/${f}" "${LOCAL_COVERAGE}/${f}" 2>/dev/null; then
            COPIED=$((COPIED + 1))
        fi
    done
    if [[ "$COPIED" -gt 0 ]]; then
        echo "✅  Integration coverage copied from ${CONTAINER} (${COPIED}/${#FILES[@]} files)"
        exit 0
    fi
fi

# ------------------------------------------------------------------
# Neither path worked — warn but let coverage:merge decide what to do.
# ------------------------------------------------------------------
echo "⚠️   Integration coverage files not found locally or in container." >&2
echo "    Expected: ${LOCAL_COVERAGE}/{clover.xml,coverage.txt}" >&2
echo "    coverage:merge will run but may produce an incomplete combined report." >&2
exit 0
