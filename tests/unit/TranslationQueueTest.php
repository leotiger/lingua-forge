<?php
/**
 * Unit tests for LinguaForge\AI\Features\TranslationQueue::queue().
 *
 * Covers the backend-selection, argument-shaping, and dedup logic of the
 * deferred-translation queue:
 *
 *   • WP-Cron fallback (Action Scheduler absent — LF's no-dependency default):
 *       schedules the worker hook with [ id, lang, params ] args, defaults
 *       params to [], and debounces a duplicate already-scheduled event.
 *   • Action Scheduler path (when as_enqueue_async_action exists): enqueues an
 *       async action with the same args + the LF group, and skips when an
 *       identical job is already pending.
 *
 * The AS-path tests run in isolated processes (@runInSeparateProcess) so the
 * Action Scheduler stubs they define do not leak into the WP-Cron fallback
 * tests, which require those functions to be absent.
 *
 * run_queued() (the worker that drives the full TranslationTrigger pipeline) is
 * exercised by the integration suite — it needs a WordPress runtime and an AI
 * provider, neither available to the unit suite.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Features\TranslationQueue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/QueueSchedulerPolyfills.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/TranslationQueue.php';

/**
 * @covers \LinguaForge\AI\Features\TranslationQueue::queue
 */
final class TranslationQueueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_q_scheduled']   = array();
		$GLOBALS['lf_q_next_calls']  = array();
		$GLOBALS['lf_q_next_return'] = false;
		$GLOBALS['lf_q_as_enqueued'] = array();
		$GLOBALS['lf_q_as_has_calls'] = array();
		$GLOBALS['lf_q_as_has']      = false;
	}

	/** Fail loudly if some other test in the process defined Action Scheduler. */
	private function require_no_action_scheduler(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is defined in this process; WP-Cron fallback is unreachable here.' );
		}
	}

	// =========================================================================
	// WP-Cron fallback (Action Scheduler absent)
	// =========================================================================

	public function test_wp_cron_fallback_schedules_worker_with_hook_and_args(): void {
		$this->require_no_action_scheduler();

		$before = time();
		TranslationQueue::queue( 42, 'es', array( 'force_refresh' => true ) );

		$this->assertCount( 1, $GLOBALS['lf_q_scheduled'] );

		$event = $GLOBALS['lf_q_scheduled'][0];
		$this->assertSame( TranslationQueue::HOOK, $event['hook'] );
		$this->assertSame( array( 42, 'es', array( 'force_refresh' => true ) ), $event['args'] );
		$this->assertIsInt( $event['ts'] );
		$this->assertGreaterThanOrEqual( $before, $event['ts'] );
	}

	public function test_wp_cron_fallback_defaults_params_to_empty_array(): void {
		$this->require_no_action_scheduler();

		TranslationQueue::queue( 7, 'ca' );

		$this->assertCount( 1, $GLOBALS['lf_q_scheduled'] );
		$this->assertSame( array( 7, 'ca', array() ), $GLOBALS['lf_q_scheduled'][0]['args'] );
	}

	public function test_wp_cron_fallback_debounces_duplicate_event(): void {
		$this->require_no_action_scheduler();

		// Simulate an identical event already in the cron queue.
		$GLOBALS['lf_q_next_return'] = 1_900_000_000;

		TranslationQueue::queue( 42, 'es' );

		$this->assertCount( 0, $GLOBALS['lf_q_scheduled'], 'No second event should be scheduled.' );
		$this->assertCount( 1, $GLOBALS['lf_q_next_calls'], 'queue() should check wp_next_scheduled once.' );
		$this->assertSame(
			array( TranslationQueue::HOOK, array( 42, 'es', array() ) ),
			$GLOBALS['lf_q_next_calls'][0]
		);
	}

	// =========================================================================
	// Action Scheduler path (isolated processes)
	// =========================================================================

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uses_action_scheduler_when_available(): void {
		lf_define_action_scheduler_stubs();

		TranslationQueue::queue( 42, 'es', array( 'with_meta_description' => true ) );

		$this->assertCount( 1, $GLOBALS['lf_q_as_enqueued'] );
		$this->assertSame(
			array(
				TranslationQueue::HOOK,
				array( 42, 'es', array( 'with_meta_description' => true ) ),
				TranslationQueue::GROUP,
			),
			$GLOBALS['lf_q_as_enqueued'][0]
		);
		// Must not also fall through to WP-Cron.
		$this->assertCount( 0, $GLOBALS['lf_q_scheduled'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_skips_action_scheduler_when_already_pending(): void {
		lf_define_action_scheduler_stubs();
		$GLOBALS['lf_q_as_has'] = true; // identical job already pending

		TranslationQueue::queue( 42, 'es' );

		$this->assertCount( 0, $GLOBALS['lf_q_as_enqueued'], 'Duplicate AS job must be skipped.' );
		$this->assertCount( 1, $GLOBALS['lf_q_as_has_calls'] );
		$this->assertSame(
			array( TranslationQueue::HOOK, array( 42, 'es', array() ), TranslationQueue::GROUP ),
			$GLOBALS['lf_q_as_has_calls'][0]
		);
	}
}
