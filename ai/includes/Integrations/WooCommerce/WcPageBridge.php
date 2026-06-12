<?php
/**
 * Admin and frontend bridge between WooCommerce's built-in page registry and
 * Lingua Forge's translated page graph.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.2.2
 */

declare(strict_types=1);

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

class WcPageBridge {

	/**
	 * WC option keys for built-in pages, in the order they appear in WC's registry.
	 *
	 * @var string[]
	 */
	private const WC_PAGE_OPTIONS = [
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
	];

	/**
	 * Per-request cache: WC option key → `_lf_trid` of the corresponding
	 * source page.  null = not yet built.  Built once on the first call that
	 * needs it, then reused for every subsequent page row / conditional check.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $source_trids = null;

	/**
	 * Per-request cache for resolved translated WC built-in page IDs.
	 *
	 * Keyed by WC option name (e.g. 'woocommerce_cart_page_id').
	 * Absence = not yet resolved.
	 * false   = resolved; no translation for the current language.
	 * int     = resolved translated page ID.
	 *
	 * Shared by all translate_*_page_id callbacks so every WC built-in page
	 * is resolved at most once per request regardless of how many times WC
	 * calls wc_get_page_id() for that type.
	 *
	 * @var array<string, int|false>
	 */
	private static array $translated_page_ids = [];

	/**
	 * Per-request cache of post_name slugs belonging to the WC My Account page
	 * and its LF translations.  Populated once by is_myaccount_page_slug().
	 *
	 * @var string[]|null  null = not yet populated.
	 */
	private static ?array $myaccount_slugs = null;

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 20 — run after WC's own display_post_states at priority 10
		// so WC has already labelled source-language pages before we handle
		// translated equivalents.
		add_filter( 'display_post_states', [ self::class, 'add_translated_page_states' ], 20, 2 );

		// Returns the translated page ID for each WC built-in page type so every
		// WC code path that calls wc_get_page_id( 'shop|cart|checkout|myaccount' )
		// — including is_shop(), breadcrumbs, mini-cart links, and the template
		// loader — all see the translated ID automatically.
		add_filter( 'woocommerce_get_shop_page_id',     [ self::class, 'translate_shop_page_id' ] );
		add_filter( 'woocommerce_get_cart_page_id',     [ self::class, 'translate_cart_page_id' ] );
		add_filter( 'woocommerce_get_checkout_page_id', [ self::class, 'translate_checkout_page_id' ] );
		add_filter( 'woocommerce_get_myaccount_page_id', [ self::class, 'translate_myaccount_page_id' ] );

		// WC reads the Privacy Policy page via wc_privacy_policy_page_id() which
		// applies this filter and calls get_permalink() directly — it never goes
		// through WordPress's get_privacy_policy_url().  The Redirector separately
		// hooks privacy_policy_url for WP core contexts (login footer, etc.).
		add_filter( 'woocommerce_privacy_policy_page_id', [ self::class, 'translate_privacy_policy_page_id' ] );

		// Two filter points for the T&C page:
		//   woocommerce_get_terms_page_id          — via wc_get_page_id('terms')
		//   woocommerce_terms_and_conditions_page_id — via wc_get_terms_and_conditions_page_id()
		// The second is the one WC_Checkout and the checkout template actually call to
		// render the "I agree to Terms & Conditions" link.  Both need to return the
		// translated page ID; both resolve via the same cached option-key lookup.
		add_filter( 'woocommerce_get_terms_page_id',            [ self::class, 'translate_terms_page_id' ] );
		add_filter( 'woocommerce_terms_and_conditions_page_id', [ self::class, 'translate_terms_page_id' ] );

		// Priority 9 — before WC_Query::pre_get_posts at 10.  Converts the
		// translated shop page's pagename query to a product-type archive so
		// WC's hook calls product_query() and fires woocommerce_product_query,
		// which lets CatalogQuery inject the per-language _lf_lang constraint.
		add_action( 'pre_get_posts', [ self::class, 'inject_shop_post_type' ], 9 );

		// Taxonomy archive rewrite rules for product_cat / product_tag must be
		// registered at priority 9, before Manager::register_rewrite_rules() at p10,
		// so they are inserted into $extra_rules_top before the generic pagename
		// fallback and therefore take priority over it.
		add_action( 'init', [ self::class, 'register_taxonomy_archive_rewrite_rules' ], 9 );

		// Prefix product_cat and product_tag archive URLs with the active language.
		// Without this, get_term_link() returns source-language archive URLs and
		// category links on translated pages point to the wrong archive.
		add_filter( 'term_link', [ self::class, 'translate_wc_term_link' ], 10, 3 );

		// Convert a language-prefixed WC taxonomy archive query into an explicit
		// translated-product ID set.  Runs at p9 so the query vars are set before
		// WC_Query::pre_get_posts at p10 (which would set post_type=product and
		// cause QueryFilter to exit early via its WC-type exclusion guard).
		add_action( 'pre_get_posts', [ self::class, 'inject_taxonomy_archive_lang' ], 9 );

		// Scope WC's related-products to the same language as the current
		// product so English and Spanish products are never mixed in the
		// "Productos relacionados" / "Related products" section.
		// Uses woocommerce_related_products (not _args) because that hook
		// receives $product_id directly; the _args hook fires inside get_posts()
		// where get_queried_object_id() returns 0.
		add_filter( 'woocommerce_related_products', [ self::class, 'filter_related_products_by_lang' ], 10, 2 );

		// WC My Account sub-endpoint URLs under a language prefix arrive as
		// pagename=mi-cuenta/orders because LF's generic rewrite rule captures
		// everything after the lang prefix as a single pagename value.  Split
		// the multi-segment pagename into page slug + WC endpoint query var
		// before WP_Query runs so WC can resolve the correct account page.
		add_filter( 'request', [ self::class, 'fix_myaccount_endpoint_request' ] );

