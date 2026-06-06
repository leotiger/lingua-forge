<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\PageTagRepair.
 *
 * PageTagRepair::maybe_repair() is intended to run on load-edit.php for the
 * pages list screen when "All languages" is selected.  It reads the WooCommerce
 * built-in page option keys, finds any pages that are missing `_lf_lang`, and
 * assigns them the LF source language.
 *
 * Tests are exercised by calling maybe_repair() / tag_untagged_wc_pages()
 * directly via the public entry point after configuring LfWcMocks and a stub
 * Router.  No WordPress or WooCommerce runtime is needed.
 *
 * Coverage:
 *   1. Happy path — untagged WC page receives source language.
 *   2. Already tagged — existing _lf_lang value is not overwritten.
 *   3. Page ID = 0 — unconfigured option is silently skipped.
 *   4. All five WC options handled — each untagged page is repaired.
 *   5. Capability guard — nothing written when current_user_can() returns false.
 *   6. Screen type guard — nothing written when post_type ≠ page.
 *   7. GET language filter active — nothing written when a specific lang is set.
 *   8. User-meta language filter active — nothing written.
 *   9. Non-default source language — Router-injected language is used, not hard-coded "en".
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\PageTagRepair;

require_once __DIR__ . '/WcPolyfills.php';
require_once dirname( __DIR__, 3 ) . '/language-router/includes/class-context.php';
require_once dirname( __DIR__, 3 ) . '/language-router/includes/class-language-router.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/PageTagRepair.php';

final class PageTagRepairTest extends WcUnitTestCase {

	/** @var array<string,mixed> Saved $_GET state, restored after each test. */
	private array $saved_get = [];

	protected function setUp(): void {
		parent::setUp(); // resets LfWcMocks, injects Router('en')
		$this->saved_get = $_GET;

		// Default to the pages list screen with no language filter.
		$_GET['post_type']       = 'page';
		$_GET['lf_lang_filter']  = '';
	}

	protected function tearDown(): void {
		$_GET = $this->saved_get;
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Registers a WC page option with the given post ID and optionally pre-sets
	 * `_lf_lang` meta for that post.
	 */
	private function set_wc_page( string $option, int $page_id, string $lang = '' ): void {
		\LfWcMocks::$options[ $option ] = (string) $page_id;
		if ( '' !== $lang ) {
			\LfWcMocks::$meta[ $page_id ]['_lf_lang'] = $lang;
		}
	}

	// =========================================================================
	// 1. Happy path
	// =========================================================================

	public function test_untagged_page_receives_source_language(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10 );

		PageTagRepair::maybe_repair();

		$this->assertSame( 'en', \LfWcMocks::$meta[10]['_lf_lang'] );
	}

	// =========================================================================
	// 2. Already tagged — not overwritten
	// =========================================================================

	public function test_tagged_page_is_not_overwritten(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10, 'es' );

		PageTagRepair::maybe_repair();

		$this->assertSame( 'es', \LfWcMocks::$meta[10]['_lf_lang'], 'Pre-existing tag must not be changed.' );
	}

	// =========================================================================
	// 3. Page ID = 0 — unconfigured option
	// =========================================================================

	public function test_skips_when_page_id_is_zero(): void {
		\LfWcMocks::$options['woocommerce_shop_page_id'] = '0';

		PageTagRepair::maybe_repair();

		$this->assertArrayNotHasKey( 0, \LfWcMocks::$meta, 'No meta must be written for page_id = 0.' );
	}

	// =========================================================================
	// 4. All five WC options — each untagged page repaired
	// =========================================================================

	public function test_all_five_wc_options_are_repaired(): void {
		$options = [
			'woocommerce_shop_page_id'       => 10,
			'woocommerce_cart_page_id'       => 11,
			'woocommerce_checkout_page_id'   => 12,
			'woocommerce_myaccount_page_id'  => 13,
			'woocommerce_terms_page_id'      => 14,
		];

		foreach ( $options as $option => $id ) {
			$this->set_wc_page( $option, $id );
		}

		PageTagRepair::maybe_repair();

		foreach ( $options as $id ) {
			$this->assertSame( 'en', \LfWcMocks::$meta[ $id ]['_lf_lang'], "Page $id must be tagged." );
		}
	}

	// =========================================================================
	// 5. Capability guard
	// =========================================================================

	public function test_nothing_written_when_user_lacks_capability(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10 );
		\LfWcMocks::$current_user_can = false;

		PageTagRepair::maybe_repair();

		$this->assertArrayNotHasKey( 10, \LfWcMocks::$meta, 'No meta must be written without edit_posts capability.' );
	}

	// =========================================================================
	// 6. Screen type guard — not the pages list
	// =========================================================================

	public function test_nothing_written_when_screen_is_not_page(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10 );
		$_GET['post_type'] = 'product'; // products list, not pages

		PageTagRepair::maybe_repair();

		$this->assertArrayNotHasKey( 10, \LfWcMocks::$meta, 'No meta must be written on non-page screens.' );
	}

	// =========================================================================
	// 7. GET language filter active
	// =========================================================================

	public function test_nothing_written_when_get_lang_filter_is_set(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10 );
		$_GET['lf_lang_filter'] = 'es'; // specific language selected

		PageTagRepair::maybe_repair();

		$this->assertArrayNotHasKey( 10, \LfWcMocks::$meta, 'No meta must be written when a language filter is active via GET.' );
	}

	// =========================================================================
	// 8. User-meta language filter active
	// =========================================================================

	public function test_nothing_written_when_user_meta_lang_filter_is_set(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10 );
		unset( $_GET['lf_lang_filter'] ); // no GET override
		\LfWcMocks::$user_meta[1]['lf_lang_filter'] = 'fr'; // persisted from previous visit

		PageTagRepair::maybe_repair();

		$this->assertArrayNotHasKey( 10, \LfWcMocks::$meta, 'No meta must be written when user meta lang filter is set.' );
	}

	// =========================================================================
	// 9. Non-default source language
	// =========================================================================

	public function test_uses_router_source_language_not_hardcoded_value(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10 );
		self::inject_router( 'ca' ); // Catalan as source language

		PageTagRepair::maybe_repair();

		$this->assertSame( 'ca', \LfWcMocks::$meta[10]['_lf_lang'], 'Must use source language from Router, not a hard-coded value.' );
	}
}
