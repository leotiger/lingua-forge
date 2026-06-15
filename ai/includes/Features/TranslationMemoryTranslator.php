<?php
/**
 * Class LinguaForge\AI\Features\TranslationMemoryTranslator
 *
 * Encapsulates the Translation Memory path of Translation::run().
 * Extracted from Translation.php in 2.1.9 to keep that class focused on its
 * FeatureInterface contract and the JSON-envelope path.
 *
 * Responsibilities:
 *   • Block parsing + TM lookup (bulk, with compliance-signature keying)
 *   • Queue building for blocks not in the TM cache
 *   • API call (title + uncached blocks) with injected provider
 *   • Response validation + block reassembly
 *   • Writing new translations back to the TM table
 *   • Building the result payload and writing to CacheStore
 *
 * All six pure sub-operations are public static methods so they are
 * directly callable in unit tests without reflection.
 *
 * @package LinguaForge\AI\Features
 * @since   2.1.9
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Core\JsonRepair;
use LinguaForge\AI\Core\Log;
use LinguaForge\AI\Core\TranslationDebug;
use LinguaForge\AI\Core\TranslationMemory;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;

defined( 'ABSPATH' ) || exit;

class TranslationMemoryTranslator {

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
     * Execute the TM translation path for a single post.
     *
     * Returns the success payload array on a clean TM run, or null on any
     * recoverable failure so the caller (Translation::run()) can fall through
     * to the JSON-envelope path.
     *
     * @param  \WP_Post $post                Source post object.
     * @param  int      $post_id             Source post ID.
     * @param  string   $cache_key           Cache feature key (e.g. 'translation_de').
     * @param  string   $hash                Cache content hash.
     * @param  string   $target_language     Two-letter target language code.
     * @param  string   $language_name       Human-readable target language.
     * @param  string   $source_lang         Two-letter source language code.
     * @param  string   $content_to_translate Post content to translate.
     * @param  string   $footnotes_raw       Serialised footnotes JSON, or ''.
     * @param  bool     $has_footnotes       Whether the post has footnotes.
     * @param  array    $params              Original request parameters.
     * @return array<string,mixed>|null
     */
    public function translate(
        \WP_Post $post,
        int      $post_id,
        string   $cache_key,
        string   $hash,
        string   $target_language,
        string   $language_name,
        string   $source_lang,
        string   $content_to_translate,
        string   $footnotes_raw,
        bool     $has_footnotes,
        array    $params
    ): ?array {

        // ── Parse top-level blocks ─────────────────────────────────────────
        $source_blocks = parse_blocks( $content_to_translate );

        if ( empty( $source_blocks ) ) {
            return null; // nothing to translate — fall back to JSON-envelope
        }

        $tm_source_markups = self::build_tm_source_markups( $source_blocks );

        if ( empty( $tm_source_markups ) ) {
            return null; // no meaningful blocks — fall back
        }

        // ── Auxiliary cache-key components ─────────────────────────────────
        $glossary_hash        = Glossary::hash_for_pair( $source_lang, $target_language );
        $compliance_signature = self::compute_compliance_signature( $post_id );

        // ── Bulk TM lookup ─────────────────────────────────────────────────
        $tm_hits = TranslationMemory::lookup_batch(
            array_values( $tm_source_markups ),
            $source_lang,
            $target_language,
            $glossary_hash,
            $compliance_signature
        );

        // ── Build queue of uncached blocks ────────────────────────────────
        [ $queue_markups, $queue_to_source_index ] = self::build_tm_queue( $tm_source_markups, $tm_hits );

        $needs_blocks    = ! empty( $queue_markups );
        $needs_footnotes = $has_footnotes && $footnotes_raw !== '';

        // ── Build API payload ──────────────────────────────────────────────
        $tm_schema       = self::build_tm_schema( $needs_blocks, $needs_footnotes );
        $tm_user_message = self::build_tm_user_message(
            $source_lang, $language_name, $post->post_title,
            $queue_markups, $needs_blocks, $needs_footnotes, $footnotes_raw
        );

        // ── Worker config with TM schema ───────────────────────────────────
        $tm_worker_config = new WorkerConfig(
            model:           $this->base_config->model,
            max_tokens:      $this->base_config->max_tokens,
            temperature:     $this->base_config->temperature,
            response_schema: $tm_schema,
        );
        $tm_worker_config = apply_filters(
            'linguaforge_translation_worker_config',
            $tm_worker_config,
            $post_id,
            $params
        );

        /**
         * Filters the AI provider instance for the Translation Memory path.
         *
         * Allows integration tests and site code to substitute a custom
         * AIProviderInterface implementation without a live API key.
         *
         * @param \LinguaForge\AI\Contracts\AIProviderInterface $provider The default provider.
         * @param int                                           $post_id  Source post ID.
         * @param WorkerConfig                                  $config   Worker config.
         */
        $tm_provider = apply_filters( 'linguaforge_ai_provider', ProviderFactory::make( $tm_worker_config ), $post_id, $tm_worker_config );

        // ── Debug log ──────────────────────────────────────────────────────
        if ( TranslationDebug::debug_enabled() ) {
            TranslationDebug::debug_write( $post_id, $target_language, 'tm-source', $tm_user_message );
        }

        $system_prompt = $this->system_prompt;
        $result        = UsageRecorder::tracked( 'translation', static fn() => $tm_provider->chat( [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user',   'content' => $tm_user_message ],
        ] ) );

        if ( TranslationDebug::debug_enabled() ) {
            TranslationDebug::debug_write( $post_id, $target_language, 'tm-response', (string) $result );
        }

        if ( empty( $result ) ) {
            return null;
        }

        // ── Parse + validate API response ──────────────────────────────────
        $parsed = self::parse_tm_envelope( (string) $result, $needs_blocks, count( $queue_markups ), $post_id );
        if ( $parsed === null ) {
            return null;
        }

        [ 'title' => $translated_title, 'blocks' => $translated_blocks, 'footnotes' => $translated_fnotes ] = $parsed;

        // ── Store new translations in TM ──────────────────────────────────
        $fresh_by_source_index = [];
        foreach ( $queue_to_source_index as $queue_i => $orig_i ) {
            $source_markup = $queue_markups[ $queue_i ];
            $translated    = (string) ( $translated_blocks[ $queue_i ] ?? '' );
            if ( $translated === '' ) continue;

            $fresh_by_source_index[ $orig_i ] = $translated;

            TranslationMemory::store(
                $source_markup,
                $translated,
                $source_lang,
                $target_language,
                $glossary_hash,
                $compliance_signature
            );
        }

        // ── Reassemble translated content ──────────────────────────────────
        $translated_content = self::reassemble_tm_blocks(
            $source_blocks, $tm_source_markups, $tm_hits, $fresh_by_source_index
        );

        // Title fallback: if the AI returned an empty title, keep the source.
        if ( $translated_title === '' ) {
            $translated_title = $post->post_title;
        }

        // Footnotes re-encode (rest of the plugin treats them as a string).
        $translated_footnotes_json = $translated_fnotes !== null
            ? wp_json_encode( $translated_fnotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : null;

        // ── Build payload + write cache ───────────────────────────────────
        $payload = [
            'output'   => $translated_content,
            'type'     => 'content',
            'language' => $language_name,
        ];
        if ( $translated_title !== '' ) {
            $payload['translated_title'] = $translated_title;
        }
        if ( $translated_footnotes_json !== null ) {
            $payload['footnotes'] = $translated_footnotes_json;
        }

        CacheStore::set( $post_id, $cache_key, $hash, $payload );

        return array_merge( [ 'success' => true ], $payload );
    }

    // =========================================================================
    // Pure static helpers — public so unit tests can call them directly
    // =========================================================================

    /**
     * Walk top-level parsed blocks and return a map of source_blocks index →
     * serialized markup for every "meaningful" block (has a blockName and
     * non-empty visible text after stripping tags). Whitespace-only entries
     * between top-level blocks are excluded — they are preserved during
     * reassembly but never sent to the TM.
     *
     * @param  array<int,array> $source_blocks  Output of parse_blocks().
     * @return array<int,string>                Map: source index → markup string.
     */
    public static function build_tm_source_markups( array $source_blocks ): array {
        $tm_source_markups = [];
        foreach ( $source_blocks as $i => $block ) {
            if ( empty( $block['blockName'] ) ) continue;
            $markup = serialize_block( $block );
            if ( trim( wp_strip_all_tags( $markup ) ) === '' ) continue;
            $tm_source_markups[ $i ] = $markup;
        }
        return $tm_source_markups;
    }

    /**
     * Build the queue of blocks that are NOT already in the TM cache.
     *
     * @param  array<int,string>    $tm_source_markups  Map: source index → markup.
     * @param  array<string,string> $tm_hits            Map: markup → cached translation.
     * @return array{0:list<string>,1:array<int,int>}   [$queue_markups, $queue_to_source_index]
     */
    public static function build_tm_queue( array $tm_source_markups, array $tm_hits ): array {
        $queue_markups         = [];
        $queue_to_source_index = [];
        foreach ( $tm_source_markups as $orig_i => $markup ) {
            if ( isset( $tm_hits[ $markup ] ) ) continue;
            $queue_to_source_index[ count( $queue_markups ) ] = $orig_i;
            $queue_markups[]                                  = $markup;
        }
        return [ $queue_markups, $queue_to_source_index ];
    }

    /**
     * Build the JSON response schema for the TM API call.
     * Title is always required; blocks and footnotes are added conditionally.
     *
     * @param  bool  $needs_blocks    Whether uncached blocks need translating.
     * @param  bool  $needs_footnotes Whether post footnotes need translating.
     * @return array<string,mixed>    JSON Schema object.
     */
    public static function build_tm_schema( bool $needs_blocks, bool $needs_footnotes ): array {
        $schema_properties = [
            'title' => [
                'type'        => 'string',
                'description' => 'Translated post title (plain text).',
            ],
        ];
        $schema_required = [ 'title' ];

        if ( $needs_blocks ) {
            $schema_properties['blocks'] = [
                'type'        => 'array',
                'description' => 'Translated block markups in the same order as the source `blocks` array. Preserve every <!-- wp: --> comment, HTML tag, _lfid value, and shortcode exactly.',
                'items'       => [ 'type' => 'string' ],
            ];
            $schema_required[] = 'blocks';
        }
        if ( $needs_footnotes ) {
            $schema_properties['footnotes'] = [
                'type'        => 'array',
                'description' => 'Translated WordPress core footnotes. Each item keeps its original "id" and supplies a translated "content".',
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
            $schema_required[] = 'footnotes';
        }

        return [
            'type'                 => 'object',
            'properties'           => $schema_properties,
            'required'             => $schema_required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Build the user-facing message for the TM API call.
     *
     * @param  string       $source_lang     Two-letter source language code.
     * @param  string       $language_name   Human-readable target language.
     * @param  string       $post_title      Source post title.
     * @param  list<string> $queue_markups   Uncached block markups to translate.
     * @param  bool         $needs_blocks    Whether block list should be included.
     * @param  bool         $needs_footnotes Whether footnotes section should be included.
     * @param  string       $footnotes_raw   Serialised source footnotes JSON.
     */
    public static function build_tm_user_message(
        string $source_lang,
        string $language_name,
        string $post_title,
        array  $queue_markups,
        bool   $needs_blocks,
        bool   $needs_footnotes,
        string $footnotes_raw
    ): string {
        $user_lines = [
            "Translate the following from {$source_lang} to {$language_name}.",
            "Preserve all HTML, WordPress block comments, _lfid values, shortcodes, and attributes exactly.",
            "Return a JSON object matching the schema you've been given.",
            "",
            "Source title: " . $post_title,
        ];

        if ( $needs_blocks ) {
            $user_lines[] = "";
            $user_lines[] = "Blocks to translate (one per array entry; preserve every block-comment delimiter exactly):";
            foreach ( $queue_markups as $i => $markup ) {
                $user_lines[] = "[" . ( $i + 1 ) . "] " . $markup;
            }
        }

        if ( $needs_footnotes ) {
            $user_lines[] = "";
            $user_lines[] = "Source footnotes JSON (translate only each \"content\" value; leave every \"id\" unchanged):";
            $user_lines[] = $footnotes_raw;
        }

        return implode( "\n", $user_lines );
    }

    /**
     * Parse and validate the raw TM API response.
     *
     * Returns a map with keys 'title', 'blocks', 'footnotes' on success,
     * or null on any failure (invalid JSON, truncation, block-count mismatch).
     *
     * @param  string $result               Raw API response string.
     * @param  bool   $needs_blocks         Whether blocks were requested.
     * @param  int    $expected_block_count Number of blocks sent to the AI.
     * @param  int    $post_id              Used in diagnostic error_log messages.
     * @return array{title:string,blocks:array,footnotes:?array}|null
     */
    public static function parse_tm_envelope(
        string $result,
        bool   $needs_blocks,
        int    $expected_block_count,
        int    $post_id = 0
    ): ?array {
        $normalised = JsonRepair::normalise_json_response( $result );
        $envelope   = json_decode( $normalised, true );

        if ( ! is_array( $envelope ) ) {
            $looks_truncated = str_starts_with( $normalised, '{' ) && ! str_ends_with( $normalised, '}' );
            Log::debug( sprintf(
                'Lingua Forge AI [Translation/TM] post %d: response was not valid JSON%s. Falling back. First 200 chars: %s',
                $post_id,
                $looks_truncated ? ' (response appears truncated — raise Max output tokens)' : '',
                mb_substr( $normalised, 0, 200 )
            ) );
            return null;
        }

        $translated_title  = isset( $envelope['title'] ) ? trim( (string) $envelope['title'] ) : '';
        $translated_blocks = isset( $envelope['blocks'] ) && is_array( $envelope['blocks'] )
            ? $envelope['blocks']
            : [];
        $translated_fnotes = isset( $envelope['footnotes'] ) && is_array( $envelope['footnotes'] )
            ? $envelope['footnotes']
            : null;

        if ( $needs_blocks && count( $translated_blocks ) !== $expected_block_count ) {
            Log::debug( sprintf(
                'Lingua Forge AI [Translation/TM] post %d: expected %d translated blocks, got %d. Falling back.',
                $post_id,
                $expected_block_count,
                count( $translated_blocks )
            ) );
            return null;
        }

        return [
            'title'     => $translated_title,
            'blocks'    => $translated_blocks,
            'footnotes' => $translated_fnotes,
        ];
    }

    /**
     * Reassemble translated content from source blocks, TM hits, and fresh
     * translations. Walks source blocks in order, substituting each named block
     * with its fresh translation, cached TM hit, or (for empty-content blocks)
     * the original serialized markup. Whitespace-only entries are preserved from
     * the source innerHTML.
     *
     * @param  array<int,array>     $source_blocks         Output of parse_blocks().
     * @param  array<int,string>    $tm_source_markups     Map: source index → markup.
     * @param  array<string,string> $tm_hits               Map: markup → cached translation.
     * @param  array<int,string>    $fresh_by_source_index Map: source index → new translation.
     * @return string Translated content with inter-block <br> stripped.
     */
    public static function reassemble_tm_blocks(
        array $source_blocks,
        array $tm_source_markups,
        array $tm_hits,
        array $fresh_by_source_index
    ): string {
        $pieces = [];
        foreach ( $source_blocks as $i => $block ) {
            if ( empty( $block['blockName'] ) ) {
                $pieces[] = $block['innerHTML'] ?? '';
                continue;
            }
            if ( isset( $fresh_by_source_index[ $i ] ) ) {
                $pieces[] = $fresh_by_source_index[ $i ];
            } elseif ( isset( $tm_source_markups[ $i ] ) && isset( $tm_hits[ $tm_source_markups[ $i ] ] ) ) {
                $pieces[] = $tm_hits[ $tm_source_markups[ $i ] ];
            } else {
                $pieces[] = serialize_block( $block );
            }
        }

        $content = implode( "\n\n", array_filter(
            $pieces,
            static fn( string $s ): bool => $s !== ''
        ) );

        return BlockTextExtractor::strip_interblock_br( $content );
    }

    /**
     * Stable 16-char signature of the active compliance preset's state.
     *
     * Folded into the TM cache key so that toggling the preset or editing its
     * addendum invalidates affected rows — the new system prompt's rules apply
     * on the next translation rather than being hidden behind a stale cache.
     *
     * Public so integration tests can pre-seed TM entries with the exact key
     * that translate() will use at runtime (avoiding key mismatches).
     *
     * @param int $post_id 0 = global only; non-zero allows per-post preset overrides.
     */
    public static function compute_compliance_signature( int $post_id = 0 ): string {

        $preset   = Config::active_preset( $post_id );
        $presets  = Config::presets();
        $addendum = Config::preset_addendum( $preset );

        return substr( hash( 'sha256',
            ( $preset !== 'standard' ? '1' : '0' )
            . '|' . (string) ( $presets[ $preset ]['temperature'] ?? 0.1 )
            . '|' . md5( $addendum )
        ), 0, 16 );
    }
}
