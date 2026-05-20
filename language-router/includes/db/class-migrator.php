<?php
/**
 * Class LinguaForge\Router\Db\Migrator
 *
 * Manages the router's DB schema — currently the idx_lang index on
 * wp_postmeta that speeds up _lang meta queries across large post tables.
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
		$stored = get_option( 'lf_lang_router_version' );

		if ( $stored === Router::DB_VERSION ) return;

		$ok = $this->ensure_lang_index();

		if ( $ok !== false ) {
			update_option( 'lf_lang_router_version', Router::DB_VERSION, false );
		}
	}

	// =========================================================
	// SCHEMA
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
}
