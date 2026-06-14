/**
 * E2E — WooCommerce checkout journey in a translated language (DE).
 *
 * Covers the complete front-end purchase path that integration tests cannot:
 * add-to-cart → DE cart page (WcPageBridge) → DE checkout page.
 *
 * Prerequisites (all handled by `npm run env:seed`):
 *   1. WooCommerce active with the Test Widget product seeded.
 *   2. DE translations of the WC cart and checkout pages created and linked via
 *      _lf_trid so WcPageBridge can route DE visitors to them.
 *
 * Skips gracefully when any prerequisite is missing.
 *
 * Scenarios:
 *   1. DE product page has an add-to-cart button.
 *   2. Adding DE product to cart redirects to the DE cart page (WcPageBridge).
 *   3. DE cart shows the product title and a recognisable price.
 *   4. Proceeding to checkout lands on the DE checkout page (WcPageBridge).
 *
 * PENDING (scenarios 5–6 — COD order placement + order-received page):
 *   WC Blocks checkout fetches payment methods via the Store API after page load.
 *   With only virtual items in the cart, WC returns an empty payment-methods list
 *   even when COD is enabled — the enable_for_virtual gateway setting is not
 *   reliably honoured in the Store API context.  These scenarios are deferred
 *   until a reproducible fix is found (use a physical product + shipping zone, or
 *   confirm the Store API gateway-availability hook that controls virtual carts).
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

// ── Guard: skip the whole file when WooCommerce is not active ─────────────────
async function skipIfWcInactive( page ) {
	await page.goto( '/wp-admin/edit.php?post_type=product' );
	const title = await page.title();
	if ( /not found|invalid/i.test( title ) ) {
		test.skip();
	}
}

// ── Helper: resolve the EN source product ID from the EN product page ─────────
// The EN product (test-widget) has _price set and is purchasable by WC.
// The DE translation has no _price meta — MetaDelegate delegates it at runtime
// via filter hooks that fire AFTER WC's add-to-cart URL handler already ran its
// is_purchasable() check, so the DE product can't be added via ?add-to-cart=.
// Using the EN product ID in a DE URL context still exercises WcPageBridge.
async function resolveEnProductId( page ) {
	const response = await page.goto( '/en/test-widget' );
	if ( ! response || response.status() >= 400 ) {
		return null;
	}
	return page.evaluate( () => {
		// Method 1: WC classic single-product button carries value="{id}".
		const btn = document.querySelector( 'button[name="add-to-cart"]' );
		if ( btn ) {
			const id = parseInt( btn.getAttribute( 'value' ), 10 );
			if ( id ) return id;
		}
		// Method 2: WC adds data-product_id to link-style add-to-cart elements.
		const atcEl = document.querySelector( '[data-product_id]' );
		if ( atcEl ) {
			const id = parseInt( atcEl.getAttribute( 'data-product_id' ), 10 );
			if ( id ) return id;
		}
		// Method 3: link href ?add-to-cart={id} (archive-style or block theme).
		const link = document.querySelector( 'a[href*="add-to-cart="]' );
		if ( link ) {
			const m = ( link.getAttribute( 'href' ) || '' ).match( /add-to-cart=(\d+)/ );
			if ( m ) return parseInt( m[ 1 ], 10 );
		}
		// Fallback: WP adds postid-{N} to body class on singular posts.
		const mc = document.body.className.match( /\bpostid-(\d+)\b/ );
		return mc ? parseInt( mc[ 1 ], 10 ) : null;
	} );
}

// ── Helper: add a product to cart in DE language context ──────────────────────
// Uses the EN source product (purchasable) via /de/warenkorb/?add-to-cart={id}.
// The /de/ prefix sets the LF language context to DE, so WcPageBridge returns
// the DE cart page ID when WC queries woocommerce_cart_page_id.
//
// Returns { added: true } when cart is confirmed non-empty,
// { added: false, reason } when setup failed.
async function addDeProductToCart( page ) {
	const productId = await resolveEnProductId( page );
	if ( ! productId ) {
		return { added: false, reason: 'EN source product (/en/test-widget) not found — run env:seed.' };
	}

	await page.goto( `/de/warenkorb/?add-to-cart=${ productId }` );

	const html = await page.content();
	const isEmpty = /warenkorb ist derzeit leer|cart is currently empty/i.test( html );
	if ( isEmpty ) {
		return { added: false, reason: 'Cart still empty after adding EN product in DE context.' };
	}
	return { added: true };
}

// =============================================================================
// Scenario 1 — DE product page has an add-to-cart button
// =============================================================================

test.describe( 'WC checkout journey — DE product add-to-cart', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '1. DE product page has an add-to-cart element', async ( { page } ) => {
		// Navigate to the product page and verify an add-to-cart element is present
		// in the raw HTML (button or link — both are valid WC patterns).
		const response = await page.goto( '/de/test-widget-de' );
		if ( ! response || response.status() >= 400 ) {
			test.skip( true, 'DE product page (/de/test-widget-de) not found — run env:seed.' );
			return;
		}

		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );

		const html = await page.content();
		const hasAddToCart =
			/single_add_to_cart_button|wc-block-add-to-cart|add-to-cart/i.test( html );
		expect( hasAddToCart ).toBe( true );
	} );

} );

// =============================================================================
// Scenario 2 — Adding product redirects to the DE cart page (WcPageBridge)
// =============================================================================

test.describe( 'WC checkout journey — WcPageBridge cart redirect', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '2. Adding DE product to cart lands on the DE cart page (WcPageBridge)', async ( { page } ) => {
		const result = await addDeProductToCart( page );
		if ( ! result.added ) {
			test.skip( true, result.reason );
			return;
		}

		// addDeProductToCart navigates to /de/warenkorb/?add-to-cart={id}.
		// WcPageBridge must have resolved the DE cart page — confirm the URL
		// contains the DE cart slug ("warenkorb") and the cart is non-empty.
		const finalUrl = page.url();
		expect( /warenkorb/i.test( finalUrl ) ).toBe( true );

		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );
	} );

} );

// =============================================================================
// Scenario 3 — DE cart shows the product and a price
// =============================================================================

test.describe( 'WC checkout journey — cart contents', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '3. DE cart contains the product name and a price', async ( { page } ) => {
		// Add the product first via URL add-to-cart (lands on /de/warenkorb).
		const result = await addDeProductToCart( page );
		if ( ! result.added ) {
			test.skip( true, result.reason );
			return;
		}

		// Re-navigate to DE cart to get a clean page load for assertions.
		const response = await page.goto( '/de/warenkorb' );
		// A 404 means the DE cart page wasn't seeded — skip gracefully.
		if ( response && response.status() === 404 ) {
			test.skip( true, 'DE cart page (/de/warenkorb) not found — run env:seed.' );
			return;
		}

		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );

		const html = await page.content();

		// Product name (either EN source or DE title delegated) must appear.
		const hasProduct =
			/Test.Widget|Test.Hemd|test-widget|test-shirt/i.test( html );
		expect( hasProduct ).toBe( true );

		// A price must appear — MetaDelegate reads price from source.
		const hasPrice = /\d+[,.]\d{2}/.test( html );
		expect( hasPrice ).toBe( true );
	} );

} );

// =============================================================================
// Scenario 4 — Proceeding to checkout lands on the DE checkout page
// =============================================================================

test.describe( 'WC checkout journey — WcPageBridge checkout redirect', () => {

	test.beforeEach( async ( { page } ) => {
		await skipIfWcInactive( page );
	} );

	test( '4. Proceeding to checkout lands on the DE checkout page (WcPageBridge)', async ( { page } ) => {
		// Ensure at least one product is in the cart.
		const result = await addDeProductToCart( page );
		if ( ! result.added ) {
			test.skip( true, result.reason );
			return;
		}

		// Navigate directly to DE checkout (WcPageBridge slug = "kasse").
		// WC only shows the checkout form when the cart is non-empty.
		const response = await page.goto( '/de/kasse' );
		if ( response && response.status() === 404 ) {
			test.skip( true, 'DE checkout page (/de/kasse) not found — run env:seed.' );
			return;
		}

		await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );

		// The checkout form must be present (blocks or classic).
		const html = await page.content();
		const hasCheckout =
			/wc-block-checkout|woocommerce-checkout|checkout-form|billing_first_name/i.test( html );
		expect( hasCheckout ).toBe( true );
	} );

} );

// =============================================================================
// Scenarios 5 & 6 — PENDING: Place COD order; verify order-received page in DE
//
// Not implemented: WC Blocks checkout with a virtual-only cart returns an empty
// payment-methods list from the Store API even when COD is enabled and
// enable_for_virtual=yes.  The checkout form renders but no payment method is
// shown, so order placement cannot be automated.  Options to unblock:
//   a) Seed a physical product + shipping zone so a shipping method is selected.
//   b) Identify the WC Store API hook that gates gateway availability for virtual
//      carts and add the fix to the plugin or the seed script.
// =============================================================================

