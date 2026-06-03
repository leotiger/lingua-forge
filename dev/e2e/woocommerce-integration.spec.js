/**
 * E2E — WooCommerce integration: variable products, variation translations,
 * product_brand delegation, and REST write guard.
 *
 * All tests in this file require:
 *   1. WooCommerce active in wp-env.
 *   2. npm run env:seed has been run (creates Test Shirt variable product
 *      with EN/DE/CA translations, pa_color terms Red/Blue with translated
 *      term names, product_brand "Acme", and translated variation children
 *      via VariationSync).
 *
 * Skips gracefully when WooCommerce is not active.
 *
 * Scenarios:
 *   Admin — variable product list
 *     1. Variable product (Test Shirt) appears in EN products list.
 *     2. Translated DE variable product appears in DE products list.
 *     3. Translated CA variable product appears in CA products list.
 *     4. Variable product admin edit screen loads without PHP errors.
 *
 *   Frontend — product page rendering
 *     5. EN variable product page loads and renders without fatal errors.
 *     6. DE translated product page loads without fatal errors.
 *     7. CA translated product page loads without fatal errors.
 *     8. EN product page has a variation form / attribute selector.
 *     9. DE product page has a variation form / attribute selector (delegation active).
 *
 *   TermNameFilter — translated attribute labels
 *    10. DE product page contains translated colour term name ("Rot" or "Blau").
 *
 *   product_brand delegation
 *    11. EN product page contains the brand name "Acme".
 *    12. DE product page also shows "Acme" (delegated from source via TaxonomyDelegate).
 *
 *   REST write guard
 *    13. GET /wc/v3/products/{en_id} returns 200.
 *    14. PUT /wc/v3/products/{de_id} returns 422 with linguaforge error code.
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

// ── WC availability guard ──────────────────────────────────────────────────────
// Shared beforeEach used across all describe blocks.
async function skipIfWcInactive( page ) {
	await page.goto( '/wp-admin/edit.php?post_type=product' );
	const title = await page.title();
	if ( /not found|invalid/i.test( title ) ) {
		test.skip();
	}
}

// ── Helper: find the first edit link matching a title substring ────────────────
async function findProductEditLink( page, titleSubstring ) {
	const link = page.locator(
		`td.name a.row-title:has-text("${ titleSubstring }"), td.title a.row-title:has-text("${ titleSubstring }")`
	).first();
	return link;
}

// ── Helper: resolve a product ID via WC REST (admin nonce auth) ────────────────
// WP REST API supports cookie + nonce authentication for admin sessions.
async function resolveProductIdBySlug( page, slug ) {
	const nonce = await page.evaluate( () => {
		// wpApiSettings is available on admin screens where wp-api is enqueued.
		return window.wpApiSettings?.nonce ?? null;
	} );
	if ( ! nonce ) {
		return null;
	}
	const response = await page.request.get( `/wp-json/wc/v3/products?slug=${ slug }&status=publish`, {
		headers: { 'X-WP-Nonce': nonce },
	} );
	if ( ! response.ok() ) {
		return null;
	}
	const products = await response.json();
	return Array.isArray( products ) && products.length > 0 ? products[ 0 ].id : null;
}

// =============================================================================
// Admin — variable product list
// =============================================================================

test.describe( 'WooCommerce variable product — admin list', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '1. Test Shirt EN appears in the EN products list', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=product&lf_lang_filter=en' );
		await expect(
			page.locator( 'td.name a.row-title, td.title a.row-title' )
			    .filter( { hasText: /test shirt/i } )
			    .first()
		).toBeVisible();
	} );

	test( '2. Test Shirt DE appears in the DE products list', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=product&lf_lang_filter=de' );
		await expect(
			page.locator( 'td.name a.row-title, td.title a.row-title' )
			    .filter( { hasText: /test.hemd/i } )
			    .first()
		).toBeVisible();
	} );

	test( '3. Test Shirt CA appears in the CA products list', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=product&lf_lang_filter=ca' );
		await expect(
			page.locator( 'td.name a.row-title, td.title a.row-title' )
			    .filter( { hasText: /samarreta/i } )
			    .first()
		).toBeVisible();
	} );

	test( '4. Variable product EN edit screen loads without PHP errors', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=product&lf_lang_filter=en' );

		const editLink = await findProductEditLink( page, 'Test Shirt' );
		await expect( editLink ).toBeVisible();
		await editLink.click();

		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );
	} );

} );

// =============================================================================
// Frontend — product page rendering
// =============================================================================

test.describe( 'WooCommerce variable product — frontend rendering', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '5. EN product page loads without fatal errors', async ( { page } ) => {
		const response = await page.goto( '/en/test-shirt' );
		expect( response?.status() ).toBeLessThan( 500 );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );
	} );

	test( '6. DE translated product page loads without fatal errors', async ( { page } ) => {
		const response = await page.goto( '/de/test-shirt-de' );
		expect( response?.status() ).toBeLessThan( 500 );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );
	} );

	test( '7. CA translated product page loads without fatal errors', async ( { page } ) => {
		const response = await page.goto( '/ca/test-shirt-ca' );
		expect( response?.status() ).toBeLessThan( 500 );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );
	} );

	test( '8. EN product page contains variation attribute data', async ( { page } ) => {
		await page.goto( '/en/test-shirt' );
		// WC embeds available_variations JSON or renders attribute selects in the HTML.
		// Use page.content() to search raw HTML — the block theme may render the form
		// hidden initially (JS-enhanced) so toBeVisible() would time out even when the
		// element is correctly in the DOM.
		const html = await page.content();
		// Variation-indicating strings in any WC template or block:
		//   classic: form.variations_form, attribute_pa_color
		//   block:   wp-block-add-to-cart-form, data-attribute, wc-block
		const hasVariationData =
			/variations_form|attribute_pa_color|data-product_variations|wc-block-add-to-cart|available_variations/i.test( html );
		expect( hasVariationData ).toBe( true );
	} );

	test( '9. DE product page contains variation attribute data (MetaDelegate delegation active)', async ( { page } ) => {
		await page.goto( '/de/test-shirt-de' );
		// Same as test 8 but for the translated product. If MetaDelegate bulk-read
		// delegation works, WC loads _product_attributes from the source and renders
		// the variation UI. Searching raw HTML avoids false negatives from WC's JS
		// hiding the form until attribute selection events fire.
		const html = await page.content();
		const hasVariationData =
			/variations_form|attribute_pa_color|data-product_variations|wc-block-add-to-cart|available_variations/i.test( html );
		expect( hasVariationData ).toBe( true );
	} );

} );

// =============================================================================
// TermNameFilter — translated attribute term labels
// =============================================================================

test.describe( 'WooCommerce TermNameFilter — translated colour names', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '10. DE product page contains translated colour term names (Rot/Blau)', async ( { page } ) => {
		await page.goto( '/de/test-shirt-de' );
		// TermNameFilter hooks get_term (Store API / block path) and wp_get_object_terms
		// (classic path). Language is read from _lf_lang on the queried product post
		// since WC products have no /de/ URL prefix. Both paths produce translated names.
		const html = await page.content();
		expect( /\bRot\b/.test( html ) || /\bBlau\b/.test( html ) ).toBe( true );
	} );

} );

// =============================================================================
// product_brand delegation
// =============================================================================

test.describe( 'WooCommerce product_brand delegation', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '11. EN product page contains brand "Acme" in page HTML', async ( { page } ) => {
		await page.goto( '/en/test-shirt' );
		// Brand may render in a meta element, schema JSON-LD, or theme-specific block —
		// search raw HTML rather than requiring a visible element.
		const html = await page.content();
		expect( /Acme/i.test( html ) ).toBe( true );
	} );

	test( '12. DE product page also contains "Acme" (TaxonomyDelegate brand delegation)', async ( { page } ) => {
		await page.goto( '/de/test-shirt-de' );
		// TaxonomyDelegate clears the WP term cache at `wp` action priority 5 so
		// get_the_terms() goes through wp_get_object_terms() → our filter → source terms.
		// Brand appears in raw HTML (meta, schema, or visible block) when delegation works.
		const html = await page.content();
		expect( /Acme/i.test( html ) ).toBe( true );
	} );

} );

// =============================================================================
// REST write guard
// =============================================================================

test.describe( 'WooCommerce REST write guard', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
		// Navigate to an admin screen to get a valid WP nonce for REST calls.
		await page.goto( '/wp-admin/edit.php?post_type=product' );
	} );

	test( '13. GET /wc/v3/products returns 200 for EN source product', async ( { page } ) => {
		const en_id = await resolveProductIdBySlug( page, 'test-shirt' );
		if ( ! en_id ) {
			test.skip( true, 'Could not resolve EN product ID — nonce or REST unavailable.' );
			return;
		}

		const nonce = await page.evaluate( () => window.wpApiSettings?.nonce ?? '' );
		const response = await page.request.get( `/wp-json/wc/v3/products/${ en_id }`, {
			headers: { 'X-WP-Nonce': nonce },
		} );
		expect( response.status() ).toBe( 200 );
	} );

	test( '14. PUT /wc/v3/products/{de_id} returns 422 (RestWriteGuard)', async ( { page } ) => {
		const de_id = await resolveProductIdBySlug( page, 'test-shirt-de' );
		if ( ! de_id ) {
			test.skip( true, 'Could not resolve DE product ID — nonce or REST unavailable.' );
			return;
		}

		const nonce = await page.evaluate( () => window.wpApiSettings?.nonce ?? '' );
		const response = await page.request.put( `/wp-json/wc/v3/products/${ de_id }`, {
			headers: {
				'X-WP-Nonce':  nonce,
				'Content-Type': 'application/json',
			},
			data: JSON.stringify( { regular_price: '99.99' } ),
		} );

		// RestWriteGuard returns HTTP 422 for writes targeting translated products.
		expect( response.status() ).toBe( 422 );

		const body = await response.json();
		expect( body.code ).toBe( 'linguaforge_rest_write_to_translated_product' );

		// Error data must include the source product ID so callers can resolve it.
		expect( body.data?.source_id ).toBeGreaterThan( 0 );
	} );

} );

// =============================================================================
// Price delegation — MetaDelegate bulk-read end-to-end
// =============================================================================

test.describe( 'WooCommerce MetaDelegate — price delegation', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '15. DE product page shows delegated price range from source variations', async ( { page } ) => {
		await page.goto( '/de/test-shirt-de' );
		// MetaDelegate::maybe_delegate_bulk() intercepts WC's bulk get_post_meta()
		// read and injects source variation prices. The price range (€19.99–€21.99)
		// is embedded in the WP Interactivity state JSON on the page.
		// WC stores prices in minor units (1999 = €19.99); either form may appear.
		const html = await page.content();
		expect( /1999|19[,.]99/.test( html ) ).toBe( true );
	} );

} );

// =============================================================================
// TermNameFilter — CA translated attribute term labels
// =============================================================================

test.describe( 'WooCommerce TermNameFilter — CA colour names', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '16. CA product page contains translated colour term names (Vermell or Blau)', async ( { page } ) => {
		await page.goto( '/ca/test-shirt-ca' );
		// Same get_term / _lf_lang postmeta detection path as DE.
		// Seed stores _lf_term_name_ca = "Vermell" (Red) and "Blau" (Blue).
		const html = await page.content();
		expect( /\bVermell\b/.test( html ) || /\bBlau\b/.test( html ) ).toBe( true );
	} );

} );

// =============================================================================
// REST write guard — translated product_variation
// =============================================================================

test.describe( 'WooCommerce REST write guard — translated variation', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
		await page.goto( '/wp-admin/edit.php?post_type=product' );
	} );

	test( '17. PUT /wc/v3/products/{de_id}/variations/{var_id} returns 422 (RestWriteGuard)', async ( { page } ) => {
		const de_id = await resolveProductIdBySlug( page, 'test-shirt-de' );
		if ( ! de_id ) {
			test.skip( true, 'Could not resolve DE product ID.' );
			return;
		}

		const nonce = await page.evaluate( () => window.wpApiSettings?.nonce ?? '' );

		// Fetch the DE product's variation children via WC REST.
		const varResponse = await page.request.get(
			`/wp-json/wc/v3/products/${ de_id }/variations?per_page=1`,
			{ headers: { 'X-WP-Nonce': nonce } }
		);

		if ( ! varResponse.ok() ) {
			test.skip( true, 'Could not fetch DE product variations.' );
			return;
		}

		const variations = await varResponse.json();
		if ( ! Array.isArray( variations ) || variations.length === 0 ) {
			test.skip( true, 'No translated variations found for DE product.' );
			return;
		}

		const var_id = variations[ 0 ].id;

		// RestWriteGuard also hooks woocommerce_rest_pre_insert_product_variation_object.
		const response = await page.request.put(
			`/wp-json/wc/v3/products/${ de_id }/variations/${ var_id }`,
			{
				headers: {
					'X-WP-Nonce':   nonce,
					'Content-Type': 'application/json',
				},
				data: JSON.stringify( { regular_price: '99.99' } ),
			}
		);

		expect( response.status() ).toBe( 422 );

		const body = await response.json();
		expect( body.code ).toBe( 'linguaforge_rest_write_to_translated_product' );
	} );

} );
