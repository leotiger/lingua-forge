<?php
/**
 * Integration tests for LinguaForge\MetaDescription\Module.
 *
 * Module is the meta-description sub-module (meta-description/meta-description.php).
 * It manages the `_linguaforge_meta_description` post-meta key, the classic editor
 * meta box, and the wp_head output filter. This file is distinct from
 * ai/includes/Features/MetaDescription.php (the AI generation feature).
 *
 * Coverage targets (module at 2%):
 *   1. get() — returns stored custom description.
 *   2. get() — returns empty string when no meta is set.
 *   3. save() — stores description when valid nonce + capability + value.
 *   4. save() — deletes meta when empty value submitted (delete-on-empty contract).
 *   5. output_tags() — outputs three <meta> tags using bloginfo fallback when
 *      not on a singular page (the CLI / non-frontend path).
 *
 * Design notes:
 *   • Module::init() is called at file-load time; in the integration env the
 *     plugin has already booted, so all hooks are registered.
 *   • save() requires $_POST nonce + current user. An administrator is set in
 *     setUp() and nonces are created via wp_create_nonce().
 *   • output_tags() is captured via output buffering.
 *   • WP_UnitTestCase transaction rollback handles post/meta cleanup.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\MetaDescription\Module;
use WP_UnitTestCase;

final class MetaDescriptionModuleIntegrationTest extends WP_UnitTestCase {

	/** The stored meta key — mirrors the class constant so tests stay readable. */
	private const META_KEY = '_linguaforge_meta_description';

	protected function setUp(): void {
		parent::setUp();

		// save() checks current_user_can('edit_post') — set an administrator.
		$user_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
	}

	protected function tearDown(): void {
		// Clean up any POST fields set by save() tests.
		unset( $_POST[ Module::NONCE_FIELD ], $_POST[ Module::INPUT_FIELD ] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// 1. get() — stored custom description returned
	// =========================================================================

	public function test_get_returns_stored_custom_description(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, self::META_KEY, 'Solar energy solutions for homes and businesses.' );

		$result = Module::get( $post_id );

		$this->assertSame(
			'Solar energy solutions for homes and businesses.',
			$result,
			'get() must return the stored custom meta description.'
		);
	}

	// =========================================================================
	// 2. get() — empty string when no meta set
	// =========================================================================

	public function test_get_returns_empty_string_when_no_meta(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		// No meta stored.

		$result = Module::get( $post_id );

		$this->assertSame( '', $result, 'get() must return an empty string when no meta is stored.' );
	}

	// =========================================================================
	// 3. save() — stores description with valid nonce + capability
	// =========================================================================

	public function test_save_stores_description_with_valid_nonce(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$_POST[ Module::NONCE_FIELD ] = wp_create_nonce( Module::NONCE_ACTION );
		$_POST[ Module::INPUT_FIELD ] = 'Install solar panels today and cut energy bills by 50%.';

		Module::save( $post_id );

		$stored = get_post_meta( $post_id, self::META_KEY, true );
		$this->assertSame(
			'Install solar panels today and cut energy bills by 50%.',
			$stored,
			'save() must write the submitted value to post meta.'
		);
	}

	// =========================================================================
	// 4. save() — deletes meta when empty value submitted
	// =========================================================================

	/**
	 * Empty submission deletes the stored meta so the fallback chain (excerpt →
	 * site description) is re-activated. This is the "reset to default" contract.
	 */
	public function test_save_deletes_meta_when_value_is_empty(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, self::META_KEY, 'Previous custom description.' );

		$_POST[ Module::NONCE_FIELD ] = wp_create_nonce( Module::NONCE_ACTION );
		$_POST[ Module::INPUT_FIELD ] = '';

		Module::save( $post_id );

		$stored = get_post_meta( $post_id, self::META_KEY, true );
		$this->assertSame(
			'',
			$stored,
			'save() must delete the post meta when an empty value is submitted.'
		);
	}

	// =========================================================================
	// 5. output_tags() — bloginfo fallback when not on a singular page
	// =========================================================================

	/**
	 * In the CLI / integration test context is_singular() returns false, so
	 * output_tags() reaches the get_bloginfo('description') fallback and outputs
	 * all three <meta> tags when a site description is configured.
	 */
	public function test_output_tags_outputs_meta_tags_using_bloginfo_fallback(): void {
		update_option( 'blogdescription', 'Renewable energy for a sustainable future.' );

		ob_start();
		Module::output_tags();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'<meta name="description"',
			$output,
			'output_tags() must emit <meta name="description">.'
		);
		$this->assertStringContainsString(
			'<meta property="og:description"',
			$output,
			'output_tags() must emit <meta property="og:description">.'
		);
		$this->assertStringContainsString(
			'<meta name="twitter:description"',
			$output,
			'output_tags() must emit <meta name="twitter:description">.'
		);
		$this->assertStringContainsString(
			'Renewable energy for a sustainable future.',
			$output,
			'output_tags() must use the bloginfo description as the fallback value.'
		);
	}
}
