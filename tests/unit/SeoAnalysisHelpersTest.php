<?php
/**
 * Unit tests for the public static helper methods on SeoAnalysisPanel.
 *
 * All helpers are pure functions with no WP runtime dependencies (or use the
 * optional $home parameter to avoid calling home_url() inline).
 *
 * Covers:
 *   count_words()      — word counting via whitespace split
 *   extract_headings() — regex H1-H6 detection in HTML
 *   analyze_images()   — image alt-text coverage analysis
 *   analyze_links()    — internal / external link classification
 *   rate_title()       — quality rating for page title length
 *   rate_meta()        — quality rating for meta description length
 *   rate_words()       — quality rating for word count
 *   rate_headings()    — quality rating for heading structure
 *   rate_images()      — quality rating for image alt coverage
 *   rate_links()       — quality rating for link presence
 *   compute_score()    — weighted 0–100 score from metric statuses
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

// The `use` statements in SeoAnalysisPanel.php reference AI classes that are
// only needed when ajax_ai_analyze() executes.  Pure static helper methods
// (count_words, rate_*, etc.) never resolve those names, so no stubs are
// required — PHP defers class resolution to call time, not parse time.
require_once dirname( __DIR__, 2 ) . '/ai/includes/Admin/Settings/Panels/SeoAnalysisPanel.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel
 */
final class SeoAnalysisHelpersTest extends TestCase {

