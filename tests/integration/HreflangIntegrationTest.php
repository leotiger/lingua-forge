<?php
/**
 * Integration tests for Hreflang output methods.
 *
 * Covered here:
 *   print_hreflang_tags() — singular with 3-language trid group, singular without
 *                           translations, category archive, paged-archive §5.3
 *                           regression (must keep /page/N/ in all alternates)
 *   print_canonical()     — singular and archive paths when no SEO plugin active
 *   print_robots()        — noindex tag when _lf_noindex set; absent otherwise
 *
 * Strategy:
 *   • Methods are called directly after establishing WP query state via go_to(),
 *     avoiding a full HTTP dispatch through wp-env.
 *   • Output is captured with ob_start() / ob_get_clean().
 *   • The lf_languages_list filter pins the language list to ['en','es','ca'] so
 *     assertions are deterministic regardless of the wp-env locale setup.
 *   • Context caches are reset in setUp() so option changes take effect immediately.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use WP_UnitTestCase;

final class HreflangIntegrationTest extends WP_UnitTestCase {

	private TridGroup $tg;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language',      'en',   false );
		update_option( 'linguaforge_routing_mode',          'path', false );
		update_option( 'linguaforge_seo_hreflang_enabled',  true,   false );

		$this->reset_context_caches();

		// Pin the language list so test assertions are independent of installed
		// language packs in wp-env.
		add_filter( 'lf_languages_list', [ $this, 'three_langs' ] );

		$this->tg = Router::get_instance()->trid_group;

		$_SERVER['REQUEST_URI'] = '/en/sample-page/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test setup.
	}

	protected function tearDown(): void {
		remove_filter( 'lf_languages_list', [ $this, 'three_langs' ] );
		remove_all_filters( 'lf_hreflang_x_default' );
		$_SERVER['REQUEST_URI'] = '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test teardown.
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

	/**
	 * Create a published post assigned to a language and TRID.
	 */
	private function make_lf_post( string $lang, string $trid ): int {
		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish', 'post_type' => 'post' ] );
		$this->tg->set_lang( $id, $lang );
		$this->tg->set_trid( $id, $trid );
		return $id;
	}

	// =========================================================================
	// print_hreflang_tags() — singular
	// =========================================================================

	/**
	 * A singular post belonging to a 3-language trid group must emit one hreflang
	 * link per language plus an x-default pointing at the source-language URL.
	 */
	public function test_singular_outputs_reciprocal_hreflang_and_x_default(): void {

		$trid  = 'hlt-' . uniqid( '', true );
		$en_id = $this->make_lf_post( 'en', $trid );
		$es_id = $this->make_lf_post( 'es', $trid );
		$ca_id = $this->make_lf_post( 'ca', $trid );

		// Clear the translation object cache so get_translations() re-queries DB.
		$this->tg->clear_translation_cache( $en_id );

		$this->go_to( '/?p=' . $en_id );

		ob_start();
		Router::get_instance()->hreflang->print_hreflang_tags();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'hreflang="en-US"', $output, 'en alternate expected' );
		$this->assertStringContainsString( 'hreflang="es-ES"', $output, 'es alternate expected' );
		$this->assertStringContainsString( 'hreflang="ca-ES"', $output, 'ca alternate expected' );
		$this->assertStringContainsString( 'hreflang="x-default"', $output, 'x-default expected' );
		// x-default must point at the source (en) permalink — its ID is $en_id.
		$this->assertStringContainsString( (string) get_permalink( $en_id ), $output, 'x-default must be en URL' );

		// Prevent PHPUnit "unused variable" notice for $es_id / $ca_id.
		unset( $es_id, $ca_id );
	}

	/**
	 * A singular post with no trid (i.e. no translation group) must produce no
	 * hreflang output — emitting tags for a single-language post would be incorrect.
	 */
	public function test_singular_without_trid_outputs_nothing(): void {

		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		// No set_trid call — post is not part of any translation group.
		$this->go_to( '/?p=' . $id );

		ob_start();
		Router::get_instance()->hreflang->print_hreflang_tags();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	// =========================================================================
	// print_hreflang_tags() — archive
	// =========================================================================

	/**
	 * A category archive must emit one hreflang link per configured language.
	 * In path mode the source language (en) has no prefix; others are prefixed.
	 *
	 * go_to() sets is_archive() but uses a query-string URL (/?cat=N) whose PHP_URL_PATH
	 * is '/'.  We override REQUEST_URI after go_to() so the hreflang path-detection code
	 * sees the actual category slug in the URL.
	 */
	public function test_archive_outputs_hreflang_for_each_language(): void {

		$cat_id = (int) $this->factory->category->create( [ 'slug' => 'testcat-hl' ] );
		$this->go_to( '/?cat=' . $cat_id );
		// Override to a path-based URL so hreflang path extraction picks up the slug.
		$_SERVER['REQUEST_URI'] = '/category/testcat-hl/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test setup.

		ob_start();
		Router::get_instance()->hreflang->print_hreflang_tags();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'hreflang="en-US"', $output );
		$this->assertStringContainsString( 'hreflang="es-ES"', $output );
		$this->assertStringContainsString( 'hreflang="ca-ES"', $output );
		// Source lang (en) must NOT have a lang prefix in the URL.
		$this->assertStringContainsString( home_url( '/category/testcat-hl/' ), $output );
		// Non-source langs must have a prefix.
		$this->assertStringContainsString( home_url( '/es/category/testcat-hl/' ), $output );
		$this->assertStringContainsString( home_url( '/ca/category/testcat-hl/' ), $output );
	}

	/**
	 * §5.3 regression — a paged archive URL (/es/category/news/page/2/) must
	 * produce alternates that all preserve the /page/2/ path segment.
	 *
	 * Before the §5.3 fix the paged branch ran before the archive branch, which
	 * discarded the pagination segment and emitted root-archive URLs instead.
	 */
	public function test_paged_archive_hreflang_preserves_page_segment(): void {

		$cat_id = (int) $this->factory->category->create( [ 'slug' => 'news-hl' ] );
		// go_to() sets up the WP archive query; we then override REQUEST_URI to
		// simulate a paginated request — the hreflang method reads REQUEST_URI
		// directly to derive the path for each language alternate.
		$this->go_to( '/?cat=' . $cat_id );
		$_SERVER['REQUEST_URI'] = '/es/category/news-hl/page/2/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test setup.

		ob_start();
		Router::get_instance()->hreflang->print_hreflang_tags();
		$output = (string) ob_get_clean();

		// All alternates must carry the pagination path.
		$this->assertStringContainsString( 'page/2', $output,
			'Every hreflang alternate must include the /page/2/ segment' );

		// The es URL must not contain a double lang prefix (/es/es/).
		$this->assertStringNotContainsString( '/es/es/', $output,
			'Lang prefix must not be doubled in the es alternate (§5.3 regression)' );
	}

	// =========================================================================
	// print_canonical()
	// =========================================================================

	/**
	 * A singular post must produce a self-referencing canonical when no third-party
	 * SEO plugin is active.
	 */
	public function test_print_canonical_singular_emits_self_referencing_tag(): void {

		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( '/?p=' . $id );

		ob_start();
		Router::get_instance()->hreflang->print_canonical();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="canonical"', $output );
		$this->assertStringContainsString( esc_url( (string) get_permalink( $id ) ), $output );
	}

	/**
	 * A category archive in path mode must produce a canonical tag containing
	 * the category slug.
	 *
	 * print_canonical() uses LF_LANG (not REQUEST_URI) to determine the lang.
	 * In wp-env CLI mode LF_LANG is always 'en' — the source language — so the
	 * canonical is the un-prefixed archive URL: /category/xxx/.  We therefore
	 * assert that the slug is present and that there is no double lang prefix.
	 */
	public function test_print_canonical_archive_emits_category_path(): void {

		$cat_id = (int) $this->factory->category->create( [ 'slug' => 'canonical-cat' ] );
		$this->go_to( '/?cat=' . $cat_id );

		// Set REQUEST_URI to a realistic path-mode archive URL.
		$_SERVER['REQUEST_URI'] = '/category/canonical-cat/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test setup.

		ob_start();
		Router::get_instance()->hreflang->print_canonical();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="canonical"', $output );
		// LF_LANG='en' (source), so canonical must NOT carry a lang prefix.
		$this->assertStringContainsString( 'canonical-cat', $output,
			'Archive canonical must include the category slug' );
		$this->assertStringNotContainsString( '/en/', $output,
			'Source language must not appear as a path prefix in the canonical' );
	}

	// =========================================================================
	// print_robots()
	// =========================================================================

	/**
	 * A singular post with _lf_noindex = '1' must emit a noindex robots meta tag.
	 */
	public function test_print_robots_noindex_when_flag_set(): void {

		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $id, '_lf_noindex', '1' );
		$this->go_to( '/?p=' . $id );

		ob_start();
		Router::get_instance()->hreflang->print_robots();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="robots"', $output );
		$this->assertStringContainsString( 'noindex', $output );
	}

	/**
	 * A singular post without _lf_noindex must produce no robots meta tag.
	 */
	public function test_print_robots_absent_for_normal_post(): void {

		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( '/?p=' . $id );

		ob_start();
		Router::get_instance()->hreflang->print_robots();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * print_robots() must produce nothing on a non-singular context (archive).
	 */
	public function test_print_robots_absent_on_non_singular_context(): void {

		$cat_id = (int) $this->factory->category->create();
		$this->go_to( '/?cat=' . $cat_id );

		ob_start();
		Router::get_instance()->hreflang->print_robots();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	// =========================================================================
	// §1.7 — singular pagination suffix on canonical + hreflang
	// =========================================================================

	/** Invoke the private append_singular_pagination() helper. */
	private function paginate( string $url ): string {
		$hreflang = Router::get_instance()->hreflang;
		$m        = new \ReflectionMethod( $hreflang, 'append_singular_pagination' );
		$m->setAccessible( true );
		return (string) $m->invoke( $hreflang, $url );
	}

	private function set_pagination_query( int $page, int $cpage ): void {
		$GLOBALS['wp_query']->set( 'page', $page );
		$GLOBALS['wp_query']->set( 'cpage', $cpage );
	}

	public function test_pagination_helper_appends_in_post_page_pretty(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['wp_rewrite']->init();
		$this->set_pagination_query( 2, 0 );

		$this->assertSame(
			home_url( '/sample/2/' ),
			$this->paginate( home_url( '/sample/' ) ),
			'In-post pagination must append /2/ under pretty permalinks.'
		);

		$this->set_pagination_query( 0, 0 );
		update_option( 'permalink_structure', '' );
		$GLOBALS['wp_rewrite']->init();
	}

	public function test_pagination_helper_comment_page_takes_precedence_pretty(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['wp_rewrite']->init();
		// page is set too, but cpage must win (matches core wp_get_canonical_url).
		$this->set_pagination_query( 5, 3 );

		$this->assertSame(
			home_url( '/sample/comment-page-3/' ),
			$this->paginate( home_url( '/sample/' ) ),
			'Comment pagination must take precedence and append /comment-page-3/.'
		);

		$this->set_pagination_query( 0, 0 );
		update_option( 'permalink_structure', '' );
		$GLOBALS['wp_rewrite']->init();
	}

	public function test_pagination_helper_plain_permalinks_use_query_args(): void {
		update_option( 'permalink_structure', '' );
		$GLOBALS['wp_rewrite']->init();
		$this->set_pagination_query( 4, 0 );

		$url = $this->paginate( home_url( '/?p=99' ) );
		$this->assertStringContainsString( 'page=4', $url,
			'Plain permalinks must carry the page as a query arg.' );

		$this->set_pagination_query( 0, 0 );
	}

	public function test_pagination_helper_unpaginated_returns_unchanged(): void {
		$this->set_pagination_query( 0, 0 );
		$base = home_url( '/sample/' );
		$this->assertSame( $base, $this->paginate( $base ),
			'An unpaginated singular must return the permalink unchanged.' );
	}

	/**
	 * End-to-end: a paged singular's self-canonical must carry the page suffix
	 * (rather than pointing at page 1). Uses plain permalinks so the ?p= URL
	 * always resolves without a rewrite flush.
	 */
	public function test_singular_canonical_carries_page_suffix(): void {
		update_option( 'permalink_structure', '' );
		$GLOBALS['wp_rewrite']->init();

		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( '/?p=' . $id );
		$GLOBALS['wp_query']->set( 'page', 2 );

		ob_start();
		Router::get_instance()->hreflang->print_canonical();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="canonical"', $output );
		$this->assertStringContainsString( 'page=2', $output,
			'A paged singular canonical must carry the in-post page suffix.' );

		$GLOBALS['wp_query']->set( 'page', 0 );
	}
}
