<?php
/**
 * Class LinguaForge\AI\Features\TranslationQueue
 *
 * Deferred (off-request) execution wrapper around TranslationTrigger::run().
 *
 * linguaforge_trigger_translation() is synchronous: it makes one blocking AI
 * call in the current request. Programmatic publishers that translate into every
 * active language from a single intake request (e.g. a webhook or IMAP handler
 * creating a post, then translating it N times) therefore make N blocking AI
 * calls in one PHP process — which times out or backs up at scale.
 *
 * This class moves that work off the request:
 *   - queue()      schedules one job per call (Action Scheduler if available,
 *                  WP-Cron otherwise).
 *   - run_queued() is the worker the scheduler fires; it runs the normal
 *                  TranslationTrigger pipeline (and so still fires
 *                  `linguaforge_translation_complete` on success).
 *
 * Both backends dispatch the same hook with the same ordered arguments, so a
 * single run_queued() callback serves both.
 *
 * @see linguaforge_queue_translation() — public wrapper function in ai/ai.php.
 * @since 2.4.0
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\Log;

if ( ! defined( 'ABSPATH' ) ) exit;

class TranslationQueue {

	/** Hook fired (by Action Scheduler or WP-Cron) to run one queued translation. */
	public const HOOK = 'linguaforge_run_queued_translation';

	/** Action Scheduler group — keeps LF jobs identifiable in the AS admin UI. */
	public const GROUP = 'lingua-forge';

	/**
	 * Register the worker callback.
	 *
	 * MUST be called unconditionally at module load (from ai/ai.php), NOT inside
	 * Plugin::boot(): boot() short-circuits on plain frontend and WP-Cron
	 * requests, but a queued job runs in exactly such a (cron) request and needs
	 * its callback present. Registered via the class-string form so the class is
	 * only autoloaded when the hook actually fires, not on every page load.
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, [ self::class, 'run_queued' ], 10, 3 );
	}

	/**
	 * Queue a translation job for deferred execution.
	 *
	 * Prefers Action Scheduler (ships with WooCommerce and many hosts); falls
	 * back to a single WP-Cron event. Duplicate pending jobs for the same
	 * post + language + params are skipped so a burst of identical saves collapses
	 * into one translation.
	 *
	 * @param int    $source_post_id Source-language post ID.
	 * @param string $target_lang    Target language code (BCP-47 short form, e.g. 'es').
	 * @param array  $params         Forwarded verbatim to linguaforge_trigger_translation().
	 */
	public static function queue( int $source_post_id, string $target_lang, array $params = [] ): void {

		$args = [ $source_post_id, $target_lang, $params ];

		// ── Action Scheduler path ───────────────────────────────────────────
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if (
				function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK, $args, self::GROUP )
			) {
				return; // identical job already pending
			}
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			return;
		}

		// ── WP-Cron fallback ────────────────────────────────────────────────
		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return; // identical event already queued (debounce)
		}
		wp_schedule_single_event( time(), self::HOOK, $args );
	}

	/**
	 * Worker: run one queued translation.
	 *
	 * Runs in a cron / Action Scheduler request where Plugin::boot() (hence
	 * Registry::init()) may not have run, so it self-initialises the feature
	 * registry. Errors are logged (WP_DEBUG-gated) and swallowed — a background
	 * job has no caller to hand a WP_Error back to. Success and failure are
	 * both recorded via TranslationBackfill so a timed-out or otherwise-failed
	 * job leaves a trail: TranslationBackfill's recurring scan uses that state
	 * to find and re-queue the still-missing (post, lang) pair automatically,
	 * rather than the gap sitting silently until someone notices.
	 *
	 * @param int    $source_post_id Source-language post ID.
	 * @param string $target_lang    Target language code.
	 * @param array  $params         Params forwarded to TranslationTrigger::run().
	 */
	public static function run_queued( int $source_post_id, string $target_lang, array $params = [] ): void {

		if ( ! Registry::get( 'translation' ) ) {
			Registry::init();
		}

		$result = TranslationTrigger::run( $source_post_id, $target_lang, $params );

		if ( is_wp_error( $result ) ) {
			Log::debug( sprintf(
				'Lingua Forge AI [TranslationQueue] queued translation of post %d to "%s" failed: %s',
				$source_post_id,
				$target_lang,
				$result->get_error_message()
			) );
			TranslationBackfill::record_failure( $source_post_id, $target_lang, $result->get_error_message() );
			return;
		}

		TranslationBackfill::clear_failure( $source_post_id, $target_lang );
	}
}
