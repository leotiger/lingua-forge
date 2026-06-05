<?php
/**
 * Integration tests for LinguaForge\Router\Rewrite\Manager::lang_permalink().
 *
 * lang_permalink() is the post_link / page_link filter callback. It resolves
 * the post's language from TridGroup, then rewrites the URL through the pure
 * static helper rewrite_lang_permalink() — already unit-tested in
 * RouterPureHelpersTest. These tests cover the WP-dependent early-exit paths
 * that require a real WordPress runtime.
 *
 * Coverage — §6.0.1 Low (class-manager.php, 38%):
 *   1. Source-language post → URL returned unchanged (lang === source_language guard).
 *   2. Non-existent post ID → URL returned unchanged (!$post instanceof WP_Post guard).
 *
 * Design notes:
 *   • Router::get_instance()->rewrite is the public Manager instance.
 *   • lang_permalink() is called directly rather than via get_permalink() so
 *     the test doesn't depend on post_link hook registration order.
 *   • WP_UnitTestCase transaction rollback handles cleanup.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class ManagerIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );
		update_option( 'linguaforge_routing_mode',     'path',            false );

		// Reset per-request Context caches.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'lf_languages_list' );
		parent::tearDown();
	}

	// =========================================================================
	// 1. Source-language post — URL returned unchanged
	// =========================================================================

	/**
	 * lang_permalink() must return the original URL unchanged when the post's
	 * language is the source language.
	 *
	 * The guard: `if (!$lang || $lang === $this->router->context->source_language()) return $url`.
	 */
	public function test_source_language_post_url_returned_unchanged(): void {
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$tg = Router::get_instance()->trid_group;
		$tg->set_lang( $post_id, self::SOURCE_LANG );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		$original_url = 'https://example.org/hello-world/';
		$result       = Router::get_instance()->rewrite->lang_permalink( $original_url, $post );

		$this->assertSame(
			$original_url,
			$result,
			'lang_permalink() must return the URL unchanged for a source-language post.'
		);
	}

	// =========================================================================
	// 2. Non-existent post ID — URL returned unchanged
	// =========================================================================

	/**
	 * lang_permalink() accepts a numeric post ID and resolves it via get_post().
	 * When the post does not exist, get_post() returns null and the method must
	 * return the original URL unchanged (!$post instanceof WP_Post guard).
	 */
	public function test_nonexistent_post_id_url_returned_unchanged(): void {
		$original_url = 'https://example.org/ghost-page/';

		// 999999 is a post ID that does not exist in the test DB.
		$result = Router::get_instance()->rewrite->lang_permalink( $original_url, 999999 );

		$this->assertSame(
			$original_url,
			$result,
			'lang_permalink() must return the URL unchanged when the post ID does not resolve to a WP_Post.'
		);
	}
}
