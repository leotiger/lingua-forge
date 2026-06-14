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
 *    When the secondary query carries a `tax_query` (e.g. a Product Collection
 *    block filtered to a category embedded on a normal page), translated products
 *    have no wp_term_relationships rows and the SQL JOIN returns zero.  The same
 *    trid-lookup strategy used by WcPageBridge::inject_taxonomy_archive_lang() is
 *    applied: source products matching the tax_query are looked up first, their
 *    trids collected, and the tax_query replaced with _lf_trid IN + _lf_lang.
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

	/**
	 * Per-request cache for Phase 3a trid-match checks.
	 *
	 * Keyed by md5(trids).':'.effective_lang — true when at least one translated
	 * product with a matching _lf_trid exists, false when none do.
	 *
	 * Declared as a class property (not a function-local static) so that unit
	 * tests can reset it via Reflection between test cases.
	 *
	 * @var array<string,bool>
	 */
	private static array $trid_match_cache = [];

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
	 * Tax-query handling for catalogue blocks on normal pages:
	 *  WooCommerce catalogue blocks (Product Collection, Products, HandpickedProducts, …)
	 *  embedded on a regular page carry a `tax_query` for their category/tag filter.
	 *  WordPress resolves `tax_query` with a SQL JOIN on `wp_term_relationships`.
	 *  In LF's shared-stock model, translated products have no rows in that table
	 *  (TaxonomyDelegate virtualises the assignment at the PHP layer via
	 *  `wp_get_object_terms`, not at the SQL layer).  A plain `_lf_lang` meta
	 *  constraint combined with a `tax_query` therefore returns zero results on
	 *  any non-source-language page.
	 *
	 *  When a `tax_query` is present and the active language is not the source
	 *  language, we apply the same three-phase trid-lookup strategy used by
	 *  WcPageBridge::inject_taxonomy_archive_lang() for category archive pages:
	 *   1. Fetch source-language product IDs that satisfy the original tax_query
	 *      (suppress_filters=true + explicit _lf_lang=$source_lang so that this
	 *      same callback's early-return guard fires and prevents recursion).
	 *   2. Collect their _lf_trid values.
	 *   3. Replace the tax_query with _lf_trid IN ($trids) + _lf_lang in meta_query.
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

		// On single product pages the queried object's _lf_lang may differ from
		// LF_LANG.  Language-neutral product URLs (e.g. /product/widget-de-prueba/)
		// always have LF_LANG = source language even when the product is a translation.
		// Using LF_LANG would exclude the translated related products returned by
		// woocommerce_related_products / apply_language_filter_to_related_query.
		// Use the product's own _lf_lang so the secondary post__in query accepts them.
		$effective_lang = LF_LANG;
		if ( is_singular( 'product' ) ) {
			$queried_object = get_queried_object();
			if ( $queried_object instanceof \WP_Post ) {
				$product_lang = (string) get_post_meta( $queried_object->ID, '_lf_lang', true );
				if ( '' !== $product_lang ) {
					$effective_lang = $product_lang;
				}
			}
		}

		// ── Tax-query path: catalogue blocks on normal pages ──────────────────────
		//
		// When the query carries a tax_query (e.g. Product Collection block filtered
		// to a category) AND we are not on the source-language page, the SQL JOIN on
		// wp_term_relationships returns zero rows for translated products.
		// Resolve using the trid-lookup strategy: fetch source products that match
		// the taxonomy constraints, collect their trids, and replace the tax_query
		// with _lf_trid IN ($trids) + _lf_lang in meta_query.
		$source_lang = \LinguaForge\Router\Router::get_instance()->source_language();
		$tax_query   = (array) $query->get( 'tax_query', [] );

		// ── post__in path: handpicked / explicitly-IDed product blocks ────────────
		//
		// Handpicked product blocks (woocommerce/handpicked-products and
		// woocommerce/product-collection with woocommerceHandPickedProducts) store
		// source-language post IDs in post__in.  On a non-source-language page a
		// plain _lf_lang constraint combined with those IDs returns zero, because
		// the specific post IDs belong to the source language.
		//
		// Map each source ID to its translated sibling via _lf_trid, then clear
		// any tax_query: translated products have no wp_term_relationships rows of
		// their own, so any visibility/category JOIN would filter them out again.
		$post_in = array_values( array_filter( array_map( 'intval', (array) $query->get( 'post__in', [] ) ) ) );

		if ( ! empty( $post_in ) && $effective_lang !== $source_lang ) {
			$router         = \LinguaForge\Router\Router::get_instance();
			$translated_ids = [];

			foreach ( $post_in as $src_id ) {
				if ( $effective_lang === $router->get_lang( $src_id ) ) {
					// ID is already in the target language (block on a translated page).
					$translated_ids[] = $src_id;
					continue;
				}
				// Walk the translation group to find the target-language sibling.
				foreach ( $router->get_translations( $src_id ) as $sibling_id ) {
					if ( $effective_lang === $router->get_lang( $sibling_id ) ) {
						$translated_ids[] = $sibling_id;
						break;
					}
				}
			}

			$query->set( 'post__in', ! empty( $translated_ids ) ? $translated_ids : [ -1 ] );
			$query->set( 'tax_query', [] );
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$meta_query[] = [ 'key' => '_lf_lang', 'value' => $effective_lang ];
			$query->set( 'meta_query', $meta_query );
			return;
		}

		if ( ! empty( $tax_query ) && $effective_lang !== $source_lang ) {

			// Phase 1: source products that satisfy the original tax_query.
			// We pass _lf_lang = $source_lang explicitly so that our own
			// pre_get_posts callback sees the _lf_lang key and returns early,
			// preventing recursion.  suppress_filters=true prevents post_results /
			// the_posts filters from firing (TaxonomyDelegate is not needed here
			// since source products have their own wp_term_relationships rows).
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$source_ids = get_posts( [
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'tax_query'        => $tax_query,
				'meta_query'       => [ [ 'key' => '_lf_lang', 'value' => $source_lang ] ],
			] );
			// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_tax_query

			// Phase 2: collect _lf_trid values.
			$trids = [];
			foreach ( (array) $source_ids as $id ) {
				$trid = (string) get_post_meta( (int) $id, '_lf_trid', true );
				if ( '' !== $trid ) {
					$trids[] = $trid;
				}
			}

			// Phase 3: replace the tax_query with a meta_query trid+lang scope.
			$query->set( 'tax_query', [] );

			if ( empty( $trids ) ) {
				if ( empty( $source_ids ) ) {
					// No source products satisfy the original tax_query — nothing to
					// translate; return an impossible condition so the block is empty.
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					$query->set( 'meta_query', [ [ 'key' => '_lf_lang', 'value' => '__lf_no_match__' ] ] );
				} else {
					// Source products exist but none carry _lf_trid — products
					// pre-date _lf_trid being written by class-sync.php (e.g. imported
					// without an authenticated admin user).  tax_query already cleared;
					// fall back to a plain _lf_lang constraint so translated products
					// (if any) are still shown.
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					$meta_query[] = [ 'key' => '_lf_lang', 'value' => $effective_lang ];
					$query->set( 'meta_query', $meta_query );
				}
				return;
			}

			// Phase 3a: verify that translated products with these trids actually
			// exist before committing to the trid-based query.  Without this check,
			// products created programmatically (e.g. AI-batch translation, WC CSV
			// import, REST without the translations-metabox nonce) receive their
			// own fresh UUID rather than inheriting the source's _lf_trid, causing
			// Phase 3 to silently return zero results.
			//
			// The check is cached per (trid-set, language) pair per request so that
			// multiple blocks with the same taxonomy filter on the same page pay the
			// cost of at most one extra query.
			$trid_cache_key = md5( implode( ',', $trids ) ) . ':' . $effective_lang;

			if ( ! array_key_exists( $trid_cache_key, self::$trid_match_cache ) ) {
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$trid_matches = get_posts( [
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_query'       => [
						[ 'key' => '_lf_trid', 'value' => $trids, 'compare' => 'IN' ],
						[ 'key' => '_lf_lang', 'value' => $effective_lang ],
					],
				] );
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				self::$trid_match_cache[ $trid_cache_key ] = ! empty( $trid_matches );
			}

			if ( ! self::$trid_match_cache[ $trid_cache_key ] ) {
				// Trids were resolved from source products but no translated products
				// carry matching trids — translation group linkage is incomplete
				// (class-sync.php will fix on next admin save of the source product).
				// Fall back to _lf_lang so any translated products are still shown.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$meta_query[] = [ 'key' => '_lf_lang', 'value' => $effective_lang ];
				$query->set( 'meta_query', $meta_query );
				return;
			}

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$meta_query[] = [ 'key' => '_lf_trid', 'value' => $trids, 'compare' => 'IN' ];
			$meta_query[] = [ 'key' => '_lf_lang', 'value' => $effective_lang ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$query->set( 'meta_query', $meta_query );
			return;
		}

		// ── Simple path: no tax_query (or source-language page) ──────────────────
		$meta_query[] = [
			'key'   => '_lf_lang',
			'value' => $effective_lang,
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
	 * @param array $query       SQL fragment array: fields, join, where, limits.
	 * @param int   $_product_id Source product ID (unused; present for filter signature).
	 * @return array Modified (or unchanged) SQL fragment array.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $product_id is required by the filter signature but this hook only needs the SQL array.
	public static function apply_language_filter_to_related_query( array $query, int $_product_id ): array {

		if ( is_admin() ) {
			return $query;
		}

		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $query;
		}

		// Always use the source language so the SQL JOIN hits real wp_term_relationships
		// rows.  TaxonomyDelegate virtualises taxonomy at the PHP layer; WC's raw $wpdb
		// SQL bypasses those filters and queries the table directly.  Translated products
		// have no wp_term_relationships rows of their own, so using LF_LANG on a
		// language-specific URL (e.g. /es/producto/…, LF_LANG=es) always returns empty.
		// Using the source language returns source-language peers; filter_related_products_by_lang
		// (woocommerce_related_products, p10) then maps those IDs to translations via _lf_trid.
		$source_lang = \LinguaForge\Router\Router::get_instance()->context->source_language();

		global $wpdb;

		$query['join'] .= $wpdb->prepare(
			" INNER JOIN {$wpdb->postmeta} pm_lf_lang
			      ON pm_lf_lang.post_id = p.ID
			     AND pm_lf_lang.meta_key = '_lf_lang'
			     AND pm_lf_lang.meta_value = %s",
			$source_lang
		);

		return $query;
	}
}
