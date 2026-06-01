#!/usr/bin/env bash
# seed-dev-env.sh
#
# Idempotent dev-environment setup for the Lingua Forge wp-env instance.
# Run from lingua-forge/dev/ after `npm run env:start`:
#
#   npm run env:seed
#
# Safe to re-run — all WP-CLI commands are idempotent or guarded with checks.
# Nothing here touches production; it only affects the local wp-env container.

set -euo pipefail

WP="./node_modules/.bin/wp-env run cli wp"

echo "── Lingua Forge dev seed ────────────────────────────────────────────────"

# ── Permalink structure ───────────────────────────────────────────────────────
# Required for language URL routing (/en/, /de/, etc.) to work.
# Without this the router's rewrite rules never fire.
echo "  Setting permalink structure …"
$WP option update permalink_structure '/%postname%/' --quiet
$WP rewrite flush --quiet

# ── Site identity ─────────────────────────────────────────────────────────────
echo "  Setting site identity …"
$WP option update blogname      'Lingua Forge Dev' --quiet
$WP option update blogdescription 'Local wp-env instance' --quiet

# ── Lingua Forge: language router options ─────────────────────────────────────
# Source language: English.
# Routing mode: path prefix (/en/, /de/, /ca/).
echo "  Configuring Lingua Forge router …"
$WP option update linguaforge_primary_language 'en'     --quiet
$WP option update linguaforge_routing_mode     'path'   --quiet

# ── FSE (block) theme ────────────────────────────────────────────────────────
# Twenty Twenty-Four ships with WordPress and is a full-site-editing theme.
# Activating it enables the FSE scaffold/translate/link-fix pipeline in the
# Router tab and lets the FSE E2E tests run instead of being skipped.
echo "  Activating FSE theme (twentytwentyfour) …"
$WP theme activate twentytwentyfour --quiet 2>/dev/null \
    || $WP theme install twentytwentyfour --activate --quiet \
    || echo "    ↳ twentytwentyfour not available — FSE tests will be skipped."

# ── Dev mu-plugin: force DE + CA into the router language list ───────────────
# Written directly into the container so it loads regardless of Docker volume
# mount behaviour. Safe to re-run — file_put_contents overwrites idempotently.
echo "  Installing dev mu-plugin (lf-dev-env.php) …"
$WP eval '
$mu_dir = WPMU_PLUGIN_DIR;
if ( ! is_dir( $mu_dir ) ) {
    wp_mkdir_p( $mu_dir );
}
$php = "<?php\n/**\n * Dev-only mu-plugin — forces DE and CA into the router language list.\n * Written by seed-dev-env.sh; never ships to production.\n */\nadd_filter( \"lf_languages_list\", function ( array \$langs ): array {\n    return array_values( array_unique( array_merge( \$langs, [ \"de\", \"ca\" ] ) ) );\n} );\n";
file_put_contents( $mu_dir . "/lf-dev-env.php", $php );
echo "  ✓ lf-dev-env.php written to " . $mu_dir . "\n";
'

# ── Install language packs so the router discovers DE + CA ───────────────────
# The router auto-discovers active languages from get_available_languages().
# The mu-plugin above guarantees DE/CA appear in the language list even when
# pack downloads fail (e.g. slow container network).
echo "  Installing language packs (de_DE, ca) …"
$WP language core install de_DE || true
$WP language core install ca    || true

# ── Sample content ────────────────────────────────────────────────────────────
# Create a small set of pages in each language so translation workflows,
# link-fixer, and the lang column can be exercised without manual setup.
# Guard with a meta query so re-runs skip already-created posts.

