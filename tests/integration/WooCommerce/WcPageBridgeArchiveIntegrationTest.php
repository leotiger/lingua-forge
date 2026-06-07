<?php
/**
 * Integration tests for WcPageBridge::inject_taxonomy_archive_lang().
 *
 * The method is a pre_get_posts p9 hook that rewrites translated-language
 * WooCommerce taxonomy archives (product_cat / product_tag) to return only the
 * correct translated products.  Because it calls get_posts() and get_term_by()
 * against a real DB, these are integration tests rather than unit tests.
 *
 * Strategy: construct a bare WP_Query, set $GLOBALS['wp_the_query'] to it so
 * $q->is_main_query() returns true, configure the necessary query vars, then
 * call the static method directly and assert on the resulting query state.
 *
 * LF_LANG is defined once per PHP process as 'es' inside setUpBeforeClass() —
 * NOT at file level — to avoid polluting earlier test classes (e.g. VariationDelegate)
 * that run in the same process.  PHPUnit includes all test files during collection
 * before executing any tests, so a file-level define() would fire before VariationDelegate
 * tests run and cause CatalogQuery to inject _lf_lang='es' into their queries.
 * WcIntegrationTestCase fixes the source language to 'ca', so LF_LANG !== source_lang
 * and the source-language guard is bypassed in all tests except #7.
 *
 * Coverage:
 *   1. Happy path: source product in category, has trid → _lf_trid + _lf_lang meta clauses injected.
 *   2. Trid values in the injected clause match the source product's actual trid.
 *   3. _lf_lang clause value equals LF_LANG.
 *   4. product_cat qvar is cleared after injection.
 *   5. tax_query is cleared after injection.
 *   6. post_type and post_status are forced to 'product' / 'publish'.
 *   7. Source-language guard: LF_LANG == source_lang → query unchanged.
 *   8. No taxonomy qvar present → method returns early, query unchanged.
 *   9. Term slug not found in DB → method returns early.
 *  10. Source product exists but has no _lf_trid → impossible sentinel set.
 *  11. Category exists but contains no source products → impossible sentinel set.
 *  12. Multiple source products in category → all trids collected.
 *  13. product_tag qvar handled identically to product_cat.
 *  14. Phase 1 finds source-language products (CatalogQuery guard verified by outcome).
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

final class WcPageBridgeArchiveIntegrationTest extends WcIntegrationTestCase {

	/** @var \WP_Query|null Original global main-query pointer, restored in tearDown. */
	private ?\WP_Query $saved_wp_the_query = null;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Define LF_LANG here — NOT at file level — so it fires during test execution,
	 * after all other WC integration test classes (alphabetically before 'W') have
	 * already run.  A file-level define() would execute during PHPUnit's collection
	 * phase (before any tests run) and would break VariationDelegate and other tests
	 * by causing CatalogQuery to inject _lf_lang into all their queries.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		defined( 'LF_LANG' ) || define( 'LF_LANG', 'es' );
	}

	protected function setUp(): void {
		parent::setUp();
		$this->saved_wp_the_query = $GLOBALS['wp_the_query'] ?? null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	protected function tearDown(): void {
		$GLOBALS['wp_the_query'] = $this->saved_wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a product_cat term and return [ term_id, slug ].
	 *
	 * @return array{ 0: int, 1: string }
	 */
	private function insert_product_cat( string $name ): array {
		$result = wp_insert_term( $name, 'product_cat' );
		$this->assertNotWPError( $result, "wp_insert_term($name, product_cat) failed." );
		$term = get_term( (int) $result['term_id'], 'product_cat' );
		$this->assertInstanceOf( \WP_Term::class, $term );
		return [ (int) $result['term_id'], $term->slug ];
	}

	/**
	 * Build a bare WP_Query, set the given taxonomy qvar, and register it as
	 * the global main query so $q->is_main_query() returns true.
	 */
	private function make_main_query( string $tax_qv = '', string $term_slug = '' ): \WP_Query {
		$q = new \WP_Query();
		$q->init();
		if ( '' !== $tax_qv ) {
			$q->set( $tax_qv, $term_slug );
		}
		$GLOBALS['wp_the_query'] = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $q;
	}

	/**
	 * Collect all 'key' strings from a flat or nested meta_query array.
	 *
	 * @return string[]
	 */
	private function meta_keys( array $meta_query ): array {
		$keys = [];
		array_walk_recursive( $meta_query, static function ( $v, $k ) use ( &$keys ) {
			if ( 'key' === $k ) {
				$keys[] = $v;
			}
		} );
		return $keys;
	}

	/**
	 * Return the first meta_query clause whose 'key' matches $key, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_meta_clause( array $meta_query, string $key ): ?array {
		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && ( $clause['key'] ?? '' ) === $key ) {
				return $clause;
			}
		}
		return null;
	}

	/**
	 * Flush the Router context's cached_source_language so source_language()
	 * re-reads the option on the next call.
	 */
	private function flush_source_language_cache(): void {
		$ref = new ReflectionClass( Context::class );
		$p   = $ref->getProperty( 'cached_source_language' );
		$p->setAccessible( true );
		$p->setValue( Router::get_instance()->context, null );
	}

	// =========================================================================
	// 1. Happy path — meta clauses are injected
	// =========================================================================

	public function test_happy_path_injects_trid_and_lang_meta_clauses(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Happy Cat ' . uniqid() );
		[ $source_id ]      = $this->make_product_pair();
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$keys = $this->meta_keys( (array) $q->get( 'meta_query' ) );
		$this->assertContains( '_lf_trid', $keys, 'meta_query must contain a _lf_trid clause.' );
		$this->assertContains( '_lf_lang', $keys, 'meta_query must contain a _lf_lang clause.' );
	}

	// =========================================================================
	// 2. Trid clause value matches the source product's trid
	// =========================================================================

	public function test_trid_clause_value_matches_source_product_trid(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Trid Check ' . uniqid() );
		$trid      = $this->trid();
		$source_id = $this->make_product( self::SOURCE_LANG, $trid );
		$this->make_product( self::TRANS_LANG, $trid );
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$clause = $this->find_meta_clause( (array) $q->get( 'meta_query' ), '_lf_trid' );
		$this->assertNotNull( $clause, '_lf_trid clause must be present.' );
		$this->assertContains( $trid, (array) $clause['value'], 'Trid clause must include the source product trid.' );
	}

	// =========================================================================
	// 3. _lf_lang clause value equals LF_LANG
	// =========================================================================

	public function test_lang_clause_value_equals_lf_lang(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Lang Clause ' . uniqid() );
		[ $source_id ]      = $this->make_product_pair();
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$clause = $this->find_meta_clause( (array) $q->get( 'meta_query' ), '_lf_lang' );
		$this->assertNotNull( $clause, '_lf_lang clause must be present.' );
		$this->assertSame( LF_LANG, $clause['value'], '_lf_lang clause value must equal LF_LANG.' );
	}

	// =========================================================================
	// 4. product_cat qvar is cleared
	// =========================================================================

	public function test_product_cat_qvar_is_cleared_after_injection(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Qvar Clear ' . uniqid() );
		[ $source_id ]      = $this->make_product_pair();
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$this->assertSame( '', (string) $q->get( 'product_cat' ), 'product_cat qvar must be cleared so WP does not emit a taxonomy JOIN.' );
	}

	// =========================================================================
	// 5. tax_query is cleared
	// =========================================================================

	public function test_tax_query_is_cleared_after_injection(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Tax Clear ' . uniqid() );
		[ $source_id ]      = $this->make_product_pair();
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$this->assertSame( [], (array) $q->get( 'tax_query' ), 'tax_query must be cleared to prevent NOT IN subquery from wp_term_relationships.' );
	}

	// =========================================================================
	// 6. post_type forced to 'product' and post_status forced to 'publish'
	// =========================================================================

	public function test_post_type_and_post_status_are_forced(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Post Type ' . uniqid() );
		[ $source_id ]      = $this->make_product_pair();
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$this->assertSame( 'product', $q->get( 'post_type' ),   'post_type must be pinned to product.' );
		$this->assertSame( 'publish', $q->get( 'post_status' ), 'post_status must be pinned to publish to exclude private drafts.' );
	}

	// =========================================================================
	// 7. Source-language guard: LF_LANG === source_lang → query unchanged
	// =========================================================================

	public function test_source_language_guard_returns_early_without_modifying_query(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Source Guard ' . uniqid() );
		[ $source_id ]      = $this->make_product_pair();
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		// Temporarily set the source language to LF_LANG ('es') so the guard fires.
		update_option( 'linguaforge_primary_language', LF_LANG, false );
		$this->flush_source_language_cache();

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$this->assertSame( $slug, (string) $q->get( 'product_cat' ), 'product_cat must be unchanged when source == LF_LANG.' );
		// WP_Query::init() sets meta_query to '' by default; array_filter strips it.
		$this->assertEmpty( array_filter( (array) $q->get( 'meta_query' ) ), 'meta_query must be empty when source == LF_LANG.' );
	}

	// =========================================================================
	// 8. No taxonomy qvar → method returns early
	// =========================================================================

	public function test_no_taxonomy_qvar_returns_early(): void {
		$q = $this->make_main_query(); // no product_cat / product_tag

		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$this->assertEmpty( array_filter( (array) $q->get( 'meta_query' ) ), 'meta_query must be empty when no taxonomy qvar is set.' );
	}

	// =========================================================================
	// 9. Term slug not found in DB → method returns early
	// =========================================================================

	public function test_nonexistent_term_slug_returns_early(): void {
		$q = $this->make_main_query( 'product_cat', 'no-such-category-' . uniqid() );

		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$this->assertEmpty( array_filter( (array) $q->get( 'meta_query' ) ), 'meta_query must be empty for a non-existent term slug.' );
	}

	// =========================================================================
	// 10. Source product exists but has no _lf_trid → impossible no-match sentinel
	// =========================================================================

	public function test_source_product_without_trid_yields_no_match_sentinel(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'No Trid ' . uniqid() );

		// Product with language but no trid set.
		$post_id = (int) self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $post_id, self::SOURCE_LANG );
		// Deliberately no set_trid() call.
		wp_set_object_terms( $post_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$clause = $this->find_meta_clause( (array) $q->get( 'meta_query' ), '_lf_lang' );
		$this->assertNotNull( $clause, 'An _lf_lang sentinel clause must be present.' );
		$this->assertSame( '__lf_no_match__', $clause['value'], 'Impossible sentinel must be set when no trids are found.' );
		// product_cat must still be cleared on the empty path.
		$this->assertSame( '', (string) $q->get( 'product_cat' ), 'product_cat must be cleared even when trids are empty.' );
	}

	// =========================================================================
	// 11. Category exists but contains no source products → impossible sentinel
	// =========================================================================

	public function test_empty_category_yields_no_match_sentinel(): void {
		[ , $slug ] = $this->insert_product_cat( 'Empty Cat ' . uniqid() );
		// No products assigned.

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$clause = $this->find_meta_clause( (array) $q->get( 'meta_query' ), '_lf_lang' );
		$this->assertNotNull( $clause );
		$this->assertSame( '__lf_no_match__', $clause['value'], 'Empty category must yield the no-match sentinel.' );
	}

	// =========================================================================
	// 12. Multiple source products in category → all trids collected
	// =========================================================================

	public function test_multiple_source_products_all_trids_in_clause(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Multi Product ' . uniqid() );

		$trid_a = $this->trid();
		$trid_b = $this->trid();
		$src_a  = $this->make_product( self::SOURCE_LANG, $trid_a );
		$src_b  = $this->make_product( self::SOURCE_LANG, $trid_b );
		$this->make_product( self::TRANS_LANG, $trid_a );
		$this->make_product( self::TRANS_LANG, $trid_b );
		wp_set_object_terms( $src_a, [ $term_id ], 'product_cat' );
		wp_set_object_terms( $src_b, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$clause = $this->find_meta_clause( (array) $q->get( 'meta_query' ), '_lf_trid' );
		$this->assertNotNull( $clause );
		$values = (array) $clause['value'];
		$this->assertContains( $trid_a, $values, 'First trid must be present.' );
		$this->assertContains( $trid_b, $values, 'Second trid must be present.' );
	}

	// =========================================================================
	// 13. product_tag qvar handled identically to product_cat
	// =========================================================================

	public function test_product_tag_qvar_is_handled_and_cleared(): void {
		$result = wp_insert_term( 'Test Tag ' . uniqid(), 'product_tag' );
		$this->assertNotWPError( $result );
		$tag  = get_term( (int) $result['term_id'], 'product_tag' );
		$this->assertInstanceOf( \WP_Term::class, $tag );

		$trid      = $this->trid();
		$source_id = $this->make_product( self::SOURCE_LANG, $trid );
		$this->make_product( self::TRANS_LANG, $trid );
		wp_set_object_terms( $source_id, [ (int) $result['term_id'] ], 'product_tag' );

		$q = $this->make_main_query( 'product_tag', $tag->slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		$keys = $this->meta_keys( (array) $q->get( 'meta_query' ) );
		$this->assertContains( '_lf_trid', $keys, '_lf_trid clause must be present for product_tag.' );
		$this->assertContains( '_lf_lang', $keys, '_lf_lang clause must be present for product_tag.' );
		$this->assertSame( '', (string) $q->get( 'product_tag' ), 'product_tag qvar must be cleared.' );
	}

	// =========================================================================
	// 14. Phase 1 finds source-language products, not translated ones
	//
	// Phase 1 adds _lf_lang = $source_lang to its get_posts() call, which trips
	// CatalogQuery's early-return guard and prevents it from injecting the target
	// language (even though pre_get_posts fires before suppress_filters is checked).
	// We verify this indirectly: if Phase 1 were finding translated products instead
	// of source ones, the translated product's trid would be the same — but the key
	// is that the category term is only on the SOURCE product.  If Phase 1 were
	// broken (fetching translated products), the source product would not be found
	// and the empty-sentinel path would fire.  A non-sentinel trid clause proves
	// Phase 1 correctly found the source product.
	// =========================================================================

	public function test_phase1_finds_source_products_not_translated_ones(): void {
		[ $term_id, $slug ] = $this->insert_product_cat( 'Phase1 Guard ' . uniqid() );
		$trid      = $this->trid();
		$source_id = $this->make_product( self::SOURCE_LANG, $trid );
		$this->make_product( self::TRANS_LANG, $trid );
		// Only the source product is in the term.  Translated products never have
		// wp_term_relationships rows in LF's shared-stock model.
		wp_set_object_terms( $source_id, [ $term_id ], 'product_cat' );

		$q = $this->make_main_query( 'product_cat', $slug );
		WcPageBridge::inject_taxonomy_archive_lang( $q );

		// A real _lf_trid clause (not the __lf_no_match__ sentinel) proves Phase 1
		// found the source product rather than the translated one.
		$clause = $this->find_meta_clause( (array) $q->get( 'meta_query' ), '_lf_trid' );
		$this->assertNotNull( $clause, '_lf_trid clause must exist — Phase 1 must find the source product.' );
		$this->assertContains(
			$trid,
			(array) $clause['value'],
			'Phase 1 must find the source-language product (which is in the term) and not skip it due to a language mismatch.'
		);
	}
}
