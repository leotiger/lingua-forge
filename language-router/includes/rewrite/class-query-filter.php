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
		add_action( 'pre_get_posts',  [ $this, 'handle_secondary_pre_get_posts' ] );
		add_filter( 'get_pages',        [ $this, 'filter_page_list_frontend' ],   10, 2 );
		add_filter( 'pre_render_block', [ $this, 'arm_page_list_lang_filter' ],   10, 3 );
		add_filter( 'render_block',     [ $this, 'clear_nav_lang_after_render' ], 10, 2 );

		// REST: expose _lf_lang for wp_navigation posts and register lf_lang
		// as a valid query param on the pages endpoint so the block editor
		// sidebar can filter pages by language.
		add_action( 'rest_api_init',                 [ $this, 'register_rest_nav_lang_meta' ] );
		add_filter( 'rest_page_collection_params',   [ $this, 'register_lf_lang_rest_param' ] );
		add_filter( 'rest_page_query',               [ $this, 'filter_pages_by_lf_lang_rest' ], 10, 2 );

		// Populate the secondary-query exclusion list with built-in known types
		// (e.g. wpcf7_contact_form) and any post types added via Settings → Router.
		add_filter( 'linguaforge_secondary_query_excluded_post_types', [ $this, 'builtin_excluded_post_types' ] );
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
		if ( ! $q->is_main_query() ) return;
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
			// A static front page (Settings -> Reading -> "A static page") is a
			// singular Page query resolved by ID/permalink -- there is no post
			// listing to scope by language, so it is skipped entirely.
			//
			// When the front page instead shows the latest posts ("Your latest
			// posts"), is_front_page() is ALSO true, but the query is a normal
			// posts listing that must be scoped to the active language exactly
			// like any other archive/home query -- without this distinction every
			// language's posts would appear mixed together on `/{lang}/`. Fall
			// through to the is_archive()/is_home() branch below in that case.
			if ( $q->is_front_page() && 'page' === get_option( 'show_on_front' ) ) return;

			// Skip WC post types on the frontend — same reason as admin: WC has its own
			// query pipeline and a meta_query JOIN on products is prohibitively expensive.
			$queried_type = (string) $q->get( 'post_type' );
			$wc_types     = [ 'product', 'shop_order', 'shop_coupon', 'shop_subscription', 'shop_booking', 'product_variation' ];
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
		$wc_non_content   = [ 'shop_order', 'shop_coupon', 'shop_subscription', 'shop_booking' ];
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
	// SECONDARY QUERY FILTER (non-main WP_Query instances)
	// =========================================================

	/**
	 * Adds a `_lf_lang` meta constraint to secondary (non-main) WP_Query
	 * instances on the public frontend.
	 *
	 * handle_pre_get_posts() guards with is_main_query() and therefore leaves
	 * all secondary queries unfiltered: sidebar widgets, `get_posts()` calls
	 * in templates, "Latest Posts" / "Latest Events" core blocks, and any
	 * other code that creates a WP_Query directly without going through the
	 * main query cycle.  Without this handler, those queries return results
	 * from all languages mixed together.
	 *
	 * WooCommerce post types are excluded — CatalogQuery already handles them
	 * via its own pre_get_posts hook and contains WC-specific logic (per-product
	 * `_lf_lang` override on language-neutral singular pages, related-products
	 * SQL patching, etc.) that must not be duplicated here.
	 *
	 * `post_type = 'any'` is skipped to avoid interfering with internal WP /
	 * WC queries that aggregate multiple post types, some of which may lack
	 * `_lf_lang` meta entirely.
	 *
	 * Third-party code can opt additional post types out of secondary filtering
	 * via the `linguaforge_secondary_query_excluded_post_types` filter.
	 *
	 * @param WP_Query $q  The secondary query being built.
	 */
	public function handle_secondary_pre_get_posts( WP_Query $q ): void {
		if ( $q->is_main_query() ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		// REST API requests are not public-frontend queries. The link control,
		// block renderer, and other editor REST calls have no URL language prefix,
		// so LF_LANG would resolve to the source language and silently exclude all
		// non-source-language content from search results. REST endpoints that need
		// explicit language scoping use the lf_lang query param registered via
		// filter_pages_by_lf_lang_rest() instead.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return;
		}

		$post_type = $q->get( 'post_type' );

		// Skip 'any' — too broad; may include internal types without _lf_lang.
		if ( 'any' === $post_type ) {
			return;
		}

		// Skip ID-only lookups (fields='ids' or 'id=>parent'). These are
		// internal infrastructure queries — not content displayed to users.
		// WcPageBridge and similar helpers use get_posts(['fields'=>'ids'])
		// to resolve translation page IDs; injecting a language constraint
		// would exclude the very pages they are trying to find.
		$fields = $q->get( 'fields' );
		if ( 'ids' === $fields || 'id=>parent' === $fields ) {
			return;
		}

		// Normalize: empty string means WordPress will query 'post'.
		$types = '' !== $post_type ? (array) $post_type : [ 'post' ];

		// WordPress system / infrastructure post types — never carry _lf_lang meta
		// and must not be language-filtered here. wp_navigation is especially
		// critical: injecting a meta constraint causes WP_Navigation_Fallback to
		// find zero results and create a brand-new navigation post from the latest
		// classic menu, which manifests as unexpected navigation items on the
		// frontend. nav_menu_item queries (classic menus) are also system-internal.
		// This list mirrors internal_post_types() in class-sync.php (wp_navigation
		// is intentionally included here, unlike the sync exclusion list).
		$system_types = [
			'wp_navigation', 'wp_navigation_fallback',
			'nav_menu_item',
			'wp_template', 'wp_template_part',
			'wp_block', 'wp_global_styles',
			'wp_font_family', 'wp_font_face',
		];
		if ( array_intersect( $types, $system_types ) ) {
			return;
		}

		// WC post types are handled by CatalogQuery — skip if any type in the
		// query is a WC type to avoid double-injection or logic conflicts.
		$wc_types = [ 'product', 'product_variation', 'shop_order', 'shop_coupon', 'shop_subscription', 'shop_booking' ];
		if ( array_intersect( $types, $wc_types ) ) {
			return;
		}

		// WordPress 6.3+ routes get_pages() through WP_Query, so pre_get_posts now
		// fires for get_pages() calls made inside core/page-list during navigation
		// rendering.  When the navigation arm is active (pending_page_list_lang is
		// set), filter_page_list_frontend() handles language scoping for those
		// get_pages() results.  Injecting a SQL meta_query here as well would
		// filter out translated pages before filter_page_list_frontend sees them,
		// causing the fallback path to kick in and showing source-language pages
		// on translated WooCommerce product pages.
		if ( in_array( 'page', $types, true ) && $this->pending_page_list_lang !== null ) {
			return;
		}

		// Allow third-party code to opt additional post types out.
		$excluded = (array) apply_filters( 'linguaforge_secondary_query_excluded_post_types', [] );
		if ( $excluded && array_intersect( $types, $excluded ) ) {
			return;
		}

		// Double-application guard — skip if _lf_lang is already constrained
		// (e.g. by a theme or plugin that called QueryFilter::query() directly).
		$meta_query = (array) $q->get( 'meta_query' ) ?: [];
		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) && '_lf_lang' === $clause['key'] ) {
				return;
			}
		}

		$meta_query[] = [
			'key'   => '_lf_lang',
			'value' => LF_LANG,
		];

		$q->set( 'meta_query', $meta_query );
	}

	/**
	 * Populates the `linguaforge_secondary_query_excluded_post_types` filter with
	 * built-in known types and any types added via Settings → Router.
	 *
	 * wpcf7_contact_form is always included: CF7 resolves non-numeric shortcode IDs
	 * via get_posts() against that post type, and CF7 form posts carry no _lf_lang
	 * meta, so injecting the meta constraint returns zero results and silently
	 * breaks form rendering on any page.
	 *
	 * wp_sync_storage is always included: WordPress uses this post type internally
	 * to store language-pack sync data for the installed site languages. It never
	 * carries _lf_lang meta and must not be language-filtered.
	 *
	 * flamingo_contact / flamingo_inbound are always included: Flamingo (CF7
	 * companion plugin) stores form contacts and inbound messages in these types.
	 * They carry no _lf_lang meta and must not be language-filtered.
	 *
	 * @param  array<string> $types  Types already excluded by other callbacks.
	 * @return array<string>
	 */
	public function builtin_excluded_post_types( array $types ): array {
		$builtin    = [ 'wpcf7_contact_form', 'wp_sync_storage', 'flamingo_contact', 'flamingo_inbound' ];
		$saved      = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$user_types = array_filter( array_map( 'sanitize_key', explode( ',', $saved ) ) );
		return array_values( array_unique( array_merge( $types, $builtin, $user_types ) ) );
	}

	// =========================================================
	// PAGE-LIST BLOCK — LANGUAGE SCOPING (PUBLIC FRONTEND + CANVAS)
	// =========================================================

	/**
	 * Filters get_pages() results to the active language on the public frontend
	 * and the Site Editor canvas. Also active in REST block-renderer requests
	 * when arm_page_list_lang_filter() has already set the pending language.
	 *
	 * If the language filter yields no pages (e.g. a translated WooCommerce product
	 * on a language-neutral URL whose nav pages have not yet been translated),
	 * the method falls back to showing source-language pages so the navigation
	 * is never left empty.
	 *
	 * Pages marked with _lf_page_menu_exclude are hidden in
	 * language filter so they appear in every language's navigation regardless
	 * of their own _lf_lang value.  Developers can extend the excluded-ID list
	 * via the linguaforge_page_menu_excluded_page_ids filter.
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
			$lang = $this->pending_page_list_lang;
			// On the public frontend, block rendering is synchronous and
			// clear_nav_lang_after_render() reliably fires after the navigation
			// wrapper finishes — so keep pending alive for all get_pages() calls
			// within that single navigation render (e.g. one call per sub-menu
			// level or breakpoint variant).
			// On canvas/REST (admin or REST_REQUEST), rendering can be interrupted
			// mid-block, so consume immediately to prevent bleed into subsequent
			// unrelated get_pages() calls.
			if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				$this->pending_page_list_lang = null;
			}
		} elseif ( defined( 'LF_LANG' ) ) {
			// Frontend (non-admin, non-REST) — filter by the active site language.
			$lang = LF_LANG;
		} else {
			return $pages;
		}

		$is_source = ( $lang === $this->router->context->source_language() );

		// Collect IDs of pages explicitly excluded from all language navigations.
		// We check only pages already in the get_pages() result — get_post_meta()
		// is object-cached so this adds no DB overhead on warm requests.
		$meta_excluded_ids = array_map(
			fn( \WP_Post $p ) => $p->ID,
			array_filter( $pages, fn( \WP_Post $p ) => (bool) get_post_meta( $p->ID, '_lf_page_menu_exclude', true ) )
		);

		/**
		 * Filters the page IDs that are hidden from every language's navigation
		 * page-list, regardless of their _lf_lang value.
		 *
		 * By default this list is derived from the _lf_page_menu_exclude postmeta
		 * flag set via the Language meta box or Quick Edit.  Developers can extend
		 * it programmatically — e.g. to always hide the privacy-policy page:
		 *
		 *     add_filter( 'linguaforge_page_menu_excluded_page_ids', function( $ids ) {
		 *         return array_merge( $ids, [ get_option( 'wp_page_for_privacy_policy' ) ] );
		 *     } );
		 *
		 * Note: this filter operates on core/page-list blocks inside core/navigation
		 * only.  Classic nav menus (wp_nav_menu) render from stored nav_menu_item
		 * posts and are unaffected.
		 *
		 * @param int[] $ids Page IDs to hide from every language navigation.
		 */
		$excluded_ids = (array) apply_filters( 'linguaforge_page_menu_excluded_page_ids', $meta_excluded_ids ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		$filtered = array_values( array_filter(
			$pages,
			function ( \WP_Post $page ) use ( $lang, $is_source, $excluded_ids ): bool {
				if ( in_array( $page->ID, $excluded_ids, true ) ) return false;
				$page_lang = get_post_meta( $page->ID, '_lf_lang', true );
				if ( ! $page_lang ) return $is_source;
				return $page_lang === $lang;
			}
		) );

		// Fallback for language-neutral URLs (e.g. WooCommerce product pages) where
		// the queried post is a translation but no translated navigation pages exist
		// yet.  Rather than rendering an empty navigation, show source-language pages
		// so the menu is always present.  When translated nav pages are added later
		// they will appear automatically without any extra configuration.
		if ( ! $filtered && ! $is_source ) {
			$source = $this->router->context->source_language();
			$filtered = array_values( array_filter(
				$pages,
				function ( \WP_Post $page ) use ( $source, $excluded_ids ): bool {
					if ( in_array( $page->ID, $excluded_ids, true ) ) return false;
					$page_lang = get_post_meta( $page->ID, '_lf_lang', true );
					return ! $page_lang || $page_lang === $source;
				}
			) );
		}

		return $filtered;
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
		$is_rest   = defined( 'REST_REQUEST' ) && REST_REQUEST;

		$block_name = $parsed_block['blockName'] ?? '';

		// ── Frontend path ─────────────────────────────────────────────────────────
		// LF_LANG is URL-derived.  On prefixed URLs (/es/…, /ca/…) it is the right
		// language.  On language-neutral URLs (/product/…) LF_LANG is always the
		// source language, but the queried post may have a different _lf_lang —
		// e.g. a Spanish product at /product/camisa/ has LF_LANG=en (source) even
		// though its template uses header-es / navigation-es.
		//
		// Arm ONLY when all of the following hold:
		//  1. We are on a language-neutral URL  (LF_LANG === source language).
		//  2. The request is singular           (archives like /shop/ use LF_LANG).
		//  3. The queried post has a non-source _lf_lang  (= it is a translation).
		//  4. The navigation's _lf_lang matches that post language — the template
		//     deliberately chose a language-specific nav for this post.
		//
		// In every other case filter_page_list_frontend() falls through to LF_LANG,
		// which is correct for prefixed URLs, source-language singulars, and archives.
		if ( ! $is_canvas && ! $is_rest ) {
			// Arm when we are on a language-neutral URL (LF_LANG === source) but the
			// queried post is a translation (post._lf_lang !== source).  In this case
			// LF_LANG does not reflect the content language, so filter_page_list_frontend
			// must be told which language to use rather than falling through to LF_LANG.
			//
			// We use the post's own _lf_lang as the pending language — not the
			// navigation's language — so that the page-list shows pages in the correct
			// language regardless of which navigation post the template chose.  This
			// also handles CA/other languages when only an ES navigation exists: the
			// page-list shows CA pages, not ES pages.
			//
			// The arm does NOT fire on prefixed URLs (LF_LANG !== source): there the
			// fallback in filter_page_list_frontend already uses LF_LANG directly.
			if ( $block_name === 'core/navigation' && defined( 'LF_LANG' ) ) {
				$source = $this->router->context->source_language();
				if ( LF_LANG === $source && is_singular() ) {
					$post = get_queried_object();
					if ( $post instanceof \WP_Post ) {
						$post_lang = (string) get_post_meta( $post->ID, '_lf_lang', true );
						if ( $post_lang && $post_lang !== $source ) {
							$this->pending_page_list_lang = $post_lang;
						}
					}
				}
			}
			return $pre_render;
		}

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

		$lang = defined( 'LF_LANG' ) ? LF_LANG : $this->router->context->source_language();
		$args['meta_query'][] = [ 'key' => '_lf_lang', 'value' => $lang ];

		return new WP_Query( $args );
	}

	public function query_fallback( array $args = [] ): WP_Query {
		$lang   = defined( 'LF_LANG' ) ? LF_LANG : $this->router->context->source_language();
		$source = $this->router->context->source_language();

		$args['meta_query'][] = [
			'relation' => 'OR',
			[ 'key' => '_lf_lang', 'value' => $lang ],
			[ 'key' => '_lf_lang', 'value' => $source ],
		];

		return new WP_Query( $args );
	}

	public function get_posts( array $args = [], bool $fallback = false ): array {
		$q = $fallback ? $this->query_fallback( $args ) : $this->query( $args );
		return $q->posts;
	}
}
