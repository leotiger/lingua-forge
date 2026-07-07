<?php
/**
 * Class LinguaForge\Router\Seo\SeoManager
 *
 * Outputs multilingual Open Graph tags in wp_head.
 *
 * Always emits og:locale (current language) and og:locale:alternate (all other
 * configured languages) when the OG module is enabled — these are the only tags
 * that require LF routing knowledge and that no third-party plugin can provide
 * correctly for a multilingual site.
 *
 * In 'auto' mode the class detects whether the lf-social-share mu-plugin or a
 * major SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) is active.  If either
 * is present it assumes they handle the base OG set (og:title, og:description,
 * og:url, og:image, og:type) and emits only the locale tags.  When neither is
 * detected it emits the full OG + Twitter Card set.
 *
 * Mode can be overridden via Settings → SEO → Open Graph:
 *   'auto'         — detect at runtime (default)
 *   'locale-only'  — always emit only og:locale + og:locale:alternate
 *   'full'         — always emit the complete set regardless of other plugins
 *   'disabled'     — emit nothing
 *
 * Filters:
 *   linguaforge_seo_og_locale_map  array<string,string>  Language→Facebook-locale map.
 *   linguaforge_seo_og_description string                Override og:description.
 *   linguaforge_seo_og_image       string                Override og:image URL.
 *
 * @package LinguaForge\Router\Seo
 * @since   2.2.0
 */

namespace LinguaForge\Router\Seo;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class SeoManager {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {

		if ( ! get_option( 'linguaforge_seo_og_enabled', true ) ) {
			return;
		}

		// Priority 2: after Hreflang (1), before lf-social-share (5).
		add_action( 'wp_head', [ $this, 'print_og_tags' ], 2 );
	}

	// =========================================================
	// OG OUTPUT
	// =========================================================

