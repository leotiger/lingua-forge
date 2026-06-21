#!/usr/bin/env bash
# compile-pos.sh — compile translated .po files into .mo and .l10n.php
#
# Pipeline steps covered:
#   4. msgfmt           → languages/lingua-forge-{locale}.mo
#   5. wp i18n make-php → languages/lingua-forge-{locale}.l10n.php
#
# Run after translating new/fuzzy strings in the .po files.
# To extract new strings first: composer make-pot
#
# Requires: php, curl, msgfmt (gettext)
#   macOS:  brew install gettext && brew link gettext --force
#   Ubuntu: apt-get install gettext

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEV_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_DIR="$(cd "$DEV_DIR/.." && pwd)"
LANG_DIR="$PLUGIN_DIR/languages"
PHAR="$DEV_DIR/wp-cli.phar"

LOCALES=(
    ar ca de_DE el en_US es_ES eu fa_IR fr_FR hi_IN
    hu_HU id_ID it_IT ja km ko_KR nl_NL pl_PL pt_PT
    ru_RU sv_SE sw th tr_TR ur zh_CN
)

# ── Download WP-CLI if needed ────────────────────────────────────────────────

if [ ! -f "$PHAR" ]; then
    echo "wp-cli.phar not found — downloading..."
    curl -sL "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" \
        -o "$PHAR"
    chmod +x "$PHAR"
    echo "Downloaded to dev/wp-cli.phar"
fi

# ── Compile ──────────────────────────────────────────────────────────────────

if ! command -v msgfmt &>/dev/null; then
    echo ""
    echo "⚠  msgfmt not found — cannot compile .mo files."
    echo "   macOS:  brew install gettext && brew link gettext --force"
    echo "   Ubuntu: apt-get install gettext"
    echo ""
    exit 1
fi

echo "Compiling .po files..."
for LOCALE in "${LOCALES[@]}"; do
    PO="$LANG_DIR/lingua-forge-${LOCALE}.po"
    MO="$LANG_DIR/lingua-forge-${LOCALE}.mo"

    if [ ! -f "$PO" ]; then
        echo "  ⚠  $PO not found — skipping $LOCALE"
        continue
    fi

    # Compile to binary .mo (what WordPress loads at runtime).
    msgfmt "$PO" -o "$MO"
    echo "  ✓ languages/lingua-forge-${LOCALE}.mo"

    # Generate PHP cache (make-php expects a directory, not a file path).
    php "$PHAR" i18n make-php "$PO" "$LANG_DIR"
    echo "  ✓ languages/lingua-forge-${LOCALE}.l10n.php"
done

echo ""
echo "Done. Translations are now active."
