<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\MetaDelegate.
 *
 * MetaDelegate hooks get_post_metadata at priority 1.  Tests call the filter
 * callback maybe_delegate() directly rather than through WordPress's hook
 * system, so the integration is exercised in full isolation.
 *
 * Coverage:
 *   1. Key guard — non-operational keys are ignored.
 *   2. Reentrancy guard — already-active delegation bail.
 *   3. Post type guard — unknown post, non-product post types.
 *   4. Language guard — no _lf_lang meta, source-language product.
 *   5. Source ID resolution — no translation map, source_id = object_id.
 *   6. Delegation — single-value and multi-value return shapes.
 *   7. _product_attributes exception — own value takes precedence.
 *   8. Source ID caching — cache filled on first call, re-used on second.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;

require_once __DIR__ . '/WcUnitTestCase.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/MetaDelegate.php';

final class MetaDelegateTest extends WcUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		self::reset_static_array( MetaDelegate::class, 'source_cache' );
		self::reset_static_array( MetaDelegate::class, 'delegating' );
	}

	// =========================================================================
	// 1. Key guard
	// =========================================================================

	public function test_non_operational_key_returns_null(): void {
		$result = MetaDelegate::maybe_delegate( null, 42, '_custom_field', true );
		$this->assertNull( $result );
	}

	public function test_lf_lang_key_is_non_operational_and_returns_null(): void {
		// _lf_lang must never be in OPERATIONAL_KEYS; if it were, the language
		// guard's own get_post_meta() call would recurse.
		$result = MetaDelegate::maybe_delegate( null, 42, '_lf_lang', true );
		$this->assertNull( $result );
	}

	public function test_operational_key_proceeds_past_key_guard(): void {
		// No post registered — bail happens at the post guard, not the key guard.
		// The result is still null, but via a different code path.
		$result = MetaDelegate::maybe_delegate( null, 99999, '_price', true );
		$this->assertNull( $result, 'Unknown post should return null at post guard.' );
	}

	// =========================================================================
	// 2. Reentrancy guard
	// =========================================================================

	public function test_reentrancy_guard_prevents_recursion(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_price', '9.99' );

		// Simulate an already-active delegation for this exact key.
		self::set_static( MetaDelegate::class, 'delegating', [ '42:_price' => true ] );

		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result, 'Reentrancy guard must cause an early return.' );
	}

	// =========================================================================
	// 3. Post type guard
	// =========================================================================

	public function test_unknown_post_returns_null(): void {
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result );
	}

	public function test_non_product_post_type_returns_null(): void {
		$this->make_post( 42, 'page' );
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result );
	}

	public function test_custom_cpt_returns_null_by_default(): void {
		$this->make_post( 42, 'book' );
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result );
	}

	// =========================================================================
	// 4. Language guard
	// =========================================================================

	public function test_post_without_lang_meta_returns_null(): void {
		$this->make_post( 42 );
		// No _lf_lang meta set.
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result );
	}

	public function test_source_language_product_returns_null(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'en' ); // 'en' is the injected source language
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result, 'Source-language products must serve their own meta.' );
	}

	// =========================================================================
	// 5. Source ID resolution
	// =========================================================================

	public function test_no_translation_map_returns_null(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// No translation map → source_id = 0 → bail.
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result );
	}

	public function test_source_id_equal_to_object_id_returns_null(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// Pathological map where source resolves to the same post.
		$this->set_translations( 42, [ 'en' => 42 ] );
		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );
		$this->assertNull( $result );
	}

	// =========================================================================
	// 6. Delegation
	// =========================================================================

	public function test_delegates_single_value_from_source_product(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_price', '19.99' );

		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );

		// For $single=true the filter must return [ $value ]; WP unwraps it.
		$this->assertSame( [ '19.99' ], $result );
	}

	public function test_delegates_multi_value_shape_from_source_product(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_stock_status', 'instock' );

		$result = MetaDelegate::maybe_delegate( null, 42, '_stock_status', false );

		// For $single=false the filter must return the value wrapped in an array.
		$this->assertSame( [ 'instock' ], $result );
	}

	public function test_delegates_integer_stock_value(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_stock', 25 );

		$result = MetaDelegate::maybe_delegate( null, 42, '_stock', true );

		$this->assertSame( [ 25 ], $result );
	}

	public function test_passes_through_existing_non_null_filter_value_for_non_operational_key(): void {
		// Already-filtered value must be preserved for non-operational keys.
		$result = MetaDelegate::maybe_delegate( 'already_filtered', 42, '_wp_page_template', true );
		$this->assertSame( 'already_filtered', $result );
	}

	// =========================================================================
	// 7. _product_attributes exception
	// =========================================================================

	public function test_product_attributes_with_own_value_passes_through(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_product_attributes', [ 'pa_color' => [ 'name' => 'pa_color', 'is_taxonomy' => 1 ] ] );
		// Translated post has its own _product_attributes (AI-translated custom attrs).
		$this->set_meta( 42, '_product_attributes', [ 'pa_color' => [ 'name' => 'pa_color', 'is_taxonomy' => 1 ] ] );

		$result = MetaDelegate::maybe_delegate( null, 42, '_product_attributes', true );

		$this->assertNull( $result, '_product_attributes with own value must pass through (return null).' );
	}

	public function test_product_attributes_without_own_value_delegates_to_source(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$source_attrs = [ 'pa_size' => [ 'name' => 'pa_size', 'is_taxonomy' => 1 ] ];
		$this->set_meta( 100, '_product_attributes', $source_attrs );
		// Translated post has NO _product_attributes.

		$result = MetaDelegate::maybe_delegate( null, 42, '_product_attributes', true );

		$this->assertSame( [ $source_attrs ], $result );
	}

	// =========================================================================
	// 8. Source ID caching
	// =========================================================================

	public function test_source_id_is_written_to_cache_after_first_lookup(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );
		$this->set_meta( 100, '_price', '5.00' );

		MetaDelegate::maybe_delegate( null, 42, '_price', true );

		$cache = self::read_static( MetaDelegate::class, 'source_cache' );
		$this->assertArrayHasKey( 42, $cache, 'source_cache must contain entry for translated product.' );
		$this->assertSame( 100, $cache[42] );
	}

	public function test_stale_cache_entry_is_used_instead_of_fresh_lookup(): void {
		// Pre-seed the cache with a fictional source ID (200) for product 42.
		// This proves the cache short-circuits linguaforge_get_translations().
		self::set_static( MetaDelegate::class, 'source_cache', [ 42 => 200 ] );

		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// Register the cached source post and set its meta.
		$this->make_post( 200 );
		$this->set_meta( 200, '_price', 'FROM_CACHE' );
		// Do NOT register any translation map for post 42.

		$result = MetaDelegate::maybe_delegate( null, 42, '_price', true );

		$this->assertSame( [ 'FROM_CACHE' ], $result, 'Cached source ID 200 must be used.' );
	}
}
