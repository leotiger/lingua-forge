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

	$version      = '2.6.2';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.6.2/lingua-forge-2.6.2.zip';
	$last_updated = '2026-07-10';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.6.2.zip — build it, upload
	// to the v2.6.2 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = '7c4f00b6ea77a5524cc014a39577d24c5e7e55723ce8ccd522574f7ebeb0a3f7';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.6.2 &#8212; 2026-07-10</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> &#8220;Re-create all&#8221; and per-template &#8220;Re-create&#8221; buttons in Settings &#8594; Router &#8594; Templates &#8212; force-overwrite an existing FSE template with a fresh copy from the active theme, discarding any Site Editor customisations. Unlike &#8220;Create missing&#8221;/&#8220;Create&#8221;, which refuse to touch a template that already exists, Re-create replaces the content in place (same post ID when a DB-stored copy already exists) and is guarded by a confirmation dialog since it can&#8217;t be undone. New <code>force</code> POST param on the existing <code>linguaforge_scaffold_template</code> AJAX action. (<code>ai/includes/Admin/FseLocalisation/ScaffoldHandler.php</code>, <code>ai/includes/Admin/Settings/Tabs/Sections/TemplatesSection.php</code>, <code>ai/assets/fse-scaffold.js</code>)</li>' .
			'<li><strong>Added:</strong> The same &#8220;Re-create all&#8221; / &#8220;Re-create&#8221; pair for Settings &#8594; Router &#8594; Template Parts (headers, footers, and any other theme part) &#8212; same <code>force</code>-bypass logic against <code>wp_template_part</code>, same confirmation guard. (<code>ai/includes/Admin/FseLocalisation/ScaffoldHandler.php</code>, <code>ai/includes/Admin/Settings/Tabs/Sections/TemplatePartsSection.php</code>, <code>ai/assets/fse-scaffold.js</code>)</li>' .
			'<li><strong>Added:</strong> &#8220;Recreate All Languages&#8221; button above the language tabs in Settings &#8594; Router &#8594; Language Setup &#8212; runs Re-create all templates, Re-create all parts, Translate all, Fix all parts, and Fix all links for every active language in sequence, one language at a time, with a Cancel button and a final per-language failure summary. Pure client-side orchestration over the existing per-language buttons; no new AJAX endpoints. (<code>ai/assets/fse-global-actions.js</code>, <code>ai/includes/Admin/Settings/Tabs/RouterTab.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.6.1 &#8212; 2026-07-09</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> <code>linguaforge_template_for_lang</code> filter &#8212; override the language-specific FSE template slug Lingua Forge is about to assign to a translated post. Applies across every assignment path (editor save, WP-CLI, Sync button, and programmatic creation); never fires for the source-language post; returning an empty value suppresses assignment entirely. (<code>language-router/includes/translation/class-sync.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A translated post created via <code>linguaforge_trigger_translation()</code> / <code>linguaforge_queue_translation()</code> &#8212; the path every third-party integration uses, e.g. Agnosis &#8212; never received its language-specific FSE template (<code>single-{post_type}-{lang}</code>), even when one existed; it was left on the default/untranslated template. <code>TranslationTrigger::create_translated_post()</code> now assigns it after insertion, matching the normal editor save, WP-CLI, and Sync-button paths, which already did this. (<code>ai/includes/Features/TranslationTrigger.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The &#8220;Sync&#8221; button and the &#8220;Translate missing&#8221; bulk action could silently strip the language-specific template off an already-templated, existing translation when force-refreshing it in place, since both disable the normal save hook for their entire batch. Templates are now reassigned explicitly, independent of hook state. (<code>ai/includes/Admin/PostListColumn.php</code>)</li>' .
			'<li><strong>Added:</strong> &#8220;Template Sync&#8221; (TS) &#8212; a new button next to Sync in the post list Lang column that reassigns the correct language-specific template for every existing translation of a post, with no AI call and no content changes. Only shown on the primary/source-language post. Also adds <code>linguaforge_sync_templates( $post_id, $check_caps = false )</code> for programmatic use. (<code>ai/includes/Admin/PostListColumn.php</code>, <code>ai/ai.php</code>)</li>' .
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
