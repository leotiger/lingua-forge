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
 */

defined( 'ABSPATH' ) || exit;

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

function lf_update_manifest_endpoint(): WP_REST_Response {

	// -------------------------------------------------------------------------
	// UPDATE THESE FIELDS ON EVERY RELEASE
	// -------------------------------------------------------------------------

	$version      = '2.5.5';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.5.5/lingua-forge-2.5.5.zip';
	$last_updated = '2026-07-08';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.5.5.zip — build it, upload
	// to the v2.5.5 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = '21749776181bd4461f264d9213cb516b00030ad13afbd4fc12ad0f703f226366';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.5.5 &#8212; 2026-07-08</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Language Switcher &#8212; Grid Overlay panel could open off the right edge of the viewport on RTL-language pages (Arabic, Farsi, Urdu, etc.). The panel&#8217;s position was always calculated from the trigger&#8217;s left edge regardless of text direction; it now detects the resolved direction and anchors from the right edge in RTL, matching the classic dropdown&#8217;s existing RTL behaviour. (<code>class-lsflr-switcher.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.5.4 &#8212; 2026-07-07</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> &#8220;Trash + Siblings&#8221; &#8212; a new row action on the Posts/Pages/CPT admin list tables (next to Edit | Quick Edit | Trash | View) that trashes a post together with every other language version in its translation group, and a matching &#8220;Move to Trash (incl. translations)&#8221; bulk action. Both only appear when a post actually has translated siblings, act immediately with no confirmation prompt (matching the stock &#8220;Trash&#8221; link&#8217;s own reversible behaviour), and report a &#8220;Trashed N posts (including translations)&#8221; notice afterward. Skips the static front page / posts page and any post the current user can&#8217;t delete, reporting them as skipped rather than failing silently. Two new hooks for integrations: <code>linguaforge_trash_cascade_post_ids</code> and <code>linguaforge_trash_cascade_complete</code>. (<code>language-router/includes/translation/class-trash-cascade.php</code> NEW)</li>' .
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
