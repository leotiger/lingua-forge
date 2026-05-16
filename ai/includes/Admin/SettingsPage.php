<?php

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\Config;

defined('ABSPATH') || exit;

/**
 * Settings → LinguaForge AI
 *
 * Provides a standard WordPress settings page where administrators can:
 *   - Choose the active AI provider (Anthropic / OpenAI / Gemini)
 *   - Enter and store API keys (AES-256 encrypted via KeyStore)
 *   - Override the model string for the Light and Quality tiers of each provider
 *   - See where each key is currently sourced from
 *   - Remove a stored database key
 *
 * Keys entered here are encrypted before being saved to wp_options.
 * If a key is already configured via an env var or a wp-config.php constant,
 * that source takes lower priority than the database — but it is shown to
 * the administrator so they know a fallback is active.
 *
 * Model overrides are stored as plain text option values under
 * linguaforge_model_{provider}_{tier}.  Leaving a field blank resets it to
 * the built-in default (shown as placeholder text).
 */
class SettingsPage {

    private const PAGE_SLUG    = 'lingua-forge';
    private const NONCE_ACTION = 'linguaforge_save_settings';
    private const NONCE_FIELD  = 'linguaforge_nonce';
    private const OPT_PROVIDER = 'linguaforge_provider';

    /**
     * Provider slugs → human labels.
     * Defined as a method (not a constant) so labels can be wrapped with __().
     *
     * @return array<string, string>
     */
    private static function providers(): array {

        return [
            'anthropic' => __( 'Anthropic (Claude)', 'lingua-forge' ),
            'openai'    => __( 'OpenAI (GPT)',        'lingua-forge' ),
            'gemini'    => __( 'Google (Gemini)',     'lingua-forge' ),
        ];
    }

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

    // ── Initialisation ────────────────────────────────────────────────────────

    public static function init(): void {

        add_action('admin_menu',                    [self::class, 'register_menu']);
        add_action('admin_post_' . self::PAGE_SLUG, [self::class, 'handle_save']);

        // Language override file management
        add_action('admin_post_linguaforge_upload_i18n_override', [self::class, 'handle_upload_override']);
        add_action('admin_post_linguaforge_delete_i18n_override', [self::class, 'handle_delete_override']);
    }

    // ── i18n overrides directory ──────────────────────────────────────────────

    /**
     * Absolute path to the uploads-based i18n overrides directory.
     * Matches the path used by Language_Router::i18n_overrides_dir().
     *
     * @return string  Trailing-slash path.
     */
    private static function overrides_dir(): string {

        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides/';
    }

