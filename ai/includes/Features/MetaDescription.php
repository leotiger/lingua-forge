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

class MetaDescription implements FeatureInterface {

    public function get_key(): string {

        return 'meta-description';
    }

    public function get_label(): string {

        return __( 'Generate Meta Description', 'lingua-forge' );
    }

    /**
     * Haiku is sufficient for short, structured outputs like meta
     * descriptions — fast and cost-effective.
     */
    public function get_worker_config(): WorkerConfig {

        return Config::apply_compliance(new WorkerConfig(
            model:       Config::model('light'),
            max_tokens:  384,
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

    public function run(int $post_id, array $params = []): array {

        $post = get_post($post_id);

        if (!$post) {

            return ['success' => false];
        }

        $content = wp_strip_all_tags($post->post_content);

        $locale = get_post_meta($post_id, '_lang', true)
            ?: determine_locale();

        // Convert WordPress locale (e.g. 'it_IT') or short code (e.g. 'it')
        // to a human-readable name the model can reliably act on.
        $lang_code = strtolower(explode('_', $locale)[0]);
        $language  = Translation::get_languages()[$lang_code] ?? $locale;

        // ── Cache check ───────────────────────────────────────────────────────
        $hash   = CacheStore::hash([$post->post_content, $post->post_title, $locale]);
        $cached = empty($params['force_refresh'])
            ? CacheStore::get($post_id, $this->get_key(), $hash)
            : null;

        if ($cached !== null) {
            return array_merge(['success' => true, 'cached' => true], $cached);
        }

        // ── API call ──────────────────────────────────────────────────────────
        $prompt = file_get_contents(
            LINGUAFORGE_AI_PATH .
            '/templates/prompts/meta-description.txt'
        );

        if ($prompt === false) {
            return [
                'success' => false,
                'error'   => 'Prompt template not found.',
            ];
        }

        $prompt = str_replace(
            ['{{language}}', '{{title}}', '{{content}}'],
            [
                $language,
                $post->post_title,
                mb_substr($content, 0, 5000),
            ],
            $prompt
        );

        $provider = ProviderFactory::make(
            $this->get_worker_config()
        );

        $result = UsageRecorder::tracked( 'meta-description', static fn() => $provider->chat([
            ['role' => 'system', 'content' => Config::apply_compliance_to_system('You are an SEO copywriter.')],
            ['role' => 'user',   'content' => $prompt],
        ]) );

        if ($result === null || $result === '') {
            return [
                'success' => false,
                'error'   => 'No response from AI provider. Check your API key and try again.',
            ];
        }

        $result = self::clean_output($result);

        $payload = ['output' => $result, 'type' => 'text'];

        CacheStore::set($post_id, $this->get_key(), $hash, $payload);

        return array_merge(['success' => true], $payload);
    }

    // ── Output cleaning ───────────────────────────────────────────────────────

    /**
     * Strip model formatting artifacts from the raw output so that only
     * the bare meta description text reaches the UI.
     *
     * Handles:
     *  - Surrounding single or double quotes
     *  - "Meta description:" prefix (any capitalisation)
     *  - **bold** markdown wrappers
     *  - Internal newlines / excess whitespace collapsed to one space
     */
    private static function clean_output(string $text): string {

        $text = trim($text);

        // Strip surrounding quotes (single or double, may wrap the whole string).
        $text = preg_replace('/^["\'](.+)["\']$/s', '$1', $text) ?? $text;

        // If the model included an analysis section separated by --- or ***,
        // keep only what comes before the first separator.
        $text = preg_split('/^-{3,}|\*{3,}/m', $text)[0] ?? $text;

        // Strip markdown headings (lines starting with one or more #).
        $text = preg_replace('/^#{1,6}[^\n]*\n?/m', '', $text) ?? $text;

        // Strip "Meta description[:]" and similar labels (any capitalisation).
        $text = preg_replace('/^\s*meta\s+description\s*[:\-–]?\s*/i', '', $text) ?? $text;

        // Strip **bold** markdown wrappers the model sometimes adds.
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;

        // Collapse internal newlines / excess whitespace to a single space.
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
