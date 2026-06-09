<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\SystemPanel
 *
 * Read-only diagnostic panel rendered on Settings → System.
 *
 * All sections are pure reads — no write operations except the _lf_lang
 * repair AJAX action registered here (§9.3) which writes post meta.
 *
 * Sections:
 *   render_environment()        — LF / WP / PHP versions, theme, routing
 *   render_permalink_check()    — structure compatibility with path routing
 *   render_seo_plugins()        — active SEO plugin detection + conflict notes
 *   render_woocommerce()        — WC version + translated WC page coverage
 *   render_lang_coverage()      — per-post-type _lf_lang gap report + repair
 *   render_rewrite_rules()      — collapsible LF rewrite rule dump
 *   render_debug_copy()         — one-click plain-text system info
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.5
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

defined( 'ABSPATH' ) || exit;

class SystemPanel {

    // =========================================================================
    // Public entry point
    // =========================================================================

    public static function render(): void {
        self::render_environment();
        self::render_ai_config();
        self::render_permalink_check();
        self::render_seo_plugins();
        self::render_woocommerce();
        self::render_active_plugins();
        self::render_lang_coverage();
        self::render_rewrite_rules();
        self::render_debug_copy();
    }

    // =========================================================================
    // AJAX hook registration
    // =========================================================================

