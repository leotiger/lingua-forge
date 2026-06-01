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

	/**
	 * Navigation language resolved by arm_page_list_lang_filter() for the
	 * Site Editor canvas / REST context. Consumed by filter_page_list_frontend()
	 * and cleared by clear_nav_lang_after_render() after the block renders.
	 *
	 * @var string|null
	 */
	private ?string $pending_page_list_lang = null;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_action( 'parse_query',    [ $this, 'handle_parse_query' ] );
		add_action( 'pre_get_posts',  [ $this, 'handle_pre_get_posts' ] );
		add_filter( 'get_pages',        [ $this, 'filter_page_list_frontend' ],   10, 2 );
		add_filter( 'pre_render_block', [ $this, 'arm_page_list_lang_filter' ],   10, 3 );
		add_filter( 'render_block',     [ $this, 'clear_nav_lang_after_render' ], 10, 2 );

		// REST: expose _lf_lang for wp_navigation posts and register lf_lang
		// as a valid query param on the pages endpoint so the block editor
		// sidebar can filter pages by language.
		add_action( 'rest_api_init',                 [ $this, 'register_rest_nav_lang_meta' ] );
		add_filter( 'rest_page_collection_params',   [ $this, 'register_lf_lang_rest_param' ] );
		add_filter( 'rest_page_query',               [ $this, 'filter_pages_by_lf_lang_rest' ], 10, 2 );
	}

	// =========================================================
	// REST — LANGUAGE PARAM FOR PAGES + NAV META EXPOSURE
	// =========================================================

	/**
	 * Exposes _lf_lang for wp_navigation posts in the REST API as meta.lf_lang
	 * so the block editor JS can read the navigation's language without a
	 * separate lookup.
	 *
	 * add_post_type_support is gated on edit_posts so that the meta field only
	 * appears in the REST schema for authenticated editors. Unauthenticated
	 * requests get no meta key in the response — the custom-fields support is
	 * never added, so WP_REST_Posts_Controller never includes the meta envelope.
	 * register_post_meta still runs unconditionally so the _lf_lang meta_query
	 * used by filter_pages_by_lf_lang_rest() works for all requests.
	 */
	public function register_rest_nav_lang_meta(): void {
		register_post_meta( 'wp_navigation', '_lf_lang', [
			'show_in_rest'  => [ 'name' => 'lf_lang' ],
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
		] );

		// Only expose the meta envelope to authenticated editors. wp_navigation
		// does not support custom-fields by default; adding it unconditionally
		// would expose all show_in_rest meta on published navigations to
		// unauthenticated REST clients.
		if ( current_user_can( 'edit_posts' ) ) {
			add_post_type_support( 'wp_navigation', 'custom-fields' );
		}
	}

	/**
	 * Registers lf_lang as a recognised collection param on the pages REST
	 * endpoint so WordPress passes it through to rest_page_query.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function register_lf_lang_rest_param( array $params ): array {
		$params['lf_lang'] = [
			'description'       => __( 'Limit results to pages in the given Lingua Forge language code.', 'lingua-forge' ),
			'type'              => 'string',
			'pattern'           => '^[a-z]{2,}(-[a-z]{2,})?$',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		];
		return $params;
	}

	/**
	 * Applies _lf_lang meta_query when lf_lang is present in a pages REST request.
	 *
	 * @param array<string, mixed>  $args
	 * @param \WP_REST_Request       $request
	 * @return array<string, mixed>
	 */
	public function filter_pages_by_lf_lang_rest( array $args, \WP_REST_Request $request ): array {
		$lang = sanitize_key( (string) $request->get_param( 'lf_lang' ) );
		if ( ! $lang ) return $args;

		$args['meta_query'][] = [ 'key' => '_lf_lang', 'value' => $lang ];
		return $args;
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

			// Skip WC post types on the frontend — same reason as admin: WC has its own
			// query pipeline and a meta_query JOIN on products is prohibitively expensive.
			$queried_type = (string) $q->get( 'post_type' );
			$wc_types     = [ 'product', 'shop_order', 'shop_coupon', 'shop_subscription', 'product_variation' ];
			if ( in_array( $queried_type, $wc_types, true ) ) return;

			if ( $q->is_search() ) {
				$meta_query   = $q->get( 'meta_query' ) ?: [];
				$meta_query[] = [ 'key' => '_lf_lang', 'value' => LF_LANG ];
				$q->set( 'meta_query', $meta_query );
				return;
			}

			if ( $q->is_archive() || $q->is_home() ) {
				$meta_query   = $q->get( 'meta_query' ) ?: [];
				$meta_query[] = [ 'key' => '_lf_lang', 'value' => LF_LANG ];
				$q->set( 'meta_query', $meta_query );
			}

			return;
		}

		// Admin — reached only when is_admin() is true (frontend block above always returns).
		// Skip WooCommerce post types — WC has its own admin query pipeline that
		// conflicts with appended meta_query conditions; WC support is handled separately.
		// Exception: when the user has set an explicit lf_lang_filter, honour it even for
		// WC post types — the filter is user-initiated and the meta_query cost is acceptable.
		// Read post_type from the URL directly: $q->get('post_type') can return an array
		// or be unset at this point on some WC versions, making in_array() unreliable.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL post_type param; no data is modified.
		$screen_post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : (string) $q->get( 'post_type' );
		$wc_non_content   = [ 'shop_order', 'shop_coupon', 'shop_subscription' ];
		$wc_content_types = [ 'product', 'product_variation' ];
		if ( in_array( $screen_post_type, $wc_non_content, true ) ) return;
		$has_lang_filter  = (bool) get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		if ( in_array( $screen_post_type, $wc_content_types, true ) && ! $has_lang_filter ) return;

		$meta_query = $q->get( 'meta_query' ) ?: [];

		// Always read the persisted language from user meta. Admin\Filters::persist_admin_lang_filter()
		// runs on load-edit.php — before pre_get_posts — and has already written the GET param value
		// to user meta by the time this hook fires. Reading $_GET directly causes interference with
		// WooCommerce's admin query pipeline on some WC versions.
		$lang = get_user_meta( get_current_user_id(), 'lf_lang_filter', true );

		if ( ! empty( $lang ) ) {
			$meta_query[] = [ 'key' => '_lf_lang', 'value' => $lang ];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( ! empty( $_GET['lf_outdated_filter'] ) ) {
			$meta_query[] = [
				'key'     => '_lf_lang',
				'value'   => $this->router->context->source_language(),
				'compare' => '!=',
			];
			$meta_query[] = [ 'key' => '_lf_translation_source_updated_at', 'compare' => 'NOT EXISTS' ];
		}

		if ( ! empty( $meta_query ) ) {
			$q->set( 'meta_query', $meta_query );
		}
	}

	// =========================================================
	// PAGE-LIST BLOCK — LANGUAGE SCOPING (PUBLIC FRONTEND + CANVAS)
	// =========================================================

	/**
	 * Filters get_pages() results to the active language on the public frontend
	 * and the Site Editor canvas. Also active in REST block-renderer requests
	 * when arm_page_list_lang_filter() has already set the pending language.
	 */
	public function filter_page_list_frontend( array $pages, array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args required by get_pages filter signature.
		if ( defined( 'WP_CLI' ) && WP_CLI ) return $pages;
		// Allow through when arm_page_list_lang_filter() has armed a language —
		// this covers the Site Editor canvas (is_admin() + REST_REQUEST) where the
		// navigation block renders server-side and core/page-list calls get_pages().
		if ( $this->pending_page_list_lang === null ) {
			if ( is_admin() ) {
				return $pages; // admin context without a pending nav lang — nothing to filter
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return $pages; // REST request without a pending nav lang — nothing to filter
			}
		}

		if ( $this->pending_page_list_lang !== null ) {
			// Consume-once: clear immediately so that if block rendering is
			// interrupted and clear_nav_lang_after_render() never fires, the
			// pending lang cannot bleed into a subsequent unrelated get_pages() call.
			$lang                         = $this->pending_page_list_lang;
			$this->pending_page_list_lang = null;
		} elseif ( defined( 'LF_LANG' ) ) {
			// Frontend (non-admin, non-REST) — filter by the active site language.
			$lang = LF_LANG;
		} else {
			return $pages;
		}

		$is_source = ( $lang === $this->router->context->source_language() );

		return array_values( array_filter(
			$pages,
			function ( \WP_Post $page ) use ( $lang, $is_source ): bool {
				$page_lang = get_post_meta( $page->ID, '_lf_lang', true );
				if ( ! $page_lang ) return $is_source;
				return $page_lang === $lang;
			}
		) );
	}

	/**
	 * Arms the page-list lang filter for the Site Editor canvas and REST
	 * requests by reading the navigation block's language into
	 * $pending_page_list_lang before core/page-list calls get_pages().
	 *
	 * Handles two cases:
	 *  1. core/navigation block with a ref — wraps the page-list (template context).
	 *  2. core/page-list rendered directly inside a wp_navigation post — the post
	 *     content IS the page-list with no outer navigation wrapper (navigation
	 *     post editor in the Site Editor).
	 *
	 * Runs ONLY on the canvas (?canvas=edit) and REST API — never on regular
	 * admin or frontend requests.
	 */
	public function arm_page_list_lang_filter( $pre_render, array $parsed_block, ?\WP_Block $parent_block = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $parent_block required by pre_render_block filter signature.
		if ( defined( 'WP_CLI' ) && WP_CLI ) return $pre_render;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_canvas = isset( $_GET['canvas'] ) && 'edit' === $_GET['canvas'];
		if ( ! $is_canvas && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $pre_render;
		}

		$block_name = $parsed_block['blockName'] ?? '';

		// ── Case 1: core/navigation wrapper (template context) ──────────────────
		if ( $block_name === 'core/navigation' ) {
			$ref = (int) ( $parsed_block['attrs']['ref'] ?? 0 );
			if ( $ref <= 0 ) {
				$fallbacks = get_posts( [
					'post_type'     => 'wp_navigation',
					'post_status'   => 'publish',
					'orderby'       => 'date',
					'order'         => 'DESC',
					'numberposts'   => 1,
					'no_found_rows' => true,
				] );
				if ( empty( $fallbacks ) ) return $pre_render;
				$ref = $fallbacks[0]->ID;
			}

			$this->pending_page_list_lang = $this->resolve_nav_lang( $ref );
			return $pre_render;
		}

		// ── Case 2: core/page-list rendered directly as wp_navigation post content ─
		// When editing a wp_navigation post in the Site Editor, the post content is
		// bare <!-- wp:page-list /--> with no outer core/navigation wrapper, so the
		// arm above never fires.  Detect the navigation post from the request context.
		if ( $block_name === 'core/page-list' && $this->pending_page_list_lang === null ) {
			$nav_id = $this->current_navigation_post_id();
			if ( $nav_id > 0 ) {
				$this->pending_page_list_lang = $this->resolve_nav_lang( $nav_id );
			}
		}

		return $pre_render;
	}

	/**
	 * Returns the _lf_lang for a wp_navigation post, falling back to a
	 * -{lang} slug suffix, then to the source language.
	 */
	private function resolve_nav_lang( int $nav_id ): string {
		$lang = get_post_meta( $nav_id, '_lf_lang', true );
		if ( ! $lang ) {
			$post = get_post( $nav_id );
			if ( $post ) {
				foreach ( $this->router->context->languages() as $l ) {
					if ( str_ends_with( $post->post_name, '-' . $l ) ) {
						$lang = $l;
						break;
					}
				}
			}
		}
		return $lang ?: $this->router->context->source_language();
	}

	/**
	 * Detects the wp_navigation post being edited from the current request context.
	 * Covers both the canvas (?postType=wp_navigation&postId=N) and the REST
	 * block-renderer / navigation REST endpoint (?id=N or ?postId=N).
	 */
	private function current_navigation_post_id(): int {
		// Primary: parse the REST route — e.g. /wp/v2/navigation/62
		// This is how the Site Editor fetches navigation posts for the canvas preview.
		global $wp;
		$rest_route = $wp->query_vars['rest_route'] ?? '';
		if ( preg_match( '#^/wp/v2/navigation/(\d+)#', $rest_route, $m ) ) {
			return (int) $m[1];
		}

		// Fallback: $_GET params (canvas URL context)
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$candidates = [
			(int) sanitize_text_field( wp_unslash( $_GET['postId']  ?? '' ) ),
			(int) sanitize_text_field( wp_unslash( $_GET['post_id'] ?? '' ) ),
			(int) sanitize_text_field( wp_unslash( $_GET['id']      ?? '' ) ),
		];
		// phpcs:enable

		foreach ( $candidates as $id ) {
			if ( $id <= 0 ) continue;
			$post = get_post( $id );
			if ( $post && $post->post_type === 'wp_navigation' ) {
				return $id;
			}
		}

		// Last resort: global $post
		global $post;
		if ( $post instanceof \WP_Post && $post->post_type === 'wp_navigation' ) {
			return $post->ID;
		}

		return 0;
	}

	/**
	 * Clears $pending_page_list_lang after the navigation block finishes
	 * rendering so it cannot bleed into subsequent get_pages() calls.
	 */
	public function clear_nav_lang_after_render( string $block_content, array $parsed_block ): string {
		$name = $parsed_block['blockName'] ?? '';
		// Clear after the navigation wrapper (case 1) or after the bare page-list
		// itself (case 2 — no wrapper, so we clear as soon as it finishes rendering).
		if ( $name === 'core/navigation' || $name === 'core/page-list' ) {
			$this->pending_page_list_lang = null;
		}
		return $block_content;
	}

	// =========================================================
	// QUERY HELPERS
	// =========================================================

	public function query( array $args = [] ): WP_Query {
		if ( ! empty( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $mq ) {
				if ( isset( $mq['key'] ) && $mq['key'] === '_lf_lang' ) {
					return new WP_Query( $args );
				}
			}
		}

		$args['meta_query'][] = [ 'key' => '_lf_lang', 'value' => LF_LANG ];

		return new WP_Query( $args );
	}

	public function query_fallback( array $args = [] ): WP_Query {
		$args['meta_query'][] = [
			'relation' => 'OR',
			[ 'key' => '_lf_lang', 'value' => LF_LANG ],
			[ 'key' => '_lf_lang', 'value' => $this->router->context->source_language() ],
		];

		return new WP_Query( $args );
	}

	public function get_posts( array $args = [], bool $fallback = false ): array {
		$q = $fallback ? $this->query_fallback( $args ) : $this->query( $args );
		return $q->posts;
	}
}
