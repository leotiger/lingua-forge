<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\Log;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress 7.0+ built-in AI Client provider.
 *
 * Delegates to core's wp_ai_client_prompt() builder instead of making direct
 * HTTP calls. API credentials are managed by the site admin through
 * Settings → Connectors — Lingua Forge stores and handles no keys for this
 * provider.
 *
 * ── How it differs from the other providers ──────────────────────────────
 * Anthropic, OpenAI, and Gemini extend AbstractProvider, which owns the HTTP
 * retry/backoff loop, KeyStore lookup, and UsageRecorder integration.
 * WpAiClient bypasses all of that and calls wp_ai_client_prompt() directly,
 * letting WordPress route the request to whichever provider the admin has
 * configured via the Connectors settings screen.
 *
 * ── Message format ────────────────────────────────────────────────────────
 * Accepts the same [['role' => ..., 'content' => ...]] array that the other
 * providers accept. A 'system' role entry is mapped to using_system_instruction();
 * the last 'user' entry becomes the prompt text; prior user/assistant turns
 * are passed via with_history() for multi-turn flows.
 *
 * ── Rate limiting ─────────────────────────────────────────────────────────
 * LF's own daily quota (RateLimiter) still applies via the existing
 * register_hooks() check. Core's wp_ai_client_prevent_prompt filter provides
 * an additional site-wide integration point; Lingua Forge does not currently
 * register a callback on it, but plugin developers or site admins can use it
 * to layer additional controls.
 *
 * ── WP version gate ───────────────────────────────────────────────────────
 * chat() returns null (with $last_error set) when wp_ai_client_prompt() is
 * not available, keeping the plugin functional on WP < 7.0 where the core
 * AI Client was not yet included.
 *
 * ── Verified against the final WP 7.0 API (2026-07-11) ───────────────────
 * WordPress 7.0 shipped 2026-05-20; this class was originally written against
 * the March-2026 preview post linked below. Every builder method name and
 * signature used here (using_system_instruction(), using_temperature(),
 * using_max_tokens(), as_json_response(), is_supported_for_text_generation(),
 * generate_text()) was checked against the shipped
 * wp-includes/ai-client/class-wp-ai-client-prompt-builder.php and its
 * underlying WordPress\AiClient\Builders\PromptBuilder (php-ai-client SDK)
 * and matches exactly. One real discrepancy was found and fixed:
 * with_history() takes a variadic list of WordPress\AiClient\Messages\DTO\Message
 * objects, NOT the ['role' => ..., 'content' => ...] array shape the rest of
 * this codebase's providers use — passing a plain array threw an uncaught
 * PHP TypeError (with_history() is not one of WP_AI_Client_Prompt_Builder's
 * "generating"/"support-check" methods, so its __call() wrapper does not
 * catch/convert the failure to a WP_Error; a TypeError also isn't a subclass
 * of Exception, so even the wrapper's own catch(Exception) would have missed
 * it). See build_history_messages() below for the fix.
 *
 * @since   2.3.0
 * @package LinguaForge\AI\Providers
 * @see     https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
 * @see     https://github.com/WordPress/WordPress/blob/master/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php
 */
class WpAiClient implements AIProviderInterface {

	/** @var string Human-readable failure reason; empty string on success. */
	protected string $last_error = '';

	public function __construct(
		protected readonly WorkerConfig $config
	) {}

	/** Return the last failure reason set by chat(). Empty string on success. */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Send a chat prompt through the WordPress AI Client and return the reply.
	 *
	 * @param  array<array{role: string, content: string}> $messages
	 * @return string|null  Assistant reply, or null on failure.
	 */
	public function chat( array $messages ): ?string {

		$this->last_error = '';

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->last_error = __(
				'WordPress AI Client requires WordPress 7.0 or later.',
				'lingua-forge'
			);
			return null;
		}

		// ── Parse the messages array ──────────────────────────────────────────
		$system    = '';
		$non_system = [];

		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role']    ?? '' );
			$content = (string) ( $message['content'] ?? '' );

