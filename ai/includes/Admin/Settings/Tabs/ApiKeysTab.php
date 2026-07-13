<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\ModelCatalog;
use LinguaForge\AI\Features\ChunkTranslation;
use LinguaForge\AI\Features\JsonEnvelopeTranslator;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\Anthropic;
use LinguaForge\AI\Providers\Gemini;
use LinguaForge\AI\Providers\OpenAI;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\Router\Context;

defined('ABSPATH') || exit;

/**
 * Settings tab: API Keys
 *
 * Per-provider key management (set / remove) and the Test Connection AJAX
 * endpoint.
 */
class ApiKeysTab extends Tab {

    public static function slug(): string {
        return 'api-keys';
    }

    public static function label(): string {
        return __( 'API Keys', 'lingua-forge' );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        ?>
        <!-- ── API Keys ──────────────────────────────────────────── -->
        <h2><?php esc_html_e('API Keys', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Keys are encrypted with AES-256-CBC before being stored in the WordPress database. The encryption secret is derived from your WordPress auth salts (wp-config.php), so plaintext keys never touch the database.',
                'lingua-forge'
            );
            ?>
        </p>

        <table class="form-table" role="presentation">

            <?php foreach (SettingsPage::providers() as $slug => $label): ?>

                <?php
                // WordPress AI Client manages credentials through WP's Connectors screen.
                // No API key is stored by Lingua Forge for this provider.
                if ( $slug === 'wp-ai-client' ):
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $label ); ?></th>
                    <td>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s is a link to the WordPress Connectors settings screen. */
                                esc_html__(
                                    'API credentials are managed by WordPress in %s. No key is stored by Lingua Forge.',
                                    'lingua-forge'
                                ),
                                sprintf(
                                    '<a href="%s">%s</a>',
                                    esc_url( admin_url( 'options-general.php?page=connectors' ) ),
                                    esc_html__( 'Settings → Connectors', 'lingua-forge' )
                                )
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <?php continue; endif; ?>

                <?php
                $source     = KeyStore::source($slug);
                $configured = $source !== null;
                ?>

                <tr>
                    <th scope="row">
                        <label for="linguaforge_key_<?php echo esc_attr($slug); ?>">
                            <?php echo esc_html($label); ?>
                            <?php esc_html_e('API Key', 'lingua-forge'); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="linguaforge_key_<?php echo esc_attr($slug); ?>"
                            name="linguaforge_key_<?php echo esc_attr($slug); ?>"
                            class="regular-text"
                            autocomplete="new-password"
                            placeholder="<?php
                                echo $configured
                                    ? esc_attr( '••••••••••••••••' )
                                    : esc_attr( __( 'Paste your API key…', 'lingua-forge' ) );
                            ?>"
                        >

                        <span class="lingua-forge-key-badge <?php
                            echo $configured ? 'lingua-forge-badge--ok' : 'lingua-forge-badge--missing';
                        ?>">
                            <?php if ($configured): ?>
                                <?php esc_html_e( '✓ Configured', 'lingua-forge' ); ?>
                                <span class="lingua-forge-key-source">
                                    (<?php echo esc_html($source); ?>)
                                </span>
                            <?php else: ?>
                                <?php esc_html_e('✗ Not configured', 'lingua-forge'); ?>
                            <?php endif; ?>
                        </span>

                        <?php if ($configured): ?>
                            <button
                                type="button"
                                class="button button-secondary lingua-forge-test-key"
                                data-provider="<?php echo esc_attr($slug); ?>"
                            >
                                <?php esc_html_e( 'Test connection', 'lingua-forge' ); ?>
                            </button>
                            <span
                                class="lingua-forge-test-result"
                                data-for="<?php echo esc_attr($slug); ?>"
                                aria-live="polite"
                            ></span>
                        <?php endif; ?>

                        <p class="description">
                            <?php
                            esc_html_e(
                                'Leave blank to keep the existing key. Enter a new value to replace it.',
                                'lingua-forge'
                            );
                            ?>
                        </p>

