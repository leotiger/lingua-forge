<?php

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Features\Translation;

defined('ABSPATH') || exit;

/**
 * Admin Toolbar — Quick Translate popover
 *
 * Registers a "Translate" node in the WordPress Admin Bar that opens an
 * inline popover for translating any text snippet into a chosen language.
 * The feature is completely independent from the editor meta-box translation
 * workflow: it uses a dedicated REST endpoint and its own JS/CSS bundle, so
 * it works on every admin screen and on the front-end admin bar alike.
 */
class AdminToolbar {

    public static function init(): void {

        add_action('admin_bar_menu',        [self::class, 'register_node'], 100);
        add_action('wp_enqueue_scripts',    [self::class, 'enqueue']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    // ── Language helper ───────────────────────────────────────────────────────

    /**
     * Returns the AI-supported language map filtered to languages active on
     * this WordPress instance.
     *
     * linguaforge_languages() (defined in language-router/language-router.php,
     * always loaded as part of the plugin) returns the 2-letter language codes
     * that the Language Router recognises as valid for this install — derived
     * from installed WP locale files, the site locale, plugin .mo files, and
     * the configured source language.
     *
     * The intersection ensures only languages that are BOTH understood by the
     * AI and actually managed by this instance appear in the dropdown.
     *
     * Sorted by language code (ksort) so the Quick Translate popover lists
     * languages in a predictable order rather than Translation::get_languages()'s
     * built-in insertion order.
     *
     * @return array<string,string>  e.g. ['en' => 'English', 'es' => 'Spanish']
     */
    private static function instance_languages(): array {
        $languages = array_intersect_key(
            Translation::get_languages(),
            array_flip( linguaforge_languages() )
        );
        ksort( $languages );
        return $languages;
    }

    // ── Admin Bar node ────────────────────────────────────────────────────────

    public static function register_node(\WP_Admin_Bar $bar): void {

        if (!current_user_can('edit_posts')) {
            return;
        }

        $bar->add_node([
            'id'    => 'lingua-forge-translate',
            'title' =>
                '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span>' .
                '<span class="ab-label">' . esc_html__( 'Translate', 'lingua-forge' ) . '</span>',
            'href'  => '#',
            'meta'  => [
                'class' => 'lingua-forge-toolbar-item',
                'title' => __( 'Lingua Forge — Quick Translate', 'lingua-forge' ),
            ],
        ]);
    }

    // ── Asset enqueue ─────────────────────────────────────────────────────────

    public static function enqueue(): void {

        if (!is_admin_bar_showing() || !current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'lingua-forge-toolbar',
            LINGUAFORGE_AI_URL . '/assets/toolbar-translate.css',
            [],
            LINGUAFORGE_VERSION
        );

        wp_enqueue_script(
            'lingua-forge-toolbar',
            LINGUAFORGE_AI_URL . '/assets/toolbar-translate.js',
            ['wp-i18n'],
            LINGUAFORGE_VERSION,
            true   // load in footer
        );

        wp_set_script_translations( 'lingua-forge-toolbar', 'lingua-forge', LINGUAFORGE_PATH . 'languages' );

        wp_localize_script(
            'lingua-forge-toolbar',
            'LinguaForgeAIToolbar',
            [
                'restUrl'      => rest_url('lingua-forge/v1'),
                'nonce'        => wp_create_nonce('wp_rest'),
                'languages'    => self::instance_languages(),
                'postLanguage' => Translation::detect_post_language(),
                'tones'        => [
                    'informative'    => __( 'Informative',    'lingua-forge' ),
                    'persuasive'     => __( 'Persuasive',     'lingua-forge' ),
                    'storytelling'   => __( 'Storytelling',   'lingua-forge' ),
                    'technical'      => __( 'Technical',      'lingua-forge' ),
                    'conversational' => __( 'Conversational', 'lingua-forge' ),
                ],
            ]
        );
    }

}
