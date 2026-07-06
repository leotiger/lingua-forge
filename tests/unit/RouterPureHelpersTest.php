<?php
/**
 * Unit tests for pure static helpers extracted from language-router classes.
 *
 * Methods covered:
 *   • Manager::rewrite_lang_permalink()   — URL path rewriting for the post-permalink
 *                                           filter (path-prefix and subdomain modes)
 *   • Switcher::build_translated_url()    — full translated-URL builder with 5 branches
 *                                           (search, singular, source-lang, subdomain,
 *                                           path-prefix)
 *
 * All dependencies (routing_mode, home_url, lang_base_url, WP query state) are passed
 * as plain parameters — no Router, no WP runtime needed.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Rewrite\Manager;
use LinguaForge\Router\Switcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/language-router/includes/rewrite/class-manager.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-lsflr-switcher.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\Router\Rewrite\Manager::rewrite_lang_permalink
 * @covers \LinguaForge\Router\Switcher::build_translated_url
 */
final class RouterPureHelpersTest extends TestCase {

	private const HOME     = 'https://example.org';
	private const LANGS    = [ 'en', 'de', 'ca' ];
	private const LANG_BASE = 'https://de.example.org/';

	// =========================================================================
	// Manager::rewrite_lang_permalink()
	// =========================================================================

	public function test_rewrite_permalink_path_mode_prepends_lang_prefix(): void {

		$result = Manager::rewrite_lang_permalink(
			'/about/',
			'de',
			self::LANGS,
			'path',
			self::LANG_BASE,
			self::HOME
		);

		$this->assertSame( 'https://example.org/de/about/', $result );
	}

	public function test_rewrite_permalink_path_mode_strips_stale_prefix_and_prepends(): void {

		$result = Manager::rewrite_lang_permalink(
			'/en/about/',  // has old lang prefix
			'de',
			self::LANGS,
			'path',
			self::LANG_BASE,
			self::HOME
		);

		$this->assertSame( 'https://example.org/de/about/', $result );
	}

	public function test_rewrite_permalink_subdomain_mode_replaces_host(): void {

		$result = Manager::rewrite_lang_permalink(
			'/about/',
			'de',
			self::LANGS,
			'subdomain',
			self::LANG_BASE,
			self::HOME
		);

		$this->assertSame( 'https://de.example.org/about/', $result );
	}

	public function test_rewrite_permalink_subdomain_strips_stale_path_prefix(): void {

		$result = Manager::rewrite_lang_permalink(
			'/en/about/',
			'de',
			self::LANGS,
			'subdomain',
			self::LANG_BASE,
			self::HOME
		);

		$this->assertSame( 'https://de.example.org/about/', $result );
	}

	public function test_rewrite_permalink_root_path(): void {

		$result = Manager::rewrite_lang_permalink(
			'/',
			'de',
			self::LANGS,
			'path',
			self::LANG_BASE,
			self::HOME
		);

		$this->assertSame( 'https://example.org/de//', $result );
	}

	// =========================================================================
	// Switcher::build_translated_url() — path-prefix mode
	// =========================================================================

	public function test_build_url_path_mode_switches_lang_prefix(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/en/page/',
			'de', 'en', self::LANGS, 'path',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertSame( 'https://example.org/de/page/', $result );
	}

	public function test_build_url_path_mode_to_source_lang_strips_prefix(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/de/seite/',
			'en', 'en', self::LANGS, 'path',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertSame( 'https://example.org/seite/', $result );
	}

	public function test_build_url_path_mode_preserves_query_string(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/en/page/?foo=bar',
			'de', 'en', self::LANGS, 'path',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertStringContainsString( '?foo=bar', $result );
		$this->assertStringContainsString( '/de/', $result );
	}

	// =========================================================================
	// Switcher::build_translated_url() — subdomain mode
	// =========================================================================

	public function test_build_url_subdomain_mode_uses_lang_base_url(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/en/page/',
			'de', 'en', self::LANGS, 'subdomain',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertStringStartsWith( self::LANG_BASE, $result );
		$this->assertStringContainsString( 'page/', $result );
	}

	// =========================================================================
	// Switcher::build_translated_url() — search results
	// =========================================================================

	public function test_build_url_search_path_mode(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/en/?s=hello',
			'de', 'en', self::LANGS, 'path',
			true, 'hello', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertStringContainsString( 's=hello', $result );
		$this->assertStringContainsString( 'lang=de', $result );
	}

	public function test_build_url_search_subdomain_mode(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/en/?s=hello',
			'de', 'en', self::LANGS, 'subdomain',
			true, 'hello', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertStringStartsWith( self::LANG_BASE, $result );
		$this->assertStringContainsString( 's=hello', $result );
	}

	// =========================================================================
	// Switcher::build_translated_url() — singular page
	// =========================================================================

	public function test_build_url_singular_uses_permalink(): void {

		$permalink = 'https://example.org/de/meine-seite/';

		$result = Switcher::build_translated_url(
			self::HOME . '/en/my-page/',
			'de', 'en', self::LANGS, 'path',
			false, '', true, $permalink,
			self::LANG_BASE, self::HOME
		);

		$this->assertSame( $permalink, $result );
	}

	// =========================================================================
	// Switcher::build_translated_url() — non-singular homepage (empty $new_path)
	//
	// Regression coverage: with a "Your latest posts" front page, is_singular()
	// is false on the homepage itself, so these branches (previously only
	// reachable with a non-empty sub-path, e.g. an archive) are now reached
	// with $new_path === ''. The old implementation produced a double slash
	// (".../de//") in that case.
	// =========================================================================

	public function test_build_url_homepage_path_mode_no_double_slash(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/en/',
			'de', 'en', self::LANGS, 'path',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertSame( 'https://example.org/de/', $result );
	}

	public function test_build_url_homepage_path_mode_to_source_lang_no_double_slash(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/de/',
			'en', 'en', self::LANGS, 'path',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertSame( 'https://example.org/', $result );
	}

	public function test_build_url_bare_root_path_mode_no_double_slash(): void {

		$result = Switcher::build_translated_url(
			self::HOME . '/',
			'de', 'en', self::LANGS, 'path',
			false, '', false, '',
			self::LANG_BASE, self::HOME
		);

		$this->assertSame( 'https://example.org/de/', $result );
	}
}
