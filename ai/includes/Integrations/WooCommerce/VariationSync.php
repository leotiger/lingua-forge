<?php
/**
 * WooCommerce variation sync — creates translated product_variation children,
 * syncs WC structural taxonomies (product_type, pa_*, product_brand) from
 * source to translated products, and propagates type changes on source save.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.1.6
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use LinguaForge\Router\Router;

class VariationSync {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 30 — fires after Sync::handle_save_post (10) has committed
		// _lf_lang and _lf_trid. Only relevant for variable products; all other
		// post types exit early in maybe_sync_on_save().
		add_action( 'wp_after_insert_post', [ self::class, 'maybe_sync_on_save' ], 30, 2 );
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Entry point for the wp_after_insert_post path (editor saves).
	 *
	 * Two paths:
	 *
	 * SOURCE product saved → propagate WC structural taxonomies (product_type,
	 * pa_* attribute terms, product_brand) to every translated product in the
	 * TRID group so that all translations remain structurally in sync with the
	 * master. This handles "simple → variable" type changes automatically.
	 *
	 * TRANSLATED product saved → ensure translated variation children exist and
	 * inherit WC structural taxonomies from the source.
	 *
	 * @param int      $post_id  Post being saved.
	 * @param \WP_Post $post     Post object.
	 */
	public static function maybe_sync_on_save( int $post_id, \WP_Post $post ): void {

		if ( 'product' !== $post->post_type ) {
			return;
		}

		$lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return;
		}

		$source_lang = Router::get_instance()->source_language();

		if ( $lang === $source_lang ) {
			// Source product saved: propagate structural taxonomies to all translations.
			$trid = (string) get_post_meta( $post_id, '_lf_trid', true );
			if ( '' !== $trid ) {
				self::propagate_wc_taxonomies_to_translations( $post_id );
			}
			return;
		}

		// Translated product: ensure variation children and WC structural taxonomies.
		$trid = (string) get_post_meta( $post_id, '_lf_trid', true );
		if ( '' === $trid ) {
			return;
		}

		self::sync_variations_for( $post_id );
	}

	// =========================================================================
	// Core sync logic
	// =========================================================================

	/**
	 * Ensure the translated product has translated variation children for every
	 * source variation.
	 *
	 * For each source variation the method:
	 *   1. Assigns `_lf_lang` (= source language) and a TRID to the source
	 *      variation if not already present.
	 *   2. Checks whether a translated variation already exists in the TRID group.
	 *   3. Creates a translated variation if not, copying `_variation_description`
	 *      (WC's actual description meta key — NOT post_content, which is always
	 *      empty on variation posts), post_title, post_status, and attribute
	 *      assignments (`attribute_pa_*` postmeta — note: no leading underscore).
	 *
	 * Operational meta (price, SKU, stock, dimensions, …) is NOT copied here —
	 * MetaDelegate delegates those from the source variation at runtime.
	 *
	 * @param int $translated_product_id  ID of the translated product post.
	 */
	public static function sync_variations_for( int $translated_product_id ): void {

		global $wpdb;

		$translated_product = get_post( $translated_product_id );
		if ( ! $translated_product || 'product' !== $translated_product->post_type ) {
			return;
		}

		$trans_lang = (string) get_post_meta( $translated_product_id, '_lf_lang', true );
		if ( '' === $trans_lang ) {
			return;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $trans_lang === $source_lang ) {
			return;
		}

		// ── Resolve source product ─────────────────────────────────────────────
		$source_product_id = MetaDelegate::get_source_id_for( $translated_product_id );
		if ( ! $source_product_id || $source_product_id === $translated_product_id ) {
			return;
		}

		// ── Fetch source variations ────────────────────────────────────────────
		// Direct SQL avoids a WP_Query → pre_get_posts → VariationDelegate loop.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time sync lookup; no WP cache group for this query pattern.
		$source_var_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product_variation'
			   AND post_parent = %d
			   AND post_status != 'trash'",
			$source_product_id
		) );

		if ( empty( $source_var_ids ) ) {
			return; // Simple product — no variations to sync.
		}

		$trid_group = Router::get_instance()->trid_group;

		foreach ( $source_var_ids as $source_var_id ) {
			$source_var_id = (int) $source_var_id;

			// ── 1. Ensure source variation is TRID-labelled ────────────────────
			// Source variations are public=>false, so Sync::handle_save_post()
			// never auto-assigns _lf_lang or _lf_trid to them. We assign these
			// explicitly so the TRID group query can locate both members of the pair.
			$source_var_lang = (string) get_post_meta( $source_var_id, '_lf_lang', true );
			if ( '' === $source_var_lang ) {
				update_post_meta( $source_var_id, '_lf_lang', $source_lang );
			}

			$source_var_trid = (string) get_post_meta( $source_var_id, '_lf_trid', true );
			if ( '' === $source_var_trid ) {
				$source_var_trid = wp_generate_uuid4();
				update_post_meta( $source_var_id, '_lf_trid', $source_var_trid );
			}

			// ── 2. Check for existing translated variation ─────────────────────
			$existing = $trid_group->get_translations( $source_var_id );
			if ( ! empty( $existing[ $trans_lang ] ) ) {
				// Already translated — sync attribute_pa_* in case attributes were
				// added or changed on the source variation since initial creation.
				// (Creation copies attributes once; without this update pass, new
				// attributes added to the source variation are never propagated.)
				$existing_var_id = (int) $existing[ $trans_lang ];

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- attribute keys fetched once per variation on update; result used immediately.
				$attr_metas = $wpdb->get_results( $wpdb->prepare(
					"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
					 WHERE post_id = %d AND meta_key LIKE %s",
					$source_var_id,
					$wpdb->esc_like( 'attribute_' ) . '%'
				) );

				foreach ( $attr_metas as $attr ) {
					update_post_meta( $existing_var_id, $attr->meta_key, $attr->meta_value );
				}

				continue;
			}

			// ── 3. Create translated variation ─────────────────────────────────
			$source_var = get_post( $source_var_id );
			if ( ! $source_var ) {
				continue;
			}

			// post_content is intentionally empty — WC stores variation descriptions
			// in _variation_description postmeta, not in post_content.
			// post_title is auto-generated by WC from parent title + attribute slugs
			// (generate_product_title in WC_Product_Variation_Data_Store_CPT).
			// Copying the source's post_title here seeds WC's title-sync logic;
			// WC will regenerate it with the translated parent's name on next save.
			$new_var_id = wp_insert_post( [
				'post_type'   => 'product_variation',
				'post_parent' => $translated_product_id,
				'post_content' => '',
				'post_title'   => $source_var->post_title,
				'post_status'  => $source_var->post_status ?: 'publish',
			] );

			if ( ! $new_var_id ) {
				continue;
			}

			// ── 4. Set language + TRID ─────────────────────────────────────────
			update_post_meta( $new_var_id, '_lf_lang', $trans_lang );
			update_post_meta( $new_var_id, '_lf_trid', $source_var_trid );

			$trid_group->clear_translation_cache( $new_var_id );

			// ── 5. Copy variation description ──────────────────────────────────
			// WC stores the per-variation description in _variation_description
			// postmeta (read by WC_Product_Variation_Data_Store_CPT::read_product_data
			// via the $_meta_key_to_props map). post_content on a variation post is
			// always empty in WC's own data model. This is the field translatable by
			// editors via the standard Retranslate button.
			$variation_description = get_post_meta( $source_var_id, '_variation_description', true );
			if ( '' !== $variation_description ) {
				update_post_meta( $new_var_id, '_variation_description', $variation_description );
			}

			// ── 6. Copy attribute assignments ──────────────────────────────────
			// WC stores variation attribute selections as `attribute_pa_color = 'red'`
			// (prefix `attribute_`, no leading underscore). This is what:
			//   • find_matching_product_variation() queries to match user selection
			//   • wc_get_product_variation_attributes() reads to build the attributes array
			//   • read_variation_attributes() in the data store queries via postmeta
			//
			// These keys are NOT in MetaDelegate::OPERATIONAL_KEYS and therefore not
			// delegated at runtime — they must be copied at creation time.
			// TermNameFilter translates the display name (e.g. 'red' → 'Rot') separately.
			//
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- attribute keys fetched once per variation on creation; result used immediately and not re-queried.
			$attr_metas = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id = %d AND meta_key LIKE %s",
				$source_var_id,
				$wpdb->esc_like( 'attribute_' ) . '%'
			) );

			foreach ( $attr_metas as $attr ) {
				update_post_meta( $new_var_id, $attr->meta_key, $attr->meta_value );
			}

			// ── 7. Clear WC children transient for translated product ──────────
			// WC caches variation child IDs in a 30-day transient keyed by parent
			// product ID (wc_product_children_{id}). Without clearing it here, the
			// translated product won't see the new variation child until the transient
			// expires or is manually invalidated.
			delete_transient( 'wc_product_children_' . $translated_product_id );
		}

		// ── 8. Sync WC structural taxonomies from source to translated product ─
		// product_type, pa_* attribute terms, and product_brand determine what WC
		// renders on the translated product page. They must be physically present
		// in the DB — runtime delegation via TaxonomyDelegate cannot overcome WC's
		// own caching layers that run before our filter fires.
		self::sync_wc_taxonomies_from_source( $source_product_id, $translated_product_id );
	}

	// =========================================================================
	// WC structural taxonomy sync
	// =========================================================================

	/**
	 * Copy WC structural taxonomy term assignments from source to translated product.
	 *
	 * Covers the three taxonomy groups that determine WC product behaviour:
	 *   - product_type  — 'simple', 'variable', 'grouped', etc. Without this, WC
	 *                     renders a translated variable product as simple.
	 *   - pa_*          — Attribute taxonomies. WC reads attribute term assignments
	 *                     to build variation dropdowns and the attributes table.
	 *   - product_brand — Native WC 10.x brand taxonomy; not content-translated.
	 *
	 * These are structural (not content) and must be identical on all language
	 * versions. Translated product_cat / product_tag are handled by TaxonomyDelegate
	 * at runtime and are intentionally NOT copied here.
	 *
	 * @param int $source_id      Source (EN) product post ID.
	 * @param int $translated_id  Translated product post ID.
	 */
	public static function sync_wc_taxonomies_from_source( int $source_id, int $translated_id ): void {

		if ( ! $source_id || ! $translated_id || $source_id === $translated_id ) {
			return;
		}

		// product_type — must match source exactly.
		$type_terms = wp_get_object_terms( $source_id, 'product_type', [ 'fields' => 'slugs' ] );
		if ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) ) {
			wp_set_object_terms( $translated_id, $type_terms, 'product_type' );
		}

		// product_brand — native WC 10.x taxonomy; brand identity is language-neutral.
		if ( taxonomy_exists( 'product_brand' ) ) {
			$brand_terms = wp_get_object_terms( $source_id, 'product_brand', [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $brand_terms ) ) {
				wp_set_object_terms( $translated_id, $brand_terms, 'product_brand' );
			}
		}

		// pa_* attribute taxonomies — term slugs are language-neutral (TermNameFilter
		// handles display-time translation of term labels separately).
		$all_taxonomies = get_object_taxonomies( 'product' );
		foreach ( $all_taxonomies as $taxonomy ) {
			if ( ! str_starts_with( $taxonomy, 'pa_' ) ) {
				continue;
			}
			$term_ids = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $term_ids ) ) {
				wp_set_object_terms( $translated_id, $term_ids, $taxonomy );
			}
		}

		// Flush WC product type cache so next wc_get_product() sees the new type.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $translated_id );
		}
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $translated_id );
	}

	/**
	 * Propagate WC structural taxonomies from a source product to all its
	 * translated counterparts.
	 *
	 * Called when the SOURCE product is saved so that product_type changes
	 * (e.g. simple → variable) are immediately reflected on all translations.
	 *
	 * @param int $source_id  Source product post ID.
	 */
	public static function propagate_wc_taxonomies_to_translations( int $source_id ): void {

		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $source_id )
			: [];

		$source_lang = Router::get_instance()->source_language();

		foreach ( $translations as $lang => $translated_id ) {
			if ( $lang === $source_lang || (int) $translated_id === $source_id ) {
				continue;
			}
			self::sync_wc_taxonomies_from_source( $source_id, (int) $translated_id );
		}
	}
}
