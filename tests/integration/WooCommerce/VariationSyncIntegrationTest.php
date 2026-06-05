<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\VariationSync.
 *
 * Verifies that sync_variations_for() correctly creates translated
 * product_variation children, wires TRID groups, copies attribute meta,
 * and is idempotent.
 *
 * Coverage:
 *   1.  Translated variations are created for each source variation.
 *   2.  Source variation receives _lf_lang and _lf_trid when previously unset.
 *   3.  Translated variation has correct post_parent (translated product).
 *   4.  Translated variation has correct _lf_lang (translated language).
 *   5.  Translated variation shares _lf_trid with source variation.
 *   6.  Translated variation copies post_content from source variation.
 *   7.  Translated variation copies _attribute_pa_* meta from source variation.
 *   8.  Running sync twice does not create duplicate variations (idempotent).
 *   9.  Source product (not translated) — sync is a no-op.
 *  10.  Product with no source variations — sync is a no-op.
 *  11.  MetaDelegate delegates _price from source variation to translated variation.
 *  12.  Multiple source variations each get a translated counterpart.
 *  13.  maybe_sync_on_save() fires via wp_after_insert_post when post is a translated product.
 *  14.  maybe_sync_on_save() does NOT fire for product_variation post type.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;
use LinguaForge\AI\Integrations\WooCommerce\VariationSync;

