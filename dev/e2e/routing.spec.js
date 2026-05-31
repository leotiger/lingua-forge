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
