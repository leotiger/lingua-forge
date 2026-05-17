<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Block-level translation memory — cache translated equivalents of source
 * blocks keyed by (block markup hash, source lang, target lang, glossary hash)
 * so reusable content (shared footers, sidebars, accordions, boilerplate-heavy
 * legal sections) can be served from cache across posts.
 *
 * Cache key composition:
 *
 *   sha256( block_markup + "\x00" + source_lang + "\x00" + target_lang
 *           + "\x00" + glossary_hash + "\x00" + compliance_signature )
 *
 * Including the glossary hash means a glossary edit invalidates every TM row
 * for the affected language pair on next read. The compliance signature
 * folds in the compliance preset state — flipping the preset on or off
 * should also invalidate cached translations so the new system prompt's
 * stricter rules actually apply.
 *
 * Schema:
 *
 *   {$wpdb->prefix}lingua_forge_ai_tm
 *     id              BIGINT UNSIGNED  PK auto-increment
 *     content_hash    CHAR(64)         see formula above
 *     source_lang     VARCHAR(8)
 *     target_lang     VARCHAR(8)
 *     source_text     LONGTEXT         the source block markup (for audit/debug)
 *     translated_text LONGTEXT         the translated block markup
 *     cached_at       INT UNSIGNED
 *     last_hit_at     INT UNSIGNED
 *     hit_count       INT UNSIGNED
 *     UNIQUE KEY (content_hash)
 *     KEY (source_lang, target_lang)
 *     KEY (last_hit_at)        — future LRU eviction
 *
 * Lifecycle:
 *
 *   - TM is OPT-IN via the linguaforge_translation_memory_enabled option
 *     (Behavior tab toggle). When off, Translation::run() takes its existing
 *     pre-§4.5 path. When on, blocks are looked up here before the API call.
 *   - The table is created lazily on first lookup or store.
 *   - clear_all() truncates; future passes can add LRU eviction by last_hit_at.
 */
class TranslationMemory {

    private const DB_VERSION        = '1.0';
    private const DB_VERSION_OPTION = 'linguaforge_ai_tm_db_version';

    private static bool $table_ensured = false;

    // ── Public toggle helpers ───────────────────────────────────────────────

    /**
     * Whether Translation::run() should route through TM.
     *
     * Filterable via `linguaforge_translation_memory_enabled` so site code
     * can force on/off (e.g. disable for a specific post type) on top of
     * the stored option.
     */
    public static function enabled(): bool {

        $opt = (bool) get_option('linguaforge_translation_memory_enabled', false);
        return (bool) apply_filters('linguaforge_translation_memory_enabled', $opt);
    }

    // ── Lookup ──────────────────────────────────────────────────────────────

    /**
     * Compute the cache-key hash for a single block.
     *
     * Public so the Translation feature can reuse the exact formula when
     * storing — guarantees lookup and store agree on the hash.
     */
    public static function compute_hash(
        string $block_markup,
        string $source_lang,
        string $target_lang,
        string $glossary_hash,
        string $compliance_signature
    ): string {

        return hash(
            'sha256',
            $block_markup . "\x00" . $source_lang . "\x00" . $target_lang
                . "\x00" . $glossary_hash
                . "\x00" . $compliance_signature
        );
    }

