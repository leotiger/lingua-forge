<?php
/**
 * Integration tests for LocalAttributeTranslator::on_translation_complete().
 *
 * These tests exercise the guard conditions in a real WordPress + WooCommerce
 * environment to confirm that the method integrates correctly with real
 * get_post() / get_post_meta() / update_post_meta() calls.
 *
 * Full AI-path tests (where TermNameTranslator::translate_term_names() would be
 * called) are deferred — they require intercepting the HTTP AI call at the
 * provider layer and are tracked in AUDIT §8.1 item 13 (future work).
 *
 * Covered here:
 *   1. Source post is not a 'product' → no meta written on translated post.
 *   2. Source product has no _product_attributes meta → no meta written.
 *   3. Source product has only taxonomy (pa_*) attributes → no meta written.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\LocalAttributeTranslator;

final class LocalAttributeTranslatorIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helper
	// =========================================================================

	/**
	 * Check that update_post_meta was NOT called for _product_attributes on $translated_id
	 * by reading the meta directly from the DB (bypasses any delegation layer).
	 */
	private function assert_no_product_attributes_written( int $translated_id ): void {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion; rolled back by WP_UnitTestCase transaction.
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_product_attributes'",
			$translated_id
		) );
		$this->assertSame( 0, $count, '_product_attributes must not be written when guard fires.' );
	}

	// =========================================================================
	// 1. Source is not a product
	// =========================================================================

	public function test_early_return_when_source_is_not_product(): void {
		$source_id = self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		] );
		$translated_id = $this->make_product( self::TRANS_LANG, $this->trid() );

		LocalAttributeTranslator::on_translation_complete( $translated_id, $source_id, self::TRANS_LANG );

		$this->assert_no_product_attributes_written( $translated_id );
	}

	// =========================================================================
	// 2. Source product has no _product_attributes
	// =========================================================================

	public function test_early_return_when_no_product_attributes_meta(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		// No _product_attributes set on source.

		LocalAttributeTranslator::on_translation_complete( $translated_id, $source_id, self::TRANS_LANG );

		$this->assert_no_product_attributes_written( $translated_id );
	}

	// =========================================================================
	// 3. All attributes are taxonomy-backed (is_taxonomy=1)
	// =========================================================================

	public function test_early_return_when_all_attributes_are_taxonomy(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		update_post_meta( $source_id, '_product_attributes', [
			'pa_color' => [
				'name'         => 'pa_color',
				'value'        => '',
				'position'     => 0,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 1,
			],
		] );

		LocalAttributeTranslator::on_translation_complete( $translated_id, $source_id, self::TRANS_LANG );

		$this->assert_no_product_attributes_written( $translated_id );
	}
}
