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
 * Hooks into SchemaManager extension point:
 *
 *   linguaforge_seo_schema_extra_types action — outputs Product JSON-LD for WC
 *                                               product pages with name, description,
 *                                               inLanguage, url, image, and offers.
 *
 * Only registers Schema hook when linguaforge_seo_schema_enabled and
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

		// ── Schema.org hook ───────────────────────────────────────────────────
		if ( get_option( 'linguaforge_seo_schema_enabled', true )
			&& get_option( 'linguaforge_seo_schema_product', true ) ) {
			add_action( 'linguaforge_seo_schema_extra_types', [ self::class, 'output_product_schema' ], 10, 2 );
		}
	}

	// =========================================================================
	// Filters / actions
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
	// Schema.org — Product
	// =========================================================================

	/**
	 * Output Schema.org Product JSON-LD for WooCommerce product pages.
	 *
	 * Hooked on linguaforge_seo_schema_extra_types — only fires when
	 * SchemaManager is active (no conflicting SEO plugin detected) and both
	 * linguaforge_seo_schema_enabled and linguaforge_seo_schema_product are true.
	 *
	 * @param string $lang        Current LF language code (e.g. 'de').
	 * @param string $in_language BCP 47 locale (e.g. 'de-DE').
	 */
	public static function output_product_schema( string $lang, string $in_language ): void {

		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$post    = get_post();
		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product || ! $post instanceof \WP_Post ) {
			return;
		}

		$name        = wp_strip_all_tags( get_the_title( $post ) );
		$url         = (string) get_permalink( $post );
		$description = self::get_product_description( $post );
		$image       = self::get_product_image( $post, $product );
		$price       = $product->get_price();
		$currency    = get_woocommerce_currency();

		// Schema.org availability URLs.
		$availability_map = [
			'instock'     => 'https://schema.org/InStock',
			'outofstock'  => 'https://schema.org/OutOfStock',
			'onbackorder' => 'https://schema.org/PreOrder',
		];
		$availability = $availability_map[ $product->get_stock_status() ] ?? 'https://schema.org/InStock';

		$data = [
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $name,
			'url'         => $url,
			'inLanguage'  => $in_language,
		];

		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		if ( '' !== $image ) {
			$data['image'] = $image;
		}

		if ( '' !== (string) $price ) {
			$data['offers'] = [
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
				'availability'  => $availability,
				'url'           => $url,
			];
		}

		$data = (array) apply_filters( 'linguaforge_seo_schema_data', $data, 'Product' );

		// Re-use SchemaManager's output helper via the Router singleton.
		\LinguaForge\Router\Seo\SchemaManager::output_schema( $data );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	private static function get_product_description( \WP_Post $post ): string {

		$lf_meta = get_post_meta( $post->ID, '_linguaforge_meta_description', true );
		if ( is_string( $lf_meta ) && '' !== trim( $lf_meta ) ) {
			return trim( $lf_meta );
		}
		if ( '' !== $post->post_excerpt ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
	}

	private static function get_product_image( \WP_Post $post, \WC_Product $product ): string {

		// WooCommerce product image (stored as _thumbnail_id on the source product).
		if ( has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post ), 'full' );
			if ( $src ) return $src;
		}

		// Fallback: first gallery image.
		$gallery = $product->get_gallery_image_ids();
		if ( ! empty( $gallery ) ) {
			$src = wp_get_attachment_image_url( (int) $gallery[0], 'full' );
			if ( $src ) return $src;
		}

		return '';
	}
}