	public function print_og_tags(): void {

		if ( is_admin() ) {
			return;
		}

		$mode = $this->resolve_og_mode();

		if ( 'disabled' === $mode ) {
			return;
		}

		$lang  = defined( 'LF_LANG' ) ? LF_LANG : $this->router->context->detect_lang_safe();
		$langs = $this->router->context->languages();

		echo "\n";

		// og:locale — current language as a Facebook locale code.
		echo '<meta property="og:locale" content="' . esc_attr( self::lang_to_locale( $lang ) ) . '">' . "\n";

		// og:locale:alternate — one per other configured language.
		foreach ( $langs as $alt_lang ) {
			if ( $alt_lang === $lang ) {
				continue;
			}
			echo '<meta property="og:locale:alternate" content="' . esc_attr( self::lang_to_locale( $alt_lang ) ) . '">' . "\n";
		}

		if ( 'locale-only' === $mode ) {
			return;
		}

		// ── Full OG set ────────────────────────────────────────────────────────

		// og:type — 'article' for singular pages, 'website' for archives.
		// Filterable so the WooCommerce integration can return 'product' on
		// product pages without coupling SeoManager to WC.
		$og_type = is_singular() ? 'article' : 'website';
		$og_type = (string) apply_filters( 'linguaforge_seo_og_type', $og_type );
		echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";

		// og:title
		$title = is_singular()
			? wp_strip_all_tags( get_the_title() )
			: wp_get_document_title();
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";

		// og:description — prefer _linguaforge_meta_description, then excerpt, then bloginfo.
		$description = $this->get_og_description();
		if ( '' !== $description ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		}

		// og:url
		$url = is_singular() ? (string) get_permalink() : $this->get_current_url();
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";

		// og:image
		$image = $this->get_og_image();
		if ( '' !== $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
		}

		// Twitter / X Cards
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		if ( '' !== $description ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		if ( '' !== $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		}

		/**
		 * Fires after the full OG + Twitter Card set has been output.
		 *
		 * Use this action to append additional Open Graph properties without
		 * modifying SeoManager directly.  The WooCommerce integration uses it
		 * to output og:price:amount, og:price:currency, og:availability for
		 * product pages.
		 *
		 * @since 2.2.0
		 */
		do_action( 'linguaforge_seo_og_extra_tags' );
	}

	// =========================================================
	// MODE RESOLUTION
	// =========================================================

	/**
	 * Resolve the effective OG output mode.
	 *
	 * @return string 'full'|'locale-only'|'disabled'
	 */
	public function resolve_og_mode(): string {

		$option = (string) get_option( 'linguaforge_seo_og_mode', 'auto' );

		if ( 'auto' !== $option ) {
			return $option;
		}

		// Auto: defer base OG to the legacy lf-social-share mu-plugin or a major
		// SEO plugin when either is detected as already handling OG output.
		// LF's built-in Social Share (class-social-share.php) does NOT output OG —
		// it only rewrites Social Icons block URLs — so it does not affect this check.
		if ( $this->is_social_share_mu_plugin_active() || $this->is_seo_plugin_active() ) {
			return 'locale-only';
		}

		return 'full';
	}

	// =========================================================
	// DETECTION HELPERS  (public — used by OpenGraphPanel)
	// =========================================================

	/**
	 * Whether the legacy lf-social-share mu-plugin is loaded.
	 *
	 * The mu-plugin outputs its own OG/Twitter Card tags at wp_head priority 5.
	 * When it is active, SeoManager defers to it for the base OG set and only
	 * emits og:locale + og:locale:alternate.
	 *
	 * NOTE: LF's built-in Social Share (SocialShare class) does NOT output OG
	 * tags — it only handles Social Icons block URL rewriting and footer JS.
	 * Its presence has no bearing on this check.
	 */
	public function is_social_share_mu_plugin_active(): bool {
		return function_exists( 'lf_social_share_get_current_url' );
	}

	/**
	 * @deprecated 2.2.0 Use is_social_share_mu_plugin_active() instead.
	 */
	public function is_social_share_active(): bool {
		return $this->is_social_share_mu_plugin_active();
	}

	/**
	 * Whether a major SEO plugin that outputs OG tags is active.
	 *
	 * @return bool
	 */
	public function is_seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Name(s) of the active SEO plugin(s) detected, for display in the admin panel.
	 *
	 * @return string[]
	 */
	public function detected_seo_plugins(): array {

		$found = [];

		if ( defined( 'WPSEO_VERSION' ) )    $found[] = 'Yoast SEO';
		if ( defined( 'RANK_MATH_VERSION' ) ) $found[] = 'Rank Math';
		if ( defined( 'AIOSEO_VERSION' ) )    $found[] = 'All in One SEO';
		if ( defined( 'SEOPRESS_VERSION' ) )  $found[] = 'SEOPress';

		return $found;
	}

	// =========================================================
	// CONTENT HELPERS
	// =========================================================

	/**
	 * Resolve the og:description value.
	 *
	 * Priority: LF meta description → post excerpt → trimmed content → bloginfo.
	 */
	private function get_og_description(): string {

		$description = '';

		if ( is_singular() ) {

			$post = get_post();

			if ( $post ) {
				// LF AI-generated meta description takes priority.
				$lf_meta = get_post_meta( $post->ID, '_linguaforge_meta_description', true );

				if ( is_string( $lf_meta ) && '' !== trim( $lf_meta ) ) {
					$description = trim( $lf_meta );
				} elseif ( '' !== $post->post_excerpt ) {
					$description = wp_strip_all_tags( $post->post_excerpt );
				} else {
					$description = wp_trim_words(
						wp_strip_all_tags( $post->post_content ),
						30
					);
				}
			}
		}

		if ( '' === $description ) {
			$description = get_bloginfo( 'description' );
		}

		return (string) apply_filters( 'linguaforge_seo_og_description', $description );
	}

	/**
	 * Resolve the og:image URL.
	 *
	 * Priority:
	 *   1. Featured image (singular pages)
	 *   2. Site logo (custom_logo theme mod)
	 *   3. Site icon (favicon)
	 *   4. Admin-configured default OG image (linguaforge_seo_og_default_image option)
	 *   5. lf-social-share mu-plugin fallback file (backward compat)
	 *   6. linguaforge_seo_og_image filter (empty string — last chance override)
	 */
	private function get_og_image(): string {

		$image = '';

		// 1. Featured image.
		if ( is_singular() && has_post_thumbnail() ) {
			$src = wp_get_attachment_image_url( (int) get_post_thumbnail_id(), 'full' );
			if ( $src ) {
				$image = $src;
			}
		}

		// 2. Site logo.
		if ( '' === $image ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$src = wp_get_attachment_image_url( $logo_id, 'full' );
				if ( $src ) {
					$image = $src;
				}
			}
		}

		// 3. Site icon.
		if ( '' === $image ) {
			$icon_id = (int) get_option( 'site_icon' );
			if ( $icon_id ) {
				$src = wp_get_attachment_image_url( $icon_id, 'full' );
				if ( $src ) {
					$image = $src;
				}
			}
		}

		// 4. Admin-configured default OG image.
		if ( '' === $image ) {
			$default = (string) get_option( 'linguaforge_seo_og_default_image', '' );
			if ( '' !== $default ) {
				$image = $default;
			}
		}

		// 5. lf-social-share mu-plugin fallback file — backward compat for sites
		//    that previously used the mu-plugin and have a default-og.jpg in place.
		if ( '' === $image ) {
			$mu_fallback = WP_CONTENT_DIR . '/mu-plugins/lf-social-share/assets/default-og.jpg';
			if ( file_exists( $mu_fallback ) ) {
				$image = content_url( 'mu-plugins/lf-social-share/assets/default-og.jpg' );
			}
		}

		return (string) apply_filters( 'linguaforge_seo_og_image', $image );
	}

