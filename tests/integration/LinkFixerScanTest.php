<?php
/**
 * Integration tests for LinkFixer::scan_post().
 *
 * scan_post() is the core scanner — it analyses a translated post's content for
 * internal links that don't point to the correct language version, partitions
 * them into fixes / stale_fixes / flagged, and returns a structured result.
 *
 * Dependencies: get_post(), get_permalink(), get_the_title(), home_url(),
 * TridGroup (reads _lf_trid / _lf_lang postmeta), Router context (routing_mode).
 * All are available in the wp-env test environment.
 *
 * Infrastructure:
 *   • Path-prefix routing mode with 'en' as the primary language.
 *   • target_prefix = home_url() + '/de/' (http://example.org/de/).
 *   • Posts are created via factory(); WP_UnitTestCase rolls back the DB per test.
 *   • Context caches are cleared in setUp() so option changes take effect.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\LinkFixer;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use WP_UnitTestCase;

final class LinkFixerScanTest extends WP_UnitTestCase {

	private LinkFixer $lf;
	private TridGroup $tg;

	/** Site home URL as returned by home_url() in this test environment. */
	private string $home;

	protected function setUp(): void {
		parent::setUp();

		$router   = Router::get_instance();
		$this->lf = $router->link_fixer;
		$this->tg = $router->trid_group;

		// Path-prefix mode with English as source language.
		update_option( 'linguaforge_routing_mode',     'path' );
		update_option( 'linguaforge_primary_language', 'en'   );

		// Clear Context instance-caches so the option changes above are re-read.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $router->context, null );
		}

		$this->home = \untrailingslashit( home_url() );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Create a linked pair: source (en) + translation (de) with shared TRID. */
	private function make_pair( string $source_slug = 'source-page', string $target_slug = 'target-page' ): array {

		$trid   = 'trid-' . uniqid( '', true );

		$en_id = (int) self::factory()->post->create( [
			'post_title'  => 'Source Page (EN)',
			'post_name'   => $source_slug,
			'post_status' => 'publish',
		] );
		$de_id = (int) self::factory()->post->create( [
			'post_title'  => 'Zielseite (DE)',
			'post_name'   => $target_slug,
			'post_status' => 'publish',
		] );

		$this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_lang( $de_id, 'de' );
		$this->tg->set_trid( $en_id, $trid );
		$this->tg->set_trid( $de_id, $trid );

		return [ 'en' => $en_id, 'de' => $de_id ];
	}

	/** Build post content with a single internal link using Gutenberg block markup. */
	private function make_content( int $data_id, string $href ): string {
		return '<!-- wp:paragraph --><p><a data-id="' . $data_id . '" href="' . $href . '">Link text</a></p><!-- /wp:paragraph -->';
	}

	// =========================================================================
	// post not found
	// =========================================================================

	public function test_scan_nonexistent_post_returns_empty_structure(): void {

		$result = $this->lf->scan_post( 99999, 'de' );

		$this->assertSame( 0,  $result['post_id'] );
		$this->assertSame( '', $result['title'] );
		$this->assertSame( [], $result['fixes'] );
		$this->assertSame( [], $result['stale_fixes'] );
		$this->assertSame( [], $result['flagged'] );
	}

	// =========================================================================
	// no internal links
	// =========================================================================

	public function test_scan_post_with_no_links_returns_all_empty(): void {

		$post_id = (int) self::factory()->post->create( [
			'post_content' => '<p>No links here.</p>',
			'post_status'  => 'publish',
		] );

		$result = $this->lf->scan_post( $post_id, 'de' );

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( [], $result['fixes'] );
		$this->assertSame( [], $result['stale_fixes'] );
		$this->assertSame( [], $result['flagged'] );
	}

	// =========================================================================
	// wrong-language link → fixes[]
	// =========================================================================

	public function test_scan_wrong_language_link_appears_in_fixes(): void {

		$pair    = $this->make_pair();
		$en_href = $this->home . '/en/source-page/'; // points to English version

		$post_id = (int) self::factory()->post->create( [
			'post_content' => $this->make_content( $pair['en'], $en_href ),
			'post_status'  => 'publish',
		] );

		$result = $this->lf->scan_post( $post_id, 'de' );

		$this->assertCount( 1, $result['fixes'] );
		$this->assertSame( [], $result['flagged'] );
		$this->assertSame( [], $result['stale_fixes'] );

		$fix = $result['fixes'][0];
		$this->assertSame( $en_href,        $fix['from'] );
		$this->assertSame( $pair['en'],     $fix['linked_post_id'] );
		$this->assertSame( $pair['de'],     $fix['target_post_id'] );
		$this->assertNotEmpty( $fix['to'] );
		$this->assertNotSame( $en_href,     $fix['to'] );
	}

	// =========================================================================
	// wrong-language link but no translation → flagged[] as no_translation
	// =========================================================================

	public function test_scan_link_with_no_translation_flagged_as_no_translation(): void {

		// Create an English-only post — no DE translation registered.
		$en_id = (int) self::factory()->post->create( [
			'post_name'   => 'en-only',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $en_id, 'trid-no-de-' . uniqid() );

		$en_href = $this->home . '/en/en-only/';
		$post_id = (int) self::factory()->post->create( [
			'post_content' => $this->make_content( $en_id, $en_href ),
			'post_status'  => 'publish',
		] );

		$result = $this->lf->scan_post( $post_id, 'de' );

		$this->assertSame( [], $result['fixes'] );
		$this->assertCount( 1, $result['flagged'] );

		$flag = $result['flagged'][0];
		$this->assertSame( 'no_translation', $flag['reason'] );
		$this->assertSame( $en_href,          $flag['url'] );
		$this->assertSame( $en_id,            $flag['linked_post_id'] );
	}

	// =========================================================================
	// data-id doesn't resolve → flagged[] as unresolved
	// =========================================================================

	public function test_scan_unresolvable_data_id_flagged_as_unresolved(): void {

		$ghost_id = 88888; // post that does not exist
		$href     = $this->home . '/en/ghost-page/';
		$post_id  = (int) self::factory()->post->create( [
			'post_content' => $this->make_content( $ghost_id, $href ),
			'post_status'  => 'publish',
		] );

		$result = $this->lf->scan_post( $post_id, 'de' );

		$this->assertSame( [], $result['fixes'] );
		$this->assertCount( 1, $result['flagged'] );
		$this->assertSame( 'unresolved', $result['flagged'][0]['reason'] );
		$this->assertSame( $href,         $result['flagged'][0]['url'] );
	}

	// =========================================================================
	// link already in target language → not in fixes (may be stale_fixes or clean)
	// =========================================================================

	public function test_scan_correct_language_link_not_in_fixes(): void {

		$pair    = $this->make_pair( 'de-source', 'de-target' );
		$de_href = $this->home . '/de/de-target/'; // already in target language

		$post_id = (int) self::factory()->post->create( [
			'post_content' => $this->make_content( $pair['de'], $de_href ),
			'post_status'  => 'publish',
		] );

		$result = $this->lf->scan_post( $post_id, 'de' );

		$this->assertSame( [], $result['fixes'], 'Correct-language link must not appear in fixes.' );
	}

	// =========================================================================
	// return shape completeness
	// =========================================================================

	public function test_scan_result_always_contains_all_keys(): void {

		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$result  = $this->lf->scan_post( $post_id, 'de' );

		$this->assertArrayHasKey( 'post_id',     $result );
		$this->assertArrayHasKey( 'title',       $result );
		$this->assertArrayHasKey( 'fixes',       $result );
		$this->assertArrayHasKey( 'stale_fixes', $result );
		$this->assertArrayHasKey( 'flagged',     $result );
	}
}
