<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Contracts\FeatureInterface;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;

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
     *   3. WPML — the wpml_post_language_details filter returns an array with
     *      a 'language_code' key when WPML is installed and active.
     *
     *   4. Site locale — get_locale() (e.g. "de_DE") is trimmed to its 2-letter
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
            // and enqueue_block_editor_assets.
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

            // Screen base 'post' covers all post types in both the classic
            // editor and Gutenberg (posts, pages, custom post types).
            if ( $screen && $screen->base === 'post' ) {
                $post_id = (int) ( $_GET['post']     ?? 0 )
                        ?: (int) ( $_POST['post_ID'] ?? 0 );
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

        // ── 3. WPML ───────────────────────────────────────────────────────────

        $wpml = apply_filters( 'wpml_post_language_details', null, $post_id );

        if ( is_array( $wpml ) && ! empty( $wpml['language_code'] ) ) {

            $lang = (string) $wpml['language_code'];

            if ( array_key_exists( $lang, self::get_languages() ) ) {
                return $lang;
            }
        }

        // ── 4. Site locale ────────────────────────────────────────────────────
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
     * Sonnet offers stronger multilingual quality and can handle the
     * larger token budgets required by full-page content.
     *
     * max_tokens is read from Settings → LinguaForge AI → Translation Limits
     * (default: 16 000).  Raise it there if you hit truncation on very large
     * pages rather than touching this code.
     */
    public function get_worker_config(): WorkerConfig {

        return new WorkerConfig(
            model:       Config::model('quality'),
            max_tokens:  Config::translation_max_tokens(),
            temperature: 0.2,
        );
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
        $force = !empty($params['force_refresh']) || (defined('LINGUAFORGE_AI_DEBUG') && LINGUAFORGE_AI_DEBUG);
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

        // ── Build optional extra-output sections ─────────────────────────────
        // All additional output sections (footnotes, block attrs) are assembled
        // first so they can be injected into the {{extra_output}} placeholder
        // in the template.  Putting them inside the template — rather than
        // appending after it — eliminates the prompt conflict that previously
        // caused the model to skip these sections: the template used to say
        // "nothing else before or after the content body" and then we appended
        // instructions telling the model to output ===FOOTNOTES=== afterward,
        // which it silently ignored.

        $has_footnotes  = false;
        $extra_output   = '';
        $extra_sections = [];

        // ── WordPress core footnotes (footnotes post meta) ────────────────────
        if ($footnotes_raw !== '') {
            $decoded = json_decode($footnotes_raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                $has_footnotes    = true;
                $extra_sections[] =
                    "After the translated content, output this exact separator on its own line:\n" .
                    "===FOOTNOTES===\n" .
                    "Then output the complete translated footnotes JSON array.\n" .
                    "Translate only each \"content\" field value; leave every \"id\" value unchanged.\n\n" .
                    "Footnotes JSON:\n" . $footnotes_raw;
            }
        }

        // ── Block attribute strings ───────────────────────────────────────────
        if (!empty($attr_map)) {
            $extra_sections[] =
                "After the translated content (and after the ===FOOTNOTES=== section if present), " .
                "output this exact separator on its own line:\n" .
                "===ATTRS===\n" .
                "Then output a JSON object mapping each placeholder key to its translation. " .
                "Translate only the values — every key must remain exactly as shown.\n\n" .
                "Block attribute strings:\n" .
                wp_json_encode($attr_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (!empty($extra_sections)) {
            $extra_output = "\n\n" . implode("\n\n", $extra_sections);
        }

        // ── Apply configurable input-length cap ───────────────────────────────
        // 0 means "no limit" — the full content is forwarded.
        // A non-zero value from Settings caps the input and logs a warning so
        // administrators know the content was trimmed.
        $max_input = Config::translation_max_input_chars();
        $content_to_translate = $placeholder_content;

        if ($max_input > 0 && mb_strlen($placeholder_content) > $max_input) {
            $content_to_translate = mb_substr($placeholder_content, 0, $max_input);
            error_log(sprintf(
                'LinguaForge AI [Translation] post %d: content trimmed to %d characters (limit set in Translation Limits settings). Blocks beyond that position will not be translated.',
                $post_id,
                $max_input
            ));
        }

        $prompt = str_replace(
            ['{{language}}', '{{title}}', '{{content}}', '{{extra_output}}'],
            [
                $language_name,
                $post->post_title,
                $content_to_translate,
                $extra_output,
            ],
            $prompt
        );

        $provider = ProviderFactory::make(
            $this->get_worker_config()
        );

        // ── Debug: log source sent to AI ──────────────────────────────────────
        if (defined('LINGUAFORGE_AI_DEBUG') && LINGUAFORGE_AI_DEBUG) {
            self::debug_write(
                $post_id,
                $target_language,
                'source',
                $prompt
            );
        }

        $result = $provider->chat([
            [
                'role'    => 'system',
                'content' =>
                    'You are a professional translator. ' .
                    'Preserve all WordPress block comments ' .
                    '(<!-- wp:... /-->), HTML tags, shortcodes, ' .
                    'and attributes exactly as they appear. ' .
                    'Only translate the visible text content. ' .
                    'Do NOT add any new HTML tags — especially not <br> or <br/> — ' .
                    'that are not already present in the source.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ]);

        // ── Debug: log raw AI response ────────────────────────────────────────
        if (defined('LINGUAFORGE_AI_DEBUG') && LINGUAFORGE_AI_DEBUG) {
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

        // ── Split response sections ───────────────────────────────────────────
        // Response structure:
        //   ===TITLE===          (always first)
        //   [translated title]
        //   [translated content body]
        //   ===FOOTNOTES===      (optional)
        //   [footnotes JSON]
        //   ===ATTRS===          (optional, always last)
        //   [block attribute translations JSON]
        //
        // Parse outermost sections first so each step works on clean input.
        $translated_content   = trim($result);
        $translated_footnotes = null;
        $translated_title     = null;

        // ── ===TITLE=== (first line of response) ──────────────────────────────
        if (str_starts_with($translated_content, '===TITLE===')) {

            $after = ltrim(substr($translated_content, strlen('===TITLE===')));
            $nl    = strpos($after, "\n");

            if ($nl !== false) {
                $translated_title   = trim(substr($after, 0, $nl));
                $translated_content = trim(substr($after, $nl + 1));
            } else {
                // Edge case: model returned only a title with no body.
                $translated_title   = trim($after);
                $translated_content = '';
            }
        }

        // Strip ===ATTRS=== section before handling footnotes.
        $translated_attrs_json = null;
        if (!empty($attr_map) && str_contains($translated_content, '===ATTRS===')) {

            $parts                 = explode('===ATTRS===', $translated_content, 2);
            $translated_content    = trim($parts[0]);
            $translated_attrs_json = trim($parts[1]);
        }

        if ($has_footnotes && str_contains($translated_content, '===FOOTNOTES===')) {

            [$content_part, $footnotes_part] = explode('===FOOTNOTES===', $translated_content, 2);

            $translated_content = trim($content_part);
            $candidate          = trim($footnotes_part);

            // Only accept the footnotes section if it is valid JSON.
            if (json_decode($candidate) !== null) {
                $translated_footnotes = $candidate;
            } else {
                error_log('LinguaForge AI [Translation] footnotes section was not valid JSON — skipped.');
            }
        }

        // Reinsert translated block attribute strings.
        if ($translated_attrs_json !== null) {

            $translated_attrs = json_decode($translated_attrs_json, true);

            if (is_array($translated_attrs)) {
                $translated_content = BlockTextExtractor::reinsert(
                    $translated_content,
                    $translated_attrs
                );
            } else {
                error_log('LinguaForge AI [Translation] block attribute translations were not valid JSON — skipped.');
            }
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

        $provider = ProviderFactory::make(new WorkerConfig(
            model:       Config::model(Config::quick_translate_tier()),
            max_tokens:  Config::quick_translate_max_tokens(),
            temperature: 0.2,
        ));

        $result = $provider->chat([
            [
                'role'    => 'system',
                'content' =>
                    'You are a professional translator. ' .
                    'Output only the translated text — no commentary, no preamble.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ]);

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
     * Write a debug file to wp-content/uploads/lingua-forge-debug/.
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

        $upload_dir = wp_upload_dir();
        $debug_dir  = trailingslashit($upload_dir['basedir']) . 'lingua-forge-debug';

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
