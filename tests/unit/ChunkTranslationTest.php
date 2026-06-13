<?php
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- polyfill functions and test class must coexist; same pattern as ApiPolyfills.php.
/**
 * Unit tests for LinguaForge\AI\Features\ChunkTranslation.
 *
 * Tests the full run() method with a mock AIProviderInterface, covering:
 *
 *   • Empty input guard
 *   • Provider failure (null / empty return)
 *   • Successful non-refinement translation — payload shape
 *   • Successful refinement translation — payload shape
 *   • Message array size: 2-element for normal, 4-element for refinement
 *   • Input capping at quick_translate_max_input_chars
 *   • Language code resolution via resolve_language_code()
 *   • build_messages() pure helper — both paths
 *
 * Cache is disabled in all tests (linguaforge_api_cache_enabled = false)
 * to avoid $wpdb dependency. Cache behaviour is covered by CacheStoreHashTest
 * and the integration suite.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Features\ChunkTranslation;
use LinguaForge\AI\Contracts\AIProviderInterface;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once __DIR__ . '/ApiPolyfills.php';

// Dependency chain for ChunkTranslation.
require_once LINGUAFORGE_AI_PATH . '/includes/Contracts/AIProviderInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/WorkerConfig.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/Config.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/CacheStore.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/Glossary.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/UsageRecorder.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/JsonRepair.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/BlockTextExtractor.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/Contracts/FeatureInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/Translation.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/ChunkTranslation.php';

// ---------------------------------------------------------------------------
// Test helpers
// ---------------------------------------------------------------------------

/**
 * Build a mock AIProviderInterface whose chat() returns $return_value and
 * records the messages array it was called with into $holder->messages.
 *
 * Using a stdClass holder avoids PHP's fragile by-reference semantics when
 * references are threaded through anonymous class constructors.
 *
 * @param  string|null    $return_value  Value chat() will return.
 * @param  \stdClass|null $holder        If provided, chat() writes messages to $holder->messages.
 */
