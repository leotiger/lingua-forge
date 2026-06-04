/**
 * E2E — AI modal surfaces: Quick Translate, diff modal apply flow, content-gen modal.
 *
 * Split into two concerns:
 *
 *   1. REST endpoints — real AI calls that verify the full stack end-to-end.
 *      These skip gracefully when the provider returns empty (transient failure
 *      or missing API key) so CI stays green.
 *
 *   2. Modal UI — no AI calls required. Both modals accept their data as plain
 *      JS objects, so we can open them with mock payloads via page.evaluate()
 *      and verify DOM behaviour without any provider dependency.
 *
 * Requires: npm run env:seed has been run (pages exist to navigate to).
 * AI calls require a live provider key in .wp-env.override.json.
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

// ── helpers ───────────────────────────────────────────────────────────────────

/** Navigate to the first available page edit screen and wait for LfAdmin. */
async function goToFirstPageEdit( page ) {
    await page.goto( '/wp-admin/edit.php?post_type=page' );
    const firstLink = page.locator( 'td.title a.row-title' ).first();
    await expect( firstLink ).toBeVisible();
    await firstLink.click();
    await page.waitForFunction(
        () => typeof window.LfAdmin !== 'undefined',
        { timeout: 10_000 }
    );
}

/** Extract the REST nonce injected by LF into window.LinguaForgeAI.nonce. */
async function getRestNonce( page ) {
    await page.goto( '/wp-admin/' );
    const nonce = await page.evaluate( () => window.wpApiSettings?.nonce || '' );
    expect( nonce ).toBeTruthy();
    return nonce;
}

/** Returns true if the response indicates a transient provider failure (not a plugin bug). */
function isTransientProviderFailure( body ) {
    if ( ! body?.error ) return false;
    return /failed|try again|truncated/i.test( body.error ) &&
           ! /invalid|not found|forbidden|template/i.test( body.error );
}

// ── 1. Quick Translate REST endpoint (/translate-chunk) ───────────────────────

test.describe( 'Quick Translate — /translate-chunk REST endpoint', () => {

    test( 'POST /translate-chunk returns a translated snippet', async ( { page } ) => {
        test.setTimeout( 120_000 );

        const nonce = await getRestNonce( page );

        const response = await page.request.post(
            'http://localhost:8888/wp-json/lingua-forge/v1/translate-chunk',
            {
                headers: {
                    'X-WP-Nonce':   nonce,
                    'Content-Type': 'application/json',
                },
                data: JSON.stringify( {
                    target_language: 'de',
                    chunk_text:      'Hello world. This is a quick translate test.',
                } ),
                timeout: 100_000,
            }
        );

        expect( response.status() ).toBe( 200 );
        const body = await response.json();

        if ( isTransientProviderFailure( body ) ) {
            test.skip( true, `Provider transient failure — skipping: ${ body.error }` );
            return;
        }
        if ( body.error ) {
            throw new Error( `Plugin error on /translate-chunk: ${ body.error }` );
        }

        expect( body.success ).toBe( true );
        expect( typeof body.output ).toBe( 'string' );
        expect( body.output.length ).toBeGreaterThan( 0 );
        expect( body.type ).toBe( 'chunk' );
        expect( body.language ).toMatch( /german/i );
        // Output must differ from the English input.
        expect( body.output ).not.toContain( 'Hello world' );
    } );

} );

// ── 2. Content-gen REST endpoint (/create-chunk) ──────────────────────────────

test.describe( 'Content generation — /create-chunk REST endpoint', () => {

    test( 'POST /create-chunk returns generated content', async ( { page } ) => {
        test.setTimeout( 120_000 );

        const nonce = await getRestNonce( page );

        const response = await page.request.post(
            'http://localhost:8888/wp-json/lingua-forge/v1/create-chunk',
            {
                headers: {
                    'X-WP-Nonce':   nonce,
                    'Content-Type': 'application/json',
                },
                data: JSON.stringify( {
                    hints:           'Write a short intro paragraph about solar energy benefits.',
                    tone:            'informative',
                    target_language: 'de',
                } ),
                timeout: 100_000,
            }
        );

        expect( response.status() ).toBe( 200 );
        const body = await response.json();

        if ( isTransientProviderFailure( body ) ) {
            test.skip( true, `Provider transient failure — skipping: ${ body.error }` );
            return;
        }
        if ( body.error ) {
            throw new Error( `Plugin error on /create-chunk: ${ body.error }` );
        }

        expect( body.success ).toBe( true );
        expect( typeof body.output ).toBe( 'string' );
        expect( body.output.length ).toBeGreaterThan( 0 );
        // ContentGenerator::run() does not return a 'type' field — only 'success', 'output', 'language'.
        expect( body.language ).toMatch( /german/i );
    } );

} );

// ── 3. Diff modal UI — open / apply / close ───────────────────────────────────

