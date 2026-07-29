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

// ── Comment translation queue + 'auto'-mode triggers ────────────────────────
// Registered unconditionally for the same reason as the translation worker
// above: a queued job runs in a cron / Action Scheduler request that never
// reaches Plugin::boot(), and the 'auto'-mode triggers themselves (comment
// approval / already-approved insert) can fire on a plain frontend request
// (a visitor's own comment) as easily as in wp-admin.
\LinguaForge\AI\Features\CommentTranslationQueue::register_hooks();

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

/**
 * Retranslate a post out into every other configured language — the engine
 * behind the "Sync" button in the post list Lang column.
 *
 * Unlike linguaforge_trigger_translation() (one target language) and
 * linguaforge_queue_translation() (one target, deferred), this fans a single
 * post out to EVERY other active language in one call: a language with no
 * translation yet is created, a language that already has one is
 * force-refreshed in place. The primary/source language is not exempt — if
 * $post_id is itself a secondary-language post, this can overwrite the
 * source post via back-translation. That is the intended behaviour of Sync:
 * "make every other version match this one."
 *
 * Secondary-language safeguards still apply, via two independent guards:
 * syncing FROM a secondary-language 'product' or 'product_variation' post is
 * blocked unless the `linguaforge_wc_allow_secondary_sync` option or the
 * `linguaforge_wc_secondary_sync_allowed` filter allows it — see
 * PostListColumn::wc_secondary_sync_blocked(); syncing FROM a
 * secondary-language post of any OTHER post type is blocked unless the
 * `linguaforge_allow_secondary_sync` option or the
 * `linguaforge_secondary_sync_allowed` filter allows it — see
 * PostListColumn::general_secondary_sync_blocked(). Enabling one has no
 * effect on the other. Syncing FROM the primary post is always unaffected by
 * either.
 *
 * $check_caps defaults to false here, unlike the wp-admin "Sync" button
 * (which always checks), matching linguaforge_trigger_translation() /
 * linguaforge_trash_translation_group()'s convention: a programmatic caller
 * (a REST endpoint, a CLI command, another plugin's own workflow) very often
 * has no meaningful current-WP-user context at all, so gating on
 * current_user_can() by default would silently do nothing rather than sync
 * anything. The calling integration is responsible for its own authorization
 * before calling in. Pass true to also require
 * current_user_can('edit_post', $post_id).
 *
 * This call is synchronous and blocking — it makes one AI request per target
 * language before returning. For many languages, consider looping
 * linguaforge_queue_translation() per target instead if you don't need the
 * "overwrite everything, including the primary" behaviour Sync provides.
 *
 * @param int  $post_id     Post ID to sync FROM. Its own `_lf_lang` determines direction.
 * @param bool $check_caps  Require current_user_can('edit_post', $post_id). Default false.
 * @return array{success:bool,message?:string,results?:array<string,array{status:string,id?:int,edit_url?:string,message?:string}>,from_lang?:string}
 *
 * @example
 * $result = linguaforge_sync_translations( 42 );
 * if ( empty( $result['success'] ) ) {
 *     error_log( $result['message'] ?? 'Sync failed' );
 * }
 *
 * @since 2.6.0
 */
function linguaforge_sync_translations( int $post_id, bool $check_caps = false ): array {
	return \LinguaForge\AI\Admin\PostListColumn::run_sync( $post_id, $check_caps );
}

/**
 * Reassign the language-specific FSE template for every EXISTING sibling in
 * a post's translation group — the engine behind the "TS" (Template Sync)
 * button in the post list Lang column.
 *
 * Unlike linguaforge_sync_translations() / linguaforge_trigger_translation() /
 * linguaforge_queue_translation(), this never calls the AI translation
 * feature and never touches post_content, post_title, or post_excerpt — it
 * only re-resolves and re-writes `_wp_page_template` (via
 * Sync::assign_template_if_needed()) on translations that already exist. It
 * also cannot create a missing translation, since doing so requires
 * translated content, which requires the AI call this function deliberately
 * avoids. Use this to cheaply "assure" every sibling's template is correct —
 * after a template rename, a theme change, or recovering from a bug like the
 * one fixed in 2.6.1 — without spending an AI call on content that hasn't
 * changed.
 *
 * $post_id must be the PRIMARY/source-language post — restricted from the
 * start, since this is meant as the one entry point that fixes every
 * sibling's template in a single pass, not a per-post action. Calling it
 * with a secondary-language post's ID returns
 * `['success' => false, 'message' => ...]` rather than silently doing
 * something partial.
 *
 * No secondary-language safeguard is needed here (unlike Sync): this
 * function never touches content, so the back-translation content-overwrite
 * risk those guards exist for does not apply.
 *
 * $check_caps defaults to false, matching linguaforge_sync_translations() /
 * linguaforge_trigger_translation()'s convention — a programmatic caller
 * very often has no meaningful current-WP-user context at all. Pass true to
 * also require current_user_can('edit_post', $post_id).
 *
 * @param int  $post_id     Post ID of the PRIMARY/source-language post.
 * @param bool $check_caps  Require current_user_can('edit_post', $post_id). Default false.
 * @return array{success:bool,message?:string,results?:array<string,array{status:string,id?:int,template?:string,message?:string}>}
 *
 * @example
 * $result = linguaforge_sync_templates( 42 );
 * if ( empty( $result['success'] ) ) {
 *     error_log( $result['message'] ?? 'Template Sync failed' );
 * }
 *
 * @since 2.6.1
 */
function linguaforge_sync_templates( int $post_id, bool $check_caps = false ): array {
	return \LinguaForge\AI\Admin\PostListColumn::run_sync_templates( $post_id, $check_caps );
}