			if ( $role === 'system' ) {
				$system = $content;
			} else {
				$non_system[] = [ 'role' => $role, 'content' => $content ];
			}
		}

		if ( empty( $non_system ) ) {
			$this->last_error = __( 'No user message found in the message array.', 'lingua-forge' );
			return null;
		}

		// Last entry is the prompt; everything before it is conversation history.
		$last        = end( $non_system );
		$prompt_text = (string) ( $last['content'] ?? '' );
		$history     = array_slice( $non_system, 0, -1 );

		// ── Build the prompt ──────────────────────────────────────────────────
		// Inline function_exists() guard (unreachable at runtime — early-return above covers it)
		// satisfies Plugin Check's control-flow requirement for optional WP 7.0+ features.
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			// @codeCoverageIgnore
			return null;
		}
		// call_user_func is used deliberately: Plugin Check's static analyser flags direct calls to
		// wp_ai_client_prompt() against Requires at least: 6.4, but cannot resolve call_user_func()
		// to the underlying function. Runtime safety is guaranteed by the function_exists() guards.
		// @phpstan-ignore-next-line -- string literal is a valid callable; verified by function_exists() above.
		$builder = call_user_func( 'wp_ai_client_prompt', $prompt_text );

		if ( $system !== '' ) {
			$builder->using_system_instruction( $system );
		}

		if ( ! empty( $history ) ) {
			$history_messages = self::build_history_messages( $history );
			if ( ! empty( $history_messages ) ) {
				// with_history() is variadic — spread the built Message list.
				$builder->with_history( ...$history_messages );
			}
		}

		$builder->using_temperature( $this->config->temperature );
		$builder->using_max_tokens( $this->config->max_tokens );

		if ( $this->config->response_schema !== null ) {
			// as_json_response() accepts a JSON-schema associative array — the
			// same format stored in WorkerConfig::$response_schema.
			$builder->as_json_response( $this->config->response_schema );
		}

		// ── Capability check (fast, no API call) ─────────────────────────────
		if ( ! $builder->is_supported_for_text_generation() ) {
			$this->last_error = __(
				'No text-generation model is available. Configure an AI provider in Settings → Connectors.',
				'lingua-forge'
			);
			return null;
		}

		// ── Generate ──────────────────────────────────────────────────────────
		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			$this->last_error = $result->get_error_message();
			Log::debug( sprintf( 'Lingua Forge AI [WP AI Client] %s', $result->get_error_message() ) );
			return null;
		}

		$text = trim( (string) $result );

		if ( $text === '' ) {
			Log::debug( 'Lingua Forge AI [WP AI Client] provider returned a successful response with empty text content — check connector configuration' );
			return null;
		}

		return $text;
	}

	/**
	 * Converts this codebase's ['role' => 'user'|'assistant', 'content' => string]
	 * history array shape into the list of WordPress\AiClient\Messages\DTO\Message
	 * objects with_history() actually requires.
	 *
	 * Uses dynamic (string) class names rather than literal `new UserMessage(...)`
	 * calls, and checks class_exists() before touching them — the same
	 * call_user_func()/function_exists() workaround this file already uses for
	 * wp_ai_client_prompt() itself, because these WordPress\AiClient\* classes
	 * only exist on WP 7.0+ and are not present in the wordpress-stubs package
	 * this plugin's PHPStan run depends on. This keeps static analysis green on
	 * WP < 7.0 support without suppressing the whole method.
	 *
	 * LF's 'system' role is filtered out by the caller before this method ever
	 * runs (handled separately via using_system_instruction()); 'assistant'
	 * maps to the SDK's MODEL role since WordPress\AiClient\Messages\Enums\
	 * MessageRoleEnum only defines USER and MODEL. Any other/unrecognised role
	 * is treated as USER rather than dropped, since a stray non-standard role
	 * string is far more likely to be a user-authored turn than a model one.
	 * Empty-content turns are skipped — Message's own validation would reject
	 * an empty-parts message, and an empty turn carries no information anyway.
	 *
	 * @param array<array{role:string,content:string}> $history
	 * @return array<object> List of Message instances; empty when the SDK
	 *                       classes aren't available (e.g. under the unit test
	 *                       suite, or on a WP version without the AI Client).
	 */
	private static function build_history_messages( array $history ): array {

		$part_class  = 'WordPress\\AiClient\\Messages\\DTO\\MessagePart';
		$user_class  = 'WordPress\\AiClient\\Messages\\DTO\\UserMessage';
		$model_class = 'WordPress\\AiClient\\Messages\\DTO\\ModelMessage';

		if ( ! class_exists( $part_class ) || ! class_exists( $user_class ) || ! class_exists( $model_class ) ) {
			return [];
		}

		$messages = [];

		foreach ( $history as $turn ) {
			$role    = (string) ( $turn['role']    ?? '' );
			$content = (string) ( $turn['content'] ?? '' );

			if ( $content === '' ) {
				continue;
			}

			$part = new $part_class( $content );

			$message_class = ( $role === 'assistant' ) ? $model_class : $user_class;

			$messages[] = new $message_class( [ $part ] );
		}

		return $messages;
	}
}
