<?php
/**
 * Class LinguaForge\AI\Admin\PostListColumn
 *
 * Injects action buttons into the existing "Lang" column (rendered by
 * LinguaForge\Router\Admin\Columns) for two scenarios:
 *
 *   • "Translate missing" — shown on source-language posts that are missing
 *     one or more target-language translations.  Hooks `lf_lang_column_missing`.
 *
 *   • "Retranslate" — shown on every TRID-linked post regardless of outdated
 *     status, so editors can force a fresh translation at any time.
 *     Hooks `lf_lang_column_retranslate`.
 *
 * Both hooks are fired by Columns::render_lang_column() in the language-router
 * module, keeping the AI module fully decoupled.
 *
 * @package LinguaForge\AI\Admin
 * @since   1.8.1
 */

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Features\MetaDescription;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class PostListColumn {

	const AJAX_ACTION            = 'lf_fill_missing';
	const NONCE_NAME             = 'lf_fill_missing_nonce';
	const AJAX_ACTION_RETRANSLATE = 'lf_retranslate';
	const NONCE_NAME_RETRANSLATE  = 'lf_retranslate_nonce';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// "Translate missing" — fires on source posts with missing translations.
		add_action( 'lf_lang_column_missing', [ self::class, 'render_fill_button' ], 10, 2 );

		// "Retranslate" — fires on every post regardless of outdated status.
		add_action( 'lf_lang_column_retranslate', [ self::class, 'render_retranslate_button' ], 10, 1 );

		// Enqueue JS + CSS on the post list screen.
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );

		// AJAX handlers — admin-only (logged-in user required by WordPress).
		add_action( 'wp_ajax_' . self::AJAX_ACTION,            [ self::class, 'ajax_fill_missing' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_RETRANSLATE, [ self::class, 'ajax_retranslate'  ] );
	}

	// =========================================================================
	// Button output
	// =========================================================================

	/**
	 * Output the "Translate missing" button inside the Lang column cell.
	 *
	 * Called via the `lf_lang_column_missing` action fired by
	 * Columns::render_lang_column() when a post has at least one missing
	 * target-language translation.
	 *
	 * @param int      $post_id  Current post ID.
	 * @param string[] $missing  Missing language codes (unused here — AJAX
	 *                           recalculates server-side to stay authoritative).
	 */
	public static function render_fill_button( int $post_id, array $missing ): void {
		// wp_navigation posts are translated via the FSE navigation pipeline
		// (Router tab → ajax_translate_fse_navigation), not the standard AI
		// translate-missing flow. Skip the button so the wrong handler is never
		// triggered from the Navigation List admin screen.
		$post = get_post( $post_id );
		if ( $post && $post->post_type === 'wp_navigation' ) {
			return;
		}

		printf(
			' <button type="button" class="button button-small lf-fill-missing" data-post-id="%d">%s</button>',
			esc_attr( (string) $post_id ),
			esc_html__( 'Translate missing', 'lingua-forge' )
		);
	}

	/**
	 * Output the "Retranslate" selector + button in the Lang column.
	 *
	 * Renders a <select> listing every language in the TRID group except the
	 * current post's own language, so the editor can choose which version to
	 * translate from.  Excluding the current language prevents a meaningless
	 * "retranslate German from German" operation.
	 *
	 * Called via the `lf_lang_column_retranslate` action fired unconditionally
	 * by Columns::render_lang_column() for every post, whether or not the ⚠
	 * outdated indicator is present.  Returns early if the post has no TRID
	 * siblings to translate from.
	 *
	 * @param int $post_id  Current post ID.
	 */
	public static function render_retranslate_button( int $post_id ): void {
		$current_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $post_id )
			: [];

		// Remove own language — retranslating from the same language makes no sense.
		unset( $translations[ $current_lang ] );

		if ( empty( $translations ) ) {
			return; // No other language available to translate from.
		}

		echo ' <span class="lf-retranslate-wrap">';

		// Language selector.
		echo '<select class="lf-retranslate-from">';
		foreach ( $translations as $lang => $_ ) {
			printf(
				'<option value="%s">%s %s</option>',
				esc_attr( $lang ),
				/* translators: label prefix for the retranslate language picker */
				esc_html__( 'From', 'lingua-forge' ),
				esc_html( strtoupper( $lang ) )
			);
		}
		echo '</select>';

		// Button.
		printf(
			'<button type="button" class="button button-small lf-retranslate" data-post-id="%d">%s</button>',
			esc_attr( (string) $post_id ),
			esc_html__( 'Retranslate', 'lingua-forge' )
		);

		echo '</span>';
	}

	// =========================================================================
	// Asset enqueue
	// =========================================================================

	/**
	 * Enqueue the post-list script + stylesheet on the post list (edit.php) screen.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		// Shared stylesheet (also loaded on post.php by MetaBox::enqueue()).
		wp_enqueue_style(
			'lingua-forge-admin',
			LINGUAFORGE_AI_URL . '/assets/admin.css',
			[],
			LINGUAFORGE_VERSION
		);

		wp_enqueue_script(
			'lf-post-list',
			LINGUAFORGE_AI_URL . '/assets/post-list.js',
			[ 'jquery' ],
			LINGUAFORGE_VERSION,
			true
		);

		wp_localize_script(
			'lf-post-list',
			'lfPostList',
			[
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'action'              => self::AJAX_ACTION,
				'nonce'               => wp_create_nonce( self::NONCE_NAME ),
				'actionRetranslate'   => self::AJAX_ACTION_RETRANSLATE,
				'nonceRetranslate'    => wp_create_nonce( self::NONCE_NAME_RETRANSLATE ),
				'l10n'                => [
					'translating'  => __( 'Translating…', 'lingua-forge' ),
					'retranslating' => __( 'Retranslating…', 'lingua-forge' ),
					'done'         => __( '✓ Done', 'lingua-forge' ),
					'error'        => __( 'Error', 'lingua-forge' ),
				],
			]
		);
	}

	// =========================================================================
	// AJAX handler
	// =========================================================================

	/**
	 * Fill all missing target-language translations for a given source post.
	 *
	 * Replicates FillTranslationsCommand logic without the WP-CLI scaffolding.
	 * For each missing language: calls Translation::run(), then creates a new
	 * TRID-linked post or updates the existing one.
	 */
	public static function ajax_fill_missing(): void {
		// ── Auth + nonce ──────────────────────────────────────────────────────
		check_ajax_referer( self::NONCE_NAME, 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_ajax_referer above.
		if ( $post_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => 'Post not found.' ] );
		}

		// wp_navigation posts must be translated via the Router tab's FSE
		// navigation pipeline (ajax_translate_fse_navigation), which applies
		// label-only translation rules and URL rewriting. Reject here so a stale
		// "Translate missing" button from before the TRID group was populated
		// cannot trigger the generic AI pipeline on a navigation post.
		if ( $post->post_type === 'wp_navigation' ) {
			wp_send_json_error( [
				'message' => __( 'Navigation posts are translated via the Router tab, not this button.', 'lingua-forge' ),
			] );
		}

		// ── Determine which languages are missing ─────────────────────────────
		$router      = Router::get_instance();
		$source_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $source_lang ) {
			$source_lang = $router->source_language();
		}

		$all_langs    = $router->languages();
		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $post_id )
			: [];

		$to_fill = [];
		foreach ( $all_langs as $lang ) {
			if ( $lang !== $source_lang && empty( $translations[ $lang ] ) ) {
				$to_fill[] = $lang;
			}
		}

		if ( empty( $to_fill ) ) {
			wp_send_json_success( [
				'message' => 'All translations already exist.',
				'results' => [],
			] );
		}

		// ── Creation gate ─────────────────────────────────────────────────────
		// Allow external integrations (e.g. WooCommerce) to block translated-post
		// creation until their own delegation layer is active.  Returning false
		// from this filter prevents creating a broken product post that lacks
		// price, stock, and other operational meta.
		if ( ! apply_filters( 'linguaforge_cpt_create_allowed', true, $post->post_type ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: post type slug */
					__( 'Creating translations for post type "%s" requires an active integration. Please ensure the required integration is installed and active.', 'lingua-forge' ),
					$post->post_type
				),
			] );
		}

		// ── Translation feature ───────────────────────────────────────────────
		$translation = Registry::get( 'translation' );
		if ( ! $translation instanceof Translation ) {
			wp_send_json_error( [ 'message' => 'Translation feature not available. Is an AI provider configured?' ] );
		}

		// ── Bypass save hooks for the duration of the batch ───────────────────
		// Same pattern as AbstractTranslateCommand: detach our own save-post /
		// cache-clear handlers so wp_insert_post / wp_update_post calls don't
		// fire them mid-batch, then restore afterwards.
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$results = [];
		$errors  = 0;

		foreach ( $to_fill as $lang ) {
			$result = $translation->run( $post_id, [
				'target_language' => $lang,
				'translate_mode'  => 'full',
				'force_refresh'   => false,
			] );

			if ( empty( $result['success'] ) ) {
				$results[ $lang ] = [
					'status'  => 'error',
					'message' => (string) ( $result['error'] ?? 'unknown error' ),
				];
				++$errors;
				continue;
			}

			// Re-fetch after run() in case a previous iteration created a post
			// and the TRID group has changed.
			$translations = function_exists( 'linguaforge_get_translations' )
				? linguaforge_get_translations( $post_id )
				: [];

			if ( empty( $translations[ $lang ] ) ) {
				$outcome = self::create_linked_post( $post, $lang, $result );
			} else {
				$outcome = self::update_linked_post( (int) $translations[ $lang ], $result );
			}

			if ( 'error' === $outcome['status'] ) {
				++$errors;
			} else {
				// Generate and persist a meta description for the new/updated post,
				// reusing the same MetaDescription::run() stack as the CLI's
				// --with-meta-description flag.
				self::generate_meta_description( (int) $outcome['id'] );
			}

			$results[ $lang ] = $outcome;
		}

		// ── Restore save hooks ────────────────────────────────────────────────
		add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		if ( $errors > 0 && $errors === count( $to_fill ) ) {
			wp_send_json_error( [ 'message' => 'All translations failed.', 'results' => $results ] );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * Retranslate an outdated target-language post.
	 *
	 * Replicates RetranslateCommand logic without WP-CLI scaffolding:
	 *   1. Resolves the source post via the TRID group.
	 *   2. Wipes the per-language AI cache entry so no stale result is returned.
	 *   3. Calls Translation::run() with force_refresh = true.
	 *   4. Updates the target post content / title.
	 *   5. Calls linguaforge_mark_translation_synced() to clear the ⚠ flag.
	 *   6. Regenerates the meta description.
	 */
	public static function ajax_retranslate(): void {
		// ── Auth + nonce ──────────────────────────────────────────────────────
		check_ajax_referer( self::NONCE_NAME_RETRANSLATE, 'nonce' );

		$target_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_ajax_referer above.
		if ( $target_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
		}

		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$target_post = get_post( $target_id );
		if ( ! $target_post instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => 'Post not found.' ] );
		}

		// ── Resolve target language, from-language, and source post ─────────
		$router      = Router::get_instance();
		$target_lang = (string) get_post_meta( $target_id, '_lf_lang', true );
		if ( '' === $target_lang ) {
			wp_send_json_error( [ 'message' => 'Target post has no language assigned.' ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$from_lang = sanitize_key( wp_unslash( $_POST['from_lang'] ?? '' ) );
		if ( '' === $from_lang ) {
			$from_lang = $router->source_language(); // Fallback to configured source.
		}

		if ( $from_lang === $target_lang ) {
			wp_send_json_error( [ 'message' => 'Cannot retranslate a post into the same language it is already in.' ] );
		}

		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $target_id )
			: [];

		$source_id = (int) ( $translations[ $from_lang ] ?? 0 );

		if ( $source_id <= 0 ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: language code */
					__( 'No %s version found in this translation group.', 'lingua-forge' ),
					strtoupper( $from_lang )
				),
			] );
		}

		// ── Translation feature ───────────────────────────────────────────────
		$translation = Registry::get( 'translation' );
		if ( ! $translation instanceof Translation ) {
			wp_send_json_error( [ 'message' => 'Translation feature not available. Is an AI provider configured?' ] );
		}

		// ── Wipe stale cache entry ────────────────────────────────────────────
		CacheStore::delete( $source_id, 'translation_' . $target_lang );

		// ── Bypass save hooks ─────────────────────────────────────────────────
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$result = $translation->run( $source_id, [
			'target_language' => $target_lang,
			'translate_mode'  => 'full',
			'force_refresh'   => true,
		] );

		// ── Restore save hooks ────────────────────────────────────────────────
		add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [
				'message' => (string) ( $result['error'] ?? 'Translation failed.' ),
			] );
		}

		// ── Update target post ────────────────────────────────────────────────
		$outcome = self::update_linked_post( $target_id, $result );

		if ( 'error' === $outcome['status'] ) {
			wp_send_json_error( [ 'message' => $outcome['message'] ?? 'Could not update post.' ] );
		}

		// ── Clear outdated flag ───────────────────────────────────────────────
		if ( function_exists( 'linguaforge_mark_translation_synced' ) ) {
			linguaforge_mark_translation_synced( $target_id );
		}

		// ── Regenerate meta description ───────────────────────────────────────
		self::generate_meta_description( $target_id );

		wp_send_json_success( $outcome );
	}

	// =========================================================================
	// Private helpers — post create / update
	// =========================================================================

	/**
	 * Create a new post linked into the source's TRID group, populated with
	 * the translated content.
	 *
	 * Mirrors AbstractTranslateCommand::create_trid_linked_post() but returns
	 * an associative result array instead of calling WP_CLI::warning().
	 *
	 * @param  \WP_Post $source  Source post.
	 * @param  string   $lang    Target language code.
	 * @param  array    $result  Translation::run() result.
	 * @return array{status:string,id?:int,edit_url?:string,message?:string}
	 */
	private static function create_linked_post( \WP_Post $source, string $lang, array $result ): array {
		$post_id = $source->ID;

		// ── Ensure the source has a TRID UUID ─────────────────────────────────
		$trid = (string) get_post_meta( $post_id, '_lf_trid', true );
		if ( '' === $trid ) {
			$trid = wp_generate_uuid4();
			update_post_meta( $post_id, '_lf_trid', $trid );
		}

		// ── Derive post title ─────────────────────────────────────────────────
		$title = ! empty( $result['translated_title'] )
			? (string) $result['translated_title']
			: $source->post_title . ' [' . strtoupper( $lang ) . ']';

		// ── Inherit source status (publish / private / draft only) ────────────
		$allowed_statuses = [ 'publish', 'private', 'draft' ];
		$target_status    = in_array( $source->post_status, $allowed_statuses, true )
			? $source->post_status
			: 'draft';

		$new_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => (string) ( $result['output'] ?? '' ),
			'post_status'  => $target_status,
			'post_type'    => $source->post_type,
			'post_author'  => (int) $source->post_author,
		], true );

		if ( is_wp_error( $new_id ) ) {
			return [
				'status'  => 'error',
				'message' => $new_id->get_error_message(),
			];
		}

		// ── Link into TRID group ──────────────────────────────────────────────
		update_post_meta( $new_id, '_lf_trid', $trid );
		update_post_meta( $new_id, '_lf_lang', $lang );

		if ( ! empty( $result['footnotes'] ) ) {
			update_post_meta( $new_id, 'footnotes', (string) $result['footnotes'] );
		}

		// Flush TRID cache now that group membership is complete.
		Router::get_instance()->trid_group->clear_translation_cache( $new_id );

		// Assign a language-specific FSE template if one exists.
		$new_post = get_post( $new_id );
		if ( $new_post instanceof \WP_Post ) {
			Router::get_instance()->sync->assign_template_if_needed( $new_id, $new_post, $lang );
		}

		return [
			'status'   => 'created',
			'id'       => $new_id,
			'edit_url' => (string) ( get_edit_post_link( $new_id ) ?: '' ),
		];
	}

	/**
	 * Generate and persist a meta description for a freshly translated post.
	 *
	 * Mirrors AbstractTranslateCommand::generate_and_save_meta_description().
	 * Failures are silently swallowed — a missing meta description is not a
	 * reason to surface an error to the user for a list-screen batch action.
	 *
	 * @param int $target_id  Post ID to generate a meta description for.
	 */
	private static function generate_meta_description( int $target_id ): void {
		$feature = new MetaDescription();
		$result  = $feature->run( $target_id );

		if ( ! empty( $result['success'] ) && ! empty( $result['output'] ) ) {
			update_post_meta( $target_id, '_linguaforge_meta_description', (string) $result['output'] );
		}
	}

	/**
	 * Update an existing TRID-linked target post with freshly translated content.
	 *
	 * @param  int   $target_id  Target post ID.
	 * @param  array $result     Translation::run() result.
	 * @return array{status:string,id?:int,edit_url?:string,message?:string}
	 */
	private static function update_linked_post( int $target_id, array $result ): array {
		$update = [
			'ID'           => $target_id,
			'post_content' => (string) ( $result['output'] ?? '' ),
		];

		if ( ! empty( $result['translated_title'] ) ) {
			$update['post_title'] = (string) $result['translated_title'];
			$update['post_name']  = sanitize_title( (string) $result['translated_title'] );
		}

		if ( isset( $result['translated_excerpt'] ) ) {
			$update['post_excerpt'] = (string) $result['translated_excerpt'];
		}

		$updated = wp_update_post( $update, true );

		if ( is_wp_error( $updated ) ) {
			return [
				'status'  => 'error',
				'message' => $updated->get_error_message(),
			];
		}

		Router::get_instance()->trid_group->clear_translation_cache( $target_id );

		if ( ! empty( $result['footnotes'] ) ) {
			update_post_meta( $target_id, 'footnotes', (string) $result['footnotes'] );
		}

		return [
			'status'   => 'updated',
			'id'       => $target_id,
			'edit_url' => (string) ( get_edit_post_link( $target_id ) ?: '' ),
		];
	}
}
