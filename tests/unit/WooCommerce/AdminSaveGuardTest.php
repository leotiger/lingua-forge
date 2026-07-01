<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\AdminSaveGuard.
 *
 * Tests the public static methods that can be exercised without a WP runtime:
 *
 *   whitelist_meta_write()        — meta-write filter active during intercepted save.
 *   pre_has_unique_sku()          — genuine short-circuit filter (wc_product_pre_has_unique_sku,
 *                                   WC 9.0+): a non-null bool returned here IS the final
 *                                   "is unique?" answer (true = unique).
 *   flag_source_sku_conflict()   — legacy observation-only filter (wc_product_has_unique_sku).
 *                                   WARNING: its polarity is the OPPOSITE of "is_unique" —
 *                                   the incoming/outgoing bool is $sku_found (true = a
 *                                   duplicate WAS found by WC's direct SQL check). This
 *                                   method never flips that value; it only records
 *                                   source-product conflicts for shutdown suppression.
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
	// pre_has_unique_sku() — an earlier filter already decided
	// =========================================================================

	/**
	 * When an earlier filter already returned a non-null short-circuit value,
	 * LF must not interfere — returns it unchanged.
	 */
	public function test_pre_has_unique_sku_passes_through_earlier_short_circuit(): void {
		$this->assertFalse(
			AdminSaveGuard::pre_has_unique_sku( false, 1, 'SKU-001' )
		);
		$this->assertTrue(
			AdminSaveGuard::pre_has_unique_sku( true, 1, 'SKU-001' )
		);
	}

	// =========================================================================
	// pre_has_unique_sku() — non-product post type / not LF-managed
	// =========================================================================

	/**
	 * Posts that are not 'product' or 'product_variation' must return null
	 * (no LF intervention — WC proceeds with its own check).
	 */
	public function test_pre_has_unique_sku_ignores_non_product_post_type(): void {
		$this->make_post( 5, 'page' );
		$this->set_meta( 5, '_lf_lang', 'es' );

		$result = AdminSaveGuard::pre_has_unique_sku( null, 5, 'SKU-001' );

		$this->assertNull( $result, 'Non-product post type must return null (proceed normally).' );
	}

	/**
	 * Products without _lf_lang meta are not managed by LF — returns null.
	 */
	public function test_pre_has_unique_sku_ignores_non_lf_product(): void {
		$this->make_post( 6, 'product' );
		// No _lf_lang meta → get_post_meta returns ''.

		$result = AdminSaveGuard::pre_has_unique_sku( null, 6, 'SKU-001' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// pre_has_unique_sku() — translated product/variation
	// =========================================================================

	/**
	 * A translated product (lang ≠ source) must be forced unique (true) because
	 * the only "duplicate" WC could find is the source product's own SKU row
	 * (or a stray physical row on the translated post).
	 */
	public function test_pre_has_unique_sku_returns_true_for_translated_product(): void {
		$this->make_post( 7, 'product' );
		$this->set_meta( 7, '_lf_lang', 'es' ); // source_language() = 'en' → es ≠ en → translated

		$result = AdminSaveGuard::pre_has_unique_sku( null, 7, 'SKU-001' );

		$this->assertTrue( $result, 'Translated product must be forced unique via the short-circuit filter.' );
	}

	/**
	 * product_variation post type with a translated lang must also be forced unique.
	 */
	public function test_pre_has_unique_sku_returns_true_for_translated_variation(): void {
		$this->make_post( 8, 'product_variation' );
		$this->set_meta( 8, '_lf_lang', 'ca' ); // source = 'en' → ca ≠ en

		$result = AdminSaveGuard::pre_has_unique_sku( null, 8, 'SKU-VAR-001' );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// pre_has_unique_sku() — source product/variation: let WC validate normally
	// =========================================================================

	/**
	 * Source product (_lf_lang = source_language) must return null so WC's own
	 * uniqueness check runs unmodified.
	 */
	public function test_pre_has_unique_sku_returns_null_for_source_product(): void {
		$this->make_post( 9, 'product' );
		$this->set_meta( 9, '_lf_lang', 'en' ); // source_language() = 'en' → source product

		$result = AdminSaveGuard::pre_has_unique_sku( null, 9, 'SKU-SRC' );

		$this->assertNull( $result, 'Source product must let WC validate normally (no short-circuit).' );
	}

	// =========================================================================
	// flag_source_sku_conflict() — no conflict found
	// =========================================================================

	/**
	 * When WC's direct SQL check found no conflict ($sku_found=false), LF must
	 * not interfere and must not record a pending suppression.
	 */
	public function test_flag_conflict_returns_false_when_no_conflict_found(): void {
		$this->assertFalse(
			AdminSaveGuard::flag_source_sku_conflict( false, 1, 'SKU-001' )
		);
		$this->assertNull(
			static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ),
			'No pending suppress should be set when no conflict was found.'
		);
	}

	// =========================================================================
	// flag_source_sku_conflict() — non-product post type / not LF-managed
	// =========================================================================

	/**
	 * Posts that are not 'product' or 'product_variation' must pass $sku_found
	 * through unchanged (no LF intervention).
	 */
	public function test_flag_conflict_ignores_non_product_post_type(): void {
		$this->make_post( 5, 'page' );
		$this->set_meta( 5, '_lf_lang', 'es' );

		$result = AdminSaveGuard::flag_source_sku_conflict( true, 5, 'SKU-001' );

		$this->assertTrue( $result, 'Non-product post type must return $sku_found unchanged.' );
		$this->assertNull( static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
	}

	/**
	 * Products without _lf_lang meta are not managed by LF — $sku_found passed through.
	 */
	public function test_flag_conflict_ignores_non_lf_product(): void {
		$this->make_post( 6, 'product' );
		// No _lf_lang meta → get_post_meta returns ''.

		$result = AdminSaveGuard::flag_source_sku_conflict( true, 6, 'SKU-001' );

		$this->assertTrue( $result );
		$this->assertNull( static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
	}

	// =========================================================================
	// flag_source_sku_conflict() — translated product (already resolved upstream)
	// =========================================================================

	/**
	 * Translated products are already resolved by pre_has_unique_sku(); this
	 * method is a defensive fallback only and must not record a suppression
	 * for them.
	 */
	public function test_flag_conflict_ignores_translated_product(): void {
		$this->make_post( 7, 'product' );
		$this->set_meta( 7, '_lf_lang', 'es' ); // source_language() = 'en' → es ≠ en → translated

		$result = AdminSaveGuard::flag_source_sku_conflict( true, 7, 'SKU-001' );

		$this->assertTrue( $result, 'Translated product must return $sku_found unchanged (defensive fallback).' );
		$this->assertNull( static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
	}

	// =========================================================================
	// flag_source_sku_conflict() — source product records pending suppress
	// =========================================================================

	/**
	 * Source product (_lf_lang = source_language) with a genuine conflict found
	 * must return $sku_found unchanged (letting WC's block stand) AND record the
	 * product + SKU for later suppression of the spurious notice at shutdown.
	 */
	public function test_flag_conflict_records_pending_for_source_product(): void {
		$this->make_post( 9, 'product' );
		$this->set_meta( 9, '_lf_lang', 'en' ); // source_language() = 'en' → source product

		$result = AdminSaveGuard::flag_source_sku_conflict( true, 9, 'SKU-SRC' );

		$this->assertTrue( $result, 'Source product must let WC validate normally (returns $sku_found unchanged).' );
		$this->assertSame( 9, static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_product' ) );
		$this->assertSame( 'SKU-SRC', static::read_static( AdminSaveGuard::class, 'pending_sku_suppress_sku' ) );
	}
}
