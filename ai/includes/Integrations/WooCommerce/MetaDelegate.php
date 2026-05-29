<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\MetaDelegate
 *
 * Implements the shared-stock delegation model for WooCommerce products.
 *
 * WooCommerce product data divides into two categories:
 *
 *   Content   — post_title, post_content, post_excerpt, meta description,
 *               custom attribute values (_product_attributes is_taxonomy=0).
 *               Lives on each translated product post.
 *
 *   Operational — price, SKU, stock, dimensions, images, taxonomy-based
 *               attributes, upsells, downloads, and all other WC meta.
 *               Lives on the source-language product only and is read
 *               transparently by all language versions at runtime.
 *
 * This class hooks into WordPress's `get_post_metadata` filter so that any
 * `get_post_meta()` call for an operational key on a translated product post
 * transparently returns the source product's value instead.
 *
 * The only exception is `_product_attributes`: if the translated product has
 * its own `_product_attributes` stored (written by the AI translation pipeline
 * with translated custom-attribute values), that value takes precedence.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class MetaDelegate {

	// =========================================================================
	// Operational meta keys delegated from source product
	// =========================================================================

	/**
	 * WooCommerce meta keys that are language-neutral and should always be
	 * read from the source product, not from the translated product post.
	 *
	 * `_product_attributes` is listed here but has special handling: when the
	 * translated product has its own value (AI-translated custom attributes),
	 * that stored value takes precedence and delegation is skipped.
	 *
	 * @var string[]
	 */
	private const OPERATIONAL_KEYS = [
		// Pricing
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		// Identity
		'_sku',
		// Stock
		'_stock',
		'_stock_qty',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_sold_individually',
		// Dimensions / shipping
		'_weight',
		'_length',
		'_width',
		'_height',
		// Media
		'_thumbnail_id',
		'_product_image_gallery',
		// Attributes (see _product_attributes exception in maybe_delegate())
		'_product_attributes',
		// Cross-sells / up-sells
		'_upsell_ids',
		'_crosssell_ids',
		// Downloads
		'_downloadable',
		'_virtual',
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		// Variation defaults
		'_default_attributes',
		// WooCommerce internal
		'_product_version',
		'_wc_average_rating',
		'_wc_review_count',
		'_wc_rating_count',
	];

	/**
	 * Per-request cache: translated product ID → source product ID.
	 * Avoids repeated `linguaforge_get_translations()` lookups for the same post.
	 *
	 * @var array<int,int>
	 */
	private static array $source_cache = [];

	/**
	 * Reentrancy guard: prevents infinite recursion when we call `get_post_meta()`
	 * for the source product from inside the `get_post_metadata` filter.
	 *
	 * Keys are "{$object_id}:{$meta_key}".
	 *
	 * @var array<string,true>
	 */
	private static array $delegating = [];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 1 — run before most other plugins to ensure operational reads
		// always hit the source product regardless of plugin load order.
		add_filter( 'get_post_metadata', [ self::class, 'maybe_delegate' ], 1, 4 );
	}

	// =========================================================================
	// Filter callback
	// =========================================================================

	/**
	 * Intercept get_post_meta() calls for WooCommerce operational keys on
	 * translated product posts and transparently return the source product's value.
	 *
	 * WordPress calls `apply_filters("get_{$type}_metadata", null, $object_id, $meta_key, $single)`.
	 * Returning null means "not filtered — proceed normally". Returning a non-null value
	 * short-circuits the DB lookup:
	 *   • When $single = true, return [ $value ] — WP unwraps to $value.
	 *   • When $single = false, return the raw metadata array from $wpdb.
	 *
	 * @param  mixed  $value     Current filter value (null = not yet filtered).
	 * @param  int    $object_id Post ID being queried.
	 * @param  string $meta_key  Meta key being read.
	 * @param  bool   $single    Whether a single value was requested.
	 * @return mixed  null to pass through, or the delegated value from the source product.
	 */
	public static function maybe_delegate( mixed $value, int $object_id, string $meta_key, bool $single ): mixed {

		// ── 1. Quick key guard ─────────────────────────────────────────────────
		if ( ! in_array( $meta_key, self::OPERATIONAL_KEYS, true ) ) {
			return $value;
		}

		// ── 2. Reentrancy guard ────────────────────────────────────────────────
		$guard = $object_id . ':' . $meta_key;
		if ( isset( self::$delegating[ $guard ] ) ) {
			return $value;
		}

		// ── 3. Post type guard ─────────────────────────────────────────────────
		// Use get_post() which is served from the WP object cache on warm requests.
		$post = get_post( $object_id );
		if ( ! $post ) {
			return $value;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return $value;
		}

		// ── 4. Language guard — only delegate for translated (non-source) posts ─
		// _lf_lang is NOT in OPERATIONAL_KEYS, so this get_post_meta() call will
		// not re-enter this filter.
		$lang = (string) get_post_meta( $object_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $value; // Post has no language assigned — not managed by LF.
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $value; // This IS the source product — serve its own meta normally.
		}

		// ── 5. Resolve source product ID ──────────────────────────────────────
		$source_id = self::get_source_id_for( $object_id );
		if ( ! $source_id || $source_id === $object_id ) {
			return $value; // No source found — fail safe (serve translated post's own meta).
		}

		// ── 6. _product_attributes exception ──────────────────────────────────
		// Custom attributes (is_taxonomy=0 entries) are content: the AI translation
		// pipeline writes translated values as _product_attributes directly on the
		// translated post. When that meta exists, respect it over the delegation.
		//
		// metadata_exists() fires apply_filters("get_post_metadata", …) internally,
		// which would re-enter this callback and recurse infinitely because the guard
		// is not yet set at this point. Set the guard first so the re-entrant call
		// returns null and metadata_exists() falls through to its DB/cache check.
		if ( '_product_attributes' === $meta_key ) {
			self::$delegating[ $guard ] = true;
			$has_own_attrs = metadata_exists( 'post', $object_id, '_product_attributes' );
			unset( self::$delegating[ $guard ] );

			if ( $has_own_attrs ) {
				return $value; // Has own translated value — pass through to normal WP lookup.
			}
		}

		// ── 7. Delegate: return source product's value ─────────────────────────
		self::$delegating[ $guard ] = true;
		$source_value = get_post_meta( $source_id, $meta_key, $single );
		unset( self::$delegating[ $guard ] );

		// Wrap in array as WordPress expects from get_{type}_metadata filter:
		//   • $single=true  → return [ $value ]; WP unwraps to $value on return.
		//   • $single=false → return the raw array (all stored values for this key).
		return $single ? [ $source_value ] : (array) $source_value;
	}

	// =========================================================================
	// Source ID resolution (shared with StockRouter, VariationDelegate, TaxonomyDelegate)
	// =========================================================================

	/**
	 * Return the source-language product ID for a given translated product ID.
	 *
	 * Results are cached per request to avoid repeated TRID group DB lookups.
	 * Returns 0 when no source product can be resolved (no TRID, or TRID group
	 * has no source-language member).
	 *
	 * @param  int $translated_id  Translated product post ID.
	 * @return int  Source product post ID, or 0 on failure.
	 */
	public static function get_source_id_for( int $translated_id ): int {

		if ( isset( self::$source_cache[ $translated_id ] ) ) {
			return self::$source_cache[ $translated_id ];
		}

		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $translated_id )
			: [];

		$source_lang = Router::get_instance()->source_language();
		$source_id   = (int) ( $translations[ $source_lang ] ?? 0 );

		self::$source_cache[ $translated_id ] = $source_id;

		return $source_id;
	}
}
