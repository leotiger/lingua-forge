<?php
/**
 * Parametrized integration tests for AbstractProvider::chat() across all three
 * concrete providers: Anthropic, OpenAI, and Gemini.
 *
 * The existing AbstractProviderIntegrationTest covers only the Anthropic path
 * (intentionally, as a focused regression net).  This class adds coverage for
 * the provider-specific contract points that differ between implementations:
 *
 *   - Auth header / URL key scheme        (x-api-key vs Bearer vs ?key=)
 *   - Success response envelope            (content[0].text / choices[0].message.content / candidates[0]…)
 *   - Truncation marker field              (stop_reason / finish_reason / finishReason)
 *   - OpenAI quota-vs-rate-limit error     (insufficient_quota → specific label)
 *
 * Design:
 *   Each "@dataProvider" provider returns [$slug, $class, $model, $key] tuples;
 *   the helper make_provider() constructs the instance and stores the test key.
 *   pre_http_request intercepts every wp_remote_post() call, capturing the URL
 *   and headers for request-shape assertions without any network traffic.
 *   The retry policy is collapsed to 1 attempt so tests run instantly.
 *
 * OpenAI and Gemini were at 0% coverage before this class — together with the
 * existing AbstractProviderIntegrationTest they now give meaningful evidence for
 * the "any of three providers" product promise (§4.4 of TEST-WC-DEEPDIVE).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Providers\Anthropic;
use LinguaForge\AI\Providers\Gemini;
use LinguaForge\AI\Providers\OpenAI;
use LinguaForge\AI\Providers\WorkerConfig;
use WP_UnitTestCase;

final class ProviderChatIntegrationTest extends WP_UnitTestCase {

	/** Messages payload — content is irrelevant since HTTP is always intercepted. */
	private const MESSAGES = [
		[ 'role' => 'user', 'content' => 'Translate: hello.' ],
	];

	// =========================================================================
	// Data providers
	// =========================================================================

	/**
	 * All three providers with a representative model name and dummy API key.
	 * Used for tests that don't depend on the response body format.
	 *
	 * @return array<string, array{string, class-string<AIProviderInterface>, string, string}>
	 */
	public static function all_providers(): array {
		return [
			'anthropic' => [ 'anthropic', Anthropic::class, 'claude-haiku-4-5-20251001', 'sk-ant-test-key' ],
			'openai'    => [ 'openai',    OpenAI::class,    'gpt-4o-mini',               'sk-openai-test-key' ],
			'gemini'    => [ 'gemini',    Gemini::class,    'gemini-2.0-flash',          'gemini-test-key' ],
		];
	}

	/**
	 * Per-provider success response bodies and the expected extracted text.
	 *
	 * @return array<string, array{string, class-string<AIProviderInterface>, string, string, string, string}>
	 */
	public static function success_fixtures(): array {
		$text = 'Hola.';

		$anthropic_body = (string) wp_json_encode( [
			'content'     => [ [ 'type' => 'text', 'text' => $text ] ],
			'stop_reason' => 'end_turn',
			'usage'       => [ 'input_tokens' => 5, 'output_tokens' => 3 ],
		] );

		$openai_body = (string) wp_json_encode( [
			'choices' => [ [
				'message'       => [ 'role' => 'assistant', 'content' => $text ],
				'finish_reason' => 'stop',
			] ],
			'usage'   => [ 'prompt_tokens' => 5, 'completion_tokens' => 3 ],
		] );

		$gemini_body = (string) wp_json_encode( [
			'candidates'    => [ [
				'content'      => [ 'parts' => [ [ 'text' => $text ] ] ],
				'finishReason' => 'STOP',
			] ],
			'usageMetadata' => [ 'promptTokenCount' => 5, 'candidatesTokenCount' => 3 ],
		] );

		return [
			'anthropic' => [ 'anthropic', Anthropic::class, 'claude-haiku-4-5-20251001', 'sk-ant-test-key', $anthropic_body, $text ],
			'openai'    => [ 'openai',    OpenAI::class,    'gpt-4o-mini',               'sk-openai-test-key', $openai_body,   $text ],
			'gemini'    => [ 'gemini',    Gemini::class,    'gemini-2.0-flash',          'gemini-test-key',    $gemini_body,   $text ],
		];
	}

	/**
	 * Per-provider truncated response bodies (max-tokens hit).
	 *
	 * @return array<string, array{string, class-string<AIProviderInterface>, string, string, string}>
	 */
	public static function truncated_fixtures(): array {
		$anthropic_body = (string) wp_json_encode( [
			'content'     => [ [ 'type' => 'text', 'text' => 'partial…' ] ],
			'stop_reason' => 'max_tokens',
			'usage'       => [ 'input_tokens' => 5, 'output_tokens' => 64 ],
		] );

		$openai_body = (string) wp_json_encode( [
			'choices' => [ [
				'message'       => [ 'role' => 'assistant', 'content' => 'partial…' ],
				'finish_reason' => 'length',
			] ],
			'usage'   => [ 'prompt_tokens' => 5, 'completion_tokens' => 100 ],
		] );

		$gemini_body = (string) wp_json_encode( [
			'candidates'    => [ [
				'content'      => [ 'parts' => [ [ 'text' => 'partial…' ] ] ],
				'finishReason' => 'MAX_TOKENS',
			] ],
			'usageMetadata' => [ 'promptTokenCount' => 5, 'candidatesTokenCount' => 100 ],
		] );

		return [
			'anthropic' => [ 'anthropic', Anthropic::class, 'claude-haiku-4-5-20251001', 'sk-ant-test-key',    $anthropic_body ],
			'openai'    => [ 'openai',    OpenAI::class,    'gpt-4o-mini',               'sk-openai-test-key', $openai_body   ],
			'gemini'    => [ 'gemini',    Gemini::class,    'gemini-2.0-flash',          'gemini-test-key',    $gemini_body   ],
		];
	}

	// =========================================================================
	// setUp / tearDown
	// =========================================================================

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'linguaforge_ai_retry_policy' );
		// Clean up all three slugs so no key bleeds between tests.
		foreach ( [ 'anthropic', 'openai', 'gemini' ] as $slug ) {
			KeyStore::delete( $slug );
		}
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a provider instance, store a dummy API key, and set retry = 1.
	 *
	 * @param  string                          $slug   KeyStore slug ('anthropic', 'openai', 'gemini').
	 * @param  class-string<AIProviderInterface> $class Provider class name.
	 * @param  string                          $model  Model identifier string.
	 * @param  string                          $key    Dummy API key value.
	 * @return AIProviderInterface
	 */
	private function make_provider(
		string $slug,
		string $class,
		string $model,
		string $key
	): AIProviderInterface {
		KeyStore::set( $slug, $key );

		/** @var AIProviderInterface $provider */
		$provider = new $class( new WorkerConfig(
			model:       $model,
			max_tokens:  64,
			temperature: 0.0,
		) );

		// Collapse to 1 attempt so tests don't sleep through retry back-off.
		add_filter( 'linguaforge_ai_retry_policy', static function ( array $policy ): array {
			$policy['attempts'] = 1;
			$policy['delay_ms'] = 0;
			return $policy;
		} );

		return $provider;
	}

	/**
	 * Install a pre_http_request stub that returns $response for every call.
	 */
	private function stub_http( array|\WP_Error $response ): void {
		add_filter( 'pre_http_request', static fn() => $response, 10, 3 );
	}

	/**
	 * Install a pre_http_request stub that captures (URL, headers) into
	 * $captured and returns a 200 with an empty body.
	 */
	private function capture_request( array &$captured ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) use ( &$captured ): array {
				$captured = [ 'url' => $url, 'headers' => $args['headers'] ?? [] ];
				// Return a minimal valid response to prevent further processing errors.
				return [
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'body'     => '{}',
					'headers'  => [],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	private function make_response( int $status, string $body ): array {
		return [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [],
			'cookies'  => [],
		];
	}

	// =========================================================================
	// 1. WP_Error → null  (all providers)
	// =========================================================================

	/**
	 * @dataProvider all_providers
	 * @param  class-string<AIProviderInterface> $class
	 */
	public function test_wp_error_returns_null(
		string $slug,
		string $class,
		string $model,
		string $key
	): void {
		$provider = $this->make_provider( $slug, $class, $model, $key );
		$this->stub_http( new \WP_Error( 'http_request_failed', 'TCP refused.' ) );

		$this->assertNull( $provider->chat( self::MESSAGES ) );
	}

	// =========================================================================
	// 2. Non-200 HTTP status → null  (all providers)
	// =========================================================================

	/**
	 * @dataProvider all_providers
	 * @param  class-string<AIProviderInterface> $class
	 */
	public function test_non_200_status_returns_null(
		string $slug,
		string $class,
		string $model,
		string $key
	): void {
		$provider = $this->make_provider( $slug, $class, $model, $key );
		$this->stub_http( $this->make_response( 401, '{"error":{"message":"Bad key."}}' ) );

		$this->assertNull( $provider->chat( self::MESSAGES ) );
	}

	// =========================================================================
	// 3. Invalid JSON body → null  (all providers)
	// =========================================================================

	/**
	 * @dataProvider all_providers
	 * @param  class-string<AIProviderInterface> $class
	 */
	public function test_invalid_json_body_returns_null(
		string $slug,
		string $class,
		string $model,
		string $key
	): void {
		$provider = $this->make_provider( $slug, $class, $model, $key );
		$this->stub_http( $this->make_response( 200, 'not-valid-json' ) );

		$this->assertNull( $provider->chat( self::MESSAGES ) );
	}

	// =========================================================================
	// 4. Truncated response → null  (per-provider body)
	// =========================================================================

	/**
	 * @dataProvider truncated_fixtures
	 * @param  class-string<AIProviderInterface> $class
	 */
	public function test_truncated_response_returns_null(
		string $slug,
		string $class,
		string $model,
		string $key,
		string $body
	): void {
		$provider = $this->make_provider( $slug, $class, $model, $key );
		$this->stub_http( $this->make_response( 200, $body ) );

		$this->assertNull(
			$provider->chat( self::MESSAGES ),
			"$class::chat() must return null when the provider signals a max-tokens truncation."
		);
	}

	// =========================================================================
	// 5. Successful response → extracted text  (per-provider body)
	// =========================================================================

	/**
	 * @dataProvider success_fixtures
	 * @param  class-string<AIProviderInterface> $class
	 */
	public function test_successful_response_returns_text(
		string $slug,
		string $class,
		string $model,
		string $key,
		string $body,
		string $expected
	): void {
		$provider = $this->make_provider( $slug, $class, $model, $key );
		$this->stub_http( $this->make_response( 200, $body ) );

		$this->assertSame(
			$expected,
			$provider->chat( self::MESSAGES ),
			"$class::chat() must return the extracted text on a successful response."
		);
	}

	// =========================================================================
	// 6. Request shape — authentication scheme per provider
	// =========================================================================

	/**
	 * Anthropic must send the API key in the x-api-key header (not Authorization).
	 */
	public function test_anthropic_sends_api_key_in_x_api_key_header(): void {
		$key      = 'sk-ant-test-shape';
		$provider = $this->make_provider( 'anthropic', Anthropic::class, 'claude-haiku-4-5-20251001', $key );

		$captured = [];
		$this->capture_request( $captured );

		$provider->chat( self::MESSAGES );

		$this->assertArrayHasKey( 'x-api-key', $captured['headers'],
			'Anthropic must send auth via x-api-key header.' );
		$this->assertSame( $key, $captured['headers']['x-api-key'],
			'x-api-key must contain the configured API key.' );
		$this->assertArrayNotHasKey( 'Authorization', $captured['headers'],
			'Anthropic must not send an Authorization header.' );
	}

	/**
	 * OpenAI must send the API key as a Bearer token in the Authorization header.
	 */
	public function test_openai_sends_bearer_authorization_header(): void {
		$key      = 'sk-openai-test-shape';
		$provider = $this->make_provider( 'openai', OpenAI::class, 'gpt-4o-mini', $key );

		$captured = [];
		$this->capture_request( $captured );

		$provider->chat( self::MESSAGES );

		$this->assertArrayHasKey( 'Authorization', $captured['headers'],
			'OpenAI must send auth via Authorization header.' );
		$this->assertSame( 'Bearer ' . $key, $captured['headers']['Authorization'],
			'Authorization header must use the Bearer scheme.' );
		$this->assertArrayNotHasKey( 'x-api-key', $captured['headers'],
			'OpenAI must not send an x-api-key header.' );
	}

	/**
	 * Gemini must send the API key as a ?key= URL query parameter, not a header.
	 */
	public function test_gemini_sends_api_key_as_url_query_param(): void {
		$key      = 'gemini-test-shape';
		$provider = $this->make_provider( 'gemini', Gemini::class, 'gemini-2.0-flash', $key );

		$captured = [];
		$this->capture_request( $captured );

		$provider->chat( self::MESSAGES );

		$parsed = wp_parse_url( $captured['url'] );
		parse_str( $parsed['query'] ?? '', $query_params );

		$this->assertArrayHasKey( 'key', $query_params,
			'Gemini must pass the API key as a ?key= URL query parameter.' );
		$this->assertSame( $key, $query_params['key'],
			'?key= must contain the configured API key.' );
		$this->assertArrayNotHasKey( 'Authorization', $captured['headers'],
			'Gemini must not send an Authorization header.' );
		$this->assertArrayNotHasKey( 'x-api-key', $captured['headers'],
			'Gemini must not send an x-api-key header.' );
	}

	/**
	 * Gemini request URL must target the generativelanguage.googleapis.com domain
	 * and embed the model name in the path.
	 */
	public function test_gemini_url_contains_model_and_correct_domain(): void {
		$model    = 'gemini-2.0-flash';
		$provider = $this->make_provider( 'gemini', Gemini::class, $model, 'gemini-url-test' );

		$captured = [];
		$this->capture_request( $captured );

		$provider->chat( self::MESSAGES );

		$this->assertStringContainsString(
			'generativelanguage.googleapis.com',
			$captured['url'],
			'Gemini request URL must target generativelanguage.googleapis.com.'
		);
		$this->assertStringContainsString(
			rawurlencode( $model ),
			$captured['url'],
			'Gemini request URL must embed the model name in the path.'
		);
	}

	// =========================================================================
	// 7. OpenAI-specific: quota exhaustion vs. ordinary rate-limiting
	// =========================================================================

	/**
	 * When OpenAI returns HTTP 429 with error.code === 'insufficient_quota',
	 * get_last_error() must return a credits-specific message, not a generic
	 * rate-limit message — so admins know to top up rather than wait.
	 */
	public function test_openai_insufficient_quota_surfaces_credits_error(): void {
		$provider = $this->make_provider( 'openai', OpenAI::class, 'gpt-4o-mini', 'sk-openai-quota-test' );

		$body = (string) wp_json_encode( [
			'error' => [
				'message' => 'You exceeded your current quota.',
				'type'    => 'insufficient_quota',
				'code'    => 'insufficient_quota',
			],
		] );
		$this->stub_http( $this->make_response( 429, $body ) );

		$result = $provider->chat( self::MESSAGES );

		$this->assertNull( $result, 'chat() must return null on quota exhaustion.' );
		$this->assertStringContainsString(
			'credits',
			strtolower( $provider->get_last_error() ),
			'get_last_error() must mention credits/billing on insufficient_quota, not just "rate limited".'
		);
	}

	/**
	 * When OpenAI returns HTTP 429 without the insufficient_quota code (ordinary
	 * rate limiting), get_last_error() must return a rate-limit message.
	 */
	public function test_openai_rate_limit_surfaces_rate_limit_error(): void {
		$provider = $this->make_provider( 'openai', OpenAI::class, 'gpt-4o-mini', 'sk-openai-rate-test' );

		$body = (string) wp_json_encode( [
			'error' => [
				'message' => 'Rate limit reached for requests.',
				'type'    => 'requests',
				'code'    => 'rate_limit_exceeded',
			],
		] );
		$this->stub_http( $this->make_response( 429, $body ) );

		$result = $provider->chat( self::MESSAGES );

		$this->assertNull( $result );

		$error = strtolower( $provider->get_last_error() );
		$this->assertTrue(
			str_contains( $error, 'rate' ) || str_contains( $error, 'limited' ) || str_contains( $error, 'wait' ),
			'get_last_error() must indicate rate limiting for a 429 without insufficient_quota.'
		);
	}

	// =========================================================================
	// 8. No API key configured → null + last_error set
	//    REMOVED from integration suite: wp-env injects API keys as PHP
	//    constants via wp-config.php (from .wp-env.override.json).  PHP
	//    constants cannot be unset at runtime, so every provider with a key
	//    defined as a constant would skip rather than run.  This path is
	//    covered by:
	//      a) the unit suite (KeyStoreTest + per-provider unit tests), and
	//      b) the Settings → AI ping UI test in the E2E suite, which exercises
	//         the missing-key error surface through the browser interface.
	// =========================================================================
}
