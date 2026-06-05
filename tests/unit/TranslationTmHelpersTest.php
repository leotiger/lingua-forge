<?php
/**
 * Unit tests for the TranslationMemoryTranslator pure helper methods.
 *
 * All six helpers are public static methods on TranslationMemoryTranslator
 * (extracted from Translation.php in 2.1.9). No WP runtime needed.
 *
 * Helpers covered:
 *   • build_tm_source_markups()  — filter + serialize source blocks
 *   • build_tm_queue()           — partition markups into TM hits and queue
 *   • build_tm_schema()          — build JSON schema for TM API call
 *   • build_tm_user_message()    — build user message string
 *   • parse_tm_envelope()        — parse + validate API response
 *   • reassemble_tm_blocks()     — assemble translated content
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Features\TranslationMemoryTranslator;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Core/JsonRepair.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/BlockTextExtractor.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/WorkerConfig.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/TranslationMemoryTranslator.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Features\TranslationMemoryTranslator
 */
final class TranslationTmHelpersTest extends TestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Call a public static method on TranslationMemoryTranslator. */
	private function call( string $method, array $args = [] ): mixed {
		return TranslationMemoryTranslator::$method( ...$args );
	}

	/** Build a minimal parsed-block array as parse_blocks() would return. */
	private function makeBlock( string $name, string $html, array $attrs = [] ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/** Whitespace-only entry that parse_blocks inserts between named blocks. */
	private function makeWhitespaceBlock(): array {
		return [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => "\n\n",
			'innerContent' => [ "\n\n" ],
		];
	}

	// =========================================================================
	// build_tm_source_markups()
	// =========================================================================

	public function test_source_markups_empty_blocks_returns_empty(): void {
		$result = $this->call( 'build_tm_source_markups', [ [] ] );
		$this->assertSame( [], $result );
	}

	public function test_source_markups_excludes_whitespace_entry(): void {
		$blocks = [ $this->makeWhitespaceBlock() ];
		$result = $this->call( 'build_tm_source_markups', [ $blocks ] );
		$this->assertSame( [], $result );
	}

	public function test_source_markups_excludes_tag_only_block(): void {
		// A block whose visible text is empty after strip_tags (e.g. spacer).
		$blocks = [ $this->makeBlock( 'core/spacer', '<div style="height:20px"></div>' ) ];
		$result = $this->call( 'build_tm_source_markups', [ $blocks ] );
		$this->assertSame( [], $result );
	}

	public function test_source_markups_includes_block_with_text(): void {
		$block  = $this->makeBlock( 'core/paragraph', '<p>Hello world</p>' );
		$result = $this->call( 'build_tm_source_markups', [ [ $block ] ] );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'core/paragraph', array_values( $result )[0] );
	}

	public function test_source_markups_preserves_source_block_index(): void {
		$blocks = [
			0 => $this->makeWhitespaceBlock(),
			1 => $this->makeBlock( 'core/paragraph', '<p>Text</p>' ),
		];
		$result = $this->call( 'build_tm_source_markups', [ $blocks ] );
		$this->assertArrayHasKey( 1, $result );
		$this->assertArrayNotHasKey( 0, $result );
	}

	public function test_source_markups_includes_multiple_named_blocks(): void {
		$blocks = [
			$this->makeBlock( 'core/paragraph', '<p>First</p>' ),
			$this->makeWhitespaceBlock(),
			$this->makeBlock( 'core/heading', '<h2>Second</h2>' ),
		];
		$result = $this->call( 'build_tm_source_markups', [ $blocks ] );
		$this->assertCount( 2, $result );
	}

	// =========================================================================
	// build_tm_queue()
	// =========================================================================

	public function test_queue_all_hits_returns_empty_queue(): void {
		$markups = [ 0 => '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->' ];
		$hits    = [ $markups[0] => '<p>A translated</p>' ];
		[ $queue, $index ] = $this->call( 'build_tm_queue', [ $markups, $hits ] );
		$this->assertSame( [], $queue );
		$this->assertSame( [], $index );
	}

	public function test_queue_no_hits_puts_all_in_queue(): void {
		$markups = [
			0 => '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->',
			1 => '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->',
		];
		[ $queue, $index ] = $this->call( 'build_tm_queue', [ $markups, [] ] );
		$this->assertCount( 2, $queue );
		$this->assertSame( 0, $index[0] );
		$this->assertSame( 1, $index[1] );
	}

	public function test_queue_partial_hits_correct_partitioning(): void {
		$a = '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->';
		$b = '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->';
		$markups = [ 0 => $a, 2 => $b ]; // note: index 1 skipped (whitespace)
		$hits    = [ $a => '<p>A cached</p>' ];
		[ $queue, $index ] = $this->call( 'build_tm_queue', [ $markups, $hits ] );
		$this->assertCount( 1, $queue );
		$this->assertSame( $b, $queue[0] );
		$this->assertSame( 2, $index[0] ); // queue[0] maps back to source index 2
	}

	// =========================================================================
	// build_tm_schema()
	// =========================================================================

	public function test_schema_neither_flag_has_only_title(): void {
		$schema = $this->call( 'build_tm_schema', [ false, false ] );
		$this->assertSame( [ 'title' ], $schema['required'] );
		$this->assertArrayNotHasKey( 'blocks',    $schema['properties'] );
		$this->assertArrayNotHasKey( 'footnotes', $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_schema_needs_blocks_adds_blocks_array(): void {
		$schema = $this->call( 'build_tm_schema', [ true, false ] );
		$this->assertContains( 'blocks', $schema['required'] );
		$this->assertSame( 'array', $schema['properties']['blocks']['type'] );
		$this->assertSame( 'string', $schema['properties']['blocks']['items']['type'] );
	}

	public function test_schema_needs_footnotes_adds_footnotes_array(): void {
		$schema = $this->call( 'build_tm_schema', [ false, true ] );
		$this->assertContains( 'footnotes', $schema['required'] );
		$this->assertSame( 'array', $schema['properties']['footnotes']['type'] );
	}

	public function test_schema_both_flags_has_three_required_fields(): void {
		$schema = $this->call( 'build_tm_schema', [ true, true ] );
		$this->assertCount( 3, $schema['required'] );
		$this->assertSame( [ 'title', 'blocks', 'footnotes' ], $schema['required'] );
	}

	// =========================================================================
	// build_tm_user_message()
	// =========================================================================

	private function callMessage( array $overrides = [] ): string {
		$defaults = [
			'source_lang'     => 'en',
			'language_name'   => 'German',
			'post_title'      => 'My Post',
			'queue_markups'   => [],
			'needs_blocks'    => false,
			'needs_footnotes' => false,
			'footnotes_raw'   => '',
		];
		$p = array_merge( $defaults, $overrides );
		return $this->call( 'build_tm_user_message', [
			$p['source_lang'], $p['language_name'], $p['post_title'],
			$p['queue_markups'], $p['needs_blocks'], $p['needs_footnotes'],
			$p['footnotes_raw'],
		] );
	}

	public function test_message_contains_source_lang_language_name_and_title(): void {
		$msg = $this->callMessage();
		$this->assertStringContainsString( 'en',      $msg );
		$this->assertStringContainsString( 'German',  $msg );
		$this->assertStringContainsString( 'My Post', $msg );
	}

	public function test_message_without_blocks_has_no_numbered_list(): void {
		$msg = $this->callMessage( [ 'needs_blocks' => false ] );
		$this->assertStringNotContainsString( '[1]', $msg );
	}

	public function test_message_with_blocks_includes_numbered_markups(): void {
		$markups = [ '<p>Block one</p>', '<p>Block two</p>' ];
		$msg     = $this->callMessage( [ 'needs_blocks' => true, 'queue_markups' => $markups ] );
		$this->assertStringContainsString( '[1] <p>Block one</p>', $msg );
		$this->assertStringContainsString( '[2] <p>Block two</p>', $msg );
	}

	public function test_message_with_footnotes_includes_footnotes_section(): void {
		$fn  = '[{"id":"fn-1","content":"A note"}]';
		$msg = $this->callMessage( [ 'needs_footnotes' => true, 'footnotes_raw' => $fn ] );
		$this->assertStringContainsString( 'Source footnotes JSON', $msg );
		$this->assertStringContainsString( $fn, $msg );
	}

	public function test_message_without_footnotes_has_no_footnotes_section(): void {
		$msg = $this->callMessage( [ 'needs_footnotes' => false ] );
		$this->assertStringNotContainsString( 'Source footnotes JSON', $msg );
	}

	// =========================================================================
	// parse_tm_envelope()
	// =========================================================================

	public function test_envelope_empty_string_returns_null(): void {
		$result = $this->call( 'parse_tm_envelope', [ '', false, 0 ] );
		$this->assertNull( $result );
	}

	public function test_envelope_invalid_json_returns_null(): void {
		$result = $this->call( 'parse_tm_envelope', [ 'not json at all', false, 0 ] );
		$this->assertNull( $result );
	}

	public function test_envelope_truncated_json_returns_null(): void {
		$result = $this->call( 'parse_tm_envelope', [ '{"title":"Hi","content":"Inc', false, 0 ] );
		$this->assertNull( $result );
	}

	public function test_envelope_valid_title_only_returns_parsed(): void {
		$json   = wp_json_encode([ 'title' => 'Hallo Welt' ] );
		$result = $this->call( 'parse_tm_envelope', [ $json, false, 0 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 'Hallo Welt', $result['title'] );
		$this->assertSame( [], $result['blocks'] );
		$this->assertNull( $result['footnotes'] );
	}

	public function test_envelope_correct_block_count_returns_parsed(): void {
		$json   = wp_json_encode([ 'title' => 'T', 'blocks' => [ '<p>Eins</p>', '<p>Zwei</p>' ] ] );
		$result = $this->call( 'parse_tm_envelope', [ $json, true, 2 ] );
		$this->assertIsArray( $result );
		$this->assertSame( [ '<p>Eins</p>', '<p>Zwei</p>' ], $result['blocks'] );
	}

	public function test_envelope_wrong_block_count_returns_null(): void {
		$json   = wp_json_encode([ 'title' => 'T', 'blocks' => [ '<p>Only one</p>' ] ] );
		$result = $this->call( 'parse_tm_envelope', [ $json, true, 3 ] );
		$this->assertNull( $result );
	}

	public function test_envelope_includes_footnotes_when_present(): void {
		$fn   = [ [ 'id' => 'fn-1', 'content' => 'Note' ] ];
		$json = wp_json_encode([ 'title' => 'T', 'footnotes' => $fn ] );
		$result = $this->call( 'parse_tm_envelope', [ $json, false, 0 ] );
		$this->assertSame( $fn, $result['footnotes'] );
	}

	// =========================================================================
	// reassemble_tm_blocks()
	// =========================================================================

	public function test_reassemble_uses_fresh_translation(): void {
		$block  = $this->makeBlock( 'core/paragraph', '<p>Source</p>' );
		$markup = serialize_block( $block );
		$result = $this->call( 'reassemble_tm_blocks', [
			[ $block ],
			[ 0 => $markup ],
			[],
			[ 0 => '<p>Frisch übersetzt</p>' ],
		] );
		$this->assertStringContainsString( 'Frisch übersetzt', $result );
	}

	public function test_reassemble_uses_tm_hit_when_no_fresh(): void {
		$block  = $this->makeBlock( 'core/paragraph', '<p>Cached block</p>' );
		$markup = serialize_block( $block );
		$result = $this->call( 'reassemble_tm_blocks', [
			[ $block ],
			[ 0 => $markup ],
			[ $markup => '<p>Gecacht</p>' ],
			[],
		] );
		$this->assertStringContainsString( 'Gecacht', $result );
	}

	public function test_reassemble_preserves_whitespace_block_innerHTML(): void {
		$whitespace = $this->makeWhitespaceBlock();
		$result     = $this->call( 'reassemble_tm_blocks', [ [ $whitespace ], [], [], [] ] );
		// "\n\n" is not '' so array_filter keeps it; strip_interblock_br leaves
		// plain newlines alone. The whitespace innerHTML is preserved as-is.
		$this->assertSame( "\n\n", $result );
	}

	public function test_reassemble_falls_back_to_source_markup_for_skipped_block(): void {
		$block  = $this->makeBlock( 'core/paragraph', '<p>Original</p>' );
		$markup = serialize_block( $block );
		// Neither fresh nor TM hit — block was "skipped empty content"
		$result = $this->call( 'reassemble_tm_blocks', [ [ $block ], [ 0 => $markup ], [], [] ] );
		$this->assertStringContainsString( 'Original', $result );
	}

	public function test_reassemble_strips_interblock_br(): void {
		$a = $this->makeBlock( 'core/paragraph', '<p>A</p>' );
		$b = $this->makeBlock( 'core/paragraph', '<p>B</p>' );
		$ma = serialize_block( $a );
		$mb = serialize_block( $b );
		$result = $this->call( 'reassemble_tm_blocks', [
			[ $a, $b ],
			[ 0 => $ma, 1 => $mb ],
			[],
			[ 0 => "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n<br>\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->" ],
		] );
		$this->assertStringNotContainsString( '<br>', $result );
	}
}
