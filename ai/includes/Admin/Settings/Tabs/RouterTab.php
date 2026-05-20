<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Settings tab: Router
 *
 * Primary language selection, permalink flush, active languages list, and
 * AJAX-driven language-pack installation.
 *
 * This tab uses its own admin-post actions rather than the shared settings
 * form, so save() is not implemented.
 */
class RouterTab extends Tab {

    public static function slug(): string {
        return 'router';
    }

    public static function label(): string {
        return __( 'Router', 'lingua-forge' );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        $router          = \LinguaForge\Router\Router::get_instance();
        $primary_stored  = (string) get_option( 'linguaforge_primary_language', 'ca' );
        $router_langs    = $router->languages();
        $installed_locales = get_available_languages();
        ?>

        <?php
        // ── Feedback notices ─────────────────────────────────────────────────
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flags set by wp_safe_redirect() after router actions; no data is modified here.
        if ( ! empty( $_GET['lf_router_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Primary language saved.', 'lingua-forge' ); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( ! empty( $_GET['lf_permalinks_flushed'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Permalink rules flushed successfully.', 'lingua-forge' ); ?></p>
            </div>
        <?php endif; ?>

        <!-- ── Primary Language ────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Primary Language', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'The primary language is served at the root of your site (no URL prefix) and uses the default WordPress FSE templates (page, single, etc.). All other languages get a /lang/ URL prefix and are expected to use language-specific templates such as page-de or single-de.', 'lingua-forge' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="linguaforge_save_router_settings">
            <?php wp_nonce_field( 'linguaforge_save_router_settings', 'linguaforge_router_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="linguaforge_primary_language">
                            <?php esc_html_e( 'Primary language', 'lingua-forge' ); ?>
                        </label>
                    </th>
                    <td>
                        <select id="linguaforge_primary_language" name="linguaforge_primary_language">
                            <?php foreach ( $router_langs as $code ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $primary_stored, $code ); ?>>
                                    <?php echo esc_html( strtoupper( $code ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'After changing the primary language, flush permalinks (section below) for the URL routing to update.', 'lingua-forge' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Browser language redirect', 'lingua-forge' ); ?>
                    </th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                name="lf_browser_redirect"
                                value="1"
                                <?php checked( get_option( 'lf_browser_redirect', false ) ); ?>
                            />
                            <?php esc_html_e( 'Redirect visitors to their preferred language based on the browser\'s Accept-Language header', 'lingua-forge' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'When enabled, first-time visitors with no language cookie and no language prefix in the URL are redirected to the closest matching language version. The redirect is skipped if the browser\'s preferred language is not among the active router languages. Once a visitor selects a language via the switcher, the cookie takes priority and the browser header is ignored on all future visits.', 'lingua-forge' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Router Settings', 'lingua-forge' ), 'secondary' ); ?>
        </form>

        <!-- ── Flush Permalinks ─────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Flush Permalinks', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Regenerates WordPress rewrite rules so URL prefixes, language-specific slugs, and archive rewrites are all in sync. Necessary after changing the primary language or adding new language support.', 'lingua-forge' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="linguaforge_flush_permalinks">
            <?php wp_nonce_field( 'linguaforge_flush_permalinks', 'linguaforge_flush_nonce' ); ?>
            <?php submit_button( __( 'Flush Permalink Rules', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
        </form>

        <!-- ── Active Languages ─────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Active Languages', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Languages currently known to the router (derived from installed WordPress locale packs plus the primary language). Install additional language packs in the section below to make more languages available for routing and translation.', 'lingua-forge' ); ?>
        </p>

        <div class="lf-installed-langs">
            <?php foreach ( $router_langs as $code ) : ?>
                <span class="lf-lang-chip"><?php echo esc_html( $code ); ?></span>
            <?php endforeach; ?>
        </div>

        <?php if ( ! empty( $installed_locales ) ) : ?>
            <p class="description">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: count of installed locale packs */
                    _n( '%d locale pack installed.', '%d locale packs installed.', count( $installed_locales ), 'lingua-forge' ),
                    count( $installed_locales )
                ) );
                ?>
            </p>
        <?php endif; ?>

        <!-- ── Install Language ─────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Install a Language', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Download and install a WordPress core language pack directly from WordPress.org. Once installed, the locale becomes available for URL routing and the AI translation workflow. The list of available languages is fetched on demand — click the button below to load it.', 'lingua-forge' ); ?>
        </p>

        <?php if ( ! wp_is_file_mod_allowed( 'download_language_pack' ) ) : ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e( 'Language installation is disabled on this server. The DISALLOW_FILE_MODS constant is set in wp-config.php. Install language packs manually via WP-CLI: wp language core install de_DE', 'lingua-forge' ); ?></p>
            </div>
        <?php else : ?>
            <p>
                <button type="button" id="lf-load-langs-btn" class="button">
                    <?php esc_html_e( 'Load available languages', 'lingua-forge' ); ?>
                </button>
            </p>

            <p id="lf-lang-install-row" style="display:none;">
                <select id="lf-lang-install-select" disabled>
                    <option value=""><?php esc_html_e( '— select a language —', 'lingua-forge' ); ?></option>
                </select>
                <button type="button" id="lf-install-lang-btn" class="button button-primary" disabled>
                    <?php esc_html_e( 'Install', 'lingua-forge' ); ?>
                </button>
                <span id="lf-lang-install-result"></span>
            </p>
        <?php endif; ?>

        <?php
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * Save the primary language setting from the Router tab.
     */
    public static function handle_save_router_settings(): void {
        check_admin_referer( 'linguaforge_save_router_settings', 'linguaforge_router_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'lingua-forge' ), 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-] which is sufficient for a two-char language code.
        $lang = sanitize_key( wp_unslash( $_POST['linguaforge_primary_language'] ?? 'ca' ) );
        update_option( 'linguaforge_primary_language', $lang ?: 'ca', false );

        update_option( 'lf_browser_redirect', ! empty( $_POST['lf_browser_redirect'] ), false );

        wp_safe_redirect( admin_url( 'options-general.php' ) . '?page=' . SettingsPage::PAGE_SLUG . '&lf_router_saved=1#router' );
        exit;
    }

    /**
     * Flush WordPress rewrite rules from the Router tab.
     */
    public static function handle_flush_permalinks(): void {
        check_admin_referer( 'linguaforge_flush_permalinks', 'linguaforge_flush_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'lingua-forge' ), 403 );
        }

        flush_rewrite_rules();

        wp_safe_redirect( admin_url( 'options-general.php' ) . '?page=' . SettingsPage::PAGE_SLUG . '&lf_permalinks_flushed=1#router' );
        exit;
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    /**
     * Return the list of WordPress.org translations not yet installed locally.
     *
     * Called via wp_ajax_linguaforge_get_available_languages.
     * Fetches from translate.wordpress.org; the result is cached in a transient
     * (~12 h) by wp_get_available_translations() so only the first call is slow.
     */
    public static function ajax_get_available_languages(): void {
        check_ajax_referer( 'linguaforge_get_available_languages', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        if ( ! function_exists( 'wp_get_available_translations' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $available  = wp_get_available_translations();
        $installed  = get_available_languages();

        // Build a set of installed two-char prefixes (e.g. 'de' from 'de_DE').
        $installed_codes = [];
        foreach ( $installed as $locale ) {
            $installed_codes[ $locale ] = true;
            // Also mark the two-char code so e.g. 'de_DE' suppresses 'de_DE' variants.
        }

        $options = [];
        foreach ( $available as $locale => $meta ) {
            if ( isset( $installed_codes[ $locale ] ) ) {
                continue; // already installed
            }
            $options[] = [
                'locale'       => esc_attr( $locale ),
                'english_name' => esc_html( $meta['english_name'] ?? $locale ),
                'native_name'  => esc_html( $meta['native_name']  ?? '' ),
            ];
        }

        // Sort by English name for readability.
        usort( $options, fn( $a, $b ) => strcmp( $a['english_name'], $b['english_name'] ) );

        wp_send_json_success( [ 'languages' => $options ] );
    }

    /**
     * Download and install a WordPress core language pack.
     *
     * Called via wp_ajax_linguaforge_install_language.
     * Uses wp_download_language_pack() — requires file modifications to be
     * allowed (DISALLOW_FILE_MODS must not be set).
     */
    public static function ajax_install_language(): void {
        check_ajax_referer( 'linguaforge_install_language', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        if ( ! wp_is_file_mod_allowed( 'download_language_pack' ) ) {
            wp_send_json_error( __( 'Language installation is disabled on this server (DISALLOW_FILE_MODS is set).', 'lingua-forge' ) );
        }

        $locale = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );
        if ( ! $locale || ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2,4})?$/', $locale ) ) {
            wp_send_json_error( __( 'Invalid locale code.', 'lingua-forge' ) );
        }

        if ( ! function_exists( 'wp_download_language_pack' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }
        if ( ! class_exists( 'Language_Pack_Upgrader' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        ob_start();
        $result = wp_download_language_pack( $locale );
        ob_end_clean();

        if ( $result ) {
            wp_send_json_success( [
                'locale'  => $result,
                /* translators: %s: locale code such as de_DE */
                'message' => sprintf( __( 'Language %s installed successfully.', 'lingua-forge' ), esc_html( $result ) ),
            ] );
        } else {
            wp_send_json_error( __( 'Language pack installation failed. The language may already be installed, the locale code may be incorrect, or your server may block file writes.', 'lingua-forge' ) );
        }
    }
}
