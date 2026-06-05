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
$php = "<?php\n/**\n * Dev-only mu-plugin — forces DE, CA, and ES into the router language list.\n * Written by seed-dev-env.sh; never ships to production.\n */\nadd_filter( \"lf_languages_list\", function ( array \$langs ): array {\n    return array_values( array_unique( array_merge( \$langs, [ \"de\", \"ca\", \"es\" ] ) ) );\n} );\n";
file_put_contents( $mu_dir . "/lf-dev-env.php", $php );
echo "  ✓ lf-dev-env.php written to " . $mu_dir . "\n";
'

# ── Install language packs so the router discovers DE, CA, ES ────────────────
# The router auto-discovers active languages from get_available_languages().
# The mu-plugin above guarantees DE/CA/ES appear in the language list even when
# pack downloads fail (e.g. slow container network).
echo "  Installing language packs (de_DE, ca, es_ES) …"
$WP language core install de_DE || true
$WP language core install ca    || true
$WP language core install es_ES || true

# ── Sample content ────────────────────────────────────────────────────────────
# Create a small set of pages in each language so translation workflows,
# link-fixer, and the lang column can be exercised without manual setup.
# Guard with a meta query so re-runs skip already-created posts.

# Returns the page ID on stdout; progress goes to stderr.
# Usage: create_page_if_missing "Title" "lang" "content" "slug"
# The slug parameter is required.  WP-CLI's --search is unreliable when
# WooCommerce is active (it ignores the search term and returns all posts),
# so we use --name (post_name / slug) for the existence check instead.
create_page_if_missing() {
    local title="$1"
    local lang="$2"
    local content="$3"
    local slug="$4"

    local existing
    existing=$($WP post list \
        --post_type=page \
        --post_status=publish \
        --meta_key=_lf_lang \
        --meta_value="$lang" \
        --fields=ID \
        --format=ids \
        --name="$slug" \
        --quiet 2>/dev/null || true)

    if [ -n "$existing" ]; then
        echo "    ↳ \"$title\" ($lang, slug=$slug) already exists (ID $existing), skipping." >&2
        echo "$existing"
        return
    fi

    local id
    id=$($WP post create \
        --post_type=page \
        --post_status=publish \
        --post_title="$title" \
        --post_name="$slug" \
        --post_content="$content" \
        --porcelain \
        --quiet)

    $WP post meta set "$id" _lf_lang "$lang" --quiet
    echo "    ↳ Created \"$title\" ($lang, slug=$slug) → ID $id" >&2
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
EN_HOME=$(    create_page_if_missing "Home"    "en" "<!-- wp:paragraph --><p>Welcome to the Lingua Forge dev site.</p><!-- /wp:paragraph -->"    "home")
EN_ABOUT=$(   create_page_if_missing "About"   "en" "<!-- wp:paragraph --><p>About us — English version.</p><!-- /wp:paragraph -->"             "about")
EN_CONTACT=$( create_page_if_missing "Contact" "en" "<!-- wp:paragraph --><p>Contact us — English version.</p><!-- /wp:paragraph -->"            "contact")

# German translations
DE_HOME=$(    create_page_if_missing "Startseite" "de" "<!-- wp:paragraph --><p>Willkommen auf der Lingua Forge Entwicklungsseite.</p><!-- /wp:paragraph -->" "startseite")
DE_ABOUT=$(   create_page_if_missing "Über uns"   "de" "<!-- wp:paragraph --><p>Über uns — Deutsche Version.</p><!-- /wp:paragraph -->"                       "uber-uns")
DE_CONTACT=$( create_page_if_missing "Kontakt"    "de" "<!-- wp:paragraph --><p>Kontaktieren Sie uns — Deutsche Version.</p><!-- /wp:paragraph -->"            "kontakt")

# Catalan translations
CA_HOME=$(    create_page_if_missing "Inici"    "ca" "<!-- wp:paragraph --><p>Benvinguts al lloc de desenvolupament de Lingua Forge.</p><!-- /wp:paragraph -->" "inici")
CA_ABOUT=$(   create_page_if_missing "Qui som"  "ca" "<!-- wp:paragraph --><p>Qui som — Versió catalana.</p><!-- /wp:paragraph -->"                             "qui-som")
CA_CONTACT=$( create_page_if_missing "Contacte" "ca" "<!-- wp:paragraph --><p>Contacteu-nos — Versió catalana.</p><!-- /wp:paragraph -->"                       "contacte")

# Link each translation group with a shared TRID.
echo "  Linking page translation groups …"
link_translation_group "$EN_HOME $DE_HOME $CA_HOME"
link_translation_group "$EN_ABOUT $DE_ABOUT $CA_ABOUT"
link_translation_group "$EN_CONTACT $DE_CONTACT $CA_CONTACT"

# ── Language switcher block — append to EN Home ───────────────────────────────
# The switcher only renders when the current page has translation siblings in the
# TRID group (get_languages() returns [] otherwise). The EN Home page already has
# DE and CA translations, so appending the block there gives a reliable test surface.
# The E2E test navigates to /en/home and asserts .lsflr-switcher is present.
echo "  Appending language switcher block to EN Home page …"
$WP eval '
// Look up the EN Home page by slug AND _lf_lang meta so slug conflicts with
// temporary test pages never misdirect the switcher to the wrong post.
$pages = get_posts( [
    "post_type"   => "page",
    "post_status" => "publish",
    "name"        => "home",
    "meta_key"    => "_lf_lang",
    "meta_value"  => "en",
    "numberposts" => 1,
] );
$page = $pages ? $pages[0] : null;
if ( ! $page ) {
    echo "    EN Home page (slug=home, _lf_lang=en) not found — skipping switcher block.\n";
} elseif ( strpos( $page->post_content, "lsflr-switcher" ) !== false ) {
    echo "    Switcher block already present in Home page (ID " . $page->ID . "), skipping.\n";
} else {
    $new_content = $page->post_content . "\n<!-- wp:custom/lsflr-switcher /-->";
    wp_update_post( [ "ID" => $page->ID, "post_content" => $new_content ] );
    echo "    Added lsflr-switcher block to Home page (ID " . $page->ID . ")\n";
}
'

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

    # ── Ensure pa_color exists as a proper WC attribute (persistent, not just registered) ─
    # register_taxonomy() is session-only. wc_create_attribute() writes to
    # wc_attribute_taxonomies so WC re-registers pa_color on every init.
    # This block always runs — idempotent because it checks before creating.
    echo "  Ensuring pa_color WC attribute exists …"
    $WP eval '
global $wpdb;
$exists = $wpdb->get_var("SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = \"color\"");
if ( ! $exists ) {
    $id = wc_create_attribute([
        "name"         => "Color",
        "slug"         => "color",
        "type"         => "select",
        "order_by"     => "menu_order",
        "has_archives" => false,
    ]);
    if ( is_wp_error( $id ) ) {
        echo "  ERROR creating pa_color: " . $id->get_error_message() . "\n";
    } else {
        echo "  ↳ Created pa_color WC attribute (ID $id)\n";
        // Flush rewrite rules so pa_color archive URLs resolve.
        delete_option( "rewrite_rules" );
    }
} else {
    echo "  ↳ pa_color WC attribute already exists (ID $exists), skipping.\n";
}

// wc_create_attribute() stores in DB but does NOT register the taxonomy for this request.
// Register it manually so wp_insert_term() works in the same eval call.
register_taxonomy( "pa_color", ["product"], ["label" => "Color"] );
foreach ( [ "Red", "Blue" ] as $name ) {
    if ( ! term_exists( $name, "pa_color" ) ) {
        $result = wp_insert_term( $name, "pa_color" );
        echo is_wp_error( $result )
            ? "  ERROR inserting $name: " . $result->get_error_message() . "\n"
            : "  ↳ Created pa_color term: $name\n";
    } else {
        echo "  ↳ pa_color term $name already exists.\n";
    }
}
'

    # ── Translated term names for pa_color — exercises TermNameFilter ─────────
    # TermNameFilter reads _lf_term_name_{lang} termmeta to display colour names
    # in the visitor's language. Always runs — idempotent via get_term_meta check.
    echo "  Seeding translated pa_color term names …"
    $WP eval '
$translations = [
    "Red"  => [ "de" => "Rot",   "ca" => "Vermell", "es" => "Rojo"  ],
    "Blue" => [ "de" => "Blau",  "ca" => "Blau",    "es" => "Azul"  ],
];
foreach ( $translations as $en_name => $langs ) {
    $term = get_term_by( "name", $en_name, "pa_color" );
    if ( ! $term ) { echo "  ↳ Term $en_name not found in pa_color — run env:seed again after WC registers the attribute.\n"; continue; }
    foreach ( $langs as $lang => $translated_name ) {
        $existing = get_term_meta( $term->term_id, "_lf_term_name_$lang", true );
        if ( ! $existing ) {
            add_term_meta( $term->term_id, "_lf_term_name_$lang", $translated_name );
            echo "  ↳ " . $en_name . " → $lang: $translated_name (term_id=" . $term->term_id . ")\n";
        } else {
            echo "  ↳ " . $en_name . " → $lang: already set ($existing), skipping.\n";
        }
    }
}
'

    # ── Variable product with variations ─────────────────────────────────────
    # Tests: VariationSync, MetaDelegate bulk-read (wc_get_product()->get_price()),
    #        attribute matching (find_matching_product_variation), _variation_description,
    #        TermNameFilter (colour names in DE/CA), and product_brand delegation.
    echo "  Creating variable product with variations …"
    $WP eval '
// ── Skip if already seeded ──────────────────────────────────────────────
$existing = get_posts([
    "post_type"   => "product",
    "post_status" => "publish",
    "meta_key"    => "_lf_lang",
    "meta_value"  => "en",
    "name"        => "test-shirt",
    "fields"      => "ids",
    "numberposts" => 1,
]);
if ( $existing ) {
    $src_id = $existing[0];
    echo "  ↳ Variable product already exists (ID $src_id) — ensuring WC taxonomies are synced.\n";
    if ( class_exists( "LinguaForge\AI\Integrations\WooCommerce\VariationSync" ) ) {
        LinguaForge\AI\Integrations\WooCommerce\VariationSync::propagate_wc_taxonomies_to_translations( $src_id );
        echo "  ↳ WC taxonomies propagated to all translations.\n";
    }
    return;
}

// ── Resolve pa_color term slugs (attribute created above, registered by WC) ─
$red_term  = get_term_by( "name", "Red",  "pa_color" );
$blue_term = get_term_by( "name", "Blue", "pa_color" );
if ( ! $red_term || ! $blue_term ) {
    echo "  ERROR: pa_color terms not found — ensure env:seed ran the attribute creation block first.\n";
    return;
}
$red_slug  = $red_term->slug;
$blue_slug = $blue_term->slug;

// ── Register product_brand taxonomy ─────────────────────────────────────
if ( ! taxonomy_exists( "product_brand" ) ) {
    register_taxonomy( "product_brand", ["product"], ["label" => "Brand"] );
}
$brand = wp_insert_term( "Acme", "product_brand" );
$brand_id = is_wp_error( $brand ) ? get_term_by( "name", "Acme", "product_brand" )->term_id : $brand["term_id"];

// ── Create source variable product (EN) ──────────────────────────────────
$src_id = wp_insert_post([
    "post_type"    => "product",
    "post_status"  => "publish",
    "post_title"   => "Test Shirt",
    "post_name"    => "test-shirt",
    "post_content" => "<!-- wp:paragraph --><p>A sample variable product for testing Lingua Forge WooCommerce integration.</p><!-- /wp:paragraph -->",
], true);
if ( is_wp_error( $src_id ) ) { echo "ERROR: " . $src_id->get_error_message() . "\n"; return; }

// Mark as variable product + assign pa_color + product_brand terms.
wp_set_object_terms( $src_id, "variable", "product_type" );
wp_set_object_terms( $src_id, [ "pa_color" ], "product_brand", false ); // reset — using correct call below
wp_set_object_terms( $src_id, [ $brand_id ], "product_brand", false );
wp_set_object_terms( $src_id, [ "Red", "Blue" ], "pa_color", false );

// _product_attributes: one taxonomy attribute (pa_color), used for variations.
update_post_meta( $src_id, "_product_attributes", [
    "pa_color" => [
        "name"         => "pa_color",
        "value"        => "",
        "position"     => 0,
        "is_visible"   => 1,
        "is_variation" => 1,
        "is_taxonomy"  => 1,
    ],
]);
update_post_meta( $src_id, "_visibility",   "visible" );
update_post_meta( $src_id, "_stock_status", "instock" );
update_post_meta( $src_id, "_lf_lang",      "en" );
$trid = wp_generate_uuid4();
update_post_meta( $src_id, "_lf_trid", $trid );

// ── Create source variations ──────────────────────────────────────────────
foreach ( [
    "red"  => [ "slug" => $red_slug,  "price" => "19.99", "stock" => 10, "desc" => "The red version — bold and vibrant." ],
    "blue" => [ "slug" => $blue_slug, "price" => "21.99", "stock" => 5,  "desc" => "The blue version — cool and calm." ],
] as $color => $data ) {
    $var_id = wp_insert_post([
        "post_type"   => "product_variation",
        "post_status" => "publish",
        "post_parent" => $src_id,
        "post_title"  => "Test Shirt - " . ucfirst( $color ),
    ]);
    update_post_meta( $var_id, "attribute_pa_color",   $data["slug"] );
    update_post_meta( $var_id, "_price",               $data["price"] );
    update_post_meta( $var_id, "_regular_price",       $data["price"] );
    update_post_meta( $var_id, "_stock",               $data["stock"] );
    update_post_meta( $var_id, "_stock_status",        "instock" );
    update_post_meta( $var_id, "_manage_stock",        "yes" );
    update_post_meta( $var_id, "_variation_description", $data["desc"] );
    // LF language + TRID so VariationSync can link translations to this variation.
    update_post_meta( $var_id, "_lf_lang",  "en" );
    update_post_meta( $var_id, "_lf_trid",  wp_generate_uuid4() );
    echo "  ↳ Source variation (en/$color) → ID $var_id price=" . $data["price"] . "\n";
}

// ── Create translated product stubs (DE, CA) ──────────────────────────────
foreach ( [
    "de" => [ "title" => "Test-Hemd",       "desc" => "<!-- wp:paragraph --><p>Ein Muster-Produkt für die WooCommerce-Integration.</p><!-- /wp:paragraph -->" ],
    "ca" => [ "title" => "Samarreta de prova", "desc" => "<!-- wp:paragraph --><p>Un producte de prova per a la integració de WooCommerce.</p><!-- /wp:paragraph -->" ],
] as $lang => $data ) {
    $trans_id = wp_insert_post([
        "post_type"    => "product",
        "post_status"  => "publish",
        "post_title"   => $data["title"],
        "post_name"    => "test-shirt-$lang",
        "post_content" => $data["desc"],
    ], true);
    if ( is_wp_error( $trans_id ) ) { echo "ERROR ($lang): " . $trans_id->get_error_message() . "\n"; continue; }
    update_post_meta( $trans_id, "_visibility",   "visible" );
    update_post_meta( $trans_id, "_stock_status", "instock" );
    update_post_meta( $trans_id, "_lf_lang",  $lang );
    update_post_meta( $trans_id, "_lf_trid",  $trid );
    echo "  ↳ Translated product ($lang) → ID $trans_id\n";

    // ── Trigger VariationSync: create variation children + inherit WC taxonomies ─
    // sync_wc_taxonomies_from_source copies product_type, pa_* attribute terms,
    // and product_brand directly onto the translated product so WC recognises it
    // as a variable product without relying on runtime TaxonomyDelegate delegation.
    if ( class_exists( "LinguaForge\AI\Integrations\WooCommerce\VariationSync" ) ) {
        LinguaForge\AI\Integrations\WooCommerce\VariationSync::sync_variations_for( $trans_id );
        LinguaForge\AI\Integrations\WooCommerce\VariationSync::sync_wc_taxonomies_from_source( $src_id, $trans_id );
        echo "  ↳ VariationSync ran for ID $trans_id — variations created + WC taxonomies inherited.\n";
    } else {
        echo "  ↳ VariationSync not available — run sync manually or via Retranslate.\n";
    }
}

echo "  ↳ Variable product seeded. Source ID: $src_id  TRID: $trid\n";
echo "  ↳ Brand \"Acme\" (product_brand) assigned to source product.\n";
echo "  ↳ pa_color terms: Red ($red_slug) / Blue ($blue_slug)\n";
echo "  ↳ _variation_description set on each source variation.\n";
'

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

# ── Final rewrite flush ───────────────────────────────────────────────────────
# Must run AFTER linguaforge_primary_language, linguaforge_routing_mode, and
# language packs are all in place so the router registers its language-prefix
# rewrite rules (e.g. /en/, /de/, /ca/) before the E2E tests run.
# The flush at the top of this script runs too early (before LF options exist)
# and does not produce language-prefix rules on a fresh environment.
echo "  Flushing rewrite rules (post-seed, with LF options active) …"
$WP rewrite flush --quiet

# ── Open Lingua Forge meta box for the admin user ─────────────────────────────
# In a fresh WordPress environment the admin user has no stored Gutenberg panel
# preferences.  The Lingua Forge meta box is registered with context 'normal',
# which places it in the collapsible section at the bottom of the block editor.
# WordPress stores hidden/closed meta boxes in user meta; clearing those keys
# ensures the meta box is visible (open) on first visit — required for E2E tests
# that expect .lingua-forge-action buttons to be visible.
echo "  Ensuring Lingua Forge meta box is open for admin user …"
$WP user meta delete 1 closedpostboxes_page  2>/dev/null || true
$WP user meta delete 1 metaboxhidden_page    2>/dev/null || true

# ── Dismiss block-editor welcome guide ────────────────────────────────────────
# On a fresh WordPress install the block editor shows a 4-page "Welcome to the
# editor" modal on first open.  While this dialog is visible, Gutenberg sets
# aria-hidden on the rest of the page, causing Playwright's toBeVisible() to
# report meta-box buttons as hidden.  Persist the "guide dismissed" preference
# so the dialog never appears during E2E runs.
echo "  Dismissing block editor welcome guide for admin user …"
$WP eval '
$prefs = get_user_meta( 1, "wp_persisted_preferences", true );
if ( ! is_array( $prefs ) ) { $prefs = []; }
if ( ! isset( $prefs["core/edit-post"] ) ) { $prefs["core/edit-post"] = []; }
$prefs["core/edit-post"]["welcomeGuide"] = false;
$prefs["_modified"] = gmdate( "c" );
update_user_meta( 1, "wp_persisted_preferences", $prefs );
echo "  ✓ Welcome guide dismissed\n";
'

# ── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo "  ✓ Dev environment seeded."
echo "────────────────────────────────────────────────────────────────────────"
