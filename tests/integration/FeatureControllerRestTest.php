<?php
/**
 * Integration tests for FeatureController REST endpoints — HTTP-layer behaviour.
 *
 * Uses WP_REST_Request + rest_do_request() inside wp-env to exercise the full
 * stack (route registration → permission_callback → handler → response) without
 * making real HTTP calls or calling the AI API.
 *
 * Coverage targets:
 *   • 401/403 — unauthenticated and capability-restricted requests rejected
 *   • 400     — invalid input (bad language code, unknown feature)
 *   • 404     — unknown feature slug
 *   • 429     — rate-limit enforcement via seeded transient + filter
 *   • Handler dispatch confirmed for /translate-chunk, /create-chunk,
 *     /revise-block, /feature/{feature}/{id} — response shape and 400 paths
 *
 * No live API key is required — all tests that would reach the AI call are
 * designed to be rejected before that point (validation / rate limit / auth).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\REST\FeatureController;
use WP_UnitTestCase;

final class FeatureControllerRestTest extends WP_UnitTestCase {

	private int $admin_id;

	protected function setUp(): void {
		parent::setUp();

		// Plugin::boot() only fires on WP 'init' when should_boot() returns true —
		// PHPUnit is neither admin, REST, nor WP-CLI, so it never fires. Boot the
		// two components the REST tests need directly.
		Registry::init();       // populates Registry so run_translate_chunk etc. can resolve features
		FeatureController::init(); // registers the rest_api_init hook

		// Reset the REST server so each test gets a clean slate with the
		// newly-registered rest_api_init hook active.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standard WP REST integration test pattern.
		do_action( 'rest_api_init', $wp_rest_server );

		// Create an administrator who has the default 'edit_posts' capability.
		$this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional teardown reset.

		remove_all_filters( 'linguaforge_ai_rate_limit' );
		remove_all_filters( 'linguaforge_ai_daily_quota' );
		remove_all_filters( 'linguaforge_required_capability' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function post( string $route, array $body = [], bool $as_admin = true ): \WP_REST_Response {
		if ( $as_admin ) {
			wp_set_current_user( $this->admin_id );
		} else {
			wp_set_current_user( 0 ); // anonymous
		}

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	// =========================================================================
	// Auth — unauthenticated requests → 401
	// =========================================================================

	public function test_translate_chunk_unauthenticated_returns_401(): void {
		$response = $this->post( '/lingua-forge/v1/translate-chunk', [], false );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_create_chunk_unauthenticated_returns_401(): void {
		$response = $this->post( '/lingua-forge/v1/create-chunk', [], false );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_revise_block_unauthenticated_returns_401(): void {
		$response = $this->post( '/lingua-forge/v1/revise-block', [], false );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_feature_endpoint_unauthenticated_returns_401(): void {
		$post_id  = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$response = $this->post( '/lingua-forge/v1/feature/translation/' . $post_id, [], false );
		$this->assertSame( 401, $response->get_status() );
	}

	// =========================================================================
	// Auth — insufficient capability → 403
	// =========================================================================

	public function test_translate_chunk_insufficient_capability_returns_403(): void {
		// Force a capability that the administrator doesn't have.
		add_filter( 'linguaforge_required_capability', fn() => 'do_not_allow' );
		$response = $this->post( '/lingua-forge/v1/translate-chunk' );
		$this->assertSame( 403, $response->get_status() );
	}

	// =========================================================================
	// Validation — /translate-chunk → 400 on bad language
	// =========================================================================

	public function test_translate_chunk_invalid_language_returns_400(): void {
		$response = $this->post( '/lingua-forge/v1/translate-chunk', [
			'target_language' => 'zz', // not in Translation::LANGUAGES
			'chunk_text'      => 'Hello world',
		] );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invalid_language', $data['code'] );
	}

	// =========================================================================
	// Validation — /feature/{feature}/{id} → 404 for unknown feature
	// =========================================================================

	public function test_feature_endpoint_unknown_feature_returns_404(): void {
		$post_id  = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$response = $this->post( '/lingua-forge/v1/feature/no-such-feature/' . $post_id );
		$this->assertSame( 404, $response->get_status() );
	}

	// =========================================================================
	// Rate limit — /translate-chunk → 429 when limit exceeded
	// =========================================================================

	public function test_translate_chunk_rate_limited_returns_429(): void {
		wp_set_current_user( $this->admin_id );

		// Set limit to 1 and seed the transient with 1 event so the next call is over limit.
		add_filter( 'linguaforge_ai_rate_limit', static fn() => [
			'window_seconds' => 60,
			'max_requests'   => 1,
		] );

		$key = 'linguaforge_rate_user_' . $this->admin_id . '_translate-chunk';
		set_transient( $key, [ time() ], 65 );

		$response = $this->post( '/lingua-forge/v1/translate-chunk', [
			'target_language' => 'de',
			'chunk_text'      => 'Hello',
		] );

		$this->assertSame( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rate_limited', $data['code'] );
		$this->assertArrayHasKey( 'retry_after', $data['data'] );
	}

	// =========================================================================
	// Daily quota — /translate-chunk → 429 when daily quota exceeded
	// =========================================================================

	public function test_translate_chunk_daily_quota_exceeded_returns_429(): void {
		// Force a quota of 1 and seed the counter at 1.
		add_filter( 'linguaforge_ai_daily_quota', static fn() => 1 );

		$quota_key = 'linguaforge_quota_daily_used_' . gmdate( 'Ymd' );
		set_transient( $quota_key, 1, DAY_IN_SECONDS );

		$response = $this->post( '/lingua-forge/v1/translate-chunk', [
			'target_language' => 'de',
			'chunk_text'      => 'Hello',
		] );

		$this->assertSame( 429, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'daily_quota_exceeded', $data['code'] );
	}

	// =========================================================================
	// /create-chunk — 400 on missing hints
	// =========================================================================

	public function test_create_chunk_empty_hints_returns_400(): void {
		$response = $this->post( '/lingua-forge/v1/create-chunk', [
			'hints' => '', // empty → invalid
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	// =========================================================================
	// /revise-block — 400 on missing block content
	// =========================================================================

	public function test_revise_block_empty_chunk_text_returns_400(): void {
		$response = $this->post( '/lingua-forge/v1/revise-block', [
			'revision_type' => 'improve',
			'chunk_text'    => '', // empty → missing_content 400
		] );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'missing_content', $data['code'] );
	}

	// =========================================================================
	// /feature/{feature}/{id} — success dispatch — §6.0.1 Medium
	//
	// Previous tests only exercised error paths (401, 403, 400, 404, 429).
	// These tests confirm the success dispatch: Registry::get() resolves the
	// feature, supports() passes, and run() returns success=true via StubProvider.
	// =========================================================================

	/**
	 * A valid authenticated POST to /feature/meta-description/{id} must return
	 * HTTP 200 with success=true when the AI provider is stubbed.
	 */
	public function test_feature_meta_description_dispatch_returns_200_success(): void {
		require_once dirname( __DIR__ ) . '/integration/Stubs/StubProvider.php';

		$post_id = (int) self::factory()->post->create( [
			'post_title'   => 'Renewable Energy Overview',
			'post_content' => '<!-- wp:paragraph --><p>Solar and wind power are the future.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		] );

		$stub = new \LinguaForge\Tests\Integration\Stubs\StubProvider( 'Discover the future of renewable energy solutions.' );
		add_filter( 'linguaforge_ai_provider', static fn() => $stub, 10, 3 );

		// Disable API cache so the stub is always reached.
		update_option( 'linguaforge_api_cache_enabled', false );

		$response = $this->post( "/lingua-forge/v1/feature/meta-description/{$post_id}" );

		remove_all_filters( 'linguaforge_ai_provider' );

		$this->assertSame( 200, $response->get_status(), '/feature/meta-description/{id} must return 200 on success.' );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] ?? false, 'Response must carry success=true.' );
		$this->assertNotEmpty( $data['output'] ?? '', "'output' must be set and non-empty." );
	}

	/**
	 * A POST to /feature/{unknown}/{id} must return HTTP 404 with
	 * error code 'invalid_feature'.
	 *
	 * Completes the success/error dispatch matrix for the feature endpoint.
	 */
	public function test_feature_unknown_slug_returns_404(): void {
		$post_id  = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$response = $this->post( "/lingua-forge/v1/feature/no-such-feature/{$post_id}" );

		$this->assertSame( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invalid_feature', $data['code'] );
	}
}
