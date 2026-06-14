<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\AdminSaveGuard.
 *
 * Tests the two public static methods that can be exercised without a WP runtime:
 *
 *   whitelist_meta_write()          — meta-write filter active during intercepted save.
 *   allow_source_sku_on_translated() — SKU uniqueness filter for LF-managed products.
 *
 * maybe_intercept_translated_save() is not tested here — it calls
 * VariationSync::disable_for_request() and add_filter(), which require a live WP
 * runtime; that path is covered in AdminSaveGuardIntegrationTest.
 *
 * Strategy:
 *   • Uses WcUnitTestCase::set_static() / read_static() to manipulate and inspect
 *     the private static properties ($intercepting, $pending_sku_suppress_product,
 *     $pending_sku_suppress_sku) without changing production visibility.
 *   • setUp() resets all three statics before each test so state never leaks.
 *   • LfWcMocks provides get_post() and get_post_meta() via WcPolyfills.
 *   • inject_router('en') is called by WcUnitTestCase::setUp() — source_language()
 *     returns 'en' by default.
 *
 * Run via: composer test:unit  (no Docker / wp-env needed).
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\AdminSaveGuard;

require_once __DIR__ . '/WcUnitTestCase.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/AdminSaveGuard.php';

final class AdminSaveGuardTest extends WcUnitTestCase {

