<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\ModelCatalog;
use LinguaForge\AI\Providers\Anthropic;
use LinguaForge\AI\Providers\Gemini;
use LinguaForge\AI\Providers\OpenAI;
use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

/**
 * Settings tab: API Keys
 *
 * Per-provider key management (set / remove) and the Test Connection AJAX
 * endpoint.
 */
class ApiKeysTab extends Tab {

    public static function slug(): string {
        return 'api-keys';
    }

    public static function label(): string {
        return __( 'API Keys', 'lingua-forge' );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        ?>
        <!-- ── API Keys ──────────────────────────────────────────── -->
        <h2><?php esc_html_e('API Keys', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Keys are encrypted with AES-256-CBC before being stored in the WordPress database. The encryption secret is derived from your WordPress auth salts (wp-config.php), so plaintext keys never touch the database.',
                'lingua-forge'
            );
            ?>
        </p>

        <table class="form-table" role="presentation">

            <?php foreach (SettingsPage::providers() as $slug => $label): ?>

                <?php
                // WordPress AI Client manages credentials through WP's Connectors screen.
                // No API key is stored by Lingua Forge for this provider.
                if ( $slug === 'wp-ai-client' ):
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $label ); ?></th>
                    <td>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s is a link to the WordPress Connectors settings screen. */
                                esc_html__(
                                    'API credentials are managed by WordPress in %s. No key is stored by Lingua Forge.',
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
                </tr>
                <?php continue; endif; ?>

                <?php
                $source     = KeyStore::source($slug);
                $configured = $source !== null;
                ?>

                <tr>
                    <th scope="row">
                        <label for="linguaforge_key_<?php echo esc_attr($slug); ?>">
                            <?php echo esc_html($label); ?>
                            <?php esc_html_e('API Key', 'lingua-forge'); ?>
                        </label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="linguaforge_key_<?php echo esc_attr($slug); ?>"
                            name="linguaforge_key_<?php echo esc_attr($slug); ?>"
                            class="regular-text"
                            autocomplete="new-password"
                            placeholder="<?php
                                echo $configured
                                    ? esc_attr( '••••••••••••••••' )
                                    : esc_attr( __( 'Paste your API key…', 'lingua-forge' ) );
                            ?>"
                        >

                        <span class="lingua-forge-key-badge <?php
                            echo $configured ? 'lingua-forge-badge--ok' : 'lingua-forge-badge--missing';
                        ?>">
                            <?php if ($configured): ?>
                                <?php esc_html_e( '✓ Configured', 'lingua-forge' ); ?>
                                <span class="lingua-forge-key-source">
                                    (<?php echo esc_html($source); ?>)
                                </span>
                            <?php else: ?>
                                <?php esc_html_e('✗ Not configured', 'lingua-forge'); ?>
                            <?php endif; ?>
                        </span>

                        <?php if ($configured): ?>
                            <button
                                type="button"
                                class="button button-secondary lingua-forge-test-key"
                                data-provider="<?php echo esc_attr($slug); ?>"
                            >
                                <?php esc_html_e( 'Test connection', 'lingua-forge' ); ?>
                            </button>
                            <span
                                class="lingua-forge-test-result"
                                data-for="<?php echo esc_attr($slug); ?>"
                                aria-live="polite"
                            ></span>
                        <?php endif; ?>

                        <p class="description">
                            <?php
                            esc_html_e(
                                'Leave blank to keep the existing key. Enter a new value to replace it.',
                                'lingua-forge'
                            );
                            ?>
                        </p>

                        <?php if ($source === 'database'): ?>
                            <p>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="linguaforge_remove_<?php echo esc_attr($slug); ?>"
                                        value="1"
                                    >
                                    <?php esc_html_e('Remove stored key', 'lingua-forge'); ?>
                                </label>
                            </p>
                        <?php elseif ($source === 'environment' || $source === 'constant'): ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s is the key source: either "environment variable" or "PHP constant". */
                                    esc_html__(
                                        'This key is currently supplied by a server %s and cannot be removed here. Enter a new key above to override it with a database value.',
                                        'lingua-forge'
                                    ),
                                    $source === 'environment'
                                        ? esc_html__('environment variable', 'lingua-forge')
                                        : esc_html__('PHP constant', 'lingua-forge')
                                );
                                ?>
                            </p>
                        <?php endif; ?>

                    </td>
                </tr>

