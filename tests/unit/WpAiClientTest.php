<?php
/**
 * Unit tests for LinguaForge\AI\Providers\WpAiClient.
 *
 * AUDIT-2026-07-11 §1.3 / §12 row 9 asked for two things: (1) verify every
 * builder method name/signature WpAiClient::chat() calls against the *final*
 * WordPress 7.0 AI Client API (it was originally written against a March-2026
 * preview post), and (2) "add a mock-based unit test that stubs a
 * wp_ai_client_prompt() builder double and asserts the message→builder
 * mapping (system instruction, history slice, last-message prompt,
 * temperature/max_tokens, JSON-schema), independent of any connector."
 *
 * (1) was done by reading the shipped
 * wp-includes/ai-client/class-wp-ai-client-prompt-builder.php and its
 * underlying WordPress\AiClient\Builders\PromptBuilder source directly — see
 * the class docblock in WpAiClient.php for the full verification note. One
 * real bug was found in the process: with_history() requires a variadic list
 * of WordPress\AiClient\Messages\DTO\Message objects, not the
 * ['role' => ..., 'content' => ...] array shape this codebase's other
 * providers use. Passing a plain array threw an uncaught PHP TypeError
 * (with_history() isn't one of the builder wrapper's "generating" methods, so
 * its own catch(Exception) never even had a chance to catch a TypeError,
 * which isn't an Exception subtype in the first place). Fixed via the new
 * WpAiClient::build_history_messages() helper.
 *
 * (2) is this file, plus three small companion bootstrap files it requires:
 *
 *   - WpAiClientSdkStubs.php        — stand-in WordPress\AiClient\Messages\DTO\*
 *                                     classes (real ones only exist on WP 7.0+).
 *   - FakeAiClientPromptBuilder.php — the recording double wp_ai_client_prompt()
 *                                     returns instead of a real builder.
 *   - WpAiClientGlobalPolyfills.php — the wp_ai_client_prompt()/__()/is_wp_error()
 *                                     stubs + WP_Error, which must live in the
 *                                     global namespace.
 *
 * Split into separate single-namespace files (rather than one file with
 * bracketed multi-namespace blocks) to satisfy this project's phpcs ruleset,
 * which forbids curly-brace namespace syntax and more than one namespace
 * declaration per file.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Providers\WpAiClient;
use LinguaForge\AI\Providers\WorkerConfig;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once __DIR__ . '/WpAiClientSdkStubs.php';
require_once __DIR__ . '/FakeAiClientPromptBuilder.php';
require_once __DIR__ . '/WpAiClientGlobalPolyfills.php';

require_once LINGUAFORGE_AI_PATH . '/includes/Contracts/AIProviderInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/WorkerConfig.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/WpAiClient.php';

/**
 * @covers \LinguaForge\AI\Providers\WpAiClient::chat
 * @covers \LinguaForge\AI\Providers\WpAiClient::build_history_messages
 */
class WpAiClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_wp_ai_client_builder']        = null;
		$GLOBALS['lf_test_wp_ai_client_builder_config'] = [];
	}

	private function make_provider( ?array $schema = null, float $temperature = 0.4, int $max_tokens = 1024 ): WpAiClient {
		$config = new WorkerConfig( 'wp-ai-client', $max_tokens, $temperature, $schema );
		return new WpAiClient( $config );
	}

	private function captured_builder(): FakeAiClientPromptBuilder {
		$builder = $GLOBALS['lf_test_wp_ai_client_builder'] ?? null;
		$this->assertInstanceOf( FakeAiClientPromptBuilder::class, $builder );
		return $builder;
	}

	// ── System instruction + last-message prompt ─────────────────────────────

	public function test_system_message_maps_to_using_system_instruction(): void {

		$provider = $this->make_provider();
		$provider->chat(
			[
				[ 'role' => 'system', 'content' => 'You are a translator.' ],
				[ 'role' => 'user', 'content' => 'Translate: hello' ],
			]
		);

		$builder = $this->captured_builder();
		$this->assertSame( 'You are a translator.', $builder->system_instruction );
		$this->assertSame( 'Translate: hello', $builder->prompt_text );
	}

	public function test_no_system_message_leaves_system_instruction_unset(): void {

		$provider = $this->make_provider();
		$provider->chat( [ [ 'role' => 'user', 'content' => 'Translate: hello' ] ] );

		$builder = $this->captured_builder();
		$this->assertNull( $builder->system_instruction );
	}

	public function test_last_message_becomes_prompt_even_with_multiple_user_turns(): void {

		$provider = $this->make_provider();
		$provider->chat(
			[
				[ 'role' => 'system', 'content' => 'sys' ],
				[ 'role' => 'user', 'content' => 'first turn' ],
				[ 'role' => 'assistant', 'content' => 'draft reply' ],
				[ 'role' => 'user', 'content' => 'refine please' ],
			]
		);

		$builder = $this->captured_builder();
		$this->assertSame( 'refine please', $builder->prompt_text );
	}

	// ── History slice → with_history() mapping ───────────────────────────────

	public function test_single_turn_prompt_calls_with_history_with_nothing(): void {

		$provider = $this->make_provider();
		$provider->chat( [ [ 'role' => 'user', 'content' => 'only turn' ] ] );

		$builder = $this->captured_builder();
		$this->assertSame( [], $builder->history );
	}

	public function test_refinement_history_is_passed_as_message_objects_not_arrays(): void {

		$provider = $this->make_provider();
		$provider->chat(
			[
				[ 'role' => 'system', 'content' => 'sys' ],
				[ 'role' => 'user', 'content' => 'original prompt' ],
				[ 'role' => 'assistant', 'content' => 'previous draft' ],
				[ 'role' => 'user', 'content' => 'refine hint' ],
			]
		);

		$builder = $this->captured_builder();

		// This is the bug this session fixed: with_history() must never
		// receive plain ['role'=>..,'content'=>..] arrays — the real SDK's
		// with_history(Message ...$messages) would fatal with a TypeError.
		$this->assertCount( 2, $builder->history );
		foreach ( $builder->history as $message ) {
			$this->assertIsObject( $message );
			$this->assertIsNotArray( $message );
		}

		// Role mapping: 'user' -> UserMessage, 'assistant' -> ModelMessage
		// (wp-ai-client's MessageRoleEnum only has USER and MODEL).
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $builder->history[0] );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\ModelMessage::class, $builder->history[1] );

		// Order preserved, content carried through correctly.
		$this->assertSame( 'original prompt', $builder->history[0]->parts[0]->text );
		$this->assertSame( 'previous draft', $builder->history[1]->parts[0]->text );

		// And the refine hint itself became the prompt, not part of history.
		$this->assertSame( 'refine hint', $builder->prompt_text );
	}

	public function test_history_conversion_skips_empty_content_turns(): void {

		$provider = $this->make_provider();
		$provider->chat(
			[
				[ 'role' => 'user', 'content' => '' ],
				[ 'role' => 'assistant', 'content' => 'kept' ],
				[ 'role' => 'user', 'content' => 'final prompt' ],
			]
		);

		$builder = $this->captured_builder();
		$this->assertCount( 1, $builder->history );
		$this->assertSame( 'kept', $builder->history[0]->parts[0]->text );
	}

	// ── Temperature / max tokens / JSON schema ───────────────────────────────

	public function test_temperature_and_max_tokens_come_from_worker_config(): void {

		$provider = $this->make_provider( null, 0.75, 2048 );
		$provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$builder = $this->captured_builder();
		$this->assertSame( 0.75, $builder->temperature );
		$this->assertSame( 2048, $builder->max_tokens );
	}

	public function test_json_schema_triggers_as_json_response_with_schema(): void {

		$schema   = [ 'type' => 'object', 'properties' => [ 'foo' => [ 'type' => 'string' ] ] ];
		$provider = $this->make_provider( $schema );
		$provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$builder = $this->captured_builder();
		$this->assertTrue( $builder->json_response_called );
		$this->assertSame( $schema, $builder->json_schema );
	}

	public function test_null_schema_never_calls_as_json_response(): void {

		$provider = $this->make_provider( null );
		$provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$builder = $this->captured_builder();
		$this->assertFalse( $builder->json_response_called );
	}

	// ── Capability check + generation outcomes ───────────────────────────────

	public function test_unsupported_capability_returns_null_with_last_error(): void {

		$GLOBALS['lf_test_wp_ai_client_builder_config'] = [
			'supported_for_text_generation' => false,
		];

		$provider = $this->make_provider();
		$result   = $provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertNull( $result );
		$this->assertNotSame( '', $provider->get_last_error() );
	}

	public function test_wp_error_from_generate_text_returns_null_with_error_message(): void {

		$GLOBALS['lf_test_wp_ai_client_builder_config'] = [
			'generate_text_result' => new \WP_Error( 'quota_exceeded', 'Provider quota exceeded.' ),
		];

		$provider = $this->make_provider();
		$result   = $provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertNull( $result );
		$this->assertSame( 'Provider quota exceeded.', $provider->get_last_error() );
	}

	public function test_empty_text_result_returns_null(): void {

		$GLOBALS['lf_test_wp_ai_client_builder_config'] = [
			'generate_text_result' => '   ',
		];

		$provider = $this->make_provider();
		$result   = $provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertNull( $result );
	}

	public function test_successful_text_result_is_trimmed_and_returned(): void {

		$GLOBALS['lf_test_wp_ai_client_builder_config'] = [
			'generate_text_result' => "  Bonjour le monde  \n",
		];

		$provider = $this->make_provider();
		$result   = $provider->chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertSame( 'Bonjour le monde', $result );
		$this->assertSame( '', $provider->get_last_error() );
	}

	public function test_no_user_message_returns_null_without_calling_builder(): void {

		$provider = $this->make_provider();
		$result   = $provider->chat( [ [ 'role' => 'system', 'content' => 'sys only' ] ] );

		$this->assertNull( $result );
		$this->assertNotSame( '', $provider->get_last_error() );
		$this->assertNull( $GLOBALS['lf_test_wp_ai_client_builder'] );
	}
}
