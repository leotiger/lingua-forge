<?php
/**
 * Integration tests for the `linguaforge_translated_post_meta` filter
 * (Agnosis compatibility audit §3b).
 *
 * Exercises TranslationTrigger::create_translated_post() inside a real
 * WordPress runtime. The private method is invoked directly via Reflection with
 * a fabricated translation result, so the test isolates the meta-propagation
 * behaviour without needing an AI provider or an active-language configuration.
 *
 * Verifies:
 *   • meta returned by the filter is written to the new translated post
 *     (born with it, via wp_insert_post meta_input);
 *   • the filter receives the source id, target language, and source post type;
 *   • LF's own group keys (_lf_trid, _lf_lang) cannot be overridden through the
 *     filter — they keep their authoritative values;
 *   • with no filter registered, behaviour is unchanged (no stray meta);
 *   • the source post's featured image (`_thumbnail_id`) is copied onto the
 *     new translation automatically when the source has one, skipped when it
 *     doesn't, and left alone when the `linguaforge_translated_post_meta`
 *     filter already supplied an explicit `_thumbnail_id`.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Features\TranslationTrigger;
use ReflectionMethod;
use WP_UnitTestCase;

final class TranslationTriggerMetaFilterIntegrationTest extends WP_UnitTestCase {

	private int $source_id;

	/** Captured filter arguments for assertion. */
	private array $captured_args = [];

	protected function setUp(): void {
		parent::setUp();

		$this->source_id = (int) self::factory()->post->create( [
			'post_title'   => 'Hello World',
			'post_content' => '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		] );

		$this->captured_args = [];
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_translated_post_meta' );
		parent::tearDown();
	}

	/**
	 * Invoke the private create_translated_post() with a fabricated AI result.
	 *
	 * @param array<string,mixed> $result Stand-in for Translation::run() output.
	 * @return int|\WP_Error
	 */
	private function create_translation( array $result = [] ) {
		$source = get_post( $this->source_id );

		$method = new ReflectionMethod( TranslationTrigger::class, 'create_translated_post' );
		$method->setAccessible( true );

		return $method->invoke(
			null,
			$source,
			'es',
			$result + [ 'output' => '<p>Hola mundo</p>', 'translated_title' => 'Hola Mundo' ],
			[]
		);
	}

	public function test_translated_excerpt_is_written_at_creation(): void {
		$new_id = $this->create_translation( [ 'translated_excerpt' => 'Resumen traducido' ] );

		$this->assertIsInt( $new_id );
		$this->assertSame( 'Resumen traducido', get_post( $new_id )->post_excerpt );
	}

	public function test_no_excerpt_written_when_payload_lacks_one(): void {
		// $result has no translated_excerpt key → post_excerpt stays empty (not "").
		$new_id = $this->create_translation();

		$this->assertIsInt( $new_id );
		$this->assertSame( '', get_post( $new_id )->post_excerpt );
	}

	public function test_filtered_meta_is_written_to_translated_post(): void {
		add_filter(
			'linguaforge_translated_post_meta',
			static function ( array $meta ): array {
				$meta['_thumbnail_id']   = 4242;
				$meta['_agnosis_medium'] = 'oil on canvas';
				return $meta;
			}
		);

		$new_id = $this->create_translation();

		$this->assertIsInt( $new_id );
		$this->assertSame( '4242', (string) get_post_meta( $new_id, '_thumbnail_id', true ) );
		$this->assertSame( 'oil on canvas', get_post_meta( $new_id, '_agnosis_medium', true ) );
	}

	public function test_filter_receives_source_id_lang_and_post_type(): void {
		add_filter(
			'linguaforge_translated_post_meta',
			function ( array $meta, int $source_id, string $target_lang, string $source_post_type ): array {
				$this->captured_args = [ $source_id, $target_lang, $source_post_type ];
				return $meta;
			},
			10,
			4
		);

		$this->create_translation();

		$this->assertSame( [ $this->source_id, 'es', 'post' ], $this->captured_args );
	}

	public function test_filter_cannot_override_lf_group_keys(): void {
		add_filter(
			'linguaforge_translated_post_meta',
			static function ( array $meta ): array {
				$meta['_lf_trid'] = 'hijacked-trid';
				$meta['_lf_lang'] = 'zz';
				return $meta;
			}
		);

		$new_id = $this->create_translation();

		// _lf_lang must be the real target language, not the filtered value.
		$this->assertSame( 'es', get_post_meta( $new_id, '_lf_lang', true ) );

		// _lf_trid must match the source's TRID group, not the filtered value.
		$this->assertNotSame( 'hijacked-trid', get_post_meta( $new_id, '_lf_trid', true ) );
		$this->assertSame(
			get_post_meta( $this->source_id, '_lf_trid', true ),
			get_post_meta( $new_id, '_lf_trid', true )
		);
	}

	public function test_no_filter_leaves_no_stray_meta(): void {
		$new_id = $this->create_translation();

		$this->assertIsInt( $new_id );
		$this->assertSame( '', (string) get_post_meta( $new_id, '_thumbnail_id', true ) );
		$this->assertSame( '', (string) get_post_meta( $new_id, '_agnosis_medium', true ) );
	}

	// =========================================================================
	// Automatic featured-image copy
	// =========================================================================

	public function test_source_thumbnail_is_copied_to_translated_post(): void {
		$attachment_id = (int) self::factory()->attachment->create( [ 'post_parent' => $this->source_id ] );
		// A raw meta write, not set_post_thumbnail(): the latter additionally
		// requires wp_get_attachment_image() to render the attachment, which a
		// bare factory attachment (no real file/generated sizes) fails, so
		// set_post_thumbnail() would silently no-op. create_translated_post()
		// only reads the _thumbnail_id meta value via get_post_thumbnail_id().
		update_post_meta( $this->source_id, '_thumbnail_id', $attachment_id );

		$new_id = $this->create_translation();

		$this->assertIsInt( $new_id );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $new_id ) );
	}

	public function test_no_thumbnail_copied_when_source_has_none(): void {
		$new_id = $this->create_translation();

		$this->assertIsInt( $new_id );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $new_id ) );
	}

	public function test_filter_supplied_thumbnail_takes_precedence_over_source(): void {
		$source_attachment = (int) self::factory()->attachment->create( [ 'post_parent' => $this->source_id ] );
		update_post_meta( $this->source_id, '_thumbnail_id', $source_attachment );

		$override_attachment = (int) self::factory()->attachment->create();

		add_filter(
			'linguaforge_translated_post_meta',
			static function ( array $meta ) use ( $override_attachment ): array {
				$meta['_thumbnail_id'] = $override_attachment;
				return $meta;
			}
		);

		$new_id = $this->create_translation();

		$this->assertIsInt( $new_id );
		$this->assertSame( $override_attachment, (int) get_post_thumbnail_id( $new_id ) );
	}
}
