<?php
/**
 * Class LinguaForge\AI\Features\TranslationTrigger
 *
 * Programmatic server-side translation pipeline for use by third-party plugins,
 * WP-CLI scripts, and bulk-import workflows.
 *
 * Public entry point: TranslationTrigger::run( $source_post_id, $target_lang ).
 * Exposes the same flow as the WP-CLI `wp linguaforge translate` command:
 *   1. Call the AI translation feature (Translation::run).
 *   2. Create a new post (or update the existing one) linked into the TRID group.
 *   3. Fire the `linguaforge_translation_complete` action.
 *
 * @see linguaforge_trigger_translation() — public wrapper function in ai/ai.php.
 */

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Registry;

if ( ! defined( 'ABSPATH' ) ) exit;

class TranslationTrigger {

	/**
	 * Translate a post and persist the result as a linked translated post.
	 *
	 * @param int    $source_post_id  ID of the source-language post to translate.
	 * @param string $target_lang     Two-letter target language code (e.g. 'es').
	 * @param array  $params          Optional parameters forwarded to Translation::run():
	 *                                  - 'force_refresh' (bool)  — bypass translation cache.
	 *                                  - 'force_draft'   (bool)  — always create as draft.
	 *                                  - 'with_meta_description' (bool) — chain meta description.
	 *
	 * @return int|\WP_Error  New (or updated) translated post ID on success, WP_Error on failure.
	 */
	public static function run( int $source_post_id, string $target_lang, array $params = [] ): int|\WP_Error {

		$source = get_post( $source_post_id );
		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error(
				'linguaforge_trigger_source_not_found',
				sprintf( 'Source post %d not found.', $source_post_id )
			);
		}

		if ( ! linguaforge_is_valid_lang( $target_lang ) ) {
			return new \WP_Error(
				'linguaforge_trigger_invalid_lang',
				sprintf( 'Language "%s" is not active in Lingua Forge.', $target_lang )
			);
		}

		// ── Run AI translation ────────────────────────────────────────────────
		/** @var Translation|null $feature */
		$feature = Registry::get( 'translation' );

		if ( ! $feature ) {
			return new \WP_Error(
				'linguaforge_trigger_feature_unavailable',
				'The translation feature is not registered. Ensure the AI module is active.'
			);
		}

