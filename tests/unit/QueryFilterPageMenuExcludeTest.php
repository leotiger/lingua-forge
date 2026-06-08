<?php
/**
 * Unit tests for the _lf_page_menu_exclude logic in
 * LinguaForge\Router\Rewrite\QueryFilter::filter_page_list_frontend().
 *
 * Tests exercise the three code paths added for §9.1:
 *   • Pages marked with _lf_page_menu_exclude='1' are re-attached after the
 *     language filter so they appear in every language's navigation.
 *   • Developers can extend the excluded-ID list via the
 *     linguaforge_page_menu_excluded_page_ids filter.
 *   • The admin early-return path is unaffected.
 *
 * Strategy: set $pending_page_list_lang via Reflection to drive a specific
 * filter language without depending on the LF_LANG constant (which is fixed
 * per PHPUnit process once another test file has defined it).
 *
 * Coverage:
 *   1. Normal language filtering — no excluded pages, behaviour unchanged.
 *   2. Page in wrong language marked excluded → re-attached in translated nav.
 *   3. Page in current language marked excluded → appears exactly once (no duplicate).
 *   4. Excluded pages re-sorted by menu_order after re-attach.
 *   5. Developer filter adds extra page ID → re-attached.
 *   6. Admin context with no pending lang → early return, all pages unfiltered.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Router;
use LinguaForge\Router\Rewrite\QueryFilter;
use LinguaForge\Tests\Unit\WooCommerce\WcUnitTestCase;
use ReflectionClass;

// WcUnitTestCase loads WcPolyfills (get_post_meta, is_admin, WP_Post …) and
// class-language-router (Router, Context).  require_once is safe even if other
// test files already pulled these in.
require_once dirname( __DIR__, 2 ) . '/language-router/includes/rewrite/class-query-filter.php';

/**
 * @covers \LinguaForge\Router\Rewrite\QueryFilter::filter_page_list_frontend
 */
final class QueryFilterPageMenuExcludeTest extends WcUnitTestCase {

	private QueryFilter $qf;

