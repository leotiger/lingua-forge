<?php
/**
 * LinguaForge — Language Router sub-module.
 * Author: Uli Hake
 * Version: 1.3.4
 * Requires PHP: 8.0
 *
 * Loaded by lingua-forge.php; not a standalone plugin.
 * Defines LF_LANG at file-load time and exposes lf_* wrapper functions.
 */

if ( ! defined( 'ABSPATH' ) )          { exit; }
if ( ! defined( 'LINGUAFORGE_PATH' ) ) { exit; } // Must be loaded via lingua-forge.php

// =========================================================
// AUTOLOAD CLASSES
// =========================================================
require_once __DIR__ . '/includes/class-language-router.php';
require_once __DIR__ . '/includes/class-lsflr-switcher.php';
require_once __DIR__ . '/includes/class-lsflr-link-fixer.php';

// =========================================================
// BOOT
// Instantiation defines LF_LANG immediately (same timing as before)
// =========================================================
$language_router  = Language_Router::get_instance();
$lsflr_switcher   = new LSFLR_Switcher( $language_router );
$lsflr_link_fixer = new LSFLR_Link_Fixer( $language_router );

// =========================================================
// THEME / TEMPLATE COMPATIBILITY WRAPPERS
//
// These thin functions delegate to the class instance so
// existing theme code continues to work without changes.
// =========================================================

function lf_source_language(): string {
	return Language_Router::get_instance()->source_language();
}

function lf_languages(): array {
	return Language_Router::get_instance()->languages();
}

function lf_is_valid_lang( $lang ): bool {
	return Language_Router::get_instance()->is_valid_lang( $lang );
}

function lf_locale_from_lang( string $lang ): string {
	return Language_Router::get_instance()->locale_from_lang( $lang );
}

function lf_language_label( string $lang ): string {
	return Language_Router::get_instance()->language_label( $lang );
}

function lf_detect_lang(): string {
	return Language_Router::get_instance()->detect_lang();
}

function lf_detect_lang_safe(): string {
	return Language_Router::get_instance()->detect_lang_safe();
}

function lf_get_trid( int $id ): string {
	return Language_Router::get_instance()->get_trid( $id );
}

function lf_set_trid( int $id, string $v ): void {
	Language_Router::get_instance()->set_trid( $id, $v );
}

function lf_get_lang( int $id ): string {
	return Language_Router::get_instance()->get_lang( $id );
}

function lf_set_lang( int $id, string $v ): void {
	Language_Router::get_instance()->set_lang( $id, $v );
}

function lf_get_translations( int $post_id ): array {
	return Language_Router::get_instance()->get_translations( $post_id );
}

function lf_clear_translation_cache( int $post_id ): void {
	Language_Router::get_instance()->clear_translation_cache( $post_id );
}

function lf_mark_source_updated( int $post_id ): void {
	Language_Router::get_instance()->mark_source_updated( $post_id );
}

function lf_mark_translation_synced( int $post_id ): void {
	Language_Router::get_instance()->mark_translation_synced( $post_id );
}

function lf_is_outdated( int $post_id ): bool {
	return Language_Router::get_instance()->is_outdated( $post_id );
}

function lf_get_missing_languages( int $post_id ): array {
	return Language_Router::get_instance()->get_missing_languages( $post_id );
}

function lf_query( array $args = [] ): WP_Query {
	return Language_Router::get_instance()->query( $args );
}

function lf_query_fallback( array $args = [] ): WP_Query {
	return Language_Router::get_instance()->query_fallback( $args );
}

function lf_get_posts( array $args = [], bool $fallback = false ): array {
	return Language_Router::get_instance()->get_posts( $args, $fallback );
}

function lf_safe_query_args( string $url ): string {
	return Language_Router::get_instance()->safe_query_args( $url );
}

function lf_is_system_request(): bool {
	return Language_Router::get_instance()->is_system_request();
}

function lf_set_lang_cookie( string $lang ): void {
	Language_Router::get_instance()->set_lang_cookie( $lang );
}

function lf_hreflang_mode(): string {
	return Language_Router::get_instance()->hreflang_mode();
}

function lf_build_search_content( int $post_id ): void {
	Language_Router::get_instance()->build_search_content( $post_id );
}

function lf_ensure_lang_index(): bool {
	return Language_Router::get_instance()->ensure_lang_index();
}

function lf_debug( string $message, array $context = [] ): void {
	Language_Router::get_instance()->debug( $message, $context );
}

/**
 * Kept for theme code that calls lf_lang_permalink() directly (my_lang_permalink was the old mu-plugin name).
 * The filter registration is handled inside the class.
 */
function lf_lang_permalink( string $url, $post ): string {
	return Language_Router::get_instance()->lang_permalink( $url, $post );
}

// LSFLR shortcut (for themes calling the render function directly)
function lf_lsflr_render_switcher( array $atts = [] ): string {
	global $lsflr_switcher;
	if ( ! $lsflr_switcher instanceof LSFLR_Switcher ) {
		return '';
	}
	return $lsflr_switcher->render_switcher( $atts );
}

function lf_lsflr_get_languages(): array {
	global $lsflr_switcher;
	if ( ! $lsflr_switcher instanceof LSFLR_Switcher ) {
		return [];
	}
	return $lsflr_switcher->get_languages();
}

function lf_lsflr_translate_current_url( string $target_lang, ?int $post_id = null ): string {
	global $lsflr_switcher;
	if ( ! $lsflr_switcher instanceof LSFLR_Switcher ) {
		return '';
	}
	return $lsflr_switcher->translate_current_url( $target_lang, $post_id );
}
