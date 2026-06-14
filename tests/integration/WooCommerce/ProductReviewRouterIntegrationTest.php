<?php
/**
 * Integration tests for LinguaForge\AI\Integrations\WooCommerce\ProductReviewRouter.
 *
 * Covers the shared-review-pool model (§6.5 / 2.3.0):
 *
 *  1. redirect_submission — review submitted on a translated product lands on
 *     the source product (comment_post_ID rewritten).
 *  2. serve_source_reviews — when WP fetches comments for a translated product
 *     page, the source product's approved reviews are returned instead.
 *
 * Boundary cases:
 *  - Reviews on source products are never redirected.
 *  - Non-product post types are left untouched by both filters.
 *  - Reentrancy guard on serve_source_reviews prevents infinite loops.
 *  - A product with no TRID source (fail-safe) is left untouched.
 *
 * Comment rows are inserted into the DB via wp_insert_comment(); WP_UnitTestCase
 * rolls them back on tearDown together with post rows.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\ProductReviewRouter;
use ReflectionClass;

final class ProductReviewRouterIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// setUp / tearDown
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		$this->reset_fetching_guard();
	}

	protected function tearDown(): void {
		$this->reset_fetching_guard();
		remove_all_filters( 'preprocess_comment' );
		remove_all_filters( 'comments_array' );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function reset_fetching_guard(): void {
		$ref  = new ReflectionClass( ProductReviewRouter::class );
		$prop = $ref->getProperty( 'fetching' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	/**
	 * Build a commentdata array as WP passes to the preprocess_comment filter.
	 *
	 * @param  int    $post_id
	 * @param  string $content
	 * @return array<string,mixed>
	 */
	private function make_commentdata( int $post_id, string $content = 'Great product!' ): array {
		return [
			'comment_post_ID'      => $post_id,
			'comment_author'       => 'Test User',
			'comment_author_email' => 'test@example.com',
			'comment_content'      => $content,
			'comment_type'         => 'review',
		];
	}

	/**
	 * Insert a real approved review comment on a post.
	 */
	private function insert_review( int $post_id, string $content = 'Great product!' ): int {
		return (int) wp_insert_comment( [
			'comment_post_ID'  => $post_id,
			'comment_content'  => $content,
			'comment_approved' => 1,
			'comment_type'     => 'review',
		] );
	}

	// =========================================================================
	// redirect_submission
	// =========================================================================

	/**
	 * A review submitted on a translated product must have its comment_post_ID
	 * rewritten to the source product before insertion.
	 */
	public function test_redirect_submission_rewrites_translated_product_to_source(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		$commentdata = $this->make_commentdata( $translated_id );
		$result      = ProductReviewRouter::redirect_submission( $commentdata );

		$this->assertSame(
			$source_id,
			(int) $result['comment_post_ID'],
			'comment_post_ID must be rewritten to the source product ID.'
		);
	}

	/**
	 * A review submitted on the source product must NOT be redirected.
	 */
	public function test_redirect_submission_leaves_source_product_untouched(): void {
		[ $source_id ] = $this->make_product_pair();

		$commentdata = $this->make_commentdata( $source_id );
		$result      = ProductReviewRouter::redirect_submission( $commentdata );

		$this->assertSame(
			$source_id,
			(int) $result['comment_post_ID'],
			'Reviews on the source product must not be redirected.'
		);
	}

	/**
	 * A comment on a non-product post type must be left entirely unchanged.
	 */
	public function test_redirect_submission_leaves_non_product_post_type_untouched(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );

		$commentdata = $this->make_commentdata( $post_id );
		$result      = ProductReviewRouter::redirect_submission( $commentdata );

		$this->assertSame(
			$post_id,
			(int) $result['comment_post_ID'],
			'comment_post_ID must not be changed for non-product post types.'
		);
	}

	/**
	 * A commentdata array with a zero/missing post_id must pass through.
	 */
	public function test_redirect_submission_handles_missing_post_id_gracefully(): void {
		$commentdata = [ 'comment_post_ID' => 0, 'comment_content' => 'test' ];
		$result      = ProductReviewRouter::redirect_submission( $commentdata );

		$this->assertSame( 0, (int) $result['comment_post_ID'] );
	}

	/**
	 * A translated product that has no resolvable source (fail-safe path) must
	 * keep its original comment_post_ID so WP can insert the review locally.
	 */
	public function test_redirect_submission_failsafe_when_no_source_resolved(): void {
		// Translated product with _lf_lang=es but NO TRID / source post.
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		update_post_meta( $post_id, '_lf_lang', 'es' );
		// Deliberately do NOT call $this->tg->set_trid() — no source available.

		$commentdata = $this->make_commentdata( $post_id );
		$result      = ProductReviewRouter::redirect_submission( $commentdata );

		$this->assertSame(
			$post_id,
			(int) $result['comment_post_ID'],
			'Fail-safe: when no source can be resolved, comment_post_ID must be left unchanged.'
		);
	}

	// =========================================================================
	// serve_source_reviews
	// =========================================================================

	/**
	 * When WP fetches comments for a translated product page, serve_source_reviews
	 * must substitute the source product's approved reviews.
	 */
	public function test_serve_source_reviews_returns_source_reviews_for_translated_product(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Insert reviews on the source product only.
		$this->insert_review( $source_id, 'Excellent!' );
		$this->insert_review( $source_id, 'Very good.' );

		$result = ProductReviewRouter::serve_source_reviews( [], $translated_id );

		$this->assertCount( 2, $result, 'serve_source_reviews() must return the source product\'s two reviews.' );

		$contents = array_map( fn( $c ) => $c->comment_content, $result );
		$this->assertContains( 'Excellent!', $contents );
		$this->assertContains( 'Very good.', $contents );
	}

	/**
	 * When fetching comments for the source product, serve_source_reviews must
	 * return the original $comments array unchanged.
	 */
	public function test_serve_source_reviews_does_not_alter_source_product_comments(): void {
		[ $source_id ] = $this->make_product_pair();

		$dummy_comment = (object) [ 'comment_content' => 'Direct on source' ];
		$result        = ProductReviewRouter::serve_source_reviews( [ $dummy_comment ], $source_id );

		$this->assertSame( [ $dummy_comment ], $result );
	}

	/**
	 * serve_source_reviews must leave comments on non-product posts untouched.
	 */
	public function test_serve_source_reviews_leaves_non_product_posts_untouched(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );

		$dummy = (object) [ 'comment_content' => 'Blog comment' ];
		$result = ProductReviewRouter::serve_source_reviews( [ $dummy ], $post_id );

		$this->assertSame( [ $dummy ], $result );
	}

	/**
	 * The reentrancy guard ($fetching) must prevent infinite loops when
	 * serve_source_reviews is called recursively for the same translated post.
	 */
	public function test_serve_source_reviews_reentrancy_guard_prevents_infinite_loop(): void {
		[ , $translated_id ] = $this->make_product_pair();

		// Simulate mid-fetch state: guard is set for this translated post.
		$ref  = new ReflectionClass( ProductReviewRouter::class );
		$prop = $ref->getProperty( 'fetching' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ $translated_id => true ] );

		$input  = [ (object) [ 'comment_content' => 'sentinel' ] ];
		$result = ProductReviewRouter::serve_source_reviews( $input, $translated_id );

		$this->assertSame( $input, $result, 'Reentrancy guard must return the original comments immediately.' );
	}

	/**
	 * After serve_source_reviews() completes normally, the reentrancy guard must
	 * be cleared so subsequent calls for the same post work correctly.
	 */
	public function test_serve_source_reviews_clears_reentrancy_guard_after_completion(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->insert_review( $source_id, 'Test review' );

		ProductReviewRouter::serve_source_reviews( [], $translated_id );

		$ref     = new ReflectionClass( ProductReviewRouter::class );
		$prop    = $ref->getProperty( 'fetching' );
		$prop->setAccessible( true );
		$fetching = $prop->getValue( null );

		$this->assertArrayNotHasKey(
			$translated_id,
			$fetching,
			'$fetching guard must be cleared after serve_source_reviews() returns.'
		);
	}

	// =========================================================================
	// Full-cycle: redirect then display
	// =========================================================================

	/**
	 * End-to-end: insert a review through the redirect filter (lands on source),
	 * then read via the display filter (translated page sees the same review).
	 */
	public function test_review_submitted_on_translated_appears_on_both_pages(): void {
		[ $source_id, $translated_id ] = $this->make_product_pair();

		// Submit review through the redirect filter.
		$commentdata = $this->make_commentdata( $translated_id, 'End-to-end review' );
		$redirected  = ProductReviewRouter::redirect_submission( $commentdata );

		// Insert the comment on the (redirected) source product.
		wp_insert_comment( [
			'comment_post_ID'  => $redirected['comment_post_ID'],
			'comment_content'  => $redirected['comment_content'],
			'comment_approved' => 1,
			'comment_type'     => 'review',
		] );

		// Read via the display filter for the translated product.
		$result = ProductReviewRouter::serve_source_reviews( [], $translated_id );

		$contents = array_map( fn( $c ) => $c->comment_content, $result );
		$this->assertContains(
			'End-to-end review',
			$contents,
			'Review submitted on translated product must appear in serve_source_reviews() output.'
		);
	}
}
