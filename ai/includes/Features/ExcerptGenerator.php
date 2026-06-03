<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Contracts\FeatureInterface;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Features\Translation;

defined('ABSPATH') || exit;

class ExcerptGenerator implements FeatureInterface {

    public function get_key(): string {

        return 'excerpt';
    }

    public function get_label(): string {

        return __( 'Generate Excerpt', 'lingua-forge' );
    }

    /**
     * Haiku handles short editorial excerpts well; no need for
     * a heavier model.
     */
    public function get_worker_config(): WorkerConfig {

        return Config::apply_compliance(new WorkerConfig(
            model:       Config::model('light'),
            max_tokens:  512,
            temperature: 0.4,
        ));
    }

    public function get_ui_fields(): array {

        return [];
    }

    public function get_field_defaults(int $post_id): array {

        return [];
    }

    public function supports(int $post_id): bool {

        return current_user_can('edit_post', $post_id);
    }

    /**
     * Extracts the two-letter language code from a WordPress locale string.
     *
     * Examples: 'en_US' → 'en', 'zh_TW' → 'zh', 'de' → 'de'.
     *
     * @internal Public for unit tests.
     */
    public static function locale_to_lang_code( string $locale ): string {

        return strtolower( explode( '_', $locale )[0] );
    }

    public function run(int $post_id, array $params = []): array {

        $post = get_post($post_id);

        if (!$post) {

            return ['success' => false];
        }

        $content = wp_strip_all_tags($post->post_content);

        $locale = get_post_meta($post_id, '_lf_lang', true)
            ?: determine_locale();

        // Convert WordPress locale (e.g. 'it_IT') or short code (e.g. 'it')
        // to a human-readable name the model can reliably act on.
        $lang_code = self::locale_to_lang_code( $locale );
        $language  = Translation::get_languages()[$lang_code] ?? $locale;

        // ── Cache check ───────────────────────────────────────────────────────
        $hash   = CacheStore::hash([$post->post_title, $post->post_content, $locale, Config::provider(), Config::model('light')]);
        $cached = empty($params['force_refresh'])
            ? CacheStore::get($post_id, $this->get_key(), $hash)
            : null;

        if ($cached !== null) {
            return array_merge(['success' => true, 'cached' => true], $cached);
        }

        // ── API call ──────────────────────────────────────────────────────────
        $provider = ProviderFactory::make(
            $this->get_worker_config()
        );

        $result = UsageRecorder::tracked( 'excerpt', static fn() => $provider->chat([
            [
                'role'    => 'system',
                'content' => Config::apply_compliance_to_system('You write concise editorial excerpts.'),
            ],
            [
                'role'    => 'user',
                'content' =>
                    'Write a compelling excerpt in ' .
                    $language .
                    '. Maximum 240 characters.' .
                    "\n\n" .
                    $content,
            ],
        ]) );

        if ($result === null || $result === '') {
            return [
                'success' => false,
                'error'   => 'No response from AI provider. Check your API key and try again.',
            ];
        }

        $payload = ['output' => $result, 'type' => 'text'];

        CacheStore::set($post_id, $this->get_key(), $hash, $payload);

        return array_merge(['success' => true], $payload);
    }
}
