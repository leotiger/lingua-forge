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
		if ( ! $post || ! isset( $post->ID ) ) return $url;

		$lang = $this->router->trid_group->get_lang( $post->ID );
		if ( ! $lang || $lang === $this->router->context->source_language() ) return $url;

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) return $url;

		$path        = trim( $path, '/' );
		$langs_regex = implode( '|', array_map( 'preg_quote', $this->router->context->languages() ) );
		$path        = preg_replace( '#^(' . $langs_regex . ')/#', '', $path );

		return home_url( '/' . $lang . '/' . $path . '/' );
	}
}
