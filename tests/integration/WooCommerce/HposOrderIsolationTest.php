<?php
/**
 * HposOrderIsolationTest — asserts that shop_order posts are never touched by
 * Lingua Forge's language-assignment or translation-creation paths.
 *
 * Covers the concern raised in AUDIT-2026-06-01 §5.3:
 *   "Verify that custom order meta does not accidentally get a _lf_lang meta
 *    key written by any automated path."
 *
 * How LF protects non-content WC post types:
 *
 *   A. Sync::handle_save_post() bails early when $pto->public is false.
 *      shop_order, shop_coupon, shop_subscription and product_variation all
 *      have public => false, so _lf_lang is never auto-assigned on save.
 *
 *   B. MetaDelegate::maybe_delegate() only fires on post types that LF has
 *      assigned _lf_lang meta to.  Without _lf_lang, the delegation path is
 *      never entered, so order meta reads are not intercepted.
 *
 *   C. ajax_fill_missing operates on a caller-supplied post_id from the admin
 *      post list.  The Lang column is only registered for public, non-internal
 *      post types, so the "Translate missing" button never appears on order
 *      screens — providing a UI-level gate independent of the code path.
 *
 * Three things are tested here:
 *
 *   1. handle_save_post does NOT write _lf_lang to a shop_order on wp_insert_post.
 *   2. MetaDelegate does not intercept get_post_meta() on an order that has no
 *      _lf_lang (the normal state guaranteed by test 1).
 *   3. MetaDelegate::$delegating static guard is not left dirty after an order
 *      meta read — no reentrancy state leaks into subsequent reads.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

final class HposOrderIsolationTest extends WcIntegrationTestCase {

	// =========================================================================
	// 1. Sync::handle_save_post() does not write _lf_lang to shop_order
	// =========================================================================

	/**
	 * Creating a shop_order via wp_insert_post() fires wp_after_insert_post,
	 * which is hooked by Sync::handle_save_post().  That handler bails when
	 * $pto->public is false — shop_order registers with public => false —
	 * so _lf_lang must never be written.
	 *
	 * @dataProvider non_content_wc_post_types
	 */
	public function test_handle_save_post_does_not_assign_lang_to_non_public_wc_type( string $post_type ): void {
		$post_id = wp_insert_post( [
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => "Test {$post_type}",
		] );

		$this->assertIsInt( $post_id, "wp_insert_post must succeed for post type '{$post_type}'." );
		$this->assertGreaterThan( 0, $post_id );

		$lang = get_post_meta( $post_id, '_lf_lang', true );

		$this->assertSame(
			'',
			$lang,
			"_lf_lang must not be written to a '{$post_type}' post by handle_save_post()."
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function non_content_wc_post_types(): array {
		return [
			'shop_order'        => [ 'shop_order' ],
			'shop_coupon'       => [ 'shop_coupon' ],
			'shop_subscription' => [ 'shop_subscription' ],
			'product_variation' => [ 'product_variation' ],
		];
	}

	// =========================================================================
	// 2. MetaDelegate does not intercept shop_order meta reads
	// =========================================================================

	/**
	 * An order post without _lf_lang must never enter MetaDelegate's delegation
	 * path.  get_post_meta() on an order must return the value stored directly
	 * on that order — not a delegated source product value.
	 */
	public function test_meta_delegate_does_not_intercept_order_meta(): void {
		$order_id = self::factory()->post->create( [
			'post_type'   => 'shop_order',
			'post_status' => 'publish',
		] );

		// Write a meta key that MetaDelegate would delegate on a product.
		update_post_meta( $order_id, '_price', '99.00' );

		// Create an unrelated product so a "source" exists in the DB —
		// if delegation fired incorrectly it might delegate to this.
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		update_post_meta( $product_id, '_price', '1.00' );

		$result = get_post_meta( $order_id, '_price', true );

		$this->assertSame(
			'99.00',
			$result,
			'MetaDelegate must not intercept get_post_meta() on a shop_order post.'
		);
	}

	// =========================================================================
	// 3. MetaDelegate leaves no dirty static state after an order meta read
	// =========================================================================

	/**
	 * If MetaDelegate ever incorrectly entered the delegation path for a
	 * shop_order, the static $delegating reentrancy guard would be left set,
	 * causing unpredictable behaviour for subsequent meta reads in the same
	 * request.  Assert the guard remains clean after an order meta read.
	 */
	public function test_meta_delegate_leaves_no_dirty_state_after_order_read(): void {
		$order_id = self::factory()->post->create( [
			'post_type'   => 'shop_order',
			'post_status' => 'publish',
		] );
		update_post_meta( $order_id, '_order_total', '150.00' );

		get_post_meta( $order_id, '_order_total', true );

		$ref  = new \ReflectionClass( \LinguaForge\AI\Integrations\WooCommerce\MetaDelegate::class );
		$prop = $ref->getProperty( 'delegating' );
		$prop->setAccessible( true );
		$delegating = $prop->getValue( null );

		$this->assertEmpty(
			$delegating,
			'MetaDelegate::$delegating must not be set after reading meta on a shop_order.'
		);
	}
}
