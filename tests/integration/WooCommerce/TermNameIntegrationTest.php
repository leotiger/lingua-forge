<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\TermNameFilter.
 *
 * Exercises the full WordPress term-name path: apply_filters('term_name', …)
 * → TermNameFilter::translate_term_name() → get_term_meta() → termmeta DB.
 *
 * WP_UnitTestCase wraps each test in a DB transaction rolled back on tearDown,
 * so no manual term or termmeta cleanup is needed.
 *
 * Coverage:
 *
 *   WP_Term object path (edit-tags.php admin list table):
 *   1. Translated name returned for product_cat in non-source language.
 *   2. Source-language request returns original name (no swap).
 *   3. No termmeta stored → original name returned (fallback).
 *   4. Non-WC taxonomy is not filtered.
 *   5. pa_* attribute taxonomy is filtered.
 *   6. product_tag is filtered.
 *   7. Empty termmeta value falls back to original name.
 *   8. Multiple languages resolved independently.
 *
 *   Integer term-ID path (sanitize_term_field — fires on frontend catalog pages):
 *   9.  'display' context with int term ID → translated (this is the frontend path).
 *   10. 'edit' context with int term ID → original name returned (must not rewrite).
 *   11. 'db' context with int term ID → original name returned.
 *   12. Non-WC taxonomy via int path → not filtered.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\TermNameFilter;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;

