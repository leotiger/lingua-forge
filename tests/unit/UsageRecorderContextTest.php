<?php
/**
 * Unit tests for LinguaForge\AI\Core\UsageRecorder — context stack.
 *
 * Covers the pure context-management layer that sits entirely above the DB:
 *
 *   • push_context() / pop_context() / current_context() stack mechanics
 *   • Nested pushes return the innermost key (stack discipline)
 *   • tracked() sets context during callback and restores it after
 *   • tracked() pops context even when the callback throws (try/finally)
 *   • record() with no active context is a complete no-op (no DB touch)
 *
 * These methods are load-bearing for the entire recording pipeline — every
 * feature wraps its chat() call in tracked() — but were never explicitly tested.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use LinguaForge\AI\Core\UsageRecorder;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once __DIR__ . '/ApiPolyfills.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/UsageRecorder.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Core\UsageRecorder::tracked
 * @covers \LinguaForge\AI\Core\UsageRecorder::push_context
 * @covers \LinguaForge\AI\Core\UsageRecorder::pop_context
 * @covers \LinguaForge\AI\Core\UsageRecorder::current_context
 * @covers \LinguaForge\AI\Core\UsageRecorder::record
 */
final class UsageRecorderContextTest extends TestCase {

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Reset the static context stack between tests. */
	protected function setUp(): void {
		$prop = new ReflectionProperty( UsageRecorder::class, 'context_stack' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	// ── current_context() ─────────────────────────────────────────────────────

	public function test_current_context_returns_null_on_empty_stack(): void {
		$this->assertNull( UsageRecorder::current_context() );
	}

	// ── push / pop / current ──────────────────────────────────────────────────

	public function test_push_makes_key_current(): void {
		UsageRecorder::push_context( 'translation' );
		$this->assertSame( 'translation', UsageRecorder::current_context() );
	}

	public function test_nested_push_returns_innermost_key(): void {
		UsageRecorder::push_context( 'translation' );
		UsageRecorder::push_context( 'meta-description' );
		$this->assertSame( 'meta-description', UsageRecorder::current_context() );
	}

	public function test_pop_restores_previous_context(): void {
		UsageRecorder::push_context( 'translation' );
		UsageRecorder::push_context( 'meta-description' );
		UsageRecorder::pop_context();
		$this->assertSame( 'translation', UsageRecorder::current_context() );
	}

	public function test_pop_to_empty_stack_returns_null(): void {
		UsageRecorder::push_context( 'translation' );
		UsageRecorder::pop_context();
		$this->assertNull( UsageRecorder::current_context() );
	}

	public function test_pop_on_already_empty_stack_does_not_fatal(): void {
		UsageRecorder::pop_context(); // no push — must not throw
		$this->assertNull( UsageRecorder::current_context() );
	}

	// ── tracked() ─────────────────────────────────────────────────────────────

	public function test_tracked_sets_context_during_callback(): void {
		$observed = null;
		UsageRecorder::tracked( 'translation', function () use ( &$observed ): void {
			$observed = UsageRecorder::current_context();
		} );
		$this->assertSame( 'translation', $observed );
	}

	public function test_tracked_restores_null_context_after_callback(): void {
		UsageRecorder::tracked( 'translation', fn() => null );
		$this->assertNull( UsageRecorder::current_context() );
	}

	public function test_tracked_restores_outer_context_after_nested_call(): void {
		UsageRecorder::push_context( 'outer' );
		UsageRecorder::tracked( 'inner', fn() => null );
		$this->assertSame( 'outer', UsageRecorder::current_context() );
	}

	public function test_tracked_pops_context_even_when_callback_throws(): void {
		try {
			UsageRecorder::tracked( 'translation', function (): void {
				throw new \RuntimeException( 'test exception' );
			} );
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- exception intentionally swallowed; we only care that context was popped.
			unset( $e );
		}
		$this->assertNull( UsageRecorder::current_context() );
	}

	public function test_tracked_propagates_callback_exception(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'test exception' );
		UsageRecorder::tracked( 'translation', function (): void {
			throw new \RuntimeException( 'test exception' );
		} );
	}

	public function test_tracked_returns_callback_return_value(): void {
		$result = UsageRecorder::tracked( 'translation', fn() => 'hello' );
		$this->assertSame( 'hello', $result );
	}

	// ── record() no-op guard ──────────────────────────────────────────────────

	public function test_record_with_no_context_is_a_noop(): void {
		// No context pushed — record() must return before touching the DB.
		// If it reaches ensure_table() / $wpdb->query(), LfWpdb::query()
		// is undefined and the test would fatal with an error.
		UsageRecorder::record( 'Anthropic', 'claude-sonnet-4-6', 100, 50 );
		$this->assertNull( UsageRecorder::current_context() ); // stack still clean
	}
}
