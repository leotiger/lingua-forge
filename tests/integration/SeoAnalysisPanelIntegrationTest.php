<?php
/**
 * Integration tests for SeoAnalysisPanel::ajax_analyze().
 *
 * Exercises the full AJAX handler stack inside wp-env:
 *   1. Permission denied when user lacks edit_posts capability.
 *   2. Invalid post ID returns error response.
 *   3. Valid published post returns correct metric structure and score.
 *   4. Score is always in the 0–100 range.
 *
 * Design notes:
 *   • ajax_analyze() calls check_ajax_referer() (dies on mismatch), then
 *     wp_send_json_success/error() (also dies).  We call it inside an output
 *     buffer and catch WPDieException for error paths; for success paths we
 *     buffer the JSON output and decode it directly.
 *   • WP_UnitTestCase transaction rollback handles all post/meta cleanup.
 *   • No AI provider is needed — the handler only runs the rule-based analysis.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel;
use WP_UnitTestCase;

final class SeoAnalysisPanelIntegrationTest extends WP_UnitTestCase {

	private int $admin_id;
	private int $post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->post_id = (int) self::factory()->post->create( [
			'post_title'   => 'A Great Post About Renewable Energy',
			'post_content' => implode( ' ', array_fill( 0, 350, 'word' ) ), // 350-word post
			'post_status'  => 'publish',
			'post_excerpt' => 'A focused excerpt about renewable energy solutions.',
		] );

		update_post_meta( $this->post_id, '_lf_lang', 'en' );

		// Seed a nonce that check_ajax_referer() will accept.
		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_ai_provider' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Call ajax_analyze() with given POST params; return decoded JSON data.
	 * Sets up nonce and user before the call.
	 *
	 * @param  array<string,mixed> $params
	 * @return array{success:bool, data:mixed}
	 */
	private function dispatch( array $params ): array {

		// check_ajax_referer() reads the nonce from $_REQUEST, not $_POST.
		// In PHP CLI, $_REQUEST is never auto-populated from $_POST, so the
		// nonce lookup silently fails and the non-AJAX branch calls die('-1'),
		// killing the PHPUnit process.  Set both superglobals to avoid this.
		//
		// wp_send_json() uses the same DOING_AJAX guard: define it so the
		// response goes through wp_die() → WPDieException rather than bare die.
		defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', true );

		$payload = array_merge(
			[
				'nonce'   => wp_create_nonce( 'linguaforge_seo_analyze' ),
				'post_id' => (string) $this->post_id,
				'lang'    => 'en',
			],
			$params
		);

		$_POST    = $payload;
		$_REQUEST = $payload;

		// In WordPress 6.9 the test framework no longer auto-installs the
		// wp_die → WPDieException filter in WP_UnitTestCase::setUp().  Install
		// it explicitly here so wp_die() throws instead of terminating the
		// process.  get_wp_die_handler() is the canonical WP_UnitTestCase helper
		// that returns the WPDieException-throwing callable.
		add_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		// Use a suppressing ob callback: the JSON echoed by wp_send_json() is
		// captured into $raw and the callback returns '' so nothing propagates
		// to any parent buffer (PHPUnit strict-output monitor, WP's own ob stack).
		$raw = '';
		ob_start(
			static function ( string $buffer ) use ( &$raw ): string {
				$raw = $buffer;
				return ''; // suppress — do not forward to parent buffer
			}
		);
		try {
			SeoAnalysisPanel::ajax_analyze();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentional: we capture the JSON output; the exception signals wp_die() was called.
		}
		ob_end_clean(); // trigger the callback above, then pop our buffer level

		remove_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		$_POST    = [];
		$_REQUEST = [];

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [ 'success' => false, 'data' => null ];
	}

	// =========================================================================
	// Tests
	// =========================================================================

	public function test_permission_denied_as_subscriber(): void {

		$subscriber = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$result = $this->dispatch( [] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Permission', (string) ( $result['data']['message'] ?? '' ) );
	}

	public function test_invalid_post_id_returns_error(): void {

		$result = $this->dispatch( [ 'post_id' => '0' ] );

		$this->assertFalse( $result['success'] );
	}

	public function test_valid_post_returns_metric_keys(): void {

		$result = $this->dispatch( [] );

		$this->assertTrue( $result['success'], 'Expected success response: ' . wp_json_encode( $result ) );

		$data = $result['data'];
		$this->assertArrayHasKey( 'post_id',    $data );
		$this->assertArrayHasKey( 'post_title', $data );
		$this->assertArrayHasKey( 'metrics',    $data );
		$this->assertArrayHasKey( 'score',      $data );

		$metrics = $data['metrics'];
		$this->assertArrayHasKey( 'title',            $metrics );
		$this->assertArrayHasKey( 'meta_description', $metrics );
		$this->assertArrayHasKey( 'word_count',       $metrics );
		$this->assertArrayHasKey( 'reading_time',     $metrics );
		$this->assertArrayHasKey( 'headings',         $metrics );
		$this->assertArrayHasKey( 'images',           $metrics );
		$this->assertArrayHasKey( 'links',            $metrics );
	}

	public function test_valid_post_score_in_range(): void {

		$result = $this->dispatch( [] );

		$this->assertTrue( $result['success'] );
		$score = (int) $result['data']['score'];
		$this->assertGreaterThanOrEqual( 0,   $score );
		$this->assertLessThanOrEqual(    100, $score );
	}

	public function test_long_content_word_count_metric_is_ok(): void {

		$result  = $this->dispatch( [] );
		$metrics = $result['data']['metrics'];

		// 350-word post should be rated 'ok' for word count (threshold: 300).
		$this->assertSame( 'ok', $metrics['word_count']['status'] );
	}
}