test.describe( 'Diff modal — open and interact', () => {

    // These tests use mock payloads — no AI call required.

    test( 'openApplyDiffModal renders the modal with translated content', async ( { page } ) => {
        await goToFirstPageEdit( page );

        await page.evaluate( () => {
            window.LfAdmin.openApplyDiffModal( {
                button:            null, // no meta-box button context needed for modal open
                translatedContent: '<!-- wp:paragraph --><p>Dies ist übersetzter Inhalt.</p><!-- /wp:paragraph -->',
                translatedTitle:   'Übersetzter Titel',
                footnotesJson:     '',
                metaDescription:   '',
                targetLang:        'de',
            } );
        } );

        const modal = page.locator( '#lingua-forge-diff-modal' );
        await expect( modal ).toBeVisible( { timeout: 5_000 } );

        // Translated content pane should contain the mock text.
        const newContentPane = modal.locator( '[data-lf-pane="new-content"]' );
        await expect( newContentPane ).toContainText( 'Dies ist übersetzter Inhalt' );
    } );

    test( 'diff modal Cancel button closes the modal', async ( { page } ) => {
        await goToFirstPageEdit( page );

        await page.evaluate( () => {
            window.LfAdmin.openApplyDiffModal( {
                button:            null,
                translatedContent: '<!-- wp:paragraph --><p>Testinhalt.</p><!-- /wp:paragraph -->',
                translatedTitle:   '',
                footnotesJson:     '',
                targetLang:        'de',
            } );
        } );

        const modal = page.locator( '#lingua-forge-diff-modal' );
        await expect( modal ).toBeVisible( { timeout: 5_000 } );

        // Use the header close button (✕) — the footer Cancel button also matches
        // data-lf-action="cancel" and Playwright would throw a strict-mode violation.
        await modal.locator( 'button.lingua-forge-diff-modal__close' ).click();

        await expect( modal ).toBeHidden( { timeout: 5_000 } );
    } );

    test( 'diff modal Apply button is present, enabled, and clickable', async ( { page } ) => {
        await goToFirstPageEdit( page );

        await page.evaluate( () => {
            window.LfAdmin.openApplyDiffModal( {
                button:            null,
                translatedContent: '<!-- wp:paragraph --><p>Angewendeter Inhalt.</p><!-- /wp:paragraph -->',
                translatedTitle:   'Neuer Titel',
                footnotesJson:     '',
                targetLang:        'de',
            } );
        } );

        const modal = page.locator( '#lingua-forge-diff-modal' );
        await expect( modal ).toBeVisible( { timeout: 5_000 } );

        const applyBtn = modal.locator( 'button[data-lf-action="apply"]' );
        await expect( applyBtn ).toBeVisible();
        await expect( applyBtn ).toBeEnabled();

        // Verify no JS error is thrown when Apply is clicked.
        // (Full editor-write behaviour is covered by the ai-translation.spec.js suite.)
        const jsErrors = [];
        page.on( 'pageerror', ( err ) => jsErrors.push( err.message ) );
        await applyBtn.click();
        expect( jsErrors ).toHaveLength( 0 );
    } );

} );

// ── 4. Content-gen modal UI — open and interact ───────────────────────────────

test.describe( 'Content-gen modal — open and interact', () => {

    // These tests open the modal with a mock payload — no AI call required.

    test( 'openContentGenOverlay renders the modal with generated output', async ( { page } ) => {
        await goToFirstPageEdit( page );

        // Obtain a post ID from the URL so the modal has a valid anchor.
        const postId = await page.evaluate( () => {
            const m = window.location.search.match( /[?&]post=(\d+)/ );
            return m ? m[ 1 ] : '1';
        } );

        await page.evaluate( ( id ) => {
            window.LfAdmin.openContentGenOverlay(
                {
                    success:  true,
                    output:   '<p>Solarenergie ist eine saubere und erneuerbare Energiequelle.</p>',
                    type:     'content',
                    language: 'German',
                },
                { hints: 'Solar energy benefits', tone: 'informative', target_language: 'de' },
                id
            );
        }, postId );

        const modal = page.locator( '#lf-cg-modal' );
        await expect( modal ).toBeVisible( { timeout: 5_000 } );

        // Preview pane should contain the mock output.
        const preview = modal.locator( '[data-lf-cg="preview"]' );
        await expect( preview ).toContainText( 'Solarenergie' );
    } );

    test( 'content-gen modal Cancel button closes the modal', async ( { page } ) => {
        await goToFirstPageEdit( page );

        const postId = await page.evaluate( () => {
            const m = window.location.search.match( /[?&]post=(\d+)/ );
            return m ? m[ 1 ] : '1';
        } );

        await page.evaluate( ( id ) => {
            window.LfAdmin.openContentGenOverlay(
                { success: true, output: '<p>Test.</p>', type: 'content', language: 'German' },
                { hints: 'Test', tone: 'informative', target_language: 'de' },
                id
            );
        }, postId );

        const modal = page.locator( '#lf-cg-modal' );
        await expect( modal ).toBeVisible( { timeout: 5_000 } );

        // The backdrop div is behind the panel — click the explicit close button in the header.
        await modal.locator( 'button.lf-cg-modal__close' ).click();

        await expect( modal ).toBeHidden( { timeout: 5_000 } );
    } );

    test( 'content-gen modal Apply button is present and enabled', async ( { page } ) => {
        await goToFirstPageEdit( page );

        const postId = await page.evaluate( () => {
            const m = window.location.search.match( /[?&]post=(\d+)/ );
            return m ? m[ 1 ] : '1';
        } );

        await page.evaluate( ( id ) => {
            window.LfAdmin.openContentGenOverlay(
                { success: true, output: '<p>Inhalt für den Test.</p>', type: 'content', language: 'German' },
                { hints: 'Test', tone: 'informative', target_language: 'de' },
                id
            );
        }, postId );

        const modal  = page.locator( '#lf-cg-modal' );
        const applyBtn = modal.locator( '[data-lf-cg="apply"]' );

        await expect( modal ).toBeVisible( { timeout: 5_000 } );
        await expect( applyBtn ).toBeVisible();
        await expect( applyBtn ).toBeEnabled();
    } );

} );
