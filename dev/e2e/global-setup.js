/**
 * E2E global setup — log in as admin once and save the session.
 *
 * Playwright re-uses the saved storageState for every spec so we only
 * pay the login cost once per test run.
 */

'use strict';

const { test: setup, expect } = require( '@playwright/test' );
const path = require( 'path' );
const fs   = require( 'fs' );

const AUTH_FILE = path.join( __dirname, '.auth/admin.json' );

setup( 'authenticate as admin', async ( { page } ) => {
    fs.mkdirSync( path.dirname( AUTH_FILE ), { recursive: true } );

    await page.goto( '/wp-login.php' );

    await page.fill( '#user_login', 'admin' );
    await page.fill( '#user_pass', 'password' );

    // Wait for the redirect to wp-admin to complete before saving session.
    await Promise.all( [
        page.waitForURL( /wp-admin/, { timeout: 15_000 } ),
        page.click( '#wp-submit' ),
    ] );

    await page.context().storageState( { path: AUTH_FILE } );
} );
