<?php
/**
 * Unit tests for LinguaForge\AI\REST\RateLimiter.
 *
 * Both public gates are tested:
 *
 *   enforce_rate_limit()
 *     • Anonymous user (user_id = 0)  → WP_Error 'rate_limited'.
 *     • Under-limit authenticated user → returns null + records the event.
 *     • At-limit                       → returns WP_Error with retry_after > 0.
 *     • Stale events outside the window are pruned (do not count toward limit).
 *     • Policy override via linguaforge_ai_rate_limit filter is respected.
 *
 *   enforce_daily_quota()
 *     • Quota = 0 (unlimited)     → always returns null.
 *     • Under quota               → returns null + increments transient counter.
 *     • At quota                  → returns WP_Error 'daily_quota_exceeded'.
 *     • Quota via filter override → respected.
 *
 * Uses the WP_Error stub, transient polyfills, apply_filters polyfill, and
 * get_current_user_id polyfill from ApiPolyfills.php.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\REST\RateLimiter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/REST/RateLimiter.php';

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_transients'] = [];
		$GLOBALS['lf_test_options']    = [];
		$GLOBALS['lf_test_filters']    = [];
		$GLOBALS['lf_test_user_id']    = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['lf_test_transients'] = [];
		$GLOBALS['lf_test_options']    = [];
		$GLOBALS['lf_test_filters']    = [];
		unset( $GLOBALS['lf_test_user_id'] );
		parent::tearDown();
	}

	// =========================================================================
	// enforce_rate_limit() — anonymous user
	// =========================================================================

	public function test_rate_limit_returns_error_for_anonymous_user(): void {
		$GLOBALS['lf_test_user_id'] = 0;

		$result = RateLimiter::enforce_rate_limit( 'translate' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}

	// =========================================================================
	// enforce_rate_limit() — under limit
	// =========================================================================

	public function test_rate_limit_returns_null_when_under_limit(): void {
		// Empty transient → 0 events in window → allowed.
		$result = RateLimiter::enforce_rate_limit( 'translate' );

		$this->assertNull( $result );
	}

	public function test_rate_limit_records_event_in_transient(): void {
		RateLimiter::enforce_rate_limit( 'translate' );

		$key    = 'linguaforge_rate_user_1_translate';
		$events = $GLOBALS['lf_test_transients'][ $key ] ?? [];

		$this->assertIsArray( $events );
		$this->assertCount( 1, $events );
		$this->assertIsInt( $events[0] );
	}

	public function test_rate_limit_accumulates_multiple_events(): void {
		RateLimiter::enforce_rate_limit( 'translate' );
		RateLimiter::enforce_rate_limit( 'translate' );
		RateLimiter::enforce_rate_limit( 'translate' );

		$key    = 'linguaforge_rate_user_1_translate';
		$events = $GLOBALS['lf_test_transients'][ $key ] ?? [];

		$this->assertCount( 3, $events );
	}

	// =========================================================================
	// enforce_rate_limit() — at limit
	// =========================================================================

	public function test_rate_limit_returns_error_when_limit_reached(): void {
		// Override policy to limit=2 for a tighter test.
		$GLOBALS['lf_test_filters']['linguaforge_ai_rate_limit'] = static function (): array {
			return [ 'window_seconds' => 60, 'max_requests' => 2 ];
		};

		// Fill the transient with 2 recent events (now - 5s to stay in window).
		$now = time();
		$GLOBALS['lf_test_transients']['linguaforge_rate_user_1_translate'] = [ $now - 5, $now - 3 ];

		$result = RateLimiter::enforce_rate_limit( 'translate' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThan( 0, $data['retry_after'] );
	}

	// =========================================================================
	// enforce_rate_limit() — stale events pruned
	// =========================================================================

	public function test_rate_limit_prunes_stale_events_outside_window(): void {
		$GLOBALS['lf_test_filters']['linguaforge_ai_rate_limit'] = static function (): array {
			return [ 'window_seconds' => 60, 'max_requests' => 2 ];
		};

		// Put 2 events from 2 minutes ago — outside the 60 s window.
		$stale = time() - 120;
		$GLOBALS['lf_test_transients']['linguaforge_rate_user_1_translate'] = [ $stale, $stale + 1 ];

		// Should be allowed because both events are pruned.
		$result = RateLimiter::enforce_rate_limit( 'translate' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// enforce_rate_limit() — endpoint isolation
	// =========================================================================

	public function test_rate_limit_is_per_endpoint(): void {
		$GLOBALS['lf_test_filters']['linguaforge_ai_rate_limit'] = static function (): array {
			return [ 'window_seconds' => 60, 'max_requests' => 1 ];
		};

		$now = time();
		// Fill the 'translate' endpoint.
		$GLOBALS['lf_test_transients']['linguaforge_rate_user_1_translate'] = [ $now ];

		// A different endpoint must be unaffected.
		$result = RateLimiter::enforce_rate_limit( 'meta-description' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// enforce_daily_quota() — unlimited (quota = 0)
	// =========================================================================

	public function test_daily_quota_returns_null_when_quota_is_zero(): void {
		// Default option is 0 = unlimited.
		$result = RateLimiter::enforce_daily_quota( 'translate' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// enforce_daily_quota() — under quota
	// =========================================================================

	public function test_daily_quota_returns_null_when_under_quota(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 100;

		// No transient yet → used = 0.
		$result = RateLimiter::enforce_daily_quota( 'translate' );

		$this->assertNull( $result );
	}

	public function test_daily_quota_increments_counter(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 100;

		RateLimiter::enforce_daily_quota( 'translate' );

		$today = gmdate( 'Ymd' );
		$key   = "linguaforge_quota_daily_used_{$today}";
		$used  = $GLOBALS['lf_test_transients'][ $key ] ?? null;

		$this->assertSame( 1, $used );
	}

	public function test_daily_quota_accumulates_across_calls(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 100;

		RateLimiter::enforce_daily_quota( 'translate' );
		RateLimiter::enforce_daily_quota( 'translate' );
		RateLimiter::enforce_daily_quota( 'translate' );

		$today = gmdate( 'Ymd' );
		$key   = "linguaforge_quota_daily_used_{$today}";

		$this->assertSame( 3, $GLOBALS['lf_test_transients'][ $key ] );
	}

	// =========================================================================
	// enforce_daily_quota() — at quota
	// =========================================================================

	public function test_daily_quota_returns_error_when_quota_reached(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 50;

		$today = gmdate( 'Ymd' );
		$key   = "linguaforge_quota_daily_used_{$today}";
		$GLOBALS['lf_test_transients'][ $key ] = 50; // already at limit.

		$result = RateLimiter::enforce_daily_quota( 'translate' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'daily_quota_exceeded', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThan( 0, $data['retry_after'] );
		$this->assertSame( 50, $data['quota'] );
	}

	public function test_daily_quota_does_not_increment_when_limit_hit(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 10;

		$today = gmdate( 'Ymd' );
		$key   = "linguaforge_quota_daily_used_{$today}";
		$GLOBALS['lf_test_transients'][ $key ] = 10;

		RateLimiter::enforce_daily_quota( 'translate' );

		// Counter must not have been bumped beyond 10.
		$this->assertSame( 10, $GLOBALS['lf_test_transients'][ $key ] );
	}

	// =========================================================================
	// enforce_daily_quota() — filter override
	// =========================================================================

	public function test_daily_quota_filter_can_override_option(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 0; // unlimited by option.

		// Filter enforces a tighter limit.
		$GLOBALS['lf_test_filters']['linguaforge_ai_daily_quota'] = static function (): int {
			return 5;
		};

		$today = gmdate( 'Ymd' );
		$key   = "linguaforge_quota_daily_used_{$today}";
		$GLOBALS['lf_test_transients'][ $key ] = 5;

		$result = RateLimiter::enforce_daily_quota( 'translate' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'daily_quota_exceeded', $result->get_error_code() );
	}
}
