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

	$version      = '2.4.1';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.4.1/lingua-forge-2.4.1.zip';
	$last_updated = '2026-07-03';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): build lingua-forge-2.4.1.zip, upload it to the v2.4.1 GitHub
	// release, then paste its sha256 here before deploying this manifest.
	$sha256 = '2aacccd7f51a17b8a762eb935fb2a3800e991c2b626adc65dfe803b5f7b45d72';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.4.1 &#8212; 2026-07-03</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> IndexNow key-file submissions could fail with 403 even though the file loaded fine in a browser. The key-file URL never matches a real post/page/rewrite rule, so WordPress had already set an HTTP 404 status before the plugin&#8217;s handler ran &#8212; the correct key was served, but under a 404 status line that browsers render fine but that the reachability self-check and real IndexNow crawlers correctly reject. The response now explicitly sends status 200. (<code>language-router/includes/seo/class-indexnow-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Sitemap chunk files (<code>/lf-sitemap-{N}.xml</code>) could go undiscovered by Google for the same reason &#8212; served with a correct XML body under an inherited HTTP 404 status, which Search Console rejects regardless of body content. The response now explicitly sends status 200. The sitemap index (<code>/lf-sitemap.xml</code>) was not affected. (<code>language-router/includes/seo/class-sitemap-manager.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.4.0 &#8212; 2026-06-30</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> <code>linguaforge_queue_translation()</code> &#8212; a non-blocking companion to <code>linguaforge_trigger_translation()</code> that runs a translation off-request via Action Scheduler (when available) or WP-Cron, so programmatic publishers can translate into many languages without making blocking AI calls inline. (<code>ai/ai.php</code>, <code>ai/includes/Features/TranslationQueue.php</code>)</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_translated_post_meta</code> filter &#8212; lets an integration declare the post meta a programmatically-created translated post is born with (featured image, gallery, custom fields), written via <code>meta_input</code> so the translation is complete the moment it exists. WooCommerce operational keys remain delegated by MetaDelegate. (<code>ai/includes/Features/TranslationTrigger.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A first-time translated post now keeps its translated excerpt &#8212; it was discarded on creation, so the meta description fell back to a trimmed slice of the content. The create path now writes <code>post_excerpt</code> from the AI&#8217;s <code>translated_excerpt</code>, matching the update path. (<code>ai/includes/Features/TranslationTrigger.php</code>)</li>' .
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
