<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\DebugFilesPanel
 *
 * Renders the Debug Files section on the Maintenance tab: a toggle for
 * LINGUAFORGE_AI_DEBUG logging and a clear-files button.
 *
 * All data reads delegate to TranslationDebug static methods.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.1.9
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\TranslationDebug;

defined( 'ABSPATH' ) || exit;

class DebugFilesPanel {

    // =========================================================================
    // Render
    // =========================================================================

    public static function render(): void {

        $debug_enabled       = TranslationDebug::debug_enabled();
        $debug_dir           = TranslationDebug::debug_dir();
        $debug_count         = TranslationDebug::debug_file_count();
        $debug_const_defined = TranslationDebug::debug_constant_defined();
        $debug_const_value   = TranslationDebug::debug_constant_value();
        $debug_option_state  = (bool) get_option( 'linguaforge_ai_debug_enabled', false );

        ?>
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the clear action.
        if ( isset( $_GET['lf_debug_cleared'] ) ) :
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $removed = absint( $_GET['lf_debug_cleared'] );
            ?>
            <div class="notice notice-success is-dismissible">
                <?php /* translators: %d: number of debug files removed. */ ?>
                <p><?php echo esc_html( sprintf( _n(
                    'Debug files cleared. %d file was removed.',
                    'Debug files cleared. %d files were removed.',
                    $removed, 'lingua-forge'
                ), $removed ) ); ?></p>
            </div>
        <?php endif; ?>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag.
        if ( isset( $_GET['lf_debug_setting_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    if ( sanitize_key( wp_unslash( $_GET['lf_debug_setting_saved'] ) ) === '1' ) {
                        esc_html_e( 'Debug logging enabled.', 'lingua-forge' );
                    } else {
                        esc_html_e( 'Debug logging disabled.', 'lingua-forge' );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="linguaforge_save_debug_setting">
            <?php wp_nonce_field( 'linguaforge_save_debug_setting', 'linguaforge_save_debug_setting_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Debug logging', 'lingua-forge' ); ?></th>
                    <td>
                        <?php if ( $debug_const_defined ) : ?>
                            <label>
                                <input type="checkbox" disabled <?php checked( (bool) $debug_const_value ); ?>>
                                <?php esc_html_e( 'Write AI prompts and responses to disk for troubleshooting', 'lingua-forge' ); ?>
                            </label>
                            <p class="description">
                                <?php
                                if ( $debug_const_value ) {
                                    esc_html_e( 'Forced ON by the LINGUAFORGE_AI_DEBUG constant in wp-config.php. Remove that line to control this toggle from here.', 'lingua-forge' );
                                } else {
                                    esc_html_e( 'Forced OFF by the LINGUAFORGE_AI_DEBUG constant in wp-config.php. Remove that line to control this toggle from here.', 'lingua-forge' );
                                }
                                ?>
                            </p>
                        <?php else : ?>
                            <label>
                                <input type="checkbox" name="linguaforge_ai_debug_enabled" value="1" <?php checked( $debug_option_state ); ?>>
                                <?php esc_html_e( 'Write AI prompts and responses to disk for troubleshooting', 'lingua-forge' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Files land in the directory below. Useful for diagnosing translation issues — turn off once you have what you need so the files do not accumulate. You can also force this from wp-config.php with `define( \'LINGUAFORGE_AI_DEBUG\', true );` which overrides the toggle.', 'lingua-forge' ); ?>
                            </p>
                        <?php endif; ?>

                        <p>
                            <strong><?php esc_html_e( 'Currently:', 'lingua-forge' ); ?></strong>
                            <?php if ( $debug_enabled ) : ?>
                                <span class="lingua-forge-key-badge lingua-forge-badge--ok"><?php esc_html_e( '✓ Enabled', 'lingua-forge' ); ?></span>
                            <?php else : ?>
                                <span class="lingua-forge-key-badge lingua-forge-badge--missing"><?php esc_html_e( '✗ Disabled', 'lingua-forge' ); ?></span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Directory', 'lingua-forge' ); ?></th>
                    <td>
                        <code><?php echo esc_html( $debug_dir ); ?></code>
                        <p class="description"><?php esc_html_e( 'Filter with linguaforge_debug_dir to redirect debug output to a non-public location.', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Files', 'lingua-forge' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( number_format_i18n( $debug_count ) ); ?></strong>
                        <?php esc_html_e( '.txt file(s) in the directory', 'lingua-forge' ); ?>
                    </td>
                </tr>
            </table>

            <?php if ( ! $debug_const_defined ) : ?>
                <?php submit_button( __( 'Save Debug Setting', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
            <?php endif; ?>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('<?php echo esc_js( __( 'Delete all .txt files in the debug directory? The directory itself will remain so future debug writes still land cleanly.', 'lingua-forge' ) ); ?>');">
            <input type="hidden" name="action" value="linguaforge_clear_debug_files">
            <?php wp_nonce_field( 'linguaforge_clear_debug_files', 'linguaforge_clear_debug_files_nonce' ); ?>
            <?php submit_button(
                __( 'Clear Debug Files', 'lingua-forge' ),
                'secondary', 'submit', false,
                $debug_count > 0 ? [] : [ 'disabled' => 'disabled' ]
            ); ?>
        </form>
        <?php
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    public static function handle_clear_debug_files(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_clear_debug_files', 'linguaforge_clear_debug_files_nonce' );

        $count = TranslationDebug::clear_debug_files();

        wp_safe_redirect( add_query_arg(
            'lf_debug_cleared',
            (int) $count,
            admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
        ) );
        exit;
    }

    public static function handle_save_debug_setting(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_save_debug_setting', 'linguaforge_save_debug_setting_nonce' );

        $enabled = ! empty( $_POST['linguaforge_ai_debug_enabled'] );
        update_option( 'linguaforge_ai_debug_enabled', $enabled ? 1 : 0, false );

        wp_safe_redirect( add_query_arg(
            'lf_debug_setting_saved',
            $enabled ? '1' : '0',
            admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
        ) );
        exit;
    }
}
