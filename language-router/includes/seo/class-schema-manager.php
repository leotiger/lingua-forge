<?php
/**
 * Class LinguaForge\Router\Seo\SchemaManager
 *
 * Outputs Schema.org JSON-LD structured data in wp_head for multilingual pages.
 *
 * Fires at wp_head priority 3 — after Hreflang (1) and Open Graph (2).
 *
 * ── Deference ──────────────────────────────────────────────────────────────
 * Schema.org JSON-LD cannot be cleanly supplemented when another plugin is
 * already outputting it — duplicate or conflicting graphs cause validation
 * errors and confuse crawlers.  When Yoast SEO, Rank Math, All in One SEO,
 * or SEOPress is active, SchemaManager registers no hooks at all and the
 * active plugin handles structured data output.
 *
 * ── Types emitted (sprint 2) ──────────────────────────────────────────────
 *   Article / WebPage   — singular posts and pages (independently togglable)
 *   WebSite             — front page / blog index only
 *
 * WooCommerce Product schema is handled by
 * LinguaForge\AI\Integrations\WooCommerce\SeoSupport which hooks into the
 * linguaforge_seo_schema_extra_types action fired at the end of print_schema().
 *
 * ── Options ───────────────────────────────────────────────────────────────
 *   linguaforge_seo_schema_enabled  bool  Master switch (default true).
 *   linguaforge_seo_schema_article  bool  Article / WebPage output (default true).
 *   linguaforge_seo_schema_website  bool  WebSite output on front page (default true).
 *
 * ── Filters ───────────────────────────────────────────────────────────────
 *   linguaforge_seo_schema_data  array  Modify the full schema array before encoding.
 *                                       Receives the array and the schema @type string.
 *
 * @package LinguaForge\Router\Seo
 * @since   2.2.0
 */

namespace LinguaForge\Router\Seo;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class SchemaManager {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {

		if ( ! get_option( 'linguaforge_seo_schema_enabled', true ) ) {
			return;
		}

		// Skip entirely when a SEO plugin already outputs structured data —
		// conflicting JSON-LD graphs cause validation errors.
		if ( $this->is_schema_plugin_active() ) {
			return;
		}

		add_action( 'wp_head', [ $this, 'print_schema' ], 3 );
	}

	// =========================================================
	// SCHEMA OUTPUT
	// =========================================================

	public function print_schema(): void {

		if ( is_admin() ) {
			return;
		}

		$lang  = defined( 'LF_LANG' ) ? LF_LANG : $this->router->context->detect_lang_safe();
		$in_language = self::lang_to_bcp47( $lang );

		// ── WebSite — front page / blog index ─────────────────────────────────
		if ( get_option( 'linguaforge_seo_schema_website', true ) ) {
			if ( is_front_page() || is_home() ) {
				self::output_schema( $this->build_website_schema( $in_language ) );
			}
		}

		// ── Article / WebPage — singular pages ────────────────────────────────
		if ( get_option( 'linguaforge_seo_schema_article', true ) ) {
			if ( is_singular() ) {
				$post = get_post();
				if ( $post ) {
					self::output_schema( $this->build_article_schema( $post, $in_language ) );
				}
			}
		}

		/**
		 * Fires after all built-in schema types have been output.
		 *
		 * Use this action to output additional JSON-LD types without modifying
		 * SchemaManager directly.  The WooCommerce integration uses it to output
		 * Product schema.
		 *
		 * Receives the current language code and BCP 47 locale as arguments.
		 *
		 * @param string $lang        Current LF language code (e.g. 'de').
		 * @param string $in_language BCP 47 locale (e.g. 'de-DE').
		 *
		 * @since 2.2.0
		 */
		do_action( 'linguaforge_seo_schema_extra_types', $lang, $in_language );
	}

	// =========================================================
	// SCHEMA BUILDERS
	// =========================================================

	/**
	 * Build WebSite schema for the front page / blog index.
	 *
	 * @param  string $in_language  BCP 47 locale string.
	 * @return array<string, mixed>
	 */
	private function build_website_schema( string $in_language ): array {

		$data = [
			'@context'   => 'https://schema.org',
			'@type'      => 'WebSite',
			'name'       => get_bloginfo( 'name' ),
			'url'        => home_url( '/' ),
			'inLanguage' => $in_language,
		];

		return (array) apply_filters( 'linguaforge_seo_schema_data', $data, 'WebSite' );
	}

	/**
	 * Build Article or WebPage schema for a singular post.
	 *
	 * Blog posts use `Article`; all other post types use `WebPage`.
	 *
	 * @param  \WP_Post $post
	 * @param  string   $in_language  BCP 47 locale string.
	 * @return array<string, mixed>
	 */
	private function build_article_schema( \WP_Post $post, string $in_language ): array {

		$type = ( 'post' === $post->post_type ) ? 'Article' : 'WebPage';

		$headline    = wp_strip_all_tags( get_the_title( $post ) );
		$description = $this->get_description( $post );
		$url         = (string) get_permalink( $post );
		$image       = $this->get_image_url( $post );
		$published   = (string) get_post_time( 'c', true, $post );
		$modified    = (string) get_post_modified_time( 'c', true, $post );
		$publisher   = $this->get_publisher();

		$data = [
			'@context'      => 'https://schema.org',
			'@type'         => $type,
			'headline'      => $headline,
			'url'           => $url,
			'inLanguage'    => $in_language,
			'datePublished' => $published,
			'dateModified'  => $modified,
		];

		if ( '' !== $description ) {
			$data['description'] = $description;
		}

		if ( '' !== $image ) {
			$data['image'] = $image;
		}

		if ( ! empty( $publisher ) ) {
			$data['publisher'] = $publisher;
		}

		return (array) apply_filters( 'linguaforge_seo_schema_data', $data, $type );
	}

