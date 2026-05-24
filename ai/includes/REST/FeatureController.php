<?php

namespace LinguaForge\AI\REST;

use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\UsageRecorder;
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
    /**
     * Supported block revision types.
     *
     * IMPORTANT: instructions must produce INLINE-compatible output. The
     * block-action JS writes the result back into a single block attribute
     * (e.g. `core/paragraph`'s `content`), which only accepts inline HTML —
     * <strong>, <em>, <a>, <br>, etc. Block-level elements like <ul>, <h2>,
     * <blockquote> would break the block parser. The bulletize variant gets
     * around this by emitting `<br>`-separated lines prefixed with the
     * bullet character, which renders as a visual list inside a paragraph
     * without leaving the block model.
     *
     * Key → JS dropdown key (must match the corresponding entry in
     * block-action.js's REVISION_TYPES map).
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
        'bulletize' => [
            'label'       => 'Bulleted',
            'instruction' => 'Rewrite the text as a vertical list of concise bullet points. Each point must start with the • bullet character followed by a space. Separate points with <br> tags (no <ul> or <li> markup — the output must stay inline-compatible with a paragraph block). Preserve every factual claim from the source; reorganise wording for clarity, do not invent new content.',
        ],
        'lead_paragraph' => [
            'label'       => 'Lead paragraph',
            'instruction' => 'Rewrite as a single tight lead paragraph of at most 60 words. Capture the most important point first, omit secondary details, and write in active voice. Output a single paragraph with no line breaks or list formatting — suitable as an article intro, mobile-first summary, or excerpt preview.',
        ],
        'cite' => [
            'label'       => 'Citation prompts',
            'instruction' => 'Identify factual claims, statistics, or assertions that would benefit from a citation, and append the marker [citation needed] immediately after each such claim (inside the same sentence, before the trailing punctuation). Do not remove, reword, or reorder any existing text. Do not add markers to opinions, definitions, or background context. If no claims warrant a citation, return the text unchanged.',
        ],
        'plain_language' => [
            'label'       => 'Plain language',
            'instruction' => 'Rewrite in plain, accessible language. Replace technical jargon, legal terminology, regulatory citations expressed as prose, and bureaucratic phrasing with everyday equivalents — but preserve article numbers, percentages, dates, currencies, brand names, and unit symbols exactly. Keep approximately the same paragraph length and structure. Target a general adult reader.',
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
                'permission_callback' => function (\WP_REST_Request $request) {
                    return current_user_can(
                        self::required_capability(sanitize_key((string) $request['feature']))
                    );
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
                    return current_user_can(self::required_capability('translate-chunk'));
                },
            ]
        );

        // ── Toolbar chunk-create endpoint ────────────────────────────────────
        // Generates new free-form text from hints without requiring a post ID.
        // Used by the "Create" tab in the Admin Toolbar popover. Also handles
        // iterative refinement when `refine_hint` + `previous_output` are present.
        register_rest_route(
            'lingua-forge/v1',
            '/create-chunk',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'run_create_chunk'],
                'permission_callback' => function () {
                    return current_user_can(self::required_capability('create-chunk'));
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
                    return current_user_can(self::required_capability('revise-block'));
                },
            ]
        );
    }

    /**
     * Resolve the WP capability required to use an AI endpoint or feature.
     *
     * Source of truth (lowest priority first):
     *   wp_options['linguaforge_required_capability']   set by admin in Settings
     *   apply_filters('linguaforge_required_capability', $cap, $context)
     *
     * Defaults to 'edit_posts' (the legacy gate from before this refactor).
     * Common admin choices: 'edit_published_posts' (Authors), 'edit_others_posts'
     * (Editors), 'manage_options' (Admins only).
     *
     * @param string $context Feature key (for /feature/{feature}/{id}) or
     *                        endpoint slug ('translate-chunk', 'revise-block').
     */
    private static function required_capability(string $context): string {

        $default = (string) get_option('linguaforge_required_capability', 'edit_posts');
        if ($default === '') {
            $default = 'edit_posts';
        }

        $cap = (string) apply_filters('linguaforge_required_capability', $default, $context);

        // Final safety net — never let a misconfigured filter return an empty
        // capability (which would resolve to "anyone can" in current_user_can).
        return $cap !== '' ? $cap : 'edit_posts';
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

        // Rate-limit check runs after structural validation (so bad-request
        // calls don't burn the user's budget) but before the paid AI call.
        $rate_error = RateLimiter::enforce_rate_limit('translate-chunk');
        if ($rate_error instanceof \WP_Error) {
            return $rate_error;
        }

        // Site-wide daily ceiling — protects against the per-user limit being
        // multiplied across many users on multi-author sites.
        $quota_error = RateLimiter::enforce_daily_quota('translate-chunk');
        if ($quota_error instanceof \WP_Error) {
            return $quota_error;
        }

        return rest_ensure_response(
            $translation->run_chunk($language_name, $params)
        );
    }

    /**
     * Generate free-form text from hints via the Admin Toolbar popover.
     *
     * Accepts:
     *   - hints           (string)  What to write — key points, topic, instructions
     *   - tone            (string)  One of: informative, persuasive, storytelling, technical, conversational
     *   - target_language (string)  Optional ISO language code; omit for same language as hints
     *   - refine_hint     (string)  Optional — iterative improvement instruction
     *   - previous_output (string)  Optional — prior draft to refine (required with refine_hint)
     *
     * Returns:
     *   - success  (bool)
     *   - output   (string)  Generated text
     *   - tone     (string)  Effective tone key
     *   - language (string)  Human-readable target language, or empty string
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function run_create_chunk(\WP_REST_Request $request) {

        $params          = (array) ($request->get_json_params() ?? []);
        $hints           = mb_substr( trim( sanitize_textarea_field( wp_unslash( $params['hints'] ?? '' ) ) ), 0, 3000 );
        $tone            = sanitize_key( $params['tone'] ?? 'informative' );
        $target_language = sanitize_text_field( $params['target_language'] ?? '' );
        $refine_hint     = mb_substr( trim( sanitize_textarea_field( $params['refine_hint'] ?? '' ) ), 0, 2000 );
        $previous_output = trim( (string) ( $params['previous_output'] ?? '' ) );
        $is_refinement   = $refine_hint !== '' && $previous_output !== '';

        if ( $hints === '' && ! $is_refinement ) {
            return new \WP_Error(
                'missing_hints',
                'Hints are required.',
                ['status' => 400]
            );
        }

        $valid_tones = ['informative', 'persuasive', 'storytelling', 'technical', 'conversational'];
        if ( ! in_array( $tone, $valid_tones, true ) ) {
            $tone = 'informative';
        }

        $rate_error = RateLimiter::enforce_rate_limit('create-chunk');
        if ($rate_error instanceof \WP_Error) {
            return $rate_error;
        }

        $quota_error = RateLimiter::enforce_daily_quota('create-chunk');
        if ($quota_error instanceof \WP_Error) {
            return $quota_error;
        }

        // ── Build prompt ──────────────────────────────────────────────────────
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local prompt-template read from the plugin's own assets directory; not a remote URL.
        $prompt_tpl = file_get_contents( LINGUAFORGE_AI_PATH . '/templates/prompts/create-chunk.txt' );

        if ( $prompt_tpl === false ) {
            return new \WP_Error(
                'missing_prompt',
                'Prompt template not found.',
                ['status' => 500]
            );
        }

        $prompt = str_replace( ['{{tone}}', '{{hints}}'], [$tone, $hints], $prompt_tpl );

        // ── Optional target language ──────────────────────────────────────────
        $language_label = '';
        if ( $target_language !== '' ) {
            $languages = Translation::get_languages();
            if ( isset( $languages[$target_language] ) ) {
                $language_label = $languages[$target_language];
                $prompt        .= "\n\nWrite the content in " . $language_label . '.';
            }
        }

        // ── Provider ──────────────────────────────────────────────────────────
        $max_tokens = Config::quick_translate_max_tokens();
        if ( $max_tokens <= 0 ) {
            $max_tokens = 2000;
        }

        $provider = ProviderFactory::make( Config::apply_compliance( new WorkerConfig(
            model:       Config::model('quality'),
            max_tokens:  $max_tokens,
            temperature: 0.6,
        ) ) );

        $system_prompt = Config::apply_compliance_to_system(
            'You are an expert content writer. ' .
            'Output only the requested content — no commentary, no preamble, no meta-explanation.'
        );

        // ── Build messages (single-turn or multi-turn refinement) ─────────────
        if ( $is_refinement ) {
            $messages = [
                [ 'role' => 'system',    'content' => $system_prompt ],
                [ 'role' => 'user',      'content' => $prompt ],
                [ 'role' => 'assistant', 'content' => $previous_output ],
                [ 'role' => 'user',      'content' =>
                    "Please refine the content above based on these additional instructions:\n\n" .
                    $refine_hint ],
            ];
        } else {
            $messages = [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $prompt ],
            ];
        }

        $result = UsageRecorder::tracked( 'create-chunk', static fn() => $provider->chat( $messages ) );

        if ( empty( $result ) ) {
            return rest_ensure_response([
                'success' => false,
                'error'   => 'Content creation failed. Please try again.',
            ]);
        }

        return rest_ensure_response([
            'success'  => true,
            'output'   => trim( $result ),
            'tone'     => $tone,
            'language' => $language_label,
        ]);
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
        // Optional free-form instructions supplied by the editor alongside the
        // predefined revision type. Appended to the type's instruction string so
        // the AI honours both the structural revision goal and the extra guidance.
        $custom_instruction = sanitize_textarea_field(wp_unslash($params['custom_instruction'] ?? ''));

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
        $instruction  = $type_config['instruction'];

        // Append editor-supplied instructions when present, preserving the
        // type's structural guidance as the leading sentence.
        if ($custom_instruction !== '') {
            $instruction .= "\nAdditional instructions from the editor: " . $custom_instruction;
        }

        $prompt_path  = LINGUAFORGE_AI_PATH . '/templates/prompts/block-revision.txt';

        if (!file_exists($prompt_path)) {
            return new \WP_Error(
                'missing_prompt',
                'Prompt template not found.',
                ['status' => 500]
            );
        }

        // Rate-limit check runs after structural validation (so bad-request
        // calls don't burn the user's budget) but before the paid AI call.
        $rate_error = RateLimiter::enforce_rate_limit('revise-block');
        if ($rate_error instanceof \WP_Error) {
            return $rate_error;
        }

        // Site-wide daily ceiling — protects against the per-user limit being
        // multiplied across many users on multi-author sites.
        $quota_error = RateLimiter::enforce_daily_quota('revise-block');
        if ($quota_error instanceof \WP_Error) {
            return $quota_error;
        }

        $prompt = str_replace(
            ['{{instruction}}', '{{content}}'],
            [$instruction, mb_substr(trim($chunk_text), 0, 8000)],
            file_get_contents($prompt_path)
        );

        $provider = ProviderFactory::make(Config::apply_compliance(
            new WorkerConfig(
                model:       Config::model('quality'),
                max_tokens:  2048,
                temperature: 0.4,
            )
        ));

        // Block revisions ship without a base system prompt, so when compliance
        // mode is on we prepend a system message whose entire content IS the
        // strict-preservation addendum. Otherwise we keep the original
        // user-only message shape to avoid surprising the model.
        $messages = [];
        if (Config::active_preset() !== 'standard') {
            $messages[] = [
                'role'    => 'system',
                'content' => Config::apply_compliance_to_system(''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $result = UsageRecorder::tracked( 'block-revision', static fn() => $provider->chat($messages) );

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

    // ─────────────────────────────────────────────────────────────────────────
    // RATE LIMITING & QUOTA
    //
    // The translate-chunk, create-chunk, and revise-block endpoints accept
    // arbitrary text and make paid AI calls. Two layered defenses protect
    // the API budget — both live on {@see RateLimiter} so the same gates
    // can be reused by the FSE-translate AJAX handlers in RouterTab:
    //
    //   1. Per-user sliding 60s window — RateLimiter::enforce_rate_limit()
    //         Default 30 req/min per user per endpoint. Filterable via
    //         apply_filters('linguaforge_ai_rate_limit', ...).
    //
    //   2. Per-site rolling daily ceiling — RateLimiter::enforce_daily_quota()
    //         Configured in Settings → AI Limits & Security. 0 = unlimited.
    //         Filterable via apply_filters('linguaforge_ai_daily_quota', ...).
    //
    // Both fire before the AI call. Rate limit returns 429 with retry_after;
    // the daily ceiling returns 429 with the human-readable date when the
    // counter resets. REST callers return the WP_Error directly; AJAX
    // callers use RateLimiter::gate_ajax_or_die() to convert + exit.
    // ─────────────────────────────────────────────────────────────────────────

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
