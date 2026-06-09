<?php
/**
 * Integration tests for SystemPanel AJAX handlers.
 *
 * Covers:
 *   1.  ajax_exclude_post_type() — saves slug to option.
 *   2.  ajax_exclude_post_type() — deduplicates: calling twice stores once.
 *   3.  ajax_exclude_post_type() — sanitizes the slug (strips invalid chars).
 *   4.  ajax_exclude_post_type() — merges into an existing option value.
 *   5.  ajax_exclude_post_type() — rejects empty post_type with error.
 *   6.  ajax_exclude_post_type() — rejects non-admin with permission error.
 *   7.  ajax_repair_lf_lang()   — tags routable posts with source language.
 *   8.  ajax_repair_lf_lang()   — skips posts in routing-excluded post types
 *                                  (wpcf7_contact_form is always excluded).
 *   9.  ajax_repair_lf_lang()   — skips posts in user-configured excluded types.
 *   10. ajax_repair_lf_lang()   — rejects non-admin with permission error.
 *
 * Design notes:
 *   • Both handlers call check_ajax_referer() and wp_send_json_*() (die paths).
 *     We define DOING_AJAX, install the WPDieException filter, capture output
 *     in an ob_start callback, and decode the JSON — same pattern as
 *     SeoAnalysisPanelIntegrationTest.
 *   • wpcf7_contact_form is registered temporarily so wp_insert_post() accepts
 *     it; unregistered in tearDown.
 *   • WP_UnitTestCase transaction rollback handles post/meta cleanup.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\Settings\Panels\SystemPanel;
use WP_UnitTestCase;

final class SystemPanelIntegrationTest extends WP_UnitTestCase {

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();

		$this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		// Register wpcf7_contact_form so wp_insert_post() accepts it.
		register_post_type( 'wpcf7_contact_form', [ 'public' => false, 'label' => 'CF7 Form' ] );

		defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', true );
	}

	protected function tearDown(): void {
		unregister_post_type( 'wpcf7_contact_form' );
		delete_option( 'linguaforge_secondary_query_excluded_types' );
		wp_set_current_user( 0 );
		$_POST    = [];
		$_REQUEST = [];
		parent::tearDown();
	}

	// =========================================================================
	// Dispatch helpers
	// =========================================================================

	/**
	 * Dispatch an AJAX handler method and return the decoded JSON envelope.
	 *
	 * @param  callable            $handler      Static method to invoke.
	 * @param  string              $nonce_action Nonce action for check_ajax_referer().
	 * @param  array<string,mixed> $params       POST params (nonce added automatically).
	 * @return array{success:bool, data:mixed}
	 */
	private function dispatch( callable $handler, string $nonce_action, array $params ): array {

		$payload = array_merge(
			[ 'nonce' => wp_create_nonce( $nonce_action ) ],
			$params
		);

		$_POST    = $payload;
		$_REQUEST = $payload;

		add_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		$raw = '';
		ob_start(
			static function ( string $buffer ) use ( &$raw ): string {
				$raw = $buffer;
				return '';
			}
		);
		try {
			$handler();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentional: JSON captured via ob_start; exception signals wp_die() was called.
		}
		ob_end_clean();

		remove_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		$_POST    = [];
		$_REQUEST = [];

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [ 'success' => false, 'data' => null ];
	}

	private function dispatch_exclude( array $params ): array {
		return $this->dispatch(
			[ SystemPanel::class, 'ajax_exclude_post_type' ],
			'linguaforge_exclude_post_type',
			$params
		);
	}

	private function dispatch_repair( array $extra = [] ): array {
		$router = \LinguaForge\Router\Router::get_instance();
		return $this->dispatch(
			[ SystemPanel::class, 'ajax_repair_lf_lang' ],
			'linguaforge_repair_lf_lang',
			array_merge( [ 'source' => $router->source_language() ], $extra )
		);
	}

	// =========================================================================
	// ajax_exclude_post_type() tests
	// =========================================================================

	// 1. Saves slug to option.
	public function test_exclude_saves_slug_to_option(): void {

		$result = $this->dispatch_exclude( [ 'post_type' => 'my_cpt' ] );

		$this->assertTrue( $result['success'] );
		$saved = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$this->assertStringContainsString( 'my_cpt', $saved );
	}

	// 2. Deduplicates: calling twice stores once.
	public function test_exclude_deduplicates(): void {

		$this->dispatch_exclude( [ 'post_type' => 'my_cpt' ] );
		$this->dispatch_exclude( [ 'post_type' => 'my_cpt' ] );

		$saved  = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$tokens = array_filter( explode( ',', $saved ) );
		$this->assertSame(
			1,
			count( array_filter( $tokens, fn( $t ) => $t === 'my_cpt' ) ),
			'my_cpt must appear exactly once after two exclude calls.'
		);
	}

	// 3. Sanitizes slug (strips invalid characters).
	public function test_exclude_sanitizes_slug(): void {

		// sanitize_key() strips uppercase and special chars.
		$result = $this->dispatch_exclude( [ 'post_type' => 'My CPT!!' ] );

		$this->assertTrue( $result['success'] );
		// sanitize_key turns "My CPT!!" into "my-cpt": uppercased letters are lowercased, spaces become hyphens, exclamation marks are stripped.
		$saved = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$this->assertStringNotContainsString( 'My CPT', $saved );
	}

	// 4. Merges into an existing option value.
	public function test_exclude_merges_into_existing_option(): void {

		update_option( 'linguaforge_secondary_query_excluded_types', 'existing_type', false );

		$result = $this->dispatch_exclude( [ 'post_type' => 'new_type' ] );

		$this->assertTrue( $result['success'] );
		$saved = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$this->assertStringContainsString( 'existing_type', $saved );
		$this->assertStringContainsString( 'new_type', $saved );
	}

	// 5. Rejects empty post_type.
	public function test_exclude_rejects_empty_post_type(): void {

		$result = $this->dispatch_exclude( [ 'post_type' => '' ] );

		$this->assertFalse( $result['success'] );
	}

	// 6. Rejects non-admin.
	public function test_exclude_rejects_non_admin(): void {

		$subscriber = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$result = $this->dispatch_exclude( [ 'post_type' => 'my_cpt' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Permission', (string) ( $result['data']['message'] ?? '' ) );
	}

	// =========================================================================
	// ajax_repair_lf_lang() tests
	// =========================================================================

	// 7. Tags routable posts with source language.
	public function test_repair_tags_routable_posts(): void {

		$post_id = (int) self::factory()->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );
		// Ensure no _lf_lang meta.
		delete_post_meta( $post_id, '_lf_lang' );

		$result = $this->dispatch_repair();

		$this->assertTrue( $result['success'], wp_json_encode( $result ) );
		$this->assertGreaterThanOrEqual( 1, (int) $result['data']['repaired'] );

		$lang = get_post_meta( $post_id, '_lf_lang', true );
		$this->assertNotEmpty( $lang, 'Routable post must receive _lf_lang after repair.' );
	}

	// 8. Skips wpcf7_contact_form (always in builtin exclusion list).
	public function test_repair_skips_builtin_excluded_wpcf7(): void {

		$cf7_id = (int) self::factory()->post->create( [
			'post_type'   => 'wpcf7_contact_form',
			'post_status' => 'publish',
		] );
		delete_post_meta( $cf7_id, '_lf_lang' );

		$this->dispatch_repair();

		$lang = get_post_meta( $cf7_id, '_lf_lang', true );
		$this->assertEmpty( $lang, 'wpcf7_contact_form posts must not be tagged by repair.' );
	}

	// 9. Skips user-configured excluded post types.
	public function test_repair_skips_user_excluded_post_type(): void {

		// Register a temporary CPT and add it to the exclusion option.
		register_post_type( 'lf_test_internal', [ 'public' => false ] );
		update_option( 'linguaforge_secondary_query_excluded_types', 'lf_test_internal', false );

		$internal_id = (int) self::factory()->post->create( [
			'post_type'   => 'lf_test_internal',
			'post_status' => 'publish',
		] );
		delete_post_meta( $internal_id, '_lf_lang' );

		$this->dispatch_repair();

		$lang = get_post_meta( $internal_id, '_lf_lang', true );
		$this->assertEmpty( $lang, 'User-excluded post types must not be tagged by repair.' );

		unregister_post_type( 'lf_test_internal' );
	}

	// 10. Rejects non-admin.
	public function test_repair_rejects_non_admin(): void {

		$subscriber = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$result = $this->dispatch_repair();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Permission', (string) ( $result['data']['message'] ?? '' ) );
	}
}
