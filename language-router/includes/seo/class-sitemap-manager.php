<?php
/**
 * Class LinguaForge\Router\Seo\SitemapManager
 *
 * Generates a dedicated multilingual XML sitemap at /lf-sitemap.xml that
 * includes <xhtml:link rel="alternate" hreflang> entries for every
 * translation group LF manages.
 *
 * ── Why a separate sitemap ────────────────────────────────────────────────
 * The WordPress 5.5+ built-in sitemap has no hook to inject <xhtml:link>
 * elements into individual <url> blocks — its XML renderer is closed.
 * SEO plugin sitemaps (Yoast, Rank Math) replace the WP sitemap entirely
 * but have no knowledge of LF's routing configuration.
 *
 * LF disables the WP core sitemap entirely (wp_sitemaps_enabled → false)
 * and replaces it with /lf-sitemap.xml, which includes the hreflang
 * alternates that search engines need to index multilingual content.
 *
 * Submit /lf-sitemap.xml to Google Search Console.
 *
 * ── Discovery ─────────────────────────────────────────────────────────────
 * The sitemap URL is announced via a Sitemap: directive in robots.txt so
 * crawlers find it automatically without manual submission.
 *
 * ── Caching ───────────────────────────────────────────────────────────────
 * The sitemap is split into a sitemap index at /lf-sitemap.xml and per-chunk
 * urlset files at /lf-sitemap-0.xml, /lf-sitemap-1.xml, …  Each chunk holds
 * up to GROUPS_PER_CHUNK (1 000) TRID groups.  All chunks are generated in a
 * single DB query and cached in separate transients (24 h TTL).  The index
 * transient key is linguaforge_sitemap_xml; chunk keys are
 * linguaforge_sitemap_chunk_{N}.  The cache is flushed automatically on any
 * save_post that affects an LF-managed post and can be flushed manually from
 * the admin panel.
 *
 * ── Options ───────────────────────────────────────────────────────────────
 *   linguaforge_seo_sitemap_enabled  bool  Master switch (default true).
 *
 * ── Filters ───────────────────────────────────────────────────────────────
 *   linguaforge_seo_sitemap_slug  string  URL slug (default 'lf-sitemap.xml').
 *   linguaforge_seo_sitemap_xml   string  Sitemap index XML string before output.
 *
 * @package LinguaForge\Router\Seo
 * @since   2.2.0
 */

namespace LinguaForge\Router\Seo;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class SitemapManager {

	private const CACHE_KEY        = 'linguaforge_sitemap_xml';    // sitemap index transient
	private const CACHE_KEY_CHUNK  = 'linguaforge_sitemap_chunk_'; // + N (0-based chunk index)
	private const CACHE_TTL        = DAY_IN_SECONDS;
	private const GROUPS_PER_CHUNK = 1000;                         // TRID groups per chunk file

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {

		if ( ! get_option( 'linguaforge_seo_sitemap_enabled', true ) ) {
			return;
		}

		// Disable the WordPress 5.5+ built-in sitemap — it has no hook for
		// <xhtml:link> alternates, so LF's own sitemap replaces it entirely.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// Serve the sitemap XML on the front end.
		add_action( 'template_redirect', [ $this, 'maybe_serve_sitemap' ], 1 );

		// Announce in robots.txt.
		add_filter( 'robots_txt', [ $this, 'append_robots_txt' ], 10, 2 );

		// Flush cache when a translated post is saved.
		add_action( 'save_post', [ $this, 'flush_on_save' ] );
	}

	// =========================================================
	// SITEMAP SERVING
	// =========================================================

