<?php
/**
 * Class LinguaForge\AI\Features\CommentTranslationQueue
 *
 * Deferred (off-request) execution wrapper around
 * CommentTranslation::translate_comment(), and the 'auto' mode wiring
 * (see Settings → Behavior → Comment Translation → Translation trigger).
 * Same shape as TranslationQueue: Action Scheduler when available, a single
 * WP-Cron event otherwise, de-duplicated per comment.
 *
 * In 'manual' mode (the default), neither of this class's two triggering
 * hooks queues anything — translation only happens via the admin-triggered
 * "Translate missing" bulk action, which calls CommentTranslation directly.
 *
 * @package LinguaForge\AI\Features
 * @since   2.7.0
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\Log;
use LinguaForge\Router\Comments\CommentMirror;
use LinguaForge\Router\Router;
use WP_Comment;

if ( ! defined( 'ABSPATH' ) ) exit;

class CommentTranslationQueue {

	/** Hook fired (by Action Scheduler or WP-Cron) to run one queued comment translation. */
	public const HOOK = 'linguaforge_run_queued_comment_translation';

	/** Action Scheduler group — keeps LF jobs identifiable in the AS admin UI. */
	public const GROUP = 'lingua-forge';

	/**
	 * Register the worker callback and the 'auto'-mode triggering hooks.
	 *
	 * MUST be called unconditionally at module load (from ai/ai.php), NOT
	 * inside Plugin::boot() — a queued job runs in exactly such a (cron)
	 * request and needs its callback present, same reasoning as
	 * TranslationQueue::register_hooks().
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, [ self::class, 'run_queued' ], 10, 1 );

		// 'auto' mode triggers — both gated internally on CommentMirror::mode().
		// Priority 20 on each: CommentMirror's own group-ID assignment
		// (wp_insert_comment) and status cascade (transition_comment_status)
		// are registered at the default priority 10, and must run first so
		// is_eligible_comment()'s is_canonical() check sees a group ID
		// that's already been assigned.
		add_action( 'transition_comment_status', [ self::class, 'maybe_queue_on_status_change' ], 20, 3 );
		add_action( 'wp_insert_comment', [ self::class, 'maybe_queue_on_insert' ], 20, 2 );
	}

	/** A comment approved via normal moderation — the common 'auto'-mode trigger. */
	public static function maybe_queue_on_status_change( string $new_status, string $old_status, WP_Comment $comment ): void {
		if ( 'approved' !== $new_status || $old_status === $new_status ) {
			return;
		}
		self::maybe_queue( (int) $comment->comment_ID );
	}

	/** A comment that arrives ALREADY approved (trusted commenter, or moderation disabled) never fires transition_comment_status, so this covers that case directly. */
	public static function maybe_queue_on_insert( int $comment_id, $comment ): void {
		if ( ! $comment instanceof WP_Comment ) {
			$comment = get_comment( $comment_id );
		}
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}
		if ( '1' !== (string) $comment->comment_approved ) {
			return; // Held for moderation — the status-change hook above covers it once approved.
		}
		self::maybe_queue( $comment_id );
	}

	private static function maybe_queue( int $comment_id ): void {
		if ( 'auto' !== CommentMirror::mode() ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		$mirror = Router::get_instance()->comment_mirror;
		if ( ! $mirror->is_eligible_comment( $comment ) ) {
			return;
		}

		self::queue( $comment_id );
	}

	/**
	 * Queue a comment-translation job for deferred execution. Prefers Action
	 * Scheduler; falls back to a single WP-Cron event. Duplicate pending
	 * jobs for the same comment are skipped.
	 */
	public static function queue( int $comment_id ): void {
		$args = [ $comment_id ];

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if (
				function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK, $args, self::GROUP )
			) {
				return;
			}
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			return;
		}

		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::HOOK, $args );
	}

	/**
	 * Worker: run one queued comment translation. Runs in a cron / Action
	 * Scheduler request where Plugin::boot() may not have run, so it
	 * self-initialises the feature registry — same precedent as
	 * TranslationQueue::run_queued().
	 */
	public static function run_queued( int $comment_id ): void {
		if ( ! Registry::get( 'translation' ) ) {
			Registry::init();
		}

		$result = CommentTranslation::translate_comment( $comment_id );

		if ( ! empty( $result['failed'] ) ) {
			Log::debug( sprintf(
				'Lingua Forge AI [CommentTranslationQueue] comment %d had failures: %s',
				$comment_id,
				wp_json_encode( $result['failed'] )
			) );
		}
	}
}
