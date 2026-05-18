<?php

namespace LinguaForge\AI\Core;

use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

class Config {

    // ── Preset addendum defaults ───────────────────────────────────────────────

    /** System-prompt addendum for the Legal / Compliance preset. */
    public const LEGAL_ADDENDUM_DEFAULT =
        "Strict-preservation mode is active. Apply these rules to every output:\n"
        . "- Preserve all technical terms, regulatory citations, article numbers, percentages, currencies, dates, and unit symbols exactly as they appear.\n"
        . "- Do not paraphrase legal or regulatory language. Match the source register precisely.\n"
        . "- Preserve brand names, product names, and proper nouns verbatim.\n"
        . "- Flag any term where the target language has no direct equivalent rather than guessing.";

    /** @deprecated Use LEGAL_ADDENDUM_DEFAULT. Kept for backwards compatibility. */
    public const COMPLIANCE_ADDENDUM_DEFAULT = self::LEGAL_ADDENDUM_DEFAULT;

    /** System-prompt addendum for the Technical preset. */
    public const TECHNICAL_ADDENDUM_DEFAULT =
        "Technical-precision mode is active. Apply these rules to every output:\n"
        . "- Preserve all technical terms, units of measurement, abbreviations, chemical names, model numbers, and specifications exactly as they appear.\n"
        . "- Do not paraphrase technical descriptions or substitute synonyms for established terminology.\n"
        . "- Maintain the source document's register and structural formatting.\n"
        . "- Preserve brand names, product names, and proper nouns verbatim.";

    /** System-prompt addendum for the Creative preset. */
    public const CREATIVE_ADDENDUM_DEFAULT =
        "Creative mode is active. Prioritise natural, expressive, and engaging output:\n"
        . "- Adapt idioms and cultural references so they land naturally in the target language rather than translating them literally.\n"
        . "- Favour vivid, varied vocabulary and sentence rhythm that matches the source's energy.\n"
        . "- Preserve the author's voice and tone — conversational, lyrical, or playful as the source demands.";

    /**
     * Named presets — the authoritative list of available AI behaviour modes.
     *
     * Each preset defines:
     *   temperature  (float|null) — null means "use the feature's own default".
     *   addendum     (string)     — appended to the system prompt; '' means none.
     *
     * The global "Custom addendum" field in Settings overrides the preset's
     * default addendum when non-empty, giving admins fine-grained control
     * without having to touch the preset definitions here.
     */
    public static function presets(): array {

        return [
            'standard' => [
                'label'       => __( 'Standard',            'lingua-forge' ),
                'temperature' => null,
                'addendum'    => '',
            ],
            'technical' => [
                'label'       => __( 'Technical / Scientific', 'lingua-forge' ),
                'temperature' => 0.2,
                'addendum'    => self::TECHNICAL_ADDENDUM_DEFAULT,
            ],
            'legal' => [
                'label'       => __( 'Legal / Compliance',  'lingua-forge' ),
                'temperature' => 0.1,
                'addendum'    => self::LEGAL_ADDENDUM_DEFAULT,
            ],
            'creative' => [
                'label'       => __( 'Creative / Marketing', 'lingua-forge' ),
                'temperature' => 0.7,
                'addendum'    => self::CREATIVE_ADDENDUM_DEFAULT,
            ],
        ];
    }

    private const OPT_PROVIDER = 'linguaforge_provider';

    /**
     * Default model strings per provider and tier.
     *
     * Two tiers:
     *   light   — fast / cost-effective model; used by Meta Description and
     *             Excerpt Generator where short structured output is sufficient.
     *   quality — higher-capability model; used by Translation and Content
     *             Generator where accuracy and longer context matter.
     *
     * These are the fallback values when no override has been saved via the
     * Settings page.  To update a model site-wide, go to Settings → Lingua Forge AI
     * and enter the new model string in the Models section.
     *
     * @var array<string, array<string, string>>
     */
    private const MODEL_DEFAULTS = [
        'anthropic' => [
            'light'   => 'claude-haiku-4-5-20251001',
            'quality' => 'claude-sonnet-4-6',
        ],
        'openai' => [
            'light'   => 'gpt-4o-mini',
            'quality' => 'gpt-4o',
        ],
        'gemini' => [
            'light'   => 'gemini-2.0-flash',
            'quality' => 'gemini-1.5-pro',
        ],
    ];

