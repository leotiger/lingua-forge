<?php

namespace LinguaForge\AI\CLI;

use LinguaForge\AI\Core\TranslationDebug;
use LinguaForge\AI\Features\MetaDescription;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

/**
 * Shared base for the three CLI commands that drive Translation::run() —
 * translate, retranslate, fill_translations.
 *
 * Holds the helpers that were previously private methods on Commands:
 *
 *   collect_worker_config_overrides() / register_worker_overrides_filter()
 *   resolve_translation_feature()
 *   dump_debug_files()
 *   apply_translation() / create_trid_linked_post()
 *   generate_and_save_meta_description()
 *
 * Plus the validators that translate / retranslate share (post-id parsing,
 * target-lang parsing).
 *
 * Subclasses implement execute(array $args, array $assoc_args): void as their
 * sole public entry point — called from the matching facade method on
 * \LinguaForge\AI\CLI\Commands.
 *
 * MissingTranslationsCommand and CacheClearCommand do NOT extend this class —
 * they do not run the translation pipeline.
 */
abstract class AbstractTranslateCommand {

    /**
     * Subcommand entry point. Receives the WP-CLI positional and associative
     * argument arrays exactly as the facade method on Commands forwards them.
     */
    abstract public function execute( array $args, array $assoc_args ): void;

    // ── Argument validation ──────────────────────────────────────────────────

