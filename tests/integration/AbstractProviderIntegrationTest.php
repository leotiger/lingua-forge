<?php
/**
 * Integration tests for LinguaForge\AI\Providers\AbstractProvider::chat().
 *
 * AbstractProvider::chat() is `final` and cannot be tested by subclassing with
 * a mock implementation. The `pre_http_request` WordPress filter intercepts
 * wp_remote_post() before any network call, allowing controlled stub responses
 * without consuming API quota or requiring real credentials.
 *
 * The Anthropic concrete provider is used as the test vehicle because its
 * response envelope is straightforward and well-documented in the plugin source.
 *
 * Coverage — §6.0.1 Low (AbstractProvider.php + concrete providers, 2%):
 *   1. WP_Error from wp_remote_post → chat() returns null.
 *   2. HTTP 401 (non-200, non-retryable) → chat() returns null.
 *   3. Response body is not valid JSON → chat() returns null.
 *   4. Anthropic stop_reason = 'max_tokens' (truncated) → chat() returns null.
 *   5. Successful response → chat() returns the extracted text.
 *
 * Design notes:
 *   • An API key is stored via KeyStore::set() so chat() passes the key check.
 *   • Retry policy is set to 1 attempt via linguaforge_ai_retry_policy filter
 *     to prevent usleep() delays in the retry loop.
 *   • Each test removes its filters in tearDown via remove_all_filters().
 *   • WP_UnitTestCase transaction rollback handles wp_options cleanup.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Providers\Anthropic;
use LinguaForge\AI\Providers\WorkerConfig;
use WP_UnitTestCase;

final class AbstractProviderIntegrationTest extends WP_UnitTestCase {

	private Anthropic $provider;

	/** Messages payload — content does not matter since HTTP is intercepted. */
	private const MESSAGES = [
		[ 'role' => 'user', 'content' => 'Say hello.' ],
	];

	protected function setUp(): void {
		parent::setUp();

		// Store a dummy API key so chat() passes the `!$api_key` guard.
		KeyStore::set( 'anthropic', 'sk-ant-integration-test-key' );

		$this->provider = new Anthropic( new WorkerConfig(
			model:       'claude-haiku-4-5-20251001',
			max_tokens:  64,
			temperature: 0.0,
		) );

		// Disable retry back-off: set attempts = 1 so tests run instantly.
		add_filter( 'linguaforge_ai_retry_policy', static function ( array $policy ): array {
			$policy['attempts'] = 1;
			$policy['delay_ms'] = 0;
			return $policy;
		} );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'linguaforge_ai_retry_policy' );
		// Remove the test key so it doesn't bleed into other tests.
		KeyStore::delete( 'anthropic' );
		parent::tearDown();
	}

	// =========================================================================
	// Helper — register a pre_http_request stub
	// =========================================================================

	/**
	 * Install a pre_http_request filter that returns the given response for
	 * every wp_remote_post() call made during the test.
	 *
	 * @param array|\WP_Error $response  Value to return from the filter.
	 */
	private function stub_http( array|\WP_Error $response ): void {
		add_filter(
			'pre_http_request',
			static fn() => $response,
			10,
			3
		);
	}

	/**
	 * Build a minimal HTTP response array that WordPress helper functions
	 * (wp_remote_retrieve_response_code, wp_remote_retrieve_body) can parse.
	 */
	private function make_response( int $status, string $body ): array {
		return [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [],
			'cookies'  => [],
		];
	}

	// =========================================================================
	// 1. WP_Error from wp_remote_post → null
	// =========================================================================

	/**
	 * A network failure (wp_remote_post returns WP_Error) must cause chat()
	 * to return null. The error is logged; no exception is thrown.
	 */
	public function test_wp_error_response_returns_null(): void {
		$this->stub_http( new \WP_Error( 'http_request_failed', 'TCP connection refused.' ) );

		$result = $this->provider->chat( self::MESSAGES );

		$this->assertNull( $result, 'chat() must return null when wp_remote_post() returns a WP_Error.' );
	}

	// =========================================================================
	// 2. Non-200 HTTP status → null
	// =========================================================================

	/**
	 * A 401 Unauthorized response (bad API key on the provider side) must cause
	 * chat() to return null. HTTP 401 is not in the retry_statuses list, so the
	 * loop exits after one attempt.
	 */
	public function test_non_200_http_status_returns_null(): void {
		$this->stub_http( $this->make_response( 401, '{"error":{"message":"Invalid API key."}}' ) );

		$result = $this->provider->chat( self::MESSAGES );

		$this->assertNull( $result, 'chat() must return null on a non-200 HTTP status.' );
	}

	// =========================================================================
	// 3. Non-JSON response body → null
	// =========================================================================

	/**
	 * When the provider returns HTTP 200 but the body is not valid JSON, chat()
	 * must return null (json_decode returns null; the !is_array guard fires).
	 */
	public function test_invalid_json_body_returns_null(): void {
		$this->stub_http( $this->make_response( 200, 'not-valid-json' ) );

		$result = $this->provider->chat( self::MESSAGES );

		$this->assertNull( $result, 'chat() must return null when the response body is not valid JSON.' );
	}

	// =========================================================================
	// 4. Truncated response (stop_reason = max_tokens) → null
	// =========================================================================

	/**
	 * When the Anthropic API signals that the response was truncated due to
	 * hitting max_tokens, chat() must return null and log the truncation.
	 * Anthropic::is_truncated() checks stop_reason === 'max_tokens'.
	 */
	public function test_truncated_response_returns_null(): void {
		$body = wp_json_encode( [
			'content'     => [ [ 'type' => 'text', 'text' => 'Truncated partial...' ] ],
			'stop_reason' => 'max_tokens',
			'usage'       => [ 'input_tokens' => 10, 'output_tokens' => 64 ],
		] );
		$this->stub_http( $this->make_response( 200, (string) $body ) );

		$result = $this->provider->chat( self::MESSAGES );

		$this->assertNull( $result, 'chat() must return null when the provider signals a max_tokens truncation.' );
	}

	// =========================================================================
	// 5. Successful response → extracted text
	// =========================================================================

	/**
	 * A well-formed Anthropic response with stop_reason = 'end_turn' and a
	 * non-empty content text must cause chat() to return the extracted string.
	 */
	public function test_successful_response_returns_text(): void {
		$expected = 'Hello! How can I help you today?';
		$body     = wp_json_encode( [
			'content'     => [ [ 'type' => 'text', 'text' => $expected ] ],
			'stop_reason' => 'end_turn',
			'usage'       => [ 'input_tokens' => 5, 'output_tokens' => 10 ],
		] );
		$this->stub_http( $this->make_response( 200, (string) $body ) );

		$result = $this->provider->chat( self::MESSAGES );

		$this->assertSame( $expected, $result, 'chat() must return the extracted text on a successful response.' );
	}
}
