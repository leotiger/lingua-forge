<?php

namespace LinguaForge\AI\REST;

use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\ProviderFactory;
use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

class FeatureController {

    /**
     * Supported block revision types.
     * Key   → sent by JS as revision_type param.
     * label → human-readable label returned in the response.
     * instruction → injected into the prompt template.
     */
    private const REVISION_TYPES = [
        'improve' => [
            'label'       => 'Improved',
            'instruction' => 'Improve the writing quality: fix grammar errors, enhance clarity, improve sentence flow, and polish the style. Preserve the original meaning and tone.',
        ],
        'formal'  => [
            'label'       => 'Made Formal',
            'instruction' => 'Rewrite in a formal, professional register. Preserve the meaning but use more formal vocabulary and sentence structure.',
        ],
        'casual'  => [
            'label'       => 'Made Casual',
            'instruction' => 'Rewrite in a casual, conversational style. Keep the meaning but make it warmer and more approachable.',
        ],
        'concise' => [
            'label'       => 'Made Concise',
            'instruction' => 'Make the text more concise. Remove redundancy and wordiness while keeping all key information intact.',
        ],
        'expand'  => [
            'label'       => 'Expanded',
            'instruction' => 'Expand the text with more detail and elaboration. Develop the ideas further while maintaining the original meaning and direction.',
        ],
    ];

    public static function init(): void {

        add_action(
            'rest_api_init',
            [self::class, 'register_routes']
        );

        // Strip any <br> tags that survive — or are re-introduced by
        // wpautop (which some plugins/themes apply to REST responses via
        // the_content filter).  Priority 999 ensures this runs after every
        // other filter, including wpautop, so it is truly the last step
        // before the response is echoed to the browser.
        add_filter(
            'rest_pre_echo_response',
            [self::class, 'strip_br_from_output'],
            999,
            3
        );
    }

    public static function register_routes(): void {

        register_rest_route(
            'lingua-forge/v1',
            '/feature/(?P<feature>[a-z0-9\-]+)/(?P<id>\d+)',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'run'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]
        );

