<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Contracts\FeatureInterface;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Core\TranslationMemory;
use LinguaForge\AI\Core\UsageRecorder;

defined('ABSPATH') || exit;

/**
 * Translates the full content of a post or page into a target language
 * while preserving all WordPress block markup and HTML structure.
 *
 * Uses Claude Sonnet for higher quality on longer texts.
 */
class Translation implements FeatureInterface {

    /** @var array<string, string> Supported target languages. */
    public const LANGUAGES = [
        // European — West
        'en' => 'English',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'fr' => 'French',
        'it' => 'Italian',
        'de' => 'German',
        'nl' => 'Dutch',
        'ca' => 'Catalan',
        'sv' => 'Swedish',
        'da' => 'Danish',
        'nb' => 'Norwegian',
        'fi' => 'Finnish',
        // European — East & South
        'pl' => 'Polish',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'hr' => 'Croatian',
        'sl' => 'Slovenian',
        'el' => 'Greek',
        'uk' => 'Ukrainian',
        'ru' => 'Russian',
        // Middle East & Africa
        'ar' => 'Arabic',
        'he' => 'Hebrew',
        'fa' => 'Persian',
        'tr' => 'Turkish',
        'sw' => 'Swahili',
        // South & South-East Asia
        'hi' => 'Hindi',
        'bn' => 'Bengali',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'vi' => 'Vietnamese',
        'th' => 'Thai',
        // East Asia
        'zh' => 'Chinese (Simplified)',
        'zh-tw' => 'Chinese (Traditional)',
        'ja' => 'Japanese',
        'ko' => 'Korean',
    ];

    /**
     * Return the supported languages map, optionally extended or overridden
     * by the `linguaforge_translation_languages` filter.
     *
     * The filter receives the default language map and must return an
     * associative array of `code => 'English name'` pairs.  Language names
     * are passed verbatim to the AI prompt, so use the English name of the
     * language (e.g. `'Swahili'`, not `'Kiswahili'`).
     *
     * Example — add Swahili and remove Russian:
     *
     *   add_filter( 'linguaforge_translation_languages', function ( $languages ) {
     *       $languages['sw'] = 'Swahili';
     *       unset( $languages['ru'] );
     *       return $languages;
     *   } );
     *
     * Result is cached per request so the filter runs only once per page load.
     *
     * @return array<string, string>  Language code => English name.
     */
    public static function get_languages(): array {

        static $cache = null;

        if ( $cache === null ) {
            $cache = (array) apply_filters(
                'linguaforge_translation_languages',
                self::LANGUAGES
            );
        }

        return $cache;
    }

    /**
     * Detect the language of the post or page currently being viewed or edited
     * and return its code if it matches one of our supported target languages.
     *
     * Detection is attempted in priority order:
     *
     *   1. _lang post meta — our own language marker written by the meta box.
     *      Most reliable: it was explicitly set by the user within this plugin.
     *
     *   2. Polylang — pll_get_post_language( $post_id, 'slug' ) returns the
     *      language slug (e.g. "fr") when the Polylang plugin is active.
     *
     *   3. Site locale — get_locale() (e.g. "de_DE") is trimmed to its 2-letter
     *      ISO 639-1 prefix.  Used as a fallback for single-language sites.
     *
     * Returns null when no post context exists (e.g. a generic admin screen or
     * the FSE template editor) or when the detected code is not in our list.
     *
     * @return string|null  Language code (e.g. "de") or null.
     */
    public static function detect_post_language(): ?string {

        // ── Resolve the current post ID ───────────────────────────────────────

        $post_id = null;

        if ( is_admin() ) {

            // get_current_screen() is available during admin_enqueue_scripts
            // and enqueue_block_editor_assets — the two hooks all callers of
            // this method run on.
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

            // Screen base 'post' covers post create / edit screens for every
            // post type (posts, pages, CPTs) in both the classic editor and
            // Gutenberg. WP core sets the global $post before either of our
            // enqueue hooks fires on those screens:
            //
            //   wp-admin/post.php       — $post = get_post( $_GET['post'] )
            //   wp-admin/post-new.php   — $post = get_default_post_to_edit()
            //
            // Other screen bases ('site-editor', 'edit', 'dashboard', etc.)
            // have no single $post in scope; we correctly resolve to null
            // for them so the JS falls back to the form-level default.
            if ( $screen && $screen->base === 'post' ) {
                global $post;
                if ( $post instanceof \WP_Post ) {
                    $post_id = (int) $post->ID;
                }
            }

        } else {

            // Front-end admin bar: use the singular queried object.
            if ( is_singular() ) {
                $post_id = (int) get_queried_object_id();
            }
        }

        if ( ! $post_id ) {
            return null;
        }

        // ── 1. Our own _lang post meta ────────────────────────────────────────

        $lang = (string) get_post_meta( $post_id, '_lang', true );

        if ( $lang !== '' && array_key_exists( $lang, self::get_languages() ) ) {
            return $lang;
        }

        // ── 2. Polylang ───────────────────────────────────────────────────────

        if ( function_exists( 'pll_get_post_language' ) ) {

            $lang = (string) pll_get_post_language( $post_id, 'slug' );

            if ( $lang !== '' && array_key_exists( $lang, self::get_languages() ) ) {
                return $lang;
            }
        }

        // ── 3. Site locale ────────────────────────────────────────────────────
        // Trim "de_DE" → "de", "en_US" → "en", etc.

        $code = strtolower( substr( get_locale(), 0, 2 ) );

        if ( array_key_exists( $code, self::get_languages() ) ) {
            return $code;
        }

        return null;
    }

