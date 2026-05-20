<?php
/**
 * Unit tests for LinguaForge\AI\Core\BlockTextExtractor.
 *
 * These exercise the pure-function members (no WordPress required):
 *   • reinsert()              — placeholder → translated string replacement.
 *   • strip_interblock_br()   — removal of hallucinated <br> between blocks.
 *
 * extract() depends on WordPress's parse_blocks() / serialize_blocks() and
 * therefore lives in the integration suite, not here.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Core\BlockTextExtractor;

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/BlockTextExtractor.php';

final class BlockTextExtractorTest extends TestCase {

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