# Returns the page ID on stdout; progress goes to stderr.
create_page_if_missing() {
    local title="$1"
    local lang="$2"
    local content="$3"

    local existing
    existing=$($WP post list \
        --post_type=page \
        --post_status=publish \
        --meta_key=_lf_lang \
        --meta_value="$lang" \
        --fields=ID \
        --format=ids \
        --search="$title" \
        --quiet 2>/dev/null || true)

    if [ -n "$existing" ]; then
        echo "    ↳ \"$title\" ($lang) already exists (ID $existing), skipping." >&2
        echo "$existing"
        return
    fi

    local id
    id=$($WP post create \
        --post_type=page \
        --post_status=publish \
        --post_title="$title" \
        --post_content="$content" \
        --porcelain \
        --quiet)

    $WP post meta set "$id" _lf_lang "$lang" --quiet
    echo "    ↳ Created \"$title\" ($lang) → ID $id" >&2
    echo "$id"
}

# Apply a shared TRID to a space-separated list of post IDs.
# Reuses any existing TRID found in the group; generates a fresh UUID otherwise.
link_translation_group() {
    local ids="$*"
    $WP eval '
$ids  = array_filter( array_map( "intval", explode( " ", trim( "'"$ids"'" ) ) ) );
$trid = "";
foreach ( $ids as $pid ) {
    $existing = get_post_meta( $pid, "_lf_trid", true );
    if ( $existing ) { $trid = $existing; break; }
}
if ( ! $trid ) { $trid = wp_generate_uuid4(); }
foreach ( $ids as $pid ) {
    update_post_meta( $pid, "_lf_trid", $trid );
}
echo "    ↳ Linked IDs " . implode( ", ", $ids ) . " → trid=" . $trid . "\n";
'
}

echo "  Creating sample pages …"

# ── Source-only page (EN only, no translations) ───────────────────────────────
# Used by the E2E "Translate missing" button test.  The page has a _lf_trid so
# the plugin knows DE and CA are expected but absent — making the button appear.
echo "  Creating source-only 'Services' page (for Translate-missing E2E test) …"
$WP eval '
$existing = get_page_by_path( "services", OBJECT, "page" );
if ( $existing ) {
    echo "    already exists (ID " . $existing->ID . "), skipping.\n";
} else {
    $id = wp_insert_post( [
        "post_type"    => "page",
        "post_status"  => "publish",
        "post_title"   => "Services",
        "post_name"    => "services",
        "post_content" => "<!-- wp:paragraph --><p>Our services — English version.</p><!-- /wp:paragraph -->",
    ], true );
    if ( is_wp_error( $id ) ) {
        echo "    ERROR: " . $id->get_error_message() . "\n";
    } else {
        $trid = wp_generate_uuid4();
        update_post_meta( $id, "_lf_lang", "en" );
        update_post_meta( $id, "_lf_trid", $trid );
        echo "    created ID " . $id . " trid=" . $trid . "\n";
    }
}
'

# English originals
EN_HOME=$(    create_page_if_missing "Home"    "en" "<!-- wp:paragraph --><p>Welcome to the Lingua Forge dev site.</p><!-- /wp:paragraph -->")
EN_ABOUT=$(   create_page_if_missing "About"   "en" "<!-- wp:paragraph --><p>About us — English version.</p><!-- /wp:paragraph -->")
EN_CONTACT=$( create_page_if_missing "Contact" "en" "<!-- wp:paragraph --><p>Contact us — English version.</p><!-- /wp:paragraph -->")

# German translations
DE_HOME=$(    create_page_if_missing "Startseite" "de" "<!-- wp:paragraph --><p>Willkommen auf der Lingua Forge Entwicklungsseite.</p><!-- /wp:paragraph -->")
DE_ABOUT=$(   create_page_if_missing "Über uns"   "de" "<!-- wp:paragraph --><p>Über uns — Deutsche Version.</p><!-- /wp:paragraph -->")
DE_CONTACT=$( create_page_if_missing "Kontakt"    "de" "<!-- wp:paragraph --><p>Kontaktieren Sie uns — Deutsche Version.</p><!-- /wp:paragraph -->")

