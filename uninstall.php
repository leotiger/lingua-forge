<?php
/**
 * Lingua Forge — Uninstall handler
 *
 * Runs automatically when an administrator deletes the plugin from
 * Plugins → Installed Plugins → Delete.  WordPress calls this file
 * only when the plugin is deleted (not on deactivation).
 *
 * Always removed:
 *   - All linguaforge_* and lf_* plugin options from wp_options
 *   - Encrypted API key options (linguaforge_key_*)
 *   - Model override options (linguaforge_model_*)
 *   - Translation Limits options
 *   - Language Router version flag
 *   - AI preset options (linguaforge_active_preset)
 *   - AI result caches stored in post meta (_linguaforge_cache_*)
 *   - Derived/regenerable post meta (_lf_search_content)
 *   - AI cache and usage custom tables
 *   - Per-user language filter preference (lf_lang_filter)
 *   - The idx_lang composite index on wp_postmeta
 *
 * Removed only when Settings → Maintenance → "Delete content data on uninstall"
 * is explicitly enabled (default: OFF):
 *   - Language Router post meta (_lf_lang, _lf_lang_previous, _lf_trid,
 *     _lf_source_updated_at, _lf_translation_source_updated_at)
 *   - Meta description post meta (_linguaforge_meta_description)
 *   - Per-page AI preset override (_linguaforge_preset)
 *   - AI glossary and Translation Memory custom tables
 *
 * The legacy unprefixed key meta_description is never deleted — it may be
 * used by other plugins or themes.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the content-removal preference BEFORE deleting options — once the
// options table is cleared this value is gone.
$linguaforge_remove_content = (bool) get_option( 'linguaforge_remove_content_on_uninstall', false );

// ── wp_options — named keys ───────────────────────────────────────────────────

$linguaforge_named_options = [
    // Core
    'linguaforge_flush_rewrite_rules',
    'linguaforge_provider',
    'lf_lang_router_version',
    'linguaforge_ai_cache_db_version',
    'linguaforge_ai_usage_db_version',
    'linguaforge_ai_glossary_db_version',
    'linguaforge_ai_tm_db_version',
    'linguaforge_translation_memory_enabled',
    'linguaforge_installed_version',
    'linguaforge_overrides_hardened_v1',
    // AI Limits & Security
    'linguaforge_ai_daily_quota',
    'linguaforge_required_capability',
    // Behavior — Block Editor (§2.7)
    'linguaforge_block_editor_allow_lock_blocks',
    'linguaforge_block_editor_allow_template_mode',
    // Behavior — AI preset
    'linguaforge_compliance_addendum',
    'linguaforge_active_preset',
    // Debug logging toggle (§3.7)
    'linguaforge_ai_debug_enabled',
    // Uninstall behaviour toggle
    'linguaforge_remove_content_on_uninstall',
];

foreach ( $linguaforge_named_options as $linguaforge_option ) {
    delete_option( $linguaforge_option );
}

// ── wp_options — wildcard prefixes ────────────────────────────────────────────
// Covers linguaforge_key_*, linguaforge_model_*, linguaforge_translation_*,
// linguaforge_quick_translate_*, linguaforge_content_generator_*,
// and any other options added in future versions.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
// Rationale: uninstall.php runs exactly once (on plugin deletion) to irreversibly
// remove plugin data. Caching is meaningless for one-shot destructive operations,
// and there are no WordPress API equivalents for LIKE-based bulk DELETEs or DDL
// (DROP INDEX). All values are either hardcoded literals, escaped via $wpdb->prepare(),
// or passed through $wpdb->insert/$wpdb->delete which handle their own escaping.

$linguaforge_prefixes = [
    'linguaforge\_key\_%',
    'linguaforge\_model\_%',
    'linguaforge\_translation\_%',
    'linguaforge\_quick\_translate\_%',
    'linguaforge\_content\_generator\_%',
    // Rate-limit / daily-quota transients stored in wp_options. WP auto-expires
    // these via TTL, but on uninstall we sweep them up alongside the timeouts
    // so deletion is complete.
    '\_transient\_linguaforge\_rate\_user\_%',
    '\_transient\_timeout\_linguaforge\_rate\_user\_%',
    '\_transient\_linguaforge\_quota\_daily\_used\_%',
    '\_transient\_timeout\_linguaforge\_quota\_daily\_used\_%',
];

foreach ( $linguaforge_prefixes as $linguaforge_like ) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $linguaforge_like
        )
    );
}

// ── Legacy AI cache postmeta sweep ───────────────────────────────────────────
// Pre-1.4 installs stored AI feature results in wp_postmeta under
// `_linguaforge_cache_<feature>_<lang>` keys. The custom-table CacheStore
// replaced this in 1.4 and the lazy postmeta fallback in get() has since
// been removed, so any rows remaining are unreachable cruft. Sweep them on
// uninstall so the docblock at the top of this file is honest.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        '\_linguaforge\_cache\_%'
    )
);

// ── Post meta — always delete (regenerable / derived) ────────────────────────

$linguaforge_always_meta_keys = [
    // Derived search index — rebuilt on next language assignment save.
    '_lf_search_content',
];

foreach ( $linguaforge_always_meta_keys as $linguaforge_key ) {
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-shot cleanup on plugin uninstall; not a hot path.
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $linguaforge_key ] );
}

// ── Post meta — conditional (content relationships, default: keep) ────────────
//
// Deleted only when the administrator has explicitly enabled
// "Delete content data on uninstall" in Settings → Maintenance.
// Default is OFF to protect against accidental data loss — language
// assignments and translation links (_lang, _trid) represent editorial
// work that cannot be reconstructed automatically.

if ( $linguaforge_remove_content ) {

    $linguaforge_content_meta_keys = [
        // Language Router — routing and relationship data
        '_lf_lang',
        '_lf_lang_previous',
        '_lf_trid',
        '_lf_source_updated_at',
        '_lf_translation_source_updated_at',
        // Meta Description — prefixed key written by this plugin.
        // The legacy unprefixed key `meta_description` is intentionally NOT
        // deleted: it is a generic key that other plugins or themes may also
        // use, and removing it on uninstall could destroy data that isn't ours.
        '_linguaforge_meta_description',
        // Per-page AI preset override
        '_linguaforge_preset',
    ];

    foreach ( $linguaforge_content_meta_keys as $linguaforge_key ) {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-shot cleanup on plugin uninstall; not a hot path.
        $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $linguaforge_key ] );
    }
}


// ── Custom tables — always drop (pure cache / diagnostic data) ───────────────

// AI result cache — regenerable on demand.
// Created on first use by CacheStore::ensure_table().
$linguaforge_ai_cache_table = $wpdb->prefix . 'lingua_forge_ai_cache';

// AI usage / cost tracking — diagnostic data, no editorial value.
// Created on first successful AI call by UsageRecorder::ensure_table().
$linguaforge_ai_usage_table = $wpdb->prefix . 'lingua_forge_ai_usage';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP TABLE on plugin-owned tables; names are $wpdb->prefix + hardcoded suffix, no caller-supplied data. DirectQuery/NoCaching already suppressed by the outer phpcs:disable above.
$wpdb->query( "DROP TABLE IF EXISTS {$linguaforge_ai_cache_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$linguaforge_ai_usage_table}" );

// ── Custom tables — conditional (user-built content, default: keep) ──────────

if ( $linguaforge_remove_content ) {

    // Glossary — user-curated terminology; loss is not automatically recoverable.
    $linguaforge_ai_glossary_table = $wpdb->prefix . 'lingua_forge_ai_glossary';

    // Translation Memory — accumulated from real translation work.
    $linguaforge_ai_tm_table = $wpdb->prefix . 'lingua_forge_ai_tm';

    $wpdb->query( "DROP TABLE IF EXISTS {$linguaforge_ai_glossary_table}" );
    $wpdb->query( "DROP TABLE IF EXISTS {$linguaforge_ai_tm_table}" );
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

// ── User meta ─────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-shot cleanup on plugin uninstall; not a hot path.
$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'lf_lang_filter' ] );

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
