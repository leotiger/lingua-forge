<?php
/**
 * Integration tests closing AUDIT-2026-07-11 §3: a translated variable
 * product created via TranslationTrigger::create_translated_post() (the path
 * behind linguaforge_trigger_translation()/linguaforge_queue_translation() and
 * the TranslationBackfill scan) or AbstractTranslateCommand::create_trid_linked_post()
 * (the WP-CLI translate/fill_translations create path) was born with no
 * translated variation children and no WC structural taxonomies — because
 * VariationSync::maybe_sync_on_save()'s wp_after_insert_post p30 hook always
 * saw an empty _lf_lang during creation (that meta is written AFTER
 * wp_insert_post() returns in both paths) and silently bailed.
 *
 * Only PostListColumn::create_linked_post() ("Translate missing"/Sync)
 * already compensated with an explicit VariationSync call. Both gaps are now
 * closed via the shared TranslationTrigger::sync_variation_children_if_product()
 * helper, called explicitly by all three creation paths after the TRID/lang
 * meta is written.
 *
 * These tests invoke the private creation methods directly via Reflection
 * with a fabricated AI result, the same technique
 * TranslationTriggerTemplateAssignmentIntegrationTest.php and
 * AbstractTranslateCommandIntegrationTest.php already use — no AI provider
 * call is made.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\CLI\AbstractTranslateCommand;
use LinguaForge\AI\CLI\TranslateCommand;
use LinguaForge\AI\Features\TranslationTrigger;
use ReflectionMethod;

require_once __DIR__ . '/../support-wp-cli-stub.php';

final class VariationSyncOnCreateIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a source 'product' post (published, source language) with one
	 * variation attached. Mirrors VariationSyncIntegrationTest::make_source_variation()
	 * but for a standalone source product rather than a make_product_pair() pair,
	 * since these tests create the TRANSLATED product themselves via the method
	 * under test rather than via make_product_pair().
	 */
	private function make_source_product_with_variation(): int {
		$source_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $source_id, self::SOURCE_LANG );
		$this->tg->set_trid( $source_id, $this->trid() );

		self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $source_id,
		] );

		return $source_id;
	}

	/** @return int[] product_variation IDs attached to $parent_id. */
	private function get_variation_ids( int $parent_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test helper; DB state rolled back after each test.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product_variation' AND post_parent = %d AND post_status != 'trash'",
			$parent_id
		) );
		return array_map( 'intval', $ids );
	}

	// =========================================================================
	// TranslationTrigger::create_translated_post()
	// =========================================================================

	public function test_create_translated_post_syncs_variation_children_for_a_product(): void {
		$source_id = $this->make_source_product_with_variation();
		$source    = get_post( $source_id );
		$this->assertInstanceOf( \WP_Post::class, $source );

		$method = new ReflectionMethod( TranslationTrigger::class, 'create_translated_post' );
		$method->setAccessible( true );

		$new_id = $method->invoke(
			null,
			$source,
			self::TRANS_LANG,
			[ 'output' => '<p>Contenido</p>', 'translated_title' => 'Título' ],
			[]
		);

		$this->assertIsInt( $new_id, 'create_translated_post() must succeed for a variable product.' );
		$this->assertCount(
			1,
			$this->get_variation_ids( $new_id ),
			'A translated variable product created via TranslationTrigger::create_translated_post() ' .
			'must have its variation children synced immediately, not left empty until a later re-save.'
		);
	}

	public function test_create_translated_post_does_not_call_variation_sync_for_a_non_product(): void {
		// Sanity check: the shared helper must be a no-op for ordinary post
		// types, so this fix doesn't introduce a WooCommerce dependency on
		// non-product translations.
		$source_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->tg->set_lang( $source_id, self::SOURCE_LANG );
		$this->tg->set_trid( $source_id, $this->trid() );
		$source = get_post( $source_id );

		$method = new ReflectionMethod( TranslationTrigger::class, 'create_translated_post' );
		$method->setAccessible( true );

		$new_id = $method->invoke( null, $source, self::TRANS_LANG, [ 'output' => 'x' ], [] );

		$this->assertIsInt( $new_id );
		// No assertion beyond "did not fatal" — sync_variation_children_if_product()
		// checks post_type === 'product' before touching anything WC-specific.
	}

	// =========================================================================
	// AbstractTranslateCommand::create_trid_linked_post() (WP-CLI create path)
	// =========================================================================

	public function test_cli_create_path_syncs_variation_children_for_a_product(): void {
		$source_id = $this->make_source_product_with_variation();

		$cmd = new TranslateCommand();
		$ref = new ReflectionMethod( AbstractTranslateCommand::class, 'create_trid_linked_post' );
		$ref->setAccessible( true );

		$new_id = $ref->invoke(
			$cmd,
			$source_id,
			self::TRANS_LANG,
			[ 'output' => '<p>Contenido</p>', 'translated_title' => 'Título' ],
			false
		);

		$this->assertIsInt( $new_id );
		$this->assertGreaterThan( 0, $new_id, 'create_trid_linked_post() must succeed for a variable product.' );
		$this->assertCount(
			1,
			$this->get_variation_ids( $new_id ),
			'A translated variable product created via the WP-CLI translate/fill_translations ' .
			'create path must have its variation children synced immediately.'
		);
	}
}
