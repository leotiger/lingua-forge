<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\WcOrderLang.
 *
 * Covers the order-language capture, email-locale-switch, and admin
 * column-registration logic introduced in 2.3.0 (§6.2).
 *
 * Static state notes:
 *   WcOrderLang uses three private static properties ($pending_email_lang,
 *   $locale_switched, $current_email_lang). Each test resets them via
 *   ReflectionProperty to ensure clean isolation regardless of run order.
 *
 * LF_LANG constant:
 *   In the wp-env integration context LF_LANG is not defined at boot; the
 *   capture_order_lang() source-language fallback path is therefore the only
 *   constant-free path exercisable here. The LF_LANG-is-defined path is
 *   covered by E2E tests against the running site.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\WcOrderLang;
use LinguaForge\Router\Router;
use ReflectionClass;
use WC_Order;

final class WcOrderLangIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// setUp / tearDown
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		$this->reset_wc_order_lang_statics();
	}

	protected function tearDown(): void {
		$this->reset_wc_order_lang_statics();
		remove_all_filters( 'linguaforge_email_order_lang' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Reset the three private static state properties on WcOrderLang.
	 */
	private function reset_wc_order_lang_statics(): void {
		$ref = new ReflectionClass( WcOrderLang::class );

		foreach ( [ 'pending_email_lang', 'current_email_lang' ] as $name ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, '' );
		}

		$ls = $ref->getProperty( 'locale_switched' );
		$ls->setAccessible( true );
		$ls->setValue( null, false );
	}

	/**
	 * Create a real WC_Order, optionally write _lf_order_lang meta to it, and
	 * save it so the meta row is persisted before any assertion.
	 *
	 * @param  string $lang  If non-empty, write _lf_order_lang to the order.
	 * @return WC_Order
	 */
	private function make_order( string $lang = '' ): WC_Order {
		$order = wc_create_order();
		if ( $lang ) {
			$order->update_meta_data( '_lf_order_lang', $lang );
			$order->save();
		}
		return $order;
	}

	/**
	 * Read a private static string property from WcOrderLang.
	 */
	private function get_static( string $property ): mixed {
		$ref  = new ReflectionClass( WcOrderLang::class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		return $prop->getValue( null );
	}

	/**
	 * Write a private static property on WcOrderLang.
	 */
	private function set_static( string $property, mixed $value ): void {
		$ref  = new ReflectionClass( WcOrderLang::class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	// =========================================================================
	// capture_order_lang
	// =========================================================================

	/**
	 * Expected language: LF_LANG when it is defined and non-empty (the normal
	 * wp-env state), otherwise the Router source language.
	 * We cannot undefine a constant, so the test is written to accept both paths.
	 */
	private function expected_capture_lang(): string {
		return defined( 'LF_LANG' ) && '' !== LF_LANG
			? LF_LANG
			: Router::get_instance()->context->source_language();
	}

	/**
	 * capture_order_lang() must write a non-empty language code to the order.
	 * The value is LF_LANG when defined and non-empty, otherwise source_language().
	 */
	public function test_capture_order_lang_writes_current_language_to_order(): void {
		$order    = $this->make_order();
		$expected = $this->expected_capture_lang();

		WcOrderLang::capture_order_lang( $order, [] );

		$written = $order->get_meta( '_lf_order_lang' );
		$this->assertNotEmpty( $written, 'capture_order_lang() must always write a non-empty language code.' );
		$this->assertSame( $expected, $written, 'Written language must match LF_LANG or the source language fallback.' );
	}

	/**
	 * capture_order_lang must not call $order->save() — WooCommerce does that
	 * after the hook returns. We verify this indirectly: the meta must be staged
	 * in-memory immediately, and readable after an explicit save().
	 */
	public function test_capture_order_lang_meta_persists_after_save(): void {
		$order    = wc_create_order();
		$expected = $this->expected_capture_lang();

		WcOrderLang::capture_order_lang( $order, [] );

		// Staged meta must be visible in-memory before any save().
		$this->assertSame(
			$expected,
			$order->get_meta( '_lf_order_lang' ),
			'Meta must be staged in-memory after capture_order_lang() without a save.'
		);

		// After save, it must be readable from a fresh wc_get_order() call.
		$order->save();
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame(
			$expected,
			$reloaded->get_meta( '_lf_order_lang' ),
			'Saved order must carry _lf_order_lang after capture_order_lang() + save().'
		);
	}

	// =========================================================================
	// seed_pending_email_lang
	// =========================================================================

	/**
	 * seed_pending_email_lang() must stash the order's _lf_order_lang into the
	 * private static $pending_email_lang property.
	 */
	public function test_seed_pending_email_lang_stores_order_lang(): void {
		$order = $this->make_order( 'es' );

		WcOrderLang::seed_pending_email_lang( $order->get_id(), $order );

		$this->assertSame(
			'es',
			$this->get_static( 'pending_email_lang' ),
			'seed_pending_email_lang() must populate $pending_email_lang from order meta.'
		);
	}

	/**
	 * An order without _lf_order_lang must leave $pending_email_lang unchanged.
	 */
	public function test_seed_pending_email_lang_no_op_for_order_without_lang(): void {
		$order = $this->make_order(); // no lang

		$this->set_static( 'pending_email_lang', '' );
		WcOrderLang::seed_pending_email_lang( $order->get_id(), $order );

		$this->assertSame(
			'',
			$this->get_static( 'pending_email_lang' ),
			'seed_pending_email_lang() must not modify $pending_email_lang when order has no _lf_order_lang.'
		);
	}

	/**
	 * seed_pending_email_lang_by_order_id() fetches the WC_Order and stashes
	 * the lang — covering the woocommerce_order_refunded / _partially_refunded
	 * hook signature.
	 */
	public function test_seed_pending_email_lang_by_order_id_stores_lang(): void {
		$order = $this->make_order( 'ca' );

		WcOrderLang::seed_pending_email_lang_by_order_id( $order->get_id() );

		$this->assertSame(
			'ca',
			$this->get_static( 'pending_email_lang' ),
			'seed_pending_email_lang_by_order_id() must stash the order lang.'
		);
	}

	/**
	 * seed_from_resend() accepts a WC_Order and stashes the lang — the admin
	 * "Resend email" button path.
	 */
	public function test_seed_from_resend_stores_lang(): void {
		$order = $this->make_order( 'es' );

		WcOrderLang::seed_from_resend( $order );

		$this->assertSame(
			'es',
			$this->get_static( 'pending_email_lang' ),
			'seed_from_resend() must populate $pending_email_lang from order meta.'
		);
	}

	// =========================================================================
	// maybe_switch_email_locale
	// =========================================================================

	/**
	 * When $pending_email_lang is set, maybe_switch_email_locale() must:
	 *   • call switch_to_locale() (locale_switched → true),
	 *   • set $current_email_lang to the stashed language,
	 *   • clear $pending_email_lang,
	 *   • return false (suppressing WC's own locale switch).
	 */
	public function test_maybe_switch_email_locale_switches_when_pending_lang_set(): void {
		$this->set_static( 'pending_email_lang', 'es' );

		$result = WcOrderLang::maybe_switch_email_locale( true );

		$this->assertFalse( $result, 'maybe_switch_email_locale() must return false when it handles the switch.' );
		$this->assertSame( 'es', $this->get_static( 'current_email_lang' ), '$current_email_lang must be set.' );
		$this->assertSame( '', $this->get_static( 'pending_email_lang' ), '$pending_email_lang must be cleared.' );
		$this->assertTrue( $this->get_static( 'locale_switched' ), '$locale_switched must be true.' );

		// Restore locale so subsequent tests run in the correct locale.
		restore_current_locale();
		$this->set_static( 'locale_switched', false );
		$this->set_static( 'current_email_lang', '' );
	}

	/**
	 * When no pending lang is stashed, maybe_switch_email_locale() must return
	 * the $setup argument unchanged (pass-through).
	 */
	public function test_maybe_switch_email_locale_passthrough_when_no_pending_lang(): void {
		$this->set_static( 'pending_email_lang', '' );

		$result = WcOrderLang::maybe_switch_email_locale( true );

		$this->assertTrue( $result, 'maybe_switch_email_locale() must pass through $setup when no pending lang.' );
		$this->assertFalse( $this->get_static( 'locale_switched' ), '$locale_switched must stay false.' );
		$this->assertSame( '', $this->get_static( 'current_email_lang' ), '$current_email_lang must stay empty.' );
	}

	// =========================================================================
	// maybe_restore_email_locale
	// =========================================================================

	/**
	 * maybe_restore_email_locale() must call restore_current_locale() when
	 * $locale_switched is true and reset all state.
	 */
	public function test_maybe_restore_email_locale_resets_state(): void {
		// Simulate state left by a prior maybe_switch_email_locale() call.
		$this->set_static( 'locale_switched', true );
		$this->set_static( 'current_email_lang', 'es' );
		// switch_to_locale so the restore has something to undo.
		$locale = Router::get_instance()->locale_from_lang( 'es' );
		switch_to_locale( $locale );

		WcOrderLang::maybe_restore_email_locale();

		$this->assertFalse( $this->get_static( 'locale_switched' ), '$locale_switched must be cleared.' );
		$this->assertSame( '', $this->get_static( 'current_email_lang' ), '$current_email_lang must be cleared.' );
	}

	/**
	 * maybe_restore_email_locale() must be a no-op when $locale_switched is false.
	 */
	public function test_maybe_restore_email_locale_noop_when_not_switched(): void {
		$this->set_static( 'locale_switched', false );
		$this->set_static( 'current_email_lang', '' );

		// Should not throw or call restore_current_locale().
		WcOrderLang::maybe_restore_email_locale();

		$this->assertFalse( $this->get_static( 'locale_switched' ) );
	}

	// =========================================================================
	// get_current_email_lang (via linguaforge_email_order_lang filter)
	// =========================================================================

	/**
	 * When $current_email_lang is set, the linguaforge_email_order_lang filter
	 * must return it, overriding the caller's default.
	 */
	public function test_get_current_email_lang_returns_active_lang(): void {
		WcOrderLang::init(); // ensure filter is registered
		$this->set_static( 'current_email_lang', 'es' );

		$result = apply_filters( 'linguaforge_email_order_lang', '' );

		$this->assertSame( 'es', $result );
	}

	/**
	 * When $current_email_lang is empty, the filter must pass through the
	 * caller's default value.
	 */
	public function test_get_current_email_lang_passthrough_when_no_active_lang(): void {
		WcOrderLang::init();
		$this->set_static( 'current_email_lang', '' );

		$result = apply_filters( 'linguaforge_email_order_lang', 'fallback' );

		$this->assertSame( 'fallback', $result );
	}

	// =========================================================================
	// add_lang_column
	// =========================================================================

	/**
	 * add_lang_column() must insert an 'lf_order_lang' column immediately
	 * before the 'wc_actions' column.
	 */
	public function test_add_lang_column_inserts_before_wc_actions(): void {
		$columns = [
			'order_number' => 'Order',
			'order_date'   => 'Date',
			'wc_actions'   => 'Actions',
		];

		$result = WcOrderLang::add_lang_column( $columns );
		$keys   = array_keys( $result );

		$this->assertArrayHasKey( 'lf_order_lang', $result );

		$lang_pos    = array_search( 'lf_order_lang', $keys, true );
		$actions_pos = array_search( 'wc_actions', $keys, true );

		$this->assertLessThan(
			$actions_pos,
			$lang_pos,
			'lf_order_lang column must come before wc_actions.'
		);
	}

	/**
	 * When 'wc_actions' is absent, add_lang_column() must append 'lf_order_lang'
	 * at the end rather than losing the column.
	 */
	public function test_add_lang_column_appends_when_wc_actions_absent(): void {
		$columns = [
			'order_number' => 'Order',
			'order_date'   => 'Date',
		];

		$result = WcOrderLang::add_lang_column( $columns );

		$this->assertArrayHasKey( 'lf_order_lang', $result );
		// Must also still contain the original columns.
		$this->assertArrayHasKey( 'order_number', $result );
		$this->assertArrayHasKey( 'order_date', $result );
	}

	/**
	 * All original columns must be preserved, and 'wc_actions' must still be
	 * the last column when present.
	 */
	public function test_add_lang_column_preserves_all_original_columns(): void {
		$columns = [
			'cb'           => '<input>',
			'order_number' => 'Order',
			'wc_actions'   => 'Actions',
		];

		$result = WcOrderLang::add_lang_column( $columns );

		foreach ( array_keys( $columns ) as $key ) {
			$this->assertArrayHasKey( $key, $result, "Original column '$key' must be preserved." );
		}
		$last = array_key_last( $result );
		$this->assertSame( 'wc_actions', $last, 'wc_actions must remain the last column.' );
	}
}
