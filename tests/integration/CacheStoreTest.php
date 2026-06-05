<?php
/**
 * Integration tests for LinguaForge\AI\Core\CacheStore — stats() and clear_all().
 *
 * These methods hit $wpdb against the plugin's custom cache table and are
 * the primary data source for CacheStatsPanel. Zero test coverage before 2.1.9.
 *
 * Properties under test:
 *   1. stats() on an empty table returns the correct zero shape.
 *   2. stats() after seeding returns accurate row count, hit count, and dates.
 *   3. clear_all() returns the pre-clear row count and leaves the table empty.
 *
 * setUp() enables the cache (set() is a no-op when disabled) and calls
 * ensure_table() so the table exists before any test touches it.
 * clear_all() in setUp/tearDown keeps tests isolated without relying on
 * WP_UnitTestCase transaction rollback for DDL-created tables.
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\CacheStore;
use WP_UnitTestCase;

final class CacheStoreTest extends WP_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        CacheStore::ensure_table();
        update_option( 'linguaforge_api_cache_enabled', true );
        CacheStore::clear_all();
    }

    protected function tearDown(): void {
        CacheStore::clear_all();
        parent::tearDown();
    }

    // =========================================================================
    // stats()
    // =========================================================================

    public function test_stats_empty_table_returns_zero_shape(): void {

        $stats = CacheStore::stats();

        $this->assertSame( 0,  $stats['rows'] );
        $this->assertSame( 0,  $stats['total_hits'] );
        $this->assertSame( '', $stats['oldest'] );
        $this->assertSame( '', $stats['newest'] );
    }

    public function test_stats_after_seeding_returns_correct_row_count(): void {

        $post_id = (int) self::factory()->post->create();
        CacheStore::set( $post_id, 'translation_de', 'hash-abc', [ 'output' => '<p>Hallo</p>' ] );
        CacheStore::set( $post_id, 'translation_fr', 'hash-def', [ 'output' => '<p>Bonjour</p>' ] );

        $stats = CacheStore::stats();

        $this->assertSame( 2, $stats['rows'] );
    }

    public function test_stats_oldest_and_newest_are_date_strings(): void {

        $post_id = (int) self::factory()->post->create();
        CacheStore::set( $post_id, 'translation_de', 'hash-abc', [ 'output' => '<p>Test</p>' ] );

        $stats = CacheStore::stats();

        $today = gmdate( 'Y-m-d' );
        $this->assertSame( $today, $stats['oldest'] );
        $this->assertSame( $today, $stats['newest'] );
    }

    public function test_stats_total_hits_reflects_cache_hits(): void {

        global $wpdb;

        $post_id = (int) self::factory()->post->create();
        CacheStore::set( $post_id, 'translation_de', 'hash-abc', [ 'output' => '<p>Test</p>' ] );

        // Simulate cache hits by directly updating hit_count in the table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test seeding; no production path.
        $wpdb->query( "UPDATE {$wpdb->prefix}lingua_forge_ai_cache SET hit_count = 5" );

        $stats = CacheStore::stats();

        $this->assertSame( 5, $stats['total_hits'] );
    }

    // =========================================================================
    // clear_all()
    // =========================================================================

    public function test_clear_all_returns_pre_clear_count(): void {

        $post_id = (int) self::factory()->post->create();
        CacheStore::set( $post_id, 'translation_de', 'hash-abc', [ 'output' => '<p>A</p>' ] );
        CacheStore::set( $post_id, 'translation_fr', 'hash-def', [ 'output' => '<p>B</p>' ] );

        $count = CacheStore::clear_all();

        $this->assertSame( 2, $count );
    }

    public function test_clear_all_leaves_table_empty(): void {

        $post_id = (int) self::factory()->post->create();
        CacheStore::set( $post_id, 'translation_de', 'hash-abc', [ 'output' => '<p>Test</p>' ] );

        CacheStore::clear_all();

        $this->assertSame( 0, CacheStore::stats()['rows'] );
    }

    public function test_clear_all_on_empty_table_returns_zero(): void {

        $this->assertSame( 0, CacheStore::clear_all() );
    }
}
