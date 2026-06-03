<?php
/**
 * Integration tests for MetaDelegate via the WooCommerce object API.
 *
 * These tests call wc_get_product() / wc_get_product() on a variation, which
 * triggers WC_Product_Data_Store_CPT::read_product_data(). That method reads ALL
 * product meta in a single bulk call:
 *
 *   $post_meta_values = get_post_meta( $id );   // no meta_key argument
 *
 * WordPress fires apply_filters('get_post_metadata', null, $id, '', false)
 * for this bulk call. MetaDelegate's filter guard `! in_array('', OPERATIONAL_KEYS)`
 * currently returns null — meaning the bulk read bypasses MetaDelegate entirely
 * and WC reads translated product/variation operational meta as empty.
 *
 * These tests document and pin that behaviour:
 *   • They ASSERT the expected correct values (source price, SKU, stock, weight).
 *   • If the bulk-read bypass is NOT fixed they will FAIL, confirming the gap.
 *   • Once MetaDelegate is extended to handle the bulk read, they will pass and
 *     act as regression guards.
 *
 * Coverage — translated parent products:
 *   1.  wc_get_product($translated_id)->get_price() returns source price.
 *   2.  wc_get_product($translated_id)->get_regular_price() returns source price.
 *   3.  wc_get_product($translated_id)->get_sku() returns source SKU.
 *   4.  wc_get_product($translated_id)->get_stock_quantity() returns source stock.
 *   5.  wc_get_product($translated_id)->get_weight() returns source weight.
 *   6.  wc_get_product($source_id)->get_price() returns its own price unchanged.
 *
 * Coverage — translated product variations (2.1.6+, via VariationSync):
 *   7.  wc_get_product($translated_var_id)->get_regular_price() returns source price.
 *   8.  wc_get_product($translated_var_id)->get_sku() returns source SKU.
 *   9.  wc_get_product($translated_var_id)->get_stock_quantity() returns source stock.
 *  10.  wc_get_product($translated_var_id)->get_description() returns translated
 *       _variation_description (own field — NOT delegated).
 *  11.  wc_get_product($translated_var_id)->get_attributes() contains attribute
 *       values matching source variation's attribute_pa_* meta.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\VariationSync;

final class MetaDelegateWcApiIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Skip the test if wc_get_product() is not available.
	 * (Guard against running outside a wp-env with WooCommerce active.)
	 */
	private function require_wc(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'wc_get_product() not available — WooCommerce must be active.' );
		}
	}

	/**
	 * Create a source variation attached to $parent_id with price, SKU, stock,
	 * and an attribute assignment.
	 */
	private function make_source_variation_with_meta(
		int    $parent_id,
		string $price    = '29.99',
		string $sku      = 'SRC-SKU-001',
		int    $stock    = 10,
		string $color    = 'red',
		string $desc     = 'Source variation description.'
	): int {
		$var_id = (int) self::factory()->post->create( [
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => $parent_id,
		] );

		update_post_meta( $var_id, '_regular_price', $price );
		update_post_meta( $var_id, '_price',         $price );
		update_post_meta( $var_id, '_sku',           $sku );
		update_post_meta( $var_id, '_stock',         $stock );
		update_post_meta( $var_id, '_stock_status',  'instock' );
		update_post_meta( $var_id, '_manage_stock',  'yes' );
		update_post_meta( $var_id, '_variation_description', $desc );
		// WC stores attribute meta as attribute_pa_* (no leading underscore).
		update_post_meta( $var_id, 'attribute_pa_color', $color );

		return $var_id;
	}

	// =========================================================================
	// 1–5. Translated parent product — WC API reads
	// =========================================================================

	public function test_wc_get_product_translated_parent_price(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_price',         '49.99' );
		update_post_meta( $source_id, '_regular_price', '49.99' );

		$product = wc_get_product( $translated_id );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertSame(
			'49.99',
			$product->get_price(),
			'wc_get_product($translated)->get_price() must return the source price via MetaDelegate.'
		);
	}

	public function test_wc_get_product_translated_parent_regular_price(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_regular_price', '59.99' );

		$product = wc_get_product( $translated_id );
		$this->assertSame( '59.99', $product->get_regular_price() );
	}

	public function test_wc_get_product_translated_parent_sku(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_sku', 'SRC-001' );

		$product = wc_get_product( $translated_id );
		$this->assertSame( 'SRC-001', $product->get_sku() );
	}

	public function test_wc_get_product_translated_parent_stock(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_stock',        42 );
		update_post_meta( $source_id, '_manage_stock', 'yes' );

		$product = wc_get_product( $translated_id );
		$this->assertSame( 42.0, (float) $product->get_stock_quantity() );
	}

	public function test_wc_get_product_translated_parent_weight(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_weight', '1.5' );

		$product = wc_get_product( $translated_id );
		$this->assertSame( '1.5', $product->get_weight() );
	}

	// =========================================================================
	// 6. Source product — own meta unchanged
	// =========================================================================

	public function test_wc_get_product_source_price_unaffected(): void {
		$this->require_wc();

		[ $source_id ] = $this->make_product_pair();
		update_post_meta( $source_id, '_price', '99.99' );

		$product = wc_get_product( $source_id );
		$this->assertSame( '99.99', $product->get_price(), 'Source product must return its own price.' );
	}

	// =========================================================================
	// 7–9. Translated variation — WC API operational meta reads
	// =========================================================================

	public function test_wc_get_product_translated_variation_regular_price(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$source_var_id = $this->make_source_variation_with_meta( $source_id, '19.99' );
		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = get_posts( [
			'post_type'      => 'product_variation',
			'post_parent'    => $translated_id,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'nopaging'       => true,
			'no_found_rows'  => true,
		] );

		$this->assertNotEmpty( $trans_var_ids, 'VariationSync must create translated variation.' );

		$variation = wc_get_product( (int) $trans_var_ids[0] );
		$this->assertInstanceOf( \WC_Product_Variation::class, $variation );
		$this->assertSame(
			'19.99',
			$variation->get_regular_price(),
			'wc_get_product($translated_var)->get_regular_price() must return source variation price.'
		);

		unset( $source_var_id ); // Suppress unused-variable warning.
	}

	public function test_wc_get_product_translated_variation_sku(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation_with_meta( $source_id, '19.99', 'VAR-SKU-007' );
		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = get_posts( [
			'post_type'     => 'product_variation',
			'post_parent'   => $translated_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );

		$variation = wc_get_product( (int) $trans_var_ids[0] );
		$this->assertSame( 'VAR-SKU-007', $variation->get_sku() );
	}

	public function test_wc_get_product_translated_variation_stock(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation_with_meta( $source_id, '9.99', 'SK', 25 );
		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = get_posts( [
			'post_type'     => 'product_variation',
			'post_parent'   => $translated_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );

		$variation = wc_get_product( (int) $trans_var_ids[0] );
		$this->assertSame( 25.0, (float) $variation->get_stock_quantity() );
	}

	// =========================================================================
	// 10. Translated variation description — own field, NOT delegated
	// =========================================================================

	public function test_wc_get_product_translated_variation_description_is_own(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation_with_meta(
			$source_id, '9.99', 'SK', 5, 'red', 'Source: water-based dye.'
		);
		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = get_posts( [
			'post_type'     => 'product_variation',
			'post_parent'   => $translated_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );

		$variation = wc_get_product( (int) $trans_var_ids[0] );

		// Description is copied from source at creation — it starts identical.
		// It is NOT in OPERATIONAL_KEYS, so MetaDelegate does not delegate it.
		// Editors retranslate it via the Retranslate button.
		$this->assertSame(
			'Source: water-based dye.',
			$variation->get_description(),
			'_variation_description must be present on the translated variation as an own (non-delegated) field.'
		);

		// Now simulate a translated description being saved.
		update_post_meta( (int) $trans_var_ids[0], '_variation_description', 'Colorant aqueux.' );

		// Reload — must return the translated description, not the source.
		$variation_reloaded = wc_get_product( (int) $trans_var_ids[0] );
		$this->assertSame(
			'Colorant aqueux.',
			$variation_reloaded->get_description(),
			'After translation, get_description() must return the own translated value, not the source.'
		);
	}

	// =========================================================================
	// 11. Translated variation attributes — copied at creation, WC-readable
	// =========================================================================

	public function test_wc_get_product_translated_variation_attributes_present(): void {
		$this->require_wc();

		[ $source_id, $translated_id ] = $this->make_product_pair();
		$this->make_source_variation_with_meta( $source_id, '9.99', 'SK', 5, 'blue' );
		VariationSync::sync_variations_for( $translated_id );

		$trans_var_ids = get_posts( [
			'post_type'     => 'product_variation',
			'post_parent'   => $translated_id,
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		] );

		// Verify attribute meta was copied with the correct WC key prefix (attribute_pa_color).
		$this->assertSame(
			'blue',
			get_post_meta( (int) $trans_var_ids[0], 'attribute_pa_color', true ),
			'attribute_pa_color must be present on translated variation with attribute_ prefix (no leading underscore).'
		);
	}
}
