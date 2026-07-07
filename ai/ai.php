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

// ── GDPR / privacy integration ───────────────────────────────────────────
// Registers an exporter and eraser for the AI usage stats table so WordPress's
// Tools → Export / Erase Personal Data flows cover user_id rows in that table.
add_action( 'init', [ \LinguaForge\AI\Core\PrivacyIntegration::class, 'register' ] );

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

// ── Deferred translation worker ─────────────────────────────────────────────
// Registered unconditionally (NOT inside Plugin::boot(), which short-circuits on
// plain frontend and WP-Cron requests) so a queued job always finds its callback
// when it runs in a cron / Action Scheduler request. The ::class form is a plain
// string, so TranslationQueue is autoloaded only when the hook actually fires.
add_action(
	'linguaforge_run_queued_translation',
	[ \LinguaForge\AI\Features\TranslationQueue::class, 'run_queued' ],
	10,
	3
);

// ── Automatic missing-translation backfill ──────────────────────────────────
// Self-heals a post left with a translation gap (a queued job that timed out,
// errored, or was otherwise lost) by periodically re-scanning for TRID-group
// gaps in the active languages and re-queuing just the missing ones — the
// same recovery an admin would otherwise have to trigger by hand via
// `wp linguaforge missing_translations` + `wp linguaforge fill_translations`.
// Registered unconditionally for the same reason as the worker above: the
// scan itself runs in a WP-Cron request, which never reaches Plugin::boot().
\LinguaForge\AI\Features\TranslationBackfill::register_hooks();

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

/**
 * Queue a translation job for deferred (off-request) execution.
 *
 * Non-blocking counterpart to linguaforge_trigger_translation(). Instead of
 * making the AI call inline, it schedules the work to run shortly after the
 * current request — via Action Scheduler when available (it ships with
 * WooCommerce and many hosts), or a single WP-Cron event otherwise. The job runs
 * the same pipeline as linguaforge_trigger_translation() and so still fires the
 * `linguaforge_translation_complete` action on success.
 *
 * Intended for programmatic publishers that would otherwise make N blocking AI
 * calls in a single intake request (one per target language). Replace a
 * synchronous `foreach ( $langs as $l ) linguaforge_trigger_translation( … )`
 * loop with `linguaforge_queue_translation()` to move all AI work off the
 * request. Duplicate pending jobs for the same post + language + params are
 * skipped.
 *
 * Fire-and-forget: there is no caller to return a result to, so the job logs
 * (WP_DEBUG-gated) and swallows any failure. Use linguaforge_trigger_translation()
 * directly when you need the new post ID or a WP_Error synchronously.
 *
 * @param int    $source_post_id  Post ID of the source-language post to translate.
 * @param string $target_lang     Two-letter language code, e.g. 'es'. Must be an
 *                                active Lingua Forge language.
 * @param array  $params          Same keys as linguaforge_trigger_translation():
 *                                force_refresh (bool), force_draft (bool),
 *                                with_meta_description (bool).
 * @return void
 *
 * @since 2.4.0
 */
function linguaforge_queue_translation( int $source_post_id, string $target_lang, array $params = [] ): void {
	\LinguaForge\AI\Features\TranslationQueue::queue( $source_post_id, $target_lang, $params );
}
