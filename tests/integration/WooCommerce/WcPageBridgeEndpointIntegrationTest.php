<?php
/**
 * Integration tests for WcPageBridge::fix_myaccount_endpoint_request().
 *
 * Root cause recap
 * ----------------
 * WC's endpoint rewrite rule `(.?.+?)/orders(/(.*))?/?$` lives earlier in the
 * rule array than LF's generic fallback.  For /es/mi-cuenta/orders/ it matches
 * first and produces pagename=es/mi-cuenta.  WordPress calls
 * get_page_by_path('es/mi-cuenta'), finds nothing, clears all query vars, and
 * sets error=404 — before the `request` filter fires.  The fix re-parses
 * $_SERVER['REQUEST_URI'] directly and rebuilds correct query vars.
 *
 * Coverage
 * --------
 *   1. orders endpoint: error=404 input + /es/mi-cuenta/orders/ → correct vars.
 *   2. Endpoint with a value: /es/mi-cuenta/view-order/123/ → view-order=123.
 *   3. Unknown endpoint slug → input passed through unchanged.
 *   4. Non-myaccount page slug → input passed through unchanged.
 *   5. URL without a language prefix → input passed through unchanged.
 *   6. My Account root URL (no endpoint segment) → input passed through unchanged.
 *   7. Source-language page slug also recognised by is_myaccount_page_slug().
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\WcPageBridge;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;

final class WcPageBridgeEndpointIntegrationTest extends WcIntegrationTestCase {

	/** ID of the source (ca) My Account page created in setUp. */
	private int $source_page_id = 0;

	/** ID of the translated (es) My Account page created in setUp. */
	private int $trans_page_id = 0;

	/** Saved $_SERVER['REQUEST_URI'] value — restored in tearDown. */
	private string|null $saved_request_uri = null;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// LF_LANG may already be defined by WcPageBridgeArchiveIntegrationTest
		// which runs alphabetically earlier.  Both define it as 'es'.
		defined( 'LF_LANG' ) || define( 'LF_LANG', 'es' );
	}

	protected function setUp(): void {
		parent::setUp();

		// Expose 'ca' and 'es' languages to the Router so the lang regex in
		// fix_myaccount_endpoint_request() includes 'es'.
		add_filter( 'lf_languages_list', static fn() => [ 'ca', 'es' ] );

		// Flush the Context language cache so the filter above is picked up.
		$ctx_ref = new ReflectionClass( Context::class );
		$p       = $ctx_ref->getProperty( 'cached_languages' );
		$p->setAccessible( true );
		$p->setValue( Router::get_instance()->context, null );

		// Build source (ca) and translated (es) My Account pages.
		$trid = $this->trid();

		$this->source_page_id = (int) self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_name'   => 'my-account',
		] );
		$this->tg->set_lang( $this->source_page_id, self::SOURCE_LANG );
		$this->tg->set_trid( $this->source_page_id, $trid );

		$this->trans_page_id = (int) self::factory()->post->create( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_name'   => 'mi-cuenta',
		] );
		$this->tg->set_lang( $this->trans_page_id, self::TRANS_LANG );
		$this->tg->set_trid( $this->trans_page_id, $trid );

		update_option( 'woocommerce_myaccount_page_id', $this->source_page_id );

		// Snapshot REQUEST_URI so each test can set its own value.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test infrastructure: saving and restoring a server variable; no output or DB write involved.
		$this->saved_request_uri = $_SERVER['REQUEST_URI'] ?? null;

		// Reset WcPageBridge static caches (populated on first use; must be
		// cleared between tests because WP_UnitTestCase rolls back the DB but
		// not PHP statics, and the pages created above have new IDs each run).
		$this->reset_wcpb_caches();
	}

	protected function tearDown(): void {
		// Restore REQUEST_URI.
		if ( null === $this->saved_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->saved_request_uri;
		}

		$this->reset_wcpb_caches();

		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Reset all WcPageBridge per-request static caches via reflection.
	 */
	private function reset_wcpb_caches(): void {
		$ref = new ReflectionClass( WcPageBridge::class );

		foreach ( [ 'myaccount_slugs', 'source_trids' ] as $nullable_prop ) {
			$p = $ref->getProperty( $nullable_prop );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}

		$p = $ref->getProperty( 'translated_page_ids' );
		$p->setAccessible( true );
		$p->setValue( null, [] );
	}

	/**
	 * Set the request URI, call the static method, return its output.
	 *
	 * @param  array<string,mixed> $input_qv
	 * @return array<string,mixed>
	 */
	private function fix( array $input_qv, string $request_uri ): array {
		$_SERVER['REQUEST_URI'] = $request_uri;
		return WcPageBridge::fix_myaccount_endpoint_request( $input_qv );
	}

	// =========================================================================
	// 1. orders endpoint — main happy path
	// =========================================================================

	public function test_orders_endpoint_url_is_fixed(): void {
		$result = $this->fix( [ 'error' => '404' ], '/es/mi-cuenta/orders/' );

		$this->assertSame(
			'mi-cuenta',
			$result['pagename'] ?? '',
			'pagename must be the My Account page slug.'
		);
		$this->assertSame(
			'es',
			$result['lang'] ?? '',
			'lang must be preserved from the URL.'
		);
		$this->assertArrayHasKey( 'orders', $result, 'orders endpoint query var must be present.' );
		$this->assertSame( '', $result['orders'], 'orders value is empty for the list view.' );
		$this->assertArrayNotHasKey( 'error', $result, 'error=404 must be cleared.' );
	}

	// =========================================================================
	// 2. Endpoint with a value (view-order/123)
	// =========================================================================

	public function test_view_order_endpoint_with_value_is_fixed(): void {
		$result = $this->fix( [ 'error' => '404' ], '/es/mi-cuenta/view-order/123/' );

		$this->assertSame( 'mi-cuenta', $result['pagename'] ?? '' );
		$this->assertSame( 'es', $result['lang'] ?? '' );
		$this->assertArrayHasKey( 'view-order', $result );
		$this->assertSame( '123', $result['view-order'] );
		$this->assertArrayNotHasKey( 'error', $result );
	}

	// =========================================================================
	// 3. Unknown endpoint slug — input unchanged
	// =========================================================================

	public function test_unknown_endpoint_slug_passes_through(): void {
		$input  = [ 'error' => '404' ];
		$result = $this->fix( $input, '/es/mi-cuenta/totally-unknown-endpoint/' );

		$this->assertSame( $input, $result );
	}

	// =========================================================================
	// 4. Non-myaccount page slug — input unchanged
	// =========================================================================

	public function test_non_myaccount_page_slug_passes_through(): void {
		$input  = [ 'error' => '404' ];
		$result = $this->fix( $input, '/es/tienda/orders/' );

		$this->assertSame( $input, $result );
	}

	// =========================================================================
	// 5. URL without a language prefix — input unchanged
	// =========================================================================

	public function test_url_without_lang_prefix_passes_through(): void {
		$input  = [ 'pagename' => 'mi-cuenta/orders' ];
		$result = $this->fix( $input, '/mi-cuenta/orders/' );

		$this->assertSame( $input, $result );
	}

	// =========================================================================
	// 6. My Account root URL (no endpoint segment) — input unchanged
	// =========================================================================

	public function test_myaccount_root_url_passes_through(): void {
		$input  = [ 'pagename' => 'mi-cuenta', 'lang' => 'es' ];
		$result = $this->fix( $input, '/es/mi-cuenta/' );

		$this->assertSame( $input, $result );
	}

	// =========================================================================
	// 7. Source-language slug also recognised by is_myaccount_page_slug()
	// =========================================================================

	public function test_source_page_slug_is_also_recognised(): void {
		// The source My Account page slug ('my-account') must be in the slug set
		// built by is_myaccount_page_slug() alongside translated slugs.
		$result = $this->fix( [ 'error' => '404' ], '/es/my-account/orders/' );

		$this->assertSame(
			'my-account',
			$result['pagename'] ?? '',
			'Source-language My Account slug must be recognised.'
		);
		$this->assertArrayHasKey( 'orders', $result );
		$this->assertArrayNotHasKey( 'error', $result );
	}
}
