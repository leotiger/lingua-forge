<?php

namespace LinguaForge\AI\CLI;

defined('ABSPATH') || exit;

/**
 * `wp linguaforge missing-translations` — implementation.
 *
 * The user-facing docblock (## OPTIONS / ## EXAMPLES / @when) lives on the
 * matching method in \LinguaForge\AI\CLI\Commands so WP-CLI's command-help
 * introspection continues to render it. This class only holds the run-loop.
 *
 * This command is a pure read-only scanner: it does not extend
 * AbstractTranslateCommand because it never calls Translation::run().
 */
class MissingTranslationsCommand {

    public function execute( array $args, array $assoc_args ): void {

        // ── Args ──────────────────────────────────────────────────────────
        $source_lang = sanitize_key( (string) ( $args[0] ?? '' ) );
        if ( $source_lang === '' ) {
            \WP_CLI::error( 'A source language code is required as the first argument (e.g. ca).' );
        }

        $post_type = sanitize_key( (string) ( $args[1] ?? '' ) );
        if ( $post_type === '' || ! post_type_exists( $post_type ) ) {
            \WP_CLI::error( sprintf( "Post type '%s' does not exist.", $post_type ) );
        }

        $status  = sanitize_key( (string) ( $assoc_args['status'] ?? 'publish' ) );
        $format  = (string) ( $assoc_args['format'] ?? 'table' );

        // ── Excluded languages ────────────────────────────────────────────
        $exclude_raw = (string) ( $assoc_args['exclude'] ?? '' );
        $excluded    = $exclude_raw !== ''
            ? array_map(
                static fn( string $s ): string => sanitize_key( trim( $s ) ),
                explode( ',', $exclude_raw )
              )
            : [];

        // ── Router target languages ───────────────────────────────────────
        $router   = \LinguaForge\Router\Router::get_instance();
        $all_langs = $router->languages();
        $targets  = array_values( array_filter(
            $all_langs,
            static fn( string $l ): bool =>
                $l !== $source_lang && ! in_array( $l, $excluded, true )
        ) );

        if ( empty( $targets ) ) {
            \WP_CLI::error( 'No target languages found after excluding the source and any --exclude values.' );
        }

        // ── Query all source-language posts ───────────────────────────────
        $query = new \WP_Query( [
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => -1,
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional full-type scan; CLI-only, not a frontend query.
                [
                    'key'   => '_lang',
                    'value' => $source_lang,
                ],
            ],
            'no_found_rows'  => true,
            'fields'         => 'all',
        ] );

        if ( empty( $query->posts ) ) {
            \WP_CLI::warning( sprintf(
                "No '%s' posts found with _lang = '%s' and status = '%s'.",
                $post_type,
                $source_lang,
                $status
            ) );
            return;
        }

        // ── Check each post's translation group ───────────────────────────
        $rows = [];

        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            $translations = function_exists( 'linguaforge_get_translations' )
                ? linguaforge_get_translations( $post->ID )
                : [];

            $missing = array_values( array_filter(
                $targets,
                static fn( string $l ): bool => empty( $translations[ $l ] )
            ) );

            if ( empty( $missing ) ) {
                continue; // fully translated — skip
            }

            $rows[] = [
                'post_id'  => $post->ID,
                'title'    => $post->post_title,
                'status'   => $post->post_status,
                'missing'  => implode( ', ', $missing ),
                'count'    => count( $missing ),
            ];
        }

        if ( empty( $rows ) ) {
            \WP_CLI::success( sprintf(
                'All %d post(s) have translations for every active language (%s).',
                count( $query->posts ),
                implode( ', ', $targets )
            ) );
            return;
        }

        // Sort by missing count descending so the most incomplete posts appear first.
        usort( $rows, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );

        \WP_CLI\Utils\format_items( $format, $rows, [ 'post_id', 'title', 'status', 'missing', 'count' ] );

        \WP_CLI::warning( sprintf(
            '%d of %d post(s) are missing at least one translation. '
            . 'Run: wp linguaforge fill-translations <post_id> --draft',
            count( $rows ),
            count( $query->posts )
        ) );
    }
}
