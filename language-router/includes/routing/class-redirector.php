<?php
/**
 * Class LinguaForge\Router\Routing\Redirector
 *
 * Handles all frontend redirects (singular, homepage, search, slash normalisation),
 * fixes the site-logo link, and rewrites navigation menu URLs to the translated
 * post permalinks.
 */

namespace LinguaForge\Router\Routing;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Redirector {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Template redirect
		add_action( 'template_redirect', [ $this, 'handle_singular_redirect' ], 1 );
		add_action( 'template_redirect', [ $this, 'handle_homepage_redirect' ] );
		add_action( 'template_redirect', [ $this, 'normalize_duplicate_slashes' ], 0 );
		add_action( 'template_redirect', [ $this, 'redirect_search_under_lang_prefix' ] );

		// Init redirects (homepage + search at 'init' stage)
		add_action( 'init', [ $this, 'handle_init_redirects' ], 0 );

		// Allow wp_safe_redirect() to follow cross-domain redirects to language subdomains.
		add_filter( 'allowed_redirect_hosts', [ $this, 'allow_lang_subdomains' ] );

		// Site logo + site title links + navigation home-link item
		add_filter( 'render_block', [ $this, 'fix_site_logo_link' ], 20, 2 );
		add_filter( 'render_block', [ $this, 'fix_site_title_link' ], 20, 2 );
		add_filter( 'render_block', [ $this, 'fix_home_link' ], 20, 2 );

		// WooCommerce breadcrumb "Home" link
		add_filter( 'woocommerce_breadcrumb_home_url', [ $this, 'translate_breadcrumb_home_url' ] );

		// WordPress core Privacy Policy page — translates the URL returned by
		// get_privacy_policy_url(), which is used by WC checkout, the WP login
		// footer, and any theme or block that calls that function.
		add_filter( 'privacy_policy_url', [ $this, 'translate_privacy_policy_url' ], 10, 2 );