    /**
     * Register the _lf_lang repair AJAX action.
     * Called once from SettingsPage::register_hooks().
     */
    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_repair_lf_lang',      [ self::class, 'ajax_repair_lf_lang' ] );
        add_action( 'wp_ajax_linguaforge_exclude_post_type',   [ self::class, 'ajax_exclude_post_type' ] );
    }

    // =========================================================================
    // §1 — Environment
    // =========================================================================

    private static function render_environment(): void {

        $router         = \LinguaForge\Router\Router::get_instance();
        $theme          = wp_get_theme();
        $is_fse         = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
        $routing_mode   = (string) get_option( 'linguaforge_routing_mode', 'path' );
        $primary_lang   = (string) get_option( 'linguaforge_primary_language', '' );
        $active_langs   = $router->languages();
        $lf_version     = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : '—';
        $php_timeout    = (int) ini_get( 'max_execution_time' );
        $php_memory     = (string) ini_get( 'memory_limit' );
        // 0 = unlimited (CLI / some managed hosts set this deliberately).
        $timeout_ok     = 0 === $php_timeout || $php_timeout >= 60;

        ?>
        <h2><?php esc_html_e( 'Environment', 'lingua-forge' ); ?></h2>

        <table class="widefat striped" style="max-width:640px;margin:0 0 24px;">
            <tbody>
                <tr>
                    <th style="width:220px"><?php esc_html_e( 'Lingua Forge version', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( $lf_version ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WordPress version', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'PHP version', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( PHP_VERSION ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Active theme', 'lingua-forge' ); ?></th>
                    <td>
                        <?php echo esc_html( $theme->get( 'Name' ) ); ?>
                        <span style="color:#646970;margin-left:8px;">
                            <?php echo $is_fse
                                ? esc_html__( '(FSE / block theme)', 'lingua-forge' )
                                : esc_html__( '(classic theme)', 'lingua-forge' ); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'URL structure', 'lingua-forge' ); ?></th>
                    <td>
                        <?php echo 'subdomain' === $routing_mode
                            ? esc_html__( 'Subdomain (de.example.com)', 'lingua-forge' )
                            : esc_html__( 'Path prefix (/de/)',         'lingua-forge' ); ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WP instance language', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( get_locale() ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Primary content language', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( strtoupper( $primary_lang ) ?: '—' ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Active languages', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( implode( ', ', array_map( 'strtoupper', $active_langs ) ) ?: '—' ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'PHP max_execution_time', 'lingua-forge' ); ?></th>
                    <td>
                        <?php if ( 0 === $php_timeout ) : ?>
                            <span style="color:#46b450;font-weight:700;">&#10003;</span>
                            <?php esc_html_e( 'No PHP limit', 'lingua-forge' ); ?>
                            <span style="color:#646970;margin-left:6px;font-size:12px;"><?php esc_html_e( '(server/FPM timeout still applies)', 'lingua-forge' ); ?></span>
                        <?php elseif ( $timeout_ok ) : ?>
                            <span style="color:#46b450;font-weight:700;">&#10003;</span>
                            <?php echo esc_html( $php_timeout . 's' ); ?>
                        <?php else : ?>
                            <span style="color:#d63638;font-weight:700;">&#10007;</span>
                            <?php
                            echo esc_html( $php_timeout . 's' );
                            echo ' — ';
                            esc_html_e( 'AI translation and batch analysis may time out. Recommend 120s or more.', 'lingua-forge' );
                            ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'PHP memory_limit', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( $php_memory ); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    // =========================================================================
    // §1b — AI configuration
    // =========================================================================

    private static function render_ai_config(): void {

        $active_provider = \LinguaForge\AI\Core\Config::provider();
        $provider_labels = [
            'anthropic' => 'Anthropic (Claude)',
            'openai'    => 'OpenAI (GPT)',
            'gemini'    => 'Google (Gemini)',
        ];

        $model_light  = \LinguaForge\AI\Core\Config::model( 'light' );
        $model_quality = \LinguaForge\AI\Core\Config::model( 'quality' );
        $preset        = (string) get_option( 'linguaforge_active_preset', 'standard' );
        $daily_quota   = (int) get_option( 'linguaforge_ai_daily_quota', 0 );

        ?>
        <h2><?php esc_html_e( 'AI Configuration', 'lingua-forge' ); ?></h2>

        <table class="widefat striped" style="max-width:640px;margin:0 0 24px;">
            <tbody>
                <?php foreach ( $provider_labels as $slug => $label ) :
                    $key_set   = '' !== (string) \LinguaForge\AI\Core\KeyStore::get( $slug );
                    $is_active = $slug === $active_provider;
                ?>
                <tr>
                    <th style="width:220px"><?php echo esc_html( $label ); ?><?php if ( $is_active ) : ?>
                        <span style="margin-left:6px;font-size:11px;font-weight:400;color:#2271b1;"><?php esc_html_e( '(active)', 'lingua-forge' ); ?></span>
                    <?php endif; ?></th>
                    <td>
                        <?php if ( $key_set ) : ?>
                            <span style="color:#46b450;font-weight:700;">&#10003;</span>
                            <?php esc_html_e( 'Configured', 'lingua-forge' ); ?>
                        <?php else : ?>
                            <span style="color:#999;">&#8212;</span>
                            <?php esc_html_e( 'No key', 'lingua-forge' ); ?>
                            <?php if ( $is_active ) : ?>
                                <span style="color:#d63638;margin-left:4px;"><?php esc_html_e( '— AI features are disabled.', 'lingua-forge' ); ?></span>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lingua-forge#api-keys' ) ); ?>" style="margin-left:6px;">
                                    <?php esc_html_e( 'Add key →', 'lingua-forge' ); ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th><?php esc_html_e( 'Light model', 'lingua-forge' ); ?></th>
                    <td><code><?php echo esc_html( $model_light ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Quality model', 'lingua-forge' ); ?></th>
                    <td><code><?php echo esc_html( $model_quality ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Active preset', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( ucfirst( $preset ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Daily quota', 'lingua-forge' ); ?></th>
                    <td><?php echo 0 === $daily_quota
                        ? esc_html__( 'Unlimited', 'lingua-forge' )
                        : esc_html( (string) $daily_quota . ' ' . __( 'requests / day', 'lingua-forge' ) ); ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    // =========================================================================
    // §2 — Permalink structure check
    // =========================================================================

    private static function render_permalink_check(): void {

        $structure = (string) get_option( 'permalink_structure', '' );
        // Plain ('') and Numeric ('/%year%/%monthnum%/%day%/%post_id%/'  or just '/%post_id%/')
        // are incompatible with LF path routing.
        $is_plain   = '' === $structure;
        $is_numeric = ! $is_plain && (bool) preg_match( '#^/%post_id%#', $structure );
        $ok         = ! $is_plain && ! $is_numeric;

        ?>
        <h2><?php esc_html_e( 'Permalink Structure', 'lingua-forge' ); ?></h2>

        <p style="margin:0 0 8px;">
            <?php if ( $ok ) : ?>
                <span style="color:#46b450;font-weight:700;">&#10003;</span>
                <?php esc_html_e( 'Permalink structure is compatible with Lingua Forge path routing.', 'lingua-forge' ); ?>
                <code style="margin-left:8px;"><?php echo esc_html( $structure ); ?></code>
            <?php elseif ( $is_plain ) : ?>
                <span style="color:#d63638;font-weight:700;">&#10007;</span>
                <strong><?php esc_html_e( 'Plain permalinks are not supported.', 'lingua-forge' ); ?></strong>
                <?php esc_html_e( 'Path routing requires a post-name based permalink structure. Change it in', 'lingua-forge' ); ?>
                <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">
                    <?php esc_html_e( 'Settings → Permalinks', 'lingua-forge' ); ?>
                </a>.
            <?php else : ?>
                <span style="color:#d63638;font-weight:700;">&#10007;</span>
                <strong><?php esc_html_e( 'Numeric permalink structure is not supported.', 'lingua-forge' ); ?></strong>
                <?php esc_html_e( 'Switch to a post-name based structure in', 'lingua-forge' ); ?>
                <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">
                    <?php esc_html_e( 'Settings → Permalinks', 'lingua-forge' ); ?>
                </a>.
            <?php endif; ?>
        </p>
        <hr style="margin:24px 0 16px;">
        <?php
    }

    // =========================================================================
    // §3 — Active SEO plugins
    // =========================================================================

    private static function render_seo_plugins(): void {

        $plugins = [
            'Yoast SEO'      => defined( 'WPSEO_VERSION' ),
            'Rank Math'      => defined( 'RANK_MATH_VERSION' ),
            'All in One SEO' => defined( 'AIOSEO_VERSION' ),
            'SEOPress'       => defined( 'SEOPRESS_VERSION' ),
        ];
        $active = array_keys( array_filter( $plugins ) );

        ?>
        <h2><?php esc_html_e( 'SEO Plugins', 'lingua-forge' ); ?></h2>

        <?php if ( empty( $active ) ) : ?>
            <p><?php esc_html_e( 'No third-party SEO plugins detected. Lingua Forge handles all multilingual SEO output.', 'lingua-forge' ); ?></p>
        <?php else : ?>
            <p>
                <?php
                echo esc_html( sprintf(
                    /* translators: %s: comma-separated list of plugin names */
                    __( 'Active: %s.', 'lingua-forge' ),
                    implode( ', ', $active )
                ) );
                ?>
                <?php esc_html_e( 'See Settings → SEO → Compatibility for the full per-feature behaviour table.', 'lingua-forge' ); ?>
            </p>
        <?php endif; ?>
        <hr style="margin:24px 0 16px;">
        <?php
    }

    // =========================================================================
    // §4 — WooCommerce
    // =========================================================================

    private static function render_woocommerce(): void {
        ?>
        <h2><?php esc_html_e( 'WooCommerce', 'lingua-forge' ); ?></h2>

        <?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
            <p class="description"><?php esc_html_e( 'WooCommerce is not active.', 'lingua-forge' ); ?></p>
            <hr style="margin:24px 0 16px;">
            <?php return;
        endif;

        $router       = \LinguaForge\Router\Router::get_instance();
        $source_lang  = $router->source_language();
        $sec_langs    = array_values( array_filter( $router->languages(), fn( $l ) => $l !== $source_lang ) );
        $wc_version   = defined( 'WC_VERSION' ) ? WC_VERSION : ( class_exists( 'WooCommerce' ) ? WC()->version : '—' );
        $bridge_class  = 'LinguaForge\\AI\\Integrations\\WooCommerce\\WcPageBridge';
        $bridge_active = class_exists( $bridge_class );

        // Detect WooCommerce order storage mode.
        // Modes: 'cpt' (legacy wp_posts), 'hpos' (custom tables), 'shared' (HPOS + sync).
        $order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if ( class_exists( $order_util ) && method_exists( $order_util, 'custom_orders_table_usage_is_enabled' ) ) {
            $hpos_active = (bool) $order_util::custom_orders_table_usage_is_enabled();
        } else {
            $hpos_active = 'yes' === (string) get_option( 'woocommerce_feature_custom_order_tables_enabled', 'no' );
        }
        $hpos_sync    = $hpos_active && 'yes' === (string) get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
        $storage_mode = ! $hpos_active ? 'cpt' : ( $hpos_sync ? 'shared' : 'hpos' );

        // WC built-in pages to check.
        $wc_pages = [
            'shop'      => __( 'Shop', 'lingua-forge' ),
            'cart'      => __( 'Cart', 'lingua-forge' ),
            'checkout'  => __( 'Checkout', 'lingua-forge' ),
            'myaccount' => __( 'My Account', 'lingua-forge' ),
        ];

        ?>
        <table class="widefat striped" style="max-width:640px;margin:0 0 16px;">
            <tbody>
                <tr>
                    <th style="width:220px"><?php esc_html_e( 'WooCommerce version', 'lingua-forge' ); ?></th>
                    <td><?php echo esc_html( $wc_version ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Order storage', 'lingua-forge' ); ?></th>
                    <td>
                        <?php if ( 'hpos' === $storage_mode ) : ?>
                            <?php esc_html_e( 'High-Performance Order Storage (HPOS)', 'lingua-forge' ); ?>
                            <span style="color:#646970;margin-left:6px;font-size:12px;"><?php esc_html_e( 'Custom tables — LF post-meta filters do not affect orders.', 'lingua-forge' ); ?></span>
                        <?php elseif ( 'shared' === $storage_mode ) : ?>
                            <?php esc_html_e( 'Shared / compatibility mode', 'lingua-forge' ); ?>
                            <span style="color:#646970;margin-left:6px;font-size:12px;"><?php esc_html_e( 'HPOS + CPT synced — transitional; LF post-meta filters apply to CPT copy.', 'lingua-forge' ); ?></span>
                        <?php else : ?>
                            <?php esc_html_e( 'Legacy post-based (CPT)', 'lingua-forge' ); ?>
                            <span style="color:#646970;margin-left:6px;font-size:12px;"><?php esc_html_e( 'Orders in wp_posts — ensure shop_order is excluded from LF routing.', 'lingua-forge' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WcPageBridge active', 'lingua-forge' ); ?></th>
                    <td>
                        <?php if ( $bridge_active ) : ?>
                            <span style="color:#46b450;font-weight:700;">&#10003;</span>
                            <?php esc_html_e( 'Yes', 'lingua-forge' ); ?>
                        <?php else : ?>
                            <span style="color:#d63638;font-weight:700;">&#10007;</span>
                            <?php esc_html_e( 'No — WooCommerce integration class not loaded', 'lingua-forge' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ( ! empty( $sec_langs ) ) : ?>
        <h4 style="margin:0 0 8px;"><?php esc_html_e( 'WooCommerce page translations', 'lingua-forge' ); ?></h4>
        <table class="widefat striped" style="max-width:640px;margin:0 0 24px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Page', 'lingua-forge' ); ?></th>
                    <?php foreach ( $sec_langs as $lang ) : ?>
                        <th><?php echo esc_html( strtoupper( $lang ) ); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $wc_pages as $page_key => $page_label ) :
                    $page_id = (int) get_option( 'woocommerce_' . $page_key . '_page_id', 0 );
                    $translations = $page_id > 0
                        ? $router->get_translations( $page_id )
                        : [];
                ?>
                <tr>
                    <td><?php echo esc_html( $page_label ); ?></td>
                    <?php foreach ( $sec_langs as $lang ) : ?>
                        <td>
                            <?php if ( ! empty( $translations[ $lang ] ) ) : ?>
                                <span style="color:#46b450;font-weight:700;">&#10003;</span>
                            <?php else : ?>
                                <span style="color:#d63638;">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'No secondary languages configured.', 'lingua-forge' ); ?></p>
        <?php endif; ?>

        <hr style="margin:24px 0 16px;">
        <?php
    }

    // =========================================================================
    // §4b — Active plugins
    // =========================================================================

    private static function render_active_plugins(): void {

        $active_files  = (array) get_option( 'active_plugins', [] );
        // Network-active plugins (multisite) — include if present.
        $network_files = function_exists( 'is_multisite' ) && is_multisite()
            ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) )
            : [];
        $all_active = array_unique( array_merge( $active_files, $network_files ) );
        sort( $all_active );

        // get_plugin_data() reads file headers; require the helper if not loaded.
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = [];
        foreach ( $all_active as $file ) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            if ( ! is_readable( $path ) ) {
                $plugins[] = [ 'name' => $file, 'version' => '' ];
                continue;
            }
            $data      = get_plugin_data( $path, false, false );
            $plugins[] = [
                'name'    => $data['Name'] ?: $file,
                'version' => $data['Version'] ?: '',
            ];
        }

        ?>
        <h2><?php esc_html_e( 'Active Plugins', 'lingua-forge' ); ?></h2>

        <?php if ( empty( $plugins ) ) : ?>
            <p class="description"><?php esc_html_e( 'No active plugins detected.', 'lingua-forge' ); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:640px;margin:0 0 24px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Plugin', 'lingua-forge' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Version', 'lingua-forge' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $plugins as $plugin ) : ?>
                    <tr>
                        <td><?php echo esc_html( $plugin['name'] ); ?></td>
                        <td style="color:#646970;"><?php echo esc_html( $plugin['version'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <hr style="margin:24px 0 16px;">
        <?php
    }

    // =========================================================================
    // §5 — _lf_lang coverage (§9.3)
    // =========================================================================

    /**
     * Return the set of post types excluded from language routing.
     *
     * Mirrors the logic in QueryFilter::builtin_excluded_post_types() so this
     * panel can classify rows without instantiating the router subsystem.
     * Kept private here — the canonical source is QueryFilter.
     *
     * @return string[]
     */
    private static function routing_excluded_post_types(): array {
        $builtin    = [ 'wpcf7_contact_form' ];
        $saved      = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
        $from_opt   = array_filter(
            array_map( 'sanitize_key', preg_split( '/[\s,]+/', $saved, -1, PREG_SPLIT_NO_EMPTY ) )
        );
        return array_values( array_unique( array_merge( $builtin, $from_opt ) ) );
    }

    private static function render_lang_coverage(): void {

        global $wpdb;

        // WP-internal post types that never carry _lf_lang — hidden from query.
        $wp_internal = [
            'revision', 'auto-draft', 'nav_menu_item',
            'wp_navigation', 'wp_template', 'wp_template_part',
            'wp_global_styles', 'wp_block', 'wp_font_face',
            'wp_font_family', 'attachment',
        ];
        $placeholders = implode( ', ', array_fill( 0, count( $wp_internal ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic query; no caching needed for an admin-only read.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders contains only '%s' literals built by array_fill(); all values are bound by the ...$wp_internal spread args.
                "SELECT p.post_type, COUNT(*) AS missing
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m
                       ON m.post_id = p.ID AND m.meta_key = '_lf_lang'
                 WHERE p.post_status = 'publish'
                   AND p.post_type NOT IN ({$placeholders})
                   AND m.meta_id IS NULL
                 GROUP BY p.post_type
                 ORDER BY missing DESC",
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                ...$wp_internal
            ),
            ARRAY_A
        );

        // Split into routable (need attention) vs. router-excluded (informational only).
        $routing_excluded = self::routing_excluded_post_types();
        $routable_rows    = [];
        $excluded_rows    = [];
        foreach ( (array) $rows as $row ) {
            if ( in_array( $row['post_type'], $routing_excluded, true ) ) {
                $excluded_rows[] = $row;
            } else {
                $routable_rows[] = $row;
            }
        }

        $has_gaps         = ! empty( $routable_rows );
        $has_any_rows     = ! empty( $rows );

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        $exclude_nonce = wp_create_nonce( 'linguaforge_exclude_post_type' );

        ?>
        <h2><?php esc_html_e( '_lf_lang Coverage', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Posts without _lf_lang are invisible to every Lingua Forge language filter. This can happen when content is imported or created before the plugin was activated.', 'lingua-forge' ); ?>
        </p>

        <p id="lf-lang-coverage-ok" <?php echo $has_any_rows ? 'style="display:none;"' : ''; ?>>
            <span style="color:#46b450;font-weight:700;">&#10003;</span>
            <?php esc_html_e( 'All published posts have a _lf_lang value. No gaps detected.', 'lingua-forge' ); ?>
        </p>

        <?php if ( $has_any_rows ) : ?>

            <table id="lf-lang-coverage-table" class="widefat striped" style="max-width:560px;margin:0 0 16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Post type', 'lingua-forge' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Missing', 'lingua-forge' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $routable_rows as $row ) : ?>
                    <tr id="lf-cov-row-<?php echo esc_attr( $row['post_type'] ); ?>">
                        <td><code><?php echo esc_html( $row['post_type'] ); ?></code></td>
                        <td style="text-align:right;font-weight:600;color:#d63638;">
                            <?php echo (int) $row['missing']; ?>
                        </td>
                        <td style="text-align:right;">
                            <button type="button"
                                    class="button button-small lf-exclude-post-type-btn"
                                    data-post-type="<?php echo esc_attr( $row['post_type'] ); ?>"
                                    data-nonce="<?php echo esc_attr( $exclude_nonce ); ?>"
                                    title="<?php esc_attr_e( 'Exclude this post type from language routing — its posts will no longer appear here or receive _lf_lang. This cannot be undone automatically.', 'lingua-forge' ); ?>">
                                <?php esc_html_e( 'Exclude from routing', 'lingua-forge' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ( $excluded_rows as $row ) : ?>
                    <tr style="opacity:.55;" id="lf-cov-row-<?php echo esc_attr( $row['post_type'] ); ?>">
                        <td>
                            <code><?php echo esc_html( $row['post_type'] ); ?></code>
                            <span style="margin-left:6px;font-size:11px;color:#646970;"><?php esc_html_e( 'excluded from routing', 'lingua-forge' ); ?></span>
                        </td>
                        <td style="text-align:right;color:#646970;"><?php echo (int) $row['missing']; ?></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $has_gaps ) : ?>

            <div id="lf-lang-coverage-repair" style="border-left:4px solid #d63638;padding:10px 14px;margin:0 0 12px;background:#fff8f8;max-width:560px;">
                <p style="margin:0 0 8px;font-weight:600;color:#d63638;">
                    &#9888; <?php esc_html_e( 'Destructive action — read before proceeding', 'lingua-forge' ); ?>
                </p>
                <p style="margin:0 0 10px;" class="description">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %s: source language code in upper case, e.g. CA */
                        __( 'Repair tags every routable post listed above with _lf_lang = %s. Posts in routing-excluded post types are not touched. Only run this after importing content or on initial activation — do not use as a bulk language-reassignment tool.', 'lingua-forge' ),
                        strtoupper( $source_lang )
                    ) );
                    ?>
                </p>
                <button type="button"
                        id="lf-repair-lf-lang-btn"
                        class="button"
                        style="border-color:#d63638;color:#d63638;"
                        data-source="<?php echo esc_attr( $source_lang ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'linguaforge_repair_lf_lang' ) ); ?>">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %s: source language code shown in upper case, e.g. CA */
                        __( 'Repair — assign _lf_lang = %s to all routable posts above', 'lingua-forge' ),
                        strtoupper( $source_lang )
                    ) );
                    ?>
                </button>
                <span id="lf-repair-lf-lang-msg" style="margin-left:10px;font-weight:600;"></span>
            </div>

            <?php endif; ?>

        <?php endif; ?>
        <hr style="margin:24px 0 16px;">
        <?php
    }

    // =========================================================================
    // §6 — Rewrite rules
    // =========================================================================

    private static function render_rewrite_rules(): void {

        global $wp_rewrite;
        $all_rules = is_object( $wp_rewrite ) ? (array) $wp_rewrite->extra_rules_top : [];

        // Filter to only rules whose rewrite target references LF query vars.
        $lf_rules = array_filter(
            $all_rules,
            static fn( string $target ): bool => false !== strpos( $target, 'lf_lang' )
        );

        ?>
        <h2><?php esc_html_e( 'Rewrite Rules', 'lingua-forge' ); ?></h2>

        <p><?php esc_html_e( 'LF-owned entries currently registered in $wp_rewrite->extra_rules_top. Useful for diagnosing 404s or routing conflicts. Copy these when filing a bug report.', 'lingua-forge' ); ?></p>

        <details>
            <summary style="cursor:pointer;font-weight:600;margin-bottom:8px;">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: number of rewrite rules */
                    _n( '%d rule registered', '%d rules registered', count( $lf_rules ), 'lingua-forge' ),
                    count( $lf_rules )
                ) );
                ?>
            </summary>
            <?php if ( empty( $lf_rules ) ) : ?>
                <p class="description"><?php esc_html_e( 'No LF rewrite rules found. Flush permalinks if this is unexpected.', 'lingua-forge' ); ?></p>
            <?php else : ?>
                <pre id="lf-rewrite-rules-pre" style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px 14px;font-size:12px;overflow-x:auto;max-height:320px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"><?php
                    foreach ( $lf_rules as $pattern => $target ) {
                        echo esc_html( $pattern ) . "\n    → " . esc_html( $target ) . "\n\n";
                    }
                ?></pre>
            <?php endif; ?>
        </details>
        <hr style="margin:24px 0 16px;">
        <?php
    }

    // =========================================================================
    // §7 — Debug copy
    // =========================================================================

    private static function render_debug_copy(): void {

        $info = self::build_debug_text();
        ?>
        <h2><?php esc_html_e( 'Debug Info', 'lingua-forge' ); ?></h2>

        <p><?php esc_html_e( 'Copy and paste into a GitHub issue or support request.', 'lingua-forge' ); ?></p>

        <p>
            <button type="button" id="lf-copy-debug-btn" class="button">
                <?php esc_html_e( 'Copy system info', 'lingua-forge' ); ?>
            </button>
            <span id="lf-copy-debug-msg" style="margin-left:10px;font-size:12px;color:#46b450;"></span>
        </p>

        <textarea
            id="lf-debug-info-textarea"
            readonly
            rows="12"
            style="width:100%;max-width:720px;font-family:monospace;font-size:12px;resize:vertical;"
        ><?php echo esc_textarea( $info ); ?></textarea>

        <script>
        (function () {
            var copyBtn  = document.getElementById('lf-copy-debug-btn');
            var textarea = document.getElementById('lf-debug-info-textarea');
            var msg      = document.getElementById('lf-copy-debug-msg');
            var repairBtn = document.getElementById('lf-repair-lf-lang-btn');
            var repairMsg = document.getElementById('lf-repair-lf-lang-msg');

            if (copyBtn && textarea) {
                copyBtn.addEventListener('click', function () {
                    textarea.select();
                    try {
                        navigator.clipboard.writeText(textarea.value).then(function () {
                            msg.textContent = '<?php echo esc_js( __( 'Copied!', 'lingua-forge' ) ); ?>';
                            setTimeout(function () { msg.textContent = ''; }, 2500);
                        });
                    } catch (e) {
                        // Fallback for HTTP or older browsers
                        document.execCommand('copy');
                        msg.textContent = '<?php echo esc_js( __( 'Copied!', 'lingua-forge' ) ); ?>';
                        setTimeout(function () { msg.textContent = ''; }, 2500);
                    }
                });
            }

            if (repairBtn && repairMsg) {
                repairBtn.addEventListener('click', function () {
                    repairBtn.disabled = true;
                    repairMsg.style.color = '#646970';
                    repairMsg.textContent = '<?php echo esc_js( __( 'Repairing…', 'lingua-forge' ) ); ?>';
                    var data = new FormData();
                    data.append('action', 'linguaforge_repair_lf_lang');
                    data.append('nonce', repairBtn.dataset.nonce);
                    data.append('source', repairBtn.dataset.source);
                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function (r) { return r.json(); })
                        .then(function (resp) {
                            if (resp.success) {
                                // Hide the table and repair block; show the all-clear.
                                var tbl    = document.getElementById('lf-lang-coverage-table');
                                var repair = document.getElementById('lf-lang-coverage-repair');
                                if (tbl)    { tbl.style.display    = 'none'; }
                                if (repair) { repair.style.display = 'none'; }
                                var ok = document.getElementById('lf-lang-coverage-ok');
                                if (ok) { ok.style.display = ''; }
                            } else {
                                repairBtn.disabled = false;
                                repairMsg.style.color = '#d63638';
                                repairMsg.textContent = (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Repair failed.', 'lingua-forge' ) ); ?>';
                            }
                        })
                        .catch(function () {
                            repairBtn.disabled = false;
                            repairMsg.style.color = '#d63638';
                            repairMsg.textContent = '<?php echo esc_js( __( 'Network error.', 'lingua-forge' ) ); ?>';
                        });
                });
            }

            // Per-row "Exclude from routing" buttons.
            document.querySelectorAll('.lf-exclude-post-type-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var postType = btn.dataset.postType;
                    if (!confirm('<?php echo esc_js( __( 'Exclude this post type from language routing? Its posts will no longer appear in this list or receive _lf_lang. You can reverse this in Settings → Router → Query Filter Exclusions.', 'lingua-forge' ) ); ?>')) {
                        return;
                    }
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Excluding…', 'lingua-forge' ) ); ?>';
                    var data = new FormData();
                    data.append('action', 'linguaforge_exclude_post_type');
                    data.append('nonce', btn.dataset.nonce);
                    data.append('post_type', postType);
                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function (r) { return r.json(); })
                        .then(function (resp) {
                            if (resp.success) {
                                // Move the row to the excluded (muted) section visually.
                                var row = document.getElementById('lf-cov-row-' + postType);
                                if (row) {
                                    row.style.opacity = '0.55';
                                    var cells = row.querySelectorAll('td');
                                    if (cells[0]) {
                                        var badge = document.createElement('span');
                                        badge.style.cssText = 'margin-left:6px;font-size:11px;color:#646970;';
                                        badge.textContent = '<?php echo esc_js( __( 'excluded from routing', 'lingua-forge' ) ); ?>';
                                        cells[0].appendChild(badge);
                                    }
                                    if (cells[1]) { cells[1].style.color = '#646970'; }
                                    if (cells[2]) { cells[2].innerHTML = ''; }
                                }
                            } else {
                                btn.disabled = false;
                                btn.textContent = '<?php echo esc_js( __( 'Exclude from routing', 'lingua-forge' ) ); ?>';
                                alert((resp.data && resp.data.message) || '<?php echo esc_js( __( 'Failed to save exclusion.', 'lingua-forge' ) ); ?>');
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Exclude from routing', 'lingua-forge' ) ); ?>';
                            alert('<?php echo esc_js( __( 'Network error.', 'lingua-forge' ) ); ?>');
                        });
                });
            });
        }());
        </script>
        <?php
    }

    // =========================================================================
    // Debug text builder
    // =========================================================================

    private static function build_debug_text(): string {

        global $wpdb, $wp_rewrite;

        $router      = \LinguaForge\Router\Router::get_instance();
        $theme       = wp_get_theme();
        $is_fse      = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
        $lf_version  = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : '—';
        $structure   = (string) get_option( 'permalink_structure', '' );
        $mode        = (string) get_option( 'linguaforge_routing_mode', 'path' );
        $primary     = (string) get_option( 'linguaforge_primary_language', '' );
        $langs       = implode( ', ', array_map( 'strtoupper', $router->languages() ) );
        $wc_active   = class_exists( 'WooCommerce' );
        $wc_version  = $wc_active && defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A';
        if ( $wc_active ) {
            $dbg_order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
            if ( class_exists( $dbg_order_util ) && method_exists( $dbg_order_util, 'custom_orders_table_usage_is_enabled' ) ) {
                $dbg_hpos = (bool) $dbg_order_util::custom_orders_table_usage_is_enabled();
            } else {
                $dbg_hpos = 'yes' === (string) get_option( 'woocommerce_feature_custom_order_tables_enabled', 'no' );
            }
            $dbg_sync         = $dbg_hpos && 'yes' === (string) get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
            $dbg_storage_mode = ! $dbg_hpos ? 'Legacy CPT (wp_posts)' : ( $dbg_sync ? 'Shared / compatibility (HPOS + CPT sync)' : 'HPOS (custom tables)' );
        }

        $seo_plugins = [];
        if ( defined( 'WPSEO_VERSION' ) )      { $seo_plugins[] = 'Yoast SEO'; }
        if ( defined( 'RANK_MATH_VERSION' ) )  { $seo_plugins[] = 'Rank Math'; }
        if ( defined( 'AIOSEO_VERSION' ) )     { $seo_plugins[] = 'All in One SEO'; }
        if ( defined( 'SEOPRESS_VERSION' ) )   { $seo_plugins[] = 'SEOPress'; }

        $excluded = [
            'revision', 'auto-draft', 'nav_menu_item',
            'wp_navigation', 'wp_template', 'wp_template_part',
            'wp_global_styles', 'wp_block', 'wp_font_face',
            'wp_font_family', 'attachment',
        ];
        $placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic query for debug text; admin-only, no caching needed.
        $gap_rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders contains only '%s' literals built by array_fill(); all values are bound by the ...$excluded spread args.
                "SELECT post_type, COUNT(*) AS missing
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m
                       ON m.post_id = p.ID AND m.meta_key = '_lf_lang'
                 WHERE p.post_status = 'publish'
                   AND p.post_type NOT IN ({$placeholders})
                   AND m.meta_id IS NULL
                 GROUP BY p.post_type
                 ORDER BY missing DESC",
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                ...$excluded
            ),
            ARRAY_A
        );

        $all_rules = is_object( $wp_rewrite ) ? (array) $wp_rewrite->extra_rules_top : [];
        $lf_rules  = array_filter(
            $all_rules,
            static fn( string $t ): bool => false !== strpos( $t, 'lf_lang' )
        );

        $php_timeout   = (int) ini_get( 'max_execution_time' );
        $php_memory    = (string) ini_get( 'memory_limit' );
        $timeout_label = 0 === $php_timeout ? 'No PHP limit (server/FPM timeout still applies)' : $php_timeout . 's' . ( $php_timeout < 60 ? ' (WARN: <60s)' : '' );

        $ai_provider        = \LinguaForge\AI\Core\Config::provider();
        $ai_provider_labels = [
            'anthropic' => 'Anthropic (Claude)',
            'openai'    => 'OpenAI (GPT)',
            'gemini'    => 'Google (Gemini)',
        ];
        $ai_model_l  = \LinguaForge\AI\Core\Config::model( 'light' );
        $ai_model_q  = \LinguaForge\AI\Core\Config::model( 'quality' );
        $ai_preset   = (string) get_option( 'linguaforge_active_preset', 'standard' );
        $ai_quota    = (int) get_option( 'linguaforge_ai_daily_quota', 0 );

        $lines   = [];
        $lines[] = '=== Lingua Forge System Info ===';
        $lines[] = 'Generated: ' . current_time( 'c' );
        $lines[] = '';
        $lines[] = '-- Environment --';
        $lines[] = 'LF version     : ' . $lf_version;
        $lines[] = 'WP version     : ' . get_bloginfo( 'version' );
        $lines[] = 'PHP version    : ' . PHP_VERSION;
        $lines[] = 'PHP timeout    : ' . $timeout_label;
        $lines[] = 'PHP memory     : ' . $php_memory;
        $lines[] = 'Theme          : ' . $theme->get( 'Name' ) . ( $is_fse ? ' (FSE)' : ' (classic)' );
        $lines[] = 'URL mode       : ' . $mode;
        $lines[] = 'WP language    : ' . get_locale();
        $lines[] = 'Primary content: ' . strtoupper( $primary );
        $lines[] = 'All langs      : ' . $langs;
        $lines[] = 'Permalink      : ' . ( '' !== $structure ? $structure : '(plain — incompatible)' );
        $lines[] = '';
        $lines[] = '-- AI Configuration --';
        foreach ( $ai_provider_labels as $ai_slug => $ai_label ) {
            $ai_key_set = '' !== (string) \LinguaForge\AI\Core\KeyStore::get( $ai_slug );
            $active_tag = $ai_slug === $ai_provider ? ' [active]' : '';
            $lines[]    = $ai_label . $active_tag . ': ' . ( $ai_key_set ? 'Configured' : 'No key' );
        }
        $lines[] = 'Light model : ' . $ai_model_l;
        $lines[] = 'Quality model: ' . $ai_model_q;
        $lines[] = 'Preset      : ' . ucfirst( $ai_preset );
        $lines[] = 'Daily quota : ' . ( 0 === $ai_quota ? 'Unlimited' : $ai_quota . ' requests/day' );
        $lines[] = '';
        $lines[] = '-- SEO Plugins --';
        $lines[] = empty( $seo_plugins ) ? 'None detected' : implode( ', ', $seo_plugins );
        $lines[] = '';
        $lines[] = '-- WooCommerce --';
        if ( $wc_active ) {
            $lines[] = 'Active, version ' . $wc_version;
            $lines[] = 'Order storage   : ' . $dbg_storage_mode;
        } else {
            $lines[] = 'Not active';
        }
        $lines[] = '';
        $lines[] = '-- Active Plugins --';
        $active_plugin_files = (array) get_option( 'active_plugins', [] );
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            $network = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
            $active_plugin_files = array_unique( array_merge( $active_plugin_files, $network ) );
        }
        sort( $active_plugin_files );
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( $active_plugin_files as $pfile ) {
            $ppath = WP_PLUGIN_DIR . '/' . $pfile;
            $pname = $pfile;
            $pver  = '';
            if ( is_readable( $ppath ) ) {
                $pdata = get_plugin_data( $ppath, false, false );
                $pname = $pdata['Name'] ?: $pfile;
                $pver  = $pdata['Version'] ?: '';
            }
            $lines[] = $pname . ( $pver ? ' ' . $pver : '' );
        }
        $lines[] = '';
        $lines[] = '-- _lf_lang Gaps --';
        if ( empty( $gap_rows ) ) {
            $lines[] = 'None — all published posts have _lf_lang';
        } else {
            foreach ( $gap_rows as $row ) {
                $lines[] = (string) $row['post_type'] . ': ' . (int) $row['missing'] . ' missing';
            }
        }
        $lines[] = '';
        $lines[] = '-- LF Rewrite Rules (' . count( $lf_rules ) . ') --';
        foreach ( $lf_rules as $pattern => $target ) {
            $lines[] = $pattern . ' → ' . $target;
        }

        return implode( "\n", $lines );
    }

    // =========================================================================
    // AJAX — _lf_lang repair (§9.3)
    // =========================================================================

    public static function ajax_repair_lf_lang(): void {
        check_ajax_referer( 'linguaforge_repair_lf_lang', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ] );
        }

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        global $wpdb;

        // WP-internal types (hidden) + routing-excluded types (not repaired).
        $excluded = array_values( array_unique( array_merge(
            [
                'revision', 'auto-draft', 'nav_menu_item',
                'wp_navigation', 'wp_template', 'wp_template_part',
                'wp_global_styles', 'wp_block', 'wp_font_face',
                'wp_font_family', 'attachment',
            ],
            self::routing_excluded_post_types()
        ) ) );
        $placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );

        // Fetch all post IDs missing _lf_lang in one query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write operation; no meaningful caching possible for a one-shot repair.
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders contains only '%s' literals built by array_fill(); all values are bound by the ...$excluded spread args.
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m
                       ON m.post_id = p.ID AND m.meta_key = '_lf_lang'
                 WHERE p.post_status = 'publish'
                   AND p.post_type NOT IN ({$placeholders})
                   AND m.meta_id IS NULL",
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                ...$excluded
            )
        );

        $repaired = 0;
        foreach ( $post_ids as $post_id ) {
            update_post_meta( (int) $post_id, '_lf_lang', $source_lang );
            $repaired++;
        }

        wp_send_json_success( [
            'repaired' => $repaired,
            'message'  => sprintf(
                /* translators: 1: count of repaired posts, 2: source language code in upper case e.g. EN */
                _n(
                    '%1$d post tagged with _lf_lang = %2$s.',
                    '%1$d posts tagged with _lf_lang = %2$s.',
                    $repaired,
                    'lingua-forge'
                ),
                $repaired,
                strtoupper( $source_lang )
            ),
        ] );
    }

    /**
     * Add a post type to the routing exclusion list.
     *
     * Appends the requested slug to the `linguaforge_secondary_query_excluded_types`
     * option (comma-separated). QueryFilter::builtin_excluded_post_types() reads this
     * option on every front-end request, so the exclusion takes effect immediately
     * without a flush.
     */
    public static function ajax_exclude_post_type(): void {
        check_ajax_referer( 'linguaforge_exclude_post_type', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ] );
        }

        $post_type = sanitize_key( (string) ( $_POST['post_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
        if ( '' === $post_type ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'lingua-forge' ) ] );
        }

        $saved   = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
        $current = array_filter(
            array_map( 'sanitize_key', preg_split( '/[\s,]+/', $saved, -1, PREG_SPLIT_NO_EMPTY ) )
        );

        if ( ! in_array( $post_type, $current, true ) ) {
            $current[] = $post_type;
        }

        update_option( 'linguaforge_secondary_query_excluded_types', implode( ',', array_values( $current ) ), false );

        wp_send_json_success( [ 'post_type' => $post_type ] );
    }
}
