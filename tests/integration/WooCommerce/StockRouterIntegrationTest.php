<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw $wpdb queries are intentional throughout this file: they bypass MetaDelegate's get_post_metadata filter so each assertion reads the actual DB row written by StockRouter, not the delegated value. Caching would defeat the purpose; every query is inside a WP_UnitTestCase transaction rolled back on tearDown.
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\StockRouter.
 *
 * Exercises the full WordPress metadata write path:
 *   update_post_meta() / add_post_meta()
 *   → update_post_metadata / add_post_metadata filter (priority 1)
 *   → StockRouter::route_stock_write() / route_stock_add()
 *   → update_post_meta() / add_post_meta() on the source product.
 *
 * Raw $wpdb queries bypass MetaDelegate's read filter and verify that:
 *   • the source product received the new value in the DB, and
 *   • the translated product has NO direct meta row for the stock key.
 *
 * Coverage:
 *   1. update_post_meta on translated product routes to source (raw verify).
 *   2. Translated post has no direct _stock meta after routed write.
 *   3. add_post_meta on translated product routes to source.
 *   4. Source product writes are NOT redirected.
 *   5. Non-stock keys are NOT routed (translated post keeps its own value).
 *   6. Product without language assignment is not routed.
 *   7. Stock key spot-check via dataProvider (update path).
 *   8. Stock key spot-check via dataProvider (add path).
 *   9. Two translated products in different groups do not bleed stock writes.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

final class StockRouterIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// 1 & 2. update_post_meta routed to source; translated remains meta-free
	// =========================================================================

	public function test_update_post_meta_routes_stock_write_to_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		update_post_meta( $translated_id, '_stock', 42 );

		global $wpdb;
		$source_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$source_id
		) );

		$this->assertSame( '42', $source_value, 'Stock write must be routed to the source product.' );
	}

	public function test_translated_product_has_no_direct_stock_meta_after_routed_write(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		unset( $source_id ); // not needed in this assertion.

		update_post_meta( $translated_id, '_stock', 42 );

		global $wpdb;
		$direct_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$translated_id
		) );

		$this->assertNull( $direct_value, 'Translated product must have no direct _stock meta row after a routed write.' );
	}

	// =========================================================================
	// 3. add_post_meta routes to source
	// =========================================================================

	public function test_add_post_meta_routes_stock_write_to_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		add_post_meta( $translated_id, '_stock_status', 'outofstock' );

		global $wpdb;
		$source_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock_status'",
			$source_id
		) );

		$this->assertSame( 'outofstock', $source_value, 'add_post_meta stock write must be routed to source product.' );
	}

	public function test_translated_product_has_no_direct_stock_status_after_add(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		unset( $source_id );

		add_post_meta( $translated_id, '_stock_status', 'outofstock' );

		global $wpdb;
		$direct_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock_status'",
			$translated_id
		) );

		$this->assertNull( $direct_value, 'add_post_meta must not write directly to translated product.' );
	}

	// =========================================================================
	// 4. Source product writes are not redirected
	// =========================================================================

	public function test_source_product_stock_write_is_not_redirected(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		unset( $translated_id );

		update_post_meta( $source_id, '_stock', 15 );

		global $wpdb;
		$source_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$source_id
		) );

		$this->assertSame( '15', $source_value, 'Direct write to source product must not be redirected.' );
	}

	// =========================================================================
	// 5. Non-stock keys are not routed
	// =========================================================================

	public function test_non_stock_key_is_not_routed(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		unset( $source_id );

		update_post_meta( $translated_id, '_price', '19.99' );

		global $wpdb;
		$direct_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price'",
			$translated_id
		) );

		// _price is not a STOCK_WRITE_KEY — StockRouter must not intercept it.
		// The write goes directly to the translated post.
		$this->assertSame( '19.99', $direct_value, 'Non-stock key writes must not be routed.' );
	}

	// =========================================================================
	// 6. Product without language assignment is not routed
	// =========================================================================

	public function test_product_without_lang_meta_is_not_routed(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		// No _lf_lang set — StockRouter must leave it alone.

		update_post_meta( $post_id, '_stock', 99 );

		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$post_id
		) );

		$this->assertSame( '99', $value, 'Product without _lf_lang must have its stock write applied locally.' );
	}

	// =========================================================================
	// 7 & 8. Spot-check all stock keys (update + add)
	// =========================================================================

	/**
	 * @dataProvider stock_key_provider
	 */
	public function test_update_stock_key_routes_to_source( string $key, mixed $value ): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		update_post_meta( $translated_id, $key, $value );

		global $wpdb;
		$source_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$source_id,
			$key
		) );

		$direct_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$translated_id,
			$key
		) );

		$this->assertEquals( $value, $source_value, "Stock key '$key' must be routed to source on update." );
		$this->assertNull( $direct_value, "Translated product must have no direct row for stock key '$key' after update routing." );
	}

	/**
	 * @dataProvider stock_key_provider
	 */
	public function test_add_stock_key_routes_to_source( string $key, mixed $value ): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		add_post_meta( $translated_id, $key, $value );

		global $wpdb;
		$source_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$source_id,
			$key
		) );

		$direct_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$translated_id,
			$key
		) );

		$this->assertEquals( $value, $source_value, "Stock key '$key' must be routed to source on add." );
		$this->assertNull( $direct_value, "Translated product must have no direct row for stock key '$key' after add routing." );
	}

	public static function stock_key_provider(): array {
		return [
			'_stock'             => [ '_stock',             '10'       ],
			'_stock_qty'         => [ '_stock_qty',         '5'        ],
			'_stock_status'      => [ '_stock_status',      'instock'  ],
			'_manage_stock'      => [ '_manage_stock',      'yes'      ],
			'_backorders'        => [ '_backorders',        'no'       ],
			'_sold_individually' => [ '_sold_individually', 'yes'      ],
		];
	}

	// =========================================================================
	// 9. Two groups do not bleed stock writes
	// =========================================================================

	public function test_two_groups_do_not_bleed_stock_writes(): void {
		[ $source_a, $translated_a ] = $this->make_product_pair();
		[ $source_b, $translated_b ] = $this->make_product_pair();

		update_post_meta( $translated_a, '_stock', 3 );
		update_post_meta( $translated_b, '_stock', 7 );

		global $wpdb;

		$stock_a = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$source_a
		) );
		$stock_b = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'",
			$source_b
		) );

		$this->assertSame( '3', $stock_a, 'Source A must receive only its own group stock write.' );
		$this->assertSame( '7', $stock_b, 'Source B must receive only its own group stock write.' );
	}
}
