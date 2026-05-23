<?php
/**
 * Class LinguaForge\Router\Db\Migrator
 *
 * Manages the router's DB schema — the idx_lang index on wp_postmeta, and
 * the one-time rename of unprefixed meta keys to _lf_* equivalents.
 *
 * Migrations run in version order inside check_db_version().
 * Each migration must be idempotent: safe to run more than once.
 *
 * Version history:
 *   1.0 — idx_lang composite index on wp_postmeta (meta_key, meta_value(10)).
 *   1.1 — rename unprefixed meta keys to _lf_ equivalents.
 */

namespace LinguaForge\Router\Db;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Migrator {

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Priority 1 on plugins_loaded — must run before any query that benefits
		// from the idx_lang index on init.
		add_action( 'plugins_loaded', [ $this, 'check_db_version' ], 1 );
	}

	// =========================================================
	// DB VERSION CHECK
	// =========================================================

	public function check_db_version(): void {
		$stored = (string) get_option( 'lf_lang_router_version', '' );

		if ( $stored === Router::DB_VERSION ) return;

		// Run migrations in ascending version order.
		// version_compare( '', '1.0', '<' ) is true, so fresh installs run everything.
		if ( version_compare( $stored, '1.0', '<' ) ) {
			if ( ! $this->ensure_lang_index() ) return; // DDL failure — abort; retry next load.
		}

		if ( version_compare( $stored, '1.1', '<' ) ) {
			$this->rename_meta_keys(); // Data migration — always proceeds; errors are non-fatal.
		}

		update_option( 'lf_lang_router_version', Router::DB_VERSION, false );
	}

	// =========================================================
	// MIGRATION 1.0 — idx_lang INDEX
	// =========================================================

	public function ensure_lang_index(): bool {
		global $wpdb;

		$table      = $wpdb->postmeta;
		$index_name = 'idx_lang';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- INFORMATION_SCHEMA query to detect the idx_lang index before creating it; no WP API equivalent. $table and $index_name are bound via %s placeholders in prepare().
		$exists = $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(1)
			FROM INFORMATION_SCHEMA.STATISTICS
			WHERE table_schema = DATABASE()
			AND table_name = %s
			AND index_name = %s
		", $table, $index_name ) );

		if ( $exists ) return true;

		// DDL: CREATE INDEX on wp_postmeta — no WP API equivalent.
		// Identifiers cannot use %s placeholders; escaped with esc_sql() and backticks.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query(
			'CREATE INDEX `' . esc_sql( $index_name ) . '` ON `' . esc_sql( $table ) . '` (meta_key, meta_value(10))'
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $result !== false;
	}

	// =========================================================
	// MIGRATION 1.1 — RENAME UNPREFIXED META KEYS
	//
	// Renames legacy unprefixed meta keys to their _lf_ equivalents.
	// Safe approach:
	//   1. Rename _trid → _lf_trid globally (no collision risk; unique abbreviation).
	//   2. Use the now-renamed _lf_trid as a scoping anchor: only rename
	//      _lang (and the other keys) on posts that have an _lf_trid row,
	//      so we never touch rows belonging to other plugins.
	//
	// All queries are idempotent — re-running after partial completion is safe.
	// =========================================================

	public function rename_meta_keys(): void {
		global $wpdb;

		// Step 1 — rename _trid globally (idempotent: zero rows if already done).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time data migration on version bump; no WP API equivalent for bulk meta_key rename. Idempotent.
		$wpdb->update( $wpdb->postmeta, [ 'meta_key' => '_lf_trid' ], [ 'meta_key' => '_trid' ] );

		// Step 2 — rename remaining keys, scoped to posts that have _lf_trid
		// (i.e. are known Lingua Forge posts). Uses a JOIN that $wpdb->update()
		// cannot express, so we use a direct prepared query.
		$keys = [
			'_lang'                          => '_lf_lang',
			'_lang_previous'                 => '_lf_lang_previous',
			'_source_updated_at'             => '_lf_source_updated_at',
			'_translation_source_updated_at' => '_lf_translation_source_updated_at',
			'_search_content'                => '_lf_search_content',
		];

		foreach ( $keys as $old => $new ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time data migration; JOIN required to scope to LF posts; no WP API equivalent. $wpdb->postmeta is a trusted server-defined table name; old/new keys bound via %s placeholders.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->postmeta} pm_anchor
				         ON pm_anchor.post_id = pm.post_id
				        AND pm_anchor.meta_key = '_lf_trid'
				 SET pm.meta_key = %s
				 WHERE pm.meta_key = %s",
				$new,
				$old
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
	}
}
