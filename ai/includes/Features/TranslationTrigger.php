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
	// SHARED CREATION HELPER
	// =========================================================

	/**
	 * Build the common wp_insert_post() args every translated-post CREATION
	 * path needs: title, content, status, type, author, and — critically —
	 * the translated excerpt when the AI result included one.
	 *
	 * Extracted per AUDIT-2026-07-11 §2: the plugin has three independent
	 * translated-post creation paths (this class, PostListColumn::create_linked_post()
	 * for "Translate missing"/Sync, and AbstractTranslateCommand::create_trid_linked_post()
	 * for WP-CLI), and a fix to one of these common fields has twice now landed
	 * in only one or two of the three (the 2.4.0 excerpt fix originally only
	 * reached this class). Routing all three through this single helper means
	 * the next such fix lands everywhere by construction instead of requiring
	 * a three-way spot-fix.
	 *
	 * Callers layer their own path-specific fields on top of the returned
	 * array (e.g. `meta_input` for a copied featured image or integration-
	 * supplied meta) before calling wp_insert_post().
	 *
	 * @param \WP_Post $source       Source-language post being translated.
	 * @param string   $target_lang  Target language code — used only for the
	 *                               synthetic-title fallback (e.g. "Title [ES]").
	 * @param array    $result       Translation::run() result array.
	 * @param bool     $force_draft  Always create as 'draft', ignoring the
	 *                               source post's own status. Default false.
	 * @return array{post_title:string,post_content:string,post_status:string,post_type:string,post_author:int,post_excerpt?:string}
	 */
	public static function build_create_args(
		\WP_Post $source,
		string   $target_lang,
		array    $result,
		bool     $force_draft = false
	): array {

		$title = ! empty( $result['translated_title'] )
			? (string) $result['translated_title']
			: $source->post_title . ' [' . strtoupper( $target_lang ) . ']';

		$allowed       = [ 'publish', 'private', 'draft' ];
		$target_status = $force_draft
			? 'draft'
			: ( in_array( $source->post_status, $allowed, true ) ? $source->post_status : 'draft' );

		$args = [
			'post_title'   => $title,
			'post_content' => (string) ( $result['output'] ?? '' ),
			'post_status'  => $target_status,
			'post_type'    => $source->post_type,
			'post_author'  => (int) $source->post_author,
		];

		// Carry the translated excerpt at birth. The AI already returns it in
		// $result; without this a first-time translation has no excerpt, so
		// SEO og:description falls back from the excerpt to a trimmed slice
		// of post_content (AUDIT-2026-07-11 §2).
		if ( isset( $result['translated_excerpt'] ) ) {
			$args['post_excerpt'] = (string) $result['translated_excerpt'];
		}

		return $args;
	}

	/**
	 * Sync WooCommerce variation children + structural taxonomies onto a
	 * newly created translated product.
	 *
	 * `VariationSync::maybe_sync_on_save()` normally does this itself via a
	 * `wp_after_insert_post` priority-30 hook, but it bails immediately when
	 * `_lf_lang` is empty — and every one of the three translated-post
	 * creation paths writes `_lf_trid`/`_lf_lang` AFTER `wp_insert_post()`
	 * returns (the new post's own ID has to exist first), so that hook always
	 * sees an empty `_lf_lang` during creation and silently does nothing. A
	 * translated variable product was therefore born with no translated
	 * variation children and no WC structural taxonomies (`product_type`,
	 * `pa_*`, `product_brand`) until something else re-saved it.
	 *
	 * Extracted per AUDIT-2026-07-11 §3: `PostListColumn::create_linked_post()`
	 * already called this explicitly after writing the TRID/lang meta;
	 * `TranslationTrigger::create_translated_post()` and
	 * `AbstractTranslateCommand::create_trid_linked_post()` did not. All three
	 * creation paths now call this one shared helper instead of duplicating
	 * (or omitting) the guard, matching the consolidation §2 started for the
	 * common `wp_insert_post()` args.
	 *
	 * No-op for anything other than a WooCommerce 'product' post, or when
	 * WooCommerce/VariationSync aren't loaded. Callers MUST call this only
	 * after `_lf_trid`/`_lf_lang` have been written on $new_id — VariationSync
	 * reads them via `MetaDelegate::get_source_id_for()`.
	 *
	 * @param int      $new_id  Newly created translated post ID (TRID/lang meta already written).
	 * @param \WP_Post $source  Source-language post that was translated.
	 */
	public static function sync_variation_children_if_product( int $new_id, \WP_Post $source ): void {

		if ( 'product' !== $source->post_type
			|| ! class_exists( 'WooCommerce' )
			|| ! class_exists( \LinguaForge\AI\Integrations\WooCommerce\VariationSync::class )
		) {
			return;
		}

		\LinguaForge\AI\Integrations\WooCommerce\VariationSync::sync_variations_for( $new_id );
		\LinguaForge\AI\Integrations\WooCommerce\VariationSync::sync_wc_taxonomies_from_source( $source->ID, $new_id );
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

		// ── Common creation args (title, content, status, type, author, excerpt) ──
		// Shared with PostListColumn::create_linked_post() and
		// AbstractTranslateCommand::create_trid_linked_post() — see
		// build_create_args()'s docblock (AUDIT-2026-07-11 §2).
		$insert = self::build_create_args( $source, $target_lang, $result, ! empty( $params['force_draft'] ) );

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

		// ── Featured image — copy from source, unless already supplied above ──
		// Without this, a translated post/page/CPT is born with no featured
		// image at all (nothing in the 3 built-in creation paths ever set it).
		// Skipped for WooCommerce products/variations: MetaDelegate already
		// serves `_thumbnail_id` from the source product at read time, so
		// writing a copy here would just be silently shadowed.
		if ( ! isset( $meta['_thumbnail_id'] )
			&& post_type_supports( $source->post_type, 'thumbnail' )
			&& ! in_array( $source->post_type, [ 'product', 'product_variation' ], true )
		) {
			$source_thumbnail_id = (int) get_post_thumbnail_id( $source->ID );
			if ( $source_thumbnail_id ) {
				$meta['_thumbnail_id'] = $source_thumbnail_id;
			}
		}

		// ── Insert — bypass LF save hooks (same pattern as AbstractTranslateCommand) ──
		$router = \LinguaForge\Router\Router::get_instance();
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

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

		// ── Assign a language-specific FSE template if one exists ─────────────
		// This path bypasses handle_save_post() (unhooked above around
		// wp_insert_post()) so the new post never gets its template auto-assigned
		// the way normal editor saves, WP-CLI, and the Sync button all do — each
		// of those calls assign_template_if_needed() explicitly for the same
		// reason. Must run after _lf_trid/_lf_lang are written above so that
		// resolve_template_for_lang()'s front-page-translation detection (which
		// compares this post's trid against the front page's trid) sees the
		// correct value instead of an empty just-inserted post.
		$new_post = get_post( $new_id );
		if ( $new_post instanceof \WP_Post ) {
			$router->sync->assign_template_if_needed( $new_id, $new_post, $target_lang );
		}

		// ── WooCommerce variation children + taxonomies (AUDIT-2026-07-11 §3) ──
		// The wp_after_insert_post p30 hook that normally does this bailed
		// during the insert above (its _lf_lang read was still empty), so it
		// must be called explicitly now that the TRID/lang meta is written.
		self::sync_variation_children_if_product( $new_id, $source );

		/** @see AbstractTranslateCommand::create_trid_linked_post() for the action docblock */
		do_action( 'linguaforge_translation_complete', $new_id, $source->ID, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return (int) $new_id;
	}
}
