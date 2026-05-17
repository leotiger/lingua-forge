<?php
/**
 * LinguaForge — Uninstall handler
 *
 * Runs automatically when an administrator deletes the plugin from
 * Plugins → Installed Plugins → Delete.  WordPress calls this file
 * only when the plugin is deleted (not on deactivation).
 *
 * Cleans up:
 *   - All linguaforge_* and lf_* plugin options from wp_options
 *   - Encrypted API key options (linguaforge_key_*)
 *   - Model override options (linguaforge_model_*)
 *   - Translation Limits options
 *   - Language Router version / migration flags
 *   - AI result caches stored in post meta (_linguaforge_cache_*)
 *   - Language Router post meta (_lang, _trid, _source_updated_at, etc.)
 *   - Meta description post meta (meta_description)
 *   - Per-user language filter preference (lf_lang_filter)
 *
 * NOTE: _lang, _trid, meta_description, and related keys are intentionally
 * generic so other plugins can read them.  If you want to keep this data
 * after removing LinguaForge, comment out the post meta section below before
 * deleting the plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// ── wp_options — named keys ───────────────────────────────────────────────────

$linguaforge_named_options = [
    // Core
    'linguaforge_flush_rewrite_rules',
    'linguaforge_mu_migration_done',
    'linguaforge_provider',
    'lf_lang_router_version',
];

foreach ( $linguaforge_named_options as $linguaforge_option ) {
    delete_option( $linguaforge_option );
}

// ── wp_options — wildcard prefixes ────────────────────────────────────────────
// Covers linguaforge_key_*, linguaforge_model_*, linguaforge_translation_*,
// linguaforge_quick_translate_*, linguaforge_content_generator_*,
// and any other options added in future versions.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// Rationale: uninstall.php runs exactly once (on plugin deletion) to irreversibly
// remove plugin data. Caching is meaningless for one-shot destructive operations,
// and there are no WordPress API equivalents for LIKE-based bulk DELETEs or DDL
// (DROP INDEX). All values are either hardcoded literals or escaped via $wpdb->prepare().

$linguaforge_prefixes = [
    'linguaforge\_key\_%',
    'linguaforge\_model\_%',
    'linguaforge\_translation\_%',
    'linguaforge\_quick\_translate\_%',
    'linguaforge\_content\_generator\_%',
];

foreach ( $linguaforge_prefixes as $linguaforge_like ) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $linguaforge_like
        )
    );
}

// ── Post meta ─────────────────────────────────────────────────────────────────

$linguaforge_post_meta_keys = [
    // AI result caches — safe to bulk-delete; keyed by pattern below
    // Language Router
    '_lang',
    '_lang_previous',
    '_trid',
    '_source_updated_at',
    '_translation_source_updated_at',
    '_search_content',
    // Meta Description
    'meta_description',
];

foreach ( $linguaforge_post_meta_keys as $linguaforge_key ) {
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $linguaforge_key ] );
}

// AI caches use a prefix (_linguaforge_cache_*) — delete with LIKE.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        '\_linguaforge\_cache\_%'
    )
);

// ── User meta ─────────────────────────────────────────────────────────────────

$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'lf_lang_filter' ] );
$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'my_lang_filter' ] );

// ── DB index created on activation ───────────────────────────────────────────
// The plugin adds a composite index on wp_postmeta for fast _lang queries.
// Remove it on uninstall to leave the database in its original state.

$linguaforge_index_exists = $wpdb->get_var(
    "SELECT COUNT(1)
     FROM information_schema.STATISTICS
     WHERE table_schema = DATABASE()
       AND table_name   = '{$wpdb->postmeta}'
       AND index_name   = 'idx_lang'"
);

if ( $linguaforge_index_exists ) {
    $wpdb->query( "ALTER TABLE {$wpdb->postmeta} DROP INDEX idx_lang" );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
