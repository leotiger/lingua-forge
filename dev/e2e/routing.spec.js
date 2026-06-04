/**
 * E2E — Language URL routing.
 *
 * Verifies that the router serves seeded pages at the correct language-prefixed
 * URLs and that visiting an unprefixed URL redirects to the source language.
 *
 * Requires: npm run env:seed has been run (creates Home/Startseite/Inici pages).
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

// wp-env default admin credentials are not needed for front-end routing tests,
// but storageState is inherited from playwright.config.js — harmless here.

test.describe( 'Language URL routing', () => {

    test( 'EN: /en/home serves the English page', async ( { page } ) => {
        const response = await page.goto( '/en/home' );
        expect( response?.status() ).toBe( 200 );
        await expect( page ).not.toHaveTitle( /not found/i );
    } );

    test( 'DE: /de/startseite serves the German page', async ( { page } ) => {
        const response = await page.goto( '/de/startseite' );
        expect( response?.status() ).toBe( 200 );
        await expect( page ).not.toHaveTitle( /not found/i );
    } );

    test( 'CA: /ca/inici serves the Catalan page', async ( { page } ) => {
        const response = await page.goto( '/ca/inici' );
        expect( response?.status() ).toBe( 200 );
        await expect( page ).not.toHaveTitle( /not found/i );
    } );

    test( 'Root / redirects or serves the source-language home', async ( { page } ) => {
        const response = await page.goto( '/' );
        // Either a redirect chain that ends at the source-language URL,
        // or a direct 200 at root — both are valid depending on routing config.
        // What is not acceptable: a 404 or a WP error page.
        expect( response?.status() ).toBeLessThan( 400 );
        await expect( page ).not.toHaveTitle( /not found|error/i );
    } );

    test( 'Cross-language: /de/home is handled without a fatal error', async ( { page } ) => {
        // The router may serve a 404 page, redirect, or fall back gracefully —
        // all are valid. The only invariant is: no PHP fatal error.
        await page.goto( '/de/home' );
        await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
        await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );
    } );

} );

test.describe( 'Language switcher rendering (frontend)', () => {

    // Verifies that the language switcher block renders on the frontend.
    // Requires: npm run env:seed (appends the block to /en/home, which has DE + CA
    // translations so get_languages() returns a non-empty list).

    test( 'language switcher block renders on the EN home page', async ( { page } ) => {
        const response = await page.goto( '/en/home' );
        expect( response?.status() ).toBe( 200 );

        const switcher = page.locator( '.lsflr-switcher' ).first();
        await expect( switcher ).toBeVisible( { timeout: 5_000 } );
    } );

    test( 'language switcher contains links for each configured language', async ( { page } ) => {
        await page.goto( '/en/home' );

        const switcher = page.locator( '.lsflr-switcher' ).first();
        await expect( switcher ).toBeVisible( { timeout: 5_000 } );

        // Each language entry is an <a> tag inside the switcher.
        // With EN + DE + CA seeded, at minimum 2 links must appear.
        const links = switcher.locator( 'a' );
        const count = await links.count();
        expect( count ).toBeGreaterThanOrEqual( 2 );
    } );

} );

test.describe( 'Hreflang output', () => {

    // Navigate to the seeded English home page and verify that the plugin
    // emits the correct <link rel="alternate" hreflang="..."> tags in <head>.
    // Requires: npm run env:seed has been run.

    test( 'EN home page emits at least two hreflang link tags', async ( { page } ) => {
        await page.goto( '/en/home' );

        const hrefs = await page.evaluate( () =>
            Array.from(
                document.querySelectorAll( 'link[rel="alternate"][hreflang]' )
            ).map( el => ( { hreflang: el.getAttribute( 'hreflang' ), href: el.getAttribute( 'href' ) } ) )
        );

        // Expect at least one tag per configured language (EN + DE at minimum).
        expect( hrefs.length ).toBeGreaterThanOrEqual( 2 );
    } );

    test( 'hreflang tags include x-default', async ( { page } ) => {
        await page.goto( '/en/home' );

        const xDefault = await page.evaluate( () => {
            const el = document.querySelector( 'link[rel="alternate"][hreflang="x-default"]' );
            return el ? el.getAttribute( 'href' ) : null;
        } );

        expect( xDefault ).not.toBeNull();
    } );

    test( 'hreflang tags cover all configured languages', async ( { page } ) => {
        await page.goto( '/en/home' );

        const hreflangs = await page.evaluate( () =>
            Array.from(
                document.querySelectorAll( 'link[rel="alternate"][hreflang]' )
            ).map( el => el.getAttribute( 'hreflang' ) )
        );

        // The seeded env has at minimum en, de, ca — each must appear.
        expect( hreflangs ).toContain( 'en' );
        expect( hreflangs ).toContain( 'de' );
        expect( hreflangs ).toContain( 'ca' );
    } );

    test( 'DE page hreflang href points to the DE URL', async ( { page } ) => {
        await page.goto( '/de/startseite' );

        const deHref = await page.evaluate( () => {
            const el = document.querySelector( 'link[rel="alternate"][hreflang="de"]' );
            return el ? el.getAttribute( 'href' ) : null;
        } );

        expect( deHref ).not.toBeNull();
        expect( deHref ).toMatch( /\/de\// );
    } );

} );
