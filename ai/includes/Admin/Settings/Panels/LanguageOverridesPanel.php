<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\LanguageOverridesPanel
 *
 * Renders the Language Overrides section (upload/delete .mo files) and the
 * Loco Translate — Copy to Safe Storage sub-section on the Maintenance tab.
 *
 * Both sections share `overrides_dir()` — the path where .mo override files
 * are stored — which is why they live in the same panel class.
 *
 * The three filesystem helpers (`overrides_dir`, `loco_is_active`,
 * `loco_custom_files`) are `public static` so unit tests can call them
 * directly with a controlled temp directory.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.1.9
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

class LanguageOverridesPanel {

    // =========================================================================
    // Filesystem helpers (public for testability)
    // =========================================================================

    /**
     * Absolute path to the uploads-based i18n overrides directory.
     * Matches the path used by Router::i18n_overrides_dir().
     *
     * @return string Trailing-slash path.
     */
    public static function overrides_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides/';
    }

    /**
     * Whether Loco Translate is currently active.
     * Uses the function marker defined in loco.php rather than is_plugin_active().
     */
    public static function loco_is_active(): bool {
        return function_exists( 'loco_plugin_version' );
    }

    /**
     * List .mo files that Loco Translate has saved in its custom directory
     * (wp-content/languages/loco/plugins/ and …/themes/).
     *
     * Returns an empty array when LOCO_LANG_DIR is not defined (Loco inactive).
     *
     * @return list<array{type:string,base:string,mo_path:string,has_po:bool,po_path:string,in_overrides:bool,size:string}>
     */
    public static function loco_custom_files(): array {

        if ( ! defined( 'LOCO_LANG_DIR' ) || ! LOCO_LANG_DIR ) {
            return [];
        }

        $loco_root     = trailingslashit( LOCO_LANG_DIR );
        $overrides_dir = self::overrides_dir();
        $files         = [];

        foreach ( [ 'plugins', 'themes' ] as $type ) {
            $dir = $loco_root . $type . '/';
            foreach ( glob( $dir . '*.mo' ) ?: [] as $path ) {
                $base    = pathinfo( $path, PATHINFO_FILENAME );
                $po_path = $dir . $base . '.po';
                $files[] = [
                    'type'         => $type,
                    'base'         => $base,
                    'mo_path'      => $path,
                    'has_po'       => file_exists( $po_path ),
                    'po_path'      => $po_path,
                    'in_overrides' => file_exists( $overrides_dir . $base . '.mo' ),
                    'size'         => size_format( (int) filesize( $path ) ),
                ];
            }
        }

        usort( $files, static fn( $a, $b ) => strcmp( $a['base'], $b['base'] ) );

        return $files;
    }

    // =========================================================================
    // Render
    // =========================================================================

    public static function render(): void {

        ?>
        <!-- ── Language Overrides ──────────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e( 'Language Overrides', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e( 'Upload compiled .mo files to override third-party plugin strings for specific locales — for example, a custom VikBooking translation that uses "apartment" instead of "room". Files must follow the WordPress naming convention: {textdomain}-{locale}.mo (e.g. vikbooking-ca.mo). They are stored in the uploads folder and survive plugin updates.', 'lingua-forge' );
            ?>
        </p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flags set by wp_safe_redirect() after upload/delete actions; no data is modified here.
        if ( ! empty( $_GET['lf_override_uploaded'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Override file uploaded successfully.', 'lingua-forge' ); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( ! empty( $_GET['lf_override_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Override file deleted.', 'lingua-forge' ); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( ! empty( $_GET['lf_override_error'] ) ) :
            $error_map = [
                'empty'        => __( 'No file was selected.', 'lingua-forge' ),
                'invalid_type' => __( 'Only .mo files are accepted.', 'lingua-forge' ),
                'upload_error' => __( 'The upload failed — please try again.', 'lingua-forge' ),
                'move_failed'  => __( 'Could not save the file. Check that the uploads folder is writable.', 'lingua-forge' ),
                'invalid_file' => __( 'Invalid filename.', 'lingua-forge' ),
                'invalid_path' => __( 'Security check failed — file path is not permitted.', 'lingua-forge' ),
            ];
            $error_key = sanitize_key( wp_unslash( $_GET['lf_override_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $error_msg = $error_map[ $error_key ] ?? __( 'An unknown error occurred.', 'lingua-forge' );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( $error_msg ); ?></p>
            </div>
        <?php endif; ?>

        <?php
        $dir      = self::overrides_dir();
        $mo_files = is_readable( $dir ) ? array_map( 'basename', glob( $dir . '*.mo' ) ?: [] ) : [];
        $po_files = is_readable( $dir ) ? array_map( 'basename', glob( $dir . '*.po' ) ?: [] ) : [];
        $all_bases = array_unique( array_merge(
            array_map( fn( $f ) => pathinfo( $f, PATHINFO_FILENAME ), $mo_files ),
            array_map( fn( $f ) => pathinfo( $f, PATHINFO_FILENAME ), $po_files )
        ) );
        sort( $all_bases );
        ?>

        <?php if ( ! empty( $all_bases ) ) : ?>

            <div class="lf-scrollable-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Text domain / locale', 'lingua-forge' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'lingua-forge' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'lingua-forge' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_bases as $base ) :
                            $has_mo  = in_array( $base . '.mo', $mo_files, true );
                            $has_po  = in_array( $base . '.po', $po_files, true );
                            $mo_path = $dir . $base . '.mo';
                            $size    = $has_mo ? size_format( filesize( $mo_path ) ) : '—';
                            $badges  = [];
                            if ( $has_mo ) $badges[] = '<code>.mo</code>';
                            if ( $has_po ) $badges[] = '<code>.po</code>';
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $base ); ?></code></td>
                                <td><?php echo wp_kses( implode( ' ', $badges ), [ 'code' => [] ] ); ?></td>
                                <td><?php echo esc_html( $size ); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                                          onsubmit="return confirm('<?php echo esc_js( __( 'Delete all files for this override (both .mo and .po)?', 'lingua-forge' ) ); ?>')">
                                        <input type="hidden" name="action" value="linguaforge_delete_i18n_override">
                                        <input type="hidden" name="linguaforge_override_file" value="<?php echo esc_attr( $base . '.mo' ); ?>">
                                        <?php wp_nonce_field( 'linguaforge_delete_override', 'linguaforge_override_nonce' ); ?>
                                        <button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'lingua-forge' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else : ?>
            <p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'No override files uploaded yet.', 'lingua-forge' ); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="linguaforge_upload_i18n_override">
            <?php wp_nonce_field( 'linguaforge_upload_override', 'linguaforge_override_nonce' ); ?>
            <table class="form-table" role="presentation" style="max-width:680px;">
                <tr>
                    <th scope="row"><label for="linguaforge_mo_file"><?php esc_html_e( 'Upload .mo file', 'lingua-forge' ); ?></label></th>
                    <td>
                        <input type="file" id="linguaforge_mo_file" name="linguaforge_mo_file" accept=".mo">
                        <p class="description"><?php esc_html_e( 'Accepts compiled .mo files only. Filename must follow the pattern {textdomain}-{locale}.mo. Uploading a file with the same name as an existing one will replace it.', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Upload Override', 'lingua-forge' ), 'secondary' ); ?>
        </form>

        <!-- ── Loco Translate ───────────────────────────────────── -->
        <?php if ( self::loco_is_active() ) :
            $lf_loco_files = self::loco_custom_files();
        ?>

        <h3><?php esc_html_e( 'Loco Translate — Copy to Safe Storage', 'lingua-forge' ); ?></h3>
        <p><?php esc_html_e( 'Loco Translate stores its custom translations in wp-content/languages/loco/ — a location that can be silently wiped by a WP core update, plugin reinstall, or if Loco Translate itself is removed. Copying files here moves them into the Lingua Forge i18n-overrides directory, which persists through all of those events and is loaded automatically on every request.', 'lingua-forge' ); ?></p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag.
        if ( ! empty( $_GET['lf_loco_copied'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'File copied to safe storage successfully.', 'lingua-forge' ); ?></p></div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( ! empty( $_GET['lf_loco_error'] ) ) :
            $loco_error_map = [
                'not_found'   => __( 'Source file not found.', 'lingua-forge' ),
                'copy_failed' => __( 'Could not copy the file. Check that the uploads folder is writable.', 'lingua-forge' ),
                'invalid'     => __( 'Invalid file reference.', 'lingua-forge' ),
            ];
            $loco_error_key = sanitize_key( wp_unslash( $_GET['lf_loco_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $loco_error_map[ $loco_error_key ] ?? __( 'An unknown error occurred.', 'lingua-forge' ) ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! empty( $lf_loco_files ) ) : ?>
            <div class="lf-scrollable-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Text domain / locale', 'lingua-forge' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'lingua-forge' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'lingua-forge' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'lingua-forge' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $lf_loco_files as $lf ) :
                            $loco_badges = [ '<code>.mo</code>' ];
                            if ( $lf['has_po'] ) $loco_badges[] = '<code>.po</code>';
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $lf['base'] ); ?></code></td>
                                <td><?php echo esc_html( $lf['type'] ); ?></td>
                                <td><?php echo wp_kses( implode( ' ', $loco_badges ), [ 'code' => [] ] ); ?></td>
                                <td><?php echo esc_html( $lf['size'] ); ?></td>
                                <td>
                                    <?php if ( $lf['in_overrides'] ) : ?>
                                        <span class="lingua-forge-key-badge lingua-forge-badge--ok"><?php esc_html_e( '✓ In safe storage', 'lingua-forge' ); ?></span>
                                    <?php else : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="linguaforge_copy_loco_override">
                                            <input type="hidden" name="linguaforge_loco_base" value="<?php echo esc_attr( $lf['base'] ); ?>">
                                            <input type="hidden" name="linguaforge_loco_type" value="<?php echo esc_attr( $lf['type'] ); ?>">
                                            <?php wp_nonce_field( 'linguaforge_copy_loco', 'linguaforge_loco_nonce' ); ?>
                                            <button type="submit" class="button button-secondary"><?php esc_html_e( 'Copy to safe storage', 'lingua-forge' ); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'No custom Loco Translate files found yet. They appear here once you save a translation in Loco Translate.', 'lingua-forge' ); ?></p>
        <?php endif; ?>

        <?php endif; // loco_is_active ?>
        <?php
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    public static function handle_copy_loco_override(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_copy_loco', 'linguaforge_loco_nonce' );

        $redirect_base = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG );
        $base          = sanitize_file_name( wp_unslash( $_POST['linguaforge_loco_base'] ?? '' ) );
        $type          = sanitize_key( wp_unslash( $_POST['linguaforge_loco_type'] ?? '' ) );

        if ( $base === '' || ! in_array( $type, [ 'plugins', 'themes' ], true ) ) {
            wp_safe_redirect( add_query_arg( 'lf_loco_error', 'invalid', $redirect_base ) . '#maintenance' );
            exit;
        }

        if ( ! defined( 'LOCO_LANG_DIR' ) || ! LOCO_LANG_DIR ) {
            wp_safe_redirect( add_query_arg( 'lf_loco_error', 'not_found', $redirect_base ) . '#maintenance' );
            exit;
        }

        $loco_dir      = trailingslashit( LOCO_LANG_DIR ) . $type . '/';
        $overrides_dir = self::overrides_dir();

        wp_mkdir_p( $overrides_dir );

        $real_loco_dir = realpath( $loco_dir );
        if ( $real_loco_dir === false ) {
            wp_safe_redirect( add_query_arg( 'lf_loco_error', 'not_found', $redirect_base ) . '#maintenance' );
            exit;
        }

        $copied = false;
        foreach ( [ 'mo', 'po' ] as $ext ) {
            $src      = $loco_dir . $base . '.' . $ext;
            $real_src = realpath( $src );
            if ( $real_src === false ) continue;
            if ( strpos( $real_src, $real_loco_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
                wp_safe_redirect( add_query_arg( 'lf_loco_error', 'invalid', $redirect_base ) . '#maintenance' );
                exit;
            }
            if ( copy( $src, $overrides_dir . $base . '.' . $ext ) ) {
                $copied = true;
            }
        }

        if ( ! $copied ) {
            wp_safe_redirect( add_query_arg( 'lf_loco_error', 'copy_failed', $redirect_base ) . '#maintenance' );
            exit;
        }

        update_option( 'linguaforge_flush_rewrite_rules', true, false );
        wp_safe_redirect( add_query_arg( 'lf_loco_copied', '1', $redirect_base ) . '#maintenance' );
        exit;
    }

    public static function handle_upload_override(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_upload_override', 'linguaforge_override_nonce' );

        $redirect_base = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG );

        if ( empty( $_FILES['linguaforge_mo_file']['name'] ) ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'empty', $redirect_base ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to wp_handle_upload which validates internally.
        $file = $_FILES['linguaforge_mo_file'];
        $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( $ext !== 'mo' ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'invalid_type', $redirect_base ) );
            exit;
        }

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'upload_error', $redirect_base ) );
            exit;
        }

        $dir = self::overrides_dir();
        wp_mkdir_p( $dir );

        $upload_dir_cb   = static function ( $dirs ) use ( $dir ) {
            $dirs['path'] = untrailingslashit( $dir );
            $dirs['url']  = '';
            $dirs['subdir'] = '';
            return $dirs;
        };
        $upload_mimes_cb = static function ( $mimes ) {
            $mimes['mo'] = 'application/octet-stream';
            return $mimes;
        };

        add_filter( 'upload_mimes', $upload_mimes_cb );
        add_filter( 'upload_dir',   $upload_dir_cb );

        $uploaded = wp_handle_upload( $file, [
            'test_form'                => false,
            'unique_filename_callback' => static fn( $d, $n, $e ) => $n,
        ] );

        remove_filter( 'upload_mimes', $upload_mimes_cb );
        remove_filter( 'upload_dir',   $upload_dir_cb );

        if ( isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'move_failed', $redirect_base ) );
            exit;
        }

        update_option( 'linguaforge_flush_rewrite_rules', true, false );
        wp_safe_redirect( add_query_arg( 'lf_override_uploaded', '1', $redirect_base ) );
        exit;
    }

    public static function handle_delete_override(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_delete_override', 'linguaforge_override_nonce' );

        $redirect_base = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG );
        $filename      = sanitize_file_name( wp_unslash( $_POST['linguaforge_override_file'] ?? '' ) );

        if ( $filename === '' || strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'invalid_file', $redirect_base ) );
            exit;
        }

        if ( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) !== 'mo' ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'invalid_type', $redirect_base ) );
            exit;
        }

        $dir      = self::overrides_dir();
        $real_dir = realpath( $dir );

        if ( $real_dir === false ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'invalid_path', $redirect_base ) );
            exit;
        }

        $base    = pathinfo( $filename, PATHINFO_FILENAME );
        $deleted = false;

        foreach ( [ 'mo', 'po' ] as $ext ) {
            $filepath  = $dir . $base . '.' . $ext;
            $real_file = realpath( $filepath );
            if ( $real_file === false || strpos( $real_file, $real_dir . DIRECTORY_SEPARATOR ) !== 0 ) {
                continue;
            }
            wp_delete_file( $filepath );
            $deleted = true;
        }

        if ( ! $deleted ) {
            wp_safe_redirect( add_query_arg( 'lf_override_error', 'invalid_path', $redirect_base ) );
            exit;
        }

        update_option( 'linguaforge_flush_rewrite_rules', true, false );
        wp_safe_redirect( add_query_arg( 'lf_override_deleted', '1', $redirect_base ) );
        exit;
    }
}
