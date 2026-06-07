<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\CatalogQuery.
 *
 * CatalogQuery::apply_language_filter() adds a `_lf_lang` meta constraint to
 * every WooCommerce product query so that secondary queries (related products,
 * up-sells, cross-sells, widgets) are scoped to the active language.
 *
 * Tests are exercised by calling apply_language_filter() directly with a
 * WP_Query stub — no WordPress runtime needed.
 *
 * Coverage — apply_language_filter (WP_Query path):
 *   1. Happy path — _lf_lang clause appended to an empty meta_query.
 *   2. Happy path — _lf_lang appended alongside pre-existing clauses.
 *   3. Double-application guard — flat meta_query already has _lf_lang.
 *   4. Double-application guard — relation-wrapped meta_query has _lf_lang.
 *   5. Admin skip — is_admin() returns true → meta_query unchanged.
 *
 * Coverage — apply_language_filter_to_secondary_query (pre_get_posts path):
 *  10. Happy path — clause appended to a secondary product query.
 *  11. Main query skip — is_main_query() true → no clause added.
 *  12. Admin skip — is_admin() true → no clause added.
 *  13. Non-product post type — no clause added.
 *  14. Array post_type including 'product' — clause appended.
 *  15. Double-application guard — _lf_lang already present → not duplicated.
 *
 * Coverage — disable_product_grid_cache (BlocksWpQuery transient gate):
 *  16. Returns false (disables cache) when LF_LANG is active.
 *  (The LF_LANG-not-set pass-through cannot be unit-tested here — same
 *   reason as the LF_LANG guard note above; it is a trivial one-liner.)
 *
 * Coverage — apply_language_filter_to_secondary_query — effective_lang branch:
 *  17. is_singular('product') with a product whose _lf_lang differs from LF_LANG →
 *      effective_lang is taken from the product's own _lf_lang, not LF_LANG.
 *  18. is_singular true but product has no _lf_lang → effective_lang falls back to LF_LANG.
 *
 * Coverage — apply_language_filter_to_related_query (raw SQL path):
 *   6. Happy path — language INNER JOIN appended to an empty join fragment.
 *   7. Happy path — language JOIN appended alongside a pre-existing join.
 *   8. Admin skip — query returned unchanged on admin requests.
 *   9. Non-join fragments (fields / where / limits) are never mutated.
 *
 * Note: the `! defined('LF_LANG') || '' === LF_LANG` guard (REST/headless
 * safety valve) is not covered here — PHP constants cannot be undefined
 * between tests in the same process. The guard is a one-liner with no
 * branching complexity; correctness is self-evident from the source.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\CatalogQuery;

// WcUnitTestCase loads WcPolyfills and class-language-router; require_once is safe
// if another test file already pulled them in first.
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/CatalogQuery.php';

// Define LF_LANG once for the unit suite — simulates the router having
// resolved a language on a French frontend request.
defined( 'LF_LANG' ) || define( 'LF_LANG', 'fr' );

