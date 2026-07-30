<?php
/**
 * Lingua Forge — update-manifest check-in telemetry (lingua-forge.com only).
 *
 * Included by ../lf-update-manifest.php via a single require_once — never
 * loaded standalone. ABSPATH, WP_REST_Request, $wpdb, and every other
 * WordPress symbol used below are provided by that parent mu-plugin's own
 * runtime. Kept in its own file/subfolder specifically so the manifest file
 * itself stays the short, readable "what to edit on every release" document
 * described in its own docblock — this file is everything else.
 *
 * What this records: every GET /wp-json/lingua-forge/v1/update request
 * already carries a `LinguaForge/{version}; WordPress/{version}; {home_url}`
 * User-Agent (see Linguaforge_Updater::fetch_manifest()'s wp_remote_get()
 * call in the plugin proper) — the same site-URL-plus-version facts every
 * WordPress site already sends unmitigated, with no opt-out, via WP_Http's
 * own default User-Agent on every core update check it performs. This file
 * parses that string and keeps a one-row-per-site tally: first seen, last
 * seen, how many times, and which Lingua Forge/WordPress versions it last
 * reported.
 *
 * Deliberately plain, untranslated strings throughout (no __()/esc_html_e())
 * — same convention the parent manifest file already follows, since a
 * mu-plugin can't rely on the distributed plugin's textdomain being loaded.
 *
 * Modeled directly on the companion Agnosis plugin's own
 * docs/agnosis-manifest-includes/telemetry.php (deployed the same way to
 * agnosis.art), so both self-hosted plugins are administered identically.
 * See that file's own docblock for the fuller privacy/GDPR reasoning behind
 * storing home_url() raw rather than hashed — the same reasoning applies
 * here unchanged.
 *
 * @package LinguaForge
 */

defined( 'ABSPATH' ) || exit;

/** Un-prefixed table name; $wpdb->prefix is applied at every call site. */
const LINGUAFORGE_MANIFEST_TELEMETRY_TABLE = 'linguaforge_manifest_checkins';

/** Admin submenu page slug. */
const LINGUAFORGE_MANIFEST_TELEMETRY_PAGE_SLUG = 'linguaforge-manifest-telemetry';

/** Bumped only when the CREATE TABLE statement below changes shape. */
const LINGUAFORGE_MANIFEST_TELEMETRY_DB_VERSION = '1';

/** Rows shown per admin-table page. */
const LINGUAFORGE_MANIFEST_TELEMETRY_PER_PAGE = 50;

// -----------------------------------------------------------------------------
// Storage
// -----------------------------------------------------------------------------

/**
 * Create (or upgrade) the check-in table, at most once per deploy.
 *
 * Mu-plugins have no activation hook to key this off, so this runs a cheap
 * option-version check on every call instead of unconditionally calling
 * dbDelta() on every single request.
 */
function linguaforge_manifest_telemetry_maybe_create_table(): void {
	if ( get_option( 'linguaforge_manifest_telemetry_db_version' ) === LINGUAFORGE_MANIFEST_TELEMETRY_DB_VERSION ) {
		return;
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = $wpdb->prefix . LINGUAFORGE_MANIFEST_TELEMETRY_TABLE;
	$charset_collate = $wpdb->get_charset_collate();

	// home_url's UNIQUE key is capped at a 191-char *index prefix* (not the
	// column length, which stays 512) — the longest prefix InnoDB's default
	// utf8mb4 row format allows without every host needing an explicit
	// ROW_FORMAT change; same 191 figure WordPress core itself uses for
	// wp_options.option_name.
	$sql = "CREATE TABLE {$table} (
		id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		home_url           VARCHAR(512)    NOT NULL,
		wp_version         VARCHAR(20)     NOT NULL DEFAULT '',
		linguaforge_version VARCHAR(20)    NOT NULL DEFAULT '',
		check_in_count     BIGINT UNSIGNED NOT NULL DEFAULT 1,
		first_seen         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		last_seen          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uq_home_url (home_url(191)),
		KEY idx_last_seen (last_seen)
	) $charset_collate;";

	dbDelta( $sql );

	update_option( 'linguaforge_manifest_telemetry_db_version', LINGUAFORGE_MANIFEST_TELEMETRY_DB_VERSION, false );
}

/**
 * Parse the requesting site's User-Agent and upsert its check-in row.
 *
 * Silently does nothing if the User-Agent doesn't match
 * Linguaforge_Updater::fetch_manifest()'s own format (a hand-curled request,
 * or a pre-telemetry Lingua Forge version) — telemetry is a nice-to-have,
 * never a reason to fail or slow down the actual manifest response that real
 * update checks depend on.
 *
 * @param WP_REST_Request $request The incoming update-check request.
 */
