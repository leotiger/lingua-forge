<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\FseLocalisation\LinkFixer;
use LinguaForge\AI\Admin\FseLocalisation\PartRefFixer;
use LinguaForge\AI\Admin\FseLocalisation\PatternHandler;
use LinguaForge\AI\Admin\FseLocalisation\ScaffoldHandler;
use LinguaForge\AI\Admin\FseLocalisation\TranslateHandler;
use LinguaForge\AI\Admin\Settings\Tabs\Sections\NavigationsSection;
use LinguaForge\AI\Admin\Settings\Tabs\Sections\PatternsSection;
use LinguaForge\AI\Admin\Settings\Tabs\Sections\TemplatePartsSection;
use LinguaForge\AI\Admin\Settings\Tabs\Sections\TemplatesSection;
use LinguaForge\AI\Admin\Language\LanguageUninstaller;
use LinguaForge\AI\Admin\SettingsPage;

defined('ABSPATH') || exit;

/**
 * Settings tab: Router
 *
 * Primary language selection, permalink flush, active languages list, and
 * AJAX-driven language-pack installation.
 *
 * FSE localisation handlers (scaffold, translate, fix links/parts/nav-refs)
 * live in FseLocalisation\* — registered via register_fse_hooks().
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

    /**
     * Register all FSE localisation AJAX hooks.
     *
     * Called once from SettingsPage instead of individual add_action calls.
     */
    public static function register_fse_hooks(): void {
        ScaffoldHandler::register_hooks();
        TranslateHandler::register_hooks();
        LinkFixer::register_hooks();
        PartRefFixer::register_hooks();
        PatternHandler::register_hooks();
    }

    // ── AI helpers ────────────────────────────────────────────────────────────

    /**
     * Return true when an AI provider is selected and a key is available.
     *
     * Public so Section and Handler classes can gate their AI-dependent UI
     * and actions without duplicating the check.
     */
    public static function ai_is_active(): bool {
        $provider = \LinguaForge\AI\Core\Config::provider();
        return ! empty( \LinguaForge\AI\Core\KeyStore::get( $provider ) );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        $router          = \LinguaForge\Router\Router::get_instance();
        $routing_mode    = $router->context->routing_mode();
        $primary_stored  = (string) get_option( 'linguaforge_primary_language', '' );
        $router_langs    = $router->languages();

        // Guarantee the currently stored value always appears in the dropdown.
        // Without this, if the stored language is not in $router_langs (e.g. 'en'
        // when no English locale pack is installed and the admin's personal
        // language is set to another locale), the select renders without that
        // option, the browser submits the first available option, and the stored
        // value is silently overwritten on the next save.
        if ( $primary_stored !== '' && ! in_array( $primary_stored, $router_langs, true ) ) {
            array_unshift( $router_langs, $primary_stored );
        }
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
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( ! empty( $_GET['lf_lang_uninstalled'] ) ) :
            // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only integer GET flags set by wp_safe_redirect() after uninstall; (int) cast is the effective sanitization.
            $lf_posts   = (int) sanitize_text_field( wp_unslash( $_GET['lf_uninstall_posts']   ?? '0' ) );
            $lf_files   = (int) sanitize_text_field( wp_unslash( $_GET['lf_uninstall_files']   ?? '0' ) );
            $lf_skipped = (int) sanitize_text_field( wp_unslash( $_GET['lf_uninstall_skipped'] ?? '0' ) );
            // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php echo esc_html( sprintf(
                        /* translators: %d: number of posts deleted */
                        _n( 'Language uninstalled. %d post deleted.', 'Language uninstalled. %d posts deleted.', $lf_posts, 'lingua-forge' ),
                        $lf_posts
                    ) ); ?>
                    <?php if ( $lf_files > 0 ) : ?>
                        <?php echo esc_html( sprintf(
                            /* translators: %d: number of locale files removed */
                            _n( '%d locale file removed.', '%d locale files removed.', $lf_files, 'lingua-forge' ),
                            $lf_files
                        ) ); ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php if ( $lf_skipped > 0 ) : ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <?php echo esc_html( sprintf(
                        /* translators: %d: number of locale files that could not be removed */
                        _n(
                            '%d locale file could not be removed automatically (DISALLOW_FILE_MODS is set). Delete it manually via your server or WP-CLI: wp language core uninstall <locale>',
                            '%d locale files could not be removed automatically (DISALLOW_FILE_MODS is set). Delete them manually via your server or WP-CLI: wp language core uninstall <locale>',
                            $lf_skipped,
                            'lingua-forge'
                        ),
                        $lf_skipped
                    ) ); ?>
                </p>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ── Primary Language ────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Primary Language', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'The primary language is always served at the root of your site — no URL prefix in path mode, no language subdomain in subdomain mode. All other languages use either a /lang/ path prefix (e.g. example.com/de/) or a language subdomain (e.g. de.example.com), depending on the URL structure setting below.', 'lingua-forge' ); ?>
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
                        <label for="linguaforge_routing_mode">
                            <?php esc_html_e( 'URL structure', 'lingua-forge' ); ?>
                        </label>
                    </th>
                    <td>
                        <select id="linguaforge_routing_mode" name="linguaforge_routing_mode">
                            <option value="path" <?php selected( $routing_mode, 'path' ); ?>>
                                <?php esc_html_e( 'Path prefix — example.com/de/', 'lingua-forge' ); ?>
                            </option>
                            <option value="subdomain" <?php selected( $routing_mode, 'subdomain' ); ?>>
                                <?php esc_html_e( 'Subdomain — de.example.com/', 'lingua-forge' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Subdomain mode requires your web server to point all language subdomains (e.g. de.example.com) to this WordPress installation. After switching modes, flush permalink rules and clear any caches. If your home URL includes www (e.g. www.example.com), use the lf_base_domain filter to set the bare apex domain so subdomains are constructed correctly.', 'lingua-forge' ); ?>
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

            <!-- ── Query Filter Exclusions ──────────────────────────────────── -->
            <h2><?php esc_html_e( 'Query Filter Exclusions', 'lingua-forge' ); ?></h2>

            <p>
                <?php esc_html_e( 'Lingua Forge scopes secondary WP_Query instances (sidebar widgets, template blocks, plugin lookups) to the active language by injecting a _lf_lang meta constraint. Post types listed here are excluded from that constraint — their queries are always unfiltered.', 'lingua-forge' ); ?>
            </p>
            <p>
                <?php
                esc_html_e( 'Contact Form 7 (wpcf7_contact_form) is excluded automatically. Add any other third-party post types whose content should not be language-filtered, one per line or comma-separated.', 'lingua-forge' );
                ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="linguaforge_secondary_query_excluded_types">
                            <?php esc_html_e( 'Excluded post types', 'lingua-forge' ); ?>
                        </label>
                    </th>
                    <td>
                        <textarea
                            id="linguaforge_secondary_query_excluded_types"
                            name="linguaforge_secondary_query_excluded_types"
                            rows="4"
                            class="large-text code"
                            placeholder="e.g. acf-field-group, nf_sub"
                        ><?php echo esc_textarea( (string) get_option( 'linguaforge_secondary_query_excluded_types', '' ) ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Post type slugs separated by commas or newlines. Changes take effect immediately on save — no permalink flush required.', 'lingua-forge' ); ?>
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

        <?php self::render_language_panels(); ?>

    <?php
    }

    // ── Language Panels (tabbed per-language layout) ─────────────────────────

    /**
     * Render the tabbed Language Setup section.
     *
     * Replaces the old cross-language tables (languages × template types,
     * parts × languages) with a tab-per-language layout so the page stays
     * manageable at 10+ installed languages.
     *
     * PHP renders the first tab active; JS (router-tab.js) persists and
     * restores the chosen tab via sessionStorage across reloads.
     */
    private static function render_language_panels(): void {

        $router          = \LinguaForge\Router\Router::get_instance();
        $source_lang     = $router->source_language();
        $secondary_langs = array_values( array_filter( $router->languages(), fn( $l ) => $l !== $source_lang ) );
        $wp_locale_lang  = strtolower( substr( (string) get_locale(), 0, 2 ) );
        ?>

        <!-- ── Language Setup ────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Language Setup', 'lingua-forge' ); ?></h2>

        <?php if ( empty( $secondary_langs ) ) : ?>
            <p class="description">
                <?php esc_html_e( 'No secondary languages configured. Install a language pack above to enable template scaffolding.', 'lingua-forge' ); ?>
            </p>
        <?php return; endif; ?>

        <p>
            <?php esc_html_e( 'Select a language to manage its FSE templates, template parts, and navigation menus.', 'lingua-forge' ); ?>
        </p>

        <!-- Tab bar -->
        <div class="lf-lang-tabs" role="tablist">
            <?php foreach ( $secondary_langs as $i => $lang ) : ?>
            <button
                class="lf-lang-tab<?php echo 0 === $i ? ' is-active' : ''; ?>"
                role="tab"
                data-tab="<?php echo esc_attr( $lang ); ?>"
                aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
                aria-controls="lf-panel-<?php echo esc_attr( $lang ); ?>">
                <?php echo esc_html( strtoupper( $lang ) ); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- One panel per secondary language -->
        <?php foreach ( $secondary_langs as $i => $lang ) : ?>
        <div
            class="lf-lang-panel<?php echo 0 === $i ? ' is-active' : ''; ?>"
            id="lf-panel-<?php echo esc_attr( $lang ); ?>"
            role="tabpanel"
            data-panel="<?php echo esc_attr( $lang ); ?>">
            <?php
            TemplatesSection::render( $lang );
            TemplatePartsSection::render( $lang );
            NavigationsSection::render( $lang );
            PatternsSection::render( $lang );
            self::render_danger_zone( $lang, $lang === $wp_locale_lang );
            ?>
        </div>
        <?php endforeach; ?>

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
        $lang = sanitize_key( wp_unslash( $_POST['linguaforge_primary_language'] ?? '' ) );
        // Only write the option when we actually received a non-empty value.
        // The old fallback to 'ca' would silently overwrite the stored language
        // if the POST field was somehow absent (e.g. the select had no options).
        if ( $lang !== '' ) {
            update_option( 'linguaforge_primary_language', $lang, false );
        }

        $mode = sanitize_key( wp_unslash( $_POST['linguaforge_routing_mode'] ?? 'path' ) );
        if ( in_array( $mode, [ 'path', 'subdomain' ], true ) ) {
            update_option( 'linguaforge_routing_mode', $mode, false );
        }

        update_option( 'lf_browser_redirect', ! empty( $_POST['lf_browser_redirect'] ), false );

        // Comma/newline-separated list of post type slugs to exclude from the
        // secondary-query language filter. Each slug is sanitized to [a-z0-9_-]
        // via sanitize_key(); empty strings and duplicates are stripped.
        $raw_excluded = wp_unslash( $_POST['linguaforge_secondary_query_excluded_types'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() applied to each token below.
        $tokens       = preg_split( '/[\s,]+/', (string) $raw_excluded, -1, PREG_SPLIT_NO_EMPTY );
        $sanitized    = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $tokens ) ) ) );
        update_option( 'linguaforge_secondary_query_excluded_types', implode( ',', $sanitized ), false );

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

    // ── Danger zone ───────────────────────────────────────────────────────────

    /**
     * Render the "Danger Zone" section at the bottom of a language panel.
     *
     * Collapsed by default via <details> — requires deliberate expansion before
     * the confirmation dialog appears. When $is_wp_locale is true the button is
     * disabled and a note explains why.
     *
     * @param string $lang           Two-character language code.
     * @param bool   $is_wp_locale   True when $lang matches the WP instance locale.
     */
    private static function render_danger_zone( string $lang, bool $is_wp_locale ): void {
        ?>
        <hr style="margin:32px 0 16px;">

        <details class="lf-danger-zone">
            <summary class="lf-danger-zone__summary">
                <?php esc_html_e( 'Danger Zone', 'lingua-forge' ); ?>
            </summary>

            <div class="lf-danger-zone__body">
                <h4 style="margin:0 0 6px;"><?php
                    echo esc_html( sprintf(
                        /* translators: %s: two-character language code displayed in upper case, e.g. DE */
                        __( 'Uninstall %s', 'lingua-forge' ),
                        strtoupper( $lang )
                    ) );
                ?></h4>

                <p class="description" style="margin:0 0 10px;"><?php
                    esc_html_e(
                        'Permanently deletes all templates, template parts, patterns, navigation menus, posts, pages, custom post types, products, and product variations associated with this language. Also removes the WordPress locale pack files so this language no longer appears in the router. This action cannot be undone.',
                        'lingua-forge'
                    );
                ?></p>

                <?php if ( $is_wp_locale ) : ?>

                    <p class="description" style="color:#b32d2e;">
                        <?php esc_html_e(
                            'This language matches the WordPress instance locale and cannot be uninstalled.',
                            'lingua-forge'
                        ); ?>
                    </p>

                <?php else : ?>

                    <form
                        method="post"
                        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                        onsubmit="return confirm( '<?php echo esc_js( sprintf(
                            /* translators: %s: upper-case language code, e.g. DE */
                            __( 'Permanently uninstall %s? This will delete all translated content for this language and cannot be undone.', 'lingua-forge' ),
                            strtoupper( $lang )
                        ) ); ?>' )"
                    >
                        <input type="hidden" name="action"           value="linguaforge_uninstall_language">
                        <input type="hidden" name="lf_uninstall_lang" value="<?php echo esc_attr( $lang ); ?>">
                        <?php wp_nonce_field( 'linguaforge_uninstall_language_' . $lang, 'linguaforge_uninstall_nonce' ); ?>
                        <button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e;">
                            <?php echo esc_html( sprintf(
                                /* translators: %s: two-character language code displayed in upper case, e.g. DE */
                                __( 'Uninstall %s', 'lingua-forge' ),
                                strtoupper( $lang )
                            ) ); ?>
                        </button>
                    </form>

                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    /**
     * Handle the linguaforge_uninstall_language admin-post action.
     *
     * Delegates all business logic to LanguageUninstaller; this method is
     * responsible only for auth, input sanitisation, and redirect building.
     */
    public static function handle_uninstall_language(): void {

        $lang = sanitize_key( wp_unslash( $_POST['lf_uninstall_lang'] ?? '' ) );

        check_admin_referer( 'linguaforge_uninstall_language_' . $lang, 'linguaforge_uninstall_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'lingua-forge' ), 403 );
        }

        if ( $lang === '' ) {
            wp_die( esc_html__( 'Invalid language code.', 'lingua-forge' ), 400 );
        }

        $uninstaller = new LanguageUninstaller( $GLOBALS['wpdb'], \LinguaForge\Router\Router::get_instance() );

        if ( $uninstaller->is_protected( $lang ) ) {
            wp_die( esc_html__( 'This language is protected and cannot be uninstalled.', 'lingua-forge' ), 403 );
        }

        $result = $uninstaller->uninstall( $lang );

        $redirect = add_query_arg(
            [
                'page'                 => SettingsPage::PAGE_SLUG,
                'lf_lang_uninstalled'  => '1',
                'lf_uninstall_posts'   => $result->posts_deleted,
                'lf_uninstall_files'   => count( $result->files_deleted ),
                'lf_uninstall_skipped' => count( $result->files_skipped ),
            ],
            admin_url( 'options-general.php' )
        ) . '#router';

        wp_safe_redirect( $redirect );
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
            // Flush rewrite rules immediately after installing a new locale pack.
            // The router derives available languages from installed locale packs,
            // so the rewrite rules (which include per-language URL prefixes and
            // archive rewrites) must be regenerated now — otherwise the new
            // language's /lang/ prefix returns 404 until someone visits
            // Settings → Permalinks manually.
            flush_rewrite_rules();

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