                        <?php if ($source === 'database'): ?>
                            <p>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="linguaforge_remove_<?php echo esc_attr($slug); ?>"
                                        value="1"
                                    >
                                    <?php esc_html_e('Remove stored key', 'lingua-forge'); ?>
                                </label>
                            </p>
                        <?php elseif ($source === 'environment' || $source === 'constant'): ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s is the key source: either "environment variable" or "PHP constant". */
                                    esc_html__(
                                        'This key is currently supplied by a server %s and cannot be removed here. Enter a new key above to override it with a database value.',
                                        'lingua-forge'
                                    ),
                                    $source === 'environment'
                                        ? esc_html__('environment variable', 'lingua-forge')
                                        : esc_html__('PHP constant', 'lingua-forge')
                                );
                                ?>
                            </p>
                        <?php endif; ?>

                    </td>
                </tr>

            <?php endforeach; ?>

        </table>

        <!-- ── Server-side key sources ──────────────────────── -->
        <div class="lingua-forge-settings-note">
            <p>
                <strong><?php esc_html_e('Alternative (server-side):', 'lingua-forge'); ?></strong>
                <?php
                esc_html_e( 'You can also define keys as constants or environment variables (e.g. in wp-config.php). Those sources are used automatically as a fallback when no database key is stored.', 'lingua-forge' );
                ?>
            </p>
            <pre class="lingua-forge-code-sample">define( 'ANTHROPIC_API_KEY', 'sk-ant-…' );
