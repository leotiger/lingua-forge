<?php
/**
 * Class LinguaForge\Router\Admin\Filters
 *
 * Persists the admin list-screen language filter per-user, filters the
 * wp_dropdown_pages() results by language, and renders the language and
 * outdated-status filter dropdowns above the post list.
 */

namespace LinguaForge\Router\Admin;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Filters {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_action( 'load-edit.php',          [ $this, 'persist_admin_lang_filter' ] );
		add_action( 'restrict_manage_posts',  [ $this, 'render_lang_filter_dropdown' ] );
		add_action( 'restrict_manage_posts',  [ $this, 'render_outdated_filter_dropdown' ] );
		// get_pages fires on both admin and frontend; the method guards on is_admin()
		// and pagenow so it only applies to the edit.php list screen.
		add_filter( 'get_pages',              [ $this, 'filter_pages_by_lang' ], 10, 2 );
	}

	// =========================================================
	// PERSIST LANG FILTER
	// =========================================================

	public function persist_admin_lang_filter(): void {
		if ( ! current_user_can( 'edit_posts' ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( isset( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
			update_user_meta( get_current_user_id(), 'lf_lang_filter', $lang );
		}
	}

	// =========================================================
	// FILTER DROPDOWNS
	// =========================================================

	public function render_lang_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, $this->managed_post_types(), true ) ) return;

		$user_id = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		$current = ! empty( $_GET['lf_lang_filter'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			? sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) )
			: ( get_user_meta( $user_id, 'lf_lang_filter', true ) ?: '' );

		echo '<select name="lf_lang_filter">';
		echo '<option value="">All languages</option>';
		foreach ( $this->router->context->languages() as $lang ) {
			echo '<option value="' . esc_attr( $lang ) . '" ' . selected( $current, $lang, false ) . '>' . esc_html( strtoupper( $lang ) ) . '</option>';
		}
		echo '</select>';
	}

	public function render_outdated_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, $this->managed_post_types(), true ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading admin list-filter URL parameter; no data is modified.
		$current = isset( $_GET['lf_outdated_filter'] ) ? sanitize_key( wp_unslash( $_GET['lf_outdated_filter'] ) ) : '';

		echo '<select name="lf_outdated_filter">';
		echo '<option value="">All statuses</option>';
		echo '<option value="1" ' . selected( $current, '1', false ) . '>Outdated only</option>';
		echo '</select>';
	}

	// =========================================================
	// MANAGED POST TYPES
	// =========================================================

	/**
	 * Returns all post types that should display the Lingua Forge filter
	 * dropdowns: 'post', 'page', and any public CPT registered via the
	 * 'linguaforge_column_post_types' filter.
	 *
	 * @return string[]
	 */
	private function managed_post_types(): array {
		$internal = [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_navigation',
			'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
			'wp_navigation_fallback',
		];

		$cpts = array_values( array_diff(
			array_keys( get_post_types( [ 'public' => true ] ) ),
			array_merge( [ 'post', 'page' ], $internal )
		) );

		/** @see linguaforge_column_post_types (documented in class-columns.php) */
		$cpts = (array) apply_filters( 'linguaforge_column_post_types', $cpts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return array_merge( [ 'post', 'page' ], $cpts );
	}

	// =========================================================
	// GET_PAGES FILTER
	// =========================================================

	public function filter_pages_by_lang( array $pages, array $args ): array {
		if ( ! is_admin() ) return $pages;

		global $pagenow;
		if ( $pagenow !== 'edit.php' ) return $pages;

		$lang = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( ! empty( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
		} else {
			$lang = get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		}

		if ( ! $lang ) return $pages;

		$filtered = [];
		foreach ( $pages as $page ) {
			if ( $this->router->trid_group->get_lang( $page->ID ) === $lang ) {
				$filtered[] = $page;
			}
		}

		return $filtered;
	}
}
