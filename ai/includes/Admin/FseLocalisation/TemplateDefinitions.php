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

        // Append single-{cpt} / archive-{cpt} slots for each public CPT that
        // the active theme ships a matching base template for.
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

        if ( $cpts ) {
            $theme = get_stylesheet();
            // Collect slugs of templates the active theme actually provides.
            $theme_slugs = [];
            foreach ( get_block_templates( [], 'wp_template' ) as $tpl ) {
                if ( $tpl->theme === $theme ) {
                    $theme_slugs[] = $tpl->slug;
                }
            }

            foreach ( $cpts as $cpt ) {
                $obj = get_post_type_object( $cpt );
                if ( ! $obj ) {
                    continue;
                }
                $singular = $obj->labels->singular_name ?? $cpt;
                $plural   = $obj->labels->name           ?? $cpt;

                if ( in_array( 'single-' . $cpt, $theme_slugs, true ) ) {
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

                if ( in_array( 'archive-' . $cpt, $theme_slugs, true ) ) {
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
        }

        return $defs;
    }
}
