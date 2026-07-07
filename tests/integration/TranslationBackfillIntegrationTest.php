<?php
/**
 * Integration tests for LinguaForge\AI\Features\TranslationBackfill.
 *
 * Shipped in 2.5.3 with no test coverage at all — this file closes that gap.
 * Covers the recurring-scan worker (run()), the schedule/unschedule pair, the
 * per-(post, lang) failure-cooldown bookkeeping, the MAX_JOBS_PER_RUN cap, and
 * the linguaforge_backfill_post_types filter.
 *
 * run() only ever calls TranslationQueue::queue() — never run_queued() — so no
 * test here makes an AI call or touches the network. queue() itself falls back
 * to a single WP-Cron event (wp_schedule_single_event) when Action Scheduler
 * isn't loaded, which is the case in the plain wp-env integration suite (no
 * WooCommerce), so "was a job queued for (post, lang)?" is asserted via
 * wp_next_scheduled( TranslationQueue::HOOK, [ $post_id, $lang, [] ] ).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Features\TranslationBackfill;
use LinguaForge\AI\Features\TranslationQueue;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class TranslationBackfillIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';
	private const TRANS_LANG  = 'de';

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );

		// Pin the active language list deterministically, same technique as
		// AdminBarLocaleSwitcherIntegrationTest / PostListColumnIntegrationTest.
		add_filter( 'lf_languages_list', static fn (): array => [ self::SOURCE_LANG, self::TRANS_LANG ] );

		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'lf_languages_list' );
		remove_all_filters( 'linguaforge_backfill_post_types' );

		$timestamp = wp_next_scheduled( TranslationBackfill::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, TranslationBackfill::CRON_HOOK );
		}

		parent::tearDown();
	}

	/**
	 * Creates a published, source-language post with a TRID assigned but no
	 * translation siblings — i.e. exactly the "gap" TranslationBackfill looks for.
	 */
	private function make_source_post_with_gap( string $post_type = 'post' ): int {
		$post_id    = self::factory()->post->create( [ 'post_type' => $post_type, 'post_status' => 'publish' ] );
		$trid_group = Router::get_instance()->trid_group;
		$trid_group->set_lang( $post_id, self::SOURCE_LANG );
		$trid_group->set_trid( $post_id, wp_generate_uuid4() );
		$trid_group->clear_translation_cache( $post_id );
		return $post_id;
	}

	/**
	 * True if a TranslationQueue job is scheduled for ($post_id, $lang, []).
	 *
	 * TranslationQueue::queue() prefers Action Scheduler over WP-Cron whenever
	 * as_enqueue_async_action() exists — which it does in this project's real
	 * wp-env (.wp-env.override.json installs WooCommerce, which ships Action
	 * Scheduler and is loaded by tests/bootstrap.php's muplugins_loaded
	 * callback). wp_next_scheduled() alone would silently see nothing in that
	 * environment regardless of whether queuing actually happened, so this
	 * checks Action Scheduler first — mirroring the exact same
	 * function_exists() branch TranslationQueue::queue() itself uses — and
	 * only falls back to wp_next_scheduled() when Action Scheduler isn't
	 * loaded at all (e.g. a bare wp-env without the WooCommerce override).
	 */
	private function is_queued( int $post_id, string $lang ): bool {
		$args = [ $post_id, $lang, [] ];

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( TranslationQueue::HOOK, $args, TranslationQueue::GROUP );
		}

		return (bool) wp_next_scheduled( TranslationQueue::HOOK, $args );
	}

	// =========================================================================
	// run() — scan and queue
	// =========================================================================

	public function test_run_queues_missing_translation_for_a_gap(): void {
		$post_id = $this->make_source_post_with_gap();

		TranslationBackfill::run();

		$this->assertTrue(
			$this->is_queued( $post_id, self::TRANS_LANG ),
			'run() must queue the missing (post, lang) pair via TranslationQueue.'
		);
	}

	public function test_run_does_not_queue_an_already_linked_translation(): void {
		$post_id  = $this->make_source_post_with_gap();
		$trans_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$trid_group = Router::get_instance()->trid_group;
		$trid       = $trid_group->get_trid( $post_id );
		$trid_group->set_trid( $trans_id, $trid );
		$trid_group->set_lang( $trans_id, self::TRANS_LANG );
		$trid_group->clear_translation_cache( $post_id );

		TranslationBackfill::run();

		$this->assertFalse(
			$this->is_queued( $post_id, self::TRANS_LANG ),
			'run() must not queue a (post, lang) pair that already has a linked translation.'
		);
	}

	public function test_run_never_queues_the_source_language_against_itself(): void {
		$post_id = $this->make_source_post_with_gap();

		TranslationBackfill::run();

		$this->assertFalse(
			$this->is_queued( $post_id, self::SOURCE_LANG ),
			'run() must only queue target languages, never the source language a post is already in.'
		);
	}

	// =========================================================================
	// Failure-cooldown bookkeeping
	// =========================================================================

	public function test_run_skips_a_pair_in_cooldown(): void {
		$post_id = $this->make_source_post_with_gap();

		for ( $i = 0; $i < 5; $i++ ) {
			TranslationBackfill::record_failure( $post_id, self::TRANS_LANG, 'stub failure' );
		}

		TranslationBackfill::run();

		$this->assertFalse(
			$this->is_queued( $post_id, self::TRANS_LANG ),
			'A pair that just hit MAX_ATTEMPTS must be left alone during its cooldown window.'
		);
	}

	public function test_run_retries_a_pair_once_its_cooldown_has_elapsed(): void {
		$post_id = $this->make_source_post_with_gap();

		for ( $i = 0; $i < 5; $i++ ) {
			TranslationBackfill::record_failure( $post_id, self::TRANS_LANG, 'stub failure' );
		}

		// Backdate the recorded last_attempt past the 24h cooldown window —
		// same shape record_failure() itself writes, just with an old timestamp.
		$failures                            = get_post_meta( $post_id, TranslationBackfill::FAILURE_META_KEY, true );
		$failures[ self::TRANS_LANG ]['last_attempt'] = time() - DAY_IN_SECONDS - 60;
		update_post_meta( $post_id, TranslationBackfill::FAILURE_META_KEY, $failures );

		TranslationBackfill::run();

		$this->assertTrue(
			$this->is_queued( $post_id, self::TRANS_LANG ),
			'Once the cooldown window has elapsed, run() must give the pair one more attempt.'
		);
	}

	public function test_record_failure_increments_attempts_and_stores_last_error(): void {
		$post_id = $this->make_source_post_with_gap();

		TranslationBackfill::record_failure( $post_id, self::TRANS_LANG, 'first error' );
		TranslationBackfill::record_failure( $post_id, self::TRANS_LANG, 'second error' );

		$failures = get_post_meta( $post_id, TranslationBackfill::FAILURE_META_KEY, true );

		$this->assertSame( 2, $failures[ self::TRANS_LANG ]['attempts'] );
		$this->assertSame( 'second error', $failures[ self::TRANS_LANG ]['last_error'] );
	}

	public function test_clear_failure_removes_only_that_languages_entry(): void {
		$post_id = $this->make_source_post_with_gap();

		TranslationBackfill::record_failure( $post_id, self::TRANS_LANG, 'boom' );
		TranslationBackfill::record_failure( $post_id, 'fr', 'also boom' );

		TranslationBackfill::clear_failure( $post_id, self::TRANS_LANG );

		$failures = get_post_meta( $post_id, TranslationBackfill::FAILURE_META_KEY, true );

		$this->assertArrayNotHasKey( self::TRANS_LANG, $failures, 'clear_failure() must remove the cleared language.' );
		$this->assertArrayHasKey( 'fr', $failures, 'clear_failure() must leave other languages\' failure state untouched.' );
	}

	public function test_clear_failure_deletes_the_meta_key_once_empty(): void {
		$post_id = $this->make_source_post_with_gap();

		TranslationBackfill::record_failure( $post_id, self::TRANS_LANG, 'boom' );
		TranslationBackfill::clear_failure( $post_id, self::TRANS_LANG );

		$this->assertSame(
			'',
			get_post_meta( $post_id, TranslationBackfill::FAILURE_META_KEY, true ),
			'The failure meta key itself must be deleted once its last language entry is cleared, not left as an empty array.'
		);
	}

	// =========================================================================
	// MAX_JOBS_PER_RUN cap
	// =========================================================================

	public function test_run_caps_jobs_queued_per_run_at_25(): void {
		$post_ids = [];
		for ( $i = 0; $i < 26; $i++ ) {
			$post_ids[] = $this->make_source_post_with_gap();
		}

		TranslationBackfill::run();

		$queued_count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( $this->is_queued( $post_id, self::TRANS_LANG ) ) {
				$queued_count++;
			}
		}

		$this->assertSame(
			25,
			$queued_count,
			'A single run() must never queue more than MAX_JOBS_PER_RUN (25) jobs, however large the backlog.'
		);
	}

	// =========================================================================
	// linguaforge_backfill_post_types filter
	// =========================================================================

	public function test_backfill_post_types_filter_restricts_the_scan(): void {
		$post_id = $this->make_source_post_with_gap( 'post' );
		$page_id = $this->make_source_post_with_gap( 'page' );

		add_filter( 'linguaforge_backfill_post_types', static fn (): array => [ 'page' ] );

		TranslationBackfill::run();

		$this->assertFalse(
			$this->is_queued( $post_id, self::TRANS_LANG ),
			'A post type filtered out via linguaforge_backfill_post_types must not be scanned.'
		);
		$this->assertTrue(
			$this->is_queued( $page_id, self::TRANS_LANG ),
			'A post type left in by the filter must still be scanned normally.'
		);
	}

	// =========================================================================
	// Schedule / unschedule
	// =========================================================================

	public function test_maybe_schedule_schedules_the_recurring_event(): void {
		// TranslationBackfill::register_hooks() hooks maybe_schedule() onto
		// 'init', which fires exactly once, globally, during the WP test
		// bootstrap — before any test's own DB transaction begins. That
		// baseline scheduled event therefore predates every test's
		// transaction and survives each test's rollback (a rollback only
		// undoes writes made *during* that transaction; it restores
		// pre-existing data rather than removing it), so asserting "nothing
		// scheduled yet" as an ambient precondition doesn't hold. Make the
		// precondition true within this test explicitly instead.
		TranslationBackfill::unschedule();
		$this->assertFalse( wp_next_scheduled( TranslationBackfill::CRON_HOOK ), 'Pre-condition: nothing scheduled yet.' );

		TranslationBackfill::maybe_schedule();

		$this->assertNotFalse( wp_next_scheduled( TranslationBackfill::CRON_HOOK ) );
	}

	public function test_maybe_schedule_does_not_double_schedule(): void {
		TranslationBackfill::maybe_schedule();
		$first = wp_next_scheduled( TranslationBackfill::CRON_HOOK );

		TranslationBackfill::maybe_schedule();
		$second = wp_next_scheduled( TranslationBackfill::CRON_HOOK );

		$this->assertSame( $first, $second, 'A second maybe_schedule() call must be a no-op when already scheduled.' );
	}

	public function test_unschedule_cancels_the_event(): void {
		TranslationBackfill::maybe_schedule();
		$this->assertNotFalse( wp_next_scheduled( TranslationBackfill::CRON_HOOK ), 'Pre-condition: event is scheduled.' );

		TranslationBackfill::unschedule();

		$this->assertFalse( wp_next_scheduled( TranslationBackfill::CRON_HOOK ) );
	}
}
