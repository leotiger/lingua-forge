<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\CatalogQuery
 *
 * Adds language-awareness to WooCommerce product queries.
 *
 * The language-router module's QueryFilter already scopes the main shop/archive
 * query by adding a `_lf_lang = LF_LANG` meta constraint on all frontend archive
 * and search queries. This class extends that coverage to WooCommerce-specific
 * secondary product queries (related products, up-sells, cross-sells, recently
 * viewed, product widgets) that do NOT go through the main query.
 *
 * These secondary queries are detected via the `woocommerce_product_query` action,
 * which WooCommerce fires for every `WC_Product_Query` and the corresponding
 * WP_Query, whether or not it is the main query.
 *
 * Before adding the meta constraint, this class checks whether the query already
 * has a `_lf_lang` condition (set by the existing QueryFilter for main queries)
 * to avoid double-application.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use WP_Query;

defined( 'ABSPATH' ) || exit;

class CatalogQuery {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// `woocommerce_product_query` passes the WP_Query for every WC product
		// query (main and secondary).  Fires after WC_Query adds its own
		// tax/meta constraints, so our addition doesn't interfere with those.
		add_action( 'woocommerce_product_query', [ self::class, 'apply_language_filter' ], 10, 1 );
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Add a `_lf_lang` meta constraint to every WooCommerce product query so
	 * that product listings are scoped to the active language.
	 *
	 * Skips:
	 *  • Admin requests (admin product management should show all languages).
	 *  • Queries that already have a `_lf_lang` condition (main query was already
	 *    handled by QueryFilter).
	 *  • Requests where LF_LANG is not defined (router has not determined a
	 *    language, e.g. REST calls without a language prefix).
	 *
	 * @param WP_Query $query  The WC product query being built.
	 */
	public static function apply_language_filter( WP_Query $query ): void {

		if ( is_admin() ) {
			return;
		}

		if ( ! defined( 'LF_LANG' ) || '' === LF_LANG ) {
			return;
		}

		// Check whether a _lf_lang constraint is already present.
		$meta_query = (array) $query->get( 'meta_query', [] );

		foreach ( $meta_query as $clause ) {
			if ( is_array( $clause ) && isset( $clause['key'] ) && '_lf_lang' === $clause['key'] ) {
				return; // Already filtered — nothing to add.
			}
		}

		$meta_query[] = [
			'key'   => '_lf_lang',
			'value' => LF_LANG,
		];

		$query->set( 'meta_query', $meta_query );
	}
}
