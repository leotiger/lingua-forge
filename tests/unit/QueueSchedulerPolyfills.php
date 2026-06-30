<?php
/**
 * Scheduler polyfills for TranslationQueueTest.
 *
 * Global-namespace recording stubs for the WP-Cron scheduling functions
 * TranslationQueue::queue() calls on the no-dependency fallback path. Loaded via
 * require_once from the test file (which is namespaced, so these must live here,
 * in the global namespace, to satisfy TranslationQueue's unqualified calls).
 *
 * The Action Scheduler functions are deliberately NOT defined at load time —
 * their mere existence would route queue() down the AS branch and make the
 * WP-Cron fallback untestable in the same process. They are defined on demand by
 * lf_define_action_scheduler_stubs(), which the AS-path tests call inside an
 * isolated process (@runInSeparateProcess) so the definition never leaks back to
 * the main process running the fallback tests.
 *
 * @package LinguaForge\Tests\Unit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- these stub WordPress / Action Scheduler core functions; their names are fixed by those APIs.

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Records each query to $GLOBALS['lf_q_next_calls']; returns the value in
	 * $GLOBALS['lf_q_next_return'] (default false) so tests can simulate an
	 * already-scheduled event.
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		$GLOBALS['lf_q_next_calls'][] = array( $hook, $args );
		return $GLOBALS['lf_q_next_return'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Records each scheduled event to $GLOBALS['lf_q_scheduled'] as
	 * [ 'ts' => int, 'hook' => string, 'args' => array ]. Always returns true.
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature; $wp_error unused in stub.
		$GLOBALS['lf_q_scheduled'][] = array(
			'ts'   => $timestamp,
			'hook' => $hook,
			'args' => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'lf_define_action_scheduler_stubs' ) ) {
	/**
	 * Define recording stubs for the Action Scheduler functions queue() prefers.
	 * Call this only inside an isolated test process.
	 *
	 *  - as_has_scheduled_action() returns $GLOBALS['lf_q_as_has'] (default false).
	 *  - as_enqueue_async_action() records to $GLOBALS['lf_q_as_enqueued'].
	 */
	function lf_define_action_scheduler_stubs(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			function as_has_scheduled_action( $hook, $args = array(), $group = '' ) {
				$GLOBALS['lf_q_as_has_calls'][] = array( $hook, $args, $group );
				return $GLOBALS['lf_q_as_has'] ?? false;
			}
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
				$GLOBALS['lf_q_as_enqueued'][] = array( $hook, $args, $group );
				return 1;
			}
		}
	}
}
