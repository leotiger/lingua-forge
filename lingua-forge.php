<?php
/**
 * Plugin Name:       Lingua Forge
 * Plugin URI:        https://github.com/leotiger/lingua-forge
 * Description:       Multilingual routing, complete multilingual SEO (hreflang, Open Graph, Schema.org, sitemap), and AI content tools for WordPress. Language detection, URL routing, translation, content generation, and a full SEO layer — no companion plugin required.
 * Version:           2.2.10
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Uli Hake
 * Author URI:        https://lingua-forge.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lingua-forge
 */

defined( 'ABSPATH' ) || exit;

// Prevent double-loading. The test bootstrap's muplugins_loaded callback
// requires this file from the mapped 'lingua-forge' path while WordPress
// also loads it as an active plugin from the wp-env 'lingua-forge-2.0'
// mount — two different path strings, so require_once does not deduplicate.
if ( defined( 'LINGUAFORGE_FILE' ) ) {
	return;
}

// =========================================================
// CORE CONSTANTS
// Available to all sub-modules so they don't need to
// hardcode their own paths.
// =========================================================

define( 'LINGUAFORGE_FILE',    __FILE__ );
define( 'LINGUAFORGE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'LINGUAFORGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'LINGUAFORGE_VERSION', '2.2.10' );

// =========================================================
// ACTIVATION / DEACTIVATION
// flush_rewrite_rules() is required so language-router's
// custom rewrite rules register and deregister cleanly.
// =========================================================

register_activation_hook( __FILE__, function () {

    // language-router registers its rewrite rules on 'init',
    // so we need at least one full request cycle before flushing.
    // Setting this option triggers a flush on the next request.
    update_option( 'linguaforge_flush_rewrite_rules', true, false );

    // Create the uploads-based i18n overrides directory and drop an
    // index.html placeholder so the directory cannot be enumerated via
    // a directory listing.  Drop {textdomain}-{locale}.mo files here
    // to override third-party plugin strings without touching the
    // plugin codebase.  Files survive plugin updates.
    linguaforge_bootstrap_overrides_dir();
} );

/**
 * Idempotent bootstrap for the i18n-overrides directory.
 *
 * Ensures the directory exists and contains a no-op index.html placeholder
 * so direct directory listing is blocked on every server type — including
 * nginx and IIS, which ignore the Apache `.htaccess` "Deny from all" pattern
 * the AI module uses for the debug directory.  `.mo` / `.po` files are
 * non-executable, so the goal here is preventing *enumeration* of the
 * installed override files rather than blocking individual file fetches.
 *
 * Called from:
 *   - register_activation_hook — covers fresh installs and Deactivate→Reactivate.
 *   - admin_init (one-shot, guarded by `linguaforge_overrides_hardened_v1`
 *     option) — covers SFTP / rsync upgrades where the activation hook
 *     does not fire.
 *
 * Both file operations are guarded by `is_dir()` / `file_exists()` so the
 * function is safe to call any number of times.
 */