    // ── Provider ──────────────────────────────────────────────────────────────

    /**
     * Return the active provider slug.
     *
     * Resolution order:
     *   1. Value stored in wp_options (set via Settings page)
     *   2. LINGUAFORGE_PROVIDER constant (wp-config.php)
     *   3. Default: 'anthropic'
     */
    public static function provider(): string {

        $stored = (string) get_option(self::OPT_PROVIDER, '');

        if ($stored !== '') {
            return $stored;
        }

        if (defined('LINGUAFORGE_PROVIDER')) {
            return (string) LINGUAFORGE_PROVIDER;
        }

        return 'anthropic';
    }

    // ── Model ─────────────────────────────────────────────────────────────────

    /**
     * Return the model string to use for a given tier and the active provider.
     *
     * Resolution order:
     *   1. Value stored in wp_options as linguaforge_model_{provider}_{tier}
     *      (set via Settings → Lingua Forge AI → Models)
     *   2. Hard-coded default from MODEL_DEFAULTS above
     *
     * Clearing the stored value (empty string) falls back to the default,
     * so "reset to default" is achieved by leaving the settings field blank.
     *
     * @param string $tier  'light' or 'quality'
     */
    public static function model(string $tier): string {

        $provider   = self::provider();
        $option_key = "linguaforge_model_{$provider}_{$tier}";
        $stored     = (string) get_option($option_key, '');

        if ($stored !== '') {
            return $stored;
        }

        return self::MODEL_DEFAULTS[$provider][$tier]
            ?? self::MODEL_DEFAULTS['anthropic'][$tier]
            ?? self::MODEL_DEFAULTS['anthropic']['light'];
    }

    /**
     * Return the hard-coded default model for a given provider and tier.
     *
     * Used by the Settings page to display placeholder text in model fields.
     *
     * @param string $provider  Provider slug ('anthropic', 'openai', 'gemini').
     * @param string $tier      'light' or 'quality'.
     */
    public static function default_model(string $provider, string $tier): string {

        return self::MODEL_DEFAULTS[$provider][$tier] ?? '';
    }

    /**
     * Expose all provider slugs with their default model tiers.
     *
     * Consumed by the Settings page to render the Models table.
     *
     * @return array<string, array<string, string>>
     */
    public static function all_model_defaults(): array {

        return self::MODEL_DEFAULTS;
    }

    // ── Translation limits ────────────────────────────────────────────────────

    /**
     * Maximum output tokens for the translation worker.
     *
     * Resolution order:
     *   1. linguaforge_translation_max_tokens stored in wp_options
     *   2. Hard-coded default (16 000)
     *
     * 16 000 tokens comfortably covers full-page translations that can produce
     * 5 000–8 000 tokens of output, with headroom for pages that are
     * significantly larger.
     */
    public static function translation_max_tokens(): int {

        $stored = (int) get_option('linguaforge_translation_max_tokens', 0);

        return $stored > 0 ? $stored : 16000;
    }

    /**
     * Maximum number of input characters sent to the AI for translation.
     *
     * Resolution order:
     *   1. linguaforge_translation_max_input_chars stored in wp_options
     *   2. Hard-coded default: 0 (no limit)
     *
     * 0 means the full post content is forwarded.  Set a non-zero value to
     * cap the input when working with very large pages or a provider that has
     * a tight context window.
     */
    public static function translation_max_input_chars(): int {

        return (int) get_option('linguaforge_translation_max_input_chars', 0);
    }

    // ── Content Generator limits ──────────────────────────────────────────────

