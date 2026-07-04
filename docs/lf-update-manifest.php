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

	$version      = '2.4.2';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.4.2/lingua-forge-2.4.2.zip';
	$last_updated = '2026-07-04';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): the icon-color feature (class-lsflr-switcher.php,
	// editor-switcher.js) landed after the previous test zip's hash was taken,
	// so it's stale again — re-zip lingua-forge-2.4.2.zip, upload it, then paste
	// the new sha256 here before deploying this manifest.
	$sha256 = 'f743d04b0804a3e50e79418546e38b964681d358f28fdc43eb7acb076f377c8b';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.4.2 &#8212; 2026-07-04</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Language Switcher &#8212; new &#8220;Icon color&#8221; block setting (Inspector, when display mode is &#8220;Icon only&#8221; or &#8220;Icon + language&#8221;), using a theme-palette-aware colour picker. Lets you override the icon&#8217;s colour for sections whose background is set locally rather than via the theme&#8217;s global style, where the switcher&#8217;s automatic contrast colour can otherwise end up matching the background. (<code>language-router/includes/class-lsflr-switcher.php</code>, <code>language-router/assets/editor-switcher.js</code>)</li>' .
			'<li><strong>Fixed:</strong> Language Switcher &#8212; Grid Overlay&#8217;s &#8220;Auto&#8221; list style could silently override an &#8220;Icon only&#8221; display and show the current language as a plain text link instead. On any page where secondary languages are configured but have no translated content yet, only one language is available to switch to; the width heuristic that decides when to auto-expand used that count directly, so it was almost always satisfied and hid the icon trigger in favour of the (now pointless) self-referential text link. The heuristic no longer runs when there&#8217;s nothing to switch to. (<code>language-router/includes/class-lsflr-switcher.php</code>)</li>' .
			'<li><strong>Changed:</strong> Grid Overlay&#8217;s language panel no longer lists the current language alongside the other languages &#8212; it now shows only the languages you can switch to, matching the classic dropdown&#8217;s existing behaviour. (<code>language-router/includes/class-lsflr-switcher.php</code>, <code>language-router/assets/lsflr.css</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.4.1 &#8212; 2026-07-03</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> IndexNow key-file submissions could fail with 403 even though the file loaded fine in a browser. The key-file URL never matches a real post/page/rewrite rule, so WordPress had already set an HTTP 404 status before the plugin&#8217;s handler ran &#8212; the correct key was served, but under a 404 status line that browsers render fine but that the reachability self-check and real IndexNow crawlers correctly reject. The response now explicitly sends status 200. (<code>language-router/includes/seo/class-indexnow-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Sitemap chunk files (<code>/lf-sitemap-{N}.xml</code>) could go undiscovered by Google for the same reason &#8212; served with a correct XML body under an inherited HTTP 404 status, which Search Console rejects regardless of body content. The response now explicitly sends status 200. The sitemap index (<code>/lf-sitemap.xml</code>) was not affected. (<code>language-router/includes/seo/class-sitemap-manager.php</code>)</li>' .
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
