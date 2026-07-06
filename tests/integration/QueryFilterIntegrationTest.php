<?php
/**
 * Integration tests for QueryFilter query-cycle methods.
 *
 * Covered here:
 *   handle_parse_query()  — main-query guard, is_system_request guard, is_admin guard,
 *                           LF_LANG not defined guard, lang query-var set, search flags
 *   handle_pre_get_posts() admin branch — WC non-content skip, WC content skip when no
 *                           user-meta filter, lang filter applied, outdated filter double clause
 *   handle_pre_get_posts() frontend branch — static front page (show_on_front='page')
 *                           adds no _lf_lang clause; latest-posts front
 *                           (show_on_front='posts', "Your latest posts") DOES add the
 *                           clause, since is_front_page() is true there too but the
 *                           query is a normal posts listing, not a singular Page lookup.
 *   query()               — LF_LANG guard, already-constrained skip
 *   query_fallback()      — OR clause with active lang + source
 *
 * Not covered here (covered in unit tests):
 *   arm_page_list_lang_filter(), resolve_nav_lang(), current_navigation_post_id(),
 *   filter_page_list_frontend(), clear_nav_lang_after_render() — QueryFilterArmTest.
 *   handle_secondary_pre_get_posts() — SecondaryQueryFilterIntegrationTest.
 *
 * Strategy:
 *   • handle_parse_query() and handle_pre_get_posts() are public and called directly.
 *   • A bare new WP_Query() has is_main_query() = false by default; tests that need
 *     a main query set $GLOBALS['wp_the_query'] = $q and restore it in tearDown.
 *   • LF_LANG is defined by the Router bootstrap as 'en' in wp-env CLI mode.
 *   • Admin branch: $GLOBALS['current_screen'] is set to a minimal object with
 *     in_admin() method so is_admin() returns true; reversed in tearDown.
 *   • User-meta filter: set via update_user_meta() on the test user ID from
 *     wp_get_current_user()->ID; deleted in tearDown.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Rewrite\QueryFilter;
use LinguaForge\Router\Router;
use WP_Query;
use WP_UnitTestCase;

final class QueryFilterIntegrationTest extends WP_UnitTestCase {

	/** @var int WP user ID used for admin-branch tests that need user-meta. */
	private int $admin_user_id = 0;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		update_option( 'linguaforge_primary_language', 'en',   false );
		update_option( 'linguaforge_routing_mode',     'path', false );
		$_GET  = []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup only.
		$_SERVER['REQUEST_URI'] = '/en/sample-page/';

		// Create a real administrator user so user-meta operations (lf_lang_filter,
		// lf_outdated_filter) have a valid user ID to write to.  Stays current for
		// the duration of each test; wp_set_current_user(0) is restored in tearDown.
		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );
	}

	protected function tearDown(): void {
		// Restore main query pointer if a test replaced it.
		unset( $GLOBALS['wp_the_query_backup_qftest'] );

		// Remove any faked admin screen.
		unset( $GLOBALS['current_screen'] );

		// Clear test user-meta and log out.
		delete_user_meta( $this->admin_user_id, 'lf_lang_filter' );
		delete_user_meta( $this->admin_user_id, 'lf_outdated_filter' );
		wp_set_current_user( 0 );

		// Restore the GET superglobal.
		$_GET = []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test teardown only.
		$_SERVER['REQUEST_URI'] = '/';

		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function filter(): QueryFilter {
		return Router::get_instance()->query_filter;
	}

	/** Build a WP_Query and make it the main query for this request. */
	private function make_main_query( array $vars = [] ): WP_Query {
		$q = new WP_Query( $vars );
		// Back up current main-query pointer and replace with our instance.
		$GLOBALS['wp_the_query_backup_qftest'] = $GLOBALS['wp_the_query'] ?? null;
		$GLOBALS['wp_the_query']               = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $q;
	}

	/** Restore the main query pointer set by make_main_query(). */
	private function restore_main_query(): void {
		if ( isset( $GLOBALS['wp_the_query_backup_qftest'] ) ) {
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_the_query_backup_qftest']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			unset( $GLOBALS['wp_the_query_backup_qftest'] );
		}
	}

	/** Make is_admin() return true by seeding a minimal screen object. */
	private function fake_admin(): void {
		$GLOBALS['current_screen'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			public function in_admin(): bool { return true; }
		};
	}

	// =========================================================================
	// handle_parse_query() — main-query / guard branches
	// =========================================================================

	/**
	 * Non-main queries must be ignored — lang query-var must not be set.
	 */
	public function test_handle_parse_query_ignores_non_main_query(): void {
		$q = new WP_Query(); // is_main_query() = false (wp_the_query is different)
		$this->filter()->handle_parse_query( $q );

		$this->assertSame( '', $q->get( 'lang' ) );
	}

	/**
	 * When LF_LANG is defined (set by Router bootstrap as 'en'), the main query
	 * must receive lang='en'.
	 */
	public function test_handle_parse_query_sets_lang_on_main_query(): void {
		$q = $this->make_main_query();
		$this->filter()->handle_parse_query( $q );
		$this->restore_main_query();

		$this->assertSame( LF_LANG, $q->get( 'lang' ) );
	}

	/**
	 * A GET search parameter must cause is_search and !is_home to be set on the
	 * main query so language-aware search results render correctly.
	 */
	public function test_handle_parse_query_sets_search_flags_when_s_param_present(): void {
		$_GET['s'] = 'hello'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test setup; no data modified.
		$q = $this->make_main_query();
		$this->filter()->handle_parse_query( $q );
		$this->restore_main_query();

		$this->assertTrue( $q->is_search );
		$this->assertFalse( $q->is_home );
	}

	/**
	 * Admin requests must be ignored by handle_parse_query — lang must not be set.
	 */
	public function test_handle_parse_query_ignores_admin_context(): void {
		$this->fake_admin();
		$q = $this->make_main_query();
		$this->filter()->handle_parse_query( $q );
		$this->restore_main_query();

		$this->assertSame( '', $q->get( 'lang' ) );
	}

	// =========================================================================
	// handle_pre_get_posts() — admin branch
	// =========================================================================

	/**
	 * Admin main query targeting a WC non-content post type (shop_order) must be
	 * skipped — no _lf_lang meta_query clause must be added.
	 */
	public function test_handle_pre_get_posts_admin_skips_wc_non_content_type(): void {
		$this->fake_admin();
		$_GET['post_type'] = 'shop_order'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = $this->make_main_query();
		$this->filter()->handle_pre_get_posts( $q );
		$this->restore_main_query();

		$meta_query = (array) $q->get( 'meta_query' );
		$keys       = array_column( array_filter( $meta_query, 'is_array' ), 'key' );
		$this->assertNotContains( '_lf_lang', $keys );
	}

	/**
	 * Admin main query for a WC content type (product) without a user-meta language
	 * filter must also be skipped — WC has its own query pipeline.
	 */
	public function test_handle_pre_get_posts_admin_skips_wc_content_without_lang_filter(): void {
		$this->fake_admin();
		$_GET['post_type'] = 'product'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q = $this->make_main_query();
		$this->filter()->handle_pre_get_posts( $q );
		$this->restore_main_query();

		$meta_query = (array) $q->get( 'meta_query' );
		$keys       = array_column( array_filter( $meta_query, 'is_array' ), 'key' );
		$this->assertNotContains( '_lf_lang', $keys );
	}

	/**
	 * Admin main query for a regular post type with an active lf_lang_filter user-meta
	 * must receive a _lf_lang meta_query clause with the persisted filter value.
	 */
	public function test_handle_pre_get_posts_admin_applies_lang_filter_from_user_meta(): void {
		$this->fake_admin();
		update_user_meta( get_current_user_id(), 'lf_lang_filter', 'es' );

		$q = $this->make_main_query();
		$this->filter()->handle_pre_get_posts( $q );
		$this->restore_main_query();

		$meta_query = (array) $q->get( 'meta_query' );
		$clauses    = array_filter( $meta_query, 'is_array' );
		$found      = false;
		foreach ( $clauses as $clause ) {
			if ( ( $clause['key'] ?? '' ) === '_lf_lang' && ( $clause['value'] ?? '' ) === 'es' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, '_lf_lang=es clause must be present in admin query with lang filter' );
	}

	/**
	 * Admin main query with the outdated filter active must receive two meta_query
	 * clauses: _lf_lang != source AND _lf_translation_source_updated_at NOT EXISTS.
	 */
	public function test_handle_pre_get_posts_admin_applies_outdated_filter_double_clause(): void {
		$this->fake_admin();
		update_user_meta( get_current_user_id(), 'lf_outdated_filter', '1' );
		$_GET['lf_outdated_filter'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$q = $this->make_main_query();
		$this->filter()->handle_pre_get_posts( $q );
		$this->restore_main_query();

		$meta_query = (array) $q->get( 'meta_query' );
		$clauses    = array_filter( $meta_query, 'is_array' );

		$has_lang_ne = false;
		$has_not_exists = false;
		foreach ( $clauses as $clause ) {
			if ( ( $clause['key'] ?? '' ) === '_lf_lang' && strtoupper( $clause['compare'] ?? '' ) === '!=' ) {
				$has_lang_ne = true;
			}
			if ( ( $clause['key'] ?? '' ) === '_lf_translation_source_updated_at'
				&& strtoupper( $clause['compare'] ?? '' ) === 'NOT EXISTS' ) {
				$has_not_exists = true;
			}
		}

		$this->assertTrue( $has_lang_ne,    '_lf_lang != source clause must be present for outdated filter' );
		$this->assertTrue( $has_not_exists, '_lf_translation_source_updated_at NOT EXISTS clause must be present' );
	}

	// =========================================================================
	// handle_pre_get_posts() — frontend: static page vs latest-posts front
	// =========================================================================

	/**
	 * Static front page (show_on_front = 'page'): the front-page query is a
	 * singular Page lookup with nothing to scope by language — no _lf_lang
	 * meta_query clause must be added to the main query. This is the
	 * pre-existing behaviour, preserved by the fix below.
	 */
	public function test_pre_get_posts_static_front_page_adds_no_lang_clause(): void {
		$front_id = (int) self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );

		$this->go_to( '/' );

		$this->assertTrue( $GLOBALS['wp_query']->is_front_page(),
			'Sanity check: / must resolve to the front page.' );

		$meta_query = (array) $GLOBALS['wp_query']->get( 'meta_query' );
		$keys       = array_column( array_filter( $meta_query, 'is_array' ), 'key' );
		$this->assertNotContains( '_lf_lang', $keys,
			'A static front page must not receive a _lf_lang meta_query clause.' );

		delete_option( 'show_on_front' );
		delete_option( 'page_on_front' );
	}

	/**
	 * Latest-posts front (show_on_front = 'posts', Settings → Reading → "Your
	 * latest posts"): is_front_page() is ALSO true here, but the query is a
	 * normal posts listing and must receive the same _lf_lang meta_query
	 * clause as any other archive/home query. Regression test for the
	 * language-mixing bug fixed in handle_pre_get_posts() — previously EVERY
	 * language's posts appeared together on `/{lang}/` because the method
	 * returned before reaching the is_archive()/is_home() branch below.
	 */
	public function test_pre_get_posts_latest_posts_front_adds_lang_clause(): void {
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );

		$this->go_to( '/' );

		$this->assertTrue( $GLOBALS['wp_query']->is_front_page(),
			'Sanity check: / must resolve to the front page.' );
		$this->assertTrue( $GLOBALS['wp_query']->is_home(),
			'Sanity check: latest-posts front page must also be the posts/home query.' );

		$meta_query = (array) $GLOBALS['wp_query']->get( 'meta_query' );
		$found      = false;
		foreach ( array_filter( $meta_query, 'is_array' ) as $clause ) {
			if ( ( $clause['key'] ?? '' ) === '_lf_lang' && ( $clause['value'] ?? '' ) === LF_LANG ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found,
			'A latest-posts front page must receive a _lf_lang meta_query clause, same as any other archive/home query.' );

		delete_option( 'show_on_front' );
	}

	// =========================================================================
	// query() — LF_LANG guard + already-constrained skip
	// =========================================================================

	/**
	 * query() must append a _lf_lang=LF_LANG clause when no _lf_lang constraint
	 * is already present in the args.
	 */
	public function test_query_appends_lf_lang_clause_when_not_already_constrained(): void {
		$q = $this->filter()->query( [ 'post_type' => 'post', 'posts_per_page' => 0 ] );

		$meta_query = (array) $q->get( 'meta_query' );
		$keys       = array_column( array_filter( $meta_query, 'is_array' ), 'key' );
		$this->assertContains( '_lf_lang', $keys );
	}

	/**
	 * query() must NOT add a second _lf_lang clause when the caller already
	 * supplied one — prevents duplicate meta_query joins.
	 */
	public function test_query_skips_clause_when_lf_lang_already_constrained(): void {
		$args = [
			'post_type'  => 'post',
			'posts_per_page' => 0,
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test exercising the already-constrained guard; meta_query is intentionally present.
				[ 'key' => '_lf_lang', 'value' => 'ca' ],
			],
		];
		$q = $this->filter()->query( $args );

		$meta_query = (array) $q->get( 'meta_query' );
		$lf_clauses = array_filter(
			$meta_query,
			fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);
		$this->assertCount( 1, $lf_clauses,
			'query() must not duplicate an existing _lf_lang clause' );
	}

	// =========================================================================
	// query_fallback() — OR clause covering active lang + source
	// =========================================================================

	/**
	 * query_fallback() must add an OR meta_query clause that accepts both the
	 * active language and the source language so partially-translated sites
	 * always show some content.
	 */
	public function test_query_fallback_adds_or_clause_for_active_and_source_lang(): void {
		$q = $this->filter()->query_fallback( [ 'post_type' => 'post', 'posts_per_page' => 0 ] );

		$meta_query = (array) $q->get( 'meta_query' );
		$or_clause  = null;
		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && strtoupper( $clause['relation'] ?? '' ) === 'OR' ) {
				$or_clause = $clause;
				break;
			}
		}

		$this->assertNotNull( $or_clause, 'query_fallback() must add an OR meta_query clause' );

		$values = array_column(
			array_filter( $or_clause, 'is_array' ),
			'value'
		);
		$source = Router::get_instance()->context->source_language();
		$this->assertContains( LF_LANG, $values, 'OR clause must include the active language' );
		$this->assertContains( $source,  $values, 'OR clause must include the source language' );
	}
}
