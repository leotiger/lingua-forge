<?php
/**
 * Lingua Forge — self-hosted update manifest endpoint.
 *
 * Deploy to: wp-content/mu-plugins/lf-update-manifest.php on lingua-forge.com.
 *
 * Registers GET /wp-json/lingua-forge/v1/update and returns the plugin update
 * manifest as JSON with no-cache headers so every request fetches live data
 * regardless of server-side or CDN caching.
 *
 * On every release: update $version, $download_url, $last_updated, and prepend
 * the new entry to $sections['changelog']. Nothing else needs changing.
 *
 * MANIFEST_URL in lingua-forge/includes/class-updater.php must point to:
 * https://lingua-forge.com/wp-json/lingua-forge/v1/update
 *
 * Modeled directly on the companion Agnosis plugin's own
 * docs/agnosis-update-manifest.php (deployed the same way to agnosis.art),
 * so both self-hosted plugins are administered identically.
 *
 * Instance check-in telemetry (which sites are polling this endpoint, and
 * what version they're running) is NOT implemented in this file — it lives
 * in lf-manifest-includes/telemetry.php, required below, so this file stays
 * exactly what its own docblock says it is: a short "what to edit on every
 * release" document. See that file's own docblock for the full design and
 * privacy reasoning.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lf-manifest-includes/telemetry.php';

add_action( 'rest_api_init', function () {
	register_rest_route(
		'lingua-forge/v1',
		'/update',
		[
			'methods'             => 'GET',
			'callback'            => 'lf_update_manifest_endpoint',
			'permission_callback' => '__return_true',
		]
	);
} );

function lf_update_manifest_endpoint( WP_REST_Request $request ): WP_REST_Response {

	// Record this check-in (site URL, WP version, Lingua Forge version —
	// parsed from the request's own User-Agent) before anything else. Never
	// allowed to affect or slow the actual manifest response below; see
	// linguaforge_manifest_telemetry_record()'s own docblock.
	linguaforge_manifest_telemetry_record( $request );

	// -------------------------------------------------------------------------
	// UPDATE THESE FIELDS ON EVERY RELEASE
	// -------------------------------------------------------------------------

	$version      = '2.7.1';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.7.1/lingua-forge-2.7.1.zip';
	$last_updated = '2026-07-30';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.7.1.zip — build it, upload
	// to the v2.7.1 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest. (dev/build-zip.sh does this last step for you
	// automatically when run locally — see that script.)
	$sha256 = '39fdaf884680d35f7f17338de5eb7d456beda0b1efed302190a5601000d30f26';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.7.1 &#8212; 2026-07-30</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> <code>linguaforge_sitemap_extra_urls</code> filter &#8212; lets a companion plugin register additional indexable URLs (e.g. per-artist community subdomains) that the sitemap&#8217;s own <code>_lf_trid</code>/<code>_lf_lang</code> query has no way to discover on its own. Rows are emitted as hreflang alternates of each other, same as a native translation group.</li>' .
		'</ul>' .
		'<h4>2.7.0 &#8212; 2026-07-29</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Comment Translation (off by default, Settings &#8594; Behavior) &#8212; mirrors an approved comment onto every language version of its post as a real, already-approved, translated comment. Manual (default) or auto trigger mode, depth-capped nested-reply backfill, new Comments-screen Lang column/filter and Translate/Translate missing actions.</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>';

	// -------------------------------------------------------------------------
	// STATIC FIELDS — change rarely
	// -------------------------------------------------------------------------

	$manifest = [
		'version'      => $version,
		'requires'     => '6.4',
		'requires_php' => '8.1',
		'tested'       => $tested,
		'last_updated' => $last_updated,
		'details_url'  => 'https://lingua-forge.com',
		'download_url' => $download_url,
		'sha256'       => $sha256,

		'icons' => [
			'1x'  => 'https://lingua-forge.com/wp-content/uploads/lingua-forge-icon-128.png',
			'2x'  => 'https://lingua-forge.com/wp-content/uploads/lingua-forge-icon-256.png',
			'svg' => 'https://lingua-forge.com/wp-content/uploads/lingua-forge-icon.svg',
		],

		'banners' => [
			'low'  => 'https://lingua-forge.com/wp-content/uploads/lingua-forge-banner-772x250.jpg',
			'high' => 'https://lingua-forge.com/wp-content/uploads/lingua-forge-banner-1544x500.jpg',
		],

		'sections' => [
			'description' =>
				'<p>Lingua Forge is a free, permanently open-source multilingual plugin for WordPress. ' .
				'It combines language routing (path prefix <code>/de/</code> or subdomain <code>de.example.com</code>), ' .
				'hreflang SEO tags, a language switcher block, AI-powered translation and content generation, ' .
				'meta description management, a terminology glossary, and Translation Memory — all in a single ' .
				'installable plugin with no runtime dependencies beyond WordPress itself.</p>' .
				'<p>No subscription. No data leaves your server except the content you actively send for translation ' .
				'or generation. You pay your AI provider directly at API rates.</p>' .
				'<p><a href="https://github.com/leotiger/lingua-forge">GitHub repository</a> · ' .
				'<a href="https://lingua-forge.com">lingua-forge.com</a></p>',

			'installation' =>
				'<ol>' .
					'<li>Download the latest ZIP from the <a href="https://github.com/leotiger/lingua-forge/releases">GitHub Releases page</a>.</li>' .
					'<li>In WordPress admin go to <strong>Plugins → Add New → Upload Plugin</strong>, choose the ZIP, and click <strong>Install Now</strong>.</li>' .
					'<li>Activate <strong>Lingua Forge</strong>.</li>' .
					'<li>Go to <strong>Settings → Permalinks</strong> and click <strong>Save Changes</strong> to register the language URL prefixes.</li>' .
					'<li>Go to <strong>Settings → Lingua Forge</strong> to configure your language router and enter your AI provider API key.</li>' .
				'</ol>' .
				'<p><strong>After the first manual install, updates are automatic.</strong> ' .
				'WordPress checks for new releases every 12 hours and displays the standard update badge ' .
				'in Plugins → Installed Plugins when one is available.</p>',

			'changelog' => $changelog,
		],
	];

	$response = new WP_REST_Response( $manifest, 200 );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Pragma', 'no-cache' );
	$response->header( 'Expires', 'Thu, 01 Jan 1970 00:00:00 GMT' );

	return $response;
}
