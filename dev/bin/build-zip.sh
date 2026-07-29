#!/usr/bin/env bash
# =============================================================================
# build-zip.sh — Package Lingua Forge for distribution.
#
# Usage (from dev/):
#   composer build-zip
# or directly:
#   ./dev/bin/build-zip.sh [output-dir]
#
# Uses rsync + .distignore so uncommitted changes are included and dev-only
# files are naturally excluded. No `composer install --no-dev` step is needed
# first — the plugin ships with zero runtime Composer dependencies (see
# CONTRIBUTING.md).
#
# Also maintains docs/lf-update-manifest.php in full — no field needs hand-
# setting after a run except the changelog HTML block itself:
#
#   - $version / $download_url — written immediately, right after $VERSION is
#     read from the plugin header below, before the zip is even built. Both
#     are pure metadata (this plugin's own version string, and a GitHub
#     release-asset URL computed from it + $PLUGIN_SLUG) with no "unverified
#     until built" risk the way a digest has, so there's no reason to gate
#     them on the build actually succeeding — same reasoning Agnosis's own
#     build-zip.sh applies to $last_updated, extended here to these two as
#     well since this script already has everything it needs to compute them
#     with no manual input.
#   - $sha256:
#       1. Cleared to '' at the very START of the run, before the zip is even
#          built. A stale digest left over from a previous run is worse than
#          an empty one — an empty $sha256 just skips verification (a
#          documented, safe default); a stale one looks valid but silently
#          stops matching the zip that actually gets uploaded if this run
#          fails partway through or the resulting zip is rebuilt/replaced
#          afterward. Clearing first means any failure below leaves the
#          manifest in the same safe "unset" state it would be in before a
#          release was ever attempted, not a misleading leftover from a
#          previous one.
#       2. Set to the freshly-built zip's real sha256sum once the build succeeds.
#   - $last_updated — set to today's UTC date once the build succeeds, same
#     trigger point as $sha256 (ported from Agnosis's own build-zip.sh, added
#     there 2026-07-22: the script already knows today's date and the
#     documented release process builds the zip immediately before shipping
#     it, so there's no real reason to keep this a separate hand-set field).
#     Unlike $sha256 it is NOT cleared at the start of a run — a failed/
#     interrupted build should leave the previous successful build's date in
#     place, not blank it, since there's no "silently wrong" risk for a plain
#     display date the way there is for a digest.
#
# The changelog HTML block is the one thing still updated by hand — it's
# prose, not a derivable value.
#
# Output: ~/Github/lingua-forge-deploy/lingua-forge-<version>.zip
#     (or [output-dir]/lingua-forge-<version>.zip)
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PLUGIN_SLUG="lingua-forge"
MANIFEST="$PLUGIN_DIR/docs/lf-update-manifest.php"