final class CatalogQueryTest extends WcUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		\LfWcMocks::reset(); // resets $is_admin to false (frontend)
	}

	// =========================================================================
	// 1. Happy path — clause appended to empty meta_query
	// =========================================================================

	public function test_appends_lf_lang_clause_when_meta_query_is_empty(): void {
		$query = new \WP_Query();

		CatalogQuery::apply_language_filter( $query );

		$meta_query = $query->get( 'meta_query' );
		$this->assertIsArray( $meta_query );
		$this->assertCount( 1, $meta_query );
		$this->assertSame( '_lf_lang', $meta_query[0]['key'] );
		$this->assertSame( LF_LANG,    $meta_query[0]['value'] );
	}

	// =========================================================================
	// 2. Happy path — clause appended alongside pre-existing clauses
	// =========================================================================

	public function test_appends_lf_lang_clause_alongside_existing_meta_clauses(): void {
		$query = new \WP_Query();
		$query->set( 'meta_query', [
			[ 'key' => '_price', 'value' => '10', 'compare' => '>=' ],
		] );

		CatalogQuery::apply_language_filter( $query );

		$meta_query = $query->get( 'meta_query' );
		$this->assertCount( 2, $meta_query );

		$keys = array_column( $meta_query, 'key' );
		$this->assertContains( '_lf_lang', $keys );
		$this->assertContains( '_price',   $keys );
	}

	// =========================================================================
	// 3. Double-application guard — flat meta_query already has _lf_lang
	// =========================================================================

	public function test_does_not_double_apply_when_lf_lang_already_present(): void {
		$query = new \WP_Query();
		$query->set( 'meta_query', [
			[ 'key' => '_lf_lang', 'value' => LF_LANG ],
		] );

		CatalogQuery::apply_language_filter( $query );

		$meta_query = $query->get( 'meta_query' );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);
		$this->assertCount( 1, $lf_clauses, '_lf_lang clause must not be duplicated.' );
	}

	// =========================================================================
	// 4. Double-application guard — relation-wrapped meta_query
	// =========================================================================

	public function test_does_not_double_apply_with_relation_wrapped_meta_query(): void {
		$query = new \WP_Query();
		$query->set( 'meta_query', [
			'relation' => 'AND',
			[ 'key' => '_lf_lang', 'value' => LF_LANG ],
			[ 'key' => '_stock_status', 'value' => 'instock' ],
		] );

		CatalogQuery::apply_language_filter( $query );

		$meta_query = $query->get( 'meta_query' );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);
		$this->assertCount( 1, $lf_clauses, '_lf_lang clause must not be duplicated in a relation-wrapped meta_query.' );
	}

	// =========================================================================
	// 5. Admin skip — is_admin() returns true
	// =========================================================================

	public function test_skips_when_is_admin(): void {
		\LfWcMocks::$is_admin = true;

		$query = new \WP_Query();

		CatalogQuery::apply_language_filter( $query );

		// get() with fallback [] — no meta_query key set means no clause was added.
		$meta_query = $query->get( 'meta_query', [] );
		$this->assertEmpty( $meta_query, 'No meta_query must be added on admin requests.' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Returns a minimal SQL fragment array matching WC_Product_Data_Store_CPT
	 * get_related_products_query() output.
	 */
	private function related_sql_query( string $existing_join = '' ): array {
		return [
			'fields' => 'SELECT DISTINCT ID FROM wp_posts p',
			'join'   => $existing_join,
			'where'  => "WHERE 1=1 AND p.post_status = 'publish' AND p.post_type = 'product'",
			'limits' => 'LIMIT 15',
		];
	}

	// =========================================================================
	// 6. Related-products: JOIN appended to empty join fragment
	// =========================================================================

	public function test_related_query_appends_language_join_when_join_is_empty(): void {
		$query  = $this->related_sql_query();
		$result = CatalogQuery::apply_language_filter_to_related_query( $query, 42 );

		$this->assertStringContainsString( 'pm_lf_lang',  $result['join'] );
		$this->assertStringContainsString( '_lf_lang',    $result['join'] );
		$this->assertStringContainsString( 'INNER JOIN',  $result['join'] );
		$this->assertStringContainsString( 'wp_postmeta', $result['join'] );
	}

	// =========================================================================
	// 7. Related-products: JOIN appended alongside a pre-existing join
	// =========================================================================

	public function test_related_query_appends_language_join_alongside_existing_join(): void {
		$existing = " LEFT JOIN ( SELECT object_id FROM wp_term_relationships ) AS exclude_join ON exclude_join.object_id = p.ID";
		$query    = $this->related_sql_query( $existing );
		$result   = CatalogQuery::apply_language_filter_to_related_query( $query, 42 );

		$this->assertStringContainsString( 'exclude_join', $result['join'], 'Pre-existing join must be preserved.' );
		$this->assertStringContainsString( 'pm_lf_lang',   $result['join'], 'Language join must be appended.' );
	}

	// =========================================================================
	// 8. Related-products: admin skip
	// =========================================================================

	public function test_related_query_unchanged_on_admin_request(): void {
		\LfWcMocks::$is_admin = true;

		$query  = $this->related_sql_query();
		$result = CatalogQuery::apply_language_filter_to_related_query( $query, 42 );

		$this->assertSame( '', $result['join'], 'Join must be untouched on admin requests.' );
	}

	// =========================================================================
	// 9. Related-products: only join is modified; other fragments unchanged
	// =========================================================================

	public function test_related_query_only_join_fragment_is_modified(): void {
		$query  = $this->related_sql_query();
		$result = CatalogQuery::apply_language_filter_to_related_query( $query, 42 );

		$this->assertSame( $query['fields'], $result['fields'], 'fields must not change.' );
		$this->assertSame( $query['where'],  $result['where'],  'where must not change.' );
		$this->assertSame( $query['limits'], $result['limits'], 'limits must not change.' );
	}

	// =========================================================================
	// 10. Secondary product query — clause appended
	// =========================================================================

	public function test_secondary_product_query_appends_lf_lang_clause(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'product' );
		// is_main_query() returns false (LfWcMocks::$is_main_query defaults to false)

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$meta_query = $query->get( 'meta_query' );
		$this->assertIsArray( $meta_query );
		$this->assertCount( 1, $meta_query );
		$this->assertSame( '_lf_lang', $meta_query[0]['key'] );
		$this->assertSame( LF_LANG,    $meta_query[0]['value'] );
	}

	// =========================================================================
	// 11. Main query skip — is_main_query() true
	// =========================================================================

	public function test_secondary_query_skips_when_is_main_query(): void {
		\LfWcMocks::$is_main_query = true;

		$query = new \WP_Query();
		$query->set( 'post_type', 'product' );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$this->assertEmpty( $query->get( 'meta_query', [] ), 'Main query must not be touched.' );
	}

	// =========================================================================
	// 12. Admin skip
	// =========================================================================

	public function test_secondary_query_skips_when_is_admin(): void {
		\LfWcMocks::$is_admin = true;

		$query = new \WP_Query();
		$query->set( 'post_type', 'product' );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$this->assertEmpty( $query->get( 'meta_query', [] ), 'Admin requests must not be filtered.' );
	}

	// =========================================================================
	// 13. Non-product post type — no clause added
	// =========================================================================

	public function test_secondary_query_skips_non_product_post_type(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'page' );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$this->assertEmpty( $query->get( 'meta_query', [] ), 'Non-product queries must not be filtered.' );
	}

	// =========================================================================
	// 14. Array post_type including 'product' — clause appended
	// =========================================================================

	public function test_secondary_query_handles_array_post_type_including_product(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', [ 'product', 'product_variation' ] );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$meta_query = $query->get( 'meta_query' );
		$this->assertIsArray( $meta_query );
		$keys = array_column( $meta_query, 'key' );
		$this->assertContains( '_lf_lang', $keys, 'Array post_type including product must receive the language clause.' );
	}

	// =========================================================================
	// 15. Double-application guard — _lf_lang already present
	// =========================================================================

	public function test_secondary_query_does_not_duplicate_lf_lang_clause(): void {
		$query = new \WP_Query();
		$query->set( 'post_type', 'product' );
		$query->set( 'meta_query', [
			[ 'key' => '_lf_lang', 'value' => LF_LANG ],
		] );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$meta_query = $query->get( 'meta_query' );
		$lf_clauses = array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		);
		$this->assertCount( 1, $lf_clauses, '_lf_lang clause must not be duplicated.' );
	}

	// =========================================================================
	// 16. Product-grid cache disabled when LF_LANG is active
	// =========================================================================

	public function test_disable_product_grid_cache_returns_false_when_lf_lang_is_set(): void {
		// LF_LANG is defined as 'fr' at the top of this file — simulates an
		// active frontend language.
		$result = CatalogQuery::disable_product_grid_cache( true );

		$this->assertFalse( $result, 'Cache must be disabled when a language is active.' );
	}

	// =========================================================================
	// 17. Effective lang — is_singular('product') with product's own _lf_lang
	// =========================================================================

	public function test_secondary_query_uses_product_lf_lang_when_is_singular_product(): void {
		$GLOBALS['lf_test_is_singular'] = true;
		\LfWcMocks::$queried_object_id  = 99;
		$this->make_post( 99, 'product' );
		\LfWcMocks::$meta[99]['_lf_lang'] = 'de'; // product lang differs from LF_LANG ('fr')

		$query = new \WP_Query();
		$query->set( 'post_type', 'product' );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$meta_query = $query->get( 'meta_query' );
		$lf_clause  = array_values( array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		) );

		$this->assertCount( 1, $lf_clause );
		$this->assertSame( 'de', $lf_clause[0]['value'], "Must use the product's _lf_lang, not LF_LANG." );
	}

	// =========================================================================
	// 18. Effective lang — is_singular true but no _lf_lang on product → LF_LANG
	// =========================================================================

	public function test_secondary_query_falls_back_to_lf_lang_when_product_has_no_lf_lang(): void {
		$GLOBALS['lf_test_is_singular'] = true;
		\LfWcMocks::$queried_object_id  = 99;
		$this->make_post( 99, 'product' );
		// _lf_lang intentionally absent → effective_lang must fall back to LF_LANG.

		$query = new \WP_Query();
		$query->set( 'post_type', 'product' );

		CatalogQuery::apply_language_filter_to_secondary_query( $query );

		$meta_query = $query->get( 'meta_query' );
		$lf_clause  = array_values( array_filter(
			$meta_query,
			static fn( $c ) => is_array( $c ) && ( $c['key'] ?? '' ) === '_lf_lang'
		) );

		$this->assertCount( 1, $lf_clause );
		$this->assertSame( LF_LANG, $lf_clause[0]['value'], 'No _lf_lang on product must fall back to LF_LANG.' );
	}

}
