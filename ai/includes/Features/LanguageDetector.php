<?php
/**
 * Class LinguaForge\AI\Features\LanguageDetector
 *
 * Small, reusable language-identification primitive. Every existing LF
 * translation flow knows its source language up front (a post's configured
 * `_lf_lang`); this is for the one case that's genuinely unknown ahead of
 * time — a visitor's comment, submitted on whatever language page they
 * happened to be viewing, in whatever language they actually typed it in.
 *
 * Deliberately not a FeatureInterface implementation — those model a
 * post-scoped, UI-triggered action (meta-box button, `run($post_id, $params)`).
 * This is a plain text-in/code-out utility, in the same "helper class under
 * Features/" shape as TranslationMemoryTranslator/JsonEnvelopeTranslator, so
 * any future feature needing language identification can call it directly
 * (built as a standalone reusable Feature per
 * lingua-forge-audit/PROPOSAL-comment-translation-2026-07-29.md's resolved
 * decision #3, not private to Comment Translation).
 *
 * @package LinguaForge\AI\Features
 * @since   2.7.0
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Log;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;

defined( 'ABSPATH' ) || exit;

class LanguageDetector {

	/**
	 * Characters of input actually sent to the model. A short excerpt is
	 * enough to identify a language reliably and keeps the call cheap —
	 * mirrors the same excerpt-length precedent used elsewhere in the AI
	 * module for lightweight classification-style calls.
	 */
	private const EXCERPT_LENGTH = 300;

	/** UsageRecorder feature key this call is tracked under in the AI Usage log. */
	private const USAGE_KEY = 'language_detection';

	/**
	 * Identify which of a candidate set of language codes a piece of text is
	 * written in.
	 *
	 * @param string   $text            Raw text to identify (HTML/markup is stripped).
	 * @param string[] $candidate_langs Language codes to choose from. Defaults to
	 *                                  every code in Translation::get_languages()
	 *                                  when omitted/empty.
	 * @return string  A code from $candidate_langs, or '' when the text is empty,
	 *                  the model call fails, or the detected language isn't in the
	 *                  candidate list (including an explicit "none of these").
	 */
	public static function detect( string $text, array $candidate_langs = [] ): string {

		$excerpt = mb_substr( wp_strip_all_tags( $text ), 0, self::EXCERPT_LENGTH );

		if ( '' === trim( $excerpt ) ) {
			return '';
		}

		$languages = $candidate_langs ?: array_keys( Translation::get_languages() );

		if ( empty( $languages ) ) {
			return '';
		}

		$config = new WorkerConfig(
			model: Config::model( 'light' ),
			max_tokens: 8,
			temperature: 0.0,
		);

		$provider = ProviderFactory::make( $config );

		$system = sprintf(
			'You are a language identification tool. Reply with EXACTLY ONE ' .
			'two-letter (or hyphenated) language code from this list, and ' .
			'nothing else — no punctuation, no explanation: %s. If the text ' .
			'does not clearly match any of these, reply with the single word NONE.',
			implode( ', ', $languages )
		);

		$result = UsageRecorder::tracked( self::USAGE_KEY, static fn () => $provider->chat( [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $excerpt ],
		] ) );

		if ( null === $result ) {
			Log::debug( 'LanguageDetector: provider call failed — ' . $provider->get_last_error() );
			return '';
		}

		// Defensive normalization — the prompt asks for a bare code, but
		// models occasionally wrap it in quotes/punctuation despite instructions.
		$code = strtolower( trim( (string) preg_replace( '/[^a-zA-Z-]/', '', trim( $result ) ) ) );

		if ( '' === $code || 'none' === $code ) {
			return '';
		}

		return in_array( $code, $languages, true ) ? $code : '';
	}
}
