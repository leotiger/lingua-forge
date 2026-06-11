<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\TermNameFilter
 *
 * Phase 1b: Translated term name display for WooCommerce taxonomies.
 *
 * In the shared-stock model, WooCommerce taxonomy terms (product categories,
 * product tags, and product attribute values) are shared across all language
 * versions of a product — there are no per-language term copies. Term names
 * display in the source language by default.
 *
 * This class hooks into WordPress's `term_name` filter so that category and
 * attribute names appear in the correct language for each visitor. Translated
 * names are stored as termmeta under the key `_lf_term_name_{lang}` and are
 * entered via the term edit screen (see TermNameAdmin).
 *
 * Covered taxonomies:
 *   - product_cat   (WooCommerce product categories)
 *   - product_tag   (WooCommerce product tags)
 *   - product_type  (WooCommerce product type)
 *   - pa_*          (WooCommerce product attribute value terms)
 *
 * The filter only fires when the current request language differs from the
 * source language and a translated name is stored for the term + language
 * combination. Otherwise the original name passes through unchanged.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;
use WP_Term;

defined( 'ABSPATH' ) || exit;

class TermNameFilter {

	/**
	 * Termmeta key prefix for translated term names.
	 * Full key: _lf_term_name_{lang}  e.g. _lf_term_name_es
	 */
	public const META_PREFIX = '_lf_term_name_';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 10 — standard. WP passes the raw stored name; we swap it when
		// a translated name exists for the detected request language.
		//
		// WordPress fires `term_name` from two distinct call sites with different
		// signatures — we must accept 4 args to handle both:
		//
		//   1. sanitize_term_field()  → (string $name, int $term_id, string $taxonomy, string $context)
		//      Fires everywhere: frontend, REST, admin. The 2nd argument is an int.
		//
		//   2. edit-tags.php          → (string $name, WP_Term $term)
		//      Fires only in the admin term-list table. The 2nd argument is WP_Term.
		//
		// See Trac #45085 (open since 2018) — the discrepancy is a known WP issue.
		add_filter( 'term_name', [ self::class, 'translate_term_name' ], 10, 4 );

		// WooCommerce renders variation attribute option names via:
		//   wc_dropdown_variation_attribute_options() → $term->name
		//   → apply_filters('woocommerce_variation_option_name', $name, $term, $attribute, $product)
		// This bypasses `term_name` (which only fires with 'display' sanitize context).
		// Hook here to translate variation dropdown labels on translated product pages.
		add_filter( 'woocommerce_variation_option_name', [ self::class, 'translate_variation_option_name' ], 10, 4 );

		// Translate WP_Term names directly on the `wp_get_object_terms` result
		// (covers wc_get_product_terms → classic template path).
		add_filter( 'wp_get_object_terms', [ self::class, 'translate_term_objects' ], 15, 4 );

		// WC Store API calls WC_Product_Attribute::get_terms() which maps term IDs
		// through individual get_term($id, $taxonomy) calls — bypassing
		// wp_get_object_terms entirely. The `get_term` filter fires after each
		// individual term is fetched, giving us the correct hook point for the
		// Store API JSON path (block themes, all store visitors).
		add_filter( 'get_term', [ self::class, 'translate_single_term_name' ], 10, 2 );

		// Translate global attribute labels (e.g. "Color" → "Farbe").
		// wc_attribute_label() wraps its return with this filter; $name is the
		// full taxonomy slug (e.g. 'pa_color') for global attributes.
		// Translations are stored by AttributeLabelAdmin under
		// linguaforge_attr_labels_{$taxonomy} in wp_options.
		add_filter( 'woocommerce_attribute_label', [ self::class, 'translate_attribute_label' ], 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook
	}

	// =========================================================================
	// Filter callback
	// =========================================================================

