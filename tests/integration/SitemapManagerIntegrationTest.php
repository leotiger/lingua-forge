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
 *   maybe_serve_sitemap()   — returns without exiting on a non-matching request
 *   send_xml_headers()      — status_header( 200 ), nocache_headers(), Content-Type,
 *                             and X-Robots-Tag on the matching path. status_header( 200 )
 *                             was added after a chunk URL was confirmed live (curl
 *                             against cal-talaia.cat) to come back as a 404 with a
 *                             correct PHP-generated body — the same root cause as
 *                             IndexNowManager's key-file route; nocache_headers()
 *                             was added 2.4.1. The matching branch of
 *                             maybe_serve_sitemap() itself ends in `exit` via
 *                             serve_xml() and so cannot be exercised end-to-end
 *                             under PHPUnit; send_xml_headers() is invoked
 *                             directly via ReflectionMethod instead, the same
 *                             pattern used in IndexNowManagerIntegrationTest.
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
use LinguaForge\Router\Seo\SitemapManager;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use ReflectionMethod;
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

		// Default every test in this file to a static front page so the
		// pre-existing assertions below (empty DB → empty sitemap, etc.) are
		// unaffected by the synthetic-homepage-entry behaviour added for
		// "Your latest posts" mode — tests further down opt into 'posts'
		// explicitly where that behaviour is what's under test.
		update_option( 'show_on_front', 'page' );

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

	// =========================================================================
	// maybe_serve_sitemap() — non-matching request must not exit
	// =========================================================================

	/**
	 * maybe_serve_sitemap() must return normally (no exit) for a URL that is
	 * neither the sitemap index nor a chunk file.
	 */
	public function test_maybe_serve_sitemap_returns_on_non_matching_request(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test save/restore of a server superglobal; value is only stored and restored verbatim, never used as input.
		$saved = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/an-ordinary-page/';

		Router::get_instance()->sitemap_manager->maybe_serve_sitemap();

		if ( null === $saved ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $saved;
		}

		$this->assertTrue( true, 'maybe_serve_sitemap() returned without exiting on a non-matching request.' );
	}

	// =========================================================================
	// §2.4.1 — send_xml_headers() — nocache regression coverage
	// =========================================================================

	/**
	 * send_xml_headers() must call nocache_headers(), which sends a
	 * Cache-Control: no-cache directive.
	 *
	 * This is the exact behaviour added in 2.4.1 to fix a full-page cache/CDN
	 * freezing a stale (typically 404) response for a sitemap chunk URL, which
	 * let Googlebot's anonymous fetch of a <sitemap><loc> from the index go
	 * undiscovered even though the chunk loaded fine for a logged-in admin whose
	 * requests bypass such caches.
	 *
	 * Unlike status_header() (see test_send_xml_headers_sends_status_200()
	 * below), WP core's nocache_headers() checks headers_sent() *before* doing
	 * anything at all — including firing its own 'nocache_headers' filter — so
	 * that filter can never be observed once headers are already sent, which is
	 * permanently true for the rest of this process from the moment WordPress's
	 * own PHPUnit bootstrap first writes output. headers_list() doesn't help
	 * either: it's always empty under the CLI SAPI.
	 *
	 * So this call is verified two ways instead: (1) the method's own source
	 * text is inspected via reflection to confirm the literal nocache_headers()
	 * call is still present, and (2) the method must complete without throwing
	 * despite headers already being sent — regression-guarding the
	 * headers_sent() guard 2.4.2 added around this method's own explicit
	 * header() calls (WP core's status_header()/nocache_headers() already guard
	 * themselves; ours didn't, and crashed under exactly this condition until
	 * that guard was added).
	 *
	 * The method is invoked directly via reflection (it is private, and is
	 * always followed by `echo $xml; exit;` in the real call site, serve_xml(),
	 * which PHPUnit cannot observe past).
	 */
	public function test_send_xml_headers_calls_nocache_headers(): void {
		$method = new ReflectionMethod( SitemapManager::class, 'send_xml_headers' );

		$source     = (string) file_get_contents( (string) $method->getFileName() );
		$body_lines = array_slice(
			explode( "\n", $source ),
			$method->getStartLine() - 1,
			$method->getEndLine() - $method->getStartLine() + 1
		);
		$this->assertStringContainsString( 'nocache_headers();', implode( "\n", $body_lines ),
			'send_xml_headers() must call nocache_headers() to prevent a full-page cache/CDN from freezing a stale response.' );

		$method->setAccessible( true );
		$method->invoke( Router::get_instance()->sitemap_manager );
		$this->addToAssertionCount( 1 ); // Reaching here means it didn't crash despite headers already being sent.
	}

	/**
	 * send_xml_headers() must call status_header( 200 ).
	 *
	 * Regression coverage for a confirmed live failure: curl against
	 * cal-talaia.cat with WordPress's own User-Agent got HTTP/2 404 back for
	 * a chunk URL (/lf-sitemap-0.xml), with the correct XML body and
	 * x-powered-by: PHP confirming this handler generated the response. A
	 * chunk URL never matches a real post/page/rewrite rule, so WordPress's
	 * own request parsing has already called status_header( 404 ) before
	 * template_redirect fires — the same root cause fixed in
	 * IndexNowManager::send_key_file_headers(). Same CLI-header limitation as
	 * test_send_xml_headers_calls_nocache_headers() above: asserted via WP
	 * core's 'status_header' filter rather than inspecting sent headers
	 * directly.
	 */
	public function test_send_xml_headers_sends_status_200(): void {
		$captured_code = null;
		add_filter(
			'status_header',
			function ( $status_header, $code ) use ( &$captured_code ) {
				$captured_code = $code;
				return $status_header;
			},
			10,
			2
		);

		$method = new ReflectionMethod( SitemapManager::class, 'send_xml_headers' );
		$method->setAccessible( true );
		$method->invoke( Router::get_instance()->sitemap_manager );

		remove_all_filters( 'status_header' );

		$this->assertSame( 200, $captured_code,
			'send_xml_headers() must call status_header( 200 ) to override any 404 status WordPress already queued for this unmatched URL.' );
	}

	// =========================================================================
	// Synthetic homepage entry — "Your latest posts" front page
	// =========================================================================

	/**
	 * When the front page shows the latest posts (Settings → Reading), the
	 * sitemap must include a synthetic entry for the language-prefixed homepage
	 * of every active language — with hreflang alternates — even when no
	 * LF-managed post exists yet. Without this, /es/, /fr/, … are real,
	 * crawlable URLs that would otherwise never appear in the sitemap.
	 */
	public function test_latest_posts_front_adds_homepage_entries_for_every_language(): void {
		update_option( 'show_on_front', 'posts' );
		add_filter( 'lf_languages_list', static fn( array $l ): array =>
			array_values( array_unique( array_merge( $l, [ 'es', 'fr' ] ) ) ) );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		remove_all_filters( 'lf_languages_list' );

		$this->assertStringContainsString( home_url( '/' ), $chunk,
			'Source-language homepage (bare root) must appear in the sitemap.' );
		$this->assertStringContainsString( home_url( '/es/' ), $chunk,
			'Spanish language-prefixed homepage must appear in the sitemap.' );
		$this->assertStringContainsString( home_url( '/fr/' ), $chunk,
			'French language-prefixed homepage must appear in the sitemap.' );
		$this->assertStringContainsString( 'hreflang="es-ES"', $chunk );
		$this->assertStringContainsString( 'hreflang="fr-FR"', $chunk );
	}

	/**
	 * The synthetic homepage entry must respect subdomain routing mode — each
	 * language's homepage URL must be its own subdomain root, not a path prefix
	 * on the primary domain.
	 */
	public function test_latest_posts_front_uses_subdomains_in_subdomain_mode(): void {
		update_option( 'show_on_front', 'posts' );
		update_option( 'linguaforge_routing_mode', 'subdomain', false );
		$this->reset_context_caches();
		add_filter( 'lf_languages_list', static fn( array $l ): array =>
			array_values( array_unique( array_merge( $l, [ 'es' ] ) ) ) );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		remove_all_filters( 'lf_languages_list' );
		update_option( 'linguaforge_routing_mode', 'path', false );
		$this->reset_context_caches();

		$this->assertStringContainsString( 'es.', $chunk,
			'Subdomain-mode homepage entry must use the es. subdomain, not a /es/ path prefix.' );
		$this->assertStringNotContainsString( '/es/', $chunk,
			'Subdomain-mode homepage entry must not also carry a path prefix.' );
	}

	/**
	 * A static front page must NOT receive a synthetic homepage entry — this
	 * is the pre-existing behaviour (a static front page is a normal Page post,
	 * already covered by the DB query when it carries _lf_trid meta) and must
	 * remain unaffected by the new "Your latest posts" support.
	 */
	public function test_static_front_page_does_not_add_synthetic_homepage_entry(): void {
		// setUp() already sets show_on_front = 'page'; assert explicitly so this
		// test's intent survives even if that default changes later.
		$this->assertSame( 'page', get_option( 'show_on_front' ) );

		$xml = Router::get_instance()->sitemap_manager->get_sitemap_xml();

		$this->assertStringNotContainsString( '<sitemap>', $xml,
			'Static front page (no LF-managed posts) must not receive a synthetic sitemap homepage entry.' );
	}

	// =========================================================================
	// linguaforge_sitemap_extra_urls (2.7.1) — third-party URL groups
	// =========================================================================

	/**
	 * A companion plugin (e.g. Agnosis registering per-artist community
	 * subdomains LF has no post/language model for) supplies a group via the
	 * filter; its URL must appear in the sitemap chunk even though no LF post
	 * exists for it at all.
	 */
	public function test_extra_urls_filter_adds_third_party_url(): void {
		add_filter( 'linguaforge_sitemap_extra_urls', static function ( array $groups ): array {
			$groups['artist-42'] = [
				[ 'url' => 'https://someartist.example.com/', 'lang' => 'en' ],
			];
			return $groups;
		} );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		remove_all_filters( 'linguaforge_sitemap_extra_urls' );

		$this->assertStringContainsString( 'https://someartist.example.com/', $chunk,
			'A URL supplied via linguaforge_sitemap_extra_urls must appear in the sitemap even with no LF-managed posts.' );
	}

	/**
	 * Rows within one supplied group must be emitted as hreflang alternates of
	 * each other, exactly like a native LF translation group.
	 */
	public function test_extra_urls_filter_rows_become_hreflang_alternates(): void {
		add_filter( 'linguaforge_sitemap_extra_urls', static function ( array $groups ): array {
			$groups['artist-42'] = [
				[ 'url' => 'https://someartist.example.com/', 'lang' => 'en' ],
				[ 'url' => 'https://someartist.example.com/es/', 'lang' => 'es' ],
			];
			return $groups;
		} );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		remove_all_filters( 'linguaforge_sitemap_extra_urls' );

		$this->assertStringContainsString( 'hreflang="en-US" href="https://someartist.example.com/"', $chunk );
		$this->assertStringContainsString( 'hreflang="es-ES" href="https://someartist.example.com/es/"', $chunk );
	}

	/**
	 * A malformed row (missing url or lang) must be silently dropped rather
	 * than breaking generation for every other group — a third-party plugin
	 * bug must not be able to take down LF's own sitemap.
	 */
	public function test_extra_urls_filter_drops_malformed_rows(): void {
		$this->make_lf_post( 'en', $this->trid() );

		add_filter( 'linguaforge_sitemap_extra_urls', static function ( array $groups ): array {
			$groups['broken']    = [ [ 'lang' => 'en' ] ]; // missing url
			$groups['also-broken'] = [ [ 'url' => 'https://example.com/' ] ]; // missing lang
			return $groups;
		} );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		remove_all_filters( 'linguaforge_sitemap_extra_urls' );

		$this->assertStringNotContainsString( 'https://example.com/', $chunk,
			'A row missing "lang" must be dropped, not emitted with an empty hreflang.' );
	}

	/**
	 * A caller-supplied group key must never collide with a real _lf_trid
	 * value — extra groups are re-namespaced under 'lf_extra_' internally, so
	 * a native LF group and a third-party group can coexist safely in the
	 * same chunk.
	 */
	public function test_extra_urls_coexist_with_native_trid_group(): void {
		$en_id = $this->make_lf_post( 'en', $this->trid() );

		add_filter( 'linguaforge_sitemap_extra_urls', static function ( array $groups ): array {
			$groups['artist-42'] = [
				[ 'url' => 'https://someartist.example.com/', 'lang' => 'en' ],
			];
			return $groups;
		} );

		$chunk = Router::get_instance()->sitemap_manager->get_sitemap_chunk_xml( 0 );

		remove_all_filters( 'linguaforge_sitemap_extra_urls' );

		$this->assertStringContainsString( (string) get_permalink( $en_id ), $chunk,
			'Native LF-managed post must still appear alongside a third-party group.' );
		$this->assertStringContainsString( 'https://someartist.example.com/', $chunk,
			'Third-party group must appear alongside a native LF-managed post.' );
	}
}
