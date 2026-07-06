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

	$version      = '2.5.0';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.5.0/lingua-forge-2.5.0.zip';
	$last_updated = '2026-07-05';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.5.0.zip — build it, upload
	// to the v2.5.0 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = '49f34b26cf290e678744fa83f8f22f2d53987d13c28309e7070ae9c0a348d519';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.5.0 &#8212; 2026-07-05</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Support for &#8220;Your latest posts&#8221; as the site&#8217;s front page (Settings &#8594; Reading) &#8212; translated homepages now live at <code>/es/</code>, <code>/fr/</code>, etc., alongside the existing static-front-page support. Includes language-scoped post listing on the latest-posts front page (previously all languages appeared mixed), a &#8220;Blog Home&#8221; entry in the FSE template scaffold list, automatic <code>home-{lang}</code> template selection at runtime, a homepage redirect for returning visitors whose detected language isn&#8217;t the source language, and a synthetic per-language homepage entry in the XML sitemap. (<code>language-router/includes/rewrite/class-query-filter.php</code>, <code>language-router/includes/routing/class-front-page-query.php</code>, <code>language-router/includes/routing/class-redirector.php</code>, <code>language-router/includes/seo/class-sitemap-manager.php</code>, <code>ai/includes/Admin/FseLocalisation/TemplateDefinitions.php</code>)</li>' .
			'<li><strong>Added:</strong> Translated posts, pages, and CPTs now get their featured image copied from the source post automatically at creation time &#8212; none of the 3 built-in translation paths did this before. Skipped for WooCommerce products (already served live from the source via <code>MetaDelegate</code>) and when an integration&#8217;s <code>linguaforge_translated_post_meta</code> filter already supplied one. A new &#8220;Fix Featured Images&#8221; bulk-fix button, next to &#8220;Fix Links&#8221; in the Posts/Pages/CPT admin list toolbar, retroactively fixes existing translations whose featured image is missing or out of sync with their source. Gallery images are unaffected &#8212; they live in post content, which is already translated. (<code>ai/includes/Features/TranslationTrigger.php</code>, <code>ai/includes/CLI/AbstractTranslateCommand.php</code>, <code>ai/includes/Admin/PostListColumn.php</code>, <code>language-router/includes/class-lsflr-featured-image-fixer.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A theme with <code>home.html</code> but no <code>front-page.html</code> could render the theme&#8217;s generic fallback content instead of real content on secondary-language homepages &#8212; confirmed live on an Agnosis-family site. &#8220;Front Page&#8221; is no longer offered for scaffolding, and no longer preferred at runtime over the correct &#8220;Blog Home&#8221; template, unless the active theme actually ships a base <code>front-page.html</code>. (<code>ai/includes/Admin/FseLocalisation/TemplateDefinitions.php</code>, <code>language-router/includes/routing/class-front-page-query.php</code>, <code>language-router/includes/translation/class-sync.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Language Switcher block could produce a double-slash URL (e.g. <code>/fr//</code>) when linking to the homepage of a site using &#8220;Your latest posts&#8221; &#8212; the link still worked (an extra redirect resolved it) but wasn&#8217;t clean. (<code>language-router/includes/class-lsflr-switcher.php</code>)</li>' .
			'<li><strong>Added:</strong> Language lists in translate-action UIs (Retranslate dropdown, AI Translate-to dropdown, Quick Translate popover, Translations meta box) are now sorted alphabetically by language code instead of following arbitrary database/discovery order. (<code>ai/includes/Admin/PostListColumn.php</code>, <code>ai/includes/Admin/MetaBox.php</code>, <code>ai/includes/Admin/AdminToolbar.php</code>, <code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A translated post of a custom post type with its own rewrite slug (e.g. an &#8220;art&#8221; CPT at <code>/art/some-artwork/</code>) 404&#8217;d once language-prefixed &#8212; confirmed live on an Agnosis-family site. Only that CPT&#8217;s archive had a language-prefixed inbound rewrite rule, never its single-post permalink, so the URL fell through to the generic <code>pagename</code> fallback and 404&#8217;d. A new rule closes this for every public, non-hierarchical CPT with a custom rewrite slug (hierarchical CPTs and WooCommerce products are not covered). <strong>Re-save Settings &#8594; Permalinks once after updating</strong> to pick up the new rule. (<code>language-router/includes/rewrite/class-manager.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.4.2 &#8212; 2026-07-04</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Language Switcher &#8212; new &#8220;Icon color&#8221; block setting (Inspector, when display mode is &#8220;Icon only&#8221; or &#8220;Icon + language&#8221;), using a theme-palette-aware colour picker. Lets you override the icon&#8217;s colour for sections whose background is set locally rather than via the theme&#8217;s global style, where the switcher&#8217;s automatic contrast colour can otherwise end up matching the background. (<code>language-router/includes/class-lsflr-switcher.php</code>, <code>language-router/assets/editor-switcher.js</code>)</li>' .
			'<li><strong>Fixed:</strong> Language Switcher &#8212; Grid Overlay&#8217;s &#8220;Auto&#8221; list style could silently override an &#8220;Icon only&#8221; display and show the current language as a plain text link instead. On any page where secondary languages are configured but have no translated content yet, only one language is available to switch to; the width heuristic that decides when to auto-expand used that count directly, so it was almost always satisfied and hid the icon trigger in favour of the (now pointless) self-referential text link. The heuristic no longer runs when there&#8217;s nothing to switch to. (<code>language-router/includes/class-lsflr-switcher.php</code>)</li>' .
			'<li><strong>Changed:</strong> Grid Overlay&#8217;s language panel no longer lists the current language alongside the other languages &#8212; it now shows only the languages you can switch to, matching the classic dropdown&#8217;s existing behaviour. (<code>language-router/includes/class-lsflr-switcher.php</code>, <code>language-router/assets/lsflr.css</code>)</li>' .
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
