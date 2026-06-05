<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate
 *
 * Delegates WooCommerce taxonomy assignments from translated products to the source.
 *
 * In Lingua Forge's shared-stock model, translated products are NOT assigned to
 * WooCommerce taxonomy terms. All language versions share the same term assignments
 * as the source product. Catalog filtering works correctly because the delegation
 * model makes translated products appear in the correct categories.
 *
 * Delegated taxonomies (default set, extensible via `linguaforge_wc_delegate_taxonomies`):
 *   - `product_cat`    — WooCommerce product categories
 *   - `product_tag`    — WooCommerce product tags
 *   - `product_type`   — WooCommerce product type (simple, variable, …)
 *   - `product_brand`  — Native WooCommerce brand taxonomy (WC 10.x+)
 *   - `pa_*`           — All attribute taxonomies (prefix-matched, not in the filter)
 *
 * Third-party brand/badge taxonomies (`pwb-brand`, etc.) can be added via the
 * `linguaforge_wc_delegate_taxonomies` filter without patching this class.
 *
 * This class hooks into `wp_get_object_terms` to intercept taxonomy queries for
 * translated products and return the source product's term assignments instead.
 * The hook removes itself before querying the source product and re-adds itself
 * afterwards to prevent infinite recursion.
 *
 * Category and attribute term names display in the source language in Phase 1.
 * Per-language term name display is added in Phase 1b via the `term_name` filter
 * and `_lf_term_name_{lang}` termmeta.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class TaxonomyDelegate {

	/**
	 * Default WooCommerce taxonomy slugs delegated to the source product.
	 * Used as the default value for the `linguaforge_wc_delegate_taxonomies` filter.
	 * `pa_*` attribute taxonomies are matched by prefix in is_wc_taxonomy() and
	 * are not included here.
	 *
	 * `product_brand` is a native WooCommerce taxonomy since WC 10.x and is
	 * included here so every WC 10+ store delegates brand assignments correctly
	 * without any site-specific configuration.
	 *
	 * @var string[]
	 */
	private const WC_TAXONOMY_DEFAULTS = [
		'product_cat',
		'product_tag',
		'product_type',
		'product_brand', // Native WC 10.x brand taxonomy.
	];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		add_filter( 'wp_get_object_terms', [ self::class, 'maybe_delegate_terms' ], 10, 4 );

		// WordPress primes the term relationship cache during WP_Query processing
		// (via update_object_term_cache()), so by the time WC calls get_the_terms()
		// for the product type the cache already holds the DB value — an empty array
		// for translated products (which have no terms stored). get_the_terms() then
		// returns the cached empty array without calling wp_get_object_terms(), which
		// means TaxonomyDelegate's filter never fires and WC defaults to 'simple'.
		//
		// Fix: on single product pages, bust the term caches for translated products
		// at `wp` action (priority 5, before WC loads the product object). This forces
		// the next get_the_terms() call to go through wp_get_object_terms() → our filter.
		add_action( 'wp', [ self::class, 'clear_translated_product_term_cache' ], 5 );

		// WordPress's loop fires `the_post` after setup_postdata() / update_object_term_cache(),
		// which re-primes the term relationship cache from the DB. This overwrites the caches
		// cleared above at `wp` priority 5. Clear again immediately after the re-prime so
		// subsequent get_the_terms() / wp_get_object_terms() calls go through our filter.
		add_action( 'the_post', [ self::class, 'clear_translated_product_term_cache_on_post' ], 10, 1 );
	}

	// =========================================================================
	// Filter callback
	// =========================================================================

	/**
	 * When wp_get_object_terms() is called for a translated product and a
	 * WooCommerce taxonomy, return the source product's terms instead.
	 *
	 * Applies only to single-object queries (the vast majority of WooCommerce
	 * calls). Multi-object queries (e.g., bulk term lookups) are left unchanged
	 * to avoid incorrectly substituting IDs in bulk admin operations.
	 *
	 * @param  array|WP_Error    $terms       Queried terms.
	 * @param  int|string|array  $object_ids  Comma-separated string of object IDs
	 *                                        as WordPress passes to the filter
	 *                                        (e.g. "42"). Clean int[] is in
	 *                                        $args['object_ids']; we use that.
	 * @param  string|string[]   $taxonomies  SQL-quoted string of taxonomy names
	 *                                        as WordPress passes to the filter
	 *                                        (e.g. "'product_cat'"). Clean string[]
	 *                                        is in $args['taxonomy']; we use that.
	 * @param  array             $args        Query args (contains 'object_ids' and
	 *                                        'taxonomy' as clean arrays).
	 * @return array|WP_Error  Source product's terms, or original $terms on bail.
	 */
	public static function maybe_delegate_terms( array|\WP_Error $terms, int|string|array $object_ids, string|array $taxonomies, array $args ): array|\WP_Error {

		// WordPress fires this filter with $object_ids as a comma-separated
		// string (e.g. "42") and $taxonomies as a SQL-quoted string (e.g.
		// "'product_cat'").  The clean arrays are reliably available via the
		// $args keys that wp_get_object_terms() sets before calling get_terms().
		// Use those to avoid matching against SQL-escaped values.
		$id_list  = isset( $args['object_ids'] ) ? (array) $args['object_ids'] : (array) $object_ids;
		$tax_list = isset( $args['taxonomy'] )   ? (array) $args['taxonomy']   : (array) $taxonomies;

		// ── 1. Only handle single-object queries ──────────────────────────────
		if ( count( $id_list ) !== 1 ) {
			return $terms;
		}

		$object_id = (int) $id_list[0];

		// ── 2. Only handle WooCommerce taxonomies ─────────────────────────────
		$is_wc_tax = false;
		foreach ( $tax_list as $tax ) {
			if ( self::is_wc_taxonomy( (string) $tax ) ) {
				$is_wc_tax = true;
				break;
			}
		}

		if ( ! $is_wc_tax ) {
			return $terms;
		}

		// ── 3. Post type guard ─────────────────────────────────────────────────
		$post = get_post( $object_id );
		if ( ! $post ) {
			return $terms;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return $terms;
		}

		// ── 4. Language guard — only delegate for translated (non-source) posts ─
		$lang = (string) get_post_meta( $object_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $terms;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $terms;
		}

		// ── 5. Resolve source product ID ──────────────────────────────────────
		$source_id = MetaDelegate::get_source_id_for( $object_id );
		if ( ! $source_id || $source_id === $object_id ) {
			return $terms;
		}

		// ── 6. Query source product's terms (remove filter to prevent recursion) ─
		// Pass $tax_list (the clean taxonomy-name array from $args['taxonomy'])
		// and strip 'object_ids'/'taxonomy' from $args so WordPress re-derives
		// them from the function parameters rather than from the stale translated
		// product values that wp_get_object_terms() bakes into $args before
		// firing this filter.
		$source_args = $args;
		unset( $source_args['object_ids'], $source_args['taxonomy'] );

		remove_filter( 'wp_get_object_terms', [ self::class, 'maybe_delegate_terms' ], 10 );

		$source_terms = wp_get_object_terms( $source_id, $tax_list, $source_args );

		add_filter( 'wp_get_object_terms', [ self::class, 'maybe_delegate_terms' ], 10, 4 );

		if ( is_wp_error( $source_terms ) ) {
			return $terms;
		}

		// Rewrite object_id on every returned term to the TRANSLATED post ID.
		//
		// When WC calls _prime_post_caches() → update_object_term_cache(), WordPress
		// fires a COMBINED multi-taxonomy wp_get_object_terms() and distributes results
		// into per-taxonomy cache buckets using $term->object_id as the key. Our source
		// terms carry object_id = $source_id (e.g. 180). Without this rewrite, all
		// delegated terms land in bucket 180 and bucket 183 gets cached as empty — so
		// get_the_terms(183, 'product_type') returns the cached [] and WC defaults to
		// 'simple'. Rewriting to $object_id makes update_object_term_cache distribute
		// the delegated terms into the translated post's buckets correctly.
		foreach ( $source_terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				// @phpstan-ignore-next-line -- WP_Term::$object_id is a dynamic property added by WordPress at runtime; not declared in stubs.
				$term->object_id = $object_id; // phpcs:ignore WordPress.WP.DiscouragedFunctions.object_id -- intentional rewrite for cache distribution.
			}
		}

		return $source_terms;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Bust WC taxonomy caches for the current single product when it is a
	 * translated (non-source) product.
	 *
	 * WordPress calls update_object_term_cache() inside WP_Query::get_posts(),
	 * which primes the term relationship caches from the DB before any plugin
	 * code runs. Translated products have no WC taxonomy terms stored in the DB,
	 * so the cache holds empty arrays. When WC then calls get_the_terms($id,
	 * 'product_type'), WordPress returns the cached empty result without invoking
	 * wp_get_object_terms() — TaxonomyDelegate's filter is never reached and WC
	 * defaults to product type 'simple'.
	 *
	 * This method fires at the `wp` action (priority 5), after the query is set up
	 * but before WC loads the product object. It deletes the cached values for all
	 * WC taxonomy relationships on the translated product post, so the next
	 * get_the_terms() call finds an empty cache and falls through to
	 * wp_get_object_terms() where TaxonomyDelegate can intercept.
	 *
	 * Runs only on singular product pages — no overhead on archives or other pages.
	 */
	public static function clear_translated_product_term_cache(): void {

		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return; // Source product — its own cached terms are correct.
		}

		foreach ( self::get_taxonomies_to_clear() as $taxonomy ) {
			wp_cache_delete( $post_id, $taxonomy . '_relationships' );
		}
	}

	/**
	 * Called by `the_post`, which fires at the start of each loop iteration before
	 * any template code runs. This ensures the term relationship cache is clear
	 * immediately before WC reads the product object (and its type) for this post,
	 * so get_the_terms() falls through to wp_get_object_terms() → TaxonomyDelegate
	 * rather than returning the cached empty array written by update_object_term_cache()
	 * during WP_Query::get_posts().
	 *
	 * @param \WP_Post $post  The post being set up.
	 */
	public static function clear_translated_product_term_cache_on_post( \WP_Post $post ): void {

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product', 'product_variation' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return;
		}

		$lang = (string) get_post_meta( $post->ID, '_lf_lang', true );
		if ( '' === $lang ) {
			return;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return;
		}

		foreach ( self::get_taxonomies_to_clear() as $taxonomy ) {
			wp_cache_delete( $post->ID, $taxonomy . '_relationships' );
		}
	}

	/**
	 * Returns the full list of taxonomy slugs whose term relationship caches must be
	 * cleared for translated products. Computed once per request and cached statically.
	 *
	 * Covers WC_TAXONOMY_DEFAULTS, product_type, and all registered pa_* attribute
	 * taxonomies. Called by both clear_translated_product_term_cache() (singular page,
	 * `wp` action) and clear_translated_product_term_cache_on_post() (`the_post` loop).
	 *
	 * @return string[]
	 */
	private static function get_taxonomies_to_clear(): array {

		static $taxonomies = null;

		if ( null === $taxonomies ) {
			$taxonomies = array_merge( self::WC_TAXONOMY_DEFAULTS, [ 'product_type' ] );
			foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
				if ( str_starts_with( $taxonomy, 'pa_' ) ) {
					$taxonomies[] = $taxonomy;
				}
			}
		}

		return $taxonomies;
	}

	/**
	 * Returns true when $taxonomy is a WooCommerce taxonomy managed by delegation.
	 *
	 * `pa_*` attribute taxonomies are matched by prefix and are always delegated
	 * regardless of the filter. All other WC taxonomies go through the filterable
	 * default list (`WC_TAXONOMY_DEFAULTS`), allowing site-specific additions:
	 *
	 *   add_filter( 'linguaforge_wc_delegate_taxonomies', function( $taxonomies ) {
	 *       $taxonomies[] = 'pwb-brand'; // Perfect Brands for WooCommerce
	 *       return $taxonomies;
	 *   } );
	 *
	 * @param string $taxonomy  Taxonomy slug to check.
	 */
	private static function is_wc_taxonomy( string $taxonomy ): bool {

		// pa_* attribute taxonomies are always delegated (prefix match, fast path).
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			return true;
		}

		/**
		 * Filters the list of WooCommerce taxonomy slugs that are delegated from
		 * translated products to their source product.
		 *
		 * The default list covers the taxonomies registered by WooCommerce core:
		 * product_cat, product_tag, product_type, and product_brand (WC 10.x+).
		 * pa_* attribute taxonomies are handled separately via prefix matching and
		 * do not need to be added here.
		 *
		 * @since 2.1.6
		 *
		 * @param string[] $taxonomies  Array of taxonomy slugs to delegate.
		 */
		$wc_taxonomies = (array) apply_filters( 'linguaforge_wc_delegate_taxonomies', self::WC_TAXONOMY_DEFAULTS );

		return in_array( $taxonomy, $wc_taxonomies, true );
	}
}
