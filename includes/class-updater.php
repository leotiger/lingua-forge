<?php
/**
 * Self-hosted update checker.
 *
 * Hooks into WordPress's plugin-update machinery so WordPress admin surfaces
 * "Update available" notices and runs the one-click updater — without the
 * plugin being listed on WordPress.org.
 *
 * Flow:
 *  1. On every WordPress update check (`pre_set_site_transient_update_plugins`),
 *     we fetch a small JSON manifest from lingua-forge.com and cache it for
 *     CACHE_TTL seconds (default: 12 h).
 *  2. If the manifest version is newer than LINGUAFORGE_VERSION we inject an
 *     entry into `$transient->response`, which is what WordPress reads to show
 *     the update badge and trigger the one-click updater.
 *  3. On the `plugins_api` hook we respond to "plugin_information" requests for
 *     our slug, returning a populated object that fills the "View version
 *     details" modal (changelog, description, etc.).
 *  4. After a successful update we purge the cached manifest so the next check
 *     re-fetches immediately.
 *
 * Manifest format — see docs/update-manifest.json for the full template.
 *
 * @package LinguaForge
 * @since   1.7.2
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Linguaforge_Updater
 *
 * All methods are static; the class is a namespace for the update-checker
 * logic and carries no instance state.
 */
class Linguaforge_Updater {

	/**
	 * URL of the update manifest JSON on lingua-forge.com.
	 *
	 * The file at this URL must be publicly readable and return a valid JSON
	 * object.  See docs/update-manifest.json for the expected schema.
	 *
	 * @var string
	 */
	const MANIFEST_URL = 'https://lingua-forge.com/wp-json/lingua-forge/v1/update';

	/**
	 * WordPress transient key used to cache the manifest.
	 *
	 * Follows the `linguaforge_` prefix policy for transient / option keys.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'linguaforge_update_manifest';

	/**
	 * How long the manifest is cached (seconds).  Default: 12 hours.
	 *
	 * Expressed as a literal rather than `12 * HOUR_IN_SECONDS` because PHP
	 * class constants require compile-time scalar expressions, and WP's
	 * `HOUR_IN_SECONDS` is a runtime `define()`.
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200; // 12 * 3600

	/**
	 * Plugin basename as WordPress stores it in update transients.
	 *
	 * @var string
	 */
	const PLUGIN_BASENAME = 'lingua-forge/lingua-forge.php';

	/**
	 * Register all WordPress hooks.
	 *
	 * Call once from lingua-forge.php, gated on is_admin() so the remote
	 * manifest fetch never fires on the frontend.
	 */
	public static function init(): void {
		// Inject (or clear) our entry in the plugin-update transient.
		add_filter(
			'pre_set_site_transient_update_plugins',
			[ self::class, 'check_for_update' ]
		);

		// Serve plugin information for the "View version details" modal.
		add_filter(
			'plugins_api',
			[ self::class, 'plugin_info' ],
			10,
			3
		);

		// Purge cached manifest after any plugin update completes, so
		// a subsequent check immediately re-fetches the latest manifest.
		add_action(
			'upgrader_process_complete',
			[ self::class, 'purge_cache' ],
			10,
			2
		);

		// Also purge when WordPress itself force-refreshes the update_plugins
		// site transient (e.g. "Check Again" on the Updates screen).  Without
		// this, our 12-hour transient keeps injecting stale manifest data into
		// every fresh WordPress update check.
		add_action(
			'delete_site_transient_update_plugins',
			[ self::class, 'purge_manifest_cache' ]
		);

		// Add a "View details" link to the plugin row on the Plugins screen.
		add_filter(
			'plugin_row_meta',
			[ self::class, 'add_view_details_link' ],
			10,
			2
		);
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Inject update information into the WordPress plugin-update transient.
	 *
	 * WordPress passes the transient to this filter before broadcasting it to
	 * all registered `update_plugins` consumers.  We add an entry to
	 * `$transient->response` when a newer version is available, or to
	 * `$transient->no_update` when the installed version is current.
	 *
	 * @param \stdClass $transient The plugins_update transient.
	 * @return \stdClass
	 */
	public static function check_for_update( \stdClass $transient ): \stdClass {
		// WordPress only populates $transient->checked after it has queried
		// the installed plugins list.  Bail early if it's not ready yet.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$manifest = self::fetch_manifest();
		if ( ! $manifest ) {
			return $transient;
		}

		// Read the installed version from the plugin file on disk rather than
		// from the LINGUAFORGE_VERSION constant.  When WordPress re-runs this
		// filter immediately after an upgrade (still within the same update.php
		// request), the constant still reflects the old version while the file
		// on disk already contains the new one.  Reading from disk prevents us
		// from re-injecting a spurious "update available" entry that would force
		// the user to click Update a second time before the badge clears.
		$file_data         = get_file_data(
			WP_PLUGIN_DIR . '/' . self::PLUGIN_BASENAME,
			[ 'Version' => 'Version' ]
		);
		$installed_version = $file_data['Version'] ?? LINGUAFORGE_VERSION;

		if ( version_compare( $manifest->version, $installed_version, '>' ) ) {
			// Newer version available — WordPress will show the update badge.
			$transient->response[ self::PLUGIN_BASENAME ] = self::build_update_object( $manifest );
		} else {
			// Up-to-date — populate no_update so WP doesn't show a stale notice.
			$transient->no_update[ self::PLUGIN_BASENAME ] = self::build_no_update_object( $manifest );
		}

		return $transient;
	}

	/**
	 * Respond to WordPress's plugin-information API for the details modal.
	 *
	 * WordPress fires `plugins_api` when the user clicks "View version details"
	 * on the plugins list or update screen.  We intercept only requests for our
	 * own slug; everything else falls through to the default handler.
	 *
	 * @param false|object|array $result  Pre-existing result (false = not handled yet).
	 * @param string             $action  Requested API action.
	 * @param object             $args    Request arguments (slug, fields, etc.).
	 * @return false|object
	 */
	public static function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== 'lingua-forge' ) {
			return $result;
		}

