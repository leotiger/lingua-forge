<?php
/**
 * Unit tests for LinguaForge\AI\Integrations\WooCommerce\LocalAttributeTranslator.
 *
 * LocalAttributeTranslator.on_translation_complete() performs two writes per
 * product translation:
 *
 *   A. Translates local (is_taxonomy=0) attribute names and pipe-separated
 *      values inside _product_attributes on the translated product post.
 *   B. Rewrites attribute_{key} postmeta on translated variation children so
 *      WC's find_matching_product_variation() can match translated selections.
 *
 * Strategy:
 *   TermNameTranslator is replaced by Stubs/TermNameTranslatorStub.php (loaded
 *   before the real class file so the same FQCN resolves to the stub). The stub
 *   exposes $stub_result (return value) and $last_call (argument capture).
 *
 *   $wpdb->get_col() is provided by LfWpdb; variation IDs are fed via
 *   LfWcMocks::$wpdb_get_col (new property added to WcPolyfills.php).
 *
 * Test groups:
 *   1–7   Guard conditions that cause an early return (nothing written).
 *   8–13  Component A — _product_attributes translation.
 *   14–17 Component B — variation meta rewrite.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

// Stub must be loaded BEFORE the real LocalAttributeTranslator so that PHP
// resolves TermNameTranslator to the stub when the translator calls it.
use LinguaForge\AI\Integrations\WooCommerce\LocalAttributeTranslator;
use LinguaForge\AI\Integrations\WooCommerce\TermNameTranslator;

require_once __DIR__ . '/WcPolyfills.php';
require_once __DIR__ . '/Stubs/TermNameTranslatorStub.php';
require_once dirname( __DIR__, 3 ) . '/ai/includes/Integrations/WooCommerce/LocalAttributeTranslator.php';

final class LocalAttributeTranslatorTest extends WcUnitTestCase {

	// =========================================================================
	// Fixture helpers
	// =========================================================================

	/**
	 * Build a minimal local attribute array entry.
	 *
	 * @param  string $name         Attribute label (e.g. 'Color').
	 * @param  string $value        Pipe-separated values (e.g. 'Red | Blue').
	 * @param  bool   $is_variation Whether used for variations.
	 * @return array<string,mixed>
	 */
	private function local_attr( string $name, string $value = '', bool $is_variation = false ): array {
		return [
			'name'         => $name,
			'value'        => $value,
			'is_taxonomy'  => 0,
			'is_visible'   => 1,
			'is_variation' => (int) $is_variation,
			'position'     => 0,
		];
	}

	/**
	 * Build a global (taxonomy-backed) attribute entry.
	 *
	 * @param  string $name Attribute label.
	 * @return array<string,mixed>
	 */
	private function taxonomy_attr( string $name ): array {
		return [
			'name'         => $name,
			'value'        => '',
			'is_taxonomy'  => 1,
			'is_visible'   => 1,
			'is_variation' => 0,
			'position'     => 0,
		];
	}

	/**
	 * Set up a source product post with a given _product_attributes value.
	 *
	 * @param  array<string,array<string,mixed>> $attrs
	 */
	private function seed_source( int $source_id, array $attrs, string $post_type = 'product' ): void {
		$this->make_post( $source_id, $post_type );
		$this->set_meta( $source_id, '_product_attributes', $attrs );
	}

	/**
	 * Extract the _product_attributes value written to the translated product
	 * from the write log.
	 *
	 * @return array<string,mixed>|null  null when no such write was made.
	 */
	private function written_product_attributes( int $translated_id ): ?array {
		foreach ( \LfWcMocks::$write_log as [ $action, $pid, $key, $value ] ) {
			if ( $pid === $translated_id && '_product_attributes' === $key ) {
				return $value;
			}
		}
		return null;
	}

	protected function setUp(): void {
		parent::setUp();
		TermNameTranslator::reset();
		\LfWcMocks::$wpdb_get_col = [];
	}

	// =========================================================================
	// 1. Guard: source post is not a product
	// =========================================================================

	public function test_guard_non_product_post_type_skips_silently(): void {
		$this->seed_source( 1, [ 'color' => $this->local_attr( 'Color', 'Red' ) ], 'page' );

		LocalAttributeTranslator::on_translation_complete( 2, 1, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log, 'Non-product source must produce no writes.' );
		$this->assertSame( 0, TermNameTranslator::$call_count, 'translate_term_names() must not be called for non-product source.' );
	}

	// =========================================================================
	// 2. Guard: source post does not exist
	// =========================================================================

	public function test_guard_unknown_source_id_skips_silently(): void {
		// Post ID 99 is not registered in LfWcMocks::$posts.
		LocalAttributeTranslator::on_translation_complete( 2, 99, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log );
		$this->assertSame( 0, TermNameTranslator::$call_count );
	}

	// =========================================================================
	// 3. Guard: _product_attributes is an empty array
	// =========================================================================

	public function test_guard_empty_product_attributes_array_skips(): void {
		$this->seed_source( 1, [] );

		LocalAttributeTranslator::on_translation_complete( 2, 1, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log );
		$this->assertSame( 0, TermNameTranslator::$call_count );
	}

	// =========================================================================
	// 4. Guard: _product_attributes is not an array (e.g. '' from get_post_meta)
	// =========================================================================

	public function test_guard_non_array_product_attributes_skips(): void {
		$this->make_post( 1 );
		$this->set_meta( 1, '_product_attributes', '' );

		LocalAttributeTranslator::on_translation_complete( 2, 1, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log );
		$this->assertSame( 0, TermNameTranslator::$call_count );
	}

	// =========================================================================
	// 5. Guard: all attributes are global (taxonomy-backed)
	// =========================================================================

	public function test_guard_all_taxonomy_attributes_skips(): void {
		$this->seed_source( 1, [
			'pa_color' => $this->taxonomy_attr( 'Color' ),
			'pa_size'  => $this->taxonomy_attr( 'Size' ),
		] );

		LocalAttributeTranslator::on_translation_complete( 2, 1, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log );
		$this->assertSame( 0, TermNameTranslator::$call_count );
	}

	// =========================================================================
	// 6. Guard: local attribute has empty name and empty value (nothing to batch)
	// =========================================================================

	public function test_guard_empty_batch_skips(): void {
		// Local attribute but both name and value are empty — nothing added to $batch.
		$this->seed_source( 1, [
			'note' => [
				'name'        => '',
				'value'       => '',
				'is_taxonomy' => 0,
				'is_visible'  => 1,
				'is_variation' => 0,
				'position'    => 0,
			],
		] );

		LocalAttributeTranslator::on_translation_complete( 2, 1, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log );
		$this->assertSame( 0, TermNameTranslator::$call_count, 'translate_term_names() must not be called when batch is empty.' );
	}

	// =========================================================================
	// 7. Guard: translate_term_names() returns empty (AI failure / no result)
	// =========================================================================

	public function test_guard_empty_translation_result_skips(): void {
		$this->seed_source( 1, [ 'color' => $this->local_attr( 'Color', 'Red' ) ] );
		TermNameTranslator::$stub_result = []; // empty → bail

		LocalAttributeTranslator::on_translation_complete( 2, 1, 'es' );

		$this->assertEmpty( \LfWcMocks::$write_log, 'No write must occur when AI returns empty translations.' );
	}

	// =========================================================================
	// 8. Component A: translates attribute name
	// =========================================================================

	public function test_component_a_translates_attribute_name(): void {
		$this->seed_source( 1, [ 'color' => $this->local_attr( 'Color', '' ) ] );
		TermNameTranslator::$stub_result = [ 'Color' => 'Colour' ];

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'en-gb' );

		$written = $this->written_product_attributes( 10 );
		$this->assertNotNull( $written, '_product_attributes must have been written.' );
		$this->assertSame( 'Colour', $written['color']['name'] );
	}

	// =========================================================================
	// 9. Component A: translates pipe-separated values (rejoined with ' | ')
	// =========================================================================

	public function test_component_a_translates_pipe_separated_values(): void {
		$this->seed_source( 1, [
			'color' => $this->local_attr( 'Color', 'Red|Blue|Green' ),
		] );
		TermNameTranslator::$stub_result = [
			'Color' => 'Color',
			'Red'   => 'Rojo',
			'Blue'  => 'Azul',
			'Green' => 'Verde',
		];

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$written = $this->written_product_attributes( 10 );
		$this->assertNotNull( $written );
		$this->assertSame( 'Rojo | Azul | Verde', $written['color']['value'] );
	}

	// =========================================================================
	// 10. Component A: falls back to source name when not in translation map
	// =========================================================================

	public function test_component_a_falls_back_to_source_name_when_missing(): void {
		$this->seed_source( 1, [ 'color' => $this->local_attr( 'Color', 'Red' ) ] );
		// 'Color' deliberately absent from stub — only 'Red' translated.
		TermNameTranslator::$stub_result = [ 'Red' => 'Rojo' ];

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$written = $this->written_product_attributes( 10 );
		$this->assertNotNull( $written );
		$this->assertSame( 'Color', $written['color']['name'], 'Source name must be kept when translation is missing.' );
	}

	// =========================================================================
	// 11. Component A: falls back to source value when not in translation map
	// =========================================================================

	public function test_component_a_falls_back_to_source_value_when_missing(): void {
		$this->seed_source( 1, [ 'color' => $this->local_attr( 'Color', 'Red|Blue' ) ] );
		// Only 'Red' translated; 'Blue' is not in map → kept as-is.
		TermNameTranslator::$stub_result = [ 'Color' => 'Color', 'Red' => 'Rojo' ];

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$written = $this->written_product_attributes( 10 );
		$this->assertNotNull( $written );
		// 'Blue' not in map → falls back to 'Blue'.
		$this->assertSame( 'Rojo | Blue', $written['color']['value'] );
	}

	// =========================================================================
	// 12. Component A: global (taxonomy) attributes pass through unchanged
	// =========================================================================

	public function test_component_a_global_taxonomy_attribute_passes_through(): void {
		$this->seed_source( 1, [
			'pa_size' => $this->taxonomy_attr( 'Size' ),
			'color'   => $this->local_attr( 'Color', 'Red' ),
		] );
		TermNameTranslator::$stub_result = [ 'Color' => 'Colour', 'Red' => 'Rojo' ];

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$written = $this->written_product_attributes( 10 );
		$this->assertNotNull( $written );

		// Global attribute must be preserved verbatim — name unchanged.
		$this->assertSame( 'Size', $written['pa_size']['name'] );
		$this->assertSame( 1, $written['pa_size']['is_taxonomy'] );
	}

	// =========================================================================
	// 13. Component A: correct batch submitted to translate_term_names()
	// =========================================================================

	public function test_component_a_submits_correct_batch_to_translator(): void {
		$this->seed_source( 1, [
			'color' => $this->local_attr( 'Color', 'Red|Blue' ),
		] );
		TermNameTranslator::$stub_result = [ 'Color' => 'Colour', 'Red' => 'Rojo', 'Blue' => 'Azul' ];

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$this->assertSame( 1, TermNameTranslator::$call_count );
		$pending = TermNameTranslator::$last_call['pending'];
		$this->assertSame( 'Color', $pending['color::name'] );
		$this->assertSame( 'Red',   $pending['color::v::0'] );
		$this->assertSame( 'Blue',  $pending['color::v::1'] );
		$this->assertSame( 'es',    TermNameTranslator::$last_call['target_lang'] );
		$this->assertSame( 1,       TermNameTranslator::$last_call['context_post_id'] );
	}

	// =========================================================================
	// 14. Component B: skips variation update when no local attr is is_variation
	// =========================================================================

	public function test_component_b_skips_when_no_variation_attribute(): void {
		$this->seed_source( 1, [
			'color' => $this->local_attr( 'Color', 'Red', false ), // is_variation = 0
		] );
		TermNameTranslator::$stub_result = [ 'Color' => 'Colour', 'Red' => 'Rojo' ];

		// Even if variations exist, they must not be touched.
		\LfWcMocks::$wpdb_get_col = [ '101' ];
		$this->make_post( 101, 'product_variation' );
		$this->set_meta( 101, 'attribute_color', 'Red' );

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		// _product_attributes IS written (Component A).
		$this->assertNotNull( $this->written_product_attributes( 10 ) );

		// attribute_color on variation 101 must NOT be updated.
		$this->assertFalse(
			$this->has_write( 101, 'attribute_color' ),
			'Variation meta must not be updated when the attribute is not used for variations.'
		);
	}

	// =========================================================================
	// 15. Component B: updates attribute_{key} meta on variation children
	// =========================================================================

	public function test_component_b_updates_variation_meta(): void {
		$this->seed_source( 1, [
			'color' => $this->local_attr( 'Color', 'Red', true ), // is_variation = 1
		] );
		TermNameTranslator::$stub_result = [ 'Color' => 'Color', 'Red' => 'Vermell' ];

		\LfWcMocks::$wpdb_get_col = [ '101', '102' ];
		$this->make_post( 101, 'product_variation' );
		$this->make_post( 102, 'product_variation' );
		$this->set_meta( 101, 'attribute_color', 'Red' );
		$this->set_meta( 102, 'attribute_color', 'Red' );

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$this->assertTrue( $this->has_write( 101, 'attribute_color', 'Vermell' ), 'Variation 101 must be updated to translated value.' );
		$this->assertTrue( $this->has_write( 102, 'attribute_color', 'Vermell' ), 'Variation 102 must be updated to translated value.' );
	}

	// =========================================================================
	// 16. Component B: empty stored value ("Any") is preserved
	// =========================================================================

	public function test_component_b_preserves_empty_any_value(): void {
		$this->seed_source( 1, [
			'color' => $this->local_attr( 'Color', 'Red', true ),
		] );
		TermNameTranslator::$stub_result = [ 'Color' => 'Color', 'Red' => 'Rojo' ];

		\LfWcMocks::$wpdb_get_col = [ '101' ];
		$this->make_post( 101, 'product_variation' );
		$this->set_meta( 101, 'attribute_color', '' ); // "Any" selection

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'es' );

		$this->assertFalse(
			$this->has_write( 101, 'attribute_color' ),
			'Variation with empty stored value ("Any") must not be overwritten.'
		);
	}

	// =========================================================================
	// 17. Component B: non-variation local attr is skipped in the variation loop
	// =========================================================================

	public function test_component_b_skips_non_variation_attr_in_loop(): void {
		$this->seed_source( 1, [
			'color'    => $this->local_attr( 'Color', 'Red', true ),  // variation
			'material' => $this->local_attr( 'Material', 'Cotton', false ), // not variation
		] );
		TermNameTranslator::$stub_result = [
			'Color'    => 'Color',
			'Red'      => 'Vermell',
			'Material' => 'Material',
			'Cotton'   => 'Cotó',
		];

		\LfWcMocks::$wpdb_get_col = [ '101' ];
		$this->make_post( 101, 'product_variation' );
		$this->set_meta( 101, 'attribute_color',    'Red' );
		$this->set_meta( 101, 'attribute_material', 'Cotton' );

		LocalAttributeTranslator::on_translation_complete( 10, 1, 'ca' );

		// attribute_color (variation attr) must be updated.
		$this->assertTrue( $this->has_write( 101, 'attribute_color', 'Vermell' ) );

		// attribute_material (non-variation attr) must NOT be updated.
		$this->assertFalse(
			$this->has_write( 101, 'attribute_material' ),
			'Non-variation local attribute must not be rewritten in variation children.'
		);
	}

	// =========================================================================
	// Write-log assertion helper
	// =========================================================================

	/**
	 * Returns true when write_log contains an update for the given post/key.
	 * If $expected_value is provided, also checks the written value.
	 *
	 * @param  int    $post_id        Post ID.
	 * @param  string $meta_key       Meta key.
	 * @param  mixed  $expected_value When non-null, the written value must match.
	 */
	private function has_write( int $post_id, string $meta_key, mixed $expected_value = null ): bool {
		foreach ( \LfWcMocks::$write_log as [ $action, $pid, $key, $value ] ) {
			if ( $pid === $post_id && $key === $meta_key ) {
				if ( null === $expected_value ) {
					return true;
				}
				if ( $value === $expected_value ) {
					return true;
				}
			}
		}
		return false;
	}
}
