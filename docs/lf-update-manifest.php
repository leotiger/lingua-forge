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

	$version      = '2.5.2';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.5.2/lingua-forge-2.5.2.zip';
	$last_updated = '2026-07-07';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.5.2.zip — build it, upload
	// to the v2.5.2 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = 'd6e6b031a3eb3e2bc169844ca6827d492f52facc0d72c95848ad7e638ec6f751';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.5.2 &#8212; 2026-07-07</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The Language Switcher could render nothing at all on a &#8220;Your latest posts&#8221; front page, even with every language correctly configured &#8212; confirmed live on an Agnosis-family site. A stray, untranslated post (WordPress&#8217;s own default &#8220;Hello world!&#8221; sample, or any other leftover post &#8212; not specific to WooCommerce or any post type) could get silently picked up as &#8220;the current post&#8221; via <code>get_the_ID()</code> on a non-singular request, and since it had no translation group the switcher hid itself entirely, even though the site&#8217;s real content was fully translated. <code>get_the_ID()</code> is now only trusted when <code>is_singular()</code> is actually true, ahead of the existing WooCommerce shop-page override; a non-singular front page falls through to the existing per-language URL fallback instead. (<code>language-router/includes/class-lsflr-switcher.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.5.1 &#8212; 2026-07-06</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The Danger Zone &#8220;Uninstall {LANG}&#8221; action on Settings &#8594; Router appeared to silently do nothing &#8212; the deletion ran correctly, but the redirect afterward pointed at a URL the plugin&#8217;s settings page (a top-level admin menu page) doesn&#8217;t live under, so WordPress fell back to the default Settings &#8594; General screen with no success notice. The Router tab&#8217;s Save button and Flush Permalinks button shared the identical bug. All three now redirect correctly and show their confirmation notice. (<code>ai/includes/Admin/Settings/Tabs/RouterTab.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Uninstalling a language could leave it only partially removed, for three independent reasons &#8212; CPT Block Pattern translations lived in a single option rather than as posts and were invisible to the uninstall&#8217;s postmeta query; custom translation files copied into the Maintenance tab&#8217;s &#8220;Loco Translate &#8212; Copy to Safe Storage&#8221; location were never scanned for removal; and for languages where WordPress&#8217;s own locale slug isn&#8217;t the 2-letter code this plugin assumed everywhere (e.g. Yoruba&#8217;s real slug is the 3-letter &#8220;yor&#8221;, not &#8220;yo&#8221;), uninstall could report success while the language stayed active forever. All three fixed: pattern translations are now purged and counted in the success notice, the Loco safe-storage directory is now scanned too, and a new <code>Context::lang_from_locale()</code> replaces the lossy 2-character truncation everywhere it appeared. Internal routing/URLs/postmeta are unaffected. (<code>ai/includes/Admin/FseLocalisation/PatternDiscovery.php</code>, <code>ai/includes/Admin/Language/LanguageUninstaller.php</code>, <code>language-router/includes/class-context.php</code>, <code>ai/includes/Admin/Settings/Panels/SystemPanel.php</code>, <code>ai/includes/Admin/Settings/Tabs/RouterTab.php</code>, <code>ai/includes/Features/Translation.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The admin-bar &#8220;Preview Language&#8221; switcher could show two languages checked at once, and could label the wrong one as current &#8212; confirmed with Yoruba added as an active language, which had no locale mapping and silently collided with English&#8217;s own locale. Added the missing mapping (plus five others found via audit: hi, ur, th, sw, km, eu) and made the switcher compare against a single resolved current-language value instead of re-deriving it per item. (<code>language-router/includes/class-locale-detector.php</code>, <code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Fixed:</strong> hreflang tags, og:locale, the &#8220;Preview Language&#8221; label, and browser-language auto-detection didn&#8217;t understand WordPress&#8217;s bare 3-letter locale slugs. A new <code>Context::iso_639_1_from_lang()</code> normalises the handful of affected languages to their real ISO 639-1 code for these outbound-facing uses only, without touching internal routing/URLs/postmeta. (<code>language-router/includes/class-context.php</code>, <code>language-router/includes/class-locale-detector.php</code>, <code>language-router/includes/seo/class-schema-manager.php</code>, <code>language-router/includes/seo/class-seo-manager.php</code>)</li>' .
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
