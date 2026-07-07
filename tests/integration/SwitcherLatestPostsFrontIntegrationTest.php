<?php
/**
 * Integration test for LinguaForge\Router\Switcher::get_languages() on a
 * "Your latest posts" front page.
 *
 * Regression coverage for a bug found on a real site: when Settings → Reading
 * → "Your latest posts" is the front page, WordPress's own WP::register_globals()
 * still points the global $post at the first row of the main post_type=post
 * query — even when the active theme's front-page/home template never loops
 * over it (e.g. a block theme rendering a custom block instead of a Query
 * Loop). get_the_ID() then returns that row's ID despite is_singular() being
 * false, so a naive `get_the_ID() ?: null` wrongly sends a non-singular
 * request down the "singular post" branch of get_languages(). If that post
 * happens to have no translation group (e.g. WordPress's own default "Hello
 * world!" sample post, or any other untranslated post-type=post row that is
 * simply the newest post — this is in no way WooCommerce-specific and applies
 * equally to a site using only custom post types for its real content, such
 * as Agnosis's `agnosis_artwork`), get_languages() returns [] and the
 * switcher silently renders nothing.
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

final class SwitcherLatestPostsFrontIntegrationTest extends WP_UnitTestCase {

	private Router $router;

	protected function setUp(): void {
		parent::setUp();

		$this->router = Router::get_instance();

		update_option( 'linguaforge_routing_mode',     'path' );
		update_option( 'linguaforge_primary_language', 'en'   );
		// "Your latest posts" front page — no static page assigned.
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );

		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $this->router->context, null );
		}
	}

	/**
	 * Reproduces the exact production scenario: an untranslated post-type=post
	 * row exists (own translation group of one, no _lf_trid siblings) and is
	 * the newest — and therefore the one WordPress's main query resolves as
	 * $wp_query->post — while the front page is "Your latest posts".
	 *
	 * Before the fix: get_the_ID() returned this post's ID even though
	 * is_singular() is false, get_translations() found nothing for it, and
	 * get_languages() returned []. After the fix: the non-singular branch is
	 * used regardless of what the main query's post happens to be, so the
	 * switcher still renders one entry per active language.
	 */
	public function test_get_languages_not_empty_on_latest_posts_front_with_untranslated_post(): void {

		// An ordinary, untranslated post — no _lf_trid, no siblings. Mirrors
		// WordPress's own default "Hello world!" sample post on a fresh install.
		self::factory()->post->create( [
			'post_status' => 'publish',
			'post_title'  => 'Hello world!',
		] );

		// go_to() runs the full main-query bootstrap (WP::main() equivalent),
		// including register_globals() — the exact mechanism that populated
		// $GLOBALS['post'] from the untranslated post in production.
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_singular(), 'Precondition: front page must be non-singular.' );

		$langs = $this->router->switcher->get_languages();

		$this->assertNotEmpty(
			$langs,
			'get_languages() must not be emptied by an unrelated, untranslated post-type=post row picked up via the main query on a non-singular "Your latest posts" front page.'
		);

		$codes = array_column( $langs, 'code' );
		$this->assertContains( 'en', $codes );
	}

	/**
	 * Same scenario, but the untranslated post is a custom post type
	 * (agnosis_artwork-style CPT) rather than 'post' — confirming the fix is
	 * general-purpose and not specific to any one post type or to WooCommerce
	 * (whose shop-page override is a separate, additional code path).
	 */
	public function test_get_languages_not_empty_on_latest_posts_front_with_untranslated_cpt(): void {

		register_post_type( 'lf_test_cpt', [ 'public' => true ] );

		self::factory()->post->create( [
			'post_type'   => 'lf_test_cpt',
			'post_status' => 'publish',
		] );

		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_singular() );

		$langs = $this->router->switcher->get_languages();

		$this->assertNotEmpty( $langs );

		unregister_post_type( 'lf_test_cpt' );
	}
}