		// Set lf_shop_page_id query var for the source-language shop page (/shop/).
		// inject_shop_post_type() handles translated shop pages (pagename queries);
		// the source shop uses WC's native rewrite and never passes through that hook.
		// The language switcher reads this var to build correct per-language shop URLs
		// instead of falling back to slug-stripping (which produces /es/shop/ rather
		// than /es/tienda/).
		add_action( 'wp', [ self::class, 'set_source_shop_page_id_query_var' ] );

		// Fix taxonomy archive page titles on translated pages.
		// woocommerce_page_title() builds the title as:
		//   sprintf( __('Products by %s', 'woocommerce'), $tax->labels->singular_name )
		// $tax->labels->singular_name is cached in $wp_taxonomies at init time in the
		// source locale and does not update when switch_to_locale() fires, so the
		// taxonomy type noun ("Category", "Tag", …) stays in the source language even
		// though the rest of the page is in the target language.  This filter rebuilds
		// the title using live gettext calls in the already-switched locale.
		add_filter( 'woocommerce_page_title', [ self::class, 'fix_taxonomy_archive_title' ] );
	}

	// =========================================================================
	// Hook: display_post_states
	// =========================================================================

	/**
	 * Appends WC page-type labels to translated equivalents of WC built-in pages.
	 *
	 * Only fires when the displayed post carries a `_lf_trid` that matches a
	 * WC source page.  The source page itself is skipped because WC's own hook
	 * at priority 10 has already attached its label.
	 *
	 * @param array<string,string> $states Existing post states from WP / WC.
	 * @param \WP_Post             $post   The post row being rendered.
	 * @return array<string,string>
	 */
	public static function add_translated_page_states( array $states, \WP_Post $post ): array {
		$trid = (string) get_post_meta( $post->ID, '_lf_trid', true );
		if ( '' === $trid ) {
			return $states;
		}

		$labels = self::page_labels();

		foreach ( self::get_source_trids() as $option => $source_trid ) {
			if ( $source_trid !== $trid ) {
				continue;
			}
			// Skip the source page itself — WC has already labelled it.
			$source_id = (int) get_option( $option );
			if ( $source_id === $post->ID ) {
				continue;
			}
			$states[ $option ] = $labels[ $option ] ?? $option;
		}

		return $states;
	}

	// =========================================================================
	// Hooks: woocommerce_get_{shop|cart|checkout|myaccount}_page_id
	// =========================================================================

	/**
	 * Returns the translated equivalent of any WC built-in page ID.
	 *
	 * All five WC page types (shop, cart, checkout, myaccount, terms) follow
	 * the same pattern: look up the source page's `_lf_trid`, then find the
	 * page that matches that trid AND `_lf_lang = LF_LANG`.  This is the single
	 * implementation; the public callbacks below are thin wrappers.
	 *
	 * Result is cached in `$translated_page_ids` after the first resolution so
	 * repeated calls to `wc_get_page_id()` within the same request (breadcrumbs,
	 * template loader, `is_shop()`, mini-cart links, etc.) cost no extra queries.
	 *
	 * Returns the original `$page_id` when:
	 *  - `is_admin()` is true.
	 *  - `LF_LANG` is not defined — source-language request; router did not fire.
	 *  - Source page has no `_lf_trid` — WC pages not linked into LF groups.
	 *  - No page with matching `_lf_trid` + `_lf_lang` found in the DB.
	 *
	 * @param  mixed  $page_id    Raw value from the WC option.
	 * @param  string $option_key e.g. 'woocommerce_cart_page_id'.
	 * @return mixed  Translated ID on success, original `$page_id` on fallback.
	 */
	private static function translate_wc_page_id( mixed $page_id, string $option_key ): mixed {
		if ( is_admin() ) {
			return $page_id;
		}
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $page_id;
		}

		if ( array_key_exists( $option_key, self::$translated_page_ids ) ) {
			$cached = self::$translated_page_ids[ $option_key ];
			return false === $cached ? $page_id : $cached;
		}

		$source_trid = self::get_source_trids()[ $option_key ] ?? '';
		if ( '' === $source_trid ) {
			self::$translated_page_ids[ $option_key ] = false;
			return $page_id;
		}

		$ids = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_lf_trid', 'value' => $source_trid ],
				[ 'key' => '_lf_lang', 'value' => LF_LANG ],
			],
		] );

		if ( ! empty( $ids ) ) {
			self::$translated_page_ids[ $option_key ] = (int) $ids[0];
			return self::$translated_page_ids[ $option_key ];
		}

		self::$translated_page_ids[ $option_key ] = false;
		return $page_id;
	}

	/** @param mixed $id Raw option value. @return mixed */
	public static function translate_shop_page_id( mixed $id ): mixed {
		return self::translate_wc_page_id( $id, 'woocommerce_shop_page_id' );
	}

	/** @param mixed $id Raw option value. @return mixed */
	public static function translate_cart_page_id( mixed $id ): mixed {
		return self::translate_wc_page_id( $id, 'woocommerce_cart_page_id' );
	}

	/** @param mixed $id Raw option value. @return mixed */
	public static function translate_checkout_page_id( mixed $id ): mixed {
		return self::translate_wc_page_id( $id, 'woocommerce_checkout_page_id' );
	}

	/** @param mixed $id Raw option value. @return mixed */
	public static function translate_myaccount_page_id( mixed $id ): mixed {
		return self::translate_wc_page_id( $id, 'woocommerce_myaccount_page_id' );
	}

	/** @param mixed $id Raw option value. @return mixed */
	public static function translate_terms_page_id( mixed $id ): mixed {
		return self::translate_wc_page_id( $id, 'woocommerce_terms_page_id' );
	}

	/**
	 * Translates the WC Privacy Policy page ID to the current language.
	 *
	 * WC resolves the Privacy Policy page via wc_privacy_policy_page_id() which
	 * reads WP's `wp_page_for_privacy_policy` option and calls get_permalink()
	 * directly — it never goes through WordPress's get_privacy_policy_url().
	 * This filter intercepts the page ID before get_permalink() is called.
	 *
	 * The privacy policy page is a WordPress core page (not a WC option), so it
	 * cannot go through translate_wc_page_id().  We resolve the translation
	 * directly via TridGroup::get_translations().
	 *
	 * @param  mixed $page_id  Raw page ID from the option.
	 * @return mixed  Translated page ID, or original on fallback.
	 */
	public static function translate_privacy_policy_page_id( mixed $page_id ): mixed {
		if ( is_admin() ) {
			return $page_id;
		}
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $page_id;
		}

		$page_id = (int) $page_id;
		if ( $page_id <= 0 ) {
			return $page_id;
		}

		$router = \LinguaForge\Router\Router::get_instance();
		if ( LF_LANG === $router->context->source_language() ) {
			return $page_id;
		}

		$translations = $router->trid_group->get_translations( $page_id );
		if ( empty( $translations[LF_LANG] ) ) {
			return $page_id;
		}

		return (int) $translations[LF_LANG];
	}

	// =========================================================================
	// Hook: pre_get_posts
	// =========================================================================

	/**
	 * Converts a pagename-based translated-shop-page query to a product archive.
	 *
	 * WooCommerce's source-language shop URL (/shop/) is handled by a WC rewrite
	 * rule that maps it to `post_type=product` at parse time — a real product
	 * archive.  Translated shop URLs (/es/tienda/) arrive as page queries via
	 * Lingua Forge's `pagename=tienda&lang=es` rewrite.
	 *
	 * `WC_Query::pre_get_posts()` at priority 10 has a fallback gate that can
	 * convert page queries to product archives, but it requires
	 * `show_on_front = 'page'`.  When the front page shows latest posts
	 * (`show_on_front = 'posts'`) the gate is always false.  As a result
	 * `product_query()` is never called and `woocommerce_product_query` never
	 * fires — so `CatalogQuery` cannot inject the language constraint and the
	 * page renders with no products.
	 *
	 * This hook runs at priority 9 (before WC at 10) and rewrites the query
	 * in-place:
	 *  - Clears `pagename` and sets `post_type = product`.
	 *  - Flips `is_page / is_singular` to false and `is_archive /
	 *    is_post_type_archive` to true.
	 *
	 * After the rewrite, `WC_Query::pre_get_posts()` sees
	 * `is_post_type_archive('product') = true`, skips its bailout `elseif`,
	 * calls `product_query()`, and fires `woocommerce_product_query`.
	 *
	 * @param \WP_Query $q Main query (already filtered to main query only).
	 */
	public static function inject_shop_post_type( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_page() ) {
			return;
		}
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return;
		}
		$pagename = (string) $q->get( 'pagename' );
		if ( '' === $pagename ) {
			return;
		}

		// Warm the cache if not yet populated.  translate_shop_page_id calls
		// get_posts() internally; the is_main_query() guard above prevents re-entry.
		if ( ! array_key_exists( 'woocommerce_shop_page_id', self::$translated_page_ids ) ) {
			wc_get_page_id( 'shop' ); // triggers translate_shop_page_id → fills cache
		}
		$shop_id = self::$translated_page_ids['woocommerce_shop_page_id'] ?? false;
		if ( ! is_int( $shop_id ) ) {
			return; // false — no translated shop page for this language
		}

		// Verify this pagename actually belongs to the translated shop page.
		// Slug-level comparison is sufficient: WC shop pages are always top-level.
		if ( get_post_field( 'post_name', $shop_id ) !== $pagename ) {
			return;
		}

		// Convert page query → product-type archive.
		$q->set( 'pagename', '' );
		$q->set( 'post_type', 'product' );
		$q->is_page             = false;
		$q->is_singular         = false;
		$q->is_archive          = true;
		$q->is_post_type_archive = true;

		// Preserve the original page ID so the language switcher can recover it.
		// After the archive conversion is_singular() is false and get_the_ID() may
		// return a product ID from the archive loop — the switcher would then build
		// product URLs instead of shop-page URLs.  Reading this query var lets the
		// Switcher use the correct page ID with force_permalink=true.
		$q->set( 'lf_shop_page_id', $shop_id );
	}

	// =========================================================================
	// Hook: init — WC taxonomy archive rewrite rules
	// =========================================================================

	/**
	 * Registers rewrite rules for language-prefixed WC taxonomy archive URLs.
	 *
	 * WooCommerce's own rewrite rules handle source-language archives:
	 *   /product-category/clothing/  →  index.php?product_cat=clothing
	 *   /product-tag/sale/           →  index.php?product_tag=sale
	 *
	 * Lingua Forge must additionally handle language-prefixed equivalents:
	 *   /es/product-category/ropa/   →  index.php?lang=es&product_cat=ropa
	 *   /es/product-tag/oferta/      →  index.php?lang=es&product_tag=oferta
	 *
	 * Without dedicated rules, Manager's generic fallback `^(lang)/(.+)$` matches
	 * these paths as pagename queries, which WordPress resolves to 404 because no
	 * page exists with the WC taxonomy slug.
	 *
	 * Rules are registered at init p9, one step before Manager::register_rewrite_rules()
	 * at p10, so they appear in $extra_rules_top before the generic fallback and
	 * therefore take priority over it.  The actual WC permalink base slugs are read
	 * from the 'woocommerce_permalinks' option so custom bases (e.g. 'categoria'
	 * instead of 'product-category') are respected automatically.
	 */
	public static function register_taxonomy_archive_rewrite_rules(): void {
		$router = \LinguaForge\Router\Router::get_instance();
		if ( $router->context->routing_mode() === 'subdomain' ) {
			return; // Subdomain mode: language is in the host; no path-prefix rules needed.
		}

		$langs = implode( '|', $router->context->languages() );
		if ( '' === $langs ) {
			return;
		}

		foreach ( self::get_product_archive_taxonomies() as $tax_name ) {
			$tax_obj = get_taxonomy( $tax_name );
			if ( ! $tax_obj instanceof \WP_Taxonomy || empty( $tax_obj->rewrite['slug'] ) ) {
				continue; // Not registered or has no rewrite slug (e.g. product_brand before WC Brands is active).
			}
			$qv   = $tax_obj->query_var ?: $tax_name;
			$slug = ltrim( (string) $tax_obj->rewrite['slug'], '/' );
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

	// =========================================================================
	// Hook: term_link — WC taxonomy archive URL language prefix
	// =========================================================================

	/**
	 * Prepends the active language to WC taxonomy archive links.
	 *
	 * WordPress's get_term_link() returns source-language URLs like
	 * /product-category/clothing/ without any language prefix.  On translated
	 * pages (e.g. a Spanish product at /product/camisa-es/), category links would
	 * land on the source-language archive rather than /es/product-category/ropa/.
	 *
	 * Scope is limited to taxonomies returned by get_product_archive_taxonomies()
	 * (product_cat, product_tag, product_brand by default; filterable via
	 * `linguaforge_wc_product_archive_taxonomies`).  Attribute taxonomies (pa_*) and
	 * internal ones (product_type, product_visibility) are excluded.
	 *
	 * Language resolution:
	 *  - On language-prefixed pages, LF_LANG holds the request language directly.
	 *  - On language-neutral product pages (/product/slug/), LF_LANG equals the
	 *    source language even for translated products.  In that case we read
	 *    _lf_lang from the queried post so a Spanish product page produces Spanish
	 *    category links rather than source-language ones.
	 *
	 * @param string $termlink  The current term archive URL.
	 * @param mixed  $term      The WP_Term object.
	 * @param string $taxonomy  The taxonomy slug.
	 * @return string  Language-prefixed URL, or original URL for source language.
	 */
	public static function translate_wc_term_link( string $termlink, mixed $term, string $taxonomy ): string {
		if ( ! in_array( $taxonomy, self::get_product_archive_taxonomies(), true ) ) {
			return $termlink;
		}
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $termlink;
		}

		$router      = \LinguaForge\Router\Router::get_instance();
		$source_lang = $router->context->source_language();

		// Resolve effective language.  On language-neutral product URLs
		// (/product/camisa/) LF_LANG equals the source language even when the
		// queried product is a translation.  Read _lf_lang from the queried object
		// so category links on a Spanish product page point to the Spanish archive.
		$lang = LF_LANG;
		if ( $lang === $source_lang && is_singular() ) {
			$queried_id = get_queried_object_id();
			if ( $queried_id ) {
				$post_lang = (string) get_post_meta( $queried_id, '_lf_lang', true );
				if ( $post_lang && $post_lang !== $source_lang ) {
					$lang = $post_lang;
				}
			}
		}

		if ( $lang === $source_lang ) {
			return $termlink; // Source language — no prefix needed.
		}

		$path = wp_parse_url( $termlink, PHP_URL_PATH );
		if ( ! $path ) {
			return $termlink;
		}

		return \LinguaForge\Router\Rewrite\Manager::rewrite_lang_permalink(
			$path,
			$lang,
			$router->context->languages(),
			$router->context->routing_mode(),
			$router->context->lang_base_url( $lang ),
			home_url()
		);
	}

	// =========================================================================
	// Hook: pre_get_posts — WC taxonomy archive language injection
	// =========================================================================

	/**
	 * Converts a language-prefixed WC taxonomy archive query to an explicit
	 * translated-product ID set.
	 *
	 * When a visitor browses /es/product-category/ropa/, the rewrite rule maps
	 * the URL to index.php?lang=es&product_cat=ropa.  WordPress builds a SQL JOIN
	 * on wp_term_relationships to find products in the 'ropa' term.  In LF's
	 * shared-stock model, translated products are NOT stored in wp_term_relationships
	 * — only source products are (delegation via TaxonomyDelegate::maybe_delegate_terms
	 * covers get_the_terms() calls, not the SQL JOIN).  A plain tax_query therefore
	 * returns zero translated products and the archive appears empty.
	 *
	 * This hook runs at pre_get_posts priority 9 (before WC_Query at 10) and
	 * replaces the taxonomy constraint with an explicit post__in list via three steps:
	 *
	 *  1. Fetch source-language products in the requested category/tag.
	 *     suppress_filters=true prevents pre_get_posts from firing on the inner
	 *     query (avoiding recursion) and bypasses CatalogQuery / TaxonomyDelegate.
	 *  2. Collect their _lf_trid values.
	 *  3. Fetch translated products matching those trids + LF_LANG.
	 *
	 * Clearing the taxonomy qvar removes the SQL JOIN from the main query while
	 * the is_tax flag and queried_object (set by parse_query before this hook fires)
	 * remain intact, so WC template conditionals like is_product_category() and
	 * woocommerce_get_loop_display_mode() continue to work correctly.
	 *
	 * @param \WP_Query $q  The current main query.
	 */
	public static function inject_taxonomy_archive_lang( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return;
		}

		$source_lang = \LinguaForge\Router\Router::get_instance()->context->source_language();
		if ( LF_LANG === $source_lang ) {
			return; // Source-language archive — WP/WC handles it natively.
		}

		$tax_qv    = '';
		$term_slug = '';
		foreach ( self::get_product_archive_taxonomies() as $tax_name ) {
			$val = (string) $q->get( $tax_name );
			if ( '' !== $val ) {
				$tax_qv    = $tax_name;
				$term_slug = $val;
				break;
			}
		}
		if ( '' === $tax_qv ) {
			return;
		}

		// Resolve the taxonomy term.  For hierarchical taxonomies the query var
		// may be a slash-separated path (e.g. 'clothing/jackets'); extract the
		// leaf slug for the get_term_by lookup.
		$leaf_slug = basename( $term_slug );
		$term_obj  = get_term_by( 'slug', $leaf_slug, $tax_qv );
		if ( ! $term_obj instanceof \WP_Term ) {
			return;
		}

		// Phase 1: source-language products in this taxonomy term.
		//
		// IMPORTANT: WordPress fires pre_get_posts unconditionally at the start
		// of WP_Query::get_posts(), BEFORE it checks suppress_filters.  This means
		// CatalogQuery::apply_language_filter_to_secondary_query() still fires on
		// this inner query and would inject _lf_lang = LF_LANG (the target language),
		// which would exclude source-language products from the result.
		//
		// To prevent that, we add _lf_lang = $source_lang explicitly.  CatalogQuery's
		// callback checks for an existing _lf_lang entry and returns early if it finds
		// one, so our source-language filter wins and the override is blocked.
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$source_ids = get_posts( [
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => $tax_qv,
					'field'    => 'term_id',
					'terms'    => $term_obj->term_id,
				],
			],
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'       => [
				[ 'key' => '_lf_lang', 'value' => $source_lang ],
			],
		] );

		// Phase 2: collect _lf_trid values from the source products.
		$trids = [];
		foreach ( $source_ids as $id ) {
			$trid = (string) get_post_meta( (int) $id, '_lf_trid', true );
			if ( '' !== $trid ) {
				$trids[] = $trid;
			}
		}

		if ( empty( $trids ) ) {
			// No source products in this term have a _lf_trid → nothing to translate.
			// Use a meta constraint that matches no product: WC_Query::product_query()
			// only appends to meta_query (never clears it), so this survives whereas
			// post__in=[0] would be overwritten by WC's wc_get_hidden_product_ids() merge.
			$q->set( $tax_qv,       '' );
			$q->set( 'tax_query',   [] );
			$q->set( 'post_type',   'product' );
			$q->set( 'post_status', 'publish' );
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$q->set( 'meta_query',  [ [ 'key' => '_lf_lang', 'value' => '__lf_no_match__' ] ] );
			return;
		}

		// Replace the taxonomy constraint with meta_query entries that scope
		// to the translated products for this category.
		//
		// Five query-var changes are needed:
		//
		// 1. Clear the named taxonomy qvar (product_cat / product_tag).
		//
		// 2. Clear query_vars['tax_query'].  parse_query() copies the product_cat
		//    slug into query_vars['tax_query'] as an explicit entry alongside the
		//    named qvar.  Clearing the named qvar does not remove this entry.  If
		//    left in place, get_posts() calls parse_tax_query() which rebuilds
		//    $this->tax_query from query_vars and generates a JOIN / NOT IN subquery
		//    on wp_term_relationships — translated products have no term_relationship
		//    rows, so the join produces wrong results.  Clearing the array lets
		//    WC_Query::product_query() add only its own product_visibility entries
		//    from scratch, which is correct.
		//
		// 3. Pin post_type = product.  Without an active named taxonomy qvar, WP
		//    cannot infer the correct object type from the taxonomy's registered
		//    object_types and falls back to querying all publicly queryable types
		//    (page, post, attachment, product …).  Setting post_type explicitly
		//    prevents that fallback.
		//
		// 4. Pin post_status = publish.  Taxonomy archives are public-facing pages
		//    and should show only published products — never private drafts.
		//
		// 5. Add _lf_trid IN ($trids) + _lf_lang = LF_LANG to meta_query.
		//    These two entries together are the category scope: only products
		//    translated from a source product that belongs to the queried category
		//    match _lf_trid IN ($trids), and only the target language matches
		//    _lf_lang.  Using meta_query rather than post__in ensures the
		//    constraints survive WC_Query::product_query(), which only appends to
		//    meta_query via get_meta_query() but may overwrite post__in.  Adding
		//    _lf_lang here also trips the early-return guard in QueryFilter and
		//    CatalogQuery::apply_language_filter(), preventing a duplicate clause.
		//
		// is_tax / is_product_category / queried_object are already baked into
		// the WP_Query object by parse_query() and remain intact — WC template
		// conditionals (is_product_category(), woocommerce_get_loop_display_mode())
		// continue to work correctly.
		$meta_query   = (array) $q->get( 'meta_query' ) ?: [];
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$meta_query[] = [ 'key' => '_lf_trid', 'value' => $trids, 'compare' => 'IN' ];
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$meta_query[] = [ 'key' => '_lf_lang', 'value' => LF_LANG ];

		$q->set( $tax_qv,       '' );
		$q->set( 'tax_query',   [] );
		$q->set( 'post_type',   'product' );
		$q->set( 'post_status', 'publish' );
		$q->set( 'meta_query',  $meta_query );
	}

	// =========================================================================
	// Hook: wp — source-language shop page query var
	// =========================================================================

	/**
	 * Sets the lf_shop_page_id query var for the source-language shop page.
	 *
	 * inject_shop_post_type() handles translated shop URLs (/es/tienda/, /ca/botiga/)
	 * which arrive as pagename queries and are converted to product archives at
	 * pre_get_posts priority 9.  The source-language shop (/shop/) uses WooCommerce's
	 * own rewrite rule and is already a post_type=product archive by the time LF
	 * hooks run — inject_shop_post_type() never fires for it and lf_shop_page_id is
	 * never set.
	 *
	 * Without this var, Switcher::get_languages() falls back to URL-rewrite mode and
	 * generates /es/shop/, /ca/shop/, etc. instead of /es/tienda/, /ca/botiga/.
	 * Those slugs redirect correctly via the language router but add an unnecessary
	 * round-trip.  Setting lf_shop_page_id here lets the switcher resolve the correct
	 * per-language shop permalink directly.
	 */
	public static function set_source_shop_page_id_query_var(): void {
		if ( is_admin() ) return;

		// Translated shop pages already have lf_shop_page_id set by inject_shop_post_type().
		if ( get_query_var( 'lf_shop_page_id' ) ) return;

		if ( ! function_exists( 'is_shop' ) || ! is_shop() ) return;

		$shop_id = (int) get_option( 'woocommerce_shop_page_id' );
		if ( $shop_id > 0 ) {
			global $wp_query;
			$wp_query->set( 'lf_shop_page_id', $shop_id );
		}
	}

	// =========================================================================
	// Hook: request — WC My Account sub-endpoint URL fix
	// =========================================================================

	/**
	 * Fixes 404s on WC My Account sub-endpoint URLs under a language prefix.
	 *
	 * When a visitor browses /es/mi-cuenta/orders/, Lingua Forge's generic rewrite
	 * rule `^(ca|es)/(.+)$` captures `mi-cuenta/orders` as a single pagename.
	 * WordPress cannot find a page with that compound slug → 404.  WooCommerce's
	 * own endpoint rules (`mi-cuenta/([^/]*)/?`) live in the main rules array and
	 * are never reached because LF's 'top' rule wins first.
	 *
	 * This hook fires on the `request` filter — after `WP::parse_request()` has
	 * resolved query vars but before WP_Query is created.  When the pagename
	 * contains a slash we check whether:
	 *
	 *  a) The first path segment is a slug belonging to the My Account page
	 *     (source or any LF translation).
	 *  b) The second path segment is a registered WC My Account endpoint slug.
	 *
	 * If both conditions hold, we rewrite:
	 *   pagename   → first segment only  (the My Account page slug)
	 *   [qvar_key] → endpoint value      (empty for list endpoints; order ID
	 *                                     for view-order, etc.)
	 *
	 * @param  array<string,mixed> $query_vars  Resolved WordPress query vars.
	 * @return array<string,mixed>
	 */
	public static function fix_myaccount_endpoint_request( array $query_vars ): array {
		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return $query_vars;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->query ) {
			return $query_vars;
		}

		// Work directly from the request URI rather than from $query_vars.
		//
		// Root cause: WC's endpoint rewrite rule `(.?.+?)/orders(/(.*))?/?$` lives
		// in the main rules array, but the TOP-5 log confirms that WC registers its
		// own 'top' rules (wc-auth, wc-api, …) that push LF's generic fallback lower
		// than expected.  For /es/mi-cuenta/orders/ the WC endpoint rule wins and
		// captures pagename=es/mi-cuenta (a non-existent hierarchical path).
		// WordPress then calls get_page_by_path('es/mi-cuenta'), fails, clears all
		// query vars, and sets error=404 — all BEFORE the `request` filter fires.
		// Inspecting $query_vars['pagename'] here therefore never sees the slash-
		// separated value; instead $query_vars === ['error' => '404'].
		//
		// Fix: parse the URI ourselves.  If it matches the pattern
		//   /{lang}/{page-slug}/{endpoint-slug}[/{endpoint-value}]
		// and the page-slug is a My Account slug and the endpoint-slug is a
		// registered WC endpoint, rebuild $query_vars from scratch.
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only for URL path parsing; no output
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! $path ) {
			return $query_vars;
		}
		$path = trim( $path, '/' ); // e.g. "es/mi-cuenta/orders" or "es/mi-cuenta/view-order/123"

		$router      = \LinguaForge\Router\Router::get_instance();
		$langs_regex = implode( '|', array_map( 'preg_quote', $router->context->languages() ) );

		// Pattern: {lang}/{page-slug}/{endpoint-slug}[/{endpoint-value}]
		if ( ! preg_match( '#^(' . $langs_regex . ')/([^/]+)/([^/]+)(/(.*))?$#', $path, $m ) ) {
			return $query_vars;
		}

		$page_slug      = $m[2];
		$endpoint_slug  = $m[3];
		$endpoint_value = isset( $m[5] ) ? trim( $m[5], '/' ) : '';

		// Gate: page slug must belong to the My Account page.
		if ( ! self::is_myaccount_page_slug( $page_slug ) ) {
			return $query_vars;
		}

		// Map URL slug → WC query var key.
		// WC()->query->get_query_vars() returns [qvar_key => url_slug];
		// flip to [url_slug => qvar_key].
		$wc_qvars     = (array) WC()->query->get_query_vars();
		$slug_to_qvar = array_flip( $wc_qvars );

		if ( ! isset( $slug_to_qvar[ $endpoint_slug ] ) ) {
			return $query_vars;
		}
		$qvar_key = (string) $slug_to_qvar[ $endpoint_slug ];

		// Rebuild query vars: clear the error, set pagename + lang + endpoint.
		return [
			'pagename'  => $page_slug,
			'page'      => '',
			'lang'      => $m[1],
			$qvar_key   => $endpoint_value,
		];
	}

	/**
	 * Returns true if $slug is the post_name of the WC My Account source page
	 * or any of its LF translations.
	 *
	 * Builds the slug set once per request and caches it in self::$myaccount_slugs.
	 *
	 * @param  string $slug  URL-decoded post_name slug to check.
	 * @return bool
	 */
	private static function is_myaccount_page_slug( string $slug ): bool {
		if ( null !== self::$myaccount_slugs ) {
			return in_array( $slug, self::$myaccount_slugs, true );
		}

		self::$myaccount_slugs = [];

		$source_id = (int) get_option( 'woocommerce_myaccount_page_id' );
		if ( $source_id <= 0 ) {
			return false;
		}

		// Source page slug.
		$source_slug = get_post_field( 'post_name', $source_id );
		if ( $source_slug ) {
			self::$myaccount_slugs[] = $source_slug;
		}

		// Slugs of all LF-linked translations via _lf_trid.
		$trid = (string) get_post_meta( $source_id, '_lf_trid', true );
		if ( '' !== $trid ) {
			$translations = get_posts( [
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => [ $source_id ],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[ 'key' => '_lf_trid', 'value' => $trid ],
				],
			] );
			foreach ( $translations as $trans_id ) {
				$trans_slug = get_post_field( 'post_name', (int) $trans_id );
				if ( $trans_slug ) {
					self::$myaccount_slugs[] = $trans_slug;
				}
			}
		}

		return in_array( $slug, self::$myaccount_slugs, true );
	}

	// =========================================================================
	// Hook: woocommerce_page_title — taxonomy archive title locale fix
	// =========================================================================

	/**
	 * Re-derives the taxonomy archive page title in the current switched locale.
	 *
	 * WooCommerce's woocommerce_page_title() calls:
	 *   sprintf( __( 'Products by %s', 'woocommerce' ), $tax->labels->singular_name )
	 *
	 * The __() call for the format string correctly uses the switched locale because
	 * it fires at template render time.  But $tax->labels->singular_name is stored
	 * in $wp_taxonomies at taxonomy registration time (init); if WooCommerce's
	 * plugins_loaded callback loads its text domain before LF's switch_to_locale()
	 * fires, the singular_name is left in the source locale (e.g. "categoria" in
	 * Catalan instead of "Categoría" in Spanish).
	 *
	 * This filter explicitly re-calls WooCommerce's own registration strings (same
	 * gettext keys, same text domain) in the already-switched locale so the
	 * complete title — format string AND taxonomy noun — appears in the target language.
	 *
	 * @param  string $title  Title produced by woocommerce_page_title().
	 * @return string         Corrected title, or $title unchanged when the fix is not needed.
	 */
	public static function fix_taxonomy_archive_title( string $title ): string {
		if ( ! defined( 'LF_LANG' ) ) {
			return $title;
		}
		$source_lang = \LinguaForge\Router\Router::get_instance()->context->source_language();
		if ( LF_LANG === $source_lang ) {
			return $title;
		}
		if ( ! is_tax() ) {
			return $title;
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return $title;
		}

		$singular = self::get_wc_taxonomy_singular( $term->taxonomy );
		if ( '' === $singular ) {
			return $title;
		}

		// Re-call the WC format string — uses the switched locale, so it returns
		// the translated form (e.g. "Productos por %s" for es_ES).
		// translators: %s: taxonomy singular name (e.g. "Category", "Tag", "Brand").
		return sprintf( __( 'Products by %s', 'woocommerce' ), $singular ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentionally calling WooCommerce's own string in the switched locale to match its registered translation.
	}

	/**
	 * Returns the singular label for a WooCommerce taxonomy in the current locale.
	 *
	 * For WooCommerce's built-in taxonomies the return value comes from a live
	 * gettext call (same key used at registration) in the switched locale.
	 * For pa_* attribute taxonomies, the AttributeLabelAdmin per-language option
	 * is checked first; if absent, the cached source-language label is returned
	 * as a fallback.
	 *
	 * @param  string $taxonomy  Taxonomy slug (e.g. 'product_cat', 'pa_color').
	 * @return string            Singular label, or '' if the taxonomy is unknown.
	 */
	private static function get_wc_taxonomy_singular( string $taxonomy ): string {
		// Built-in WC taxonomies — re-call their registration gettext strings in
		// the current locale.  Explicit literals required by WordPress.WP.I18n sniffs.
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- 'woocommerce' domain is intentional: we are re-calling the exact strings WooCommerce uses to register each taxonomy's singular_name label.
		switch ( $taxonomy ) {
			case 'product_cat':
				return __( 'Category', 'woocommerce' );
			case 'product_tag':
				return __( 'Tag', 'woocommerce' );
			case 'product_brand':
				return __( 'Brand', 'woocommerce' );
			case 'product_type':
				return __( 'Product type', 'woocommerce' );
			case 'product_shipping_class':
				return __( 'Shipping class', 'woocommerce' );
			case 'product_visibility':
				return __( 'Visibility', 'woocommerce' );
		}
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch

		// pa_* attribute taxonomies: prefer AttributeLabelAdmin's per-language
		// translation stored in linguaforge_attr_labels_{taxonomy}.
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			$labels = (array) get_option( 'linguaforge_attr_labels_' . $taxonomy, [] );
			if ( ! empty( $labels[ LF_LANG ] ) ) {
				return (string) $labels[ LF_LANG ];
			}
			// Fall back to whatever the cached label is (may be source-language).
			$tax = get_taxonomy( $taxonomy );
			return $tax instanceof \WP_Taxonomy ? (string) $tax->labels->singular_name : '';
		}

		return '';
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Returns the list of WC product taxonomy slugs that have public browsable
	 * archives and should be handled by LF's language-prefix rewrite rules,
	 * term-link prefixing, and archive language injection.
	 *
	 * Defaults cover the three built-in WC public archive taxonomies:
	 *   product_cat   — /product-category/…  (configurable via WC permalinks)
	 *   product_tag   — /product-tag/…        (configurable via WC permalinks)
	 *   product_brand — /brand/…              (WC Brands, WC 8.x+; skipped if not registered)
	 *
	 * Third-party brand or attribute plugins that register their own public
	 * taxonomy can add it via the `linguaforge_wc_product_archive_taxonomies` filter.
	 * Attribute taxonomies (pa_*) and internal ones (product_type, product_visibility)
	 * should NOT be added — they have no browsable archive pages.
	 *
	 * @return string[]  Taxonomy names.
	 */
	private static function get_product_archive_taxonomies(): array {
		return (array) apply_filters(
			'linguaforge_wc_product_archive_taxonomies',
			[ 'product_cat', 'product_tag', 'product_brand' ]
		);
	}

	/**
	 * Returns the localized display labels for WC built-in pages.
	 *
	 * Labels are intentionally loaded from the 'woocommerce' text domain so they
	 * match the strings WC's own display_post_states hook shows for source pages.
	 * Explicit string literals are required by WordPress.WP.I18n sniffs.
	 *
	 * @return array<string,string>  option_key → translated label.
	 */
	private static function page_labels(): array {
		return [
			// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- 'woocommerce' domain is intentional; labels mirror WC's own display_post_states strings so translated pages show the same label as their source-language originals.
			'woocommerce_shop_page_id'      => __( 'Shop Page',       'woocommerce' ),
			'woocommerce_cart_page_id'      => __( 'Cart Page',       'woocommerce' ),
			'woocommerce_checkout_page_id'  => __( 'Checkout Page',   'woocommerce' ),
			'woocommerce_myaccount_page_id' => __( 'My Account Page', 'woocommerce' ),
			'woocommerce_terms_page_id'     => __( 'Terms Page',      'woocommerce' ),
			// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
		];
	}

	/**
	 * Returns (and memoises) the `_lf_trid` for each WC built-in source page.
	 *
	 * Reading the five source trids once per request eliminates redundant
	 * postmeta lookups when `display_post_states` fires for every row in the
	 * admin pages list (32 rows in a typical install) and when the
	 * `woocommerce_get_shop_page_id` filter is called on frontend requests.
	 *
	 * @return array<string,string>  option_key → trid (only entries with a non-empty trid).
	 */
	private static function get_source_trids(): array {
		if ( null !== self::$source_trids ) {
			return self::$source_trids;
		}

		self::$source_trids = [];
		foreach ( self::WC_PAGE_OPTIONS as $option ) {
			$source_id = (int) get_option( $option );
			if ( $source_id <= 0 ) {
				continue;
			}
			$trid = (string) get_post_meta( $source_id, '_lf_trid', true );
			if ( '' !== $trid ) {
				self::$source_trids[ $option ] = $trid;
			}
		}

		return self::$source_trids;
	}

	// =========================================================================
	// Related products language filter
	// =========================================================================

	/**
	 * Translate WooCommerce's related-products list to match the language of the
	 * product currently being viewed.
	 *
	 * Hooks `woocommerce_related_products` (not `woocommerce_related_products_args`)
	 * because the latter fires inside `get_posts()` where `get_queried_object_id()`
	 * returns 0.  This hook receives `$product_id` directly and operates on the
	 * already-fetched ID array.
	 *
	 * WC's `wc_get_related_products()` finds products by shared taxonomy terms.
	 * Those terms are language-neutral in LF (same term_id across languages, names
	 * translated by TermNameFilter), so the result set contains source-language
	 * products.  For source-language products we return the list as-is.  For
	 * translated products we map each source-language relative to its translation
	 * in the current language via `_lf_trid` — one batch `get_posts()` for the
	 * full set — so the "related products" section shows same-language products.
	 *
	 * @param int[]  $related_ids  WC-selected related product IDs.
	 * @param int    $product_id   The product being viewed.
	 * @return int[]
	 */
	public static function filter_related_products_by_lang( array $related_ids, int $product_id ): array {
		if ( empty( $related_ids ) || ! $product_id ) {
			return $related_ids;
		}

		$lang = (string) get_post_meta( $product_id, '_lf_lang', true );
		if ( ! $lang ) {
			// Source-language product — WC result is already correct.
			return $related_ids;
		}

		$source_lang = \LinguaForge\Router\Router::get_instance()->context->source_language();
		if ( $lang === $source_lang ) {
			return $related_ids;
		}

		// Split the WC result into already-translated and source-language buckets.
		$result        = [];
		$trids_to_find = [];

		foreach ( $related_ids as $id ) {
			$rel_lang = (string) get_post_meta( $id, '_lf_lang', true );

			if ( $rel_lang === $lang ) {
				// Already in the right language — keep as-is.
				$result[] = $id;
				continue;
			}

			if ( $rel_lang !== '' && $rel_lang !== $source_lang ) {
				// Different non-source language — irrelevant, skip.
				continue;
			}

			// Source-language product: collect its _lf_trid for the batch lookup.
			$trid = (string) get_post_meta( $id, '_lf_trid', true );
			if ( $trid ) {
				$trids_to_find[] = $trid;
			}
		}

		// Batch-fetch all $lang translations for the collected trids.
		if ( ! empty( $trids_to_find ) ) {
			$translated = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					'relation' => 'AND',
					[ 'key' => '_lf_trid', 'value' => $trids_to_find, 'compare' => 'IN' ],
					[ 'key' => '_lf_lang', 'value' => $lang ],
				],
			] );

			foreach ( $translated as $trans_id ) {
				$result[] = (int) $trans_id;
			}
		}

		// Safety guard: a source-language peer of the current product may appear in
		// $related_ids (WC excluded the translated product but found the source peer
		// via shared taxonomy terms).  The trid mapping resolves that peer back to the
		// current product, which must not appear as related to itself.
		return array_values( array_diff( array_unique( $result ), [ $product_id ] ) );
	}
}
