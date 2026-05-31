/**
 * Playwright configuration for Lingua Forge E2E tests.
 *
 * Targets the local wp-env development instance (port 8888).
 * Run after `npm run env:start && npm run env:seed`:
 *
 *   npm run test:e2e
 *
 * Install browsers once with:
 *   npm run e2e:install
 */

'use strict';

const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
    testDir:             './e2e',
    timeout:             30_000,
    expect:              { timeout: 8_000 },
    fullyParallel:       false, // wp-env is a single shared container
    retries:             process.env.CI ? 2 : 0,
    workers:             1,
    reporter:            'list',

    use: {
        baseURL: 'http://localhost:8888',
        trace:   'on-first-retry',
    },

    projects: [
        // Login once, save session — runs before any spec.
        {
            name:      'setup',
            testMatch: /global-setup\.js/,
        },
        // All spec files depend on the saved session.
        {
            name:         'e2e',
            dependencies: [ 'setup' ],
            use:          {
                ...devices[ 'Desktop Chrome' ],
                storageState: './e2e/.auth/admin.json',
            },
        },
    ],
} );
