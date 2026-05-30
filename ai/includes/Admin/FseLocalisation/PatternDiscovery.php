<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers block patterns that are scoped to one or more public CPTs.
 *
 * Pure static — no hooks, no state.
 */
class PatternDiscovery {

    /**
     * Internal WP post types that are never treated as CPTs in this context.
     */
    private const INTERNAL_TYPES = [
        'post', 'page', 'attachment', 'revision', 'nav_menu_item',
        'wp_template', 'wp_template_part', 'wp_navigation',
        'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
        'wp_navigation_fallback',
    ];

    /**
     * Return all registered block patterns whose `postTypes` list contains at
     * least one public non-internal CPT.
     *
     * Each entry in the returned array preserves the original pattern data
     * (name, title, content, postTypes, …) from WP_Block_Patterns_Registry,
     * with the additional key:
     *
     *   'cpt_labels' – array<string, string>  CPT slug → singular label
     *                  (only the CPTs that are public + non-internal).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_cpt_patterns(): array {

        $public_cpts = array_diff(
            array_keys( get_post_types( [ 'public' => true ] ) ),
            self::INTERNAL_TYPES
        );

        if ( empty( $public_cpts ) ) {
            return [];
        }

        $registry = \WP_Block_Patterns_Registry::get_instance();
        $all      = $registry->get_all_registered();
        $result   = [];

        foreach ( $all as $pattern ) {
            $post_types = $pattern['postTypes'] ?? [];
            if ( ! is_array( $post_types ) || empty( $post_types ) ) {
                continue;
            }

            $matching = array_intersect( $post_types, $public_cpts );
            if ( empty( $matching ) ) {
                continue;
            }

            $cpt_labels = [];
            foreach ( $matching as $cpt ) {
                $obj = get_post_type_object( $cpt );
                $cpt_labels[ $cpt ] = $obj ? ( $obj->labels->singular_name ?? $cpt ) : $cpt;
            }

            $pattern['cpt_labels'] = $cpt_labels;
            $result[]              = $pattern;
        }

        usort( $result, static fn( $a, $b ) => strcmp( $a['title'] ?? '', $b['title'] ?? '' ) );

        return $result;
    }

    /**
     * Derive a stable option-key fragment from a pattern name.
     *
     * Pattern names look like "theme/my-pattern-name" or "plugin/name".
     * The slash is replaced with two underscores so the fragment can be
     * embedded in an option name or used as an HTML data attribute.
     *
     * @param  string $name  Pattern name (e.g. 'mytheme/hero-block').
     * @return string        Safe fragment (e.g. 'mytheme__hero-block').
     */
    public static function name_to_key( string $name ): string {
        return str_replace( '/', '__', $name );
    }

    /**
     * Return true when a translated variant for the given pattern + language
     * has been stored in the database.
     *
     * @param  string $name  Pattern name.
     * @param  string $lang  Two-char language code.
     * @return bool
     */
    public static function translation_exists( string $name, string $lang ): bool {
        $translations = (array) get_option( 'linguaforge_pattern_translations', [] );
        $key          = self::name_to_key( $name );
        return isset( $translations[ $key ][ $lang ] ) && '' !== $translations[ $key ][ $lang ];
    }

    /**
     * Retrieve the stored translated content for a pattern + language.
     *
     * @param  string $name  Pattern name.
     * @param  string $lang  Two-char language code.
     * @return string        Translated block content, or '' if not found.
     */
    public static function get_translation( string $name, string $lang ): string {
        $translations = (array) get_option( 'linguaforge_pattern_translations', [] );
        $key          = self::name_to_key( $name );
        return (string) ( $translations[ $key ][ $lang ] ?? '' );
    }

    /**
     * Persist a translated pattern variant.
     *
     * @param  string $name     Pattern name.
     * @param  string $lang     Two-char language code.
     * @param  string $content  Translated block markup.
     * @return void
     */
    public static function save_translation( string $name, string $lang, string $content ): void {
        $translations         = (array) get_option( 'linguaforge_pattern_translations', [] );
        $key                  = self::name_to_key( $name );
        $translations[ $key ]         ??= [];
        $translations[ $key ][ $lang ]  = $content;
        update_option( 'linguaforge_pattern_translations', $translations, false );
    }
}
