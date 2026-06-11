<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\TermNameTranslator
 *
 * Automatically translates WooCommerce product attribute term names when a
 * product is translated or retranslated via Lingua Forge.
 *
 * Listens to the `linguaforge_translation_complete` action and, for product
 * post types, inspects all pa_* taxonomy terms attached to the source product.
 * Any term that does not yet have a `_lf_term_name_{target_lang}` entry is
 * translated in a single batched AI call (light model tier) and the results
 * are stored via `update_term_meta`.
 *
 * This ensures that attribute values (colours, sizes, materials, …) appear
 * in the customer's language immediately after the first translation, without
 * requiring manual entry in the term admin screen.  Terms that were already
 * manually translated are left untouched.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\Router\Router;
use WP_Term;

defined( 'ABSPATH' ) || exit;

class TermNameTranslator {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		add_action( 'linguaforge_translation_complete', [ self::class, 'on_translation_complete' ], 10, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Translate all untranslated pa_* term names for the source product into
	 * $target_lang.  Called after every product translation (create or update).
	 *
	 * @param int    $translated_id  Post ID of the newly-created/updated translation.
	 * @param int    $source_id      Post ID of the source (canonical) product.
	 * @param string $target_lang    Two-letter language code, e.g. 'es'.
	 */
	public static function on_translation_complete( int $translated_id, int $source_id, string $target_lang ): void {

		// ── 1. Only product post type ─────────────────────────────────────────
		$source = get_post( $source_id );
		if ( ! $source || 'product' !== $source->post_type ) {
			return;
		}

		// ── 2. Collect pa_* terms that still need translation ─────────────────
		$terms_to_translate = self::collect_untranslated_terms( $source_id, $target_lang );
		if ( empty( $terms_to_translate ) ) {
			return;
		}

		// ── 3. Translate via AI ───────────────────────────────────────────────
		$translations = self::translate_terms( $terms_to_translate, $source_id, $target_lang );
		if ( empty( $translations ) ) {
			return;
		}

		// ── 4. Persist results ────────────────────────────────────────────────
		$meta_key = TermNameFilter::META_PREFIX . $target_lang;

		foreach ( $terms_to_translate as $term_id => $source_name ) {
			if ( isset( $translations[ $source_name ] ) && '' !== (string) $translations[ $source_name ] ) {
				update_term_meta( $term_id, $meta_key, sanitize_text_field( (string) $translations[ $source_name ] ) );
			}
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return a map of [ term_id => source_name ] for every pa_* term on
	 * $source_id that does not yet have a _lf_term_name_{target_lang} entry.
	 *
	 * All qualifying term IDs are returned, including cases where two pa_*
	 * taxonomies share a term name.  Name-level deduplication for the AI
	 * prompt is handled in translate_terms() via array_unique().
	 *
	 * @param  int    $source_id   Source product post ID.
	 * @param  string $target_lang Target language code.
	 * @return array<int, string>  term_id => source term name.
	 */
	private static function collect_untranslated_terms( int $source_id, string $target_lang ): array {

		$pending  = [];
		$meta_key = TermNameFilter::META_PREFIX . $target_lang;

		foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {

			if ( ! str_starts_with( $taxonomy, 'pa_' ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'all' ] );
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {

				if ( ! ( $term instanceof WP_Term ) ) {
					continue;
				}

				// Skip if already has a translation.
				$existing = get_term_meta( $term->term_id, $meta_key, true );
				if ( '' !== $existing && false !== $existing ) {
					continue;
				}

				$pending[ $term->term_id ] = $term->name;
			}
		}

		return $pending;
	}

	/**
	 * Make a single AI call to translate all collected term names.
	 *
	 * Public so TermNameAdmin can call it for the batch-translate button without
	 * duplicating the AI call logic.
	 *
	 * @param  array<array-key, string> $pending          key => source_name map (int term_id for terms; string taxonomy for attribute labels).
	 * @param  string                   $target_lang       Target language code.
	 * @param  int                      $context_post_id   Optional product post ID passed to the
	 *                                                     linguaforge_ai_provider filter.  Pass 0
	 *                                                     when there is no product context (e.g.
	 *                                                     the batch-translate button).
	 * @param  int                      $max_tokens        Token budget for the response.  Default 256
	 *                                                     covers ~15 short term names; pass a higher
	 *                                                     value for large batches (batch-translate button).
	 * @return array<string, string>                       source_name => translated_name.
	 */
	public static function translate_term_names( array $pending, string $target_lang, int $context_post_id = 0, int $max_tokens = 256 ): array {

		$source_lang  = Router::get_instance()->source_language();
		$languages    = Translation::get_languages();
		$source_label = $languages[ $source_lang ] ?? $source_lang;
		$target_label = $languages[ $target_lang ] ?? $target_lang;

		// Build the term list (unique names only — two terms may share a name).
		$unique_names = array_unique( array_values( $pending ) );
		$names_list   = implode( "\n", array_map( static fn( string $n ) => "- {$n}", $unique_names ) );

		$prompt = "Translate the following WooCommerce product attribute values from {$source_label} to {$target_label}.\n\n"
			. "These are short labels — colours, sizes, materials, finishes, etc.\n\n"
			. "Return ONLY a valid JSON object. Keys must be the exact source values (copied verbatim); "
			. "values must be their {$target_label} translations. "
			. "If a term is already in {$target_label} or requires no translation (numbers, symbols, brand names), return it unchanged. "
			. "Do not include any text, comments, or formatting outside the JSON object.\n\n"
			. "Terms:\n{$names_list}";

		$config = Config::apply_compliance( new WorkerConfig(
			model:       Config::model( 'light' ),
			max_tokens:  $max_tokens,
			temperature: 0.2,
		) );

		/** @var AIProviderInterface $provider */
		$provider = apply_filters( 'linguaforge_ai_provider', ProviderFactory::make( $config ), $context_post_id, $config );

		$raw = UsageRecorder::tracked( 'term-name-translator', static fn() => $provider->chat( [
			[
				'role'    => 'system',
				'content' => Config::apply_compliance_to_system( 'You are a professional product attribute translator. Output valid JSON only — a single object mapping source values to translations.' ),
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		] ) );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		return self::parse_json_response( $raw );
	}

	/**
	 * Internal wrapper: translate terms collected from a specific source product.
	 *
	 * Delegates to translate_term_names() with the product ID as context so the
	 * linguaforge_ai_provider filter can be per-product when needed.
	 *
	 * @param  array<int, string> $terms_to_translate  term_id => source_name.
	 * @param  int                $source_id            Source product post ID.
	 * @param  string             $target_lang          Target language code.
	 * @return array<string, string>                    source_name => translated_name.
	 */
	private static function translate_terms( array $terms_to_translate, int $source_id, string $target_lang ): array {
		return self::translate_term_names( $terms_to_translate, $target_lang, $source_id );
	}

	/**
	 * Extract a string => string map from the model's raw output.
	 *
	 * Handles the common case where the model wraps JSON in a markdown
	 * code fence (```json … ```).
	 *
	 * @param  string $raw  Raw model output.
	 * @return array<string, string>  Decoded map, or [] on failure.
	 */
	private static function parse_json_response( string $raw ): array {

		$text = trim( $raw );

		// Strip markdown code-fence if present.
		if ( str_starts_with( $text, '`' ) ) {
			$text = (string) preg_replace( '/^```[a-z]*\s*/i', '', $text );
			$text = (string) preg_replace( '/\s*```\s*$/i', '', $text );
			$text = trim( $text );
		}

		// If there is surrounding noise, extract the first {...} block.
		if ( ! str_starts_with( $text, '{' ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$text = substr( $text, $start, $end - $start + 1 );
			}
		}

		$decoded = json_decode( $text, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		// Keep only string key => string value pairs.
		$result = [];
		foreach ( $decoded as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}
}
