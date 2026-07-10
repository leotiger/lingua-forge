<?php

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Admin\Settings\Panels\CacheStatsPanel;
use LinguaForge\AI\Admin\Settings\Panels\DebugFilesPanel;
use LinguaForge\AI\Admin\Settings\Panels\LanguageOverridesPanel;
use LinguaForge\AI\Admin\Settings\Panels\UninstallSettingsPanel;
use LinguaForge\AI\Admin\Settings\Panels\HreflangPanel;
use LinguaForge\AI\Admin\Settings\Panels\OpenGraphPanel;
use LinguaForge\AI\Admin\Settings\Panels\SocialSharePanel;
use LinguaForge\AI\Admin\Settings\Panels\SchemaPanel;
use LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel;
use LinguaForge\AI\Admin\Settings\Panels\SitemapPanel;
use LinguaForge\AI\Admin\Settings\Panels\WooCommerceSeoPanel;
use LinguaForge\AI\Admin\Settings\Tabs\AiUsageTab;
use LinguaForge\AI\Admin\Settings\Tabs\AiProviderTab;
use LinguaForge\AI\Admin\Settings\Tabs\BehaviorTab;
use LinguaForge\AI\Admin\Settings\Tabs\GlossaryTab;
use LinguaForge\AI\Admin\Settings\Tabs\LimitsTab;
use LinguaForge\AI\Admin\Settings\Tabs\MaintenanceTab;
use LinguaForge\AI\Admin\Settings\Tabs\RouterTab;
use LinguaForge\AI\Admin\Settings\Tabs\SystemTab;
use LinguaForge\AI\Admin\Settings\Panels\SystemPanel;
use LinguaForge\AI\Admin\Settings\Tabs\SeoTab;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\Config;

defined('ABSPATH') || exit;

