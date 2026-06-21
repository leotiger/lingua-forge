<?php
/**
 * Integration tests for MetaBoxes — CPT exclusion and the
 * linguaforge_metabox_excluded_post_types filter.
 *
 * Covers:
 *   1. Non-excluded public CPT → Language, Template, Translations, and
 *      Source Footnotes meta boxes ARE registered.
 *   2. CPT in linguaforge_secondary_query_excluded_types option → all four
 *      LF meta boxes are NOT registered.
 *   3. CPT added to the linguaforge_metabox_excluded_post_types filter
 *      (not in the option) → all four LF meta boxes are NOT registered.
 *   4. linguaforge_metabox_excluded_post_types filter removes a type that
 *      the option had excluded → boxes ARE registered (filter wins).
 *
 * Strategy:
 *   • Register a temporary public CPT in setUp(); unregister in tearDown().
 *   • Reset $GLOBALS['wp_meta_boxes'] to an empty array before each test so
 *     boxes registered by other tests or the Router bootstrap don't pollute
 *     the assertions; the original value is restored in tearDown().
 *   • Call the MetaBoxes public methods directly via Router::get_instance()->meta_boxes
 *     rather than through do_action('add_meta_boxes'): register_hooks() is never
 *     called in the CLI test context (admin boot is gated by should_boot()), so
 *     the add_meta_boxes callbacks are not wired. Direct method calls test the
 *     exclusion logic without depending on hook registration — same reason
 *     FeatureControllerRestTest calls Registry::init() explicitly.
 *   • Flatten all registered box IDs for the test CPT from the global and
 *     assert presence or absence of each LF box ID.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Router;
use WP_UnitTestCase;

final class MetaBoxesIntegrationTest extends WP_UnitTestCase {

	private const CPT = 'lf_test_cpt';

	/** @var array<string,mixed> */
	private array $saved_meta_boxes = [];

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		// Stash any meta boxes already registered (Router bootstrap, other
		// tests) so each assertion starts from a known-empty state.
		$this->saved_meta_boxes   = $GLOBALS['wp_meta_boxes'] ?? [];
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional test isolation: reset between assertions and restored in tearDown.
		$GLOBALS['wp_meta_boxes'] = [];

		register_post_type( self::CPT, [
			'public' => true,
			'label'  => 'LF Test CPT',
		] );
	}

	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value stashed in setUp; no net change to the global.
		$GLOBALS['wp_meta_boxes'] = $this->saved_meta_boxes;
		unregister_post_type( self::CPT );
		delete_option( 'linguaforge_secondary_query_excluded_types' );
		remove_all_filters( 'linguaforge_metabox_excluded_post_types' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function fire_meta_boxes(): void {
		// Call the methods directly rather than via do_action(): register_hooks()
		// is never called in the CLI test context (admin boot is gated by
		// should_boot()), so the add_meta_boxes callbacks are not wired.
		// Calling the public methods directly tests the exclusion logic without
		// depending on hook registration — same pattern as SecondaryQueryFilterIntegrationTest.
		$mb = Router::get_instance()->meta_boxes;
		$mb->add_language_meta_box( self::CPT );
		$mb->add_template_meta_box( self::CPT );
		$mb->add_translations_meta_box( self::CPT );
		$mb->add_source_footnotes_meta_box();
	}

	/**
	 * Flatten all meta box IDs registered for self::CPT from the global.
	 *
	 * @return string[]
	 */
	private function registered_ids(): array {
		$boxes = $GLOBALS['wp_meta_boxes'][ self::CPT ] ?? [];
		$ids   = [];
		foreach ( $boxes as $context ) {
			foreach ( $context as $priority ) {
				foreach ( array_keys( $priority ) as $id ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/** @var string[] */
	private const LF_BOX_IDS = [ 'lf_lang', 'lf_page_template', 'lf_trans', 'lf_source_footnotes' ];

	// =========================================================================
	// 1. Non-excluded CPT — all LF meta boxes present
	// =========================================================================

	public function test_non_excluded_post_type_shows_all_meta_boxes(): void {
		$this->fire_meta_boxes();

		$ids = $this->registered_ids();

		foreach ( self::LF_BOX_IDS as $box_id ) {
			$this->assertContains(
				$box_id,
				$ids,
				"Meta box '{$box_id}' must be registered for a non-excluded public CPT."
			);
		}
	}

	// =========================================================================
	// 2. Excluded via option — no LF meta boxes registered
	// =========================================================================

	public function test_option_excluded_post_type_suppresses_all_meta_boxes(): void {
		update_option( 'linguaforge_secondary_query_excluded_types', self::CPT );

		$this->fire_meta_boxes();

		$ids = $this->registered_ids();

		foreach ( self::LF_BOX_IDS as $box_id ) {
			$this->assertNotContains(
				$box_id,
				$ids,
				"Meta box '{$box_id}' must not be registered for a CPT excluded via the option."
			);
		}
	}

	// =========================================================================
	// 3. Excluded via filter (type not in option) — no LF meta boxes registered
	// =========================================================================

	public function test_filter_excluded_post_type_suppresses_all_meta_boxes(): void {
		// Option is empty; exclusion comes solely from the filter.
		add_filter(
			'linguaforge_metabox_excluded_post_types',
			fn ( array $types ): array => array_merge( $types, [ self::CPT ] )
		);

		$this->fire_meta_boxes();

		$ids = $this->registered_ids();

		foreach ( self::LF_BOX_IDS as $box_id ) {
			$this->assertNotContains(
				$box_id,
				$ids,
				"Meta box '{$box_id}' must not be registered when the filter excludes the CPT."
			);
		}
	}

	// =========================================================================
	// 4. Filter un-excludes a type the option had excluded — boxes present
	// =========================================================================

	public function test_filter_can_remove_type_from_option_exclusion(): void {
		update_option( 'linguaforge_secondary_query_excluded_types', self::CPT );

		// A third-party plugin overrides the user's exclusion for this type.
		add_filter(
			'linguaforge_metabox_excluded_post_types',
			fn ( array $types ): array => array_values( array_diff( $types, [ self::CPT ] ) )
		);

		$this->fire_meta_boxes();

		$ids = $this->registered_ids();

		foreach ( self::LF_BOX_IDS as $box_id ) {
			$this->assertContains(
				$box_id,
				$ids,
				"Meta box '{$box_id}' must be registered when the filter un-excludes the CPT."
			);
		}
	}
}
