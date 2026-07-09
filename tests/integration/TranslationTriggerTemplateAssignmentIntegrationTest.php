<?php
/**
 * Integration tests for template auto-assignment on
 * TranslationTrigger::create_translated_post().
 *
 * Bug: create_translated_post() unhooks Sync::handle_save_post() around
 * wp_insert_post() (so trid/lang aren't re-derived mid-insert), but — unlike
 * its three siblings (normal editor save via handle_save_post() itself, the
 * WP-CLI translate/retranslate commands, and the post-list "Sync" button) —
 * never called the compensating Sync::assign_template_if_needed() afterward.
 * A post created through this path (the one linguaforge_trigger_translation()
 * / linguaforge_queue_translation() actually drives — i.e. every third-party
 * integration, e.g. Agnosis) was left with no _wp_page_template at all, so
 * WordPress fell through to the untranslated default template hierarchy even
 * when a matching single-{post_type}-{lang} FSE template existed.
 *
 * These tests invoke the private create_translated_post() directly via
 * Reflection, mirroring TranslationTriggerMetaFilterIntegrationTest's
 * approach, so they exercise the real method without needing an AI provider.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Features\TranslationTrigger;
use LinguaForge\Router\Router;
use ReflectionMethod;
use WP_UnitTestCase;

final class TranslationTriggerTemplateAssignmentIntegrationTest extends WP_UnitTestCase {

	private int $source_id;

	protected function setUp(): void {
		parent::setUp();

		// Pin the primary language so target-vs-source comparisons in these
		// tests don't depend on the ambient site locale.
		update_option( 'linguaforge_primary_language', 'en' );

		$this->source_id = (int) self::factory()->post->create( [
			'post_title'   => 'Hello World',
			'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		] );
	}

	protected function tearDown(): void {
		delete_option( 'linguaforge_primary_language' );
		parent::tearDown();
	}

	/**
	 * Invoke the private create_translated_post() with a fabricated AI result.
	 *
	 * @param string              $target_lang
	 * @param array<string,mixed> $result
	 * @return int|\WP_Error
	 */
	private function create_translation( string $target_lang, array $result = [] ) {
		$source = get_post( $this->source_id );

		$method = new ReflectionMethod( TranslationTrigger::class, 'create_translated_post' );
		$method->setAccessible( true );

		return $method->invoke(
			null,
			$source,
			$target_lang,
			$result + [ 'output' => '<p>Hola mundo</p>', 'translated_title' => 'Hola Mundo' ],
			[]
		);
	}

	public function test_language_specific_template_is_assigned_on_creation(): void {
		$new_id = $this->create_translation( 'es' );

		$this->assertIsInt( $new_id );
		$this->assertSame( 'single-es', get_post_meta( $new_id, '_wp_page_template', true ) );

		// assign_template_if_needed() also records the tracking key so a later
		// language change can tell an auto-assigned template apart from one the
		// editor picked explicitly.
		$this->assertSame( 'single-es', get_post_meta( $new_id, '_lf_auto_template', true ) );
	}

	public function test_resolved_template_matches_router_resolve_template_for_lang(): void {
		$new_id = $this->create_translation( 'de' );

		$new_post = get_post( $new_id );
		$expected = Router::get_instance()->sync->resolve_template_for_lang( $new_post, 'de' );

		$this->assertSame( $expected, get_post_meta( $new_id, '_wp_page_template', true ) );
	}

	public function test_no_template_assigned_when_target_equals_source_language(): void {
		// Degenerate case (not a real caller path, since TranslationTrigger::run()
		// rejects target_lang === source language before reaching this method) —
		// included to pin resolve_template_for_lang()'s null-return contract:
		// assign_template_if_needed() must be a no-op rather than writing 'default'
		// or an empty string.
		$new_id = $this->create_translation( 'en' );

		$this->assertIsInt( $new_id );
		$this->assertSame( '', (string) get_post_meta( $new_id, '_wp_page_template', true ) );
		$this->assertSame( '', (string) get_post_meta( $new_id, '_lf_auto_template', true ) );
	}

	public function test_trid_is_already_written_when_template_assignment_runs(): void {
		// Regression guard for the ordering fix: assign_template_if_needed() must
		// run AFTER _lf_trid/_lf_lang are persisted on the new post, because
		// resolve_template_for_lang()'s front-page-translation branch reads this
		// post's trid back via get_trid( $post->ID ). If the template call ran
		// immediately after wp_insert_post() (before those meta writes), that
		// read would see an empty trid on every first-time translation.
		$new_id = $this->create_translation( 'fr' );

		$this->assertNotSame( '', get_post_meta( $new_id, '_lf_trid', true ) );
		$this->assertSame(
			get_post_meta( $this->source_id, '_lf_trid', true ),
			get_post_meta( $new_id, '_lf_trid', true )
		);
		// And the template was still resolved correctly despite depending on it.
		$this->assertSame( 'single-fr', get_post_meta( $new_id, '_wp_page_template', true ) );
	}
}
