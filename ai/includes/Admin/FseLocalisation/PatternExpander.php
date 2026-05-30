<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * Expands wp:pattern block references to their registered markup.
 *
 * Pure static utility — no hooks, no state.
 */
class PatternExpander {

    /**
     * Expand every wp:pattern block reference inside a content string to its
     * registered markup, so the FSE translator sees actual translatable text
     * rather than a bare pattern pointer.
     *
     * Resolution order per slug:
     *   1. WP_Block_Patterns_Registry (theme / plugin PHP-registered patterns
     *      and theme patterns/ directory entries).
     *   2. wp_block synced-pattern posts (formerly "reusable blocks") — matched
     *      by post_name derived from the slug's tail segment.
     *
     * If a slug can't be resolved the original <!-- wp:pattern … /--> comment
     * is left untouched so the AI still sees a valid block structure.
     *
     * @param  string $content  Raw block markup that may contain wp:pattern refs.
     * @return string           Markup with pattern references expanded.
     */
    public static function expand( string $content ): string {

        $registry = \WP_Block_Patterns_Registry::get_instance();

        $expanded = preg_replace_callback(
            '/<!--\s*wp:pattern\s+(\{[^}]+\})\s*\/-->/i',
            static function ( array $m ) use ( $registry ): string {

                $attrs = json_decode( $m[1], true );
                $slug  = isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';

                if ( $slug === '' ) {
                    return $m[0];
                }

                // 1 — PHP-registered / theme-directory pattern.
                $pattern = $registry->is_registered( $slug )
                    ? $registry->get_registered( $slug )
                    : null;

                if ( $pattern && ! empty( $pattern['content'] ) ) {
                    return (string) $pattern['content'];
                }

                // 2 — Synced pattern stored as wp_block post.
                // The post_name is the final path segment of the slug.
                $name = ltrim( (string) strrchr( $slug, '/' ), '/' );
                if ( $name !== '' ) {
                    $posts = get_posts( [
                        'post_type'      => 'wp_block',
                        'name'           => $name,
                        'posts_per_page' => 1,
                        'post_status'    => 'publish',
                    ] );
                    if ( ! empty( $posts ) ) {
                        return (string) $posts[0]->post_content;
                    }
                }

                return $m[0]; // Unresolvable — leave the reference intact.
            },
            $content
        );

        return is_string( $expanded ) ? $expanded : $content;
    }
}
