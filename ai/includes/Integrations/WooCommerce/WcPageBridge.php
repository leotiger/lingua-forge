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

		// Priority 9 — before WC_Query::pre_get_posts at 10.  Converts the
		// translated shop page's pagename query to a product-type archive so
		// WC's hook calls product_query() and fires woocommerce_product_query,
		// which lets CatalogQuery inject the per-language _lf_lang constraint.
		add_action( 'pre_get_posts', [ self::class, 'inject_shop_post_type' ], 9 );

		// Scope WC's related-products to the same language as the current
		// product so English and Spanish products are never mixed in the
		// "Productos relacionados" / "Related products" section.
		// Uses woocommerce_related_products (not _args) because that hook
		// receives $product_id directly; the _args hook fires inside get_posts()
		// where get_queried_object_id() returns 0.
		add_filter( 'woocommerce_related_products', [ self::class, 'filter_related_products_by_lang' ], 10, 2 );

		// Set lf_shop_page_id query var for the source-language shop page (/shop/).
		// inject_shop_post_type() handles translated shop pages (pagename queries);
		// the source shop uses WC's native rewrite and never passes through that hook.
		// The language switcher reads this var to build correct per-language shop URLs
		// instead of falling back to slug-stripping (which produces /es/shop/ rather
		// than /es/tienda/).
		add_action( 'wp', [ self::class, 'set_source_shop_page_id_query_var' ] );
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
	// Internal helpers
	// =========================================================================

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
