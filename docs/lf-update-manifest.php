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

	$version      = '2.4.0';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.4.0/lingua-forge-2.4.0.zip';
	$last_updated = '2026-06-30';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): build lingua-forge-2.4.0.zip, upload it to the v2.4.0 GitHub
	// release, then paste its sha256 here before deploying this manifest.
	$sha256 = '61b9eda5ee1789405eb543f486e7402399f10cee81307d9ad6e008826962d8b3';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.4.0 &#8212; 2026-06-30</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> <code>linguaforge_queue_translation()</code> &#8212; a non-blocking companion to <code>linguaforge_trigger_translation()</code> that runs a translation off-request via Action Scheduler (when available) or WP-Cron, so programmatic publishers can translate into many languages without making blocking AI calls inline. (<code>ai/ai.php</code>, <code>ai/includes/Features/TranslationQueue.php</code>)</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_translated_post_meta</code> filter &#8212; lets an integration declare the post meta a programmatically-created translated post is born with (featured image, gallery, custom fields), written via <code>meta_input</code> so the translation is complete the moment it exists. WooCommerce operational keys remain delegated by MetaDelegate. (<code>ai/includes/Features/TranslationTrigger.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A first-time translated post now keeps its translated excerpt &#8212; it was discarded on creation, so the meta description fell back to a trimmed slice of the content. The create path now writes <code>post_excerpt</code> from the AI&#8217;s <code>translated_excerpt</code>, matching the update path. (<code>ai/includes/Features/TranslationTrigger.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.3.3 &#8212; 2026-06-21</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Language, Template, and Translations meta boxes are no longer displayed on edit screens for post types excluded from Lingua Forge routing via Settings &#8594; System. <code>add_source_footnotes_meta_box()</code> gains the same guard inside its existing loop. (<code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_metabox_excluded_post_types</code> filter &#8212; lets third-party plugins extend or override the metabox exclusion list without touching the System panel option. Receives the array already built from the saved option; filter callbacks can add or remove types. Follows the naming convention of <code>linguaforge_source_footnotes_excluded_post_types</code>. (<code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
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
