<?php
/**
 * Integration test — confirms the plugin boots cleanly inside a real
 * WordPress install and the public constants / autoloader / sub-module
 * classes are all present.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

    public function test_plugin_constants_are_defined(): void {

        $this->assertTrue( defined( 'LINGUAFORGE_FILE' ),    'LINGUAFORGE_FILE missing' );
        $this->assertTrue( defined( 'LINGUAFORGE_PATH' ),    'LINGUAFORGE_PATH missing' );
        $this->assertTrue( defined( 'LINGUAFORGE_URL' ),     'LINGUAFORGE_URL missing' );
        $this->assertTrue( defined( 'LINGUAFORGE_VERSION' ), 'LINGUAFORGE_VERSION missing' );
    }

    public function test_version_constant_matches_plugin_header(): void {

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data( LINGUAFORGE_FILE, false, false );

        $this->assertSame(
            $data['Version'],
            LINGUAFORGE_VERSION,
            'Plugin header Version and LINGUAFORGE_VERSION must match — bumping policy is documented in CONTRIBUTING.md.'
        );
    }

    public function test_ai_autoloader_resolves_core_classes(): void {

        $this->assertTrue(
            class_exists( '\LinguaForge\AI\Core\Plugin' ),
            'AI autoloader did not resolve Core\Plugin — Autoloader::register() likely never ran.'
        );

        $this->assertTrue(
            class_exists( '\LinguaForge\AI\Core\CacheStore' ),
            'AI autoloader did not resolve Core\CacheStore.'
        );

        $this->assertTrue(
            class_exists( '\LinguaForge\AI\Core\BlockTextExtractor' ),
            'AI autoloader did not resolve Core\BlockTextExtractor.'
        );
    }

    public function test_language_router_classes_are_loaded(): void {

        $this->assertTrue(
            class_exists( '\LinguaForge\Router\Context' ),
            'Language Router Context class not loaded.'
        );
    }

    public function test_meta_description_module_is_loaded(): void {

        $this->assertTrue(
            class_exists( '\LinguaForge\MetaDescription\Module' ),
            'Meta Description module not loaded.'
        );
    }

    public function test_block_text_extractor_extract_round_trip(): void {

        // extract() depends on parse_blocks/serialize_blocks (WordPress
        // core) — exercise it here in the integration suite where those
        // functions are available.
        $content = '<!-- wp:image {"alt":"Original alt text"} --><figure></figure><!-- /wp:image -->';

        [ $serialized, $map ] = \LinguaForge\AI\Core\BlockTextExtractor::extract( $content );

        $this->assertNotEmpty( $map, 'Expected at least one extracted attribute value.' );
        $this->assertStringContainsString( '__WPAI_', $serialized );
        $this->assertContains( 'Original alt text', array_values( $map ) );
    }
}
