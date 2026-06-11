<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\LocalAttributeTranslator
 *
 * Translates WooCommerce local (non-taxonomy, is_taxonomy=0) product attribute
 * labels and values when a product is translated or retranslated via Lingua Forge.
 *
 * Listens to the `linguaforge_translation_complete` action at priority 20 (after
 * TermNameTranslator at 10) and performs two writes per translation:
 *
 *   A. Writes a translated `_product_attributes` array to the translated product
 *      post.  Local attribute names (e.g. "Temàtica") and their pipe-separated
 *      values are AI-translated in a single batched call; global (pa_*) attribute
 *      entries are copied verbatim because their display names come from taxonomy
 *      terms handled by TermNameTranslator.  MetaDelegate already has an exception
 *      for `_product_attributes`: if the translated product has its own stored
 *      value, it takes precedence over source delegation — no MetaDelegate changes
 *      are required.
 *
 *   B. Updates `attribute_{key}` postmeta on the translated variation children
 *      so that WooCommerce's find_matching_product_variation() can match customer
 *      selections against the translated values.  VariationSync copies these meta
 *      values verbatim from source during wp_after_insert_post (priority 30),
 *      which always precedes linguaforge_translation_complete.  The value map
 *      built in step A is reused here without an additional DB read.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

class LocalAttributeTranslator {

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 20: after TermNameTranslator (10); variation meta update in
		// on_translation_complete() relies on VariationSync having already run
		// (wp_after_insert_post p30 always precedes linguaforge_translation_complete).
		add_action( 'linguaforge_translation_complete', [ self::class, 'on_translation_complete' ], 20, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Translate local attribute names and values, then propagate translated
	 * values to variation children.
	 *
	 * @param int    $translated_id  Post ID of the newly-created/updated translation.
	 * @param int    $source_id      Post ID of the source (canonical) product.
	 * @param string $target_lang    Two-letter language code, e.g. 'es'.
	 */
	public static function on_translation_complete( int $translated_id, int $source_id, string $target_lang ): void {

		// ── 1. Only product post type ─────────────────────────────────────────
		$source = get_post( $source_id );
		if ( ! $source || 'product' !== $source->post_type ) {
			return;
		}

		// ── 2. Read source _product_attributes ────────────────────────────────
		$source_attrs = get_post_meta( $source_id, '_product_attributes', true );
		if ( ! is_array( $source_attrs ) || empty( $source_attrs ) ) {
			return;
		}

		// ── 3. Identify local attributes (is_taxonomy = 0) ────────────────────
		$local_keys = [];
		foreach ( $source_attrs as $key => $attr ) {
			if ( isset( $attr['is_taxonomy'] ) && ! $attr['is_taxonomy'] ) {
				$local_keys[] = (string) $key;
			}
		}

		if ( empty( $local_keys ) ) {
			return;
		}

		// ── 4. Build translation batch ────────────────────────────────────────
		// Collect every unique string to translate: local attribute names and
		// their individual pipe-separated values.
		// Keys are "{attr_key}::name" / "{attr_key}::v::{n}" — opaque to the AI;
		// they exist only to map the AI response back to the right field.
		$batch = [];

		foreach ( $local_keys as $key ) {
			$attr = $source_attrs[ $key ];

			$name = (string) ( $attr['name'] ?? '' );
			if ( '' !== $name ) {
				$batch[ $key . '::name' ] = $name;
			}

			$raw_value = (string) ( $attr['value'] ?? '' );
			if ( '' !== $raw_value ) {
				$values = array_values( array_filter( array_map( 'trim', explode( '|', $raw_value ) ) ) );
				foreach ( $values as $i => $v ) {
					if ( '' !== $v ) {
						$batch[ $key . '::v::' . $i ] = $v;
					}
				}
			}
		}

		if ( empty( $batch ) ) {
			return;
		}

		// ── 5. Translate via AI ───────────────────────────────────────────────
		// TermNameTranslator::translate_term_names() accepts any flat key=>string
		// map, makes a single batched AI call with the light model tier, and
		// returns a source_string => translated_string map.
		$translations = TermNameTranslator::translate_term_names( $batch, $target_lang, $source_id );
		if ( empty( $translations ) ) {
			return;
		}

		// ── 6. Reconstruct translated _product_attributes ─────────────────────
		// Start from source (preserves position, is_taxonomy, is_visible,
		// is_variation flags).  Overwrite local attribute name and value fields
		// with translated content; global (pa_*) entries pass through unchanged.
		$translated_attrs = $source_attrs;

		/**
		 * Value map for step 8: attr_key => [ source_value => translated_value ].
		 * Indexed by attr key and source string so variation meta can be updated
		 * without re-querying the AI.
		 *
		 * @var array<string, array<string, string>>
		 */
		$value_maps = [];

		foreach ( $local_keys as $key ) {
			$attr = $source_attrs[ $key ];

			// -- Translate name ---------------------------------------------------
			$src_name  = (string) ( $attr['name'] ?? '' );
			$trans_name = ( '' !== $src_name && isset( $translations[ $src_name ] ) && '' !== $translations[ $src_name ] )
				? (string) $translations[ $src_name ]
				: $src_name;

			// -- Translate pipe-separated values ---------------------------------
			$raw_value  = (string) ( $attr['value'] ?? '' );
			$src_values = array_values( array_filter( array_map( 'trim', explode( '|', $raw_value ) ) ) );

			$trans_values = [];
			foreach ( $src_values as $sv ) {
				$tv = ( isset( $translations[ $sv ] ) && '' !== $translations[ $sv ] )
					? (string) $translations[ $sv ]
					: $sv;
				$trans_values[]          = $tv;
				$value_maps[ $key ][ $sv ] = $tv;
			}

			$translated_attrs[ $key ]['name']  = $trans_name;
			$translated_attrs[ $key ]['value'] = implode( ' | ', $trans_values );
		}

		// ── 7. Persist translated _product_attributes ─────────────────────────
		update_post_meta( $translated_id, '_product_attributes', $translated_attrs );

		// ── 8. Update variation meta for local attributes used for variations ──
		// Skip if no local attribute is marked is_variation — nothing to update.
		$has_variation_attrs = false;
		foreach ( $local_keys as $key ) {
			if ( ! empty( $translated_attrs[ $key ]['is_variation'] ) ) {
				$has_variation_attrs = true;
				break;
			}
		}

		if ( ! $has_variation_attrs ) {
			return;
		}

		// Fetch translated variation children.  Direct SQL avoids triggering
		// WP_Query → VariationDelegate → recursive pre_get_posts loop.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time sync update; result consumed immediately.
		$trans_var_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type   = 'product_variation'
			   AND post_parent = %d
			   AND post_status != 'trash'",
			$translated_id
		) );

		if ( empty( $trans_var_ids ) ) {
			return;
		}

		// Overwrite source-language local attribute values with translated
		// equivalents.  An empty stored value means "Any" — skip those rows so
		// the wildcard behaviour is preserved.
		foreach ( $trans_var_ids as $var_id ) {
			$var_id = (int) $var_id;

			foreach ( $value_maps as $attr_key => $map ) {
				if ( empty( $translated_attrs[ $attr_key ]['is_variation'] ) ) {
					continue; // Attribute not used for variations — skip.
				}

				$meta_key   = 'attribute_' . $attr_key;
				$stored_val = (string) get_post_meta( $var_id, $meta_key, true );

				if ( '' === $stored_val ) {
					continue; // "Any" option — preserve as-is.
				}

				if ( isset( $map[ $stored_val ] ) ) {
					update_post_meta( $var_id, $meta_key, $map[ $stored_val ] );
				}
			}
		}
	}
}