# Catalan translations
CA_HOME=$(    create_page_if_missing "Inici"    "ca" "<!-- wp:paragraph --><p>Benvinguts al lloc de desenvolupament de Lingua Forge.</p><!-- /wp:paragraph -->")
CA_ABOUT=$(   create_page_if_missing "Qui som"  "ca" "<!-- wp:paragraph --><p>Qui som — Versió catalana.</p><!-- /wp:paragraph -->")
CA_CONTACT=$( create_page_if_missing "Contacte" "ca" "<!-- wp:paragraph --><p>Contacteu-nos — Versió catalana.</p><!-- /wp:paragraph -->")

# Link each translation group with a shared TRID.
echo "  Linking page translation groups …"
link_translation_group "$EN_HOME $DE_HOME $CA_HOME"
link_translation_group "$EN_ABOUT $DE_ABOUT $CA_ABOUT"
link_translation_group "$EN_CONTACT $DE_CONTACT $CA_CONTACT"

# ── WooCommerce sample products ──────────────────────────────────────────────
# Only runs when WooCommerce is active (requires .wp-env.override.json + env:start).
# Creates one source EN product and DE/CA translation stubs linked by a shared
# _lf_trid so the WC delegation layer (MetaDelegate, StockRouter, etc.) can be
# exercised manually. The source product carries real WC meta; the translation
# stubs carry only content fields — exactly how the delegation model works in
# production.

# Helper: create a WC product (post_type=product) if none with this lang+slug exists.
# Writes the product ID to stdout; progress messages go to stderr.
create_product_if_missing() {
    local title="$1"
    local lang="$2"
    local slug="$3"
    local price="$4"   # empty string = no price meta (translation stub)
    local desc="$5"

    local existing
    existing=$($WP post list \
        --post_type=product \
        --post_status=publish \
        --meta_key=_lf_lang \
        --meta_value="$lang" \
        --name="$slug" \
        --fields=ID \
        --format=ids \
        --quiet 2>/dev/null || true)

    if [ -n "$existing" ]; then
        echo "    ↳ \"$title\" ($lang) already exists (ID $existing), skipping." >&2
        echo "$existing"
        return
    fi

    local id
    id=$($WP post create \
        --post_type=product \
        --post_status=publish \
        --post_title="$title" \
        --post_name="$slug" \
        --post_content="$desc" \
        --porcelain \
        --quiet)

    # Minimum WC meta so the product renders without warnings in the admin.
    $WP post meta set "$id" _visibility   'visible'  --quiet
    $WP post meta set "$id" _stock_status 'instock'  --quiet
    $WP post meta set "$id" _manage_stock 'no'       --quiet

    if [ -n "$price" ]; then
        $WP post meta set "$id" _price         "$price" --quiet
        $WP post meta set "$id" _regular_price "$price" --quiet
    fi

    # Mark as a simple product via the WC taxonomy.
    $WP post term set "$id" product_type simple --quiet 2>/dev/null || true

    $WP post meta set "$id" _lf_lang "$lang" --quiet

    echo "    ↳ Created product \"$title\" ($lang) → ID $id" >&2
    echo "$id"
}

if $WP plugin is-active woocommerce --quiet 2>/dev/null; then

    echo "  WooCommerce active — creating sample products …"

    # Generate a shared TRID for the product group (UUID via /proc or uuidgen).
    PRODUCT_TRID=$(cat /proc/sys/kernel/random/uuid 2>/dev/null \
        || uuidgen 2>/dev/null \
        || printf '%s-%s' "lf-dev" "$(date +%s)")

    EN_ID=$(create_product_if_missing \
        "Test Widget" "en" "test-widget" "29.99" \
        "<!-- wp:paragraph --><p>A sample widget for testing Lingua Forge WooCommerce integration.</p><!-- /wp:paragraph -->")

    DE_ID=$(create_product_if_missing \
        "Test-Widget" "de" "test-widget-de" "" \
        "<!-- wp:paragraph --><p>Ein Muster-Widget zum Testen der WooCommerce-Integration.</p><!-- /wp:paragraph -->")

    CA_ID=$(create_product_if_missing \
        "Widget de prova" "ca" "test-widget-ca" "" \
        "<!-- wp:paragraph --><p>Un widget de prova per a la integració de WooCommerce.</p><!-- /wp:paragraph -->")

    # Link all three with the shared TRID so LF's delegation layer can find the source.
    for pid in $EN_ID $DE_ID $CA_ID; do
        [ -z "$pid" ] && continue
        $WP post meta update "$pid" _lf_trid "$PRODUCT_TRID" --quiet 2>/dev/null \
            || $WP post meta add "$pid" _lf_trid "$PRODUCT_TRID" --quiet 2>/dev/null \
            || true
    done

    echo "    ↳ Product group TRID: $PRODUCT_TRID"
    echo "    ↳ Source (EN, with price/stock): ID $EN_ID"
    echo "    ↳ Translations (DE, CA) delegate operational meta to EN at runtime."

