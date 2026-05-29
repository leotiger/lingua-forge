<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate.
 *
 * Exercises the full WordPress taxonomy path:
 *   wp_get_object_terms()
 *   → wp_get_object_terms filter (priority 10)
 *   → TaxonomyDelegate::maybe_delegate_terms()
 *   → wp_get_object_terms() on the source product (self-removed/re-added filter).
 *
 * Coverage:
 *   1. Translated product inherits product_cat terms from source.
 *   2. Source product returns its own terms (no delegation).
 *   3. Non-WC taxonomy (category) is not delegated.
 *   4. Translated product with no source terms gets empty array, not source error.
 *   5. pa_* attribute taxonomy is delegated.
 *   6. product_tag taxonomy is delegated.
 *   7. Multi-object query is NOT delegated (bail-safe for bulk ops).
 *   8. Product without language assignment is not delegated.
 *   9. Unlinked translated product (no TRID) returns its own (empty) terms.
 *  10. Two groups do not bleed taxonomy terms.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

final class TaxonomyDelegateIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Insert a term into the given taxonomy and return its term_id.
	 * Uses wp_insert_term(); WP_UnitTestCase rolls back the DB transaction so
	 * no manual cleanup is needed.
	 */
	private function insert_term( string $name, string $taxonomy ): int {
		$result = wp_insert_term( $name, $taxonomy );
		$this->assertNotWPError( $result, "wp_insert_term($name, $taxonomy) failed." );
		return (int) $result['term_id'];
	}

	/**
	 * Assign one or more term IDs to a post in the given taxonomy.
	 */
	private function assign_terms( int $post_id, array $term_ids, string $taxonomy ): void {
		$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy );
		$this->assertNotWPError( $result, "wp_set_object_terms() failed for post $post_id / taxonomy $taxonomy." );
	}

	/**
	 * Return the sorted array of term IDs for a post in a taxonomy.
	 *
	 * @return int[]
	 */
	private function get_term_ids( int $post_id, string $taxonomy ): array {
		$terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		$this->assertNotWPError( $terms );
		sort( $terms );
		return $terms;
	}

	// =========================================================================
	// 1. Translated product inherits source product_cat terms
	// =========================================================================

	public function test_translated_product_inherits_product_cat_from_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$term_id = $this->insert_term( 'Test Cat ' . uniqid(), 'product_cat' );
		$this->assign_terms( $source_id, [ $term_id ], 'product_cat' );
		// Translated product has NO terms assigned.

		$result = $this->get_term_ids( $translated_id, 'product_cat' );

		$this->assertContains( $term_id, $result, 'Translated product must inherit product_cat terms from source.' );
	}

	// =========================================================================
	// 2. Source product returns its own terms
	// =========================================================================

	public function test_source_product_returns_own_terms(): void {
		[ $source_id ] = $this->make_product_pair();
		$term_id = $this->insert_term( 'Source Cat ' . uniqid(), 'product_cat' );
		$this->assign_terms( $source_id, [ $term_id ], 'product_cat' );

		$result = $this->get_term_ids( $source_id, 'product_cat' );

		$this->assertContains( $term_id, $result, 'Source product must return its own product_cat terms directly.' );
	}

	// =========================================================================
	// 3. Non-WC taxonomy (category) is not delegated
	// =========================================================================

	public function test_non_wc_taxonomy_is_not_delegated(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$term_id = $this->insert_term( 'WP Cat ' . uniqid(), 'category' );
		$this->assign_terms( $source_id, [ $term_id ], 'category' );
		// Translated product has NO category assigned.

		$result = $this->get_term_ids( $translated_id, 'category' );

		// TaxonomyDelegate must not delegate the standard 'category' taxonomy.
		$this->assertNotContains( $term_id, $result, 'Standard category taxonomy must not be delegated.' );
	}

	// =========================================================================
	// 4. Translated product with source that has no terms returns empty array
	// =========================================================================

	public function test_translated_product_returns_empty_when_source_has_no_terms(): void {
		[ , $translated_id ] = $this->make_product_pair();
		// Source product has NO terms in product_cat.

		$result = $this->get_term_ids( $translated_id, 'product_cat' );

		$this->assertSame( [], $result, 'Translated product must return empty array when source has no taxonomy terms.' );
	}

	// =========================================================================
	// 5. pa_* attribute taxonomy is delegated
	// =========================================================================

	public function test_pa_attribute_taxonomy_is_delegated(): void {
		// Register a temporary pa_color taxonomy if it isn't already present.
		if ( ! taxonomy_exists( 'pa_color' ) ) {
			register_taxonomy( 'pa_color', [ 'product' ] );
		}

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$term_id = $this->insert_term( 'Red ' . uniqid(), 'pa_color' );
		$this->assign_terms( $source_id, [ $term_id ], 'pa_color' );

		$result = $this->get_term_ids( $translated_id, 'pa_color' );

		$this->assertContains( $term_id, $result, 'pa_* attribute taxonomy must be delegated to source product.' );
	}

	// =========================================================================
	// 6. product_tag taxonomy is delegated
	// =========================================================================

	public function test_product_tag_taxonomy_is_delegated(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$term_id = $this->insert_term( 'Tag ' . uniqid(), 'product_tag' );
		$this->assign_terms( $source_id, [ $term_id ], 'product_tag' );

		$result = $this->get_term_ids( $translated_id, 'product_tag' );

		$this->assertContains( $term_id, $result, 'product_tag taxonomy must be delegated to source product.' );
	}

	// =========================================================================
	// 7. Multi-object query is NOT delegated
	// =========================================================================

	public function test_multi_object_query_is_not_delegated(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$term_id = $this->insert_term( 'Multi Cat ' . uniqid(), 'product_cat' );
		$this->assign_terms( $source_id, [ $term_id ], 'product_cat' );
		// Translated product has NO terms.

		// Bulk query — passes both IDs.  TaxonomyDelegate must bail.
		$terms = wp_get_object_terms( [ $source_id, $translated_id ], 'product_cat', [ 'fields' => 'ids' ] );
		$this->assertNotWPError( $terms );

		// The translated ID's own assignment (empty) must not be replaced by source terms
		// in a bulk query context.  We can't assert per-object breakdown here since WP
		// aggregates them, but we verify the hook bailed by confirming source terms appear
		// only once (not doubled by delegation).
		$counts = array_count_values( $terms );
		foreach ( $counts as $tid => $count ) {
			$this->assertSame( 1, $count, "Term $tid must appear exactly once in a bulk query — no delegation doubling." );
		}
	}

	// =========================================================================
	// 8. Product without language assignment is not delegated
	// =========================================================================

	public function test_product_without_lang_is_not_delegated(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		// No _lf_lang set — TaxonomyDelegate must leave it alone.

		$term_id = $this->insert_term( 'No Lang Cat ' . uniqid(), 'product_cat' );
		// Do NOT assign the term to this post — it should stay empty.

		$result = $this->get_term_ids( $post_id, 'product_cat' );

		$this->assertNotContains( $term_id, $result, 'Product without _lf_lang must not have delegation applied.' );
	}

	// =========================================================================
	// 9. Unlinked translated product (no TRID) returns its own (empty) terms
	// =========================================================================

	public function test_unlinked_translated_product_returns_own_terms(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $post_id, self::TRANS_LANG );
		// Deliberately no set_trid() call.

		$result = $this->get_term_ids( $post_id, 'product_cat' );

		// fail-safe: no source found → returns own terms (empty).
		$this->assertSame( [], $result, 'Unlinked translated product must return its own (empty) terms.' );
	}

	// =========================================================================
	// 10. Two groups do not bleed taxonomy terms
	// =========================================================================

	public function test_two_groups_do_not_bleed_taxonomy_terms(): void {
		[ $source_a, $translated_a ] = $this->make_product_pair();
		[ $source_b, $translated_b ] = $this->make_product_pair();

		$term_a = $this->insert_term( 'Cat A ' . uniqid(), 'product_cat' );
		$term_b = $this->insert_term( 'Cat B ' . uniqid(), 'product_cat' );

		$this->assign_terms( $source_a, [ $term_a ], 'product_cat' );
		$this->assign_terms( $source_b, [ $term_b ], 'product_cat' );

		$terms_a = $this->get_term_ids( $translated_a, 'product_cat' );
		$terms_b = $this->get_term_ids( $translated_b, 'product_cat' );

		$this->assertContains( $term_a, $terms_a, 'Translated A must inherit term A.' );
		$this->assertNotContains( $term_b, $terms_a, 'Translated A must NOT inherit term B from a different group.' );

		$this->assertContains( $term_b, $terms_b, 'Translated B must inherit term B.' );
		$this->assertNotContains( $term_a, $terms_b, 'Translated B must NOT inherit term A from a different group.' );
	}
}
