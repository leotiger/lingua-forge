<?php
/**
 * Class LinguaForge\AI\Admin\PostListColumn
 *
 * Injects a "Translate missing" button into the existing "Lang" column
 * (rendered by LinguaForge\Router\Admin\Columns) whenever a source-language
 * post is missing one or more target-language translations.
 *
 * Integration point: the language-router fires the `lf_lang_column_missing`
 * action inside Columns::render_lang_column() after printing the missing-
 * language indicator.  We hook onto that action to append the button, keeping
 * the AI module fully decoupled from the language-router module.
 *
 * The AJAX handler (wp_ajax_lf_fill_missing) replicates the logic of
 * FillTranslationsCommand without WP-CLI scaffolding: it calls
 * Translation::run() for each missing language, creates or updates the
 * TRID-linked target post, assigns an FSE template when one exists, and
 * flushes the TRID translation cache.
 *
 * @package LinguaForge\AI\Admin
 * @since   1.9.0
 */

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class PostListColumn {

	const AJAX_ACTION = 'lf_fill_missing';
	const NONCE_NAME  = 'lf_fill_missing_nonce';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Append the button after the language-router's missing-language output.
		add_action( 'lf_lang_column_missing', [ self::class, 'render_fill_button' ], 10, 2 );

		// Enqueue JS + CSS on the post list screen.
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );

		// AJAX handler — admin-only (logged-in user required by WordPress).
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax_fill_missing' ] );
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
		printf(
			'<br><button type="button" class="button button-small lf-fill-missing" data-post-id="%d">%s</button>',
			esc_attr( (string) $post_id ),
			esc_html__( 'Translate missing', 'lingua-forge' )
		);
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_NAME ),
				'l10n'    => [
					'translating' => __( 'Translating…', 'lingua-forge' ),
					'done'        => __( '✓ Done', 'lingua-forge' ),
					'error'       => __( 'Error', 'lingua-forge' ),
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
