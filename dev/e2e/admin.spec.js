/**
 * E2E — Admin: Settings page and tab structure.
 *
 * Catches fatal PHP errors, missing tab registrations, JS crashes, and
 * UI regressions in the Settings page without requiring AI API calls.
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

const SETTINGS_URL = '/wp-admin/options-general.php?page=lingua-forge';

test.describe( 'Settings page', () => {

    test( 'loads without PHP errors or JS exceptions', async ( { page } ) => {
        const jsErrors = [];
        page.on( 'pageerror', ( err ) => jsErrors.push( err.message ) );

        await page.goto( SETTINGS_URL );
        await expect( page ).toHaveTitle( /Lingua Forge/i );

        // No fatal output (PHP notices / errors render before the page title)
        await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
        await expect( page.locator( 'body' ) ).not.toContainText( 'Parse error' );

        expect( jsErrors ).toHaveLength( 0 );
    } );

    test( 'has all ten tabs', async ( { page } ) => {
        await page.goto( SETTINGS_URL );

        const expectedTabs = [
            'General', 'API Keys', 'Limits', 'Behavior',
            'Router', 'Glossary', 'AI Usage', 'Maintenance', 'SEO', 'System',
        ];

        for ( const label of expectedTabs ) {
            await expect(
                page.locator( '.lf-tab-nav a, .nav-tab', { hasText: label } ).first()
            ).toBeVisible();
        }
    } );

    test( 'Router tab: FSE language setup section is present', async ( { page } ) => {
        await page.goto( SETTINGS_URL + '#router' );
        await page.locator( 'a[href*="#router"], .nav-tab', { hasText: 'Router' } ).first().click();

        await expect(
            page.locator( 'h2, h3', { hasText: /language setup|fse|templates/i } ).first()
        ).toBeVisible( { timeout: 8_000 } );
    } );

    test( 'Maintenance tab: Language Overrides section is present', async ( { page } ) => {
        await page.goto( SETTINGS_URL + '#maintenance' );
        await page.locator( 'a[href*="#maintenance"], .nav-tab', { hasText: 'Maintenance' } ).first().click();

        await expect(
            page.locator( 'h2', { hasText: /Language Overrides/i } )
        ).toBeVisible( { timeout: 8_000 } );
    } );

    test( 'API Keys tab: provider fields are rendered', async ( { page } ) => {
        await page.goto( SETTINGS_URL + '#api-keys' );
        await page.locator( 'a[href*="#api-keys"], .nav-tab', { hasText: 'API Keys' } ).first().click();

        // Wait for at least one API key input to be visible in the active panel.
        // This is more reliable than text-matching because the tab content may
        // be rendered via JS and provider names may be inside hidden elements.
        await expect(
            page.locator( 'input[type="password"], input[type="text"][name*="key"], input[type="text"][name*="api"]' ).first()
        ).toBeVisible( { timeout: 8_000 } );
    } );

} );

test.describe( 'Post list — admin checks', () => {

    test( 'Pages list loads without errors', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=page' );
        await expect( page ).toHaveTitle( /Pages/i );
        await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
    } );

    test( 'Post edit screen loads the Lingua Forge meta box', async ( { page } ) => {
        // Navigate to any published page.
        await page.goto( '/wp-admin/edit.php?post_type=page' );

        const firstEditLink = page.locator( 'td.title a.row-title' ).first();
        await expect( firstEditLink ).toBeVisible();
        await firstEditLink.click();

        // The LF meta box is registered and present in the DOM.
        // In Gutenberg it lives inside a sidebar panel that may be collapsed,
        // so check attachment rather than visibility.
        await expect(
            page.locator( 'div#lingua-forge-ai-box, div[id*="lingua-forge-ai"], .postbox[id*="lingua-forge"]' ).first()
        ).toBeAttached( { timeout: 10_000 } );
    } );

} );
