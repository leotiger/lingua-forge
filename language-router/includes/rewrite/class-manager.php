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
		// Priority 20: add_cpt_archive_rewrite_rules() / add_cpt_single_rewrite_rules()
		// below both call get_post_types() to enumerate every registered CPT with a
		// custom rewrite slug. At the default init priority (10), that call can run
		// before a theme's or another plugin's own `add_action( 'init', ...register_post_type... )`
		// callback has fired (registration order among same-priority 'init' callbacks
		// follows plugin/theme load order, which Lingua Forge does not control) — any
		// CPT registered "later" in that same request is invisible to get_post_types()
		// at that point, so no language-prefixed rewrite rule is ever added for it, no
		// matter how many times permalinks are subsequently flushed. Confirmed live on
		// an Agnosis-family site: the "art" CPT's rewrite rule was silently never
		// registered because its plugin's post-type registration ran after this hook.
		// Priority 20 matches the same fix already applied to Columns::register_hooks()
		// for the identical class of problem (CPT-dependent admin-column hooks).
		add_action( 'init',        [ $this, 'register_rewrite_rules' ], 20 );
		add_filter( 'query_vars',  [ $this, 'add_query_vars' ] );
		add_filter( 'post_link',   [ $this, 'lang_permalink' ], 10, 2 );
		add_filter( 'page_link',   [ $this, 'lang_permalink' ], 10, 2 );

		// get_permalink() for any CUSTOM post type (registered via register_post_type(),
		// e.g. an "art" CPT) never runs through post_link/page_link at all — WordPress's
		// own get_post_permalink() applies the post_type_link filter instead (post_link
		// is documented core-side as applying only to the built-in 'post' type). Without
		// this, get_permalink() for a translated CPT post silently returns the un-prefixed
		// URL, which is exactly what the Language Switcher calls to build each language's
		// link — confirmed live on an Agnosis-family site: the switcher rendered every
		// "art" CPT link without its language prefix. lang_permalink()'s own signature
		// (2 args: $url, $post) is reused as-is; the two extra args post_type_link passes
		// ($leavename, $sample) are irrelevant to path-prefix rewriting and simply not
		// requested via the accepted_args below.
		add_filter( 'post_type_link', [ $this, 'lang_permalink' ], 10, 2 );

		// Prefix CPT archive links with the active language so breadcrumbs,
		// get_post_type_archive_link(), and nav-menu items pointing to CPT
		// archives produce language-prefixed URLs.
		add_filter( 'post_type_archive_link', [ $this, 'translate_cpt_archive_link' ], 10, 2 );

		// Prefix general (non-WC, non-built-in) taxonomy term links with the
		// active language so get_term_link() on translated pages returns a
		// language-prefixed URL.  WC taxonomy term links are handled separately
		// by WcPageBridge::translate_wc_term_link().
		add_filter( 'term_link', [ $this, 'translate_general_term_link' ], 10, 3 );
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

		// CPT archive rules — must be registered before the generic pagename
		// fallback so /es/events/ routes to a post_type=event archive rather than
		// being swallowed by `pagename=events` (which WP would 404).
		$this->add_cpt_archive_rewrite_rules( $langs );

		// CPT single-post rules — same precedence requirement, and covers a
		// distinct 404: a translated CPT post with a custom rewrite slug (e.g.
		// /es/art/some-artwork/) has no matching inbound rule at all without
		// this, since the fallback below treats the whole remainder as a flat
		// `pagename`, and `art/some-artwork` is not a real hierarchical page path.
		$this->add_cpt_single_rewrite_rules( $langs );

		// General taxonomy archive rules — same precedence requirement; must sit
		// before the generic fallback so /es/event-type/conference/ routes to the
		// correct taxonomy archive rather than being consumed by `pagename`.
		$this->add_general_taxonomy_archive_rewrite_rules( $langs );

		// Generic top-level slug with pagination — handles translated shop page,
		// blog index page, and any other top-level page that has paginated views.
		// Must sit after the CPT-archive and taxonomy pagination rules (which are
		// more specific) but before the generic pagename fallback below so that
		// /es/tienda/page/2/ resolves to pagename=tienda&paged=2 rather than
		// pagename=tienda/page/2 (which would fail to match the shop slug check
		// in WcPageBridge::inject_shop_post_type() and render no products).
		add_rewrite_rule(
			'^(' . $langs . ')/([^/]+)/page/([0-9]+)/?$',
			'index.php?lang=$matches[1]&pagename=$matches[2]&paged=$matches[3]',
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
	// CPT ARCHIVE REWRITE RULES
	// =========================================================

	/**
	 * Registers language-prefixed rewrite rules for all public CPTs that have
	 * a browsable archive (`has_archive` is true or a non-empty string).
	 *
	 * Without these rules, LF's generic fallback intercepts `/es/events/` as
	 * `pagename=events`, WP fails to find a page with that slug, and the CPT
	 * archive rewrite (`^events/?$`) never fires — resulting in a 404.
	 *
	 * Rules are added at 'top' priority BEFORE the generic pagename fallback
	 * (added last in register_rewrite_rules()), so they take precedence.
	 *
	 * WooCommerce's `product` CPT is excluded by default because its archive
	 * routing is handled separately by `WcPageBridge::inject_shop_post_type`.
	 * Additional exclusions can be added via the `linguaforge_cpt_archive_excluded_post_types`
	 * filter.
	 *
	 * @param string $langs  Pipe-separated language alternation string, e.g. 'ca|es|en'.
	 */
	private function add_cpt_archive_rewrite_rules( string $langs ): void {
		$excluded = (array) apply_filters(
			'linguaforge_cpt_archive_excluded_post_types',
			[ 'product', 'product_variation' ]
		);

		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $pto ) {
			// Skip CPTs without a browsable archive.
			if ( ! $pto->has_archive ) {
				continue;
			}
			if ( in_array( $pto->name, $excluded, true ) ) {
				continue;
			}

			// Derive the archive slug.
			// $pto->has_archive is either `true` (use the rewrite slug) or a
			// non-empty string explicitly specifying the archive base.
			if ( is_string( $pto->has_archive ) && '' !== $pto->has_archive ) {
				$slug = $pto->has_archive;
			} elseif ( ! empty( $pto->rewrite['slug'] ) ) {
				$slug = $pto->rewrite['slug'];
			} else {
				$slug = $pto->name;
			}
			$slug    = ltrim( $slug, '/' );
			$escaped = preg_quote( $slug, '#' );

			// With pagination.
			add_rewrite_rule(
				'^(' . $langs . ')/' . $escaped . '/page/([0-9]+)/?$',
				'index.php?lang=$matches[1]&post_type=' . $pto->name . '&paged=$matches[2]',
				'top'
			);
			// Without pagination.
			add_rewrite_rule(
				'^(' . $langs . ')/' . $escaped . '/?$',
				'index.php?lang=$matches[1]&post_type=' . $pto->name,
				'top'
			);
		}
	}

	// =========================================================
	// CPT SINGLE-POST REWRITE RULES
	// =========================================================

	/**
	 * Registers language-prefixed rewrite rules for the single-post permalink
	 * of every public CPT that has a custom rewrite slug.
	 *
	 * `lang_permalink()` / `rewrite_lang_permalink()` below build a translated
	 * CPT post's URL by prepending `{lang}/` to whatever the untranslated
	 * permalink already was — for a CPT with rewrite slug `art`, that produces
	 * `/{lang}/art/{postname}/`. Confirmed live on an Agnosis-family site
	 * (`agnosis_artwork`, rewrite slug `art`): without a dedicated inbound rule,
	 * that URL falls through to the generic pagename fallback registered at the
	 * end of register_rewrite_rules(), which treats `art/{postname}` as a single
	 * hierarchical page path — no such page exists, so the request 404s, even
	 * though the CPT post itself exists and its un-prefixed permalink
	 * (`/art/{postname}/`) resolves fine. This is the single-post equivalent of
	 * the CPT-archive gap add_cpt_archive_rewrite_rules() already closes.
	 *
	 * Built-in `post`/`page`/`attachment` are excluded — they either have no
	 * comparable custom slug segment or are already handled by the generic
	 * fallback (`post` normally has an empty rewrite slug; WordPress itself
	 * falls back from an unmatched `pagename` to a `post`-type lookup by name).
	 * WooCommerce `product`/`product_variation` are excluded by default,
	 * mirroring add_cpt_archive_rewrite_rules()'s exclusion — WC's own
	 * permalink/rewrite handling for products has not been audited against
	 * this rule and is left untouched to avoid an unverified interaction;
	 * site owners who need this for WC products can re-include them via the
	 * `linguaforge_cpt_single_excluded_post_types` filter.
	 *
	 * Hierarchical CPTs are skipped: their single-post permalinks can contain
	 * a variable-depth ancestor path (like Pages), which a fixed
	 * `{slug}/{postname}` pattern does not model — the same reason Pages
	 * themselves are excluded rather than handled here.
	 *
	 * @param string $langs  Pipe-separated language alternation string, e.g. 'ca|es|en'.
	 */
	private function add_cpt_single_rewrite_rules( string $langs ): void {
		$excluded = (array) apply_filters(
			'linguaforge_cpt_single_excluded_post_types',
			[ 'post', 'page', 'attachment', 'product', 'product_variation' ]
		);

		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $pto ) {
			if ( in_array( $pto->name, $excluded, true ) ) {
				continue;
			}
			if ( $pto->hierarchical ) {
				continue;
			}
			// Only CPTs with a real rewrite slug hit the fallback-swallows-it
			// failure mode this closes; a CPT with rewrite disabled has no
			// front-end single permalink to prefix in the first place.
			if ( empty( $pto->rewrite ) || empty( $pto->rewrite['slug'] ) ) {
				continue;
			}

			$slug    = ltrim( (string) $pto->rewrite['slug'], '/' );
			$escaped = preg_quote( $slug, '#' );

			// `post_type` + `name` (rather than the CPT's own query_var, which
			// may be disabled via `query_var => false`) is the same lookup
			// WordPress's own core-generated CPT single rules resolve through,
			// so this works regardless of that CPT's query_var configuration.
			add_rewrite_rule(
				'^(' . $langs . ')/' . $escaped . '/([^/]+)/?$',
				'index.php?lang=$matches[1]&post_type=' . $pto->name . '&name=$matches[2]',
				'top'
			);
		}
	}

	// =========================================================
	// GENERAL TAXONOMY ARCHIVE REWRITE RULES
	// =========================================================

	/**
	 * Registers language-prefixed rewrite rules for public non-built-in taxonomies
	 * that are not already handled by WcPageBridge (product_cat, product_tag, etc.).
	 *
	 * Without these rules, LF's generic fallback intercepts `/es/event-type/conference/`
	 * as `pagename=event-type/conference`, WP fails to find a matching page, and the
	 * taxonomy archive rewrite (`^event-type/(.+?)/?$`) never fires — resulting in a 404.
	 *
	 * The set of handled taxonomies is derived from all public taxonomies with a rewrite
	 * slug, minus WP built-ins (already have their own rules) and the WC taxonomy set
	 * (handled by WcPageBridge).  Third-party code can further exclude taxonomies via
	 * the `lf_public_taxonomy_archives_excluded` filter.
	 *
	 * @param string $langs  Pipe-separated language alternation string, e.g. 'ca|es|en'.
	 */
	private function add_general_taxonomy_archive_rewrite_rules( string $langs ): void {
		foreach ( $this->get_general_taxonomy_archive_list() as $tax_name ) {
			$tax_obj = get_taxonomy( $tax_name );
			if ( ! $tax_obj instanceof \WP_Taxonomy ) {
				continue;
			}
			$qv      = $tax_obj->query_var ?: $tax_name;
			$slug    = ltrim( (string) $tax_obj->rewrite['slug'], '/' );
			$escaped = preg_quote( $slug, '#' );

			// With pagination.
			add_rewrite_rule(
				'^(' . $langs . ')/' . $escaped . '/(.+?)/page/([0-9]+)/?$',
				'index.php?lang=$matches[1]&' . $qv . '=$matches[2]&paged=$matches[3]',
				'top'
			);
			// Without pagination.
			add_rewrite_rule(
				'^(' . $langs . ')/' . $escaped . '/(.+?)/?$',
				'index.php?lang=$matches[1]&' . $qv . '=$matches[2]',
				'top'
			);
		}
	}

	/**
	 * Returns the list of public taxonomy names that the general taxonomy archive
	 * bridge should handle.
	 *
	 * Excludes:
	 *  - WP built-in taxonomies — already handled by WordPress's own rewrite rules
	 *    (`category`, `post_tag`) or not public-facing (`post_format`, `nav_menu`,
	 *    `link_category`).
	 *  - WC product taxonomies — `product_cat`, `product_tag`, `product_brand`, and
	 *    internal WC taxonomies (`product_type`, `product_visibility`, `product_shipping_class`) —
	 *    handled by `WcPageBridge::register_taxonomy_archive_rewrite_rules`.  Hard-coded here
	 *    so Manager stays decoupled from the WC module.
	 *  - Taxonomies without a rewrite slug (no public front-end archive URL).
	 *
	 * Third-party code can exclude additional taxonomies via the
	 * `lf_public_taxonomy_archives_excluded` filter.
	 *
	 * @return string[]  Taxonomy names.
	 */
	private function get_general_taxonomy_archive_list(): array {
		$built_ins = [ 'category', 'post_tag', 'post_format', 'nav_menu', 'link_category' ];

		// WC-managed taxonomies — WcPageBridge registers their archive rules and
		// term-link filters independently.  Hard-code known WC taxonomy names so
		// Manager stays decoupled from the WC module; the filter below lets
		// WcPageBridge (or third-party code) add more at runtime if needed.
		$wc_defaults = [
			'product_cat', 'product_tag', 'product_brand',
			'product_type', 'product_visibility', 'product_shipping_class',
		];
		$excluded = array_merge(
			$built_ins,
			$wc_defaults,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
			(array) apply_filters( 'lf_public_taxonomy_archives_excluded', [] )
		);

		$result = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
			if ( in_array( $tax->name, $excluded, true ) ) {
				continue;
			}
			// Skip taxonomies without a front-end rewrite slug — they have no
			// archive URL to prefix.
			if ( empty( $tax->rewrite['slug'] ) ) {
				continue;
			}
			$result[] = $tax->name;
		}
		return $result;
	}

	// =========================================================
	// PERMALINK FILTER
	// =========================================================

	/**
	 * Prepends the active language to CPT archive links.
	 *
	 * WordPress's `get_post_type_archive_link()` returns a source-language URL
	 * like `/events/` without any language prefix.  On translated pages — or
	 * any page rendered under a language prefix — archive links in breadcrumbs,
	 * nav menus, and template calls would point to the source-language archive.
	 *
	 * This filter is the CPT-archive equivalent of `WcPageBridge::translate_wc_term_link`
	 * for WC taxonomy archives.  Unlike term links, CPT archive slugs are not
	 * translated — `/es/events/` uses the same `events` base as `/events/`.
	 *
	 * Skipped when:
	 *  - `LF_LANG` is not defined (router did not fire).
	 *  - The current language is the source language.
	 *  - The post type is in the exclusion list (WC `product` handled by WcPageBridge).
	 *
	 * @param  string $link       The CPT archive URL.
	 * @param  string $post_type  The post type name.
	 * @return string
	 */
	public function translate_cpt_archive_link( string $link, string $post_type ): string {
		if ( ! defined( 'LF_LANG' ) || LF_LANG === $this->router->context->source_language() ) {
			return $link;
		}

		$excluded = (array) apply_filters(
			'linguaforge_cpt_archive_excluded_post_types',
			[ 'product', 'product_variation' ]
		);
		if ( in_array( $post_type, $excluded, true ) ) {
			return $link;
		}

		$path = wp_parse_url( $link, PHP_URL_PATH );
		if ( ! $path ) {
			return $link;
		}

		return self::rewrite_lang_permalink(
			$path,
			LF_LANG,
			$this->router->context->languages(),
			$this->router->context->routing_mode(),
			$this->router->context->lang_base_url( LF_LANG ),
			home_url()
		);
	}

	/**
	 * Prepends the active language to general (non-WC, non-built-in) taxonomy
	 * term archive links.
	 *
	 * WordPress's `get_term_link()` returns source-language URLs such as
	 * `/event-type/conference/` regardless of the current language.  On translated
	 * pages any taxonomy term link would therefore land on the source-language archive.
	 *
	 * Scope is limited to the taxonomies returned by get_general_taxonomy_archive_list()
	 * (built-ins and WC taxonomies are excluded — they are handled elsewhere).
	 *
	 * Skipped when:
	 *  - Taxonomy is not in the general archive list.
	 *  - `LF_LANG` is not defined.
	 *  - The current language is the source language (no prefix needed).
	 *
	 * @param  string          $termlink  The term archive URL.
	 * @param  \WP_Term|mixed  $term      The term object (unused — we operate on the URL).
	 * @param  string          $taxonomy  The taxonomy slug.
	 * @return string
	 */
	public function translate_general_term_link( string $termlink, mixed $term, string $taxonomy ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $term required by term_link filter signature.
		if ( ! in_array( $taxonomy, $this->get_general_taxonomy_archive_list(), true ) ) {
			return $termlink;
		}
		if ( ! defined( 'LF_LANG' ) || LF_LANG === $this->router->context->source_language() ) {
			return $termlink;
		}

		$path = wp_parse_url( $termlink, PHP_URL_PATH );
		if ( ! $path ) {
			return $termlink;
		}

		return self::rewrite_lang_permalink(
			$path,
			LF_LANG,
			$this->router->context->languages(),
			$this->router->context->routing_mode(),
			$this->router->context->lang_base_url( LF_LANG ),
			home_url()
		);
	}

	/**
	 * Post types excluded from language-prefixed permalink rewriting even though
	 * lang_permalink() now runs on post_type_link (see register_hooks()).
	 *
	 * WooCommerce products intentionally stay on a single, language-neutral
	 * permalink (e.g. /product/camisa/) for every translation — LF_LANG on that
	 * URL is always the source language regardless of which translation is
	 * being viewed (see the language-neutral-URL handling in Switcher and
	 * CatalogQuery). Rewriting product/product_variation permalinks here would
	 * silently change that established behaviour for every WooCommerce site.
	 *
	 * @return string[]
	 */
	private function lang_permalink_excluded_post_types(): array {
		return (array) apply_filters(
			'linguaforge_permalink_excluded_post_types', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			[ 'product', 'product_variation' ]
		);
	}

	public function lang_permalink( string $url, $post ): string {
		if ( is_numeric( $post ) ) $post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) return $url;

		if ( in_array( $post->post_type, $this->lang_permalink_excluded_post_types(), true ) ) {
			return $url;
		}

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
