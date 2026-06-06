<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\CatalogQuery
 *
 * Adds language-awareness to WooCommerce product queries.
 *
 * The language-router module's QueryFilter already scopes the main shop/archive
 * query by adding a `_lf_lang = LF_LANG` meta constraint on all frontend archive
 * and search queries. This class extends that coverage to two additional paths:
 *
 * 1. WooCommerce block product queries (ProductCollection, AbstractProductGrid
 *    blocks such as TopRated, BestSellers, OnSale, HandpickedProducts, etc.) —
 *    handled via the `pre_get_posts` action.  These blocks create secondary
 *    WP_Query instances (not the main query), so they are invisible to both
 *    QueryFilter (which guards with is_main_query()) and to WC_Query's own
 *    `woocommerce_product_query` action (also main-query-only via pre_get_posts).
 *    We intercept every secondary WP_Query whose post_type is 'product' and
 *    inject the language meta constraint if it is not already present.
 *    `woocommerce_product_query` is kept as a lightweight belt-and-suspenders
 *    fallback for any path we may have missed.
 *
 * 2. Related-products raw SQL queries — WooCommerce's wc_get_related_products()
 *    calls WC_Product_Data_Store_CPT::get_related_products_query() which builds
 *    a raw $wpdb SQL array and never goes through WP_Query (so path 1 does not
 *    fire for it). We hook `woocommerce_product_related_posts_query` and inject
 *    an INNER JOIN on postmeta to restrict results to the active language.
 *    This covers both the classic related.php template and the FSE
 *    `woocommerce/related-products` block (which calls the same function and
 *    then puts the returned IDs into a post__in WP_Query).
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use WP_Query;

defined( 'ABSPATH' ) || exit;

class CatalogQuery {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Secondary WP_Query instances — ProductCollection, AbstractProductGrid
		// blocks (TopRated, BestSellers, OnSale, HandpickedProducts, etc.) and
		// any WC_Product_Query — never trigger `woocommerce_product_query` because
		// that action is guarded by is_main_query() inside WC_Query::pre_get_posts.
		// Hook pre_get_posts directly so that every non-main product query is
		// filtered regardless of which block or helper created it.
		add_action( 'pre_get_posts', [ self::class, 'apply_language_filter_to_secondary_query' ], 10, 1 );

		// AbstractProductGrid blocks (TopRated, BestSellers, OnSale, ProductNew,
		// HandpickedProducts, FeaturedProduct) use BlocksWpQuery::get_cached_posts(),
		// which hashes query vars BEFORE pre_get_posts fires.  The language
		// constraint is therefore not part of the cache key — the first language to
		// prime the transient wins and all other languages get its results.
		// Disable the transient layer when LF_LANG is active so that pre_get_posts
		// runs the language-aware SQL on every request.
		add_filter( 'woocommerce_blocks_product_grid_is_cacheable', [ self::class, 'disable_product_grid_cache' ], 10, 1 );

		// Belt-and-suspenders for the main WC shop/archive query (most sites have
		// this covered already by QueryFilter, but the extra guard is free).
		add_action( 'woocommerce_product_query', [ self::class, 'apply_language_filter' ], 10, 1 );

		// Related products bypass WP_Query entirely — WC builds a raw $wpdb SQL
		// array and executes it directly.  Inject a language-scoping INNER JOIN
		// before the query runs.
		add_filter( 'woocommerce_product_related_posts_query', [ self::class, 'apply_language_filter_to_related_query' ], 10, 2 );
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Add a `_lf_lang` meta constraint to every WooCommerce product query so
	 * that product listings are scoped to the active language.
	 *
	 * Skips:
	 *  • Admin requests (admin product management should show all languages).
	 *  • Queries that already have a `_lf_lang` condition (main query was already
	 *    handled by QueryFilter).
	 *  • Requests where LF_LANG is not defined (router has not determined a
	 *    language, e.g. REST calls without a language prefix).
	 *
	 * @param WP_Query $query  The WC product query being built.
	 */
	public static function apply_language_filter( WP_Query $query ): void {

		if ( is_admin() ) {
			return;
		}

		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return;
		}

