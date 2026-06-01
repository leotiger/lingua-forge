<?php

namespace LinguaForge\AI\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp linguaforge fix_nav_lang` — implementation.
 *
 * Backfills _lf_lang post-meta and _lf_trid translation groups on
 * wp_navigation posts created before v2.1.0.
 *
 * Language tagging:
 *   Posts whose slug ends with a known language suffix are tagged with that
 *   language (e.g. "primary-navigation-de" → _lf_lang = "de").
 *   Posts whose slug matches a base slug of a translated sibling — and that
 *   have no suffix themselves — are tagged as source language.
 *
 * TRID grouping:
 *   After language tagging, posts are grouped by their common base slug.
 *   All members of a group share the same _lf_trid UUID, linking them as
 *   translation siblings. If any post in the group already has a TRID, the
 *   existing value is used; otherwise a fresh UUID is generated.
 */
class FixNavLangCommand {

    public function execute( array $args, array $assoc_args ): void {
        $dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

        $router      = \LinguaForge\Router\Router::get_instance();
        $langs       = $router->context->languages();
        $source_lang = $router->source_language();

        if ( empty( $langs ) ) {
            \WP_CLI::error( 'No active languages found in Lingua Forge.' );
        }

        // Fetch every published wp_navigation post.
        $nav_posts = get_posts( [
            'post_type'      => 'wp_navigation',
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'no_found_rows'  => true,
            'fields'         => 'all',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        if ( empty( $nav_posts ) ) {
            \WP_CLI::success( 'No wp_navigation posts found.' );
            return;
        }

        // ── Pass 1: resolve language for each post ────────────────────────────
        // Build a map: base_slug → [ lang => WP_Post ]
        // "base_slug" is the slug with any language suffix stripped.
        // Posts with no suffix are tentatively treated as source language.
        $groups = []; // base_slug → [ lang => WP_Post ]
        $orphan = []; // posts that couldn't be matched to any group

        foreach ( $nav_posts as $post ) {
            $inferred  = '';
            $base_slug = $post->post_name;

            foreach ( $langs as $lang ) {
                if ( str_ends_with( $post->post_name, '-' . $lang ) ) {
                    $inferred  = $lang;
                    $base_slug = substr( $post->post_name, 0, -( strlen( $lang ) + 1 ) );
                    break;
                }
            }

            if ( ! $inferred ) {
                // No suffix — this post is likely the source-language navigation.
                // We'll confirm it belongs to a group once we've seen the full list.
                $inferred = $source_lang;
            }

            if ( ! isset( $groups[ $base_slug ] ) ) {
                $groups[ $base_slug ] = [];
            }

            if ( isset( $groups[ $base_slug ][ $inferred ] ) ) {
                // Duplicate: two posts inferred to the same lang in the same group.
                // Keep whichever has a TRID; otherwise keep the older one (lower ID).
                $existing = $groups[ $base_slug ][ $inferred ];
                $existing_trid = $router->get_trid( $existing->ID );
                $current_trid  = $router->get_trid( $post->ID );
                if ( $current_trid && ! $existing_trid ) {
                    $groups[ $base_slug ][ $inferred ] = $post;
                }
                \WP_CLI::warning( sprintf(
                    'Duplicate inferred lang=%s in group "%s" (IDs %d and %d). Keeping ID %d.',
                    $inferred,
                    $base_slug,
                    $existing->ID,
                    $post->ID,
                    $groups[ $base_slug ][ $inferred ]->ID
                ) );
                continue;
            }

            $groups[ $base_slug ][ $inferred ] = $post;
        }

        // Remove groups that contain only one post whose only lang is source —
        // those are truly standalone navigations with no translations, not worth
        // forcing into a TRID group.
        $lang_tagged = 0;
        $trid_linked  = 0;
        $skipped      = 0;

        foreach ( $groups as $base_slug => $members ) {
            $has_translations = ( count( $members ) > 1 );

            // ── Pass 2: apply language tags ───────────────────────────────────
            foreach ( $members as $inferred_lang => $post ) {
                $existing_lang = $router->get_lang( $post->ID );

                if ( $existing_lang && $existing_lang === $inferred_lang ) {
                    \WP_CLI::log( sprintf(
                        '  skip  ID %d (%s) — already tagged: %s',
                        $post->ID,
                        $post->post_name,
                        $existing_lang
                    ) );
                    $skipped++;
                    continue;
                }

                if ( $dry_run ) {
                    \WP_CLI::log( sprintf(
                        '  [dry] ID %d (%s) → would set _lf_lang = %s',
                        $post->ID,
                        $post->post_name,
                        $inferred_lang
                    ) );
                } else {
                    $router->set_lang( $post->ID, $inferred_lang );
                    \WP_CLI::log( sprintf(
                        '  set   ID %d (%s) → _lf_lang = %s',
                        $post->ID,
                        $post->post_name,
                        $inferred_lang
                    ) );
                }
                $lang_tagged++;
            }

            // ── Pass 3: TRID grouping ─────────────────────────────────────────
            // Only group posts that have translation siblings.
            if ( ! $has_translations ) {
                continue;
            }

            // Find any existing TRID in the group.
            $shared_trid = '';
            foreach ( $members as $post ) {
                $existing_trid = $router->get_trid( $post->ID );
                if ( $existing_trid ) {
                    $shared_trid = $existing_trid;
                    break;
                }
            }
            if ( ! $shared_trid ) {
                $shared_trid = wp_generate_uuid4();
            }

            foreach ( $members as $inferred_lang => $post ) {
                $existing_trid = $router->get_trid( $post->ID );

                if ( $existing_trid === $shared_trid ) {
                    continue; // Already in the correct group.
                }

                if ( $dry_run ) {
                    \WP_CLI::log( sprintf(
                        '  [dry] ID %d (%s) → would set _lf_trid = %s',
                        $post->ID,
                        $post->post_name,
                        $shared_trid
                    ) );
                } else {
                    $router->set_trid( $post->ID, $shared_trid );
                    \WP_CLI::log( sprintf(
                        '  trid  ID %d (%s) → _lf_trid = %s',
                        $post->ID,
                        $post->post_name,
                        $shared_trid
                    ) );
                }
                $trid_linked++;
            }
        }

        $verb = $dry_run ? 'Would update' : 'Updated';
        \WP_CLI::success( sprintf(
            '%s %d language tag(s), %d TRID link(s). Skipped (already correct): %d.',
            $verb,
            $lang_tagged,
            $trid_linked,
            $skipped
        ) );
    }
}
