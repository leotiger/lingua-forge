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

	$version      = '2.3.3';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.3.3/lingua-forge-2.3.3.zip';
	$last_updated = '2026-06-21';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	$sha256 = 'bec9ac5da61364164af06353833caad84527beed77697201515ad848cc117c25';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.3.3 &#8212; 2026-06-21</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Language, Template, and Translations meta boxes are no longer displayed on edit screens for post types excluded from Lingua Forge routing via Settings &#8594; System. <code>add_source_footnotes_meta_box()</code> gains the same guard inside its existing loop. (<code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_metabox_excluded_post_types</code> filter &#8212; lets third-party plugins extend or override the metabox exclusion list without touching the System panel option. Receives the array already built from the saved option; filter callbacks can add or remove types. Follows the naming convention of <code>linguaforge_source_footnotes_excluded_post_types</code>. (<code>language-router/includes/admin/class-meta-boxes.php</code>)</li>' .
			'<li><strong>Changed:</strong> i18n pipeline is now a two-step composer workflow &#8212; <code>composer make-pot</code> regenerates the POT and merges new/changed strings into all 26 locale .po files via <code>msgmerge</code>; a new <code>composer compile-pos</code> command compiles each .po into a binary .mo (via <code>msgfmt</code>) and a .l10n.php cache (via <code>wp i18n make-php</code>). Requires <code>gettext</code> on PATH. (<code>dev/bin/make-pot.sh</code>, <code>dev/bin/compile-pos.sh</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.3.2 &#8212; 2026-06-15</h4>' .
		'<ul>' .
			'<li><strong>Changed:</strong> IndexNow submission is now asynchronous &#8212; publishing or updating a translated post previously triggered a blocking request to api.indexnow.org inside the save (up to a 15-second timeout), stalling the editor save / REST response. The save handler now schedules a single WP-Cron event (<code>linguaforge_indexnow_submit</code>) carrying only the post ID; the outbound POST runs in a background cron request via <code>run_scheduled_submit()</code>. Rapid re-saves of the same post are debounced and the URL set is re-collected at run time, so a burst of sibling creation submits the translation group once. Manual &#8220;Submit all URLs&#8221; from the Sitemap panel remains synchronous. Core WP-Cron only &#8212; no Action Scheduler dependency. (<code>class-indexnow-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The IndexNow verification key is no longer generated during a front-end request. Key-file serving now uses a read-only accessor that never writes an option; the key is created only in admin / submission contexts, removing a write-on-read (and a cold-request race) on anonymous GETs. (<code>class-indexnow-manager.php</code>)</li>' .
			'<li><strong>Changed:</strong> AI-module diagnostic logging is now gated behind <code>WP_DEBUG</code> via a new shared <code>Log::debug()</code> helper, so production sites no longer accumulate AI request/translation diagnostics in <code>debug.log</code>. (<code>ai/includes/Core/Log.php</code>)</li>' .
			'<li><strong>Fixed:</strong> On sites with a persistent object cache, a sitemap chunk transient evicted independently of the sitemap index is now regenerated on demand instead of serving an empty <code>&lt;urlset&gt;</code>. (<code>class-sitemap-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> WooCommerce order email language is now discarded after each status transition (priority-99 clear), so one order&#8217;s email language can no longer leak to another during bulk admin status changes. (<code>WcOrderLang.php</code>)</li>' .
			'<li><strong>Fixed:</strong> On paginated singular content (multipage posts using <code>&lt;!--nextpage--&gt;</code> or paginated comments), the canonical and hreflang tags now point at the page being viewed instead of page 1. (<code>class-hreflang.php</code>)</li>' .
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
