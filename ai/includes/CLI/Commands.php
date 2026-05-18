<?php

namespace LinguaForge\AI\CLI;

use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Features\MetaDescription;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

/**
 * Translate, retranslate, and manage AI cache for multilingual posts.
 *
 * ## SUBCOMMANDS
 *
 *   translate      Translate a post into one or more target languages.
 *                  Creates missing TRID-linked target posts automatically.
 *
 *   retranslate    Force a fresh retranslation, wiping the prior cache first
 *                  and marking the translation synced afterwards.
 *
 *   fill-translations      Find and fill every missing translation for a post
 *                         across all router-active languages. Skips languages
 *                         that already have a TRID-linked post.
 *
 *   missing-translations  Scan all posts of a given type and source language,
 *                         and list every post that is missing one or more
 *                         router-language translations. Use as a work-list to
 *                         drive fill-translations in bulk.
 *
 *   cache-clear    Clear AI-result cache entries (whole table, by feature,
 *                  or by post ID).
 *
 * Run `wp linguaforge <subcommand> --help` for full options and examples.
 *
 * @package Lingua Forge
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
     * [--draft]
     * : When a new target post must be created (no TRID-linked post exists yet),
     *   always create it as 'draft' regardless of the source post's status.
     *   Without this flag the new post inherits the source status, so a published
     *   source produces a published translation. Has no effect when a target post
     *   already exists (wp_update_post never changes post_status).
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
     * [--with-meta-description]
     * : After writing each translated post, generate and save an AI meta
     *   description for that post in its target language. The description is
     *   stored under _linguaforge_meta_description, exactly as if the editor
     *   had clicked "Generate Meta Description" in the post metabox. Skipped
     *   on --dry-run (no target post exists to write to).
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
     *   # Translate and force new posts to draft for editorial review first.
     *   $ wp linguaforge translate 123 --to=fr,de --draft
     *
     *   # Dry-run with a stricter temperature to inspect quality.
     *   $ wp linguaforge translate 123 --to=fr --dry-run --temperature=0.1
     *
     *   # Translate and immediately generate meta descriptions for all targets.
     *   $ wp linguaforge translate 123 --to=fr,de --with-meta-description
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
        $dry_run          = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run',               false );
        $force            = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force',                 false );
        $force_draft      = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'draft',                 false );
        $with_meta_desc   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'with-meta-description', false );

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

            // Apply mode — write to the TRID-linked target post (create if missing).
            $applied = $this->apply_translation( $post_id, $lang, $result, $force_draft, $with_meta_desc );
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
     * List posts that are missing translations in one or more router languages.
     *
     * Scans every post of a given post type whose _lang meta matches the
     * supplied source language, checks each post's TRID translation group,
     * and reports any router language for which no linked translation post
     * exists yet. Use the output as a work-list to drive fill-translations.
     *
     * ## OPTIONS
     *
     * <lang>
     * : Source language code to scan (e.g. ca, en, de).
     *   Only posts whose _lang post meta equals this value are included.
     *
     * <post_type>
     * : WordPress post type to query (e.g. post, page, or a custom type).
     *
     * [--exclude=<langs>]
     * : Comma-separated language codes to ignore when checking for missing
     *   translations (e.g. --exclude=it,fr).
     *
     * [--status=<status>]
     * : Only include posts with this post_status. Accepts any single WP
     *   status or 'any'.
     *   ---
     *   default: publish
     *   ---
     *
     * [--format=<format>]
     * : Output format.
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
     *   # Show all Catalan pages with missing translations.
     *   $ wp linguaforge missing-translations ca page
     *
     *   # Same, but ignore Italian (not yet required).
     *   $ wp linguaforge missing-translations ca page --exclude=it
     *
     *   # Include drafts as well as published posts.
     *   $ wp linguaforge missing-translations ca post --status=any
     *
     *   # Machine-readable output to feed into a shell loop.
     *   $ wp linguaforge missing-translations ca page --format=json
     *
     *   # Pipe directly to fill-translations for each found post.
     *   $ wp linguaforge missing-translations ca page --format=json \
     *       | jq -r '.[].post_id' \
     *       | xargs -I{} wp linguaforge fill-translations {} --draft
     *
     * @when after_wp_load
     */
    public function missing_translations( array $args, array $assoc_args ): void {

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
     * [--draft]
     * : When a new target post must be created (no TRID-linked post exists yet),
     *   always create it as 'draft' regardless of the source post's status.
     *   Without this flag the new post inherits the source status. Has no effect
     *   when a target post already exists.
     *
     * [--dry-run]
     * : Generate the translation but don't write to the target post.
     *   Cache is still cleared and a fresh API call is still issued.
     *
     * [--with-meta-description]
     * : After writing each translated post, generate and save an AI meta
     *   description for that post in its target language. Stored under
     *   _linguaforge_meta_description. Skipped on --dry-run.
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
     *   # Retranslate and regenerate meta descriptions for all targets.
     *   $ wp linguaforge retranslate 123 --to=fr,de --with-meta-description
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
        $dry_run        = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run',               false );
        $force_draft    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'draft',                 false );
        $with_meta_desc = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'with-meta-description', false );

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

    /**
     * Find and fill missing translations for a post across all active languages.
     *
     * Checks every language the Language Router knows about and translates any
     * that do not yet have a TRID-linked post. Languages that already have a
     * linked post are always skipped — use `retranslate` to update existing ones.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Source post ID to check and fill.
     *
     * [--check-only]
     * : Report which languages are missing or already have a translation, without
     *   making any API calls or creating any posts. Exits with code 1 when at
     *   least one language is missing — useful in CI pipelines.
     *
     * [--dry-run]
     * : Run the translation API calls for each missing language but do not write
     *   to the database. Posts are neither created nor updated.
     *
     * [--draft]
     * : Create all new translation posts as 'draft' regardless of the source
     *   post's status. Without this flag the new post inherits the source status
     *   (a published source yields a published translation). Has no effect when
     *   the target post already exists.
     *
     * [--exclude=<langs>]
     * : Comma-separated language codes to skip entirely (e.g. --exclude=it,fr).
     *
     * [--temperature=<float>]
     * : Override the AI temperature (0.0–1.0). Clamped to that range.
     *
     * [--max-tokens=<int>]
     * : Override the worker max_tokens ceiling. Useful for very long posts.
     *
     * [--model=<name>]
     * : Override the worker model string (e.g. 'claude-opus-4-6').
     *
     * [--format=<format>]
     * : Output format for the results table.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * [--with-meta-description]
     * : After writing each translated post, generate and save an AI meta
     *   description for that post in its target language. Stored under
     *   _linguaforge_meta_description. Skipped on --dry-run and --check-only.
     *
     * ## EXAMPLES
     *
     *   # See which translations are missing for post 123.
     *   $ wp linguaforge fill-translations 123 --check-only
     *
     *   # Fill all missing translations, creating new posts as draft.
     *   $ wp linguaforge fill-translations 123 --draft
     *
     *   # Fill missing, skip Italian, create as draft.
     *   $ wp linguaforge fill-translations 123 --exclude=it --draft
     *
     *   # Dry-run — translate but don't write; inspect output length per language.
     *   $ wp linguaforge fill-translations 123 --dry-run
     *
     *   # Fill missing translations and immediately generate meta descriptions.
     *   $ wp linguaforge fill-translations 123 --draft --with-meta-description
     *
     *   # CI check: exit 1 when any translation is missing.
     *   $ wp linguaforge fill-translations 123 --check-only --format=json
     *
     * @when after_wp_load
     */
    public function fill_translations( array $args, array $assoc_args ): void {

        // ── Validate post ─────────────────────────────────────────────────
        $post_id = absint( $args[0] ?? 0 );
        if ( $post_id <= 0 ) {
            \WP_CLI::error( 'A positive post ID is required as the first argument.' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            \WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
        }

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
        $format         = (string) ( $assoc_args['format'] ?? 'table' );

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
     * Generate a meta description for $target_id and persist it.
     *
     * Calls MetaDescription::run() which reads the post's _lang meta to
     * determine the language and generates a 140–160 character SEO description
     * via the configured AI provider. On success the result is saved under
     * _linguaforge_meta_description (the same key the UI metabox writes).
     *
     * Returns a short status string suitable for appending to a CLI detail
     * column: '+ meta' on success, '+ meta (error: …)' on failure.
     *
     * @param int $target_id  The already-written translated post to describe.
     * @return string  Status token for the CLI results table.
     */
    private function generate_and_save_meta_description( int $target_id ): string {

        $feature = new MetaDescription();
        $result  = $feature->run( $target_id );

        if ( empty( $result['success'] ) || empty( $result['output'] ) ) {
            $error = (string) ( $result['error'] ?? 'no output' );
            return sprintf( '+ meta (error: %s)', $error );
        }

        update_post_meta( $target_id, '_linguaforge_meta_description', $result['output'] );

        return '+ meta';
    }

    /**
     * Create a new draft post linked into the source post's TRID translation
     * group, pre-populated with the translated content and title.
     *
     * Called by apply_translation() when no TRID-linked post exists yet for
     * the target language. The new post inherits the source's post_type,
     * post_author, and post_status — so a published source produces a published
     * translation — unless $force_draft is true, in which case it is always
     * created as 'draft' for editorial review before publishing.
     *
     * Only 'publish', 'private', and 'draft' are inherited. Any other source
     * status (e.g. 'trash', 'auto-draft', 'pending') falls back to 'draft'.
     *
     * TRID handling:
     *   - If the source already has a _trid UUID, the new post shares it.
     *   - If the source has no _trid yet (edge case for posts created outside
     *     the Language Router UI), a fresh UUID is generated and assigned to
     *     both the source and the new post.
     *
     * @param int    $source_post_id Post translated FROM.
     * @param string $target_lang    Target language code (e.g. 'fr').
     * @param array  $result         Payload from Translation::run().
     * @param bool   $force_draft    Always create as 'draft', ignoring source status.
     * @return int New post ID on success, 0 on failure.
     */
    private function create_trid_linked_post( int $source_post_id, string $target_lang, array $result, bool $force_draft = false ): int {

        $source = get_post( $source_post_id );
        if ( ! $source instanceof \WP_Post ) {
            return 0;
        }

        // ── TRID — get or create ──────────────────────────────────────────
        $trid = (string) get_post_meta( $source_post_id, '_trid', true );
        if ( $trid === '' ) {
            $trid = wp_generate_uuid4();
            update_post_meta( $source_post_id, '_trid', $trid );
        }

        // ── Determine title ───────────────────────────────────────────────
        $title = ! empty( $result['translated_title'] )
            ? (string) $result['translated_title']
            : $source->post_title . ' [' . strtoupper( $target_lang ) . ']';

        // ── Resolve target post_status ────────────────────────────────────
        // Mirror source status so a published source yields a published
        // translation. Restrict to safe inheritable values; anything else
        // (trash, auto-draft, pending, …) falls back to draft.
        $allowed_statuses = [ 'publish', 'private', 'draft' ];
        $target_status    = $force_draft
            ? 'draft'
            : ( in_array( $source->post_status, $allowed_statuses, true )
                ? $source->post_status
                : 'draft' );

        // ── Create the post, bypassing our own save hook ──────────────────
        $router = \LinguaForge\Router\Router::get_instance();
        remove_action( 'wp_after_insert_post', [ $router, 'handle_save_post' ],  10 );
        remove_action( 'wp_after_insert_post', [ $router, 'handle_cache_clear' ], 20 );

        $new_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => (string) ( $result['output'] ?? '' ),
            'post_status'  => $target_status,
            'post_type'    => $source->post_type,
            'post_author'  => $source->post_author,
        ], true );

        add_action( 'wp_after_insert_post', [ $router, 'handle_save_post' ],  10, 2 );
        add_action( 'wp_after_insert_post', [ $router, 'handle_cache_clear' ], 20 );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            if ( is_wp_error( $new_id ) ) {
                \WP_CLI::warning( sprintf(
                    'wp_insert_post failed for %s: %s',
                    strtoupper( $target_lang ),
                    $new_id->get_error_message()
                ) );
            }
            return 0;
        }

        // ── Link into TRID group ──────────────────────────────────────────
        update_post_meta( $new_id, '_trid', $trid );
        update_post_meta( $new_id, '_lang', $target_lang );

        // ── Footnotes ─────────────────────────────────────────────────────
        if ( ! empty( $result['footnotes'] ) ) {
            update_post_meta( $new_id, 'footnotes', (string) $result['footnotes'] );
        }

        return (int) $new_id;
    }

    /**
     * Write a successful translation result into the TRID-linked target-lang
     * post (post_content + optional post_title), and persist the footnotes
     * meta if the result carries it.
     *
     * When no TRID-linked post exists for the target language, a new post of the
     * same post_type is created, linked into the source's TRID group, and the
     * translated content is written into it immediately. The new post inherits
     * the source's post_status unless $force_draft is true, in which case it is
     * always created as 'draft' for editorial review.
     *
     * @param int    $source_post_id  Post we translated FROM.
     * @param string $target_lang     Target language code (e.g. 'fr').
     * @param array  $result          Payload returned by Translation::run().
     * @param bool   $force_draft     When true, always create missing posts as 'draft'.
     * @param bool   $with_meta_desc  When true, generate and save a meta description
     *                                for the target post after writing its content.
     * @return array{status:string,target_id:int,detail:string}
     */
    private function apply_translation( int $source_post_id, string $target_lang, array $result, bool $force_draft = false, bool $with_meta_desc = false ): array {

        // linguaforge_get_translations() is the procedural wrapper around
        // Router::get_translations() — works regardless of whether the
        // Language Router class is namespaced or back-compat-aliased.
        $translations = function_exists( 'linguaforge_get_translations' )
            ? linguaforge_get_translations( $source_post_id )
            : [];

        if ( empty( $translations[ $target_lang ] ) ) {
            // No TRID-linked post yet — create one.
            $target_id = $this->create_trid_linked_post( $source_post_id, $target_lang, $result, $force_draft );

            if ( ! $target_id ) {
                return [
                    'status'    => 'error',
                    'target_id' => 0,
                    'detail'    => 'could not create target post — check wp_insert_post error log',
                ];
            }

            // Assign a language-specific FSE template when one exists.
            $target_post = get_post( $target_id );
            if ( $target_post instanceof \WP_Post ) {
                \LinguaForge\Router\Router::get_instance()->assign_template_if_needed( $target_id, $target_post, $target_lang );
            }

            $created_post   = get_post( $target_id );
            $created_status = $created_post instanceof \WP_Post ? $created_post->post_status : 'unknown';

            $meta_token = $with_meta_desc ? ' ' . $this->generate_and_save_meta_description( $target_id ) : '';

            return [
                'status'    => 'created',
                'target_id' => $target_id,
                'detail'    => sprintf(
                    'created %s post %d (%s)%s',
                    $created_status,
                    $target_id,
                    ! empty( $result['cached'] ) ? 'from cache' : 'fresh translation',
                    $meta_token
                ),
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

        $meta_token = $with_meta_desc ? ' ' . $this->generate_and_save_meta_description( $target_id ) : '';

        return [
            'status'    => 'applied',
            'target_id' => $target_id,
            'detail'    => sprintf(
                'updated %d (%s)%s',
                $target_id,
                ! empty( $result['cached'] ) ? 'from cache' : 'fresh translation',
                $meta_token
            ),
        ];
    }
}