    public function get_key(): string {

        return 'translation';
    }

    public function get_label(): string {

        return __( 'Translate Content', 'lingua-forge' );
    }

    /**
     * The model tier (Quality or Light) is read from Settings → Lingua Forge AI
     * → Translation Limits (default: Quality — Sonnet / GPT-4o / Gemini Pro).
     *
     * max_tokens is read from the same settings section (default: 16 000).
     * Raise it there if you hit truncation on very large pages.
     *
     * Note: this is the base config for the worker, with no response schema.
     * The full-post path in run() builds a per-request WorkerConfig with a
     * dynamic schema (whose required keys depend on whether the post has
     * footnotes and/or block attribute placeholders).
     */
    public function get_worker_config(int $post_id = 0): WorkerConfig {

        return Config::apply_compliance(new WorkerConfig(
            model:       Config::model(Config::translation_tier()),
            max_tokens:  Config::translation_max_tokens(),
            temperature: 0.2,
        ), $post_id);
    }

    /**
     * Build the JSON envelope schema for a full-post translation request.
     *
     * Always requires "title" and "content". Adds "footnotes" / "attrs" only
     * when the source post actually has those payloads — that keeps the
     * schema tight (smaller token budget for the schema itself, no spurious
     * empty fields in the response).
     *
     * @return array<string, mixed>  JSON-schema-shaped associative array.
     */
    private static function build_translation_schema(bool $has_footnotes, bool $has_attrs): array {

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

        $required = ['title', 'content'];

        if ($has_footnotes) {
            $properties['footnotes'] = [
                'type'        => 'array',
                'description' => 'Translated WordPress core footnotes. Each item keeps its original "id" and supplies a translated "content" string.',
                'items'       => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id'      => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                    'required'             => ['id', 'content'],
                    'additionalProperties' => false,
                ],
            ];
            $required[] = 'footnotes';
        }

