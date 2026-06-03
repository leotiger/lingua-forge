<?php
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- polyfills and test class must coexist; same pattern as ApiPolyfills.php.
/**
 * Unit tests for LinguaForge\AI\Features\ContentGenerator.
 *
 * Covers four pure static helpers extracted from run():
 *
 *   • build_seed_section()  — hints vs. existing-content seed selection + truncation.
 *   • build_prompt()        — template placeholder substitution.
 *   • is_refinement()       — multi-turn refinement detection.
 *   • build_messages()      — provider message array assembly.
 *
 * All methods are pure: no WordPress functions, no database, no API calls.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Features\ContentGenerator;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Features/Contracts/FeatureInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/ContentGenerator.php';

// Minimal WP polyfill needed for the class declaration (tones()/content_types() call __()).
if ( ! function_exists( '__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- polyfill; only defined when WP isn't loaded.
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP signature; $domain unused.
		return $text;
	}
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Features\ContentGenerator::build_seed_section
 * @covers \LinguaForge\AI\Features\ContentGenerator::build_prompt
 * @covers \LinguaForge\AI\Features\ContentGenerator::is_refinement
 * @covers \LinguaForge\AI\Features\ContentGenerator::build_messages
 */
class ContentGeneratorTest extends TestCase {

	// ── build_seed_section ────────────────────────────────────────────────────

	public function test_hints_take_priority_over_existing_content(): void {

		$result = ContentGenerator::build_seed_section( 'My hints', 'Existing body text', 5000 );

		$this->assertStringContainsString( 'My hints', $result );
		$this->assertStringNotContainsString( 'Existing body text', $result );
	}

	public function test_hints_section_contains_expected_label(): void {

		$result = ContentGenerator::build_seed_section( 'Key point A', '', 5000 );

		$this->assertStringContainsString( 'Hints and key points to build from:', $result );
	}

	public function test_hints_not_truncated_by_build_seed_section(): void {

		// Truncation of hints is the caller's responsibility; build_seed_section
		// must return hints verbatim regardless of length vs max_context_chars.
		$long_hints = str_repeat( 'x', 500 );
		$result     = ContentGenerator::build_seed_section( $long_hints, '', 100 );

		$this->assertStringContainsString( $long_hints, $result );
	}

	public function test_empty_hints_falls_back_to_existing_content(): void {

		$result = ContentGenerator::build_seed_section( '', 'Post body here', 5000 );

		$this->assertStringContainsString( 'Post body here', $result );
		$this->assertStringContainsString( 'Existing content', $result );
	}

	public function test_existing_content_is_truncated_to_max_context_chars(): void {

		$long_content = str_repeat( 'a', 200 );
		$result       = ContentGenerator::build_seed_section( '', $long_content, 50 );

		// The seed section prefix is prepended; the content itself must be ≤ 50 chars.
		$prefix   = "\n\nExisting content (use as context or rewrite as needed):\n";
		$content_part = substr( $result, strlen( $prefix ) );
		$this->assertSame( 50, mb_strlen( $content_part ) );
	}

	public function test_whitespace_only_existing_content_returns_empty_string(): void {

		$result = ContentGenerator::build_seed_section( '', "   \n\t  ", 5000 );

		$this->assertSame( '', $result );
	}

	public function test_empty_hints_and_empty_content_returns_empty_string(): void {

		$result = ContentGenerator::build_seed_section( '', '', 5000 );

		$this->assertSame( '', $result );
	}

	// ── build_prompt ──────────────────────────────────────────────────────────

	public function test_all_placeholders_are_replaced(): void {

		$template = '{{title}} | {{tone}} | {{content_type}}{{existing_content}}';
		$result   = ContentGenerator::build_prompt(
			$template,
			'My Post',
			'Informative',
			'Full Article',
			"\n\nHints: foo"
		);

		$this->assertSame( 'My Post | Informative | Full Article' . "\n\nHints: foo", $result );
	}

	public function test_template_text_outside_placeholders_is_preserved(): void {

		$template = 'Write a {{tone}} post about {{title}}.';
		$result   = ContentGenerator::build_prompt( $template, 'Dogs', 'Technical', 'Outline', '' );

		$this->assertSame( 'Write a Technical post about Dogs.', $result );
	}

	public function test_empty_seed_section_leaves_no_trailing_placeholder(): void {

		$template = 'Intro.{{existing_content}}End.';
		$result   = ContentGenerator::build_prompt( $template, 'T', 'Tone', 'Type', '' );

		$this->assertSame( 'Intro.End.', $result );
	}

	// ── is_refinement ─────────────────────────────────────────────────────────

	public function test_both_non_empty_is_refinement(): void {

		$this->assertTrue( ContentGenerator::is_refinement( 'Make it shorter', 'Previous AI output here' ) );
	}

	public function test_empty_refine_hint_is_not_refinement(): void {

		$this->assertFalse( ContentGenerator::is_refinement( '', 'Previous AI output here' ) );
	}

	public function test_empty_previous_output_is_not_refinement(): void {

		$this->assertFalse( ContentGenerator::is_refinement( 'Make it shorter', '' ) );
	}

	public function test_both_empty_is_not_refinement(): void {

		$this->assertFalse( ContentGenerator::is_refinement( '', '' ) );
	}

	// ── build_messages ────────────────────────────────────────────────────────

	public function test_non_refinement_produces_two_messages(): void {

		$messages = ContentGenerator::build_messages( 'sys', 'user prompt', false, '', '' );

		$this->assertCount( 2, $messages );
	}

	public function test_non_refinement_roles_are_system_and_user(): void {

		$messages = ContentGenerator::build_messages( 'System content', 'User content', false, '', '' );

		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'System content', $messages[0]['content'] );
		$this->assertSame( 'user', $messages[1]['role'] );
		$this->assertSame( 'User content', $messages[1]['content'] );
	}

	public function test_refinement_produces_four_messages(): void {

		$messages = ContentGenerator::build_messages( 'sys', 'prompt', true, 'prior draft', 'shorter please' );

		$this->assertCount( 4, $messages );
	}

	public function test_refinement_message_order_and_roles(): void {

		$messages = ContentGenerator::build_messages( 'sys', 'prompt', true, 'prior draft', 'shorter' );

		$this->assertSame( 'system',    $messages[0]['role'] );
		$this->assertSame( 'user',      $messages[1]['role'] );
		$this->assertSame( 'assistant', $messages[2]['role'] );
		$this->assertSame( 'user',      $messages[3]['role'] );
	}

	public function test_refinement_fourth_message_contains_instruction_prefix_and_hint(): void {

		$messages = ContentGenerator::build_messages( 'sys', 'prompt', true, 'draft', 'use bullet points' );

		$this->assertStringContainsString(
			'Please refine the content above based on these additional instructions:',
			$messages[3]['content']
		);
		$this->assertStringContainsString( 'use bullet points', $messages[3]['content'] );
	}

	public function test_refinement_assistant_message_contains_prior_draft(): void {

		$messages = ContentGenerator::build_messages( 'sys', 'prompt', true, 'THE PRIOR DRAFT', 'hint' );

		$this->assertSame( 'THE PRIOR DRAFT', $messages[2]['content'] );
	}
}
