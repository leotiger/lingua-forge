<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\TranslationDebug;
use LinguaForge\AI\Core\TranslationMemory;

defined('ABSPATH') || exit;

/**
 * Settings tab: Maintenance
 *
 * Language override file management, AI cache, debug file controls, and
 * Translation Memory statistics and clear button.
 *
 * This tab uses its own admin-post actions rather than the shared settings
 * form, so save() is not implemented.
 */
class MaintenanceTab extends Tab {

    public static function slug(): string {
        return 'maintenance';
    }

    public static function label(): string {
        return __( 'Maintenance', 'lingua-forge' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Absolute path to the uploads-based i18n overrides directory.
     * Matches the path used by \LinguaForge\Router\Router::i18n_overrides_dir().
     *
     * @return string  Trailing-slash path.
     */
    private static function overrides_dir(): string {

        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides/';
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        ?>
        <!-- ── Language Overrides ──────────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e('Language Overrides', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Upload compiled .mo files to override third-party plugin strings for specific locales — for example, a custom VikBooking translation that uses "apartment" instead of "room". Files must follow the WordPress naming convention: {textdomain}-{locale}.mo (e.g. vikbooking-ca.mo). They are stored in the uploads folder and survive plugin updates.', 'lingua-forge' );
            ?>
        </p>

        <?php
        // ── Feedback notices ─────────────────────────────────────────────
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flags set by wp_safe_redirect() after upload/delete actions; no data is modified here.
        if (!empty($_GET['lf_override_uploaded'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Override file uploaded successfully.', 'lingua-forge'); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif (!empty($_GET['lf_override_deleted'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Override file deleted.', 'lingua-forge'); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif (!empty($_GET['lf_override_error'])):
            $error_map = [
                'empty'        => __('No file was selected.', 'lingua-forge'),
                'invalid_type' => __('Only .mo files are accepted.', 'lingua-forge'),
                'upload_error' => __('The upload failed — please try again.', 'lingua-forge'),
                'move_failed'  => __('Could not save the file. Check that the uploads folder is writable.', 'lingua-forge'),
                'invalid_file' => __('Invalid filename.', 'lingua-forge'),
                'invalid_path' => __('Security check failed — file path is not permitted.', 'lingua-forge'),
            ];
            $error_key = sanitize_key( wp_unslash( $_GET['lf_override_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- value is used only as a lookup key in a hardcoded error-message map; no nonce is meaningful for a redirect-back GET param.
            $error_msg = $error_map[$error_key] ?? __('An unknown error occurred.', 'lingua-forge');
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($error_msg); ?></p>
            </div>
        <?php endif; ?>

        <!-- ── Current override files ────────────────────────────────── -->
        <?php
        $dir      = self::overrides_dir();
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
                            <td><?php echo wp_kses( implode( ' ', $badges ), [ 'code' => [] ] ); ?></td>
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
                            esc_html_e( 'Accepts compiled .mo files only. Filename must follow the pattern {textdomain}-{locale}.mo. Uploading a file with the same name as an existing one will replace it.', 'lingua-forge' );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Upload Override', 'lingua-forge' ), 'secondary' ); ?>

        </form>

        <!-- ── AI Cache ─────────────────────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e( 'AI Cache', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Lingua Forge caches AI-generated translations, meta descriptions, excerpts, and generated content per-post so unchanged inputs do not re-trigger a paid API call. Cached entries are automatically invalidated when their inputs change. Clear the cache manually to reclaim database space, force a resync after switching providers or editing prompt templates, or troubleshoot a cache-related issue.',
                'lingua-forge'
            );
            ?>
        </p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the clear action; no data is modified here.
        if ( isset( $_GET['lf_cache_cleared'] ) ) :
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only GET flag; absint() bounds it.
            $linguaforge_cleared_count = absint( $_GET['lf_cache_cleared'] );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d is the number of cleared cache entries. */
                        _n(
                            'AI cache cleared. %d entry was removed.',
                            'AI cache cleared. %d entries were removed.',
                            $linguaforge_cleared_count,
                            'lingua-forge'
                        ),
                        $linguaforge_cleared_count
                    ) );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            onsubmit="return confirm('<?php echo esc_js( __( 'Clear all cached AI results? Future requests will trigger fresh API calls until the cache rebuilds.', 'lingua-forge' ) ); ?>');"
        >
            <input type="hidden" name="action" value="linguaforge_clear_ai_cache">
            <?php wp_nonce_field( 'linguaforge_clear_ai_cache', 'linguaforge_clear_ai_cache_nonce' ); ?>

            <?php submit_button(
                __( 'Clear AI Cache', 'lingua-forge' ),
                'secondary',
                'submit',
                false
            ); ?>
        </form>

        <!-- ── Debug Files ─────────────────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e( 'Debug Files', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'When debug logging is enabled (via the toggle below or by defining LINGUAFORGE_AI_DEBUG in wp-config.php), the Translation and FSE template translation features write their raw AI prompts and responses to disk for troubleshooting. Use this section to monitor that output and clear it once you have what you need — the files can grow quickly on large pages. Configure the destination directory via the linguaforge_debug_dir filter.',
                'lingua-forge'
            );
            ?>
        </p>

        <?php
        $linguaforge_debug_enabled       = TranslationDebug::debug_enabled();
        $linguaforge_debug_dir           = TranslationDebug::debug_dir();
        $linguaforge_debug_count         = TranslationDebug::debug_file_count();
        $linguaforge_debug_const_defined = TranslationDebug::debug_constant_defined();
        $linguaforge_debug_const_value   = TranslationDebug::debug_constant_value();
        $linguaforge_debug_option_state  = (bool) get_option('linguaforge_ai_debug_enabled', false);
        ?>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the clear action.
        if ( isset( $_GET['lf_debug_cleared'] ) ) :
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only flag; absint() bounds it.
            $linguaforge_debug_removed = absint( $_GET['lf_debug_cleared'] );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d is the number of removed debug files. */
                        _n(
                            'Debug files cleared. %d file was removed.',
                            'Debug files cleared. %d files were removed.',
                            $linguaforge_debug_removed,
                            'lingua-forge'
                        ),
                        $linguaforge_debug_removed
                    ) );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the toggle save action.
        if ( isset( $_GET['lf_debug_setting_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag from settings save handler; no data is modified.
                    if ( sanitize_key( wp_unslash( $_GET['lf_debug_setting_saved'] ) ) === '1' ) {
                        esc_html_e( 'Debug logging enabled.', 'lingua-forge' );
                    } else {
                        esc_html_e( 'Debug logging disabled.', 'lingua-forge' );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
        >
            <input type="hidden" name="action" value="linguaforge_save_debug_setting">
            <?php wp_nonce_field( 'linguaforge_save_debug_setting', 'linguaforge_save_debug_setting_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Debug logging', 'lingua-forge' ); ?></th>
                    <td>
                        <?php if ( $linguaforge_debug_const_defined ) : ?>

                            <?php // Constant in wp-config.php overrides — show the locked state. ?>
                            <label>
                                <input
                                    type="checkbox"
                                    disabled
                                    <?php checked( (bool) $linguaforge_debug_const_value ); ?>
                                >
                                <?php esc_html_e( 'Write AI prompts and responses to disk for troubleshooting', 'lingua-forge' ); ?>
                            </label>
                            <p class="description">
                                <?php
                                if ( $linguaforge_debug_const_value ) {
                                    esc_html_e( 'Forced ON by the LINGUAFORGE_AI_DEBUG constant in wp-config.php. Remove that line to control this toggle from here.', 'lingua-forge' );
                                } else {
                                    esc_html_e( 'Forced OFF by the LINGUAFORGE_AI_DEBUG constant in wp-config.php. Remove that line to control this toggle from here.', 'lingua-forge' );
                                }
                                ?>
                            </p>

                        <?php else : ?>

                            <label>
                                <input
                                    type="checkbox"
                                    name="linguaforge_ai_debug_enabled"
                                    value="1"
                                    <?php checked( $linguaforge_debug_option_state ); ?>
                                >
                                <?php esc_html_e( 'Write AI prompts and responses to disk for troubleshooting', 'lingua-forge' ); ?>
                            </label>
                            <p class="description">
                                <?php
                                esc_html_e( 'Files land in the directory below. Useful for diagnosing translation issues — turn off once you have what you need so the files do not accumulate. You can also force this from wp-config.php with `define( \'LINGUAFORGE_AI_DEBUG\', true );` which overrides the toggle.', 'lingua-forge' );
                                ?>
                            </p>

                        <?php endif; ?>

                        <p>
                            <strong><?php esc_html_e( 'Currently:', 'lingua-forge' ); ?></strong>
                            <?php if ( $linguaforge_debug_enabled ) : ?>
                                <span class="lingua-forge-key-badge lingua-forge-badge--ok">
                                    <?php esc_html_e( '✓ Enabled', 'lingua-forge' ); ?>
                                </span>
                            <?php else : ?>
                                <span class="lingua-forge-key-badge lingua-forge-badge--missing">
                                    <?php esc_html_e( '✗ Disabled', 'lingua-forge' ); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Directory', 'lingua-forge' ); ?></th>
                    <td>
                        <code><?php echo esc_html( $linguaforge_debug_dir ); ?></code>
                        <p class="description">
                            <?php esc_html_e( 'Filter with linguaforge_debug_dir to redirect debug output to a non-public location.', 'lingua-forge' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Files', 'lingua-forge' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( number_format_i18n( $linguaforge_debug_count ) ); ?></strong>
                        <?php esc_html_e( '.txt file(s) in the directory', 'lingua-forge' ); ?>
                    </td>
                </tr>
            </table>

            <?php if ( ! $linguaforge_debug_const_defined ) : ?>
                <?php submit_button(
                    __( 'Save Debug Setting', 'lingua-forge' ),
                    'secondary',
                    'submit',
                    false
                ); ?>
            <?php endif; ?>
        </form>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            onsubmit="return confirm('<?php echo esc_js( __( 'Delete all .txt files in the debug directory? The directory itself will remain so future debug writes still land cleanly.', 'lingua-forge' ) ); ?>');"
        >
            <input type="hidden" name="action" value="linguaforge_clear_debug_files">
            <?php wp_nonce_field( 'linguaforge_clear_debug_files', 'linguaforge_clear_debug_files_nonce' ); ?>

            <?php submit_button(
                __( 'Clear Debug Files', 'lingua-forge' ),
                'secondary',
                'submit',
                false,
                $linguaforge_debug_count > 0 ? [] : ['disabled' => 'disabled']
            ); ?>
        </form>

        <!-- ── Translation Memory ──────────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e( 'Translation Memory', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Per-block translation cache shared across posts. Configure on/off in Settings → Behavior. Stats below show what is currently cached; clearing forces every block to be re-translated on next request (useful after upgrading models or to recover database space).',
                'lingua-forge'
            );
            ?>
        </p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect after the clear action.
        if ( isset( $_GET['lf_tm_cleared'] ) ) :
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only flag; absint() bounds it.
            $linguaforge_tm_removed = absint( $_GET['lf_tm_cleared'] );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d is the number of removed cached blocks. */
                        _n(
                            'Translation Memory cleared. %d cached block was removed.',
                            'Translation Memory cleared. %d cached blocks were removed.',
                            $linguaforge_tm_removed,
                            'lingua-forge'
                        ),
                        $linguaforge_tm_removed
                    ) );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <?php
        $linguaforge_tm_enabled = (bool) get_option( 'linguaforge_translation_memory_enabled', false );
        $linguaforge_tm_stats   = TranslationMemory::stats();
        ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Status', 'lingua-forge' ); ?></th>
                <td>
                    <?php if ( $linguaforge_tm_enabled ) : ?>
                        <span class="lingua-forge-key-badge lingua-forge-badge--ok">
                            <?php esc_html_e( '✓ Enabled', 'lingua-forge' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="lingua-forge-key-badge lingua-forge-badge--missing">
                            <?php esc_html_e( '✗ Disabled', 'lingua-forge' ); ?>
                        </span>
                        <?php esc_html_e( '— toggle in Settings → Behavior.', 'lingua-forge' ); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Cached blocks', 'lingua-forge' ); ?></th>
                <td>
                    <strong><?php echo esc_html( number_format_i18n( $linguaforge_tm_stats['rows'] ) ); ?></strong>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Cumulative cache hits', 'lingua-forge' ); ?></th>
                <td>
                    <strong><?php echo esc_html( number_format_i18n( $linguaforge_tm_stats['total_hits'] ) ); ?></strong>
                    <?php
                    if ( $linguaforge_tm_stats['rows'] > 0 ) {
                        $avg = $linguaforge_tm_stats['total_hits'] / $linguaforge_tm_stats['rows'];
                        echo ' <span style="color:#646970">' . esc_html( sprintf(
                            /* translators: %s is the average hits per cached block. */
                            __( '(avg %.1f hits/block)', 'lingua-forge' ),
                            $avg
                        ) ) . '</span>';
                    }
                    ?>
                </td>
            </tr>
            <?php if ( $linguaforge_tm_stats['oldest'] !== '' ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Oldest entry', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( $linguaforge_tm_stats['oldest'] ); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Newest entry', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( $linguaforge_tm_stats['newest'] ); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Approximate size', 'lingua-forge' ); ?></th>
                <td>
                    <?php
                    echo esc_html( size_format( $linguaforge_tm_stats['bytes_estimate'] ) ?: '0 B' );
                    ?>
                </td>
            </tr>
        </table>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire Translation Memory? Future translations will rebuild the cache as they run.', 'lingua-forge' ) ); ?>');"
        >
            <input type="hidden" name="action" value="linguaforge_clear_translation_memory">
            <?php wp_nonce_field( 'linguaforge_clear_translation_memory', 'linguaforge_clear_tm_nonce' ); ?>

            <?php submit_button(
                __( 'Clear Translation Memory', 'lingua-forge' ),
                'secondary',
                'submit',
                false,
                $linguaforge_tm_stats['rows'] > 0 ? [] : ['disabled' => 'disabled']
            ); ?>
        </form>

        <!-- ── Uninstall Behaviour ──────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e( 'Uninstall Behaviour', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Controls what happens when an administrator deletes the plugin from Plugins → Installed Plugins → Delete. Plugin settings and AI caches are always removed. Language assignments and translation relationships are kept by default so an accidental uninstall or a reinstall can pick up where it left off.',
                'lingua-forge'
            );
            ?>
        </p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the save action.
        if ( isset( $_GET['lf_uninstall_setting_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Uninstall setting saved.', 'lingua-forge' ); ?></p>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
        >
            <input type="hidden" name="action" value="linguaforge_save_uninstall_setting">
            <?php wp_nonce_field( 'linguaforge_save_uninstall_setting', 'linguaforge_uninstall_setting_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Delete content data on uninstall', 'lingua-forge' ); ?></th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                name="linguaforge_remove_content_on_uninstall"
                                value="1"
                                <?php checked( (bool) get_option( 'linguaforge_remove_content_on_uninstall', false ) ); ?>
                            >
                            <?php esc_html_e( 'Also delete language assignments, translation relationships, meta descriptions, glossary, and Translation Memory when the plugin is uninstalled', 'lingua-forge' ); ?>
                        </label>
                        <p class="description" style="color:#d63638; margin-top:6px;">
                            <strong><?php esc_html_e( 'Warning:', 'lingua-forge' ); ?></strong>
                            <?php
                            esc_html_e(
                                'If checked, uninstalling the plugin will permanently delete all language assignments, translation relationships, meta descriptions, per-page presets, the AI glossary, and Translation Memory for every post on this site. This cannot be undone. Leave unchecked unless you are fully removing multilingual support from the site.',
                                'lingua-forge'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(
                __( 'Save Uninstall Setting', 'lingua-forge' ),
                'secondary',
                'submit',
                false
            ); ?>

        </form>
        <?php
    }

    // ── Language override handlers ────────────────────────────────────────────

    public static function handle_upload_override(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_upload_override', 'linguaforge_override_nonce');

        $redirect_base = admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG);

        // No file submitted
        if (empty($_FILES['linguaforge_mo_file']['name'])) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'empty', $redirect_base));
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES array is passed directly to wp_handle_upload() which performs its own validation.
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

        $dir = self::overrides_dir();

        wp_mkdir_p($dir);

        // Redirect wp_handle_upload() to our override directory and preserve the
        // exact filename so the {textdomain}-{locale}.mo convention is maintained.
        $upload_dir_cb = static function ( $dirs ) use ( $dir ) {
            $dirs['path']   = untrailingslashit( $dir );
            $dirs['url']    = '';
            $dirs['subdir'] = '';
            return $dirs;
        };

        // Register .mo as an allowed upload type so wp_handle_upload() can run
        // its MIME-magic check (test_type: true, the default). Without this,
        // WordPress would reject .mo as an unrecognised type. The check verifies
        // that the uploaded bytes match application/octet-stream — a PHP file or
        // image renamed to .mo would produce a different MIME and be rejected.
        $upload_mimes_cb = static function ( $mimes ) {
            $mimes['mo'] = 'application/octet-stream';
            return $mimes;
        };

        add_filter( 'upload_mimes', $upload_mimes_cb );
        add_filter( 'upload_dir',   $upload_dir_cb );

        $uploaded = wp_handle_upload(
            $file,
            [
                'test_form'                => false, // nonce already verified via check_admin_referer
                'unique_filename_callback' => static fn( $d, $n, $e ) => $n, // keep exact name
            ]
        );

        remove_filter( 'upload_mimes', $upload_mimes_cb );
        remove_filter( 'upload_dir',   $upload_dir_cb );

        if ( isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'move_failed', $redirect_base));
            exit;
        }

        // A new .mo override may introduce a previously-unknown locale to
        // \LinguaForge\Router\Router::languages(), which in turn feeds the rewrite rule
        // set built on init. Mark the rules dirty so the init-priority-99 hook
        // in lingua-forge.php picks the flush up on the next request — without
        // this the new /xx/ URLs return 404 until Settings → Permalinks → Save.
        update_option( 'linguaforge_flush_rewrite_rules', true, false );

        wp_safe_redirect(add_query_arg('lf_override_uploaded', '1', $redirect_base));
        exit;
    }

    public static function handle_delete_override(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_delete_override', 'linguaforge_override_nonce');

        $redirect_base = admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG);

        $filename = sanitize_file_name( wp_unslash( $_POST['linguaforge_override_file'] ?? '' ) );

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

        // Deleting the last .mo for a discovered-only locale removes it from
        // \LinguaForge\Router\Router::languages(), so the rewrite-rule set must rebuild.
        // Same mechanism as on upload — defer to init-priority-99 in lingua-forge.php.
        update_option( 'linguaforge_flush_rewrite_rules', true, false );

        wp_safe_redirect(add_query_arg('lf_override_deleted', '1', $redirect_base));
        exit;
    }

    // ── AI cache maintenance ──────────────────────────────────────────────────

    /**
     * Empty the wp_lingua_forge_ai_cache table when an admin clicks
     * the "Clear AI cache" button.
     */
    public static function handle_clear_ai_cache(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_clear_ai_cache', 'linguaforge_clear_ai_cache_nonce');

        $count = CacheStore::clear_all();

        wp_safe_redirect(add_query_arg(
            'lf_cache_cleared',
            (int) $count,
            admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Empty the debug-file directory when an admin clicks
     * the "Clear debug files" button in Maintenance → Debug Files.
     */
    public static function handle_clear_debug_files(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_clear_debug_files', 'linguaforge_clear_debug_files_nonce');

        $count = TranslationDebug::clear_debug_files();

        wp_safe_redirect(add_query_arg(
            'lf_debug_cleared',
            (int) $count,
            admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Persist the on/off state of the debug toggle.
     */
    public static function handle_save_debug_setting(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_save_debug_setting', 'linguaforge_save_debug_setting_nonce');

        $enabled = !empty($_POST['linguaforge_ai_debug_enabled']);

        update_option('linguaforge_ai_debug_enabled', $enabled ? 1 : 0, false);

        wp_safe_redirect(add_query_arg(
            'lf_debug_setting_saved',
            $enabled ? '1' : '0',
            admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG)
        ));
        exit;
    }

    // ── Uninstall behaviour ───────────────────────────────────────────────────

    /**
     * Persist the on/off state of the "delete content data on uninstall" toggle.
     */
    public static function handle_save_uninstall_setting(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_save_uninstall_setting', 'linguaforge_uninstall_setting_nonce' );

        $enabled = ! empty( $_POST['linguaforge_remove_content_on_uninstall'] );

        update_option( 'linguaforge_remove_content_on_uninstall', $enabled ? 1 : 0, false );

        wp_safe_redirect( add_query_arg(
            'lf_uninstall_setting_saved',
            '1',
            admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
        ) . '#maintenance' );
        exit;
    }

    // ── Translation Memory maintenance ────────────────────────────────────────

    /**
     * Empty the Translation Memory table from the Maintenance tab.
     */
    public static function handle_clear_translation_memory(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_clear_translation_memory', 'linguaforge_clear_tm_nonce' );

        $count = TranslationMemory::clear_all();

        wp_safe_redirect( add_query_arg(
            'lf_tm_cleared',
            (int) $count,
            admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
        ) . '#maintenance' );
        exit;
    }
}