    public static function register_menu(): void {

        add_options_page(
            'LinguaForge AI',
            'LinguaForge AI',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    // ── Form handler ──────────────────────────────────────────────────────────

    public static function handle_save(): void {

        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to manage these settings.', 'lingua-forge'),
                403
            );
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        // ── Provider ──────────────────────────────────────────────────────────
        $provider = sanitize_key($_POST[self::OPT_PROVIDER] ?? '');

        if (array_key_exists($provider, self::providers())) {
            update_option(self::OPT_PROVIDER, $provider, false);
        }

        // ── API keys — save if non-empty, remove if checkbox checked ──────────
        foreach (array_keys(self::providers()) as $slug) {

            // Explicit removal takes precedence over a new value.
            if (!empty($_POST["linguaforge_remove_{$slug}"])) {
                KeyStore::delete($slug);
                continue;
            }

            $new_key = sanitize_text_field(
                trim($_POST["linguaforge_key_{$slug}"] ?? '')
            );

            if ($new_key !== '') {
                KeyStore::set($slug, $new_key);
            }
            // If the field was left blank, the existing key is preserved.
        }

        // ── Model overrides ───────────────────────────────────────────────────
        // Store whatever the admin submitted (even empty string).
        // Config::model() treats an empty stored value as "use built-in default",
        // so clearing a field in the form is how you reset to the default.
        foreach (array_keys(self::providers()) as $slug) {
            foreach (array_keys(self::tiers()) as $tier) {

                $option_key  = "linguaforge_model_{$slug}_{$tier}";
                $model_value = sanitize_text_field(
                    trim($_POST[$option_key] ?? '')
                );

                // Allow saving an empty string to reset to the built-in default.
                update_option($option_key, $model_value, false);
            }
        }

        // ── Translation limits ────────────────────────────────────────────────
        // Store as integers; 0 / empty means "use built-in default".

        $max_tokens = (int) ($_POST['linguaforge_translation_max_tokens'] ?? 0);
        update_option(
            'linguaforge_translation_max_tokens',
            $max_tokens > 0 ? $max_tokens : '',
            false
        );

        $max_input = (int) ($_POST['linguaforge_translation_max_input_chars'] ?? 0);
        // 0 is a valid value here (means no limit), so store whatever was submitted.
        update_option(
            'linguaforge_translation_max_input_chars',
            max(0, $max_input),
            false
        );

        // ── Quick Translation limits ──────────────────────────────────────────

        $qt_tier = sanitize_key($_POST['linguaforge_quick_translate_tier'] ?? '');
        update_option(
            'linguaforge_quick_translate_tier',
            in_array($qt_tier, ['light', 'quality'], true) ? $qt_tier : '',
            false
        );

        $qt_tokens = (int) ($_POST['linguaforge_quick_translate_max_tokens'] ?? 0);
        update_option(
            'linguaforge_quick_translate_max_tokens',
            $qt_tokens > 0 ? $qt_tokens : '',
            false
        );

        $qt_input = (int) ($_POST['linguaforge_quick_translate_max_input_chars'] ?? 0);
        update_option(
            'linguaforge_quick_translate_max_input_chars',
            $qt_input > 0 ? $qt_input : '',
            false
        );

        // ── Content Generator limits ──────────────────────────────────────────

        $cg_tokens = (int) ($_POST['linguaforge_content_generator_max_tokens'] ?? 0);
        update_option(
            'linguaforge_content_generator_max_tokens',
            $cg_tokens > 0 ? $cg_tokens : '',
            false
        );

        $cg_hints = (int) ($_POST['linguaforge_content_generator_max_hints_chars'] ?? 0);
        update_option(
            'linguaforge_content_generator_max_hints_chars',
            $cg_hints > 0 ? $cg_hints : '',
            false
        );

        $cg_context = (int) ($_POST['linguaforge_content_generator_max_context_chars'] ?? 0);
        update_option(
            'linguaforge_content_generator_max_context_chars',
            $cg_context > 0 ? $cg_context : '',
            false
        );

        wp_safe_redirect(
            add_query_arg(
                'linguaforge_saved',
                '1',
                admin_url('options-general.php?page=' . self::PAGE_SLUG)
            )
        );
        exit;
    }

    // ── Language override handlers ────────────────────────────────────────────

    public static function handle_upload_override(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_upload_override', 'linguaforge_override_nonce');

        $redirect_base = admin_url('options-general.php?page=' . self::PAGE_SLUG);

        // No file submitted
        if (empty($_FILES['linguaforge_mo_file']['name'])) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'empty', $redirect_base));
            exit;
        }

        $file = $_FILES['linguaforge_mo_file'];

        // Validate extension — only .mo files are loaded at runtime
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'mo') {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_type', $redirect_base));
            exit;
        }

        // Validate upload integrity
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'upload_error', $redirect_base));
            exit;
        }

        $dir      = self::overrides_dir();
        $filename = sanitize_file_name($file['name']);
        $dest     = $dir . $filename;

        wp_mkdir_p($dir);

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'move_failed', $redirect_base));
            exit;
        }

        wp_safe_redirect(add_query_arg('lf_override_uploaded', '1', $redirect_base));
        exit;
    }

    public static function handle_delete_override(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_delete_override', 'linguaforge_override_nonce');

        $redirect_base = admin_url('options-general.php?page=' . self::PAGE_SLUG);

        $filename = sanitize_file_name($_POST['linguaforge_override_file'] ?? '');

        // Validate: must be a .mo filename, no path separators
        if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_file', $redirect_base));
            exit;
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'mo') {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_type', $redirect_base));
            exit;
        }

        $dir     = self::overrides_dir();
        $real_dir = realpath($dir);

        if ($real_dir === false) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_path', $redirect_base));
            exit;
        }

        // Delete both .mo and .po for the given base name.
        $base      = pathinfo($filename, PATHINFO_FILENAME); // strip extension
        $deleted   = false;

        foreach (['mo', 'po'] as $ext) {
            $filepath  = $dir . $base . '.' . $ext;
            $real_file = realpath($filepath);

            // Path-traversal guard: resolved path must still be inside the overrides dir
            if ($real_file === false || strpos($real_file, $real_dir . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            wp_delete_file($filepath);
            $deleted = true;
        }

        if (!$deleted) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_path', $redirect_base));
            exit;
        }

        wp_safe_redirect(add_query_arg('lf_override_deleted', '1', $redirect_base));
        exit;
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render(): void {

        if (!current_user_can('manage_options')) {
            return;
        }

        $saved_provider  = (string) get_option(self::OPT_PROVIDER, '');
        $active_provider = $saved_provider !== ''
            ? $saved_provider
            : (defined('LINGUAFORGE_PROVIDER') ? LINGUAFORGE_PROVIDER : 'anthropic');

        ?>
        <div class="wrap">

            <h1><?php esc_html_e('LinguaForge AI — Settings', 'lingua-forge'); ?></h1>

            <?php if (!empty($_GET['linguaforge_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'lingua-forge'); ?></p>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            >
                <input
                    type="hidden"
                    name="action"
                    value="<?php echo esc_attr(self::PAGE_SLUG); ?>"
                >

                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

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
                                name="<?php echo esc_attr(self::OPT_PROVIDER); ?>"
                                id="linguaforge_provider"
                            >
                                <?php foreach (self::providers() as $slug => $label): ?>
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
                    <?php foreach (self::providers() as $slug => $label): ?>

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

                    <?php foreach (self::providers() as $slug => $label): ?>

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
                                            esc_html__(
                                                'This key is currently supplied by a server %s and ' .
                                                'cannot be removed here. Enter a new key above to ' .
                                                'override it with a database value.'
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

                <!-- ── Translation limits ───────────────────────────────── -->
                <h2><?php esc_html_e('Translation Limits', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e(
                        'Control how much content is sent to the AI and how large the response can be. ' .
                        'Leave a field blank to use the built-in default (shown as placeholder). ' .
                        'Raise these values if large pages are being cut off; lower them to reduce API costs.',
                        'lingua-forge'
                    );
                    ?>
                </p>

                <table class="form-table" role="presentation">

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
                                esc_html_e(
                                    'Maximum number of tokens the AI may produce in a single translation response. ' .
                                    'If a translation is silently cut off at the end, increase this value. ' .
                                    'Default: 16 000.',
                                    'lingua-forge'
                                );
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
                                esc_html_e(
                                    'Maximum number of characters of post content forwarded to the AI. ' .
                                    '0 means no limit — the full content is always sent (recommended). ' .
                                    'Set a non-zero value only if your provider has a tight context window. ' .
                                    'A warning is written to the PHP error log whenever the content is trimmed.',
                                    'lingua-forge'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <!-- ── Quick Translation limits ─────────────────────────── -->
                <h2><?php esc_html_e('Quick Translation', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e(
                        'Quick Translation is used for snippet/chunk mode — short passages translated on demand from the toolbar, editor, or block popovers. ' .
                        'It uses a separate, lighter configuration from the full-page translation to keep responses fast and cost-effective.',
                        'lingua-forge'
                    );
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
                                    <?php esc_html_e('Quality (same model as full-page translation)', 'lingua-forge'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php
                                esc_html_e(
                                    'The Light tier uses the fast model configured in the Models table above (default: Haiku / Flash). ' .
                                    'Switch to Quality if you need the same translation accuracy as full-page mode for snippets.',
                                    'lingua-forge'
                                );
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
                    esc_html_e(
                        'Controls the token budget and input limits for the AI Content Generator feature. ' .
                        'Leave fields blank to use the built-in defaults.',
                        'lingua-forge'
                    );
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

                <!-- ── Security note ─────────────────────────────────────── -->
                <div class="lingua-forge-settings-note">
                    <p>
                        <strong><?php esc_html_e('Alternative (server-side):', 'lingua-forge'); ?></strong>
                        <?php
                        esc_html_e(
                            'You can also define keys as constants or environment ' .
                            'variables (e.g. in wp-config.php). Those sources are ' .
                            'used automatically as a fallback when no database key ' .
                            'is stored.'
                        , 'lingua-forge');
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

                <?php submit_button( __( 'Save Settings', 'lingua-forge' ) ); ?>

            </form>

            <!-- ── Language Overrides ──────────────────────────────────── -->
            <hr>

            <h2><?php esc_html_e('Language Overrides', 'lingua-forge'); ?></h2>

            <p>
                <?php
                esc_html_e(
                    'Upload compiled .mo files to override third-party plugin strings for specific locales — ' .
                    'for example, a custom VikBooking translation that uses "apartment" instead of "room". ' .
                    'Files must follow the WordPress naming convention: {textdomain}-{locale}.mo ' .
                    '(e.g. vikbooking-ca.mo). They are stored in the uploads folder and survive plugin updates.',
                    'lingua-forge'
                );
                ?>
            </p>

            <?php
            // ── Feedback notices ─────────────────────────────────────────────
            if (!empty($_GET['lf_override_uploaded'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Override file uploaded successfully.', 'lingua-forge'); ?></p>
                </div>
            <?php elseif (!empty($_GET['lf_override_deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Override file deleted.', 'lingua-forge'); ?></p>
                </div>
            <?php elseif (!empty($_GET['lf_override_error'])):
                $error_map = [
                    'empty'        => __('No file was selected.', 'lingua-forge'),
                    'invalid_type' => __('Only .mo files are accepted.', 'lingua-forge'),
                    'upload_error' => __('The upload failed — please try again.', 'lingua-forge'),
                    'move_failed'  => __('Could not save the file. Check that the uploads folder is writable.', 'lingua-forge'),
                    'invalid_file' => __('Invalid filename.', 'lingua-forge'),
                    'invalid_path' => __('Security check failed — file path is not permitted.', 'lingua-forge'),
                ];
                $error_key = sanitize_key($_GET['lf_override_error']);
                $error_msg = $error_map[$error_key] ?? __('An unknown error occurred.', 'lingua-forge');
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error_msg); ?></p>
                </div>
            <?php endif; ?>

            <!-- ── Current override files ────────────────────────────────── -->
            <?php
            $dir   = self::overrides_dir();
            $mo_files = array_map('basename', glob($dir . '*.mo') ?: []);
            $po_files = array_map('basename', glob($dir . '*.po') ?: []);

            // Merge: show .mo files with a note if a matching .po source exists.
            // Also show any orphaned .po files (no compiled .mo yet).
            $all_bases = array_unique(array_merge(
                array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $mo_files),
                array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $po_files)
            ));
            sort($all_bases);
            $files = $all_bases; // used below as the loop driver
            ?>

            <?php if (!empty($files)): ?>

                <table class="widefat striped" style="max-width:680px;margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Text domain / locale', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Files', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Size', 'lingua-forge'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $base):
                            $has_mo   = in_array($base . '.mo', $mo_files, true);
                            $has_po   = in_array($base . '.po', $po_files, true);
                            $mo_path  = $dir . $base . '.mo';
                            $size     = $has_mo ? size_format(filesize($mo_path)) : '—';
                            $badges   = [];
                            if ($has_mo) $badges[] = '<code>.mo</code>';
                            if ($has_po) $badges[] = '<code>.po</code>';
                        ?>
                            <tr>
                                <td><code><?php echo esc_html($base); ?></code></td>
                                <td><?php echo implode(' ', $badges); ?></td>
                                <td><?php echo esc_html($size); ?></td>
                                <td>
                                    <form
                                        method="post"
                                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        style="display:inline;"
                                        onsubmit="return confirm('<?php echo esc_js(__('Delete all files for this override (both .mo and .po)?', 'lingua-forge')); ?>')"
                                    >
                                        <input type="hidden" name="action" value="linguaforge_delete_i18n_override">
                                        <input type="hidden" name="linguaforge_override_file" value="<?php echo esc_attr($base . '.mo'); ?>">
                                        <?php wp_nonce_field('linguaforge_delete_override', 'linguaforge_override_nonce'); ?>
                                        <button type="submit" class="button button-link-delete">
                                            <?php esc_html_e('Delete', 'lingua-forge'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php else: ?>

                <p class="description" style="margin-bottom:16px;">
                    <?php esc_html_e('No override files uploaded yet.', 'lingua-forge'); ?>
                </p>

            <?php endif; ?>

            <!-- ── Upload form ───────────────────────────────────────────── -->
            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                enctype="multipart/form-data"
            >
                <input type="hidden" name="action" value="linguaforge_upload_i18n_override">
                <?php wp_nonce_field('linguaforge_upload_override', 'linguaforge_override_nonce'); ?>

                <table class="form-table" role="presentation" style="max-width:680px;">
                    <tr>
                        <th scope="row">
                            <label for="linguaforge_mo_file">
                                <?php esc_html_e('Upload .mo file', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="file"
                                id="linguaforge_mo_file"
                                name="linguaforge_mo_file"
                                accept=".mo"
                            >
                            <p class="description">
                                <?php
                                esc_html_e(
                                    'Accepts compiled .mo files only. Filename must follow the pattern {textdomain}-{locale}.mo. ' .
                                    'Uploading a file with the same name as an existing one will replace it.',
                                    'lingua-forge'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Upload Override', 'lingua-forge' ), 'secondary' ); ?>

            </form>

        </div>

        <style>
            /* ── Key status badges ─────────────────────────────────────── */
            .lingua-forge-key-badge {
                display: inline-block;
                margin-left: 8px;
                font-size: 12px;
                font-weight: 600;
                vertical-align: middle;
            }
            .lingua-forge-badge--ok      { color: #46b450; }
            .lingua-forge-badge--missing { color: #dc3232; }
            .lingua-forge-key-source {
                font-weight: 400;
                color: #646970;
            }

            /* ── Models table ──────────────────────────────────────────── */
            .lingua-forge-models-table {
                border-collapse: collapse;
                width: 100%;
                max-width: 860px;
            }
            .lingua-forge-models-table thead th {
                padding: 8px 10px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #dcdcde;
                vertical-align: bottom;
            }
            .lingua-forge-models-table tbody tr th,
            .lingua-forge-models-table tbody tr td {
                padding: 10px 10px;
                border-bottom: 1px solid #f0f0f1;
                vertical-align: middle;
            }
            .lingua-forge-active-provider-row {
                background: #f0f6fc;
            }
            .lingua-forge-active-provider-row th {
                font-weight: 600;
            }
            .lingua-forge-active-badge {
                display: inline-block;
                margin-left: 6px;
                padding: 1px 7px;
                border-radius: 10px;
                background: #0073aa;
                color: #fff;
                font-size: 11px;
                font-weight: 600;
                letter-spacing: 0.03em;
                vertical-align: middle;
            }
            .lingua-forge-tier-used-by {
                display: block;
                font-size: 11px;
                font-weight: 400;
                color: #646970;
                margin-top: 2px;
            }
            .lingua-forge-model-input {
                font-family: monospace;
                font-size: 12px;
                width: 100%;
                max-width: 340px;
            }
            .lingua-forge-model-override-badge {
                display: inline-block;
                margin-left: 6px;
                padding: 1px 6px;
                border-radius: 3px;
                background: #fff8e5;
                color: #996800;
                border: 1px solid #f0c33c;
                font-size: 11px;
                font-weight: 600;
                vertical-align: middle;
            }

            /* ── Security note ─────────────────────────────────────────── */
            .lingua-forge-settings-note {
                background: #f6f7f7;
                border-left: 4px solid #c3c4c7;
                padding: 12px 16px;
                margin: 20px 0;
                max-width: 600px;
            }
            .lingua-forge-settings-note p {
                margin: 6px 0;
            }
            .lingua-forge-code-sample {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 8px 12px;
                font-size: 12px;
                margin: 6px 0 10px;
                overflow-x: auto;
            }
        </style>
        <?php
    }
}
