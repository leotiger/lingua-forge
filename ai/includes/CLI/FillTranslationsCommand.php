<?php

namespace LinguaForge\AI\CLI;

defined('ABSPATH') || exit;

/**
 * `wp linguaforge fill-translations` — implementation.
 *
 * The user-facing docblock (## OPTIONS / ## EXAMPLES / @when) lives on the
 * matching method in \LinguaForge\AI\CLI\Commands so WP-CLI's command-help
 * introspection continues to render it. This class only holds the run-loop.
 *
 * Differs from TranslateCommand in three ways:
 *   1. Auto-derives the target language list from the Language Router's
 *      active languages instead of requiring --to=<csv>.
 *   2. Skips any language that already has a TRID-linked post (use
 *      retranslate to refresh those).
 *   3. Supports --check-only — a pure read path that reports missing
 *      languages without making any API calls.
 */
class FillTranslationsCommand extends AbstractTranslateCommand {

    public function execute( array $args, array $assoc_args ): void {

        // ── Validate post ─────────────────────────────────────────────────
        $post    = $this->validate_post_id( $args );
        $post_id = $post->ID;

        // ── Determine source language ─────────────────────────────────────
        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = (string) get_post_meta( $post_id, '_lang', true );
        if ( $source_lang === '' ) {
            $source_lang = $router->source_language();
        }

        // ── All router-known languages minus the source ───────────────────
        $all_langs = $router->languages();
        $targets   = array_values( array_filter(
            $all_langs,
            static fn( string $l ): bool => $l !== $source_lang
        ) );

        if ( empty( $targets ) ) {
            \WP_CLI::error( 'No target languages configured in the Language Router besides the source language.' );
        }

        // ── Honour --exclude ──────────────────────────────────────────────
        $exclude_raw = (string) ( $assoc_args['exclude'] ?? '' );
        if ( $exclude_raw !== '' ) {
            $excluded = array_map(
                static fn( string $s ): string => sanitize_key( trim( $s ) ),
                explode( ',', $exclude_raw )
            );
            $targets = array_values( array_filter(
                $targets,
                static fn( string $l ): bool => ! in_array( $l, $excluded, true )
            ) );
        }

        if ( empty( $targets ) ) {
            \WP_CLI::error( 'No target languages remain after applying --exclude.' );
        }

        // ── Mode flags ────────────────────────────────────────────────────
        $check_only     = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'check-only',           false );
        $dry_run        = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run',               false );
        $force_draft    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'draft',                 false );
        $with_meta_desc = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'with-meta-description', false );
        $debug          = ( ! $check_only ) && (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'debug', false );
        $format         = (string) ( $assoc_args['format'] ?? 'table' );

        $this->activate_debug_mode( $debug );

        // ── Existing TRID-linked posts ────────────────────────────────────
        $translations = function_exists( 'linguaforge_get_translations' )
            ? linguaforge_get_translations( $post_id )
            : [];

        // ── --check-only: pure read path ──────────────────────────────────
        if ( $check_only ) {

            $results       = [];
            $missing_count = 0;

            foreach ( $targets as $lang ) {
                if ( ! empty( $translations[ $lang ] ) ) {
                    $linked_id = (int) $translations[ $lang ];
                    $results[] = [
                        'lang'      => $lang,
                        'status'    => 'existing',
                        'target_id' => $linked_id,
                        'detail'    => sprintf( 'post %d', $linked_id ),
                    ];
                } else {
                    $missing_count++;
                    $results[] = [
                        'lang'      => $lang,
                        'status'    => 'missing',
                        'target_id' => 0,
                        'detail'    => '—',
                    ];
                }
            }

            \WP_CLI\Utils\format_items( $format, $results, [ 'lang', 'status', 'target_id', 'detail' ] );

            if ( $missing_count > 0 ) {
                \WP_CLI::log( sprintf(
                    '%d of %d language(s) missing. Run without --check-only to fill them.',
                    $missing_count,
                    count( $targets )
                ) );
                \WP_CLI::halt( 1 );
            } else {
                \WP_CLI::success( 'All active languages have a linked translation post.' );
            }
            return;
        }

        // ── Partition into existing (skip) and missing (translate) ────────
        $to_translate = [];
        $results      = [];

        foreach ( $targets as $lang ) {
            if ( ! empty( $translations[ $lang ] ) ) {
                $results[] = [
                    'lang'      => $lang,
                    'status'    => 'existing',
                    'target_id' => (int) $translations[ $lang ],
                    'detail'    => 'already linked — skipped',
                ];
            } else {
                $to_translate[] = $lang;
            }
        }

        if ( empty( $to_translate ) ) {
            \WP_CLI\Utils\format_items( $format, $results, [ 'lang', 'status', 'target_id', 'detail' ] );
            \WP_CLI::success( 'All active languages already have a linked translation post.' );
            return;
        }

        // ── Worker config overrides ───────────────────────────────────────
        $this->register_worker_overrides_filter( $assoc_args );

        $translation = $this->resolve_translation_feature();

        // ── Translate each missing language ───────────────────────────────
        foreach ( $to_translate as $lang ) {

            \WP_CLI::log( sprintf(
                'Translating post %d (%s) → %s%s...',
                $post_id,
                $post->post_type,
                strtoupper( $lang ),
                $dry_run ? ' [dry-run]' : ''
            ) );

            $params = [
                'target_language' => $lang,
                'translate_mode'  => 'full',
                'force_refresh'   => false,
            ];

            $result = $translation->run( $post_id, $params );

            if ( $debug ) {
                $this->dump_debug_files( $post_id, $lang );
            }

            if ( empty( $result['success'] ) ) {
                $results[] = [
                    'lang'      => $lang,
                    'status'    => 'error',
                    'target_id' => 0,
                    'detail'    => (string) ( $result['error'] ?? 'unknown error' ),
                ];
                continue;
            }

            if ( $dry_run ) {
                $results[] = [
                    'lang'      => $lang,
                    'status'    => 'generated',
                    'target_id' => 0,
                    'detail'    => sprintf( '%d chars', mb_strlen( (string) ( $result['output'] ?? '' ) ) ),
                ];
                continue;
            }

            $applied         = $this->apply_translation( $post_id, $lang, $result, $force_draft, $with_meta_desc );
            $applied['lang'] = $lang;
            $results[]       = $applied;
        }

        // ── Render results — existing first, then by lang ─────────────────
        usort( $results, static function ( array $a, array $b ): int {
            static $order = [ 'existing' => 0, 'created' => 1, 'generated' => 2, 'error' => 3 ];
            $ao = $order[ $a['status'] ] ?? 4;
            $bo = $order[ $b['status'] ] ?? 4;
            return $ao !== $bo ? $ao <=> $bo : strcmp( $a['lang'], $b['lang'] );
        } );

        \WP_CLI\Utils\format_items( $format, $results, [ 'lang', 'status', 'target_id', 'detail' ] );

        $created = array_filter( $results, static fn( $r ): bool => $r['status'] === 'created' );
        $errored = array_filter( $results, static fn( $r ): bool => $r['status'] === 'error' );

        if ( ! empty( $errored ) ) {
            \WP_CLI::warning( sprintf( '%d language(s) failed — see details above.', count( $errored ) ) );
            \WP_CLI::halt( 1 );
        }

        \WP_CLI::success( sprintf(
            'Done. %d new translation(s) created; %d already existed.',
            count( $created ),
            count( array_filter( $results, static fn( $r ): bool => $r['status'] === 'existing' ) )
        ) );
    }
}
