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

		// Site logo link
		add_filter( 'render_block', [ $this, 'fix_site_logo_link' ], 20, 2 );

		// Menu translation
		add_filter( 'wp_nav_menu_objects', [ $this, 'translate_menu_items' ] );
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

		// Search redirect
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( isset( $_GET['s'] ) && preg_match( '#^/[a-z]{2}/#', $path ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : LF_LANG;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			$s    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			$this->router->debug( 'SEARCH REDIRECT: /?lang=' . $lang . '&s=' . $s );
			wp_safe_redirect( '/?lang=' . $lang . '&s=' . rawurlencode( $s ), 301 );
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
			! preg_match( '#^/[a-z]{2}/?$#', $path )
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );

		if ( preg_match( '#^/[a-z]{2}/#', $uri ) ) {
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

	public function fix_site_logo_link( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/site-logo' ) return $block_content;
		if ( ! defined( 'LF_LANG' ) ) return $block_content;

		$front_id = get_option( 'page_on_front' );
		if ( ! $front_id ) return $block_content;

		$translations = $this->router->trid_group->get_translations( $front_id );

		if ( LF_LANG === $this->router->context->source_language() ) {
			$target_id = $front_id;
		} elseif ( ! empty( $translations[LF_LANG] ) ) {
			$target_id = $translations[LF_LANG];
		} else {
			$target_id = $front_id;
		}

		$target_url = get_permalink( $target_id );

		$block_content = preg_replace(
			'/<a\s+([^>]*?)href="[^"]*"/',
			'<a $1href="' . esc_url( $target_url ) . '"',
			$block_content,
			1
		);

		return $block_content;
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