    /**
     * Parse $args[0] as a positive integer post ID and resolve the WP_Post.
     * Calls \WP_CLI::error (which halts) on any failure.
     */
    protected function validate_post_id( array $args ): \WP_Post {

        $post_id = absint( $args[0] ?? 0 );
        if ( $post_id <= 0 ) {
            \WP_CLI::error( 'A positive post ID is required as the first argument.' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            \WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
        }

        return $post;
    }

    /**
     * Parse the --to=<csv> assoc arg into a list of validated language codes.
     * Calls \WP_CLI::error on empty input or unknown codes.
     *
     * @return list<string>
     */
    protected function validate_target_langs( string $to_raw ): array {

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

        return $target_langs;
    }

    /**
     * When $debug is true, flip Translation into force-debug mode and announce
     * the debug-files directory to the terminal.
     */
    protected function activate_debug_mode( bool $debug ): void {

        if ( ! $debug ) {
            return;
        }

        TranslationDebug::force_debug( true );
        \WP_CLI::log( '[LF debug] Debug mode ON — prompts and responses will be shown inline and written to ' . TranslationDebug::debug_dir() );
    }

    /**
     * If the caller supplied any of --temperature / --max-tokens / --model,
     * install a high-priority filter on `linguaforge_translation_worker_config`
     * that applies the overrides for the remainder of the process.
     *
     * No-op when no overrides were supplied — the filter is never registered.
     */
    protected function register_worker_overrides_filter( array $assoc_args ): void {

        $overrides = $this->collect_worker_config_overrides( $assoc_args );

        if ( empty( $overrides ) ) {
            return;
        }

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

    /**
     * Resolve the Translation feature singleton from the Registry, erroring
     * out if the AI module is not active.
     */
    protected function resolve_translation_feature(): Translation {

        $translation = Registry::get( 'translation' );
        if ( $translation instanceof Translation ) {
            return $translation;
        }

        \WP_CLI::error( 'Translation feature is not registered. Is the AI module active?' );
    }

    // ── Worker config overrides ──────────────────────────────────────────────

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

    // ── Debug ────────────────────────────────────────────────────────────────

    /**
     * Print the most recently written debug files for a given post + language
     * to the WP-CLI terminal.
     *
     * Called immediately after Translation::run() when --debug is active.
     * Finds the newest {post_id}-{lang}-*-source.txt and …-response.txt files
     * in the debug directory and echoes their contents with clear section
     * headers so the prompt and raw API response are visible inline without
     * tailing a log file.
     */
    protected function dump_debug_files( int $post_id, string $lang ): void {

        $debug_dir = TranslationDebug::debug_dir();

        foreach ( [ 'source', 'response', 'tm-source', 'tm-response' ] as $suffix ) {

            $pattern = $debug_dir . '/' . $post_id . '-' . $lang . '-*-' . $suffix . '.txt';
            $files   = glob( $pattern );

            if ( empty( $files ) ) {
                continue;
            }

            // Sort by modification time descending; take the newest.
            usort( $files, static fn( string $a, string $b ): int => filemtime( $b ) <=> filemtime( $a ) );
            $file = $files[0];

            // Only dump files written in the last 60 seconds (this run).
            if ( time() - filemtime( $file ) > 60 ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- $file is a local path resolved by glob() from the plugin's own debug directory three lines above; not a remote URL. The sniff's wp_remote_get() suggestion only applies to HTTP fetches.
            $content = (string) file_get_contents( $file );

            \WP_CLI::log( '' );
            \WP_CLI::log( sprintf(
                '[LF debug] ── %s (%s → %s) ──────────────',
                strtoupper( $suffix ),
                $post_id,
                strtoupper( $lang )
            ) );
            \WP_CLI::log( $content );
            \WP_CLI::log( '[LF debug] ────────────────────────────────────' );
        }
    }

    // ── Apply ────────────────────────────────────────────────────────────────

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
     * @return array{status:string,target_id:int,detail:string}
     */
    protected function apply_translation( int $source_post_id, string $target_lang, array $result, bool $force_draft = false, bool $with_meta_desc = false ): array {

        // linguaforge_get_translations() is the procedural wrapper around
        // \LinguaForge\Router\Router::get_translations().
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
                \LinguaForge\Router\Router::get_instance()->sync->assign_template_if_needed( $target_id, $target_post, $target_lang );
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
            // wp_update_post() does NOT auto-regenerate post_name when post_title
            // changes, so we derive it explicitly here — matching the behaviour of
            // wp_insert_post() used in create_trid_linked_post() for new posts.
            $update_args['post_name']  = sanitize_title( (string) $result['translated_title'] );
        }

        if ( isset( $result['translated_excerpt'] ) ) {
            $update_args['post_excerpt'] = (string) $result['translated_excerpt'];
        }

        // Bypass our own save-post handler so this content-only update doesn't
        // touch the TRID group, language metadata, or outdated flag — those
        // were correct as the editor set them.
        $router = \LinguaForge\Router\Router::get_instance();

        remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'  ], 10 );
        remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

        $updated = wp_update_post( $update_args, true );

        add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'  ], 10, 2 );
        add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

        if ( is_wp_error( $updated ) ) {
            return [
                'status'    => 'error',
                'target_id' => $target_id,
                'detail'    => $updated->get_error_message(),
            ];
        }

        // Invalidate TRID translation cache — same reason as in
        // create_trid_linked_post(): handle_cache_clear was detached during
        // wp_update_post so the update didn't self-invalidate.  The content
        // changed so any persistent-cache entry for this TRID group is stale.
        $router->trid_group->clear_translation_cache( $target_id );

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
            $router->sync->assign_template_if_needed( $target_id, $target_post, $target_lang );
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
     * @return int New post ID on success, 0 on failure.
     */
    private function create_trid_linked_post( int $source_post_id, string $target_lang, array $result, bool $force_draft = false ): int {

        $source = get_post( $source_post_id );
        if ( ! $source instanceof \WP_Post ) {
            return 0;
        }

        // ── TRID — get or create ──────────────────────────────────────────
        $trid = (string) get_post_meta( $source_post_id, '_lf_trid', true );
        if ( $trid === '' ) {
            $trid = wp_generate_uuid4();
            update_post_meta( $source_post_id, '_lf_trid', $trid );
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
        // After §2.2 Router split: handle_save_post is on Sync, handle_cache_clear on TridGroup.
        $router = \LinguaForge\Router\Router::get_instance();
        remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'  ], 10 );
        remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

        $new_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => (string) ( $result['output'] ?? '' ),
            'post_status'  => $target_status,
            'post_type'    => $source->post_type,
            'post_author'  => (int) $source->post_author,
        ], true );

        add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'  ], 10, 2 );
        add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

        // With $wp_error = true, wp_insert_post returns int<1,max>|WP_Error,
        // so the WP_Error case is the only failure path.
        if ( is_wp_error( $new_id ) ) {
            \WP_CLI::warning( sprintf(
                'wp_insert_post failed for %s: %s',
                strtoupper( $target_lang ),
                $new_id->get_error_message()
            ) );
            return 0;
        }

        // ── Link into TRID group ──────────────────────────────────────────
        update_post_meta( $new_id, '_lf_trid', $trid );
        update_post_meta( $new_id, '_lf_lang', $target_lang );

        // ── Footnotes ─────────────────────────────────────────────────────
        if ( ! empty( $result['footnotes'] ) ) {
            update_post_meta( $new_id, 'footnotes', (string) $result['footnotes'] );
        }

        // ── Invalidate TRID translation cache ─────────────────────────────
        // handle_cache_clear was detached during wp_insert_post to avoid a
        // premature flush before the _lf_trid/_lf_lang meta were written.
        // Now that the group membership is complete, clear explicitly so the
        // next frontend request re-queries the DB and finds the new post.
        // This is critical when a persistent object cache (Redis) is active:
        // without this flush the language switcher keeps serving the stale
        // group until the target post is opened and re-saved in the editor.
        $router->trid_group->clear_translation_cache( $new_id );

        /**
         * Fires after a translated post has been created, linked into its TRID
         * group, and its translation cache invalidated.
         *
         * Only fires in the CLI (server-side save) path. In the editor flow the
         * translated content is returned to the browser and saved client-side via
         * Gutenberg; no server-side post ID is available at REST response time.
         *
         * @param int    $new_id          Post ID of the newly created translation.
         * @param int    $source_post_id  Post ID of the source (original) post.
         * @param string $target_lang     Two-letter target language code (e.g. 'es').
         */
        do_action( 'linguaforge_translation_complete', (int) $new_id, $source_post_id, $target_lang );

        return (int) $new_id;
    }

    /**
     * Generate a meta description for $target_id and persist it.
     *
     * Returns a short status string suitable for appending to a CLI detail
     * column: '+ meta' on success, '+ meta (error: …)' on failure.
     */
    protected function generate_and_save_meta_description( int $target_id ): string {

        $feature = new MetaDescription();
        $result  = $feature->run( $target_id );

        if ( empty( $result['success'] ) || empty( $result['output'] ) ) {
            $error = (string) ( $result['error'] ?? 'no output' );
            return sprintf( '+ meta (error: %s)', $error );
        }

        update_post_meta( $target_id, '_linguaforge_meta_description', $result['output'] );

        return '+ meta';
    }
}
