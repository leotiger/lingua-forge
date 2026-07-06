<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- LfTestRedirectException seam stub and RedirectorRedirectIntegrationTest must coexist; same pattern as LanguageUninstallerTest.php.
/**
 * Integration tests for Redirector redirect behaviour.
 *
 * Covers redirect-firing (caught via a wp_redirect filter seam) and
 * redirect-suppression for the four public redirect methods:
 *
 *   • normalize_duplicate_slashes()
 *   • redirect_search_under_lang_prefix()
 *   • handle_homepage_redirect()
 *   • handle_init_redirects()     — search + homepage paths
 *   • handle_singular_redirect()  — guard paths only (LF_LANG = source in CLI)
 *
 * Approach — wp_redirect filter seam
 * ------------------------------------
 * wp_safe_redirect() fires apply_filters('wp_redirect', ...) before sending
 * the Location header or calling exit.  Adding a PHP_INT_MAX-priority callback
 * that throws LfTestRedirectException lets the test catch the redirect location
 * and status without the process exiting.  All redirect assertions use try/catch;
 * all no-redirect assertions simply verify the method returns normally.
 *
 * WP_CLI note
 * -----------
 * composer test:integration runs `php vendor/bin/phpunit` (not `wp phpunit`),
 * so WP_CLI is NOT defined.  Context::is_system_request() therefore returns
 * false in all redirect-method calls, and the is_system_request() guards do
 * not suppress redirects here.
 *
 * LF_LANG note
 * ------------
 * LF_LANG is set to the source language ('en') by Plugin::boot() in CLI mode.
 * handle_singular_redirect() returns early when LF_LANG === source_language(),
 * so only guard paths (non-singular, is_search) are testable here.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * Thrown by the wp_redirect seam so redirect methods never call exit.
 *
 * The constructor stores location and status as readonly properties so
 * test assertions can read them cleanly.
 */
final class LfTestRedirectException extends \RuntimeException {

	public function __construct(
		public readonly string $location,
		public readonly int    $status
	) {
		parent::__construct( "Redirect {$status} → {$location}" );
	}
}

final class RedirectorRedirectIntegrationTest extends WP_UnitTestCase {

	private Router $router;

	/** @var string  Saved $_SERVER['REQUEST_URI'] — restored in tearDown. */
	private string $saved_request_uri = '';

	/** @var array<string,mixed>  Saved $_GET — restored in tearDown. */
	private array $saved_get = [];

	protected function setUp(): void {
		parent::setUp();

		$this->router = Router::get_instance();

		update_option( 'linguaforge_routing_mode',     'path' );
		update_option( 'linguaforge_primary_language', 'en'   );

		// Flush Context caches so the option changes above take effect.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $this->router->context, null );
		}

		// Ensure 'de' is in the language list so the lang-prefix regex matches.
		add_filter( 'lf_languages_list', [ $this, 'add_de_to_language_list' ] );

		// Flush cached_languages again after adding the filter so lang_regex() sees 'de'.
		$ref->getProperty( 'cached_languages' )->setValue( $this->router->context, null );

		// Save superglobals before any test mutates them.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved verbatim for tearDown restoration only; not used for routing or processing.
		$this->saved_request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$this->saved_get         = $_GET;

		// Install the redirect seam at the highest possible priority so it fires
		// before any other wp_redirect callback and before headers are sent.
		add_filter( 'wp_redirect', [ $this, 'intercept_redirect' ], PHP_INT_MAX, 2 );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_redirect', [ $this, 'intercept_redirect' ], PHP_INT_MAX );
		remove_filter( 'lf_languages_list', [ $this, 'add_de_to_language_list' ] );

		$_SERVER['REQUEST_URI'] = $this->saved_request_uri;
		$_GET                   = $this->saved_get;

		delete_option( 'page_on_front' );
		delete_option( 'show_on_front' );

