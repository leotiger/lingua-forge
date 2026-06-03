<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\VariationDelegate.
 *
 * VariationDelegate hooks pre_get_posts at priority 5.  Tests call
 * maybe_delegate_variation_query() directly with a WP_Query stub.
 *
 * The callback modifies $query->post_parent in place to point at the source
 * product, so assertions check $query->get('post_parent') before and after.
 *
 * Coverage:
 *   1. Non-variation query is ignored (post_parent unchanged).
 *   2. Variation query with no post_parent (≤ 0) is ignored.
 *   3. Unknown parent post is ignored.
 *   4. Non-product parent post type is ignored.
 *   5. Parent without _lf_lang meta is ignored.
 *   6. Source-language parent is ignored.
 *   7. Parent with no source translation is ignored.
 *   8. Translated parent → post_parent substituted with source product ID.
 *   9. Array post_type including 'product_variation' is handled.
 *  10. Translated parent with own variation children is NOT redirected.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;
use LinguaForge\AI\Integrations\WooCommerce\VariationDelegate;

require_once __DIR__ . '/WcUnitTestCase.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/MetaDelegate.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/VariationDelegate.php';

final class VariationDelegateTest extends WcUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		self::reset_static_array( MetaDelegate::class, 'source_cache' );
		self::reset_static_array( MetaDelegate::class, 'delegating' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function make_query( string|array $post_type, int $post_parent = 0 ): \WP_Query {
		$query = new \WP_Query();
		$query->set( 'post_type', $post_type );
		if ( $post_parent > 0 ) {
			$query->set( 'post_parent', $post_parent );
		}
		return $query;
	}

	// =========================================================================
	// 1. Non-variation query
	// =========================================================================

	public function test_non_variation_query_is_ignored(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$query = $this->make_query( 'product', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );

		$this->assertSame( 42, (int) $query->get( 'post_parent' ), 'Non-variation query must not be modified.' );
	}

	public function test_post_query_with_variation_parent_is_ignored(): void {
		$this->make_post( 42 );
		$query = $this->make_query( 'post', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 42, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 2. No post_parent
	// =========================================================================

	public function test_variation_query_with_zero_post_parent_is_ignored(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'product_variation' );
		// post_parent defaults to '' → (int)'' = 0 → bail.

		VariationDelegate::maybe_delegate_variation_query( $query );

		$this->assertSame( 0, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 3. Unknown parent post
	// =========================================================================

	public function test_unknown_parent_post_is_ignored(): void {
		$query = $this->make_query( 'product_variation', 99999 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 99999, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 4. Non-product parent
	// =========================================================================

	public function test_non_product_parent_post_type_is_ignored(): void {
		$this->make_post( 42, 'page' );
		$query = $this->make_query( 'product_variation', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 42, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 5. Parent without _lf_lang
	// =========================================================================

	public function test_parent_without_lang_meta_is_ignored(): void {
		$this->make_post( 42 );
		// No _lf_lang meta.
		$query = $this->make_query( 'product_variation', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 42, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 6. Source-language parent
	// =========================================================================

	public function test_source_language_parent_is_ignored(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'en' ); // source lang
		$query = $this->make_query( 'product_variation', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 42, (int) $query->get( 'post_parent' ), 'Source-language parent serves own variations.' );
	}

	// =========================================================================
	// 7. No source translation
	// =========================================================================

	public function test_translated_parent_with_no_source_translation_is_ignored(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		// No translation map → source_id = 0 → bail safely.
		$query = $this->make_query( 'product_variation', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 42, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 8. Delegation — post_parent substituted with source product ID
	// =========================================================================

	public function test_translated_parent_is_substituted_with_source_product_id(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$query = $this->make_query( 'product_variation', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );

		$this->assertSame( 100, (int) $query->get( 'post_parent' ), 'post_parent must be repointed to source product 100.' );
	}

	public function test_source_product_id_is_unchanged_when_already_source(): void {
		// Double-delegation guard: if a query already targets the source, leave it alone.
		$this->make_post( 100 );
		$this->set_meta( 100, '_lf_lang', 'en' ); // source lang
		$query = $this->make_query( 'product_variation', 100 );
		VariationDelegate::maybe_delegate_variation_query( $query );
		$this->assertSame( 100, (int) $query->get( 'post_parent' ) );
	}

	// =========================================================================
	// 9. Array post_type including 'product_variation'
	// =========================================================================

	public function test_array_post_type_containing_product_variation_is_handled(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		$query = $this->make_query( [ 'product', 'product_variation' ], 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );

		$this->assertSame( 100, (int) $query->get( 'post_parent' ), 'Array post_type with product_variation must trigger delegation.' );
	}

	// =========================================================================
	// 10. Translated parent with own variation children is NOT redirected
	// =========================================================================

	public function test_translated_parent_with_own_variations_is_not_redirected(): void {
		$this->make_post( 42 );
		$this->set_meta( 42, '_lf_lang', 'es' );
		$this->set_translations( 42, [ 'en' => 100, 'es' => 42 ] );
		$this->make_post( 100 );

		// Simulate $wpdb->get_var() returning a row — own variations exist.
		\LfWcMocks::$wpdb_get_var = '1';

		$query = $this->make_query( 'product_variation', 42 );
		VariationDelegate::maybe_delegate_variation_query( $query );

		$this->assertSame(
			42,
			(int) $query->get( 'post_parent' ),
			'Translated parent with own variations must NOT have post_parent rewritten to source.'
		);
	}
}
