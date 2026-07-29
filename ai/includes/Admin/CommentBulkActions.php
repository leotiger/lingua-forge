<?php
/**
 * Class LinguaForge\AI\Admin\CommentBulkActions
 *
 * "Translate missing" bulk action + per-row "Translate" link on the Comments
 * screen (wp-admin/edit-comments.php) — the manual trigger path for Comment
 * Translation, and the ONLY trigger at all when
 * `linguaforge_comment_translation_mode` is 'manual' (the default). See
 * lingua-forge-audit/PROPOSAL-comment-translation-2026-07-29.md §2.4.
 *
 * Lives in the AI module (not language-router/) because it calls into
 * `CommentTranslation`, which makes AI provider requests — same ai/ vs
 * language-router/ split as the rest of the feature (CommentMirror owns the
 * data model; this and CommentTranslation own the AI-calling orchestration).
 *
 * @package LinguaForge\AI\Admin
 * @since   2.7.0
 */

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Features\CommentTranslation;
use LinguaForge\Router\Comments\CommentMirror;
use LinguaForge\Router\Router;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

class CommentBulkActions {

	public static function init(): void {
		// Feature is off entirely — don't offer an action that would do
		// nothing. Plugin::boot() re-evaluates this fresh on every admin
		// request, so flipping the setting takes effect on the next load.
		if ( ! CommentMirror::feature_enabled() ) {
			return;
		}

		add_filter( 'bulk_actions-edit-comments', [ self::class, 'add_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-comments', [ self::class, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_notices', [ self::class, 'render_admin_notice' ] );

		add_filter( 'comment_row_actions', [ self::class, 'add_row_action' ], 10, 2 );
		add_action( 'admin_post_linguaforge_translate_comment', [ self::class, 'handle_translate_comment' ] );
	}

	// =========================================================
	// BULK ACTION
	// =========================================================

	public static function add_bulk_action( array $actions ): array {
		$actions['lf_translate_missing_comments'] = __( 'Translate missing', 'lingua-forge' );
		return $actions;
	}

	/**
	 * Translates every selected comment into whatever sibling languages it's
	 * currently missing a mirror for. Selecting a comment that's already
	 * fully mirrored, ineligible (wrong post/comment type, unapproved), or
	 * already a mirror itself is simply a no-op for that row — same
	 * "picking it for a row it doesn't apply to is a no-op" precedent as
	 * TrashCascade's bulk action.
	 *
	 * @param string $redirect_to  Redirect URL core is about to send the browser to.
	 * @param string $doaction     The bulk action slug that was submitted.
	 * @param int[]  $comment_ids  Selected comment IDs.
	 */
	public static function handle_bulk_action( string $redirect_to, string $doaction, array $comment_ids ): string {
		if ( 'lf_translate_missing_comments' !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'moderate_comments' ) ) {
			return $redirect_to;
		}

		$translated = 0;
		$failed     = 0;

		foreach ( $comment_ids as $comment_id ) {
			$result      = CommentTranslation::translate_comment( (int) $comment_id );
			$translated += count( $result['translated'] );
			$failed     += count( $result['failed'] );
		}

		$redirect_to = remove_query_arg( [ 'lf_comment_translated', 'lf_comment_failed' ], $redirect_to );

		return add_query_arg(
			[
				'lf_comment_translated' => $translated,
				'lf_comment_failed'     => $failed,
			],
			$redirect_to
		);
	}

	public static function render_admin_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-comments' !== $screen->id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only integer GET flags set by wp_safe_redirect() after the nonce-verified bulk/row action already ran; no data is modified here, and absint() is the effective sanitization.
		if ( ! isset( $_GET['lf_comment_translated'] ) ) {
			return;
		}

		$translated = absint( wp_unslash( $_GET['lf_comment_translated'] ) );
		$failed     = isset( $_GET['lf_comment_failed'] ) ? absint( wp_unslash( $_GET['lf_comment_failed'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of comment-language mirrors created */
			_n( '%d comment translation created.', '%d comment translations created.', $translated, 'lingua-forge' ),
			$translated
		);

		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of comment/language pairs not yet translated */
				_n(
					'%d pair could not be translated yet (e.g. a nested reply waiting on its parent, or a language currently in cooldown after repeated failures).',
					'%d pairs could not be translated yet (e.g. nested replies waiting on their parent, or a language currently in cooldown after repeated failures).',
					$failed,
					'lingua-forge'
				),
				$failed
			);
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	// =========================================================
	// ROW ACTION — single-comment "Translate"
	// =========================================================

	public static function add_row_action( array $actions, WP_Comment $comment ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return $actions;
		}

		$mirror = Router::get_instance()->comment_mirror;
		if ( ! $mirror->is_eligible_comment( $comment ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=linguaforge_translate_comment&comment=' . $comment->comment_ID ),
			'lf_translate_comment_' . $comment->comment_ID
		);

		$actions['lf_translate_comment'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Translate', 'lingua-forge' ) . '</a>';

		return $actions;
	}

	public static function handle_translate_comment(): void {
		$comment_id = isset( $_GET['comment'] ) ? absint( wp_unslash( $_GET['comment'] ) ) : 0;

		if ( ! $comment_id || ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'lf_translate_comment_' . $comment_id );

		$result = CommentTranslation::translate_comment( $comment_id );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'edit-comments.php' );
		}
		$redirect = remove_query_arg( [ 'lf_comment_translated', 'lf_comment_failed' ], $redirect );
		$redirect = add_query_arg(
			[
				'lf_comment_translated' => count( $result['translated'] ),
				'lf_comment_failed'     => count( $result['failed'] ),
			],
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