/**
 * Lingua Forge — top-level admin menu page
 *
 * Bootstrap class: registers the admin menu, enqueues assets, dispatches
 * the settings-form save, and delegates every tab's render and standalone
 * handlers to the per-tab classes under Admin\Settings\Tabs\.
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

    public  const PAGE_SLUG    = 'lingua-forge';
    private const NONCE_ACTION = 'linguaforge_save_settings';
    private const NONCE_FIELD  = 'linguaforge_nonce';
    public  const OPT_PROVIDER = 'linguaforge_provider';

    /**
     * Provider slugs → human labels.
     * Defined as a method (not a constant) so labels can be wrapped with __().
     * Public so tab classes can call SettingsPage::providers().
     *
     * @return array<string, string>
     */
    public static function providers(): array {

        $list = [
            'anthropic' => __( 'Anthropic (Claude)', 'lingua-forge' ),
            'openai'    => __( 'OpenAI (GPT)',        'lingua-forge' ),
            'gemini'    => __( 'Google (Gemini)',     'lingua-forge' ),
        ];

        // WordPress AI Client is only available on WP 7.0+ — omit the option
        // on older installs so the dropdown doesn't show a non-functional choice.
        if ( function_exists( 'wp_ai_client_prompt' ) ) {
            $list['wp-ai-client'] = __( 'WordPress AI Client (WP 7.0+)', 'lingua-forge' );
        }

        return $list;
    }

    /**
     * Model tiers: slug → label and description shown in the settings table.
     * Public so handle_save() and tab classes can use it.
     *
     * @return array<string, array{label: string, used_by: string}>
     */
    public static function tiers(): array {

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

    /**
     * Whitelisted capability choices for the "Minimum role" Settings field.
     * Public so handle_save() and LimitsTab can both reference it.
     *
     * @return array<string, string>
     */
    public static function capability_choices(): array {

        return LimitsTab::capability_choices();
    }

    // ── Initialisation ────────────────────────────────────────────────────────

    public static function init(): void {

        add_action('admin_menu',                    [self::class, 'register_menu']);
        add_action('admin_post_' . self::PAGE_SLUG, [self::class, 'handle_save']);

        // "Settings" link on the Plugins overview page (wp-admin/plugins.php).
        add_filter(
            'plugin_action_links_' . plugin_basename( LINGUAFORGE_FILE ),
            [ self::class, 'add_action_links' ]
        );

        // Language override file management — handlers co-located with LanguageOverridesPanel
        add_action('admin_post_linguaforge_upload_i18n_override', [LanguageOverridesPanel::class, 'handle_upload_override']);
        add_action('admin_post_linguaforge_delete_i18n_override', [LanguageOverridesPanel::class, 'handle_delete_override']);
        add_action('admin_post_linguaforge_copy_loco_override',   [LanguageOverridesPanel::class, 'handle_copy_loco_override']);

        // AI cache + TM maintenance — handlers co-located with CacheStatsPanel
        add_action('admin_post_linguaforge_clear_ai_cache',       [CacheStatsPanel::class, 'handle_clear_ai_cache']);
        add_action('admin_post_linguaforge_clear_debug_files',    [DebugFilesPanel::class, 'handle_clear_debug_files']);
        add_action('admin_post_linguaforge_save_debug_setting',   [DebugFilesPanel::class, 'handle_save_debug_setting']);

        // Glossary management (§4.6)
        add_action('admin_post_linguaforge_glossary_add',    [GlossaryTab::class, 'handle_glossary_add']);
        add_action('admin_post_linguaforge_glossary_delete', [GlossaryTab::class, 'handle_glossary_delete']);

        // Translation Memory maintenance (§4.5) — handler co-located with CacheStatsPanel
        add_action('admin_post_linguaforge_clear_translation_memory', [CacheStatsPanel::class, 'handle_clear_translation_memory']);

        // Uninstall behaviour toggle — handler co-located with UninstallSettingsPanel
        add_action('admin_post_linguaforge_save_uninstall_setting',   [UninstallSettingsPanel::class, 'handle_save_uninstall_setting']);

        // SEO tab — handlers co-located with panel classes
        add_action('admin_post_linguaforge_save_seo_hreflang',      [HreflangPanel::class,     'handle_save']);
        add_action('admin_post_linguaforge_save_seo_og',            [OpenGraphPanel::class,    'handle_save']);
        add_action('admin_post_linguaforge_save_seo_social_share',  [SocialSharePanel::class,     'handle_save']);
        add_action('admin_post_linguaforge_save_seo_wc',            [WooCommerceSeoPanel::class,  'handle_save']);
        add_action('admin_post_linguaforge_save_seo_schema',        [SchemaPanel::class,           'handle_save']);
        add_action('admin_post_linguaforge_save_seo_sitemap',       [SitemapPanel::class,          'handle_save']);
        add_action('admin_post_linguaforge_flush_sitemap_cache',    [SitemapPanel::class,          'handle_flush_cache']);
        add_action('admin_post_linguaforge_indexnow_submit',        [SitemapPanel::class,          'handle_indexnow_submit']);
        add_action('admin_post_linguaforge_update_robots_txt',      [SitemapPanel::class,          'handle_update_robots']);
        add_action('admin_post_linguaforge_save_seo_analysis',       [SeoAnalysisPanel::class,      'handle_save_analysis_settings']);

        // SEO Analysis AJAX
        add_action('wp_ajax_linguaforge_seo_analyze',    [SeoAnalysisPanel::class, 'ajax_analyze']);
        add_action('wp_ajax_linguaforge_seo_get_posts',  [SeoAnalysisPanel::class, 'ajax_get_posts']);
        add_action('wp_ajax_linguaforge_seo_ai_analyze',    [SeoAnalysisPanel::class, 'ajax_ai_analyze']);
        add_action('wp_ajax_linguaforge_seo_batch_analyze', [SeoAnalysisPanel::class, 'ajax_batch_analyze']);

        // AI Usage & Cache tab — Translation Caching toggle saves
        add_action('admin_post_linguaforge_save_api_cache_enabled', [CacheStatsPanel::class, 'handle_save_api_cache_enabled']);
        add_action('admin_post_linguaforge_save_tm_enabled',        [CacheStatsPanel::class, 'handle_save_tm_enabled']);

        // Language Router tab
        add_action('admin_post_linguaforge_save_router_settings', [RouterTab::class, 'handle_save_router_settings']);
        add_action('admin_post_linguaforge_flush_permalinks',      [RouterTab::class, 'handle_flush_permalinks']);
        add_action('admin_post_linguaforge_uninstall_language',    [RouterTab::class, 'handle_uninstall_language']);
        add_action('wp_ajax_linguaforge_get_available_languages',  [RouterTab::class, 'ajax_get_available_languages']);
        add_action('wp_ajax_linguaforge_install_language',         [RouterTab::class, 'ajax_install_language']);
        RouterTab::register_fse_hooks();

        // System tab — _lf_lang repair AJAX
        SystemPanel::register_hooks();

        // Test-connection AJAX endpoint — scoped to logged-in admins via the
        // capability check inside the handler.
        add_action('wp_ajax_linguaforge_test_provider', [AiProviderTab::class, 'ajax_test_provider']);

        // Contextual help tabs (WP_Screen::add_help_tab).
        SettingsHelp::init();

        // Settings-screen-only asset enqueue.
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_settings_assets']);
    }

    /**
     * Enqueue scripts and styles used exclusively on the Settings page.
     *
     * Scoped to the Settings → Lingua Forge screen only (matched via the
     * $hook_suffix WordPress hands to admin_enqueue_scripts).
     */
    public static function enqueue_settings_assets(string $hook_suffix): void {

        // Hook suffix for a top-level menu page registered via add_menu_page
        // is "toplevel_page_{slug}".
        if ($hook_suffix !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }

        $version = defined('LINGUAFORGE_VERSION') ? LINGUAFORGE_VERSION : false;

        wp_enqueue_script(
            'linguaforge-settings-tabs',
            LINGUAFORGE_AI_URL . '/assets/settings-tabs.js',
            [],
            $version,
            true
        );

        wp_enqueue_script(
            'linguaforge-test-connection',
            LINGUAFORGE_AI_URL . '/assets/test-connection.js',
            ['wp-i18n'],
            $version,
            true
        );

        wp_localize_script('linguaforge-test-connection', 'linguaForgeTestConnection', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('linguaforge_test_provider'),
            'strings'   => [
                'testing'    => __( 'Testing…',          'lingua-forge' ),
                'ok'         => __( '✓ Connection OK',   'lingua-forge' ),
                'fail'       => __( '✗ Failed:',         'lingua-forge' ),
                'noResponse' => __( 'No response from provider — check the error log for details.', 'lingua-forge' ),
                'network'    => __( 'Network error — could not reach the WordPress AJAX endpoint.',  'lingua-forge' ),
            ],
        ]);

        // Router tab — language fetch + install JS.
        wp_enqueue_script(
            'linguaforge-router-tab',
            LINGUAFORGE_AI_URL . '/assets/router-tab.js',
            ['jquery'],
            $version,
            true
        );
        wp_add_inline_script(
            'linguaforge-router-tab',
            'var lfRouterTab = ' . wp_json_encode( [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'fetchNonce'    => wp_create_nonce( 'linguaforge_get_available_languages' ),
                'installNonce'  => wp_create_nonce( 'linguaforge_install_language' ),
                'scaffoldNonce'     => wp_create_nonce( 'linguaforge_scaffold_template' ),
                'scaffoldPartNonce' => wp_create_nonce( 'linguaforge_scaffold_template_part' ),
                'translateNonce'    => wp_create_nonce( 'linguaforge_translate_fse_content' ),
                'fixLinksNonce'     => wp_create_nonce( 'linguaforge_fix_fse_links' ),
                'fixPartsNonce'     => wp_create_nonce( 'linguaforge_fix_fse_parts' ),
                'translateNavNonce' => wp_create_nonce( 'linguaforge_translate_fse_navigation' ),
                'fixNavRefsNonce'   => wp_create_nonce( 'linguaforge_fix_fse_nav_refs' ),
                'patternNonce'      => wp_create_nonce( 'linguaforge_translate_pattern' ),
                'strings'           => [
                    'loading'           => __( 'Loading…',                'lingua-forge' ),
                    'installing'        => __( 'Installing…',             'lingua-forge' ),
                    'installed'         => __( '✓ Language installed.',   'lingua-forge' ),
                    'error'             => __( '✗ Error:',                'lingua-forge' ),
                    'selectPlaceholder' => __( '— select a language —',   'lingua-forge' ),
                    'noModify'          => __( 'Language installation is disabled on this server (DISALLOW_FILE_MODS is set).', 'lingua-forge' ),
                    'creating'          => __( 'Creating…',                         'lingua-forge' ),
                    'allDone'           => __( '✓ All templates created.',            'lingua-forge' ),
                    'allFail'           => __( 'Some templates could not be created.','lingua-forge' ),
                    'recreate'          => __( 'Re-create',                                          'lingua-forge' ),
                    'recreating'        => __( 'Recreating…',                                        'lingua-forge' ),
                    'allRecreated'      => __( '✓ All templates recreated.',                          'lingua-forge' ),
                    'recreateFail'      => __( 'Some templates could not be recreated.',              'lingua-forge' ),
                    'recreateConfirm'   => __( 'This overwrites this template with a fresh copy from the active theme, discarding any Site Editor customisations made to it. This cannot be undone. Continue?', 'lingua-forge' ),
                    'recreateAllConfirm' => __( 'This overwrites every template with a fresh copy from the active theme, discarding any Site Editor customisations made to them. This cannot be undone. Continue?', 'lingua-forge' ),
                    'allPartsRecreated'      => __( '✓ All parts recreated.',                          'lingua-forge' ),
                    'partsRecreateFail'      => __( 'Some parts could not be recreated.',              'lingua-forge' ),
                    'recreatePartConfirm'    => __( 'This overwrites this template part with a fresh copy from the active theme, discarding any Site Editor customisations made to it. This cannot be undone. Continue?', 'lingua-forge' ),
                    'recreateAllPartsConfirm' => __( 'This overwrites every template part with a fresh copy from the active theme, discarding any Site Editor customisations made to them. This cannot be undone. Continue?', 'lingua-forge' ),
                    'allPartsDone'      => __( '✓ All parts created.',                           'lingua-forge' ),
                    'allPartsFail'      => __( 'Some parts could not be created.',               'lingua-forge' ),
                    'translate'         => __( 'Translate',                                      'lingua-forge' ),
                    'translating'       => __( 'Translating…',                                   'lingua-forge' ),
                    'allTranslated'     => __( '✓ All translated.',                              'lingua-forge' ),
                    'translateFail'     => __( 'Some translations failed.',                      'lingua-forge' ),
                    'translateWarning'  => __( 'Review carefully — links and slugs not updated.','lingua-forge' ),
                    'fixLinks'          => __( 'Fix Links',                                         'lingua-forge' ),
                    'fixingLinks'       => __( 'Fixing…',                                           'lingua-forge' ),
                    'linksFixed'        => __( '✓ Links fixed.',                                    'lingua-forge' ),
                    'linksFail'         => __( 'Some link fixes failed.',                           'lingua-forge' ),
                    'fixParts'          => __( 'Fix Parts',                                         'lingua-forge' ),
                    'fixingParts'       => __( 'Fixing…',                                           'lingua-forge' ),
                    'partsFixed'        => __( '✓ Parts fixed.',                                    'lingua-forge' ),
                    'partsFail'         => __( 'Some part fixes failed.',                           'lingua-forge' ),
                    'retranslate'       => __( 'Re-translate',                                      'lingua-forge' ),
                    'translateNav'      => __( 'Translate',                                         'lingua-forge' ),
                    'translatingNav'    => __( 'Translating…',                                      'lingua-forge' ),
                    'fixNavRefs'        => __( 'Fix Nav',                                            'lingua-forge' ),
                    'fixingNavRefs'     => __( 'Fixing…',                                            'lingua-forge' ),
                    'navRefsFixed'      => __( '✓ Nav refs fixed.',                                  'lingua-forge' ),
                    'navRefsFail'         => __( 'Some nav ref fixes failed.',                         'lingua-forge' ),
                    'translatePattern'    => __( 'Translate',                                            'lingua-forge' ),
                    'translatingPattern'  => __( 'Translating…',                                        'lingua-forge' ),
                    'patternTranslated'   => __( '✓ Pattern translated.',                               'lingua-forge' ),
                    'patternFail'         => __( 'Pattern translation failed.',                          'lingua-forge' ),
                    'translationSaved'    => __( '✓ Translation saved',                                  'lingua-forge' ),
                    'view'                => __( 'View',                                                 'lingua-forge' ),
                    'hide'                => __( 'Hide',                                                 'lingua-forge' ),
                    'stepRecreateTemplates' => __( 'Re-create all templates', 'lingua-forge' ),
                    'stepRecreateParts'     => __( 'Re-create all parts',     'lingua-forge' ),
                    'stepTranslateAll'      => __( 'Translate all',           'lingua-forge' ),
                    'stepFixAllParts'       => __( 'Fix all parts',           'lingua-forge' ),
                    'stepFixAllLinks'       => __( 'Fix all links',           'lingua-forge' ),
                    /* translators: {total}: number of active languages. Placeholder, not a printf %s — substituted client-side in fse-global-actions.js. */
                    'globalConfirm'         => __( 'This runs Re-create all templates, Re-create all parts, Translate all, Fix all parts, and Fix all links for every one of your {total} active languages, one language at a time. It overwrites existing templates and parts with fresh copies from the active theme (discarding Site Editor customisations) and re-translates content — none of this can be undone. Translate all makes real AI API calls for every template and part, which may take a while and may incur cost depending on your provider. Continue?', 'lingua-forge' ),
                    'globalStarting'        => __( 'Starting…', 'lingua-forge' ),
                    /* translators: {lang}, {index}, {total}, {step}: placeholders substituted client-side in fse-global-actions.js. */
                    'globalProgress'        => __( 'Processing {lang} ({index} of {total}) — {step}…', 'lingua-forge' ),
                    'globalCancelled'       => __( 'Cancelled.', 'lingua-forge' ),
                    /* translators: {done}, {total}: placeholders substituted client-side in fse-global-actions.js. */
                    'globalProcessedOf'     => __( '{done} of {total} languages processed.', 'lingua-forge' ),
                    'globalDoneWithIssues'  => __( '⚠ Done with issues.', 'lingua-forge' ),
                    /* translators: {total}: number of active languages. Placeholder substituted client-side in fse-global-actions.js. */
                    'globalAllDone'         => __( '✓ All {total} languages processed.', 'lingua-forge' ),
                ],
            ] ) . ';',
            'before'
        );

        // FSE localisation JS — each file handles one concern, depends on the
        // router-tab script so the lfRouterTab data object is always available.
        $fse_scripts = [
            'linguaforge-fse-scaffold'    => 'fse-scaffold.js',
            'linguaforge-fse-translate'   => 'fse-translate.js',
            'linguaforge-fse-link-fixer'  => 'fse-link-fixer.js',
            'linguaforge-fse-part-fixer'  => 'fse-part-fixer.js',
            'linguaforge-fse-patterns'    => 'fse-patterns.js',
        ];
        foreach ( $fse_scripts as $handle => $file ) {
            wp_enqueue_script(
                $handle,
                LINGUAFORGE_AI_URL . '/assets/' . $file,
                [ 'jquery', 'linguaforge-router-tab' ],
                $version,
                true
            );
        }

        // Global cross-language orchestrator — depends on all four FSE
        // action scripts above, since it calls the row-level functions they
        // expose on window.lfFseActions rather than duplicating any AJAX logic.
        wp_enqueue_script(
            'linguaforge-fse-global-actions',
            LINGUAFORGE_AI_URL . '/assets/fse-global-actions.js',
            [
                'jquery',
                'linguaforge-router-tab',
                'linguaforge-fse-scaffold',
                'linguaforge-fse-translate',
                'linguaforge-fse-link-fixer',
                'linguaforge-fse-part-fixer',
            ],
            $version,
            true
        );

        // Preset preview — shows each preset's built-in addendum text when the
        // Global AI Preset dropdown changes, so editors can see what the preset
        // does and learn the format for writing their own custom instructions.
        wp_enqueue_script(
            'linguaforge-preset-preview',
            LINGUAFORGE_AI_URL . '/assets/preset-preview.js',
            [],
            $version,
            true
        );

        $preset_addenda = [];
        foreach ( array_keys( Config::presets() ) as $key ) {
            $preset_addenda[ $key ] = Config::default_preset_addendum( $key );
        }

        wp_add_inline_script(
            'linguaforge-preset-preview',
            'var lfPresetData = ' . wp_json_encode( [
                'presets' => $preset_addenda,
                'strings' => [
                    'label'          => __( 'Built-in preset instructions:', 'lingua-forge' ),
                    'noInstructions' => __( 'No built-in instructions — each AI feature uses its own tuned defaults. Fill in the Custom prompt instructions field below to add your own site-wide rules.', 'lingua-forge' ),
                ],
            ] ) . ';',
            'before'
        );

        // SEO Analysis panel JS.
        wp_enqueue_script(
            'linguaforge-seo-analysis',
            LINGUAFORGE_AI_URL . '/assets/seo-analysis.js',
            [],
            $version,
            true
        );
        $seo_profiles_list = [];
        foreach ( SeoAnalysisPanel::profiles() as $key => $prof ) {
            $seo_profiles_list[] = [ 'value' => $key, 'label' => $prof['label'] ];
        }
        wp_localize_script( 'linguaforge-seo-analysis', 'lfSeoAnalysis', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'linguaforge_seo_analyze' ),
            'profiles' => $seo_profiles_list,
            'strings'  => [
                'titleLabel'    => __( 'Title',            'lingua-forge' ),
                'metaDesc'      => __( 'Meta description', 'lingua-forge' ),
                'wordCount'     => __( 'Word count',       'lingua-forge' ),
                'readTime'      => __( 'Reading time',     'lingua-forge' ),
                'headings'      => __( 'Headings',         'lingua-forge' ),
                'images'        => __( 'Images',           'lingua-forge' ),
                'links'         => __( 'Links',            'lingua-forge' ),
                'overallScore'  => __( 'Overall SEO score', 'lingua-forge' ),
                'metric'        => __( 'Metric',           'lingua-forge' ),
                'finding'       => __( 'Finding',          'lingua-forge' ),
                'title'         => __( 'Title',            'lingua-forge' ),
                'type'          => __( 'Type',             'lingua-forge' ),
                'modified'      => __( 'Modified',         'lingua-forge' ),
                'profile'             => __( 'Profile',          'lingua-forge' ),
                'analysePlaceholder'  => __( 'Analyse…',         'lingua-forge' ), // legacy; kept for compat
                'autoDetect'          => __( '— Auto-detect —',  'lingua-forge' ),
                'edit'                => __( 'edit',             'lingua-forge' ),
                'noPostsFound'  => __( 'No published posts found for the selected filters.', 'lingua-forge' ),
                'usedSource'    => __( 'No translation found — analyzed the source language version.', 'lingua-forge' ),
                'requestFailed' => __( 'Analysis request failed. Please try again.', 'lingua-forge' ),
                'justNow'            => __( 'Just now',                                   'lingua-forge' ),
                'score'              => __( 'Score',                                     'lingua-forge' ),
                'sourceTitle'        => __( 'Source title',                              'lingua-forge' ),
                'parityHeading'      => __( 'Multilingual SEO overview',                 'lingua-forge' ),
                'parityHint'         => __( 'Scores are a signal, not a verdict. Some content is structurally limited — very short pages, landing pages with little body text, or pages whose purpose is navigation rather than information may score lower by nature. Use this overview to spot genuine parity gaps across languages, not to chase a number.', 'lingua-forge' ),
                'wcSystemPageNotice' => __( 'This is a WooCommerce system page (Shop, Cart, Checkout, etc.). Its content is managed by WooCommerce — the score reflects structural signals only, not user-editable SEO content.', 'lingua-forge' ),
            ],
        ] );

        // Settings page styles.
        wp_enqueue_style(
            'linguaforge-settings',
            LINGUAFORGE_AI_URL . '/assets/settings.css',
            [],
            $version
        );
    }

    public static function register_menu(): void {

        add_menu_page(
            'Lingua Forge',
            'Lingua Forge',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render'],
            'dashicons-translation',
            30
        );
    }

    /**
     * Prepend a "Settings" action link on the Plugins overview page.
     *
     * @param array<int|string, string> $links Existing action links.
     * @return array<int|string, string>
     */
    public static function add_action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
            esc_html__( 'Settings', 'lingua-forge' )
        );
        array_unshift( $links, $settings_link );
        return $links;
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
                wp_unslash( $_POST["linguaforge_key_{$slug}"] ?? '' )
            );

            if ($new_key !== '') {
                KeyStore::set($slug, $new_key);
            }
            // If the field was left blank, the existing key is preserved.
        }

        // ── AI Limits & Security ──────────────────────────────────────────────
        // Daily quota: 0 (or empty) = unlimited. Negative values are clamped.
        $daily_quota = intval( wp_unslash( $_POST['linguaforge_ai_daily_quota'] ?? 0 ) );
        update_option(
            'linguaforge_ai_daily_quota',
            max(0, $daily_quota),
            false
        );

        // Required capability: must be one of the whitelisted choices.
        // Defending against arbitrary capability strings here so a misclick
        // in the dropdown can't lock everyone out via an unknown cap name.
        $cap = sanitize_key( wp_unslash( $_POST['linguaforge_required_capability'] ?? 'edit_posts' ) );
        $allowed_caps = array_keys(self::capability_choices());
        if (!in_array($cap, $allowed_caps, true)) {
            $cap = 'edit_posts';
        }
        update_option('linguaforge_required_capability', $cap, false);

        // ── Behavior — Block Editor restrictions (§2.7) ───────────────────────
        // Checkboxes: absent in $_POST = unchecked = restriction stays ON.
        update_option(
            'linguaforge_block_editor_allow_lock_blocks',
            !empty($_POST['linguaforge_block_editor_allow_lock_blocks']) ? 1 : 0,
            false
        );
        update_option(
            'linguaforge_block_editor_allow_template_mode',
            !empty($_POST['linguaforge_block_editor_allow_template_mode']) ? 1 : 0,
            false
        );

        // ── Behavior — Sync safeguard (general, every non-WooCommerce post type) ──
        // Checkbox: absent in $_POST = unchecked = restriction stays ON (default).
        update_option(
            'linguaforge_allow_secondary_sync',
            !empty($_POST['linguaforge_allow_secondary_sync']) ? 1 : 0,
            false
        );

        // ── Behavior — WooCommerce Sync safeguard ─────────────────────────────
        // Independent of the general one above. Checkbox: absent in $_POST =
        // unchecked = restriction stays ON (default).
        update_option(
            'linguaforge_wc_allow_secondary_sync',
            !empty($_POST['linguaforge_wc_allow_secondary_sync']) ? 1 : 0,
            false
        );

        // ── Behavior — AI preset (replaces old compliance toggle) ────────────
        $preset_raw   = sanitize_key($_POST['linguaforge_active_preset'] ?? '');
        $valid_presets = array_keys(\LinguaForge\AI\Core\Config::presets());
        update_option(
            'linguaforge_active_preset',
            in_array($preset_raw, $valid_presets, true) ? $preset_raw : 'standard',
            false
        );

        // Per-preset addenda: editable system-prompt instructions for each
        // non-standard preset.  Empty string = use the built-in PHP default.
        foreach ( ['technical', 'legal', 'creative'] as $preset_key ) {
            $opt_key  = 'linguaforge_preset_addendum_' . $preset_key;
            $addendum = sanitize_textarea_field(
                (string) wp_unslash( $_POST[ $opt_key ] ?? '' )
            );
            update_option( $opt_key, $addendum, false );
        }

        // ── Model overrides ───────────────────────────────────────────────────
        // Store whatever the admin submitted (even empty string).
        // Config::model() treats an empty stored value as "use built-in default",
        // so clearing a field in the form is how you reset to the default.
        foreach (array_keys(self::providers()) as $slug) {
            foreach (array_keys(self::tiers()) as $tier) {

                $option_key  = "linguaforge_model_{$slug}_{$tier}";
                $model_value = sanitize_text_field(
                    wp_unslash( $_POST[$option_key] ?? '' )
                );

                // Allow saving an empty string to reset to the built-in default.
                update_option($option_key, $model_value, false);
            }
        }

        // ── Translation limits ────────────────────────────────────────────────
        // Store as integers; 0 / empty means "use built-in default".

        $translation_tier = sanitize_key($_POST['linguaforge_translation_tier'] ?? '');
        update_option(
            'linguaforge_translation_tier',
            in_array($translation_tier, ['light', 'quality'], true) ? $translation_tier : '',
            false
        );

        $max_tokens = intval( wp_unslash( $_POST['linguaforge_translation_max_tokens'] ?? 0 ) );
        update_option(
            'linguaforge_translation_max_tokens',
            $max_tokens > 0 ? $max_tokens : '',
            false
        );

        $max_input = intval( wp_unslash( $_POST['linguaforge_translation_max_input_chars'] ?? 0 ) );
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

        $qt_tokens = intval( wp_unslash( $_POST['linguaforge_quick_translate_max_tokens'] ?? 0 ) );
        update_option(
            'linguaforge_quick_translate_max_tokens',
            $qt_tokens > 0 ? $qt_tokens : '',
            false
        );

        $qt_input = intval( wp_unslash( $_POST['linguaforge_quick_translate_max_input_chars'] ?? 0 ) );
        update_option(
            'linguaforge_quick_translate_max_input_chars',
            $qt_input > 0 ? $qt_input : '',
            false
        );

        // ── Content Generator limits ──────────────────────────────────────────

        $cg_tokens = intval( wp_unslash( $_POST['linguaforge_content_generator_max_tokens'] ?? 0 ) );
        update_option(
            'linguaforge_content_generator_max_tokens',
            $cg_tokens > 0 ? $cg_tokens : '',
            false
        );

        $cg_hints = intval( wp_unslash( $_POST['linguaforge_content_generator_max_hints_chars'] ?? 0 ) );
        update_option(
            'linguaforge_content_generator_max_hints_chars',
            $cg_hints > 0 ? $cg_hints : '',
            false
        );

        $cg_context = intval( wp_unslash( $_POST['linguaforge_content_generator_max_context_chars'] ?? 0 ) );
        update_option(
            'linguaforge_content_generator_max_context_chars',
            $cg_context > 0 ? $cg_context : '',
            false
        );

        wp_safe_redirect(
            add_query_arg(
                'linguaforge_saved',
                '1',
                admin_url('admin.php?page=' . self::PAGE_SLUG)
            )
        );
        exit;
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render(): void {

        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">

            <h1><?php esc_html_e('Lingua Forge — Settings', 'lingua-forge'); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after a successful save; no data is processed here.
            if (!empty($_GET['linguaforge_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'lingua-forge'); ?></p>
                </div>
            <?php endif; ?>

            <!-- ── Tab navigation ──────────────────────────────────── -->
            <h2 class="nav-tab-wrapper lingua-forge-tabs" role="tablist">
                <a href="#ai-provider"  class="nav-tab nav-tab-active" data-lf-tab="ai-provider"><?php  esc_html_e('AI Provider', 'lingua-forge'); ?></a>
                <a href="#limits"      class="nav-tab"                data-lf-tab="limits"><?php      esc_html_e('Limits',      'lingua-forge'); ?></a>
                <a href="#behavior"    class="nav-tab"                data-lf-tab="behavior"><?php    esc_html_e('Behavior',    'lingua-forge'); ?></a>
                <a href="#router"      class="nav-tab"                data-lf-tab="router"><?php      esc_html_e('Router',      'lingua-forge'); ?></a>
                <a href="#glossary"    class="nav-tab"                data-lf-tab="glossary"><?php    esc_html_e('Glossary',    'lingua-forge'); ?></a>
                <a href="#seo"         class="nav-tab"                data-lf-tab="seo"><?php         esc_html_e('SEO',         'lingua-forge'); ?></a>
                <a href="#ai-usage"    class="nav-tab"                data-lf-tab="ai-usage"><?php    esc_html_e('AI Usage',    'lingua-forge'); ?></a>
                <a href="#maintenance" class="nav-tab"                data-lf-tab="maintenance"><?php esc_html_e('Maintenance', 'lingua-forge'); ?></a>
                <a href="#system"      class="nav-tab"                data-lf-tab="system"><?php      esc_html_e('System',      'lingua-forge'); ?></a>
            </h2>

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

                <!-- ───── Tab: AI Provider ───── -->
                <div class="lingua-forge-tab-panel is-active" data-lf-panel="ai-provider">
                <?php AiProviderTab::render_content(); ?>
                </div><!-- /lingua-forge-tab-panel: ai-provider -->

                <!-- ───── Tab: Limits ───── -->
                <div class="lingua-forge-tab-panel" data-lf-panel="limits">
                <?php LimitsTab::render_content(); ?>
                </div><!-- /lingua-forge-tab-panel: limits -->

                <!-- ───── Tab: Behavior ───── -->
                <div class="lingua-forge-tab-panel" data-lf-panel="behavior">
                <?php BehaviorTab::render_content(); ?>
                </div><!-- /lingua-forge-tab-panel: behavior -->

                <div class="lf-settings-submit">
                    <?php submit_button( __( 'Save Settings', 'lingua-forge' ) ); ?>
                </div>

            </form>

            <!-- ───── Tab: Router ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="router">
            <?php RouterTab::render_content(); ?>
            </div><!-- /lingua-forge-tab-panel: router -->

            <!-- ───── Tab: Glossary ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="glossary">
            <?php GlossaryTab::render_content(); ?>
            </div><!-- /lingua-forge-tab-panel: glossary -->

            <!-- ───── Tab: SEO ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="seo">
            <?php SeoTab::render_content(); ?>
            </div><!-- /lingua-forge-tab-panel: seo -->

            <!-- ───── Tab: AI Usage ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="ai-usage">
            <?php AiUsageTab::render_content(); ?>
            </div><!-- /lingua-forge-tab-panel: ai-usage -->

            <!-- ───── Tab: Maintenance ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="maintenance">
            <?php MaintenanceTab::render_content(); ?>
            </div><!-- /lingua-forge-tab-panel: maintenance -->

            <!-- ───── Tab: System ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="system">
            <?php SystemTab::render_content(); ?>
            </div><!-- /lingua-forge-tab-panel: system -->

        </div>

        <?php
    }
}
