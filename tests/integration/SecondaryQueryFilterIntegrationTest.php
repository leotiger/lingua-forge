<?php
/**
 * Integration tests for QueryFilter::handle_secondary_pre_get_posts().
 *
 * The method injects a `_lf_lang = LF_LANG` meta constraint on every secondary
 * (non-main) frontend WP_Query that targets non-WC post types.  It is the generic
 * equivalent of CatalogQuery::apply_language_filter_to_secondary_query(), which
 * covers `post_type = 'product'` specifically.
 *
 * Strategy:
 *   • handle_secondary_pre_get_posts() is public; called directly via
 *     Router::get_instance()->query_filter.
 *   • A bare new WP_Query() (no args) has is_main_query() = false because the
 *     global $wp_the_query is a different instance.  The main-query test sets
 *     $GLOBALS['wp_the_query'] to the test query and restores it afterwards.
 *   • LF_LANG is defined by the Router bootstrap as 'en' in wp-env CLI mode.
 *     Tests work with this actual value; no override is attempted.
 *   • is_admin() returns false in the WP integration test runner (non-admin
 *     context) — the admin-skip branch is a trivial one-liner mirroring
 *     CatalogQuery; it is not covered here.
 *
 * Coverage:
 *   1. Happy path — non-WC post_type string → _lf_lang clause injected.
 *   2. Main query (is_main_query() = true) → no clause injected.
 *   3. post_type = 'any' → no clause injected.
 *   4. WC post_type 'product' → no clause injected (CatalogQuery handles it).
 *   5. WC post_type 'shop_order' → no clause injected.
 *   6. Custom exclusion via linguaforge_secondary_query_excluded_post_types → no clause.
 *   7. Double-application guard — _lf_lang already in meta_query → not duplicated.
 *   8. Empty post_type string → treated as 'post'; _lf_lang clause injected.
 *   9. Array post_type — all non-WC → clause injected.
 *  10. Array post_type including a WC type → no clause injected.
 *  11. fields='ids' → skip (internal ID-lookup guard).
 *  12. wp_navigation → no clause injected (system type; prevents WP_Navigation_Fallback cascade).
 *  13. nav_menu_item → no clause injected (system type; classic menu queries must be unfiltered).
 *  14. page post_type + pending_page_list_lang set → no clause (filter_page_list_frontend handles it).
 *  15. wpcf7_contact_form → no clause (built-in exclusion; no manual filter required).
 *  16. linguaforge_secondary_query_excluded_types option → listed types excluded; unlisted types still filtered.
 *  17. builtin_excluded_post_types() — always contains wpcf7_contact_form; merges option; deduplicates.
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

final class SecondaryQueryFilterIntegrationTest extends WP_UnitTestCase {

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		update_option( 'linguaforge_primary_language', 'en', false );
		update_option( 'linguaforge_routing_mode',     'path', false );
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_secondary_query_excluded_post_types' );
		delete_option( 'linguaforge_secondary_query_excluded_types' );
		parent::tearDown();
	}

	// =========================================================================
	// Helper
	// =========================================================================

	private function filter(): QueryFilter {
		return Router::get_instance()->query_filter;
	}

	/**
	 * Returns a fresh bare WP_Query — is_main_query() is false because the
	 * global $wp_the_query points to a different instance.
	 */
	private function secondary_query( array $vars = [] ): WP_Query {
		$q = new WP_Query();
		foreach ( $vars as $key => $value ) {
			$q->set( $key, $value );
		}
		return $q;
	}

	// =========================================================================
	// 1. Happy path — string post_type, non-WC → clause injected
	// =========================================================================

	/**
	 * A secondary query targeting a non-WC post type must receive the
	 * `_lf_lang = LF_LANG` meta constraint.
	 */
	public function test_non_wc_post_type_receives_lang_clause(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$q = $this->secondary_query( [ 'post_type' => 'lf_event' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query' );
		$this->assertIsArray( $meta_query );

		$lf_clauses = array_values( array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		) );

		$this->assertCount( 1, $lf_clauses, '_lf_lang clause must be injected for non-WC post types.' );
		$this->assertSame( LF_LANG, $lf_clauses[0]['value'] );
	}

	// =========================================================================
	// 2. Main query → skip
	// =========================================================================

	/**
	 * When the query is the main WordPress query (is_main_query() = true),
	 * the method must return without injecting any constraint —
	 * handle_pre_get_posts() already handles main queries.
	 */
	public function test_main_query_is_skipped(): void {
		global $wp_the_query;
		$saved = $wp_the_query;

		$q = $this->secondary_query( [ 'post_type' => 'lf_event' ] );

		// Make this query appear as the main query.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: simulating is_main_query()=true in a controlled test; restored in finally block.
		$wp_the_query = $q;

		try {
			$this->filter()->handle_secondary_pre_get_posts( $q );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring pre-test value.
			$wp_the_query = $saved;
		}

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, 'Main query must not receive a secondary _lf_lang injection.' );
	}

	// =========================================================================
	// 3. post_type = 'any' → skip
	// =========================================================================

	/**
	 * Queries with post_type = 'any' aggregate multiple post types including
	 * internal WC types.  The method must leave them unfiltered.
	 */
	public function test_post_type_any_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'any' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "post_type='any' must not receive a secondary _lf_lang injection." );
	}

	// =========================================================================
	// 4. WC post_type 'product' → skip
	// =========================================================================

	/**
	 * 'product' queries are handled by CatalogQuery.  The method must exit
	 * immediately when it encounters a WC post type to avoid double-injection
	 * or logic conflicts.
	 */
	public function test_wc_product_post_type_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'product' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "'product' queries must not be handled by the generic secondary filter." );
	}

	// =========================================================================
	// 5. WC post_type 'shop_order' → skip
	// =========================================================================

	/**
	 * WC non-content post types (shop_order, shop_coupon, etc.) must also be
	 * excluded — they have no _lf_lang meta and filtering them would return 0 rows.
	 */
	public function test_wc_order_post_type_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'shop_order' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "'shop_order' queries must not be handled by the generic secondary filter." );
	}

	// =========================================================================
	// 6. Custom exclusion via filter → skip
	// =========================================================================

	/**
	 * Third-party code can exclude additional post types via the
	 * `linguaforge_secondary_query_excluded_post_types` filter.
	 */
	public function test_custom_filter_exclusion_is_respected(): void {
		add_filter(
			'linguaforge_secondary_query_excluded_post_types',
			static function ( array $excluded ): array {
				$excluded[] = 'lf_event';
				return $excluded;
			}
		);

		$q = $this->secondary_query( [ 'post_type' => 'lf_event' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, 'Post types added to linguaforge_secondary_query_excluded_post_types must be skipped.' );
	}

	// =========================================================================
	// 7. Double-application guard
	// =========================================================================

	/**
	 * If _lf_lang is already in the meta_query (e.g. set by the caller via
	 * QueryFilter::query()), the method must not inject a duplicate clause.
	 */
	public function test_double_application_guard_prevents_duplication(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$q = $this->secondary_query( [
			'post_type'  => 'lf_event',
			'meta_query' => [ [ 'key' => '_lf_lang', 'value' => LF_LANG ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- test-only; intentionally pre-setting the meta_query to verify the double-application guard.
		] );

		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query' );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);

		$this->assertCount( 1, $lf_clauses, '_lf_lang clause must not be duplicated.' );
	}

	// =========================================================================
	// 8. Empty post_type string → treated as 'post'
	// =========================================================================

	/**
	 * When post_type is an empty string WordPress defaults to querying 'post'.
	 * The method normalises this to ['post'] and applies the language constraint.
	 */
	public function test_empty_post_type_defaults_to_post_and_receives_lang_clause(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$q = $this->secondary_query( [ 'post_type' => '' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);

		$this->assertNotEmpty( $lf_clauses, 'Empty post_type must be treated as post and receive the _lf_lang clause.' );
	}

	// =========================================================================
	// 9. Array post_type — all non-WC → clause injected
	// =========================================================================

	/**
	 * When post_type is an array of non-WC types the constraint must be injected.
	 */
	public function test_array_post_type_all_non_wc_receives_lang_clause(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$q = $this->secondary_query( [ 'post_type' => [ 'lf_event', 'lf_news' ] ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);

		$this->assertNotEmpty( $lf_clauses, 'Array post_type with no WC types must receive the _lf_lang clause.' );
	}

	// =========================================================================
	// 10. Array post_type including a WC type → skip
	// =========================================================================

	/**
	 * If the post_type array contains even one WC type, the method must exit
	 * without injecting a constraint — CatalogQuery or WC internals handle it.
	 */
	public function test_array_post_type_with_wc_type_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => [ 'lf_event', 'product' ] ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, 'Array post_type containing a WC type must not receive a secondary _lf_lang injection.' );
	}

	// =========================================================================
	// 11. fields='ids' → skip (internal ID-lookup guard)
	// =========================================================================

	/**
	 * Queries with fields='ids' or 'id=>parent' are internal infrastructure
	 * lookups (e.g. WcPageBridge translates page IDs via get_posts(['fields'=>'ids'])).
	 * They must not receive a language constraint — they query across all
	 * languages by design.
	 */
	public function test_fields_ids_query_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'page', 'fields' => 'ids' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "fields='ids' queries must not receive a secondary _lf_lang injection." );
	}

	/**
	 * @see test_fields_ids_query_is_skipped
	 */
	public function test_fields_id_parent_query_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'page', 'fields' => 'id=>parent' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "fields='id=>parent' queries must not receive a secondary _lf_lang injection." );
	}

	// =========================================================================
	// 12. wp_navigation → skip (system type)
	// =========================================================================

	/**
	 * wp_navigation posts are WordPress system infrastructure — they never carry
	 * _lf_lang meta by default. Injecting a meta constraint here causes
	 * WP_Navigation_Fallback::get_fallback() to find zero results and create a
	 * brand-new navigation post from the latest classic menu, which manifests
	 * as unexpected "new" navigation items on the frontend.
	 */
	public function test_wp_navigation_post_type_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'wp_navigation' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "'wp_navigation' queries must not receive a secondary _lf_lang injection." );
	}

	// =========================================================================
	// 13. nav_menu_item → skip (system type)
	// =========================================================================

	/**
	 * Classic nav menu item queries must pass through unfiltered — nav_menu_item
	 * posts do not carry _lf_lang meta and URL translation is handled separately
	 * by Redirector::translate_menu_items() on the wp_nav_menu_objects filter.
	 */
	public function test_nav_menu_item_post_type_is_skipped(): void {
		$q = $this->secondary_query( [ 'post_type' => 'nav_menu_item' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, "'nav_menu_item' queries must not receive a secondary _lf_lang injection." );
	}

	// =========================================================================
	// 15. wpcf7_contact_form → skip (built-in exclusion, no manual filter needed)
	// =========================================================================

	/**
	 * wpcf7_contact_form is hard-coded in builtin_excluded_post_types() and must
	 * never receive a _lf_lang meta constraint.  No manual filter registration
	 * is required — the method is wired into the hook by register_hooks().
	 *
	 * Root cause this covers: CF7 resolves non-numeric shortcode IDs
	 * (e.g. id='b657a7a') via get_posts() against wpcf7_contact_form.
	 * CF7 form posts carry no _lf_lang meta, so injecting the constraint
	 * returned zero results and silently broke form rendering.
	 */
	public function test_wpcf7_contact_form_is_excluded_automatically(): void {
		$q = $this->secondary_query( [ 'post_type' => 'wpcf7_contact_form' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty(
			$meta_query,
			"'wpcf7_contact_form' must be excluded from secondary-query language filtering automatically."
		);
	}

	// =========================================================================
	// 16. Option-saved post types are excluded
	// =========================================================================

	/**
	 * Post type slugs stored in linguaforge_secondary_query_excluded_types
	 * (Settings → Router → Excluded post types) must be excluded from the
	 * secondary-query language filter without any manual filter hook.
	 */
	public function test_option_saved_post_types_are_excluded(): void {
		update_option( 'linguaforge_secondary_query_excluded_types', 'acf_field_group,nf_sub', false );

		foreach ( [ 'acf_field_group', 'nf_sub' ] as $type ) {
			$q = $this->secondary_query( [ 'post_type' => $type ] );
			$this->filter()->handle_secondary_pre_get_posts( $q );

			$meta_query = $q->get( 'meta_query', [] );
			$this->assertEmpty(
				$meta_query,
				"Post type '{$type}' saved in linguaforge_secondary_query_excluded_types must be excluded from secondary-query filtering."
			);
		}
	}

	/**
	 * A post type NOT in the option must still receive the constraint —
	 * the option excludes only what is listed.
	 */
	public function test_post_type_not_in_option_still_receives_lang_clause(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		update_option( 'linguaforge_secondary_query_excluded_types', 'acf_field_group', false );

		$q = $this->secondary_query( [ 'post_type' => 'lf_event' ] );
		$this->filter()->handle_secondary_pre_get_posts( $q );

		$meta_query = $q->get( 'meta_query', [] );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);

		$this->assertNotEmpty(
			$lf_clauses,
			"'lf_event' must still receive the _lf_lang clause when only 'acf_field_group' is in the exclusion option."
		);
	}

	// =========================================================================
	// 17. builtin_excluded_post_types() method — direct unit-style coverage
	// =========================================================================

	/**
	 * With no option set the method always returns at least wpcf7_contact_form.
	 */
	public function test_builtin_excluded_post_types_always_contains_wpcf7(): void {
		$result = $this->filter()->builtin_excluded_post_types( [] );

		$this->assertContains(
			'wpcf7_contact_form',
			$result,
			'builtin_excluded_post_types() must always include wpcf7_contact_form.'
		);
	}

	/**
	 * Types from the option are merged into the returned array.
	 */
	public function test_builtin_excluded_post_types_merges_option_values(): void {
		update_option( 'linguaforge_secondary_query_excluded_types', 'acf_field_group,nf_sub', false );

		$result = $this->filter()->builtin_excluded_post_types( [] );

		$this->assertContains( 'wpcf7_contact_form', $result );
		$this->assertContains( 'acf_field_group',    $result );
		$this->assertContains( 'nf_sub',             $result );
	}

	/**
	 * Values already in the incoming $types array are not duplicated,
	 * including wpcf7_contact_form if a caller already added it.
	 */
	public function test_builtin_excluded_post_types_deduplicates(): void {
		update_option( 'linguaforge_secondary_query_excluded_types', 'wpcf7_contact_form,acf_field_group', false );

		$result = $this->filter()->builtin_excluded_post_types( [ 'wpcf7_contact_form' ] );

		$this->assertSame(
			count( $result ),
			count( array_unique( $result ) ),
			'builtin_excluded_post_types() must not contain duplicate slugs.'
		);
		$this->assertContains( 'wpcf7_contact_form', $result );
		$this->assertContains( 'acf_field_group',    $result );
	}

	// =========================================================================
	// 14. page post_type + navigation arm active → skip
	// =========================================================================

	/**
	 * WordPress 6.3+ routes get_pages() through WP_Query, so pre_get_posts fires
	 * for get_pages() calls made during navigation block rendering.  When the
	 * navigation arm is active (pending_page_list_lang is set by
	 * arm_page_list_lang_filter()), filter_page_list_frontend() handles language
	 * scoping for that get_pages() result.  Injecting a SQL meta_query here would
	 * filter out translated pages before filter_page_list_frontend sees them,
	 * causing the fallback path to show source-language pages on translated
	 * WooCommerce product pages.
	 */
	public function test_page_post_type_skipped_when_navigation_arm_is_active(): void {
		$filter = $this->filter();

		// Simulate arm_page_list_lang_filter() having set the pending language.
		$ref  = new \ReflectionClass( $filter );
		$prop = $ref->getProperty( 'pending_page_list_lang' );
		$prop->setAccessible( true );
		$prop->setValue( $filter, 'es' );

		$q = $this->secondary_query( [ 'post_type' => 'page' ] );
		$filter->handle_secondary_pre_get_posts( $q );

		// Restore pending to null so subsequent tests start clean.
		$prop->setValue( $filter, null );

		$meta_query = $q->get( 'meta_query', [] );
		$this->assertEmpty(
			$meta_query,
			"'page' queries must be skipped when pending_page_list_lang is set — filter_page_list_frontend handles language scoping."
		);
	}
}
