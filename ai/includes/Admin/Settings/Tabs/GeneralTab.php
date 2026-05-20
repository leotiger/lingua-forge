<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\Config;

defined('ABSPATH') || exit;

/**
 * Settings tab: General
 *
 * Active Provider selection and per-provider model overrides.
 */
class GeneralTab extends Tab {

    public static function slug(): string {
        return 'general';
    }

    public static function label(): string {
        return __( 'General', 'lingua-forge' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Model tiers: slug → label and description shown in the settings table.
     * Defined as a method (not a constant) so strings can be wrapped with __().
     *
     * @return array<string, array{label: string, used_by: string}>
     */
    private static function tiers(): array {

        return [
            'light' => [
                'label'   => __( 'Light',                               'lingua-forge' ),
                'used_by' => __( 'Meta Description, Excerpt Generator', 'lingua-forge' ),
            ],
            'quality' => [
                'label'   => __( 'Quality',                        'lingua-forge' ),
                'used_by' => __( 'Translation, Content Generator', 'lingua-forge' ),
            ],
        ];
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        $saved_provider  = (string) get_option( SettingsPage::OPT_PROVIDER, '' );
        $active_provider = $saved_provider !== ''
            ? $saved_provider
            : ( defined('LINGUAFORGE_PROVIDER') ? LINGUAFORGE_PROVIDER : 'anthropic' );

        ?>
        <!-- ── Provider ──────────────────────────────────────────── -->
        <h2><?php esc_html_e('Active Provider', 'lingua-forge'); ?></h2>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="linguaforge_provider">
                        <?php esc_html_e('Provider', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <select
                        name="<?php echo esc_attr( SettingsPage::OPT_PROVIDER ); ?>"
                        id="linguaforge_provider"
                    >
                        <?php foreach (SettingsPage::providers() as $slug => $label): ?>
                            <option
                                value="<?php echo esc_attr($slug); ?>"
                                <?php selected($active_provider, $slug); ?>
                            >
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($saved_provider === '' && defined('LINGUAFORGE_PROVIDER')): ?>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s is the PHP constant name LINGUAFORGE_PROVIDER, wrapped in <code> tags. */
                                esc_html__(
                                    'Currently inherited from the %s constant. Selecting a value here will override it.',
                                    'lingua-forge'
                                ),
                                '<code>LINGUAFORGE_PROVIDER</code>'
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- ── Models ────────────────────────────────────────────── -->
        <h2><?php esc_html_e('Models', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Features are grouped into two tiers. Enter the exact model identifier for each tier and provider. Leave a field blank to use the built-in default (shown as placeholder). Only the active provider\'s models are called at runtime — configure the others in advance if you plan to switch.',
                'lingua-forge'
            );
            ?>
        </p>

        <table class="form-table lingua-forge-models-table" role="presentation">

            <thead>
                <tr>
                    <th><?php esc_html_e('Provider', 'lingua-forge'); ?></th>
                    <?php foreach (self::tiers() as $tier_slug => $tier): ?>
                        <th>
                            <?php echo esc_html($tier['label']); ?>
                            <span class="lingua-forge-tier-used-by">
                                <?php echo esc_html($tier['used_by']); ?>
                            </span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <tbody>
            <?php foreach (SettingsPage::providers() as $slug => $label): ?>

                <?php $is_active = ($slug === $active_provider); ?>

                <tr class="<?php echo $is_active ? 'lingua-forge-active-provider-row' : ''; ?>">
                    <th scope="row">
                        <?php echo esc_html($label); ?>
                        <?php if ($is_active): ?>
                            <span class="lingua-forge-active-badge">
                                <?php esc_html_e('active', 'lingua-forge'); ?>
                            </span>
                        <?php endif; ?>
                    </th>

                    <?php foreach (self::tiers() as $tier_slug => $tier): ?>

                        <?php
                        $option_key    = "linguaforge_model_{$slug}_{$tier_slug}";
                        $stored_model  = (string) get_option($option_key, '');
                        $default_model = Config::default_model($slug, $tier_slug);
                        $input_id      = "linguaforge_model_{$slug}_{$tier_slug}";
                        ?>

                        <td>
                            <input
                                type="text"
                                id="<?php echo esc_attr($input_id); ?>"
                                name="<?php echo esc_attr($option_key); ?>"
                                class="regular-text lingua-forge-model-input"
                                value="<?php echo esc_attr($stored_model); ?>"
                                placeholder="<?php echo esc_attr($default_model); ?>"
                                spellcheck="false"
                                autocomplete="off"
                            >
                            <?php if ($stored_model !== ''): ?>
                                <span class="lingua-forge-model-override-badge">
                                    <?php esc_html_e('overridden', 'lingua-forge'); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                    <?php endforeach; ?>
                </tr>

            <?php endforeach; ?>
            </tbody>

        </table>

        <p class="description">
            <?php
            esc_html_e('Tip: to reset a model to the built-in default, clear the field and save.', 'lingua-forge');
            ?>
        </p>
        <?php
    }
}
