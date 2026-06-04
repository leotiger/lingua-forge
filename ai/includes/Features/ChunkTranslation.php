<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Core\UsageRecorder;

defined( 'ABSPATH' ) || exit;

/**
 * Quick-translate (chunk) handler — translates a plain-text snippet via a
 * single AI call. Supports multi-turn refinement.
 *
 * Extracted from Translation::run_chunk() so it can be unit-tested with a
 * mock AIProviderInterface without a WordPress runtime.
 *
 * Callers (Translation::run_chunk(), FeatureController via the Translation
 * instance) remain unchanged — Translation::run_chunk() creates the provider
 * from ProviderFactory and delegates here.
 */
class ChunkTranslation {

	public function __construct( private readonly AIProviderInterface $provider ) {}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Translate a short text snippet into the target language.
	 *
	 * @param  string               $language_name  English name of the target language (e.g. "German").
	 * @param  array<string, mixed> $params         Request params:
	 *   - chunk_text      (string)  Text to translate.
	 *   - refine_hint     (string)  Optional: refinement instruction for multi-turn.
	 *   - previous_output (string)  Optional: prior translation output for multi-turn.
	 * @return array<string, mixed>  Always contains 'success' (bool). On success:
	 *   'output' (string), 'type' ('chunk'), 'language' (string), 'cached' (bool, only on cache hit).
	 *   On failure: 'error' (string).
	 */
	public function run( string $language_name, array $params ): array {

		// ── 1. Validate input ──────────────────────────────────────────────────
		$chunk_text = trim( wp_unslash( (string) ( $params['chunk_text'] ?? '' ) ) );

		if ( $chunk_text === '' ) {
			return [
				'success' => false,
				'error'   => 'No text provided. Paste a snippet into the "Text to translate" field.',
			];
		}

		// ── 2. Load prompt template ────────────────────────────────────────────
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local prompt-template read from the plugin's own assets directory; not a remote URL.
		$prompt_template = file_get_contents(
			LINGUAFORGE_AI_PATH . '/templates/prompts/translation_chunk.txt'
		);

		if ( $prompt_template === false ) {
			return [
				'success' => false,
				'error'   => 'Chunk prompt template not found.',
			];
		}

		// ── 3. Build prompt ────────────────────────────────────────────────────
		$prompt = str_replace(
			[ '{{language}}', '{{chunk_text}}' ],
			[ $language_name, mb_substr( $chunk_text, 0, Config::quick_translate_max_input_chars() ) ],
			$prompt_template
		);

		// ── 4. Build system prompt + glossary injection ────────────────────────
		$system_prompt = Config::apply_compliance_to_system(
			'You are a professional translator. ' .
			'Output only the translated text — no commentary, no preamble.'
		);

		// Chunk mode auto-detects source language, so we only pull wildcard
		// glossary entries (source_lang = '') that apply regardless of source
		// — brand names, language-agnostic abbreviations like "kWp".
		$target_code = self::resolve_language_code( $language_name );
		if ( $target_code !== '' ) {
			$glossary = Glossary::format_for_prompt( '', $target_code );
			if ( $glossary !== '' ) {
				$system_prompt .= "\n\n" . $glossary;
			}
		}

		// ── 5. Refinement detection ────────────────────────────────────────────
		// When the JS sends back the previous output + a refinement instruction
		// we build a multi-turn conversation so the model improves its own prior
		// translation rather than starting from scratch.
		$refine_hint     = mb_substr( trim( sanitize_textarea_field( (string) ( $params['refine_hint'] ?? '' ) ) ), 0, 2000 );
		$previous_output = trim( (string) ( $params['previous_output'] ?? '' ) );
		$is_refinement   = $refine_hint !== '' && $previous_output !== '';

		// ── 6. Cache check (non-refinement only) ──────────────────────────────
		// run() is not post-bound, so we use post_id = 0 as a synthetic key
		// and derive the feature key from the target language code.
		// Refinements are intentionally excluded — they depend on a prior
		// output that is not part of the hash and must never be served stale.
		$chunk_cache_key = 'chunk_' . sanitize_key( $target_code ?: $language_name );
		$chunk_hash      = CacheStore::hash( [
			$chunk_text,
			$language_name,
			Config::provider(),
			Config::model( Config::quick_translate_tier() ),
		] );

		if ( ! $is_refinement ) {
			$chunk_cached = CacheStore::get( 0, $chunk_cache_key, $chunk_hash );
			if ( $chunk_cached !== null ) {
				return array_merge( [ 'success' => true, 'cached' => true ], $chunk_cached );
			}
		}

		// ── 7. Build message array ─────────────────────────────────────────────
		$messages = self::build_messages( $system_prompt, $prompt, $is_refinement, $previous_output, $refine_hint );

		// ── 8. Call AI provider ────────────────────────────────────────────────
		$result = UsageRecorder::tracked( 'translation-chunk', fn() => $this->provider->chat( $messages ) );

		if ( empty( $result ) ) {
			return [
				'success' => false,
				'error'   => 'Translation failed. Please try again.',
			];
		}

		// ── 9. Assemble payload + persist cache ────────────────────────────────
		$chunk_payload = [
			'output'   => trim( $result ),
			'type'     => 'chunk',
			'language' => $language_name,
		];

		if ( ! $is_refinement ) {
			CacheStore::set( 0, $chunk_cache_key, $chunk_hash, $chunk_payload );
		}

		return array_merge( [ 'success' => true ], $chunk_payload );
	}

	// =========================================================================
	// Pure helpers (static — testable without a provider instance)
	// =========================================================================

	/**
	 * Resolve a language code (e.g. 'de') from an English language name (e.g. 'German').
	 * Returns '' if the name is not in the supported languages map.
	 */
	public static function resolve_language_code( string $language_name ): string {
		foreach ( Translation::get_languages() as $code => $label ) {
			if ( $label === $language_name ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Assemble the messages array for the provider chat() call.
	 *
	 * Non-refinement: 2-element array (system + user).
	 * Refinement:     4-element array (system + user + assistant + user[refine]).
	 *
	 * @return list<array{role:string,content:string}>
	 */
	public static function build_messages(
		string $system_prompt,
		string $prompt,
		bool   $is_refinement,
		string $previous_output,
		string $refine_hint
	): array {

		if ( $is_refinement ) {
			return [
				[ 'role' => 'system',    'content' => $system_prompt ],
				[ 'role' => 'user',      'content' => $prompt ],
				[ 'role' => 'assistant', 'content' => $previous_output ],
				[ 'role' => 'user',      'content' =>
					"Please refine the translation above based on these additional instructions:\n\n" .
					$refine_hint ],
			];
		}

		return [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $prompt ],
		];
	}
}
