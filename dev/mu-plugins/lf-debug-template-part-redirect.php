<?php
/**
 * LF Debug: template-part redirect — v3
 *
 * Captures core/template-part blocks via pre_render_block (fires regardless
 * of whether get_block_template is called), so we can see the exact slug
 * and theme attributes for header and footer even when WooCommerce's template
 * loading path bypasses get_block_template entirely.
 *
 * Deploy to: wp-content/mu-plugins/ on the IMPLEMENTATION server only.
 * View output: /wp-content/uploads/lf-debug-tpl-parts.txt
 * Remove after investigation.
 */
defined( 'ABSPATH' ) || exit;

$GLOBALS['_lf_debug_tpl']           = [];   // wp_template_part call log (get_block_template path).
$GLOBALS['_lf_debug_full_tpl']      = null; // Full wp_template captured at priority 1.
$GLOBALS['_lf_debug_rendered_parts'] = [];  // All core/template-part renders (pre_render_block path).

// ---- pre_render_block: capture every core/template-part that actually renders ----
// This fires REGARDLESS of whether get_block_template is called, so it catches
// template parts embedded directly in the template content (no get_block_template path).

add_filter( 'pre_render_block', 'lf_debug_pre_render_tpl_part', 1, 2 );

function lf_debug_pre_render_tpl_part( $pre_render, array $block ) {
    if ( ( $block['blockName'] ?? '' ) === 'core/template-part' ) {
        $GLOBALS['_lf_debug_rendered_parts'][] = [
            'slug'  => $block['attrs']['slug']  ?? '(none)',
            'theme' => $block['attrs']['theme'] ?? '(none)',
            'area'  => $block['attrs']['tagName'] ?? '',
        ];
    }
    return $pre_render; // null — let normal rendering proceed.
}

// ---- Priority 1: capture incoming state before any LF filter ----

add_filter( 'get_block_template', 'lf_debug_capture_pre', 1, 3 );

function lf_debug_capture_pre( $template, string $id, string $template_type ) {
    if ( 'wp_template' === $template_type ) {
        // Capture the first (most-specific) full-page template lookup.
        if ( null === $GLOBALS['_lf_debug_full_tpl'] ) {
            $GLOBALS['_lf_debug_full_tpl'] = [
                'id'       => $id,
                'pre_slug' => $template instanceof WP_Block_Template ? $template->slug : 'null',
                'content'  => $template instanceof WP_Block_Template ? (string) $template->content : '',
            ];
        }
    } elseif ( 'wp_template_part' === $template_type ) {
        $GLOBALS['_lf_debug_tpl'][ $id ] = [
            'pre'  => $template instanceof WP_Block_Template ? $template->slug : 'null',
            'post' => '(pending)',
        ];
    }
    return $template;
}

// ---- Priority 99: capture final state after all LF filters ----

add_filter( 'get_block_template', 'lf_debug_capture_post', 99, 3 );

function lf_debug_capture_post( $template, string $id, string $template_type ) {
    if ( 'wp_template' === $template_type ) {
        // Update content with the post-filter version (in case LF swapped the template).
        if ( isset( $GLOBALS['_lf_debug_full_tpl'] ) && $GLOBALS['_lf_debug_full_tpl']['id'] === $id ) {
            $GLOBALS['_lf_debug_full_tpl']['post_slug']    = $template instanceof WP_Block_Template ? $template->slug : 'null';
            $GLOBALS['_lf_debug_full_tpl']['post_content'] = $template instanceof WP_Block_Template ? (string) $template->content : '';
        }
    } elseif ( 'wp_template_part' === $template_type ) {
        if ( isset( $GLOBALS['_lf_debug_tpl'][ $id ] ) ) {
            $GLOBALS['_lf_debug_tpl'][ $id ]['post'] = $template instanceof WP_Block_Template
                ? $template->slug
                : 'null';
        }
    }
    return $template;
}

// ---- wp_footer: write combined log ----

add_action( 'wp_footer', 'lf_debug_tpl_write_log', 9999 );

