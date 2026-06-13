<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\UninstallSettingsPanel
 *
 * Renders the Uninstall Behaviour section on the Maintenance tab and
 * handles the save action for the content-deletion toggle.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.1.9
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

class UninstallSettingsPanel {

    // =========================================================================
    // Render
    // =========================================================================

    public static function render(): void {

        ?>
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

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
                        <p class="description" style="color:#d63638;margin-top:6px;">
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

            <?php submit_button( __( 'Save Uninstall Setting', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
        </form>
        <?php
    }

    // =========================================================================
    // AJAX handler
    // =========================================================================

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
            admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
        ) . '#maintenance' );
        exit;
    }
}
