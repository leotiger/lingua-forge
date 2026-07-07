<?php
/**
 * Class LinguaForge\AI\Features\TranslationBackfill
 *
 * Self-heals posts left with missing-language gaps after a queued translation
 * (TranslationQueue::run_queued()) times out, errors, or is otherwise lost —
 * e.g. a cron tick that never fired, an Action Scheduler action that expired,
 * a provider outage mid-request. Without this, such a gap is silent: nothing
 * ever revisits it, and an admin only discovers it by noticing a missing
 * language switcher entry, or by running the `missing_translations` /
 * `fill_translations` WP-CLI commands by hand.
 *
 * A recurring cron tick (self::run(), hooked to self::CRON_HOOK) periodically
 * re-derives the same "which posts are missing which active language" gap
 * those CLI commands compute, and re-queues just the missing (post, lang)
 * pairs via TranslationQueue::queue() — the same async pipeline a normal save
 * uses, so a recovered translation goes through the identical create/update +
 * TRID-link + cache-clear flow.
 *
 * Per-(post, lang) failure state (attempt count, last-attempt time, last
 * error) is written by TranslationQueue::run_queued() on every queued job —
 * success or failure — via record_failure() / clear_failure() below. This
 * scan reads that state to avoid hammering a (post, lang) pair that keeps
 * failing for a structural reason (e.g. a revoked API key) on every single
 * cron tick: once a pair has failed MAX_ATTEMPTS times in a row, it's left
 * alone for COOLDOWN_SECONDS before the next attempt — enough for a bad key
 * to get fixed or a provider outage to end without an unbounded retry storm.
 *
 * @since 2.5.3
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Core\Log;

if ( ! defined( 'ABSPATH' ) ) exit;

class TranslationBackfill {

	/** Cron hook the recurring scan runs on. */
	public const CRON_HOOK = 'linguaforge_backfill_missing_translations';

	/** Meta key on the *source* post holding per-target-language failure state. */
	public const FAILURE_META_KEY = '_lf_translation_failures';

	/** Consecutive failures a (post, lang) pair gets before it's put in cooldown. */
	private const MAX_ATTEMPTS = 5;

	/** How long a pair that hit MAX_ATTEMPTS is left alone before one more try. */
	private const COOLDOWN_SECONDS = DAY_IN_SECONDS;

	/**
	 * Cap on how many jobs a single scan tick queues. Keeps one cron/AS
	 * invocation fast and bounds how many AI calls a large backlog can trigger
	 * at once; any remainder is picked up on the next tick.
	 */
	private const MAX_JOBS_PER_RUN = 25;

	/**
	 * Register the cron callback and the "is the recurring event actually
	 * scheduled?" self-heal check.
	 *
	 * Called unconditionally at module load (from ai/ai.php), matching
	 * TranslationQueue's own worker-registration hook: this runs in a WP-Cron
	 * request, which never boots Plugin::boot()/Registry::init(), so the
	 * callback must be present regardless of request type.
	 */
	public static function register_hooks(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run' ] );

		// Checked on every 'init', not just plugin activation: activation hooks
		// are skipped entirely on SFTP/rsync deploys (see the analogous
		// linguaforge_bootstrap_overrides_dir() comment in lingua-forge.php),
		// and a scheduled event can also be silently dropped by a wp_cron
		// table clear, a migration, or a host's cron manager. wp_next_scheduled()
		// is a cheap read against the (autoloaded) cron option, so checking it
		// unconditionally on init is negligible overhead and makes the schedule
		// itself self-healing rather than a one-time setup step.
		add_action( 'init', [ self::class, 'maybe_schedule' ] );
	}

	/**
	 * Ensure the recurring scan is scheduled. No-op if it already is.
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Cancel the recurring scan. Called from the plugin's deactivation hook.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	// =========================================================
	// SCAN
	// =========================================================

	/**
	 * Worker: find posts with a TRID-group gap in any active language and
	 * queue a translation job for each missing (post, lang) pair, up to
	 * MAX_JOBS_PER_RUN.
	 *
	 * Runs in a cron / Action Scheduler request, same self-init requirement as
	 * TranslationQueue::run_queued().
	 */
	public static function run(): void {

		if ( ! Registry::get( 'translation' ) ) {
			Registry::init();
		}

		if ( ! class_exists( \LinguaForge\Router\Router::class ) ) {
			return; // language-router module not present — nothing to reconcile.
		}

		$router      = \LinguaForge\Router\Router::get_instance();
		$source_lang = $router->source_language();
		$targets     = array_values( array_filter(
			$router->languages(),
			static fn( string $l ): bool => $l !== $source_lang
		) );

		if ( empty( $targets ) ) {
			return;
		}

		$queued = 0;

		foreach ( self::post_types() as $post_type ) {
			if ( $queued >= self::MAX_JOBS_PER_RUN ) {
				break;
			}
			$queued += self::scan_post_type( $post_type, $source_lang, $targets, self::MAX_JOBS_PER_RUN - $queued );
		}

		if ( $queued > 0 ) {
			Log::debug( sprintf(
				'Lingua Forge AI [TranslationBackfill] queued %d missing-translation job(s) this run.',
				$queued
			) );
		}
	}

	/**
	 * Public post types the scan covers: 'post', 'page', and any public CPT,
	 * minus WordPress' own internal types. Mirrors the exclusion list used by
	 * the admin Lang column (class-columns.php) and filter dropdowns
	 * (class-filters.php) so "which post types does Lingua Forge manage" stays
	 * consistent across the plugin.
	 *
	 * @return string[]
	 */
	private static function post_types(): array {

		$internal = [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_navigation',
			'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
			'wp_navigation_fallback',
		];

		$types = array_values( array_diff(
			array_keys( get_post_types( [ 'public' => true ] ) ),
			$internal
		) );

		/**
		 * Filter the post types the automatic missing-translation backfill scans.
		 *
		 * @param string[] $types
		 */
		return (array) apply_filters( 'linguaforge_backfill_post_types', $types );
	}

	/**
	 * Scan one post type for source-language posts with a translation gap,
	 * queue up to $limit missing (post, lang) pairs, and return how many were
	 * queued.
	 */
	private static function scan_post_type( string $post_type, string $source_lang, array $targets, int $limit ): int {

		if ( $limit <= 0 ) {
			return 0;
		}

		$query = new \WP_Query( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional full-type scan; runs at most once per hour off-request, not on a frontend path.
				[
					'key'   => '_lf_lang',
					'value' => $source_lang,
				],
			],
			'no_found_rows'  => true,
			'fields'         => 'ids',
		] );

		if ( empty( $query->posts ) ) {
			return 0;
		}

		$queued = 0;

		foreach ( $query->posts as $post_id ) {

			if ( $queued >= $limit ) {
				break;
			}

			$post_id      = (int) $post_id;
			$translations = function_exists( 'linguaforge_get_translations' )
				? linguaforge_get_translations( $post_id )
				: [];
			$failures     = self::get_failure_state( $post_id );

			foreach ( $targets as $lang ) {

				if ( $queued >= $limit ) {
					break;
				}

				if ( ! empty( $translations[ $lang ] ) ) {
					continue; // already linked — not a gap.
				}

				if ( self::is_in_cooldown( $failures, $lang ) ) {
					continue; // repeatedly failing — leave it until the cooldown lapses.
				}

				TranslationQueue::queue( $post_id, $lang );
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * True if $lang has hit MAX_ATTEMPTS for this post and the last attempt
	 * was recent enough that it's still within its cooldown window.
	 */
	private static function is_in_cooldown( array $failures, string $lang ): bool {

		$state = $failures[ $lang ] ?? null;
		if ( ! $state ) {
			return false;
		}

		if ( (int) ( $state['attempts'] ?? 0 ) < self::MAX_ATTEMPTS ) {
			return false;
		}

		return ( time() - (int) ( $state['last_attempt'] ?? 0 ) ) < self::COOLDOWN_SECONDS;
	}

	// =========================================================
	// FAILURE-STATE BOOKKEEPING
	// Called by TranslationQueue::run_queued() on every queued job, not just
	// ones this scan itself queued — any queued translation's outcome (a
	// normal save's async job included) feeds the same state.
	// =========================================================

	/**
	 * Record a queued-translation failure for (source post, target lang).
	 */
	public static function record_failure( int $source_post_id, string $target_lang, string $error_message ): void {

		$failures = self::get_failure_state( $source_post_id );
		$prior    = $failures[ $target_lang ] ?? [ 'attempts' => 0 ];

		$failures[ $target_lang ] = [
			'attempts'     => (int) $prior['attempts'] + 1,
			'last_attempt' => time(),
			'last_error'   => $error_message,
		];

		update_post_meta( $source_post_id, self::FAILURE_META_KEY, $failures );
	}

	/**
	 * Clear failure state for (source post, target lang) after a successful
	 * queued translation.
	 */
	public static function clear_failure( int $source_post_id, string $target_lang ): void {

		$failures = self::get_failure_state( $source_post_id );
		if ( ! isset( $failures[ $target_lang ] ) ) {
			return;
		}

		unset( $failures[ $target_lang ] );

		if ( empty( $failures ) ) {
			delete_post_meta( $source_post_id, self::FAILURE_META_KEY );
		} else {
			update_post_meta( $source_post_id, self::FAILURE_META_KEY, $failures );
		}
	}

	/**
	 * @return array<string,array{attempts:int,last_attempt:int,last_error:string}>
	 */
	private static function get_failure_state( int $source_post_id ): array {

		$failures = get_post_meta( $source_post_id, self::FAILURE_META_KEY, true );
		return is_array( $failures ) ? $failures : [];
	}
}