final class VariationSyncIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a source product_variation attached to $parent_id.
	 *
	 * @param string $desc   Value for _variation_description (WC's actual description meta key).
	 * @param string $color  Value for attribute_pa_color (WC uses attribute_ prefix, no underscore).
	 */
	private function make_source_variation(
		int    $parent_id,
		string $desc  = '',
		string $color = ''
	): int {
		$var_id = (int) self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $parent_id,
			// post_content is always '' on WC variation posts — description lives in _variation_description.
		] );

		if ( '' !== $desc ) {
			update_post_meta( $var_id, '_variation_description', $desc );
		}
		if ( '' !== $color ) {
			// WC stores variation attribute meta as attribute_pa_color (prefix attribute_, no leading _).
			update_post_meta( $var_id, 'attribute_pa_color', $color );
		}

		return $var_id;
	}

	/**
	 * Return all product_variation IDs directly attached to $parent_id.
	 *
	 * @return int[]
	 */
	private function get_variation_ids( int $parent_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test helper; no WP cache group for this cross-table lookup; DB state rolled back after each test.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product_variation' AND post_parent = %d AND post_status != 'trash'",
			$parent_id
		) );
		return array_map( 'intval', $ids );
	}

	// =========================================================================
	// 1. Translated variations are created
	// =========================================================================

	public function test_sync_creates_translated_variation_for_source_variation(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );

		$trans_vars = $this->get_variation_ids( $translated_id );
		$this->assertCount( 1, $trans_vars, 'sync_variations_for() must create one translated variation.' );
	}

	// =========================================================================
	// 2. Source variation gets _lf_lang and _lf_trid
	// =========================================================================

	public function test_sync_assigns_lang_and_trid_to_source_variation(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$source_var_id = $this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );

		$this->assertSame(
			self::SOURCE_LANG,
			get_post_meta( $source_var_id, '_lf_lang', true ),
			'Source variation must have _lf_lang = source language after sync.'
		);
		$this->assertNotEmpty(
			get_post_meta( $source_var_id, '_lf_trid', true ),
			'Source variation must have a _lf_trid after sync.'
		);
	}

	// =========================================================================
	// 3. Translated variation has correct post_parent
	// =========================================================================

	public function test_translated_variation_has_translated_product_as_parent(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = $this->get_variation_ids( $translated_id );
		$this->assertCount( 1, $trans_var_ids );

		$trans_var = get_post( $trans_var_ids[0] );
		$this->assertInstanceOf( \WP_Post::class, $trans_var );
		$this->assertSame(
			$translated_id,
			(int) $trans_var->post_parent,
			'Translated variation must be attached to the translated product, not the source.'
		);
	}

	// =========================================================================
	// 4. Translated variation has correct _lf_lang
	// =========================================================================

	public function test_translated_variation_has_correct_lang(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = $this->get_variation_ids( $translated_id );
		$this->assertSame(
			self::TRANS_LANG,
			get_post_meta( $trans_var_ids[0], '_lf_lang', true ),
			'Translated variation must carry the target language code.'
		);
	}

	// =========================================================================
	// 5. Translated variation shares _lf_trid with source variation
	// =========================================================================

	public function test_translated_variation_shares_trid_with_source_variation(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$source_var_id = $this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );

		$source_trid = get_post_meta( $source_var_id, '_lf_trid', true );
		$trans_var_ids = $this->get_variation_ids( $translated_id );

		$this->assertNotEmpty( $source_trid, 'Source variation must have a TRID after sync.' );
		$this->assertSame(
			$source_trid,
			get_post_meta( $trans_var_ids[0], '_lf_trid', true ),
			'Translated variation must share the source variation\'s TRID.'
		);
	}

	// =========================================================================
	// 6. Translated variation copies _variation_description from source
	// =========================================================================

	public function test_translated_variation_copies_variation_description(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		// WC stores variation descriptions in _variation_description meta, NOT post_content.
		$this->make_source_variation( $source_id, 'Water-based dye; may run slightly smaller.' );

		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = $this->get_variation_ids( $translated_id );

		$this->assertSame(
			'Water-based dye; may run slightly smaller.',
			get_post_meta( $trans_var_ids[0], '_variation_description', true ),
			'Translated variation must copy _variation_description (WC description meta key) from source.'
		);

		// post_content must remain empty — WC always sets it to \'\'\' on variation posts.
		$trans_var = get_post( $trans_var_ids[0] );
		$this->assertSame( '', $trans_var->post_content, 'post_content must be empty on variation posts as WC expects.' );
	}

	// =========================================================================
	// 7. Translated variation copies attribute_pa_* meta (WC prefix, no leading _)
	// =========================================================================

	public function test_translated_variation_copies_attribute_meta(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		// WC stores attribute meta as attribute_pa_color (attribute_ prefix, no leading underscore).
		$this->make_source_variation( $source_id, '', 'red' );

		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = $this->get_variation_ids( $translated_id );

		$this->assertSame(
			'red',
			get_post_meta( $trans_var_ids[0], 'attribute_pa_color', true ),
			'attribute_pa_color (no leading underscore) must be set for WC find_matching_product_variation().'
		);
		$this->assertSame(
			'',
			get_post_meta( $trans_var_ids[0], '_attribute_pa_color', true ),
			'_attribute_pa_color (with leading underscore) must NOT be set — not a valid WC attribute meta key.'
		);
	}

	// =========================================================================
	// 8. Idempotency — running sync twice creates no duplicates
	// =========================================================================

	public function test_sync_is_idempotent(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );
		VariationSync::sync_variations_for( $translated_id ); // Second call.

		$trans_vars = $this->get_variation_ids( $translated_id );
		$this->assertCount(
			1,
			$trans_vars,
			'sync_variations_for() called twice must not create duplicate translated variations.'
		);
	}

	// =========================================================================
	// 9. Source product — sync is a no-op
	// =========================================================================

	public function test_sync_is_noop_for_source_product(): void {
		[ $source_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $source_id );

		// The source product's own variation must still be there (unchanged);
		// no additional variations should have been created.
		$source_vars = $this->get_variation_ids( $source_id );
		$this->assertCount( 1, $source_vars, 'sync_variations_for() must be a no-op for source products.' );
	}

	// =========================================================================
	// 10. Simple product with no source variations — sync is a no-op
	// =========================================================================

	public function test_sync_is_noop_when_no_source_variations(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		// No variations on source product.

		VariationSync::sync_variations_for( $translated_id );

		$trans_vars = $this->get_variation_ids( $translated_id );
		$this->assertCount( 0, $trans_vars, 'sync_variations_for() must be a no-op for simple products.' );
	}

	// =========================================================================
	// 11. MetaDelegate delegates _price from source variation to translated variation
	// =========================================================================

	public function test_meta_delegate_serves_price_from_source_variation(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$source_var_id = $this->make_source_variation( $source_id );
		update_post_meta( $source_var_id, '_price', '29.99' );

		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = $this->get_variation_ids( $translated_id );
		$this->assertCount( 1, $trans_var_ids );

		$price = get_post_meta( $trans_var_ids[0], '_price', true );
		$this->assertSame(
			'29.99',
			$price,
			'MetaDelegate must serve _price from the source variation to the translated variation.'
		);
	}

	// =========================================================================
	// 12. Multiple source variations each get a translated counterpart
	// =========================================================================

	public function test_multiple_source_variations_each_get_translated_counterpart(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id, 'Red variant', 'red' );
		$this->make_source_variation( $source_id, 'Blue variant', 'blue' );
		$this->make_source_variation( $source_id, 'Green variant', 'green' );

		VariationSync::sync_variations_for( $translated_id );

		$trans_vars = $this->get_variation_ids( $translated_id );
		$this->assertCount(
			3,
			$trans_vars,
			'Each source variation must get exactly one translated counterpart.'
		);
	}

	// =========================================================================
	// 13. maybe_sync_on_save() fires via wp_after_insert_post for translated product
	// =========================================================================

	public function test_maybe_sync_on_save_fires_for_translated_product(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation( $source_id );

		// Simulate a wp_after_insert_post callback by calling the handler directly
		// after the translated post has _lf_lang and _lf_trid set (as Sync would do).
		$translated_post = get_post( $translated_id );
		$this->assertNotNull( $translated_post );

		// No variations yet.
		$this->assertCount( 0, $this->get_variation_ids( $translated_id ) );

		VariationSync::maybe_sync_on_save( $translated_id, $translated_post );

		$this->assertCount(
			1,
			$this->get_variation_ids( $translated_id ),
			'maybe_sync_on_save() must create translated variations for a translated product.'
		);
	}

	// =========================================================================
	// 14. maybe_sync_on_save() is a no-op for product_variation post type
	// =========================================================================

	// =========================================================================
	// 15–16. sync_wc_taxonomies_from_source() — taxonomy propagation
	//        §6.0.1 Low (VariationSync.php, 79%)
	//
	// sync_variations_for() calls sync_wc_taxonomies_from_source() internally,
	// but only exercises the product_type and pa_* paths indirectly. These
	// tests call the public static method directly so coverage is explicit and
	// the individual taxonomy paths are each verified.
	// =========================================================================

	/**
	 * 15. product_type term physically written to translated product by sync.
	 *
	 * TaxonomyDelegate's wp_get_object_terms filter delegates the source's terms
	 * to translated products at query time, so we cannot use wp_get_object_terms()
	 * to distinguish "physically assigned" from "delegated at runtime".
	 *
	 * Strategy:
	 *   1. Pre-condition checked BEFORE assigning terms to source — delegation
	 *      returns empty because the source has no terms yet.
	 *   2. Assign term to source, then call sync.
	 *   3. Post-condition: temporarily remove TaxonomyDelegate filter and verify
	 *      the term is physically present in wp_term_relationships for the
	 *      translated product — not just delegated at query time.
	 */
	public function test_sync_wc_taxonomies_propagates_product_type(): void {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			register_taxonomy( 'product_type', [ 'product' ] );
		}

		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Pre-condition: source has no terms yet → delegation returns empty for translated.
		$before = wp_get_object_terms( $translated_id, 'product_type', [ 'fields' => 'ids' ] );
		$this->assertSame( [], $before, 'Pre-condition: source has no product_type yet, so delegation must return empty.' );

		// Assign a product_type term to the source.
		$term = wp_insert_term( 'simple-' . uniqid(), 'product_type' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		wp_set_object_terms( $source_id, [ $term_id ], 'product_type' );

		// Run the sync — must physically write the term to the translated product.
		VariationSync::sync_wc_taxonomies_from_source( $source_id, $translated_id );

		// Post-condition: bypass TaxonomyDelegate to confirm physical assignment.
		remove_filter( 'wp_get_object_terms', [ \LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate::class, 'maybe_delegate_terms' ], 10 );
		$after = wp_get_object_terms( $translated_id, 'product_type', [ 'fields' => 'ids' ] );
		add_filter( 'wp_get_object_terms', [ \LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate::class, 'maybe_delegate_terms' ], 10, 4 );

		$this->assertNotWPError( $after );
		$this->assertContains(
			$term_id,
			$after,
			'sync_wc_taxonomies_from_source() must physically write the product_type term to wp_term_relationships for the translated product.'
		);
	}

	/**
	 * 16. pa_* attribute taxonomy terms physically written to translated product.
	 *
	 * Same strategy as test 15: pre-condition before source has terms, then
	 * bypass TaxonomyDelegate in the post-condition to verify physical assignment.
	 */
	public function test_sync_wc_taxonomies_propagates_pa_attribute_terms(): void {
		if ( ! taxonomy_exists( 'pa_color' ) ) {
			register_taxonomy( 'pa_color', [ 'product' ] );
		}

		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Pre-condition: source has no pa_color terms yet → delegation returns empty.
		$before = wp_get_object_terms( $translated_id, 'pa_color', [ 'fields' => 'ids' ] );
		$this->assertSame( [], $before, 'Pre-condition: source has no pa_color yet, so delegation must return empty.' );

		// Assign a pa_color term to the source.
		$term = wp_insert_term( 'Red-' . uniqid(), 'pa_color' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		wp_set_object_terms( $source_id, [ $term_id ], 'pa_color' );

		// Run the sync.
		VariationSync::sync_wc_taxonomies_from_source( $source_id, $translated_id );

		// Post-condition: bypass TaxonomyDelegate to confirm physical assignment.
		remove_filter( 'wp_get_object_terms', [ \LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate::class, 'maybe_delegate_terms' ], 10 );
		$after = wp_get_object_terms( $translated_id, 'pa_color', [ 'fields' => 'ids' ] );
		add_filter( 'wp_get_object_terms', [ \LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate::class, 'maybe_delegate_terms' ], 10, 4 );

		$this->assertNotWPError( $after );
		$this->assertContains(
			$term_id,
			$after,
			'sync_wc_taxonomies_from_source() must physically write pa_* terms to wp_term_relationships for the translated product.'
		);
	}

	// =========================================================================
	// 14. maybe_sync_on_save() is a no-op for product_variation post type
	// =========================================================================

	public function test_maybe_sync_on_save_ignores_variation_post_type(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$source_var_id = $this->make_source_variation( $source_id );

		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = $this->get_variation_ids( $translated_id );
		$this->assertCount( 1, $trans_var_ids );

		// Calling maybe_sync_on_save with a product_variation post must be a no-op.
		$trans_var_post = get_post( $trans_var_ids[0] );
		$this->assertNotNull( $trans_var_post );

		$count_before = $this->get_variation_ids( $translated_id );
		VariationSync::maybe_sync_on_save( $trans_var_ids[0], $trans_var_post );
		$count_after = $this->get_variation_ids( $translated_id );

		$this->assertSame(
			count( $count_before ),
			count( $count_after ),
			'maybe_sync_on_save() must be a no-op when called for a product_variation post.'
		);

		unset( $source_var_id ); // Suppress unused-variable warning.
	}
}
