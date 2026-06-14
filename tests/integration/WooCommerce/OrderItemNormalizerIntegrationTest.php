<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\OrderItemNormalizer.
 *
 * Verifies the order-line-item product_id normalisation introduced in 2.3.0
 * (§6.4 / §6.6): when a customer buys a translated product, the line item's
 * product_id is rewritten to the source product so that wc_update_total_sales_counts()
 * and WC Analytics aggregate against the single source product row.
 *
 * Cases:
 *  1. Translated product → line item product_id rewritten to source.
 *  2. Source product → line item unchanged.
 *  3. Setting disabled (option 'no') → no rewrite even for translated product.
 *  4. Per-item filter override → caller can disable normalization for one item.
 *  5. Product without a TRID source → no change (fail-safe).
 *  6. is_enabled() reflects option value; defaults to true.
 *
 * WC_Order_Item_Product is constructed directly (no DB row needed); the object
 * is passed to normalize_product_id() exactly as WooCommerce would during
 * woocommerce_checkout_create_order_line_item.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\OrderItemNormalizer;
use WC_Order;
use WC_Order_Item_Product;

final class OrderItemNormalizerIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// setUp / tearDown
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		// Ensure the setting starts at the default ('yes' / enabled).
		delete_option( OrderItemNormalizer::OPT_NORMALIZE );
	}

	protected function tearDown(): void {
		delete_option( OrderItemNormalizer::OPT_NORMALIZE );
		remove_all_filters( 'linguaforge_wc_order_item_source_mapping' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a WC_Order_Item_Product with a given product_id.
	 * No DB row is needed — normalize_product_id() only calls set_product_id().
	 */
	private function make_item( int $product_id ): WC_Order_Item_Product {
		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product_id );
		return $item;
	}

	/**
	 * Call normalize_product_id() with realistic arguments (cart_item_key and
	 * values are unused by the method; a minimal WC_Order stub suffices).
	 */
	private function normalize( WC_Order_Item_Product $item ): void {
		$order = wc_create_order();
		OrderItemNormalizer::normalize_product_id( $item, 'key', [], $order );
	}

	// =========================================================================
	// 1. Translated product → rewritten to source
	// =========================================================================

	/**
	 * A line item whose product_id is the translated product must have
	 * product_id rewritten to the source product by normalize_product_id().
	 */
	public function test_normalize_product_id_rewrites_translated_to_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$item = $this->make_item( $translated_id );
		$this->normalize( $item );

		$this->assertSame(
			$source_id,
			$item->get_product_id(),
			'normalize_product_id() must rewrite the translated product_id to the source ID.'
		);
	}

	// =========================================================================
	// 2. Source product → unchanged
	// =========================================================================

	/**
	 * A line item whose product_id is already the source must be left unchanged.
	 */
	public function test_normalize_product_id_leaves_source_product_unchanged(): void {
		[ $source_id ] = $this->make_product_pair();

		$item = $this->make_item( $source_id );
		$this->normalize( $item );

		$this->assertSame(
			$source_id,
			$item->get_product_id(),
			'normalize_product_id() must not alter a line item that already carries the source product_id.'
		);
	}

	// =========================================================================
	// 3. Setting disabled → no rewrite
	// =========================================================================

	/**
	 * When the linguaforge_wc_normalize_order_product_ids option is 'no',
	 * normalize_product_id() must leave translated product IDs untouched.
	 */
	public function test_normalize_product_id_skips_rewrite_when_setting_disabled(): void {
		update_option( OrderItemNormalizer::OPT_NORMALIZE, 'no' );

		[ , $translated_id ] = $this->make_product_pair();

		$item = $this->make_item( $translated_id );
		$this->normalize( $item );

		$this->assertSame(
			$translated_id,
			$item->get_product_id(),
			'normalize_product_id() must not rewrite when the site setting is disabled.'
		);
	}

	// =========================================================================
	// 4. Per-item filter override
	// =========================================================================

	/**
	 * The linguaforge_wc_order_item_source_mapping filter must allow callers to
	 * disable normalization on a per-item basis even when the site setting is on.
	 */
	public function test_normalize_product_id_respects_per_item_filter_false(): void {
		[ , $translated_id ] = $this->make_product_pair();

		add_filter( 'linguaforge_wc_order_item_source_mapping', '__return_false' );

		$item = $this->make_item( $translated_id );
		$this->normalize( $item );

		$this->assertSame(
			$translated_id,
			$item->get_product_id(),
			'Per-item filter returning false must prevent normalization.'
		);
	}

	/**
	 * The filter can also force normalization on when the site setting is off.
	 */
	public function test_normalize_product_id_respects_per_item_filter_true(): void {
		update_option( OrderItemNormalizer::OPT_NORMALIZE, 'no' );

		[ $source_id, $translated_id ] = $this->make_product_pair();

		add_filter( 'linguaforge_wc_order_item_source_mapping', '__return_true' );

		$item = $this->make_item( $translated_id );
		$this->normalize( $item );

		$this->assertSame(
			$source_id,
			$item->get_product_id(),
			'Per-item filter returning true must force normalization even when the site setting is off.'
		);
	}

	/**
	 * The filter receives the expected arguments so callers can make informed
	 * per-item decisions.
	 */
	public function test_normalize_product_id_filter_receives_correct_arguments(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$received = null;
		add_filter(
			'linguaforge_wc_order_item_source_mapping',
			static function ( bool $normalize, int $product_id, int $sid, WC_Order_Item_Product $item ) use ( &$received ): bool {
				$received = [
					'normalize'  => $normalize,
					'product_id' => $product_id,
					'sid'        => $sid,
					'item'       => $item,
				];
				return $normalize;
			},
			10,
			4
		);

		$item = $this->make_item( $translated_id );
		$this->normalize( $item );

		$this->assertIsArray( $received, 'Filter callback must have been called.' );
		$this->assertTrue(  $received['normalize'],              'Default normalize arg must be true.' );
		$this->assertSame(  $translated_id, $received['product_id'], 'product_id arg must be the translated ID.' );
		$this->assertSame(  $source_id,     $received['sid'],         'source_id arg must be the resolved source.' );
		$this->assertSame(  $item,          $received['item'],        'item arg must be the WC_Order_Item_Product.' );
	}

	// =========================================================================
	// 5. Product without TRID source — fail-safe
	// =========================================================================

	/**
	 * A translated-looking product (has _lf_lang but no resolvable source) must
	 * not have its product_id modified.
	 */
	public function test_normalize_product_id_noop_for_product_without_trid_source(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, '_lf_lang', 'es' );
		// No _lf_trid → MetaDelegate::get_source_id_for() will return null/0.

		$item = $this->make_item( $post_id );
		$this->normalize( $item );

		$this->assertSame(
			$post_id,
			$item->get_product_id(),
			'Line item must not be altered when no source product can be resolved.'
		);
	}

	/**
	 * A product_id of 0 (absent item) must be a no-op.
	 */
	public function test_normalize_product_id_noop_for_zero_product_id(): void {
		$item = $this->make_item( 0 );
		$this->normalize( $item );

		$this->assertSame( 0, $item->get_product_id() );
	}

	// =========================================================================
	// 6. is_enabled()
	// =========================================================================

	public function test_is_enabled_defaults_to_true_when_option_absent(): void {
		delete_option( OrderItemNormalizer::OPT_NORMALIZE );

		$this->assertTrue( OrderItemNormalizer::is_enabled() );
	}

	public function test_is_enabled_returns_false_when_option_is_no(): void {
		update_option( OrderItemNormalizer::OPT_NORMALIZE, 'no' );

		$this->assertFalse( OrderItemNormalizer::is_enabled() );
	}

	public function test_is_enabled_returns_true_when_option_is_yes(): void {
		update_option( OrderItemNormalizer::OPT_NORMALIZE, 'yes' );

		$this->assertTrue( OrderItemNormalizer::is_enabled() );
	}
}
