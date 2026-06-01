<?php
/**
 * Unit tests for CacheStore::hash().
 *
 * hash() is the only pure-PHP method on CacheStore — every other method
 * requires $wpdb.  It computes a SHA-256 over the NUL-joined stringified
 * inputs, making it the content-addressable key for every cache entry.
 *
 * Covers:
 *   • Single-element input produces a 64-char hex string.
 *   • Multi-element inputs are order-sensitive (different order → different hash).
 *   • Integer inputs are cast to string before hashing.
 *   • Empty array produces a deterministic (non-empty) hash.
 *   • Identical calls are idempotent.
 *   • Adding an element changes the hash.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\CacheStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/CacheStore.php';

final class CacheStoreHashTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Provide the DB-version option so ensure_table() short-circuits
		// without touching $wpdb on any accidental call.
		$GLOBALS['lf_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['lf_test_options'] = [];
		parent::tearDown();
	}

	// =========================================================================
	// Output format
	// =========================================================================

	public function test_hash_returns_64_char_hex_string(): void {
		$h = CacheStore::hash( [ 'hello' ] );

		$this->assertSame( 64, strlen( $h ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $h );
	}

	// =========================================================================
	// Determinism
	// =========================================================================

	public function test_hash_is_idempotent(): void {
		$inputs = [ 'post content', 'en_US' ];

		$this->assertSame( CacheStore::hash( $inputs ), CacheStore::hash( $inputs ) );
	}

	public function test_empty_array_produces_deterministic_hash(): void {
		$h1 = CacheStore::hash( [] );
		$h2 = CacheStore::hash( [] );

		$this->assertSame( $h1, $h2 );
		$this->assertSame( 64, strlen( $h1 ) );
	}

	// =========================================================================
	// Sensitivity
	// =========================================================================

	public function test_different_inputs_produce_different_hashes(): void {
		$this->assertNotSame(
			CacheStore::hash( [ 'content A', 'en' ] ),
			CacheStore::hash( [ 'content B', 'en' ] )
		);
	}

	public function test_input_order_affects_hash(): void {
		$this->assertNotSame(
			CacheStore::hash( [ 'first', 'second' ] ),
			CacheStore::hash( [ 'second', 'first' ] )
		);
	}

	public function test_adding_element_changes_hash(): void {
		$this->assertNotSame(
			CacheStore::hash( [ 'a' ] ),
			CacheStore::hash( [ 'a', 'b' ] )
		);
	}

	// =========================================================================
	// Type coercion
	// =========================================================================

	public function test_integer_input_is_cast_to_string(): void {
		// hash(['42']) and hash([42]) must be equal — strval() is applied to all inputs.
		$this->assertSame(
			CacheStore::hash( [ '42' ] ),
			CacheStore::hash( [ 42 ] )
		);
	}

	// =========================================================================
	// Known value
	// =========================================================================

	public function test_known_hash_value(): void {
		// sha256("hello\x00world") — verified externally.
		$expected = hash( 'sha256', "hello\x00world" );

		$this->assertSame( $expected, CacheStore::hash( [ 'hello', 'world' ] ) );
	}
}
