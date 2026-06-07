<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\CacheStatsPanel
 *
 * Renders the Translation Caching section on the AI Usage & Cache tab:
 * a tabbed UI covering the API Response Cache and Translation Memory.
 *
 * Also owns the two admin-post handlers for clearing each cache so that
 * the action → handler relationship is visible in one place without
 * consulting SettingsPage.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.1.9
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\TranslationMemory;

defined( 'ABSPATH' ) || exit;

class CacheStatsPanel {

    // =========================================================================
    // Render
    // =========================================================================

    /**
     * Output the Translation Caching section — success notices, nav tabs,
     * API Response Cache panel, Translation Memory panel, and the tab-switcher JS.
     */
    public static function render(): void {

        ?>
        <!-- ── Translation Caching ──────────────────────────────────── -->
        <hr>

        <h2><?php esc_html_e( 'Translation Caching', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Lingua Forge uses two independent caching layers to avoid redundant AI API calls. Use the tabs below to inspect and manage each one.',
                'lingua-forge'
            );
            ?>
        </p>

        <?php self::render_notices(); ?>

        <?php
        $cache_stats = CacheStore::stats();
        $tm_enabled  = (bool) get_option( 'linguaforge_translation_memory_enabled', false );
        $tm_stats    = TranslationMemory::stats();
        ?>

        <nav class="nav-tab-wrapper lf-cache-tabs" style="margin-bottom:1.5em;">
            <a href="#lf-tab-api-cache" class="nav-tab nav-tab-active" data-lf-tab="api-cache">
                <?php esc_html_e( 'API Response Cache', 'lingua-forge' ); ?>
            </a>
            <a href="#lf-tab-tm" class="nav-tab" data-lf-tab="tm">
                <?php esc_html_e( 'Translation Memory', 'lingua-forge' ); ?>
                <?php if ( ! $tm_enabled ) : ?>
                    <span style="color:#999;font-size:11px;margin-left:4px;"><?php esc_html_e( '(disabled)', 'lingua-forge' ); ?></span>
                <?php endif; ?>
            </a>
        </nav>

        <!-- ── Tab: API Response Cache ───────────────────────────── -->
        <div id="lf-tab-api-cache" class="lf-cache-tab-panel">

            <p class="description" style="margin-bottom:1em;">
                <?php
                esc_html_e(
                    'Per-post cache for AI-generated translations, meta descriptions, excerpts, and content. Entries are invalidated automatically when the content or AI provider/model changes.',
                    'lingua-forge'
                );
                ?>
            </p>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                style="margin-bottom:1.5em;"
            >
                <input type="hidden" name="action" value="linguaforge_save_api_cache_enabled">
                <?php wp_nonce_field( 'linguaforge_save_api_cache_enabled', 'linguaforge_save_api_cache_enabled_nonce' ); ?>
                <table class="form-table" role="presentation" style="margin-bottom:.5em;">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'API Response Cache', 'lingua-forge' ); ?>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="linguaforge_api_cache_enabled"
                                    value="1"
                                    <?php checked( (bool) get_option( 'linguaforge_api_cache_enabled', true ) ); ?>
                                >
                                <?php esc_html_e( 'Enable API response caching', 'lingua-forge' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'The API Response Cache stores the raw result of every AI request — translation, meta description, excerpt, and content generation — keyed by a hash of the inputs (content, language pair, provider, model). Disable during prompt tuning or when you need every request to reach the AI provider. Disabling does not delete cached entries — re-enabling restores them.', 'lingua-forge' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
            </form>

            <table class="widefat striped" style="max-width:480px;margin-bottom:1em;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cached API responses', 'lingua-forge' ); ?></th>
                        <td><strong><?php echo esc_html( number_format_i18n( $cache_stats['rows'] ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'API calls saved by cache', 'lingua-forge' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( number_format_i18n( $cache_stats['total_hits'] ) ); ?></strong>
                            <?php if ( $cache_stats['rows'] > 0 ) : ?>
                                <?php echo esc_html( sprintf(
                                    /* translators: %s is the average hits per cached entry. */
                                    __( '(avg %.1f hits/entry)', 'lingua-forge' ),
                                    $cache_stats['total_hits'] / $cache_stats['rows']
                                ) ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $cache_stats['oldest'] ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Oldest cached response', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( $cache_stats['oldest'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Most recent cached response', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( $cache_stats['newest'] ); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                onsubmit="return confirm('<?php echo esc_js( __( 'Clear all cached AI results? Future requests will trigger fresh API calls until the cache rebuilds.', 'lingua-forge' ) ); ?>');"
            >
                <input type="hidden" name="action" value="linguaforge_clear_ai_cache">
                <?php wp_nonce_field( 'linguaforge_clear_ai_cache', 'linguaforge_clear_ai_cache_nonce' ); ?>
                <?php submit_button( __( 'Clear API Response Cache', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
            </form>

        </div><!-- #lf-tab-api-cache -->

        <!-- ── Tab: Translation Memory ───────────────────────────── -->
        <div id="lf-tab-tm" class="lf-cache-tab-panel" style="display:none;">

            <p class="description" style="margin-bottom:1em;">
                <?php
                esc_html_e(
                    'Block-level translation cache shared across posts. When enabled, identical blocks (same markup, language pair, and glossary) are served from memory instead of calling the AI again. Glossary edits and Compliance preset changes automatically invalidate affected cached translations.',
                    'lingua-forge'
                );
                ?>
            </p>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                style="margin-bottom:1.5em;"
            >
                <input type="hidden" name="action" value="linguaforge_save_tm_enabled">
                <?php wp_nonce_field( 'linguaforge_save_tm_enabled', 'linguaforge_save_tm_enabled_nonce' ); ?>
                <table class="form-table" role="presentation" style="margin-bottom:.5em;">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Translation Memory', 'lingua-forge' ); ?>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="linguaforge_translation_memory_enabled"
                                    value="1"
                                    <?php checked( $tm_enabled ); ?>
                                >
                                <?php esc_html_e( 'Enable block-level translation cache reuse across posts', 'lingua-forge' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Currently skipped for posts that use block-comment attribute placeholders (wp:details summary fields, etc.) — they fall through to the existing single-call translation path.', 'lingua-forge' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
            </form>

            <table class="widefat striped" style="max-width:480px;margin-bottom:1em;">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cached block translations', 'lingua-forge' ); ?></th>
                        <td><strong><?php echo esc_html( number_format_i18n( $tm_stats['rows'] ) ); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Block translation reuses', 'lingua-forge' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( number_format_i18n( $tm_stats['total_hits'] ) ); ?></strong>
                            <?php if ( $tm_stats['rows'] > 0 ) : ?>
                                <span style="color:#646970"><?php echo esc_html( sprintf(
                                    /* translators: %s is the average hits per cached block. */
                                    __( '(avg %.1f hits/block)', 'lingua-forge' ),
                                    $tm_stats['total_hits'] / $tm_stats['rows']
                                ) ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $tm_stats['oldest'] !== '' ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Oldest cached block', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( $tm_stats['oldest'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Most recent cached block', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( $tm_stats['newest'] ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Approximate size', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( size_format( $tm_stats['bytes_estimate'] ) ?: '0 B' ); ?></td>
                    </tr>
                </tbody>
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
                    $tm_stats['rows'] > 0 ? [] : [ 'disabled' => 'disabled' ]
                ); ?>
            </form>

        </div><!-- #lf-tab-tm -->

        <script>
        ( function () {
            var LS_KEY  = 'lf_cache_tab';
            var nav     = document.querySelector( '.lf-cache-tabs' );
            var panels  = document.querySelectorAll( '.lf-cache-tab-panel' );

            if ( ! nav ) { return; }

            function activate( tabId ) {
                nav.querySelectorAll( '[data-lf-tab]' ).forEach( function ( a ) {
                    a.classList.toggle( 'nav-tab-active', a.dataset.lfTab === tabId );
                } );
                panels.forEach( function ( panel ) {
                    panel.style.display = ( panel.id === 'lf-tab-' + tabId ) ? '' : 'none';
                } );
                try { localStorage.setItem( LS_KEY, tabId ); } catch (e) {}
            }

            var params  = new URLSearchParams( window.location.search );
            var initial = 'api-cache';
            if ( params.has( 'lf_tm_cleared' ) || params.has( 'lf_tm_enabled_saved' ) ) {
                initial = 'tm';
            } else if ( params.has( 'lf_cache_cleared' ) || params.has( 'lf_api_cache_saved' ) ) {
                initial = 'api-cache';
            } else {
                try { initial = localStorage.getItem( LS_KEY ) || 'api-cache'; } catch (e) {}
            }
            activate( initial );

            nav.addEventListener( 'click', function ( e ) {
                var a = e.target.closest( '[data-lf-tab]' );
                if ( ! a ) { return; }
                e.preventDefault();
                activate( a.dataset.lfTab );
            } );
        } )();
        </script>
        <?php
    }

    /**
     * Render success notices for cache-cleared and TM-cleared redirects.
     */
    private static function render_notices(): void {

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag; no data modified.
        if ( isset( $_GET['lf_api_cache_saved'] ) ) :
            ?>
            <div class="notice notice-success is-dismissible"><p><?php
                esc_html_e( 'API Response Cache setting saved.', 'lingua-forge' );
            ?></p></div>
        <?php endif;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['lf_tm_enabled_saved'] ) ) :
            ?>
            <div class="notice notice-success is-dismissible"><p><?php
                esc_html_e( 'Translation Memory setting saved.', 'lingua-forge' );
            ?></p></div>
        <?php endif;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag; no data modified.
        if ( isset( $_GET['lf_cache_cleared'] ) ) :
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $count = absint( $_GET['lf_cache_cleared'] );
            ?>
            <div class="notice notice-success is-dismissible"><p><?php
                /* translators: %d: number of cache entries removed. */
                echo esc_html( sprintf( _n(
                    'AI cache cleared. %d entry was removed.',
                    'AI cache cleared. %d entries were removed.',
                    $count, 'lingua-forge'
                ), $count ) );
            ?></p></div>
        <?php endif;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['lf_tm_cleared'] ) ) :
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $count = absint( $_GET['lf_tm_cleared'] );
            ?>
            <div class="notice notice-success is-dismissible"><p><?php
                /* translators: %d: number of cached blocks removed. */
                echo esc_html( sprintf( _n(
                    'Translation Memory cleared. %d cached block was removed.',
                    'Translation Memory cleared. %d cached blocks were removed.',
                    $count, 'lingua-forge'
                ), $count ) );
            ?></p></div>
        <?php endif;
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    /**
     * Handle "Clear API Response Cache" form submission.
     */
    public static function handle_clear_ai_cache(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_clear_ai_cache', 'linguaforge_clear_ai_cache_nonce' );

        $count = CacheStore::clear_all();

        wp_safe_redirect( add_query_arg(
            'lf_cache_cleared',
            (int) $count,
            admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
        ) );
        exit;
    }

    /**
     * Handle "Clear Translation Memory" form submission.
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
        ) );
        exit;
    }

    /**
     * Handle "Save" for the API Response Cache enable/disable toggle.
     *
     * Registered on admin_post_linguaforge_save_api_cache_enabled.
     */
    public static function handle_save_api_cache_enabled(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_save_api_cache_enabled', 'linguaforge_save_api_cache_enabled_nonce' );

        update_option(
            'linguaforge_api_cache_enabled',
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
            ! empty( $_POST['linguaforge_api_cache_enabled'] ) ? 1 : 0,
            false
        );

        wp_safe_redirect( add_query_arg(
            'lf_api_cache_saved', '1',
            admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
        ) );
        exit;
    }

    /**
     * Handle "Save" for the Translation Memory enable/disable toggle.
     *
     * Registered on admin_post_linguaforge_save_tm_enabled.
     */
    public static function handle_save_tm_enabled(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_save_tm_enabled', 'linguaforge_save_tm_enabled_nonce' );

        update_option(
            'linguaforge_translation_memory_enabled',
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
            ! empty( $_POST['linguaforge_translation_memory_enabled'] ) ? 1 : 0,
            false
        );

        wp_safe_redirect( add_query_arg(
            'lf_tm_enabled_saved', '1',
            admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
        ) );
        exit;
    }
}
