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
	// 0. register_rewrite_rules() must run after CPT registration
	// =========================================================================

	/**
	 * register_rewrite_rules() must be hooked at 'init' priority > 10.
	 *
	 * It calls get_post_types() (via add_cpt_archive_rewrite_rules() /
	 * add_cpt_single_rewrite_rules()) to enumerate every registered CPT with a
	 * custom rewrite slug. Themes and plugins register their own post types on
	 * 'init' at the default priority (10); if this hook also ran at priority 10,
	 * whether a given CPT is visible yet depends on unpredictable same-priority
	 * callback ordering. Confirmed live on an Agnosis-family site: a CPT
	 * ("art") registered on a later same-priority 'init' callback was invisible
	 * to get_post_types() here, so its language-prefixed single-post rewrite
	 * rule was silently never added — no rewrite-rules flush fixes that, since
	 * the rule was never queued for one in the first place.
	 */
	public function test_register_rewrite_rules_hooked_after_default_init_priority(): void {
		$priority = has_action( 'init', [ Router::get_instance()->rewrite, 'register_rewrite_rules' ] );

		$this->assertNotFalse( $priority, 'register_rewrite_rules() must be hooked on init.' );
		$this->assertGreaterThan( 10, $priority,
			'register_rewrite_rules() must run after the default init priority (10) so CPTs registered by other plugins/themes are already visible to get_post_types().' );
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

	// =========================================================================
	// 3. get_permalink() for a custom post type must be language-prefixed
	// =========================================================================

	/**
	 * WordPress's get_permalink() never applies the post_link/page_link filters
	 * for a CUSTOM post type — get_post_permalink() applies post_type_link
	 * instead (post_link only fires for the built-in 'post' type; page_link
	 * only for 'page'). Confirmed live on an Agnosis-family site: the Language
	 * Switcher calls get_permalink() to build each language's link, and every
	 * "art" CPT link rendered without its language prefix because
	 * register_hooks() only hooked post_link/page_link. This test exercises
	 * get_permalink() itself (not lang_permalink() directly) so it fails if the
	 * post_type_link hook is ever removed.
	 */
	public function test_get_permalink_is_language_prefixed_for_custom_post_type(): void {
		register_post_type( 'lf_test_cpt', [
			'public'  => true,
			'rewrite' => [ 'slug' => 'widget' ],
		] );

		$post_id = (int) self::factory()->post->create( [
			'post_type'   => 'lf_test_cpt',
			'post_status' => 'publish',
			'post_name'   => 'a-widget',
		] );

		Router::get_instance()->trid_group->set_lang( $post_id, 'es' );

		$permalink = get_permalink( $post_id );

		$this->assertStringContainsString( '/es/', $permalink,
			'get_permalink() for a translated custom-post-type post must be language-prefixed — this is what the Language Switcher relies on.' );

		unregister_post_type( 'lf_test_cpt' );
	}

	/**
	 * WooCommerce products intentionally stay on a single, language-neutral
	 * permalink for every translation (see lang_permalink_excluded_post_types()'s
	 * docblock) — the post_type_link hook must not change that.
	 */
	public function test_get_permalink_unprefixed_for_excluded_post_type(): void {
		// 'product' may already be registered by an active WooCommerce install in
		// this test environment — only register (and later unregister) it here
		// when it isn't, so this test works either way.
		$registered_here = ! post_type_exists( 'product' );
		if ( $registered_here ) {
			register_post_type( 'product', [
				'public'  => true,
				'rewrite' => [ 'slug' => 'product' ],
			] );
		}

		$post_id = (int) self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_name'   => 'a-product',
		] );

		Router::get_instance()->trid_group->set_lang( $post_id, 'es' );

		$permalink = get_permalink( $post_id );

		$this->assertStringNotContainsString( '/es/', $permalink,
			'WooCommerce product permalinks must remain language-neutral even when translated.' );

		if ( $registered_here ) {
			unregister_post_type( 'product' );
		}
	}
}