		$manifest = self::fetch_manifest();

		// Even when the manifest is temporarily unreachable, return a minimal
		// info object from locally known data so WordPress never falls through
		// to the .org API and shows "Plugin not found."
		return self::build_info_object( $manifest ?: new \stdClass() );
	}

	/**
	 * Purge the cached manifest after a plugin update completes.
	 *
	 * Ensures the next update check fetches a fresh manifest rather than
	 * serving the (now stale) pre-update cached copy.
	 *
	 * @param \WP_Upgrader $upgrader   The upgrader instance (unused).
	 * @param array        $hook_extra Extra info: type, action, plugins, etc.
	 */
	public static function purge_cache( $upgrader, array $hook_extra ): void {
		if (
			isset( $hook_extra['type'], $hook_extra['action'] ) &&
			'plugin' === $hook_extra['type'] &&
			'update' === $hook_extra['action']
		) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Purge the cached manifest whenever WordPress force-refreshes its own
	 * plugin-update site transient (e.g. "Check Again" on the Updates screen,
	 * or any code that calls `delete_site_transient( 'update_plugins' )`).
	 *
	 * Without this, our 12-hour transient keeps serving stale manifest data
	 * into every fresh WordPress update check.
	 */
	public static function purge_manifest_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Append a "View details" link to the plugin row meta on the Plugins screen.
	 *
	 * Clicking the link opens the standard WordPress plugin-information thickbox,
	 * which is populated by our `plugins_api` callback above.
	 *
	 * @param string[] $links      Existing row-meta links.
	 * @param string   $plugin_file Plugin basename (folder/file.php).
	 * @return string[]
	 */
	public static function add_view_details_link( array $links, string $plugin_file ): array {
		if ( self::PLUGIN_BASENAME !== $plugin_file ) {
			return $links;
		}

		// Scan existing links so we never create duplicates.  WordPress may
		// add its own "View details" thickbox link when plugins_api returns
		// data for our slug, and always adds "Visit plugin site" from the
		// Plugin URI header — but only when it considers the plugin "known".
		$has_details     = false;
		$has_plugin_site = false;

		foreach ( $links as $link ) {
			if ( str_contains( $link, 'TB_iframe' ) || str_contains( $link, 'open-plugin-details-modal' ) ) {
				$has_details = true;
			}
			if ( str_contains( $link, 'github.com/leotiger/lingua-forge' ) ) {
				$has_plugin_site = true;
			}
		}

		// Add "View details" only if WordPress hasn't already inserted one.
		if ( ! $has_details ) {
			$url = add_query_arg(
				[
					'tab'       => 'plugin-information',
					'plugin'    => 'lingua-forge',
					'TB_iframe' => 'true',
					'width'     => '600',
					'height'    => '550',
				],
				admin_url( 'plugin-install.php' )
			);

			$links[] = sprintf(
				'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
				esc_url( $url ),
				esc_attr__( 'View Lingua Forge details', 'lingua-forge' ),
				esc_html__( 'View details', 'lingua-forge' )
			);
		}

		// Guarantee the GitHub repository link is always present.
		// WordPress generates it from Plugin URI, but drops it for self-hosted
		// plugins when the update transient doesn't include a .org slug.
		if ( ! $has_plugin_site ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( 'https://github.com/leotiger/lingua-forge' ),
				esc_html__( 'Visit plugin site', 'lingua-forge' )
			);
		}

		return $links;
	}

	// -------------------------------------------------------------------------
	// Manifest fetch + cache
	// -------------------------------------------------------------------------

	/**
	 * Fetch the update manifest from lingua-forge.com, with transient caching.
	 *
	 * Returns false on network error, bad HTTP status, or invalid JSON.
	 * Negative results are cached for one hour to avoid hammering the server
	 * on repeated failures; positive results are cached for CACHE_TTL.
	 *
	 * The cached value is always an object.  A sentinel `{ "error": true }`
	 * object is stored for negative results so `get_transient() === false`
	 * always means "not cached", not "cached failure".
	 *
	 * @return object|false Decoded manifest, or false on failure.
	 */
	private static function fetch_manifest() {
		$cached = get_transient( self::CACHE_KEY );

		if ( false !== $cached ) {
			// Sentinel object means previous fetch failed; don't retry yet.
			return ! empty( $cached->error ) ? false : $cached;
		}

		$response = wp_remote_get(
			self::MANIFEST_URL,
			[
				'timeout'    => 10,
				'user-agent' => 'LinguaForge/' . LINGUAFORGE_VERSION
					. '; WordPress/' . get_bloginfo( 'version' )
					. '; ' . home_url(),
				'sslverify'  => true,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, (object) [ 'error' => true ], HOUR_IN_SECONDS );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $data ) || empty( $data->version ) ) {
			set_transient( self::CACHE_KEY, (object) [ 'error' => true ], HOUR_IN_SECONDS );
			return false;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	// -------------------------------------------------------------------------
	// Object builders
	// -------------------------------------------------------------------------

	/**
	 * Build the stdClass that WordPress expects in $transient->response.
	 *
	 * @param object $manifest Decoded manifest JSON.
	 * @return object
	 */
	private static function build_update_object( object $manifest ): object {
		return (object) [
			'id'           => 'lingua-forge/lingua-forge',
			'slug'         => 'lingua-forge',
			'plugin'       => self::PLUGIN_BASENAME,
			'new_version'  => $manifest->version,
			'url'          => $manifest->details_url ?? 'https://lingua-forge.com',
			'package'      => $manifest->download_url ?? '',
			'requires'     => $manifest->requires     ?? '6.4',
			'requires_php' => $manifest->requires_php ?? '8.1',
			'tested'       => $manifest->tested        ?? '',
			'icons'        => (array) ( $manifest->icons   ?? new \stdClass() ),
			'banners'      => (array) ( $manifest->banners ?? new \stdClass() ),
		];
	}

	/**
	 * Build the stdClass that WordPress expects in $transient->no_update.
	 *
	 * Populating no_update prevents stale update notices from persisting
	 * after the user has already updated.
	 *
	 * @param object $manifest Decoded manifest JSON.
	 * @return object
	 */
	private static function build_no_update_object( object $manifest ): object {
		return (object) [
			'id'           => 'lingua-forge/lingua-forge',
			'slug'         => 'lingua-forge',
			'plugin'       => self::PLUGIN_BASENAME,
			'new_version'  => $manifest->version,
			'url'          => $manifest->details_url ?? 'https://lingua-forge.com',
			'package'      => '',
			'requires'     => $manifest->requires     ?? '6.4',
			'requires_php' => $manifest->requires_php ?? '8.1',
			'tested'       => $manifest->tested        ?? '',
			'icons'        => (array) ( $manifest->icons   ?? new \stdClass() ),
			'banners'      => (array) ( $manifest->banners ?? new \stdClass() ),
		];
	}

	/**
	 * Build the stdClass that WordPress expects from a `plugins_api` callback.
	 *
	 * Populates the fields WordPress renders in the plugin-information modal
	 * (the thickbox/dialog that appears when you click "View version details").
	 *
	 * @param object $manifest Decoded manifest JSON.
	 * @return object
	 */
	private static function build_info_object( object $manifest ): object {
		$sections = (array) ( $manifest->sections ?? [] );

		// Provide a sensible fallback if the manifest omits sections entirely
		// (including the case where the manifest was temporarily unreachable).
		if ( empty( $sections ) ) {
			$sections['description'] = '<p>Multilingual routing, SEO meta tags, and AI content tools for WordPress.</p>'
				. '<p><a href="https://lingua-forge.com">lingua-forge.com</a></p>';
		}

		return (object) [
			'name'           => 'Lingua Forge',
			'slug'           => 'lingua-forge',
			'version'        => $manifest->version       ?? LINGUAFORGE_VERSION,
			'author'         => '<a href="https://lingua-forge.com">Uli Hake</a>',
			'author_profile' => 'https://lingua-forge.com',
			'homepage'       => 'https://lingua-forge.com',
			'requires'       => $manifest->requires      ?? '6.4',
			'requires_php'   => $manifest->requires_php  ?? '8.1',
			'tested'         => $manifest->tested         ?? '',
			'download_link'  => $manifest->download_url   ?? '',
			'last_updated'   => $manifest->last_updated   ?? '',
			'sections'       => $sections,
			'icons'          => (array) ( $manifest->icons   ?? new \stdClass() ),
			'banners'        => (array) ( $manifest->banners ?? new \stdClass() ),
		];
	}
}
