<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Contracts\FeatureInterface;
use LinguaForge\AI\Features\MetaDescription;
use LinguaForge\AI\Features\JsonEnvelopeTranslator;
use LinguaForge\AI\Features\TranslationMemoryTranslator;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Core\JsonRepair;
use LinguaForge\AI\Core\Log;
use LinguaForge\AI\Core\TranslationDebug;
use LinguaForge\AI\Core\TranslationMemory;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\Router\Context;

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
        } elseif ( is_singular() ) {
            // Front-end admin bar: use the singular queried object.
            $post_id = (int) get_queried_object_id();
        }

        if ( ! $post_id ) {
            return null;
        }

        // ── 1. Our own _lang post meta ────────────────────────────────────────

        $lang = (string) get_post_meta( $post_id, '_lf_lang', true );

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

        $code = Context::lang_from_locale( get_locale() );

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
     * The model tier (Quality or Light) is read from Settings → Lingua Forge
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

    // TM orchestration and pure helpers live in TranslationMemoryTranslator.php (extracted 2.1.9).

    // =========================================================================
    // SHARED HELPERS — used by both the TM and JSON-envelope code paths
    // =========================================================================

    /**
     * Build the system prompt for a translation request.
     *
     * Pure function — all WP-dependent values (compliance addendum, glossary text)
     * must be resolved by the caller and passed in as plain strings. This makes the
     * method fully unit-testable without any WordPress stubs, and reusable outside
     * this class — e.g. the Settings → AI Provider "Test model" content check
     * (ApiKeysTab::ajax_test_model()) calls this directly to build a realistic
     * system prompt for whichever tier/model it's verifying.
     *
     * @param  string $compliance_addendum  Output of Config::active_preset_addendum() — '' for standard.
     * @param  string $glossary_text        Output of Glossary::format_for_prompt() — '' when empty.
     * @param  string $extra_instruction    Optional sentence inserted before the CRITICAL JSON RULE.
     * @return string
     */
    public static function build_system_prompt(
        string $compliance_addendum,
        string $glossary_text,
        string $extra_instruction = ''
    ): string {

        $extra = $extra_instruction !== '' ? ' ' . rtrim( $extra_instruction, '.' ) . '.' : '';

        $base = 'You are a professional translator. ' .
            'Preserve all WordPress block comments (<!-- wp:... /-->), HTML tags, shortcodes, ' .
            'and attributes exactly as they appear. ' .
            'Only translate the visible text content. ' .
            'Do NOT add any new HTML tags — especially not <br> or <br/> — ' .
            'that are not already present in the source.' .
            $extra .
            ' CRITICAL JSON RULE: within every JSON string value, any double-quote character " ' .
            '(used for direct speech, technical terms, or any other purpose) ' .
            'MUST be escaped as \" — bare " inside a string breaks the JSON.';

        $prompt = $compliance_addendum !== '' ? trim( $base ) . "\n\n" . $compliance_addendum : $base;

        if ( $glossary_text !== '' ) {
            $prompt .= "\n\n" . $glossary_text;
        }

        return $prompt;
    }

    /**
     * Resolve the compliance addendum for a post and return it as a plain string.
     *
     * Thin WP-aware helper so callers can obtain the addendum without importing
     * Config — and so unit tests can bypass this method entirely.
     *
     * @param  int $post_id  Pass 0 for the global preset.
     * @return string  The addendum text, or '' for the standard (no-addendum) preset.
     */
    public static function resolve_compliance_addendum( int $post_id ): string {

        $preset = Config::active_preset( $post_id );
        if ( $preset === 'standard' ) {
            return '';
        }
        return Config::preset_addendum( $preset );
    }

    /**
     * Prepare all inputs needed for a full-post translation request.
     *
     * Pure function — all WP-dependent values (post fields, source language,
     * input-length cap) must be resolved by the caller and passed in as plain
     * scalars. This makes the method fully unit-testable without any WordPress
     * stubs or a real post object.
     *
     * On failure returns an error payload array (success => false).
     * On success returns a context array with '_success' => true plus all
     * the derived values the caller needs.
     *
     * @param  string $title            Post title (plain text).
     * @param  string $content          Raw post_content (block markup).
     * @param  string $excerpt          Post excerpt / short description (may be '').
     * @param  string $source_lang      Two-letter source language code (e.g. 'en').
     * @param  string $footnotes_raw    Raw footnotes JSON string ('' when absent).
     * @param  string $language_name    Human-readable target language (e.g. 'French').
     * @param  string $prompt_template  Contents of the translation.txt prompt template.
     * @param  int    $max_input        Max content chars (0 = no limit) from Config.
     * @param  int    $post_id          Post ID — used only in the trim diagnostic log.
     * @return array
     */
    public static function prepare_full_post_inputs(
        string $title,
        string $content,
        string $excerpt,
        string $source_lang,
        string $footnotes_raw,
        string $language_name,
        string $prompt_template,
        int    $max_input,
        int    $post_id = 0
    ): array {

        // Block attribute extraction — pull translatable strings out of block
        // comment JSON and replace them with __WPAI_N__ placeholders.
        [ $placeholder_content, $attr_map ] = BlockTextExtractor::extract( $content );

        // Detect optional payloads.
        $has_footnotes = false;
        if ( $footnotes_raw !== '' ) {
            $decoded = json_decode( $footnotes_raw, true );
            if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                $has_footnotes = true;
            }
        }
        $has_attrs   = ! empty( $attr_map );
        $has_excerpt = trim( $excerpt ) !== '';

        // Build per-section prompt inserts.
        $extra_sections   = [];
        $extra_output_doc = '';

        if ( $has_footnotes ) {
            $extra_sections[]  = "Source footnotes JSON (translate only each \"content\" value; leave every \"id\" unchanged):\n" . $footnotes_raw;
            $extra_output_doc .= "\n  - \"footnotes\": translated footnotes array; every \"id\" preserved verbatim, every \"content\" translated.";
        }
        if ( $has_attrs ) {
            $extra_sections[]  = "Source block attribute strings (translate only the values; every key must remain exactly as shown):\n"
                . wp_json_encode( $attr_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            $extra_output_doc .= "\n  - \"attrs\": object whose keys are the __WPAI_N__ placeholders from the source and whose values are their translations.";
        }
        if ( $has_excerpt ) {
            $extra_sections[]  = "Source short description (post excerpt — translate all visible text; preserve any HTML tags exactly):\n" . $excerpt;
            $extra_output_doc .= "\n  - \"excerpt\": translated short description / post excerpt (HTML preserved, only visible text translated).";
        }

        $extra_output = ! empty( $extra_sections ) ? "\n\n" . implode( "\n\n", $extra_sections ) : '';

        // Apply configurable input-length cap (0 = no limit).
        $content_to_translate = $placeholder_content;

        if ( $max_input > 0 && mb_strlen( $placeholder_content ) > $max_input ) {
            $content_to_translate = mb_substr( $placeholder_content, 0, $max_input );
            Log::debug( sprintf(
                'Lingua Forge AI [Translation] post %d: content trimmed to %d characters (limit set in Translation Limits settings). Blocks beyond that position will not be translated.',
                $post_id,
                $max_input
            ) );
        }

        // Render prompt template.
        $rendered_prompt = str_replace(
            [ '{{language}}', '{{title}}', '{{content}}', '{{extra_output}}', '{{extra_output_doc}}' ],
            [ $language_name, $title, $content_to_translate, $extra_output, $extra_output_doc ],
            $prompt_template
        );

        return [
            '_success'             => true,
            'content_to_translate' => $content_to_translate,
            'attr_map'             => $attr_map,
            'has_footnotes'        => $has_footnotes,
            'has_attrs'            => $has_attrs,
            'has_excerpt'          => $has_excerpt,
            'prompt'               => $rendered_prompt,
            'source_lang'          => $source_lang,
        ];
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
                // Intersect with linguaforge_languages() so the dropdown shows
                // only languages active on this instance — same source as the
                // Quick Translate modal (MetaBox::instance_languages()).
                // English names are kept as-is for AI prompt compatibility.
                'options' => array_intersect_key(
                    self::get_languages(),
                    array_flip( linguaforge_languages() )
                ),
            ],
            [
                'name'        => 'chunk_text',
                'type'        => 'textarea',
                'label'       => __( 'Text to translate', 'lingua-forge' ),
                'placeholder' => __( 'Paste a footnote, sentence, or any snippet here…', 'lingua-forge' ),
                'rows'        => 6,
                'condition'   => ['translate_mode' => 'chunk'],
            ],
            [
                'name'      => 'with_meta_description',
                'type'      => 'checkbox',
                'label'     => __( 'Also generate meta description', 'lingua-forge' ),
                'condition' => ['translate_mode' => 'full'],
            ],
        ];
    }

    /**
     * Pre-select the target language from the post's _lang meta so that,
     * e.g., a French page (‹_lang = fr›) has French already selected even
     * when its current content was imported in another language.
     */
    public function get_field_defaults(int $post_id): array {

        $defaults = [
            // "Also generate meta description" is on by default so editors
            // immediately get a translated meta description without a second step.
            'with_meta_description' => '1',
        ];

        $lang = (string) get_post_meta($post_id, '_lf_lang', true);

        if ($lang !== '' && array_key_exists($lang, self::get_languages())) {
            $defaults['target_language'] = $lang;
        }

        return $defaults;
    }

    public function supports(int $post_id): bool {

        return current_user_can('edit_post', $post_id);
    }

    public function run(int $post_id, array $params = []): array {

        // ── Validate params + chunk fork ──────────────────────────────────────
        $target_language = sanitize_text_field( $params['target_language'] ?? 'en' );
        if ( ! array_key_exists( $target_language, self::get_languages() ) ) {
            return [ 'success' => false, 'error' => 'Invalid target language.' ];
        }
        $language_name  = self::get_languages()[ $target_language ];
        $translate_mode = sanitize_text_field( $params['translate_mode'] ?? 'full' );
        if ( $translate_mode === 'chunk' ) {
            return $this->run_chunk( $language_name, $params );
        }

        // ── Post load ─────────────────────────────────────────────────────────
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'success' => false, 'error' => 'Post not found.' ];
        }

        // ── Cache key / hash ──────────────────────────────────────────────────
        // Prefer the footnotes value forwarded by the JS client from the live
        // Gutenberg meta store — captures unsaved footnotes not yet in the DB.
        $param_footnotes = isset( $params['footnotes_meta'] ) && is_string( $params['footnotes_meta'] )
            ? wp_unslash( $params['footnotes_meta'] )
            : '';
        $footnotes_raw = ( $param_footnotes !== '' && json_decode( $param_footnotes ) !== null )
            ? $param_footnotes
            : (string) get_post_meta( $post_id, 'footnotes', true );

        $cache_key = $this->get_key() . '_' . $target_language;
        $hash      = CacheStore::hash( [ $post->post_title, $post->post_content, $footnotes_raw, $target_language, Config::provider(), Config::model( Config::translation_tier() ) ] );

        // Debug mode bypasses cache so every click triggers a live API call.
        $force  = ! empty( $params['force_refresh'] ) || TranslationDebug::debug_enabled();
        $cached = $force ? null : CacheStore::get( $post_id, $cache_key, $hash );
        if ( $cached !== null ) {
            return array_merge( [ 'success' => true, 'cached' => true ], $cached );
        }

        // ── Resolve WP-dependent values before calling the pure helper ────────
        $source_lang = (string) get_post_meta( $post_id, '_lf_lang', true );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read from plugin assets; not a remote URL.
        $prompt_template = file_get_contents( LINGUAFORGE_AI_PATH . '/templates/prompts/translation.txt' );
        if ( $prompt_template === false ) {
            return [ 'success' => false, 'error' => 'Prompt template not found.' ];
        }

        // ── Prepare content inputs ────────────────────────────────────────────
        $ctx = self::prepare_full_post_inputs(
            $post->post_title,
            $post->post_content,
            (string) $post->post_excerpt,
            $source_lang,
            $footnotes_raw,
            $language_name,
            $prompt_template,
            Config::translation_max_input_chars(),
            $post_id
        );
        if ( empty( $ctx['_success'] ) ) {
            return $ctx; // propagate error payload
        }

        // ── Translation Memory fork (§4.5) ────────────────────────────────────
        // Block-level cache + batched API. Falls back to JSON-envelope when TM
        // doesn't apply or returns null on any recoverable failure.
        $tm_eligible = TranslationMemory::enabled()
            && ! $force
            && empty( $ctx['attr_map'] )     // v1 doesn't handle block-attr placeholders
            && ! $ctx['has_excerpt']          // TM path skips post_excerpt
            && $ctx['source_lang'] !== '';    // need known source for TM keys

        if ( $tm_eligible ) {
            $tm_translator = new TranslationMemoryTranslator(
                $this->get_worker_config( $post_id ),
                self::build_system_prompt(
                    self::resolve_compliance_addendum( $post_id ),
                    Glossary::format_for_prompt( $ctx['source_lang'], $target_language ),
                    'You will receive an array of blocks; return their translations as an array of the same length and order.'
                )
            );
            $tm_result = $tm_translator->translate(
                $post, $post_id, $cache_key, $hash,
                $target_language, $language_name, $ctx['source_lang'],
                $ctx['content_to_translate'], $footnotes_raw, $ctx['has_footnotes'],
                $params
            );
            if ( is_array( $tm_result ) ) {
                if ( ! empty( $params['with_meta_description'] ) ) {
                    $tm_result = $this->chain_meta_description( $tm_result, $post_id, $target_language );
                }
                return $tm_result;
            }
            // null → TM failed gracefully; fall through to JSON-envelope path.
        }

        $envelope_translator = new JsonEnvelopeTranslator(
            $this->get_worker_config( $post_id ),
            self::build_system_prompt(
                self::resolve_compliance_addendum( $post_id ),
                Glossary::format_for_prompt( $ctx['source_lang'], $target_language )
            )
        );
        $result = $envelope_translator->translate(
            $post, $post_id, $ctx, $cache_key, $hash, $language_name, $target_language, $params
        );

        if ( ! empty( $result['success'] ) && ! empty( $params['with_meta_description'] ) ) {
            $result = $this->chain_meta_description( $result, $post_id, $target_language );
        }

        return $result;
    }

    // JSON-envelope path lives in JsonEnvelopeTranslator.php (extracted 2.1.9).


    // ── Meta description chaining ─────────────────────────────────────────────

    /**
     * Generate a meta description from the already-translated content and
     * merge it into the result payload.
     *
     * By passing the translated content directly to MetaDescription::run()
     * we avoid a second content round-trip: the full post body was already
     * sent to the translation provider, and now the meta description is
     * derived from the in-memory result rather than re-reading from the DB.
     * MetaDescription::run() skips its cache in this mode and does not write
     * a new cache entry (the content hasn't been saved to the post yet).
     *
     * @param  array  $result           Already-merged success payload.
     * @param  int    $post_id          Post being translated.
     * @param  string $target_language  Two-letter target language code.
     * @return array  $result augmented with 'meta_description' on success.
     */
    private function chain_meta_description(
        array  $result,
        int    $post_id,
        string $target_language
    ): array {

        $md = (new MetaDescription())->run($post_id, [
            'content' => $result['output']           ?? '',
            'title'   => $result['translated_title'] ?? '',
            'lang'    => $target_language,
        ]);

        if (!empty($md['success']) && !empty($md['output'])) {
            $result['meta_description'] = $md['output'];
        }

        return $result;
    }

    // ── Chunk translation ─────────────────────────────────────────────────────

    /**
     * Translate a free-form text snippet rather than the full post.
     *
     * Delegates to ChunkTranslation, which is extracted into its own class so
     * the logic can be unit-tested with a mock AIProviderInterface. This method
     * owns only provider creation; all business logic lives in ChunkTranslation::run().
     *
     * @param  string $language_name  Human-readable language name (e.g. "French").
     * @param  array  $params         Request parameters; chunk_text is required.
     * @return array
     */
    public function run_chunk(string $language_name, array $params): array {

        $provider = ProviderFactory::make( Config::apply_compliance( new WorkerConfig(
            model:       Config::model( Config::quick_translate_tier() ),
            max_tokens:  Config::quick_translate_max_tokens(),
            temperature: 0.2,
        ) ) );

        return ( new ChunkTranslation( $provider ) )->run( $language_name, $params );
    }

}
