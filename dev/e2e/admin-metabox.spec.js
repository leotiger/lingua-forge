/**
 * E2E — Admin meta box and admin.js split verification.
 *
 * Verifies that:
 *   - The post edit screen loads without JS errors.
 *   - The window.LfAdmin shared namespace is populated by admin.js.
 *   - The split scripts (admin-diff-modal.js, admin-content-gen-modal.js)
 *     attach their entry points onto window.LfAdmin.
 *   - The Lingua Forge meta box is visible and contains feature action buttons.
 *
 * These tests deliberately avoid AI API calls — they are fast smoke checks
 * for the 2.1.5 JS split (admin.js → LfAdmin namespace + two extracted files).
 *
 * Requires: npm run env:seed has been run (pages exist to edit).
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

// ── helpers ───────────────────────────────────────────────────────────────────

/**
 * Navigate to the first available page edit screen.
 * Returns the URL it landed on so callers can assert against it if needed.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function goToFirstPageEdit( page ) {
    await page.goto( '/wp-admin/edit.php?post_type=page' );
    const firstLink = page.locator( 'td.title a.row-title' ).first();
    await expect( firstLink ).toBeVisible();
    await firstLink.click();

    // Wait until window.LfAdmin is defined.  The Gutenberg block editor keeps
    // the WP Heartbeat API polling indefinitely, so waitForLoadState('networkidle')
    // never resolves.  waitForFunction polls the page context directly and
    // resolves as soon as the admin.js IIFE has finished executing — which is
    // the precise condition all tests in this file depend on.
    await page.waitForFunction(
        () => typeof window.LfAdmin !== 'undefined',
        { timeout: 10_000 }
    );

    // Dismiss the "Welcome to the editor" guide if it is open.
    // On a fresh WordPress install this modal appears on first editor load.
    // While open, Gutenberg marks background content aria-hidden which causes
    // Playwright's toBeVisible() to report meta-box buttons as hidden.
    // The seed sets wp_persisted_preferences to dismiss it, but WP 6.9 may
    // fetch preferences asynchronously after initial render, so we close it
    // here as a belt-and-suspenders guard for the first run.
    const guide = page.getByRole( 'dialog', { name: 'Welcome to the editor' } );
    if ( await guide.isVisible( { timeout: 2_000 } ).catch( () => false ) ) {
        await page.getByRole( 'button', { name: 'Close' } ).first().click();
        // Wait for the dialog to close before proceeding.
        await guide.waitFor( { state: 'hidden', timeout: 3_000 } ).catch( () => {} );
    }

    return page.url();
}

// ── window.LfAdmin namespace ──────────────────────────────────────────────────

test.describe( 'window.LfAdmin namespace (admin.js split)', () => {

    test( 'post edit screen loads without JS exceptions', async ( { page } ) => {
        const jsErrors = [];
        page.on( 'pageerror', ( err ) => jsErrors.push( err.message ) );

        await goToFirstPageEdit( page );

        expect( jsErrors ).toHaveLength( 0 );
    } );

    test( 'window.LfAdmin is defined after admin.js loads', async ( { page } ) => {
        await goToFirstPageEdit( page );

        const defined = await page.evaluate( () => typeof window.LfAdmin === 'object' && window.LfAdmin !== null );
        expect( defined ).toBe( true );
    } );

    test( 'window.LfAdmin exposes core utility functions', async ( { page } ) => {
        await goToFirstPageEdit( page );

        const result = await page.evaluate( () => {
            const ns = window.LfAdmin;
            if ( ! ns ) return { ok: false, missing: [ 'LfAdmin itself' ] };

            const required = [
                'escHtml', 'escAttr', 'sanitizeHtml',
                'isRtlLang', 'getEditorStore', 'isGutenbergActive',
                'findInIframes', 'snapshotCurrentEditorState',
                'lfSlugify', 'applyToClassicEditor',
                'showApplyHint', 'clearApplyError',
            ];

            const missing = required.filter( ( k ) => typeof ns[ k ] !== 'function' );
            return { ok: missing.length === 0, missing };
        } );

        expect( result.missing ).toEqual( [] );
    } );

    test( 'admin-diff-modal.js exports openApplyDiffModal onto window.LfAdmin', async ( { page } ) => {
        await goToFirstPageEdit( page );

        const isFunction = await page.evaluate(
            () => typeof window.LfAdmin?.openApplyDiffModal === 'function'
        );
        expect( isFunction ).toBe( true );
    } );

    test( 'admin-content-gen-modal.js exports openContentGenOverlay onto window.LfAdmin', async ( { page } ) => {
        await goToFirstPageEdit( page );

        const isFunction = await page.evaluate(
            () => typeof window.LfAdmin?.openContentGenOverlay === 'function'
        );
        expect( isFunction ).toBe( true );
    } );

} );

// ── Lingua Forge meta box ─────────────────────────────────────────────────────

test.describe( 'Lingua Forge meta box', () => {

    test( 'meta box is visible on the post edit screen', async ( { page } ) => {
        await goToFirstPageEdit( page );

        // In classic editor the meta box is a visible postbox.
        // In Gutenberg it lives in the sidebar — may be collapsed but present.
        const metaBox = page.locator(
            'div#lingua-forge-ai-box, div[id*="lingua-forge-ai"], .postbox[id*="lingua-forge"]'
        ).first();

        await expect( metaBox ).toBeAttached( { timeout: 10_000 } );
    } );

    test( 'meta box contains at least one feature action button', async ( { page } ) => {
        await goToFirstPageEdit( page );

        // Feature buttons carry the class .lingua-forge-action and data-feature attr.
        const actionBtn = page.locator( '.lingua-forge-action[data-feature]' ).first();
        await expect( actionBtn ).toBeAttached( { timeout: 10_000 } );
    } );

    test( 'clicking a feature action button triggers an admin-ajax.php request', async ( { page } ) => {
        await goToFirstPageEdit( page );

        // Intercept the AJAX call — we don't care about the response content,
        // only that the request is made (proves the click handler in admin.js fired).
        const ajaxPromise = page.waitForRequest(
            ( req ) => req.url().includes( 'admin-ajax.php' ),
            { timeout: 15_000 }
        );

        // Gutenberg collapses the entire "Meta Boxes" section by default.
        // Check aria-expanded so we only open it when it's actually collapsed —
        // toggling an already-open panel would hide the buttons instead.
        // The resize-handle separator overlays the button and intercepts pointer
        // events, so JS-dispatch the click to avoid interception.
        const metaBoxesToggle = page.getByRole( 'region', { name: 'Editor content' } )
            .getByRole( 'button', { name: 'Meta Boxes' } );
        const isCollapsed = await metaBoxesToggle
            .getAttribute( 'aria-expanded', { timeout: 5_000 } )
            .then( v => v === 'false' )
            .catch( () => false );
        if ( isCollapsed ) {
            await metaBoxesToggle.evaluate( el => el.click() );
            await expect( metaBoxesToggle ).toHaveAttribute( 'aria-expanded', 'true', { timeout: 3_000 } )
                .catch( () => {} );
        }

        const btn = page.locator( '.lingua-forge-action[data-feature]' ).first();
        await expect( btn ).toBeVisible( { timeout: 8_000 } );
        await btn.click();

        // Verify the AJAX request was dispatched.
        const req = await ajaxPromise;
        expect( req.url() ).toContain( 'admin-ajax.php' );
    } );

} );
