<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler for rewriting internal links in FSE templates and template parts.
 */
class LinkFixer {

    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_fix_fse_links', [ self::class, 'ajax_fix_fse_links' ] );
    }

    /**
     * Rewrite internal links inside an FSE template or template part so they
     * carry the correct language URL prefix (e.g. /contact/ → /de/contact/).
     *
     * Called via wp_ajax_linguaforge_fix_fse_links.
     * POST params:
     *   slug      – full language-specific slug (e.g. 'page-de', 'header-de').
     *   post_type – 'wp_template' or 'wp_template_part'.
     *
     * Two patterns are rewritten in the raw block markup:
     *   1. href="https://site.com/path/"     — HTML anchor attributes.
     *   2. "url":"https://site.com/path/"    — block JSON attributes used by
     *                                          core/navigation-link and similar.
     *
     * URLs that already start with the language prefix are left untouched.
     * The target language is inferred from the slug suffix.
     */
    public static function ajax_fix_fse_links(): void {
        check_ajax_referer( 'linguaforge_fix_fse_links', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $slug      = sanitize_key( wp_unslash( $_POST['slug']      ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );

        // Infer the target language from the slug suffix (e.g. 'page-de' → 'de').
        // Computed before the post_type check so PHPStan can follow the control
        // flow without treating the subsequent if block as unreachable.
        $last_hyphen = strrpos( $slug, '-' );
        if ( $last_hyphen === false ) {
            wp_send_json_error( __( 'Cannot determine target language from slug.', 'lingua-forge' ) );
        }
        $lang = substr( $slug, $last_hyphen + 1 );

        if ( ! in_array( $post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
            wp_send_json_error( __( 'Invalid post type.', 'lingua-forge' ) );
        }

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        if ( ! $router->is_valid_lang( $lang ) || $lang === $source_lang ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        // Fetch the template / part — must be DB-stored (wp_id set) to be writable.
        $theme    = get_stylesheet();
        $existing = get_block_templates( [ 'slug__in' => [ $slug ], 'theme' => $theme ], $post_type );
        if ( empty( $existing ) || ! $existing[0]->wp_id ) {
            wp_send_json_error( __( 'Template not found or not stored in the database.', 'lingua-forge' ) );
        }

        $post_id = (int) $existing[0]->wp_id;
        $content = (string) $existing[0]->content;

        if ( trim( $content ) === '' ) {
            wp_send_json_error( __( 'Template has no content.', 'lingua-forge' ) );
        }

        $count  = 0;
        $home   = untrailingslashit( home_url() );
        $prefix = '/' . $lang . '/';

        // Pattern 1 — href="https://site.com/path/" in HTML block markup.
        $content = preg_replace_callback(
            '/\bhref=(["\'])(' . preg_quote( $home, '/' ) . ')(\/[^"\']*?)(\1)/i',
            static function ( array $m ) use ( $lang, $prefix, &$count ): string {
                $path = $m[3]; // e.g. /contact/ or /
                // Already carries the language prefix — skip.
                if ( str_starts_with( ltrim( $path, '/' ), $lang . '/' ) || ltrim( $path, '/' ) === $lang ) {
                    return $m[0];
                }
                $count++;
                return 'href=' . $m[1] . $m[2] . $prefix . ltrim( $path, '/' ) . $m[1];
            },
            $content
        );

        // Pattern 2 — "url":"https://site.com/path/" in block JSON attributes
        // (core/navigation-link, core/button, etc.).
        $content = preg_replace_callback(
            '/"url":"(' . preg_quote( $home, '/' ) . ')(\/[^"]*?)"/i',
            static function ( array $m ) use ( $lang, $prefix, &$count ): string {
                $path = $m[2]; // e.g. /contact/
                if ( str_starts_with( ltrim( $path, '/' ), $lang . '/' ) || ltrim( $path, '/' ) === $lang ) {
                    return $m[0];
                }
                $count++;
                return '"url":"' . $m[1] . $prefix . ltrim( $path, '/' ) . '"';
            },
            $content
        );

        // ── Save prefix-rewritten content if any changes were made ───────────
        if ( $count > 0 ) {
            $result = wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $content,
            ], true );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
        }

        // ── Stale-path pass ───────────────────────────────────────────────────
        // Fix links that already carry the correct language prefix but whose
        // path has changed — e.g. a page was moved or its slug was updated after
        // the template part was last saved. LinkFixer::fix_post() uses data-id
        // as ground truth: if get_permalink(data-id) no longer matches the
        // stored href the link is stale and gets rewritten. Works for any post
        // type, including wp_template_part.
        $stale = $router->link_fixer->fix_post( $post_id, $lang );
        $total = $count + ( $stale['applied'] ?? 0 );

        if ( $total === 0 ) {
            wp_send_json_success( [
                'slug'    => $slug,
                'count'   => 0,
                'message' => __( 'No internal links found to update.', 'lingua-forge' ),
            ] );
        }

        wp_send_json_success( [
            'slug'  => $slug,
            'count' => $total,
            /* translators: %d: number of links rewritten */
            'message' => sprintf( _n( '%d link updated.', '%d links updated.', $total, 'lingua-forge' ), $total ),
        ] );
    }
}
