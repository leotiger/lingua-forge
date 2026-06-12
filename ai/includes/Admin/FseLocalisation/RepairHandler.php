<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler: repair wp_theme namespace and _lf_lang meta on LF-scaffolded
 * FSE templates and template parts.
 *
 * Detects scaffolded posts by slug pattern — a published wp_template or
 * wp_template_part whose post_name ends with "-{lang}" for any configured
 * secondary language. For each found post:
 *
 *   • wp_theme taxonomy: sets the source template's actual owner (e.g.
 *     'woocommerce') so the Site Editor groups them correctly instead of
 *     listing everything under the active theme.
 *   • _lf_lang meta: adds the language tag if absent — needed by the
 *     theme-switch notice and SEO coverage reports.
 *
 * Called via wp_ajax_linguaforge_repair_fse_metadata.
 */
class RepairHandler {

    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_repair_fse_metadata', [ self::class, 'ajax_repair_fse_metadata' ] );
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    public static function ajax_repair_fse_metadata(): void {
        check_ajax_referer( 'linguaforge_repair_fse_metadata', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'lingua-forge' ) ] );
        }

        $router          = \LinguaForge\Router\Router::get_instance();
        $source_lang     = $router->source_language();
        $secondary_langs = array_values( array_filter(
            $router->languages(),
            fn( string $l ): bool => $l !== $source_lang
        ) );

        $theme = get_stylesheet();
        $fixed = 0;

        // When $secondary_langs is empty, get_lf_template_posts() returns [] for
        // both types and the loop is a no-op — no early-exit branch needed.
        foreach ( [ 'wp_template', 'wp_template_part' ] as $post_type ) {
            $posts = self::get_lf_template_posts( $post_type, $secondary_langs );
            foreach ( $posts as $post ) {
                $lang = self::extract_lang_suffix( (string) $post->post_name, $secondary_langs );
                if ( '' === $lang ) {
                    continue;
                }
                $base_slug = substr( (string) $post->post_name, 0, -( strlen( $lang ) + 1 ) );

                $namespace      = self::resolve_namespace( $base_slug, $theme, $post_type );
                $template_fixed = false;

                // Fix wp_theme taxonomy if wrong.
                $current_terms = wp_get_post_terms( (int) $post->ID, 'wp_theme', [ 'fields' => 'names' ] );
                $current_theme = ( ! is_wp_error( $current_terms ) && ! empty( $current_terms ) )
                    ? (string) $current_terms[0]
                    : '';
                if ( $current_theme !== $namespace ) {
                    wp_set_post_terms( (int) $post->ID, $namespace, 'wp_theme' );
                    $template_fixed = true;
                }

                // Add _lf_lang if missing.
                if ( '' === (string) get_post_meta( (int) $post->ID, '_lf_lang', true ) ) {
                    update_post_meta( (int) $post->ID, '_lf_lang', $lang );
                    $template_fixed = true;
                }

                if ( $template_fixed ) {
                    $fixed++;
                }
            }
        }

        wp_send_json_success( [
            'repaired' => $fixed,
            'message'  => $fixed > 0
                ? sprintf(
                    /* translators: %d: number of templates whose metadata was repaired */
                    _n(
                        '%d template repaired.',
                        '%d templates repaired.',
                        $fixed,
                        'lingua-forge'
                    ),
                    $fixed
                )
                : __( 'All template metadata is already up to date.', 'lingua-forge' ),
        ] );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Query published wp_template or wp_template_part posts whose post_name
     * ends with "-{lang}" for any of the given secondary languages.
     *
     * @param string   $post_type       'wp_template' or 'wp_template_part'.
     * @param string[] $secondary_langs Array of two-char language codes.
     * @return object[] Rows with ->ID and ->post_name.
     */
    private static function get_lf_template_posts( string $post_type, array $secondary_langs ): array {
        global $wpdb;

        if ( empty( $secondary_langs ) ) {
            return [];
        }

        // Build OR-ed LIKE clauses: post_name LIKE '%-es' OR post_name LIKE '%-de'.
        $wheres = [];
        $values = [ $post_type ];
        foreach ( $secondary_langs as $lang ) {
            $wheres[] = 'p.post_name LIKE %s';
            $values[] = '%' . $wpdb->esc_like( '-' . $lang );
        }

        $where_sql = implode( ' OR ', $wheres );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Admin-only repair; one-shot write operation, no useful cache layer. $where_sql contains only literal SQL with %s placeholders; all values are bound via ...$values in prepare().
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql contains only literal SQL fragments with %s placeholders; all user values are bound via the spread array.
                "SELECT p.ID, p.post_name
                 FROM {$wpdb->posts} p
                 WHERE p.post_type = %s
                   AND p.post_status = 'publish'
                   AND ( {$where_sql} )",
                // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ...$values
            )
        );
    }

    /**
     * Extract the language code from a template slug.
     *
     * Returns the two-char language code if the slug ends with "-{lang}" for
     * any configured secondary language, or '' if no match.
     *
     * @param string   $slug            Template post_name (e.g. 'page-checkout-es').
     * @param string[] $secondary_langs Configured secondary language codes.
     * @return string Language code (e.g. 'es') or ''.
     */
    private static function extract_lang_suffix( string $slug, array $secondary_langs ): string {
        foreach ( $secondary_langs as $lang ) {
            if ( str_ends_with( $slug, '-' . $lang ) ) {
                return (string) $lang;
            }
        }
        return '';
    }

    /**
     * Determine the correct wp_theme namespace for a localised template.
     *
     * Follows the same source-priority logic as ScaffoldHandler::ajax_scaffold_template():
     *   1. Active theme ships the base slug → namespace = active theme.
     *   2. Plugin-registered template with a different theme → namespace = that theme.
     *   3. No match → default to active theme (safe fallback).
     *
     * Template parts always belong to the active theme — PartDiscovery only
     * surfaces parts from the active theme's filesystem.
     *
     * Results are cached per base_slug+post_type for the lifetime of the request
     * so multi-language sites don't repeat the same block-template queries.
     *
     * @param string $base_slug  Slug without language suffix (e.g. 'page-checkout').
     * @param string $theme      Active theme slug from get_stylesheet().
     * @param string $post_type  'wp_template' or 'wp_template_part'.
     * @return string Namespace/theme slug to assign.
     */
    private static function resolve_namespace( string $base_slug, string $theme, string $post_type ): string {
        static $cache = [];

        $cache_key = $post_type . ':' . $base_slug;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        // Template parts always belong to the active theme.
        if ( 'wp_template_part' === $post_type ) {
            return $cache[ $cache_key ] = $theme;
        }

        // Check whether the active theme ships this base template directly.
        if ( get_block_template( $theme . '//' . $base_slug ) ) {
            return $cache[ $cache_key ] = $theme;
        }

        // Look for a plugin-registered template (e.g. woocommerce//page-checkout).
        // Accept any non-custom, non-user source that isn't the active theme.
        $candidates = get_block_templates( [ 'slug__in' => [ $base_slug ] ] );
        foreach ( $candidates as $candidate ) {
            if (
                ! in_array( $candidate->source, [ 'custom', 'user' ], true ) &&
                $candidate->theme !== $theme &&
                $candidate->theme
            ) {
                return $cache[ $cache_key ] = (string) $candidate->theme;
            }
        }

        return $cache[ $cache_key ] = $theme;
    }
}
