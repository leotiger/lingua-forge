<?php
/**
 * Integration tests for LinguaForge\AI\Core\PrivacyIntegration.
 *
 * Covers the WordPress personal-data exporter and anonymising eraser registered
 * for the AI usage statistics table (lingua_forge_ai_usage).
 *
 * Strategy
 * --------
 * Each test seeds the usage table directly via $wpdb->insert() and then calls
 * the static PrivacyIntegration methods under test.  WP_UnitTestCase wraps every
 * test in a DB transaction rolled back on tearDown, so no manual cleanup is
 * needed for either the wp_users rows or the usage table rows.
 *
 * Table availability
 * ------------------
 * In the wp-env integration context the plugin is active and the usage table is
 * created on activation.  setUp() calls UsageRecorder::ensure_table() as a
 * belt-and-braces guard so the suite works even when run against a fresh wp-env
 * where the plugin has never been activated (e.g. CI cold-start).
 *
 * Test matrix
 * -----------
 *  Eraser:
 *    1. Unknown e-mail address → early return, items_removed = 0.
 *    2. Known user, no rows in the table → items_removed = 0.
 *    3. Known user, two rows, no pre-existing user_id=0 collision →
 *       rows merged to user_id=0, originals gone, items_removed = 2.
 *    4. Known user, one row, pre-existing user_id=0 row for the same
 *       (date, feature, provider, model) → counts summed, original gone,
 *       items_removed = 1.
 *  Exporter:
 *    5. Unknown e-mail address → data = [], done = true.
 *    6. Known user, one row → correct group_id, item_id, and data fields.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 * @since   2.3.1
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\PrivacyIntegration;
use LinguaForge\AI\Core\UsageRecorder;
use WP_UnitTestCase;

final class PrivacyIntegrationTest extends WP_UnitTestCase {

	// =========================================================================
	// Constants
	// =========================================================================

	private const EMAIL   = 'lf-privacy-test@example.com';
	private const DATE    = '2026-06-14';
	private const FEATURE = 'translation';
	private const PROV    = 'Anthropic';
	private const MODEL   = 'claude-3-5-sonnet-20241022';

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();
		// Guarantee the table exists regardless of plugin activation state.
		UsageRecorder::ensure_table();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** @return string Table name with WP prefix. */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lingua_forge_ai_usage';
	}

	/**
	 * Seed one row into the usage table.
	 *
	 * @param int    $user_id       WP user ID (0 = anonymous sentinel).
	 * @param string $date          Y-m-d.
	 * @param string $feature_key   Feature key string.
	 * @param string $provider      Provider name.
	 * @param string $model         Model string.
	 * @param int    $input_tokens
	 * @param int    $output_tokens
	 * @param int    $request_count
	 */
	private function seed_row(
		int    $user_id,
		string $date          = self::DATE,
		string $feature_key   = self::FEATURE,
		string $provider      = self::PROV,
		string $model         = self::MODEL,
		int    $input_tokens  = 100,
		int    $output_tokens = 50,
		int    $request_count = 1
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only seed; rolled back by WP_UnitTestCase transaction.
		$wpdb->insert(
			$this->table(),
			[
				'usage_date'    => $date,
				'user_id'       => $user_id,
				'feature_key'   => $feature_key,
				'provider'      => $provider,
				'model'         => $model,
				'input_tokens'  => $input_tokens,
				'output_tokens' => $output_tokens,
				'request_count' => $request_count,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d' ]
		);
	}

	/**
	 * Count rows in the usage table matching $where conditions.
	 *
	 * @param  array<string, mixed> $where  Column => value pairs.
	 * @return int
	 */
	private function count_rows( array $where ): int {
		global $wpdb;

		$conditions = [];
		$values     = [];

		foreach ( $where as $col => $val ) {
			if ( $val === null ) {
				$conditions[] = "`{$col}` IS NULL";
			} else {
				$conditions[] = is_int( $val ) ? "`{$col}` = %d" : "`{$col}` = %s";
				$values[]     = $val;
			}
		}

		$where_sql = implode( ' AND ', $conditions );
		$table     = $this->table();

		// $table = $wpdb->prefix + hardcoded suffix; $where_sql is built from a whitelist of
		// column names supplied by test methods as string literals — never from external input.
		// Values are bound via %d / %s in the conditions array above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) ( $values
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $values ) )
			: $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}" )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	// =========================================================================
	// Eraser tests
	// =========================================================================

	/**
	 * @test
	 * Erasing a non-existent e-mail address returns done immediately with no
	 * items_removed — there is no WP user to look up rows for.
	 */
	public function test_erase_unknown_email_returns_done_immediately(): void {

		$result = PrivacyIntegration::erase_usage_data( 'nobody@nowhere.invalid' );

		$this->assertSame( 0,    $result['items_removed'],  'items_removed' );
		$this->assertSame( 0,    $result['items_retained'], 'items_retained' );
		$this->assertSame( true, $result['done'],           'done' );
	}

	/**
	 * @test
	 * A known user who has no rows in the usage table returns items_removed = 0.
	 */
	public function test_erase_user_with_no_rows_returns_zero(): void {

		self::factory()->user->create( [ 'user_email' => self::EMAIL ] );

		$result = PrivacyIntegration::erase_usage_data( self::EMAIL );

		$this->assertSame( 0,    $result['items_removed'], 'items_removed' );
		$this->assertSame( true, $result['done'],          'done' );
	}

	/**
	 * @test
	 * When a user has two rows and no pre-existing user_id = 0 rows for the same
	 * (date, feature, provider, model) combinations, the eraser:
	 *   - moves both rows to user_id = 0 with counts intact,
	 *   - deletes the originals,
	 *   - returns items_removed = 2.
	 */
	public function test_erase_anonymises_rows_no_collision(): void {

		$user_id = self::factory()->user->create( [ 'user_email' => self::EMAIL ] );

		// Row A: translation / Anthropic
		$this->seed_row( $user_id, self::DATE, 'translation', self::PROV, self::MODEL, 100, 50, 1 );
		// Row B: meta-description / OpenAI (different feature + provider → different unique key)
		$this->seed_row( $user_id, self::DATE, 'meta-description', 'OpenAI', 'gpt-4o', 200, 80, 2 );

		$result = PrivacyIntegration::erase_usage_data( self::EMAIL );

		// Return value.
		$this->assertSame( 2,    $result['items_removed'], 'items_removed' );
		$this->assertSame( 0,    $result['items_retained'], 'items_retained' );
		$this->assertSame( true, $result['done'],           'done' );

		// Original user rows must be gone.
		$this->assertSame( 0, $this->count_rows( [ 'user_id' => $user_id ] ), 'original rows remain' );

		// Anonymous rows must now exist with original counts.
		$anon_translation = $this->count_rows( [
			'user_id'     => 0,
			'feature_key' => 'translation',
			'provider'    => self::PROV,
		] );
		$this->assertSame( 1, $anon_translation, 'anon translation row' );

		$anon_meta = $this->count_rows( [
			'user_id'     => 0,
			'feature_key' => 'meta-description',
			'provider'    => 'OpenAI',
		] );
		$this->assertSame( 1, $anon_meta, 'anon meta-description row' );
	}

	/**
	 * @test
	 * When a user_id = 0 row already exists for the same (date, feature, provider,
	 * model) as the user's row, the eraser sums the token/request counts into the
	 * existing anonymous row rather than creating a duplicate.
	 *
	 * Precondition:
	 *   user row:  input=100, output=50, requests=1
	 *   anon row:  input=300, output=120, requests=3
	 * Expected after erase:
	 *   single anon row: input=400, output=170, requests=4
	 */
	public function test_erase_merges_counts_on_collision(): void {

		global $wpdb;
		$table = $this->table();

		$user_id = self::factory()->user->create( [ 'user_email' => self::EMAIL ] );

		// Seed user row.
		$this->seed_row( $user_id, self::DATE, self::FEATURE, self::PROV, self::MODEL, 100, 50, 1 );

		// Seed pre-existing anonymous row for the SAME combination.
		$this->seed_row( 0, self::DATE, self::FEATURE, self::PROV, self::MODEL, 300, 120, 3 );

		$result = PrivacyIntegration::erase_usage_data( self::EMAIL );

		// Return value.
		$this->assertSame( 1,    $result['items_removed'], 'items_removed' );
		$this->assertSame( true, $result['done'],          'done' );

		// Original user row must be gone.
		$this->assertSame( 0, $this->count_rows( [ 'user_id' => $user_id ] ), 'original row remains' );

		// Only ONE anon row must exist (no duplicate).
		$this->assertSame( 1, $this->count_rows( [ 'user_id' => 0 ] ), 'more than one anon row' );

		// Counts must be summed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only assertion read on plugin-owned table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT input_tokens, output_tokens, request_count FROM %i WHERE user_id = 0',
				$table
			),
			ARRAY_A
		);

		$this->assertNotNull( $row, 'anonymous row missing' );
		$this->assertSame( 400, (int) $row['input_tokens'],  'input_tokens summed' );
		$this->assertSame( 170, (int) $row['output_tokens'], 'output_tokens summed' );
		$this->assertSame( 4,   (int) $row['request_count'], 'request_count summed' );
	}

	// =========================================================================
	// Exporter tests
	// =========================================================================

	/**
	 * @test
	 * Exporting a non-existent e-mail address returns an empty data array
	 * with done = true.
	 */
	public function test_export_unknown_email_returns_empty(): void {

		$result = PrivacyIntegration::export_usage_data( 'nobody@nowhere.invalid' );

		$this->assertSame( [],   $result['data'], 'data' );
		$this->assertSame( true, $result['done'], 'done' );
	}

	/**
	 * @test
	 * Exporting a known user with one row returns the correct WP personal-data
	 * export structure: group_id, group_label, item_id format, and all seven
	 * data fields (date, feature, provider, model, input/output tokens, requests).
	 */
	public function test_export_returns_usage_rows_in_correct_format(): void {

		$user_id = self::factory()->user->create( [ 'user_email' => self::EMAIL ] );

		$this->seed_row( $user_id, self::DATE, self::FEATURE, self::PROV, self::MODEL, 123, 456, 7 );

		$result = PrivacyIntegration::export_usage_data( self::EMAIL );

		$this->assertSame( true, $result['done'], 'done' );
		$this->assertCount( 1, $result['data'],   'one export item' );

		$item = $result['data'][0];

		// Group membership.
		$this->assertSame( 'linguaforge-ai-usage', $item['group_id'],   'group_id' );
		$this->assertNotEmpty( $item['group_label'],                      'group_label' );

		// Item ID must encode date + feature + provider.
		$this->assertStringContainsString( self::DATE,    $item['item_id'], 'item_id contains date' );
		$this->assertStringContainsString( self::FEATURE, $item['item_id'], 'item_id contains feature' );
		$this->assertStringContainsString( self::PROV,    $item['item_id'], 'item_id contains provider' );

		// Extract data fields into a name → value map for easy assertion.
		$data_map = [];
		foreach ( $item['data'] as $field ) {
			$data_map[ $field['name'] ] = $field['value'];
		}

		$this->assertCount( 7, $data_map, 'seven data fields' );

		// Spot-check values.
		$this->assertSame( self::DATE,    (string) ( $data_map[ __( 'Date',           'lingua-forge' ) ] ?? '' ), 'Date' );
		$this->assertSame( self::FEATURE, (string) ( $data_map[ __( 'Feature',        'lingua-forge' ) ] ?? '' ), 'Feature' );
		$this->assertSame( self::PROV,    (string) ( $data_map[ __( 'Provider',       'lingua-forge' ) ] ?? '' ), 'Provider' );
		$this->assertSame( self::MODEL,   (string) ( $data_map[ __( 'Model',          'lingua-forge' ) ] ?? '' ), 'Model' );
		$this->assertSame( 123,           (int)    ( $data_map[ __( 'Input tokens',   'lingua-forge' ) ] ?? 0  ), 'Input tokens' );
		$this->assertSame( 456,           (int)    ( $data_map[ __( 'Output tokens',  'lingua-forge' ) ] ?? 0  ), 'Output tokens' );
		$this->assertSame( 7,             (int)    ( $data_map[ __( 'Request count',  'lingua-forge' ) ] ?? 0  ), 'Request count' );
	}
}
