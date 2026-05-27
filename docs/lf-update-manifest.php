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

	$version      = '1.8.2';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v1.8.2/lingua-forge-1.8.2.zip';
	$last_updated = '2026-05-27';
	$tested       = '7.0';

	// Prepend new release entry; keep the last 3–4 entries for the modal.
	$changelog =
		'<h4>1.8.2 — 2026-05-27</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> "Retranslate" button with language selector in the Lang column — outdated target posts show a "From [lang]" dropdown and a Retranslate button. Clears stale cache, reruns AI translation, resets outdated flag, regenerates meta description.</li>' .
			'<li><strong>Improved:</strong> Lang column buttons now render inline on the same line as the language indicator.</li>' .
		'</ul>' .

		'<h4>1.8.1 — 2026-05-27</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> "Translate missing" button in the Lang column of the Posts/Pages list — one click fires all missing AI translations for a source-language post from the overview screen without opening the editor. Success replaces the ⭕ indicator with ✓ Done inline.</li>' .
		'</ul>' .

		'<h4>1.8.0 — 2026-05-27</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Translations metabox — spurious Override button after language switch. Stale TRID object-cache entry not cleared by set_lang() alone; explicit cache flush added to the AJAX handler.</li>' .
			'<li><strong>Improved:</strong> "Add Language" flushes rewrite rules server-side and reloads the page client-side automatically — Active Languages chips and template tables refresh without manual reload.</li>' .
			'<li><strong>Improved:</strong> Router tab Templates / Parts / Navigations replaced with a per-language tabbed UI; active tab persists via sessionStorage.</li>' .
			'<li><strong>Maintenance:</strong> PHPCS MissingTranslatorsComment and SlowDBQuery warnings resolved in Translations metabox.</li>' .
		'</ul>' .

		'<h4>1.7.2 — 2026-05-27</h4>' .
		'<ul>' .
			'<li><strong>Improved:</strong> "View details" link in the plugin row with full changelog/description modal. Duplicate links suppressed; "Visit plugin site" (GitHub) always guaranteed.</li>' .
			'<li><strong>Fixed:</strong> Plugin info modal returns a graceful local fallback instead of "Plugin not found" when the manifest is temporarily unreachable.</li>' .
			'<li><strong>Fixed:</strong> PHPStan — $transient typed as \\stdClass; includes/ added to analysis paths.</li>' .
		'</ul>' .

		'<h4>1.7.1 — 2026-05-26</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Target Language dropdown now limited to instance languages; missing languages (e.g. Basque/eu) auto-injected via <code>linguaforge_translation_languages</code> filter.</li>' .
			'<li><strong>Fixed:</strong> Maintenance tab uninstall warning — internal meta key names removed.</li>' .
			'<li><strong>Maintenance:</strong> SECURITY.md excluded via .distignore and .gitattributes.</li>' .
		'</ul>' .

		'<h4>1.7.0 — 2026-05-24</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> Self-hosted automatic update checker — once installed, WordPress surfaces update badges and one-click updates from lingua-forge.com without a WordPress.org listing.</li>' .
			'<li>Subdomain routing mode (<code>de.example.com</code>); classic menu auto-add guard; language switcher fixes; Fix Navigation References corrections; Translate Navigation subdomain fix.</li>' .
		'</ul>';

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
