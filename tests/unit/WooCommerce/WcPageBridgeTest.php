<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\WcPageBridge.
 *
 * WcPageBridge hooks into `display_post_states` (priority 20) to mark translated
 * equivalents of WC built-in pages (Shop, Cart, Checkout, My Account, Terms)
 * with the same "— Cart Page" label WooCommerce shows for the source-language
 * originals.
 *
 * Tests are exercised by calling the static methods directly with WP_Post
 * arguments after configuring LfWcMocks.  No WordPress or WooCommerce runtime
 * is needed.
 *
 * Coverage — add_translated_page_states:
 *   1. Translated cart page with matching _lf_trid → "Cart Page" label appended.
 *   2. Source page itself → not relabelled (WC already labels it at priority 10).
 *   3. Page with no _lf_trid → no label added.
 *   4. Page whose trid doesn't match any WC page → no label added.
 *   5. All five WC page types labelled for their translated equivalents.
 *   6. Existing states from prior hooks are preserved alongside the new label.
 *
 * Coverage — translate_shop_page_id (unconditional trid+lang lookup):
 *   7. LF_LANG set, translated page found via _lf_trid + _lf_lang → translated ID returned.
 *   8. is_admin() → original value returned (admin guard).
 *   9. Source shop has no _lf_trid → original value returned.
 *  10. No page in DB with matching _lf_lang → original value returned.
 *  11. Page with right _lf_trid but wrong _lf_lang in DB → original value returned (AND condition verified).
 *  12. Result cached: second call returns same translated ID without re-querying DB.
 *
 * Coverage — translate_cart_page_id / translate_checkout_page_id / translate_myaccount_page_id:
 *  13. translate_cart_page_id — translated page found → translated ID returned.
 *  14. translate_cart_page_id — no translated page in DB → source ID passed through.
 *  15. translate_checkout_page_id — translated page found → translated ID returned.
 *  16. translate_myaccount_page_id — translated page found → translated ID returned.
 *  17. All three page-type caches are keyed independently; no cross-type bleed.
 *
 * Coverage — filter_related_products_by_lang:
 *  18. Empty related_ids → empty array returned.
 *  19. Source-language product (no _lf_lang) → related_ids passed through unchanged.
 *  20. Source-language product (_lf_lang = source lang) → related_ids unchanged.
 *  21. Translated product; source-language peers mapped to their translations via _lf_trid.
 *  22. Self-exclusion: peer maps back to the current product after trid lookup → excluded.
 *  23. Already-right-language peer → kept as-is without a DB lookup.
 *  24. Non-source / non-target language peer → dropped from result.
 *  25. Source peer with no _lf_trid → skipped (no translation entry exists).
 *  26. Source peer whose trid has no translation in the target language → dropped.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\WcPageBridge;

require_once __DIR__ . '/WcPolyfills.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/WcPageBridge.php';

// Define LF_LANG once for this suite — simulates the language router having
// resolved a translated-language frontend request.  The guard allows the
// constant to coexist with other unit-test files that may define it first.
defined( 'LF_LANG' ) || define( 'LF_LANG', 'es' );

