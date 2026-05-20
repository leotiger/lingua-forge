<?php

namespace LinguaForge\AI\CLI;

defined('ABSPATH') || exit;

/**
 * `wp linguaforge translate` — implementation.
 *
 * The user-facing docblock (## OPTIONS / ## EXAMPLES / @when) lives on the
 * matching method in \LinguaForge\AI\CLI\Commands so WP-CLI's command-help
 * introspection continues to render it. This class only holds the run-loop.
 */
class TranslateCommand extends AbstractTranslateCommand {

    public function execute( array $args, array $assoc_args ): void {

        // ── Validate post ─────────────────────────────────────────────────
        $post    = $this->validate_post_id( $args );
        $post_id = $post->ID;

        // ── Validate target languages ─────────────────────────────────────
        $target_langs = $this->validate_target_langs( (string) ( $assoc_args['to'] ?? '' ) );

        // ── Mode flags + WorkerConfig overrides ───────────────────────────
        $dry_run        = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run',               false );
        $force          = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force',                 false );
        $force_draft    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'draft',                 false );
        $with_meta_desc = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'with-meta-description', false );
        $debug          = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'debug',                 false );

        $this->activate_debug_mode( $debug );
        $this->register_worker_overrides_filter( $assoc_args );

        $translation = $this->resolve_translation_feature();

        // ── Run per target language ───────────────────────────────────────
        $results = [];

        foreach ( $target_langs as $lang ) {

            \WP_CLI::log( sprintf(
                'Translating post %d (%s) → %s%s...',
                $post_id,
                $post->post_type,
                strtoupper( $lang ),
                $force ? ' [force]' : ''
            ) );

            $params = [
                'target_language' => $lang,
                'translate_mode'  => 'full',
                'force_refresh'   => $force,
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

            $was_cached = ! empty( $result['cached'] );

            if ( $dry_run ) {
                $results[] = [
                    'lang'      => $lang,
                    'status'    => $was_cached ? 'cached' : 'generated',
                    'target_id' => 0,
                    'detail'    => sprintf( '%d chars', mb_strlen( (string) ( $result['output'] ?? '' ) ) ),
                ];
                continue;
            }

            // Apply mode — write to the TRID-linked target post (create if missing).
            $applied         = $this->apply_translation( $post_id, $lang, $result, $force_draft, $with_meta_desc );
            $applied['lang'] = $lang;
            $results[]       = $applied;
        }

        // ── Render results ────────────────────────────────────────────────
        $format = (string) ( $assoc_args['format'] ?? 'table' );

        \WP_CLI\Utils\format_items(
            $format,
            $results,
            [ 'lang', 'status', 'target_id', 'detail' ]
        );

        // Surface a non-zero exit if any language errored — useful for shell pipelines.
        $errored = array_filter( $results, static fn( $r ): bool => $r['status'] === 'error' );
        if ( ! empty( $errored ) ) {
            \WP_CLI::halt( 1 );
        }
    }
}
