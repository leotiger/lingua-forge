<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Contracts\FeatureInterface;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\UsageRecorder;

defined('ABSPATH') || exit;

/**
 * Generates or rewrites post content using AI.
 *
 * Supports multiple content types (full article, introduction, outline)
 * and tones (informative, persuasive, storytelling, technical).
 * Returns a 'content' type result so the existing JS renderer handles
 * the "Apply to Editor" / "Copy" flow out of the box.
 */
class ContentGenerator implements FeatureInterface {

    /**
     * Available writing tones.
     * Defined as a method (not a constant) so values can be wrapped with __().
     *
     * @return array<string, string>
     */
    private static function tones(): array {

        return [
            'informative'    => __( 'Informative',    'lingua-forge' ),
            'persuasive'     => __( 'Persuasive',     'lingua-forge' ),
            'storytelling'   => __( 'Storytelling',   'lingua-forge' ),
            'technical'      => __( 'Technical',      'lingua-forge' ),
            'conversational' => __( 'Conversational', 'lingua-forge' ),
        ];
    }

    /**
     * Output types the model can produce.
     * Defined as a method (not a constant) so values can be wrapped with __().
     *
     * @return array<string, string>
     */
    private static function content_types(): array {

        return [
            'full_article' => __( 'Full Article',      'lingua-forge' ),
            'introduction' => __( 'Introduction only', 'lingua-forge' ),
            'outline'      => __( 'Structured Outline', 'lingua-forge' ),
        ];
    }

    public function get_key(): string {

        return 'content-generator';
    }

    public function get_label(): string {

        return __( 'Generate Content', 'lingua-forge' );
    }

    /**
     * Sonnet for quality long-form generation.
     *
     * max_tokens is read from Settings → Lingua Forge AI → Content Generator.
     * Raise it there if generated articles are being cut off.
     */
    public function get_worker_config(int $post_id = 0): WorkerConfig {

        return Config::apply_compliance(new WorkerConfig(
            model:       Config::model('quality'),
            max_tokens:  Config::content_generator_max_tokens(),
            temperature: 0.6,
        ), $post_id);
    }

    public function get_ui_fields(): array {

        return [
            [
                'name'        => 'hints',
                'type'        => 'textarea',
                'label'       => __( 'Hints', 'lingua-forge' ),
                'placeholder' => __( 'Key points, ideas, or structure to build from…', 'lingua-forge' ),
            ],
            [
                'name'    => 'tone',
                'type'    => 'select',
                'label'   => __( 'Tone', 'lingua-forge' ),
                'options' => self::tones(),
            ],
            [
                'name'    => 'content_type',
                'type'    => 'select',
                'label'   => __( 'Output', 'lingua-forge' ),
                'options' => self::content_types(),
            ],
        ];
    }

    public function get_field_defaults(int $post_id): array {

        return [
            'hints'        => '',
            'tone'         => 'informative',
            'content_type' => 'full_article',
        ];
    }

    public function supports(int $post_id): bool {

        return current_user_can('edit_post', $post_id);
    }

    public function run(int $post_id, array $params = []): array {

        $post = get_post($post_id);

        if (!$post) {
            return [
                'success' => false,
                'error'   => 'Post not found.',
            ];
        }

        // ── Validate params ───────────────────────────────────────────────────
        $tone = sanitize_key($params['tone'] ?? 'informative');
        if (!array_key_exists($tone, self::tones())) {
            $tone = 'informative';
        }

        $content_type = sanitize_key($params['content_type'] ?? 'full_article');
        if (!array_key_exists($content_type, self::content_types())) {
            $content_type = 'full_article';
        }

        $tone_label         = self::tones()[$tone];
        $content_type_label = self::content_types()[$content_type];

        // ── Hints ─────────────────────────────────────────────────────────────
        // When hints are provided they take priority over the post body.
        // If no hints, fall back to existing post content as context.
        $hints = mb_substr(trim(sanitize_textarea_field($params['hints'] ?? '')), 0, Config::content_generator_max_hints_chars());

        // ── Cache check ───────────────────────────────────────────────────────
        // Cache is keyed per tone + content_type combination.
        $cache_key = $this->get_key() . '_' . $tone . '_' . $content_type;
        $hash      = CacheStore::hash([
            $post->post_title,
            $post->post_content,
            $tone,
            $content_type,
            $hints,
        ]);

        $cached = empty($params['force_refresh'])
            ? CacheStore::get($post_id, $cache_key, $hash)
            : null;

        if ($cached !== null) {
            return array_merge(['success' => true, 'cached' => true], $cached);
        }

        // ── Build prompt ──────────────────────────────────────────────────────
        $prompt_tpl = file_get_contents(
            LINGUAFORGE_AI_PATH . '/templates/prompts/content-generator.txt'
        );

        if ($prompt_tpl === false) {
            return [
                'success' => false,
                'error'   => 'Prompt template not found.',
            ];
        }

        // Use hints as the seed when provided; otherwise fall back to the
        // existing post body so the model can rewrite / extend it.
        if ($hints !== '') {
            $seed_section = "\n\nHints and key points to build from:\n" . $hints;
        } else {
            $existing_content = trim(wp_strip_all_tags($post->post_content));
            $seed_section     = $existing_content !== ''
                ? "\n\nExisting content (use as context or rewrite as needed):\n" .
                  mb_substr($existing_content, 0, Config::content_generator_max_context_chars())
                : '';
        }

        $prompt = str_replace(
            ['{{title}}', '{{tone}}', '{{content_type}}', '{{existing_content}}'],
            [
                $post->post_title,
                $tone_label,
                $content_type_label,
                $seed_section,
            ],
            $prompt_tpl
        );

        // ── API call ──────────────────────────────────────────────────────────
        $provider = ProviderFactory::make($this->get_worker_config($post_id));

        $system_prompt = Config::apply_compliance_to_system(
            'You are an expert WordPress content writer. ' .
            'Output clean WordPress block-editor (Gutenberg) markup. ' .
            'Use <!-- wp:paragraph -->, <!-- wp:heading -->, ' .
            '<!-- wp:list --> and similar block comments where appropriate. ' .
            'Do not include front-matter, meta-commentary, or explanations — ' .
            'output only the post body markup.',
            $post_id
        );

        $result = UsageRecorder::tracked( 'content-generator', static fn() => $provider->chat([
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $prompt],
        ]) );

        if (empty($result)) {
            return [
                'success' => false,
                'error'   => 'Content generation failed. Please try again.',
            ];
        }

        // Strip <br> tags the model hallucinated between Gutenberg block
        // boundaries.  Uses the targeted inter-block strip so that any
        // intentional soft line breaks inside <p> or similar elements are
        // preserved rather than silently removed.
        $output = BlockTextExtractor::strip_interblock_br(trim($result));

        $payload = [
            'output'       => $output,
            'type'         => 'content',
            'tone'         => $tone_label,
            'content_type' => $content_type_label,
        ];

        CacheStore::set($post_id, $cache_key, $hash, $payload);

        return array_merge(['success' => true], $payload);
    }
}
