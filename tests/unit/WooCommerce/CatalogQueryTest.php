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
 * Coverage:
 *   1. Happy path — _lf_lang clause appended to an empty meta_query.
 *   2. Happy path — _lf_lang appended alongside pre-existing clauses.
 *   3. Double-application guard — flat meta_query already has _lf_lang.
 *   4. Double-application guard — relation-wrapped meta_query has _lf_lang.
 *   5. Admin skip — is_admin() returns true → meta_query unchanged.
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
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WcPolyfills.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/CatalogQuery.php';

// Define LF_LANG once for the unit suite — simulates the router having
// resolved a language on a French frontend request.
defined( 'LF_LANG' ) || define( 'LF_LANG', 'fr' );

final class CatalogQueryTest extends TestCase {

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
}
