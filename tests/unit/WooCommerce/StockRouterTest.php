<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\StockRouter.
 *
 * StockRouter hooks update_post_metadata and add_post_metadata at priority 1.
 * Tests call route_stock_write() and route_stock_add() directly.
 *
 * The filter contract:
 *   • Return null  → "not filtered; let WordPress write the meta normally."
 *   • Return true  → "write intercepted; treat as succeeded on the translated post."
 *
 * Coverage:
 *   1. Key guard — non-stock keys pass through (null).
 *   2. Reentrancy guard — already-routing bail.
 *   3. Post type guard — unknown post, non-product post type.
 *   4. Language guard — no _lf_lang, source-language product.
 *   5. Source ID resolution — no translation map.
 *   6. Routing — update and add both route to source, return true.
 *   7. No write to translated post after routing.
 *   8. All five STOCK_WRITE_KEYS are handled (_stock_qty removed — WC 3.x+).
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;
use LinguaForge\AI\Integrations\WooCommerce\StockRouter;

require_once __DIR__ . '/WcUnitTestCase.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/MetaDelegate.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/StockRouter.php';

final class StockRouterTest extends WcUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		self::reset_static_array( StockRouter::class, 'routing' );
		self::reset_static_array( StockRouter::class, 'pending_cache_clear' );
		// MetaDelegate::get_source_id_for() is shared; reset its cache too.
		self::reset_static_array( MetaDelegate::class, 'source_cache' );
		self::reset_static_array( MetaDelegate::class, 'delegating' );
	}

	// =========================================================================
	// 1. Key guard
	// =========================================================================

	public function test_non_stock_key_update_passes_through(): void {
		$result = StockRouter::route_stock_write( null, 42, '_price', '9.99', '' );
		$this->assertNull( $result, 'Non-stock key must not be intercepted.' );
	}

	public function test_non_stock_key_add_passes_through(): void {
		$result = StockRouter::route_stock_add( null, 42, '_price', '9.99' );
		$this->assertNull( $result );
	}

	public function test_arbitrary_custom_key_passes_through(): void {
		$result = StockRouter::route_stock_write( null, 42, '_my_plugin_meta', 'val', '' );
		$this->assertNull( $result );
	}

	// =========================================================================
	// 2. Reentrancy guard
	// =========================================================================

	public function test_reentrancy_guard_prevents_second_call_for_same_key(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// Pre-set the guard as if we're mid-routing for this key.
		self::set_static( StockRouter::class, 'routing', [ '42:_stock' => true ] );

		$result = StockRouter::route_stock_write( null, 42, '_stock', 5, '' );
		$this->assertNull( $result, 'Reentrancy guard must prevent recursive routing.' );
	}

	// =========================================================================
	// 3. Post type guard
	// =========================================================================

	public function test_unknown_post_passes_through(): void {
		$result = StockRouter::route_stock_write( null, 99999, '_stock', 5, '' );
		$this->assertNull( $result );
	}

	public function test_non_product_post_type_passes_through(): void {
		$this->make_post( 42, 'page' );
		$result = StockRouter::route_stock_write( null, 42, '_stock', 5, '' );
		$this->assertNull( $result );
	}

	// =========================================================================
	// 4. Language guard
	// =========================================================================

	public function test_post_without_lang_passes_through(): void {
		$this->make_post( 42 );
		// No _lf_lang meta.
		$result = StockRouter::route_stock_write( null, 42, '_stock', 5, '' );
		$this->assertNull( $result );
	}

	public function test_source_language_product_passes_through(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'en' ); // source lang
		$result = StockRouter::route_stock_write( null, 42, '_stock', 5, '' );
		$this->assertNull( $result, 'Source-language product must write its own meta.' );
	}

	// =========================================================================
	// 5. Source ID resolution
	// =========================================================================

	public function test_no_translation_map_passes_through(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// No translation map → source_id = 0 → fail-safe, let write proceed.
		$result = StockRouter::route_stock_write( null, 42, '_stock', 5, '' );
		$this->assertNull( $result );
	}

	// =========================================================================
	// 6. Routing — update
	// =========================================================================

	public function test_update_returns_true_to_short_circuit_wp_db_write(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$result = StockRouter::route_stock_write( null, 42, '_stock', 7, '' );

		$this->assertTrue( $result, 'Must return true to short-circuit WordPress DB write.' );
	}

	public function test_update_writes_to_source_product(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		\LfWcMocks::$write_log = []; // clear setup noise

		StockRouter::route_stock_write( null, 42, '_stock', 7, '' );

		$this->assertNotEmpty( \LfWcMocks::$write_log );
		[ $action, $target_id, $key, $value ] = \LfWcMocks::$write_log[0];
		$this->assertSame( 'update', $action );
		$this->assertSame( 100, $target_id, 'Write must target the source product (100).' );
		$this->assertSame( '_stock', $key );
		$this->assertSame( 7, $value );
	}

	// =========================================================================
	// 6. Routing — add
	// =========================================================================

	public function test_add_returns_true_to_short_circuit_wp_db_write(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$result = StockRouter::route_stock_add( null, 42, '_stock', 10 );

		$this->assertTrue( $result );
	}

	public function test_add_writes_to_source_product(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		\LfWcMocks::$write_log = [];

		StockRouter::route_stock_add( null, 42, '_manage_stock', 'yes' );

		$this->assertNotEmpty( \LfWcMocks::$write_log );
		[ $action, $target_id, $key ] = \LfWcMocks::$write_log[0];
		$this->assertSame( 'add', $action );
		$this->assertSame( 100, $target_id );
		$this->assertSame( '_manage_stock', $key );
	}

	// =========================================================================
	// 7. No write to translated post
	// =========================================================================

	public function test_translated_post_is_never_written_after_routing(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		\LfWcMocks::$write_log = [];

		StockRouter::route_stock_write( null, 42, '_stock_status', 'instock', '' );

		foreach ( \LfWcMocks::$write_log as [ , $target_id ] ) {
			$this->assertNotSame( 42, $target_id, 'Must never write to the translated post.' );
		}
	}

	// =========================================================================
	// 9. rewrite_stock_sql — direct SQL interception
	// =========================================================================

	public function test_rewrite_sql_passes_through_for_source_language_product(): void {
		$this->make_post( 100 );
		$this->set_meta( 100, '_lf_lang', 'en' ); // source lang

		$sql    = "UPDATE wp_postmeta SET meta_value = '5' WHERE post_id = 100 AND meta_key='_stock'";
		$result = StockRouter::rewrite_stock_sql( $sql, 100, 5.0 );

		$this->assertSame( $sql, $result, 'Source-language product SQL must not be rewritten.' );
	}

	public function test_rewrite_sql_passes_through_when_no_translation_map(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// No translation map → source_id = 0 → fail safe.

		$sql    = "UPDATE wp_postmeta SET meta_value = '3' WHERE post_id = 42 AND meta_key='_stock'";
		$result = StockRouter::rewrite_stock_sql( $sql, 42, 3.0 );

		$this->assertSame( $sql, $result );
	}

	public function test_rewrite_sql_rewrites_post_id_to_source(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$sql    = "UPDATE wp_postmeta SET meta_value = '4' WHERE post_id = 42 AND meta_key='_stock'";
		$result = StockRouter::rewrite_stock_sql( $sql, 42, 4.0 );

		$this->assertStringContainsString( 'post_id = 100', $result );
		$this->assertStringNotContainsString( 'post_id = 42', $result );
	}

	public function test_rewrite_sql_populates_pending_cache_clear(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$sql = "UPDATE wp_postmeta SET meta_value = '2' WHERE post_id = 42 AND meta_key='_stock'";
		StockRouter::rewrite_stock_sql( $sql, 42, 2.0 );

		$pending = self::read_static( StockRouter::class, 'pending_cache_clear' );
		$this->assertArrayHasKey( 42, $pending );
		$this->assertSame( 100, $pending[42]['source_id'] );
		$this->assertSame( 2.0, $pending[42]['new_stock'] );
	}

	public function test_rewrite_sql_clears_source_cache_immediately(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		\LfWcMocks::$cache_deletes = [];

		$sql = "UPDATE wp_postmeta SET meta_value = '1' WHERE post_id = 42 AND meta_key='_stock'";
		StockRouter::rewrite_stock_sql( $sql, 42, 1.0 );

		$groups   = array_column( \LfWcMocks::$cache_deletes, 'group' );
		$post_ids = array_column( \LfWcMocks::$cache_deletes, 'key' );
		$this->assertContains( 'post_meta', $groups );
		$this->assertContains( 100, $post_ids );
	}

	// =========================================================================
	// 10. clear_source_meta_cache — post-write cleanup and lookup sync
	// =========================================================================

	public function test_clear_cache_is_no_op_when_no_pending_entry(): void {
		// No pending entry registered.
		\LfWcMocks::$cache_deletes = [];
		\LfWcMocks::$wpdb_updates  = [];

		StockRouter::clear_source_meta_cache( 99 );

		$this->assertEmpty( \LfWcMocks::$wpdb_updates, 'No DB update when no pending entry.' );
	}

	public function test_clear_cache_flushes_source_postmeta_cache(): void {
		self::set_static( StockRouter::class, 'pending_cache_clear', [
			42 => [ 'source_id' => 100, 'new_stock' => 5.0 ],
		] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_manage_stock', 'yes' );
		$this->set_meta( 100, '_stock_status', 'instock' );
		\LfWcMocks::$cache_deletes = [];

		StockRouter::clear_source_meta_cache( 42 );

		$post_meta_deletes = array_filter(
			\LfWcMocks::$cache_deletes,
			fn( $e ) => 'post_meta' === $e['group'] && 100 === $e['key']
		);
		$this->assertNotEmpty( $post_meta_deletes, 'Source postmeta cache must be flushed.' );
	}

	public function test_clear_cache_updates_lookup_table_for_source(): void {
		self::set_static( StockRouter::class, 'pending_cache_clear', [
			42 => [ 'source_id' => 100, 'new_stock' => 7.0 ],
		] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_manage_stock', 'yes' );
		$this->set_meta( 100, '_stock_status', 'instock' );
		\LfWcMocks::$wpdb_updates = [];

		StockRouter::clear_source_meta_cache( 42 );

		$this->assertCount( 1, \LfWcMocks::$wpdb_updates );
		$update = \LfWcMocks::$wpdb_updates[0];
		$this->assertSame( 'wp_wc_product_meta_lookup', $update['table'] );
		$this->assertSame( [ 'product_id' => 100 ], $update['where'] );
		$this->assertSame( 7.0, $update['data']['stock_quantity'] );
		$this->assertSame( 'instock', $update['data']['stock_status'] );
	}

	public function test_clear_cache_sets_null_stock_when_not_managing(): void {
		self::set_static( StockRouter::class, 'pending_cache_clear', [
			42 => [ 'source_id' => 100, 'new_stock' => 7.0 ],
		] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_manage_stock', 'no' );
		$this->set_meta( 100, '_stock_status', 'instock' );
		\LfWcMocks::$wpdb_updates = [];

		StockRouter::clear_source_meta_cache( 42 );

		$this->assertNull( \LfWcMocks::$wpdb_updates[0]['data']['stock_quantity'] );
	}

	public function test_clear_cache_consumes_pending_entry(): void {
		self::set_static( StockRouter::class, 'pending_cache_clear', [
			42 => [ 'source_id' => 100, 'new_stock' => 3.0 ],
		] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_manage_stock', 'yes' );
		$this->set_meta( 100, '_stock_status', 'instock' );

		StockRouter::clear_source_meta_cache( 42 );

		$pending = self::read_static( StockRouter::class, 'pending_cache_clear' );
		$this->assertArrayNotHasKey( 42, $pending, 'Entry must be consumed to prevent double-update.' );
	}

	// =========================================================================
	// 8. All STOCK_WRITE_KEYS are handled
	// =========================================================================

	/**
	 * @dataProvider stock_key_provider
	 */
	public function test_stock_key_is_routed( string $key ): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$result = StockRouter::route_stock_write( null, 42, $key, 1, '' );

		$this->assertTrue( $result, "Stock key '$key' must be intercepted and routed." );
	}

	public static function stock_key_provider(): array {
		return [
			'_stock'             => [ '_stock' ],
			// '_stock_qty' was removed from WooCommerce in 3.x (replaced by '_stock').
			// It is no longer in STOCK_WRITE_KEYS and intentionally omitted here.
			'_stock_status'      => [ '_stock_status' ],
			'_manage_stock'      => [ '_manage_stock' ],
			'_backorders'        => [ '_backorders' ],
			'_sold_individually' => [ '_sold_individually' ],
		];
	}
}
