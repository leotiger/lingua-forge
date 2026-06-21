#!/usr/bin/env bash
# build-zip.sh — Build a clean, installable lingua-forge plugin ZIP.
#
# Usage:
#   ./dev/build-zip.sh [output-dir]
#
# output-dir defaults to ~/Github/lingua-forge-deploy. The ZIP is always named
# lingua-forge-{version}.zip and always extracts to a lingua-forge/ folder.
#
# Examples:
#   ./dev/build-zip.sh                      # → ~/Github/lingua-forge-deploy/lingua-forge-1.6.5.zip
#   ./dev/build-zip.sh ~/Downloads          # → ~/Downloads/lingua-forge-1.6.5.zip

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="lingua-forge"

# Read version from lingua-forge.php
VERSION=$(grep -E "^\s*\*\s*Version:" "$PLUGIN_DIR/lingua-forge.php" | sed "s/.*Version:[[:space:]]*//" | tr -d '[:space:]')

# Output directory — first argument or ~/Github/lingua-forge-deploy
OUTPUT_DIR="${1:-$HOME/Github/lingua-forge-deploy}"
mkdir -p "$OUTPUT_DIR"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUTPUT_DIR/$ZIP_NAME"

# Build rsync exclusions from .distignore.
# Entries that start with a dot and contain no slash are file/dir patterns
# that can appear anywhere in the tree (e.g. .DS_Store, .gitkeep) — those
# get no leading slash so rsync matches them at any depth.
# Everything else gets a leading slash to anchor it to the plugin root.
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

# Always exclude .DS_Store anywhere in the tree (macOS metadata)
RSYNC_EXCLUDES+=(--exclude=".DS_Store")

# Copy into a temp directory named exactly 'lingua-forge' so the ZIP
# always extracts to lingua-forge/ regardless of the source path.
BUILD_DIR="$(mktemp -d)/build"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG"
rsync -a "${RSYNC_EXCLUDES[@]}" "$PLUGIN_DIR/" "$BUILD_DIR/$PLUGIN_SLUG/"

# Normalise permissions: 0755 for directories, 0644 for files.
# Matches the WordPress.org packaging standard and avoids shipping
# executable bits on PHP/JS/CSS files copied from a dev machine.
find "$BUILD_DIR/$PLUGIN_SLUG" -type d -exec chmod 0755 {} \;
find "$BUILD_DIR/$PLUGIN_SLUG" -type f -exec chmod 0644 {} \;

# Build the ZIP
cd "$BUILD_DIR"
zip -r "$ZIP_PATH" "$PLUGIN_SLUG/"

# Clean up
rm -rf "$BUILD_DIR"

echo "✓ Built: $ZIP_PATH"

# Compute SHA-256 of the ZIP and patch it into docs/lf-update-manifest.php.
# shasum -a 256 on macOS, sha256sum on Linux.
if command -v sha256sum &>/dev/null; then
    SHA256=$(sha256sum "$ZIP_PATH" | awk '{print $1}')
elif command -v shasum &>/dev/null; then
    SHA256=$(shasum -a 256 "$ZIP_PATH" | awk '{print $1}')
else
    echo "⚠  sha256sum / shasum not found — update \$sha256 in docs/lf-update-manifest.php manually." >&2
    SHA256=""
fi

MANIFEST="$PLUGIN_DIR/docs/lf-update-manifest.php"
if [[ -n "$SHA256" && -f "$MANIFEST" ]]; then
    # Replace `$sha256 = '...';` with the freshly computed digest.
    # Works whether the field was previously empty or already set.
    sed -i.bak "s/\\\$sha256 = '[^']*';/\$sha256 = '$SHA256';/" "$MANIFEST" && rm -f "${MANIFEST}.bak"
    echo "✓ SHA-256 written to docs/lf-update-manifest.php: $SHA256"
fi
