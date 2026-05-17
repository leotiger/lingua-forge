<?php
/**
 * Plugin Name:       Lingua Forge
 * Plugin URI:        https://github.com/leotiger/lingua-forge
 * Description:       Multilingual routing, SEO meta tags, and AI content tools for WordPress. Combines language detection, URL routing, hreflang, meta descriptions, and AI-powered excerpt, meta, and translation features.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Uli Hake
 * Author URI:        https://ulihake.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lingua-forge
 */

defined( 'ABSPATH' ) || exit;

// =========================================================
// CORE CONSTANTS
// Available to all sub-modules so they don't need to
// hardcode their own paths.
// =========================================================

define( 'LINGUAFORGE_FILE',    __FILE__ );
define( 'LINGUAFORGE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'LINGUAFORGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'LINGUAFORGE_VERSION', '1.1.0' );

// =========================================================
// ACTIVATION / DEACTIVATION
// flush_rewrite_rules() is required so language-router's
// custom rewrite rules register and deregister cleanly.
// =========================================================

register_activation_hook( __FILE__, function () {

    // language-router registers its rewrite rules on 'init',
    // so we need at least one full request cycle before flushing.
    // Setting this option triggers a flush on the next request.
    update_option( 'linguaforge_flush_rewrite_rules', true );

    // Create the uploads-based i18n overrides directory so it exists
    // immediately after activation.  Drop {textdomain}-{locale}.mo files
    // here to override third-party plugin strings without touching the
    // plugin codebase.  Files survive plugin updates.
    $upload  = wp_upload_dir();
    $dir     = trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides';

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
} );

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
// ONE-TIME MIGRATION FROM MU-PLUGIN VERSIONS
//
// Runs once on the first init after activation (or first
// load after manual mu-plugin removal). Renames wp_options
// keys written by the old standalone mu-plugins so that all
// AI settings (provider, encrypted API keys, model overrides)
// and the language-router version marker carry over without
// any manual SQL or re-entry.
//
// The encrypted API key values are fully portable: both the
// old and new KeyStore derive the AES-256 secret from the
// same wp_salt('auth'), so the ciphertext decrypts correctly
// under the new option names.
//
// Safe to run on a clean install — UPDATE WHERE is a no-op
// when the old row does not exist.
// =========================================================

add_action( 'init', function () {

    if ( get_option( 'linguaforge_mu_migration_done' ) ) {
        return;
    }

    global $wpdb;

    $renames = [
        // AI provider selection
        'wpenhance_ai_provider'              => 'linguaforge_provider',
        // Encrypted API keys
        'wpenhance_ai_key_anthropic'         => 'linguaforge_key_anthropic',
        'wpenhance_ai_key_openai'            => 'linguaforge_key_openai',
        'wpenhance_ai_key_gemini'            => 'linguaforge_key_gemini',
        // Model overrides
        'wpenhance_ai_model_anthropic_light'   => 'linguaforge_model_anthropic_light',
        'wpenhance_ai_model_anthropic_quality' => 'linguaforge_model_anthropic_quality',
        'wpenhance_ai_model_openai_light'      => 'linguaforge_model_openai_light',
        'wpenhance_ai_model_openai_quality'    => 'linguaforge_model_openai_quality',
        'wpenhance_ai_model_gemini_light'      => 'linguaforge_model_gemini_light',
        'wpenhance_ai_model_gemini_quality'    => 'linguaforge_model_gemini_quality',
        // Language router version marker
        'my_lang_router_version'             => 'lf_lang_router_version',
    ];

    foreach ( $renames as $old => $new ) {
        // Only migrate when the old key exists and the new one does not yet,
        // to avoid clobbering a value already entered in LinguaForge.
        if ( false !== get_option( $old ) && false === get_option( $new ) ) {
            $wpdb->update(
                $wpdb->options,
                [ 'option_name' => $new ],
                [ 'option_name' => $old ],
                [ '%s' ],
                [ '%s' ]
            );
            // Bust WP's in-memory options cache for both names.
            wp_cache_delete( $old, 'options' );
            wp_cache_delete( $new, 'options' );
        }
    }

    // ── User meta: language filter preference per editor ──────────────────────
    // my_lang_filter → lf_lang_filter  (affects all users; one bulk UPDATE).
    // Only runs when at least one row with the old key still exists.
    $old_meta_exists = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = 'my_lang_filter'"
    );

    if ( $old_meta_exists > 0 ) {
        // Collect affected user IDs before the bulk rename so we can
        // surgically invalidate only their usermeta cache entries.
        $affected_user_ids = $wpdb->get_col(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'my_lang_filter'"
        );

        $wpdb->update(
            $wpdb->usermeta,
            [ 'meta_key' => 'lf_lang_filter' ],
            [ 'meta_key' => 'my_lang_filter' ],
            [ '%s' ],
            [ '%s' ]
        );

        // Invalidate only the cache entries we touched.
        // wp_cache_flush() would nuke the entire object cache (Redis/Memcached)
        // which is far too aggressive for a per-user meta key rename.
        foreach ( $affected_user_ids as $user_id ) {
            wp_cache_delete( (int) $user_id, 'user_meta' );
        }
    }

    update_option( 'linguaforge_mu_migration_done', LINGUAFORGE_VERSION, false );

}, 1 );

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
