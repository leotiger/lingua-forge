<?php
/**
 * Lingua Forge — Language Router sub-module.
 * Author: Uli Hake
 * Requires PHP: 8.1
 *
 * Loaded by lingua-forge.php; not a standalone plugin.
 * Defines LF_LANG at file-load time and exposes linguaforge_* wrapper functions.
 */

if ( ! defined( 'ABSPATH' ) )          { exit; }
if ( ! defined( 'LINGUAFORGE_PATH' ) ) { exit; } // Must be loaded via lingua-forge.php

// =========================================================
// AUTOLOAD CLASSES
//
// Load order: sub-classes that have no cross-sibling dependencies first,
// then the Router orchestrator which references them all.
// =========================================================

// Core sub-classes (no Router dependency yet — required before Router class)
require_once __DIR__ . '/includes/class-context.php';
require_once __DIR__ . '/includes/class-locale-detector.php';
require_once __DIR__ . '/includes/i18n/class-overrides.php';
require_once __DIR__ . '/includes/db/class-migrator.php';
require_once __DIR__ . '/includes/translation/class-trid-group.php';
require_once __DIR__ . '/includes/translation/class-sync.php';
require_once __DIR__ . '/includes/translation/class-trash-cascade.php';
require_once __DIR__ . '/includes/rewrite/class-manager.php';
require_once __DIR__ . '/includes/rewrite/class-query-filter.php';
require_once __DIR__ . '/includes/routing/class-redirector.php';
require_once __DIR__ . '/includes/routing/class-front-page-query.php';
require_once __DIR__ . '/includes/seo/class-hreflang.php';
require_once __DIR__ . '/includes/seo/class-seo-manager.php';
require_once __DIR__ . '/includes/seo/class-social-share.php';
require_once __DIR__ . '/includes/seo/class-schema-manager.php';
require_once __DIR__ . '/includes/seo/class-sitemap-manager.php';
require_once __DIR__ . '/includes/seo/class-indexnow-manager.php';
require_once __DIR__ . '/includes/search/class-index.php';
require_once __DIR__ . '/includes/search/class-query.php';
require_once __DIR__ . '/includes/admin/class-meta-boxes.php';
require_once __DIR__ . '/includes/admin/class-columns.php';
require_once __DIR__ . '/includes/admin/class-filters.php';
require_once __DIR__ . '/includes/admin/class-scripts.php';

// Language Switcher + Link Fixer + Featured Image Fixer — loaded before the
// Router class so the Router constructor can instantiate them as sub-objects.
require_once __DIR__ . '/includes/class-lsflr-switcher.php';
require_once __DIR__ . '/includes/class-lsflr-switcher-widget.php';
require_once __DIR__ . '/includes/class-lsflr-link-fixer.php';
require_once __DIR__ . '/includes/class-lsflr-featured-image-fixer.php';
require_once __DIR__ . '/includes/rest/class-data-endpoints.php';

// Router orchestrator — requires all sub-classes above.
require_once __DIR__ . '/includes/class-language-router.php';

// Frontend blocks (missing-translation-notice, and future siblings).
// Self-bootstrapping — the file hooks register_block_type onto init.
require_once __DIR__ . '/blocks/blocks.php';

// =========================================================
// BOOT
// Instantiation defines LF_LANG immediately (same timing as before).
// Switcher and LinkFixer are now sub-objects of the Router singleton —
// no separate globals needed.
// =========================================================
\LinguaForge\Router\Router::get_instance();
\LinguaForge\Router\REST\DataEndpoints::init();

// =========================================================
// THEME / TEMPLATE WRAPPERS
//
// Thin procedural functions that delegate to the class
// instances — use these in theme functions.php or templates.
// =========================================================

function linguaforge_source_language(): string {
	return \LinguaForge\Router\Router::get_instance()->source_language();
}

function linguaforge_languages(): array {
	return \LinguaForge\Router\Router::get_instance()->languages();
}

function linguaforge_is_valid_lang( $lang ): bool {
	return \LinguaForge\Router\Router::get_instance()->is_valid_lang( $lang );
}

function linguaforge_locale_from_lang( string $lang ): string {
	return \LinguaForge\Router\Router::get_instance()->locale_from_lang( $lang );
}

function linguaforge_language_label( string $lang ): string {
	return \LinguaForge\Router\Router::get_instance()->language_label( $lang );
}

function linguaforge_detect_lang(): string {
	return \LinguaForge\Router\Router::get_instance()->detect_lang();
}

function linguaforge_detect_lang_safe(): string {
	return \LinguaForge\Router\Router::get_instance()->detect_lang_safe();
}

function linguaforge_get_trid( int $id ): string {
	return \LinguaForge\Router\Router::get_instance()->get_trid( $id );
}

function linguaforge_set_trid( int $id, string $v ): void {
	\LinguaForge\Router\Router::get_instance()->set_trid( $id, $v );
}

function linguaforge_get_lang( int $id ): string {
	return \LinguaForge\Router\Router::get_instance()->get_lang( $id );
}

function linguaforge_set_lang( int $id, string $v ): void {
	\LinguaForge\Router\Router::get_instance()->set_lang( $id, $v );
}

function linguaforge_get_translations( int $post_id ): array {
	return \LinguaForge\Router\Router::get_instance()->get_translations( $post_id );
}

function linguaforge_clear_translation_cache( int $post_id ): void {
	\LinguaForge\Router\Router::get_instance()->clear_translation_cache( $post_id );
}