# --- Read version from the main plugin file. --------------------------------
VERSION=$(grep -E "^\s*\*\s*Version:" "$PLUGIN_DIR/$PLUGIN_SLUG.php" | sed "s/.*Version:[[:space:]]*//" | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
    echo "ERROR: could not read plugin version from $PLUGIN_SLUG.php" >&2
    exit 1
fi

# Output directory — first argument or ~/Github/lingua-forge-deploy
OUTPUT_DIR="${1:-$HOME/Github/lingua-forge-deploy}"
mkdir -p "$OUTPUT_DIR"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUTPUT_DIR/$ZIP_NAME"
DOWNLOAD_URL="https://github.com/leotiger/lingua-forge/releases/download/v${VERSION}/${ZIP_NAME}"

echo "==> Building $ZIP_NAME"

# --- Write $version / $download_url — pure metadata, no build required ------
write_manifest_version_and_url() {
    if [ ! -f "$MANIFEST" ]; then
        echo "WARNING: manifest not found at $MANIFEST — skipping version/download_url update." >&2
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]version[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$version assignment in $(basename "$MANIFEST") — set it manually." >&2
    else
        sed -i.bak -E "s/^([[:space:]]*[$]version[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1'${VERSION}'\2/" "$MANIFEST"
        rm -f "$MANIFEST.bak"
    fi
    if ! grep -qE "^[[:space:]]*[$]download_url[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$download_url assignment in $(basename "$MANIFEST") — set it manually." >&2
    else
        sed -i.bak -E "s|^([[:space:]]*[$]download_url[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)|\1'${DOWNLOAD_URL}'\2|" "$MANIFEST"
        rm -f "$MANIFEST.bak"
    fi
    echo "--> Version: $VERSION"
    echo "--> Download URL: $DOWNLOAD_URL"
    echo "✓ Wrote \$version / \$download_url into $(basename "$MANIFEST")"
}
write_manifest_version_and_url

# --- Clear the manifest's $sha256 before doing anything else ----------------
clear_manifest_sha() {
    if [ ! -f "$MANIFEST" ]; then
        echo "WARNING: manifest not found at $MANIFEST — skipping sha256 clear." >&2
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]sha256[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$sha256 assignment in $(basename "$MANIFEST") — skipping clear." >&2
        return
    fi
    # NOTE: the dollar sign is matched via the bracket expression [$], not \$ —
    # \$ is not reliable as a literal-dollar escape in `sed -E`'s extended
    # regex (confirmed: it worked fine in `grep -E` above but silently failed
    # to match in `sed -E`, so the substitution never fired at all despite sed
    # exiting 0). [$] matches a literal '$' unambiguously in both grep and sed,
    # GNU and BSD alike.
    sed -i.bak -E "s/^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1''\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> Cleared \$sha256 in $(basename "$MANIFEST")"
}
clear_manifest_sha

# --- Build rsync exclusions from .distignore. --------------------------------
# Dot-only filenames (e.g. .DS_Store) match anywhere in the tree.
# Everything else is anchored to the plugin root with a leading slash.
RSYNC_EXCLUDES=()
while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*$ ]] && continue
    [[ "$line" =~ ^# ]] && continue
    if [[ "$line" == .* && "$line" != */* ]]; then
        RSYNC_EXCLUDES+=(--exclude="$line")
    else
        RSYNC_EXCLUDES+=(--exclude="/${line}")
    fi
done < "$PLUGIN_DIR/.distignore"

# Always exclude macOS metadata.
RSYNC_EXCLUDES+=(--exclude=".DS_Store")

# Copy into a temp dir named exactly 'lingua-forge' so the ZIP always
# extracts to lingua-forge/ regardless of the source path.
BUILD_DIR="$(mktemp -d)/build"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"
rsync -a "${RSYNC_EXCLUDES[@]}" "$PLUGIN_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

# Normalise permissions: 0755 dirs, 0644 files.
find "$BUILD_DIR/$PLUGIN_SLUG" -type d -exec chmod 0755 {} \;
find "$BUILD_DIR/$PLUGIN_SLUG" -type f -exec chmod 0644 {} \;

# Build the ZIP. Remove any pre-existing file at the destination first —
# `zip -r` merges into an existing archive rather than replacing it, which
# would leave stale entries behind from a previous build at the same path.
rm -f "$ZIP_PATH"
( cd "$BUILD_DIR" && zip -r "$ZIP_PATH" "$PLUGIN_SLUG/" >/dev/null )
rm -rf "$BUILD_DIR"

echo "✓ Built: $ZIP_PATH"

# --- Compute sha256 and write it into the manifest ---------------------------
write_manifest_sha() {
    if [ ! -f "$MANIFEST" ]; then
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]sha256[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$sha256 assignment in $(basename "$MANIFEST") — set it manually." >&2
        return
    fi
    local digest
    digest=$(shasum -a 256 "$ZIP_PATH" 2>/dev/null | awk '{print $1}')
    if [ -z "$digest" ]; then
        digest=$(sha256sum "$ZIP_PATH" | awk '{print $1}')
    fi
    # See the [$] note in clear_manifest_sha() above — same reasoning applies here.
    sed -i.bak -E "s/^([[:space:]]*[$]sha256[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1'${digest}'\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> SHA-256: $digest"
    echo "✓ Wrote sha256 into $(basename "$MANIFEST")"
}
write_manifest_sha

# --- Write today's date into $last_updated -----------------------------------
# Ported from Agnosis's own build-zip.sh (added there 2026-07-22) — see the
# header comment above for the reasoning. Same trigger point as $sha256:
# only written once the build actually succeeds; not cleared at the start of
# a run.
write_manifest_last_updated() {
    if [ ! -f "$MANIFEST" ]; then
        return
    fi
    if ! grep -qE "^[[:space:]]*[$]last_updated[[:space:]]*=" "$MANIFEST"; then
        echo "WARNING: could not find a \$last_updated assignment in $(basename "$MANIFEST") — set it manually." >&2
        return
    fi
    local today
    today=$(date -u +%Y-%m-%d)
    sed -i.bak -E "s/^([[:space:]]*[$]last_updated[[:space:]]*=[[:space:]]*)'[^']*'([[:space:]]*;)/\1'${today}'\2/" "$MANIFEST"
    rm -f "$MANIFEST.bak"
    echo "--> Last updated: $today"
    echo "✓ Wrote \$last_updated into $(basename "$MANIFEST")"
}
write_manifest_last_updated

echo ""
echo "  Contents preview:"
unzip -l "$ZIP_PATH" | awk 'NR>3 && NR<=33'

echo ""
echo "Remaining manual steps:"
echo "  1. Upload $ZIP_NAME to the v$VERSION GitHub release."
echo "  2. Write the changelog HTML block in $(basename "$MANIFEST") by hand — the one thing this script doesn't automate."
echo "  3. Deploy $(basename "$MANIFEST") to wp-content/mu-plugins/ on lingua-forge.com."