function lf_make_mock_provider( ?string $return_value, ?\stdClass $holder = null ): AIProviderInterface {
	return new class( $return_value, $holder ) implements AIProviderInterface {
		public function __construct(
			private readonly ?string   $return_value,
			private readonly ?\stdClass $holder
		) {}

		public function chat( array $messages ): ?string {
			if ( $this->holder !== null ) {
				$this->holder->messages = $messages;
			}
			return $this->return_value;
		}

		public function get_last_error(): string {
			return '';
		}
	};
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Features\ChunkTranslation
 */
final class ChunkTranslationTest extends TestCase {

	protected function setUp(): void {
		// Disable cache — avoids $wpdb dependency inside CacheStore::get/set.
		$GLOBALS['lf_test_options'] = [
			'linguaforge_api_cache_enabled'       => false,
			// Tell Glossary::ensure_table() the schema is current so it bails
			// before reaching get_charset_collate() / dbDelta() / upgrade.php.
			'linguaforge_ai_glossary_db_version'  => '1.0',
		];
		$GLOBALS['lf_test_filters'] = [];
		$GLOBALS['lf_test_actions'] = [];

		// ensure_table() double-checks the table physically exists via get_var().
		// Return the table name (truthy) so it short-circuits immediately.
		\LfWcMocks::$wpdb_get_var = 'wp_lingua_forge_glossary';
	}

	protected function tearDown(): void {
		\LfWcMocks::$wpdb_get_var = null;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a ChunkTranslation with a mock provider.
	 * Pass a stdClass $holder to capture the messages array the provider receives.
	 * Access captured messages via $holder->messages after run() returns.
	 */
	private function makeChunk( ?string $provider_return, ?\stdClass $holder = null ): ChunkTranslation {
		return new ChunkTranslation( lf_make_mock_provider( $provider_return, $holder ) );
	}

	// =========================================================================
	// Input validation
	// =========================================================================

	public function test_empty_chunk_text_returns_error(): void {

		$result = $this->makeChunk( 'Hola' )->run( 'Spanish', [ 'chunk_text' => '' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No text provided', $result['error'] );
	}

	public function test_whitespace_only_chunk_text_returns_error(): void {

		$result = $this->makeChunk( 'Hola' )->run( 'Spanish', [ 'chunk_text' => '   ' ] );

		$this->assertFalse( $result['success'] );
	}

	// =========================================================================
	// Provider failure
	// =========================================================================

	public function test_provider_null_returns_error(): void {

		$result = $this->makeChunk( null )->run( 'German', [ 'chunk_text' => 'Hello world' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Translation failed', $result['error'] );
	}

	public function test_provider_empty_string_returns_error(): void {

		$result = $this->makeChunk( '' )->run( 'German', [ 'chunk_text' => 'Hello world' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Translation failed', $result['error'] );
	}

	// =========================================================================
	// Successful non-refinement translation
	// =========================================================================

	public function test_success_returns_correct_payload_shape(): void {

		$result = $this->makeChunk( 'Hallo Welt' )->run( 'German', [ 'chunk_text' => 'Hello world' ] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Hallo Welt', $result['output'] );
		$this->assertSame( 'chunk', $result['type'] );
		$this->assertSame( 'German', $result['language'] );
		$this->assertArrayNotHasKey( 'cached', $result );
	}

	public function test_output_is_trimmed(): void {

		$result = $this->makeChunk( "  Hallo Welt  \n" )->run( 'German', [ 'chunk_text' => 'Hello world' ] );

		$this->assertSame( 'Hallo Welt', $result['output'] );
	}

	// =========================================================================
	// Message array structure
	// =========================================================================

	public function test_non_refinement_builds_two_message_array(): void {

		$holder = new \stdClass();
		$this->makeChunk( 'Hola', $holder )->run( 'Spanish', [ 'chunk_text' => 'Hello' ] );

		$this->assertIsArray( $holder->messages );
		$this->assertCount( 2, $holder->messages );
		$this->assertSame( 'system', $holder->messages[0]['role'] );
		$this->assertSame( 'user',   $holder->messages[1]['role'] );
	}

	public function test_refinement_builds_four_message_array(): void {

		$holder = new \stdClass();
		$this->makeChunk( 'Hola mejorado', $holder )->run( 'Spanish', [
			'chunk_text'      => 'Hello',
			'previous_output' => 'Hola',
			'refine_hint'     => 'Make it more formal',
		] );

		$this->assertIsArray( $holder->messages );
		$this->assertCount( 4, $holder->messages );
		$this->assertSame( 'system',    $holder->messages[0]['role'] );
		$this->assertSame( 'user',      $holder->messages[1]['role'] );
		$this->assertSame( 'assistant', $holder->messages[2]['role'] );
		$this->assertSame( 'user',      $holder->messages[3]['role'] );
	}

	public function test_refinement_message_contains_prior_output(): void {

		$holder = new \stdClass();
		$this->makeChunk( 'refined', $holder )->run( 'Spanish', [
			'chunk_text'      => 'Hello',
			'previous_output' => 'Hola',
			'refine_hint'     => 'More formal please',
		] );

		$this->assertSame( 'Hola', $holder->messages[2]['content'] );
		$this->assertStringContainsString( 'More formal please', $holder->messages[3]['content'] );
	}

	public function test_missing_refine_hint_is_not_treated_as_refinement(): void {

		// previous_output without refine_hint → not a refinement → 2 messages.
		$holder = new \stdClass();
		$this->makeChunk( 'Hola', $holder )->run( 'Spanish', [
			'chunk_text'      => 'Hello',
			'previous_output' => 'Hola',
			// no refine_hint
		] );

		$this->assertCount( 2, $holder->messages );
	}

	public function test_missing_previous_output_is_not_treated_as_refinement(): void {

		$holder = new \stdClass();
		$this->makeChunk( 'Hola', $holder )->run( 'Spanish', [
			'chunk_text'  => 'Hello',
			'refine_hint' => 'More formal',
			// no previous_output
		] );

		$this->assertCount( 2, $holder->messages );
	}

	// =========================================================================
	// Input capping
	// =========================================================================

	public function test_input_text_capped_at_max_input_chars(): void {

		// Set a small cap via options.
		$GLOBALS['lf_test_options']['linguaforge_quick_translate_max_input_chars'] = 10;

		$long_text = str_repeat( 'a', 50 );
		$holder    = new \stdClass();

		$this->makeChunk( 'result', $holder )->run( 'German', [ 'chunk_text' => $long_text ] );

		// The user message prompt embeds the capped chunk text.
		// The template itself also contains 'a' characters, so we can't just count them.
		// Instead: the capped 10-'a' run must be present, but an 11-'a' run must not be.
		$user_message = $holder->messages[1]['content'];
		$this->assertStringContainsString( str_repeat( 'a', 10 ), $user_message );
		$this->assertStringNotContainsString( str_repeat( 'a', 11 ), $user_message );
	}

	// =========================================================================
	// Language code resolution (pure static helper)
	// =========================================================================

	public function test_resolve_language_code_returns_code_for_known_language(): void {

		$code = ChunkTranslation::resolve_language_code( 'German' );
		$this->assertSame( 'de', $code );
	}

	public function test_resolve_language_code_returns_empty_for_unknown_language(): void {

		$code = ChunkTranslation::resolve_language_code( 'Klingon' );
		$this->assertSame( '', $code );
	}

	public function test_resolve_language_code_is_case_sensitive(): void {

		// Language names are English and capitalised in Translation::LANGUAGES.
		$code = ChunkTranslation::resolve_language_code( 'german' ); // lowercase
		$this->assertSame( '', $code );
	}

	// =========================================================================
	// build_messages() pure helper
	// =========================================================================

	public function test_build_messages_non_refinement_returns_two_elements(): void {

		$messages = ChunkTranslation::build_messages( 'sys', 'prompt', false, '', '' );

		$this->assertCount( 2, $messages );
		$this->assertSame( 'sys',    $messages[0]['content'] );
		$this->assertSame( 'prompt', $messages[1]['content'] );
	}

	public function test_build_messages_refinement_returns_four_elements(): void {

		$messages = ChunkTranslation::build_messages( 'sys', 'prompt', true, 'prior', 'hint' );

		$this->assertCount( 4, $messages );
		$this->assertSame( 'prior', $messages[2]['content'] );
		$this->assertStringContainsString( 'hint', $messages[3]['content'] );
	}

	public function test_build_messages_system_prompt_is_first(): void {

		$messages = ChunkTranslation::build_messages( 'MY_SYSTEM_PROMPT', 'p', false, '', '' );

		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'MY_SYSTEM_PROMPT', $messages[0]['content'] );
	}
}
