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

	$version      = '2.5.1';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.5.1/lingua-forge-2.5.1.zip';
	$last_updated = '2026-07-06';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.5.1.zip — build it, upload
	// to the v2.5.1 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = '7ffc5eca5512b0c6df43ced098ec0756d7adb63acc7ead7841e4f66829ef4e20';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.5.1 &#8212; 2026-07-06</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> The Danger Zone &#8220;Uninstall {LANG}&#8221; action on Settings &#8594; Router appeared to silently do nothing &#8212; the deletion ran correctly, but the redirect afterward pointed at a URL the plugin&#8217;s settings page (a top-level admin menu page) doesn&#8217;t live under, so WordPress fell back to the default Settings &#8594; General screen with no success notice. The Router tab&#8217;s Save button and Flush Permalinks button shared the identical bug. All three now redirect correctly and show their confirmation notice. (<code>ai/includes/Admin/Settings/Tabs/RouterTab.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Uninstalling a language could leave it only partially removed, for three independent reasons &#8212; CPT Block Pattern translations lived in a single option rather than as posts and were invisible to the uninstall&#8217;s postmeta query; custom translation files copied into the Maintenance tab&#8217;s &#8220;Loco Translate &#8212; Copy to Safe Storage&#8221; location were never scanned for removal; and for languages where WordPress&#8217;s own locale slug isn&#8217;t the 2-letter code this plugin assumed everywhere (e.g. Yoruba&#8217;s real slug is the 3-letter &#8220;yor&#8221;, not &#8220;yo&#8221;), uninstall could report success while the language stayed active forever. All three fixed: pattern translations are now purged and counted in the success notice, the Loco safe-storage directory is now scanned too, and a new <code>Context::lang_from_locale()</code> replaces the lossy 2-character truncation everywhere it appeared. Internal routing/URLs/postmeta are unaffected. (<code>ai/includes/Admin/FseLocalisation/PatternDiscovery.php</code>, <code>ai/includes/Admin/Language/LanguageUninstaller.php</code>, <code>language-router/includes/class-context.php</code>, <code>ai/includes/Admin/Settings/Panels/SystemPanel.php</code>, <code>ai/includes/Admin/Settings/Tabs/RouterTab.php</code>, <code>ai/includes/Features/Translation.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The admin-bar &#8220;Preview Language&#8221; switcher could show two languages checked at once, and could label the wrong one as current &#8212; confirmed with Yoruba added as an active language, which had no locale mapping and silently collided with English&#8217;s own locale. Added the missing mapping (plus five others found via audit: hi, ur, th, sw, km, eu) and made the switcher compare against a single resolved current-language value instead of re-deriving it per item. (<code>language-router/includes/class-locale-detector.php</code>, <code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Fixed:</strong> hreflang tags, og:locale, the &#8220;Preview Language&#8221; label, and browser-language auto-detection didn&#8217;t understand WordPress&#8217;s bare 3-letter locale slugs. A new <code>Context::iso_639_1_from_lang()</code> normalises the handful of affected languages to their real ISO 639-1 code for these outbound-facing uses only, without touching internal routing/URLs/postmeta. (<code>language-router/includes/class-context.php</code>, <code>language-router/includes/class-locale-detector.php</code>, <code>language-router/includes/seo/class-schema-manager.php</code>, <code>language-router/includes/seo/class-seo-manager.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.5.0 &#8212; 2026-07-05</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Support for &#8220;Your latest posts&#8221; as the site&#8217;s front page (Settings &#8594; Reading) &#8212; translated homepages now live at <code>/es/</code>, <code>/fr/</code>, etc., alongside the existing static-front-page support. Includes language-scoped post listing on the latest-posts front page (previously all languages appeared mixed), a &#8220;Blog Home&#8221; entry in the FSE template scaffold list, automatic <code>home-{lang}</code> template selection at runtime, a homepage redirect for returning visitors whose detected language isn&#8217;t the source language, and a synthetic per-language homepage entry in the XML sitemap. (<code>language-router/includes/rewrite/class-query-filter.php</code>, <code>language-router/includes/routing/class-front-page-query.php</code>, <code>language-router/includes/routing/class-redirector.php</code>, <code>language-router/includes/seo/class-sitemap-manager.php</code>, <code>ai/includes/Admin/FseLocalisation/TemplateDefinitions.php</code>)</li>' .
			'<li><strong>Added:</strong> Translated posts, pages, and CPTs now get their featured image copied from the source post automatically at creation time &#8212; none of the 3 built-in translation paths did this before. Skipped for WooCommerce products (already served live from the source via <code>MetaDelegate</code>) and when an integration&#8217;s <code>linguaforge_translated_post_meta</code> filter already supplied one. A new &#8220;Fix Featured Images&#8221; bulk-fix button, next to &#8220;Fix Links&#8221; in the Posts/Pages/CPT admin list toolbar, retroactively fixes existing translations whose featured image is missing or out of sync with their source. Gallery images are unaffected &#8212; they live in post content, which is already translated. (<code>ai/includes/Features/TranslationTrigger.php</code>, <code>ai/includes/CLI/AbstractTranslateCommand.php</code>, <code>ai/includes/Admin/PostListColumn.php</code>, <code>language-router/includes/class-lsflr-featured-image-fixer.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A theme with <code>home.html</code> but no <code>front-page.html</code> could render the theme&#8217;s generic fallback content instead of real content on secondary-language homepages &#8212; confirmed live on an Agnosis-family site. &#8220;Front Page&#8221; is no longer offered for scaffolding, and no longer preferred at runtime over the correct &#8220;Blog Home&#8221; template, unless the active theme actually ships a base <code>front-page.html</code>. (<code>ai/includes/Admin/FseLocalisation/TemplateDefinitions.php</code>, <code>language-router/includes/routing/class-front-page-query.php</code>, <code>language-router/includes/translation/class-sync.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Language Switcher block could produce a double-slash URL (e.g. <code>/fr//</code>) when linking to the homepage of a site using &#8220;Your latest posts&#8221; &#8212; the link still worked (an extra redirect resolved it) but wasn&#8217;t clean. (<code>language-router/includes/class-lsflr-switcher.php</code>)</li>' .
			'<li><strong>Added:</strong> Language lists in translate-action UIs (Retranslate dropdown, AI Translate-to dropdown, Quick Translate popover, Translations meta box) are now sorted alphabetically by language code instead of following arbitrary database/discovery order. (<code>ai/includes/Admin/PostListColumn.php</code>, <code>ai/includes/Admin/MetaBox.php</code>, <code>ai/includes/Admin/AdminToolbar.php</code>, <code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A translated post of a custom post type with its own rewrite slug (e.g. an &#8220;art&#8221; CPT at <code>/art/some-artwork/</code>) 404&#8217;d once language-prefixed &#8212; confirmed live on an Agnosis-family site. Only that CPT&#8217;s archive had a language-prefixed inbound rewrite rule, never its single-post permalink, so the URL fell through to the generic <code>pagename</code> fallback and 404&#8217;d. A new rule closes this for every public, non-hierarchical CPT with a custom rewrite slug (hierarchical CPTs and WooCommerce products are not covered). <strong>Re-save Settings &#8594; Permalinks once after updating</strong> to pick up the new rule. (<code>language-router/includes/rewrite/class-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The fix above could silently never register at all, regardless of how many times permalinks were re-saved &#8212; confirmed live on the same Agnosis-family site. The rule-building code ran on <code>init</code> at the default priority (10), the same priority almost every plugin/theme uses to register its own custom post types, so whether a given CPT was visible yet to <code>get_post_types()</code> depended on unpredictable same-priority callback ordering outside Lingua Forge&#8217;s control. Now runs at priority 20, guaranteeing CPTs registered at the default priority are already visible. (<code>language-router/includes/rewrite/class-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The Language Switcher rendered every custom-post-type link without its language prefix &#8212; confirmed live on the same Agnosis-family site. <code>get_permalink()</code> for a CPT applies WordPress&#8217;s <code>post_type_link</code> filter, not <code>post_link</code>/<code>page_link</code> (those fire only for the built-in <code>post</code>/<code>page</code> types), and only the latter two were hooked, so the switcher&#8217;s language-specific links silently fell back to the un-prefixed permalink. <code>post_type_link</code> is now hooked as well, excluding WooCommerce products/variations (filterable via <code>linguaforge_permalink_excluded_post_types</code>), which intentionally keep a single language-neutral permalink for every translation. (<code>language-router/includes/rewrite/class-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> hreflang alternates and the canonical tag both duplicated the language prefix (e.g. <code>/fr/fr/</code>) on a bare language-root homepage request &#8212; confirmed live on an Agnosis-family site&#8217;s homepage, in both path and subdomain routing modes. The lang-stripping logic required a trailing slash after the language code, which wasn&#8217;t there once the request path reduced to just the bare lang code itself, so the strip silently failed and the lang code was re-prepended downstream, doubling it. (<code>language-router/includes/seo/class-hreflang.php</code>)</li>' .
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
