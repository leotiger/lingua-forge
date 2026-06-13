<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\CouponTridMap
 *
 * Expands WooCommerce coupon product-ID restrictions to cover all TRID siblings.
 *
 * The problem: WooCommerce coupons store `product_ids` and `excluded_product_ids`
 * against the source-language product post ID (the one the admin selected in the
 * coupon editor). Cart items carry the *translated* product post ID, so
 * WC_Coupon::is_valid_for_product() ID comparison fails for customers shopping
 * in any non-source language — a "10% off Product X" coupon silently does nothing
 * on /es/, /ca/, /de/ product pages even though the same physical product is in
 * the cart.
 *
 * The fix: filter `woocommerce_coupon_get_product_ids` (and the excluded variant)
 * to expand each stored ID to its full TRID group (source + every translated
 * sibling). WC then finds the translated cart item ID in the expanded list and
 * applies the discount correctly.
 *
 * Variable products / variations: expanding the parent ID covers translated
 * parent IDs, and WC already checks $product->get_parent_id() alongside
 * $product->get_id(), so variable-product coupons work without extra logic.
 * Variation-specific coupons also work: translated variations carry their own
 * TRID entries and are expanded the same way.
 *
 * Category restrictions: WC validates these via has_term() → wp_get_object_terms(),
 * which TaxonomyDelegate already intercepts. In cart/checkout context the term
 * relationship cache is not pre-primed (update_object_term_cache() runs inside
 * WP_Query::get_posts(), not during cart processing), so TaxonomyDelegate fires
 * correctly and no additional fix is needed here.
 *
 * Performance: TRID lookups are cross-populated — a single linguaforge_get_translations()
 * call for any group member primes the cache for all siblings, so repeated calls
 * for the same group are O(1) array reads within a request.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

class CouponTridMap {

	/**
	 * Per-request TRID group cache.
	 *
	 * Maps any product post ID (source or translated) to the full array of
	 * sibling IDs in its TRID group, including the ID itself.
	 *
	 * @var array<int, int[]>
	 */
	private static array $group_cache = [];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		add_filter( 'woocommerce_coupon_get_product_ids',          [ self::class, 'expand_ids' ], 10, 2 );
		add_filter( 'woocommerce_coupon_get_excluded_product_ids', [ self::class, 'expand_ids' ], 10, 2 );
	}

	// =========================================================================
	// Filter callbacks
	// =========================================================================

	/**
	 * Expand each product ID in a coupon's restriction list to include all
	 * translated siblings, so the coupon discount (or exclusion) applies
	 * regardless of which language version the customer added to cart.
	 *
	 * Hooked to:
	 *   woocommerce_coupon_get_product_ids          (allow list)
	 *   woocommerce_coupon_get_excluded_product_ids (deny list)
	 *
	 * @param  int[]       $ids     Product IDs stored on the coupon.
	 * @param  \WC_Coupon  $_coupon The coupon being validated (unused; provided for context by WC).
	 * @return int[]        Expanded list including all TRID siblings, de-duped.
	 */
	public static function expand_ids( array $ids, \WC_Coupon $_coupon ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WC filter signature; passed for context but not needed here.

		if ( empty( $ids ) || ! function_exists( 'linguaforge_get_translations' ) ) {
			return $ids;
		}

		$expanded = [];

		foreach ( $ids as $id ) {
			$id         = (int) $id;
			$expanded[] = $id;

			foreach ( self::get_trid_group( $id ) as $sibling_id ) {
				if ( $sibling_id !== $id ) {
					$expanded[] = $sibling_id;
				}
			}
		}

		return array_values( array_unique( $expanded ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return all product IDs in the TRID group for the given post ID.
	 *
	 * Results are cross-populated across all siblings on the first call for
	 * any group member, so subsequent calls for any sibling are cache hits.
	 *
	 * @param  int $id  Any product post ID (source or translated).
	 * @return int[]    All sibling IDs including $id, or just [$id] when no TRID.
	 */
	private static function get_trid_group( int $id ): array {

		if ( isset( self::$group_cache[ $id ] ) ) {
			return self::$group_cache[ $id ];
		}

		$translations = linguaforge_get_translations( $id );

		if ( empty( $translations ) ) {
			// No TRID group — product is unmanaged by LF; keep it as-is.
			self::$group_cache[ $id ] = [ $id ];
			return [ $id ];
		}

		$group = array_values( array_unique( array_map( 'intval', $translations ) ) );

		// Cross-populate: prime the cache for every sibling so subsequent calls
		// within this request are O(1) for any group member.
		foreach ( $group as $sibling_id ) {
			self::$group_cache[ $sibling_id ] = $group;
		}

		// Defensive: $id might not appear in $translations (shouldn't happen).
		if ( ! isset( self::$group_cache[ $id ] ) ) {
			self::$group_cache[ $id ] = $group;
		}

		return $group;
	}
}