	/**
	 * Replace the term name with its translated equivalent for WooCommerce
	 * taxonomies when the current request is in a non-source language and a
	 * translated name has been stored.
	 *
	 * Handles the two distinct WordPress call signatures for the `term_name`
	 * filter (see Trac #45085):
	 *
	 *   sanitize_term_field() path  → ($name, $term_id_int, $taxonomy, $context)
	 *   edit-tags.php path          → ($name, $wp_term_object)
	 *
	 * Only the 'display' context is translated; 'edit', 'db', and 'raw' are
	 * left unchanged so stored/raw values are never silently rewritten.
	 *
	 * @param  string          $name        The current term name.
	 * @param  WP_Term|int|mixed $term_or_id  WP_Term (admin list) or int term ID (everywhere else).
	 * @param  string          $taxonomy    Taxonomy slug (set on sanitize_term_field path; empty on edit-tags path).
	 * @param  string          $context     Sanitize context: 'display', 'edit', 'db', 'raw', etc.
	 * @return string  Translated name if available, original name otherwise.
	 */
	public static function translate_term_name( string $name, mixed $term_or_id, string $taxonomy = '', string $context = 'display' ): string {

		// ── 1. Resolve term ID and taxonomy from whichever call signature fired ─
		if ( $term_or_id instanceof WP_Term ) {
			// edit-tags.php path: 2nd arg is a WP_Term object. Context is not passed,
			// so it defaults to 'display' above — always a display context anyway.
			$term_id  = $term_or_id->term_id;
			$taxonomy = $term_or_id->taxonomy;
		} elseif ( is_int( $term_or_id ) || ( is_string( $term_or_id ) && ctype_digit( (string) $term_or_id ) ) ) {
			// sanitize_term_field() path: 2nd arg is the integer term ID.
			$term_id = (int) $term_or_id;
			// $taxonomy and $context are the 3rd and 4th args, already set above.
		} else {
			return $name;
		}

		// ── 2. Only translate for 'display' context ────────────────────────────
		// 'edit', 'db', 'raw' contexts must return the stored source value.
		if ( '' !== $context && 'display' !== $context ) {
			return $name;
		}

		// ── 3. Only handle WooCommerce taxonomies ─────────────────────────────
		if ( ! self::is_wc_taxonomy( $taxonomy ) ) {
			return $name;
		}

		// ── 4. Resolve current request language ───────────────────────────────
		$router = Router::get_instance();
		$lang   = $router->detect_lang();

		// ── 5. Skip if already in source language ─────────────────────────────
		if ( '' === $lang || $lang === $router->source_language() ) {
			return $name;
		}

		// ── 6. Look up translated name from termmeta ──────────────────────────
		$translated = (string) get_term_meta( $term_id, self::META_PREFIX . $lang, true );

		return '' !== $translated ? $translated : $name;
	}

	// =========================================================================
	// wp_get_object_terms — term object name translation (Store API + classic)
	// =========================================================================

	/**
	 * Translate WP_Term name properties on the wp_get_object_terms result.
	 *
	 * This fires at priority 15 (after TaxonomyDelegate at 10, which handles
	 * source-product delegation). For every pa_* attribute term returned, look
	 * up `_lf_term_name_{lang}` termmeta and replace the name when a translation
	 * is stored.
	 *
	 * Runs on both frontend code paths:
	 *   - WC Store API (block themes): WC_Product_Attribute::get_terms()
	 *     → wc_get_product_terms() → wp_get_object_terms() → here
	 *     → ProductSchema::prepare_product_attribute_taxonomy_value($term)
	 *     reads the now-translated $term->name → JSON has "Rot"/"Blau".
	 *
	 *   - Classic WC templates: wc_dropdown_variation_attribute_options()
	 *     → wc_get_product_terms() → same path — also translated here.
	 *
	 * WP_Term objects are cloned before modification to avoid mutating
	 * the WP object cache (the default in-memory cache passes objects by
	 * reference; modifying them would corrupt cached names for subsequent reads).
	 *
	 * @param  array|\WP_Term[] $terms     Terms returned by wp_get_object_terms().
	 * @param  int[]|string     $object_ids Object IDs (not used here).
	 * @param  string[]|string  $taxonomies Taxonomy slugs queried.
	 * @param  array            $args       Query args.
	 * @return array  Terms with translated name properties where available.
	 */
	public static function translate_term_objects( mixed $terms, mixed $object_ids, mixed $taxonomies, array $args ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $object_ids, $taxonomies, $args are required by the wp_get_object_terms filter signature; only $terms is used here.

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return $terms;
		}

		// ── 1. Resolve active language ─────────────────────────────────────────
		$router      = Router::get_instance();
		$source_lang = $router->source_language();

		// Use _lf_lang from the queried product post (see translate_single_term_name).
		//
		// Read the already-resolved queried_object property directly rather than
		// calling get_queried_object_id().  The latter calls
		// WP_Query::get_queried_object() which, on taxonomy archive pages, calls
		// get_term_by() -> WP_Term_Query -> populate_terms() -> get_term() ->
		// this filter -> get_queried_object_id() -> infinite recursion.
		// Accessing the property directly is safe: if queried_object is not yet
		// set we simply fall back to URL/cookie detection below.
		$lang       = '';
		$queried_id = 0;
		$queried_obj = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->queried_object : null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- read-only access
		if ( $queried_obj instanceof \WP_Post ) {
			$queried_id = $queried_obj->ID;
		}
		if ( $queried_id ) {
			$lang = (string) get_post_meta( $queried_id, '_lf_lang', true );
		}
		if ( '' === $lang ) {
			$lang = $router->detect_lang();
		}

