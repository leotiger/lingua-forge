<?php
/**
 * Integration tests for LinguaForge\AI\Core\TranslationMemory — stats() and clear_all().
 *
 * These methods hit $wpdb against the plugin's custom TM table and are
 * the primary data source for CacheStatsPanel's Translation Memory panel.
 * Zero test coverage before 2.1.9.
 *
 * Properties under test:
 *   1. stats() on an empty table returns the correct zero shape.
 *   2. stats() after storing blocks returns accurate row count, bytes_estimate > 0.
 *   3. clear_all() returns the pre-clear row count and leaves the table empty.
 *
 * setUp() calls ensure_table() so the table exists before tests run.
 * clear_all() in setUp/tearDown provides isolation independent of WP
 * transaction rollback (DDL tables are not rolled back between tests).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\TranslationMemory;
use WP_UnitTestCase;

final class TranslationMemoryTest extends WP_UnitTestCase {

    /** Reusable store args — deterministic, short enough to keep tests readable. */
    private const SRC  = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
    private const TRAN = '<!-- wp:paragraph --><p>Hallo</p><!-- /wp:paragraph -->';

    protected function setUp(): void {
        parent::setUp();
        TranslationMemory::ensure_table();
        TranslationMemory::clear_all();
    }

    protected function tearDown(): void {
        TranslationMemory::clear_all();
        parent::tearDown();
    }

    // =========================================================================
    // stats()
    // =========================================================================

    public function test_stats_empty_table_returns_zero_shape(): void {

        $stats = TranslationMemory::stats();

        $this->assertSame( 0,  $stats['rows'] );
        $this->assertSame( 0,  $stats['total_hits'] );
        $this->assertSame( '', $stats['oldest'] );
        $this->assertSame( '', $stats['newest'] );
        $this->assertSame( 0,  $stats['bytes_estimate'] );
    }

    public function test_stats_after_storing_returns_correct_row_count(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );
        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'fr', 'g-hash', 'c-sig' );

        $this->assertSame( 2, TranslationMemory::stats()['rows'] );
    }

    public function test_stats_bytes_estimate_is_positive_after_store(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );

        $this->assertGreaterThan( 0, TranslationMemory::stats()['bytes_estimate'] );
    }

    public function test_stats_bytes_estimate_reflects_text_length(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );

        $expected_min = strlen( self::SRC ) + strlen( self::TRAN );

        $this->assertGreaterThanOrEqual( $expected_min, TranslationMemory::stats()['bytes_estimate'] );
    }

    public function test_stats_oldest_and_newest_are_date_strings(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );

        $stats = TranslationMemory::stats();
        $today = gmdate( 'Y-m-d' );

        $this->assertSame( $today, $stats['oldest'] );
        $this->assertSame( $today, $stats['newest'] );
    }

    public function test_store_is_idempotent_on_same_key(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );
        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' ); // same key → INSERT IGNORE

        $this->assertSame( 1, TranslationMemory::stats()['rows'] );
    }

    // =========================================================================
    // clear_all()
    // =========================================================================

    public function test_clear_all_returns_pre_clear_count(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );
        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'fr', 'g-hash', 'c-sig' );

        $this->assertSame( 2, TranslationMemory::clear_all() );
    }

    public function test_clear_all_leaves_table_empty(): void {

        TranslationMemory::store( self::SRC, self::TRAN, 'en', 'de', 'g-hash', 'c-sig' );
        TranslationMemory::clear_all();

        $this->assertSame( 0, TranslationMemory::stats()['rows'] );
    }

    public function test_clear_all_on_empty_table_returns_zero(): void {

        $this->assertSame( 0, TranslationMemory::clear_all() );
    }
}