        if ($has_attrs) {
            $properties['attrs'] = [
                'type'        => 'object',
                'description' => 'Translations for the __WPAI_N__ placeholders used in block-comment attribute strings. Keys must match the placeholder keys exactly; values are the translated strings.',
                'additionalProperties' => ['type' => 'string'],
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
     * Translation Memory fast path — block-level cache + batched API call.
     *
     * When TM is enabled and the post is TM-compatible (no block-attribute
     * placeholders, known source language, standard post type), parse the
     * content into top-level blocks, look each one up in the TM table, and
     * send only the uncached blocks to the API as a batched request
     * (alongside title and footnotes when those need translation too).
     *
     * Returns the same payload shape as the main run() method's success
     * branch, so the caller can return it directly. Returns null on any
     * recoverable failure (parse error, empty AI response, etc.) so the
     * caller can fall back to the existing JSON-envelope flow.
     *
     * Cache key for each block:
     *   sha256( block_markup + source_lang + target_lang
     *           + glossary_hash + compliance_signature )
     *
     * The glossary hash means a glossary edit invalidates affected rows on
     * next translation. The compliance signature folds in the active
     * compliance preset so toggling it on/off also invalidates.
     */
    private function try_translate_with_tm(
        \WP_Post $post,
        int $post_id,
        string $cache_key,
        string $hash,
        string $target_language,
        string $language_name,
        string $source_lang,
        string $content_to_translate,
        string $footnotes_raw,
        bool $has_footnotes,
        array $params
    ): ?array {

        // ── Parse top-level blocks ─────────────────────────────────────────
        $source_blocks = parse_blocks( $content_to_translate );

        if ( empty( $source_blocks ) ) {
            return null; // nothing to translate — fall back to existing flow
        }

        // Walk top-level blocks; serialize each meaningful one for TM lookup.
        // "Meaningful" = has a blockName and non-empty rendered text. The
        // empty-name entries are HTML whitespace between blocks; we preserve
        // them in reassembly but don't TM-key them.
        $tm_source_markups   = [];  // map: source_blocks index → serialized markup
        foreach ( $source_blocks as $i => $block ) {
            if ( empty( $block['blockName'] ) ) continue;
            $markup = serialize_block( $block );
            if ( trim( wp_strip_all_tags( $markup ) ) === '' ) continue;
            $tm_source_markups[ $i ] = $markup;
        }

        if ( empty( $tm_source_markups ) ) {
            return null; // no meaningful blocks — fall back
        }

        // ── Auxiliary cache-key components ─────────────────────────────────
        $glossary_hash        = Glossary::hash_for_pair( $source_lang, $target_language );
        $compliance_signature = self::compute_compliance_signature();

        // ── Bulk lookup ────────────────────────────────────────────────────
        $tm_hits = TranslationMemory::lookup_batch(
            array_values( $tm_source_markups ),
            $source_lang,
            $target_language,
            $glossary_hash,
            $compliance_signature
        );

        // ── Build queue of uncached blocks ────────────────────────────────
        // $queue_markups: ordered list of source markups to send to the AI.
        // $queue_to_source_index: queue index → $source_blocks index.
        $queue_markups          = [];
        $queue_to_source_index  = [];
        foreach ( $tm_source_markups as $orig_i => $markup ) {
            if ( isset( $tm_hits[ $markup ] ) ) continue;
            $queue_to_source_index[ count( $queue_markups ) ] = $orig_i;
            $queue_markups[]                                  = $markup;
        }

        // ── Build the batched API payload ─────────────────────────────────
        // Always include title (per-post, not TM-cached). Footnotes too if
        // the post has them. Skip "blocks" entirely when every block was
        // cached — the API call then translates just the title (and
        // footnotes if present).
        $needs_blocks    = ! empty( $queue_markups );
        $needs_footnotes = $has_footnotes && $footnotes_raw !== '';

        // Build the JSON schema dynamically based on what we're asking for.
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

        $tm_schema = [
            'type'                 => 'object',
            'properties'           => $schema_properties,
            'required'             => $schema_required,
            'additionalProperties' => false,
        ];

        // ── Build user message ─────────────────────────────────────────────
        $user_lines = [
            "Translate the following from {$source_lang} to {$language_name}.",
            "Preserve all HTML, WordPress block comments, _lfid values, shortcodes, and attributes exactly.",
            "Return a JSON object matching the schema you've been given.",
            "",
            "Source title: " . $post->post_title,
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

        $tm_user_message = implode( "\n", $user_lines );

        // ── Worker config with TM schema ───────────────────────────────────
        $base = $this->get_worker_config($post_id);
        $tm_worker_config = new WorkerConfig(
            model:           $base->model,
            max_tokens:      $base->max_tokens,
            temperature:     $base->temperature,
            response_schema: $tm_schema,
        );
        $tm_worker_config = apply_filters(
            'linguaforge_translation_worker_config',
            $tm_worker_config,
            $post_id,
            $params
        );

        $tm_provider = ProviderFactory::make( $tm_worker_config );

        // System prompt with glossary injection.
        $system_prompt = Config::apply_compliance_to_system(
            'You are a professional translator. ' .
            'Preserve all WordPress block comments (<!-- wp:... /-->), HTML tags, shortcodes, ' .
            'and attributes exactly as they appear. ' .
            'Only translate the visible text content. ' .
            'Do NOT add any new HTML tags — especially not <br> or <br/> — ' .
            'that are not already present in the source. ' .
            'You will receive an array of blocks; return their translations as an array of the same length and order.',
            $post_id
        );
        $glossary_section = Glossary::format_for_prompt( $source_lang, $target_language );
        if ( $glossary_section !== '' ) {
            $system_prompt .= "\n\n" . $glossary_section;
        }

        // ── Debug log of the source payload (TM mode) ─────────────────────
        if ( self::debug_enabled() ) {
            self::debug_write( $post_id, $target_language, 'tm-source', $tm_user_message );
        }

        $result = UsageRecorder::tracked( 'translation', static fn() => $tm_provider->chat( [
            [ 'role' => 'system', 'content' => $system_prompt ],
            [ 'role' => 'user',   'content' => $tm_user_message ],
        ] ) );

        if ( self::debug_enabled() ) {
            self::debug_write( $post_id, $target_language, 'tm-response', (string) $result );
        }

        if ( empty( $result ) ) {
            return null;
        }

        $envelope = json_decode( trim( $result ), true );
        if ( ! is_array( $envelope ) ) {
            $trimmed          = trim( (string) $result );
            $looks_truncated  = str_starts_with( $trimmed, '{' ) && ! str_ends_with( $trimmed, '}' );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log for unparseable TM response.
            error_log( sprintf(
                'Lingua Forge AI [Translation/TM] post %d: response was not valid JSON%s. Falling back to non-TM flow. First 200 chars: %s',
                $post_id,
                $looks_truncated ? ' (response appears truncated — raise Max output tokens)' : '',
                mb_substr( (string) $result, 0, 200 )
            ) );
            return null;
        }

        $translated_title   = isset( $envelope['title'] )   ? trim( (string) $envelope['title'] ) : '';
        $translated_blocks  = isset( $envelope['blocks'] ) && is_array( $envelope['blocks'] )
            ? $envelope['blocks']
            : [];
        $translated_fnotes  = isset( $envelope['footnotes'] ) && is_array( $envelope['footnotes'] )
            ? $envelope['footnotes']
            : null;

        // Sanity-check: when we asked for blocks, we expect that many back.
        if ( $needs_blocks && count( $translated_blocks ) !== count( $queue_markups ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for shape mismatch.
            error_log( sprintf(
                'Lingua Forge AI [Translation/TM] post %d: expected %d translated blocks, got %d. Falling back.',
                $post_id,
                count( $queue_markups ),
                count( $translated_blocks )
            ) );
            return null;
        }

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
        // Walk source blocks in order; substitute each meaningful block with
        // its fresh translation (if present) or cached translation (if hit).
        // Empty-name "whitespace blocks" between top-level blocks are
        // preserved from the source.
        $pieces = [];
        foreach ( $source_blocks as $i => $block ) {

            if ( empty( $block['blockName'] ) ) {
                // Raw HTML / whitespace between blocks — preserve as-is.
                $pieces[] = $block['innerHTML'] ?? '';
                continue;
            }

            if ( isset( $fresh_by_source_index[ $i ] ) ) {
                $pieces[] = $fresh_by_source_index[ $i ];
            } elseif ( isset( $tm_source_markups[ $i ] ) && isset( $tm_hits[ $tm_source_markups[ $i ] ] ) ) {
                $pieces[] = $tm_hits[ $tm_source_markups[ $i ] ];
            } else {
                // Block was skipped (empty content); keep the source markup.
                $pieces[] = serialize_block( $block );
            }
        }

        $translated_content = implode( "\n\n", array_filter(
            $pieces,
            static fn( string $s ): bool => $s !== ''
        ) );

        // ── Strip stray inter-block <br> ──────────────────────────────────
        $translated_content = BlockTextExtractor::strip_interblock_br( $translated_content );

        // Title fallback: if the AI returned an empty title (rare schema
        // failure), keep the source title rather than wiping it.
        if ( $translated_title === '' ) {
            $translated_title = $post->post_title;
        }

        // Footnotes re-encode (rest of the plugin treats them as a string).
        $translated_footnotes_json = $translated_fnotes !== null
            ? wp_json_encode( $translated_fnotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : null;

        // ── Build payload + cache ─────────────────────────────────────────
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

    /**
     * Stable signature of the current compliance preset state.
     *
     * Folded into the Translation Memory cache key so toggling compliance
     * (or editing its temperature or addendum) invalidates affected cached
     * translations — the new system prompt's stricter rules should actually
     * apply on the next translation, not be hidden behind a stale cache.
     */
    private static function compute_compliance_signature(): string {

        return substr( hash( 'sha256',
            ( Config::compliance_enabled() ? '1' : '0' )
            . '|' . Config::compliance_temperature()
            . '|' . md5( Config::compliance_addendum() )
        ), 0, 16 );
    }

    public function get_ui_fields(): array {

        return [
            [
                'name'    => 'translate_mode',
                'type'    => 'select',
                'label'   => __( 'Mode', 'lingua-forge' ),
                'options' => [
                    'full'  => __( 'Full post',        'lingua-forge' ),
                    'chunk' => __( 'Translate chunk',  'lingua-forge' ),
                ],
            ],
            [
                'name'    => 'target_language',
                'type'    => 'select',
                'label'   => __( 'Target Language', 'lingua-forge' ),
                'options' => self::get_languages(), // English names intentionally kept for AI prompt
            ],
            [
                'name'        => 'chunk_text',
                'type'        => 'textarea',
                'label'       => __( 'Text to translate', 'lingua-forge' ),
                'placeholder' => __( 'Paste a footnote, sentence, or any snippet here…', 'lingua-forge' ),
                'rows'        => 6,
                'condition'   => ['translate_mode' => 'chunk'],
            ],
        ];
    }

    /**
     * Pre-select the target language from the post's _lang meta so that,
     * e.g., a French page (‹_lang = fr›) has French already selected even
     * when its current content was imported in another language.
     */
    public function get_field_defaults(int $post_id): array {

        $lang = (string) get_post_meta($post_id, '_lang', true);

        if ($lang !== '' && array_key_exists($lang, self::get_languages())) {
            return ['target_language' => $lang];
        }

        return [];
    }

    public function supports(int $post_id): bool {

        return current_user_can('edit_post', $post_id);
    }

    public function run(int $post_id, array $params = []): array {

        $target_language = sanitize_text_field(
            $params['target_language'] ?? 'en'
        );

        if (!array_key_exists($target_language, self::get_languages())) {

            return [
                'success' => false,
                'error'   => 'Invalid target language.',
            ];
        }

        $language_name = self::get_languages()[$target_language];

        // ── Chunk mode ────────────────────────────────────────────────────────
        // Translate an arbitrary text snippet instead of the full post.
        // Useful as a manual workaround for footnotes or any passage that the
        // full-post path struggles with (long content, complex footnotes, etc.).
        $translate_mode = sanitize_text_field($params['translate_mode'] ?? 'full');

        if ($translate_mode === 'chunk') {
            return $this->run_chunk($language_name, $params);
        }

        // ── Full-post mode ────────────────────────────────────────────────────
        $post = get_post($post_id);

        if (!$post) {

            return [
                'success' => false,
                'error'   => 'Post not found.',
            ];
        }

        // ── Cache check ───────────────────────────────────────────────────────
        // Cache key is per-language so multiple translations of the same post
        // can coexist (e.g. _linguaforge_cache_translation_fr, …_ca, …_de).
        //
        // Prefer the footnotes value forwarded by the JS client from the live
        // Gutenberg meta store — this captures unsaved footnotes that have not
        // yet been written to the database.  Fall back to get_post_meta() for
        // classic-editor requests or when the param is absent / invalid JSON.
        $param_footnotes = isset($params['footnotes_meta']) && is_string($params['footnotes_meta'])
            ? wp_unslash($params['footnotes_meta'])
            : '';

        $footnotes_raw = ($param_footnotes !== '' && json_decode($param_footnotes) !== null)
            ? $param_footnotes
            : (string) get_post_meta($post_id, 'footnotes', true);
        $cache_key     = $this->get_key() . '_' . $target_language;
        $hash          = CacheStore::hash([$post->post_title, $post->post_content, $footnotes_raw, $target_language]);
        // When debug mode is active, skip the cache so every click triggers a
        // live API call and the source/response files are always written.
        $force = !empty($params['force_refresh']) || self::debug_enabled();
        $cached = $force
            ? null
            : CacheStore::get($post_id, $cache_key, $hash);

        if ($cached !== null) {
            return array_merge(['success' => true, 'cached' => true], $cached);
        }

        // ── Block attribute extraction ────────────────────────────────────────
        // Pull translatable strings out of block comment JSON attributes
        // (e.g. the "summary" field of wp:details) and replace them with
        // __WPAI_N__ placeholders.  The placeholders survive translation
        // unchanged; the translated values are reinserted afterwards.
        // When no translatable attrs are found this is a cheap no-op.
        [$placeholder_content, $attr_map] = BlockTextExtractor::extract(
            $post->post_content
        );

        // ── Build prompt ──────────────────────────────────────────────────────
        $prompt = file_get_contents(
            LINGUAFORGE_AI_PATH .
            '/templates/prompts/translation.txt'
        );

        if ($prompt === false) {
            return [
                'success' => false,
                'error'   => 'Prompt template not found.',
            ];
        }

        // ── Detect optional payloads (footnotes, block attribute placeholders)
        // Their presence drives both the prompt's {{extra_output}} blocks AND
        // the required-keys list in the JSON-envelope response schema.
        $has_footnotes = false;

        if ($footnotes_raw !== '') {
            $decoded = json_decode($footnotes_raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $has_footnotes = true;
            }
        }

        $has_attrs = !empty($attr_map);

        // ── Build per-section prompt inserts ─────────────────────────────────
        // These inject the source payloads into the prompt body so the model
        // can see the data it needs to translate. Per-key instructions are
        // captured in the JSON schema "description" fields, so we only need
        // to surface the raw inputs here.
        $extra_sections     = [];
        $extra_output_doc   = '';

        if ($has_footnotes) {
            $extra_sections[] =
                "Source footnotes JSON (translate only each \"content\" value; leave every \"id\" unchanged):\n"
                . $footnotes_raw;
            $extra_output_doc .=
                "\n  - \"footnotes\": translated footnotes array; every \"id\" preserved verbatim, every \"content\" translated.";
        }

        if ($has_attrs) {
            $extra_sections[] =
                "Source block attribute strings (translate only the values; every key must remain exactly as shown):\n"
                . wp_json_encode($attr_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $extra_output_doc .=
                "\n  - \"attrs\": object whose keys are the __WPAI_N__ placeholders from the source and whose values are their translations.";
        }

        $extra_output = !empty($extra_sections)
            ? "\n\n" . implode("\n\n", $extra_sections)
            : '';

        // ── Apply configurable input-length cap ───────────────────────────────
        // 0 means "no limit" — the full content is forwarded.
        // A non-zero value from Settings caps the input and logs a warning so
        // administrators know the content was trimmed.
        $max_input = Config::translation_max_input_chars();
        $content_to_translate = $placeholder_content;

        if ($max_input > 0 && mb_strlen($placeholder_content) > $max_input) {
            $content_to_translate = mb_substr($placeholder_content, 0, $max_input);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log; the plugin FAQ directs users to the PHP error log when translations are cut off.
            error_log(sprintf(
                'Lingua Forge AI [Translation] post %d: content trimmed to %d characters (limit set in Translation Limits settings). Blocks beyond that position will not be translated.',
                $post_id,
                $max_input
            ));
        }

        $prompt = str_replace(
            ['{{language}}', '{{title}}', '{{content}}', '{{extra_output}}', '{{extra_output_doc}}'],
            [
                $language_name,
                $post->post_title,
                $content_to_translate,
                $extra_output,
                $extra_output_doc,
            ],
            $prompt
        );

        // ── Translation Memory fork (§4.5) ─────────────────────────────────
        //
        // When TM is enabled and the post is TM-compatible, attempt block-
        // level lookup and a batched API call covering only uncached blocks
        // + title + footnotes. Falls back to the existing JSON-envelope flow
        // when TM doesn't apply (block attrs present, unknown source lang,
        // unsupported post type, or a TM parse failure).
        $source_lang = (string) get_post_meta($post_id, '_lang', true);

        $tm_eligible = TranslationMemory::enabled()
            && ! $force                                            // force_refresh / debug mode bypass TM cache
            && empty($attr_map)                                    // v1 doesn't handle block attribute placeholders
            && $source_lang !== ''                                 // need known source for TM keys
            && in_array($post->post_type, ['post', 'page'], true); // standard types only

        if ($tm_eligible) {

            $tm_result = $this->try_translate_with_tm(
                $post,
                $post_id,
                $cache_key,
                $hash,
                $target_language,
                $language_name,
                $source_lang,
                $content_to_translate,
                $footnotes_raw,
                $has_footnotes,
                $params
            );

            if (is_array($tm_result)) {
                return $tm_result;
            }
            // null return — TM failed gracefully; fall through to existing flow.
        }

        // Build a schema-constrained WorkerConfig for this request. The base
        // config still controls model / max_tokens / temperature; the schema
        // is added on top so each provider can enforce JSON output its own way.
        $base = $this->get_worker_config($post_id);
        $worker_config = new WorkerConfig(
            model:           $base->model,
            max_tokens:      $base->max_tokens,
            temperature:     $base->temperature,
            response_schema: self::build_translation_schema($has_footnotes, $has_attrs),
        );

        // Per-invocation overrides — used by the WP-CLI translate command for
        // --temperature / --max-tokens / --model, and available to user code
        // wanting per-post-type or per-language tuning (REVIEW §4.12).
        $worker_config = apply_filters(
            'linguaforge_translation_worker_config',
            $worker_config,
            $post_id,
            $params
        );

        $provider = ProviderFactory::make($worker_config);

        // ── Debug: log source sent to AI ──────────────────────────────────────
        if (self::debug_enabled()) {
            self::debug_write(
                $post_id,
                $target_language,
                'source',
                $prompt
            );
        }

        $system_prompt = Config::apply_compliance_to_system(
            'You are a professional translator. ' .
            'Preserve all WordPress block comments ' .
            '(<!-- wp:... /-->), HTML tags, shortcodes, ' .
            'and attributes exactly as they appear. ' .
            'Only translate the visible text content. ' .
            'Do NOT add any new HTML tags — especially not <br> or <br/> — ' .
            'that are not already present in the source.',
            $post_id
        );

        // Glossary injection (§4.6) — the post's `_lang` meta is the source.
        // get_for_pair() also returns wildcard rows (source_lang='') so brand
        // names and language-agnostic terms are always enforced.
        $glossary = Glossary::format_for_prompt( $source_lang, $target_language );
        if ( $glossary !== '' ) {
            $system_prompt .= "\n\n" . $glossary;
        }

        $result = UsageRecorder::tracked( 'translation', static fn() => $provider->chat([
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $prompt],
        ]) );

        // ── Debug: log raw AI response ────────────────────────────────────────
        if (self::debug_enabled()) {
            self::debug_write(
                $post_id,
                $target_language,
                'response',
                (string) $result
            );
        }

        if (empty($result)) {

            return [
                'success' => false,
                'error'   => 'Translation failed. Please try again.',
            ];
        }

        // ── Parse the JSON envelope ──────────────────────────────────────────
        // The provider returned a single JSON object conforming to the schema
        // we built above. Replaces the prior ===TITLE=== / ===FOOTNOTES=== /
        // ===ATTRS=== sentinel-marker format that was REVIEW §2.2's headline
        // fragility complaint. OpenAI and Gemini enforce the schema server-
        // side; Anthropic produces it via prefill + system directive.
        $envelope = json_decode(trim($result), true);

        if (!is_array($envelope)) {
            $trimmed         = trim((string) $result);
            $looks_truncated = str_starts_with($trimmed, '{') && !str_ends_with($trimmed, '}');

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log; the plugin FAQ directs users to the PHP error log when translations fail.
            error_log(sprintf(
                'Lingua Forge AI [Translation] post %d: response was not valid JSON%s. First 200 chars: %s',
                $post_id,
                $looks_truncated ? ' (response appears truncated — raise Max output tokens)' : '',
                mb_substr((string) $result, 0, 200)
            ));

            return [
                'success' => false,
                'error'   => $looks_truncated
                    ? 'Translation output truncated — the translated content exceeded the output token limit. '
                      . 'Raise "Max output tokens" in Settings → Lingua Forge AI → Translation Limits, '
                      . 'or pass --max-tokens=20000 on the CLI.'
                    : 'Translation failed: provider returned an unparseable response. Check the PHP error log.',
            ];
        }

        $translated_title     = isset($envelope['title']) ? trim((string) $envelope['title']) : null;
        $translated_content   = isset($envelope['content']) ? trim((string) $envelope['content']) : '';
        $translated_footnotes = null;

        if ($translated_content === '') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for empty content in an otherwise valid envelope.
            error_log(sprintf(
                'Lingua Forge AI [Translation] post %d: JSON envelope decoded but "content" was empty.',
                $post_id
            ));

            return [
                'success' => false,
                'error'   => 'Translation failed: empty translated content. Please try again.',
            ];
        }

        // ── Footnotes ────────────────────────────────────────────────────────
        // The schema validated the shape; we still re-encode here because the
        // rest of the plugin (cache, REST, JS client) treats footnotes as a
        // JSON string.
        if ($has_footnotes && isset($envelope['footnotes']) && is_array($envelope['footnotes'])) {
            $translated_footnotes = wp_json_encode(
                $envelope['footnotes'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        // ── Block attribute placeholders ─────────────────────────────────────
        if ($has_attrs && isset($envelope['attrs']) && is_array($envelope['attrs'])) {
            $translated_content = BlockTextExtractor::reinsert(
                $translated_content,
                $envelope['attrs']
            );
        }

        // ── Strip stray inter-block <br> tags ────────────────────────────────
        // Models reliably hallucinate <br> tags *between* block-comment
        // delimiters to "preserve" the appearance of newlines, breaking the
        // Gutenberg block parser.  strip_interblock_br() removes <br> only
        // from the whitespace-only connector zones between block comments
        // (after <!-- /wp:... --> or <!-- wp:... /-->) and leaves <br> tags
        // that appear inside block HTML (e.g. soft line breaks in <p>) intact.
        $translated_content = BlockTextExtractor::strip_interblock_br($translated_content);

        $payload = [
            'output'   => $translated_content,
            'type'     => 'content',
            'language' => $language_name,
        ];

        if ($translated_title !== null && $translated_title !== '') {
            $payload['translated_title'] = $translated_title;
        }

        if ($translated_footnotes !== null) {
            $payload['footnotes'] = $translated_footnotes;
        }

        CacheStore::set($post_id, $cache_key, $hash, $payload);

        return array_merge(['success' => true], $payload);
    }

    // ── Chunk translation ─────────────────────────────────────────────────────

    /**
     * Translate a free-form text snippet rather than the full post.
     *
     * Chunk mode is a manual workaround for cases where the full-post path
     * fails — most commonly long footnotes or complex HTML passages.  The user
     * pastes the snippet, clicks Translate, gets back the translated text, and
     * copies it wherever it is needed.
     *
     * No block-comment preservation, no ===FOOTNOTES=== parsing, no cache.
     * The result is intentionally kept plain so it is easy to copy-paste.
     *
     * @param  string $language_name  Human-readable language name (e.g. "French").
     * @param  array  $params         Request parameters; chunk_text is required.
     * @return array
     */
    public function run_chunk(string $language_name, array $params): array {

        $chunk_text = trim(wp_unslash($params['chunk_text'] ?? ''));

        if ($chunk_text === '') {
            return [
                'success' => false,
                'error'   => 'No text provided. Paste a snippet into the "Text to translate" field.',
            ];
        }

        $prompt_template = file_get_contents(
            LINGUAFORGE_AI_PATH . '/templates/prompts/translation_chunk.txt'
        );

        if ($prompt_template === false) {
            return [
                'success' => false,
                'error'   => 'Chunk prompt template not found.',
            ];
        }

        $prompt = str_replace(
            ['{{language}}', '{{chunk_text}}'],
            [$language_name, mb_substr($chunk_text, 0, Config::quick_translate_max_input_chars())],
            $prompt_template
        );

        $provider = ProviderFactory::make(Config::apply_compliance(new WorkerConfig(
            model:       Config::model(Config::quick_translate_tier()),
            max_tokens:  Config::quick_translate_max_tokens(),
            temperature: 0.2,
        )));

        $system_prompt = Config::apply_compliance_to_system(
            'You are a professional translator. ' .
            'Output only the translated text — no commentary, no preamble.'
        );

        // Glossary injection — chunk mode doesn't know the source language
        // (the AI auto-detects it), so we only pull wildcard entries
        // (source_lang='') that apply regardless of source: brand names,
        // language-agnostic abbreviations like "kWp".
        $target_code = '';
        foreach ( self::get_languages() as $code => $label ) {
            if ( $label === $language_name ) { $target_code = $code; break; }
        }
        if ( $target_code !== '' ) {
            $glossary = Glossary::format_for_prompt( '', $target_code );
            if ( $glossary !== '' ) {
                $system_prompt .= "\n\n" . $glossary;
            }
        }

        $result = UsageRecorder::tracked( 'translation-chunk', static fn() => $provider->chat([
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $prompt],
        ]) );

        if (empty($result)) {
            return [
                'success' => false,
                'error'   => 'Translation failed. Please try again.',
            ];
        }

        return [
            'success'  => true,
            'output'   => trim($result),
            'type'     => 'chunk',
            'language' => $language_name,
        ];
    }

    // ── Debug helpers ─────────────────────────────────────────────────────────

    /**
     * Resolve the absolute filesystem path of the debug directory.
     *
     * Default: wp-content/uploads/lingua-forge-debug
     *
     * Filterable via `linguaforge_debug_dir` so security-tight sites can
     * redirect debug output to a non-public location outside `/uploads/`.
     * A filter return of empty / non-string falls back to the default so
     * debug-writes (and the Maintenance UI) never silently disappear.
     *
     * Returns the path WITHOUT a trailing slash — callers concatenate
     * `/{filename}` themselves.
     */
    public static function debug_dir(): string {

        $upload_dir  = wp_upload_dir();
        $default_dir = trailingslashit($upload_dir['basedir']) . 'lingua-forge-debug';

        $debug_dir = (string) apply_filters('linguaforge_debug_dir', $default_dir);

        if ($debug_dir === '') {
            $debug_dir = $default_dir;
        }

        return untrailingslashit($debug_dir);
    }

    /**
     * Whether debug logging is currently enabled.
     *
     * Resolution order (constant wins, same pattern WP uses for WP_DEBUG):
     *   1. LINGUAFORGE_AI_DEBUG constant defined in wp-config.php — value is
     *      returned verbatim, regardless of any option setting.
     *   2. linguaforge_ai_debug_enabled option set via Settings → Maintenance.
     *   3. Off by default.
     */
    public static function debug_enabled(): bool {

        if (defined('LINGUAFORGE_AI_DEBUG')) {
            return (bool) LINGUAFORGE_AI_DEBUG;
        }

        return (bool) get_option('linguaforge_ai_debug_enabled', false);
    }

    /**
     * Whether the wp-config.php constant currently overrides the UI toggle.
     *
     * Used by the Settings → Maintenance → Debug Files panel to disable the
     * checkbox (and explain why) when the constant is in force.
     */
    public static function debug_constant_defined(): bool {

        return defined('LINGUAFORGE_AI_DEBUG');
    }

    /**
     * The literal value the LINGUAFORGE_AI_DEBUG constant currently holds.
     *
     * Returns null when the constant isn't defined. Used by the Maintenance
     * UI to render an accurate "forced on / forced off" message.
     */
    public static function debug_constant_value(): ?bool {

        return defined('LINGUAFORGE_AI_DEBUG') ? (bool) LINGUAFORGE_AI_DEBUG : null;
    }

    /**
     * Count the *.txt files currently in the debug directory.
     *
     * Returns 0 when the directory doesn't exist yet (e.g. nobody has run an
     * AI feature since debug was enabled). Glob is wrapped in a defensive
     * `is_dir()` check so we don't fire a PHP warning on missing paths.
     */
    public static function debug_file_count(): int {

        $dir = self::debug_dir();

        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.txt');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Delete every *.txt file in the debug directory.
     *
     * Returns the number of files actually removed. Leaves the directory
     * itself (and its .htaccess block) in place so subsequent debug writes
     * still land cleanly.
     */
    public static function clear_debug_files(): int {

        $dir = self::debug_dir();

        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.txt');
        if (!is_array($files) || empty($files)) {
            return 0;
        }

        // Defensive: only delete *.txt entries whose resolved path is still
        // inside the debug directory. Guards against a hostile symlink that
        // glob might surface (paranoia, but the cost is one realpath() per file).
        $real_dir = realpath($dir);
        if ($real_dir === false) {
            return 0;
        }

        $removed = 0;
        foreach ($files as $path) {
            $real = realpath($path);
            if ($real === false) continue;
            if (strpos($real, $real_dir . DIRECTORY_SEPARATOR) !== 0) continue;

            // wp_delete_file is the WP wrapper around unlink() with proper
            // filter coverage (other plugins can hook in to e.g. archive the
            // file before deletion).
            wp_delete_file($path);
            $removed++;
        }

        return $removed;
    }

    /**
     * Write a debug file to the configured debug directory.
     *
     * Enabled only when LINGUAFORGE_AI_DEBUG is defined in wp-config.php.
     * Files are named: {post_id}-{lang}-{timestamp}-{suffix}.txt
     *
     * @param  int    $post_id   Post being translated.
     * @param  string $lang      Target language code.
     * @param  string $suffix    'source' or 'response'.
     * @param  string $content   Content to write.
     */
    private static function debug_write(int $post_id, string $lang, string $suffix, string $content): void {

        $debug_dir = self::debug_dir();

        if (!is_dir($debug_dir)) {
            wp_mkdir_p($debug_dir);

            // Drop an .htaccess to block direct browser access.
            file_put_contents(
                $debug_dir . '/.htaccess',
                "Deny from all\n"
            );
        }

        $timestamp = gmdate('Ymd-His');
        $filename  = "{$post_id}-{$lang}-{$timestamp}-{$suffix}.txt";

        file_put_contents(
            $debug_dir . '/' . $filename,
            $content
        );
    }
}
