<?php
/**
 * Class LinguaForge\Router\Seo\Hreflang
 *
 * Outputs hreflang link tags in wp_head, replaces WordPress's canonical tag
 * with a self-referencing one on LF-managed pages, emits per-language
 * <meta name="robots"> noindex when requested, and disables third-party SEO
 * plugins' hreflang output so they don't conflict with ours.
 */

namespace LinguaForge\Router\Seo;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Hreflang {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {

		if ( ! get_option( 'linguaforge_seo_hreflang_enabled', true ) ) {
			return;
		}

		add_action( 'wp_head', [ $this, 'print_hreflang_tags' ], 1 );
		add_action( 'wp_head', [ $this, 'print_canonical' ],    1 );
		add_action( 'wp_head', [ $this, 'print_robots' ],       1 );
		add_action( 'wp',      [ $this, 'remove_core_canonical' ] );
		add_action( 'init',    [ $this, 'disable_seo_plugin_hreflang' ] );
	}

	// =========================================================
	// HREFLANG MODE
	// =========================================================

	public function hreflang_mode(): string {
		static $mode = null;
		if ( $mode !== null ) return $mode;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$mode = apply_filters( 'lf_hreflang_mode', 'custom' );
		return $mode;
	}

	// =========================================================
	// HREFLANG OUTPUT
	// =========================================================

	public function print_hreflang_tags(): void {
		if ( $this->hreflang_mode() !== 'custom' ) return;

		if ( is_singular() ) {
			global $post;
			if ( ! $post ) return;

			$translations = $this->router->trid_group->get_translations( $post->ID );
			if ( empty( $translations ) ) return;

			foreach ( $translations as $lang => $id ) {
				echo '<link rel="alternate" hreflang="' . esc_attr( SchemaManager::lang_to_bcp47( $lang ) ) . '" href="' . esc_url( get_permalink( $id ) ) . '" />' . "\n";
			}

			// x-default — Google's spec says this should point at a page intended
			// for users whose language preference matches none of the variants.
			// We default to the source-language URL (preserves pre-1.4 behavior),
			// but expose lf_hreflang_x_default so sites can swap in a dedicated
			// /global/ landing page or the English version when available.
			if ( ! empty( $translations[$this->router->context->source_language()] ) ) {
				$x_default_url = get_permalink( $translations[$this->router->context->source_language()] );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
				$x_default_url = (string) apply_filters( 'lf_hreflang_x_default', $x_default_url, $post->ID, $translations );

				if ( $x_default_url !== '' ) {
					echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default_url ) . '" />' . "\n";
				}
			}
			return;
		}

