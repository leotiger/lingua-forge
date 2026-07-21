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

	$version      = '2.6.6';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.6.6/lingua-forge-2.6.6.zip';
	$last_updated = '2026-07-21';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.6.6.zip — build it, upload
	// to the v2.6.6 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = '1ebfbc12317e28bc63839ae87db5aa63e978e774d9a2d14459f853170ba56b6b';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.6.6 &#8212; 2026-07-21</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> New <code>linguaforge_translation_extra_instruction</code> filter lets a third-party plugin inject an extra sentence into the AI translation system prompt, ahead of the CRITICAL JSON RULE &#8212; e.g. an integration that needs Latin phrases left untranslated. Receives <code>(string $instruction, int $post_id)</code>; runs for both the Translation Memory and JSON-envelope translation paths. (<code>ai/includes/Features/Translation.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.6.5 &#8212; 2026-07-13</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The Models datalist (Settings &#8594; AI Provider) reverted to the hard-coded built-in catalog on every page load, discarding the live model list fetched from the provider&#8217;s own API the last time &#8220;Test connection&#8221; succeeded &#8212; the fetch and its 24-hour cache both worked, the settings page just never read the cached list back when rendering the suggestions. (<code>ai/includes/Admin/Settings/Tabs/GeneralTab.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Overriding a model to a newer Claude generation that has deprecated the <code>temperature</code> parameter failed outright with an HTTP 400 from Anthropic. The request now retries once with the parameter dropped when the provider reports it as deprecated for that model, keeping temperature control intact (still used by the compliance presets) for models that accept it. (<code>ai/includes/Providers/AbstractProvider.php</code>, <code>ai/includes/Providers/Anthropic.php</code>)</li>' .
			'<li><strong>Added:</strong> &#8220;Test model&#8221; button next to every Light/Quality model field &#8212; translates a short sample of your most recent published post with the exact (saved or unsaved) model in that field, using the tier&#8217;s real translation code path and the currently active Behavior preset, and previews the translated output. Replaces a bare connectivity ping, which couldn&#8217;t confirm a Quality-tier override actually produced usable translations. Makes a real, billed API call. (<code>ai/includes/Admin/Settings/Tabs/GeneralTab.php</code>, <code>ai/includes/Admin/Settings/Tabs/ApiKeysTab.php</code>, <code>ai/includes/Features/Translation.php</code>, <code>ai/includes/Features/JsonEnvelopeTranslator.php</code>, <code>ai/assets/test-connection.js</code>)</li>' .
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