define( 'OPENAI_API_KEY',    'sk-…' );</pre>
            <p>
                <?php
                esc_html_e(
                    'To use a custom encryption secret (instead of the derived wp_salt value), add this to wp-config.php:',
                    'lingua-forge'
                );
                ?>
            </p>
            <pre class="lingua-forge-code-sample">define( 'LINGUAFORGE_SECRET', 'your-random-secret' );</pre>
        </div>
        <?php
    }

    // ── Test Connection (AJAX) ────────────────────────────────────────────────

    /**
     * Run a minimal "ping" chat call against a single provider and report
     * back as JSON. Wired to wp_ajax_linguaforge_test_provider.
     *
     * Why per-provider rather than always-active-provider: admins frequently
     * configure multiple keys and want to validate each one independently
     * before flipping the active provider in Settings → Active Provider.
     *
     * Response payload (always JSON, status 200 so the JS client can read it):
     *   {
     *     success: bool,
     *     provider: 'anthropic'|'openai'|'gemini',
     *     message?: string,   // present on failure
     *     reply?:  string,    // present on success (truncated provider text)
     *     models?: string[],  // present on success — merged catalog + live IDs
     *   }
     */
    public static function ajax_test_provider(): void {

        if (!current_user_can('manage_options')) {
            wp_send_json([
                'success' => false,
                'message' => __('Permission denied.', 'lingua-forge'),
            ]);
        }

        check_ajax_referer('linguaforge_test_provider', 'nonce');

        $provider_slug = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $providers     = SettingsPage::providers();

        if (!array_key_exists($provider_slug, $providers)) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Unknown provider.', 'lingua-forge'),
            ]);
        }

        if (!KeyStore::get($provider_slug)) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('No API key configured for this provider.', 'lingua-forge'),
            ]);
        }

        // Build a low-cost WorkerConfig for the ping — light tier, tight token
        // budget so an accidental verbose model can't run up much cost.
        // Config::model() always uses the active provider, so we resolve the
        // light-tier model for the requested provider here: stored override
        // first, fall back to the hard-coded default.
        $model_option = (string) get_option("linguaforge_model_{$provider_slug}_light", '');
        $model        = $model_option !== ''
            ? $model_option
            : Config::default_model($provider_slug, 'light');

        $config = new WorkerConfig(
            model:       $model,
            max_tokens:  16,
            temperature: 0.0,
        );

        $provider_instance = match ($provider_slug) {
            'anthropic' => new Anthropic($config),
            'openai'    => new OpenAI($config),
            'gemini'    => new Gemini($config),
            default     => null,
        };

        if ($provider_instance === null) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Could not instantiate the provider.', 'lingua-forge'),
            ]);
        }

        $reply = self::ping($provider_instance);

        if ($reply === null || $reply === '') {
            $error_detail = $provider_instance->get_last_error();
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => $error_detail !== ''
                    ? $error_detail
                    : __('Provider returned no response. Check the WordPress error log for details.', 'lingua-forge'),
            ]);
        }

        // Fetch available models from the provider API and cache for 24 h.
        // Non-fatal: an empty list simply means no live models were returned.
        $api_key    = (string) KeyStore::get($provider_slug);
        $live_ids   = ModelCatalog::fetch_from_api($provider_slug, $api_key);
        $models     = ModelCatalog::merge_live($provider_slug, $live_ids);
        set_transient(
            'linguaforge_available_models_' . $provider_slug,
            $models,
            DAY_IN_SECONDS
        );

        wp_send_json([
            'success'  => true,
            'provider' => $provider_slug,
            'reply'    => mb_substr((string) $reply, 0, 200),
            'models'   => $models,
        ]);
    }

    // ── Test Model (AJAX) ─────────────────────────────────────────────────────

    /** Content sent to the AI is capped here regardless of the site's real
     *  Translation Limits settings, so a content test stays fast and cheap
     *  no matter how large the sampled post is. */
    private const TEST_CONTENT_MAX_CHARS = 1500;
    private const TEST_CHUNK_MAX_CHARS   = 500;
    private const TEST_MAX_TOKENS        = 3000;

    /**
     * Run a realistic translation of real site content through a single,
     * exact model string — whatever is currently typed into a Models field,
     * whether or not it has been saved yet — using the tier's real production
     * code path (JSON-envelope full-post translation for Quality, chunk
     * translation for Light) and the currently active behaviour preset
     * (Settings → Behavior), exactly as a live translation would. Wired to
     * wp_ajax_linguaforge_test_model.
     *
     * This replaced an earlier "reply with the word ping" smoke test — a
     * ping only proves the model *responds*, not that it follows
     * instructions, respects the active preset's temperature/addendum, or
     * produces usable structured output for the feature the tier actually
     * serves. Feeding a real post through the real prompt-building and
     * response-parsing logic is what actually answers "does this model work
     * for us," which a bare ping cannot.
     *
     * ajax_test_provider() above still does the cheap ping — it's for
     * validating a bare API key across all providers quickly, not for
     * judging a specific tier's translation quality.
     *
     * Response payload (always JSON, status 200):
     *   {
     *     success:        bool,
     *     model:          string,
     *     tier:           'light'|'quality',
     *     message?:       string,  // present on failure
     *     sourceTitle?:   string,  // title of the real post used as sample content
     *     sourceLang?:    string,  // detected source language code
     *     targetLang?:    string,  // target language code chosen for the test
     *     targetLanguage?:string,  // target language English name
     *     preset?:        string,  // active behaviour preset label (Standard, Legal / Compliance, …)
     *     translatedTitle?:string, // Quality tier only, when the model returned one
     *     outputPreview?: string,  // truncated translated text
     *   }
     */
    public static function ajax_test_model(): void {

        if (!current_user_can('manage_options')) {
            wp_send_json([
                'success' => false,
                'message' => __('Permission denied.', 'lingua-forge'),
            ]);
        }

        check_ajax_referer('linguaforge_test_provider', 'nonce');

        $provider_slug = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $providers     = SettingsPage::providers();

        if (!array_key_exists($provider_slug, $providers) || $provider_slug === 'wp-ai-client') {
            wp_send_json([
                'success' => false,
                'message' => __('Unknown provider.', 'lingua-forge'),
            ]);
        }

        if (!KeyStore::get($provider_slug)) {
            wp_send_json([
                'success' => false,
                'message' => __('No API key configured for this provider.', 'lingua-forge'),
            ]);
        }

        $model = trim(sanitize_text_field(wp_unslash($_POST['model'] ?? '')));

        if ($model === '') {
            wp_send_json([
                'success' => false,
                'message' => __('Enter a model identifier to test.', 'lingua-forge'),
            ]);
        }

        $tier_raw = sanitize_key(wp_unslash($_POST['tier'] ?? ''));
        $tier     = $tier_raw === 'quality' ? 'quality' : 'light';

        $post = self::pick_sample_post();

        if ($post === null) {
            wp_send_json([
                'success' => false,
                'model'   => $model,
                'tier'    => $tier,
                'message' => __('No published post or page found to test with — publish some content first, or try Quick Translate directly.', 'lingua-forge'),
            ]);
        }

        $source_lang = (string) get_post_meta($post->ID, '_lf_lang', true);
        if ($source_lang === '') {
            $source_lang = Context::lang_from_locale(get_locale());
        }
        if ($source_lang === '') {
            $source_lang = 'en';
        }

        $target_lang   = self::pick_target_language($source_lang);
        $language_name = Translation::get_languages()[$target_lang] ?? ucfirst($target_lang);

        $preset       = Config::active_preset(0);
        $preset_label = (string) (Config::presets()[$preset]['label'] ?? $preset);

        $provider_class = match ($provider_slug) {
            'anthropic' => Anthropic::class,
            'openai'    => OpenAI::class,
            'gemini'    => Gemini::class,
            default     => null,
        };

        if ($provider_class === null) {
            wp_send_json([
                'success' => false,
                'model'   => $model,
                'tier'    => $tier,
                'message' => __('Could not instantiate the provider.', 'lingua-forge'),
            ]);
        }

        $common = [
            'model'          => $model,
            'tier'           => $tier,
            'sourceTitle'    => $post->post_title !== '' ? $post->post_title : __('(untitled)', 'lingua-forge'),
            'sourceLang'     => $source_lang,
            'targetLang'     => $target_lang,
            'targetLanguage' => $language_name,
            'preset'         => $preset_label,
        ];

        $outcome = $tier === 'quality'
            ? self::run_quality_content_test($provider_class, $model, $post, $source_lang, $target_lang, $language_name)
            : self::run_light_content_test($provider_class, $model, $post, $target_lang, $language_name);

        wp_send_json(array_merge($common, $outcome));
    }

    /**
     * Quality-tier content test — mirrors Translation::run()'s JSON-envelope
     * path (Translation::prepare_full_post_inputs() + build_system_prompt() +
     * JsonEnvelopeTranslator) so the test exercises the exact prompt
     * construction, schema, and response parsing full-page translation uses
     * in production. Content is capped to TEST_CONTENT_MAX_CHARS regardless
     * of the site's real Translation Limits setting, and the result is never
     * cached (JsonEnvelopeTranslator::translate($bypass_cache = true)).
     *
     * @param class-string $provider_class
     * @return array{success:bool,message?:string,translatedTitle?:string,outputPreview?:string}
     */
    private static function run_quality_content_test(
        string    $provider_class,
        string    $model,
        \WP_Post  $post,
        string    $source_lang,
        string    $target_lang,
        string    $language_name
    ): array {

        $prompt_template = file_get_contents(LINGUAFORGE_AI_PATH . '/templates/prompts/translation.txt'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read from plugin assets; not a remote URL.

        if ($prompt_template === false) {
            return ['success' => false, 'message' => __('Prompt template not found.', 'lingua-forge')];
        }

        $content = mb_substr((string) $post->post_content, 0, self::TEST_CONTENT_MAX_CHARS);
        $excerpt = mb_substr((string) $post->post_excerpt, 0, self::TEST_CONTENT_MAX_CHARS);

        $ctx = Translation::prepare_full_post_inputs(
            $post->post_title !== '' ? $post->post_title : '(untitled)',
            $content,
            $excerpt,
            $source_lang,
            '',
            $language_name,
            $prompt_template,
            0, // already capped above; no further trim needed
            $post->ID
        );

        $system_prompt = Translation::build_system_prompt(
            Translation::resolve_compliance_addendum(0),
            Glossary::format_for_prompt($source_lang, $target_lang)
        );

        $base_config = Config::apply_compliance(new WorkerConfig(
            model:       $model,
            max_tokens:  self::TEST_MAX_TOKENS,
            temperature: 0.2,
        ));

        // JsonEnvelopeTranslator::translate() builds its provider internally
        // via ProviderFactory::make(), which always resolves Config::provider()
        // — the currently *active* provider — regardless of which provider's
        // field is being tested here. Testing a non-active provider's model
        // would otherwise silently send that provider's model string to the
        // active provider's API. The 'linguaforge_ai_provider' filter exists
        // precisely to substitute a specific provider instance, so use it to
        // force the exact provider/model pairing under test.
        $provider_instance = new $provider_class($base_config);
        $force_provider    = static fn () => $provider_instance;
        add_filter('linguaforge_ai_provider', $force_provider);

        $translator = new JsonEnvelopeTranslator($base_config, $system_prompt);
        $result     = $translator->translate(
            $post, $post->ID, $ctx, '', '', $language_name, $target_lang, [], true
        );

        remove_filter('linguaforge_ai_provider', $force_provider);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'message' => (string) ($result['error'] ?? __('Translation failed. Please try again.', 'lingua-forge')),
            ];
        }

        $out = [
            'success'       => true,
            'outputPreview' => mb_substr(wp_strip_all_tags((string) $result['output']), 0, 300),
        ];

        if (!empty($result['translated_title'])) {
            $out['translatedTitle'] = (string) $result['translated_title'];
        }

        return $out;
    }

    /**
     * Light-tier content test — mirrors Translation::run_chunk() /
     * ChunkTranslation::run() (same system prompt, glossary injection, and
     * message shape via ChunkTranslation::build_messages()), but calls the
     * provider directly rather than through ChunkTranslation::run() so the
     * test never writes a CacheStore entry.
     *
     * @param class-string $provider_class
     * @return array{success:bool,message?:string,outputPreview?:string}
     */
    private static function run_light_content_test(
        string   $provider_class,
        string   $model,
        \WP_Post $post,
        string   $target_lang,
        string   $language_name
    ): array {

        $chunk_source = $post->post_content !== '' ? wp_strip_all_tags($post->post_content) : '';
        $chunk_text   = trim(mb_substr($chunk_source, 0, self::TEST_CHUNK_MAX_CHARS));

        if ($chunk_text === '') {
            $chunk_text = trim($post->post_title);
        }

        if ($chunk_text === '') {
            return ['success' => false, 'message' => __('The sample post has no visible text content to translate.', 'lingua-forge')];
        }

        $prompt_template = file_get_contents(LINGUAFORGE_AI_PATH . '/templates/prompts/translation_chunk.txt'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read from plugin assets; not a remote URL.

        if ($prompt_template === false) {
            return ['success' => false, 'message' => __('Chunk prompt template not found.', 'lingua-forge')];
        }

        $prompt = str_replace(
            ['{{language}}', '{{chunk_text}}'],
            [$language_name, $chunk_text],
            $prompt_template
        );

        $system_prompt = Config::apply_compliance_to_system(
            'You are a professional translator. Output only the translated text — no commentary, no preamble.'
        );

        $glossary = Glossary::format_for_prompt('', $target_lang);
        if ($glossary !== '') {
            $system_prompt .= "\n\n" . $glossary;
        }

        $messages = ChunkTranslation::build_messages($system_prompt, $prompt, false, '', '');

        $config = Config::apply_compliance(new WorkerConfig(
            model:       $model,
            max_tokens:  self::TEST_MAX_TOKENS,
            temperature: 0.2,
        ));

        $provider = new $provider_class($config);
        $reply    = $provider->chat($messages);

        if ($reply === null || $reply === '') {
            $error_detail = $provider->get_last_error();
            return [
                'success' => false,
                'message' => $error_detail !== ''
                    ? $error_detail
                    : __('Model returned no response. Check the WordPress error log for details.', 'lingua-forge'),
            ];
        }

        return [
            'success'       => true,
            'outputPreview' => mb_substr(trim($reply), 0, 300),
        ];
    }

    /**
     * Most recently published post/page/CPT — used as real content for the
     * content test. Returns null when the site has nothing published yet.
     */
    private static function pick_sample_post(): ?\WP_Post {

        $posts = get_posts([
            'post_type'           => 'any',
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        ]);

        return $posts[0] ?? null;
    }

    /**
     * Pick a target language for the content test: the first configured
     * active language (same source as the real Translate-to dropdown —
     * linguaforge_languages() intersected with Translation::get_languages())
     * other than the source, sorted for determinism. Falls back to a fixed
     * language when the site has no second active language configured (or
     * the Language Router module is unavailable) — this fallback is only
     * ever used for this diagnostic call and is never saved or exposed
     * elsewhere.
     */
    private static function pick_target_language(string $source_lang): string {

        $active     = function_exists('linguaforge_languages') ? linguaforge_languages() : [];
        $candidates = array_intersect_key(Translation::get_languages(), array_flip($active));
        unset($candidates[$source_lang]);

        if (!empty($candidates)) {
            ksort($candidates);
            return (string) array_key_first($candidates);
        }

        return $source_lang === 'es' ? 'fr' : 'es';
    }

    /**
     * Shared minimal ping used by ajax_test_provider(): ask for a single
     * word, tight token budget. (ajax_test_model() above no longer pings —
     * see its docblock for why a real content test replaced it.)
     */
    private static function ping(\LinguaForge\AI\Contracts\AIProviderInterface $provider_instance): ?string {

        return $provider_instance->chat([
            [
                'role'    => 'user',
                'content' => 'Reply with the single word: ping',
            ],
        ]);
    }
}
