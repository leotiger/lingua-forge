<?php
/**
 * Lingua Forge — AI sub-module.
 * Loaded by lingua-forge.php; not a standalone plugin.
 */

defined( 'ABSPATH' )          || exit;
defined( 'LINGUAFORGE_PATH' ) || exit; // Must be loaded via lingua-forge.php

define( 'LINGUAFORGE_AI_PATH', __DIR__ );
define( 'LINGUAFORGE_AI_URL',  LINGUAFORGE_URL . 'ai' );

require_once LINGUAFORGE_AI_PATH . '/includes/Core/Autoloader.php';

\LinguaForge\AI\Core\Plugin::init();

// ── WooCommerce HPOS + Cart Checkout Blocks compatibility ────────────────────
// FeaturesUtil::declare_compatibility() must be called on before_woocommerce_init,
// which fires before plugins_loaded p10 where WooCommerce itself boots.
// Registering at file scope (not inside a plugins_loaded callback) guarantees
// the hook is in place in time. The closure is a harmless no-op when WC is absent.
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables',  LINGUAFORGE_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LINGUAFORGE_FILE, true );
	}
} );

// ── WooCommerce integration ───────────────────────────────────────────────────
// Registers the shared-stock delegation filters (MetaDelegate, StockRouter,
// VariationDelegate, TaxonomyDelegate, CatalogQuery) on every request — not just
// admin — so frontend reads and catalog queries are delegated correctly.
//
// Priority 20: WooCommerce itself loads at plugins_loaded priority 10, so
// class_exists('WooCommerce') is reliable here without any extra guards.
add_action( 'plugins_loaded', function () {
	\LinguaForge\AI\Integrations\WooCommerce\Bootstrap::init();
}, 20 );

// ── WP-CLI commands ───────────────────────────────────────────────────────
// Registered eagerly so they're available the first time `wp linguaforge …`
// dispatches. The Commands class itself is autoloaded lazily on the first
// method invocation — registration is a hash insert into WP_CLI's command
// table, not a class instantiation.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command(
        'linguaforge',
        \LinguaForge\AI\CLI\Commands::class
    );
}

// ── Public PHP API ────────────────────────────────────────────────────────────
// Thin procedural wrappers around AI-module classes. Theme code and third-party
// plugins should call these rather than reaching into the class hierarchy.

/**
 * Programmatically translate a post and persist the result.
 *
 * Runs the full translation pipeline (AI call → post create/update → TRID link
 * → cache clear → `linguaforge_translation_complete` action) without requiring
 * a browser session or WP-CLI context.
 *
 * Safe to call from `plugins_loaded` (priority > 20), `init`, custom WP-CLI
 * commands, bulk-import scripts, and REST endpoint callbacks.
 *
 * Requires the AI module to be active. Check with
 * `did_action('linguaforge_loaded')` before calling if uncertain.
 *
 * @param int    $source_post_id  Post ID of the source-language post to translate.
 * @param string $target_lang     Two-letter language code, e.g. 'es'. Must be an
 *                                active Lingua Forge language.
 * @param array  $params {
 *     Optional parameters.
 *     @type bool $force_refresh         Bypass the translation cache. Default false.
 *     @type bool $force_draft           Create/update as draft even if source is published. Default false.
 *     @type bool $with_meta_description Also generate a translated meta description. Default false.
 * }
 * @return int|\WP_Error  Translated post ID on success, WP_Error on failure.
 *
 * @example
 * $result = linguaforge_trigger_translation( 42, 'es' );
 * if ( is_wp_error( $result ) ) {
 *     error_log( $result->get_error_message() );
 * } else {
 *     // $result is the ID of the created/updated translated post
 * }
 */
function linguaforge_trigger_translation( int $source_post_id, string $target_lang, array $params = [] ): int|\WP_Error {
	return \LinguaForge\AI\Features\TranslationTrigger::run( $source_post_id, $target_lang, $params );
}
