<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\VariationDelegate
 *
 * Delegates WooCommerce product variation queries to the source product.
 *
 * Variable products have child posts of type `product_variation` attached via
 * post_parent. In Lingua Forge's shared-stock model, translated variable
 * products are NOT given their own variation children — the source product's
 * variations are shared across all language versions.
 *
 * WooCommerce retrieves variations by querying:
 *   SELECT … FROM wp_posts
 *   WHERE post_type = 'product_variation' AND post_parent = {product_id}
 *
 * When {product_id} is a translated product, this query returns zero results
 * because no variations are attached to the translated post. This class hooks
 * `pre_get_posts` to intercept `product_variation` queries whose post_parent
 * is a translated product and substitutes the source product ID instead, so
 * WooCommerce finds the shared variations and displays the variable product
 * correctly in all languages.
 *
 * No variation posts need to be created or TRID-linked.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;
use WP_Query;

defined( 'ABSPATH' ) || exit;

class VariationDelegate {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 5 — run before WooCommerce's own pre_get_posts hooks (priority 10).
		add_action( 'pre_get_posts', [ self::class, 'maybe_delegate_variation_query' ], 5 );
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * When WooCommerce queries product_variation children of a translated product,
	 * redirect the query to use the source product ID as post_parent.
	 *
	 * @param WP_Query $query  The query being built.
	 */
	public static function maybe_delegate_variation_query( WP_Query $query ): void {

		// ── 1. Only interested in product_variation queries ────────────────────
		$post_type = $query->get( 'post_type' );

		$is_variation_query = ( 'product_variation' === $post_type )
			|| ( is_array( $post_type ) && in_array( 'product_variation', $post_type, true ) );

		if ( ! $is_variation_query ) {
			return;
		}

		// ── 2. Must have a post_parent set ────────────────────────────────────
		$post_parent = (int) $query->get( 'post_parent' );
		if ( $post_parent <= 0 ) {
			return;
		}

		// ── 3. Confirm the parent is a WooCommerce product ────────────────────
		$parent_post = get_post( $post_parent );
		if ( ! $parent_post ) {
			return;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product' ] );
		if ( ! in_array( $parent_post->post_type, $delegate_types, true ) ) {
			return;
		}

		// ── 4. Is the parent a translated (non-source) product? ───────────────
		$lang = (string) get_post_meta( $post_parent, '_lf_lang', true );
		if ( '' === $lang ) {
			return;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return; // Parent is already the source — its own variations will be found.
		}

		// ── 5. Resolve source product ID and repoint the query ────────────────
		$source_id = MetaDelegate::get_source_id_for( $post_parent );
		if ( ! $source_id || $source_id === $post_parent ) {
			return;
		}

		$query->set( 'post_parent', $source_id );
	}
}
