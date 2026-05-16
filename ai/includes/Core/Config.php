<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

class Config {

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
     * Settings page.  To update a model site-wide, go to Settings → LinguaForge AI
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
     *      (set via Settings → LinguaForge AI → Models)
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
}
