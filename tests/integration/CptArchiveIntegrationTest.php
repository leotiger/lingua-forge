<?php
/**
 * Integration tests for CPT archive routing in LinguaForge\Router\Rewrite\Manager.
 *
 * Covers two additions from 2.2.4:
 *   - Manager::translate_cpt_archive_link() — post_type_archive_link filter
 *   - Manager::add_cpt_archive_rewrite_rules() — called from register_rewrite_rules()
 *
 * Strategy:
 *   • translate_cpt_archive_link() is called directly (it is public).
 *   • add_cpt_archive_rewrite_rules() is private; exercised by calling the public
 *     register_rewrite_rules() after clearing $wp_rewrite->extra_rules_top so only
 *     the rules added by that call are visible to the assertions.
 *   • LF_LANG is defined by the Router bootstrap (detect_lang_safe()) before any
 *     setUpBeforeClass() runs.  In wp-env CLI mode there is no URL language prefix,
 *     so detect_lang_safe() returns the source language and LF_LANG = 'en'.  Tests
 *     are written around this actual value; the setUpBeforeClass() define() is omitted.
 *   • Test CPTs 'lf_event' (has_archive='events') and 'lf_news' (has_archive=true)
 *     are registered in setUpBeforeClass() if not already present from the
 *     lf-dev-env.php mu-plugin.
 *
 * Coverage:
 *   1. translate_cpt_archive_link() — source language ≠ LF_LANG, non-excluded → URL prefixed with LF_LANG.
 *   2. translate_cpt_archive_link() — LF_LANG === source language → URL unchanged.
 *   3. translate_cpt_archive_link() — 'product' excluded by default → URL unchanged.
 *   4. translate_cpt_archive_link() — custom exclusion via filter → URL unchanged.
 *   5. add_cpt_archive_rewrite_rules() — CPT with has_archive string → rules registered.
 *   6. add_cpt_archive_rewrite_rules() — CPT with has_archive = true → uses rewrite slug.
 *   7. add_cpt_archive_rewrite_rules() — CPT without has_archive → no rules generated.
 *   8. add_cpt_archive_rewrite_rules() — excluded CPT → no rules generated.
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

final class CptArchiveIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Define LF_LANG and register the test CPTs.
	 *
	 * Runs once before the first test in this class. Both CPTs persist for the
	 * whole class and are unregistered in tearDownAfterClass().
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// LF_LANG is defined by Router::__construct() during the WP test-bootstrap
		// (via detect_lang_safe()) before any test class runs.  In wp-env CLI mode
		// there is no language prefix in the request URL, so detect_lang_safe()
		// returns the source language ('en') and LF_LANG = 'en'.  No override is
		// needed; tests are written around the actual constant value.

		// 'lf_event': has_archive string — tests the explicit archive-slug branch.
		if ( ! post_type_exists( 'lf_event' ) ) {
			register_post_type( 'lf_event', [
				'label'       => 'Events',
				'public'      => true,
				'has_archive' => 'events',
				'rewrite'     => [ 'slug' => 'event', 'with_front' => false ],
				'supports'    => [ 'title', 'editor' ],
			] );
		}

		// 'lf_news': has_archive = true — tests the rewrite-slug fallback branch.
		if ( ! post_type_exists( 'lf_news' ) ) {
			register_post_type( 'lf_news', [
				'label'       => 'News',
				'public'      => true,
				'has_archive' => true,
				'rewrite'     => [ 'slug' => 'news', 'with_front' => false ],
				'supports'    => [ 'title' ],
			] );
		}
	}

	public static function tearDownAfterClass(): void {
		unregister_post_type( 'lf_event' );
		unregister_post_type( 'lf_news' );
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
		remove_all_filters( 'linguaforge_cpt_archive_excluded_post_types' );
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
	 * add_rewrite_rule() appends to $wp_rewrite->extra_rules_top immediately;
	 * a DB flush is not needed to inspect the in-memory state.  We save the
	 * current extra_rules_top, clear it, call register_rewrite_rules(), capture
	 * the result, then restore the original state — so each test sees a clean
	 * set of rules produced solely by that call.
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
	// 1. translate_cpt_archive_link() — non-source language → URL prefixed
	// =========================================================================

	/**
	 * When LF_LANG differs from the source language and the post type is not
	 * excluded, translate_cpt_archive_link() must prepend the language prefix
	 * to the archive URL.
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

		$link   = home_url( '/events/' );
		$result = $this->manager()->translate_cpt_archive_link( $link, 'lf_event' );

		$this->assertStringContainsString(
			'/' . LF_LANG . '/events/',
			$result,
			'translate_cpt_archive_link() must insert the LF_LANG prefix into the archive URL when LF_LANG differs from the source language.'
		);
	}

	// =========================================================================
	// 2. translate_cpt_archive_link() — source language guard fires
	// =========================================================================

	/**
	 * When the source language equals LF_LANG the method must return the
	 * link unchanged (first guard: `LF_LANG === source_language()`).
	 *
	 * setUp() sets linguaforge_primary_language = SOURCE_LANG = 'en'.
	 * LF_LANG is also 'en' (set by the Router bootstrap).  No override is
	 * needed — the guard fires by default in this test environment.
	 */
	public function test_source_language_guard_returns_link_unchanged(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		// setUp() sets source = SOURCE_LANG = 'en' = LF_LANG → guard fires.
		$link   = home_url( '/events/' );
		$result = $this->manager()->translate_cpt_archive_link( $link, 'lf_event' );

		$this->assertSame(
			$link,
			$result,
			'translate_cpt_archive_link() must return the URL unchanged when LF_LANG equals the source language.'
		);
	}

	// =========================================================================
	// 3. translate_cpt_archive_link() — 'product' excluded by default
	// =========================================================================

	/**
	 * The default exclusion list contains 'product' (WC handled by WcPageBridge).
	 * The method must pass through the link for any excluded post type.
	 */
	public function test_product_post_type_excluded_by_default(): void {
		$link   = home_url( '/shop/' );
		$result = $this->manager()->translate_cpt_archive_link( $link, 'product' );

		$this->assertSame(
			$link,
			$result,
			'translate_cpt_archive_link() must leave the link unchanged for the product post type (excluded by default).'
		);
	}

	// =========================================================================
	// 4. translate_cpt_archive_link() — custom exclusion via filter
	// =========================================================================

	/**
	 * Third-party code can extend the exclusion list via the
	 * `linguaforge_cpt_archive_excluded_post_types` filter.  The method must
	 * respect custom entries.
	 */
	public function test_custom_exclusion_filter_returns_link_unchanged(): void {
		add_filter(
			'linguaforge_cpt_archive_excluded_post_types',
			static function ( array $excluded ): array {
				$excluded[] = 'lf_event';
				return $excluded;
			}
		);

		$link   = home_url( '/events/' );
		$result = $this->manager()->translate_cpt_archive_link( $link, 'lf_event' );

		$this->assertSame(
			$link,
			$result,
			'translate_cpt_archive_link() must respect post types added to the exclusion list via filter.'
		);
	}

	// =========================================================================
	// 5. add_cpt_archive_rewrite_rules() — CPT with has_archive string
	// =========================================================================

	/**
	 * When a public CPT has `has_archive` set to a non-empty string, that string
	 * is used as the archive slug.  Both a plain and a paginated rule must be
	 * registered.  'lf_event' has has_archive = 'events'.
	 */
	public function test_cpt_with_has_archive_string_registers_rules(): void {
		$rules = $this->capture_registration_rules();

		$plain_found = false;
		$paged_found = false;

		foreach ( $rules as $pattern => $query ) {
			if ( ! str_contains( $query, 'post_type=lf_event' ) ) {
				continue;
			}
			if ( str_contains( $pattern, '/events/?' ) ) {
				$plain_found = true;
			}
			if ( str_contains( $pattern, '/events/page/' ) ) {
				$paged_found = true;
			}
		}

		$this->assertTrue( $plain_found, 'A language-prefixed plain archive rule must exist for lf_event (has_archive=events).' );
		$this->assertTrue( $paged_found, 'A language-prefixed paginated archive rule must exist for lf_event (has_archive=events).' );
	}

	// =========================================================================
	// 6. add_cpt_archive_rewrite_rules() — CPT with has_archive = true
	// =========================================================================

	/**
	 * When `has_archive` is the boolean true, the archive slug falls back to
	 * the CPT's `rewrite['slug']`.  'lf_news' has has_archive = true and
	 * rewrite['slug'] = 'news'.
	 */
	public function test_cpt_with_has_archive_true_uses_rewrite_slug(): void {
		$rules = $this->capture_registration_rules();

		$found = false;
		foreach ( $rules as $pattern => $query ) {
			if ( str_contains( $query, 'post_type=lf_news' ) && str_contains( $pattern, '/news/' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'A language-prefixed rule using the rewrite slug "news" must be registered for lf_news (has_archive=true).' );
	}

	// =========================================================================
	// 7. add_cpt_archive_rewrite_rules() — CPT without has_archive → no rules
	// =========================================================================

	/**
	 * Public CPTs with `has_archive = false` must not receive language-prefixed
	 * archive rules (there is no archive to route to).
	 */
	public function test_cpt_without_has_archive_generates_no_rules(): void {
		register_post_type( 'lf_noarchive', [
			'label'       => 'No Archive',
			'public'      => true,
			'has_archive' => false,
			'rewrite'     => [ 'slug' => 'no-archive' ],
		] );

		$rules = $this->capture_registration_rules();

		unregister_post_type( 'lf_noarchive' );

		// Single-post rules (added by add_cpt_single_rewrite_rules(), which
		// keys on rewrite slug presence, not has_archive) are out of scope for
		// this test — 'lf_noarchive' legitimately still gets one, since
		// individual posts of that type have their own permalink regardless
		// of whether an archive exists. Only archive-shaped rules (no `name=`
		// capture) are asserted against here.
		foreach ( $rules as $query ) {
			if ( str_contains( $query, 'name=$matches' ) ) {
				continue;
			}
			$this->assertStringNotContainsString(
				'post_type=lf_noarchive',
				$query,
				'No archive rewrite rule must reference a CPT with has_archive = false.'
			);
		}
	}

	// =========================================================================
	// 8. add_cpt_archive_rewrite_rules() — excluded CPT → no rules
	// =========================================================================

	/**
	 * CPTs in the `linguaforge_cpt_archive_excluded_post_types` list must be
	 * skipped by add_cpt_archive_rewrite_rules().
	 */
	public function test_excluded_cpt_generates_no_rules(): void {
		add_filter(
			'linguaforge_cpt_archive_excluded_post_types',
			static function ( array $excluded ): array {
				$excluded[] = 'lf_event';
				return $excluded;
			}
		);

		$rules = $this->capture_registration_rules();

		// Single-post rules are governed by the separate
		// linguaforge_cpt_single_excluded_post_types filter, not this one —
		// 'lf_event' legitimately still gets a single-post rule here. Only
		// archive-shaped rules (no `name=` capture) are asserted against.
		foreach ( $rules as $query ) {
			if ( str_contains( $query, 'name=$matches' ) ) {
				continue;
			}
			$this->assertStringNotContainsString(
				'post_type=lf_event',
				$query,
				'No archive rewrite rule must be generated for a CPT that appears in the linguaforge_cpt_archive_excluded_post_types list.'
			);
		}
	}
}