            <?php endforeach; ?>

        </table>

        <!-- ── Server-side key sources ──────────────────────── -->
        <div class="lingua-forge-settings-note">
            <p>
                <strong><?php esc_html_e('Alternative (server-side):', 'lingua-forge'); ?></strong>
                <?php
                esc_html_e( 'You can also define keys as constants or environment variables (e.g. in wp-config.php). Those sources are used automatically as a fallback when no database key is stored.', 'lingua-forge' );
                ?>
            </p>
            <pre class="lingua-forge-code-sample">define( 'ANTHROPIC_API_KEY', 'sk-ant-…' );
define( 'OPENAI_API_KEY',    'sk-…' );</pre>
            <p>
                <?php
                esc_html_e(
                    'To use a custom encryption secret (instead of the derived wp_salt value), add this to wp-config.php:',
                    'lingua-forge'
                );
                ?>
            </p>
            <pre class="lingua-forge-code-sample">define( 'LINGUAFORGE_SECRET', 'your-random-secret' );</pre>
        </div>
        <?php
    }

    // ── Test Connection (AJAX) ────────────────────────────────────────────────

    /**
     * Run a minimal "ping" chat call against a single provider and report
     * back as JSON. Wired to wp_ajax_linguaforge_test_provider.
     *
     * Why per-provider rather than always-active-provider: admins frequently
     * configure multiple keys and want to validate each one independently
     * before flipping the active provider in Settings → Active Provider.
     *
     * Response payload (always JSON, status 200 so the JS client can read it):
     *   {
     *     success: bool,
     *     provider: 'anthropic'|'openai'|'gemini',
     *     message?: string,   // present on failure
     *     reply?:  string,    // present on success (truncated provider text)
     *     models?: string[],  // present on success — merged catalog + live IDs
     *   }
     */
    public static function ajax_test_provider(): void {

        if (!current_user_can('manage_options')) {
            wp_send_json([
                'success' => false,
                'message' => __('Permission denied.', 'lingua-forge'),
            ]);
        }

        check_ajax_referer('linguaforge_test_provider', 'nonce');

        $provider_slug = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $providers     = SettingsPage::providers();

        if (!array_key_exists($provider_slug, $providers)) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Unknown provider.', 'lingua-forge'),
            ]);
        }

        if (!KeyStore::get($provider_slug)) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('No API key configured for this provider.', 'lingua-forge'),
            ]);
        }

        // Build a low-cost WorkerConfig for the ping — light tier, tight token
        // budget so an accidental verbose model can't run up much cost.
        // Config::model() always uses the active provider, so we resolve the
        // light-tier model for the requested provider here: stored override
        // first, fall back to the hard-coded default.
        $model_option = (string) get_option("linguaforge_model_{$provider_slug}_light", '');
        $model        = $model_option !== ''
            ? $model_option
            : Config::default_model($provider_slug, 'light');

        $config = new WorkerConfig(
            model:       $model,
            max_tokens:  16,
            temperature: 0.0,
        );

        $provider_instance = match ($provider_slug) {
            'anthropic' => new Anthropic($config),
            'openai'    => new OpenAI($config),
            'gemini'    => new Gemini($config),
            default     => null,
        };

        if ($provider_instance === null) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Could not instantiate the provider.', 'lingua-forge'),
            ]);
        }

        $reply = $provider_instance->chat([
            [
                'role'    => 'user',
                'content' => 'Reply with the single word: ping',
            ],
        ]);

        if ($reply === null || $reply === '') {
            $error_detail = $provider_instance->get_last_error();
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => $error_detail !== ''
                    ? $error_detail
                    : __('Provider returned no response. Check the WordPress error log for details.', 'lingua-forge'),
            ]);
        }

        // Fetch available models from the provider API and cache for 24 h.
        // Non-fatal: an empty list simply means no live models were returned.
        $api_key    = (string) KeyStore::get($provider_slug);
        $live_ids   = ModelCatalog::fetch_from_api($provider_slug, $api_key);
        $models     = ModelCatalog::merge_live($provider_slug, $live_ids);
        set_transient(
            'linguaforge_available_models_' . $provider_slug,
            $models,
            DAY_IN_SECONDS
        );

        wp_send_json([
            'success'  => true,
            'provider' => $provider_slug,
            'reply'    => mb_substr((string) $reply, 0, 200),
            'models'   => $models,
        ]);
    }
}
