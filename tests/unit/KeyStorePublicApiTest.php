<?php
/**
 * Unit tests for LinguaForge\AI\Core\KeyStore — public API.
 *
 * Covers the four public methods (get / set / delete / source) that
 * KeyStoreEnvelopeTest deliberately skips because they need WP functions.
 * Those functions are supplied by the ApiPolyfills.php option-store stubs.
 *
 * LINGUAFORGE_SECRET is defined by KeyStoreEnvelopeTest which loads first
 * (alphabetically), so encryption is deterministic without wp_salt().
 *
 * Each test begins with a clean option store ($GLOBALS['lf_test_options'])
 * and a clean $_ENV snapshot so tests don't bleed into one another.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\KeyStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

// LINGUAFORGE_SECRET must be defined before KeyStore.php is first loaded
// so that KeyStore::secret() never falls through to wp_salt().  The constant
// is defined by KeyStoreEnvelopeTest (same directory, loads first), but guard
// here too so this file is self-contained when run alone.
if ( ! defined( 'LINGUAFORGE_SECRET' ) ) {
	define( 'LINGUAFORGE_SECRET', 'unit-test-secret-do-not-use-in-production' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/KeyStore.php';

final class KeyStorePublicApiTest extends TestCase {

	/** Saved $_ENV entries that tests pollute — restored in tearDown. */
	private array $env_snapshot = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_options'] = [];
		$this->env_snapshot         = $_ENV;
	}

	protected function tearDown(): void {
		$_ENV                       = $this->env_snapshot;
		$GLOBALS['lf_test_options'] = [];
		parent::tearDown();
	}

	// =========================================================================
	// get() — no key configured
	// =========================================================================

	public function test_get_returns_null_when_no_key_configured(): void {
		$this->assertNull( KeyStore::get( 'anthropic' ) );
	}

	public function test_get_returns_null_for_unknown_provider(): void {
		$this->assertNull( KeyStore::get( 'unknown-provider-xyz' ) );
	}

	// =========================================================================
	// get() — environment variable fallback
	// =========================================================================

	public function test_get_returns_env_var_when_no_db_key(): void {
		$_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-env-test';

		$this->assertSame( 'sk-ant-env-test', KeyStore::get( 'anthropic' ) );
	}

	public function test_get_returns_env_var_for_openai(): void {
		$_ENV['OPENAI_API_KEY'] = 'sk-openai-env-test';

		$this->assertSame( 'sk-openai-env-test', KeyStore::get( 'openai' ) );
	}

	public function test_get_returns_env_var_for_gemini(): void {
		$_ENV['GEMINI_API_KEY'] = 'gemini-env-test';

		$this->assertSame( 'gemini-env-test', KeyStore::get( 'gemini' ) );
	}

	// =========================================================================
	// get() — database (set + get round-trip)
	// =========================================================================

	public function test_get_returns_key_stored_via_set(): void {
		$key = 'sk-ant-stored-' . str_repeat( 'a', 32 );

		KeyStore::set( 'anthropic', $key );

		$this->assertSame( $key, KeyStore::get( 'anthropic' ) );
	}

	public function test_get_database_key_takes_priority_over_env_var(): void {
		$db_key              = 'sk-ant-db-key-' . str_repeat( 'b', 32 );
		$_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-env-should-not-be-returned';

		KeyStore::set( 'anthropic', $db_key );

		$this->assertSame( $db_key, KeyStore::get( 'anthropic' ) );
	}

	// =========================================================================
	// set()
	// =========================================================================

	public function test_set_returns_true_on_success(): void {
		$this->assertTrue( KeyStore::set( 'anthropic', 'sk-ant-test-key' ) );
	}

	public function test_set_stores_encrypted_value_not_plaintext(): void {
		KeyStore::set( 'anthropic', 'plaintext-key' );

		$stored = $GLOBALS['lf_test_options']['linguaforge_key_anthropic'] ?? '';
		$this->assertStringStartsWith( 'v2|', $stored, 'Stored value must be a v2 envelope.' );
		$this->assertStringNotContainsString( 'plaintext-key', $stored );
	}

	// =========================================================================
	// delete()
	// =========================================================================

	public function test_delete_removes_stored_key(): void {
		KeyStore::set( 'anthropic', 'sk-ant-to-delete' );
		$this->assertSame( 'sk-ant-to-delete', KeyStore::get( 'anthropic' ) );

		KeyStore::delete( 'anthropic' );

		$this->assertNull( KeyStore::get( 'anthropic' ) );
	}

	public function test_delete_returns_true(): void {
		$this->assertTrue( KeyStore::delete( 'anthropic' ) );
	}

	// =========================================================================
	// source()
	// =========================================================================

	public function test_source_returns_null_when_nothing_configured(): void {
		$this->assertNull( KeyStore::source( 'anthropic' ) );
	}

	public function test_source_returns_environment_when_env_var_set(): void {
		$_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-env';

		$this->assertSame( 'environment', KeyStore::source( 'anthropic' ) );
	}

	public function test_source_returns_database_when_key_stored(): void {
		KeyStore::set( 'anthropic', 'sk-ant-db' );

		$this->assertSame( 'database', KeyStore::source( 'anthropic' ) );
	}

	public function test_source_database_takes_priority_over_environment(): void {
		$_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-env';
		KeyStore::set( 'anthropic', 'sk-ant-db' );

		$this->assertSame( 'database', KeyStore::source( 'anthropic' ) );
	}

	public function test_source_returns_null_for_unknown_provider(): void {
		$this->assertNull( KeyStore::source( 'no-such-provider' ) );
	}
}
