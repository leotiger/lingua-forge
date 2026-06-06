<?php
/**
 * Class LinguaForge\Router\Seo\Hreflang
 *
 * Outputs hreflang link tags in wp_head, removes WordPress's own canonical tag
 * on translated pages, and disables third-party SEO plugins' hreflang output
 * so they don't conflict with ours.
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
				echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( get_permalink( $id ) ) . '" />' . "\n";
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

		if ( is_paged() ) {
			$paged = get_query_var( 'paged' );
			foreach ( $this->router->context->languages() as $lang ) {
				if ( $this->router->context->routing_mode() === 'subdomain' ) {
					$base = $this->router->context->lang_base_url( $lang );
				} else {
					$base = ( $lang === $this->router->context->source_language() ) ? home_url( '/' ) : home_url( '/' . $lang . '/' );
				}
				$url  = ( $paged > 1 ) ? $base . 'page/' . $paged . '/' : $base;
				echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
			return;
		}

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
				echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
		}
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
}
