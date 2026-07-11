<?php
/**
 * Lingua Forge — Uninstall handler
 *
 * Runs automatically when an administrator deletes the plugin from
 * Plugins → Installed Plugins → Delete.  WordPress calls this file
 * only when the plugin is deleted (not on deactivation).
 *
 * Always removed:
 *   - Every `linguaforge_*` option in wp_options, via a single LIKE sweep
 *     (AUDIT-2026-07-11 §6) — rather than maintaining a named list plus a
 *     handful of narrower LIKE prefixes that drift out of date every time a
 *     new option ships (this file's own history: the SEO layer's ~15 options,
 *     the sitemap cache bookkeeping options, and half a dozen others were all
 *     missing from the pre-2.6.4 sweep despite the docblock's blanket claim).
 *     A future `linguaforge_*` option needs no entry here to be swept.
 *   - Every `_transient_linguaforge_*` / `_transient_timeout_linguaforge_*`
 *     option — same rationale, covers the sitemap XML/chunk cache, the
 *     rate-limit/quota transients, the self-hosted updater's manifest cache,
 *     and the theme-switch admin notice in one pattern, present or future.
 *     (Only reaches the DB row; a persistent object cache stores transient
 *     values outside wp_options entirely and is not swept here — a
 *     pre-existing limitation, not new to this pass.)
 *   - Two short `lf_*` option names that fall outside the `linguaforge_`
 *     prefix (enumerated explicitly rather than a bare `lf_%` sweep, which
 *     would be too broad given how short that prefix is): the Language
 *     Router DB-version flag and the browser-language-redirect toggle.
 *   - Encrypted API key options (`linguaforge_key_*`) — covered by the
 *     `linguaforge_*` sweep above.
 *   - AI result caches stored in post meta (`_linguaforge_cache_*`)
 *   - Derived/regenerable post meta: `_lf_search_content` (search index),
 *     `_lf_auto_template` (last auto-assigned FSE template, re-derived by
 *     Sync), `_lf_seo_score_history` and `_lf_translation_failures`
 *     (recomputed bookkeeping, added 2.2.5 / 2.5.3 — previously missing from
 *     this list; AUDIT-2026-07-11 §6).
 *   - AI cache and usage custom tables
 *   - Per-user language filter preference (lf_lang_filter)
 *   - The idx_lang composite index on wp_postmeta
 *   - Pending cron events (`linguaforge_backfill_missing_translations`,
 *     `linguaforge_indexnow_submit`, and the WP-Cron fallback for
 *     `linguaforge_run_queued_translation`) and any pending Action Scheduler
 *     actions in the `lingua-forge` group — previously left as orphans on
 *     uninstall (AUDIT-2026-07-11 §6).
 *
 * Removed only when Settings → Maintenance → "Delete content data on uninstall"
 * is explicitly enabled (default: OFF):
 *   - Language Router post meta (_lf_lang, _lf_lang_previous, _lf_trid,
 *     _lf_source_updated_at, _lf_translation_source_updated_at)
 *   - Meta description post meta (_linguaforge_meta_description)
 *   - Per-page AI preset override (_linguaforge_preset)
 *   - Per-post editorial SEO/navigation flags: `_lf_noindex` (per-language
 *     "no index" flag, 2.2.16) and `_lf_page_menu_exclude` (per-language menu
 *     exclusion, 2.2.5) — previously missing from either list
 *     (AUDIT-2026-07-11 §6)
 *   - AI glossary and Translation Memory custom tables
 *
 * The legacy unprefixed key meta_description is never deleted — it may be
 * used by other plugins or themes.
 *
 * NOT touched, by design: `_lf_order_lang` lives in WooCommerce order meta
 * (HPOS `wc_orders_meta` table, written via `$order->update_meta_data()`),
 * not wp_postmeta — it is intentionally retained as part of the order's own
 * history, the same way WooCommerce's own order meta survives a LF uninstall
 * (AUDIT-2026-07-11 §6).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the content-removal preference BEFORE deleting options — once the
// options table is cleared this value is gone.
$linguaforge_remove_content = (bool) get_option( 'linguaforge_remove_content_on_uninstall', false );

// ── wp_options — the two short lf_* names outside the linguaforge_ prefix ────

delete_option( 'lf_lang_router_version' );
delete_option( 'lf_browser_redirect' );

// ── wp_options + transients — blanket LIKE sweeps ────────────────────────────
// See the docblock above for why this replaced the previous named-list +
// narrow-prefix approach (AUDIT-2026-07-11 §6).

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
// Rationale: uninstall.php runs exactly once (on plugin deletion) to irreversibly
// remove plugin data. Caching is meaningless for one-shot destructive operations,
// and there are no WordPress API equivalents for LIKE-based bulk DELETEs or DDL
// (DROP INDEX). All values are either hardcoded literals, escaped via $wpdb->prepare(),
// or passed through $wpdb->insert/$wpdb->delete which handle their own escaping.

$linguaforge_prefixes = [
    'linguaforge\_%',
    '\_transient\_linguaforge\_%',
    '\_transient\_timeout\_linguaforge\_%',
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
    // Last auto-assigned FSE template slug — re-derived by Sync on demand.
    '_lf_auto_template',
    // SEO Content Analysis score history — recomputed on next analysis run.
    '_lf_seo_score_history',
    // Automatic Translation Backfill failure/retry bookkeeping — regenerable.
    '_lf_translation_failures',
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
        // Per-language editorial SEO/navigation flags — explicit admin/author
        // decisions, not derived bookkeeping, so they follow the same
        // opt-in-to-delete rule as the routing/relationship data above.
        '_lf_noindex',
        '_lf_page_menu_exclude',
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

// ── Scheduled events — cron + Action Scheduler ───────────────────────────────
// None of these were cleared on uninstall before 2.6.4 (AUDIT-2026-07-11 §6) —
// deactivation only unschedules the backfill recurrence
// (TranslationBackfill::maybe_unschedule(), lingua-forge.php), so a pending
// single event or queued Action Scheduler job could still fire once after the
// plugin's own code (and therefore its callbacks) is gone.

// Recurring hourly tick (TranslationBackfill::CRON_HOOK). wp_clear_scheduled_hook()
// with no $args clears every pending instance of the hook, not just ones
// scheduled with empty args.
wp_clear_scheduled_hook( 'linguaforge_backfill_missing_translations' );

// Debounced single event, scheduled with a $post_id arg (IndexNowManager::CRON_HOOK).
// Omitting $args here still clears every pending instance regardless of which
// post ID it carries — see wp_clear_scheduled_hook()'s $args semantics.
wp_clear_scheduled_hook( 'linguaforge_indexnow_submit' );

// TranslationQueue::HOOK — dispatched via Action Scheduler when available,
// falling back to a plain wp_schedule_single_event() otherwise (WooCommerce's
// AS integration is only guaranteed present when WooCommerce itself is
// active). Clear the WP-Cron fallback path unconditionally:
wp_clear_scheduled_hook( 'linguaforge_run_queued_translation' );

// ...and any real Action Scheduler action in the 'lingua-forge' group
// (TranslationQueue::GROUP) when Action Scheduler is loaded.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'linguaforge_run_queued_translation', [], 'lingua-forge' );
}

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
