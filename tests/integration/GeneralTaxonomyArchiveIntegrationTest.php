<?php
/**
 * Integration tests for general taxonomy archive routing in LinguaForge\Router\Rewrite\Manager.
 *
 * Covers two additions from 2.2.4:
 *   - Manager::translate_general_term_link()  — term_link filter
 *   - Manager::add_general_taxonomy_archive_rewrite_rules() — called from register_rewrite_rules()
 *
 * Strategy:
 *   • translate_general_term_link() is called directly (it is public).
 *   • add_general_taxonomy_archive_rewrite_rules() is private; exercised by calling the public
 *     register_rewrite_rules() after clearing $wp_rewrite->extra_rules_top so only
 *     the rules added by that call are visible to the assertions.
 *   • LF_LANG is defined by the Router bootstrap (detect_lang_safe()) before any
 *     setUpBeforeClass() runs.  In wp-env CLI mode there is no URL language prefix,
 *     so detect_lang_safe() returns the source language and LF_LANG = 'en'.  Tests
 *     are written around this actual value.
 *   • Test taxonomy 'lf_event_type' (rewrite['slug'] = 'event-type') is registered
 *     in setUpBeforeClass() and unregistered in tearDownAfterClass().
 *
 * Coverage:
 *   1. translate_general_term_link() — source language ≠ LF_LANG, non-excluded → URL prefixed with LF_LANG.
 *   2. translate_general_term_link() — LF_LANG === source language → URL unchanged.
 *   3. translate_general_term_link() — WC taxonomy (product_cat) excluded by default → URL unchanged.
 *   4. translate_general_term_link() — custom exclusion via lf_public_taxonomy_archives_excluded filter → URL unchanged.
 *   5. add_general_taxonomy_archive_rewrite_rules() — taxonomy with rewrite slug → rules registered.
 *   6. add_general_taxonomy_archive_rewrite_rules() — built-in taxonomy (post_tag) excluded → no rules.
 *   7. add_general_taxonomy_archive_rewrite_rules() — taxonomy excluded via filter → no rules.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Rewrite\Manager;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class GeneralTaxonomyArchiveIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Register the test taxonomy.
	 *
	 * Runs once before the first test in this class.  The taxonomy persists for
	 * the whole class and is unregistered in tearDownAfterClass().
	 *
	 * LF_LANG is defined by Router::__construct() during the WP test-bootstrap
	 * (via detect_lang_safe()) before any test class runs.  In wp-env CLI mode
	 * there is no language prefix in the request URL, so detect_lang_safe()
	 * returns the source language ('en') and LF_LANG = 'en'.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// 'lf_event_type': public taxonomy with an explicit rewrite slug.
		// Used to exercise translate_general_term_link() and rewrite-rule registration.
		if ( ! taxonomy_exists( 'lf_event_type' ) ) {
			register_taxonomy( 'lf_event_type', [ 'post' ], [
				'label'   => 'Event Types',
				'public'  => true,
				'rewrite' => [ 'slug' => 'event-type' ],
			] );
		}
	}

	public static function tearDownAfterClass(): void {
		unregister_taxonomy( 'lf_event_type' );
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );
		update_option( 'linguaforge_routing_mode',     'path',            false );

		// Ensure de/ca/es appear in Context::languages() alongside the source 'en'.
		add_filter( 'lf_languages_list', static function ( array $langs ): array {
			return array_values( array_unique( array_merge( $langs, [ 'de', 'ca', 'es' ] ) ) );
		} );

		$this->flush_context_caches();
	}

	protected function tearDown(): void {
		remove_all_filters( 'lf_languages_list' );
		remove_all_filters( 'lf_public_taxonomy_archives_excluded' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function manager(): Manager {
		return Router::get_instance()->rewrite;
	}

	/**
	 * Flush all per-request Context caches so option changes in setUp take effect.
	 */
	private function flush_context_caches(): void {
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	/**
	 * Capture only the rewrite rules produced by register_rewrite_rules().
	 *
	 * Saves the current extra_rules_top, clears it, calls register_rewrite_rules(),
	 * captures the result, then restores the original state — so each test sees a
	 * clean set of rules produced solely by that call.
	 *
	 * @return array<string,string>  [ regex => query_string ] added by the call.
	 */
	private function capture_registration_rules(): array {
		global $wp_rewrite;
		$saved                       = $wp_rewrite->extra_rules_top;
		$wp_rewrite->extra_rules_top = [];
		$this->manager()->register_rewrite_rules();
		$rules                       = $wp_rewrite->extra_rules_top;
		$wp_rewrite->extra_rules_top = $saved;
		return $rules;
	}

	// =========================================================================
	// 1. translate_general_term_link() — non-source language → URL prefixed
	// =========================================================================

	/**
	 * When LF_LANG differs from the source language and the taxonomy is not
	 * excluded, translate_general_term_link() must prepend the language prefix.
	 *
	 * LF_LANG is 'en' (defined by the Router bootstrap; cannot be overridden).
	 * We set the source language to 'de' so that the guard
	 * (LF_LANG === source_language()) does not fire and the URL is prefixed
	 * with the LF_LANG value ('en').
	 */
	public function test_non_source_language_link_is_prefixed(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		// Set source to a language ≠ LF_LANG so the guard does not fire.
		update_option( 'linguaforge_primary_language', 'de', false );
		$this->flush_context_caches();

		$termlink = home_url( '/event-type/conference/' );
		$result   = $this->manager()->translate_general_term_link( $termlink, null, 'lf_event_type' );

		$this->assertStringContainsString(
			'/' . LF_LANG . '/event-type/',
			$result,
			'translate_general_term_link() must insert the LF_LANG prefix into the term link when LF_LANG differs from the source language.'
		);
	}

	// =========================================================================
	// 2. translate_general_term_link() — source language guard fires
	// =========================================================================

	/**
	 * When the source language equals LF_LANG the method must return the
	 * link unchanged (guard: `LF_LANG === source_language()`).
	 *
	 * setUp() sets linguaforge_primary_language = SOURCE_LANG = 'en'.
	 * LF_LANG is also 'en' (set by the Router bootstrap).  The guard fires
	 * by default in this test environment.
	 */
	public function test_source_language_guard_returns_link_unchanged(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		// setUp() sets source = SOURCE_LANG = 'en' = LF_LANG → guard fires.
		$termlink = home_url( '/event-type/conference/' );
		$result   = $this->manager()->translate_general_term_link( $termlink, null, 'lf_event_type' );

		$this->assertSame(
			$termlink,
			$result,
			'translate_general_term_link() must return the URL unchanged when LF_LANG equals the source language.'
		);
	}

	// =========================================================================
	// 3. translate_general_term_link() — WC taxonomy excluded by default
	// =========================================================================

	/**
	 * WC taxonomies such as 'product_cat' are in the hard-coded exclusion list
	 * inside get_general_taxonomy_archive_list().  The method must pass through
	 * the link for any excluded taxonomy regardless of language settings.
	 */
	public function test_wc_taxonomy_excluded_by_default(): void {
		// Set source to 'de' so the language guard alone would not skip the call;
		// only the taxonomy-not-in-list guard should trigger.
		update_option( 'linguaforge_primary_language', 'de', false );
		$this->flush_context_caches();

		$termlink = home_url( '/product-category/shirts/' );
		$result   = $this->manager()->translate_general_term_link( $termlink, null, 'product_cat' );

		$this->assertSame(
			$termlink,
			$result,
			'translate_general_term_link() must leave the link unchanged for WC taxonomies excluded by default (product_cat).'
		);
	}

	// =========================================================================
	// 4. translate_general_term_link() — custom exclusion via filter
	// =========================================================================

	/**
	 * Third-party code can extend the exclusion list via the
	 * `lf_public_taxonomy_archives_excluded` filter.  The method must respect
	 * custom entries and return the link unchanged.
	 */
	public function test_custom_exclusion_filter_returns_link_unchanged(): void {
		// Set source to 'de' so the language guard alone would not skip the call.
		update_option( 'linguaforge_primary_language', 'de', false );
		$this->flush_context_caches();

		add_filter(
			'lf_public_taxonomy_archives_excluded',
			static function ( array $excluded ): array {
				$excluded[] = 'lf_event_type';
				return $excluded;
			}
		);

		$termlink = home_url( '/event-type/conference/' );
		$result   = $this->manager()->translate_general_term_link( $termlink, null, 'lf_event_type' );

		$this->assertSame(
			$termlink,
			$result,
			'translate_general_term_link() must respect taxonomies added to the exclusion list via lf_public_taxonomy_archives_excluded filter.'
		);
	}

	// =========================================================================
	// 5. add_general_taxonomy_archive_rewrite_rules() — rewrite rules registered
	// =========================================================================

	/**
	 * When a public taxonomy has a rewrite slug, both a plain and a paginated
	 * language-prefixed rule must be registered.  'lf_event_type' has
	 * rewrite['slug'] = 'event-type'.
	 */
	public function test_taxonomy_with_rewrite_slug_registers_rules(): void {
		$rules = $this->capture_registration_rules();

		$plain_found = false;
		$paged_found = false;

		foreach ( $rules as $query ) {
			if ( ! str_contains( $query, 'lf_event_type=' ) ) {
				continue;
			}
			// Plain rule: has taxonomy query var but no paged parameter.
			if ( ! str_contains( $query, 'paged=' ) ) {
				$plain_found = true;
			}
			// Paginated rule: has both taxonomy query var and paged parameter.
			if ( str_contains( $query, 'paged=' ) ) {
				$paged_found = true;
			}
		}

		$this->assertTrue( $plain_found, 'A language-prefixed plain archive rule must be registered for lf_event_type (event-type slug).' );
		$this->assertTrue( $paged_found, 'A language-prefixed paginated archive rule must be registered for lf_event_type (event-type slug).' );
	}

	// =========================================================================
	// 6. add_general_taxonomy_archive_rewrite_rules() — no rewrite slug → no rules
	// =========================================================================

	/**
	 * Taxonomies with no rewrite slug (rewrite => false) have no public archive
	 * URL and must be skipped by add_general_taxonomy_archive_rewrite_rules().
	 *
	 * This exercises the `empty($tax->rewrite['slug'])` guard inside
	 * get_general_taxonomy_archive_list().
	 */
	public function test_taxonomy_without_rewrite_slug_generates_no_rules(): void {
		register_taxonomy( 'lf_test_noslug', [ 'post' ], [
			'label'   => 'No Slug',
			'public'  => true,
			'rewrite' => false,
		] );

		$rules = $this->capture_registration_rules();

		unregister_taxonomy( 'lf_test_noslug' );

		foreach ( $rules as $query ) {
			$this->assertStringNotContainsString(
				'lf_test_noslug=',
				$query,
				'No rewrite rule must be generated for a public taxonomy that has no rewrite slug.'
			);
		}
	}

	// =========================================================================
	// 7. add_general_taxonomy_archive_rewrite_rules() — custom exclusion via filter
	// =========================================================================

	/**
	 * Taxonomies added to the `lf_public_taxonomy_archives_excluded` filter must
	 * be skipped by add_general_taxonomy_archive_rewrite_rules().
	 */
	public function test_excluded_taxonomy_generates_no_rules(): void {
		add_filter(
			'lf_public_taxonomy_archives_excluded',
			static function ( array $excluded ): array {
				$excluded[] = 'lf_event_type';
				return $excluded;
			}
		);

		$rules = $this->capture_registration_rules();

		foreach ( $rules as $query ) {
			$this->assertStringNotContainsString(
				'lf_event_type=',
				$query,
				'No rewrite rule must be generated for a taxonomy that appears in the lf_public_taxonomy_archives_excluded list.'
			);
		}
	}
}
