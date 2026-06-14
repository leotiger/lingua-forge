<?php

namespace LinguaForge\AI\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress personal-data integration for the AI usage statistics table.
 *
 * Registers an exporter and an eraser with WordPress's privacy tools
 * (Tools → Export Personal Data / Tools → Erase Personal Data) so that
 * AI usage rows keyed by `user_id` are included in data exports and
 * removed when a user's data is erased.
 *
 * The `_lf_order_lang` WooCommerce order meta is deliberately excluded:
 * it contains only a two-character language code (no PII) and is covered
 * by WooCommerce's own order anonymisation flow.
 *
 * @package LinguaForge\AI\Core
 * @since   2.3.1
 */
class PrivacyIntegration {

	/**
	 * Register the exporter and eraser filters.
	 * Called once from ai.php on init.
	 */
	public static function register(): void {

		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers',  [ self::class, 'register_eraser'   ] );
	}

	// ── Filter callbacks ────────────────────────────────────────────────────

	/**
	 * @param  array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {

		$exporters['lingua-forge-ai-usage'] = [
			'exporter_friendly_name' => __( 'Lingua Forge — AI Usage Statistics', 'lingua-forge' ),
			'callback'               => [ self::class, 'export_usage_data' ],
		];

		return $exporters;
	}

	/**
	 * @param  array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {

		$erasers['lingua-forge-ai-usage'] = [
			'eraser_friendly_name' => __( 'Lingua Forge — AI Usage Statistics', 'lingua-forge' ),
			'callback'             => [ self::class, 'erase_usage_data' ],
		];

		return $erasers;
	}

	// ── Exporter ────────────────────────────────────────────────────────────

	/**
	 * Export all AI usage rows for the given email address.
	 *
	 * WordPress calls exporters with ($email_address, $page) for pagination.
	 * Our table is small (one row per day/feature/provider/model bucket per user),
	 * so we export everything in one page.
	 *
	 * @param  string $email_address
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_usage_data( string $email_address ): array {

		$user = get_user_by( 'email', $email_address );
		if ( ! $user instanceof \WP_User ) {
			return [ 'data' => [], 'done' => true ];
		}

		global $wpdb;

		$table = $wpdb->prefix . 'lingua_forge_ai_usage';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query on plugin-owned table; %i escapes the table identifier safely (WP 6.2+).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT usage_date, feature_key, provider, model, input_tokens, output_tokens, request_count
				   FROM %i
				  WHERE user_id = %d
				  ORDER BY usage_date DESC',
				$table,
				$user->ID
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [ 'data' => [], 'done' => true ];
		}

		$export_items = [];

		foreach ( $rows as $row ) {
			$export_items[] = [
				'group_id'    => 'linguaforge-ai-usage',
				'group_label' => __( 'Lingua Forge AI Usage Statistics', 'lingua-forge' ),
				'item_id'     => 'lf-usage-' . $row['usage_date'] . '-' . $row['feature_key'] . '-' . $row['provider'],
				'data'        => [
					[
						'name'  => __( 'Date', 'lingua-forge' ),
						'value' => $row['usage_date'],
					],
					[
						'name'  => __( 'Feature', 'lingua-forge' ),
						'value' => $row['feature_key'],
					],
					[
						'name'  => __( 'Provider', 'lingua-forge' ),
						'value' => $row['provider'],
					],
					[
						'name'  => __( 'Model', 'lingua-forge' ),
						'value' => $row['model'],
					],
					[
						'name'  => __( 'Input tokens', 'lingua-forge' ),
						'value' => (int) $row['input_tokens'],
					],
					[
						'name'  => __( 'Output tokens', 'lingua-forge' ),
						'value' => (int) $row['output_tokens'],
					],
					[
						'name'  => __( 'Request count', 'lingua-forge' ),
						'value' => (int) $row['request_count'],
					],
				],
			];
		}

		return [ 'data' => $export_items, 'done' => true ];
	}

	// ── Eraser ──────────────────────────────────────────────────────────────

	/**
	 * Anonymise AI usage rows for the given email address.
	 *
	 * Rather than deleting rows (which would destroy aggregate billing data),
	 * we merge the user's rows into the anonymous bucket (user_id = 0 — the
	 * sentinel value for system / WP-CLI calls) and then delete the
	 * user-identified originals.  If a user_id = 0 row already exists for the
	 * same (usage_date, feature_key, provider, model) combination, the token
	 * counts and request_count are summed into it via an UPDATE…JOIN.  Rows with
	 * no anon counterpart are inserted fresh via INSERT IGNORE.  The personal
	 * data — the link between a WordPress user ID and the usage rows — is
	 * removed; the aggregate numbers are retained.
	 *
	 * @param  string $email_address
	 * @return array{items_removed: int, items_retained: int, messages: list<string>, done: bool}
	 */
	public static function erase_usage_data( string $email_address ): array {

		$user = get_user_by( 'email', $email_address );
		if ( ! $user instanceof \WP_User ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		global $wpdb;

		$table = $wpdb->prefix . 'lingua_forge_ai_usage';

		// Count rows about to be anonymised.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query on plugin-owned table; %i escapes the table identifier safely (WP 6.2+).
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $table, $user->ID )
		);

		if ( 0 === $count ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		// Two-step merge — avoids VALUES() which is undefined in INSERT…SELECT
		// ON DUPLICATE KEY UPDATE on MariaDB and MySQL < 8.0.20.
		//
		// Step 1: Sum the user's token counts into any pre-existing user_id = 0
		// row that shares the same (usage_date, feature_key, provider, model).
		// The multi-table UPDATE JOIN is supported by all MySQL/MariaDB versions.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct UPDATE on plugin-owned table; %i escapes both table identifiers safely (WP 6.2+); SQL template is a string literal.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS anon
				 INNER JOIN %i AS usr
				         ON usr.usage_date  = anon.usage_date
				        AND usr.feature_key = anon.feature_key
				        AND usr.provider    = anon.provider
				        AND usr.model       = anon.model
				        AND usr.user_id     = %d
				        AND anon.user_id    = 0
				    SET anon.input_tokens  = anon.input_tokens  + usr.input_tokens,
				        anon.output_tokens = anon.output_tokens + usr.output_tokens,
				        anon.request_count = anon.request_count + usr.request_count',
				$table,
				$table,
				$user->ID
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Step 2: Insert new anon rows for (date, feature, provider, model)
		// combinations that had no pre-existing user_id = 0 row.
		// INSERT IGNORE skips rows that would violate the unique key (i.e. those
		// already handled by the UPDATE above).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct INSERT…SELECT on plugin-owned table; %i escapes both table identifiers safely (WP 6.2+); SQL template is a string literal.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i
				       (usage_date, user_id, feature_key, provider, model,
				        input_tokens, output_tokens, request_count)
				 SELECT usage_date, 0, feature_key, provider, model,
				        input_tokens, output_tokens, request_count
				   FROM %i
				  WHERE user_id = %d',
				$table,
				$table,
				$user->ID
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Remove the now-merged user-identified rows — personal data is gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct DELETE on plugin-owned table; no WP API equivalent for parameterised table name.
		$wpdb->delete( $table, [ 'user_id' => $user->ID ], [ '%d' ] );

		return [
			'items_removed'  => $count,
			'items_retained' => 0,
			'messages'       => [],
			'done'           => true,
		];
	}
}