function linguaforge_manifest_telemetry_record( WP_REST_Request $request ): void {
	$user_agent = (string) $request->get_header( 'user-agent' );

	if ( ! preg_match( '/^LinguaForge\/([^;]+);\s*WordPress\/([^;]+);\s*(https?:\/\/.+)$/i', $user_agent, $m ) ) {
		return;
	}

	$linguaforge_version = sanitize_text_field( trim( $m[1] ) );
	$wp_version          = sanitize_text_field( trim( $m[2] ) );
	$home_url            = esc_url_raw( trim( $m[3] ) );

	if ( '' === $home_url ) {
		return;
	}

	linguaforge_manifest_telemetry_maybe_create_table();

	global $wpdb;
	$table = $wpdb->prefix . LINGUAFORGE_MANIFEST_TELEMETRY_TABLE;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upsert keyed on the table's own UNIQUE home_url index; no $wpdb helper covers ON DUPLICATE KEY UPDATE.
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus a hardcoded constant, never raw input; only the %s placeholders below carry request-derived values.
			"INSERT INTO {$table} (home_url, wp_version, linguaforge_version, check_in_count, first_seen, last_seen)
			 VALUES (%s, %s, %s, 1, NOW(), NOW())
			 ON DUPLICATE KEY UPDATE
			 	wp_version = VALUES(wp_version),
			 	linguaforge_version = VALUES(linguaforge_version),
			 	check_in_count = check_in_count + 1,
			 	last_seen = NOW()",
			$home_url,
			$wp_version,
			$linguaforge_version
		)
	);
}

// -----------------------------------------------------------------------------
// Admin UI — "Instance Check-ins", appended to the "Lingua Forge" top-level menu
// -----------------------------------------------------------------------------

/**
 * Submenu registration.
 *
 * The main plugin's own SettingsPage::register_menu() (ai/includes/Admin/)
 * adds the top-level "Lingua Forge" menu on admin_menu at the default
 * priority 10. This file lives entirely outside that bootstrap — it's a
 * separate mu-plugin — so rather than trying to slot into that exact call,
 * this just runs one priority tick later. WordPress renders a parent menu's
 * submenu items in registration order, so priority 11 guarantees this entry
 * is always appended after the plugin's own auto-generated first submenu
 * item.
 */
function linguaforge_manifest_telemetry_register_menu(): void {
	add_submenu_page(
		'lingua-forge',
		'Instance Check-ins',
		'Instance Check-ins',
		'manage_options',
		LINGUAFORGE_MANIFEST_TELEMETRY_PAGE_SLUG,
		'linguaforge_manifest_telemetry_render_page'
	);
}
add_action( 'admin_menu', 'linguaforge_manifest_telemetry_register_menu', 11 );

/** Renders the paginated check-ins table. */
function linguaforge_manifest_telemetry_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	linguaforge_manifest_telemetry_maybe_create_table();

	global $wpdb;
	$table = $wpdb->prefix . LINGUAFORGE_MANIFEST_TELEMETRY_TABLE;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET pagination, no state mutation; capability already checked above.
	$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$offset = ( $paged - 1 ) * LINGUAFORGE_MANIFEST_TELEMETRY_PER_PAGE;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus a hardcoded constant, never raw input.
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus a hardcoded constant, never raw input; only the %d placeholders below carry request-derived values.
			"SELECT home_url, wp_version, linguaforge_version, check_in_count, first_seen, last_seen FROM {$table}
			 ORDER BY last_seen DESC
			 LIMIT %d OFFSET %d",
			LINGUAFORGE_MANIFEST_TELEMETRY_PER_PAGE,
			$offset
		)
	);

	$total_pages = (int) ceil( $total / LINGUAFORGE_MANIFEST_TELEMETRY_PER_PAGE );
	?>
	<div class="wrap linguaforge-manifest-telemetry">
		<h1><span class="dashicons dashicons-translation"></span> Instance Check-ins</h1>
		<p style="color:#666">
			<?php echo esc_html( (string) $total ); ?> distinct site<?php echo 1 === $total ? '' : 's'; ?>
			have checked in for updates via this manifest.
		</p>

		<?php if ( empty( $rows ) ) : ?>
			<p style="margin-top:2rem;color:#666;">No check-ins recorded yet.</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped linguaforge-manifest-telemetry-table">
				<thead>
					<tr>
						<th>Site</th>
						<th style="width:8rem">Lingua Forge</th>
						<th style="width:8rem">WordPress</th>
						<th style="width:7rem">Check-ins</th>
						<th>First seen</th>
						<th>Last seen</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $row->home_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->home_url ); ?></a></td>
							<td><?php echo esc_html( $row->linguaforge_version ); ?></td>
							<td><?php echo esc_html( $row->wp_version ); ?></td>
							<td><?php echo esc_html( (string) $row->check_in_count ); ?></td>
							<td><?php echo esc_html( $row->first_seen ); ?></td>
							<td><?php echo esc_html( $row->last_seen ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php linguaforge_manifest_telemetry_render_pagination( $paged, $total_pages ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/** Prev/Next pager. */
function linguaforge_manifest_telemetry_render_pagination( int $current_page, int $total_pages ): void {
	if ( $total_pages <= 1 ) {
		return;
	}
	?>
	<div class="tablenav-pages" style="margin-top:1rem">
		<span class="displaying-num">Page <?php echo esc_html( (string) $current_page ); ?> of <?php echo esc_html( (string) $total_pages ); ?></span>
		<span style="margin-left:.75rem">
			<?php if ( $current_page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => LINGUAFORGE_MANIFEST_TELEMETRY_PAGE_SLUG, 'paged' => $current_page - 1 ], admin_url( 'admin.php' ) ) ); ?>">&larr; Previous</a>
			<?php endif; ?>
			<?php if ( $current_page < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => LINGUAFORGE_MANIFEST_TELEMETRY_PAGE_SLUG, 'paged' => $current_page + 1 ], admin_url( 'admin.php' ) ) ); ?>">Next &rarr;</a>
			<?php endif; ?>
		</span>
	</div>
	<?php
}
