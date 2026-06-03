<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\VariationDelegate.
 *
 * Exercises the full WordPress query path:
 *   new WP_Query(['post_type' => 'product_variation', 'post_parent' => $id])
 *   → pre_get_posts action (priority 5)
 *   → VariationDelegate::maybe_delegate_variation_query()
 *   → $query->set('post_parent', $source_id) when $id is a translated product.
 *
 * Coverage:
 *   1. WP_Query for variations of a translated product returns source's variations.
 *   2. Translated product's own (non-existent) variations return empty.
 *      (Control: without VariationDelegate the translated query would return [])
 *   3. Source product query returns its own variations unchanged.
 *   4. Product without language assignment is not delegated.
 *   5. Unlinked translated product (no TRID) returns no variations (fail-safe).
 *   6. Non-variation post_type query is not affected.
 *   7. Query with zero post_parent is not affected.
 *   8. Two translated products in different groups get correct source variations.
 *   9. Array post_type containing product_variation is also delegated.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

final class VariationDelegateIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a product_variation child attached to $parent_id.
	 */
	private function make_variation( int $parent_id ): int {
		return (int) self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $parent_id,
		] );
	}

	/**
	 * Run a WP_Query for product_variation children of $parent_id.
	 * Returns post IDs found.
	 *
	 * @return int[]
	 */
	private function query_variations( int $parent_id ): array {
		$q = new \WP_Query( [
			'post_type'      => 'product_variation',
			'post_parent'    => $parent_id,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'nopaging'       => true,
			'no_found_rows'  => true,
		] );
		return array_map( 'intval', $q->posts );
	}

	// =========================================================================
	// 1. Translated product query returns source variations
	// =========================================================================

	public function test_variation_query_for_translated_parent_returns_source_variations(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$variation_id = $this->make_variation( $source_id );

		$found = $this->query_variations( $translated_id );

		$this->assertContains(
			$variation_id,
			$found,
			'WP_Query for product_variation on translated parent must return source variations.'
		);
	}

	// =========================================================================
	// 2. Without delegation the translated product would yield no results
	//    (verified by querying after temporarily removing the hook)
	// =========================================================================

	public function test_without_delegation_translated_parent_yields_no_variations(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_variation( $source_id );

		// Temporarily remove VariationDelegate from pre_get_posts.
		remove_action( 'pre_get_posts', [ \LinguaForge\AI\Integrations\WooCommerce\VariationDelegate::class, 'maybe_delegate_variation_query' ], 5 );

		$found = $this->query_variations( $translated_id );

		// Re-add so tearDown and other tests are not affected.
		add_action( 'pre_get_posts', [ \LinguaForge\AI\Integrations\WooCommerce\VariationDelegate::class, 'maybe_delegate_variation_query' ], 5 );

		$this->assertSame(
			[],
			$found,
			'Without VariationDelegate the translated product must yield no variation results (baseline confirmation).'
		);
	}

	// =========================================================================
	// 3. Source product returns its own variations unchanged
	// =========================================================================

	public function test_source_product_returns_own_variations(): void {
		[ $source_id ] = $this->make_product_pair();
		$variation_id = $this->make_variation( $source_id );

		$found = $this->query_variations( $source_id );

		$this->assertContains(
			$variation_id,
			$found,
			'Source product must return its own variations without any delegation rewrite.'
		);
	}

	// =========================================================================
	// 4. Product without language assignment is not delegated
	// =========================================================================

	public function test_product_without_lang_is_not_delegated(): void {
		$other_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$variation_id = $this->make_variation( $other_id );
		// No _lf_lang set — VariationDelegate must leave the query alone.

		$found = $this->query_variations( $other_id );

		$this->assertContains(
			$variation_id,
			$found,
			'Product without _lf_lang must return its own attached variations.'
		);
	}

	// =========================================================================
	// 5. Unlinked translated product (no TRID) returns no variations
	// =========================================================================

	public function test_unlinked_translated_product_returns_no_variations(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $post_id, self::TRANS_LANG );
		// No set_trid() call — source_id resolves to 0.

		$found = $this->query_variations( $post_id );

		$this->assertSame(
			[],
			$found,
			'Unlinked translated product must return no variations (fail-safe: no rewrite).'
		);
	}

	// =========================================================================
	// 6. Non-variation post_type query is not affected
	// =========================================================================

	public function test_non_variation_query_is_not_affected(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		unset( $translated_id );

		// Create a regular 'product' child (not a variation) of source.
		$child_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_parent' => $source_id,
		] );

		$q = new \WP_Query( [
			'post_type'     => 'product',
			'post_parent'   => $source_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );
		$found = array_map( 'intval', $q->posts );

		$this->assertContains( $child_id, $found, 'Non-variation query must not be rewritten by VariationDelegate.' );
	}

	// =========================================================================
	// 7. Query with zero post_parent is not affected
	// =========================================================================

	public function test_query_with_zero_post_parent_is_not_affected(): void {
		// Just verify the query runs without error and does not crash.
		$q = new \WP_Query( [
			'post_type'     => 'product_variation',
			'post_parent'   => 0,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );

		// No assertion on results — we only care that VariationDelegate didn't blow up.
		$this->assertIsArray( $q->posts );
	}

	// =========================================================================
	// 8. Two translated products in different groups get correct source variations
	// =========================================================================

	public function test_two_groups_do_not_bleed_variation_queries(): void {
		[ $source_a, $translated_a ] = $this->make_product_pair();
		[ $source_b, $translated_b ] = $this->make_product_pair();

		$variation_a = $this->make_variation( $source_a );
		$variation_b = $this->make_variation( $source_b );

		$found_a = $this->query_variations( $translated_a );
		$found_b = $this->query_variations( $translated_b );

		$this->assertContains( $variation_a, $found_a, 'Translated A must return group A variations.' );
		$this->assertNotContains( $variation_b, $found_a, 'Translated A must NOT return group B variations.' );

		$this->assertContains( $variation_b, $found_b, 'Translated B must return group B variations.' );
		$this->assertNotContains( $variation_a, $found_b, 'Translated B must NOT return group A variations.' );
	}

	// =========================================================================
	// 9. Array post_type containing product_variation is also delegated
	// =========================================================================

	public function test_array_post_type_with_product_variation_is_delegated(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$variation_id = $this->make_variation( $source_id );

		$q = new \WP_Query( [
			'post_type'     => [ 'product_variation', 'product' ],
			'post_parent'   => $translated_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );
		$found = array_map( 'intval', $q->posts );

		$this->assertContains(
			$variation_id,
			$found,
			'Array post_type containing product_variation must still trigger VariationDelegate rewrite.'
		);
	}

	// =========================================================================
	// 10. Translated parent with own variation children is NOT redirected to source
	// =========================================================================

	public function test_translated_parent_with_own_variations_is_not_redirected(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Source variation — would normally be served to translated parent via redirect.
		$source_variation_id = $this->make_variation( $source_id );

		// Translated variation — directly attached to the translated parent.
		$translated_variation_id = $this->make_variation( $translated_id );

		$found = $this->query_variations( $translated_id );

		// Must find the translated variation, not the source variation.
		$this->assertContains(
			$translated_variation_id,
			$found,
			'When translated parent has own variations, WP_Query must return those directly.'
		);
		$this->assertNotContains(
			$source_variation_id,
			$found,
			'When translated parent has own variations, the source variation must NOT appear in the result.'
		);
	}

	// =========================================================================
	// 11. Translated parent with own variations still falls back to source
	//     when its own variations are trashed
	// =========================================================================

	public function test_trashed_own_variation_still_redirects_to_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$source_variation_id = $this->make_variation( $source_id );

		// Create a translated variation, then trash it.
		$translated_variation_id = $this->make_variation( $translated_id );
		wp_trash_post( $translated_variation_id );

		$found = $this->query_variations( $translated_id );

		$this->assertContains(
			$source_variation_id,
			$found,
			'After trashing own translated variations, VariationDelegate must fall back to source variations.'
		);
	}
}
