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

		$post_data = [ 'ID' => $existing_id ];

		if ( ! empty( $result['output'] ) ) {
			$post_data['post_content'] = (string) $result['output'];
		}

		if ( ! empty( $result['translated_title'] ) ) {
			$post_data['post_title'] = (string) $result['translated_title'];
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

		// ── Insert — bypass LF save hooks (same pattern as AbstractTranslateCommand) ──
		$router = \LinguaForge\Router\Router::get_instance();
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$new_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => (string) ( $result['output'] ?? '' ),
			'post_status'  => $target_status,
			'post_type'    => $source->post_type,
			'post_author'  => (int) $source->post_author,
		], true );

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