		// Check whether a _lf_lang constraint is already present.
		$meta_query = (array) $query->get( 'meta_query', [] );

		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) && '_lf_lang' === $clause['key'] ) {
				return; // Already filtered — nothing to add.
			}
		}

		$meta_query[] = [
			'key'   => '_lf_lang',
			'value' => LF_LANG,
		];

		$query->set( 'meta_query', $meta_query );
	}

	// =========================================================================
	// Secondary product-query filter (pre_get_posts)
	// =========================================================================

	/**
	 * Add a `_lf_lang` meta constraint to every secondary WooCommerce product
	 * query — those created by blocks and WC helpers that bypass the main query.
	 *
	 * WC_Query::pre_get_posts() guards with is_main_query(), so the
	 * `woocommerce_product_query` action never fires for secondary queries.
	 * Hooking pre_get_posts directly covers all of them:
	 *  • AbstractProductGrid blocks (TopRated, BestSellers, OnSale, HandpickedProducts…)
	 *  • ProductCollection / QueryBuilder
	 *  • Any code that creates a new WP_Query( ['post_type' => 'product', …] )
	 *
	 * Skips:
	 *  • Main query (handled by QueryFilter).
	 *  • Admin requests (product management must show all languages).
	 *  • Requests where LF_LANG is not defined or empty.
	 *  • Queries not targeting post_type = 'product'.
	 *  • Queries that already carry a `_lf_lang` constraint (double-application guard).
	 *
	 * @param WP_Query $query  The WP_Query being built.
	 */
	public static function apply_language_filter_to_secondary_query( WP_Query $query ): void {

		if ( $query->is_main_query() ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return;
		}

		// Only act on product post-type queries.
		$post_type = $query->get( 'post_type' );
		if ( 'product' !== $post_type ) {
			if ( ! is_array( $post_type ) || ! in_array( 'product', $post_type, true ) ) {
				return;
			}
		}

		// Check whether a _lf_lang constraint is already present.
		$meta_query = (array) $query->get( 'meta_query', [] );

		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) && '_lf_lang' === $clause['key'] ) {
				return; // Already filtered — nothing to add.
			}
		}

		$meta_query[] = [
			'key'   => '_lf_lang',
			'value' => LF_LANG,
		];

		$query->set( 'meta_query', $meta_query );
	}

	// =========================================================================
	// Product-grid transient-cache gate
	// =========================================================================

	/**
	 * Disable the BlocksWpQuery transient cache for AbstractProductGrid blocks
	 * (TopRated, BestSellers, OnSale, ProductNew, HandpickedProducts, etc.)
	 * when a language is active.
	 *
	 * BlocksWpQuery::get_cached_posts() computes a hash from the query vars
	 * BEFORE calling get_posts(), which is when our pre_get_posts callback
	 * injects the _lf_lang meta constraint.  The language constraint is therefore
	 * absent from the cache key — whichever language primes the transient first
	 * causes every subsequent language to receive the wrong results.
	 *
	 * Returning false forces AbstractProductGrid::get_products() to call
	 * BlocksWpQuery::get_posts() directly, so pre_get_posts always runs the
	 * correct language-scoped SQL.  Caching is unaffected when LF_LANG is not
	 * set (REST requests, WP-CLI, etc.) — only multilingual frontend pages pay
	 * the cost of a live query, which is acceptable because the main product
	 * catalog (shop archive) already has its own server-level caching.
	 *
	 * @param bool $is_cacheable  WooCommerce's default (true).
	 * @return bool False when a language is active, original value otherwise.
	 */
	public static function disable_product_grid_cache( bool $is_cacheable ): bool {
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $is_cacheable;
		}
		return false;
	}

	// =========================================================================
	// Related-products SQL filter
	// =========================================================================

	/**
	 * Inject a language-scoping INNER JOIN into WooCommerce's related-products
	 * raw SQL query.
	 *
	 * wc_get_related_products() delegates to
	 * WC_Product_Data_Store_CPT::get_related_products_query() which returns a
	 * plain array of SQL fragments (fields / join / where / limits).  That array
	 * is passed through `woocommerce_product_related_posts_query` before being
	 * executed with $wpdb->get_col() — it never goes through WP_Query, so the
	 * `woocommerce_product_query` hook above does not fire for it.
	 *
	 * We add an INNER JOIN on postmeta so that only products whose `_lf_lang`
	 * matches the active language are included.  The JOIN alias `pm_lf_lang` is
	 * deliberately namespaced to avoid collisions with any other postmeta join
	 * WooCommerce may already have added.
	 *
	 * Assumption: every product post has a `_lf_lang` meta row.  Lingua Forge
	 * writes this on every save_post (class-sync.php); any pre-existing product
	 * from before LF was installed can be fixed with a single re-save or a
	 * WP-CLI command.  An INNER JOIN is therefore safe — no products are silently
	 * excluded except those that genuinely belong to a different language.
	 *
	 * Skips:
	 *  • Admin requests — product management must show all languages.
	 *  • Requests where LF_LANG is not defined or empty (REST, CLI without lang).
	 *
	 * @param array $query      SQL fragment array: fields, join, where, limits.
	 * @param int   $product_id Source product ID (unused; present for filter signature).
	 * @return array Modified (or unchanged) SQL fragment array.
	 */
	public static function apply_language_filter_to_related_query( array $query, int $product_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $product_id required by filter signature; language comes from LF_LANG constant.

		if ( is_admin() ) {
			return $query;
		}

		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $query;
		}

		global $wpdb;

		$query['join'] .= $wpdb->prepare(
			" INNER JOIN {$wpdb->postmeta} pm_lf_lang
			      ON pm_lf_lang.post_id = p.ID
			     AND pm_lf_lang.meta_key = '_lf_lang'
			     AND pm_lf_lang.meta_value = %s",
			LF_LANG
		);

		return $query;
	}
}
