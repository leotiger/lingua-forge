<?php
/**
 * Class LinguaForge\Router\Translation\TridGroup
 *
 * Manages the TRID translation-group system: reading / writing the _lf_lang and
 * _lf_trid post-meta, looking up all translations for a post, and clearing the
 * object-cache entries that front the DB queries.
 */

namespace LinguaForge\Router\Translation;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class TridGroup {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Cache clear runs at priority 20 so it fires after handle_save_post (10).
		add_action( 'wp_after_insert_post', [ $this, 'handle_cache_clear' ], 20 );
	}

	// =========================================================
	// TRID ACCESSORS
	// =========================================================

	public function get_trid( int $id ): string {
		return (string) get_post_meta( $id, '_lf_trid', true );
	}

	public function set_trid( int $id, string $v ): void {
		$old = $this->get_trid( $id );
		update_post_meta( $id, '_lf_trid', $v );

		if ( $old !== $v ) {
			/**
			 * Fires after a post's TRID (translation-group UUID) changes.
			 *
			 * Useful for object-cache plugins that need to invalidate entries keyed
			 * on TRID, and for any code that maintains derived data from translation
			 * groups (e.g. sitemaps, search indexes).
			 *
			 * @param int    $id       Post ID whose TRID was updated.
			 * @param string $new_trid New TRID value (a UUID, or '' to unlink).
			 * @param string $old_trid Previous TRID value ('' if not previously set).
			 */
			do_action( 'linguaforge_trid_changed', $id, $v, $old ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
		}
	}

	// =========================================================
	// LANG ACCESSORS
	// =========================================================

	public function get_lang( int $id ): string {
		$lang = get_post_meta( $id, '_lf_lang', true );
		return $lang ?: $this->router->context->source_language();
	}

	public function set_lang( int $id, string $v ): void {
		update_post_meta( $id, '_lf_lang', $v );
	}

	// =========================================================
	// TRANSLATION QUERIES
	// =========================================================

	public function get_translations( int $post_id ): array {
		global $wpdb;

		$trid = $this->get_trid( $post_id );
		if ( ! $trid ) return [];

		$cache_key = 'trid_' . $trid;
		$cached    = wp_cache_get( $cache_key, 'lf_translations' );
		if ( $cached !== false ) return $cached;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Subquery across wp_postmeta to resolve translation groups by TRID; no WP API equivalent. $wpdb->postmeta is a server-defined table name; $trid bound via %s placeholder. Result cached immediately below via wp_cache_set.
		$rows = $wpdb->get_results( $wpdb->prepare( "
			SELECT pm.post_id, pm.meta_value lang
			FROM $wpdb->postmeta pm
			INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
			WHERE pm.meta_key='_lf_lang'
			AND p.post_status != 'auto-draft'
			AND p.post_type NOT IN ('wp_template','wp_template_part','wp_global_styles','wp_block','nav_menu_item','revision','attachment')
			AND pm.post_id IN (
				SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key='_lf_trid' AND meta_value=%s
			)
		", $trid ) );

		$out = [];
		foreach ( $rows as $r ) {
			$out[$r->lang] = (int) $r->post_id; // wpdb returns strings; cast for type safety
		}

		wp_cache_set( $cache_key, $out, 'lf_translations', 3600 );

		return $out;
	}

	public function clear_translation_cache( int $post_id ): void {
		$trid = $this->get_trid( $post_id );
		if ( ! $trid ) return;
		wp_cache_delete( 'trid_' . $trid, 'lf_translations' );
	}

	public function handle_cache_clear( int $post_id ): void {
		$this->clear_translation_cache( $post_id );
	}

	// =========================================================
	// MISSING LANGUAGES
	// =========================================================

	public function get_missing_languages( int $post_id ): array {
		$translations = $this->get_translations( $post_id );
		$existing     = array_keys( $translations );
		$current      = $this->get_lang( $post_id );
		$missing      = [];

		foreach ( $this->router->context->languages() as $lang ) {
			if ( $lang === $current ) continue;
			if ( ! in_array( $lang, $existing, true ) ) {
				$missing[] = $lang;
			}
		}

		return $missing;
	}
}