function lf_debug_tpl_write_log(): void {
    $log = [];

    $log[] = str_repeat( '=', 70 );
    $log[] = 'LF template-part redirect debug — ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
    $log[] = 'URL: ' . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'unknown' );
    $log[] = 'Theme: ' . get_stylesheet();
    $log[] = 'LF_LANG: ' . ( defined( 'LF_LANG' ) ? LF_LANG : 'NOT DEFINED' );

    $source_lang = 'n/a';
    if ( class_exists( 'LinguaForge\Router\Router' ) ) {
        try {
            $source_lang = \LinguaForge\Router\Router::get_instance()->context->source_language();
        } catch ( \Throwable $e ) {
            $source_lang = 'error: ' . $e->getMessage();
        }
    } else {
        $source_lang = 'Router class not found';
    }
    $log[] = 'Source lang: ' . $source_lang;

    // Check if the Redirector filter is registered.
    global $wp_filter;
    $lf_hooked = 'NOT FOUND';
    if ( isset( $wp_filter['get_block_template'] ) ) {
        foreach ( $wp_filter['get_block_template']->callbacks as $prio => $hooks ) {
            foreach ( $hooks as $tag => $data ) {
                if ( str_contains( $tag, 'redirect_template_part' ) || str_contains( $tag, 'Redirector' ) ) {
                    $lf_hooked = 'YES at priority ' . $prio;
                }
            }
        }
    }
    $log[] = 'Redirector hook registered: ' . $lf_hooked;

    // ---- pre_render_block hits (the ground truth) ----

    $log[] = '';
    $log[] = '--- core/template-part blocks actually rendered (pre_render_block) ---';

    $rendered = $GLOBALS['_lf_debug_rendered_parts'];
    if ( empty( $rendered ) ) {
        $log[] = '  (none — header/footer are likely inlined blocks, not template-part references)';
    } else {
        foreach ( $rendered as $r ) {
            $marker = '';
            if ( str_contains( $r['slug'], 'footer' ) || str_contains( $r['slug'], 'peu' ) ) {
                $marker = ' <<< FOOTER';
            } elseif ( str_contains( $r['slug'], 'header' ) || str_contains( $r['slug'], 'cap' ) ) {
                $marker = ' <<< HEADER';
            }
            $log[] = sprintf( '  slug=%-30s  theme=%-30s%s', $r['slug'], $r['theme'], $marker );
        }
    }

    // ---- Full page template ----

    $log[] = '';
    $log[] = '--- Full page template (wp_template) ---';

    $tpl = $GLOBALS['_lf_debug_full_tpl'];
    if ( null === $tpl ) {
        $log[] = '  (no wp_template call captured)';
    } else {
        $log[] = 'ID:       ' . $tpl['id'];
        $log[] = 'Pre-LF:   ' . $tpl['pre_slug'];
        $log[] = 'Post-LF:  ' . ( isset( $tpl['post_slug'] ) ? $tpl['post_slug'] : '(not recaptured)' );

        // Analyse the POST-filter content (what actually renders).
        $content = isset( $tpl['post_content'] ) && '' !== $tpl['post_content']
            ? $tpl['post_content']
            : $tpl['content'];

        $has_tpl_part = str_contains( $content, 'core/template-part' );
        preg_match_all( '/"slug"\s*:\s*"([^"]+)"/', $content, $slug_matches );
        $slugs_in_tpl = $slug_matches[1] ?? [];

        $log[] = 'Contains core/template-part blocks: ' . ( $has_tpl_part ? 'YES' : 'NO — footer/header are likely INLINE blocks' );
        if ( $has_tpl_part ) {
            $log[] = 'template-part slugs referenced: ' . implode( ', ', $slugs_in_tpl );
        }

        // Content preview — first 3 000 chars (enough to see the structure).
        $log[] = '';
        $log[] = 'Template content preview (first 3000 chars):';
        $log[] = '---';
        $log[] = substr( $content, 0, 3000 );
        $log[] = '---';
    }

    // ---- Template-part calls ----

    $log[] = '';
    $log[] = '--- All wp_template_part calls this request ---';

    $entries = $GLOBALS['_lf_debug_tpl'];
    if ( empty( $entries ) ) {
        $log[] = '  (none)';
    } else {
        foreach ( $entries as $id => $state ) {
            $marker = ( str_contains( $id, 'footer' ) || str_contains( $id, 'peu' ) ) ? ' <<< FOOTER' : '';
            $marker = ( str_contains( $id, 'header' ) || str_contains( $id, 'cap' ) ) ? ' <<< HEADER' : $marker;
            $log[]  = sprintf( '  %-60s  pre=%-20s  post=%s%s', $id, $state['pre'], $state['post'], $marker );
        }
    }

    // ---- DB snapshot of footer-related template parts ----

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_name, p.post_status,
                MAX( CASE WHEN pm.meta_key = 'theme'                 THEN pm.meta_value END ) AS theme,
                MAX( CASE WHEN pm.meta_key = 'wp_template_part_area' THEN pm.meta_value END ) AS area
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = 'wp_template_part'
           AND ( p.post_name LIKE '%footer%' OR p.post_name LIKE '%peu%' )
         GROUP BY p.ID, p.post_name, p.post_status
         ORDER BY p.post_name"
    );

    $log[] = '';
    $log[] = '--- DB: wp_template_part rows matching "footer" / "peu" ---';
    if ( empty( $rows ) ) {
        $log[] = '  (none found)';
    } else {
        foreach ( $rows as $r ) {
            $log[] = sprintf(
                '  ID=%d  name=%-40s  status=%-10s  theme=%-30s  area=%s',
                $r->ID, $r->post_name, $r->post_status, (string) $r->theme, (string) $r->area
            );
        }
    }

    // ---- DB snapshot of the wp_template post serving this page ----

    if ( null !== $tpl ) {
        $theme_slug = get_stylesheet();
        $sep        = strpos( $tpl['id'], '//' );
        $tpl_slug   = false !== $sep ? substr( $tpl['id'], $sep + 2 ) : $tpl['id'];

        $tpl_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.ID, p.post_name, p.post_status, p.post_content
             FROM {$wpdb->posts} p
             WHERE p.post_type = 'wp_template'
               AND p.post_name = %s
               AND p.post_status = 'publish'
             LIMIT 1",
            $tpl_slug
        ) );

        $log[] = '';
        $log[] = '--- DB: wp_template row for slug "' . $tpl_slug . '" ---';
        if ( $tpl_row ) {
            $log[] = 'ID:     ' . $tpl_row->ID;
            $log[] = 'name:   ' . $tpl_row->post_name;
            $log[] = 'status: ' . $tpl_row->post_status;
            $has_part_db = str_contains( (string) $tpl_row->post_content, 'core/template-part' );
            $log[] = 'Contains core/template-part: ' . ( $has_part_db ? 'YES' : 'NO — footer is INLINED in DB template' );
            $log[] = 'Content preview (first 2000 chars):';
            $log[] = substr( (string) $tpl_row->post_content, 0, 2000 );
        } else {
            $log[] = '  Not found in DB — template comes from filesystem.';
        }
    }

    $log[] = str_repeat( '=', 70 );
    $log[] = '';

    $upload_dir = wp_upload_dir();
    $log_file   = $upload_dir['basedir'] . '/lf-debug-tpl-parts.txt';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    file_put_contents( $log_file, implode( "\n", $log ) . "\n", FILE_APPEND );
}
