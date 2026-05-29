<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\MetaDelegate.
 *
 * Exercises the full WordPress metadata path: get_post_meta() → get_post_metadata
 * filter (priority 1) → MetaDelegate::maybe_delegate() → get_post_meta() on the
 * source product.  All assertions run against the live $wpdb stack inside wp-env.
 *
 * WP_UnitTestCase wraps each test in a DB transaction rolled back on tearDown,
 * so no manual cleanup of posts or postmeta is needed.
 *
 * Coverage:
 *   1. Operational meta on translated product returns source value.
 *   2. Source product serves its own meta (not delegated).
 *   3. Non-operational meta is NOT delegated.
 *   4. _product_attributes own-value on translated product takes precedence.
 *   5. Unlinked translated product (no TRID) returns empty meta (fail-safe).
 *   6. Product without a language assignment is not delegated.
 *   7. All single OPERATIONAL_KEYS are delegated (data-provider spot-check).
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

final class MetaDelegateIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// 1. Delegation to source
	// =========================================================================

	public function test_operational_meta_on_translated_product_returns_source_value(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_price', '49.99' );

		$result = get_post_meta( $translated_id, '_price', true );

		$this->assertSame( '49.99', $result, 'Translated product must return the source price via delegation.' );
	}

	public function test_delegation_works_for_single_false_return_shape(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_stock_status', 'instock' );

		$result = get_post_meta( $translated_id, '_stock_status', false );

		// WP unwraps the filter's returned array, giving us the plain array of values.
		$this->assertIsArray( $result );
		$this->assertContains( 'instock', $result );
	}

	public function test_delegation_returns_integer_stock_value(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_stock', 25 );

		// WooCommerce stores _stock as a string, but let's verify the value regardless.
		$result = get_post_meta( $translated_id, '_stock', true );

		$this->assertSame( 25, (int) $result );
	}

	// =========================================================================
	// 2. Source product serves its own meta
	// =========================================================================

	public function test_source_product_serves_own_meta(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_price', '99.00' );

		$result = get_post_meta( $source_id, '_price', true );

		$this->assertSame( '99.00', $result, 'Source product must serve its own meta without delegation.' );
	}

	public function test_two_sources_in_different_groups_do_not_bleed(): void {
		[ $source_a, $translated_a ] = $this->make_product_pair();
		[ $source_b, $translated_b ] = $this->make_product_pair();

		update_post_meta( $source_a, '_price', '10.00' );
		update_post_meta( $source_b, '_price', '20.00' );

		$this->assertSame( '10.00', get_post_meta( $translated_a, '_price', true ) );
		$this->assertSame( '20.00', get_post_meta( $translated_b, '_price', true ) );
	}

	// =========================================================================
	// 3. Non-operational meta passes through
	// =========================================================================

	public function test_non_operational_meta_is_not_delegated(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_my_plugin_data', 'source_value' );
		update_post_meta( $translated_id, '_my_plugin_data', 'translated_value' );

		$result = get_post_meta( $translated_id, '_my_plugin_data', true );

		$this->assertSame( 'translated_value', $result, 'Non-operational meta must not be delegated.' );
	}

	public function test_lf_lang_is_not_delegated(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Translated product's own _lf_lang must be returned, not the source's.
		$result = get_post_meta( $translated_id, '_lf_lang', true );

		$this->assertSame( self::TRANS_LANG, $result );
	}

	// =========================================================================
	// 4. _product_attributes own-value takes precedence
	// =========================================================================

	public function test_product_attributes_own_value_takes_precedence_over_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Use custom (non-taxonomy-backed) attributes — is_taxonomy=0 avoids triggering
		// WooCommerce's pa_* taxonomy registration and the term-loading machinery it entails.
		$source_attrs     = [ 'size' => [ 'name' => 'Size', 'is_taxonomy' => 0 ] ];
		$translated_attrs = [ 'size' => [ 'name' => 'Size', 'is_taxonomy' => 0, '_translated' => true ] ];

		update_post_meta( $source_id, '_product_attributes', $source_attrs );
		update_post_meta( $translated_id, '_product_attributes', $translated_attrs );

		$result = get_post_meta( $translated_id, '_product_attributes', true );

		$this->assertSame( $translated_attrs, $result, 'Translated _product_attributes must not be overwritten by delegation.' );
	}

	public function test_product_attributes_delegated_when_translated_has_none(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Plain custom attribute — no pa_* taxonomy registration or term loading.
		$source_attrs = [ 'color' => [ 'name' => 'Color', 'is_taxonomy' => 0 ] ];
		update_post_meta( $source_id, '_product_attributes', $source_attrs );
		// Translated product has NO _product_attributes.

		$result = get_post_meta( $translated_id, '_product_attributes', true );

		$this->assertSame( $source_attrs, $result );
	}

	// =========================================================================
	// 5. Fail-safe: unlinked translated product
	// =========================================================================

	public function test_unlinked_translated_product_returns_empty_meta(): void {
		// Translated product with a language but no TRID → source_id = 0 → fail-safe.
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $post_id, self::TRANS_LANG );
		// Deliberately no set_trid() call.

		update_post_meta( $post_id, '_price', '' ); // Ensure no own value exists.

		$result = get_post_meta( $post_id, '_price', true );

		$this->assertSame( '', $result, 'Unlinked translated product must return empty meta (fail-safe).' );
	}

	// =========================================================================
	// 6. Product without language assignment is not delegated
	// =========================================================================

	public function test_product_without_lang_meta_is_not_delegated(): void {
		$source_id = self::factory()->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$other_id  = self::factory()->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		// $other_id has no _lf_lang — MetaDelegate must not touch it.

		update_post_meta( $source_id, '_price', '5.00' );
		update_post_meta( $other_id,  '_price', '7.00' );

		$this->assertSame( '7.00', get_post_meta( $other_id, '_price', true ) );
	}

	// =========================================================================
	// 7. Spot-check operational key set
	// =========================================================================

	/**
	 * @dataProvider operational_key_provider
	 */
	public function test_operational_key_is_delegated( string $key, mixed $value ): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, $key, $value );

		$result = get_post_meta( $translated_id, $key, true );

		$this->assertEquals( $value, $result, "Operational key '$key' must be delegated to the source product." );
	}

	public static function operational_key_provider(): array {
		return [
			'_price'         => [ '_price',         '29.99' ],
			'_regular_price' => [ '_regular_price',  '39.99' ],
			'_sku'           => [ '_sku',             'ABC-001' ],
			'_stock'         => [ '_stock',           10 ],
			'_stock_status'  => [ '_stock_status',    'instock' ],
			'_manage_stock'  => [ '_manage_stock',    'yes' ],
			'_weight'        => [ '_weight',          '0.5' ],
			'_thumbnail_id'  => [ '_thumbnail_id',    42 ],
		];
	}
}
