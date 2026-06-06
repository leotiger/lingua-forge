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

		$_POST = array_merge(
			[
				'nonce'   => wp_create_nonce( 'linguaforge_seo_analyze' ),
				'post_id' => (string) $this->post_id,
				'lang'    => 'en',
			],
			$params
		);

		ob_start();
		try {
			SeoAnalysisPanel::ajax_analyze();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentional: we capture the JSON output before the exception.
		}
		$raw = ob_get_clean();

		$_POST = [];

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
