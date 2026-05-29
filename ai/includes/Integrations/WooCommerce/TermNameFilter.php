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
	// Helpers
	// =========================================================================

	/**
	 * Returns true when $taxonomy is a WooCommerce taxonomy that participates
	 * in term name translation.
	 *
	 * Covers product_cat, product_tag, product_type, and all pa_* attribute
	 * taxonomies (WooCommerce registers one per product attribute).
	 */
	public static function is_wc_taxonomy( string $taxonomy ): bool {

		static $exact = [ 'product_cat', 'product_tag', 'product_type' ];

		if ( in_array( $taxonomy, $exact, true ) ) {
			return true;
		}

		return str_starts_with( $taxonomy, 'pa_' );
	}
}
