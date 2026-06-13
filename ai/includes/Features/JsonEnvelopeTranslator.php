<?php
/**
 * Class LinguaForge\AI\Features\JsonEnvelopeTranslator
 *
 * Encapsulates the JSON-envelope translation path of Translation::run().
 * Extracted from Translation.php in 2.1.9 alongside TranslationMemoryTranslator.
 *
 * Responsibilities:
 *   • Building the schema-constrained WorkerConfig for a full-post translate call
 *   • Making the AI API call and handling empty/failed responses
 *   • Delegating response parsing to parse_full_post_envelope()
 *   • Applying the linguaforge_translation_content filter
 *   • Writing the result to CacheStore
 *
 * Note: meta description chaining (with_meta_description flag) is handled by
 * Translation::run() after both the TM and JSON-envelope paths, so it is NOT
 * performed inside translate() here.
 *
 * build_translation_schema() and parse_full_post_envelope() are public static
 * so unit tests can call them directly without reflection.
 *
 * @package LinguaForge\AI\Features
 * @since   2.1.9
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\JsonRepair;
use LinguaForge\AI\Core\TranslationDebug;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;

defined( 'ABSPATH' ) || exit;

class JsonEnvelopeTranslator {

    /**
     * @param WorkerConfig $base_config   Base WorkerConfig (model/tokens/temperature)
     *                                    resolved by Translation before instantiation.
     * @param string       $system_prompt Fully-built system prompt including compliance
     *                                    addendum and glossary injection.
     */
    public function __construct(
        private readonly WorkerConfig $base_config,
        private readonly string       $system_prompt
    ) {}

    // =========================================================================
    // Orchestration
    // =========================================================================

    /**
     * Execute the JSON-envelope translation path for a single post.
     *
     * Returns a success payload array on success, or an error array on failure.
     * Meta description chaining is NOT performed here — Translation::run() handles
     * it for both the TM and JSON-envelope paths after this returns.
     *
     * @param  \WP_Post $post            Source post object.
     * @param  int      $post_id         Source post ID.
     * @param  array    $ctx             Context from prepare_full_post_inputs() — must
     *                                   include 'prompt', 'has_footnotes', 'has_attrs',
     *                                   'has_excerpt', 'source_lang', 'attr_map'.
     * @param  string   $cache_key       Cache feature key (e.g. 'translation_de').
     * @param  string   $hash            Cache content hash.
     * @param  string   $language_name   Human-readable target language (e.g. 'German').
     * @param  string   $target_language Two-letter target language code (e.g. 'de').
     * @param  array    $params          Original request parameters.
     * @return array<string,mixed>
     */
    public function translate(
        \WP_Post $post,
        int      $post_id,
        array    $ctx,
        string   $cache_key,
        string   $hash,
        string   $language_name,
        string   $target_language,
        array    $params
    ): array {

        $worker_config = new WorkerConfig(
            model:           $this->base_config->model,
            max_tokens:      $this->base_config->max_tokens,
            temperature:     $this->base_config->temperature,
            response_schema: self::build_translation_schema( $ctx['has_footnotes'], $ctx['has_attrs'], $ctx['has_excerpt'] ),
        );
        $worker_config = apply_filters( 'linguaforge_translation_worker_config', $worker_config, $post_id, $params );

        /**
         * Filters the AI provider instance for the JSON-envelope translation path.
         *
         * Allows integration tests and site code to substitute a custom
         * AIProviderInterface implementation without a live API key.
         *
         * @param \LinguaForge\AI\Contracts\AIProviderInterface $provider The default provider.
         * @param int                                           $post_id  Source post ID.
         * @param WorkerConfig                                  $config   Worker config.
         */
        $provider = apply_filters( 'linguaforge_ai_provider', ProviderFactory::make( $worker_config ), $post_id, $worker_config );

        if ( TranslationDebug::debug_enabled() ) {
            TranslationDebug::debug_write( $post_id, $target_language, 'source', $ctx['prompt'] );
        }

        $system_prompt = $this->system_prompt;
        $result        = UsageRecorder::tracked( 'translation', static fn() => $provider->chat( [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user',   'content' => $ctx['prompt'] ],
        ] ) );

        if ( TranslationDebug::debug_enabled() ) {
            TranslationDebug::debug_write( $post_id, $target_language, 'response', (string) $result );
        }

        if ( empty( $result ) ) {
            $provider_error = $provider->get_last_error();
            return [
                'success' => false,
                'error'   => $provider_error !== ''
                    ? $provider_error
                    : 'Translation failed. Please try again.',
            ];
        }

        $ctx['language_name'] = $language_name;
        $payload = self::parse_full_post_envelope( (string) $result, $post_id, $ctx );

        if ( empty( $payload['output'] ) ) {
            return $payload; // error array from parser
        }

        /**
         * Filters the translated content payload before it is cached and returned.
         *
         * Runs after the AI response has been validated, footnotes and block
         * attributes re-inserted, and stray `<br>` tags stripped — but before the
         * payload is stored in the translation cache or sent back to the caller.
         * Changes made here are reflected in the cache, in the REST response (editor
         * flow), and in the post saved server-side (CLI flow).
         *
         * @param array  $payload         Keys: `output`, `type`, `language`, and
         *                                optionally `translated_title`, `footnotes`.
         * @param int    $post_id         Source post ID being translated.
         * @param string $target_language Two-letter target language code.
         */
        $payload = (array) apply_filters( 'linguaforge_translation_content', $payload, $post_id, $target_language );

        CacheStore::set( $post_id, $cache_key, $hash, $payload );

        return array_merge( [ 'success' => true ], $payload );
    }

    // =========================================================================
    // Pure static helpers — public so unit tests can call them directly
    // =========================================================================

    /**
     * Build the JSON response schema for a full-post translation call.
     *
     * Title and content are always required; excerpt, footnotes, and attrs are
     * added conditionally based on what the post actually contains.
     *
     * @param  bool  $has_footnotes Whether the post has WP core footnotes.
     * @param  bool  $has_attrs     Whether the post has block-attribute placeholders.
     * @param  bool  $has_excerpt   Whether the post has a non-empty excerpt.
     * @return array<string, mixed> JSON-schema-shaped associative array.
     */
    public static function build_translation_schema( bool $has_footnotes, bool $has_attrs, bool $has_excerpt = false ): array {

        $properties = [
            'title'   => [
                'type'        => 'string',
                'description' => 'Translated post title (plain text, no HTML).',
            ],
            'content' => [
                'type'        => 'string',
                'description' => 'Translated post body with all WordPress block comments, HTML tags, shortcodes, __WPAI_N__ placeholders, and _lfid values preserved exactly as in the source.',
            ],
        ];

        $required = [ 'title', 'content' ];

        if ( $has_excerpt ) {
            $properties['excerpt'] = [
                'type'        => 'string',
                'description' => 'Translated product short description / post excerpt. Translate all visible text; preserve any HTML tags exactly as they appear in the source.',
            ];
            $required[] = 'excerpt';
        }

        if ( $has_footnotes ) {
            $properties['footnotes'] = [
                'type'        => 'array',
                'description' => 'Translated WordPress core footnotes. Each item keeps its original "id" and supplies a translated "content" string.',
                'items'       => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'      => [ 'type' => 'string' ],
                        'content' => [ 'type' => 'string' ],
                    ],
                    'required'             => [ 'id', 'content' ],
                    'additionalProperties' => false,
                ],
            ];
            $required[] = 'footnotes';
        }

        if ( $has_attrs ) {
            $properties['attrs'] = [
                'type'                 => 'object',
                'description'          => 'Translations for the __WPAI_N__ placeholders used in block-comment attribute strings. Keys must match the placeholder keys exactly; values are the translated strings.',
                'additionalProperties' => [ 'type' => 'string' ],
            ];
            $required[] = 'attrs';
        }

        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Decode and validate the JSON envelope returned by the AI provider for a
     * full-post translation, then assemble the payload array.
     *
     * Strips Markdown code fences via JsonRepair, validates the decoded shape,
     * extracts all fields, reinserts block-attribute placeholder translations,
     * and strips stray inter-block <br> tags.
     *
     * Returns the assembled payload (output, type, language, + optional fields)
     * on success. Returns a standard error array (success => false) on failure.
     *
     * @param  string $result   Raw string returned by the AI provider.
     * @param  int    $post_id  Source post ID (for error logging).
     * @param  array  $ctx      Context from prepare_full_post_inputs() + 'language_name'.
     * @return array
     */
    public static function parse_full_post_envelope( string $result, int $post_id, array $ctx ): array {

        $normalised = JsonRepair::normalise_json_response( $result );
        $envelope   = json_decode( $normalised, true );

        if ( ! is_array( $envelope ) ) {
            $looks_truncated = str_starts_with( $normalised, '{' ) && ! str_ends_with( $normalised, '}' );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log.
            error_log( sprintf(
                'Lingua Forge AI [Translation] post %d: response was not valid JSON%s. First 200 chars: %s',
                $post_id,
                $looks_truncated ? ' (response appears truncated — raise Max output tokens)' : '',
                mb_substr( $normalised, 0, 200 )
            ) );
            return [
                'success' => false,
                'error'   => $looks_truncated
                    ? 'Translation output truncated — the translated content exceeded the output token limit. '
                      . 'Raise "Max output tokens" in Settings → Lingua Forge → Translation Limits, '
                      . 'or pass --max-tokens=20000 on the CLI.'
                    : 'Translation failed: provider returned an unparseable response. Check the PHP error log.',
            ];
        }

        $translated_title     = isset( $envelope['title'] )   ? trim( (string) $envelope['title'] )   : null;
        $translated_content   = isset( $envelope['content'] ) ? trim( (string) $envelope['content'] ) : '';
        $translated_footnotes = null;
        $translated_excerpt   = ( $ctx['has_excerpt'] && isset( $envelope['excerpt'] ) )
            ? (string) $envelope['excerpt']
            : null;

        if ( $translated_content === '' ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for empty content.
            error_log( sprintf( 'Lingua Forge AI [Translation] post %d: JSON envelope decoded but "content" was empty.', $post_id ) );
            return [ 'success' => false, 'error' => 'Translation failed: empty translated content. Please try again.' ];
        }

        if ( $ctx['has_footnotes'] && isset( $envelope['footnotes'] ) && is_array( $envelope['footnotes'] ) ) {
            $translated_footnotes = wp_json_encode( $envelope['footnotes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        if ( $ctx['has_attrs'] && isset( $envelope['attrs'] ) && is_array( $envelope['attrs'] ) ) {
            $translated_content = BlockTextExtractor::reinsert( $translated_content, $envelope['attrs'] );
        }

        $translated_content = BlockTextExtractor::strip_interblock_br( $translated_content );

        $payload = [
            'output'   => $translated_content,
            'type'     => 'content',
            'language' => $ctx['language_name'],
        ];
        if ( $translated_title !== null && $translated_title !== '' ) {
            $payload['translated_title'] = $translated_title;
        }
        if ( $translated_footnotes !== null ) {
            $payload['footnotes'] = $translated_footnotes;
        }
        if ( $translated_excerpt !== null ) {
            $payload['translated_excerpt'] = $translated_excerpt;
        }

        return $payload;
    }
}