final class TermNameIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// setUp / tearDown
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		// Ensure the test languages (ca, es, de) are recognised by the Router
		// even when only en_US is installed in the wp-env locale stack.
		add_filter( 'lf_languages_list', function ( array $langs ): array {
			return array_unique( array_merge( $langs, [ 'ca', 'es', 'de' ] ) );
		} );
	}

	protected function tearDown(): void {
		// Restore the cookie state changed by individual tests.
		unset( $_COOKIE['lf_lang'] );

		// Flush Context caches so the next test starts clean.
		$this->reset_context_caches();

		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a term in $taxonomy and return its WP_Term object.
	 */
	private function make_term( string $taxonomy, string $name ): \WP_Term {
		$result = wp_insert_term( $name, $taxonomy );
		$this->assertNotWPError( $result );
		return get_term( $result['term_id'], $taxonomy );
	}

	/**
	 * Set the simulated current request language via the lf_lang cookie.
	 * Also clears Context caches so detect_lang() picks it up immediately.
	 */
	private function set_current_lang( string $lang ): void {
		$_COOKIE['lf_lang'] = $lang;
		$this->reset_context_caches();
	}

	/**
	 * Reset per-request Context caches (same approach as WcIntegrationTestCase::setUp).
	 */
	private function reset_context_caches(): void {
		$ctx_ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language' ] as $prop ) {
			$p = $ctx_ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	// =========================================================================
	// 1. Translated name returned for product_cat in non-source language
	// =========================================================================

	public function test_translated_name_returned_for_product_cat(): void {
		$term = $this->make_term( 'product_cat', 'Electronics' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Electrónica' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Electrónica', $result );
	}

	// =========================================================================
	// 2. Source-language request returns original name
	// =========================================================================

	public function test_source_language_request_returns_original_name(): void {
		$term = $this->make_term( 'product_cat', 'Clothing' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Ropa' );

		// Simulate a source-language request.
		$this->set_current_lang( self::SOURCE_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Clothing', $result );
	}

	// =========================================================================
	// 3. No termmeta stored → fallback to original name
	// =========================================================================

	public function test_no_termmeta_returns_original_name(): void {
		$term = $this->make_term( 'product_cat', 'Books' );
		// No termmeta stored for TRANS_LANG.

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Books', $result );
	}

	// =========================================================================
	// 4. Non-WC taxonomy not filtered
	// =========================================================================

	public function test_non_wc_taxonomy_not_filtered(): void {
		// Register a temporary custom taxonomy.
		register_taxonomy( 'lf_test_tax', 'post' );
		$term = $this->make_term( 'lf_test_tax', 'Science' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Ciencia' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Science', $result, 'Non-WC taxonomy must not be filtered.' );
	}

	// =========================================================================
	// 5. pa_* attribute taxonomy is filtered
	// =========================================================================

	public function test_pa_taxonomy_is_filtered(): void {
		// WooCommerce registers pa_* taxonomies at runtime; ensure the test one exists.
		if ( ! taxonomy_exists( 'pa_color' ) ) {
			register_taxonomy( 'pa_color', 'product' );
		}

		$term = $this->make_term( 'pa_color', 'Red' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Rojo' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Rojo', $result );
	}

	// =========================================================================
	// 6. product_tag is filtered
	// =========================================================================

	public function test_product_tag_is_filtered(): void {
		$term = $this->make_term( 'product_tag', 'Sale' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Oferta' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Oferta', $result );
	}

	// =========================================================================
	// 7. Empty termmeta value falls back to original name
	// =========================================================================

	public function test_empty_termmeta_falls_back_to_original(): void {
		$term = $this->make_term( 'product_cat', 'Music' );
		// Explicitly store an empty string (TermNameAdmin deletes on empty, but
		// test that TermNameFilter is robust if empty string somehow exists).
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, '' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Music', $result, 'Empty termmeta must fall back to original name.' );
	}

	// =========================================================================
	// 8. Multiple languages resolved independently
	// =========================================================================

	public function test_multiple_languages_resolved_independently(): void {
		$term = $this->make_term( 'product_cat', 'Sports' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . 'es', 'Deportes' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . 'de', 'Sport' );

		$this->set_current_lang( 'es' );
		$result_es = apply_filters( 'term_name', $term->name, $term );

		$this->set_current_lang( 'de' );
		$result_de = apply_filters( 'term_name', $term->name, $term );

		$this->assertSame( 'Deportes', $result_es );
		$this->assertSame( 'Sport',    $result_de );
	}

	// =========================================================================
	// 9–12. sanitize_term_field() path (int term ID as 2nd argument)
	//
	// WordPress fires apply_filters('term_name', $value, $term_id, $taxonomy, $context)
	// from sanitize_term_field(). This is the path that runs on every frontend
	// page — product archive, shop, category pages, etc. Tests 1–8 above use
	// the admin list-table path (WP_Term object). Tests below use the int path.
	// =========================================================================

	/**
	 * 9. sanitize_term_field 'display' context → translated name returned.
	 * This is the critical frontend catalog path.
	 */
	public function test_int_path_display_context_returns_translation(): void {
		$term = $this->make_term( 'product_cat', 'Furniture' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Mobles' );

		$this->set_current_lang( self::TRANS_LANG );

		// Simulate sanitize_term_field() call: (name, term_id_int, taxonomy, context).
		$result = apply_filters( 'term_name', $term->name, $term->term_id, 'product_cat', 'display' );

		$this->assertSame( 'Mobles', $result, 'sanitize_term_field display path must translate term names.' );
	}

	/**
	 * 10. sanitize_term_field 'edit' context → original name returned.
	 * Admin edit screens and REST edit endpoints use 'edit'; must not rewrite.
	 */
	public function test_int_path_edit_context_returns_original(): void {
		$term = $this->make_term( 'product_cat', 'Outdoors' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Exterior' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term->term_id, 'product_cat', 'edit' );

		$this->assertSame( 'Outdoors', $result, "'edit' context must return original name, not the translation." );
	}

	/**
	 * 11. sanitize_term_field 'db' context → original name returned.
	 * DB context must always be the stored source value.
	 */
	public function test_int_path_db_context_returns_original(): void {
		$term = $this->make_term( 'product_cat', 'Garden' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Jardí' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term->term_id, 'product_cat', 'db' );

		$this->assertSame( 'Garden', $result, "'db' context must return original name, not the translation." );
	}

	/**
	 * 12. Non-WC taxonomy via int path → not filtered.
	 */
	public function test_int_path_non_wc_taxonomy_not_filtered(): void {
		register_taxonomy( 'lf_test_tax2', 'post' );
		$term = $this->make_term( 'lf_test_tax2', 'History' );
		update_term_meta( $term->term_id, TermNameFilter::META_PREFIX . self::TRANS_LANG, 'Història' );

		$this->set_current_lang( self::TRANS_LANG );

		$result = apply_filters( 'term_name', $term->name, $term->term_id, 'lf_test_tax2', 'display' );

		$this->assertSame( 'History', $result, 'Non-WC taxonomy must not be filtered via int path.' );
	}
}
