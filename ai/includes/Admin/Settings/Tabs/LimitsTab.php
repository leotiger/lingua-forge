<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Core\Config;

defined('ABSPATH') || exit;

/**
 * Settings tab: Limits
 *
 * AI Limits & Security (daily quota, minimum role) and per-feature token /
 * character limits for Translation, Quick Translation, and Content Generator.
 */
class LimitsTab extends Tab {

    public static function slug(): string {
        return 'limits';
    }

    public static function label(): string {
        return __( 'Limits', 'lingua-forge' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Whitelisted capability choices for the "Minimum role" Settings field.
     *
     * Maps the WP capability string (passed to current_user_can) to a
     * human-readable label. The capability column is what gets stored;
     * the label is only used to render the dropdown.
     *
     * @return array<string, string>
     */
    public static function capability_choices(): array {

        return [
            'edit_published_posts' => __( 'Authors and above (edit_published_posts)', 'lingua-forge' ),
            'edit_posts'           => __( 'Contributors and above — default (edit_posts)', 'lingua-forge' ),
            'edit_others_posts'    => __( 'Editors and above (edit_others_posts)', 'lingua-forge' ),
            'manage_options'       => __( 'Administrators only (manage_options)', 'lingua-forge' ),
        ];
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        ?>
        <!-- ── AI Limits & Security ─────────────────────────────── -->
        <h2><?php esc_html_e('AI Limits & Security', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Cap how much AI usage the site can generate and restrict which user roles may trigger paid AI calls. Sits on top of the per-user rate limit (30 requests / minute, hardcoded) that already protects against single-user runaway loops.', 'lingua-forge' );
            ?>
        </p>

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="linguaforge_ai_daily_quota">
                        <?php esc_html_e( 'Daily request limit', 'lingua-forge' ); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_ai_daily_quota"
                        name="linguaforge_ai_daily_quota"
                        value="<?php echo esc_attr( (string) (int) get_option( 'linguaforge_ai_daily_quota', 0 ) ); ?>"
                        min="0"
                        step="1"
                        class="small-text"
                    >
                    <p class="description">
                        <?php
                        esc_html_e( 'Site-wide ceiling on AI requests per UTC day (counts both Toolbar translations and block revisions). Counter resets at UTC midnight. Set to 0 to disable the cap.', 'lingua-forge' );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_required_capability">
                        <?php esc_html_e( 'Minimum role', 'lingua-forge' ); ?>
                    </label>
                </th>
                <td>
                    <?php $current_cap = (string) get_option( 'linguaforge_required_capability', 'edit_posts' ); ?>
                    <select
                        id="linguaforge_required_capability"
                        name="linguaforge_required_capability"
                    >
                        <?php foreach ( self::capability_choices() as $cap_value => $cap_label ) : ?>
                            <option
                                value="<?php echo esc_attr( $cap_value ); ?>"
                                <?php selected( $current_cap, $cap_value ); ?>
                            >
                                <?php echo esc_html( $cap_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php
                        esc_html_e( 'Lowest WordPress capability allowed to trigger AI features. Tightening this on multi-author sites prevents Contributors or trial accounts from running paid AI calls. Override per-feature via the linguaforge_required_capability filter.', 'lingua-forge' );
                        ?>
                    </p>
                </td>
            </tr>

        </table>

        <!-- ── Translation limits ───────────────────────────────── -->
        <h2><?php esc_html_e('Translation Limits', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Control how much content is sent to the AI and how large the response can be. Leave a field blank to use the built-in default (shown as placeholder). Raise these values if large pages are being cut off; lower them to reduce API costs.', 'lingua-forge' );
            ?>
        </p>

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="linguaforge_translation_tier">
                        <?php esc_html_e('Model tier', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <?php
                    $translation_tier = Config::translation_tier();
                    ?>
                    <select
                        id="linguaforge_translation_tier"
                        name="linguaforge_translation_tier"
                    >
                        <option value="quality" <?php selected($translation_tier, 'quality'); ?>>
                            <?php esc_html_e('Quality (default — Sonnet / GPT-4o / Gemini Pro)', 'lingua-forge'); ?>
                        </option>
                        <option value="light" <?php selected($translation_tier, 'light'); ?>>
                            <?php esc_html_e('Light (fast and cost-effective — Haiku / Flash)', 'lingua-forge'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php
                        esc_html_e( 'Which model tier to use for full-page translation. Quality uses the model configured in the Models table above and is recommended for accurate, long-form translation. Switch to Light only if speed or cost is the priority and the content is short.', 'lingua-forge' );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_translation_max_tokens">
                        <?php esc_html_e('Max output tokens', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_translation_max_tokens"
                        name="linguaforge_translation_max_tokens"
                        class="small-text"
                        min="1000"
                        max="128000"
                        step="1000"
                        value="<?php echo esc_attr((string) get_option('linguaforge_translation_max_tokens', '')); ?>"
                        placeholder="16000"
                    >
                    <p class="description">
                        <?php
                        esc_html_e( 'Maximum number of tokens the AI may produce in a single translation response. If a translation is silently cut off at the end, increase this value. Default: 16 000.', 'lingua-forge' );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_translation_max_input_chars">
                        <?php esc_html_e('Max input characters', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_translation_max_input_chars"
                        name="linguaforge_translation_max_input_chars"
                        class="small-text"
                        min="0"
                        max="500000"
                        step="1000"
                        value="<?php echo esc_attr((string) get_option('linguaforge_translation_max_input_chars', '')); ?>"
                        placeholder="0 (no limit)"
                    >
                    <p class="description">
                        <?php
                        esc_html_e( 'Maximum number of characters of post content forwarded to the AI. 0 means no limit — the full content is always sent (recommended). Set a non-zero value only if your provider has a tight context window. A warning is written to the PHP error log whenever the content is trimmed.', 'lingua-forge' );
                        ?>
                    </p>
                </td>
            </tr>

        </table>

        <!-- ── Quick Translation limits ─────────────────────────── -->
        <h2><?php esc_html_e('Quick Translation', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Quick Translation is used for snippet/chunk mode — short passages translated on demand from the toolbar, editor, or block popovers. It uses a separate, lighter configuration from the full-page translation to keep responses fast and cost-effective.', 'lingua-forge' );
            ?>
        </p>

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="linguaforge_quick_translate_tier">
                        <?php esc_html_e('Model tier', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <?php
                    $qt_tier = Config::quick_translate_tier();
                    ?>
                    <select
                        id="linguaforge_quick_translate_tier"
                        name="linguaforge_quick_translate_tier"
                    >
                        <option value="light" <?php selected($qt_tier, 'light'); ?>>
                            <?php esc_html_e('Light (default — fast and cost-effective)', 'lingua-forge'); ?>
                        </option>
                        <option value="quality" <?php selected($qt_tier, 'quality'); ?>>
                            <?php esc_html_e('Quality (Sonnet / GPT-4o / Gemini Pro)', 'lingua-forge'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php
                        esc_html_e( 'The Light tier uses the fast model configured in the Models table above (default: Haiku / Flash). Switch to Quality if you need the same translation accuracy as full-page mode for snippets.', 'lingua-forge' );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_quick_translate_max_tokens">
                        <?php esc_html_e('Max output tokens', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_quick_translate_max_tokens"
                        name="linguaforge_quick_translate_max_tokens"
                        class="small-text"
                        min="256"
                        max="16000"
                        step="256"
                        value="<?php echo esc_attr((string) get_option('linguaforge_quick_translate_max_tokens', '')); ?>"
                        placeholder="2000"
                    >
                    <p class="description">
                        <?php esc_html_e('Maximum tokens the AI may produce per quick translation. Default: 2 000.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_quick_translate_max_input_chars">
                        <?php esc_html_e('Max input characters', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_quick_translate_max_input_chars"
                        name="linguaforge_quick_translate_max_input_chars"
                        class="small-text"
                        min="256"
                        max="32000"
                        step="256"
                        value="<?php echo esc_attr((string) get_option('linguaforge_quick_translate_max_input_chars', '')); ?>"
                        placeholder="8000"
                    >
                    <p class="description">
                        <?php esc_html_e('Maximum characters accepted from the input field. Default: 8 000.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>

        </table>

        <!-- ── Content Generator limits ─────────────────────────── -->
        <h2><?php esc_html_e('Content Generator', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Controls the token budget and input limits for the AI Content Generator feature. Leave fields blank to use the built-in defaults.', 'lingua-forge' );
            ?>
        </p>

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="linguaforge_content_generator_max_tokens">
                        <?php esc_html_e('Max output tokens', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_content_generator_max_tokens"
                        name="linguaforge_content_generator_max_tokens"
                        class="small-text"
                        min="1000"
                        max="128000"
                        step="1000"
                        value="<?php echo esc_attr((string) get_option('linguaforge_content_generator_max_tokens', '')); ?>"
                        placeholder="8192"
                    >
                    <p class="description">
                        <?php esc_html_e('Maximum tokens the AI may produce per generation run. Raise this if full articles are being cut off. Default: 8 192.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_content_generator_max_hints_chars">
                        <?php esc_html_e('Max hints characters', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_content_generator_max_hints_chars"
                        name="linguaforge_content_generator_max_hints_chars"
                        class="small-text"
                        min="256"
                        max="32000"
                        step="256"
                        value="<?php echo esc_attr((string) get_option('linguaforge_content_generator_max_hints_chars', '')); ?>"
                        placeholder="2000"
                    >
                    <p class="description">
                        <?php esc_html_e('Maximum characters read from the Hints field. Default: 2 000.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="linguaforge_content_generator_max_context_chars">
                        <?php esc_html_e('Max context characters', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        id="linguaforge_content_generator_max_context_chars"
                        name="linguaforge_content_generator_max_context_chars"
                        class="small-text"
                        min="256"
                        max="32000"
                        step="256"
                        value="<?php echo esc_attr((string) get_option('linguaforge_content_generator_max_context_chars', '')); ?>"
                        placeholder="6000"
                    >
                    <p class="description">
                        <?php esc_html_e('Maximum characters of existing post content passed to the AI as seed context when no Hints are provided. Default: 6 000.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>

        </table>
        <?php
    }
}