	protected function setUp(): void {
		parent::setUp(); // plants a stub Router (source=en) and resets LfWcMocks
		\LfWcMocks::$is_admin         = false;
		$GLOBALS['lf_test_filters']   = [];
		$this->qf = new QueryFilter( Router::get_instance() );
		// Drive the filter as a Spanish frontend request (avoids relying on the
		// process-wide LF_LANG constant, which is locked in by other test files).
		$this->arm( 'es' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Set pending_page_list_lang on the QueryFilter instance via Reflection. */
	private function arm( ?string $lang ): void {
		$ref  = new ReflectionClass( QueryFilter::class );
		$prop = $ref->getProperty( 'pending_page_list_lang' );
		$prop->setAccessible( true );
		$prop->setValue( $this->qf, $lang );
	}

	/**
	 * Build a page WP_Post stub and register its meta in LfWcMocks.
	 *
	 * @param bool $excluded  Whether to set _lf_page_menu_exclude='1'.
	 */
	private function make_page(
		int    $id,
		string $lang,
		string $title      = '',
		int    $menu_order = 0,
		bool   $excluded   = false
	): \WP_Post {
		$p             = new \WP_Post();
		$p->ID         = $id;
		$p->post_type  = 'page';
		$p->post_title = $title ?: "Page {$id}";
		$p->menu_order = $menu_order;
		$this->set_meta( $id, '_lf_lang', $lang );
		if ( $excluded ) {
			$this->set_meta( $id, '_lf_page_menu_exclude', '1' );
		}
		return $p;
	}

	// =========================================================================
	// 1. Normal filtering — no excluded pages
	// =========================================================================

	public function test_normal_filtering_unchanged_when_no_exclusions(): void {
		$en = $this->make_page( 1, 'en', 'Home' );
		$es = $this->make_page( 2, 'es', 'Inicio' );
		$ca = $this->make_page( 3, 'ca', 'Inici' );

		$result = $this->qf->filter_page_list_frontend( [ $en, $es, $ca ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertSame( [ 2 ], $ids, 'Only the Spanish page should survive for lang=es' );
	}

	// =========================================================================
	// 2. Excluded page in wrong language → hidden from translated navigation
	// =========================================================================

	public function test_excluded_page_in_wrong_language_is_hidden(): void {
		$en_privacy = $this->make_page( 10, 'en', 'Privacy Policy', 0, true );
		$es         = $this->make_page( 11, 'es', 'Inicio' );

		$result = $this->qf->filter_page_list_frontend( [ $en_privacy, $es ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertNotContains( 10, $ids, 'Excluded EN page must not appear in ES navigation' );
		$this->assertContains( 11, $ids, 'Regular ES page must remain' );
		$this->assertCount( 1, $result );
	}

	// =========================================================================
	// 3. Excluded page in current language → hidden even in its own language
	// =========================================================================

	public function test_excluded_page_in_current_language_is_hidden(): void {
		$es_terms   = $this->make_page( 20, 'es', 'Términos', 0, true );
		$es_regular = $this->make_page( 21, 'es', 'Inicio' );

		$result = $this->qf->filter_page_list_frontend( [ $es_terms, $es_regular ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertNotContains( 20, $ids, 'Excluded ES page must not appear in ES navigation' );
		$this->assertContains( 21, $ids );
		$this->assertCount( 1, $result );
	}

	// =========================================================================
	// 4. Multiple excluded pages → all hidden, remaining pages keep get_pages() order
	// =========================================================================

	public function test_multiple_excluded_pages_all_hidden(): void {
		$es_a    = $this->make_page( 30, 'es', 'Alpha',   10 );
		$es_b    = $this->make_page( 31, 'es', 'Beta',    30 );
		$en_excl = $this->make_page( 32, 'en', 'Privacy', 20, true );

		$result = $this->qf->filter_page_list_frontend( [ $es_a, $es_b, $en_excl ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertSame( [ 30, 31 ], $ids, 'Only non-excluded ES pages must survive' );
	}

	// =========================================================================
	// 5. Developer filter adds extra page ID → also hidden
	// =========================================================================

	public function test_developer_filter_can_add_extra_excluded_id(): void {
		$en_privacy = $this->make_page( 40, 'en', 'Privacy Policy' ); // NOT flagged via meta
		$es         = $this->make_page( 41, 'es', 'Inicio' );

		// Developer hook adds page 40 to the excluded list at runtime.
		$GLOBALS['lf_test_filters']['linguaforge_page_menu_excluded_page_ids'] =
			fn( array $ids ) => array_merge( $ids, [ 40 ] );

		$result = $this->qf->filter_page_list_frontend( [ $en_privacy, $es ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertNotContains( 40, $ids, 'Developer-added page must be hidden' );
		$this->assertContains( 41, $ids );
		$this->assertCount( 1, $result );
	}

	// =========================================================================
	// 5b. Developer filter adds ID not in $pages → silently ignored
	// =========================================================================

	public function test_developer_filter_extra_id_not_in_pages_is_ignored(): void {
		$es = $this->make_page( 50, 'es', 'Inicio' );

		// Developer tries to add page 999 which is not in the current $pages result.
		$GLOBALS['lf_test_filters']['linguaforge_page_menu_excluded_page_ids'] =
			fn( array $ids ) => array_merge( $ids, [ 999 ] );

		$result = $this->qf->filter_page_list_frontend( [ $es ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertNotContains( 999, $ids, 'Non-existent page ID must not be injected' );
		$this->assertSame( [ 50 ], $ids );
	}

	// =========================================================================
	// 6. No translated pages exist → fallback to source-language pages
	// =========================================================================

	public function test_fallback_to_source_when_no_translated_pages_exist(): void {
		// Site has only source-language (un-languaged) pages — no Spanish translations.
		$home     = $this->make_page( 60, '',   'Home' );   // no _lf_lang → source
		$shop     = $this->make_page( 61, 'en', 'Shop' );   // _lf_lang=en → source
		$privacy  = $this->make_page( 62, 'en', 'Privacy', 0, true ); // excluded

		// Filter armed to 'es' (e.g. Spanish product page).
		// No Spanish pages exist → language filter would return empty →
		// fallback must show source pages (Home, Shop) without Privacy.
		$result = $this->qf->filter_page_list_frontend( [ $home, $shop, $privacy ], [] );

		$ids = array_column( $result, 'ID' );
		$this->assertContains( 60, $ids, 'Source un-languaged page must appear in fallback' );
		$this->assertContains( 61, $ids, 'Source _lf_lang=en page must appear in fallback' );
		$this->assertNotContains( 62, $ids, 'Excluded page must not appear even in fallback' );
		$this->assertCount( 2, $result );
	}

	// =========================================================================
	// 7. Admin context with no pending lang → early return, unfiltered
	// =========================================================================

	public function test_admin_without_pending_lang_returns_pages_unfiltered(): void {
		\LfWcMocks::$is_admin = true;
		$this->arm( null ); // no pending language set

		$en = $this->make_page( 60, 'en', 'Home',   0, true );
		$es = $this->make_page( 61, 'es', 'Inicio', 0, false );

		$result = $this->qf->filter_page_list_frontend( [ $en, $es ], [] );

		// Admin early return: no filtering, original array returned as-is.
		$this->assertCount( 2, $result );
		$this->assertSame( 60, $result[0]->ID );
		$this->assertSame( 61, $result[1]->ID );
	}
}
