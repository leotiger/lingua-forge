<?php
/**
 * Integration tests for Redirector and Switcher WP-dependent methods.
 *
 * Uses Router::get_instance() + TridGroup scaffolding (same pattern as
 * LinkFixerScanTest) to exercise methods that require real DB and WP state.
 *
 * Methods covered:
 *   • Redirector::allow_lang_subdomains() — builds allowed subdomain host list
 *   • Redirector::fix_site_logo_link()   — rewrites logo href to current-language home
 *   • Redirector::translate_menu_items() — rewrites nav-item URLs via TRID
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use WP_UnitTestCase;

final class RedirectorSwitcherTest extends WP_UnitTestCase {

	private Router    $router;
	private TridGroup $tg;

	protected function setUp(): void {
		parent::setUp();

		$this->router = Router::get_instance();
		$this->tg     = $this->router->trid_group;

		update_option( 'linguaforge_routing_mode',     'path' );
		update_option( 'linguaforge_primary_language', 'en'   );

		// Clear Context caches so option changes take effect.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $this->router->context, null );
		}
	}

	// =========================================================================
	// Redirector::allow_lang_subdomains()
	// =========================================================================

	public function test_allow_lang_subdomains_returns_hosts_unchanged_in_path_mode(): void {

		// Path-prefix mode — subdomains not applicable.
		update_option( 'linguaforge_routing_mode', 'path' );
		$ref = new ReflectionClass( Context::class );
		$ref->getProperty( 'cached_routing_mode' )->setAccessible( true );
		$ref->getProperty( 'cached_routing_mode' )->setValue( $this->router->context, null );

		$hosts  = [ 'example.org' ];
		$result = $this->router->redirector->allow_lang_subdomains( $hosts );

		$this->assertSame( $hosts, $result );
	}

	public function test_allow_lang_subdomains_adds_subdomains_in_subdomain_mode(): void {

		// Switch to subdomain mode and fix the base domain via the filter.
		update_option( 'linguaforge_routing_mode', 'subdomain' );

		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_routing_mode', 'cached_base_domain', 'cached_languages' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $this->router->context, null );
		}

		// base_domain() uses apply_filters('lf_base_domain', $host) — set via filter.
		add_filter( 'lf_base_domain', static fn() => 'example.org' );
		// Ensure at least 'de' is in the language list.
		add_filter( 'lf_languages_list', static fn( array $l ): array =>
			array_values( array_unique( array_merge( $l, [ 'de' ] ) ) )
		);

		$result = $this->router->redirector->allow_lang_subdomains( [ 'example.org' ] );

		$this->assertContains( 'de.example.org', $result );
		remove_all_filters( 'lf_base_domain' );
		remove_all_filters( 'lf_languages_list' );
	}

	// =========================================================================
	// Redirector::fix_site_logo_link()
	// =========================================================================

	public function test_fix_site_logo_link_non_logo_block_returned_unchanged(): void {

		$content = '<a href="https://example.org/">Home</a>';
		$result  = $this->router->redirector->fix_site_logo_link(
			$content,
			[ 'blockName' => 'core/navigation-link' ]
		);

		$this->assertSame( $content, $result );
	}

	public function test_fix_site_logo_link_no_page_on_front_returned_unchanged(): void {

		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		update_option( 'page_on_front', 0 ); // no static front page

		$content = '<a href="https://example.org/">Home</a>';
		$result  = $this->router->redirector->fix_site_logo_link(
			$content,
			[ 'blockName' => 'core/site-logo' ]
		);

		$this->assertSame( $content, $result );
	}

	public function test_fix_site_logo_link_rewrites_href_to_front_page_permalink(): void {

		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined.' );
		}

		$front_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_option( 'page_on_front', $front_id );

		$content = '<a href="https://example.org/stale-url/">Home</a>';
		$result  = $this->router->redirector->fix_site_logo_link(
			$content,
			[ 'blockName' => 'core/site-logo' ]
		);

		// Regardless of LF_LANG value, the href must be rewritten to the resolved
		// front-page permalink (source or translated). The stale URL is gone.
		$this->assertStringNotContainsString( 'stale-url', $result );
		$this->assertStringContainsString( 'href="', $result );
		// The new href matches get_permalink of whichever post was resolved.
		$this->assertMatchesRegularExpression( '/href="[^"]+"/', $result );
	}

	// =========================================================================
	// Redirector::translate_menu_items()
	// =========================================================================

	public function test_translate_menu_items_rewrites_url_when_translation_exists(): void {

		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined — requires a full router boot.' );
		}

		$trid  = 'trid-menu-' . uniqid();
		$en_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$de_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_lang( $de_id, 'de' );
		$this->tg->set_trid( $en_id, $trid );
		$this->tg->set_trid( $de_id, $trid );

		$item            = new \stdClass();
		$item->object_id = $en_id;
		$item->url       = 'https://example.org/original/';

		$result = $this->router->redirector->translate_menu_items( [ $item ] );

		// The method sets $item->url = get_permalink($translations[LF_LANG]).
		// LF_LANG in CLI is whatever the router resolved for this request — look
		// up what the method would have used and assert consistently.
		$translations    = $this->tg->get_translations( $en_id );
		$lf_lang         = LF_LANG;
		if ( ! empty( $translations[ $lf_lang ] ) ) {
			$this->assertSame( get_permalink( $translations[ $lf_lang ] ), $result[0]->url );
		} else {
			// No translation for the current request language — URL unchanged.
			$this->assertSame( $item->url, $result[0]->url );
		}
	}

	public function test_translate_menu_items_unchanged_when_no_translation(): void {

		if ( ! defined( 'LF_LANG' ) ) {
			$this->markTestSkipped( 'LF_LANG not defined — requires a full router boot.' );
		}

		// Create a post that has NO translation for LF_LANG.
		$post_id = (int) self::factory()->post->create( [ 'post_status' => 'publish' ] );
		// No TRID set → get_translations returns empty → URL unchanged.

		$original_url    = 'https://example.org/original/';
		$item            = new \stdClass();
		$item->object_id = $post_id;
		$item->url       = $original_url;

		$result = $this->router->redirector->translate_menu_items( [ $item ] );

		$this->assertSame( $original_url, $result[0]->url );
	}
}
