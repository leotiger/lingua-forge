<?php
/**
 * Unit tests for LinguaForge\AI\Core\KeyStore's encryption envelopes.
 *
 * Exercises the private encrypt/decrypt internals via ReflectionMethod
 * because exposing them publicly would widen the security-sensitive
 * surface for the production code's sake of testability. The public
 * API (get/set/delete/source) goes through `get_option`/`update_option`
 * and therefore belongs in the integration suite — covered later.
 *
 * What this file proves:
 *   • v2 envelope round-trips cleanly.
 *   • v2 output carries the "v2|" prefix.
 *   • Provider slug acts as AAD — decrypting with the wrong slug fails.
 *   • Tampered ciphertext fails the tag check.
 *   • Legacy v1 (AES-CBC) envelopes still decrypt cleanly — backward
 *     compatibility for any value sitting in production DBs from
 *     before the §3.5 upgrade.
 *   • The dispatcher routes by prefix.
 *
 * The test forces a deterministic encryption secret by defining
 * LINGUAFORGE_SECRET before loading KeyStore — that side-steps the
 * `wp_salt('auth')` fallback so the unit suite stays WP-free.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_SECRET' ) ) {
    // Fixed test secret — ensures secret() returns a deterministic
    // 32-byte key without touching wp_salt().
    define( 'LINGUAFORGE_SECRET', 'unit-test-secret-do-not-use-in-production' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/KeyStore.php';

use LinguaForge\AI\Core\KeyStore;

final class KeyStoreEnvelopeTest extends TestCase {

    /** @var array<string, ReflectionMethod> */
    private static array $methods;

    public static function setUpBeforeClass(): void {

        $rc = new ReflectionClass( KeyStore::class );

        foreach ( [ 'encrypt_v2', 'decrypt', 'decrypt_v2', 'decrypt_v1', 'is_v2', 'secret' ] as $name ) {
            $m = $rc->getMethod( $name );
            $m->setAccessible( true );
            self::$methods[ $name ] = $m;
        }
    }

    /** Invoke a private static. */
    private static function call( string $name, array $args = [] ): mixed {
        return self::$methods[ $name ]->invoke( null, ...$args );
    }

    // ── v2 envelope ──────────────────────────────────────────────────────────

    public function test_v2_round_trip_recovers_plaintext(): void {

        $plain = 'sk-ant-test-' . str_repeat( 'x', 96 );

        $envelope = self::call( 'encrypt_v2', [ $plain, 'anthropic' ] );

        $this->assertNotSame( '', $envelope, 'encrypt_v2 should produce a non-empty envelope.' );
        $this->assertStringStartsWith( 'v2|', $envelope );

        $recovered = self::call( 'decrypt_v2', [ $envelope, 'anthropic' ] );

        $this->assertSame( $plain, $recovered );
    }

    public function test_v2_envelope_has_expected_byte_layout(): void {

        $envelope = self::call( 'encrypt_v2', [ 'short-key', 'openai' ] );

        $raw = base64_decode( substr( $envelope, 3 ), true );

        $this->assertIsString( $raw );
        // 12-byte IV + ≥1 byte ciphertext + 16-byte tag — at least 29 bytes.
        $this->assertGreaterThanOrEqual(
            12 + 1 + 16,
            strlen( $raw ),
            'v2 envelope must contain IV(12) + ciphertext(≥1) + tag(16).'
        );
    }

    public function test_v2_decrypt_with_wrong_provider_returns_null(): void {

        $plain    = 'cross-provider-swap-attempt';
        $envelope = self::call( 'encrypt_v2', [ $plain, 'anthropic' ] );

        // GCM tag was computed with "anthropic" as AAD — verifying with
        // "openai" must fail. This is the property that prevents an
        // attacker with DB write access from swapping ciphertexts
        // between provider option rows.
        $result = self::call( 'decrypt_v2', [ $envelope, 'openai' ] );

        $this->assertNull(
            $result,
            'Decrypting a v2 envelope with the wrong provider slug must fail (AAD mismatch).'
        );
    }

    public function test_v2_tampered_ciphertext_fails_authentication(): void {

        $envelope = self::call( 'encrypt_v2', [ 'plaintext', 'gemini' ] );
        $raw      = base64_decode( substr( $envelope, 3 ), true );

        // Flip a byte somewhere inside the ciphertext region (not the
        // IV header, not the tag trailer).
        $iv_len  = 12;
        $tag_len = 16;
        $middle  = $iv_len + 1;
        $raw[ $middle ] = chr( ord( $raw[ $middle ] ) ^ 0x01 );

        $tampered = 'v2|' . base64_encode( $raw );

        $this->assertNull(
            self::call( 'decrypt_v2', [ $tampered, 'gemini' ] ),
            'A single-bit tamper in the ciphertext must fail GCM authentication.'
        );
    }

    public function test_v2_short_envelope_is_rejected(): void {

        // A v2-prefixed value that is too short to contain IV+tag.
        $bogus = 'v2|' . base64_encode( str_repeat( "\x00", 8 ) );

        $this->assertNull( self::call( 'decrypt_v2', [ $bogus, 'anthropic' ] ) );
    }

    public function test_is_v2_detects_prefix(): void {

        $this->assertTrue(  self::call( 'is_v2', [ 'v2|whatever' ] ) );
        $this->assertFalse( self::call( 'is_v2', [ 'whatever'    ] ) );
        $this->assertFalse( self::call( 'is_v2', [ ''            ] ) );
    }

    // ── v1 legacy envelope ──────────────────────────────────────────────────

    public function test_v1_legacy_envelope_decrypts_cleanly(): void {

        // Construct a v1 envelope the same way the pre-§3.5 code did:
        //   base64( IV(16) || aes-256-cbc(plaintext, secret, IV) )
        $plain  = 'legacy-key-from-pre-3.5';
        $secret = self::call( 'secret' );
        $iv     = random_bytes( 16 );
        $ct     = openssl_encrypt( $plain, 'aes-256-cbc', $secret, OPENSSL_RAW_DATA, $iv );

        $this->assertNotFalse( $ct, 'openssl_encrypt must succeed for the test vector.' );

        $legacy = base64_encode( $iv . $ct );

        $recovered = self::call( 'decrypt_v1', [ $legacy ] );

        $this->assertSame( $plain, $recovered );
    }

    public function test_v1_malformed_base64_returns_null(): void {

        $this->assertNull( self::call( 'decrypt_v1', [ '!!!not-base64!!!' ] ) );
    }

    public function test_v1_truncated_payload_returns_null(): void {

        // Shorter than the 16-byte CBC IV — must not crash, must return null.
        $tiny = base64_encode( 'short' );

        $this->assertNull( self::call( 'decrypt_v1', [ $tiny ] ) );
    }

    // ── dispatcher ──────────────────────────────────────────────────────────

    public function test_dispatcher_routes_v2_via_v2_path(): void {

        $plain    = 'dispatched-correctly';
        $envelope = self::call( 'encrypt_v2', [ $plain, 'anthropic' ] );

        $this->assertSame(
            $plain,
            self::call( 'decrypt', [ $envelope, 'anthropic' ] )
        );
    }

    public function test_dispatcher_routes_legacy_via_v1_path(): void {

        // Build a v1 envelope (same construction as the legacy test).
        $plain  = 'dispatched-to-v1';
        $secret = self::call( 'secret' );
        $iv     = random_bytes( 16 );
        $ct     = openssl_encrypt( $plain, 'aes-256-cbc', $secret, OPENSSL_RAW_DATA, $iv );
        $legacy = base64_encode( $iv . $ct );

        // Provider slug is ignored on the v1 path — pass anything.
        $this->assertSame(
            $plain,
            self::call( 'decrypt', [ $legacy, 'whatever-slug-is-ignored' ] )
        );
    }
}
