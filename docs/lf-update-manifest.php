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

	$version      = '2.2.4';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.2.4/lingua-forge-2.2.4.zip';
	$last_updated = '2026-06-08';
	$tested       = '7.0';

	// Current release only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.2.4 — 2026-06-08</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> WooCommerce My Account sub-endpoint URLs under a language prefix (e.g. /es/mi-cuenta/orders/) returning 404. New fix_myaccount_endpoint_request parses the URI directly and rebuilds query vars from scratch.</li>' .
			'<li><strong>Fixed:</strong> WooCommerce Terms &amp; Conditions and Privacy Policy links on checkout always pointing to the source-language page. Added woocommerce_get_terms_page_id, woocommerce_terms_and_conditions_page_id, and woocommerce_privacy_policy_page_id filters in WcPageBridge.</li>' .
			'<li><strong>Fixed:</strong> WooCommerce Brands (product_brand) and custom product taxonomy archives falling back to source-language pages. Taxonomy-archive hooks now consume a dynamic list via get_product_archive_taxonomies(), filterable via lf_wc_product_archive_taxonomies.</li>' .
			'<li><strong>Fixed:</strong> Site Title block (core/site-title) wrapping link not localised — fix_site_title_link() added, mirroring the existing fix_site_logo_link() pattern.</li>' .
			'<li><strong>Fixed:</strong> Custom taxonomy archive URLs returning 404 under a language prefix. New add_general_taxonomy_archive_rewrite_rules() registers explicit top-priority rules for all public custom taxonomies.</li>' .
			'<li><strong>Fixed:</strong> WooCommerce Product structured data duplicated when no SEO plugin is active. SeoSupport now injects inLanguage via woocommerce_structured_data_product; SchemaManager skips Article/WebPage on product singulars.</li>' .
			'<li><strong>Fixed:</strong> Secondary WP_Query instances (sidebar widgets, get_posts(), Latest Posts/Events blocks) returning mixed-language results. New handle_secondary_pre_get_posts() injects _lf_lang on all secondary frontend queries; ID-only lookups (fields=ids) are skipped.</li>' .
			'<li><strong>Fixed:</strong> Navigation block injecting unexpected new items. handle_secondary_pre_get_posts() was missing an exclusion for WordPress system post types; wp_navigation queries returned zero results and WordPress silently created a new navigation post from the latest classic menu. System types (wp_navigation, nav_menu_item, wp_template, wp_template_part, etc.) are now excluded.</li>' .
			'<li><strong>Changed:</strong> Translation Memory and API Response Cache enable/disable toggles moved from Settings &rarr; Behavior to Settings &rarr; AI Usage &amp; Cache (each in its own inner sub-tab).</li>' .
		'</ul>' .
		'<h4>2.2.3 — 2026-06-07</h4>' .
		'<ul>' .
			'<li><strong>Fixed:</strong> WooCommerce Cart, Checkout, and My Account pages always linking to source-language URLs in mini-cart and checkout navigation. Translated equivalents now returned via woocommerce_get_{type}_page_id filters using the same _lf_trid/_lf_lang lookup as the Shop page.</li>' .
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
