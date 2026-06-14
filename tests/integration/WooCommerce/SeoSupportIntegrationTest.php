<?php
/**
 * Integration tests for WooCommerce\SeoSupport.
 *
 * Covered here:
 *   filter_og_type()       — returns 'product' on WC product pages;
 *                            passes non-product $type unchanged.
 *   output_og_extra()      — emits og:price:amount, og:price:currency,
 *                            og:availability (instock → 'instock'; outofstock → 'oos');
 *                            exits silently on non-product pages.
 *   inject_inlanguage()    — injects 'inLanguage' BCP 47 value into the WC Product
 *                            markup array (2.2.4 dedup contract: no separate Product
 *                            JSON-LD block, only this filter).
 *
 * All tests extend WcIntegrationTestCase so the suite is skipped when
 * WooCommerce is not active in wp-env.
 *
 * Run via: composer test:integration:wc  (requires wp-env + WC running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\SeoSupport;
use LinguaForge\Router\Seo\SchemaManager;

final class SeoSupportIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		update_option( 'linguaforge_seo_wc_og_enabled', true, false );
		update_option( 'linguaforge_seo_schema_enabled', true, false );
		update_option( 'linguaforge_seo_schema_product', true, false );
	}

	// =========================================================================
	// filter_og_type()
	// =========================================================================

	/**
	 * filter_og_type() must return 'product' when the current page is a singular
	 * WooCommerce product.
	 */
	public function test_filter_og_type_returns_product_on_product_page(): void {
		[ $product_id ] = $this->make_product_pair();
		// Must include post_type=product: go_to('/?p=ID') alone does not set the
		// queried object's post_type, so is_singular('product') returns false.
		$this->go_to( '/?post_type=product&p=' . $product_id );

		$result = SeoSupport::filter_og_type( 'article' );

		$this->assertSame( 'product', $result );
	}

	/**
	 * filter_og_type() must pass the type unchanged when the current page is not
	 * a WC product (e.g. a regular post).
	 */
	public function test_filter_og_type_passthrough_on_non_product_page(): void {
		$post_id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( '/?p=' . $post_id );

		$result = SeoSupport::filter_og_type( 'article' );

		$this->assertSame( 'article', $result );
	}

	// =========================================================================
	// output_og_extra()
	// =========================================================================

	/**
	 * output_og_extra() must output og:price:amount and og:price:currency for an
	 * in-stock WooCommerce product.
	 */
	public function test_output_og_extra_outputs_price_and_currency_for_product(): void {
		[ $product_id ] = $this->make_product_pair();

		// Set a price on the product so $product->get_price() returns a non-empty string.
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			$this->markTestSkipped( 'WC product could not be loaded.' );
		}
		$product->set_price( '29.99' );
		$product->set_regular_price( '29.99' );
		$product->set_stock_status( 'instock' );
		$product->save();

		$this->go_to( '/?post_type=product&p=' . $product_id );

		ob_start();
		SeoSupport::output_og_extra();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'og:price:amount', $output );
		$this->assertStringContainsString( 'og:price:currency', $output );
		$this->assertStringContainsString( 'og:availability', $output );
		$this->assertStringContainsString( 'content="instock"', $output );
	}

	/**
	 * An out-of-stock product must receive og:availability = 'oos' (Facebook's
	 * value for out-of-stock, mapped from WC's 'outofstock' stock status).
	 */
	public function test_output_og_extra_maps_outofstock_to_oos(): void {
		[ $product_id ] = $this->make_product_pair();

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			$this->markTestSkipped( 'WC product could not be loaded.' );
		}
		$product->set_price( '9.99' );
		$product->set_regular_price( '9.99' );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$this->go_to( '/?post_type=product&p=' . $product_id );

		ob_start();
		SeoSupport::output_og_extra();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'content="oos"', $output,
			'WC outofstock must map to Facebook og:availability "oos"' );
	}

	/**
	 * output_og_extra() must produce no output on a non-product page.
	 */
	public function test_output_og_extra_silent_on_non_product_page(): void {
		$post_id = (int) $this->factory->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( '/?p=' . $post_id );

		ob_start();
		SeoSupport::output_og_extra();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	// =========================================================================
	// inject_inlanguage()
	// =========================================================================

	/**
	 * inject_inlanguage() must add 'inLanguage' (BCP 47) to the WC Product markup
	 * array when LF_LANG is defined (it always is in wp-env, set to 'en').
	 *
	 * This is the 2.2.4 dedup contract: LF adds inLanguage via this filter rather
	 * than emitting a separate Product JSON-LD block, so there is never more than
	 * one Product schema block on a product page.
	 */
	public function test_inject_inlanguage_adds_bcp47_in_language_key(): void {
		$markup = [
			'@type' => 'Product',
			'name'  => 'Test Product',
		];

		$result = SeoSupport::inject_inlanguage( $markup );

		$this->assertArrayHasKey( 'inLanguage', $result,
			'inject_inlanguage() must add the inLanguage key to WC Product markup' );

		// LF_LANG = 'en' in wp-env; expected BCP 47 = 'en-US'.
		$expected_bcp47 = SchemaManager::lang_to_bcp47( LF_LANG );
		$this->assertSame( $expected_bcp47, $result['inLanguage'] );
	}

	/**
	 * inject_inlanguage() must not duplicate the Product schema: calling the
	 * filter and then asserting no extra @type entries is the regression guard
	 * for the double-Product-block bug fixed in 2.2.4.
	 */
	public function test_inject_inlanguage_does_not_add_duplicate_type_key(): void {
		$markup = [
			'@type' => 'Product',
			'name'  => 'Widget',
			'sku'   => 'W-123',
		];

		$result = SeoSupport::inject_inlanguage( $markup );

		// @type must remain a scalar string (not an array of types).
		$this->assertIsString( $result['@type'],
			'@type must remain a single string after inject_inlanguage()' );
		$this->assertSame( 'Product', $result['@type'] );
	}
}
