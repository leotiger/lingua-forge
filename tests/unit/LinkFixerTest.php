<?php
/**
 * Unit tests for the three public static pure helpers on LinkFixer.
 *
 * These methods have zero WP dependencies once $home is passed as a parameter
 * instead of being resolved via home_url() inside the method.
 *
 * Methods covered:
 *   • LinkFixer::alt_scheme()            — http↔https URL scheme swap
 *   • LinkFixer::extract_internal_links() — regex extraction + URL normalisation
 *   • LinkFixer::fix_data_id_attr()      — data-id attribute rewrite in HTML
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\LinkFixer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-lsflr-link-fixer.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\Router\LinkFixer
 */
final class LinkFixerTest extends TestCase {

	private const HOME = 'https://example.org';

	// =========================================================================
	// alt_scheme()
	// =========================================================================

	public function test_alt_scheme_https_returns_http(): void {
		$this->assertSame( 'http://example.org', LinkFixer::alt_scheme( 'https://example.org' ) );
	}

	public function test_alt_scheme_http_returns_https(): void {
		$this->assertSame( 'https://example.org', LinkFixer::alt_scheme( 'http://example.org' ) );
	}

	public function test_alt_scheme_non_http_returns_null(): void {
		$this->assertNull( LinkFixer::alt_scheme( 'ftp://example.org' ) );
		$this->assertNull( LinkFixer::alt_scheme( '/relative/path' ) );
		$this->assertNull( LinkFixer::alt_scheme( '' ) );
	}

	// =========================================================================
	// extract_internal_links()
	// =========================================================================

	public function test_extract_empty_content_returns_empty(): void {
		$this->assertSame( [], LinkFixer::extract_internal_links( '', self::HOME ) );
	}

	public function test_extract_content_with_no_links_returns_empty(): void {
		$this->assertSame( [], LinkFixer::extract_internal_links( '<p>No links here.</p>', self::HOME ) );
	}

	public function test_extract_link_with_data_id_and_home_url(): void {
		$html   = '<a data-id="42" href="https://example.org/en/about">About</a>';
		$result = LinkFixer::extract_internal_links( $html, self::HOME );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
		$this->assertSame( 'https://example.org/en/about', $result[0]['url'] );
	}

	public function test_extract_http_variant_normalised_to_https_home(): void {
		// Home is https but link uses http — should be normalised to https.
		$html   = '<a data-id="7" href="http://example.org/de/page">Page</a>';
		$result = LinkFixer::extract_internal_links( $html, self::HOME );

		$this->assertCount( 1, $result );
		$this->assertStringStartsWith( 'https://', $result[0]['url'] );
	}

	public function test_extract_relative_path_normalised_to_absolute(): void {
		$html   = '<a data-id="5" href="/ca/contacte">Contact</a>';
		$result = LinkFixer::extract_internal_links( $html, self::HOME );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.org/ca/contacte', $result[0]['url'] );
	}

	public function test_extract_external_link_excluded(): void {
		$html = '<a data-id="9" href="https://other-site.com/page">External</a>';
		$this->assertSame( [], LinkFixer::extract_internal_links( $html, self::HOME ) );
	}

	public function test_extract_link_without_data_id_excluded(): void {
		$html = '<a href="https://example.org/en/about">No data-id</a>';
		$this->assertSame( [], LinkFixer::extract_internal_links( $html, self::HOME ) );
	}

	public function test_extract_link_without_href_excluded(): void {
		$html = '<a data-id="3" name="anchor">Anchor</a>';
		$this->assertSame( [], LinkFixer::extract_internal_links( $html, self::HOME ) );
	}

	public function test_extract_duplicate_post_ids_deduplicated(): void {
		$html = '
			<a data-id="42" href="https://example.org/en/about">First</a>
			<a data-id="42" href="https://example.org/en/about">Second</a>
		';
		$result = LinkFixer::extract_internal_links( $html, self::HOME );

		$this->assertCount( 1, $result );
		$this->assertSame( 42, $result[0]['id'] );
	}

	public function test_extract_multiple_different_links(): void {
		$html = '
			<a data-id="1" href="https://example.org/en/about">About</a>
			<a data-id="2" href="https://example.org/en/contact">Contact</a>
		';
		$result = LinkFixer::extract_internal_links( $html, self::HOME );

		$this->assertCount( 2, $result );
		$ids = array_column( $result, 'id' );
		$this->assertContains( 1, $ids );
		$this->assertContains( 2, $ids );
	}

	public function test_extract_protocol_relative_excluded(): void {
		// Protocol-relative URLs (//example.org/…) are excluded.
		$html = '<a data-id="3" href="//example.org/en/page">Proto-relative</a>';
		$this->assertSame( [], LinkFixer::extract_internal_links( $html, self::HOME ) );
	}

	// =========================================================================
	// fix_data_id_attr()
	// =========================================================================

	public function test_fix_data_id_rewrites_matching_link(): void {
		$href    = 'https://example.org/de/uber-uns';
		$content = '<a data-id="10" href="' . $href . '">About</a>';

		$result = LinkFixer::fix_data_id_attr( $content, $href, 10, 99 );

		$this->assertStringContainsString( 'data-id="99"', $result );
		$this->assertStringNotContainsString( 'data-id="10"', $result );
	}

	public function test_fix_data_id_leaves_other_links_unchanged(): void {
		$href     = 'https://example.org/de/uber-uns';
		$other    = 'https://example.org/de/kontakt';
		$content  = '<a data-id="10" href="' . $href . '">A</a><a data-id="20" href="' . $other . '">B</a>';

		$result = LinkFixer::fix_data_id_attr( $content, $href, 10, 99 );

		$this->assertStringContainsString( 'data-id="99"', $result );
		$this->assertStringContainsString( 'data-id="20"', $result ); // unchanged
	}

	public function test_fix_data_id_no_match_returns_content_unchanged(): void {
		$content = '<a data-id="5" href="https://example.org/de/other">Link</a>';
		$result  = LinkFixer::fix_data_id_attr( $content, 'https://example.org/de/no-such-link', 5, 99 );

		$this->assertSame( $content, $result );
	}

	public function test_fix_data_id_empty_content_returns_empty(): void {
		$this->assertSame( '', LinkFixer::fix_data_id_attr( '', 'https://example.org/de/page', 1, 2 ) );
	}
}