    /**
     * Bulk lookup: given an array of source-block markups, return a map
     * keyed by the markup whose value is the translated markup. Misses are
     * absent from the returned map (so caller can use !isset() to detect).
     *
     * Updates last_hit_at + hit_count on every cache hit in a single UPDATE
     * statement so we don't pay a separate write per block.
     *
     * @param list<string> $block_markups
     * @return array<string, string>   source_markup => translated_markup
     */
    public static function lookup_batch(
        array $block_markups,
        string $source_lang,
        string $target_lang,
        string $glossary_hash,
        string $compliance_signature
    ): array {

        if (empty($block_markups)) {
            return [];
        }

        self::ensure_table();

        // Compute hashes upfront; build a reverse map (hash → markup) so we
        // can translate hash-keyed query results back to markup-keyed.
        $hash_to_markup = [];
        foreach ($block_markups as $markup) {
            $hash = self::compute_hash($markup, $source_lang, $target_lang, $glossary_hash, $compliance_signature);
            $hash_to_markup[$hash] = $markup;
        }

        $hashes = array_keys($hash_to_markup);

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $table        = self::table_name();
        $sql          = "SELECT content_hash, translated_text
                         FROM {$table}
                         WHERE content_hash IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- IN clause built from a known-size %s placeholder list above; $hashes are sha256 hexes bound through %s.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $hashes), ARRAY_A);

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $hits = [];
        $hit_hashes = [];
        foreach ($rows as $row) {
            $hash = (string) $row['content_hash'];
            if (isset($hash_to_markup[$hash])) {
                $hits[$hash_to_markup[$hash]] = (string) $row['translated_text'];
                $hit_hashes[] = $hash;
            }
        }

        // Bookkeeping: bump last_hit_at + hit_count for every hit row in
        // one statement. Non-critical — if it fails we still return the hits.
        if (!empty($hit_hashes)) {
            $now = time();
            $update_placeholders = implode(',', array_fill(0, count($hit_hashes), '%s'));
            $update_sql = "UPDATE {$table}
                           SET last_hit_at = %d, hit_count = hit_count + 1
                           WHERE content_hash IN ({$update_placeholders})";
            $args = array_merge([$now], $hit_hashes);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- IN clause built from a known-size %s placeholder list above; $args is $now + $hit_hashes, all bound through %d/%s.
            $wpdb->query($wpdb->prepare($update_sql, $args));
        }

        return $hits;
    }

    /**
     * Store a freshly-translated block. Idempotent — repeated stores for the
     * same hash leave the existing row (use clear / invalidate to refresh).
     *
     * Returns true on insert success (or already-present), false on hard failure.
     */
    public static function store(
        string $source_markup,
        string $translated_markup,
        string $source_lang,
        string $target_lang,
        string $glossary_hash,
        string $compliance_signature
    ): bool {

        self::ensure_table();

        $hash = self::compute_hash($source_markup, $source_lang, $target_lang, $glossary_hash, $compliance_signature);

        global $wpdb;

        $now = time();

        // INSERT IGNORE: if a row with this content_hash already exists,
        // skip without error. Idempotent on the cache-key composition.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- INSERT IGNORE on the plugin's own table; the prepare() first argument is "INSERT IGNORE INTO " . self::table_name() . "…" — concat with a class method that resolves to $wpdb->prefix + hardcoded suffix. All caller data is bound through %s/%d.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::table_name() . "
                (content_hash, source_lang, target_lang, source_text, translated_text, cached_at, last_hit_at, hit_count)
             VALUES (%s, %s, %s, %s, %s, %d, %d, 0)",
            $hash,
            $source_lang,
            $target_lang,
            $source_markup,
            $translated_markup,
            $now,
            $now
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return true;
    }

    // ── Stats / maintenance ─────────────────────────────────────────────────

    /**
     * Return overall TM stats for the Maintenance UI:
     *   - rows: number of cached blocks
     *   - total_hits: sum of hit_count across all rows
     *   - oldest: earliest cached_at (UTC Y-m-d), empty when no rows
     *   - newest: latest cached_at
     *   - bytes_estimate: approximate storage size — sum of source+translated text lengths
     */
    public static function stats(): array {

        self::ensure_table();

        global $wpdb;

        $table = self::table_name();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read on the plugin's own table.
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*)                                                  AS rows_count,
                COALESCE(SUM(hit_count), 0)                               AS total_hits,
                COALESCE(MIN(cached_at), 0)                               AS oldest_ts,
                COALESCE(MAX(cached_at), 0)                               AS newest_ts,
                COALESCE(SUM(LENGTH(source_text) + LENGTH(translated_text)), 0) AS bytes
             FROM {$table}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!is_array($row)) {
            return ['rows' => 0, 'total_hits' => 0, 'oldest' => '', 'newest' => '', 'bytes_estimate' => 0];
        }

        return [
            'rows'           => (int) $row['rows_count'],
            'total_hits'     => (int) $row['total_hits'],
            'oldest'         => $row['oldest_ts'] > 0 ? gmdate('Y-m-d', (int) $row['oldest_ts']) : '',
            'newest'         => $row['newest_ts'] > 0 ? gmdate('Y-m-d', (int) $row['newest_ts']) : '',
            'bytes_estimate' => (int) $row['bytes'],
        ];
    }

    public static function clear_all(): int {

        self::ensure_table();

        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- COUNT on the plugin's own table.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- TRUNCATE on the plugin's own table.
        $wpdb->query("TRUNCATE TABLE {$table}");

        return $count;
    }

    // ── Schema ──────────────────────────────────────────────────────────────

    public static function ensure_table(): void {

        if (self::$table_ensured) {
            return;
        }

        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            self::$table_ensured = true;
            return;
        }

        global $wpdb;

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            content_hash CHAR(64) NOT NULL,
            source_lang VARCHAR(8) NOT NULL,
            target_lang VARCHAR(8) NOT NULL,
            source_text LONGTEXT NOT NULL,
            translated_text LONGTEXT NOT NULL,
            cached_at INT UNSIGNED NOT NULL,
            last_hit_at INT UNSIGNED NOT NULL,
            hit_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY content_hash (content_hash),
            KEY lang_pair (source_lang, target_lang),
            KEY last_hit_at (last_hit_at)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);

        self::$table_ensured = true;
    }

    private static function table_name(): string {

        global $wpdb;
        return $wpdb->prefix . 'lingua_forge_ai_tm';
    }
}
