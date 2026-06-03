<?php
/**
 * WooCommerce REST write guard — returns HTTP 422 for PUT/PATCH to translated
 * products or product variations. Source product ID is included in the error
 * response so callers can resolve the correct write target.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.1.6
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use LinguaForge\Router\Router;
use WP_Error;
use WP_REST_Request;

class RestWriteGuard {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Covers PUT/PATCH on /wc/v3/products/{id}.
		add_filter( 'woocommerce_rest_pre_insert_product_object', [ self::class, 'guard_product_write' ], 10, 3 );

		// Covers PUT/PATCH on /wc/v3/products/{product_id}/variations/{id}.
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', [ self::class, 'guard_product_write' ], 10, 3 );
	}

	// =========================================================================
	// Filter callback
	// =========================================================================

	/**
	 * Block REST writes targeting translated (non-source) products or variations.
	 *
	 * @param  mixed            $product   WC product/variation object prepared for the write
	 *                                     (WC_Data subclass), or WP_Error from an earlier filter.
	 * @param  WP_REST_Request  $request   The current REST request.
	 * @param  bool             $creating  True for POST (create), false for PUT/PATCH (update).
	 * @return mixed  The unmodified $product (pass-through), or WP_Error to abort.
	 */
	public static function guard_product_write( mixed $product, WP_REST_Request $request, bool $creating ): mixed {

		// ── 1. Permit creates — external tools creating translated posts are intentional.
		if ( $creating ) {
			return $product;
		}

		// ── 2. Pass through if the filter value is already an error (earlier filter fired).
		if ( $product instanceof WP_Error ) {
			return $product;
		}

		// ── 3. Must be a WC data object with a valid post ID.
		// WC_Data has no PHPStan stubs in this project — suppress the two WC-specific errors.
		// @phpstan-ignore-next-line
		if ( ! ( $product instanceof \WC_Data ) ) {
			return $product;
		}

		// @phpstan-ignore-next-line
		$post_id = $product->get_id();
		if ( $post_id <= 0 ) {
			return $product;
		}

		// ── 4. Language guard — only block translated (non-source) posts.
		$lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $product; // Not managed by Lingua Forge — pass through.
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $product; // Source product — writes are permitted.
		}

		// ── 5. Resolve source ID for the error message / machine-readable data.
		$source_id = MetaDelegate::get_source_id_for( $post_id );

		$source_hint = $source_id
			? sprintf( ' (source product ID: %d)', $source_id )
			: '';

		return new WP_Error(
			'linguaforge_rest_write_to_translated_product',
			sprintf(
				// translators: 1: translated product ID, 2: language code, 3: source product hint e.g. " (source product ID: 42)", 4: translated product ID for the translations REST endpoint.
				__( 'Cannot write to product %1$d: it is a translated version (language: %2$s)%3$s. Operational data (price, stock, SKU, dimensions) must be updated on the source product. Resolve the source product ID via: GET /wp-json/lingua-forge/v1/post/%4$d/translations', 'lingua-forge' ),
				$post_id,
				$lang,
				$source_hint,
				$post_id
			),
			[
				'status'          => 422,
				'source_id'       => $source_id ?: null,
				'translated_lang' => $lang,
			]
		);
	}
}