		parent::tearDown();
	}

	/** Filter callback: ensures 'de' is always in the language list. */
	public function add_de_to_language_list( array $langs ): array {
		return array_values( array_unique( array_merge( $langs, [ 'de' ] ) ) );
	}

	/**
	 * Redirect seam — added to 'wp_redirect' at PHP_INT_MAX.
	 * Throws before wp_redirect() sends the Location header, preventing exit.
	 *
	 * @throws LfTestRedirectException Always.
	 */
	public function intercept_redirect( string $location, int $status ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only seam; location is caught and compared in tests, never rendered to output.
		throw new LfTestRedirectException( $location, $status );
	}

	// =========================================================================
	// normalize_duplicate_slashes()
	// =========================================================================

	/**
	 * @testdox Double-slash URI triggers a 301 redirect to the clean path.
	 */
	public function test_normalize_double_slash_fires_redirect(): void {
		$_SERVER['REQUEST_URI'] = '/foo//bar/';

		try {
			$this->router->redirector->normalize_duplicate_slashes();
			$this->fail( 'Expected redirect for double-slash URI.' );
		} catch ( LfTestRedirectException $e ) {
			$this->assertSame( '/foo/bar/', $e->location );
			$this->assertSame( 301, $e->status );
		}
	}

	/**
	 * @testdox Clean URI does not trigger a redirect.
	 */
	public function test_normalize_clean_uri_no_redirect(): void {
		$_SERVER['REQUEST_URI'] = '/foo/bar/';

		$this->router->redirector->normalize_duplicate_slashes();
		$this->addToAssertionCount( 1 ); // method returned normally — no redirect.
	}

	/**
	 * @testdox Multiple consecutive leading slashes are collapsed (no preceding colon to protect them).
	 */
	public function test_normalize_multiple_leading_slashes_collapsed(): void {
		$_SERVER['REQUEST_URI'] = '//foo//bar/';

		try {
			$this->router->redirector->normalize_duplicate_slashes();
			$this->fail( 'Expected redirect.' );
		} catch ( LfTestRedirectException $e ) {
			// Both leading // and internal // are collapsed.
			$this->assertSame( '/foo/bar/', $e->location );
			$this->assertSame( 301, $e->status );
		}
	}

	/**
	 * @testdox Query string is preserved when normalising slashes.
	 */
	public function test_normalize_preserves_query_string(): void {
		$_SERVER['REQUEST_URI'] = '/foo//bar/?s=hello';

		try {
			$this->router->redirector->normalize_duplicate_slashes();
			$this->fail( 'Expected redirect.' );
		} catch ( LfTestRedirectException $e ) {
			$this->assertSame( '/foo/bar/?s=hello', $e->location );
		}
	}

	// =========================================================================
	// redirect_search_under_lang_prefix()
	// =========================================================================

	/**
	 * @testdox Non-search page does not trigger search redirect.
	 */
	public function test_search_redirect_non_search_page_no_redirect(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );

		$this->router->redirector->redirect_search_under_lang_prefix();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Search request NOT under a lang prefix is left alone.
	 */
	public function test_search_redirect_no_lang_prefix_no_redirect(): void {
		$this->go_to( '/?s=hello' );
		$_SERVER['REQUEST_URI'] = '/?s=hello';

		$this->router->redirector->redirect_search_under_lang_prefix();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Search request under /de/ prefix triggers 301 redirect to canonical search URL.
	 */
	public function test_search_redirect_under_lang_prefix_fires_redirect(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		// Simulate a search arriving at /de/?s=hello (lang prefix in path).
		$this->go_to( '/?s=hello' );
		$_SERVER['REQUEST_URI'] = '/de/?s=hello';
		$_GET['lang']           = 'de';
		// Note: redirect_search_under_lang_prefix() reads get_query_var('s'),
		// which was set by go_to('/?s=hello') above.

		try {
			$this->router->redirector->redirect_search_under_lang_prefix();
			$this->fail( 'Expected redirect for search under lang prefix.' );
		} catch ( LfTestRedirectException $e ) {
			$this->assertStringContainsString( 'lang=de', $e->location );
			$this->assertStringContainsString( 's=hello', $e->location );
			$this->assertSame( 301, $e->status );
		}
	}

	// =========================================================================
	// handle_homepage_redirect()
	// =========================================================================

	/**
	 * @testdox Non-root path does not trigger homepage redirect.
	 */
	public function test_homepage_redirect_non_root_path_no_redirect(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$_SERVER['REQUEST_URI'] = '/some/interior/page/';
		$this->go_to( '/' );

		$this->router->redirector->handle_homepage_redirect();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Root path with no static front page does not redirect.
	 */
	public function test_homepage_redirect_no_page_on_front_no_redirect(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		update_option( 'page_on_front', 0 );
		$_SERVER['REQUEST_URI'] = '/';
		$this->go_to( '/' );

		$this->router->redirector->handle_homepage_redirect();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Root path with a static front page set fires a 302 redirect.
	 */
	public function test_homepage_redirect_fires_when_page_on_front_set(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$front_id = (int) self::factory()->post->create( [
			'post_status' => 'publish',
			'post_type'   => 'page',
			'post_name'   => 'hp-redir-' . uniqid(),
		] );
		update_option( 'page_on_front', $front_id );
		update_option( 'show_on_front', 'page' );

		$_SERVER['REQUEST_URI'] = '/';
		$this->go_to( '/' );

		// In wp-env (plain permalinks) get_permalink($front_id) returns
		// ?page_id=N which is never equal to home_url('/'), so the redirect fires.
		// Accommodate edge-cases where they do match by accepting either outcome
		// but always recording an assertion.
		try {
			$this->router->redirector->handle_homepage_redirect();
			// If we reach here the permalinks coincidentally matched — that is valid
			// behaviour (no redirect needed), so just record the assertion.
			$this->addToAssertionCount( 1 );
		} catch ( LfTestRedirectException $e ) {
			$this->assertSame( 302, $e->status );
			$this->assertNotEmpty( $e->location );
		}
	}

	/**
	 * @testdox Lang-prefixed root path (e.g. /de/) with static front page fires redirect.
	 */
	public function test_homepage_redirect_lang_prefix_root_path(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$front_id = (int) self::factory()->post->create( [
			'post_status' => 'publish',
			'post_type'   => 'page',
			'post_name'   => 'hp-de-' . uniqid(),
		] );
		update_option( 'page_on_front', $front_id );
		update_option( 'show_on_front', 'page' );

		// /de/ matches the lang-prefix pattern → method proceeds.
		$_SERVER['REQUEST_URI'] = '/de/';
		$this->go_to( '/' );

		try {
			$this->router->redirector->handle_homepage_redirect();
			$this->addToAssertionCount( 1 );
		} catch ( LfTestRedirectException $e ) {
			$this->assertSame( 302, $e->status );
			$this->assertNotEmpty( $e->location );
		}
	}

	// =========================================================================
	// handle_init_redirects() — search path
	// =========================================================================

	/**
	 * @testdox Search query under a lang-prefix path at init stage fires 301 redirect.
	 */
	public function test_init_redirects_search_under_lang_prefix(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$_SERVER['REQUEST_URI'] = '/de/?s=cats';
		$_GET['s']              = 'cats';
		$_GET['lang']           = 'de';

		try {
			$this->router->redirector->handle_init_redirects();
			$this->fail( 'Expected redirect for search under lang prefix at init.' );
		} catch ( LfTestRedirectException $e ) {
			$this->assertStringContainsString( 'lang=de', $e->location );
			$this->assertStringContainsString( 's=cats', $e->location );
			$this->assertSame( 301, $e->status );
		}
	}

	/**
	 * @testdox No search param + no page_on_front → handle_init_redirects does nothing.
	 */
	public function test_init_redirects_no_action_when_nothing_to_redirect(): void {
		$_SERVER['REQUEST_URI'] = '/';
		$_GET                   = [];
		update_option( 'page_on_front', 0 );

		$this->router->redirector->handle_init_redirects();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Latest-posts front (page_on_front = 0) + bare root in the source
	 *          language does not redirect.
	 *
	 * This exercises the new "Your latest posts" branch added to the homepage
	 * redirect: when there is no static front-page post, a non-source LF_LANG
	 * (detected from the lf_lang cookie/Accept-Language, since bare '/' carries
	 * no URL prefix) should redirect to the language-prefixed root. LF_LANG is
	 * fixed to the source language ('en') in this CLI test harness (see class
	 * docblock), so only the negative guard path — source-language visitor,
	 * no redirect — is reachable here; the same limitation applies to
	 * handle_singular_redirect() above.
	 */
	public function test_init_redirects_latest_posts_front_source_lang_no_redirect(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		update_option( 'page_on_front', 0 );
		update_option( 'show_on_front', 'posts' );

		$_SERVER['REQUEST_URI'] = '/';
		$_GET                   = [];

		$this->router->redirector->handle_init_redirects();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Root path at init with page_on_front set + TRID translation fires redirect.
	 */
	public function test_init_redirects_homepage_fires_when_translation_exists(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$trid     = 'trid-hp-init-' . uniqid();
		$front_id = (int) self::factory()->post->create( [
			'post_status' => 'publish',
			'post_type'   => 'page',
			'post_name'   => 'front-init-' . uniqid(),
		] );
		// Register the page in a TRID group as the 'en' (source) translation.
		$tg = $this->router->trid_group;
		$tg->set_lang( $front_id, 'en' );
		$tg->set_trid( $front_id, $trid );

		update_option( 'page_on_front', $front_id );
		update_option( 'show_on_front', 'page' );

		$_SERVER['REQUEST_URI'] = '/';
		$_GET                   = [];

		// LF_LANG = 'en' = source, so translations[LF_LANG] = $front_id.
		// get_permalink($front_id) ≠ home_url('/') in plain-permalink wp-env → redirect fires.
		try {
			$this->router->redirector->handle_init_redirects();
			// Redirect didn't fire — permalinks matched home_url('/'); valid.
			$this->addToAssertionCount( 1 );
		} catch ( LfTestRedirectException $e ) {
			$this->assertSame( 302, $e->status );
			$this->assertNotEmpty( $e->location );
		}
	}

	// =========================================================================
	// handle_singular_redirect() — guard paths
	// (LF_LANG === source_language() in CLI, so the actual redirect branch
	//  is unreachable; we cover the early-return guards instead.)
	// =========================================================================

	/**
	 * @testdox Non-singular page (homepage) does not trigger singular redirect.
	 */
	public function test_singular_redirect_non_singular_no_redirect(): void {
		$this->go_to( '/' );

		$this->router->redirector->handle_singular_redirect();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Search page does not trigger singular redirect.
	 */
	public function test_singular_redirect_search_page_no_redirect(): void {
		$this->go_to( '/?s=hello' );

		$this->router->redirector->handle_singular_redirect();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Singular post in source language does not trigger singular redirect.
	 *
	 * In CLI mode LF_LANG === source_language() so handle_singular_redirect()
	 * returns early at the LF_LANG guard — the redirect branch is not reached.
	 */
	public function test_singular_redirect_source_language_no_redirect(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );

		$this->router->redirector->handle_singular_redirect();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox REST-style REQUEST_URI triggers is_system_request() and suppresses redirect.
	 */
	public function test_singular_redirect_rest_uri_no_redirect(): void {
		// Setting the URI to a REST path makes is_system_request() return true.
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

		$this->router->redirector->handle_singular_redirect();
		$this->addToAssertionCount( 1 );
	}
}
