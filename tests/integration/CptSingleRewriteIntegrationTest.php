<?php
/**
 * Integration tests for CPT single-post routing in LinguaForge\Router\Rewrite\Manager.
 *
 * Covers Manager::add_cpt_single_rewrite_rules() — called from
 * register_rewrite_rules(), closing a 404 confirmed live on an Agnosis-family
 * site: a translated post of a CPT with a custom rewrite slug (`agnosis_artwork`,
 * slug `art`) produces a `/{lang}/art/{postname}/` URL via
 * Manager::lang_permalink(), but no inbound rewrite rule matched that shape —
 * it fell through to the generic `pagename` fallback (registered at the end of
 * register_rewrite_rules()), which 404s because `art/{postname}` is not a real
 * hierarchical page path.
 *
 * Strategy:
 *   • add_cpt_single_rewrite_rules() is private; exercised the same way
 *     CptArchiveIntegrationTest.php exercises add_cpt_archive_rewrite_rules() —
 *     call the public register_rewrite_rules() after clearing
 *     $wp_rewrite->extra_rules_top so only the rules added by that call are
 *     visible to the assertions.
 *   • Test CPTs are registered in setUpBeforeClass(): 'lf_artwork' (non-
 *     hierarchical, custom slug 'art' — mirrors the real-world bug),
 *     'lf_page_like' (hierarchical, custom slug — must be skipped),
 *     'lf_no_slug' (rewrite disabled — must be skipped).
 *
 * Coverage:
 *   1. Non-hierarchical CPT with a custom rewrite slug → language-prefixed
 *      single-post rule registered, matching post_type + name.
 *   2. Hierarchical CPT → no rule generated (excluded).
 *   3. CPT with rewrite disabled → no rule generated (nothing to prefix).
 *   4. Built-in 'post'/'page' and WooCommerce 'product'/'product_variation'
 *      → excluded by default.
 *   5. Custom exclusion via the `linguaforge_cpt_single_excluded_post_types`
 *      filter is respected.
 *   6. End-to-end: the generated pattern actually matches the exact URL shape
 *      Manager::rewrite_lang_permalink() produces for this CPT.
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

final class CptSingleRewriteIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';

	// =========================================================================
	// Lifecycle
	// =========================================================================

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// 'lf_artwork': non-hierarchical, custom rewrite slug — mirrors the
		// real-world agnosis_artwork CPT (rewrite slug 'art') that 404'd.
		if ( ! post_type_exists( 'lf_artwork' ) ) {
			register_post_type( 'lf_artwork', [
				'label'        => 'Artwork',
				'public'       => true,
				'hierarchical' => false,
				'rewrite'      => [ 'slug' => 'art', 'with_front' => false ],
				'supports'     => [ 'title', 'editor' ],
			] );
		}

		// 'lf_page_like': hierarchical CPT with a custom slug — must be
		// skipped; a fixed {slug}/{postname} pattern can't model an arbitrary
		// ancestor path.
		if ( ! post_type_exists( 'lf_page_like' ) ) {
			register_post_type( 'lf_page_like', [
				'label'        => 'Page-like',
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs', 'with_front' => false ],
				'supports'     => [ 'title', 'editor', 'page-attributes' ],
			] );
		}

		// 'lf_no_slug': rewrite disabled entirely — nothing to prefix.
		if ( ! post_type_exists( 'lf_no_slug' ) ) {
			register_post_type( 'lf_no_slug', [
				'label'    => 'No Slug',
				'public'   => true,
				'rewrite'  => false,
				'supports' => [ 'title' ],
			] );
		}
	}

	public static function tearDownAfterClass(): void {
		unregister_post_type( 'lf_artwork' );
		unregister_post_type( 'lf_page_like' );
		unregister_post_type( 'lf_no_slug' );
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );
		update_option( 'linguaforge_routing_mode',     'path',            false );

		add_filter( 'lf_languages_list', static function ( array $langs ): array {
			return array_values( array_unique( array_merge( $langs, [ 'de', 'ca', 'es' ] ) ) );
		} );

		$this->flush_context_caches();
	}

	protected function tearDown(): void {
		remove_all_filters( 'lf_languages_list' );
		remove_all_filters( 'linguaforge_cpt_single_excluded_post_types' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function manager(): Manager {
		return Router::get_instance()->rewrite;
	}

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
	 * See CptArchiveIntegrationTest::capture_registration_rules() for the
	 * identical rationale.
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
	// 1. Non-hierarchical CPT with a custom rewrite slug → rule registered
	// =========================================================================

	public function test_cpt_with_custom_slug_registers_single_post_rule(): void {
		$rules = $this->capture_registration_rules();

		$found = false;
		foreach ( $rules as $pattern => $query ) {
			if ( str_contains( $query, 'post_type=lf_artwork' )
				&& str_contains( $query, 'name=$matches[2]' )
				&& str_contains( $pattern, '/art/' )
			) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found,
			'A language-prefixed single-post rule (post_type=lf_artwork, name=$matches[2]) must be registered for the "art" rewrite slug.' );
	}

	/**
	 * End-to-end: the generated pattern must actually match the exact URL
	 * shape Manager::rewrite_lang_permalink() produces for this CPT — the
	 * precise 404 confirmed live (/{lang}/art/{postname}/).
	 */
	public function test_generated_pattern_matches_the_url_lang_permalink_produces(): void {
		$rules = $this->capture_registration_rules();

		$translated_path = 'es/art/some-artwork-post';

		$matched = false;
		foreach ( $rules as $pattern => $query ) {
			if ( ! str_contains( $query, 'post_type=lf_artwork' ) ) {
				continue;
			}
			if ( preg_match( '#' . $pattern . '#', $translated_path ) ) {
				$matched = true;
				break;
			}
		}

		$this->assertTrue( $matched,
			'The registered rule must match "es/art/some-artwork-post" — the exact shape Manager::rewrite_lang_permalink() builds for a translated CPT post with a custom rewrite slug.' );
	}

	// =========================================================================
	// 2. Hierarchical CPT → excluded
	// =========================================================================

	public function test_hierarchical_cpt_generates_no_rule(): void {
		$rules = $this->capture_registration_rules();

		foreach ( $rules as $query ) {
			$this->assertStringNotContainsString(
				'post_type=lf_page_like',
				$query,
				'Hierarchical CPTs must not receive a single-post rewrite rule — a fixed {slug}/{postname} pattern cannot model a variable-depth ancestor path.'
			);
		}
	}

	// =========================================================================
	// 3. CPT with rewrite disabled → no rule generated
	// =========================================================================

	public function test_cpt_without_rewrite_slug_generates_no_rule(): void {
		$rules = $this->capture_registration_rules();

		foreach ( $rules as $query ) {
			$this->assertStringNotContainsString(
				'post_type=lf_no_slug',
				$query,
				'A CPT with rewrite disabled has no front-end permalink to prefix and must not receive a rule.'
			);
		}
	}

	// =========================================================================
	// 4. Default exclusions — built-ins and WooCommerce
	// =========================================================================

	public function test_built_in_and_woocommerce_post_types_excluded_by_default(): void {
		$rules = $this->capture_registration_rules();

		foreach ( $rules as $query ) {
			foreach ( [ 'post_type=post&', 'post_type=page&', 'post_type=product&', 'post_type=product_variation&' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, $query,
					"Built-in/WooCommerce post types must be excluded by default from the single-post rule generator ({$needle})." );
			}
		}
	}

	// =========================================================================
	// 5. Custom exclusion via filter
	// =========================================================================

	public function test_custom_exclusion_filter_is_respected(): void {
		add_filter(
			'linguaforge_cpt_single_excluded_post_types',
			static function ( array $excluded ): array {
				$excluded[] = 'lf_artwork';
				return $excluded;
			}
		);

		$rules = $this->capture_registration_rules();

		foreach ( $rules as $query ) {
			$this->assertStringNotContainsString(
				'post_type=lf_artwork',
				$query,
				'A post type added to linguaforge_cpt_single_excluded_post_types must not receive a rule.'
			);
		}
	}
}
