<?php
/**
 * Test stub for LinguaForge\AI\Integrations\WooCommerce\TermNameTranslator.
 *
 * Loaded before LocalAttributeTranslator.php in unit tests so that calls to
 * TermNameTranslator::translate_term_names() hit this stub instead of the real
 * implementation (which requires a live AI provider, Router options, etc.).
 *
 * Usage in test setUp():
 *   TermNameTranslatorStub::reset();
 *   TermNameTranslatorStub::$stub_result = [ 'Red' => 'Rojo', 'Color' => 'Color' ];
 *
 * @package LinguaForge\Tests\Unit\WooCommerce\Stubs
 */

declare(strict_types=1);

namespace LinguaForge\AI\Integrations\WooCommerce;

/**
 * Stub replaces the real TermNameTranslator for unit tests.
 * Defined in the same namespace so LocalAttributeTranslator resolves it without
 * any `use` statement change.
 */
// phpcs:ignore Generic.Classes.DuplicateClassName.Found -- intentional stub that shadows the production class for unit tests.
class TermNameTranslator {

	/**
	 * Configurable return value for translate_term_names().
	 * Shape: [ source_string => translated_string ]
	 *
	 * @var array<string, string>
	 */
	public static array $stub_result = [];

	/**
	 * Captures the arguments passed to the last translate_term_names() call.
	 * Allows tests to assert that the correct batch was submitted.
	 *
	 * @var array{pending: array<array-key,string>, target_lang: string, context_post_id: int}
	 */
	public static array $last_call = [];

	/**
	 * Number of times translate_term_names() has been called in the current test.
	 *
	 * @var int
	 */
	public static int $call_count = 0;

	/**
	 * Reset all stub state between tests.
	 */
	public static function reset(): void {
		self::$stub_result = [];
		self::$last_call   = [];
		self::$call_count  = 0;
	}

	/**
	 * Stub implementation — returns $stub_result without making any AI call.
	 *
	 * @param  array<array-key, string> $pending         key => source_string map (same shape as real).
	 * @param  string                   $target_lang     Target language code.
	 * @param  int                      $context_post_id Optional product post ID (captured but unused).
	 * @param  int                      $max_tokens      Token budget (captured but unused).
	 * @return array<string, string>    source_string => translated_string.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $max_tokens accepted for signature compatibility; unused in stub.
	public static function translate_term_names(
		array $pending,
		string $target_lang,
		int $context_post_id = 0,
		int $max_tokens = 256
	): array {
		self::$call_count++;
		self::$last_call = [
			'pending'         => $pending,
			'target_lang'     => $target_lang,
			'context_post_id' => $context_post_id,
		];
		return self::$stub_result;
	}
}