else
    echo "  WooCommerce not active — skipping product seed."
    echo "  To include products: add WooCommerce to .wp-env.override.json,"
    echo "  run 'npm run env:start', then re-run 'npm run env:seed'."
fi

# ── AI provider + API key ─────────────────────────────────────────────────────
# The key is injected as a PHP constant in .wp-env.override.json so KeyStore
# reads it directly — no DB encryption round-trip needed for a dev environment.
# .wp-env.override.json is gitignored; the key never leaves the local machine.

echo ""
echo "  AI provider setup"
echo "  ─────────────────"
echo "  Providers: anthropic · openai · gemini  (leave blank to skip)"
printf "  Provider: "
read -r LF_PROVIDER

if [ -n "$LF_PROVIDER" ]; then

    case "$LF_PROVIDER" in
        anthropic) LF_CONST="ANTHROPIC_API_KEY" ;;
        openai)    LF_CONST="OPENAI_API_KEY"    ;;
        gemini)    LF_CONST="GEMINI_API_KEY"    ;;
        *)
            echo "  Unknown provider \"$LF_PROVIDER\" — skipping AI setup."
            LF_PROVIDER=""
            ;;
    esac

fi

if [ -n "$LF_PROVIDER" ]; then

    printf "  API key (input hidden): "
    read -rs LF_API_KEY
    echo ""

    if [ -z "$LF_API_KEY" ]; then
        echo "  No key entered — skipping AI setup."
    else
        # Set the active provider option.
        $WP option update linguaforge_provider "$LF_PROVIDER" --quiet

        # Write / merge the constant into .wp-env.override.json.
        # We use node (available via npm) for safe JSON manipulation.
        node - "$LF_CONST" "$LF_API_KEY" <<'NODE'
const fs   = require('fs');
const path = require('path');
// When invoked as `node -` (stdin), __dirname is the process CWD.
// The seed script runs from lingua-forge/dev/, so this resolves to
// lingua-forge/dev/.wp-env.override.json — alongside .wp-env.json.
const file = path.resolve(process.cwd(), '.wp-env.override.json');

const [,, constName, apiKey] = process.argv;

let cfg = {};
if (fs.existsSync(file)) {
    try { cfg = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_) {}
}

cfg.config = cfg.config || {};
cfg.config[constName] = apiKey;

fs.writeFileSync(file, JSON.stringify(cfg, null, 4) + '\n');
console.log('  ✓ Wrote ' + constName + ' to .wp-env.override.json');
NODE

        # Restart so wp-env picks up the new constant.
        echo "  Restarting wp-env to apply the constant …"
        ./node_modules/.bin/wp-env start --quiet
        ./node_modules/.bin/wp-env run cli bash -c 'wp plugin activate lingua-forge --quiet'

        echo "  ✓ AI provider: $LF_PROVIDER"
        echo "  ✓ Key constant $LF_CONST is live — no UI entry needed."
    fi

fi

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo "  ✓ Dev environment seeded."
echo "────────────────────────────────────────────────────────────────────────"
