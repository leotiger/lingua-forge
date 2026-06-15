<?php
/**
 * Integration tests for IndexNowManager — key management, URL collection,
 * submission payload, and the asynchronous (WP-Cron) submission path added
 * in 2.3.2.
 *
 * Covered here:
 *   get_key() / rotate_key() / key_file_url() — 32-char hex key generation,
 *                             persistence, rotation, and key-file URL format
 *   collect_post_urls()     — all published TRID siblings; unpublished siblings
 *                             excluded
 *   collect_all_urls()      — published LF posts only; non-LF posts and excluded
 *                             post types absent
 *   submit_urls()           — payload shape (host/key/keyLocation/urlList),
 *                             200/202 → 'ok', non-2xx → 'error', empty → 'error'
 *   submit_all()            — 'empty' on no posts; submits when posts exist
 *   on_post_saved()         — schedules a single cron event, does NO network I/O,
 *                             debounces duplicates, skips drafts and non-LF posts
 *   run_scheduled_submit()  — the cron callback collects siblings and submits
 *   maybe_serve_key_file()  — returns without exiting on a non-matching request
 *
 * Strategy:
 *   • Outbound HTTP is intercepted with the `pre_http_request` filter (same
 *     mechanism as AbstractProviderIntegrationTest) so no real request is made
 *     and the submitted payload can be asserted.
 *   • Posts are created via the factory with _lf_trid / _lf_lang set through
 *     TridGroup — the same mechanism the plugin uses at runtime.
 *   • The IndexNow key option and any scheduled cron events are cleared in
 *     setUp()/tearDown() so tests do not bleed into each other.
 *
 * The matching branch of maybe_serve_key_file() ends in `exit` and therefore
 * cannot be exercised under PHPUnit; only the non-matching (no-exit) guard is
 * tested here. The serving output is covered by the live key-file reachability
 * check in the Sitemap panel.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Seo\IndexNowManager;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use WP_UnitTestCase;

final class IndexNowManagerIntegrationTest extends WP_UnitTestCase {

	private const CRON_HOOK = 'linguaforge_indexnow_submit';
	private const KEY_OPTION = 'linguaforge_indexnow_key';

	private TridGroup $tg;

	/** @var array<int,array{url:string,args:array}> Captured outbound HTTP requests. */
	private array $captured = [];

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', 'en',   false );
		update_option( 'linguaforge_routing_mode',     'path', false );

		$this->reset_context_caches();

		$this->tg       = Router::get_instance()->trid_group;
		$this->captured = [];

		// Start clean: no stored key, no pending cron events.
		delete_option( self::KEY_OPTION );
		wp_unschedule_hook( self::CRON_HOOK );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( self::KEY_OPTION );
		wp_unschedule_hook( self::CRON_HOOK );
		$this->reset_context_caches();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function indexnow(): IndexNowManager {
		return Router::get_instance()->indexnow_manager;
	}

	private function reset_context_caches(): void {
		$ctx_ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ctx_ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	/**
	 * Create a post assigned to a language and TRID.
	 *
	 * @param  string $lang
	 * @param  string $trid
	 * @param  string $status     post_status (default 'publish')
	 * @param  string $post_type
	 * @return int    New post ID.
	 */
	private function make_lf_post( string $lang, string $trid, string $status = 'publish', string $post_type = 'post' ): int {
		$id = (int) $this->factory->post->create( [
			'post_type'   => $post_type,
			'post_status' => $status,
		] );
		$this->tg->set_lang( $id, $lang );
		$this->tg->set_trid( $id, $trid );
		return $id;
	}

	private function trid(): string {
		return 'in-trid-' . uniqid( '', true );
	}

	/**
	 * Install a pre_http_request stub that records every request and returns a
	 * canned response with the given status code.
	 */
	private function stub_http_capture( int $code, string $body = '' ): void {
		$response = [
			'response' => [ 'code' => $code, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [],
			'cookies'  => [],
		];

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $response ) {
				$this->captured[] = [ 'url' => (string) $url, 'args' => (array) $args ];
				return $response;
			},
			10,
			3
		);
	}

	/** Count scheduled cron events for CRON_HOOK whose first arg equals $post_id. */
	private function count_scheduled( int $post_id ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			if ( ! isset( $events[ self::CRON_HOOK ] ) ) {
				continue;
			}
			foreach ( $events[ self::CRON_HOOK ] as $instance ) {
				if ( (int) ( $instance['args'][0] ?? 0 ) === $post_id ) {
					$count++;
				}
			}
		}
		return $count;
	}

	// =========================================================================
	// Key management
	// =========================================================================

	public function test_get_key_generates_and_persists_32_char_hex_key(): void {
		$key = $this->indexnow()->get_key();

		$this->assertSame( 32, strlen( $key ), 'IndexNow key must be 32 characters.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key, 'Key must be lowercase hex.' );

		// Second read returns the same persisted key.
		$this->assertSame( $key, $this->indexnow()->get_key(), 'get_key() must persist the generated key.' );
		$this->assertSame( $key, (string) get_option( self::KEY_OPTION ), 'Key must be stored in the option.' );
	}

	public function test_rotate_key_changes_the_key(): void {
		$first = $this->indexnow()->get_key();
		$next  = $this->indexnow()->rotate_key();

		$this->assertNotSame( $first, $next, 'rotate_key() must produce a different key.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $next );
	}

	public function test_key_file_url_format(): void {
		$key = $this->indexnow()->get_key();

		$this->assertSame(
			home_url( '/' . $key . '.txt' ),
			$this->indexnow()->key_file_url(),
			'key_file_url() must be /<key>.txt under the site home URL.'
		);
	}

	// =========================================================================
	// URL collection
	// =========================================================================

	public function test_collect_post_urls_includes_all_published_siblings(): void {
		$trid  = $this->trid();
		$en_id = $this->make_lf_post( 'en', $trid );
		$es_id = $this->make_lf_post( 'es', $trid );

		$urls = $this->indexnow()->collect_post_urls( $en_id );

		$this->assertContains( get_permalink( $en_id ), $urls );
		$this->assertContains( get_permalink( $es_id ), $urls );
		$this->assertCount( 2, $urls, 'Both published siblings must be collected.' );
	}

	public function test_collect_post_urls_excludes_unpublished_sibling(): void {
		$trid    = $this->trid();
		$en_id   = $this->make_lf_post( 'en', $trid );
		$draft_id = $this->make_lf_post( 'es', $trid, 'draft' );

		$urls = $this->indexnow()->collect_post_urls( $en_id );

		$this->assertContains( get_permalink( $en_id ), $urls );
		$this->assertNotContains( get_permalink( $draft_id ), $urls,
			'A draft sibling must not be submitted to IndexNow.' );
	}

	public function test_collect_all_urls_returns_published_lf_posts_only(): void {
		$lf_id  = $this->make_lf_post( 'en', $this->trid() );

		// A plain post with no _lf_trid must be excluded (INNER JOIN on _lf_trid).
		$plain_id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );

		// An excluded post type carrying _lf_trid must also be absent.
		$excl_id = $this->make_lf_post( 'en', $this->trid(), 'publish', 'nav_menu_item' );

		$urls = $this->indexnow()->collect_all_urls();

		$this->assertContains( get_permalink( $lf_id ), $urls );
		$this->assertNotContains( get_permalink( $plain_id ), $urls,
			'A post without _lf_trid must not appear.' );
		$this->assertNotContains( get_permalink( $excl_id ), $urls,
			'An excluded post type must not appear.' );
	}

	// =========================================================================
	// submit_urls() — payload + status handling
	// =========================================================================

	public function test_submit_urls_posts_expected_payload(): void {
		$this->stub_http_capture( 200 );

		$urls   = [ 'https://example.org/a/', 'https://example.org/b/' ];
		$result = $this->indexnow()->submit_urls( $urls );

		$this->assertSame( 'ok', $result );
		$this->assertCount( 1, $this->captured, 'Exactly one IndexNow request must be sent.' );

		$req = $this->captured[0];
		$this->assertSame( 'https://api.indexnow.org/indexnow', $req['url'] );

		$payload = json_decode( (string) $req['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $payload['host'] );
		$this->assertSame( $this->indexnow()->get_key(), $payload['key'] );
		$this->assertSame( $this->indexnow()->key_file_url(), $payload['keyLocation'] );
		$this->assertSame( $urls, $payload['urlList'] );
	}

	public function test_submit_urls_accepts_202(): void {
		$this->stub_http_capture( 202 );

		$this->assertSame( 'ok', $this->indexnow()->submit_urls( [ 'https://example.org/a/' ] ) );
	}

	public function test_submit_urls_returns_error_on_non_2xx(): void {
		$this->stub_http_capture( 403 );

		$this->assertSame( 'error', $this->indexnow()->submit_urls( [ 'https://example.org/a/' ] ) );
	}

	public function test_submit_urls_returns_error_on_empty_url_list_without_http(): void {
		$this->stub_http_capture( 200 );

		$this->assertSame( 'error', $this->indexnow()->submit_urls( [] ) );
		$this->assertCount( 0, $this->captured, 'No HTTP request must be made for an empty URL list.' );
	}

	// =========================================================================
	// submit_all()
	// =========================================================================

	public function test_submit_all_returns_empty_when_no_lf_posts(): void {
		// Fresh per-test transaction → no LF posts exist.
		$this->assertSame( 'empty', $this->indexnow()->submit_all() );
	}

	public function test_submit_all_submits_when_posts_exist(): void {
		$this->stub_http_capture( 200 );
		$id = $this->make_lf_post( 'en', $this->trid() );

		$this->assertSame( 'ok', $this->indexnow()->submit_all() );
		$this->assertNotEmpty( $this->captured );

		$payload = json_decode( (string) $this->captured[0]['args']['body'], true );
		$this->assertContains( get_permalink( $id ), $payload['urlList'] );
	}

	// =========================================================================
	// Async path — on_post_saved() schedules; never blocks on HTTP
	// =========================================================================

	public function test_on_post_saved_schedules_cron_event_without_http(): void {
		$this->stub_http_capture( 200 );

		$trid  = $this->trid();
		$en_id = $this->make_lf_post( 'en', $trid );
		$this->make_lf_post( 'es', $trid );

		$this->indexnow()->on_post_saved( $en_id, get_post( $en_id ) );

		$this->assertNotFalse(
			wp_next_scheduled( self::CRON_HOOK, [ $en_id ] ),
			'on_post_saved() must schedule a cron event for the post.'
		);
		$this->assertCount( 0, $this->captured,
			'on_post_saved() must not perform any HTTP — submission is deferred to cron.' );
	}

	public function test_on_post_saved_debounces_duplicate_schedules(): void {
		$id = $this->make_lf_post( 'en', $this->trid() );
		$post = get_post( $id );

		$this->indexnow()->on_post_saved( $id, $post );
		$this->indexnow()->on_post_saved( $id, $post );

		$this->assertSame( 1, $this->count_scheduled( $id ),
			'Repeated saves of the same post must collapse into a single scheduled event.' );
	}

	public function test_on_post_saved_skips_draft_post(): void {
		$id = $this->make_lf_post( 'en', $this->trid(), 'draft' );

		$this->indexnow()->on_post_saved( $id, get_post( $id ) );

		$this->assertSame( 0, $this->count_scheduled( $id ),
			'A non-published post must not be scheduled.' );
	}

	public function test_on_post_saved_skips_post_without_trid(): void {
		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$this->indexnow()->on_post_saved( $id, get_post( $id ) );

		$this->assertSame( 0, $this->count_scheduled( $id ),
			'A post with no _lf_trid must not be scheduled.' );
	}

	// =========================================================================
	// Async path — run_scheduled_submit() is the cron callback
	// =========================================================================

	public function test_run_scheduled_submit_collects_and_submits_siblings(): void {
		$this->stub_http_capture( 200 );

		$trid  = $this->trid();
		$en_id = $this->make_lf_post( 'en', $trid );
		$es_id = $this->make_lf_post( 'es', $trid );

		$this->indexnow()->run_scheduled_submit( $en_id );

		$this->assertCount( 1, $this->captured, 'The cron callback must perform the submission.' );

		$payload = json_decode( (string) $this->captured[0]['args']['body'], true );
		$this->assertContains( get_permalink( $en_id ), $payload['urlList'] );
		$this->assertContains( get_permalink( $es_id ), $payload['urlList'] );
	}

	// =========================================================================
	// Key-file serving — non-matching request must not exit
	// =========================================================================

	public function test_maybe_serve_key_file_returns_on_non_matching_request(): void {
		// Ensure a key exists so the URL-mismatch branch (not the empty-key guard)
		// is the path under test.
		$this->indexnow()->get_key();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test save/restore of a server superglobal; value is only stored and restored verbatim, never used as input.
		$saved = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/an-ordinary-page/';

		// Must return normally (no exit) for a URL that is not the key file.
		$this->indexnow()->maybe_serve_key_file();

		if ( null === $saved ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $saved;
		}

		$this->assertTrue( true, 'maybe_serve_key_file() returned without exiting on a non-matching request.' );
	}

	// =========================================================================
	// §1.8 — front-end serving must never generate the key (write-on-read)
	// =========================================================================

	public function test_read_key_does_not_generate_or_persist(): void {
		// No key stored (deleted in setUp).
		$this->assertSame( '', $this->indexnow()->read_key(),
			'read_key() must return an empty string when no key is stored.' );
		$this->assertFalse( get_option( self::KEY_OPTION, false ),
			'read_key() must not create the key option.' );
	}

	public function test_maybe_serve_key_file_does_not_generate_key_on_frontend(): void {
		// No key stored yet — a front-end GET must not write one.
		$this->assertFalse( get_option( self::KEY_OPTION, false ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test save/restore of a server superglobal; value is only stored and restored verbatim, never used as input.
		$saved = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/some-front-end-page/';

		$this->indexnow()->maybe_serve_key_file();

		if ( null === $saved ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $saved;
		}

		$this->assertFalse( get_option( self::KEY_OPTION, false ),
			'A front-end request must not generate the IndexNow key (no write-on-read).' );
	}
}