final class WcPageBridgeTest extends WcUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset per-request caches so state never leaks between tests.
		self::set_static( WcPageBridge::class, 'source_trids', null );
		self::reset_static_array( WcPageBridge::class, 'translated_page_ids' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a page-type WP_Post stub registered in the mock post store and,
	 * optionally, set its _lf_trid.
	 */
	private function make_page( int $id, string $trid = '' ): \WP_Post {
		$post = $this->make_post( $id, 'page' );
		if ( '' !== $trid ) {
			\LfWcMocks::$meta[ $id ]['_lf_trid'] = $trid;
		}
		return $post;
	}

	/**
	 * Configure a WC option + source-page trid in the mock stores.
	 */
	private function set_wc_page( string $option, int $source_id, string $trid ): void {
		\LfWcMocks::$options[ $option ]                  = (string) $source_id;
		\LfWcMocks::$meta[ $source_id ]['_lf_trid']     = $trid;
	}

	// =========================================================================
	// 1. Translated cart page receives label
	// =========================================================================

	public function test_translated_cart_page_receives_label(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 10, 'trid-cart' );
		$translated = $this->make_page( 20, 'trid-cart' );

		$states = WcPageBridge::add_translated_page_states( [], $translated );

		$this->assertArrayHasKey( 'woocommerce_cart_page_id', $states );
		$this->assertSame( 'Cart Page', $states['woocommerce_cart_page_id'] );
	}

	// =========================================================================
	// 2. Source page itself is not relabelled
	// =========================================================================

	public function test_source_page_is_not_relabelled(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 10, 'trid-cart' );
		$source = $this->make_page( 10, 'trid-cart' );

		$states = WcPageBridge::add_translated_page_states( [], $source );

		$this->assertEmpty( $states, 'Source page must not receive a duplicate label — WC already labels it at priority 10.' );
	}

	// =========================================================================
	// 3. Page with no trid — no label
	// =========================================================================

	public function test_page_with_no_trid_receives_no_label(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 10, 'trid-cart' );
		$unlinked = $this->make_page( 99 ); // no _lf_trid

		$states = WcPageBridge::add_translated_page_states( [], $unlinked );

		$this->assertEmpty( $states );
	}

	// =========================================================================
	// 4. Page whose trid doesn't match any WC page — no label
	// =========================================================================

	public function test_page_with_unrelated_trid_receives_no_label(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 10, 'trid-cart' );
		$unrelated = $this->make_page( 30, 'trid-about' ); // belongs to a different translation group

		$states = WcPageBridge::add_translated_page_states( [], $unrelated );

		$this->assertEmpty( $states );
	}

	// =========================================================================
	// 5. All five WC page types receive their labels
	// =========================================================================

	public function test_all_five_wc_page_types_label_their_translated_equivalents(): void {
		$pages = [
			'woocommerce_shop_page_id'      => [ 'source' => 10, 'translated' => 110, 'trid' => 'trid-shop',     'label' => 'Shop Page' ],
			'woocommerce_cart_page_id'      => [ 'source' => 11, 'translated' => 111, 'trid' => 'trid-cart',     'label' => 'Cart Page' ],
			'woocommerce_checkout_page_id'  => [ 'source' => 12, 'translated' => 112, 'trid' => 'trid-checkout', 'label' => 'Checkout Page' ],
			'woocommerce_myaccount_page_id' => [ 'source' => 13, 'translated' => 113, 'trid' => 'trid-account',  'label' => 'My Account Page' ],
			'woocommerce_terms_page_id'     => [ 'source' => 14, 'translated' => 114, 'trid' => 'trid-terms',    'label' => 'Terms Page' ],
		];

		foreach ( $pages as $option => $data ) {
			$this->set_wc_page( $option, $data['source'], $data['trid'] );
		}

		foreach ( $pages as $option => $data ) {
			// Reset the source-trid cache so each sub-test has a clean slate.
			self::set_static( WcPageBridge::class, 'source_trids', null );
			$translated = $this->make_page( $data['translated'], $data['trid'] );
			$states     = WcPageBridge::add_translated_page_states( [], $translated );
			$this->assertArrayHasKey( $option, $states, "Missing label for {$option}." );
			$this->assertSame( $data['label'], $states[ $option ], "Wrong label for {$option}." );
		}
	}

	// =========================================================================
	// 6. Existing states are preserved
	// =========================================================================

	public function test_existing_states_are_preserved_alongside_new_wc_label(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 10, 'trid-cart' );
		$translated = $this->make_page( 20, 'trid-cart' );

		$prior  = [ 'front_page' => 'Front Page' ];
		$states = WcPageBridge::add_translated_page_states( $prior, $translated );

		$this->assertArrayHasKey( 'front_page', $states, 'Prior state must be preserved.' );
		$this->assertArrayHasKey( 'woocommerce_cart_page_id', $states, 'WC label must be appended.' );
	}

	// =========================================================================
	// 7. translate_shop_page_id — happy path: trid + lang lookup returns translated ID
	// =========================================================================

	public function test_translate_shop_page_id_returns_translated_id_via_trid_lang_lookup(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10, 'trid-shop' );
		// Translated page (ID 20) shares the source shop page's _lf_trid and is
		// tagged with the current frontend language.  queried_object_id is NOT
		// needed — the filter is unconditional (WCML / Polylang pattern).
		\LfWcMocks::$meta[20]['_lf_trid'] = 'trid-shop';
		\LfWcMocks::$meta[20]['_lf_lang'] = LF_LANG;

		// Filter must return 20 so WC_Query::pre_get_posts() sees page_id === shop_id.
		$this->assertSame( 20, WcPageBridge::translate_shop_page_id( 10 ) );
	}

	// =========================================================================
	// 8. translate_shop_page_id — is_admin() guard
	// =========================================================================

	public function test_translate_shop_page_id_passes_through_in_admin_context(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10, 'trid-shop' );
		\LfWcMocks::$meta[20]['_lf_trid'] = 'trid-shop';
		\LfWcMocks::$meta[20]['_lf_lang'] = LF_LANG;
		\LfWcMocks::$is_admin             = true;

		$this->assertSame( 10, WcPageBridge::translate_shop_page_id( 10 ) );
	}

	// =========================================================================
	// 9. translate_shop_page_id — source shop has no _lf_trid
	// =========================================================================

	public function test_translate_shop_page_id_passes_through_when_source_has_no_trid(): void {
		// Source page (ID 10) stored in option but has no _lf_trid — site has not
		// linked the WC pages into LF translation groups.
		\LfWcMocks::$options['woocommerce_shop_page_id'] = '10';
		// No _lf_trid on post 10 → get_source_trids() returns no shop entry.

		$this->assertSame( 10, WcPageBridge::translate_shop_page_id( 10 ) );
	}

	// =========================================================================
	// 10. translate_shop_page_id — no translated page with matching _lf_lang in DB
	// =========================================================================

	public function test_translate_shop_page_id_passes_through_when_no_translated_page_in_db(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10, 'trid-shop' );
		// DB has no page with _lf_lang = LF_LANG — translated shop not yet created.

		$this->assertSame( 10, WcPageBridge::translate_shop_page_id( 10 ) );
	}

	// =========================================================================
	// 11. translate_shop_page_id — trid matches but _lf_lang differs (AND condition)
	// =========================================================================

	public function test_translate_shop_page_id_passes_through_when_trid_matches_but_lang_differs(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10, 'trid-shop' );
		// Page 20 belongs to the same translation group but is tagged with a
		// different language — the AND relation in the meta_query must exclude it.
		\LfWcMocks::$meta[20]['_lf_trid'] = 'trid-shop';
		\LfWcMocks::$meta[20]['_lf_lang'] = 'xx'; // not LF_LANG

		$this->assertSame( 10, WcPageBridge::translate_shop_page_id( 10 ) );
	}

	// =========================================================================
	// 12. translate_shop_page_id — result is cached for the duration of the request
	// =========================================================================

	public function test_translate_shop_page_id_result_is_cached_per_request(): void {
		$this->set_wc_page( 'woocommerce_shop_page_id', 10, 'trid-shop' );
		\LfWcMocks::$meta[20]['_lf_trid'] = 'trid-shop';
		\LfWcMocks::$meta[20]['_lf_lang'] = LF_LANG;

		// Prime the cache.
		$this->assertSame( 20, WcPageBridge::translate_shop_page_id( 10 ) );

		// Remove the DB entry — a fresh query would now return no match.
		unset( \LfWcMocks::$meta[20] );

		// Second call must still return 20 from the per-request cache.
		$this->assertSame( 20, WcPageBridge::translate_shop_page_id( 10 ) );
	}

	// =========================================================================
	// 13. translate_cart_page_id — translated page found
	// =========================================================================

	public function test_translate_cart_page_id_returns_translated_id(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 40, 'trid-cart' );
		\LfWcMocks::$meta[41]['_lf_trid'] = 'trid-cart';
		\LfWcMocks::$meta[41]['_lf_lang'] = LF_LANG;

		$this->assertSame( 41, WcPageBridge::translate_cart_page_id( 40 ) );
	}

	// =========================================================================
	// 14. translate_cart_page_id — no translated page in DB → passthrough
	// =========================================================================

	public function test_translate_cart_page_id_passes_through_when_no_translated_page(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id', 40, 'trid-cart' );
		// No page with _lf_trid='trid-cart' and _lf_lang=LF_LANG in mock DB.

		$this->assertSame( 40, WcPageBridge::translate_cart_page_id( 40 ) );
	}

	// =========================================================================
	// 15. translate_checkout_page_id — translated page found
	// =========================================================================

	public function test_translate_checkout_page_id_returns_translated_id(): void {
		$this->set_wc_page( 'woocommerce_checkout_page_id', 50, 'trid-checkout' );
		\LfWcMocks::$meta[51]['_lf_trid'] = 'trid-checkout';
		\LfWcMocks::$meta[51]['_lf_lang'] = LF_LANG;

		$this->assertSame( 51, WcPageBridge::translate_checkout_page_id( 50 ) );
	}

	// =========================================================================
	// 16. translate_myaccount_page_id — translated page found
	// =========================================================================

	public function test_translate_myaccount_page_id_returns_translated_id(): void {
		$this->set_wc_page( 'woocommerce_myaccount_page_id', 60, 'trid-account' );
		\LfWcMocks::$meta[61]['_lf_trid'] = 'trid-account';
		\LfWcMocks::$meta[61]['_lf_lang'] = LF_LANG;

		$this->assertSame( 61, WcPageBridge::translate_myaccount_page_id( 60 ) );
	}

	// =========================================================================
	// 17. Cart/checkout/myaccount caches are keyed independently
	// =========================================================================

	public function test_cart_checkout_myaccount_caches_are_keyed_independently(): void {
		$this->set_wc_page( 'woocommerce_cart_page_id',      40, 'trid-cart' );
		$this->set_wc_page( 'woocommerce_checkout_page_id',  50, 'trid-checkout' );
		$this->set_wc_page( 'woocommerce_myaccount_page_id', 60, 'trid-account' );
		\LfWcMocks::$meta[41]['_lf_trid'] = 'trid-cart';     \LfWcMocks::$meta[41]['_lf_lang'] = LF_LANG;
		\LfWcMocks::$meta[51]['_lf_trid'] = 'trid-checkout'; \LfWcMocks::$meta[51]['_lf_lang'] = LF_LANG;
		\LfWcMocks::$meta[61]['_lf_trid'] = 'trid-account';  \LfWcMocks::$meta[61]['_lf_lang'] = LF_LANG;

		$this->assertSame( 41, WcPageBridge::translate_cart_page_id( 40 ),      'cart ID' );
		$this->assertSame( 51, WcPageBridge::translate_checkout_page_id( 50 ),  'checkout ID' );
		$this->assertSame( 61, WcPageBridge::translate_myaccount_page_id( 60 ), 'myaccount ID' );
	}

	// =========================================================================
	// filter_related_products_by_lang — shared product fixture
	// =========================================================================

	/**
	 * Seed LfWcMocks with a standard two-language product fixture:
	 *
	 *   EN Widget  (ID 13,  _lf_lang='en', _lf_trid='T-wgt')
	 *   ES Widget  (ID 121, _lf_lang='es', _lf_trid='T-wgt')  ← product under test
	 *   EN Shirt   (ID 16,  _lf_lang='en', _lf_trid='T-srt')
	 *   ES Shirt   (ID 118, _lf_lang='es', _lf_trid='T-srt')
	 *
	 * Router inject_router('en') is already called by WcUnitTestCase::setUp();
	 * source language is therefore 'en'.  LF_LANG = 'es'.
	 */
	private function seed_related_products_fixture(): void {
		$this->make_post( 13,  'product' );
		$this->make_post( 121, 'product' );
		$this->make_post( 16,  'product' );
		$this->make_post( 118, 'product' );
		\LfWcMocks::$meta[13]['_lf_lang']  = 'en'; \LfWcMocks::$meta[13]['_lf_trid']  = 'T-wgt';
		\LfWcMocks::$meta[121]['_lf_lang'] = 'es'; \LfWcMocks::$meta[121]['_lf_trid'] = 'T-wgt';
		\LfWcMocks::$meta[16]['_lf_lang']  = 'en'; \LfWcMocks::$meta[16]['_lf_trid']  = 'T-srt';
		\LfWcMocks::$meta[118]['_lf_lang'] = 'es'; \LfWcMocks::$meta[118]['_lf_trid'] = 'T-srt';
	}

	// =========================================================================
	// 18. filter_related_products_by_lang — empty related_ids
	// =========================================================================

	public function test_filter_related_products_empty_input_returns_empty(): void {
		$this->seed_related_products_fixture();

		$result = WcPageBridge::filter_related_products_by_lang( [], 121 );

		$this->assertSame( [], $result );
	}

	// =========================================================================
	// 19. filter_related_products_by_lang — source-language product (no _lf_lang)
	// =========================================================================

	public function test_filter_related_products_source_product_no_lang_passes_through(): void {
		// Product 13 has no _lf_lang set → treated as source → passthrough.
		$this->make_post( 13, 'product' );
		$related = [ 16, 20 ];

		$result = WcPageBridge::filter_related_products_by_lang( $related, 13 );

		$this->assertSame( $related, $result );
	}

	// =========================================================================
	// 20. filter_related_products_by_lang — source-language product (_lf_lang = source lang)
	// =========================================================================

	public function test_filter_related_products_source_lang_product_returns_unchanged(): void {
		// Router source lang = 'en'; product also 'en' → passthrough.
		\LfWcMocks::$meta[13]['_lf_lang'] = 'en';
		$related = [ 16, 20 ];

		$result = WcPageBridge::filter_related_products_by_lang( $related, 13 );

		$this->assertSame( $related, $result );
	}

	// =========================================================================
	// 21. filter_related_products_by_lang — source peers mapped to translations
	// =========================================================================

	public function test_filter_related_products_maps_source_peers_to_translations(): void {
		$this->seed_related_products_fixture();
		// Product 121 (ES Widget) viewed; WC returns [16] (EN Shirt).
		// Expected: [118] (ES Shirt) via T-srt trid.

		$result = WcPageBridge::filter_related_products_by_lang( [ 16 ], 121 );

		$this->assertSame( [ 118 ], array_values( $result ) );
	}

	// =========================================================================
	// 22. filter_related_products_by_lang — self-exclusion after trid mapping
	// =========================================================================

	public function test_filter_related_products_excludes_self_after_trid_mapping(): void {
		$this->seed_related_products_fixture();
		// Product 121 (ES Widget) viewed; WC returns [13, 16].
		// 13 (EN Widget, T-wgt) maps to 121 → self-exclusion.
		// 16 (EN Shirt, T-srt) maps to 118.
		// Expected: [118].

		$result = WcPageBridge::filter_related_products_by_lang( [ 13, 16 ], 121 );

		$this->assertSame( [ 118 ], array_values( $result ) );
	}

	// =========================================================================
	// 23. filter_related_products_by_lang — already-right-language peer kept as-is
	// =========================================================================

	public function test_filter_related_products_keeps_already_translated_peer(): void {
		$this->seed_related_products_fixture();
		// Product 121 (ES) viewed; WC returns [118] (ES Shirt already in es).
		// rel_lang='es' === lang='es' → kept without batch lookup.

		$result = WcPageBridge::filter_related_products_by_lang( [ 118 ], 121 );

		$this->assertSame( [ 118 ], array_values( $result ) );
	}

	// =========================================================================
	// 24. filter_related_products_by_lang — different language peer dropped
	// =========================================================================

	public function test_filter_related_products_skips_different_language_peer(): void {
		$this->seed_related_products_fixture();
		// DE product (201): neither 'en' (source) nor 'es' (target) → dropped.
		$this->make_post( 201, 'product' );
		\LfWcMocks::$meta[201]['_lf_lang'] = 'de';
		\LfWcMocks::$meta[201]['_lf_trid'] = 'T-de';

		$result = WcPageBridge::filter_related_products_by_lang( [ 201 ], 121 );

		$this->assertSame( [], array_values( $result ) );
	}

	// =========================================================================
	// 25. filter_related_products_by_lang — source peer with no _lf_trid skipped
	// =========================================================================

	public function test_filter_related_products_skips_source_peer_with_no_trid(): void {
		$this->seed_related_products_fixture();
		// EN product 99 has no _lf_trid → cannot locate a translation → dropped.
		$this->make_post( 99, 'product' );
		\LfWcMocks::$meta[99]['_lf_lang'] = 'en';
		// _lf_trid intentionally absent.

		$result = WcPageBridge::filter_related_products_by_lang( [ 99 ], 121 );

		$this->assertSame( [], array_values( $result ) );
	}

	// =========================================================================
	// 26. filter_related_products_by_lang — no translation in target lang → dropped
	// =========================================================================

	public function test_filter_related_products_drops_peer_when_no_translation_in_target_lang(): void {
		// EN product 16 has a trid but no ES translation exists in DB.
		$this->make_post( 16, 'product' );
		\LfWcMocks::$meta[16]['_lf_lang']  = 'en';
		\LfWcMocks::$meta[16]['_lf_trid']  = 'T-srt';
		// Current product is ES.
		\LfWcMocks::$meta[121]['_lf_lang'] = 'es';
		// No post with _lf_trid='T-srt' AND _lf_lang='es' in mock DB.

		$result = WcPageBridge::filter_related_products_by_lang( [ 16 ], 121 );

		$this->assertSame( [], array_values( $result ) );
	}

	// =========================================================================
	// 27–32. translate_privacy_policy_page_id
	//
	// The WC Privacy Policy page is a WordPress core page (not a WC option), so
	// translate_privacy_policy_page_id() resolves the translation directly via
	// TridGroup::get_translations() rather than the shared translate_wc_page_id()
	// helper used by the other WC page types.
	//
	// LF_LANG = 'es' (defined at the top of this file).
	// Default source_lang from inject_router() = 'en'.
	// =========================================================================

	/**
	 * 27. is_admin() returns true → original page_id passed through.
	 */
	public function test_translate_privacy_policy_page_id_skips_on_admin(): void {
		\LfWcMocks::$is_admin = true;

		$result = WcPageBridge::translate_privacy_policy_page_id( 99 );

		$this->assertSame( 99, $result, 'Admin requests must not translate the Privacy Policy page ID.' );
	}

	/**
	 * 28. page_id ≤ 0 → return original (invalid page; no DB lookup performed).
	 */
	public function test_translate_privacy_policy_page_id_skips_non_positive_id(): void {
		$result = WcPageBridge::translate_privacy_policy_page_id( 0 );

		$this->assertSame( 0, $result, 'Non-positive page ID must be returned unchanged.' );
	}

	/**
	 * 29. LF_LANG === source_language() → return original (no translation needed).
	 */
	public function test_translate_privacy_policy_page_id_skips_source_lang(): void {
		// Override source_lang to 'es' so it equals LF_LANG ('es') → early return.
		self::inject_router( 'es' );

		$result = WcPageBridge::translate_privacy_policy_page_id( 55 );

		$this->assertSame( 55, $result, 'Source-language requests must not translate the Privacy Policy page ID.' );
	}

	/**
	 * 30. Translation found via TridGroup → translated page ID returned.
	 */
	public function test_translate_privacy_policy_page_id_returns_translation_when_found(): void {
		self::inject_router_with_trid_group();

		// Source Privacy Policy page: ID 60, trid 'T-pp-030'.
		\LfWcMocks::$meta[60] = [ '_lf_trid' => 'T-pp-030' ];

		// TridGroup::get_translations(60) will call $wpdb->get_results() →
		// return the pre-set rows: EN original + ES translation.
		\LfWcMocks::$db_results = [
			(object) [ 'post_id' => '60',  'lang' => 'en' ],
			(object) [ 'post_id' => '61', 'lang' => 'es' ],
		];

		$result = WcPageBridge::translate_privacy_policy_page_id( 60 );

		$this->assertSame( 61, $result, 'Must return the translated page ID when a translation exists for LF_LANG.' );
	}

	/**
	 * 31. No translation for LF_LANG in trid group → original ID returned.
	 */
	public function test_translate_privacy_policy_page_id_falls_back_when_no_translation(): void {
		self::inject_router_with_trid_group();

		// Source Privacy Policy page: ID 70, trid 'T-pp-031'.
		\LfWcMocks::$meta[70] = [ '_lf_trid' => 'T-pp-031' ];

		// Only the EN row exists — no ES translation.
		\LfWcMocks::$db_results = [
			(object) [ 'post_id' => '70', 'lang' => 'en' ],
		];

		$result = WcPageBridge::translate_privacy_policy_page_id( 70 );

		$this->assertSame( 70, $result, 'Must return original page ID when no translation exists for LF_LANG.' );
	}

	/**
	 * 32. Page has no _lf_trid → TridGroup returns [] → original ID returned.
	 */
	public function test_translate_privacy_policy_page_id_falls_back_when_no_trid(): void {
		self::inject_router_with_trid_group();

		// Source Privacy Policy page: ID 80, no _lf_trid stored.
		\LfWcMocks::$meta[80] = []; // intentionally no _lf_trid

		// DB would not be queried (get_trid returns '' → get_translations returns []),
		// but even if queried, $db_results is empty.
		\LfWcMocks::$db_results = [];

		$result = WcPageBridge::translate_privacy_policy_page_id( 80 );

		$this->assertSame( 80, $result, 'Must return original page ID when the page has no _lf_trid.' );
	}

}
