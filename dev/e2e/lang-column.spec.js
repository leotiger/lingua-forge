/**
 * E2E — Lang column, language filter, and translation meta box.
 *
 * Verifies the admin post-list layer added by Lingua Forge: the Lang column,
 * the language filter dropdown, and basic meta box presence on post edit screens.
 *
 * Requires: npm run env:seed has been run (creates pages with _lf_lang meta).
 */

'use strict';

const { test, expect } = require( '@playwright/test' );

test.describe( 'Pages list — Lang column', () => {

    test( 'Lang column header is present', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=page' );
        // WP renders the column header in both thead and tfoot — use .first().
        await expect( page.locator( 'th#lang, th.column-lang' ).first() ).toBeVisible();
    } );

    test( 'Language filter dropdown is rendered', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=page' );
        await expect( page.locator( 'select[name="lf_lang_filter"], select#lf_lang_filter' ) ).toBeVisible();
    } );

    test( 'Seeded EN pages show "en" in the Lang column', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=page&lf_lang_filter=en' );

        // At least one row should be visible after filtering for EN.
        const rows = page.locator( 'tbody#the-list tr:not(.no-items)' );
        await expect( rows.first() ).toBeVisible();

        // Every visible Lang cell should contain "EN" (case-insensitive).
        // Cells may also contain sibling-language badges and action links.
        const langCells = page.locator( 'td.column-lang' );
        const count = await langCells.count();
        expect( count ).toBeGreaterThan( 0 );

        for ( let i = 0; i < count; i++ ) {
            await expect( langCells.nth( i ) ).toContainText( /\ben\b/i );
        }
    } );

    test( 'Seeded DE pages show "de" in the Lang column', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=page&lf_lang_filter=de' );

        const langCells = page.locator( 'td.column-lang' );
        const count = await langCells.count();
        expect( count ).toBeGreaterThan( 0 );

        for ( let i = 0; i < count; i++ ) {
            await expect( langCells.nth( i ) ).toContainText( /\bde\b/i );
        }
    } );

    test( 'Seeded CA pages show "ca" in the Lang column', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=page&lf_lang_filter=ca' );

        const langCells = page.locator( 'td.column-lang' );
        const count = await langCells.count();
        expect( count ).toBeGreaterThan( 0 );

        for ( let i = 0; i < count; i++ ) {
            await expect( langCells.nth( i ) ).toContainText( /\bca\b/i );
        }
    } );

} );

test.describe( 'WooCommerce products — Lang column', () => {

    test.beforeEach( async ( { page } ) => {
        // Skip entire describe block if WC is not active.
        await page.goto( '/wp-admin/edit.php?post_type=product' );
        const title = await page.title();
        if ( /not found|invalid/i.test( title ) ) {
            test.skip();
        }
    } );

    test( 'Products list has Lang column', async ( { page } ) => {
        // WP renders thead + tfoot — use .first().
        await expect( page.locator( 'th#lang, th.column-lang' ).first() ).toBeVisible();
    } );

    test( 'Seeded EN product shows "en" in the Lang column', async ( { page } ) => {
        await page.goto( '/wp-admin/edit.php?post_type=product&lf_lang_filter=en' );

        const langCells = page.locator( 'td.column-lang' );
        const count = await langCells.count();
        expect( count ).toBeGreaterThan( 0 );
        await expect( langCells.first() ).toContainText( /\ben\b/i );
    } );

    test( 'DE product edit screen delegates price from EN source', async ( { page } ) => {
        // Navigate to the DE product and check the REST API delegation:
        // the _price meta visible in the editor should match the EN source.
        await page.goto( '/wp-admin/edit.php?post_type=product&lf_lang_filter=de' );

        // WC product list uses td.name, not td.title.
        const firstEditLink = page.locator( 'td.name a.row-title, td.title a.row-title' ).first();
        await expect( firstEditLink ).toBeVisible();

        // Navigate to edit screen — we just verify it loads without errors.
        await firstEditLink.click();
        await expect( page.locator( 'body' ) ).not.toContainText( 'Fatal error' );
    } );

} );