    /**
     * Maximum output tokens for the Content Generator worker.
     *
     * Resolution order:
     *   1. linguaforge_content_generator_max_tokens stored in wp_options
     *   2. Hard-coded default: 8 192
     *
     * Full articles can be long — raise this if generated content is cut off.
     */
    public static function content_generator_max_tokens(): int {

        $stored = (int) get_option('linguaforge_content_generator_max_tokens', 0);

        return $stored > 0 ? $stored : 8192;
    }

    /**
     * Maximum characters accepted from the Hints field.
     *
     * Resolution order:
     *   1. linguaforge_content_generator_max_hints_chars stored in wp_options
     *   2. Hard-coded default: 2 000
     */
    public static function content_generator_max_hints_chars(): int {

        $stored = (int) get_option('linguaforge_content_generator_max_hints_chars', 0);

        return $stored > 0 ? $stored : 2000;
    }

    /**
     * Maximum characters of existing post content used as generation seed.
     *
     * Resolution order:
     *   1. linguaforge_content_generator_max_context_chars stored in wp_options
     *   2. Hard-coded default: 6 000
     *
     * Only used when no Hints are provided — the existing post body is passed
     * as context so the model can rewrite or extend it.
     */
    public static function content_generator_max_context_chars(): int {

        $stored = (int) get_option('linguaforge_content_generator_max_context_chars', 0);

        return $stored > 0 ? $stored : 6000;
    }

    // ── Translation (full-page) tier ──────────────────────────────────────────

    /**
     * Model tier used by the full-page Translation worker.
     *
     * Resolution order:
     *   1. linguaforge_translation_tier stored in wp_options ('light'|'quality')
     *   2. Hard-coded default: 'quality'
     *
     * Full-page translation uses the Quality model by default (Sonnet / GPT-4o /
     * Gemini Pro) for accurate, long-form output. Administrators can switch to
     * Light in Settings → Lingua Forge AI → Translation Limits if speed or cost
     * is the priority and the content is short.
     */
    public static function translation_tier(): string {

        $stored = (string) get_option('linguaforge_translation_tier', '');

        return ($stored === 'light') ? 'light' : 'quality';
    }

    // ── Quick Translation limits ───────────────────────────────────────────────

    /**
     * Model tier used by the Quick Translation (chunk) worker.
     *
     * Resolution order:
     *   1. linguaforge_quick_translate_tier stored in wp_options ('light'|'quality')
     *   2. Hard-coded default: 'light'
     *
     * Quick translations are short snippets — the Light model (Haiku/Flash)
     * is fast, cost-effective, and more than capable for sentence-level work.
     */
    public static function quick_translate_tier(): string {

        $stored = (string) get_option('linguaforge_quick_translate_tier', '');

        return ($stored === 'quality') ? 'quality' : 'light';
    }

    /**
     * Maximum output tokens for the Quick Translation (chunk) worker.
     *
     * Resolution order:
     *   1. linguaforge_quick_translate_max_tokens stored in wp_options
     *   2. Hard-coded default: 2 000
     *
     * Short snippets rarely produce more than a few hundred tokens of output —
     * 2 000 gives comfortable headroom without over-reserving capacity.
     */
    public static function quick_translate_max_tokens(): int {

        $stored = (int) get_option('linguaforge_quick_translate_max_tokens', 0);

        return $stored > 0 ? $stored : 2000;
    }

    /**
     * Maximum number of input characters accepted by Quick Translation.
     *
     * Resolution order:
     *   1. linguaforge_quick_translate_max_input_chars stored in wp_options
     *   2. Hard-coded default: 8 000
     */
    public static function quick_translate_max_input_chars(): int {

        $stored = (int) get_option('linguaforge_quick_translate_max_input_chars', 0);

        return $stored > 0 ? $stored : 8000;
    }

    // ── Preset resolution ─────────────────────────────────────────────────────

