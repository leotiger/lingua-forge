<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\ModelCatalog;

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
                'label'   => __( 'Light',                                              'lingua-forge' ),
                'used_by' => __( 'Meta Description, Excerpt Generator, quick translate', 'lingua-forge' ),
            ],
            'quality' => [
                'label'   => __( 'Quality',                                                                        'lingua-forge' ),
                'used_by' => __( 'Full-page Translation, Content Generator (mid-tier models are fully sufficient)', 'lingua-forge' ),
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

        <?php if ( $active_provider === 'wp-ai-client' ): ?>
        <div class="notice notice-warning inline" style="margin:12px 0 0;">
            <p>
                <strong><?php esc_html_e( 'WordPress AI Client — requirements:', 'lingua-forge' ); ?></strong>
                <?php
                esc_html_e(
                    'Full-page translation uses JSON-schema-constrained output. This requires a connector plugin to be installed and active (not just an API key saved in Settings → Connectors). If translation fails, check the PHP error log for the specific error, or switch to a built-in provider.',
                    'lingua-forge'
                );
                ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- ── Provider Consoles ─────────────────────────────────── -->
        <p class="description">
            <?php esc_html_e( 'Manage your account, review usage, or top up credits:', 'lingua-forge' ); ?>
            <?php
            $lf_consoles = [
                'anthropic' => [ 'label' => __( 'Anthropic Console', 'lingua-forge' ), 'url' => 'https://console.anthropic.com/' ],
                'openai'    => [ 'label' => __( 'OpenAI Platform',    'lingua-forge' ), 'url' => 'https://platform.openai.com/'   ],
                'gemini'    => [ 'label' => __( 'Google AI Studio',   'lingua-forge' ), 'url' => 'https://aistudio.google.com/'   ],
            ];
            $lf_links = [];
            foreach ( $lf_consoles as $lf_console ) {
                $lf_links[] = sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s ↗</a>',
                    esc_url( $lf_console['url'] ),
                    esc_html( $lf_console['label'] )
                );
            }
            // On WP 7.0+ the WordPress AI Client provider is available; surface the
            // Connectors screen link alongside the three API-key console links.
            if ( array_key_exists( 'wp-ai-client', SettingsPage::providers() ) ) {
                $lf_links[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( admin_url( 'options-general.php?page=connectors' ) ),
                    esc_html__( 'WordPress Connectors', 'lingua-forge' )
                );
            }
            echo implode( ' &middot; ', $lf_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link built with esc_url/esc_html above.
            ?>
        </p>

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
        <p class="description">
            <?php
            esc_html_e(
                'Start typing in any field to see model suggestions, or use the reference table below. Light and mid-tier Quality models (Haiku, GPT-4o mini, Gemini Flash) are fully sufficient for translation and content generation — flagship "max" models add cost with minimal quality gain for structured text.',
                'lingua-forge'
            );
            ?>
        </p>

        <?php
        // ── Datalists: one per provider, shared by both tier inputs ──────────
        // Populated from the static catalog; refreshed from the live API when
        // a "Test connection" succeeds in the API Keys tab (test-connection.js).
        foreach (SettingsPage::providers() as $provider_slug => $provider_label):
            // WP AI Client has no model catalog — model selection is managed by WP core.
            if ( $provider_slug === 'wp-ai-client' ) continue;
            $models  = ModelCatalog::for_provider($provider_slug);
            $list_id = 'lf-models-' . $provider_slug;
        ?>
        <datalist id="<?php echo esc_attr($list_id); ?>">
            <?php foreach ($models as $model_id => $meta): ?>
                <option value="<?php echo esc_attr($model_id); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <?php endforeach; ?>

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

                    <?php if ( $slug === 'wp-ai-client' ): ?>

                        <td colspan="2">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s is a link to the WordPress Connectors settings screen. */
                                    esc_html__(
                                        'Model selection is managed by WordPress in %s — no model identifier is stored by Lingua Forge for this provider.',
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

                    <?php else: ?>

                    <?php foreach (self::tiers() as $tier_slug => $tier): ?>

                        <?php
                        $option_key    = "linguaforge_model_{$slug}_{$tier_slug}";
                        $stored_model  = (string) get_option($option_key, '');
                        $default_model = Config::default_model($slug, $tier_slug);
                        $input_id      = "linguaforge_model_{$slug}_{$tier_slug}";
                        $datalist_id   = 'lf-models-' . $slug;
                        ?>

                        <td>
                            <input
                                type="text"
                                id="<?php echo esc_attr($input_id); ?>"
                                name="<?php echo esc_attr($option_key); ?>"
                                class="regular-text lingua-forge-model-input"
                                value="<?php echo esc_attr($stored_model); ?>"
                                placeholder="<?php echo esc_attr($default_model); ?>"
                                list="<?php echo esc_attr($datalist_id); ?>"
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

                    <?php endif; ?>
                </tr>

            <?php endforeach; ?>
            </tbody>

        </table>

        <p class="description">
            <?php esc_html_e('Tip: to reset a model to the built-in default, clear the field and save.', 'lingua-forge'); ?>
        </p>

        <!-- ── Available models reference ───────────────────────── -->
        <details class="lingua-forge-models-reference">
            <summary><?php esc_html_e('Available models reference', 'lingua-forge'); ?></summary>

            <?php foreach (ModelCatalog::all() as $provider_slug => $provider_models): ?>

                <?php
                $provider_labels = SettingsPage::providers();
                $provider_label  = $provider_labels[$provider_slug] ?? $provider_slug;
                ?>

                <h4><?php echo esc_html($provider_label); ?></h4>
                <table class="widefat striped lingua-forge-models-reference-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Model ID', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Name', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Tier', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Notes', 'lingua-forge'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($provider_models as $model_id => $meta): ?>
                        <tr>
                            <td><code><?php echo esc_html($model_id); ?></code></td>
                            <td><?php echo esc_html($meta['label']); ?></td>
                            <td><?php echo esc_html($meta['tier']); ?></td>
                            <td><?php echo esc_html($meta['note']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endforeach; ?>

            <p class="description">
                <?php
                esc_html_e(
                    'This catalog is updated with each Lingua Forge release. Testing a connection in the API Keys tab also fetches the live model list from the provider and refreshes the suggestions above.',
                    'lingua-forge'
                );
                ?>
            </p>
        </details>
        <?php
    }
}
