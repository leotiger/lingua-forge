<?php
/**
 * Integration tests for LinguaForge\Router\FeaturedImageFixer.
 *
 * scan_post() compares a translated post's featured image against its
 * source-language sibling in the same TRID group; fix_post() copies it across.
 * Covered here: no source translation, source with no thumbnail, target
 * already in sync, target missing/mismatched thumbnail, and fix_post()
 * actually applying the copy via set_post_thumbnail().
 *
 * Dependencies: get_post(), get_post_thumbnail_id(), set_post_thumbnail(),
 * TridGroup (reads/writes _lf_trid / _lf_lang postmeta), Router context
 * (source_language()). All available in the wp-env test environment.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\FeaturedImageFixer;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use WP_UnitTestCase;

final class FeaturedImageFixerTest extends WP_UnitTestCase {

	private FeaturedImageFixer $fixer;
	private TridGroup $tg;

	protected function setUp(): void {
		parent::setUp();

		$router      = Router::get_instance();
		$this->fixer = $router->featured_image_fixer;
		$this->tg    = $router->trid_group;

		update_option( 'linguaforge_routing_mode',     'path' );
		update_option( 'linguaforge_primary_language', 'en' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Create a linked pair: source (en) + translation (de) with shared TRID. */
	private function make_pair(): array {
		$trid = 'trid-' . uniqid( '', true );

		$en_id = (int) self::factory()->post->create( [
			'post_title'  => 'Source Post (EN)',
			'post_status' => 'publish',
		] );
		$de_id = (int) self::factory()->post->create( [
			'post_title'  => 'Zielseite (DE)',
			'post_status' => 'publish',
		] );

		$this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_lang( $de_id, 'de' );
		$this->tg->set_trid( $en_id, $trid );
		$this->tg->set_trid( $de_id, $trid );

		return [ 'en' => $en_id, 'de' => $de_id ];
	}

	/**
	 * Create an attachment and register it as a post's featured image.
	 *
	 * Writes `_thumbnail_id` directly via update_post_meta() rather than calling
	 * WordPress's set_post_thumbnail(), which additionally requires
	 * wp_get_attachment_image() to successfully render the attachment — a bare
	 * factory attachment (no uploaded file, no `_wp_attachment_metadata`) fails
	 * that check, so set_post_thumbnail() would silently no-op instead of setting
	 * anything. The code under test (scan_post()/fix_post()) only reads/writes
	 * the `_thumbnail_id` meta value itself, so this matches production exactly.
	 */
	private function attach_thumbnail( int $post_id ): int {
		$attachment_id = (int) self::factory()->attachment->create( [
			'post_parent' => $post_id,
		] );
		update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		return $attachment_id;
	}

	// =========================================================================
	// scan_post()
	// =========================================================================

	public function test_scan_returns_null_for_source_language(): void {
		$pair = $this->make_pair();
		$this->attach_thumbnail( $pair['en'] );

		$this->assertNull( $this->fixer->scan_post( $pair['en'], 'en' ) );
	}

	public function test_scan_returns_null_when_no_source_translation_exists(): void {
		$de_only = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->tg->set_lang( $de_only, 'de' );
		$this->tg->set_trid( $de_only, 'trid-de-only-' . uniqid() );

		$this->assertNull( $this->fixer->scan_post( $de_only, 'de' ) );
	}

	public function test_scan_returns_null_when_source_has_no_thumbnail(): void {
		$pair = $this->make_pair();
		// Neither post has a thumbnail — nothing to copy.
		$this->assertNull( $this->fixer->scan_post( $pair['de'], 'de' ) );
	}

	public function test_scan_returns_null_when_already_in_sync(): void {
		$pair          = $this->make_pair();
		$attachment_id = $this->attach_thumbnail( $pair['en'] );
		update_post_meta( $pair['de'], '_thumbnail_id', $attachment_id );

		$this->assertNull( $this->fixer->scan_post( $pair['de'], 'de' ) );
	}

	public function test_scan_returns_fixable_when_target_has_no_thumbnail(): void {
		$pair          = $this->make_pair();
		$attachment_id = $this->attach_thumbnail( $pair['en'] );

		$scan = $this->fixer->scan_post( $pair['de'], 'de' );

		$this->assertNotNull( $scan );
		$this->assertSame( $pair['de'], $scan['post_id'] );
		$this->assertSame( 0, $scan['current_id'] );
		$this->assertSame( $pair['en'], $scan['source_id'] );
		$this->assertSame( $attachment_id, $scan['source_thumb'] );
	}

	public function test_scan_returns_fixable_when_target_thumbnail_differs(): void {
		$pair              = $this->make_pair();
		$source_attachment = $this->attach_thumbnail( $pair['en'] );
		$stale_attachment  = $this->attach_thumbnail( $pair['de'] );

		$scan = $this->fixer->scan_post( $pair['de'], 'de' );

		$this->assertNotNull( $scan );
		$this->assertSame( $stale_attachment,  $scan['current_id'] );
		$this->assertSame( $source_attachment, $scan['source_thumb'] );
	}

	// =========================================================================
	// fix_post()
	// =========================================================================

	public function test_fix_post_copies_source_thumbnail_onto_target(): void {
		$pair          = $this->make_pair();
		$attachment_id = $this->attach_thumbnail( $pair['en'] );

		$result = $this->fixer->fix_post( $pair['de'], 'de' );

		$this->assertTrue( $result['applied'] );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $pair['de'] ) );
	}

	public function test_fix_post_returns_not_applied_when_nothing_to_fix(): void {
		$pair          = $this->make_pair();
		$attachment_id = $this->attach_thumbnail( $pair['en'] );
		update_post_meta( $pair['de'], '_thumbnail_id', $attachment_id ); // already in sync

		$result = $this->fixer->fix_post( $pair['de'], 'de' );

		$this->assertFalse( $result['applied'] );
	}

	public function test_fix_post_returns_not_applied_when_source_has_no_thumbnail(): void {
		$pair   = $this->make_pair();
		$result = $this->fixer->fix_post( $pair['de'], 'de' );

		$this->assertFalse( $result['applied'] );
	}
}