function linguaforge_mark_source_updated( int $post_id ): void {
	\LinguaForge\Router\Router::get_instance()->mark_source_updated( $post_id );
}

function linguaforge_mark_translation_synced( int $post_id ): void {
	\LinguaForge\Router\Router::get_instance()->mark_translation_synced( $post_id );
}

function linguaforge_is_outdated( int $post_id ): bool {
	return \LinguaForge\Router\Router::get_instance()->is_outdated( $post_id );
}

function linguaforge_get_missing_languages( int $post_id ): array {
	return \LinguaForge\Router\Router::get_instance()->get_missing_languages( $post_id );
}

/**
 * Trash $post_id together with every other post in its TRID translation
 * group — the same engine behind the "Trash + Siblings" row action and
 * "Move to Trash (incl. translations)" bulk action in wp-admin.
 *
 * Unlike those two wp-admin entry points, $check_caps defaults to false
 * here: a programmatic caller (a REST endpoint, a CLI command, an
 * integration's own removal flow) very often has no meaningful current-WP-user
 * context at all — an anonymous, token-authenticated request being the
 * common case — so gating on current_user_can() would silently skip every
 * post rather than trash anything. Matches the existing
 * linguaforge_trigger_translation() / linguaforge_queue_translation()
 * convention: this function does not authorize the action, it performs it —
 * the calling integration is responsible for deciding the caller is allowed
 * to do this before calling in (e.g. verifying its own signed token, or
 * that the requester is the post's own author) — same as it would already
 * have to before calling wp_trash_post() directly.
 *
 * Skips (and reports as skipped) posts already in the Trash and the site's
 * static front page / posts page, which core refuses to trash regardless.
 * Fires `linguaforge_trash_cascade_complete` on completion — receives the
 * trashed/skipped post ID arrays and the triggering $post_id — so a caller
 * that wants the specifics rather than just the counts this function
 * returns can hook that instead.
 *
 * @param int  $post_id    Any post in the translation group — the group is
 *                          resolved from its TRID, not just its own siblings.
 * @param bool $check_caps Pass true to also require
 *                          current_user_can('delete_post', $id) per post,
 *                          matching the wp-admin row/bulk action behaviour.
 *                          Default false.
 * @return array{trashed:int,skipped:int} Counts, not IDs — hook
 *                          `linguaforge_trash_cascade_complete` for the IDs.
 *
 * @since 2.5.4
 */
function linguaforge_trash_translation_group( int $post_id, bool $check_caps = false ): array {
	return \LinguaForge\Router\Router::get_instance()->trash_translation_group( $post_id, $check_caps );
}

function linguaforge_query( array $args = [] ): WP_Query {
	return \LinguaForge\Router\Router::get_instance()->query( $args );
}

function linguaforge_query_fallback( array $args = [] ): WP_Query {
	return \LinguaForge\Router\Router::get_instance()->query_fallback( $args );
}

function linguaforge_get_posts( array $args = [], bool $fallback = false ): array {
	return \LinguaForge\Router\Router::get_instance()->get_posts( $args, $fallback );
}

function linguaforge_safe_query_args( string $url ): string {
	return \LinguaForge\Router\Router::get_instance()->safe_query_args( $url );
}

function linguaforge_is_system_request(): bool {
	return \LinguaForge\Router\Router::get_instance()->is_system_request();
}

function linguaforge_set_lang_cookie( string $lang ): void {
	\LinguaForge\Router\Router::get_instance()->set_lang_cookie( $lang );
}

function linguaforge_hreflang_mode(): string {
	return \LinguaForge\Router\Router::get_instance()->hreflang_mode();
}

function linguaforge_build_search_content( int $post_id ): void {
	\LinguaForge\Router\Router::get_instance()->build_search_content( $post_id );
}

function linguaforge_ensure_lang_index(): bool {
	return \LinguaForge\Router\Router::get_instance()->ensure_lang_index();
}

function linguaforge_debug( string $message, array $context = [] ): void {
	\LinguaForge\Router\Router::get_instance()->debug( $message, $context );
}

/** The filter registration is handled inside the class. */
function linguaforge_lang_permalink( string $url, $post ): string {
	return \LinguaForge\Router\Router::get_instance()->lang_permalink( $url, $post );
}

// Language Switcher shortcuts
function linguaforge_lsflr_render_switcher( array $atts = [] ): string {
	return \LinguaForge\Router\Router::get_instance()->switcher->render_switcher( $atts );
}

function linguaforge_lsflr_get_languages(): array {
	return \LinguaForge\Router\Router::get_instance()->switcher->get_languages();
}

function linguaforge_lsflr_translate_current_url( string $target_lang, ?int $post_id = null ): string {
	return \LinguaForge\Router\Router::get_instance()->switcher->translate_current_url( $target_lang, $post_id );
}

/**
 * Fires after the Lingua Forge Language Router has fully booted and all
 * public wrapper functions are available.
 *
 * Use this as the safe attach point for third-party integrations that depend
 * on `LF_LANG`, the Router singleton, or any `linguaforge_*` / `lf_*`
 * wrapper function being defined. Hooking on `plugins_loaded` at a lower
 * priority risks running before the router has initialised.
 *
 * @param string $version Current Lingua Forge version string (LINGUAFORGE_VERSION).
 *
 * @example
 * add_action( 'linguaforge_loaded', function ( $version ) {
 *     if ( version_compare( $version, '2.0.0', '>=' ) ) {
 *         // safe to use linguaforge_* functions here
 *     }
 * } );
 */
do_action( 'linguaforge_loaded', defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
