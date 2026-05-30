<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for discovering and checking FSE template parts.
 *
 * Pure static utilities — no hooks, no state.
 */
class PartDiscovery {

    /**
     * Check whether a template part slug exists on the active theme (file or DB).
     *
     * Template parts live in {theme}/parts/ rather than {theme}/templates/.
     *
     * @param string $slug Part slug, e.g. 'header', 'header-de'.
     */
    public static function part_exists( string $slug ): bool {
        // Filesystem — active (child) theme.
        if ( file_exists( get_stylesheet_directory() . '/parts/' . $slug . '.html' ) ) {
            return true;
        }
        // DB — wp_template_part posts for the active theme.
        $found = get_block_templates(
            [ 'slug__in' => [ $slug ], 'theme' => get_stylesheet() ],
            'wp_template_part'
        );
        return ! empty( $found );
    }

    /**
     * Recursive helper: collect all core/template-part slugs + areas from a
     * parsed block tree.
     *
     * @param array<int, array<string, mixed>> $blocks Output of parse_blocks().
     * @param array<string, string>            $parts  Accumulator: slug → area.
     */
    public static function collect_parts_from_blocks( array $blocks, array &$parts ): void {
        foreach ( $blocks as $block ) {
            if ( 'core/template-part' === ( $block['blockName'] ?? '' ) ) {
                $slug = (string) ( $block['attrs']['slug'] ?? '' );
                $area = (string) ( $block['attrs']['area'] ?? 'uncategorized' );
                if ( $slug !== '' && ! isset( $parts[ $slug ] ) ) {
                    $parts[ $slug ] = $area;
                }
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::collect_parts_from_blocks( $block['innerBlocks'], $parts );
            }
        }
    }

    /**
     * Discover which template parts are referenced by the theme's base templates.
     *
     * Two-pass approach:
     *   Pass 1 – collect every core/template-part slug found in the base
     *            template block markup (area attribute from the block is kept
     *            only as a last-resort fallback because many themes omit it).
     *   Pass 2 – resolve the canonical area from the wp_template_part object
     *            itself (read from the wp_template_part_area taxonomy), so that
     *            the Site Editor groups the scaffolded part correctly even when
     *            the block comment carries no area attribute.
     *
     * @param string $theme Active theme stylesheet (get_stylesheet()).
     * @return array<string, string> Slug → area map, sorted by slug.
     */
    public static function discover_template_parts( string $theme ): array {
        // Pass 1 — harvest slugs (and block-level area as a fallback).
        $raw = [];
        foreach ( array_keys( TemplateDefinitions::get() ) as $base_slug ) {
            $tpl = get_block_template( $theme . '//' . $base_slug );
            if ( $tpl && $tpl->content ) {
                self::collect_parts_from_blocks( parse_blocks( $tpl->content ), $raw );
            }
        }

        // Pass 2 — authoritative area from the template part object.
        $parts = [];
        foreach ( array_keys( $raw ) as $slug ) {
            $part           = get_block_template( $theme . '//' . $slug, 'wp_template_part' );
            $parts[ $slug ] = ( $part && $part->area ) ? $part->area : ( $raw[ $slug ] ?? 'uncategorized' );
        }

        ksort( $parts );
        return $parts;
    }
}
