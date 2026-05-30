<?php
/**
 * Integration tests for LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery;
use WP_UnitTestCase;

final class PatternDiscoveryIntegrationTest extends WP_UnitTestCase {

    /** Patterns registered during a test, cleaned up in tear_down(). */
    private array $registered_patterns = [];

    /** CPT slugs registered during a test, cleaned up in tear_down(). */
    private array $registered_cpts = [];

    public function set_up(): void {
        parent::set_up();
        delete_option( 'linguaforge_pattern_translations' );
    }

    public function tear_down(): void {
        foreach ( $this->registered_patterns as $name ) {
            if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
                unregister_block_pattern( $name );
            }
        }
        $this->registered_patterns = [];

        foreach ( $this->registered_cpts as $cpt ) {
            unregister_post_type( $cpt );
        }
        $this->registered_cpts = [];

        delete_option( 'linguaforge_pattern_translations' );
        parent::tear_down();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function register_pattern( string $name, array $args ): void {
        register_block_pattern( $name, $args );
        $this->registered_patterns[] = $name;
    }

    private function register_cpt( string $slug, string $label = '' ): void {
        register_post_type( $slug, [
            'public'   => true,
            'label'    => $label ?: ucfirst( $slug ),
            'labels'   => [
                'singular_name' => $label ?: ucfirst( $slug ),
            ],
        ] );
        $this->registered_cpts[] = $slug;
    }

    // ── name_to_key() ─────────────────────────────────────────────────────────

    public function test_name_to_key_replaces_slash_with_double_underscore(): void {
        $this->assertSame( 'mytheme__hero-block', PatternDiscovery::name_to_key( 'mytheme/hero-block' ) );
    }

    public function test_name_to_key_handles_no_slash(): void {
        $this->assertSame( 'simple-pattern', PatternDiscovery::name_to_key( 'simple-pattern' ) );
    }

    public function test_name_to_key_handles_empty_string(): void {
        $this->assertSame( '', PatternDiscovery::name_to_key( '' ) );
    }

    public function test_name_to_key_handles_multiple_slashes(): void {
        $this->assertSame( 'a__b__c', PatternDiscovery::name_to_key( 'a/b/c' ) );
    }

    // ── get_cpt_patterns() — filtering ───────────────────────────────────────

    public function test_pattern_without_post_types_is_excluded(): void {
        $this->register_cpt( 'lf-test-cpt-a', 'Test A' );
        $this->register_pattern( 'lf-test/no-post-types', [
            'title'   => 'No Post Types',
            'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
        ] );

        $names = array_column( PatternDiscovery::get_cpt_patterns(), 'name' );
        $this->assertNotContains( 'lf-test/no-post-types', $names );
    }

    public function test_pattern_scoped_to_internal_type_is_excluded(): void {
        $this->register_pattern( 'lf-test/internal-post', [
            'title'     => 'Internal Post',
            'content'   => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'postTypes' => [ 'post' ],
        ] );

        $names = array_column( PatternDiscovery::get_cpt_patterns(), 'name' );
        $this->assertNotContains( 'lf-test/internal-post', $names );
    }

    public function test_pattern_scoped_to_public_cpt_is_included(): void {
        $this->register_cpt( 'lf-test-product', 'Product' );
        $this->register_pattern( 'lf-test/product-hero', [
            'title'     => 'Product Hero',
            'content'   => '<!-- wp:paragraph --><p>Buy now</p><!-- /wp:paragraph -->',
            'postTypes' => [ 'lf-test-product' ],
        ] );

        $names = array_column( PatternDiscovery::get_cpt_patterns(), 'name' );
        $this->assertContains( 'lf-test/product-hero', $names );
    }

    public function test_cpt_labels_key_contains_singular_name(): void {
        $this->register_cpt( 'lf-test-item', 'Widget' );
        $this->register_pattern( 'lf-test/item-card', [
            'title'     => 'Item Card',
            'content'   => '<!-- wp:paragraph --><p>Widget</p><!-- /wp:paragraph -->',
            'postTypes' => [ 'lf-test-item' ],
        ] );

        $patterns = PatternDiscovery::get_cpt_patterns();
        $match    = array_filter( $patterns, fn( $p ) => $p['name'] === 'lf-test/item-card' );
        $this->assertNotEmpty( $match );

        $pattern = reset( $match );
        $this->assertArrayHasKey( 'lf-test-item', $pattern['cpt_labels'] );
        $this->assertSame( 'Widget', $pattern['cpt_labels']['lf-test-item'] );
    }

    public function test_pattern_scoped_to_mixed_types_only_surfaces_cpt_label(): void {
        $this->register_cpt( 'lf-test-event', 'Event' );
        $this->register_pattern( 'lf-test/mixed-types', [
            'title'     => 'Mixed Types',
            'content'   => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'postTypes' => [ 'post', 'lf-test-event' ],
        ] );

        $patterns = PatternDiscovery::get_cpt_patterns();
        $match    = array_filter( $patterns, fn( $p ) => $p['name'] === 'lf-test/mixed-types' );
        $this->assertNotEmpty( $match );

        $pattern = reset( $match );
        $this->assertArrayNotHasKey( 'post', $pattern['cpt_labels'] );
        $this->assertArrayHasKey( 'lf-test-event', $pattern['cpt_labels'] );
    }

    public function test_pattern_scoped_to_non_public_cpt_is_excluded(): void {
        register_post_type( 'lf-test-private', [ 'public' => false ] );
        $this->registered_cpts[] = 'lf-test-private';

        $this->register_pattern( 'lf-test/private-pattern', [
            'title'     => 'Private Pattern',
            'content'   => '<!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph -->',
            'postTypes' => [ 'lf-test-private' ],
        ] );

        $names = array_column( PatternDiscovery::get_cpt_patterns(), 'name' );
        $this->assertNotContains( 'lf-test/private-pattern', $names );
    }

    public function test_results_are_sorted_alphabetically_by_title(): void {
        $this->register_cpt( 'lf-test-sort-cpt', 'SortCpt' );
        $this->register_pattern( 'lf-test/zzz-last',   [ 'title' => 'ZZZ Last',   'content' => '<!-- wp:paragraph --><p>Z</p><!-- /wp:paragraph -->', 'postTypes' => [ 'lf-test-sort-cpt' ] ] );
        $this->register_pattern( 'lf-test/aaa-first',  [ 'title' => 'AAA First',  'content' => '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->', 'postTypes' => [ 'lf-test-sort-cpt' ] ] );
        $this->register_pattern( 'lf-test/mmm-middle', [ 'title' => 'MMM Middle', 'content' => '<!-- wp:paragraph --><p>M</p><!-- /wp:paragraph -->', 'postTypes' => [ 'lf-test-sort-cpt' ] ] );

        $ours  = array_values( array_filter( PatternDiscovery::get_cpt_patterns(), fn( $p ) => str_starts_with( $p['name'], 'lf-test/' ) ) );
        $names = array_column( $ours, 'name' );

        $pos_a = array_search( 'lf-test/aaa-first',  $names, true );
        $pos_m = array_search( 'lf-test/mmm-middle', $names, true );
        $pos_z = array_search( 'lf-test/zzz-last',   $names, true );

        $this->assertLessThan( $pos_m, $pos_a, 'AAA should sort before MMM.' );
        $this->assertLessThan( $pos_z, $pos_m, 'MMM should sort before ZZZ.' );
    }

    // ── save_translation / translation_exists / get_translation ──────────────

    public function test_translation_does_not_exist_before_save(): void {
        $this->assertFalse( PatternDiscovery::translation_exists( 'mytheme/hero', 'de' ) );
    }

    public function test_get_translation_returns_empty_string_before_save(): void {
        $this->assertSame( '', PatternDiscovery::get_translation( 'mytheme/hero', 'de' ) );
    }

    public function test_save_and_exists_round_trip(): void {
        PatternDiscovery::save_translation( 'mytheme/hero', 'de', '<!-- wp:paragraph --><p>Hallo</p><!-- /wp:paragraph -->' );
        $this->assertTrue( PatternDiscovery::translation_exists( 'mytheme/hero', 'de' ) );
    }

    public function test_save_and_get_round_trip(): void {
        $content = '<!-- wp:paragraph --><p>Hola mundo</p><!-- /wp:paragraph -->';
        PatternDiscovery::save_translation( 'mytheme/hero', 'ca', $content );
        $this->assertSame( $content, PatternDiscovery::get_translation( 'mytheme/hero', 'ca' ) );
    }

    public function test_save_overwrites_existing_translation(): void {
        PatternDiscovery::save_translation( 'mytheme/hero', 'de', 'First version' );
        PatternDiscovery::save_translation( 'mytheme/hero', 'de', 'Second version' );
        $this->assertSame( 'Second version', PatternDiscovery::get_translation( 'mytheme/hero', 'de' ) );
    }

    public function test_translations_for_different_languages_are_independent(): void {
        PatternDiscovery::save_translation( 'mytheme/hero', 'de', 'Auf Deutsch' );
        PatternDiscovery::save_translation( 'mytheme/hero', 'ca', 'En català' );

        $this->assertSame( 'Auf Deutsch', PatternDiscovery::get_translation( 'mytheme/hero', 'de' ) );
        $this->assertSame( 'En català',   PatternDiscovery::get_translation( 'mytheme/hero', 'ca' ) );
    }

    public function test_translations_for_different_patterns_are_independent(): void {
        PatternDiscovery::save_translation( 'theme/hero',   'de', 'Hero DE' );
        PatternDiscovery::save_translation( 'theme/footer', 'de', 'Footer DE' );

        $this->assertSame( 'Hero DE',   PatternDiscovery::get_translation( 'theme/hero',   'de' ) );
        $this->assertSame( 'Footer DE', PatternDiscovery::get_translation( 'theme/footer', 'de' ) );
    }

    public function test_empty_string_content_is_not_treated_as_existing(): void {
        PatternDiscovery::save_translation( 'mytheme/hero', 'de', '' );
        $this->assertFalse( PatternDiscovery::translation_exists( 'mytheme/hero', 'de' ) );
    }

    public function test_translations_persisted_to_option_with_autoload_false(): void {
        PatternDiscovery::save_translation( 'mytheme/hero', 'de', 'Welt' );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct SQL required to verify autoload flag in option row.
        $autoload = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                'linguaforge_pattern_translations'
            )
        );

        $this->assertContains( $autoload, [ 'no', 'off' ], 'linguaforge_pattern_translations must not autoload.' );
    }
}
