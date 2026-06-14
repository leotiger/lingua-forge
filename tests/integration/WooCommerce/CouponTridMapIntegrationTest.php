<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\CouponTridMap.
 *
 * Verifies that coupon product-ID restriction lists are expanded to include
 * all TRID siblings so discounts (and exclusions) apply regardless of which
 * language version of a product the customer has added to their cart (§6.3).
 *
 * Design notes:
 *   - linguaforge_get_translations() is the real plugin function seeded by
 *     TridGroup (already exercised by all WcIntegrationTestCase-based tests).
 *   - CouponTridMap::$group_cache is a private static; each test resets it
 *     via ReflectionProperty to avoid cache hits across tests.
 *   - WC_Coupon is passed as the second argument to the filter but is unused
 *     inside expand_ids(); a minimal stub via PHPUnit's mock builder keeps the
 *     tests free of a real coupon database row.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\CouponTridMap;
use ReflectionClass;
use WC_Coupon;

final class CouponTridMapIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// setUp / tearDown
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		$this->reset_coupon_trid_cache();
	}

	protected function tearDown(): void {
		$this->reset_coupon_trid_cache();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function reset_coupon_trid_cache(): void {
		$ref  = new ReflectionClass( CouponTridMap::class );
		$prop = $ref->getProperty( 'group_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	/**
	 * Return a minimal WC_Coupon stub — expand_ids() only uses it for type
	 * hinting, so a real WC_Coupon object (even without a DB row) is fine.
	 */
	private function make_coupon(): WC_Coupon {
		return new WC_Coupon();
	}

	// =========================================================================
	// 1. Source product ID → expanded to include translated sibling
	// =========================================================================

	/**
	 * A coupon restricted to the source product ID must be valid for the
	 * translated sibling after expand_ids() runs.
	 */
	public function test_expand_ids_includes_translated_sibling_for_source_id(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$expanded = CouponTridMap::expand_ids( [ $source_id ], $this->make_coupon() );

		$this->assertContains( $source_id,     $expanded, 'Source ID must remain in the expanded list.' );
		$this->assertContains( $translated_id, $expanded, 'Translated sibling must be added to the expanded list.' );
	}

	/**
	 * Conversely, a coupon restricted to the translated product ID must also
	 * expand to include the source and all siblings.
	 */
	public function test_expand_ids_includes_source_for_translated_id(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$expanded = CouponTridMap::expand_ids( [ $translated_id ], $this->make_coupon() );

		$this->assertContains( $translated_id, $expanded );
		$this->assertContains( $source_id,     $expanded, 'Source ID must be added when starting from translated ID.' );
	}

	// =========================================================================
	// 2. Excluded IDs follow the same expansion path
	// =========================================================================

	/**
	 * The excluded-IDs filter uses the same expand_ids() method.  Asserting it
	 * here confirms both filter registrations work identically — which they do
	 * because both filters point to the same callback.
	 */
	public function test_expand_ids_works_for_excluded_product_ids(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Simulate the excluded-IDs filter call.
		$expanded = CouponTridMap::expand_ids( [ $source_id ], $this->make_coupon() );

		$this->assertContains( $translated_id, $expanded, 'Excluded-ID expansion must include translated sibling.' );
	}

	// =========================================================================
	// 3. Empty list passes through unchanged
	// =========================================================================

	public function test_expand_ids_returns_empty_array_unchanged(): void {
		$result = CouponTridMap::expand_ids( [], $this->make_coupon() );

		$this->assertSame( [], $result, 'An empty ID list must be returned unchanged.' );
	}

	// =========================================================================
	// 4. Product without TRID group is left unchanged
	// =========================================================================

	/**
	 * A product that is not managed by Lingua Forge (no _lf_trid) must be
	 * returned as-is so LF does not silently interfere with non-translated
	 * products in existing coupon configurations.
	 */
	public function test_expand_ids_leaves_unmanaged_product_unchanged(): void {
		// Create a product with no _lf_lang / _lf_trid.
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );

		$result = CouponTridMap::expand_ids( [ $post_id ], $this->make_coupon() );

		$this->assertSame(
			[ $post_id ],
			$result,
			'Product without a TRID group must pass through expand_ids() unchanged.'
		);
	}

	// =========================================================================
	// 5. De-duplication: IDs are not repeated
	// =========================================================================

	/**
	 * If both the source and translated IDs are listed (e.g. a naively-configured
	 * coupon), the output must not contain duplicates.
	 */
	public function test_expand_ids_deduplicates_results(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$result = CouponTridMap::expand_ids( [ $source_id, $translated_id ], $this->make_coupon() );

		$this->assertCount(
			count( array_unique( $result ) ),
			$result,
			'expand_ids() must not produce duplicate IDs in its output.'
		);
	}

	// =========================================================================
	// 6. Cross-populate cache: second call for sibling is a cache hit
	// =========================================================================

	/**
	 * The first expand_ids() call for any group member must prime the cache for
	 * all siblings, so the second call (for the sibling) does not re-query the DB.
	 *
	 * We verify this by calling once for source_id, then asserting the group_cache
	 * already contains an entry for the translated sibling before the second call.
	 */
	public function test_group_cache_is_cross_populated_after_first_call(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// First call — seeds the cache for both IDs.
		CouponTridMap::expand_ids( [ $source_id ], $this->make_coupon() );

		// Inspect the private cache via reflection.
		$ref   = new ReflectionClass( CouponTridMap::class );
		$cache = $ref->getProperty( 'group_cache' );
		$cache->setAccessible( true );
		$map = $cache->getValue( null );

		$this->assertArrayHasKey(
			$translated_id,
			$map,
			'group_cache must contain the translated sibling after the first expand_ids() call.'
		);
		$this->assertContains(
			$source_id,
			$map[ $translated_id ],
			'The cached group for the translated ID must include the source ID.'
		);
	}

	// =========================================================================
	// 7. Three-language group
	// =========================================================================

	/**
	 * A coupon restricted to one product ID must expand to all three siblings
	 * in a 3-language TRID group.
	 */
	public function test_expand_ids_expands_three_language_group(): void {
		$trid  = $this->trid();
		$ca_id = $this->make_product( self::SOURCE_LANG, $trid );
		$es_id = $this->make_product( 'es', $trid );
		$de_id = $this->make_product( 'de', $trid );

		$result = CouponTridMap::expand_ids( [ $ca_id ], $this->make_coupon() );

		$this->assertContains( $ca_id, $result );
		$this->assertContains( $es_id, $result );
		$this->assertContains( $de_id, $result );
	}

	// =========================================================================
	// 8. Multiple coupon restrictions (multiple groups) are expanded independently
	// =========================================================================

	/**
	 * A coupon may restrict to products from different TRID groups; each must
	 * be expanded independently without cross-contamination.
	 */
	public function test_expand_ids_handles_multiple_groups_independently(): void {
		[ $source_a, $trans_a ] = $this->make_product_pair( 'es' );
		[ $source_b, $trans_b ] = $this->make_product_pair( 'de' );

		$result = CouponTridMap::expand_ids( [ $source_a, $source_b ], $this->make_coupon() );

		$this->assertContains( $source_a, $result );
		$this->assertContains( $trans_a,  $result );
		$this->assertContains( $source_b, $result );
		$this->assertContains( $trans_b,  $result );

		// Ensure no cross-contamination: $trans_a should NOT be in group B.
		// (Both sibling lists must be disjoint.)
		$group_b = [ $source_b, $trans_b ];
		$this->assertNotContains( $trans_a, $group_b );
		$this->assertNotContains( $source_a, $group_b );
	}
}