        // ── Toolbar chunk-translation endpoint ────────────────────────────────
        // Translates a free-form text snippet without requiring a post ID.
        // Used by the Admin Toolbar translate popover — completely independent
        // from the editor meta box translation feature.
        register_rest_route(
            'lingua-forge/v1',
            '/translate-chunk',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'run_translate_chunk'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]
        );

        // ── Block revise endpoint ─────────────────────────────────────────────
        // Revises a single block's HTML content (improve, formal, casual,
        // concise, expand) without requiring a post ID.
        // Used by the block toolbar Translate / Revise popover.
        register_rest_route(
            'lingua-forge/v1',
            '/revise-block',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'run_revise_block'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]
        );
    }

    public static function run(
        \WP_REST_Request $request
    ) {
        $feature_key = sanitize_key($request['feature']);
        $post_id     = (int) $request['id'];

        // Collect extra parameters sent as a JSON body.
        $params = (array) ($request->get_json_params() ?? []);

        $feature = Registry::get($feature_key);

        if (!$feature) {

            return new \WP_Error(
                'invalid_feature',
                'Unknown feature.',
                ['status' => 404]
            );
        }

        if (!$feature->supports($post_id)) {

            return new \WP_Error(
                'forbidden',
                'Access denied.',
                ['status' => 403]
            );
        }

        return rest_ensure_response(
            $feature->run($post_id, $params)
        );
    }

    /**
     * Translate a free-form text chunk via the Admin Toolbar popover.
     *
     * Accepts:
     *   - target_language  (string)  ISO language code, e.g. "es"
     *   - chunk_text       (string)  The text to translate
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function run_translate_chunk(\WP_REST_Request $request) {

        $params          = (array) ($request->get_json_params() ?? []);
        $target_language = sanitize_text_field($params['target_language'] ?? 'en');
        $languages       = Translation::get_languages();

        if (!array_key_exists($target_language, $languages)) {
            return new \WP_Error(
                'invalid_language',
                'Invalid target language.',
                ['status' => 400]
            );
        }

        $language_name = $languages[$target_language];

        /** @var Translation $translation */
        $translation = Registry::get('translation');

        if (!$translation) {
            return new \WP_Error(
                'feature_unavailable',
                'Translation feature is not registered.',
                ['status' => 500]
            );
        }

        return rest_ensure_response(
            $translation->run_chunk($language_name, $params)
        );
    }

    /**
     * Revise a single block's HTML content via the block-toolbar popover.
     *
     * Accepts:
     *   - revision_type  (string)  One of the REVISION_TYPES keys (e.g. "improve")
     *   - chunk_text     (string)  The block's HTML content to revise
     *
     * Returns:
     *   - success        (bool)
     *   - output         (string)  Revised HTML content
     *   - revision_label (string)  Human-readable label (e.g. "Improved")
     *   - revision_type  (string)  Echo of the requested type
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function run_revise_block(\WP_REST_Request $request) {

        $params        = (array) ($request->get_json_params() ?? []);
        $revision_type = sanitize_key($params['revision_type'] ?? 'improve');
        // wp_kses_post preserves safe inline HTML (strong, em, a, br, code, …)
        // so the AI can see and honour the markup.  sanitize_textarea_field would
        // strip all tags — destroying the block's HTML structure.
        $chunk_text    = wp_kses_post($params['chunk_text'] ?? '');

        if (!array_key_exists($revision_type, self::REVISION_TYPES)) {
            return new \WP_Error(
                'invalid_revision_type',
                'Invalid revision type.',
                ['status' => 400]
            );
        }

        if (trim($chunk_text) === '') {
            return new \WP_Error(
                'missing_content',
                'Content is required.',
                ['status' => 400]
            );
        }

        $type_config  = self::REVISION_TYPES[$revision_type];
        $prompt_path  = LINGUAFORGE_AI_PATH . '/templates/prompts/block-revision.txt';

        if (!file_exists($prompt_path)) {
            return new \WP_Error(
                'missing_prompt',
                'Prompt template not found.',
                ['status' => 500]
            );
        }

        $prompt = str_replace(
            ['{{instruction}}', '{{content}}'],
            [$type_config['instruction'], mb_substr(trim($chunk_text), 0, 8000)],
            file_get_contents($prompt_path)
        );

        $provider = ProviderFactory::make(
            new WorkerConfig(
                model:       Config::model('quality'),
                max_tokens:  2048,
                temperature: 0.4,
            )
        );

        $result = $provider->chat(
            [['role' => 'user', 'content' => $prompt]]
        );

        if (empty($result)) {
            return new \WP_Error(
                'revision_failed',
                'Revision failed. Please try again.',
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success'        => true,
            'output'         => trim($result),
            'revision_label' => $type_config['label'],
            'revision_type'  => $revision_type,
        ]);
    }

    /**
     * Remove <br> tags that wpautop injected between Gutenberg block
     * boundaries in the 'output' field of our feature REST responses.
     *
     * Running at priority 999 guarantees this executes after wpautop
     * (priority 10) and any other plugin that hooks into rest_pre_echo_response
     * to apply the_content filters.  wpautop converts newlines between block
     * comment delimiters to <br /> tags, breaking the Gutenberg block parser.
     *
     * Uses BlockTextExtractor::strip_interblock_br() so that only inter-block
     * <br> tags are removed.  Legitimate soft line breaks (Shift+Enter) inside
     * block HTML — e.g. <p>Line one<br>Line two</p> — are left untouched.
     *
     * @param mixed            $result  Raw response data (array or scalar).
     * @param \WP_REST_Server  $server  REST server instance (unused).
     * @param \WP_REST_Request $request Current request.
     * @return mixed
     */
    public static function strip_br_from_output($result, $server, $request) {

        if (!is_array($result)) {
            return $result;
        }

        // Target only our feature endpoint so we do not touch other routes.
        if (strpos($request->get_route(), '/lingua-forge/v1/feature/') === false) {
            return $result;
        }

        if (isset($result['output']) && is_string($result['output'])) {
            $result['output'] = BlockTextExtractor::strip_interblock_br($result['output']);
        }

        return $result;
    }
}
