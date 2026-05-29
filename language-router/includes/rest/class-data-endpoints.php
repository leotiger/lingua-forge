<?php
/**
 * Class LinguaForge\Router\REST\DataEndpoints
 *
 * Public read-only REST endpoints for language and translation data.
 * No AI module dependency — these work whenever the Language Router is active.
 *
 * Routes registered:
 *   GET /wp-json/lingua-forge/v1/languages
 *   GET /wp-json/lingua-forge/v1/post/{id}/translations
 */

namespace LinguaForge\Router\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

class DataEndpoints {

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {

		// ── Language list ────────────────────────────────────────────────────
		// Returns all active languages with their code and human-readable label.
		// No authentication required — the language list is not sensitive.
		register_rest_route(
			'lingua-forge/v1',
			'/languages',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_languages' ],
				'permission_callback' => '__return_true',
			]
		);

		// ── Translation map for a post ───────────────────────────────────────
		// Returns { lang_code: permalink } for every published translation of
		// the given post, including the post itself (the source language entry).
		// Private or password-protected posts require `read_post` capability.
		register_rest_route(
			'lingua-forge/v1',
			'/post/(?P<id>\d+)/translations',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_post_translations' ],
				'permission_callback' => [ self::class, 'check_post_read_permission' ],
				'args'                => [
					'id' => [
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	// =========================================================
	// PERMISSION CALLBACKS
	// =========================================================

	/**
	 * Allow the request if the post is publicly readable.
	 * Private / password-protected posts require `read_post` capability.
	 */
	public static function check_post_read_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			// Let the callback return 404 — no need for a permission error here.
			return true;
		}

		if ( $post->post_status === 'publish' ) {
			return true;
		}

		if ( current_user_can( 'read_post', $post_id ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to read this post.', 'lingua-forge' ),
			[ 'status' => 403 ]
		);
	}

	// =========================================================
	// HANDLERS
	// =========================================================

	/**
	 * GET /lingua-forge/v1/languages
	 *
	 * @return \WP_REST_Response  Array of { code: string, label: string }.
	 */
	public static function handle_languages(): \WP_REST_Response {

		$result = [];

		foreach ( linguaforge_languages() as $code ) {
			$result[] = [
				'code'  => $code,
				'label' => linguaforge_language_label( $code ),
			];
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /lingua-forge/v1/post/{id}/translations
	 *
	 * @return \WP_REST_Response|\WP_Error  Object keyed by language code, values are permalinks.
	 */
	public static function handle_post_translations( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {

		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'rest_post_invalid_id', __( 'Post not found.', 'lingua-forge' ), [ 'status' => 404 ] );
		}

		$translation_map = linguaforge_get_translations( $post_id );

		if ( empty( $translation_map ) ) {
			return rest_ensure_response( (object) [] );
		}

		$result = [];

		foreach ( $translation_map as $lang => $trans_id ) {
			if ( ! $trans_id ) {
				continue;
			}

			$trans_post = get_post( $trans_id );
			if ( ! $trans_post ) {
				continue;
			}

			// Only expose published translations (or privately readable ones for
			// authenticated requests — the permission callback already cleared that).
			if ( $trans_post->post_status !== 'publish'
				&& ! current_user_can( 'read_post', $trans_id ) ) {
				continue;
			}

			$url = get_permalink( $trans_id );
			if ( $url ) {
				$result[ $lang ] = $url;
			}
		}

		return rest_ensure_response( (object) $result );
	}
}