	// =========================================================================
	// Lifecycle — reset AdminSaveGuard static state between tests
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		// parent::setUp() calls inject_router('en') so source_language() = 'en'.
		$this->reset_guard_state();
	}

	protected function tearDown(): void {
		$this->reset_guard_state();
		parent::tearDown();
	}

	private function reset_guard_state(): void {
		static::set_static( AdminSaveGuard::class, 'intercepting',                false );
		static::set_static( AdminSaveGuard::class, 'pending_sku_suppress_product', null );
		static::set_static( AdminSaveGuard::class, 'pending_sku_suppress_sku',     '' );
	}

	// =========================================================================
	// whitelist_meta_write() — not intercepting (pass-through)
	// =========================================================================

	/**
	 * When $intercepting is false the filter must be a no-op: any $check value
	 * is returned unchanged regardless of the meta key.
	 */
	public function test_whitelist_passthrough_when_not_intercepting(): void {
		// $intercepting = false (default, reset by setUp).
		$this->assertNull(
			AdminSaveGuard::whitelist_meta_write( null, 1, '_sku' ),
			'$check = null must be returned unchanged when not intercepting.'
		);
		$this->assertFalse(
			AdminSaveGuard::whitelist_meta_write( false, 1, '_price' ),
			'$check = false must be returned unchanged when not intercepting.'
		);
	}

	// =========================================================================
	// whitelist_meta_write() — intercepting: whitelisted keys allowed (null returned)
	// =========================================================================

	/**
	 * _lf_* keys must be allowed through (returns null = proceed normally).
	 */
	public function test_whitelist_allows_lf_prefixed_keys(): void {
		static::set_static( AdminSaveGuard::class, 'intercepting', true );

		$this->assertNull( AdminSaveGuard::whitelist_meta_write( null, 1, '_lf_lang' ) );
		$this->assertNull( AdminSaveGuard::whitelist_meta_write( null, 1, '_lf_trid' ) );
		$this->assertNull( AdminSaveGuard::whitelist_meta_write( null, 1, '_lf_any_key' ) );
	}

	/**
	 * _wp_page_template must be allowed (FSE template slug correction happens here).
	 */
	public function test_whitelist_allows_page_template_key(): void {
		static::set_static( AdminSaveGuard::class, 'intercepting', true );

		$this->assertNull( AdminSaveGuard::whitelist_meta_write( null, 1, '_wp_page_template' ) );
	}

	/**
	 * _edit_lock and _edit_last (WP concurrent-edit tracking) must be allowed.
	 */
	public function test_whitelist_allows_wp_housekeeping_keys(): void {
		static::set_static( AdminSaveGuard::class, 'intercepting', true );

		$this->assertNull( AdminSaveGuard::whitelist_meta_write( null, 1, '_edit_lock' ) );
		$this->assertNull( AdminSaveGuard::whitelist_meta_write( null, 1, '_edit_last' ) );
	}

	// =========================================================================
	// whitelist_meta_write() — intercepting: non-whitelisted keys blocked
	// =========================================================================

	/**
	 * Non-whitelisted keys must receive a fake-success return value (true) so
	 * WooCommerce thinks the write succeeded and does not retry.
	 */
	public function test_whitelist_blocks_sku_with_fake_success(): void {
		static::set_static( AdminSaveGuard::class, 'intercepting', true );

		$this->assertTrue( AdminSaveGuard::whitelist_meta_write( null, 1, '_sku' ) );
	}

	public function test_whitelist_blocks_price_and_stock_keys(): void {
		static::set_static( AdminSaveGuard::class, 'intercepting', true );

		$this->assertTrue( AdminSaveGuard::whitelist_meta_write( null, 1, '_price' ) );
		$this->assertTrue( AdminSaveGuard::whitelist_meta_write( null, 1, '_regular_price' ) );
		$this->assertTrue( AdminSaveGuard::whitelist_meta_write( null, 1, '_stock' ) );
	}

	// =========================================================================
	// allow_source_sku_on_translated() — $is_unique already true
	// =========================================================================

	/**
	 * When WC already considers the SKU unique ($is_unique=true), LF must not
	 * interfere — returns true immediately.
	 */
	public function test_allow_sku_returns_true_when_already_unique(): void {
		$this->assertTrue(
			AdminSaveGuard::allow_source_sku_on_translated( true, 1, 'SKU-001' )
		);
		$this->assertNull(
			static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ),
			'No pending suppress should be set when $is_unique is already true.'
		);
	}

	// =========================================================================
	// allow_source_sku_on_translated() — non-product post type
	// =========================================================================

	/**
	 * Posts that are not 'product' or 'product_variation' must pass $is_unique
	 * through unchanged (no LF intervention).
	 */
	public function test_allow_sku_ignores_non_product_post_type(): void {
		$this->make_post( 5, 'page' );
		$this->set_meta( 5, '_lf_lang', 'es' );

		$result = AdminSaveGuard::allow_source_sku_on_translated( false, 5, 'SKU-001' );

		$this->assertFalse( $result, 'Non-product post type must return $is_unique unchanged.' );
		$this->assertNull( static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
	}

	// =========================================================================
	// allow_source_sku_on_translated() — not LF-managed
	// =========================================================================

	/**
	 * Products without _lf_lang meta are not managed by LF — $is_unique passed through.
	 */
	public function test_allow_sku_ignores_non_lf_product(): void {
		$this->make_post( 6, 'product' );
		// No _lf_lang meta → get_post_meta returns ''.

		$result = AdminSaveGuard::allow_source_sku_on_translated( false, 6, 'SKU-001' );

		$this->assertFalse( $result );
		$this->assertNull( static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
	}

	// =========================================================================
	// allow_source_sku_on_translated() — translated product
	// =========================================================================

	/**
	 * A translated product (lang ≠ source) must be allowed unconditionally (true)
	 * because the "duplicate" is always the source product's own SKU row.
	 */
	public function test_allow_sku_returns_true_for_translated_product(): void {
		$this->make_post( 7, 'product' );
		$this->set_meta( 7, '_lf_lang', 'es' ); // source_language() = 'en' → es ≠ en → translated

		$result = AdminSaveGuard::allow_source_sku_on_translated( false, 7, 'SKU-001' );

		$this->assertTrue( $result, 'Translated product must always pass the SKU uniqueness check.' );
	}

	/**
	 * product_variation post type with a translated lang must also be allowed.
	 */
	public function test_allow_sku_returns_true_for_translated_variation(): void {
		$this->make_post( 8, 'product_variation' );
		$this->set_meta( 8, '_lf_lang', 'ca' ); // source = 'en' → ca ≠ en

		$result = AdminSaveGuard::allow_source_sku_on_translated( false, 8, 'SKU-VAR-001' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// allow_source_sku_on_translated() — source product records pending suppress
	// =========================================================================

	/**
	 * Source product (_lf_lang = source_language) must return $is_unique unchanged
	 * AND record the product + SKU for later suppression at shutdown.
	 */
	public function test_allow_sku_records_pending_for_source_product(): void {
		$this->make_post( 9, 'product' );
		$this->set_meta( 9, '_lf_lang', 'en' ); // source_language() = 'en' → source product

		$result = AdminSaveGuard::allow_source_sku_on_translated( false, 9, 'SKU-SRC' );

		$this->assertFalse( $result, 'Source product must let WC validate normally (returns false).' );
		$this->assertSame( 9, static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
		$this->assertSame( 'SKU-SRC', static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_sku' ) );
	}
}