		$result = $feature->run( $source_post_id, array_merge( $params, [ 'target_language' => $target_lang ] ) );

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'linguaforge_trigger_translation_failed',
				$result['error'] ?? 'Translation failed.'
			);
		}

		// ── Resolve or create the target post ────────────────────────────────
		$existing_id = self::find_existing_translation( $source_post_id, $target_lang );

		if ( $existing_id ) {
			return self::update_translated_post( $existing_id, $source_post_id, $target_lang, $result );
		}

		return self::create_translated_post( $source, $target_lang, $result, $params );
	}

	// =========================================================
	// PRIVATE HELPERS
	// =========================================================

	/**
	 * Return the post ID of an existing translation, or 0 if none exists.
	 */
	private static function find_existing_translation( int $source_post_id, string $target_lang ): int {
		$translations = linguaforge_get_translations( $source_post_id );
		return (int) ( $translations[ $target_lang ] ?? 0 );
	}

	/**
	 * Update an existing translated post with fresh content.
	 */
	private static function update_translated_post(
		int    $existing_id,
		int    $source_post_id,
		string $target_lang,
		array  $result
	): int|\WP_Error {

		$post_data = [
			'ID'            => $existing_id,
			// Reset page_template to 'default' to prevent an invalid_page_template
			// WP_Error on WP 6.7+ when updating a post type that supports 'page-attributes'
			// (e.g. WooCommerce 'product') that already has an FSE slug such as
			// 'single-product-es' stored in _wp_page_template.  WP 6.7+ includes that meta
			// value in WP_Post::to_array(), and FSE slugs are not in get_page_templates().
			// handle_save_post → assign_template_if_needed() re-assigns the correct template
			// on wp_after_insert_post once the save has completed successfully.
			'page_template' => 'default',
		];

		if ( ! empty( $result['output'] ) ) {
			$post_data['post_content'] = (string) $result['output'];
		}

		if ( ! empty( $result['translated_title'] ) ) {
			$post_data['post_title'] = (string) $result['translated_title'];
		}

		if ( isset( $result['translated_excerpt'] ) ) {
			$post_data['post_excerpt'] = (string) $result['translated_excerpt'];
		}

		$updated = wp_update_post( $post_data, true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( ! empty( $result['footnotes'] ) ) {
			update_post_meta( $existing_id, 'footnotes', (string) $result['footnotes'] );
		}

		linguaforge_clear_translation_cache( $existing_id );

		/** @see AbstractTranslateCommand::create_trid_linked_post() for the action docblock */
		do_action( 'linguaforge_translation_complete', $existing_id, $source_post_id, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return $existing_id;
	}

	/**
	 * Insert a new translated post and link it into the TRID group.
	 */
	private static function create_translated_post(
		\WP_Post $source,
		string   $target_lang,
		array    $result,
		array    $params
	): int|\WP_Error {

		// ── TRID — get or create ──────────────────────────────────────────────
		$trid = linguaforge_get_trid( $source->ID );
		if ( $trid === '' ) {
			$trid = wp_generate_uuid4();
			linguaforge_set_trid( $source->ID, $trid );
		}

		// ── Title ─────────────────────────────────────────────────────────────
		$title = ! empty( $result['translated_title'] )
			? (string) $result['translated_title']
			: $source->post_title . ' [' . strtoupper( $target_lang ) . ']';

		// ── Status ────────────────────────────────────────────────────────────
		$force_draft     = ! empty( $params['force_draft'] );
		$allowed         = [ 'publish', 'private', 'draft' ];
		$target_status   = $force_draft
			? 'draft'
			: ( in_array( $source->post_status, $allowed, true ) ? $source->post_status : 'draft' );

		// ── Integration-supplied meta — born with the post ────────────────────
		/**
		 * Filter the post meta a programmatically-created translated post is born
		 * with. Fires before the post is inserted; the returned pairs are written
		 * via wp_insert_post()'s meta_input, so the translated post is complete the
		 * moment it exists — there is no window where a reader (object-cache warm-up,
		 * a queued broadcast, a sitemap ping) sees it without its featured image,
		 * gallery, or other custom meta. Prefer this over patching meta after the
		 * fact on `linguaforge_translation_complete`.
		 *
		 * LF's own translation-group keys (`_lf_trid`, `_lf_lang`) are written
		 * authoritatively after insert and cannot be overridden through this filter.
		 *
		 * WooCommerce note: writing an operational product key (`_thumbnail_id`,
		 * `_product_image_gallery`, `_price`, `_sku`, …) onto a translated *product*
		 * has no observable effect — MetaDelegate serves those keys from the source
		 * product at read time, so such a write is silently shadowed. (Translated
		 * custom-attribute values are the documented exception; see MetaDelegate.)
		 * Use `$source_post_type` to scope the filter to the types you own.
		 *
		 * @param array<string,mixed> $meta             Meta key→value pairs to write. Default empty.
		 * @param int                 $source_id        Source post ID.
		 * @param string              $target_lang      Target language code.
		 * @param string              $source_post_type Source post type (for scoping).
		 */
		$meta = (array) apply_filters(
			'linguaforge_translated_post_meta', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			[],
			$source->ID,
			$target_lang,
			$source->post_type
		);

		// LF-authoritative group keys are written below, never via the filter.
		unset( $meta['_lf_trid'], $meta['_lf_lang'] );

		// ── Insert — bypass LF save hooks (same pattern as AbstractTranslateCommand) ──
		$router = \LinguaForge\Router\Router::get_instance();
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$insert = [
			'post_title'   => $title,
			'post_content' => (string) ( $result['output'] ?? '' ),
			'post_status'  => $target_status,
			'post_type'    => $source->post_type,
			'post_author'  => (int) $source->post_author,
		];

		// Carry the translated excerpt at birth (symmetry with update_translated_post).
		// The AI already returned it in this payload; without this the first-time
		// translation has no excerpt, so SEO og:description falls back from the
		// excerpt to a trimmed slice of post_content.
		if ( isset( $result['translated_excerpt'] ) ) {
			$insert['post_excerpt'] = (string) $result['translated_excerpt'];
		}

		if ( $meta !== [] ) {
			$insert['meta_input'] = $meta;
		}

		$new_id = wp_insert_post( $insert, true );

		add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// ── Link into TRID group ──────────────────────────────────────────────
		update_post_meta( $new_id, '_lf_trid', $trid );
		update_post_meta( $new_id, '_lf_lang', $target_lang );

		if ( ! empty( $result['footnotes'] ) ) {
			update_post_meta( $new_id, 'footnotes', (string) $result['footnotes'] );
		}

		// ── Invalidate cache now that group membership is fully written ───────
		$router->trid_group->clear_translation_cache( $new_id );

		/** @see AbstractTranslateCommand::create_trid_linked_post() for the action docblock */
		do_action( 'linguaforge_translation_complete', $new_id, $source->ID, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return (int) $new_id;
	}
}