	/**
	 * Serve the sitemap index or a chunk when the request URL matches.
	 *
	 * /lf-sitemap.xml        → sitemap index (<sitemapindex>)
	 * /lf-sitemap-{N}.xml   → chunk N urlset (<urlset> with hreflang alternates)
	 */
	public function maybe_serve_sitemap(): void {

		$request_path = isset( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path extraction via wp_parse_url.
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '/';

		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$base_slug = $this->sitemap_slug();
		$stem      = (string) preg_replace( '/\.xml$/i', '', $base_slug );

		// Index: /lf-sitemap.xml
		if ( rtrim( $request_path, '/' ) === $home_path . '/' . $base_slug ) {
			$this->serve_xml( $this->get_sitemap_xml() );
		}

		// Chunk: /lf-sitemap-{N}.xml (N is 0-based)
		$pattern = '#^' . preg_quote( $home_path . '/' . $stem, '#' ) . '-(\d+)\.xml$#';
		if ( preg_match( $pattern, rtrim( $request_path, '/' ), $m ) ) {
			$this->serve_xml( $this->get_sitemap_chunk_xml( (int) $m[1] ) );
		}
	}

	/**
	 * Output XML and exit.
	 *
	 * @param string $xml
	 */
	private function serve_xml( string $xml ): void {
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML is generated internally; esc_xml() would corrupt valid XML entities.
		echo $xml;
		exit;
	}

	// =========================================================
	// ROBOTS.TXT
	// =========================================================

	/**
	 * Append a Sitemap: directive to robots.txt.
	 *
	 * @param  string $output  Existing robots.txt content.
	 * @param  string $is_public  '1' if site is public.
	 * @return string
	 */
	public function append_robots_txt( string $output, string $is_public ): string {

		if ( '1' !== $is_public ) {
			return $output;
		}

		return $output . "\nSitemap: " . esc_url( $this->get_sitemap_url() ) . "\n";
	}

	// =========================================================
	// CACHE MANAGEMENT
	// =========================================================

	/**
	 * Flush the sitemap cache when an LF-managed post is saved.
	 *
	 * @param int $post_id
	 */
	public function flush_on_save( int $post_id ): void {

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only flush when the post is part of a translation group.
		if ( '' === (string) get_post_meta( $post_id, '_lf_trid', true ) ) {
			return;
		}

		$this->flush_cache();
	}

	/**
	 * Delete the cached sitemap index and all chunk transients.
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );

		$chunk_count = max( (int) get_option( 'linguaforge_sitemap_chunk_count', 0 ), 1 );
		for ( $i = 0; $i < $chunk_count; $i++ ) {
			delete_transient( self::CACHE_KEY_CHUNK . $i );
		}

		delete_option( 'linguaforge_sitemap_chunk_count' );
		delete_option( 'linguaforge_sitemap_url_count' );
	}

	/**
	 * Return the ISO 8601 timestamp of the last cache write, or null.
	 *
	 * @return string|null
	 */
	public function cache_age(): ?string {

		$timestamp = get_option( 'linguaforge_sitemap_cached_at', '' );
		return '' !== $timestamp ? (string) $timestamp : null;
	}

	// =========================================================
	// XML GENERATION
	// =========================================================

	/**
	 * Return the sitemap index XML, reading from cache or generating fresh.
	 *
	 * /lf-sitemap.xml is always a <sitemapindex> pointing to chunk files.
	 * Chunk files are generated and cached in the same pass.
	 *
	 * @return string
	 */
	public function get_sitemap_xml(): string {
		$this->ensure_cache_populated();
		$cached = get_transient( self::CACHE_KEY );
		return ( is_string( $cached ) && '' !== $cached ) ? $cached : $this->empty_index_xml();
	}

	/**
	 * Return a single chunk urlset XML, reading from cache or generating fresh.
	 *
	 * @param  int    $chunk  0-based chunk index.
	 * @return string
	 */
	public function get_sitemap_chunk_xml( int $chunk ): string {
		$this->ensure_cache_populated();
		$cached = get_transient( self::CACHE_KEY_CHUNK . $chunk );
		return ( is_string( $cached ) && '' !== $cached ) ? $cached : $this->empty_urlset_xml();
	}

	/**
	 * Populate the index and all chunk transients if the cache is cold.
	 *
	 * One DB query feeds the index and every chunk; all transients are written
	 * together so they always expire and flush as a unit.
	 */
	private function ensure_cache_populated(): void {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return;
		}
		$this->generate_and_cache();
	}

