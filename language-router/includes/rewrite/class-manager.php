<?php
/**
 * Class LinguaForge\Router\Rewrite\Manager
 *
 * Manages WordPress rewrite rules and permalink filters for language prefixes.
 */

namespace LinguaForge\Router\Rewrite;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Manager {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_action( 'init',        [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',  [ $this, 'add_query_vars' ] );
		add_filter( 'post_link',   [ $this, 'lang_permalink' ], 10, 2 );
		add_filter( 'page_link',   [ $this, 'lang_permalink' ], 10, 2 );
	}

	// =========================================================
	// REWRITE RULES
	// =========================================================

	public function register_rewrite_rules(): void {
		$langs = implode( '|', $this->router->context->languages() );

		add_rewrite_tag( '%lang%', '(' . $langs . ')' );

		// In subdomain mode the language is determined by the host, not the URL
		// path. No path-prefix rewrite rules are needed; WordPress handles routing
		// normally on each subdomain and the %lang% query var is still registered
		// above for admin-ajax and other internal uses.
		if ( $this->router->context->routing_mode() === 'subdomain' ) return;

		// Pagination
		add_rewrite_rule(
			'^(' . $langs . ')/page/([0-9]+)/?$',
			'index.php?lang=$matches[1]&paged=$matches[2]',
			'top'
		);

		// Category + pagination
		add_rewrite_rule(
			'^(' . $langs . ')/category/(.+?)/page/([0-9]+)/?$',
			'index.php?lang=$matches[1]&category_name=$matches[2]&paged=$matches[3]',
			'top'
		);

		add_rewrite_rule(
			'^(' . $langs . ')/category/(.+?)/?$',
			'index.php?lang=$matches[1]&category_name=$matches[2]',
			'top'
		);

		// Front page (/de/)
		add_rewrite_rule(
			'^(' . $langs . ')/?$',
			'index.php?lang=$matches[1]',
			'top'
		);

		// Generic fallback
		add_rewrite_rule(
			'^(' . $langs . ')/(.+)$',
			'index.php?lang=$matches[1]&pagename=$matches[2]',
			'top'
		);
	}

	// =========================================================
	// QUERY VARS
	// =========================================================

	public function add_query_vars( array $vars ): array {
		if ( $this->router->context->is_system_request() ) return $vars;
		$vars[] = 'lang';
		return $vars;
	}

	// =========================================================
	// PERMALINK FILTER
	// =========================================================

	public function lang_permalink( string $url, $post ): string {
		if ( is_numeric( $post ) ) $post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) return $url;

		// Only rewrite URLs for public, front-end post types.
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) return $url;

		$lang = $this->router->trid_group->get_lang( $post->ID );
		if ( ! $lang || $lang === $this->router->context->source_language() ) return $url;

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) return $url;

		return self::rewrite_lang_permalink(
			$path,
			$lang,
			$this->router->context->languages(),
			$this->router->context->routing_mode(),
			$this->router->context->lang_base_url( $lang ),
			\home_url()
		);
	}

	/**
	 * Rewrite a URL path to the given language — pure, WP-free.
	 *
	 * Public static so unit tests can call it directly with controlled inputs.
	 * Called by lang_permalink() after all WP-dependent lookups are resolved.
	 *
	 * @param  string   $path          The URL path component (e.g. '/en/about/').
	 * @param  string   $lang          Target language code (e.g. 'de').
	 * @param  string[] $langs         All active language codes.
	 * @param  string   $routing_mode  'path' or 'subdomain'.
	 * @param  string   $lang_base_url Subdomain base URL for $lang (e.g. 'https://de.example.org/').
	 * @param  string   $home_url      Site home URL (e.g. 'https://example.org').
	 * @return string   Rewritten absolute URL.
	 */
	public static function rewrite_lang_permalink(
		string $path,
		string $lang,
		array  $langs,
		string $routing_mode,
		string $lang_base_url,
		string $home_url
	): string {
		$langs_regex = implode( '|', array_map( 'preg_quote', $langs ) );

		if ( $routing_mode === 'subdomain' ) {
			$path = (string) preg_replace( '#^/(' . $langs_regex . ')/#', '/', $path );
			return $lang_base_url . ltrim( $path, '/' );
		}

		$path = trim( $path, '/' );
		$path = (string) preg_replace( '#^(' . $langs_regex . ')/#', '', $path );
		return \untrailingslashit( $home_url ) . '/' . $lang . '/' . $path . '/';
	}
}
