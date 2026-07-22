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

	$version      = '2.6.7';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.6.7/lingua-forge-2.6.7.zip';
	$last_updated = '2026-07-21';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.6.7.zip — build it, upload
	// to the v2.6.7 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = '15983fe34bb66f010db4565254a9b6aca7bafb4c58f895044ad1fee505757cec';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.6.7 &#8212; 2026-07-21</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> <code>ChunkTranslation::run()</code> (Translate-chunk mode and the Admin Toolbar&#8217;s <code>/translate-chunk</code> popover) never resolved the <code>linguaforge_translation_extra_instruction</code> filter added in 2.6.6, so an integration relying on it saw the instruction silently drop for any chunk translation. It now resolves the same filter, with <code>Translation::run_chunk()</code> threading through the real post ID for the meta-box path (<code>0</code> for the post-independent toolbar popover). (<code>ai/includes/Features/ChunkTranslation.php</code>, <code>ai/includes/Features/Translation.php</code>, <code>ai/includes/Admin/Settings/Tabs/ApiKeysTab.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Cached translations could silently outlive a change to the <code>linguaforge_translation_extra_instruction</code> filter&#8217;s output &#8212; neither the full-post nor the chunk cache hash included the resolved instruction. Both now do. (<code>ai/includes/Features/Translation.php</code>, <code>ai/includes/Features/ChunkTranslation.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Chunk translation always used the site&#8217;s global Behavior preset, even for a page with its own per-page preset override &#8212; full-post translation already respected it. Chunk mode now does too when translating from a real page; the post-independent Admin Toolbar popover is unaffected. (<code>ai/includes/Features/ChunkTranslation.php</code>, <code>ai/includes/Core/Config.php</code>, <code>ai/includes/Admin/Settings/Tabs/ApiKeysTab.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.6.6 &#8212; 2026-07-21</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> New <code>linguaforge_translation_extra_instruction</code> filter lets a third-party plugin inject an extra sentence into the AI translation system prompt, ahead of the CRITICAL JSON RULE &#8212; e.g. an integration that needs Latin phrases left untranslated. Receives <code>(string $instruction, int $post_id)</code>; runs for both the Translation Memory and JSON-envelope translation paths. (<code>ai/includes/Features/Translation.php</code>)</li>' .
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