function linguaforge_bootstrap_overrides_dir(): void {

    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides';

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $index_path = trailingslashit( $dir ) . 'index.html';
    if ( is_dir( $dir ) && ! file_exists( $index_path ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-shot directory-listing guard inside the plugin's own uploads subdir. WP_Filesystem would require admin FTP credentials, inappropriate for a hardening write that must run silently on activation.
        file_put_contents( $index_path, "<!-- Silence is golden. -->\n" );
    }
}

// One-shot hardening for installs that pre-date the index.html placeholder
// (i.e. were activated before this code shipped, or were deployed via
// SFTP / rsync so register_activation_hook never fired).  The option flag
// is bumped to `_v2` etc. if future hardening (a web.config / robots block)
// needs a separate trigger.
add_action( 'admin_init', function () {

    if ( get_option( 'linguaforge_overrides_hardened_v1' ) ) {
        return;
    }

    linguaforge_bootstrap_overrides_dir();
    update_option( 'linguaforge_overrides_hardened_v1', 1, false );
}, 1 );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

add_action( 'init', function () {
    if ( get_option( 'linguaforge_flush_rewrite_rules' ) ) {
        flush_rewrite_rules();
        delete_option( 'linguaforge_flush_rewrite_rules' );
    }
}, 99 );


// =========================================================
// BOOT LANGUAGE ROUTER
// Loaded first so that:
//  • LF_LANG is defined at file-load time (before any init hooks)
//  • Custom rewrite rules are registered before the flush check above
// The AI module does NOT depend on LF_LANG directly — it reads
// _lang post meta and falls back to determine_locale(). Boot order
// here is about URL routing, not AI.
// =========================================================

require_once LINGUAFORGE_PATH . 'language-router/language-router.php';

// =========================================================
// BOOT META DESCRIPTION
// Fully self-contained — no dependency on language-router or
// the AI module. Load order does not matter for this module.
// =========================================================

require_once LINGUAFORGE_PATH . 'meta-description/meta-description.php';

// =========================================================
// BOOT LINGUAFORGE AI
// Requires LINGUAFORGE_PATH / LINGUAFORGE_URL (set above).
// No hard dependency on language-router: language detection
// uses _lang post meta with a determine_locale() fallback,
// so all AI features work even on monolingual sites.
// =========================================================

require_once LINGUAFORGE_PATH . 'ai/ai.php';

// =========================================================
// SELF-HOSTED UPDATE CHECKER
// Hooks into WordPress's plugin-update machinery to serve
// update notices and one-click updates from lingua-forge.com
// rather than the WordPress.org Plugin Directory.
// Admin-only: the remote manifest fetch must never fire on
// frontend requests.
// =========================================================

require_once LINGUAFORGE_PATH . 'includes/class-updater.php';

if ( is_admin() ) {
	Linguaforge_Updater::init();
}

// =========================================================
// UPGRADE DETECTION — eagerly create / upgrade custom tables
//
// Each AI-module class with a custom table (CacheStore, UsageRecorder,
// Glossary, TranslationMemory) already has an idempotent ensure_table()
// that runs dbDelta on a version-option mismatch and is otherwise a single
// get_option() call. The class-level guards mean lazy access is always
// safe — but on zip-overwrite deploys, the WP plugin-activation hook
// doesn't fire, so a newly-shipped table only materializes the first time
// someone touches its feature.
//
// This hook fires on every admin request, but the version check makes the
// body a no-op once tables are up to date. It runs eagerly on:
//   - Fresh installs: option doesn't exist, all tables created on first
//     admin request.
//   - Zip-overwrite / rsync upgrades: option holds an old version, dbDelta
//     re-runs for any schema bumps.
//
// Requires bumping LINGUAFORGE_VERSION (above) on each deploy that ships
// a schema change. Asset cache busting is the same lever, so it's
// good hygiene anyway.
// =========================================================

add_action( 'admin_init', function () {

    if ( get_option( 'linguaforge_installed_version' ) === LINGUAFORGE_VERSION ) {
        return;
    }

    // The classes are autoloaded by ai/ai.php's Autoloader::register(),
    // which already ran during file load above. The dbDelta require is
    // inside each ensure_table() call.
    \LinguaForge\AI\Core\CacheStore::ensure_table();
    \LinguaForge\AI\Core\UsageRecorder::ensure_table();
    \LinguaForge\AI\Core\Glossary::ensure_table();
    \LinguaForge\AI\Core\TranslationMemory::ensure_table();

    update_option( 'linguaforge_installed_version', LINGUAFORGE_VERSION, false );
}, 5 );

// =========================================================
// ONE-SHOT MIGRATION — per-preset addenda (shipped in 1.5.0)
//
// Copies the old single `linguaforge_compliance_addendum` option to
// the active preset's new per-preset option so sites that customised
// the global addendum field don't lose their text after the upgrade.
//
// Guard flag `linguaforge_preset_addendum_migrated_v1` ensures this
// runs exactly once.  Kept here (not inside the version-gate block
// above) so it also fires on SFTP/rsync deploys where the activation
// hook never runs, without waiting for a LINGUAFORGE_VERSION change.
// =========================================================

add_action( 'admin_init', function () {

    if ( get_option( 'linguaforge_preset_addendum_migrated_v1' ) ) {
        return;
    }

    $old_value = trim( (string) get_option( 'linguaforge_compliance_addendum', '' ) );

    if ( $old_value !== '' ) {

        // Identify which per-preset slot to copy into.  Prefer the
        // currently-active preset; fall back to 'legal' (the most common
        // use-case for a custom compliance addendum).
        $active_preset = (string) get_option( 'linguaforge_active_preset', '' );
        $target        = in_array( $active_preset, [ 'technical', 'legal', 'creative' ], true )
            ? $active_preset
            : 'legal';

        // Only copy if the target slot hasn't been set already.
        $target_key = 'linguaforge_preset_addendum_' . $target;
        if ( trim( (string) get_option( $target_key, '' ) ) === '' ) {
            update_option( $target_key, $old_value, false );
        }
    }

    update_option( 'linguaforge_preset_addendum_migrated_v1', 1, false );
}, 10 );