    /**
     * Resolve the active preset for a given context.
     *
     * Resolution order:
     *   1. Per-page meta `_linguaforge_preset` — only checked when $post_id > 0.
     *      Only Translation and ContentGenerator pass a post_id; block-level
     *      endpoints (translate-chunk, revise-block), MetaDescription, and
     *      ExcerptGenerator all pass 0 and stay global.
     *   2. Global setting `linguaforge_active_preset`.
     *   3. Backwards-compat: old `linguaforge_compliance_mode_enabled` flag → 'legal'.
     *   4. Default: 'standard'.
     */
    public static function active_preset(int $post_id = 0): string {

        $valid = array_keys(self::presets());

        if ($post_id > 0) {
            $page = (string) get_post_meta($post_id, '_linguaforge_preset', true);
            if ($page !== '' && in_array($page, $valid, true)) {
                return $page;
            }
        }

        $global = (string) get_option('linguaforge_active_preset', '');
        if (in_array($global, $valid, true)) {
            return $global;
        }

        // Backwards compat: migrate sites still running the old boolean toggle.
        if (get_option('linguaforge_compliance_mode_enabled', false)) {
            return 'legal';
        }

        return 'standard';
    }

    /**
     * Return a WorkerConfig with the preset's temperature applied.
     * When the active preset is 'standard' (or has no temperature override)
     * the original config is returned unchanged.
     *
     * @param  WorkerConfig $config   The feature's base config.
     * @param  int          $post_id  Pass the post ID to allow per-page override;
     *                                0 (default) uses the global preset only.
     */
    public static function apply_compliance(WorkerConfig $config, int $post_id = 0): WorkerConfig {

        $presets = self::presets();
        $preset  = self::active_preset($post_id);
        $temp    = $presets[$preset]['temperature'] ?? null;

        if ($temp === null) {
            return $config;
        }

        return new WorkerConfig(
            model:           $config->model,
            max_tokens:      $config->max_tokens,
            temperature:     $temp,
            response_schema: $config->response_schema,
        );
    }

    /**
     * Append the preset's system-prompt addendum to a feature's base prompt.
     * Returns the base unchanged for the 'standard' preset or when no addendum
     * is defined.
     *
     * A non-empty global "Custom addendum" option overrides the preset's
     * built-in text, giving admins domain-specific control.
     *
     * @param  string $base_system_prompt  The feature's own system prompt.
     * @param  int    $post_id             Pass post ID for per-page preset; 0 = global.
     */
    public static function apply_compliance_to_system(string $base_system_prompt, int $post_id = 0): string {

        $presets = self::presets();
        $preset  = self::active_preset($post_id);
        $stored  = trim( (string) get_option('linguaforge_compliance_addendum', '') );

        if ( $stored !== '' ) {
            // An explicit custom addendum always applies, even with the Standard
            // preset — the user chose to write it, so it must reach the model.
            $addendum = $stored;
        } elseif ( $preset === 'standard' ) {
            // Standard + no custom addendum: leave the system prompt untouched.
            return $base_system_prompt;
        } else {
            // Non-standard preset with no custom override: use the preset default.
            $addendum = trim( $presets[$preset]['addendum'] ?? '' );
        }

        if ( $addendum === '' ) {
            return $base_system_prompt;
        }

        return trim($base_system_prompt) . "\n\n" . $addendum;
    }

    // ── Deprecated compliance helpers (kept for any external callers) ─────────

    /** @deprecated Use active_preset() !== 'standard'. */
    public static function compliance_enabled(): bool {
        return self::active_preset() !== 'standard';
    }

    /** @deprecated Use presets()['legal']['temperature']. */
    public static function compliance_temperature(): float {
        $presets = self::presets();
        return (float) ($presets[ self::active_preset() ]['temperature'] ?? 0.1);
    }

    /** @deprecated Use apply_compliance_to_system(). */
    public static function compliance_addendum(): string {
        $stored = (string) get_option('linguaforge_compliance_addendum', '');
        return $stored !== '' ? $stored : self::LEGAL_ADDENDUM_DEFAULT;
    }
}
