<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\OrderItemNormalizer
 *
 * Normalizes the product_id on WooCommerce order line items to the source-
 * language product at checkout, when the "Normalize order line items to source
 * product" setting is enabled (default: on).
 *
 * Why this matters:
 *
 *  - `wc_update_total_sales_counts()` runs after payment and increments
 *    `total_sales` directly on the product_id stored in each order line item.
 *    Without normalization this increments the translated product's counter;
 *    with normalization it correctly increments the source product (§6.4).
 *
 *  - WC Analytics aggregates revenue and units sold by order-item product_id.
 *    Normalized IDs produce one row per product instead of one row per language
 *    version in sales-by-product reports.
 *
 *  - `_sku` is already delegated via MetaDelegate, so SKU-keyed exports and
 *    reports already aggregate correctly regardless of this setting.
 *
 * Trade-offs (deliberate, documented in CONTRIBUTING):
 *
 *  - "View product" from the My Account order screen or transactional emails
 *    links to the source-language page, not the customer's language.
 *  - The admin order screen shows the source-language product title.
 *
 * The `linguaforge_wc_order_item_source_mapping` filter allows third-party code
 * to override normalization on a per-item basis (return false to skip an item).
 *
 * Note: variation_id on the line item is intentionally left unchanged. Stock
 * reduction for variable products uses variation_id, which StockRouter already
 * handles; variation-level normalization is a separate future concern.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

class OrderItemNormalizer {

	/**
	 * Option key for the "normalize order line items" setting.
	 * Stored as 'yes'|'no' (WC convention for boolean options) but exposed as
	 * a bool via is_enabled().
	 */
	public const OPT_NORMALIZE = 'linguaforge_wc_normalize_order_product_ids';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 10 (default) — runs before most third-party plugins that
		// read product meta from the item at later priorities.
		add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'normalize_product_id' ], 10, 4 );
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Normalize the product_id on a checkout line item to the source-language
	 * product, when the site setting is enabled and the filter allows it.
	 *
	 * @param \WC_Order_Item_Product $item          The order line item being created.
	 * @param string                 $cart_item_key Cart item key (unused).
	 * @param array<string,mixed>    $values        Cart item data (unused).
	 * @param \WC_Order              $order         The order being created (unused).
	 */
	public static function normalize_product_id( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $cart_item_key, $values, $order required by WC action signature.
		\WC_Order_Item_Product $item,
		string                 $cart_item_key,
		array                  $values,
		\WC_Order              $order
	): void {

		$product_id = (int) $item->get_product_id();
		if ( ! $product_id ) {
			return;
		}

		// ── Source resolution ──────────────────────────────────────────────────
		$source_id = MetaDelegate::get_source_id_for( $product_id );
		if ( ! $source_id || $source_id === $product_id ) {
			return; // Already the source, or no TRID group found.
		}

		// ── Site setting ───────────────────────────────────────────────────────
		$normalize = self::is_enabled();

		/**
		 * Filter: linguaforge_wc_order_item_source_mapping
		 *
		 * Allows third-party code to enable or disable product-ID normalization
		 * on a per-item basis.
		 *
		 * @param bool                   $normalize  Whether to normalize this item.
		 * @param int                    $product_id The translated product ID on the line item.
		 * @param int                    $source_id  The resolved source product ID.
		 * @param \WC_Order_Item_Product $item       The order line item.
		 */
		$normalize = (bool) apply_filters( 'linguaforge_wc_order_item_source_mapping', $normalize, $product_id, $source_id, $item );

		if ( ! $normalize ) {
			return;
		}

		$item->set_product_id( $source_id );
	}

	// =========================================================================
	// Helper
	// =========================================================================

	/**
	 * Whether the normalize-order-line-items setting is active.
	 * Defaults to true (on) when the option has not been saved yet.
	 */
	public static function is_enabled(): bool {
		return 'no' !== get_option( self::OPT_NORMALIZE, 'yes' );
	}
}
