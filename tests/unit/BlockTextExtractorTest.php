<?php
/**
 * Unit tests for LinguaForge\AI\Core\BlockTextExtractor.
 *
 * Covers all three public methods:
 *   • extract()               — replaces translatable block attr values with
 *                               __WPAI_N__ placeholders. Uses the parse_blocks /
 *                               serialize_blocks polyfills from ApiPolyfills.php
 *                               so these can run in the unit suite without WP.
 *   • reinsert()              — placeholder → translated string replacement.
 *   • strip_interblock_br()   — removal of hallucinated <br> between blocks.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Core\BlockTextExtractor;

require_once __DIR__ . '/ApiPolyfills.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/BlockTextExtractor.php';

final class BlockTextExtractorTest extends TestCase {

    // =========================================================================
    // extract()
    // =========================================================================

    public function test_extract_returns_content_unchanged_when_no_translatable_attrs(): void {

        // 'level' and 'textAlign' are not in TRANSLATABLE_ATTRS.
        $content = '<!-- wp:heading {"level":2,"textAlign":"left"} --><h2>Hello</h2><!-- /wp:heading -->';

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertSame( [], $map, 'No TRANSLATABLE_ATTRS present — map must be empty.' );
        // When map is empty extract() returns the original $content unchanged.
        $this->assertSame( $content, $out );
    }

    public function test_extract_replaces_alt_attr_with_placeholder(): void {

        $content = '<!-- wp:image {"alt":"A cat on a mat","id":1} --><figure></figure><!-- /wp:image -->';

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertCount( 1, $map );
        $this->assertArrayHasKey( '__WPAI_0__', $map );
        $this->assertSame( 'A cat on a mat', $map['__WPAI_0__'] );
        // The serialised block comment must carry the placeholder, not the original text.
        $this->assertStringContainsString( '__WPAI_0__', $out );
        $this->assertStringNotContainsString( 'A cat on a mat', $out );
    }

    public function test_extract_replaces_multiple_translatable_attrs_incrementally(): void {

        // alt → __WPAI_0__, caption → __WPAI_1__
        $content = '<!-- wp:image {"alt":"Sunset","caption":"Evening glow","id":5} --><figure></figure><!-- /wp:image -->';

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertCount( 2, $map );
        $this->assertSame( 'Sunset',       $map['__WPAI_0__'] );
        $this->assertSame( 'Evening glow', $map['__WPAI_1__'] );
        $this->assertStringContainsString( '__WPAI_0__', $out );
        $this->assertStringContainsString( '__WPAI_1__', $out );
    }

    public function test_extract_skips_empty_string_attr_value(): void {

        // alt is present but empty — must not generate a placeholder.
        $content = '<!-- wp:image {"alt":"","id":2} --><figure></figure><!-- /wp:image -->';

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertSame( [], $map );
        $this->assertSame( $content, $out );
    }

    public function test_extract_skips_whitespace_only_attr_value(): void {

        $content = '<!-- wp:image {"alt":"   ","id":3} --><figure></figure><!-- /wp:image -->';

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertSame( [], $map );
    }

    public function test_extract_walks_inner_blocks(): void {

        // Outer block has no translatable attrs; inner image block does.
        $inner   = '<!-- wp:image {"alt":"Nested alt"} --><figure></figure><!-- /wp:image -->';
        $content = "<!-- wp:group {} -->{$inner}<!-- /wp:group -->";

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertCount( 1, $map );
        $this->assertSame( 'Nested alt', $map['__WPAI_0__'] );
        $this->assertStringContainsString( '__WPAI_0__', $out );
    }

    public function test_extract_handles_multiple_sibling_blocks(): void {

        $b1      = '<!-- wp:image {"alt":"First"} --><figure></figure><!-- /wp:image -->';
        $b2      = '<!-- wp:image {"alt":"Second"} --><figure></figure><!-- /wp:image -->';
        $content = $b1 . $b2;

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        $this->assertCount( 2, $map );
        $this->assertSame( 'First',  $map['__WPAI_0__'] );
        $this->assertSame( 'Second', $map['__WPAI_1__'] );
    }

    public function test_extract_handles_search_block_button_text(): void {

        $content = '<!-- wp:search {"buttonText":"Go","label":"Search"} --><!-- /wp:search -->';

        [ $out, $map ] = BlockTextExtractor::extract( $content );

        // buttonText and label are both in TRANSLATABLE_ATTRS.
        $this->assertCount( 2, $map );
        $this->assertContains( 'Go',     $map );
        $this->assertContains( 'Search', $map );
    }

    // =========================================================================
    // reinsert()
    // =========================================================================

    public function test_reinsert_with_empty_translations_returns_content_unchanged(): void {

        $content = '<!-- wp:paragraph --><p>Hello __WPAI_0__</p><!-- /wp:paragraph -->';

        $this->assertSame(
            $content,
            BlockTextExtractor::reinsert( $content, [] )
        );
    }

    public function test_reinsert_replaces_placeholder_with_json_escaped_value(): void {

        $content = '{"alt":"__WPAI_0__"}';
        $result  = BlockTextExtractor::reinsert(
            $content,
            [ '__WPAI_0__' => 'Hello "world"' ]
        );

        // json_encode would wrap in quotes; the substr(…, 1, -1) strips
        // the outer quotes so the result fits inside the existing JSON
        // string field.
        $this->assertSame( '{"alt":"Hello \"world\""}', $result );
    }

    public function test_reinsert_handles_unicode_without_escaping(): void {

        $content = '"__WPAI_0__"';
        $result  = BlockTextExtractor::reinsert(
            $content,
            [ '__WPAI_0__' => 'Català és bonic' ]
        );

        $this->assertSame( '"Català és bonic"', $result );
    }

    // =========================================================================
    // strip_interblock_br()
    // =========================================================================

    public function test_strip_interblock_br_removes_br_between_blocks(): void {

        $content =
            '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->' .
            '<br>' .
            '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->';

        $stripped = BlockTextExtractor::strip_interblock_br( $content );

        $this->assertStringNotContainsString( '<br>', $stripped );
        $this->assertStringContainsString( '<p>One</p>',  $stripped );
        $this->assertStringContainsString( '<p>Two</p>',  $stripped );
    }

    public function test_strip_interblock_br_preserves_br_inside_blocks(): void {

        $content =
            '<!-- wp:paragraph --><p>Line one<br>Line two</p><!-- /wp:paragraph -->';

        // The <br> sits *inside* a block, not between blocks, so it must
        // survive untouched.
        $this->assertSame(
            $content,
            BlockTextExtractor::strip_interblock_br( $content )
        );
    }
}
