#!/usr/bin/env bash
# make-pot.sh — extract source strings and merge into .po files
#
# Pipeline steps covered:
#   1. wp i18n make-pot  → languages/lingua-forge.pot
#   2. msgmerge --update → merges new/changed strings into each .po
#                          (existing translations kept; new strings untranslated;
#                           changed source strings marked #, fuzzy)
#   3. clear-fuzzy.php   → blanks every fuzzy msgstr and strips the fuzzy
#                          flag, so each one starts clean instead of
#                          carrying a stale, possibly-wrong auto-matched
#                          guess into Loco Translate
#
# After this script, translate the newly-blank and new strings in the
# .po files, then run: composer compile-pos
#
# Requires: php, curl, msgmerge (gettext)
#   macOS:  brew install gettext && brew link gettext --force
#   Ubuntu: apt-get install gettext

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEV_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_DIR="$(cd "$DEV_DIR/.." && pwd)"
LANG_DIR="$PLUGIN_DIR/languages"
PHAR="$DEV_DIR/wp-cli.phar"
POT="$LANG_DIR/lingua-forge.pot"

LOCALES=(
    ar ca de_DE el en_US es_ES eu fa_IR fr_FR hi_IN
    hu_HU id_ID it_IT ja km ko_KR nl_NL pl_PL pt_PT
    ru_RU sv_SE sw th tr_TR ur zh_CN
)

# ── 1. Download WP-CLI if needed ────────────────────────────────────────────

if [ ! -f "$PHAR" ]; then
    echo "wp-cli.phar not found — downloading..."
    curl -sL "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" \
        -o "$PHAR"
    chmod +x "$PHAR"
    echo "Downloaded to dev/wp-cli.phar"
fi

# ── 2. Generate POT ──────────────────────────────────────────────────────────

echo "Generating POT file..."
php "$PHAR" i18n make-pot "$PLUGIN_DIR" \
    "$POT" \
    --domain=lingua-forge \
    --exclude=dev
echo "  ✓ languages/lingua-forge.pot"

# ── 3. Merge new/changed strings into each .po ──────────────────────────────

if ! command -v msgmerge &>/dev/null; then
    echo ""
    echo "⚠  msgmerge not found — skipping .po merge."
    echo "   macOS:  brew install gettext && brew link gettext --force"
    echo "   Ubuntu: apt-get install gettext"
    echo ""
    echo "Translate new strings manually, then run: composer compile-pos"
else
    echo "Merging into .po files..."
    for LOCALE in "${LOCALES[@]}"; do
        PO="$LANG_DIR/lingua-forge-${LOCALE}.po"

        if [ ! -f "$PO" ]; then
            echo "  ⚠  $PO not found — skipping $LOCALE"
            continue
        fi

        msgmerge --update --backup=none --quiet "$PO" "$POT"
        echo "  ✓ languages/lingua-forge-${LOCALE}.po  (merged)"
    done

    # ── 4. Clear fuzzy matches ───────────────────────────────────────────
    # msgmerge's auto-matched "#, fuzzy" guesses are easy to miss in Loco
    # Translate (a stale translation just sits there looking legitimate).
    # Blank them so every fuzzy string starts as plainly untranslated.
    echo ""
    echo "Clearing fuzzy matches..."
    php "$SCRIPT_DIR/clear-fuzzy.php" "$LANG_DIR"/lingua-forge-*.po

    echo ""
    echo "Translate the new and now-blank strings in the .po files,"
    echo "then run: composer compile-pos"
fi

echo ""
echo "Done."
