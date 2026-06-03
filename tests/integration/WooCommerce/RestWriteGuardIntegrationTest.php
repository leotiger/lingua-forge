<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\RestWriteGuard.
 *
 * Verifies that the `woocommerce_rest_pre_insert_product_object` and
 * `woocommerce_rest_pre_insert_product_variation_object` filters correctly
 * reject REST writes targeting translated (non-source) products.
 *
 * Tests call RestWriteGuard::guard_product_write() directly (or via apply_filters)
 * so no HTTP stack is required. The filter is the exact integration point WC
 * uses — `save_object()` checks is_wp_error() immediately after the filter fires.
 *
 * Coverage:
 *   1.  PUT to translated product returns WP_Error (blocked).
 *   2.  PUT to source product passes through (permitted).
 *   3.  POST (create) to translated product passes through (creates permitted).
 *   4.  Product with no _lf_lang passes through (not managed by LF).
 *   5.  WP_Error contains correct error code.
 *   6.  WP_Error has HTTP 422 status.
 *   7.  WP_Error data includes source_id.
 *   8.  WP_Error data includes translated_lang.
 *   9.  Existing WP_Error from earlier filter is passed through unchanged.
 *  10.  PUT to translated product_variation returns WP_Error (blocked).
 *  11.  PUT to source product_variation passes through (permitted).
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\RestWriteGuard;
use LinguaForge\AI\Integrations\WooCommerce\VariationSync;

final class RestWriteGuardIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a minimal WP_REST_Request for testing.
	 * The guard uses it only to satisfy the type signature — no request data is read.
	 */
	private function make_request(): \WP_REST_Request {
		return new \WP_REST_Request( 'PUT', '/wc/v3/products/1' );
	}

	/**
	 * Get the WC product object for a given post ID, or skip if wc_get_product() unavailable.
	 */
	private function get_wc_product( int $post_id ): \WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'wc_get_product() not available.' );
		}
		$product = wc_get_product( $post_id );
		$this->assertInstanceOf( \WC_Product::class, $product );
		return $product;
	}

	/**
	 * Get a WC_Product_Variation object for a given variation post ID.
	 */
	private function get_wc_variation( int $var_id ): \WC_Product_Variation {
		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'wc_get_product() not available.' );
		}
		$variation = wc_get_product( $var_id );
		$this->assertInstanceOf( \WC_Product_Variation::class, $variation );
		return $variation;
	}

	// =========================================================================
	// 1. PUT to translated product is blocked
	// =========================================================================

	public function test_put_to_translated_product_is_blocked(): void {
		[ , $translated_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $translated_id );

		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );

		$this->assertInstanceOf( \WP_Error::class, $result, 'PUT to translated product must return WP_Error.' );
	}

	// =========================================================================
	// 2. PUT to source product passes through
	// =========================================================================

	public function test_put_to_source_product_passes_through(): void {
		[ $source_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $source_id );

		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );

		$this->assertSame( $product, $result, 'PUT to source product must pass through unchanged.' );
	}

	// =========================================================================
	// 3. POST (create) to translated product passes through
	// =========================================================================

	public function test_post_create_to_translated_product_passes_through(): void {
		[ , $translated_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $translated_id );

		// $creating = true → create, must not be blocked.
		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), true );

		$this->assertSame( $product, $result, 'POST (create) must not be blocked even for translated products.' );
	}

	// =========================================================================
	// 4. Product with no _lf_lang passes through
	// =========================================================================

	public function test_product_without_lang_passes_through(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'wc_get_product() not available.' );
		}

		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		// No _lf_lang set.
		$product = wc_get_product( $post_id );
		$this->assertInstanceOf( \WC_Product::class, $product );

		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );

		$this->assertSame( $product, $result, 'Product without _lf_lang must not be blocked.' );
	}

	// =========================================================================
	// 5. WP_Error has correct error code
	// =========================================================================

	public function test_error_has_correct_code(): void {
		[ , $translated_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $translated_id );

		/** @var \WP_Error $result */
		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );

		$this->assertSame(
			'linguaforge_rest_write_to_translated_product',
			$result->get_error_code(),
			'WP_Error code must be linguaforge_rest_write_to_translated_product.'
		);
	}

	// =========================================================================
	// 6. WP_Error has HTTP 422 status
	// =========================================================================

	public function test_error_has_422_status(): void {
		[ , $translated_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $translated_id );

		/** @var \WP_Error $result */
		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );
		$data   = $result->get_error_data();

		$this->assertSame( 422, $data['status'], 'WP_Error must carry HTTP status 422.' );
	}

	// =========================================================================
	// 7. WP_Error data includes source_id
	// =========================================================================

	public function test_error_data_includes_source_id(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $translated_id );

		/** @var \WP_Error $result */
		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );
		$data   = $result->get_error_data();

		$this->assertSame(
			$source_id,
			$data['source_id'],
			'WP_Error data must include the source product ID so callers can resolve the correct write target.'
		);
	}

	// =========================================================================
	// 8. WP_Error data includes translated_lang
	// =========================================================================

	public function test_error_data_includes_translated_lang(): void {
		[ , $translated_id ] = $this->make_product_pair();
		$product = $this->get_wc_product( $translated_id );

		/** @var \WP_Error $result */
		$result = RestWriteGuard::guard_product_write( $product, $this->make_request(), false );
		$data   = $result->get_error_data();

		$this->assertSame(
			self::TRANS_LANG,
			$data['translated_lang'],
			'WP_Error data must include the translated language code.'
		);
	}

	// =========================================================================
	// 9. Existing WP_Error from earlier filter passes through unchanged
	// =========================================================================

	public function test_existing_wp_error_passes_through(): void {
		$prior_error = new \WP_Error( 'some_prior_error', 'Earlier filter already failed.' );

		$result = RestWriteGuard::guard_product_write( $prior_error, $this->make_request(), false );

		$this->assertSame( $prior_error, $result, 'An existing WP_Error from an earlier filter must pass through unchanged.' );
	}

	// =========================================================================
	// 10. PUT to translated product_variation is blocked
	// =========================================================================

	public function test_put_to_translated_variation_is_blocked(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Create a source variation and sync translated counterpart.
		$source_var_id = (int) self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $source_id,
		] );
		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = get_posts( [
			'post_type'     => 'product_variation',
			'post_parent'   => $translated_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );
		$this->assertNotEmpty( $trans_var_ids );

		$variation = $this->get_wc_variation( (int) $trans_var_ids[0] );

		$result = RestWriteGuard::guard_product_write( $variation, $this->make_request(), false );

		$this->assertInstanceOf( \WP_Error::class, $result, 'PUT to translated product_variation must return WP_Error.' );

		unset( $source_var_id ); // Suppress unused-variable warning.
	}

	// =========================================================================
	// 11. PUT to source product_variation passes through
	// =========================================================================

	public function test_put_to_source_variation_passes_through(): void {
		[ $source_id ] = $this->make_product_pair();

		$source_var_id = (int) self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $source_id,
		] );
		// Source variation has _lf_lang = source_lang assigned by VariationSync when a translated pair is synced.
		// For this test, assign it manually to match how VariationSync sets it.
		update_post_meta( $source_var_id, '_lf_lang', self::SOURCE_LANG );

		$variation = $this->get_wc_variation( $source_var_id );

		$result = RestWriteGuard::guard_product_write( $variation, $this->make_request(), false );

		$this->assertSame( $variation, $result, 'PUT to source product_variation must pass through unchanged.' );
	}
}