	// =========================================================
	// CONTENT HELPERS
	// =========================================================

	/**
	 * Resolve the best description for a post.
	 *
	 * Priority: LF AI meta description → post excerpt → trimmed content.
	 */
	private function get_description( \WP_Post $post ): string {

		$lf_meta = get_post_meta( $post->ID, '_linguaforge_meta_description', true );

		if ( is_string( $lf_meta ) && '' !== trim( $lf_meta ) ) {
			return trim( $lf_meta );
		}

		if ( '' !== $post->post_excerpt ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
	}

	/**
	 * Resolve the best image URL for a post.
	 *
	 * Priority: featured image → site logo → site icon.
	 */
	private function get_image_url( \WP_Post $post ): string {

		if ( has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post ), 'full' );
			if ( $src ) return $src;
		}

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$src = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $src ) return $src;
		}

		$icon_id = (int) get_option( 'site_icon' );
		if ( $icon_id ) {
			$src = wp_get_attachment_image_url( $icon_id, 'full' );
			if ( $src ) return $src;
		}

		return '';
	}

	/**
	 * Build the publisher Organization object.
	 *
	 * @return array<string, mixed>|array{}
	 */
	private function get_publisher(): array {

		$name = get_bloginfo( 'name' );
		if ( '' === $name ) {
			return [];
		}

		$publisher = [
			'@type' => 'Organization',
			'name'  => $name,
		];

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				$publisher['logo'] = [
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				];
			}
		}

		return $publisher;
	}

	// =========================================================
	// OUTPUT HELPER
	// =========================================================

	/**
	 * Encode a schema array and print the JSON-LD script tag.
	 *
	 * Prevents </script> injection by escaping the closing tag within values.
	 *
	 * @param  array<string, mixed> $data
	 */
	public static function output_schema( array $data ): void {

		if ( empty( $data ) ) {
			return;
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return;
		}

		// Prevent </script> in field values from breaking the script block.
		$json = str_replace( '</', '<\/', $json );

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded, </script> escaped above.
	}

	// =========================================================
	// DETECTION (public — used by SchemaPanel and CompatibilityPanel)
	// =========================================================

	/**
	 * Whether a SEO plugin that outputs Schema.org JSON-LD is active.
	 *
	 * When true, SchemaManager registers no hooks.
	 */
	public function is_schema_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Names of active schema-outputting SEO plugins.
	 *
	 * @return string[]
	 */
	public function detected_schema_plugins(): array {

		$found = [];

		if ( defined( 'WPSEO_VERSION' ) )    $found[] = 'Yoast SEO';
		if ( defined( 'RANK_MATH_VERSION' ) ) $found[] = 'Rank Math';
		if ( defined( 'AIOSEO_VERSION' ) )    $found[] = 'All in One SEO';
		if ( defined( 'SEOPRESS_VERSION' ) )  $found[] = 'SEOPress';

		return $found;
	}

	// =========================================================
	// LOCALE HELPERS
	// =========================================================

	/**
	 * Convert an LF language code to a BCP 47 locale string.
	 *
	 * Schema.org inLanguage expects BCP 47 format (e.g. 'de-DE', 'en-US').
	 * Internally we map via the same language→locale map used by SeoManager,
	 * then convert the underscore separator to a hyphen.
	 *
	 * @param  string $lang  LF language code (e.g. 'de').
	 * @return string        BCP 47 locale (e.g. 'de-DE').
	 */
	public static function lang_to_bcp47( string $lang ): string {

		static $map = null;

		if ( null === $map ) {
			$map = (array) apply_filters(
				'linguaforge_seo_schema_locale_map',
				[
					'ar' => 'ar-AR',
					'bg' => 'bg-BG',
					'ca' => 'ca-ES',
					'cs' => 'cs-CZ',
					'da' => 'da-DK',
					'de' => 'de-DE',
					'el' => 'el-GR',
					'en' => 'en-US',
					'es' => 'es-ES',
					'et' => 'et-EE',
					'fi' => 'fi-FI',
					'fr' => 'fr-FR',
					'hr' => 'hr-HR',
					'hu' => 'hu-HU',
					'id' => 'id-ID',
					'it' => 'it-IT',
					'ja' => 'ja-JP',
					'ko' => 'ko-KR',
					'lt' => 'lt-LT',
					'lv' => 'lv-LV',
					'nb' => 'nb-NO',
					'nl' => 'nl-NL',
					'pl' => 'pl-PL',
					'pt' => 'pt-PT',
					'ro' => 'ro-RO',
					'ru' => 'ru-RU',
					'sk' => 'sk-SK',
					'sl' => 'sl-SI',
					'sr' => 'sr-RS',
					'sv' => 'sv-SE',
					'th' => 'th-TH',
					'tr' => 'tr-TR',
					'uk' => 'uk-UA',
					'vi' => 'vi-VN',
					'zh' => 'zh-CN',
				]
			);
		}

		return $map[ $lang ] ?? ( $lang . '-' . strtoupper( $lang ) );
	}
}
