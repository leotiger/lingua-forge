<?php
/**
 * Unit tests for QueryFilter::arm_page_list_lang_filter() and related helpers.
 *
 * Covered here:
 *   arm_page_list_lang_filter() — frontend neutral-URL path
 *   arm_page_list_lang_filter() — canvas case 1 (core/navigation with ref, and ref=0 fallback)
 *   arm_page_list_lang_filter() — canvas case 2 (core/page-list + current_navigation_post_id())
 *   resolve_nav_lang() — meta / slug-suffix / source-fallback (tested via arm)
 *   current_navigation_post_id() — REST route / $_GET / global $post (tested via arm)
 *   filter_page_list_frontend() — consume-immediately on admin context
 *   clear_nav_lang_after_render() — navigation / page-list / other block
 *
 * Not covered here (covered elsewhere or need a real WP_Query cycle):
 *   filter_page_list_frontend() main language filtering — QueryFilterPageMenuExcludeTest
 *   handle_parse_query(), handle_pre_get_posts(), query(), query_fallback()
 *       — QueryFilterIntegrationTest (requires wp-env).
 *
 * LF_LANG notes:
 *   Canvas / filter / clear tests do not need LF_LANG — they use the canvas
 *   path ($_GET['canvas']='edit') which bypasses the LF_LANG check entirely.
 *
 *   Frontend-arm tests DO need LF_LANG='en' (source).  SeoHelpersTest.php also
 *   defines LF_LANG='es' at file level.  Whichever file PHPUnit loads first wins
 *   the process-wide constant — making the value unpredictable.  To isolate them,
 *   those five tests are annotated @runInSeparateProcess @preserveGlobalState
 *   disabled.  Each child process has a clean constant slate; the define() inside
 *   the test method runs before any code that reads LF_LANG.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Rewrite\QueryFilter;
use LinguaForge\Tests\Unit\WooCommerce\WcUnitTestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/language-router/includes/rewrite/class-query-filter.php';

/**
 * @covers \LinguaForge\Router\Rewrite\QueryFilter::arm_page_list_lang_filter
 * @covers \LinguaForge\Router\Rewrite\QueryFilter::clear_nav_lang_after_render
 * @covers \LinguaForge\Router\Rewrite\QueryFilter::filter_page_list_frontend
 */
final class QueryFilterArmTest extends WcUnitTestCase {

	private QueryFilter $qf;

	protected function setUp(): void {
		parent::setUp(); // resets LfWcMocks, lf_test_filters, lf_test_is_singular
		\LfWcMocks::$is_admin        = false;
		$GLOBALS['lf_test_filters']  = [];
		$_GET                        = []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup only; no data modified.

		// Provide a minimal $wp global so current_navigation_post_id() reads
		// $wp->query_vars['rest_route'] without a fatal on null dereference.
		$wp             = new \stdClass();
		$wp->query_vars = [];
		$GLOBALS['wp']  = $wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		unset( $GLOBALS['post'] );

		// Inject a Router stub with source='en' and pre-seeded language list so
		// resolve_nav_lang() can iterate languages() without calling
		// get_available_languages() (unavailable in unit context).
		self::inject_router_with_langs( 'en', [ 'en', 'es', 'ca', 'fr', 'de' ] );
		$this->qf = new QueryFilter( Router::get_instance() );
	}