		// Archive and home branches are checked before any is_paged() test because
		// a paginated archive is BOTH is_paged() AND is_archive(). Checking is_paged()
		// first would emit alternates for the blog-home pagination (/page/N/) instead
		// of the actual archive path (/category/foo/page/N/). The REQUEST_URI rebuild
		// below naturally includes the /page/N/ segment, so paged archives and paged
		// home pages are handled correctly here without a separate is_paged() branch.
		if ( is_archive() || is_home() ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
			$path = trim( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );

			$langs_regex = implode( '|', array_map( 'preg_quote', $this->router->context->languages() ) );
			$path        = preg_replace( '#^(' . $langs_regex . ')/#', '', $path );
			$path        = preg_replace( '#/+#', '/', $path );

			foreach ( $this->router->context->languages() as $lang ) {
				if ( $this->router->context->routing_mode() === 'subdomain' ) {
					$base = $this->router->context->lang_base_url( $lang );
					$url  = empty( $path ) ? $base : $base . trailingslashit( $path );
				} elseif ( $lang === $this->router->context->source_language() ) {
					$url = empty( $path ) ? home_url( '/' ) : home_url( '/' . trailingslashit( $path ) );
				} else {
					$url = empty( $path )
						? home_url( '/' . trailingslashit( $lang ) )
						: home_url( '/' . trailingslashit( $lang . '/' . $path ) );
				}
				echo '<link rel="alternate" hreflang="' . esc_attr( SchemaManager::lang_to_bcp47( $lang ) ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
		}
	}

	// =========================================================
	// CANONICAL OUTPUT
	// =========================================================

	/**
	 * Emit a self-referencing <link rel="canonical"> tag.
	 *
	 * WordPress's built-in rel_canonical() is removed by remove_core_canonical()
	 * below because it can produce the wrong URL on LF-managed pages (e.g. the
	 * source-language permalink for a translated post). This method replaces it
	 * with the correct self URL. Third-party SEO plugins (Yoast, Rank Math, etc.)
	 * manage canonical themselves — we skip output when any of them is active.
	 */
	public function print_canonical(): void {
		if ( $this->hreflang_mode() !== 'custom' ) return;
		if ( $this->has_seo_plugin() ) return;

		if ( is_singular() ) {
			global $post;
			if ( ! $post ) return;
			echo '<link rel="canonical" href="' . esc_url( get_permalink( $post->ID ) ) . '" />' . "\n";
			return;
		}

		// Check archive/home before any is_paged() test — same ordering fix as
		// print_hreflang_tags(). A paginated archive is both is_paged() and
		// is_archive(); REQUEST_URI already includes /page/N/ so no separate
		// is_paged() branch is needed.
		if ( is_archive() || is_home() ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
			$path = trim( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );

			$langs_regex = implode( '|', array_map( 'preg_quote', $this->router->context->languages() ) );
			$path        = preg_replace( '#^(' . $langs_regex . ')/#', '', $path );
			$path        = preg_replace( '#/+#', '/', $path );

			$lang = defined( 'LF_LANG' ) ? LF_LANG : $this->router->context->source_language();

			if ( $this->router->context->routing_mode() === 'subdomain' ) {
				$base = $this->router->context->lang_base_url( $lang );
				$url  = empty( $path ) ? $base : $base . trailingslashit( $path );
			} elseif ( $lang === $this->router->context->source_language() ) {
				$url = empty( $path ) ? home_url( '/' ) : home_url( '/' . trailingslashit( $path ) );
			} else {
				$url = empty( $path )
					? home_url( '/' . trailingslashit( $lang ) )
					: home_url( '/' . trailingslashit( $lang . '/' . $path ) );
			}
			echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	// =========================================================
	// ROBOTS OUTPUT
	// =========================================================

	/**
	 * Emit <meta name="robots" content="noindex,follow"> when the post-level
	 * _lf_noindex flag is set for this language version.
	 *
	 * Fires on all singular pages regardless of whether a third-party SEO
	 * plugin is active — the flag is per-post and user-intentional, so we
	 * always honour it. Google takes the most restrictive directive when
	 * multiple robots tags are present, so double-output is safe.
	 */
	public function print_robots(): void {
		if ( ! is_singular() ) return;
		global $post;
		if ( ! $post ) return;
		if ( ! get_post_meta( $post->ID, '_lf_noindex', true ) ) return;
		echo '<meta name="robots" content="noindex,follow" />' . "\n";
	}

	// =========================================================
	// CANONICAL + SEO PLUGIN COMPAT
	// =========================================================

	public function remove_core_canonical(): void {
		if ( is_admin() ) return;
		if ( $this->hreflang_mode() !== 'custom' ) return;
		remove_action( 'wp_head', 'rel_canonical' );
	}

	public function disable_seo_plugin_hreflang(): void {
		if ( $this->hreflang_mode() !== 'custom' ) return;

		if ( defined( 'WPSEO_VERSION' ) )     add_filter( 'wpseo_hreflang', '__return_false' );
		if ( defined( 'RANK_MATH_VERSION' ) )  add_filter( 'rank_math/frontend/hreflang', '__return_false' );
		if ( defined( 'AIOSEO_VERSION' ) )     add_filter( 'aioseo_hreflang', '__return_false' );
		if ( defined( 'SEOPRESS_VERSION' ) )   add_filter( 'seopress_hreflang', '__return_false' );
	}

	/**
	 * Return true when any known third-party SEO plugin is active.
	 *
	 * Used by print_canonical() to hand canonical management back to the
	 * plugin — Yoast, Rank Math, AIOSEO and SEOPress all emit their own
	 * <link rel="canonical"> and would conflict with ours.
	 */
	private function has_seo_plugin(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}
}
