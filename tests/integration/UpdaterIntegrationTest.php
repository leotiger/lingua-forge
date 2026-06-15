<?php
/**
 * Integration tests for the self-hosted updater (Linguaforge_Updater),
 * focused on the 2.3.0 supply-chain hardening: download host-pinning and
 * SHA-256 package verification (AUDIT-2026-06-15 §3 item 1).
 *
 * Covered here:
 *   is_allowed_download_host() — exact + subdomain matches accepted; spoofed
 *                                and unrelated hosts rejected
 *   verify_and_download()      — passthrough when $pre already set; false for a
 *                                non-LF package; WP_Error for a blocked host;
 *                                SHA-256 mismatch → WP_Error (+ temp file gone);
 *                                empty SHA-256 → verification skipped; matching
 *                                SHA-256 → temp path returned; download WP_Error
 *                                propagated
 *   check_for_update()         — newer manifest → response entry injected;
 *                                not-newer → no_update entry; empty checked → bail
 *   build_update_object() /
 *   build_no_update_object()   — field mapping + defaults; no_update has empty package
 *
 * Strategy:
 *   • The manifest is primed directly into its transient cache
 *     (`linguaforge_update_manifest`) so fetch_manifest() never makes a network
 *     call and every branch can be driven deterministically.
 *   • Package downloads are intercepted with the `pre_http_request` filter. When
 *     that filter short-circuits, WordPress does not stream a body to the temp
 *     file, so the downloaded file is empty — the SHA-256 "match" test therefore
 *     expects the hash of an empty string (documented inline).
 *   • Private static methods are exercised via Reflection.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use ReflectionMethod;
use WP_UnitTestCase;

final class UpdaterIntegrationTest extends WP_UnitTestCase {

	private const CACHE_KEY = 'linguaforge_update_manifest';

	// =========================================================================
	// Lifecycle
	// =========================================================================

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// download_url() / wp_tempnam() and WP_Upgrader live in wp-admin includes,
		// which are not loaded by default in the test (front-end) context.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	protected function setUp(): void {
		parent::setUp();
		delete_transient( self::CACHE_KEY );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_transient( self::CACHE_KEY );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Prime the manifest cache so fetch_manifest() returns it without HTTP. */
	private function prime_manifest( array $fields ): void {
		set_transient( self::CACHE_KEY, (object) $fields, 3600 );
	}

	/** Invoke a private/protected static method via Reflection. */
	private function call_static( string $method, array $args = [] ) {
		$ref = new ReflectionMethod( \Linguaforge_Updater::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	/** Install a pre_http_request stub returning a fixed response/WP_Error. */
	private function stub_http( $response ): void {
		add_filter( 'pre_http_request', static fn() => $response, 10, 3 );
	}

	private function http_200(): array {
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => '',
			'headers'  => [],
			'cookies'  => [],
		];
	}

	private function upgrader(): \WP_Upgrader {
		return new \WP_Upgrader();
	}

	// =========================================================================
	// is_allowed_download_host()
	// =========================================================================

	public function test_allowed_hosts_exact_match(): void {
		foreach ( [ 'lingua-forge.com', 'github.com', 'objects.githubusercontent.com' ] as $host ) {
			$this->assertTrue(
				$this->call_static( 'is_allowed_download_host', [ $host ] ),
				"$host must be allowed (exact match)."
			);
		}
	}

	public function test_allowed_hosts_subdomain_match(): void {
		foreach ( [ 'releases.github.com', 'www.lingua-forge.com', 'cdn.objects.githubusercontent.com' ] as $host ) {
			$this->assertTrue(
				$this->call_static( 'is_allowed_download_host', [ $host ] ),
				"$host must be allowed (subdomain match)."
			);
		}
	}

	public function test_disallowed_and_spoofed_hosts_rejected(): void {
		$rejected = [
			'evil.com',
			'',
			'notgithub.com',          // not a subdomain of github.com
			'github.com.evil.com',    // suffix-spoof attempt
			'lingua-forge.com.attacker.net',
			'githubXcom',
		];
		foreach ( $rejected as $host ) {
			$this->assertFalse(
				$this->call_static( 'is_allowed_download_host', [ $host ] ),
				"$host must be rejected."
			);
		}
	}

	// =========================================================================
	// verify_and_download() — guards
	// =========================================================================

	public function test_passthrough_when_pre_already_handled(): void {
		$result = \Linguaforge_Updater::verify_and_download( '/already/handled.zip', 'https://github.com/x.zip', $this->upgrader() );
		$this->assertSame( '/already/handled.zip', $result, 'A prior filter result must be respected.' );
	}

	public function test_returns_false_when_manifest_unavailable(): void {
		// Sentinel error object → fetch_manifest() returns false.
		$this->prime_manifest( [ 'error' => true ] );

		$result = \Linguaforge_Updater::verify_and_download( false, 'https://github.com/x.zip', $this->upgrader() );
		$this->assertFalse( $result );
	}

	public function test_returns_false_for_non_lf_package(): void {
		$this->prime_manifest( [
			'version'      => '9.9.9',
			'download_url' => 'https://github.com/leotiger/lingua-forge/releases/download/v9.9.9/lingua-forge.zip',
		] );

		// A different package URL must not be intercepted.
		$result = \Linguaforge_Updater::verify_and_download( false, 'https://github.com/someone/other.zip', $this->upgrader() );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// verify_and_download() — host pinning (the security-critical path)
	// =========================================================================

	public function test_blocks_disallowed_download_host(): void {
		$package = 'https://evil.example/lingua-forge.zip';
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package ] );

		// Defensive: if the host check regressed and a download were attempted,
		// this stub would surface a *different* error code, failing the assertion.
		$this->stub_http( new \WP_Error( 'should_not_download', 'Download must not be attempted for a blocked host.' ) );

		$result = \Linguaforge_Updater::verify_and_download( false, $package, $this->upgrader() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'linguaforge_updater_host_blocked', $result->get_error_code() );
	}

	// =========================================================================
	// verify_and_download() — SHA-256 verification
	// =========================================================================

	public function test_empty_sha256_skips_verification_and_returns_temp_file(): void {
		$package = 'https://github.com/leotiger/lingua-forge/releases/download/v9.9.9/lingua-forge.zip';
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => '' ] );
		$this->stub_http( $this->http_200() );

		$result = \Linguaforge_Updater::verify_and_download( false, $package, $this->upgrader() );

		$this->assertIsString( $result, 'An allowed host with no SHA-256 must return the temp file path.' );
		$this->assertFileExists( $result );
		wp_delete_file( $result );
	}

	public function test_matching_sha256_returns_temp_file(): void {
		$package = 'https://github.com/leotiger/lingua-forge/releases/download/v9.9.9/lingua-forge.zip';

		// The pre_http_request short-circuit means WordPress does not stream a body
		// to the temp file, so the downloaded file is empty. The expected hash is
		// therefore the SHA-256 of an empty string — this asserts the *match* path,
		// not any particular payload.
		$empty_hash = hash( 'sha256', '' );
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => $empty_hash ] );
		$this->stub_http( $this->http_200() );

		$result = \Linguaforge_Updater::verify_and_download( false, $package, $this->upgrader() );

		$this->assertIsString( $result, 'A matching SHA-256 must return the temp file path.' );
		$this->assertFileExists( $result );
		wp_delete_file( $result );
	}

	public function test_mismatched_sha256_returns_error(): void {
		$package = 'https://github.com/leotiger/lingua-forge/releases/download/v9.9.9/lingua-forge.zip';
		$wrong   = str_repeat( 'a', 64 ); // valid-shape hex that will never match the empty file
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => $wrong ] );
		$this->stub_http( $this->http_200() );

		$result = \Linguaforge_Updater::verify_and_download( false, $package, $this->upgrader() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'linguaforge_updater_checksum_mismatch', $result->get_error_code() );
	}

	public function test_download_error_is_propagated(): void {
		$package = 'https://github.com/leotiger/lingua-forge/releases/download/v9.9.9/lingua-forge.zip';
		$this->prime_manifest( [ 'version' => '9.9.9', 'download_url' => $package, 'sha256' => '' ] );
		$this->stub_http( new \WP_Error( 'http_request_failed', 'Connection refused.' ) );

		$result = \Linguaforge_Updater::verify_and_download( false, $package, $this->upgrader() );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A download failure must propagate as a WP_Error.' );
	}

	// =========================================================================
	// check_for_update() — version comparison
	// =========================================================================

	public function test_check_for_update_injects_response_when_newer(): void {
		$package = 'https://github.com/leotiger/lingua-forge/releases/download/v99.0.0/lingua-forge.zip';
		$this->prime_manifest( [
			'version'      => '99.0.0',
			'download_url' => $package,
			'details_url'  => 'https://lingua-forge.com',
		] );

		$transient          = new \stdClass();
		$transient->checked  = [ \Linguaforge_Updater::PLUGIN_BASENAME => '2.0.0' ];
		$transient->response = [];

		$result = \Linguaforge_Updater::check_for_update( $transient );

		$this->assertArrayHasKey( \Linguaforge_Updater::PLUGIN_BASENAME, $result->response );
		$this->assertSame( '99.0.0', $result->response[ \Linguaforge_Updater::PLUGIN_BASENAME ]->new_version );
		$this->assertSame( $package, $result->response[ \Linguaforge_Updater::PLUGIN_BASENAME ]->package );
	}

	public function test_check_for_update_marks_no_update_when_not_newer(): void {
		$this->prime_manifest( [
			'version'      => '0.0.1',
			'download_url' => 'https://github.com/leotiger/lingua-forge/releases/download/v0.0.1/lingua-forge.zip',
		] );

		$transient           = new \stdClass();
		$transient->checked   = [ \Linguaforge_Updater::PLUGIN_BASENAME => '2.0.0' ];
		$transient->response  = [];
		$transient->no_update = [];

		$result = \Linguaforge_Updater::check_for_update( $transient );

		$this->assertArrayNotHasKey( \Linguaforge_Updater::PLUGIN_BASENAME, $result->response );
		$this->assertArrayHasKey( \Linguaforge_Updater::PLUGIN_BASENAME, $result->no_update );
	}

	public function test_check_for_update_bails_when_checked_empty(): void {
		$this->prime_manifest( [ 'version' => '99.0.0', 'download_url' => 'https://github.com/x.zip' ] );

		$transient          = new \stdClass();
		$transient->checked  = []; // WordPress hasn't populated the installed list yet.
		$transient->response = [];

		$result = \Linguaforge_Updater::check_for_update( $transient );

		$this->assertEmpty( $result->response, 'No update entry must be injected before WP has populated $checked.' );
	}

	// =========================================================================
	// build_update_object() / build_no_update_object()
	// =========================================================================

	public function test_build_update_object_maps_fields_and_defaults(): void {
		$manifest = (object) [
			'version'      => '9.9.9',
			'download_url' => 'https://github.com/x.zip',
			'details_url'  => 'https://lingua-forge.com',
		];

		$obj = $this->call_static( 'build_update_object', [ $manifest ] );

		$this->assertSame( 'lingua-forge', $obj->slug );
		$this->assertSame( \Linguaforge_Updater::PLUGIN_BASENAME, $obj->plugin );
		$this->assertSame( '9.9.9', $obj->new_version );
		$this->assertSame( 'https://github.com/x.zip', $obj->package );
		$this->assertSame( '6.4', $obj->requires, 'requires must default to 6.4 when the manifest omits it.' );
		$this->assertSame( '8.1', $obj->requires_php );
	}

	public function test_build_no_update_object_has_empty_package(): void {
		$manifest = (object) [ 'version' => '2.3.2' ];

		$obj = $this->call_static( 'build_no_update_object', [ $manifest ] );

		$this->assertSame( '2.3.2', $obj->new_version );
		$this->assertSame( '', $obj->package, 'no_update entries must carry an empty package URL.' );
	}
}
