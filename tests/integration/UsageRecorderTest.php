<?php
/**
 * Integration tests for LinguaForge\AI\Core\UsageRecorder — record(), query(),
 * row_count(), and clear_all().
 *
 * The context-stack methods (tracked, push_context, pop_context, current_context)
 * are already covered by UsageRecorderContextTest (unit, 13 tests). This suite
 * covers the DB layer that the context stack feeds into.
 *
 * Seeding strategy: push_context() / record() / pop_context() — this matches the
 * exact path that AbstractProvider::chat() uses in production, so we exercise the
 * real pipeline, not a back-door INSERT.
 *
 * Properties under test:
 *   1. record() without a context is a no-op — query() returns empty.
 *   2. record() + query() round-trip returns the correct row shape.
 *   3. record() ON DUPLICATE KEY UPDATE — same composite key twice → one row,
 *      summed tokens, incremented request_count.
 *   4. record() clamps negative tokens to 0.
 *   5. query() 'since' filter excludes rows outside the window.
 *   6. query() aggregates multiple feature/provider/model combinations.
 *   7. row_count() returns 0 on an empty table, correct count after seeding.
 *   8. clear_all() returns pre-clear count and leaves the table empty.
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\UsageRecorder;
use WP_UnitTestCase;

final class UsageRecorderTest extends WP_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        UsageRecorder::ensure_table();
        UsageRecorder::clear_all();
    }

    protected function tearDown(): void {
        UsageRecorder::clear_all();
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Seed one usage record using the real production pipeline.
     */
    private function seed(
        string $feature       = 'translation',
        string $provider      = 'anthropic',
        string $model         = 'claude-haiku',
        int    $input_tokens  = 100,
        int    $output_tokens = 200
    ): void {
        UsageRecorder::push_context( $feature );
        UsageRecorder::record( $provider, $model, $input_tokens, $output_tokens );
        UsageRecorder::pop_context();
    }

    // =========================================================================
    // record() — no-op without context
    // =========================================================================

    public function test_record_without_context_is_noop(): void {

        UsageRecorder::record( 'anthropic', 'claude-haiku', 100, 200 );

        $this->assertSame( [], UsageRecorder::query() );
    }

    // =========================================================================
    // record() + query() round-trip
    // =========================================================================

    public function test_record_and_query_return_correct_row_shape(): void {

        $this->seed( 'translation', 'anthropic', 'claude-haiku', 500, 300 );

        $rows = UsageRecorder::query();

        $this->assertCount( 1, $rows );
        $row = $rows[0];

        $this->assertSame( 'translation', $row['feature_key'] );
        $this->assertSame( 'anthropic',   $row['provider'] );
        $this->assertSame( 'claude-haiku', $row['model'] );
        $this->assertSame( 500,  $row['input_tokens'] );
        $this->assertSame( 300,  $row['output_tokens'] );
        $this->assertSame( 800,  $row['total_tokens'] );
        $this->assertSame( 1,    $row['request_count'] );
    }

    // =========================================================================
    // ON DUPLICATE KEY UPDATE — same composite key accumulates
    // =========================================================================

    public function test_record_same_bucket_accumulates_tokens(): void {

        $this->seed( 'translation', 'anthropic', 'claude-haiku', 100, 200 );
        $this->seed( 'translation', 'anthropic', 'claude-haiku', 50,  80 );

        $rows = UsageRecorder::query();

        $this->assertCount( 1, $rows );
        $this->assertSame( 150,  $rows[0]['input_tokens'] );
        $this->assertSame( 280,  $rows[0]['output_tokens'] );
        $this->assertSame( 430,  $rows[0]['total_tokens'] );
        $this->assertSame( 2,    $rows[0]['request_count'] );
    }

    // =========================================================================
    // Negative token clamping
    // =========================================================================

    public function test_record_clamps_negative_tokens_to_zero(): void {

        $this->seed( 'translation', 'anthropic', 'claude-haiku', -50, -100 );

        $rows = UsageRecorder::query();

        $this->assertSame( 0, $rows[0]['input_tokens'] );
        $this->assertSame( 0, $rows[0]['output_tokens'] );
        $this->assertSame( 0, $rows[0]['total_tokens'] );
    }

    // =========================================================================
    // query() — 'since' date filter
    // =========================================================================

    public function test_query_since_filter_excludes_earlier_rows(): void {

        global $wpdb;

        $this->seed( 'translation', 'anthropic', 'claude-haiku', 100, 200 );

        // Back-date the seeded row to yesterday so the 'since=today' filter excludes it.
        $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture; back-dating a row to verify date filtering.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}lingua_forge_ai_usage SET usage_date = %s",
            $yesterday
        ) );

        $rows_today = UsageRecorder::query( [ 'since' => gmdate( 'Y-m-d' ) ] );
        $rows_all   = UsageRecorder::query();

        $this->assertCount( 0, $rows_today );
        $this->assertCount( 1, $rows_all );
    }

    // =========================================================================
    // query() — GROUP BY aggregation across different combinations
    // =========================================================================

    public function test_query_groups_by_feature_provider_model(): void {

        $this->seed( 'translation',       'anthropic', 'claude-haiku', 100, 200 );
        $this->seed( 'meta-description',  'openai',    'gpt-4o-mini',  30,  50 );

        $rows = UsageRecorder::query();

        $this->assertCount( 2, $rows );

        // Ordered by total_tokens DESC → translation (300) before meta-description (80).
        $this->assertSame( 'translation',      $rows[0]['feature_key'] );
        $this->assertSame( 'meta-description', $rows[1]['feature_key'] );
    }

    // =========================================================================
    // row_count()
    // =========================================================================

    public function test_row_count_empty_table_returns_zero(): void {

        $this->assertSame( 0, UsageRecorder::row_count() );
    }

    public function test_row_count_reflects_distinct_rows(): void {

        $this->seed( 'translation',      'anthropic', 'claude-haiku' );
        $this->seed( 'meta-description', 'anthropic', 'claude-haiku' );
        // Same composite as first seed — ON DUPLICATE KEY UPDATE, so still 2 rows.
        $this->seed( 'translation',      'anthropic', 'claude-haiku' );

        $this->assertSame( 2, UsageRecorder::row_count() );
    }

    // =========================================================================
    // clear_all()
    // =========================================================================

    public function test_clear_all_returns_pre_clear_count(): void {

        $this->seed( 'translation',      'anthropic', 'claude-haiku' );
        $this->seed( 'meta-description', 'anthropic', 'claude-haiku' );

        $this->assertSame( 2, UsageRecorder::clear_all() );
    }

    public function test_clear_all_leaves_table_empty(): void {

        $this->seed();
        UsageRecorder::clear_all();

        $this->assertSame( 0, UsageRecorder::row_count() );
    }

    public function test_clear_all_on_empty_table_returns_zero(): void {

        $this->assertSame( 0, UsageRecorder::clear_all() );
    }
}
