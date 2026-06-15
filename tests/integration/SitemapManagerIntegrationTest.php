<?php
/**
 * Integration tests for SitemapManager XML generation and cache management.
 *
 * Covered here:
 *   get_sitemap_chunk_xml() — alternates per <url> for a trid group, x-default
 *                             points at source-language URL, lastmod = newest
 *                             sibling, excluded post types absent from output
 *   get_sitemap_xml()       — sitemap index lists chunk URLs; empty DB produces
 *                             valid empty sitemapindex XML
 *   flush_on_save()         — only flushes cache when the saved post has a _lf_trid;
 *                             revisions and posts without trid are skipped
 *   append_robots_txt()     — adds Sitemap: directive when site is public; skips
 *                             when site is not public
 *
 * Strategy:
 *   • flush_cache() is called in setUp() so every test starts with a cold cache.
 *   • Posts are created via the factory with _lf_trid and _lf_lang set through
 *     TridGroup — the same mechanism used by the plugin at runtime.
 *   • The sitemap DB query and get_permalink() run inside the WP_UnitTestCase
 *     transaction, so all created data is visible to the query.
 *   • Context caches are reset in setUp() / tearDown() so source-language option
 *     changes take effect.
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

final class SitemapManagerIntegrationTest extends WP_UnitTestCase {

	private TridGroup $tg;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language',      'en',  false );
		update_option( 'linguaforge_routing_mode',          'path', false );
		update_option( 'linguaforge_seo_sitemap_enabled',   true,  false );

		$this->reset_context_caches();

		$this->tg = Router::get_instance()->trid_group;

		// Start every test with a cold sitemap cache.
		Router::get_instance()->sitemap_manager->flush_cache();
	}

	protected function tearDown(): void {
		Router::get_instance()->sitemap_manager->flush_cache();
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

	/**
	 * Create a published post assigned to a language and TRID.
	 *
	 * @param  string $lang
	 * @param  string $trid
	 * @param  string $post_type
	 * @return int    New post ID.
	 */
	private function make_lf_post( string $lang, string $trid, string $post_type = 'post' ): int {
		$id = (int) $this->factory->post->create( [
			'post_type'   => $post_type,
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $id, $lang );
		$this->tg->set_trid( $id, $trid );
		return $id;
	}

	private function trid(): string {
		return 'sm-trid-' . uniqid( '', true );
	}

	// =========================================================================
	// get_sitemap_chunk_xml() — alternates
	// =========================================================================

	/**
	 * A trid group with two languages must produce a chunk XML that includes
	 * <xhtml:link> alternates for both language URLs in every <url> block.
	 */
	public function test_chunk_xml_includes_alternates_for_trid_group(): void {
		$trid  = $this->trid();
		$en_id = $this->make_lf_post( 'en', $trid );
		$es_id = $this->make_lf_post( 'es', $trid );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		// Both language URLs must appear as <loc> entries.
		$this->assertStringContainsString( get_permalink( $en_id ), $chunk );
		$this->assertStringContainsString( get_permalink( $es_id ), $chunk );

		// Each <url> must carry xhtml:link alternates.
		$this->assertStringContainsString( 'xhtml:link', $chunk );
		$this->assertStringContainsString( 'hreflang="en-US"', $chunk );
		$this->assertStringContainsString( 'hreflang="es-ES"', $chunk );
	}

	/**
	 * The x-default alternate in each <url> block must point at the source-language
	 * URL (the 'en' permalink when linguaforge_primary_language = 'en').
	 */
	public function test_x_default_points_at_source_language_url(): void {
		$trid  = $this->trid();
		$en_id = $this->make_lf_post( 'en', $trid );
		$es_id = $this->make_lf_post( 'es', $trid );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		$en_url = esc_url( (string) get_permalink( $en_id ) );
		// x-default must be associated with the en URL.
		$this->assertMatchesRegularExpression(
			'#hreflang="x-default" href="' . preg_quote( $en_url, '#' ) . '"#',
			$chunk,
			'x-default must point at the source-language (en) URL'
		);

		unset( $es_id );
	}

	/**
	 * The <lastmod> value in a <url> block must be the ISO 8601 date of the
	 * most recently modified sibling in the trid group.
	 */
	public function test_lastmod_is_newest_sibling_modified_date(): void {
		$trid = $this->trid();

		// Create an en post and backdate its modified time.
		$en_id = (int) $this->factory->post->create( [
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_modified_gmt' => '2024-01-01 10:00:00',
			'post_date_gmt'     => '2024-01-01 10:00:00',
		] );
		$this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $en_id, $trid );

		// Create an es post with a more recent modified time.
		$es_id = (int) $this->factory->post->create( [
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_modified_gmt' => '2025-06-01 12:00:00',
			'post_date_gmt'     => '2025-06-01 12:00:00',
		] );
		$this->tg->set_lang( $es_id, 'es' );
		$this->tg->set_trid( $es_id, $trid );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		// The lastmod tag must reflect 2025-06-01 (the newer sibling).
		$this->assertStringContainsString( '<lastmod>', $chunk );
		$this->assertStringContainsString( '2025-06-01', $chunk );
		// The older date must not appear as lastmod.
		$this->assertStringNotContainsString( '2024-01-01', $chunk );
	}

	/**
	 * Post types excluded from the sitemap (shop_order, attachment, nav_menu_item, …)
	 * must not appear in the chunk XML even when they carry _lf_trid meta.
	 *
	 * shop_order is not registered in unit-test wp-env (no WC), so we use
	 * 'nav_menu_item' which IS always registered but always excluded.
	 */
	public function test_excluded_post_type_absent_from_chunk_xml(): void {
		$trid = $this->trid();

		// Create a nav_menu_item post with lf meta — it must be filtered out.
		$excl_id = (int) $this->factory->post->create( [
			'post_type'   => 'nav_menu_item',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $excl_id, 'en' );
		$this->tg->set_trid( $excl_id, $trid );

		// Create a normal post so the chunk is non-empty (excluded post type only
		// means we test that the excluded one is absent, not that the sitemap is empty).
		$trid2  = $this->trid();
		$reg_id = $this->make_lf_post( 'en', $trid2 );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		// The normal post must appear; the excluded type must not.
		$this->assertStringContainsString( get_permalink( $reg_id ), $chunk,
			'Normal post must be included in the sitemap' );
		$this->assertStringNotContainsString( 'ID=' . $excl_id, $chunk,
			'Excluded post type must not appear in the sitemap' );
	}

	// =========================================================================
	// get_sitemap_xml() — index
	// =========================================================================

	/**
	 * When there are no LF-managed posts the sitemap must still return valid
	 * XML — a <sitemapindex> root with no <sitemap> children.
	 */
	public function test_empty_db_produces_valid_empty_sitemapindex(): void {
		// No posts created — sitemap must handle empty DB gracefully.
		$xml = Router::get_instance()->sitemap_manager->get_sitemap_xml();

		$this->assertStringContainsString( '<?xml', $xml );
		$this->assertStringContainsString( '<sitemapindex', $xml );
		$this->assertStringNotContainsString( '<sitemap>', $xml,
			'Empty DB sitemap index must contain no <sitemap> children' );
	}

	/**
	 * When there are LF-managed posts the sitemap index must list at least one
	 * chunk URL pointing at lf-sitemap-0.xml.
	 */
	public function test_sitemap_index_lists_chunk_url_when_posts_exist(): void {
		$this->make_lf_post( 'en', $this->trid() );

		$xml = Router::get_instance()->sitemap_manager->get_sitemap_xml();

		$this->assertStringContainsString( '<sitemapindex', $xml );
		$this->assertStringContainsString( 'lf-sitemap-0.xml', $xml,
			'Sitemap index must include a link to the first chunk' );
	}

	// =========================================================================
	// flush_on_save()
	// =========================================================================

	/**
	 * flush_on_save() must clear the sitemap cache when the saved post belongs
	 * to a trid group (has _lf_trid meta).
	 */
	public function test_flush_on_save_clears_cache_for_trid_post(): void {
		$trid = $this->trid();
		$id   = $this->make_lf_post( 'en', $trid );

		// Warm the cache by triggering generation.
		Router::get_instance()->sitemap_manager->get_sitemap_xml();
		$this->assertNotFalse( get_transient( 'linguaforge_sitemap_xml' ),
			'Cache must be warm before the flush test' );

		// Calling flush_on_save() directly with a trid post must clear the transient.
		Router::get_instance()->sitemap_manager->flush_on_save( $id );

		$this->assertFalse( get_transient( 'linguaforge_sitemap_xml' ),
			'Sitemap cache must be cleared after flush_on_save() for a trid post' );
	}

	/**
	 * flush_on_save() must NOT clear the sitemap cache for a post that has no
	 * _lf_trid meta — non-LF posts must not invalidate the sitemap on every save.
	 */
	public function test_flush_on_save_skips_post_without_trid(): void {
		// Warm the cache.
		Router::get_instance()->sitemap_manager->get_sitemap_xml();

		// Create a plain post with no trid.
		$id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );

		Router::get_instance()->sitemap_manager->flush_on_save( $id );

		$this->assertNotFalse( get_transient( 'linguaforge_sitemap_xml' ),
			'Sitemap cache must not be cleared when the saved post has no _lf_trid' );
	}

	// =========================================================================
	// append_robots_txt()
	// =========================================================================

	/**
	 * append_robots_txt() must append a Sitemap: directive when the site is public.
	 */
	public function test_robots_txt_includes_sitemap_directive_when_public(): void {
		$output = Router::get_instance()->sitemap_manager->append_robots_txt( 'User-agent: *', '1' );

		$this->assertStringContainsString( 'Sitemap:', $output );
		$this->assertStringContainsString( 'lf-sitemap.xml', $output );
	}

	/**
	 * append_robots_txt() must leave the robots.txt unchanged when the site
	 * is not public (blog_public = 0).
	 */
	public function test_robots_txt_unchanged_when_site_not_public(): void {
		$original = 'User-agent: *';
		$output   = Router::get_instance()->sitemap_manager->append_robots_txt( $original, '0' );

		$this->assertSame( $original, $output,
			'robots.txt must not receive a Sitemap: directive when the site is not public' );
	}

	// =========================================================================
	// §1.4 — chunk eviction self-heal (chunk transient evicted, index survives)
	// =========================================================================

	/**
	 * When a chunk transient is evicted independently of the index (object-cache
	 * per-key eviction), get_sitemap_chunk_xml() must regenerate and serve the
	 * real URLs rather than a valid-but-empty <urlset>.
	 */
	public function test_chunk_regenerated_after_independent_eviction(): void {
		$trid  = $this->trid();
		$en_id = $this->make_lf_post( 'en', $trid );

		$sm = Router::get_instance()->sitemap_manager;

		// Warm the cache (writes the index, chunk 0, and the chunk-count option).
		$sm->get_sitemap_xml();
		$this->assertNotFalse( get_transient( 'linguaforge_sitemap_xml' ),
			'Index must be warm before the eviction test.' );

		// Simulate per-key eviction: drop chunk 0 but leave the index intact.
		delete_transient( 'linguaforge_sitemap_chunk_0' );
		$this->assertFalse( get_transient( 'linguaforge_sitemap_chunk_0' ) );

		$chunk = $sm->get_sitemap_chunk_xml( 0 );

		$this->assertStringContainsString( '<url>', $chunk,
			'An evicted in-range chunk must be regenerated, not served empty.' );
		$this->assertStringContainsString( (string) get_permalink( $en_id ), $chunk );
		// And the transient must be repopulated for subsequent requests.
		$this->assertNotFalse( get_transient( 'linguaforge_sitemap_chunk_0' ) );
	}

	/**
	 * An out-of-range chunk index must return a valid empty <urlset> (and must
	 * not loop into a rebuild on every request — guarded by the chunk-count
	 * range check).
	 */
	public function test_out_of_range_chunk_returns_empty_urlset(): void {
		$this->make_lf_post( 'en', $this->trid() );

		$sm = Router::get_instance()->sitemap_manager;
		$sm->get_sitemap_xml(); // warm — one chunk (index 0) exists.

		$chunk = $sm->get_sitemap_chunk_xml( 999 );

		$this->assertStringContainsString( '<urlset', $chunk );
		$this->assertStringNotContainsString( '<url>', $chunk,
			'An out-of-range chunk must be an empty urlset.' );
	}
}
