<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\SeoSupport
 *
 * WooCommerce-specific SEO enhancements for Lingua Forge.
 *
 * ── Open Graph ────────────────────────────────────────────────────────────
 * Hooks into SeoManager extension points:
 *
 *   linguaforge_seo_og_type       filter — returns 'product' on WC product pages.
 *   linguaforge_seo_og_extra_tags action — outputs og:price:amount, og:price:currency,
 *                                          og:availability (and product: namespace equivalents).
 *
 * Only registers OG hooks when linguaforge_seo_wc_og_enabled is truthy (default true).
 *
 * ── Schema.org ────────────────────────────────────────────────────────────
 * WooCommerce already outputs its own Product JSON-LD via WC_Structured_Data
 * (wp_footer).  Emitting a parallel Product block from LF would produce duplicate
 * schema — two <script type="application/ld+json"> Product entries on the same page.
 *
 * Instead, SeoSupport hooks woocommerce_structured_data_product and injects
 * `inLanguage` (BCP 47) into WC's own markup.  WC does the heavy lifting
 * (SKU, gtin, reviews, offers, etc.); LF adds the multilingual property.
 *
 * SchemaManager suppresses its WebPage block on product pages for the same
 * reason — WC_Structured_Data already covers structured data for that page type.
 *
 * Only registers the Schema hook when linguaforge_seo_schema_enabled and
 * linguaforge_seo_schema_product are both truthy (defaults true).
 *
 * Bootstrapped by WooCommerce\Bootstrap::init() — only called when WC is active.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.2.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

class SeoSupport {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {

		// ── Open Graph hooks ──────────────────────────────────────────────────
		if ( get_option( 'linguaforge_seo_wc_og_enabled', true ) ) {
			add_filter( 'linguaforge_seo_og_type',       [ self::class, 'filter_og_type'  ] );
			add_action( 'linguaforge_seo_og_extra_tags', [ self::class, 'output_og_extra' ] );
		}

		// ── Schema.org: inject inLanguage into WC's own Product schema ────────
		// WC_Structured_Data collects product markup via this filter and outputs
		// it in a single JSON-LD graph at wp_footer.  We add inLanguage here
		// instead of emitting a separate Product block from SchemaManager.
		if ( get_option( 'linguaforge_seo_schema_enabled', true )
			&& get_option( 'linguaforge_seo_schema_product', true ) ) {
			add_filter( 'woocommerce_structured_data_product', [ self::class, 'inject_inlanguage' ] );
		}
	}

	// =========================================================================
	// Open Graph filters / actions
	// =========================================================================

	/**
	 * Return 'product' when the current page is a WooCommerce product.
	 *
	 * @param  string $type  Resolved og:type value from SeoManager ('article'|'website').
	 * @return string
	 */
	public static function filter_og_type( string $type ): string {

		if ( is_singular( 'product' ) ) {
			return 'product';
		}

		return $type;
	}

	/**
	 * Output WooCommerce product Open Graph properties.
	 *
	 * Fires only in SeoManager 'full' mode (the action is never called in
	 * 'locale-only' or 'disabled' mode).  Bails silently on non-product pages.
	 */
	public static function output_og_extra(): void {

		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// ── Price ──────────────────────────────────────────────────────────────
		// Use the display price (respects WC tax settings) rather than the raw
		// meta value so the OG tag reflects what visitors see on the page.
		$price    = $product->get_price();
		$currency = get_woocommerce_currency();

		if ( '' !== (string) $price ) {
			echo '<meta property="og:price:amount" content="' . esc_attr( $price ) . '">' . "\n";
			echo '<meta property="og:price:currency" content="' . esc_attr( $currency ) . '">' . "\n";

			// product:price:amount / product:price:currency — Facebook Open Graph
			// product namespace (used by Facebook Shop and Catalog).
			echo '<meta property="product:price:amount" content="' . esc_attr( $price ) . '">' . "\n";
			echo '<meta property="product:price:currency" content="' . esc_attr( $currency ) . '">' . "\n";
		}

		// ── Availability ───────────────────────────────────────────────────────
		// Facebook Open Graph availability values: 'instock', 'oos' (out of stock),
		// 'pending' (preorder).  WooCommerce stock statuses: 'instock', 'outofstock',
		// 'onbackorder'.
		$stock_status = $product->get_stock_status();

		$availability_map = [
			'instock'     => 'instock',
			'outofstock'  => 'oos',
			'onbackorder' => 'pending',
		];

		$availability = $availability_map[ $stock_status ] ?? 'instock';

		echo '<meta property="og:availability" content="' . esc_attr( $availability ) . '">' . "\n";
		echo '<meta property="product:availability" content="' . esc_attr( $availability ) . '">' . "\n";
	}

	// =========================================================================
	// Schema.org — inLanguage injection into WC's own markup
	// =========================================================================

	/**
	 * Inject `inLanguage` (BCP 47) into WooCommerce's Product structured data.
	 *
	 * WC_Structured_Data applies this filter before collecting the markup into
	 * its internal graph array, which is then output as a single JSON-LD block
	 * at wp_footer.  Adding inLanguage here is the correct integration point —
	 * it avoids a duplicate Product schema while still surfacing the language
	 * property that crawlers use for multilingual structured data.
	 *
	 * @param  array<string, mixed> $markup  WC Product markup (no @context yet).
	 * @return array<string, mixed>
	 */
	public static function inject_inlanguage( array $markup ): array {

		if ( ! defined( 'LF_LANG' ) ) {
			return $markup;
		}

		$markup['inLanguage'] = \LinguaForge\Router\Seo\SchemaManager::lang_to_bcp47( LF_LANG );

		return $markup;
	}
}
