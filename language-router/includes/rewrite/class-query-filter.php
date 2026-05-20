<?php
/**
 * Class LinguaForge\Router\Rewrite\QueryFilter
 *
 * Filters WP_Query objects on both the frontend and in wp-admin to scope
 * results to the active language, and exposes convenience query helpers.
 */

namespace LinguaForge\Router\Rewrite;

use LinguaForge\Router\Router;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) exit;

class QueryFilter {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_action( 'parse_query',    [ $this, 'handle_parse_query' ] );
		add_action( 'pre_get_posts',  [ $this, 'handle_pre_get_posts' ] );
	}

	// =========================================================
	// PARSE QUERY
	// =========================================================

	public function handle_parse_query( $q ): void {
		if ( $this->router->context->is_system_request() ) return;
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;

		$q->set( 'lang', LF_LANG );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( ! empty( $_GET['s'] ) ) {
			$q->is_search = true;
			$q->is_home   = false;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			$this->router->debug( 'Search forced', [ 's' => sanitize_text_field( wp_unslash( $_GET['s'] ) ) ] );
		}
	}

	// =========================================================
	// PRE_GET_POSTS
	// =========================================================

	public function handle_pre_get_posts( $q ): void {
		if ( ! $q->is_main_query() ) return;

		// Frontend
		if ( ! is_admin() ) {
			if ( $q->is_front_page() ) return;

			if ( $q->is_search() ) {
				$meta_query   = $q->get( 'meta_query' ) ?: [];
				$meta_query[] = [ 'key' => '_lang', 'value' => LF_LANG ];
				$q->set( 'meta_query', $meta_query );
				$this->router->debug( 'Search filtered by language', [ 'lang' => LF_LANG ] );
				return;
			}

			if ( $q->is_archive() || $q->is_home() ) {
				$meta_query   = $q->get( 'meta_query' ) ?: [];
				$meta_query[] = [ 'key' => '_lang', 'value' => LF_LANG ];
				$q->set( 'meta_query', $meta_query );
			}

			return;
		}

		// Admin — reached only when is_admin() is true (frontend block above always returns).
		$meta_query = $q->get( 'meta_query' ) ?: [];
		$user_id    = get_current_user_id();
		$lang       = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( isset( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
		} else {
			$lang = get_user_meta( $user_id, 'lf_lang_filter', true );
		}

		if ( ! empty( $lang ) ) {
			$meta_query[] = [ 'key' => '_lang', 'value' => $lang ];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( ! empty( $_GET['lf_outdated_filter'] ) ) {
			$meta_query[] = [
				'key'     => '_lang',
				'value'   => $this->router->context->source_language(),
				'compare' => '!=',
			];
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => '_translation_source_updated_at', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_translation_source_updated_at', 'value' => 0, 'compare' => '=' ],
			];
		}

		if ( ! empty( $meta_query ) ) {
			$q->set( 'meta_query', $meta_query );
		}
	}

	// =========================================================
	// QUERY HELPERS
	// =========================================================

	public function query( array $args = [] ): WP_Query {
		if ( ! empty( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $mq ) {
				if ( isset( $mq['key'] ) && $mq['key'] === '_lang' ) {
					return new WP_Query( $args );
				}
			}
		}

		$args['meta_query'][] = [ 'key' => '_lang', 'value' => LF_LANG ];

		return new WP_Query( $args );
	}

	public function query_fallback( array $args = [] ): WP_Query {
		$args['meta_query'][] = [
			'relation' => 'OR',
			[ 'key' => '_lang', 'value' => LF_LANG ],
			[ 'key' => '_lang', 'value' => $this->router->context->source_language() ],
		];

		return new WP_Query( $args );
	}

	public function get_posts( array $args = [], bool $fallback = false ): array {
		$q = $fallback ? $this->query_fallback( $args ) : $this->query( $args );
		return $q->posts;
	}
}