	protected function tearDown(): void {
		$_GET = []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test teardown only.
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/** Read the private $pending_page_list_lang property via Reflection. */
	private function pending(): ?string {
		$ref  = new ReflectionClass( QueryFilter::class );
		$prop = $ref->getProperty( 'pending_page_list_lang' );
		$prop->setAccessible( true );
		return $prop->getValue( $this->qf );
	}

	/**
	 * inject_router() extended to also pre-seed cached_languages on the Context
	 * so that resolve_nav_lang() can iterate context->languages() without
	 * calling get_available_languages(), which requires a WordPress runtime.
	 *
	 * @param  string   $source Source language code.
	 * @param  string[] $langs  Full language list (must include $source).
	 */
	private static function inject_router_with_langs( string $source, array $langs ): void {
		self::inject_router( $source );

		$router_ref = new ReflectionClass( Router::class );
		$ctx_prop   = $router_ref->getProperty( 'context' );
		$ctx_prop->setAccessible( true );
		$context = $ctx_prop->getValue( Router::get_instance() );

		$ctx_ref   = new ReflectionClass( Context::class );
		$lang_prop = $ctx_ref->getProperty( 'cached_languages' );
		$lang_prop->setAccessible( true );
		$lang_prop->setValue( $context, $langs );
	}

	/**
	 * Register a wp_navigation post stub in LfWcMocks::$posts with a slug and
	 * optional _lf_lang meta.
	 */
	private function make_nav_post( int $id, string $slug = '', string $lang = '' ): \WP_Post {
		$p            = $this->make_post( $id, 'wp_navigation' );
		$p->post_name = $slug ?: "navigation-{$id}";
		// Reflect the slug back into the stored stub so get_post() returns it.
		\LfWcMocks::$posts[ $id ]->post_name = $p->post_name;
		if ( '' !== $lang ) {
			$this->set_meta( $id, '_lf_lang', $lang );
		}
		return $p;
	}

	/**
	 * Register a page post stub in LfWcMocks::$posts with a _lf_lang value.
	 */
	private function make_page_post( int $id, string $lang ): \WP_Post {
		$p = $this->make_post( $id, 'page' );
		$this->set_meta( $id, '_lf_lang', $lang );
		return $p;
	}

	/**
	 * Call arm_page_list_lang_filter() with a $pre_render sentinel and default
	 * null $parent_block.
	 */
	private function arm( string $block_name, array $attrs = [], mixed $sentinel = null ): mixed {
		return $this->qf->arm_page_list_lang_filter(
			$sentinel,
			[ 'blockName' => $block_name, 'attrs' => $attrs ],
			null // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- test helper; $parent_block intentionally null.
		);
	}

	// =========================================================================
	// arm_page_list_lang_filter() — frontend neutral-URL path
	//
	// These tests need LF_LANG='en' (source language) so the neutral-URL arm
	// fires.  SeoHelpersTest also defines LF_LANG='es' at file level; whichever
	// file PHPUnit loads first wins the process-wide constant.  To sidestep
	// the ordering race each test runs in its own PHP process, defines LF_LANG
	// there, and the main PHPUnit process never sees the define.
	// =========================================================================

	/**
	 * LF_LANG='en' equals source='en' (neutral URL).  A singular product post
	 * has _lf_lang='es'.  The navigation arm must set pending to 'es'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_frontend_arm_sets_pending_on_neutral_url_with_translated_singular(): void {
		if ( ! defined( 'LF_LANG' ) ) define( 'LF_LANG', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test-only constant; defining 'en' for this isolated subprocess.
		$GLOBALS['lf_test_is_singular'] = true;
		$this->make_post( 50, 'product' );
		$this->set_meta( 50, '_lf_lang', 'es' );
		\LfWcMocks::$queried_object_id = 50;

		$this->arm( 'core/navigation' );

		$this->assertSame( 'es', $this->pending() );
	}

	/**
	 * Non-navigation blocks on the frontend must not set pending, even when
	 * on a neutral URL with a translated queried object.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_frontend_arm_noop_for_non_navigation_block(): void {
		if ( ! defined( 'LF_LANG' ) ) define( 'LF_LANG', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test-only constant.
		$GLOBALS['lf_test_is_singular'] = true;
		$this->make_post( 51, 'product' );
		$this->set_meta( 51, '_lf_lang', 'es' );
		\LfWcMocks::$queried_object_id = 51;

		$this->arm( 'core/paragraph' );

		$this->assertNull( $this->pending() );
	}

	/**
	 * The arm must not fire for non-singular contexts (archives, home page).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_frontend_arm_noop_when_not_singular(): void {
		if ( ! defined( 'LF_LANG' ) ) define( 'LF_LANG', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test-only constant.
		$GLOBALS['lf_test_is_singular'] = false;
		$this->make_post( 52, 'product' );
		$this->set_meta( 52, '_lf_lang', 'es' );
		\LfWcMocks::$queried_object_id = 52;

		$this->arm( 'core/navigation' );

		$this->assertNull( $this->pending() );
	}

	/**
	 * When the queried post's _lf_lang equals the source language, the URL is
	 * not language-neutral in the relevant sense — no arm should fire.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_frontend_arm_noop_when_queried_object_lang_is_source(): void {
		if ( ! defined( 'LF_LANG' ) ) define( 'LF_LANG', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test-only constant.
		$GLOBALS['lf_test_is_singular'] = true;
		$this->make_post( 53, 'product' );
		$this->set_meta( 53, '_lf_lang', 'en' ); // same as source
		\LfWcMocks::$queried_object_id = 53;

		$this->arm( 'core/navigation' );

		$this->assertNull( $this->pending() );
	}

	/**
	 * Posts with no _lf_lang meta at all must not trigger the arm.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_frontend_arm_noop_when_queried_object_has_no_lf_lang(): void {
		if ( ! defined( 'LF_LANG' ) ) define( 'LF_LANG', 'en' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- test-only constant.
		$GLOBALS['lf_test_is_singular'] = true;
		$this->make_post( 54, 'product' ); // no _lf_lang set
		\LfWcMocks::$queried_object_id = 54;

		$this->arm( 'core/navigation' );

		$this->assertNull( $this->pending() );
	}

	// =========================================================================
	// arm_page_list_lang_filter() — canvas case 1 (core/navigation with ref)
	// =========================================================================

	/**
	 * Canvas path: a core/navigation block with a valid ref.  The nav post has
	 * an explicit _lf_lang meta → pending is set to that language.
	 */
	public function test_canvas_case1_with_ref_sets_pending_via_nav_meta(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 10, 'navigation-main', 'ca' );

		$this->arm( 'core/navigation', [ 'ref' => 10 ] );

		$this->assertSame( 'ca', $this->pending() );
	}

