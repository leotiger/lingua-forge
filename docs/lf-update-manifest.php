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

	$version      = '2.3.2';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.3.2/lingua-forge-2.3.2.zip';
	$last_updated = '2026-06-15';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	$sha256 = '';

	// Current release only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.3.2 &#8212; 2026-06-15</h4>' .
		'<ul>' .
			'<li><strong>Changed:</strong> IndexNow submission is now asynchronous &#8212; publishing or updating a translated post previously triggered a blocking request to api.indexnow.org inside the save (up to a 15-second timeout), stalling the editor save / REST response. The save handler now schedules a single WP-Cron event (<code>linguaforge_indexnow_submit</code>) carrying only the post ID; the outbound POST runs in a background cron request via <code>run_scheduled_submit()</code>. Rapid re-saves of the same post are debounced and the URL set is re-collected at run time, so a burst of sibling creation submits the translation group once. Manual &#8220;Submit all URLs&#8221; from the Sitemap panel remains synchronous. Core WP-Cron only &#8212; no Action Scheduler dependency. (<code>class-indexnow-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The IndexNow verification key is no longer generated during a front-end request. Key-file serving now uses a read-only accessor that never writes an option; the key is created only in admin / submission contexts, removing a write-on-read (and a cold-request race) on anonymous GETs. (<code>class-indexnow-manager.php</code>)</li>' .
			'<li><strong>Changed:</strong> AI-module diagnostic logging is now gated behind <code>WP_DEBUG</code> via a new shared <code>Log::debug()</code> helper, so production sites no longer accumulate AI request/translation diagnostics in <code>debug.log</code>. (<code>ai/includes/Core/Log.php</code>)</li>' .
			'<li><strong>Fixed:</strong> On sites with a persistent object cache, a sitemap chunk transient evicted independently of the sitemap index is now regenerated on demand instead of serving an empty <code>&lt;urlset&gt;</code>. (<code>class-sitemap-manager.php</code>)</li>' .
			'<li><strong>Fixed:</strong> WooCommerce order email language is now discarded after each status transition (priority-99 clear), so one order&#8217;s email language can no longer leak to another during bulk admin status changes. (<code>WcOrderLang.php</code>)</li>' .
			'<li><strong>Fixed:</strong> On paginated singular content (multipage posts using <code>&lt;!--nextpage--&gt;</code> or paginated comments), the canonical and hreflang tags now point at the page being viewed instead of page 1. (<code>class-hreflang.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.3.1 &#8212; 2026-06-14</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> GDPR right-to-erasure gap in AI usage statistics &#8212; <code>PrivacyIntegration</code> now registers an exporter (date, feature, provider, model, token counts per row) and an anonymising eraser: existing anonymous rows receive summed counts via <code>UPDATE&nbsp;&#8230;&nbsp;JOIN</code>; rows with no anonymous counterpart are inserted fresh via <code>INSERT&nbsp;IGNORE</code>; the user-identified originals are then deleted. Aggregate billing data is preserved; the personal link (WP user ID) is removed. <code>_lf_order_lang</code> order meta rides WooCommerce&#8217;s own order anonymiser. (<code>ai/includes/Core/PrivacyIntegration.php</code>)</li>' .
			'<li><strong>Fixed:</strong> WooCommerce catalogue block pagination broken on WC 10 / WP 6.5+ &#8212; the 2.2.16 <code>isInteractivityRequest()</code> guard detected interactivity requests by URL parameters (<code>?cst</code>, <code>query-N-page</code>). WP 6.5+ and WC 10+ dropped those parameters and send an <code>X-WP-Interactivity-Router-Nonce</code> header instead; the URL-only guard missed these, causing <code>?lang=</code> injection on pagination fetches and an empty page 2+ response. <code>frontend-lang.js</code> now also inspects request headers on <code>fetch()</code> calls for this header. (<code>language-router/assets/frontend-lang.js</code>)</li>' .
			'<li><strong>Fixed:</strong> WooCommerce variation stock not routing to source product &#8212; <code>StockRouter::maybe_route()</code> and <code>rewrite_stock_sql()</code> defaulted to <code>[&#8216;product&#8217;]</code> while <code>MetaDelegate</code> already defaulted to <code>[&#8216;product&#8217;, &#8216;product_variation&#8217;]</code>. Translated variation stock writes (e.g. <code>_stock</code> reduced on purchase) passed through without routing to the source variation. Both default arrays aligned to <code>[&#8216;product&#8217;, &#8216;product_variation&#8217;]</code>. (<code>StockRouter.php</code>)</li>' .
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
