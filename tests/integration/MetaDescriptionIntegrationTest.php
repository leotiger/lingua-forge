<?php
/**
 * Integration tests for LinguaForge\AI\Features\MetaDescription::run().
 *
 * run() uses the `linguaforge_ai_provider` filter (same seam as Translation)
 * so a StubProvider can be injected without a live API key.
 *
 * Coverage — §6.0.1 Medium (MetaDescription.php, 74%):
 *   1. Success path — stub returns text → success=true, output set, type=text.
 *   2. Empty provider response → success=false, error message set.
 *   3. Cache hit — enable API cache; second run() call returns cached=true.
 *
 * Design notes:
 *   • run() reads the prompt template from LINGUAFORGE_AI_PATH/templates/prompts/
 *     meta-description.txt — the file must exist in the mounted plugin directory.
 *   • CacheStore is checked only when `linguaforge_api_cache_enabled` is true;
 *     the option is enabled/disabled per test.
 *   • WP_UnitTestCase transaction rollback handles post cleanup.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Features\MetaDescription;
use LinguaForge\Tests\Integration\Stubs\StubProvider;
use WP_UnitTestCase;

require_once __DIR__ . '/Stubs/StubProvider.php';

final class MetaDescriptionIntegrationTest extends WP_UnitTestCase {

	private MetaDescription $feature;
	private int             $post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->feature = new MetaDescription();

		$this->post_id = (int) self::factory()->post->create( [
			'post_title'   => 'Solar Panels for Homes',
			'post_content' => '<!-- wp:paragraph --><p>Install solar panels today and save on energy bills.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		] );

		// Set a known language so Translation::get_languages() can resolve it.
		update_post_meta( $this->post_id, '_lf_lang', 'en' );

		// Disable API cache by default; test 3 opts in.
		update_option( 'linguaforge_api_cache_enabled', false );
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_ai_provider' );
		parent::tearDown();
	}

	// =========================================================================
	// Helper
	// =========================================================================

	/**
	 * Register a StubProvider for the linguaforge_ai_provider filter.
	 * Returns the stub so tests can inspect call count or assert messages.
	 */
	private function inject_stub( string|null $response ): StubProvider {
		$stub = new StubProvider( $response );
		add_filter(
			'linguaforge_ai_provider',
			static fn() => $stub,
			10,
			3
		);
		return $stub;
	}

	// =========================================================================
	// 1. Success path
	// =========================================================================

	/**
	 * When the provider returns a valid string, run() must return success=true
	 * with the (cleaned) text in 'output' and type='text'.
	 */
	public function test_run_success_path_returns_output(): void {
		$this->inject_stub( 'Install solar panels today and cut your energy bills by 50%.' );

		$result = $this->feature->run( $this->post_id );

		$this->assertTrue( $result['success'], 'run() must return success=true on a valid provider response.' );
		$this->assertArrayHasKey( 'output', $result, "Result must contain 'output' key." );
		$this->assertNotEmpty( $result['output'], "'output' must not be empty." );
		$this->assertSame( 'text', $result['type'], "Result type must be 'text'." );
		$this->assertArrayNotHasKey( 'cached', $result, 'Fresh call must not carry a cached flag.' );
	}

	// =========================================================================
	// 2. Empty provider response → failure
	// =========================================================================

	/**
	 * When the provider returns null or an empty string, run() must return
	 * success=false with an error message.
	 */
	public function test_run_empty_response_returns_failure(): void {
		$this->inject_stub( null );

		$result = $this->feature->run( $this->post_id );

		$this->assertFalse( $result['success'], 'run() must return success=false when provider returns null.' );
		$this->assertArrayHasKey( 'error', $result, 'Failure result must carry an error message.' );
		$this->assertNotEmpty( $result['error'] );
	}

	// =========================================================================
	// 3. Cache hit on second call
	// =========================================================================

	/**
	 * With API cache enabled, the second run() call for the same post must
	 * return cached=true without calling the provider again.
	 */
	public function test_run_returns_cached_result_on_second_call(): void {
		update_option( 'linguaforge_api_cache_enabled', true );

		$stub = $this->inject_stub( 'Great deals on solar energy storage solutions.' );

		// First call — fills the cache.
		$first = $this->feature->run( $this->post_id );
		$this->assertTrue( $first['success'], 'First run() must succeed.' );
		$this->assertCount( 1, $stub->calls, 'Provider must be called exactly once on cache miss.' );

		// Second call — must hit the cache.
		$second = $this->feature->run( $this->post_id );
		$this->assertTrue( $second['success'], 'Cached run() must still report success=true.' );
		$this->assertTrue( $second['cached'] ?? false, 'Second run() must return cached=true.' );
		$this->assertCount( 1, $stub->calls, 'Provider must not be called again on cache hit.' );
	}
}