	/**
	 * Run the sitemap DB query, split results into chunks, and cache everything.
	 */
	private function generate_and_cache(): void {

		global $wpdb;

		$source_lang = $this->router->context->source_language();

		// ── Fetch all published LF-managed posts ──────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query intentional; result is transient-cached by ensure_cache_populated().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_modified_gmt, pm_trid.meta_value AS trid, pm_lang.meta_value AS lang
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_trid ON p.ID = pm_trid.post_id AND pm_trid.meta_key = '_lf_trid'
				 INNER JOIN {$wpdb->postmeta} pm_lang ON p.ID = pm_lang.post_id AND pm_lang.meta_key = '_lf_lang'
				 WHERE p.post_status = %s
				   AND p.post_type NOT IN (
				       'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
				       'oembed_cache', 'user_request', 'wp_block',
				       'wp_template', 'wp_template_part', 'wp_navigation',
				       'wp_global_styles', 'wp_font_face', 'wp_font_family',
				       'shop_order', 'shop_coupon', 'shop_subscription', 'shop_order_refund'
				   )
				 ORDER BY pm_trid.meta_value, pm_lang.meta_value",
				'publish'
			)
		);

		if ( empty( $rows ) ) {
			// Cache empty responses so we don't re-query on every request.
			set_transient( self::CACHE_KEY, $this->empty_index_xml(), self::CACHE_TTL );
			set_transient( self::CACHE_KEY_CHUNK . '0', $this->empty_urlset_xml(), self::CACHE_TTL );
			update_option( 'linguaforge_sitemap_chunk_count', 1, false );
			update_option( 'linguaforge_sitemap_url_count', 0, false );
			update_option( 'linguaforge_sitemap_cached_at', wp_date( 'c' ), false );
			return;
		}

		// ── Group rows by TRID ────────────────────────────────────────────────
		$groups = [];
		foreach ( $rows as $row ) {
			$groups[ $row->trid ][] = $row;
		}

		// ── Split into chunks of GROUPS_PER_CHUNK TRIDs each ─────────────────
		$group_chunks = array_chunk( array_values( $groups ), self::GROUPS_PER_CHUNK );
		$chunk_count  = count( $group_chunks );
		$total_urls   = 0;

		foreach ( $group_chunks as $i => $chunk_groups ) {
			$chunk_xml   = $this->generate_chunk_xml( $chunk_groups, $source_lang );
			$total_urls += substr_count( $chunk_xml, '<url>' );
			set_transient( self::CACHE_KEY_CHUNK . $i, $chunk_xml, self::CACHE_TTL );
		}

		// ── Generate and cache the sitemap index ──────────────────────────────
		$index_xml = $this->generate_index_xml( $chunk_count );
		$index_xml = (string) apply_filters( 'linguaforge_seo_sitemap_xml', $index_xml );
		set_transient( self::CACHE_KEY, $index_xml, self::CACHE_TTL );

		update_option( 'linguaforge_sitemap_chunk_count', $chunk_count, false );
		update_option( 'linguaforge_sitemap_url_count', $total_urls, false );
		update_option( 'linguaforge_sitemap_cached_at', wp_date( 'c' ), false );
	}

	/**
	 * Build the <sitemapindex> XML listing all chunk URLs.
	 *
	 * @param  int    $chunk_count  Number of chunk files.
	 * @return string
	 */
	private function generate_index_xml( int $chunk_count ): string {

		$now = (string) wp_date( 'c' );
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		for ( $i = 0; $i < $chunk_count; $i++ ) {
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( $this->chunk_url( $i ) ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . esc_html( $now ) . "</lastmod>\n";
			$xml .= "\t</sitemap>\n";
		}

		$xml .= '</sitemapindex>';
		return $xml;
	}

	/**
	 * Build one <urlset> chunk containing the given TRID groups.
	 *
	 * @param  array  $groups       Array of TRID groups; each group is an array of row objects.
	 * @param  string $source_lang  Source language code for x-default selection.
	 * @return string
	 */
	private function generate_chunk_xml( array $groups, string $source_lang ): string {

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( $groups as $posts ) {

			$alternates = [];
			$latest_mod = '';

			foreach ( $posts as $row ) {
				$permalink = get_permalink( (int) $row->ID );
				if ( ! $permalink ) {
					continue;
				}
				$alternates[ $row->lang ] = $permalink;
				if ( $row->post_modified_gmt > $latest_mod ) {
					$latest_mod = $row->post_modified_gmt;
				}
			}

			if ( empty( $alternates ) ) {
				continue;
			}

			$lastmod   = '' !== $latest_mod ? wp_date( 'c', strtotime( $latest_mod . ' UTC' ) ) : '';
			$x_default = $alternates[ $source_lang ] ?? reset( $alternates );

			// One <url> block per language version.
			foreach ( $alternates as $lang => $url ) {

				$xml .= "\t<url>\n";
				$xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";

				if ( '' !== $lastmod ) {
					$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
				}

				foreach ( $alternates as $alt_lang => $alt_url ) {
					$xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( SchemaManager::lang_to_bcp47( $alt_lang ) ) . '" href="' . esc_url( $alt_url ) . '"/>' . "\n";
				}

				// x-default.
				$xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . esc_url( $x_default ) . "\"/>\n";
				$xml .= "\t</url>\n";
			}
		}

		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * Chunk slug derived from the base sitemap slug.
	 *
	 * e.g. 'lf-sitemap.xml' → 'lf-sitemap-0.xml'
	 *
	 * @param  int    $chunk  0-based chunk index.
	 * @return string
	 */
	private function chunk_slug( int $chunk ): string {
		$stem = (string) preg_replace( '/\.xml$/i', '', $this->sitemap_slug() );
		return $stem . '-' . $chunk . '.xml';
	}

	/**
	 * Absolute URL to a chunk file.
	 *
	 * @param  int    $chunk  0-based chunk index.
	 * @return string
	 */
	private function chunk_url( int $chunk ): string {
		return home_url( '/' . $this->chunk_slug( $chunk ) );
	}

	/**
	 * Return an empty but valid sitemap index XML string.
	 */
	private function empty_index_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
			. "\n</sitemapindex>";
	}

	/**
	 * Return an empty but valid urlset XML string.
	 */
	private function empty_urlset_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ' xmlns:xhtml="http://www.w3.org/1999/xhtml">'
			. "\n</urlset>";
	}

	// =========================================================
	// PUBLIC HELPERS (used by SitemapPanel)
	// =========================================================

	/**
	 * The sitemap slug (without leading slash).
	 *
	 * @return string
	 */
	public function sitemap_slug(): string {
		return (string) apply_filters( 'linguaforge_seo_sitemap_slug', 'lf-sitemap.xml' );
	}

	/**
	 * Full absolute URL to the sitemap.
	 *
	 * @return string
	 */
	public function get_sitemap_url(): string {
		return home_url( '/' . $this->sitemap_slug() );
	}

	/**
	 * Total <url> count across all chunks, from cache only — never triggers generation.
	 *
	 * Returns null when the sitemap has not been generated yet.
	 * Use this in admin UI to avoid running a full DB query on every page load.
	 *
	 * @return int|null  Entry count, or null if the cache is empty.
	 */
	public function get_cached_entry_count(): ?int {

		// Use the index transient as the presence sentinel — if it is absent the
		// cache has not been populated (or has been flushed).
		$cached = get_transient( self::CACHE_KEY );

		if ( ! is_string( $cached ) || '' === $cached ) {
			return null;
		}

		$count = get_option( 'linguaforge_sitemap_url_count', null );
		return null !== $count ? max( 0, (int) $count ) : null;
	}

	/**
	 * Whether a SEO plugin with its own sitemap system is active.
	 *
	 * These plugins replace the WP core sitemap entirely.  LF's sitemap
	 * adds the hreflang alternates these plugins lack; both should be
	 * submitted to Search Console.
	 *
	 * @return bool
	 */
	public function is_seo_sitemap_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Names of active SEO plugins with their own sitemap.
	 *
	 * @return string[]
	 */
	public function detected_sitemap_plugins(): array {

		$found = [];

		if ( defined( 'WPSEO_VERSION' ) )    $found[] = 'Yoast SEO';
		if ( defined( 'RANK_MATH_VERSION' ) ) $found[] = 'Rank Math';
		if ( defined( 'AIOSEO_VERSION' ) )    $found[] = 'All in One SEO';
		if ( defined( 'SEOPRESS_VERSION' ) )  $found[] = 'SEOPress';

		return $found;
	}
}
