<?php

namespace LinguaForge\AI\CLI;

use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for LinguaForge AI.
 *
 * Registered from ai/ai.php when WP_CLI is defined. Each public method below
 * maps to a sub-command:
 *
 *   wp linguaforge translate     →  translate()
 *   wp linguaforge retranslate   →  retranslate()
 *   wp linguaforge cache-clear   →  cache_clear()  (underscore → hyphen)
 *
 * The class itself is autoloaded the first time WP-CLI dispatches one of its
 * methods, so command registration cost on every WP-CLI invocation is zero.
 */
class Commands {

    /**
     * Translate a post into one or more target languages.
     *
     * Runs the full Translation feature pipeline (cache check, JSON schema,
     * provider retry/backoff, Compliance preset, JSON envelope parsing) for
     * each target language, then either prints a dry-run summary or writes
     * the translated content into the TRID-linked target-language post via
     * wp_update_post.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Source post ID to translate from.
     *
     * --to=<langs>
     * : Comma-separated list of target language codes (e.g. fr,de,es).
     *   Each must be present in Translation::get_languages().
     *
     * [--dry-run]
     * : Generate the translation but don't write anywhere. Default behavior
     *   without --dry-run is to apply the translation to the TRID-linked
     *   target-language post (if one exists) via wp_update_post.
     *
     * [--force]
     * : Skip the cache. Forces a fresh API call even when an unchanged-input
     *   cache entry exists.
     *
     * [--temperature=<float>]
     * : Override the worker temperature (0.0–1.0). Clamped to that range.
     *   When omitted, the feature default (0.2) plus any active Compliance
     *   preset override applies.
     *
     * [--max-tokens=<int>]
     * : Override the worker max_tokens. Useful when translating very long
     *   pages that hit truncation with the default ceiling.
     *
     * [--model=<name>]
     * : Override the worker model string (e.g. 'claude-sonnet-4-6').
     *
     * [--format=<format>]
     * : Output format for the per-language results table.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * ## EXAMPLES
     *
     *   # Translate post 123 into French and German, applying to linked posts.
     *   $ wp linguaforge translate 123 --to=fr,de
     *
     *   # Dry-run with a stricter temperature to inspect quality.
     *   $ wp linguaforge translate 123 --to=fr --dry-run --temperature=0.1
     *
     *   # Force a refresh and override the model for a single batch.
     *   $ wp linguaforge translate 123 --to=fr,de --force --model=claude-opus-4-6
     *
     * @when after_wp_load
     */
    public function translate( array $args, array $assoc_args ): void {

        // ── Validate post ─────────────────────────────────────────────────
        $post_id = absint( $args[0] ?? 0 );
        if ( $post_id <= 0 ) {
            \WP_CLI::error( 'A positive post ID is required as the first argument.' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            \WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
        }

        // ── Validate target languages ─────────────────────────────────────
        $to_raw = (string) ( $assoc_args['to'] ?? '' );
        if ( $to_raw === '' ) {
            \WP_CLI::error( '--to=<langs> is required (comma-separated language codes, e.g. --to=fr,de).' );
        }

        $target_langs = array_values( array_filter( array_map(
            static fn( $s ): string => sanitize_key( trim( (string) $s ) ),
            explode( ',', $to_raw )
        ) ) );

        if ( empty( $target_langs ) ) {
            \WP_CLI::error( 'Could not parse --to into any language codes.' );
        }

        $known_langs = Translation::get_languages();
        foreach ( $target_langs as $lang ) {
            if ( ! array_key_exists( $lang, $known_langs ) ) {
                \WP_CLI::error( sprintf(
                    "Unknown language code '%s'. Available codes: %s",
                    $lang,
                    implode( ', ', array_keys( $known_langs ) )
                ) );
            }
        }

        // ── Mode flags + WorkerConfig overrides ───────────────────────────
        $dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $force   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

        $overrides = $this->collect_worker_config_overrides( $assoc_args );

        if ( ! empty( $overrides ) ) {
            $filter = static function ( WorkerConfig $config ) use ( $overrides ): WorkerConfig {
                return new WorkerConfig(
                    model:           $overrides['model']       ?? $config->model,
                    max_tokens:      $overrides['max_tokens']  ?? $config->max_tokens,
                    temperature:     $overrides['temperature'] ?? $config->temperature,
                    response_schema: $config->response_schema,
                );
            };
            add_filter( 'linguaforge_translation_worker_config', $filter, 99, 1 );
        }

        // ── Resolve the Translation feature ───────────────────────────────
        $translation = Registry::get( 'translation' );
        if ( ! $translation instanceof Translation ) {
            \WP_CLI::error( 'Translation feature is not registered. Is the AI module active?' );
        }

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

            // Apply mode — write to the TRID-linked target post.
            $applied = $this->apply_translation( $post_id, $lang, $result );
            $applied['lang'] = $lang;
            $results[] = $applied;
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

    /**
     * Clear AI-result cache entries.
     *
     * With no options, truncates the entire wp_lingua_forge_ai_cache table
     * (matching the Maintenance → AI Cache → "Clear AI Cache" button). Use
     * the options to scope deletion to a single feature, a single post, or
     * both.
     *
     * ## OPTIONS
     *
     * [--feature=<name>]
     * : Feature-key prefix to match. 'translation' clears every per-language
     *   translation cache (translation_fr, translation_de, …). Other valid
     *   prefixes: 'meta-description', 'excerpt', 'content_generator'.
     *
     * [--post-id=<id>]
     * : Only clear entries for this post ID.
     *
     * [--yes]
     * : Skip the confirmation prompt when truncating the whole table.
     *
     * ## EXAMPLES
     *
     *   # Clear every cached translation across the whole site.
     *   $ wp linguaforge cache-clear --feature=translation
     *
     *   # Clear all cached AI results for one post.
     *   $ wp linguaforge cache-clear --post-id=123
     *
     *   # Nuke the whole cache, no prompt.
     *   $ wp linguaforge cache-clear --yes
     *
     * @when after_wp_load
     */
    public function cache_clear( array $args, array $assoc_args ): void {

        $feature = isset( $assoc_args['feature'] ) ? sanitize_key( (string) $assoc_args['feature'] ) : '';
        $post_id = absint( $assoc_args['post-id'] ?? 0 );

        $criteria = [];
        if ( $feature !== '' ) {
            $criteria['feature_prefix'] = $feature;
        }
        if ( $post_id > 0 ) {
            $criteria['post_id'] = $post_id;
        }

        // Confirm before a whole-table truncate unless --yes is passed.
        if ( empty( $criteria ) ) {
            \WP_CLI::confirm( 'This will clear every AI-result cache entry. Proceed?', $assoc_args );
        }

        $count = CacheStore::clear( $criteria );

        $scope_desc = [];
        if ( $feature !== '' ) $scope_desc[] = sprintf( "feature '%s'", $feature );
        if ( $post_id > 0 )    $scope_desc[] = sprintf( 'post %d',    $post_id );

        if ( empty( $scope_desc ) ) {
            \WP_CLI::success( sprintf( 'Cleared %d cache entries (whole table).', $count ) );
        } else {
            \WP_CLI::success( sprintf(
                'Cleared %d cache entries scoped to %s.',
                $count,
                implode( ' / ', $scope_desc )
            ) );
        }
    }

    /**
     * Force a fresh retranslation of a post, clearing any cached result first.
     *
     * Designed for the common "source post was edited, retranslate now" workflow.
     * The AI-result cache for each target language is wiped before the run, so the
     * previous translation is never returned. On success, the TRID-linked target post
     * is updated and its outdated flag is cleared.
     *
     * This is equivalent to `wp linguaforge translate <id> --to=<langs> --force`, but
     * with `--temperature` as a first-class argument and with automatic sync-flag
     * clearing — so the ⚠ outdated indicator in the post list disappears after the run.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Source post ID to retranslate from.
     *
     * --to=<langs>
     * : Comma-separated list of target language codes (e.g. fr,de,es).
     *   Each must be present in Translation::get_languages().
     *
     * [--temperature=<float>]
     * : Override the AI temperature (0.0–1.0). Clamped to that range.
     *   When omitted, the active Behavior preset temperature is used:
     *   Standard 0.4 / Technical 0.2 / Legal 0.1 / Creative 0.7.
     *
     * [--max-tokens=<int>]
     * : Override the worker max_tokens ceiling. Useful for very long posts.
     *
     * [--model=<name>]
     * : Override the worker model string (e.g. 'claude-opus-4-6').
     *
     * [--dry-run]
     * : Generate the translation but don't write to the target post.
     *   Cache is still cleared and a fresh API call is still issued.
     *
     * [--format=<format>]
     * : Output format for the per-language results table.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * ## EXAMPLES
     *
     *   # Retranslate post 123 into French after editing the source.
     *   $ wp linguaforge retranslate 123 --to=fr
     *
     *   # Retranslate with legal-grade temperature for a terms-of-service page.
     *   $ wp linguaforge retranslate 123 --to=fr,de --temperature=0.1
     *
     *   # Dry-run to verify output quality before committing.
     *   $ wp linguaforge retranslate 123 --to=es --temperature=0.2 --dry-run
     *
     *   # Retranslate with a specific model override.
     *   $ wp linguaforge retranslate 123 --to=fr --model=claude-opus-4-6
     *
     * @when after_wp_load
     */
    public function retranslate( array $args, array $assoc_args ): void {

        // ── Validate post ─────────────────────────────────────────────────
        $post_id = absint( $args[0] ?? 0 );
        if ( $post_id <= 0 ) {
            \WP_CLI::error( 'A positive post ID is required as the first argument.' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            \WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
        }

        // ── Validate target languages ─────────────────────────────────────
        $to_raw = (string) ( $assoc_args['to'] ?? '' );
        if ( $to_raw === '' ) {
            \WP_CLI::error( '--to=<langs> is required (comma-separated language codes, e.g. --to=fr,de).' );
        }

        $target_langs = array_values( array_filter( array_map(
            static fn( $s ): string => sanitize_key( trim( (string) $s ) ),
            explode( ',', $to_raw )
        ) ) );

        if ( empty( $target_langs ) ) {
            \WP_CLI::error( 'Could not parse --to into any language codes.' );
        }

        $known_langs = Translation::get_languages();
        foreach ( $target_langs as $lang ) {
            if ( ! array_key_exists( $lang, $known_langs ) ) {
                \WP_CLI::error( sprintf(
                    "Unknown language code '%s'. Available codes: %s",
                    $lang,
                    implode( ', ', array_keys( $known_langs ) )
                ) );
            }
        }

        // ── Mode flags + WorkerConfig overrides ───────────────────────────
        $dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

        $overrides = $this->collect_worker_config_overrides( $assoc_args );

        if ( ! empty( $overrides ) ) {
            $filter = static function ( WorkerConfig $config ) use ( $overrides ): WorkerConfig {
                return new WorkerConfig(
                    model:           $overrides['model']       ?? $config->model,
                    max_tokens:      $overrides['max_tokens']  ?? $config->max_tokens,
                    temperature:     $overrides['temperature'] ?? $config->temperature,
                    response_schema: $config->response_schema,
                );
            };
            add_filter( 'linguaforge_translation_worker_config', $filter, 99, 1 );
        }

        // ── Resolve the Translation feature ───────────────────────────────
        $translation = Registry::get( 'translation' );
        if ( ! $translation instanceof Translation ) {
            \WP_CLI::error( 'Translation feature is not registered. Is the AI module active?' );
        }

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

            // Apply to the TRID-linked target post.
            $applied         = $this->apply_translation( $post_id, $lang, $result );
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

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Pull --temperature / --max-tokens / --model from $assoc_args, validating
     * and clamping each. Returns only the keys the user actually supplied so
     * the override closure can leave unset fields at their feature defaults.
     *
     * @return array{model?:string,max_tokens?:int,temperature?:float}
     */
    private function collect_worker_config_overrides( array $assoc_args ): array {

        $overrides = [];

        if ( isset( $assoc_args['temperature'] ) ) {
            $raw = $assoc_args['temperature'];
            if ( is_numeric( $raw ) ) {
                $overrides['temperature'] = max( 0.0, min( 1.0, (float) $raw ) );
            } else {
                \WP_CLI::warning( sprintf( "--temperature='%s' is not numeric; ignored.", (string) $raw ) );
            }
        }

        if ( isset( $assoc_args['max-tokens'] ) ) {
            $raw = $assoc_args['max-tokens'];
            if ( is_numeric( $raw ) && (int) $raw > 0 ) {
                $overrides['max_tokens'] = (int) $raw;
            } else {
                \WP_CLI::warning( sprintf( "--max-tokens='%s' must be a positive integer; ignored.", (string) $raw ) );
            }
        }

        if ( isset( $assoc_args['model'] ) ) {
            $raw = trim( (string) $assoc_args['model'] );
            if ( $raw !== '' ) {
                $overrides['model'] = $raw;
            }
        }

        return $overrides;
    }

    /**
     * Write a successful translation result into the TRID-linked target-lang
     * post (post_content + optional post_title), and persist the footnotes
     * meta if the result carries it.
     *
     * Skips with status 'skipped' when no linked post exists — matches the
     * editor flow's invariant of never auto-creating posts (that's the
     * translate-missing command's future job).
     *
     * @param int    $source_post_id Post we translated FROM.
     * @param string $target_lang    Target language code (e.g. 'fr').
     * @param array  $result         Payload returned by Translation::run().
     * @return array{status:string,target_id:int,detail:string}
     */
    private function apply_translation( int $source_post_id, string $target_lang, array $result ): array {

        // linguaforge_get_translations() is the procedural wrapper around
        // Router::get_translations() — works regardless of whether the
        // Language Router class is namespaced or back-compat-aliased.
        $translations = function_exists( 'linguaforge_get_translations' )
            ? linguaforge_get_translations( $source_post_id )
            : [];

        if ( empty( $translations[ $target_lang ] ) ) {
            return [
                'status'    => 'skipped',
                'target_id' => 0,
                'detail'    => 'no TRID-linked target post (run translate-missing to create one — coming later)',
            ];
        }

        $target_id = (int) $translations[ $target_lang ];

        $update_args = [
            'ID'           => $target_id,
            'post_content' => (string) ( $result['output'] ?? '' ),
        ];

        if ( ! empty( $result['translated_title'] ) ) {
            $update_args['post_title'] = (string) $result['translated_title'];
        }

        // Bypass our own save-post handler so this content-only update doesn't
        // touch the TRID group, language metadata, or outdated flag — those
        // were correct as the editor set them. Same pattern as LSFLR_Link_Fixer.
        // Use the canonical namespaced class name (back-compat alias
        // Language_Router is slated for removal in 1.5).
        $router = \LinguaForge\Router\Router::get_instance();

        remove_action( 'wp_after_insert_post', [ $router, 'handle_save_post' ], 10 );
        remove_action( 'wp_after_insert_post', [ $router, 'handle_cache_clear' ], 20 );

        $updated = wp_update_post( $update_args, true );

        add_action( 'wp_after_insert_post', [ $router, 'handle_save_post' ], 10, 2 );
        add_action( 'wp_after_insert_post', [ $router, 'handle_cache_clear' ], 20 );

        if ( is_wp_error( $updated ) ) {
            return [
                'status'    => 'error',
                'target_id' => $target_id,
                'detail'    => $updated->get_error_message(),
            ];
        }

        // Footnotes meta — only when the translation produced one.
        if ( ! empty( $result['footnotes'] ) ) {
            update_post_meta( $target_id, 'footnotes', (string) $result['footnotes'] );
        }

        // Template alignment — if the target post is on the default template,
        // assign the language-specific one (page-{lang} for pages,
        // single-{lang} for posts) when a matching wp_template exists. The
        // in-method guard inside Router::assign_template_if_needed() leaves
        // any explicit admin choice alone, so this is safe to run on every
        // CLI apply rather than only when the target was hastily-created.
        $target_post = get_post( $target_id );
        if ( $target_post instanceof \WP_Post ) {
            $router->assign_template_if_needed( $target_id, $target_post, $target_lang );
        }

        return [
            'status'    => 'applied',
            'target_id' => $target_id,
            'detail'    => sprintf(
                'updated %d (%s)',
                $target_id,
                ! empty( $result['cached'] ) ? 'from cache' : 'fresh translation'
            ),
        ];
    }
}
