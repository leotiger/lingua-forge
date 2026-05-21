<?php
/**
 * Class LinguaForge\Router\Search\Query
 *
 * Extends WordPress search to include the _lf_search_content meta index,
 * boosts title matches, fixes the search form action URL, and overrides
 * the search template for language-specific variants.
 */

namespace LinguaForge\Router\Search;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Query {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_filter( 'get_block_templates', [ $this, 'override_search_template' ], 10, 3 );
		add_filter( 'render_block',        [ $this, 'fix_search_form' ], 20, 2 );
		add_filter( 'posts_search',        [ $this, 'extend_posts_search' ], 20, 2 );
		add_filter( 'posts_clauses',       [ $this, 'boost_title_in_search' ], 20, 2 );
	}

	// =========================================================
	// SEARCH TEMPLATE
	// =========================================================

	public function override_search_template( array $templates, $query, string $template_type ): array {
		if ( $template_type !== 'wp_template' ) return $templates;
		if ( ! defined( 'LF_LANG' ) ) return $templates;
		if ( ! is_search() ) return $templates;

		$lang_slug = 'search-' . LF_LANG;
		$tpl       = get_page_by_path( $lang_slug, OBJECT, 'wp_template' );

		if ( $tpl ) {
			$this->router->debug( 'Search template override SUCCESS', [ 'template' => $lang_slug ] );
			return [ _build_block_template_result_from_post( $tpl ) ];
		}

		return $templates;
	}

	// =========================================================
	// SEARCH FORM FIX
	// =========================================================

	public function fix_search_form( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/search' ) return $block_content;
		if ( ! defined( 'LF_LANG' ) ) return $block_content;

		$block_content = preg_replace( '/<form[^>]*action="[^"]*"/', '<form action="/"', $block_content );

		if ( ! str_contains( $block_content, 'name="lang"' ) ) {
			$hidden        = '<input type="hidden" name="lang" value="' . esc_attr( LF_LANG ) . '">';
			$block_content = preg_replace( '/<\/form>/', $hidden . '</form>', $block_content, 1 );
		}

		$this->router->debug( 'Search form fixed (root + lang)', [ 'lang' => LF_LANG ] );

		return $block_content;
	}

	// =========================================================
	// SEARCH QUERY FILTERS
	// =========================================================

	public function boost_title_in_search( array $clauses, $query ): array {
		global $wpdb;

		if ( ! is_search() ) return $clauses;

		$term = $query->get( 's' );
		if ( ! $term ) return $clauses;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$clauses['orderby'] = $wpdb->prepare( "
			(CASE
				WHEN {$wpdb->posts}.post_title LIKE %s THEN 1
				ELSE 2
			END),
			{$wpdb->posts}.post_date DESC
		", $like );

		return $clauses;
	}

	public function extend_posts_search( string $search, $wp_query ): string {
		global $wpdb;

		if ( ! is_search() ) return $search;

		$term = $wp_query->get( 's' );
		if ( ! $term ) return $search;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->prepare( "
			AND (
				{$wpdb->posts}.post_title LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta}
					WHERE post_id = {$wpdb->posts}.ID
					AND meta_key = '_lf_search_content'
					AND meta_value LIKE %s
				)
			)
		", $like, $like );
	}
}