		if ( '' === $lang || $lang === $source_lang ) {
			return $terms; // Source language — no translation needed.
		}

		// ── 2. Translate term names ────────────────────────────────────────────
		$translated = [];
		foreach ( $terms as $term ) {
			if ( ! ( $term instanceof WP_Term ) ) {
				$translated[] = $term;
				continue;
			}

			// Translate all WC taxonomies that carry user-visible term labels
			// (pa_*, product_cat, product_tag, product_brand, and any added via
			// the linguaforge_wc_delegate_taxonomies filter).
			if ( ! self::is_wc_taxonomy( $term->taxonomy ) ) {
				$translated[] = $term;
				continue;
			}

			$name = (string) get_term_meta( $term->term_id, self::META_PREFIX . $lang, true );
			if ( '' === $name ) {
				$translated[] = $term; // No translation stored — keep original.
				continue;
			}

			// Clone to avoid mutating the WP object cache reference.
			$clone       = clone $term;
			$clone->name = $name;
			$translated[] = $clone;
		}

		return $translated;
	}

	/**
	 * Translate an individual WP_Term name when fetched via get_term().
	 *
	 * WC's Store API calls WC_Product_Attribute::get_terms() which maps stored
	 * term IDs through array_map('get_term', $ids, $taxonomies). The `get_term`
	 * filter fires for every individual term fetch — this is the correct hook
	 * for the Store API/block path where wp_get_object_terms is never called.
	 *
	 * A clone is returned to avoid mutating the WP object-cache reference
	 * (the default in-memory cache passes objects by reference).
	 *
	 * @param  mixed  $term      WP_Term or other value returned by get_term().
	 * @param  string $taxonomy  The taxonomy slug.
	 * @return mixed  Cloned WP_Term with translated name, or original on pass-through.
	 */
	public static function translate_single_term_name( mixed $term, string $taxonomy ): mixed {

		if ( ! ( $term instanceof WP_Term ) ) {
			return $term;
		}

		// Translate all WC taxonomies with user-visible term labels (pa_*, product_cat,
		// product_tag, product_brand, and any added via linguaforge_wc_delegate_taxonomies).
		$tax = $term->taxonomy ?: $taxonomy;
		if ( ! self::is_wc_taxonomy( $tax ) ) {
			return $term;
		}

		$router      = Router::get_instance();
		$source_lang = $router->source_language();

		// Prefer _lf_lang from the queried product post over URL-prefix detection.
		//
		// WC product pages are served at /product/{slug}/ — no language prefix in the
		// URL. detect_lang() reads the URL prefix and returns source lang for all WC
		// product pages, making URL-prefix detection useless here. Reading _lf_lang
		// directly from the current product post gives the correct language regardless
		// of permalink structure.
		//
		// IMPORTANT: do NOT call get_queried_object_id() here. On taxonomy archive
		// pages, WP_Query::get_queried_object() resolves the queried object by
		// calling get_term_by() -> WP_Term_Query -> populate_terms() -> get_term()
		// -> this filter -> get_queried_object_id() -> get_queried_object() -> ...
		// infinite recursion. Reading the already-resolved queried_object property
		// directly is safe: if it is not yet set, $queried_id stays 0 and we fall
		// back to URL/cookie detection (correct for archive pages anyway).
		$lang        = '';
		$queried_id  = 0;
		$queried_obj = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->queried_object : null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- read-only access
		if ( $queried_obj instanceof \WP_Post ) {
			$queried_id = $queried_obj->ID;
		}
		if ( $queried_id ) {
			$lang = (string) get_post_meta( $queried_id, '_lf_lang', true );
		}

		// Fallback to URL/cookie detection (e.g. for REST/Store API requests,
		// archive pages, or any context where there is no queried product post).
		if ( '' === $lang ) {
			$lang = $router->detect_lang();
		}

		if ( '' === $lang || $lang === $source_lang ) {
			return $term;
		}

		$name = (string) get_term_meta( $term->term_id, self::META_PREFIX . $lang, true );
		if ( '' === $name ) {
			return $term;
		}

		$clone       = clone $term;
		$clone->name = $name;

		return $clone;
	}

	// =========================================================================
	// WooCommerce variation option name
	// =========================================================================

	/**
	 * Translate a WooCommerce variation dropdown option name.
	 *
	 * `woocommerce_variation_option_name` fires inside wc_dropdown_variation_attribute_options()
	 * for each term option. `term_name` (with 'display' context) does NOT fire in this path —
	 * WC reads $term->name directly from the WP_Term object and passes it through this filter.
	 *
	 * @param  string        $name       The term name as it will appear in the dropdown.
	 * @param  \WP_Term|null $term       The WP_Term object, or null for custom (non-taxonomy) attributes.
	 * @param  string        $attribute  The attribute taxonomy slug (e.g. 'pa_color').
	 * @param  mixed         $product    The WC_Product object (no PHPStan stubs; typed as mixed).
	 * @return string  Translated name if available, original name otherwise.
	 */
	public static function translate_variation_option_name( string $name, mixed $term, string $attribute, mixed $product ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $product (WC_Product, no stubs) required by woocommerce_variation_option_name filter signature; unused here.

		// Custom (non-taxonomy) attributes pass null for $term — nothing to translate.
		if ( ! ( $term instanceof WP_Term ) ) {
			return $name;
		}

		if ( ! self::is_wc_taxonomy( $attribute ) ) {
			return $name;
		}

		$router      = Router::get_instance();
		$source_lang = $router->source_language();

		// Use _lf_lang from the queried product post — WC product pages at
		// /product/{slug}/ have no URL prefix, so detect_lang() returns source lang.
		// Read queried_object directly (see translate_single_term_name for rationale).
		$lang        = '';
		$queried_id  = 0;
		$queried_obj = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->queried_object : null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- read-only access
		if ( $queried_obj instanceof \WP_Post ) {
			$queried_id = $queried_obj->ID;
		}
		if ( $queried_id ) {
			$lang = (string) get_post_meta( $queried_id, '_lf_lang', true );
		}
		if ( '' === $lang ) {
			$lang = $router->detect_lang();
		}

		if ( '' === $lang || $lang === $source_lang ) {
			return $name;
		}

		$translated = (string) get_term_meta( $term->term_id, self::META_PREFIX . $lang, true );

		return '' !== $translated ? $translated : $name;
	}

	// =========================================================================
	// Attribute label filter
	// =========================================================================

	/**
	 * Return the translated attribute label for the current language.
	 *
	 * Hooked on `woocommerce_attribute_label` (priority 10).  Only acts on
	 * global pa_* attribute taxonomies — custom per-product attributes are left
	 * unchanged.  Falls back to the original $label when no translation is stored.
	 *
	 * Translations are stored by AttributeLabelAdmin in wp_options under the key
	 * `linguaforge_attr_labels_{$name}` (e.g. `linguaforge_attr_labels_pa_color`).
	 *
	 * @param string $label The resolved attribute label (from WooCommerce).
	 * @param string $name  The attribute name passed to wc_attribute_label().
	 * @return string
	 */
	public static function translate_attribute_label( string $label, string $name ): string {

		// Only translate global pa_* attribute taxonomies.
		if ( ! str_starts_with( $name, 'pa_' ) ) {
			return $label;
		}

		$router      = Router::get_instance();
		$source_lang = $router->source_language();

		// Prefer _lf_lang from the queried product post — WC product pages at
		// /product/{slug}/ have no URL prefix, so detect_lang_safe() returns the
		// source language for all WC product pages.  Same pattern as
		// translate_single_term_name() and translate_term_objects().
		$lang       = '';
		$queried_obj = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->queried_object : null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- read-only access
		if ( $queried_obj instanceof \WP_Post ) {
			$lang = (string) get_post_meta( $queried_obj->ID, '_lf_lang', true );
		}
		if ( '' === $lang ) {
			$lang = $router->detect_lang_safe();
		}

		if ( '' === $lang || $lang === $source_lang ) {
			return $label;
		}

		$translations = (array) get_option( AttributeLabelAdmin::OPTION_PREFIX . $name, [] );

		return isset( $translations[ $lang ] ) && '' !== $translations[ $lang ]
			? (string) $translations[ $lang ]
			: $label;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Returns true when $taxonomy is a WooCommerce taxonomy that participates
	 * in term name translation.
	 *
	 * Covers product_cat, product_tag, product_type, product_brand (native WC 10.x),
	 * and all pa_* attribute taxonomies (WooCommerce registers one per product attribute).
	 */
	public static function is_wc_taxonomy( string $taxonomy ): bool {

		static $exact = [ 'product_cat', 'product_tag', 'product_type', 'product_brand' ];

		if ( in_array( $taxonomy, $exact, true ) ) {
			return true;
		}

		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			return true;
		}

		// Also cover any taxonomies registered via the TaxonomyDelegate filter
		// (e.g. pwb-brand from Perfect Brands for WooCommerce).
		$custom = (array) apply_filters( 'linguaforge_wc_delegate_taxonomies', [] );
		return in_array( $taxonomy, $custom, true );
	}
}