	/**
	 * Current URL for non-singular pages.
	 *
	 * Uses the same sanitised SERVER vars pattern as lf-social-share.
	 */
	private function get_current_url(): string {

		$scheme = is_ssl() ? 'https' : 'http';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is extracted via wp_parse_url (path component only); sanitised before use.
		$raw_host = isset( $_SERVER['HTTP_HOST'] )
			? wp_parse_url( 'http://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST )
			: wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $raw_host ) ? $raw_host : (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$raw_path = isset( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path extraction via wp_parse_url.
			? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
			: null;
		$path = is_string( $raw_path ) ? $raw_path : '/';

		return $scheme . '://' . $host . $path;
	}

	// =========================================================
	// LOCALE MAPPING
	// =========================================================

	/**
	 * Convert a 2-char LF language code to a Facebook og:locale string.
	 *
	 * Sites with non-standard language codes can override the map via the
	 * linguaforge_seo_og_locale_map filter.
	 *
	 * @param  string $lang  LF language code (e.g. 'de').
	 * @return string        Facebook locale (e.g. 'de_DE').
	 */
	public static function lang_to_locale( string $lang ): string {

		// Normalise WordPress's own bare 3-letter-only locale slugs (e.g.
		// 'yor' for Yoruba) to their real ISO 639-1 code first — og:locale
		// expects language_TERRITORY, and 'yor' would produce a malformed
		// "yor_YOR" via the fallback below. Internal routing/URLs/postmeta
		// are untouched; this is purely for this outbound-facing conversion.
		// See Context::iso_639_1_from_lang().
		$lang = Context::iso_639_1_from_lang( $lang );

		static $map = null;

		if ( null === $map ) {
			$map = (array) apply_filters(
				'linguaforge_seo_og_locale_map',
				[
					'ar' => 'ar_AR',
					'bg' => 'bg_BG',
					'ca' => 'ca_ES',
					'cs' => 'cs_CZ',
					'da' => 'da_DK',
					'de' => 'de_DE',
					'el' => 'el_GR',
					'en' => 'en_US',
					'es' => 'es_ES',
					'et' => 'et_EE',
					'fi' => 'fi_FI',
					'fr' => 'fr_FR',
					'hr' => 'hr_HR',
					'hu' => 'hu_HU',
					'id' => 'id_ID',
					'it' => 'it_IT',
					'ja' => 'ja_JP',
					'ko' => 'ko_KR',
					'lt' => 'lt_LT',
					'lv' => 'lv_LV',
					'nb' => 'nb_NO',
					'nl' => 'nl_NL',
					'pl' => 'pl_PL',
					'pt' => 'pt_PT',
					'ro' => 'ro_RO',
					'ru' => 'ru_RU',
					'sk' => 'sk_SK',
					'sl' => 'sl_SI',
					'sr' => 'sr_RS',
					'sv' => 'sv_SE',
					'th' => 'th_TH',
					'tr' => 'tr_TR',
					'uk' => 'uk_UA',
					'vi' => 'vi_VN',
					'zh' => 'zh_CN',
				]
			);
		}

		return $map[ $lang ] ?? ( $lang . '_' . strtoupper( $lang ) );
	}
}
