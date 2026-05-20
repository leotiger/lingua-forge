<?php

namespace LinguaForge\AI\CLI;

use LinguaForge\AI\Core\CacheStore;

defined('ABSPATH') || exit;

/**
 * `wp linguaforge retranslate` — implementation.
 *
 * The user-facing docblock (## OPTIONS / ## EXAMPLES / @when) lives on the
 * matching method in \LinguaForge\AI\CLI\Commands so WP-CLI's command-help
 * introspection continues to render it. This class only holds the run-loop.
 *
 * Differs from TranslateCommand in two ways:
 *   1. Wipes the per-language AI cache entry before each run so a stale
 *      cached translation can never be returned.
 *   2. Clears the ⚠ outdated flag on the target post after a successful apply.
 */
class RetranslateCommand extends AbstractTranslateCommand {

    public function execute( array $args, array $assoc_args ): void {

        // ── Validate post ─────────────────────────────────────────────────
        $post    = $this->validate_post_id( $args );
        $post_id = $post->ID;

        // ── Validate target languages ─────────────────────────────────────
        $target_langs = $this->validate_target_langs( (string) ( $assoc_args['to'] ?? '' ) );

        // ── Mode flags + WorkerConfig overrides ───────────────────────────
        $dry_run        = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run',               false );
        $force_draft    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'draft',                 false );
        $with_meta_desc = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'with-meta-description', false );
        $debug          = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'debug',                 false );

        $this->activate_debug_mode( $debug );
        $this->register_worker_overrides_filter( $assoc_args );

        $translation = $this->resolve_translation_feature();

        // ── Run per target language ───────────────────────────────────────
        $results = [];

        foreach ( $target_langs as $lang ) {

            // Wipe the existing AI-result cache entry for this post + language
            // so the previous translation can never be returned as "cached".
            CacheStore::delete( $post_id, 'translation_' . $lang );

            \WP_CLI::log( sprintf(
                'Retranslating post %d (%s) → %s%s...',
                $post_id,
                $post->post_type,
                strtoupper( $lang ),
                $dry_run ? ' [dry-run]' : ''
            ) );

            $params = [
                'target_language' => $lang,
                'translate_mode'  => 'full',
                'force_refresh'   => true,
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

            // Apply to the TRID-linked target post (create if missing).
            $applied         = $this->apply_translation( $post_id, $lang, $result, $force_draft, $with_meta_desc );
            $applied['lang'] = $lang;

            // Mark the target as synced so the ⚠ outdated indicator clears.
            if ( $applied['status'] === 'applied' && $applied['target_id'] > 0 ) {
                if ( function_exists( 'linguaforge_mark_translation_synced' ) ) {
                    linguaforge_mark_translation_synced( $applied['target_id'] );
                }
            }

            $results[] = $applied;
        }

        // ── Render results ────────────────────────────────────────────────
        $format = (string) ( $assoc_args['format'] ?? 'table' );

        \WP_CLI\Utils\format_items(
            $format,
            $results,
            [ 'lang', 'status', 'target_id', 'detail' ]
        );

        $errored = array_filter( $results, static fn( $r ): bool => $r['status'] === 'error' );
        if ( ! empty( $errored ) ) {
            \WP_CLI::halt( 1 );
        }
    }
}
