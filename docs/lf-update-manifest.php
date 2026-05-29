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

	$version      = '2.0.1';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.0.1/lingua-forge-2.0.1.zip';
	$last_updated = '2026-05-29';
	$tested       = '7.0';

	// Current release only. Full history: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.0.1 — 2026-05-29</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> Translate / Review panel now closes automatically when the user focuses a different block in the editor. Previously the panel remained open after switching blocks, requiring a manual dismiss.</li>' .
		'</ul>' .
		'<h4>2.0.0 — 2026-05-28</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> WooCommerce integration — Phase 1 (shared-stock delegation model). Translated products carry only content fields; all operational data (price, SKU, stock, dimensions, images, variations, taxonomy assignments) is read transparently from the source-language product at runtime. Five new classes: <code>MetaDelegate</code> (<code>get_post_metadata</code> delegation), <code>StockRouter</code> (stock write routing), <code>VariationDelegate</code> (<code>product_variation</code> delegation), <code>TaxonomyDelegate</code> (<code>wp_get_object_terms</code> delegation for <code>product_cat</code> / <code>product_tag</code> / <code>product_type</code> / <code>pa_*</code>), <code>CatalogQuery</code> (WC product query language filter).</li>' .
			'<li><strong>Added:</strong> WooCommerce integration — Phase 1b (translated term names). Category, tag, product-type, and attribute term names display in the visitor\'s language via <code>_lf_term_name_{lang}</code> termmeta. Editable from the term add/edit screens. New classes: <code>TermNameFilter</code>, <code>TermNameAdmin</code>.</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_cpt_create_allowed</code> filter — allows integrations to block translated-post creation until their delegation layer is active.</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_wc_delegate_post_types</code> filter — controls which post types participate in operational-meta delegation and stock-write routing.</li>' .
			'<li><strong>Added:</strong> <code>linguaforge_wc_integration_active</code> action — fires after the WooCommerce integration initialises for the current request.</li>' .
			'<li><strong>Added:</strong> Custom Post Type support (Phase 0) — all public CPTs now receive the full Lingua Forge admin layer: Lang column, filter dropdowns, quick-edit language control, AI translation metabox, FSE template selector, Translation Memory eligibility, and link-fixer scan. Opt-out filters: <code>linguaforge_column_post_types</code>, <code>linguaforge_ai_metabox_post_types</code>, <code>linguaforge_link_fixer_post_types</code>.</li>' .
			'<li><strong>Added:</strong> FSE template auto-assignment for CPTs using <code>single-{post_type}-{lang}</code> naming (e.g. <code>single-product-de</code>).</li>' .
			'<li><strong>Added:</strong> Third-party integration API — five new hooks: <code>linguaforge_loaded</code> (fires after router boot; use instead of <code>plugins_loaded</code> for integrations), <code>linguaforge_translation_content</code> filter, <code>linguaforge_translation_complete</code> action, <code>linguaforge_trid_changed</code> action, <code>linguaforge_switcher_output</code> filter. Two public REST endpoints: <code>GET /wp-json/lingua-forge/v1/languages</code> and <code>GET /wp-json/lingua-forge/v1/post/{id}/translations</code>. New public PHP function <code>linguaforge_trigger_translation()</code>. Full documentation in CONTRIBUTING.md.</li>' .
			'<li><strong>Added:</strong> Classic theme language switcher — <code>[lsflr_switcher]</code> shortcode and <code>Lsflr_Switcher_Widget</code> (Appearance → Widgets) available on any theme.</li>' .
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
