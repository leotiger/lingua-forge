<?php
/**
 * Integration tests for the lingua-forge/missing-translation-notice block.
 *
 * The block's render_callback returns content only when
 * LINGUAFORGE_LANG_FALLBACK_ACTIVE is defined and truthy. That constant
 * is set by Routing\Redirector::handle_singular_redirect() when a
 * source-language post is being served at a target-language URL because
 * no translation exists in the request locale.
 *
 * Test isolation note: PHP constants cannot be undefined once set, so
 * the "fallback NOT active" case runs in a separate process. The
 * remaining tests all assume the constant IS defined and can run in
 * the shared process.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use WP_UnitTestCase;

final class MissingTranslationNoticeBlockTest extends WP_UnitTestCase {

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_block_renders_empty_when_fallback_not_active(): void {

        $this->assertFalse(
            defined( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE' ),
            'Fixture invariant: this test requires the fallback constant undefined. '
            . '@runInSeparateProcess ensures a clean PHP process.'
        );

        $html = do_blocks( '<!-- wp:lingua-forge/missing-translation-notice /-->' );

        $this->assertSame(
            '',
            trim( $html ),
            'Block must emit no output when LINGUAFORGE_LANG_FALLBACK_ACTIVE is not set.'
        );
    }

    public function test_block_renders_notice_when_fallback_active(): void {

        $this->define_fallback_active();

        $html = do_blocks( '<!-- wp:lingua-forge/missing-translation-notice /-->' );

        $this->assertNotEmpty( trim( $html ) );
        $this->assertStringContainsString( 'role="status"', $html );
        // Default message from block.json must be present.
        $this->assertStringContainsString(
            "This page is shown in its original language because a translation isn",
            $html
        );
    }

    public function test_block_respects_custom_message_text(): void {

        $this->define_fallback_active();

        $html = do_blocks(
            '<!-- wp:lingua-forge/missing-translation-notice '
            . '{"messageText":"Cette page n’est pas encore traduite."} /-->'
        );

        $this->assertStringContainsString( 'Cette page n', $html );
    }

    public function test_block_omits_link_when_show_home_link_is_false(): void {

        $this->define_fallback_active();

        $html = do_blocks(
            '<!-- wp:lingua-forge/missing-translation-notice {"showHomeLink":false} /-->'
        );

        $this->assertStringNotContainsString( '<a href=', $html );
    }

    public function test_block_renders_link_when_show_home_link_is_true(): void {

        $this->define_fallback_active();

        $html = do_blocks(
            '<!-- wp:lingua-forge/missing-translation-notice {"showHomeLink":true,"homeLinkText":"Aller à l’accueil"} /-->'
        );

        $this->assertStringContainsString( '<a href=', $html );
        $this->assertStringContainsString( 'Aller', $html );
    }

    public function test_block_escapes_html_in_message_text(): void {

        $this->define_fallback_active();

        $html = do_blocks(
            '<!-- wp:lingua-forge/missing-translation-notice '
            . '{"messageText":"<script>alert(1)</script>"} /-->'
        );

        // The script tag must be escaped, not passed through.
        $this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
        $this->assertStringContainsString(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $html
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function define_fallback_active(): void {
        if ( ! defined( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE' ) ) {
            define( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE', true );
        }
    }
}
