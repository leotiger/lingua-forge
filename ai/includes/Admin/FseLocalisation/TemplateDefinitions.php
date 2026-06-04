<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * Standard FSE template types available for per-language scaffolding.
 *
 * Pure static data provider — no hooks, no state.
 */
class TemplateDefinitions {

    /**
     * Return the full set of scaffold-able template definitions.
     *
     * Keys are the base template slugs used by WordPress's template hierarchy.
     * 'label'  – short column header shown in the scaffold table.
     * 'title'  – prefix used in the generated wp_template post title
     *            (e.g. 'Search Results' → title becomes 'Search Results DE').
     *
     * CPT-specific slots (single-{cpt} / archive-{cpt}) are appended
     * dynamically for each registered public CPT whose base template is
     * actually shipped by the active theme.
     *
     * @return array<string, array{label: string, title: string}>
     */
    public static function get(): array {
        // Cache the result for the lifetime of the request. get_block_templates()
        // with no filters returns the full theme + plugin template set — on a
        // WooCommerce site this can be 30–60 templates and involves multiple DB
        // queries. The scaffold table renders this list on every Settings → Router
        // page load, and ScaffoldHandler calls get() on every scaffold AJAX request
        // for its allow-list check, so without caching it fires multiple times per
        // page. The filter is applied inside the cache fill, not on every call.
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $defs = [
            'page'       => [
                'label' => __( 'Page',           'lingua-forge' ),
                'title' => __( 'Page',           'lingua-forge' ),
            ],
            'single'     => [
                'label' => __( 'Single',         'lingua-forge' ),
                'title' => __( 'Single',         'lingua-forge' ),
            ],
            'search'     => [
                'label' => __( 'Search',         'lingua-forge' ),
                'title' => __( 'Search Results', 'lingua-forge' ),
            ],
            'archive'    => [
                'label' => __( 'Archive',        'lingua-forge' ),
                'title' => __( 'Archive',        'lingua-forge' ),
            ],
            'front-page' => [
                'label' => __( 'Front Page',     'lingua-forge' ),
                'title' => __( 'Front Page',     'lingua-forge' ),
            ],
        ];

        // ── Collect all registered block templates in one query ──────────────
        // Partition into theme-owned vs plugin-owned so we can handle each
        // appropriately. Custom/user templates (source='custom') are skipped —
        // those are already-personalised and don't need scaffolding.
        $theme       = get_stylesheet();
        $theme_slugs = [];   // slugs provided by the active theme
        $plugin_tpls = [];   // WP_Block_Template objects from plugins

        foreach ( get_block_templates( [], 'wp_template' ) as $tpl ) {
            if ( $tpl->theme === $theme ) {
                $theme_slugs[] = $tpl->slug;
            } elseif ( ! in_array( $tpl->source, [ 'custom', 'user' ], true ) ) {
                // Catches plugin-registered templates regardless of how the plugin
                // sets $tpl->source — WooCommerce uses source='theme' with
                // theme='woocommerce', other plugins may use source='plugin'.
                // Exclude 'custom'/'user' (user-edited Site Editor templates).
                $plugin_tpls[ $tpl->slug ] = $tpl;
            }
        }

        // All available slugs (theme + plugin) — used for CPT pattern matching.
        $all_slugs = array_merge( $theme_slugs, array_keys( $plugin_tpls ) );

        // ── CPT slots: single-{cpt} / archive-{cpt} ──────────────────────────
        // Checked against ALL available templates (theme + plugin) so that
        // plugin-registered CPT templates (e.g. WooCommerce's single-product,
        // archive-product) are included even when the theme doesn't ship them.
        // Standard internal-types exclusion list. See class-sync.php for the
        // intentional wp_navigation omission that exists only in that file.
        $internal = [
            'attachment', 'revision', 'nav_menu_item',
            'wp_template', 'wp_template_part', 'wp_navigation',
            'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
            'wp_navigation_fallback',
        ];
        $cpts = array_values( array_diff(
            array_keys( get_post_types( [ 'public' => true ] ) ),
            array_merge( [ 'post', 'page' ], $internal )
        ) );

        foreach ( $cpts as $cpt ) {
            $obj = get_post_type_object( $cpt );
            if ( ! $obj ) {
                continue;
            }
            $singular = $obj->labels->singular_name ?? $cpt;
            $plural   = $obj->labels->name           ?? $cpt;

            if ( in_array( 'single-' . $cpt, $all_slugs, true ) ) {
                $defs[ 'single-' . $cpt ] = [
                    'label' => sprintf(
                        /* translators: %s: CPT singular label, e.g. "Product" */
                        __( 'Single: %s', 'lingua-forge' ),
                        $singular
                    ),
                    'title' => sprintf(
                        /* translators: %s: CPT singular label, e.g. "Product" */
                        __( 'Single %s', 'lingua-forge' ),
                        $singular
                    ),
                ];
            }

            if ( in_array( 'archive-' . $cpt, $all_slugs, true ) ) {
                $defs[ 'archive-' . $cpt ] = [
                    'label' => sprintf(
                        /* translators: %s: CPT plural label, e.g. "Products" */
                        __( 'Archive: %s', 'lingua-forge' ),
                        $plural
                    ),
                    'title' => sprintf(
                        /* translators: %s: CPT plural label, e.g. "Products" */
                        __( '%s Archive', 'lingua-forge' ),
                        $plural
                    ),
                ];
            }
        }

        // ── WooCommerce block templates ───────────────────────────────────────
        // WooCommerce stores its block templates as .html files under
        // WC_ABSPATH/templates/templates/. We scan the directory directly
        // because get_block_templates() does not reliably return WooCommerce
        // templates in all request contexts (CLI, some admin hooks, etc.).
        // Each file's basename (without .html) is a scaffoldable template slug.
        if ( defined( 'WC_ABSPATH' ) ) {
            $wc_tpl_dir = WC_ABSPATH . 'templates/templates/';
            if ( is_dir( $wc_tpl_dir ) ) {
                $wc_files = glob( $wc_tpl_dir . '*.html' ) ?: [];
                natsort( $wc_files );
                foreach ( $wc_files as $file ) {
                    $slug = basename( $file, '.html' );
                    if ( isset( $defs[ $slug ] ) ) {
                        continue; // already covered by CPT loop (e.g. single-product)
                    }
                    // Convert slug to a readable label: order-confirmation → Order Confirmation.
                    $label = ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
                    $defs[ $slug ] = [
                        'label' => $label,
                        'title' => $label,
                    ];
                }
            }
        }

        // ── Other plugin-registered templates ─────────────────────────────────
        // Catches any plugin that properly registers templates via
        // get_block_templates() (source != 'custom'/'user', theme != active theme).
        foreach ( $plugin_tpls as $slug => $tpl ) {
            if ( isset( $defs[ $slug ] ) ) {
                continue;
            }
            $defs[ $slug ] = [
                'label' => $tpl->title,
                'title' => $tpl->title,
            ];
        }

        /**
         * Filter the full set of scaffold-able FSE template definitions.
         *
         * Allows third-party code to add, remove, or rename entries.
         * Keys are base template slugs; values are arrays with 'label' and 'title'.
         *
         * @param array<string, array{label: string, title: string}> $defs
         */
        $cache = apply_filters( 'linguaforge_fse_template_definitions', $defs );
        return $cache;
    }
}
