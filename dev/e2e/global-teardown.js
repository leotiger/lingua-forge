/**
 * E2E global teardown — reset admin user locale after every test run.
 *
 * Any spec that calls ajax_set_user_locale (or otherwise changes the WP
 * admin-user locale preference) can silently corrupt the session for
 * subsequent specs because all specs share a single saved storageState.
 *
 * This teardown runs once after all specs complete and deletes the
 * `locale` user-meta entry for user ID 1 (admin), causing WordPress to
 * fall back to the site default (en_US) on the next login or page load.
 *
 * Failure is non-fatal — if wp-env is not running (e.g. the suite was
 * run without the container), a warning is printed and the teardown exits
 * cleanly so it never blocks the test result.
 */

'use strict';

const { execFileSync } = require( 'child_process' );
const path             = require( 'path' );

module.exports = async function globalTeardown() {

    const wp_env = path.join( __dirname, '..', 'node_modules', '.bin', 'wp-env' );
    const dev_dir = path.join( __dirname, '..' );

    try {
        execFileSync(
            wp_env,
            [ 'run', 'cli', 'wp', 'user', 'meta', 'delete', '1', 'locale' ],
            { cwd: dev_dir, stdio: 'pipe' }
        );
    } catch ( _err ) {
        // Silently ignored. WP-CLI exits with code 1 when the locale meta
        // row doesn't exist (the normal case — most runs don't change locale).
        // Any real failure (container not running, permission error) is also
        // non-fatal and does not affect the test result.
    }
};