		// Menu translation — classic nav menus
		add_filter( 'wp_nav_menu_objects', [ $this, 'translate_menu_items' ] );
	}

	/**
	 * Whitelist language subdomains for wp_safe_redirect() in subdomain routing mode.
	 *
	 * Without this, wp_safe_redirect() strips cross-domain redirects to
	 * e.g. de.example.com when home_url() is example.com.
	 *
	 * @param  array<string> $hosts  Currently allowed hosts.
	 * @return array<string>
	 */
	public function allow_lang_subdomains( array $hosts ): array {
		if ( $this->router->context->routing_mode() !== 'subdomain' ) return $hosts;
		$base = $this->router->context->base_domain();
		foreach ( $this->router->context->languages() as $lang ) {
			$hosts[] = $lang . '.' . $base;
		}
		return $hosts;
	}

	// =========================================================
	// HELPERS
	// =========================================================

	/**
	 * Returns a regex alternation of all configured language slugs,
	 * each preg_quote'd against the '#' delimiter used in this class.
	 * Replaces the former hardcoded '[a-z]{2}' pattern so that
	 * multi-character locale codes such as zh-tw are matched correctly.
	 *
	 * @return string  e.g. 'ca|es|en|zh\-tw'
	 */
	private function lang_regex(): string {
		return implode( '|', array_map(
			static fn( string $l ) => preg_quote( $l, '#' ),
			$this->router->context->languages()
		) );
	}

	// =========================================================
	// INIT REDIRECTS
	// =========================================================

	public function handle_init_redirects(): void {
		if ( $this->router->context->is_system_request() ) return;
		if ( is_admin() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		// Search redirect — canonicalise search requests that arrive under a lang prefix.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( isset( $_GET['s'] ) && preg_match( '#^/(?:' . $this->lang_regex() . ')/#', $path ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : LF_LANG;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			if ( $this->router->context->routing_mode() === 'subdomain' ) {
				// In subdomain mode the language is in the host; redirect to the
				// language subdomain root with just the search query.
				$base = $this->router->context->lang_base_url( $lang );
				wp_safe_redirect( $base . '?s=' . rawurlencode( $s ), 301 );
			} else {
				wp_safe_redirect( '/?lang=' . $lang . '&s=' . rawurlencode( $s ), 301 );
			}
			exit;
		}

		// Homepage redirect
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( ( $path === '/' || $path === '' ) && empty( $_GET['s'] ) ) {
			$front_id = get_option( 'page_on_front' );
			if ( ! $front_id ) return;

			$translations = $this->router->trid_group->get_translations( $front_id );

			if ( ! empty( $translations[LF_LANG] ) ) {
				$target = get_permalink( $translations[LF_LANG] );
				if ( untrailingslashit( $target ) !== untrailingslashit( home_url( '/' ) ) ) {
					$target = $this->router->context->safe_query_args( $target );
					wp_safe_redirect( $target, 302 );
					exit;
				}
			}
		}
	}

	// =========================================================
	// TEMPLATE REDIRECT HANDLERS
	// =========================================================

	public function handle_singular_redirect(): void {
		if ( $this->router->context->is_system_request() ) return;
		if ( is_admin() ) return;
		if ( is_search() ) return;
		if ( ! is_singular() ) return;
		if ( LF_LANG === $this->router->context->source_language() ) return;

		global $post;
		if ( ! $post ) return;

		// Don't process internal/non-public post types (e.g. wp_global_styles, wp_navigation).
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) return;

		$translations = $this->router->trid_group->get_translations( $post->ID );

		if ( empty( $translations[LF_LANG] ) ) {
			if ( ! defined( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE' ) ) {
				define( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE', true );
			}
			return;
		}

		$target_id = (int) $translations[LF_LANG];
		if ( $target_id === (int) $post->ID ) return;

		$target_url = get_permalink( $target_id );
		if ( ! $target_url ) return;

		$target_url = $this->router->context->safe_query_args( $target_url );
		wp_safe_redirect( $target_url, 301 );
		exit;
	}

	public function handle_homepage_redirect(): void {
		if ( $this->router->context->is_system_request() ) return;
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;
		if ( is_search() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if (
			$path !== '/' &&
			$path !== '' &&
			! preg_match( '#^/(?:' . $this->lang_regex() . ')/?$#', $path )
		) {
			return;
		}

		$front_id = get_option( 'page_on_front' );
		if ( ! $front_id ) return;

		$translations = $this->router->trid_group->get_translations( $front_id );

		if (
			LF_LANG !== $this->router->context->source_language() &&
			! empty( $translations[LF_LANG] )
		) {
			$target_id = $translations[LF_LANG];
		} else {
			$target_id = $front_id;
		}

		$target  = get_permalink( $target_id );
		if ( ! $target ) return;

		$current = home_url( trailingslashit( ltrim( $path, '/' ) ) );
		if ( untrailingslashit( $target ) === untrailingslashit( $current ) ) return;

		$target = $this->router->context->safe_query_args( $target );
		wp_safe_redirect( $target, 302 );
		exit;
	}

	public function normalize_duplicate_slashes(): void {
		if ( is_admin() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri   = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		$clean = preg_replace( '#(?<!:)//+#', '/', $uri );

		if ( $clean !== $uri ) {
			wp_safe_redirect( $clean, 301 );
			exit;
		}
	}

	public function redirect_search_under_lang_prefix(): void {
		if ( ! is_search() ) return;
		if ( $this->router->context->routing_mode() === 'subdomain' ) return; // No path prefix in subdomain mode.

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );

		if ( preg_match( '#^/(?:' . $this->lang_regex() . ')/#', $uri ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : LF_LANG;
			$s    = get_query_var( 's' );
			$url  = '/?lang=' . $lang . '&s=' . rawurlencode( $s );
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	// =========================================================
	// RENDER BLOCK FILTERS
	// =========================================================

	/**
	 * Returns the language-appropriate home URL for the current request.
	 *
	 * For sites with a static front page: resolves the translated front page
	 * permalink so the link lands on the correct language version.
	 * For sites showing the latest posts: returns home_url('/') with the
	 * language prefix path for non-source languages, or plain home_url('/') for
	 * the source language.
	 *
	 * Returns null when LF_LANG is not defined or when no URL can be determined.
	 *
	 * @return string|null
	 */
	private function lang_home_url(): ?string {
		if ( ! defined( 'LF_LANG' ) ) {
			return null;
		}

		$source = $this->router->context->source_language();
		$lang   = LF_LANG;

		// On language-neutral URLs (e.g. WooCommerce product pages), LF_LANG is
		// always the source language even when the queried post is a translation.
		// Detect the actual content language from the queried post so that home
		// links, logo links, and site-title links resolve correctly on those pages.
		if ( $lang === $source && is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$post_lang = (string) $this->router->trid_group->get_lang( $post->ID );
				if ( $post_lang && $post_lang !== $source ) {
					$lang = $post_lang;
				}
			}
		}

		$front_id = (int) get_option( 'page_on_front' );

		if ( $front_id > 0 ) {
			// Static front page: use the translated page's permalink.
			$translations = $this->router->trid_group->get_translations( $front_id );

			if ( $lang === $source ) {
				$target_id = $front_id;
			} elseif ( ! empty( $translations[ $lang ] ) ) {
				$target_id = (int) $translations[ $lang ];
			} else {
				$target_id = $front_id;
			}

			$url = get_permalink( $target_id );
			return $url ?: null;
		}

		// Latest-posts front: build the language-prefixed root URL.
		if ( $lang === $source ) {
			return home_url( '/' );
		}

		return home_url( '/' . $lang . '/' );
	}

	public function fix_site_logo_link( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/site-logo' ) return $block_content;

		$target_url = $this->lang_home_url();
		if ( ! $target_url ) return $block_content;

		$block_content = preg_replace(
			'/<a\s+([^>]*?)href="[^"]*"/',
			'<a $1href="' . esc_url( $target_url ) . '"',
			$block_content,
			1
		);

		return $block_content;
	}

	/**
	 * Rewrites the href on the `core/site-title` block's anchor to the
	 * language-appropriate home URL, matching the behaviour of fix_site_logo_link.
	 */
	public function fix_site_title_link( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/site-title' ) return $block_content;

		$target_url = $this->lang_home_url();
		if ( ! $target_url ) return $block_content;

		$block_content = preg_replace(
			'/<a\s+([^>]*?)href="[^"]*"/',
			'<a $1href="' . esc_url( $target_url ) . '"',
			$block_content,
			1
		);

		return $block_content;
	}

	/**
	 * Rewrites the href on the `core/home-link` block to the language-appropriate
	 * home URL.  core/home-link always renders home_url() directly; without this
	 * filter the link stays at the source-language root on language-neutral URLs
	 * such as WooCommerce product pages.
	 */
	public function fix_home_link( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/home-link' ) return $block_content;

		$target_url = $this->lang_home_url();
		if ( ! $target_url ) return $block_content;

		$block_content = preg_replace(
			'/<a\s+([^>]*?)href="[^"]*"/',
			'<a $1href="' . esc_url( $target_url ) . '"',
			$block_content,
			1
		);

		return $block_content;
	}

	/**
	 * Overrides the WooCommerce breadcrumb "Home" link with the language-
	 * appropriate home URL so the first breadcrumb crumb points to the correct
	 * language version of the site root.
	 *
	 * @param  string $url  Default home URL from WooCommerce.
	 * @return string
	 */
	public function translate_breadcrumb_home_url( string $url ): string {
		return $this->lang_home_url() ?? $url;
	}

	/**
	 * Translates the WordPress Privacy Policy page URL to the current language.
	 *
	 * Hooks `privacy_policy_url` (WordPress core), which fires from
	 * `get_privacy_policy_url()`.  Called by WC checkout, the WP login footer,
	 * and any theme or block rendering a Privacy Policy link.
	 *
	 * @param  string $url            Current privacy policy URL.
	 * @param  int    $policy_page_id Post ID of the source privacy policy page.
	 * @return string
	 */
	public function translate_privacy_policy_url( string $url, int $policy_page_id ): string {
		if ( ! defined( 'LF_LANG' ) ) {
			return $url;
		}
		if ( LF_LANG === $this->router->context->source_language() ) {
			return $url;
		}
		if ( $policy_page_id <= 0 ) {
			return $url;
		}

		$translations = $this->router->trid_group->get_translations( $policy_page_id );
		if ( empty( $translations[LF_LANG] ) ) {
			return $url;
		}

		$translated_url = get_permalink( (int) $translations[LF_LANG] );
		return $translated_url ?: $url;
	}

	// =========================================================
	// MENU TRANSLATION
	// =========================================================

	/**
	 * @param array<int, object> $items WordPress nav-menu items — stdClass
	 *     instances with dynamically attached `->url`, `->object_id`,
	 *     `->title`, etc. properties. WP's wp_nav_menu_objects filter is
	 *     the canonical injection point.
	 *
	 * @return array<int, object>
	 */
	public function translate_menu_items( array $items ): array {
		foreach ( $items as &$item ) {
			if ( ! empty( $item->object_id ) ) {
				$translations = $this->router->trid_group->get_translations( (int) $item->object_id );
				if ( ! empty( $translations[LF_LANG] ) ) {
					/** @phpstan-ignore-next-line property.notFound — Nav-menu items carry a dynamic ->url property attached by WP's wp_setup_nav_menu_item(). PHPStan can't infer this from the generic `object` type. */
					$item->url = get_permalink( $translations[LF_LANG] );
				}
			}
		}
		return $items;
	}

}
