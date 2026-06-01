<?php
/**
 * Unit tests for MetaDescription::clean_output() (private static).
 *
 * clean_output() is pure PHP with no WordPress or I/O dependencies,
 * so it is reachable via ReflectionMethod without any stubs.
 *
 * Covers:
 *   • Surrounding single/double quotes stripped.
 *   • "Meta description:" prefix removed (any capitalisation).
 *   • **bold** markdown wrappers unwrapped.
 *   • Analysis sections after --- / *** truncated.
 *   • Markdown headings stripped.
 *   • Internal newlines collapsed to one space.
 *   • Plain clean text returned unchanged (whitespace trimmed).
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Features\MetaDescription;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Providers/WorkerConfig.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Features/Contracts/FeatureInterface.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Features/MetaDescription.php';

final class MetaDescriptionCleanOutputTest extends TestCase {

	private static function clean( string $text ): string {
		$m = new ReflectionMethod( MetaDescription::class, 'clean_output' );
		$m->setAccessible( true );
		return $m->invoke( null, $text );
	}

	// =========================================================================
	// Plain text — no-op paths
	// =========================================================================

	public function test_plain_text_is_returned_trimmed(): void {
		$this->assertSame( 'A clean meta description.', self::clean( '  A clean meta description.  ' ) );
	}

	// =========================================================================
	// Surrounding quotes
	// =========================================================================

	public function test_surrounding_double_quotes_are_stripped(): void {
		$this->assertSame( 'Great SEO text.', self::clean( '"Great SEO text."' ) );
	}

	public function test_surrounding_single_quotes_are_stripped(): void {
		$this->assertSame( 'Great SEO text.', self::clean( "'Great SEO text.'" ) );
	}

	// =========================================================================
	// Meta description prefix
	// =========================================================================

	public function test_meta_description_colon_prefix_stripped(): void {
		$this->assertSame( 'Buy now.', self::clean( 'Meta description: Buy now.' ) );
	}

	public function test_meta_description_prefix_case_insensitive(): void {
		$this->assertSame( 'Buy now.', self::clean( 'META DESCRIPTION: Buy now.' ) );
	}

	public function test_meta_description_prefix_with_dash_stripped(): void {
		$this->assertSame( 'Buy now.', self::clean( 'Meta description - Buy now.' ) );
	}

	// =========================================================================
	// Bold markdown wrappers
	// =========================================================================

	public function test_bold_markdown_unwrapped(): void {
		$this->assertSame( 'Important text here.', self::clean( '**Important text here.**' ) );
	}

	public function test_bold_markdown_inline_unwrapped(): void {
		$this->assertSame( 'Buy our best product today.', self::clean( 'Buy our **best** product today.' ) );
	}

	// =========================================================================
	// Analysis section separator
	// =========================================================================

	public function test_content_after_triple_dash_truncated(): void {
		$input = "Great description.\n---\nSome analysis the model added.";

		$result = self::clean( $input );

		$this->assertSame( 'Great description.', $result );
		$this->assertStringNotContainsString( 'analysis', $result );
	}

	public function test_content_after_triple_star_truncated(): void {
		$input = "Great description.\n***\nMore model commentary.";

		$result = self::clean( $input );

		$this->assertSame( 'Great description.', $result );
	}

	// =========================================================================
	// Markdown headings
	// =========================================================================

	public function test_markdown_heading_line_stripped(): void {
		$input = "## Meta Description\nBuy our product.";

		$result = self::clean( $input );

		$this->assertStringNotContainsString( '##', $result );
		$this->assertStringContainsString( 'Buy our product.', $result );
	}

	// =========================================================================
	// Internal newlines collapsed
	// =========================================================================

	public function test_internal_newlines_collapsed_to_space(): void {
		$input = "First sentence.\nSecond sentence.";

		$this->assertSame( 'First sentence. Second sentence.', self::clean( $input ) );
	}

	public function test_multiple_spaces_collapsed(): void {
		$this->assertSame( 'One two three.', self::clean( 'One  two   three.' ) );
	}
}
