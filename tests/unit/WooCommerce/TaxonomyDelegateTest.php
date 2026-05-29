<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate.
 *
 * TaxonomyDelegate hooks wp_get_object_terms at priority 10.  Tests call
 * maybe_delegate_terms() directly.
 *
 * The private static is_wc_taxonomy() helper is exercised via Reflection to
 * keep taxonomy-recognition logic independently verifiable.
 *
 * Coverage:
 *   1. is_wc_taxonomy() — product_cat, product_tag, product_type, pa_* prefix.
 *   2. is_wc_taxonomy() — non-WC taxonomies are rejected.
 *   3. Multi-object queries are ignored.
 *   4. Non-WC taxonomy queries are ignored.
 *   5. Unknown post is ignored.
 *   6. Non-product post type is ignored.
 *   7. Post without _lf_lang is ignored.
 *   8. Source-language product is ignored.
 *   9. No source translation is ignored.
 *  10. Delegation — source product's terms are returned.
 *  11. WP_Error from source query falls back to original terms.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;
use LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate;
use ReflectionClass;

require_once __DIR__ . '/WcUnitTestCase.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/MetaDelegate.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/TaxonomyDelegate.php';

final class TaxonomyDelegateTest extends WcUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		self::reset_static_array( MetaDelegate::class, 'source_cache' );
		self::reset_static_array( MetaDelegate::class, 'delegating' );
	}

	// =========================================================================
	// Helper — call the private static is_wc_taxonomy()
	// =========================================================================

	private static function is_wc_taxonomy( string $taxonomy ): bool {
		$ref    = new ReflectionClass( TaxonomyDelegate::class );
		$method = $ref->getMethod( 'is_wc_taxonomy' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $taxonomy );
	}

	// =========================================================================
	// 1. is_wc_taxonomy — WC taxonomies
	// =========================================================================

	public function test_is_wc_taxonomy_product_cat(): void {
		$this->assertTrue( self::is_wc_taxonomy( 'product_cat' ) );
	}

	public function test_is_wc_taxonomy_product_tag(): void {
		$this->assertTrue( self::is_wc_taxonomy( 'product_tag' ) );
	}

	public function test_is_wc_taxonomy_product_type(): void {
		$this->assertTrue( self::is_wc_taxonomy( 'product_type' ) );
	}

	public function test_is_wc_taxonomy_pa_color(): void {
		$this->assertTrue( self::is_wc_taxonomy( 'pa_color' ) );
	}

	public function test_is_wc_taxonomy_pa_size(): void {
		$this->assertTrue( self::is_wc_taxonomy( 'pa_size' ) );
	}

	public function test_is_wc_taxonomy_arbitrary_pa_prefix(): void {
		$this->assertTrue( self::is_wc_taxonomy( 'pa_material_type' ) );
	}

	// =========================================================================
	// 2. is_wc_taxonomy — non-WC taxonomies
	// =========================================================================

	public function test_is_wc_taxonomy_rejects_category(): void {
		$this->assertFalse( self::is_wc_taxonomy( 'category' ) );
	}

	public function test_is_wc_taxonomy_rejects_post_tag(): void {
		$this->assertFalse( self::is_wc_taxonomy( 'post_tag' ) );
	}

	public function test_is_wc_taxonomy_rejects_custom_taxonomy(): void {
		$this->assertFalse( self::is_wc_taxonomy( 'genre' ) );
	}

	public function test_is_wc_taxonomy_rejects_partial_prefix_match(): void {
		// 'product_cat_extra' does not equal 'product_cat' — exact match required.
		$this->assertFalse( self::is_wc_taxonomy( 'product_cat_extra' ) );
	}

	// =========================================================================
	// 3. Multi-object queries are ignored
	// =========================================================================

	public function test_multi_object_query_is_not_delegated(): void {
		$original_terms = [ (object) [ 'term_id' => 1 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42, 43 ], // two object IDs
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $original_terms, $result, 'Multi-object queries must not be modified.' );
	}

	// =========================================================================
	// 4. Non-WC taxonomy
	// =========================================================================

	public function test_non_wc_taxonomy_query_is_not_delegated(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$original_terms = [ (object) [ 'term_id' => 5 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42 ],
			[ 'category' ], // non-WC taxonomy
			[]
		);

		$this->assertSame( $original_terms, $result );
	}

	// =========================================================================
	// 5. Unknown post
	// =========================================================================

	public function test_unknown_post_is_not_delegated(): void {
		$original_terms = [];
		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 99999 ],
			[ 'product_cat' ],
			[]
		);
		$this->assertSame( $original_terms, $result );
	}

	// =========================================================================
	// 6. Non-product post type
	// =========================================================================

	public function test_non_product_post_type_is_not_delegated(): void {
		$this->make_post( 42, 'page' );
		$original_terms = [ (object) [ 'term_id' => 3 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42 ],
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $original_terms, $result );
	}

	// =========================================================================
	// 7. Post without _lf_lang
	// =========================================================================

	public function test_post_without_lang_meta_is_not_delegated(): void {
		$this->make_post( 42 );
		$original_terms = [ (object) [ 'term_id' => 7 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42 ],
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $original_terms, $result );
	}

	// =========================================================================
	// 8. Source-language product
	// =========================================================================

	public function test_source_language_product_serves_own_terms(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'en' ); // source lang
		$original_terms = [ (object) [ 'term_id' => 9 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42 ],
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $original_terms, $result );
	}

	// =========================================================================
	// 9. No source translation
	// =========================================================================

	public function test_translated_product_without_source_translation_not_delegated(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// No translation map → source_id = 0 → bail.
		$original_terms = [ (object) [ 'term_id' => 11 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42 ],
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $original_terms, $result );
	}

	// =========================================================================
	// 10. Delegation — source terms returned
	// =========================================================================

	public function test_delegates_source_product_terms_for_product_cat(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$source_terms = [ (object) [ 'term_id' => 5, 'name' => 'T-shirts' ] ];
		\LfWcMocks::$object_terms[100] = $source_terms;

		$result = TaxonomyDelegate::maybe_delegate_terms(
			[], // translated product's terms (empty — none assigned)
			[ 42 ],
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $source_terms, $result );
	}

	public function test_delegates_source_product_terms_for_pa_taxonomy(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'de' );
		$this->set_translations( 42, [ 'en' => 200, 'de' => 42 ] );
		$this->make_post( 200 );

		$source_terms = [
			(object) [ 'term_id' => 10, 'slug' => 'red' ],
			(object) [ 'term_id' => 11, 'slug' => 'blue' ],
		];
		\LfWcMocks::$object_terms[200] = $source_terms;

		$result = TaxonomyDelegate::maybe_delegate_terms(
			[],
			[ 42 ],
			[ 'pa_color' ],
			[]
		);

		$this->assertSame( $source_terms, $result );
	}

	public function test_delegates_across_multiple_wc_taxonomies_in_single_query(): void {
		// WooCommerce sometimes queries multiple taxonomies at once.
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$source_terms = [ (object) [ 'term_id' => 3 ] ];
		\LfWcMocks::$object_terms[100] = $source_terms;

		$result = TaxonomyDelegate::maybe_delegate_terms(
			[],
			[ 42 ],
			[ 'product_cat', 'product_tag' ], // two WC taxonomies
			[]
		);

		$this->assertSame( $source_terms, $result );
	}

	// =========================================================================
	// 11. WP_Error fallback
	// =========================================================================

	public function test_wp_error_from_source_query_returns_original_terms(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		// Inject a WP_Error as the result for the source product's term query.
		\LfWcMocks::$object_terms[100] = new \WP_Error();

		$original_terms = [ (object) [ 'term_id' => 1 ] ];

		$result = TaxonomyDelegate::maybe_delegate_terms(
			$original_terms,
			[ 42 ],
			[ 'product_cat' ],
			[]
		);

		$this->assertSame( $original_terms, $result, 'WP_Error from source query must return the original terms.' );
	}
}
