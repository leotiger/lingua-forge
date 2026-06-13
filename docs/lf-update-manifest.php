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

	$version      = '2.3.0';
	$download_url = 'https://github.com/leotiger/lingua-forge/releases/download/v2.3.0/lingua-forge-2.3.0.zip';
	$last_updated = '2026-06-13';
	$tested       = '7.0';

	// SHA-256 of the release ZIP — run `sha256sum lingua-forge-X.Y.Z.zip` after
	// building and paste the hex digest here.  Empty string = verification skipped
	// (safe for existing cached manifests; new downloads will verify once set).
	$sha256 = '';

	// Current release only — do not accumulate history here; it bloats the manifest.
	// Full changelog: CHANGELOG.md in the plugin repository.
	$changelog =
		'<h4>2.3.0 &#8212; 2026-06-13</h4>' .
		'<ul>' .
			'<li><strong>Added:</strong> WordPress 7.0 AI Client as a fourth translation provider &#8212; new <code>WpAiClient</code> class delegates to core&#8217;s <code>wp_ai_client_prompt()</code> builder; API credentials are managed through WordPress Settings &#8594; Connectors. Works alongside existing Anthropic, OpenAI, and Gemini providers. (<code>ai/includes/Providers/WpAiClient.php</code>)</li>' .
			'<li><strong>Added:</strong> Sitemap index + chunking &#8212; <code>/lf-sitemap.xml</code> now serves a sitemap-index document that splits URLs into 2,000-URL sub-sitemaps (<code>/lf-sitemap-1.xml</code>, <code>/lf-sitemap-2.xml</code>, &#8230;). Handles the 50,000-URL protocol limit automatically for large multilingual sites. (<code>class-sitemap-manager.php</code>)</li>' .
			'<li><strong>Added:</strong> BreadcrumbList JSON-LD &#8212; <code>SchemaManager</code> now outputs <code>BreadcrumbList</code> structured data for singular posts, pages, custom post types, and taxonomy archive pages. URLs are language-prefixed automatically via LF&#8217;s rewrite filters. Controlled by the new <code>linguaforge_seo_schema_breadcrumb</code> option (default: on). (<code>class-schema-manager.php</code>)</li>' .
			'<li><strong>Added:</strong> WooCommerce order language capture and transactional email locale switching &#8212; <code>WcOrderLang</code> stores the customer&#8217;s language as order meta at checkout (<code>_lf_order_lang</code>) and switches the WooCommerce email locale for order-confirmation, processing, completed, refunded, and customer-note emails. (<code>WcOrderLang.php</code>, <code>WcPageBridge.php</code>)</li>' .
			'<li><strong>Added:</strong> WooCommerce coupon product-restriction mapping &#8212; <code>CouponTridMap</code> hooks <code>woocommerce_coupon_is_valid_for_product</code> to remap translated product IDs to their source-language equivalents, so coupon &#8220;Products&#8221; restrictions apply across all language versions of a product. (<code>CouponTridMap.php</code>)</li>' .
			'<li><strong>Added:</strong> WooCommerce order line item normalisation &#8212; <code>OrderItemNormalizer</code> rewrites the <code>product_id</code> on checkout line items to the source-language product, so <code>total_sales</code> increments on the source product (not a translated sibling) and WC Analytics reports one revenue row per product. Default on; configurable via Settings &#8594; Router &#8594; WooCommerce Integration. New <code>linguaforge_wc_order_item_source_mapping</code> filter for per-item control. (<code>OrderItemNormalizer.php</code>, <code>RouterTab.php</code>)</li>' .
			'<li><strong>Added:</strong> WooCommerce shared product review pool &#8212; <code>ProductReviewRouter</code> redirects review submissions to the source product and serves source-language reviews on translated product pages, so review counts and ratings are shared across language versions. (<code>ProductReviewRouter.php</code>)</li>' .
			'<li><strong>Fixed:</strong> <code>AIProviderInterface</code> gains a <code>get_last_error(): string</code> method; <code>JsonEnvelopeTranslator</code> and <code>ChunkTranslation</code> now surface the provider&#8217;s specific error reason (e.g. &#8220;No connector configured for text generation&#8221;) in the toolbar notification instead of a generic fallback message. (<code>AIProviderInterface.php</code>, <code>JsonEnvelopeTranslator.php</code>, <code>ChunkTranslation.php</code>)</li>' .
			'<li><strong>Fixed:</strong> <code>QueryFilter::query()</code> and <code>query_fallback()</code> now guard the bare <code>LF_LANG</code> constant reference; WP-CLI and cron requests where the router never fires no longer produce a PHP 8 fatal error when <code>linguaforge_get_posts()</code> / <code>linguaforge_query_fallback()</code> are called by theme or plugin code. (<code>class-query-filter.php</code>)</li>' .
			'<li><strong>Fixed:</strong> <code>handle_parse_query()</code> now runs only on the main query &#8212; the previous missing <code>is_main_query()</code> guard caused <code>is_search</code> and <code>is_home</code> mutations to apply to every <code>WP_Query</code> on search pages, breaking widget and block queries. (<code>class-query-filter.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Sitemap <code>hreflang</code> attribute values and hreflang head tags now route through <code>SchemaManager::lang_to_bcp47()</code> for correct BCP 47 casing and regional-code normalisation. (<code>class-sitemap-manager.php</code>, <code>class-hreflang.php</code>)</li>' .
			'<li><strong>Fixed:</strong> Bundled translations now load correctly &#8212; <code>load_plugin_textdomain()</code> registered on <code>init</code> (priority 1) and <code>Domain Path: /languages</code> added to the plugin header; <code>.l10n.php</code> performant-translation files load automatically on WP 6.5+. (<code>lingua-forge.php</code>)</li>' .
			'<li><strong>Fixed:</strong> <code>missing-translation-notice</code> block attributes marked with <code>"role": "content"</code> in <code>block.json</code> &#8212; required for WP 7.0&#8217;s <code>contentOnly</code> editing default so the block&#8217;s text fields remain selectable inside template parts and patterns. (<code>missing-translation-notice/block.json</code>)</li>' .
			'<li><strong>Fixed:</strong> Self-hosted updater now includes a <code>sha256</code> field in the manifest and verifies the downloaded ZIP before handing off to WP&#8217;s upgrader; host pinning applied to the manifest endpoint. (<code>docs/lf-update-manifest.php</code>, <code>class-updater.php</code>)</li>' .
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
