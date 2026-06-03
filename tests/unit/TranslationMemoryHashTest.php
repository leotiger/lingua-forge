<?php
/**
 * Unit tests for TranslationMemory::compute_hash().
 *
 * compute_hash() is a pure function: it takes five strings and returns a
 * sha256 hex digest.  No WordPress functions are involved, so no polyfills
 * are needed.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Core\TranslationMemory;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Core/TranslationMemory.php';

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Core\TranslationMemory::compute_hash
 */
class TranslationMemoryHashTest extends TestCase {

	// ── Shape ────────────────────────────────────────────────────────────────

	public function test_returns_64_char_hex_string(): void {

		$hash = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'ghash', 'csig' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	public function test_empty_inputs_produce_valid_hash(): void {

		$hash = TranslationMemory::compute_hash( '', '', '', '', '' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	// ── Determinism ──────────────────────────────────────────────────────────

	public function test_same_inputs_produce_same_hash(): void {

		$a = TranslationMemory::compute_hash( '<!-- wp:p -->Hello<!-- /wp:p -->', 'en', 'de', 'g1', 'c1' );
		$b = TranslationMemory::compute_hash( '<!-- wp:p -->Hello<!-- /wp:p -->', 'en', 'de', 'g1', 'c1' );

		$this->assertSame( $a, $b );
	}

	// ── Sensitivity — each field changes the hash ─────────────────────────────

	public function test_different_block_markup_produces_different_hash(): void {

		$base    = TranslationMemory::compute_hash( 'markup-A', 'en', 'de', 'g1', 'c1' );
		$changed = TranslationMemory::compute_hash( 'markup-B', 'en', 'de', 'g1', 'c1' );

		$this->assertNotSame( $base, $changed );
	}

	public function test_different_source_lang_produces_different_hash(): void {

		$base    = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'g1', 'c1' );
		$changed = TranslationMemory::compute_hash( 'markup', 'fr', 'de', 'g1', 'c1' );

		$this->assertNotSame( $base, $changed );
	}

	public function test_different_target_lang_produces_different_hash(): void {

		$base    = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'g1', 'c1' );
		$changed = TranslationMemory::compute_hash( 'markup', 'en', 'fr', 'g1', 'c1' );

		$this->assertNotSame( $base, $changed );
	}

	public function test_different_glossary_hash_produces_different_hash(): void {

		$base    = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'gloss-1', 'c1' );
		$changed = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'gloss-2', 'c1' );

		$this->assertNotSame( $base, $changed );
	}

	public function test_different_compliance_signature_produces_different_hash(): void {

		$base    = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'g1', 'compliance-A' );
		$changed = TranslationMemory::compute_hash( 'markup', 'en', 'de', 'g1', 'compliance-B' );

		$this->assertNotSame( $base, $changed );
	}
}
