<?php
/**
 * Integration tests for SeoManager::print_og_tags() and resolve_og_mode().
 *
 * Covered here:
 *   print_og_tags() — og:locale present, og:locale:alternate excludes current
 *                     language, full mode outputs og:type/og:title/og:url,
 *                     locale-only mode omits base OG, disabled mode outputs nothing
 *   resolve_og_mode() — full / locale-only / disabled forced via option;
 *                       auto mode with no plugin detected → full
 *   get_og_description() — LF AI meta wins; excerpt wins over content; content
 *                          trimmed as last resort
 *
 * Strategy:
 *   • output captured with ob_start() / ob_get_clean()
 *   • Language list pinned to ['en','es','ca'] via lf_languages_list filter
 *   • Source language fixed to 'en' via linguaforge_primary_language option
 *   • LF_LANG is defined by the Router bootstrap in wp-env CLI mode as 'en'
 *   • Context caches are reset in setUp() and tearDown() so options changes
 *     take effect immediately.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class SeoManagerIntegrationTest extends WP_UnitTestCase {

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', 'en',    false );
		update_option( 'linguaforge_routing_mode',     'path',  false );
		update_option( 'linguaforge_seo_og_enabled',   true,    false );
		update_option( 'linguaforge_seo_og_mode',      'full',  false );

		$this->reset_context_caches();

		add_filter( 'lf_languages_list', [ $this, 'three_langs' ] );
	}

	protected function tearDown(): void {
		remove_filter( 'lf_languages_list', [ $this, 'three_langs' ] );
		remove_all_filters( 'linguaforge_seo_og_description' );
		remove_all_filters( 'linguaforge_seo_og_image' );
		remove_all_filters( 'linguaforge_seo_og_type' );
		$this->reset_context_caches();
		parent::tearDown();
	}

	/** @return string[] */
	public function three_langs(): array {
		return [ 'en', 'es', 'ca' ];
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

	private function capture_og(): string {
		ob_start();
		Router::get_instance()->seo_manager->print_og_tags();
		return (string) ob_get_clean();
	}

	// =========================================================================
	// og:locale and og:locale:alternate
	// =========================================================================

	/**
	 * print_og_tags() must always emit og:locale for the current language.
	 * LF_LANG is 'en' in wp-env CLI mode; the expected locale is 'en_US'.
	 */
	public function test_og_locale_is_emitted_for_current_language(): void {
		$output = $this->capture_og();
		$this->assertStringContainsString( 'property="og:locale"', $output );
		$this->assertStringContainsString( 'content="en_US"', $output );
	}

	/**
	 * og:locale:alternate must list all other configured languages but must NOT
	 * include the current language (en).
	 */
	public function test_og_locale_alternate_excludes_current_language(): void {
		$output = $this->capture_og();

		// Alternates for the other two languages.
		$this->assertStringContainsString( 'og:locale:alternate', $output );
		$this->assertStringContainsString( 'content="es_ES"', $output );
		$this->assertStringContainsString( 'content="ca_ES"', $output );

		// Count og:locale:alternate tags — must be exactly 2, not 3.
		$count = substr_count( $output, 'og:locale:alternate' );
		$this->assertSame( 2, $count,
			'og:locale:alternate must appear once per non-current language' );
	}

	// =========================================================================
	// Mode: full
	// =========================================================================

	/**
	 * In 'full' mode print_og_tags() must output the base OG set including
	 * og:type, og:title, og:url, and Twitter Card tags.
	 */
	public function test_full_mode_outputs_base_og_set(): void {
		update_option( 'linguaforge_seo_og_mode', 'full', false );

		$post_id = (int) $this->factory->post->create( [
			'post_title'  => 'Test OG Post',
			'post_status' => 'publish',
		] );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_og();

		$this->assertStringContainsString( 'property="og:type"',    $output );
		$this->assertStringContainsString( 'property="og:title"',   $output );
		$this->assertStringContainsString( 'property="og:url"',     $output );
		$this->assertStringContainsString( 'name="twitter:card"',   $output );
	}

	/**
	 * A singular post in full mode must emit og:type=article.
	 */
	public function test_full_mode_singular_post_has_og_type_article(): void {
		update_option( 'linguaforge_seo_og_mode', 'full', false );

		$post_id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_og();

		$this->assertStringContainsString( 'content="article"', $output );
	}

	// =========================================================================
	// Mode: locale-only
	// =========================================================================

	/**
	 * In 'locale-only' mode only og:locale and og:locale:alternate must be emitted
	 * — no og:type, og:title, og:url, or Twitter Card.
	 */
	public function test_locale_only_mode_omits_base_og_tags(): void {
		update_option( 'linguaforge_seo_og_mode', 'locale-only', false );

		$output = $this->capture_og();

		$this->assertStringContainsString( 'og:locale', $output );
		$this->assertStringNotContainsString( 'og:type',     $output );
		$this->assertStringNotContainsString( 'og:title',    $output );
		$this->assertStringNotContainsString( 'twitter:card', $output );
	}

	// =========================================================================
	// Mode: disabled
	// =========================================================================

	/**
	 * In 'disabled' mode print_og_tags() must produce no output at all.
	 */
	public function test_disabled_mode_outputs_nothing(): void {
		update_option( 'linguaforge_seo_og_mode', 'disabled', false );

		$output = $this->capture_og();

		$this->assertSame( '', $output );
	}

	// =========================================================================
	// Mode: auto (no plugin active)
	// =========================================================================

	/**
	 * In 'auto' mode with no SEO plugin or mu-plugin active, resolve_og_mode()
	 * must resolve to 'full'.
	 */
	public function test_auto_mode_without_seo_plugin_resolves_to_full(): void {
		update_option( 'linguaforge_seo_og_mode', 'auto', false );

		$mode = Router::get_instance()->seo_manager->resolve_og_mode();

		$this->assertSame( 'full', $mode );
	}

	// =========================================================================
	// Description fallback chain
	// =========================================================================

	/**
	 * The LF AI meta description (_linguaforge_meta_description) must take
	 * priority over post excerpt and content.
	 */
	public function test_og_description_uses_lf_meta_over_excerpt(): void {
		update_option( 'linguaforge_seo_og_mode', 'full', false );

		$post_id = (int) $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_excerpt' => 'The raw excerpt.',
			'post_content' => 'The body content.',
		] );
		update_post_meta( $post_id, '_linguaforge_meta_description', 'LF AI description' );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_og();

		$this->assertStringContainsString( 'LF AI description', $output );
		$this->assertStringNotContainsString( 'The raw excerpt.', $output );
	}

	/**
	 * When no LF meta description is set, the post excerpt must be used.
	 */
	public function test_og_description_falls_back_to_excerpt(): void {
		update_option( 'linguaforge_seo_og_mode', 'full', false );

		$post_id = (int) $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_excerpt' => 'This is the excerpt.',
			'post_content' => 'Body content that should be ignored.',
		] );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_og();

		$this->assertStringContainsString( 'This is the excerpt.', $output );
	}

	/**
	 * When no LF meta or excerpt exists, the post content (trimmed to 30 words)
	 * must be used as the og:description value.
	 */
	public function test_og_description_falls_back_to_trimmed_content(): void {
		update_option( 'linguaforge_seo_og_mode', 'full', false );

		$post_id = (int) $this->factory->post->create( [
			'post_status'  => 'publish',
			'post_excerpt' => '',
			'post_content' => 'Alpha Beta Gamma',
		] );
		$this->go_to( '/?p=' . $post_id );

		$output = $this->capture_og();

		$this->assertStringContainsString( 'Alpha Beta Gamma', $output );
	}
}