	private const HOME = 'https://example.org';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_filters'] = [];
		$GLOBALS['lf_test_home_url'] = self::HOME;
		$GLOBALS['lf_test_is_singular'] = false;
	}

	// =========================================================================
	// count_words()
	// =========================================================================

	public function test_count_words_empty_string_returns_zero(): void {
		$this->assertSame( 0, SeoAnalysisPanel::count_words( '' ) );
	}

	public function test_count_words_counts_whitespace_separated_tokens(): void {
		$this->assertSame( 4, SeoAnalysisPanel::count_words( 'Hello world foo bar' ) );
	}

	public function test_count_words_handles_multiple_spaces(): void {
		$this->assertSame( 3, SeoAnalysisPanel::count_words( "one  two\tthree" ) );
	}

	// =========================================================================
	// extract_headings()
	// =========================================================================

	public function test_extract_headings_empty_html_returns_zeros(): void {
		$result = SeoAnalysisPanel::extract_headings( '' );
		$this->assertSame( [ 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ], $result );
	}

	public function test_extract_headings_counts_each_level(): void {
		$html   = '<h1>Title</h1><h2>Sub</h2><h2>Sub2</h2><h3>Sub3</h3>';
		$result = SeoAnalysisPanel::extract_headings( $html );

		$this->assertSame( 1, $result['h1'] );
		$this->assertSame( 2, $result['h2'] );
		$this->assertSame( 1, $result['h3'] );
		$this->assertSame( 0, $result['h4'] );
	}

	public function test_extract_headings_handles_attributes(): void {
		$html   = '<h1 id="main" class="foo">Title</h1><h2 style="color:red">Sub</h2>';
		$result = SeoAnalysisPanel::extract_headings( $html );
		$this->assertSame( 1, $result['h1'] );
		$this->assertSame( 1, $result['h2'] );
	}

	public function test_extract_headings_case_insensitive(): void {
		$html   = '<H1>Title</H1><H2>Sub</H2>';
		$result = SeoAnalysisPanel::extract_headings( $html );
		$this->assertSame( 1, $result['h1'] );
		$this->assertSame( 1, $result['h2'] );
	}

	// =========================================================================
	// analyze_images()
	// =========================================================================

	public function test_analyze_images_empty_html_returns_zeros(): void {
		$result = SeoAnalysisPanel::analyze_images( '' );
		$this->assertSame( [ 'total' => 0, 'with_alt' => 0, 'without_alt' => 0 ], $result );
	}

	public function test_analyze_images_with_alt_counted_correctly(): void {
		$html   = '<img src="a.jpg" alt="Cat"> <img src="b.jpg" alt="Dog">';
		$result = SeoAnalysisPanel::analyze_images( $html );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 2, $result['with_alt'] );
		$this->assertSame( 0, $result['without_alt'] );
	}

	public function test_analyze_images_missing_alt_detected(): void {
		$html   = '<img src="a.jpg"> <img src="b.jpg" alt="">';
		$result = SeoAnalysisPanel::analyze_images( $html );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 0, $result['with_alt'] );
		$this->assertSame( 2, $result['without_alt'] );
	}

	public function test_analyze_images_mixed_coverage(): void {
		$html   = '<img src="a.jpg" alt="Cat"> <img src="b.jpg">';
		$result = SeoAnalysisPanel::analyze_images( $html );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['with_alt'] );
		$this->assertSame( 1, $result['without_alt'] );
	}

	// =========================================================================
	// analyze_links()
	// =========================================================================

	public function test_analyze_links_empty_html_returns_zeros(): void {
		$result = SeoAnalysisPanel::analyze_links( '', self::HOME );
		$this->assertSame( [ 'internal' => 0, 'external' => 0 ], $result );
	}

	public function test_analyze_links_internal_link_detected(): void {
		$html   = '<a href="' . self::HOME . '/about/">About</a>';
		$result = SeoAnalysisPanel::analyze_links( $html, self::HOME );
		$this->assertSame( 1, $result['internal'] );
		$this->assertSame( 0, $result['external'] );
	}

	public function test_analyze_links_relative_path_is_internal(): void {
		$html   = '<a href="/de/about/">About</a>';
		$result = SeoAnalysisPanel::analyze_links( $html, self::HOME );
		$this->assertSame( 1, $result['internal'] );
	}

	public function test_analyze_links_external_link_detected(): void {
		$html   = '<a href="https://google.com">Google</a>';
		$result = SeoAnalysisPanel::analyze_links( $html, self::HOME );
		$this->assertSame( 0, $result['internal'] );
		$this->assertSame( 1, $result['external'] );
	}

	public function test_analyze_links_anchor_and_mailto_skipped(): void {
		$html   = '<a href="#section">Jump</a> <a href="mailto:test@test.com">Mail</a>';
		$result = SeoAnalysisPanel::analyze_links( $html, self::HOME );
		$this->assertSame( 0, $result['internal'] );
		$this->assertSame( 0, $result['external'] );
	}

	// =========================================================================
	// rate_title()
	// =========================================================================

	public function test_rate_title_empty_is_fail(): void {
		$result = SeoAnalysisPanel::rate_title( '', 0 );
		$this->assertSame( 'fail', $result['status'] );
	}

	public function test_rate_title_very_short_is_warn(): void {
		// < 10 chars → warn regardless of word count.
		$result = SeoAnalysisPanel::rate_title( 'Hi', 2 );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_title_exactly_ten_chars_is_ok(): void {
		// Exactly 10 chars, two words — ok (boundary: must be >= 10).
		$result = SeoAnalysisPanel::rate_title( 'About Us!!', 10 );
		$this->assertSame( 'ok', $result['status'] );
	}

	public function test_rate_title_nine_chars_is_warn(): void {
		// 9 chars (< 10) — warn regardless of word count.
		$result = SeoAnalysisPanel::rate_title( 'About Us!', 9 );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_title_single_word_over_ten_chars_is_warn(): void {
		// >= 10 chars but only one word → warn.
		$title  = 'Confidentiality'; // 15 chars, 1 word
		$result = SeoAnalysisPanel::rate_title( $title, mb_strlen( $title ) );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_title_multiword_over_ten_chars_is_ok(): void {
		// Real-world multilingual title: > 10 chars, 3 words → ok.
		$title  = 'Política de Privacitat'; // 22 chars, 3 words
		$result = SeoAnalysisPanel::rate_title( $title, mb_strlen( $title ) );
		$this->assertSame( 'ok', $result['status'] );
	}

	public function test_rate_title_optimal_length_is_ok(): void {
		// > 10 chars, two words → ok.
		$title  = 'How to bake sourdough bread at home';
		$result = SeoAnalysisPanel::rate_title( $title, mb_strlen( $title ) );
		$this->assertSame( 'ok', $result['status'] );
	}

	public function test_rate_title_long_is_warn(): void {
		// Over max (default 60) → warn, even with multiple words.
		$title  = 'This title is intentionally far too long for any search engine results page display';
		$result = SeoAnalysisPanel::rate_title( $title, mb_strlen( $title ) );
		$this->assertSame( 'warn', $result['status'] );
	}

	// =========================================================================
	// rate_meta()
	// =========================================================================

	public function test_rate_meta_empty_is_fail(): void {
		$result = SeoAnalysisPanel::rate_meta( '', 0, false );
		$this->assertSame( 'fail', $result['status'] );
	}

	public function test_rate_meta_short_is_warn(): void {
		$result = SeoAnalysisPanel::rate_meta( 'Too short', 9, false );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_meta_optimal_is_ok(): void {
		$meta   = str_repeat( 'a', 150 );
		$result = SeoAnalysisPanel::rate_meta( $meta, 150, true );
		$this->assertSame( 'ok', $result['status'] );
	}

	// =========================================================================
	// rate_words()
	// =========================================================================

	public function test_rate_words_below_100_is_fail(): void {
		$result = SeoAnalysisPanel::rate_words( 50 );
		$this->assertSame( 'fail', $result['status'] );
	}

	public function test_rate_words_100_to_299_is_warn(): void {
		$result = SeoAnalysisPanel::rate_words( 200 );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_words_300_plus_is_ok(): void {
		$result = SeoAnalysisPanel::rate_words( 500 );
		$this->assertSame( 'ok', $result['status'] );
	}

	// =========================================================================
	// rate_headings()
	// =========================================================================

	public function test_rate_headings_no_h1_is_warn(): void {
		$result = SeoAnalysisPanel::rate_headings( [ 'h1' => 0, 'h2' => 2, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ] );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_headings_multiple_h1_is_fail(): void {
		$result = SeoAnalysisPanel::rate_headings( [ 'h1' => 3, 'h2' => 1, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ] );
		$this->assertSame( 'fail', $result['status'] );
	}

	public function test_rate_headings_one_h1_plus_h2_is_ok(): void {
		$result = SeoAnalysisPanel::rate_headings( [ 'h1' => 1, 'h2' => 3, 'h3' => 1, 'h4' => 0, 'h5' => 0, 'h6' => 0 ] );
		$this->assertSame( 'ok', $result['status'] );
		// Ordered placeholders: 1$d = H2 count, 2$d = H3 count.
		$this->assertStringContainsString( '3', $result['message'] );
		$this->assertStringContainsString( '1', $result['message'] );
	}

	// =========================================================================
	// rate_images()
	// =========================================================================

	public function test_rate_images_no_images_is_info(): void {
		$result = SeoAnalysisPanel::rate_images( [ 'total' => 0, 'with_alt' => 0, 'without_alt' => 0 ] );
		$this->assertSame( 'info', $result['status'] );
	}

	public function test_rate_images_missing_alt_is_warn(): void {
		$result = SeoAnalysisPanel::rate_images( [ 'total' => 3, 'with_alt' => 2, 'without_alt' => 1 ] );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_images_all_have_alt_is_ok(): void {
		$result = SeoAnalysisPanel::rate_images( [ 'total' => 3, 'with_alt' => 3, 'without_alt' => 0 ] );
		$this->assertSame( 'ok', $result['status'] );
	}

	// =========================================================================
	// rate_links()
	// =========================================================================

	public function test_rate_links_no_links_is_warn(): void {
		$result = SeoAnalysisPanel::rate_links( [ 'internal' => 0, 'external' => 0 ] );
		$this->assertSame( 'warn', $result['status'] );
	}

	public function test_rate_links_with_internal_links_is_ok(): void {
		$result = SeoAnalysisPanel::rate_links( [ 'internal' => 3, 'external' => 1 ] );
		$this->assertSame( 'ok', $result['status'] );
	}

	// =========================================================================
	// compute_score()
	// =========================================================================

	public function test_compute_score_all_ok_returns_high_score(): void {
		$metrics = [
			'title'            => [ 'status' => 'ok' ],
			'meta_description' => [ 'status' => 'ok' ],
			'word_count'       => [ 'status' => 'ok' ],
			'headings'         => [ 'status' => 'ok' ],
			'images'           => [ 'status' => 'ok' ],
			'links'            => [ 'status' => 'ok' ],
			'reading_time'     => [ 'status' => 'info' ],
		];
		$score = SeoAnalysisPanel::compute_score( $metrics );
		$this->assertSame( 100, $score );
	}

	public function test_compute_score_all_fail_returns_only_base(): void {
		$metrics = [
			'title'            => [ 'status' => 'fail' ],
			'meta_description' => [ 'status' => 'fail' ],
			'word_count'       => [ 'status' => 'fail' ],
			'headings'         => [ 'status' => 'fail' ],
			'images'           => [ 'status' => 'fail' ],
			'links'            => [ 'status' => 'fail' ],
			'reading_time'     => [ 'status' => 'info' ],
		];
		$score = SeoAnalysisPanel::compute_score( $metrics );
		// Only the 10-point reading_time base is earned.
		$this->assertSame( 10, $score );
	}

	public function test_compute_score_mixed_warn_returns_midrange(): void {
		$metrics = [
			'title'            => [ 'status' => 'ok'   ],  // 15
			'meta_description' => [ 'status' => 'warn' ],  // 10 (half of 20)
			'word_count'       => [ 'status' => 'ok'   ],  // 15
			'headings'         => [ 'status' => 'warn' ],  // 10 (half of 20)
			'images'           => [ 'status' => 'ok'   ],  // 10
			'links'            => [ 'status' => 'ok'   ],  // 10
			'reading_time'     => [ 'status' => 'info' ],  // 10 pts (informational base, always awarded)
		];
		$score = SeoAnalysisPanel::compute_score( $metrics );
		// 10 + 15 + 10 + 15 + 10 + 10 + 10 = 80
		$this->assertSame( 80, $score );
	}

	public function test_compute_score_capped_at_100(): void {
		// Even with extra-high weights the score never exceeds 100.
		$metrics = array_fill_keys(
			[ 'title', 'meta_description', 'word_count', 'headings', 'images', 'links', 'reading_time' ],
			[ 'status' => 'ok' ]
		);
		$score = SeoAnalysisPanel::compute_score( $metrics );
		$this->assertLessThanOrEqual( 100, $score );
	}
}
