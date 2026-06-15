<?php
/**
 * Integration tests for ModelCatalog::fetch_from_api() (AUDIT §7.1 untested-file row).
 *
 * Covered here:
 *   fetch_from_api() per provider — happy path returns the filtered live model IDs;
 *   a transport WP_Error and a malformed (non-JSON / wrong-shape) body both return
 *   [] so callers fall back to the curated catalog; an unknown provider returns []
 *   without any HTTP call.
 *
 * Strategy:
 *   • Outbound HTTP is intercepted with the `pre_http_request` filter — no network.
 *   • Provider-specific filtering is asserted: OpenAI keeps only gpt-[0-9]/o[1-9]
 *     IDs; Gemini keeps only generateContent-capable, "gemini-"-prefixed models
 *     (with the "models/" prefix stripped); Anthropic keeps all string IDs.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\ModelCatalog;
use WP_UnitTestCase;

final class ModelCatalogIntegrationTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Stub every wp_remote_* call with a fixed 200 response carrying $body. */
	private function stub_body( string $body ): void {
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body'     => $body,
				'headers'  => [],
				'cookies'  => [],
			],
			10,
			3
		);
	}

	private function stub_error(): void {
		add_filter(
			'pre_http_request',
			static fn() => new \WP_Error( 'http_request_failed', 'Connection refused.' ),
			10,
			3
		);
	}

	// =========================================================================
	// Happy paths
	// =========================================================================

	public function test_fetch_openai_keeps_only_chat_models(): void {
		$this->stub_body( (string) wp_json_encode( [
			'data' => [
				[ 'id' => 'gpt-4o' ],
				[ 'id' => 'o3-mini' ],
				[ 'id' => 'text-embedding-3-large' ],
				[ 'id' => 'dall-e-3' ],
				[ 'id' => 'whisper-1' ],
			],
		] ) );

		$ids = ModelCatalog::fetch_from_api( 'openai', 'sk-test' );

		$this->assertContains( 'gpt-4o', $ids );
		$this->assertContains( 'o3-mini', $ids );
		$this->assertNotContains( 'text-embedding-3-large', $ids );
		$this->assertNotContains( 'dall-e-3', $ids );
		$this->assertNotContains( 'whisper-1', $ids );
	}

	public function test_fetch_anthropic_returns_all_string_ids(): void {
		$this->stub_body( (string) wp_json_encode( [
			'data' => [
				[ 'id' => 'claude-sonnet-9-9' ],
				[ 'id' => 'claude-haiku-9-9' ],
			],
		] ) );

		$ids = ModelCatalog::fetch_from_api( 'anthropic', 'key' );

		$this->assertSame( [ 'claude-sonnet-9-9', 'claude-haiku-9-9' ], $ids );
	}

	public function test_fetch_gemini_keeps_generatecontent_models_and_strips_prefix(): void {
		$this->stub_body( (string) wp_json_encode( [
			'models' => [
				[ 'name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => [ 'generateContent' ] ],
				[ 'name' => 'models/embedding-001',    'supportedGenerationMethods' => [ 'embedContent' ] ],
				[ 'name' => 'models/gemini-2.5-pro',   'supportedGenerationMethods' => [ 'generateContent', 'countTokens' ] ],
			],
		] ) );

		$ids = ModelCatalog::fetch_from_api( 'gemini', 'key' );

		$this->assertContains( 'gemini-2.5-flash', $ids, 'models/ prefix must be stripped.' );
		$this->assertContains( 'gemini-2.5-pro', $ids );
		$this->assertNotContains( 'embedding-001', $ids, 'Non-generateContent / non-gemini models excluded.' );
	}

	// =========================================================================
	// Failure → curated fallback ([])
	// =========================================================================

	public function test_fetch_returns_empty_on_transport_error(): void {
		$this->stub_error();
		$this->assertSame( [], ModelCatalog::fetch_from_api( 'openai', 'key' ) );
	}

	public function test_fetch_returns_empty_on_malformed_body(): void {
		$this->stub_body( 'this is not json' );
		$this->assertSame( [], ModelCatalog::fetch_from_api( 'anthropic', 'key' ) );
	}

	public function test_fetch_returns_empty_on_wrong_shape(): void {
		// Valid JSON but no "data" / "models" array.
		$this->stub_body( (string) wp_json_encode( [ 'unexpected' => true ] ) );
		$this->assertSame( [], ModelCatalog::fetch_from_api( 'gemini', 'key' ) );
	}

	public function test_fetch_unknown_provider_returns_empty(): void {
		$this->assertSame( [], ModelCatalog::fetch_from_api( 'nope', 'key' ) );
	}
}
