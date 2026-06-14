<?php
/**
 * Integration tests for LinguaForge\Router\Routing\FrontPageQuery.
 *
 * FrontPageQuery hooks `get_block_templates` and swaps the base `front-page`
 * template for a language-specific variant (`front-page-{lang}`) when one exists
 * in the active block theme.
 *
 * Covered here:
 *   1. Wrong template_type ('wp_template_part') → templates returned unchanged.
 *   2. is_front_page() = false (non-root URL) → templates returned unchanged.
 *   3. No language-specific template in DB → templates returned unchanged.
 *   4. filter is idempotent when called twice (reentrancy guard works).
 *
 * Note: test 3 proves the negative (no crash, unchanged array) without inserting
 * a real wp_template CPT row — get_block_templates() hits both DB and theme files,
 * and in a plain wp-env setup there is no block theme active, so the query returns
 * [] for any slug__in lookup.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Router;
use WP_UnitTestCase;

final class FrontPageQueryIntegrationTest extends WP_UnitTestCase {

	private \LinguaForge\Router\Routing\FrontPageQuery $fpq;

	protected function setUp(): void {
		parent::setUp();
		$this->fpq = Router::get_instance()->front_page_query;
		// Reset reentrancy guard in case a previous test left it set.
		$this->reset_in_override();
	}

	protected function tearDown(): void {
		$this->reset_in_override();
		parent::tearDown();
	}

	private function reset_in_override(): void {
		$ref  = new \ReflectionClass( $this->fpq );
		$prop = $ref->getProperty( 'in_override' );
		$prop->setAccessible( true );
		$prop->setValue( $this->fpq, false );
	}

	/** Minimal fake WP_Block_Template object (only slug is checked by the filter). */
	private function fake_template( string $slug ): \WP_Block_Template {
		$t       = new \WP_Block_Template();
		$t->slug = $slug;
		return $t;
	}

	// =========================================================================
	// 1. Wrong template_type → unchanged
	// =========================================================================

	public function test_wrong_template_type_returns_templates_unchanged(): void {
		$templates = [ $this->fake_template( 'front-page' ) ];

		$result = $this->fpq->override_front_page_template(
			$templates,
			null,
			'wp_template_part' // not 'wp_template'
		);

		$this->assertSame( $templates, $result,
			'filter must be a no-op for template_type other than wp_template.' );
	}

	// =========================================================================
	// 2. is_front_page() = false → unchanged
	// =========================================================================

	public function test_non_front_page_returns_templates_unchanged(): void {
		// Navigate to a post to make is_front_page() = false.
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$templates = [ $this->fake_template( 'front-page' ) ];

		$result = $this->fpq->override_front_page_template(
			$templates,
			null,
			'wp_template'
		);

		$this->assertSame( $templates, $result,
			'filter must be a no-op when is_front_page() is false.' );
	}

	// =========================================================================
	// 3. is_front_page() = true, no lang-specific template found → unchanged
	// =========================================================================

	public function test_no_lang_template_returns_original_templates(): void {
		// Navigate to the front page.
		$this->go_to( '/' );

		$templates = [ $this->fake_template( 'front-page' ) ];

		$result = $this->fpq->override_front_page_template(
			$templates,
			null,
			'wp_template'
		);

		// get_block_templates() returns [] for 'front-page-{lang}' in plain wp-env
		// (no block theme active, no matching DB row) — $found is empty, so the
		// original $templates are returned unchanged.
		$this->assertSame( $templates, $result,
			'When no language-specific template exists, the original templates must be returned.' );
	}

	// =========================================================================
	// 4. Reentrancy guard: calling the filter while $in_override = true
	// =========================================================================

	public function test_reentrancy_guard_returns_templates_unchanged(): void {
		$this->go_to( '/' );

		// Simulate a re-entrant call by pre-setting in_override = true.
		$ref  = new \ReflectionClass( $this->fpq );
		$prop = $ref->getProperty( 'in_override' );
		$prop->setAccessible( true );
		$prop->setValue( $this->fpq, true );

		$templates = [ $this->fake_template( 'front-page' ) ];

		$result = $this->fpq->override_front_page_template(
			$templates,
			null,
			'wp_template'
		);

		$this->assertSame( $templates, $result,
			'Reentrancy guard must short-circuit and return templates unchanged.' );
	}
}
