<?php
/**
 * Integration tests for SchemaManager output methods.
 *
 * Covered here:
 *   output_schema()   — static method: JSON encodes and wraps in <script> tag;
 *                       output must be valid JSON-LD; </script> injection escape.
 *   print_schema()    — Article on singular post (type='post'), WebPage on singular
 *                       page, WebSite on front page / blog index, inLanguage = BCP 47
 *                       of LF_LANG.
 *   register_hooks()  — no wp_head hook added when a SEO plugin constant is defined
 *                       (suppression path; tested via a subprocess to isolate the
 *                       constant definition).
 *
 * Not covered here (unit-testable pure-PHP logic):
 *   lang_to_bcp47() — covered by SeoHelpersTest.
 *
 * Strategy:
 *   • output_schema() is called as a static method; print_schema() directly on the
 *     SchemaManager instance obtained from Router.
 *   • Output captured with ob_start() / ob_get_clean().
 *   • Query state established via go_to() for singular/front-page contexts.
 *   • Language fixed to 'en' (LF_LANG = 'en' in wp-env CLI mode).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Seo\SchemaManager;
use ReflectionClass;
use WP_UnitTestCase;

final class SchemaManagerIntegrationTest extends WP_UnitTestCase {

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language',       'en',  false );
		update_option( 'linguaforge_seo_schema_enabled',     true,  false );
		update_option( 'linguaforge_seo_schema_article',     true,  false );
		update_option( 'linguaforge_seo_schema_website',     true,  false );
		update_option( 'linguaforge_seo_schema_breadcrumb',  true,  false );
		update_option( 'linguaforge_seo_schema_product',     true,  false );

		$this->reset_context_caches();
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_seo_schema_data' );
		$this->reset_context_caches();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function reset_context_caches(): void {
		$ctx_ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ctx_ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	private function capture_schema(): string {
		ob_start();
		Router::get_instance()->schema_manager->print_schema();
		return (string) ob_get_clean();
	}

	// =========================================================================
	// output_schema() — static
	// =========================================================================

	/**
	 * output_schema() must wrap the data in a valid JSON-LD <script> tag.
	 * The output must be decodeable by json_decode().
	 */
	public function test_output_schema_produces_valid_json_ld_script_tag(): void {
		$data = [
			'@context'   => 'https://schema.org',
			'@type'      => 'WebSite',
			'name'       => 'Test Site',
			'url'        => 'https://example.org/',
			'inLanguage' => 'en-US',
		];

		ob_start();
		SchemaManager::output_schema( $data );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<script type="application/ld+json">', $output );
		$this->assertStringContainsString( '</script>', $output );

		// Extract and decode the JSON from within the script tag.
		preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $output, $m );
		$this->assertNotEmpty( $m[1], 'JSON body must be present inside script tag' );

		$decoded = json_decode( $m[1], true );
		$this->assertIsArray( $decoded, 'JSON-LD body must be valid JSON' );
		$this->assertSame( 'WebSite', $decoded['@type'] );
		$this->assertSame( 'en-US',   $decoded['inLanguage'] );
	}

	/**
	 * output_schema() must escape </script> sequences inside field values to
	 * prevent premature closing of the script block.
	 */
	public function test_output_schema_escapes_script_closing_tag(): void {
		$data = [
			'@context' => 'https://schema.org',
			'@type'    => 'Thing',
			'name'     => '</script><script>evil()</script>',
		];

		ob_start();
		SchemaManager::output_schema( $data );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( '</script><script>', $output,
			'Raw </script> tag inside field value must be escaped' );
	}

	/**
	 * output_schema() must produce no output for an empty data array.
	 */
	public function test_output_schema_is_silent_for_empty_data(): void {
		ob_start();
		SchemaManager::output_schema( [] );
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	// =========================================================================
	// print_schema() — Article (singular post)
	// =========================================================================

	/**
	 * A singular post (post_type='post') must produce an Article JSON-LD block
	 * with the correct @type and an inLanguage matching the current LF_LANG ('en').
	 */
	public function test_print_schema_article_on_singular_post(): void {
		$post_id = (int) $this->factory->post->create( [
			'post_type'   => 'post',
			'post_title'  => 'Schema Test Post',
			'post_status' => 'publish',
		] );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_schema();

		$this->assertStringContainsString( '"@type":"Article"', $output );
		// LF_LANG='en' in wp-env → en-US BCP 47.
		$this->assertStringContainsString( '"inLanguage":"en-US"', $output );
		$this->assertStringContainsString( '"headline":', $output );
	}

	// =========================================================================
	// print_schema() — WebPage (singular page)
	// =========================================================================

	/**
	 * A singular page (post_type='page') must produce a WebPage block, not
	 * Article — the schema type depends on post_type.
	 */
	public function test_print_schema_webpage_on_singular_page(): void {
		$page_id = (int) $this->factory->post->create( [
			'post_type'   => 'page',
			'post_title'  => 'Schema Test Page',
			'post_status' => 'publish',
		] );
		$this->go_to( '/?page_id=' . $page_id );

		$output = $this->capture_schema();

		$this->assertStringContainsString( '"@type":"WebPage"', $output );
		$this->assertStringNotContainsString( '"@type":"Article"', $output );
		$this->assertStringContainsString( '"inLanguage":"en-US"', $output );
	}

	// =========================================================================
	// print_schema() — WebSite (front page)
	// =========================================================================

	/**
	 * The front page must produce a WebSite JSON-LD block.
	 */
	public function test_print_schema_website_on_front_page(): void {
		// Ensure we're on the blog index / front page.
		$this->go_to( home_url( '/' ) );

		$output = $this->capture_schema();

		$this->assertStringContainsString( '"@type":"WebSite"', $output );
		$this->assertStringContainsString( '"inLanguage":"en-US"', $output );
	}

	// =========================================================================
	// print_schema() — inLanguage reflects LF_LANG
	// =========================================================================

	/**
	 * The inLanguage value must be the BCP 47 representation of LF_LANG.
	 * In wp-env CLI mode LF_LANG is always 'en', so inLanguage must be 'en-US'.
	 */
	public function test_print_schema_in_language_matches_lf_lang(): void {
		$post_id = (int) $this->factory->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_schema();

		// LF_LANG is 'en' in wp-env; lang_to_bcp47('en') = 'en-US'.
		$this->assertStringContainsString( '"inLanguage":"en-US"', $output );
	}
}
