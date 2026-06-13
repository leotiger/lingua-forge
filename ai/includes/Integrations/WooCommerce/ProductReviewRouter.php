<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\ProductReviewRouter
 *
 * Implements the shared-review-pool model for translated WooCommerce products.
 *
 * Problem: WC attaches submitted reviews to the product post the customer is
 * viewing. A review written on /es/producto-x lands on the translated post, not
 * the source. The three rating meta keys (_wc_average_rating, _wc_review_count,
 * _wc_rating_count) are already delegated to the source product via MetaDelegate,
 * so the displayed star count is correct — but the review list shown on the page
 * is fetched from the translated post, producing a mismatch: "12 reviews" with
 * only 13 visible (or 0 if all prior reviews were written on the source).
 *
 * Fix — option (a), shared pool (mirrors WPML's approach):
 *
 *  1. Submission redirect (preprocess_comment):
 *     Before a review comment is inserted, redirect comment_post_ID from the
 *     translated product to the source product. All reviews land in one place;
 *     WC's "verified purchase" check works correctly because line items carry
 *     translated product IDs and WC resolves them through its own delegation.
 *
 *  2. Display delegation (comments_array):
 *     When WP fetches comments for a translated product page, substitute the
 *     source product's review list. All language pages display the same pool.
 *
 * Migration note: reviews written on translated products before this fix was
 * deployed remain on those posts and are not shown by the display filter.
 * A one-off SQL migration (reassign comment_post_ID to source) can be run if
 * needed; no such migration is applied automatically here.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class ProductReviewRouter {

	/**
	 * Reentrancy guard for serve_source_reviews().
	 * Keyed by translated post ID; prevents an infinite loop if get_comments()
	 * for the source product somehow re-enters this filter for the same post.
	 *
	 * @var array<int,true>
	 */
	private static array $fetching = [];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Redirect new review submissions to the source product before insertion.
		add_filter( 'preprocess_comment', [ self::class, 'redirect_submission' ] );

		// Serve source-product reviews on translated product pages.
		add_filter( 'comments_array', [ self::class, 'serve_source_reviews' ], 10, 2 );
	}

	// =========================================================================
	// Filter callbacks
	// =========================================================================

	/**
	 * Before a comment is inserted, redirect comment_post_ID to the source
	 * product when the review is submitted on a translated product page.
	 *
	 * Fires for all comment types; the post-type guard restricts the redirect
	 * to WooCommerce product posts (and any additional types added via the
	 * linguaforge_wc_delegate_post_types filter).
	 *
	 * @param  array<string,mixed> $commentdata Raw comment data array.
	 * @return array<string,mixed>
	 */
	public static function redirect_submission( array $commentdata ): array {

		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( ! $post_id ) {
			return $commentdata;
		}

		// ── Post type guard ────────────────────────────────────────────────────
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $commentdata;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return $commentdata;
		}

		// ── Language guard — only redirect non-source products ─────────────────
		$lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $commentdata;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $commentdata;
		}

		// ── Resolve source product ─────────────────────────────────────────────
		$source_id = MetaDelegate::get_source_id_for( $post_id );
		if ( ! $source_id || $source_id === $post_id ) {
			return $commentdata; // Fail safe — let WP insert on the translated post.
		}

		$commentdata['comment_post_ID'] = $source_id;
		return $commentdata;
	}

	/**
	 * When displaying reviews on a translated product page, return the source
	 * product's reviews so all language pages show the same review pool.
	 *
	 * The source product's rating meta keys (_wc_average_rating, _wc_review_count,
	 * _wc_rating_count) are already served by MetaDelegate, so after this filter
	 * the star rating and the review list are consistent on every language page.
	 *
	 * @param  \WP_Comment[] $comments Array of comments fetched for the post.
	 * @param  int           $post_id  Post ID the comments were fetched for.
	 * @return \WP_Comment[]
	 */
	public static function serve_source_reviews( array $comments, int $post_id ): array {

		// ── Reentrancy guard ───────────────────────────────────────────────────
		if ( isset( self::$fetching[ $post_id ] ) ) {
			return $comments;
		}

		// ── Post type guard ────────────────────────────────────────────────────
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $comments;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return $comments;
		}

		// ── Language guard — only act on non-source products ───────────────────
		$lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $comments;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $comments;
		}

		// ── Resolve source product ─────────────────────────────────────────────
		$source_id = MetaDelegate::get_source_id_for( $post_id );
		if ( ! $source_id || $source_id === $post_id ) {
			return $comments;
		}

		// ── Fetch source product reviews ───────────────────────────────────────
		// The source product is the source language, so this get_comments() call
		// will not re-enter this filter at the language-guard check.
		// The reentrancy guard is a belt-and-suspenders safety for edge cases
		// where the filter fires for the translated post_id a second time.
		self::$fetching[ $post_id ] = true;

		$source_reviews = get_comments( [
			'post_id' => $source_id,
			'status'  => 'approve',
			'type'    => 'review',
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		] );

		unset( self::$fetching[ $post_id ] );

		return is_array( $source_reviews ) ? $source_reviews : $comments;
	}
}
