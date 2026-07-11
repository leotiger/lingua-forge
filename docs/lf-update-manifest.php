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

	$version      = '2.6.4';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.6.4/lingua-forge-2.6.4.zip';
	$last_updated = '2026-07-11';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	// TODO(release): pending the built lingua-forge-2.6.4.zip — build it, upload
	// to the v2.6.4 GitHub release, sha256sum it, paste the digest here, then
	// deploy this manifest.
	$sha256 = 'd80c8df82bc99220384f8613e62cf3e6c1160632d48057715ec5c5a26a1b990a';

	// Two most recent releases only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.6.4 &#8212; 2026-07-11</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> A first-time translated post created via &#8220;Translate missing&#8221;/Sync or the WP-CLI <code>translate</code>/<code>fill_translations</code> commands was born with no excerpt &#8212; only the programmatic-API creation path carried it, a gap left by the 2.4.0 excerpt fix. All three creation paths now build their common <code>wp_insert_post()</code> args through one new shared helper, <code>TranslationTrigger::build_create_args()</code>, so a future fix to a common field lands in all three by construction. (<code>ai/includes/Features/TranslationTrigger.php</code>, <code>ai/includes/Admin/PostListColumn.php</code>, <code>ai/includes/CLI/AbstractTranslateCommand.php</code>)</li>' .
			'<li><strong>Fixed:</strong> A translated WooCommerce variable product created via the programmatic API or the WP-CLI create path was born with no translated variation children and no WC structural taxonomies &#8212; the sync hook always saw an empty language meta during creation and silently did nothing. All three creation paths now call a shared <code>TranslationTrigger::sync_variation_children_if_product()</code> helper explicitly after that meta is written. (<code>ai/includes/Features/TranslationTrigger.php</code>, <code>ai/includes/Admin/PostListColumn.php</code>, <code>ai/includes/CLI/AbstractTranslateCommand.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Uninstalling a language could delete the wrong plugin/theme locale files, or miss the right ones &#8212; unanchored prefix matching could delete unrelated dialect files sharing a 2-letter prefix (e.g. <code>ar</code> vs <code>ary</code>/<code>arq</code>), and couldn&#8217;t match the <code>{textdomain}-{locale}.mo</code> suffix convention used in <code>plugins/</code>/<code>themes/</code> subdirectories at all. New anchored root and suffix matchers fix both. (<code>ai/includes/Admin/Language/LanguageUninstaller.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Sync and Template Sync could overwrite a sibling translation the current user has no permission to edit &#8212; both only checked permissions on the clicked post, not each post they actually write to. Both now skip (and report) any target post the current user can&#8217;t edit. (<code>ai/includes/Admin/PostListColumn.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The uninstall cleanup had drifted roughly 30 options, several post meta keys, and every scheduled cron/Action Scheduler job behind current source, despite claiming to remove &#8220;all&#8221; plugin options. Replaced with a single self-updating options sweep, the missing post meta keys, and cron/Action Scheduler cleanup. (<code>uninstall.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The FSE &#8220;Re-create&#8221; force path (Templates and Template Parts) looked up the existing post to update in place with no theme scoping, so it could silently overwrite an unrelated same-slug row belonging to a different theme &#8212; WordPress itself allows two themes to share a template slug. The lookup is now scoped to the active theme/namespace. (<code>ai/includes/Admin/FseLocalisation/ScaffoldHandler.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The WordPress AI Client provider (WP 7.0+) crashed on any multi-turn &#8220;Refine&#8221; request (AI Content Generation, Chunk Translation) when selected as the active provider &#8212; verifying it against the API as actually shipped (it was originally written against an earlier preview) found <code>with_history()</code> needed a different input shape than was being sent. The other three AI providers were unaffected. (<code>ai/includes/Providers/WpAiClient.php</code>)</li>' .
		'</ul>' .
		'<p><a href="https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md">Full changelog on GitHub</a></p>' .
		'<h4>2.6.3 &#8212; 2026-07-11</h4>' .
		'<ul>' .
			'<li><strong>Changed:</strong> Automatic Translation Backfill (2.5.3) is now off by default and controlled by a new <strong>Settings &#8594; Behavior &#8594; Automatic Translation Backfill</strong> toggle &#8212; previously it ran unconditionally, hourly, for every site with the AI module active, with no setting to stop it. (<code>ai/includes/Features/TranslationBackfill.php</code>, <code>ai/includes/Admin/Settings/Tabs/BehaviorTab.php</code>, <code>ai/includes/Admin/SettingsPage.php</code>)</li>' .
			'<li><strong>Fixed:</strong> The backfill scan no longer queues jobs when no AI provider/API key is configured, and now respects the <code>linguaforge_cpt_create_allowed</code> integration filter per post type, matching &#8220;Translate missing&#8221; and Sync. (<code>ai/includes/Features/TranslationBackfill.php</code>)</li>' .
			'<li><strong>Changed:</strong> WooCommerce products and variations are now excluded from the backfill scan by default, since its creation path doesn&#8217;t run variation sync. Still reachable via the existing <code>linguaforge_backfill_post_types</code> filter. (<code>ai/includes/Features/TranslationBackfill.php</code>)</li>' .
			'<li><strong>Changed:</strong> <code>readme.txt</code>&#8217;s External Services section now discloses the background AI sends Automatic Translation Backfill makes when enabled.</li>' .
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
