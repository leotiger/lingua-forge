<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Raw $wpdb queries bypass MetaDelegate so assertions read the true DB row written by StockRouter, not the delegated source value. Each query runs inside a WP_UnitTestCase transaction rolled back on tearDown.
/**
 * End-to-end purchase flow tests for LF-managed WooCommerce products.
 *
 * Verifies that the full WooCommerce order → stock lifecycle works correctly
 * when translated products are purchased:
 *
 *   A. Simple product flow:
 *      1. Order placed for translated product → stock reduced on SOURCE.
 *      2. Translated product has no direct stock meta row (routed to source).
 *      3. Order refunded → stock restored on source.
 *
 *   B. Variation product flow:
 *      4. Order placed for translated variation → stock reduced on SOURCE variation.
 *
 * The test creates real WP posts (product/product_variation), seeds stock on the
 * source product, and exercises wc_maybe_reduce_stock_levels() / wc_maybe_increase_stock_levels()
 * directly (bypassing the WC status-change hook) to keep the test focused on the
 * stock routing layer rather than WC order-management UI.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;

final class PurchaseFlowIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Read _stock for a post directly from the DB row (bypasses MetaDelegate read delegation).
	 */
	private function db_stock( int $post_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$post_id
		) );
	}

	/**
	 * Set manage_stock + stock on a product directly in the DB.
	 */
	private function seed_stock( int $post_id, int $qty ): void {
		global $wpdb;
		foreach ( [ '_manage_stock' => 'yes', '_stock' => (string) $qty, '_stock_status' => 'instock' ] as $key => $val ) {
			$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => $key ] );
			$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $val ] );
		}
	}

	/**
	 * Create a minimal WC_Order containing one unit of $product_id and persist it.
	 */
	private function make_order_for( int $product_id ): WC_Order {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'wc_create_order() not available — WooCommerce not active.' );
		}

		$order = wc_create_order();

		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product_id );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$order->add_item( $item );

		$order->save();
		return $order;
	}

	// =========================================================================
	// A.1 — Order for translated product reduces stock on SOURCE
	// =========================================================================

	public function test_stock_reduced_on_source_when_translated_product_purchased(): void {
		if ( ! function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			$this->markTestSkipped( 'wc_maybe_reduce_stock_levels() not available.' );
		}

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->seed_stock( $source_id, 10 );

		$order = $this->make_order_for( $translated_id );

		wc_maybe_reduce_stock_levels( $order->get_id() );

		$this->assertSame( '9', $this->db_stock( $source_id ),
			'Source stock must decrease by 1 when a translated product is purchased.' );
	}

	// =========================================================================
	// A.2 — Translated product has no direct _stock row after purchase
	// =========================================================================

	public function test_translated_product_has_no_direct_stock_row_after_purchase(): void {
		if ( ! function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			$this->markTestSkipped( 'wc_maybe_reduce_stock_levels() not available.' );
		}

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->seed_stock( $source_id, 5 );

		$order = $this->make_order_for( $translated_id );
		wc_maybe_reduce_stock_levels( $order->get_id() );

		$this->assertNull( $this->db_stock( $translated_id ),
			'Translated product must never have a direct _stock row — stock lives on source.' );
	}

	// =========================================================================
	// A.3 — Refund restores stock on source
	// =========================================================================

	public function test_stock_restored_on_source_after_refund(): void {
		if ( ! function_exists( 'wc_maybe_reduce_stock_levels' ) || ! function_exists( 'wc_maybe_increase_stock_levels' ) ) {
			$this->markTestSkipped( 'WC stock level functions not available.' );
		}

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->seed_stock( $source_id, 10 );

		$order = $this->make_order_for( $translated_id );
		wc_maybe_reduce_stock_levels( $order->get_id() );

		$this->assertSame( '9', $this->db_stock( $source_id ), 'Pre-condition: stock must be 9 after purchase.' );

		// Simulate a full refund restoring stock.
		wc_maybe_increase_stock_levels( $order->get_id() );

		$this->assertSame( '10', $this->db_stock( $source_id ),
			'Source stock must be restored to 10 after refund.' );
	}

	// =========================================================================
	// B — Variation: stock routed from translated variation to source variation
	// =========================================================================

	public function test_variation_stock_reduced_on_source_variation(): void {
		if ( ! function_exists( 'wc_maybe_reduce_stock_levels' ) ) {
			$this->markTestSkipped( 'wc_maybe_reduce_stock_levels() not available.' );
		}

		// Create source variable product and a translation pair.
		$trid      = $this->trid();
		$src_parent  = $this->make_product( self::SOURCE_LANG, $trid );
		$trs_parent  = $this->make_product( self::TRANS_LANG,  $trid );

		// Source variation child.
		$src_var = self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_parent' => $src_parent,
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $src_var, self::SOURCE_LANG );
		$this->tg->set_trid( $src_var, $trid . '-var' );

		// Translated variation child.
		$trs_var = self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_parent' => $trs_parent,
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $trs_var, self::TRANS_LANG );
		$this->tg->set_trid( $trs_var, $trid . '-var' );

		$this->seed_stock( $src_var, 8 );

		// Place an order for the translated variation.
		// WC_Order_Item_Product requires product_id = parent 'product' post and
		// variation_id = the 'product_variation' post — set_product_id() rejects
		// product_variation post types as an invalid product ID.
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'wc_create_order() not available.' );
		}
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( $trs_parent );
		$item->set_variation_id( $trs_var );
		$item->set_quantity( 1 );
		$item->set_subtotal( 10 );
		$item->set_total( 10 );
		$order->add_item( $item );
		$order->save();

		wc_maybe_reduce_stock_levels( $order->get_id() );

		$this->assertSame( '7', $this->db_stock( $src_var ),
			'Source variation stock must decrease by 1 when translated variation is purchased.' );
		$this->assertNull( $this->db_stock( $trs_var ),
			'Translated variation must have no direct _stock row.' );
	}
}
