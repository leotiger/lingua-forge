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
require_once __DIR__ . '/includes/rewrite/class-manager.php';
require_once __DIR__ . '/includes/rewrite/class-query-filter.php';
require_once __DIR__ . '/includes/routing/class-redirector.php';
require_once __DIR__ . '/includes/seo/class-hreflang.php';
require_once __DIR__ . '/includes/search/class-index.php';
require_once __DIR__ . '/includes/search/class-query.php';
require_once __DIR__ . '/includes/admin/class-meta-boxes.php';
require_once __DIR__ . '/includes/admin/class-columns.php';
require_once __DIR__ . '/includes/admin/class-filters.php';
require_once __DIR__ . '/includes/admin/class-scripts.php';

// Router orchestrator — requires all sub-classes above.
require_once __DIR__ . '/includes/class-language-router.php';
require_once __DIR__ . '/includes/class-lsflr-switcher.php';
require_once __DIR__ . '/includes/class-lsflr-link-fixer.php';

// Frontend blocks (missing-translation-notice, and future siblings).
// Self-bootstrapping — the file hooks register_block_type onto init.
require_once __DIR__ . '/blocks/blocks.php';

// =========================================================
// BOOT
// Instantiation defines LF_LANG immediately (same timing as before)
// =========================================================
$linguaforge_language_router  = \LinguaForge\Router\Router::get_instance();
$linguaforge_lsflr_switcher   = new \LinguaForge\Router\Switcher( $linguaforge_language_router );
$linguaforge_lsflr_link_fixer = new \LinguaForge\Router\LinkFixer( $linguaforge_language_router );

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
	global $linguaforge_lsflr_switcher;
	if ( ! $linguaforge_lsflr_switcher instanceof \LinguaForge\Router\Switcher ) {
		return '';
	}
	return $linguaforge_lsflr_switcher->render_switcher( $atts );
}

function linguaforge_lsflr_get_languages(): array {
	global $linguaforge_lsflr_switcher;
	if ( ! $linguaforge_lsflr_switcher instanceof \LinguaForge\Router\Switcher ) {
		return [];
	}
	return $linguaforge_lsflr_switcher->get_languages();
}

function linguaforge_lsflr_translate_current_url( string $target_lang, ?int $post_id = null ): string {
	global $linguaforge_lsflr_switcher;
	if ( ! $linguaforge_lsflr_switcher instanceof \LinguaForge\Router\Switcher ) {
		return '';
	}
	return $linguaforge_lsflr_switcher->translate_current_url( $target_lang, $post_id );
}
