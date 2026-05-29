<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate
 *
 * Delegates WooCommerce taxonomy assignments from translated products to the source.
 *
 * In Lingua Forge's shared-stock model, translated products are NOT assigned to
 * WooCommerce taxonomy terms (`product_cat`, `product_tag`, `product_type`,
 * `pa_*` attribute taxonomies). All language versions share the same term
 * assignments as the source product. Catalog filtering works correctly because
 * the delegation model makes translated products appear in the correct categories.
 *
 * This class hooks into `wp_get_object_terms` to intercept taxonomy queries for
 * translated products and return the source product's term assignments instead.
 *
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
	 * WooCommerce taxonomy slugs always delegated to the source product.
	 * `pa_*` attribute taxonomies are matched by prefix; see is_wc_taxonomy().
	 *
	 * @var string[]
	 */
	private const WC_TAXONOMY_PREFIXES = [ 'product_cat', 'product_tag', 'product_type' ];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		add_filter( 'wp_get_object_terms', [ self::class, 'maybe_delegate_terms' ], 10, 4 );
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

		return is_wp_error( $source_terms ) ? $terms : $source_terms;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Returns true when $taxonomy is a WooCommerce taxonomy managed by delegation.
	 *
	 * Covers product_cat, product_tag, product_type, and all pa_* attribute
	 * taxonomies (WooCommerce registers one per product attribute, e.g. pa_color).
	 */
	private static function is_wc_taxonomy( string $taxonomy ): bool {

		if ( in_array( $taxonomy, self::WC_TAXONOMY_PREFIXES, true ) ) {
			return true;
		}

		return str_starts_with( $taxonomy, 'pa_' );
	}
}