	/**
	 * Canvas path: nav post has no _lf_lang meta but the slug ends with a
	 * recognised language suffix → resolve_nav_lang() returns that language.
	 */
	public function test_canvas_case1_with_ref_resolves_nav_lang_via_slug_suffix(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 11, 'navigation-fr' ); // no lang meta

		$this->arm( 'core/navigation', [ 'ref' => 11 ] );

		$this->assertSame( 'fr', $this->pending() );
	}

	/**
	 * Canvas path: nav post has no _lf_lang meta and no recognised slug suffix
	 * → resolve_nav_lang() falls back to the source language.
	 */
	public function test_canvas_case1_with_ref_falls_back_to_source_when_no_meta_and_no_slug(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 12, 'my-custom-nav' ); // no lang meta, no recognisable suffix

		$this->arm( 'core/navigation', [ 'ref' => 12 ] );

		$this->assertSame( 'en', $this->pending() ); // source language
	}

	// =========================================================================
	// arm_page_list_lang_filter() — canvas case 1, ref=0 (get_posts fallback)
	// =========================================================================

	/**
	 * When the navigation block carries no ref (ref=0), the arm falls back to
	 * get_posts() for the latest wp_navigation post.
	 */
	public function test_canvas_case1_without_ref_falls_back_to_latest_nav_post(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 20, 'navigation-de', 'de' );

		$this->arm( 'core/navigation', [ 'ref' => 0 ] );

		$this->assertSame( 'de', $this->pending() );
	}

	/**
	 * When no wp_navigation posts exist at all, the arm returns $pre_render
	 * unchanged and leaves pending null.
	 */
	public function test_canvas_case1_without_ref_no_nav_posts_returns_pre_render_unchanged(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// LfWcMocks::$posts is empty — get_posts() returns [].

		$sentinel = new \stdClass();
		$result   = $this->arm( 'core/navigation', [], $sentinel );

		$this->assertSame( $sentinel, $result,
			'arm must return $pre_render unchanged when no nav post is found.' );
		$this->assertNull( $this->pending() );
	}

	// =========================================================================
	// arm_page_list_lang_filter() — canvas case 2 (core/page-list)
	// =========================================================================

	/**
	 * Canvas case 2: core/page-list block with a postId GET param pointing to a
	 * wp_navigation post → pending is resolved from that post's _lf_lang.
	 */
	public function test_canvas_case2_page_list_resolves_from_postid_get_param(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['postId'] = '30'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 30, 'navigation-es', 'es' );

		$this->arm( 'core/page-list' );

		$this->assertSame( 'es', $this->pending() );
	}

	/**
	 * Canvas case 2: the post_id (underscore variant) GET param is tried when
	 * postId is absent.
	 */
	public function test_canvas_case2_page_list_resolves_from_post_id_get_param(): void {
		$_GET['canvas']  = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['post_id'] = '31'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 31, 'navigation-ca', 'ca' );

		$this->arm( 'core/page-list' );

		$this->assertSame( 'ca', $this->pending() );
	}

	/**
	 * current_navigation_post_id(): the REST route is parsed from
	 * $GLOBALS['wp']->query_vars['rest_route'].  This is the primary path when
	 * the Site Editor fetches a navigation post via the REST API.
	 */
	public function test_canvas_case2_page_list_resolves_from_rest_route_in_wp_global(): void {
		$_GET['canvas']                      = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$GLOBALS['wp']->query_vars           = [ 'rest_route' => '/wp/v2/navigation/40' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->make_nav_post( 40, 'navigation-de', 'de' );

		$this->arm( 'core/page-list' );

		$this->assertSame( 'de', $this->pending() );
	}

	/**
	 * current_navigation_post_id() last-resort: global $post is a wp_navigation
	 * post → ID is returned and pending is resolved accordingly.
	 */
	public function test_canvas_case2_page_list_resolves_from_global_post_fallback(): void {
		$_GET['canvas']  = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 41, 'navigation-fr', 'fr' );
		$GLOBALS['post'] = \LfWcMocks::$posts[41]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->arm( 'core/page-list' );

		$this->assertSame( 'fr', $this->pending() );
	}

	/**
	 * When no $_GET params, no global $post, and no REST route are present,
	 * current_navigation_post_id() returns 0 and pending stays null.
	 */
	public function test_canvas_case2_page_list_pending_null_when_no_candidates(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// No candidates — empty $_GET (other than canvas), no global $post.

		$this->arm( 'core/page-list' );

		$this->assertNull( $this->pending() );
	}

	// =========================================================================
	// filter_page_list_frontend() — consume-immediately in admin context
	// =========================================================================

	/**
	 * When the filter is called from an admin context (is_admin() = true),
	 * the pending language must be consumed immediately after the first call so
	 * it cannot bleed into subsequent unrelated get_pages() invocations.
	 */
	public function test_filter_page_list_frontend_consumes_pending_immediately_in_admin(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 50, 'navigation-es', 'es' );
		$this->arm( 'core/navigation', [ 'ref' => 50 ] );
		$this->assertSame( 'es', $this->pending(), 'precondition: pending must be set after arm' );

		\LfWcMocks::$is_admin = true;
		$pages = [
			$this->make_page_post( 1, 'es' ),
			$this->make_page_post( 2, 'en' ),
		];

		$filtered = $this->qf->filter_page_list_frontend( $pages, [] );

		$this->assertNull( $this->pending(),
			'pending must be cleared after first filter call in admin context' );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 1, $filtered[0]->ID );
	}

	// =========================================================================
	// clear_nav_lang_after_render()
	// =========================================================================

	/**
	 * core/navigation render completion must clear the pending language.
	 */
	public function test_clear_nav_lang_clears_pending_for_navigation_block(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 60, 'navigation-es', 'es' );
		$this->arm( 'core/navigation', [ 'ref' => 60 ] );
		$this->assertNotNull( $this->pending(), 'precondition: pending must be set' );

		$this->qf->clear_nav_lang_after_render( '', [ 'blockName' => 'core/navigation' ] );

		$this->assertNull( $this->pending() );
	}

	/**
	 * core/page-list render completion must also clear the pending language
	 * (handles case 2 where there is no outer navigation wrapper).
	 */
	public function test_clear_nav_lang_clears_pending_for_page_list_block(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['postId'] = '61'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 61, 'navigation-ca', 'ca' );
		$this->arm( 'core/page-list' );
		$this->assertNotNull( $this->pending(), 'precondition: pending must be set' );

		$this->qf->clear_nav_lang_after_render( '', [ 'blockName' => 'core/page-list' ] );

		$this->assertNull( $this->pending() );
	}

	/**
	 * Blocks other than core/navigation and core/page-list must not clear the
	 * pending language.
	 */
	public function test_clear_nav_lang_does_not_clear_pending_for_other_blocks(): void {
		$_GET['canvas'] = 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->make_nav_post( 62, 'navigation-de', 'de' );
		$this->arm( 'core/navigation', [ 'ref' => 62 ] );
		$this->assertNotNull( $this->pending(), 'precondition: pending must be set' );

		$this->qf->clear_nav_lang_after_render( '', [ 'blockName' => 'core/paragraph' ] );

		$this->assertSame( 'de', $this->pending(),
			'core/paragraph must not clear the pending language' );
	}
}
