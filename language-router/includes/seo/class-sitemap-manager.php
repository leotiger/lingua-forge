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
 * Generated XML is stored in a 24-hour transient (linguaforge_sitemap_xml).
 * The cache is flushed automatically on any save_post event that affects
 * an LF-managed post.  It can also be flushed manually from the admin panel.
 *
 * ── Options ───────────────────────────────────────────────────────────────
 *   linguaforge_seo_sitemap_enabled  bool  Master switch (default true).
 *
 * ── Filters ───────────────────────────────────────────────────────────────
 *   linguaforge_seo_sitemap_slug  string  URL slug (default 'lf-sitemap.xml').
 *   linguaforge_seo_sitemap_xml   string  Full XML string before output.
 *
 * @package LinguaForge\Router\Seo
 * @since   2.2.0
 */

namespace LinguaForge\Router\Seo;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class SitemapManager {

	private const CACHE_KEY = 'linguaforge_sitemap_xml';
	private const CACHE_TTL = DAY_IN_SECONDS;

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
	 * Serve the sitemap XML when the request URL matches the sitemap slug.
	 */
	public function maybe_serve_sitemap(): void {

		$request_path = isset( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path extraction via wp_parse_url.
			? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: '/';

		$home_path   = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path   = rtrim( $home_path, '/' );
		$sitemap_url = $home_path . '/' . $this->sitemap_slug();

		if ( rtrim( $request_path, '/' ) !== $sitemap_url ) {
			return;
		}

		$xml = $this->get_sitemap_xml();

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
	 * Delete the cached sitemap XML.
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
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
	 * Return the sitemap XML, reading from cache or generating fresh.
	 *
	 * @return string
	 */
	public function get_sitemap_xml(): string {

		$cached = get_transient( self::CACHE_KEY );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$xml = $this->generate_xml();
		set_transient( self::CACHE_KEY, $xml, self::CACHE_TTL );
		update_option( 'linguaforge_sitemap_cached_at', wp_date( 'c' ), false );

		return $xml;
	}

	/**
	 * Generate the full sitemap XML string.
	 *
	 * Queries every published post that carries _lf_trid meta, groups by TRID,
	 * and outputs one <url> block per post with <xhtml:link> alternates for
	 * every language in the same translation group.
	 *
	 * @return string
	 */
	private function generate_xml(): string {

		global $wpdb;

		$source_lang = $this->router->context->source_language();

		// ── Fetch all published LF-managed posts ──────────────────────────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query intentional; result is transient-cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_modified_gmt, pm_trid.meta_value AS trid, pm_lang.meta_value AS lang
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_trid ON p.ID = pm_trid.post_id AND pm_trid.meta_key = '_lf_trid'
				 INNER JOIN {$wpdb->postmeta} pm_lang ON p.ID = pm_lang.post_id AND pm_lang.meta_key = '_lf_lang'
				 WHERE p.post_status = %s
				   AND p.post_type NOT IN (
				       'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
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
			return $this->empty_xml();
		}

		// ── Group by TRID ──────────────────────────────────────────────────────
		$groups = [];
		foreach ( $rows as $row ) {
			$groups[ $row->trid ][] = $row;
		}

		// ── Build XML ──────────────────────────────────────────────────────────
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( $groups as $trid => $posts ) {

			// Build the alternates list: lang → permalink.
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

			$lastmod     = '' !== $latest_mod ? wp_date( 'c', strtotime( $latest_mod . ' UTC' ) ) : '';
			$x_default   = $alternates[ $source_lang ] ?? reset( $alternates );

			// One <url> block per language version.
			foreach ( $alternates as $lang => $url ) {

				$xml .= "\t<url>\n";
				$xml .= "\t\t<loc>" . esc_url( $url ) . "</loc>\n";

				if ( '' !== $lastmod ) {
					$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
				}

				foreach ( $alternates as $alt_lang => $alt_url ) {
					$xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $alt_lang ) . '" href="' . esc_url( $alt_url ) . '"/>' . "\n";
				}

				// x-default.
				$xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . esc_url( $x_default ) . "\"/>\n";

				$xml .= "\t</url>\n";
			}
		}

		$xml .= '</urlset>';

		return (string) apply_filters( 'linguaforge_seo_sitemap_xml', $xml );
	}

	/**
	 * Return an empty but valid sitemap XML string.
	 */
	private function empty_xml(): string {
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
	 * Count of URL entries from the cache only — never triggers generation.
	 *
	 * Returns null when the sitemap has not been generated yet.
	 * Use this in admin UI to avoid running a full DB query on every page load.
	 *
	 * @return int|null  Entry count, or null if the cache is empty.
	 */
	public function get_cached_entry_count(): ?int {

		$cached = get_transient( self::CACHE_KEY );

		if ( ! is_string( $cached ) || '' === $cached ) {
			return null;
		}

		return max( 0, substr_count( $cached, '<url>' ) );
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
