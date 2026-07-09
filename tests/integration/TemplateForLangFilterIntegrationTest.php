<?php
/**
 * Integration tests for the `linguaforge_template_for_lang` filter.
 *
 * Lets a third-party integration override the language-specific FSE template
 * slug that Sync::resolve_template_for_lang() is about to hand back for
 * assignment. Applied once, inside resolve_template_for_lang() itself, so it
 * covers every path that assigns a template (editor save, WP-CLI, the Sync
 * button, and programmatic creation via linguaforge_trigger_translation() /
 * linguaforge_queue_translation()) rather than only one of them.
 *
 * Verifies:
 *   • the filter can override the resolved slug for a CPT, for 'post', and
 *     for the plain 'page' branch;
 *   • the filter never fires when the target language equals the source
 *     language (the null-return branch is a deliberate no-override zone —
 *     see the docblock on resolve_template_for_lang());
 *   • the filter receives the slug LF computed, the post, and the language;
 *   • returning '' or null from the filter suppresses assignment entirely,
 *     identically to the built-in "no template" case.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Router;
use WP_UnitTestCase;

final class TemplateForLangFilterIntegrationTest extends WP_UnitTestCase {

	private int $post_id;
	private int $page_id;

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', 'en' );

		$this->post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->page_id = (int) self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		] );
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_template_for_lang' );
		delete_option( 'linguaforge_primary_language' );
		parent::tearDown();
	}

	private function sync() {
		return Router::get_instance()->sync;
	}

	public function test_filter_overrides_resolved_slug_for_a_post(): void {
		add_filter(
			'linguaforge_template_for_lang',
			static function () {
				return 'custom-single-es';
			}
		);

		$result = $this->sync()->resolve_template_for_lang( get_post( $this->post_id ), 'es' );

		$this->assertSame( 'custom-single-es', $result );
	}

	public function test_filter_overrides_resolved_slug_for_a_page(): void {
		add_filter(
			'linguaforge_template_for_lang',
			static function () {
				return 'custom-page-de';
			}
		);

		$result = $this->sync()->resolve_template_for_lang( get_post( $this->page_id ), 'de' );

		$this->assertSame( 'custom-page-de', $result );
	}

	public function test_filter_does_not_fire_for_source_language(): void {
		$called = false;

		add_filter(
			'linguaforge_template_for_lang',
			static function ( $resolved ) use ( &$called ) {
				$called = true;
				return $resolved;
			}
		);

		$result = $this->sync()->resolve_template_for_lang( get_post( $this->post_id ), 'en' );

		$this->assertNull( $result );
		$this->assertFalse( $called, 'linguaforge_template_for_lang must not fire for the source-language post.' );
	}

	public function test_filter_receives_computed_slug_post_and_lang(): void {
		$captured = [];

		add_filter(
			'linguaforge_template_for_lang',
			static function ( $resolved, $post, $lang ) use ( &$captured ) {
				$captured = [ $resolved, $post->ID, $lang ];
				return $resolved;
			},
			10,
			3
		);

		$this->sync()->resolve_template_for_lang( get_post( $this->post_id ), 'fr' );

		$this->assertSame( [ 'single-fr', $this->post_id, 'fr' ], $captured );
	}

	public function test_empty_string_from_filter_suppresses_assignment(): void {
		add_filter(
			'linguaforge_template_for_lang',
			static function () {
				return '';
			}
		);

		$result = $this->sync()->resolve_template_for_lang( get_post( $this->post_id ), 'es' );

		$this->assertNull( $result );
	}

	public function test_null_from_filter_suppresses_assignment(): void {
		add_filter(
			'linguaforge_template_for_lang',
			static function () {
				return null;
			}
		);

		$result = $this->sync()->resolve_template_for_lang( get_post( $this->post_id ), 'es' );

		$this->assertNull( $result );
	}

	public function test_unhooked_behaviour_is_unchanged(): void {
		$result = $this->sync()->resolve_template_for_lang( get_post( $this->post_id ), 'es' );

		$this->assertSame( 'single-es', $result );
	}
}
