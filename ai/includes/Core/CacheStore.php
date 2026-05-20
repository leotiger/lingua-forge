<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Custom-table cache for AI-generated feature results.
 *
 * Before v1.4 each cache entry lived in wp_postmeta keyed by feature. A full
 * page translation can run 30–100 KB of HTML, and every `get_post_meta($post_id)`
 * no-key call loaded the lot — once a post had a handful of language caches
 * the editor was paying megabytes per save. The custom table fixes that:
 *
 *   wp_lingua_forge_ai_cache
 *     post_id      BIGINT UNSIGNED   primary key part
 *     feature_key  VARCHAR(64)       primary key part
 *     hash         CHAR(64)          SHA-256 of inputs
 *     payload      LONGTEXT          JSON-encoded feature result
 *     cached_at    INT UNSIGNED      Unix timestamp
 *
 * Each entry stores the result payload alongside a SHA-256 hash of the
 * inputs used to produce it. On retrieval the hash is recomputed and
 * compared — a mismatch (i.e. the post content or locale changed) causes
 * a cache miss and a fresh API call is made.
 *
 * No TTL: caches are invalidated precisely when the input hash changes.
 *
 * Public API is identical to the previous post-meta implementation — get(),
 * set(), delete(), and hash() — so call sites in Translation / MetaDescription /
 * ExcerptGenerator / ContentGenerator need no changes.
 */
class CacheStore {

    /**
     * Schema version. Bump when the CREATE TABLE shape changes; ensure_table()
     * compares this to the linguaforge_ai_cache_db_version option and re-runs
     * dbDelta on a mismatch.
     */
    private const DB_VERSION        = '1.0';
    private const DB_VERSION_OPTION = 'linguaforge_ai_cache_db_version';

    /**
     * Static guard so ensure_table() is at most a one-time check per request,
     * even when get()/set() are called many times in the same page load.
     *
     * @var bool
     */
    private static bool $table_ensured = false;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the cached payload if the stored hash matches, or null on miss.
     *
     * @return array<string, mixed>|null
     */
    public static function get(
        int    $post_id,
        string $feature,
        string $hash
    ): ?array {

        self::ensure_table();

        global $wpdb;

        $feature_key = self::sanitize_feature($feature);
        $table       = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table is the canonical store; no WP API exists, and object-cache invalidation is unnecessary for an input-hash-keyed cache that's already its own cache.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built from $wpdb->prefix and a hardcoded suffix; no user input is interpolated.
                "SELECT hash, payload FROM {$table} WHERE post_id = %d AND feature_key = %s",
                $post_id,
                $feature_key
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        if ($row['hash'] !== $hash) {
            return null; // inputs changed — stale cache, will be overwritten on next set()
        }

        $payload = json_decode((string) $row['payload'], true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Persist a feature result payload alongside the input hash. The
     * combination of $post_id + $feature is the row's composite key;
     * $hash identifies the input set that produced this $payload (used
     * by get() to detect stale cache entries).
     */
    public static function set(
        int    $post_id,
        string $feature,
        string $hash,
        array  $payload
    ): void {

        self::ensure_table();

        self::write_row(
            $post_id,
            self::sanitize_feature($feature),
            $hash,
            $payload,
            time()
        );
    }

    /** Remove the cache entry for a specific feature on a post. */
    public static function delete(
        int    $post_id,
        string $feature
    ): void {

        self::ensure_table();

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Targeted DELETE against the plugin's own table; no caching layer applies.
        $wpdb->delete(
            self::table_name(),
            [
                'post_id'     => $post_id,
                'feature_key' => self::sanitize_feature($feature),
            ],
            ['%d', '%s']
        );
    }

    /**
     * Empty the cache table. Returns the number of entries that were in the
     * table before clearing. Convenience wrapper around clear() with no criteria.
     */
    public static function clear_all(): int {

        return self::clear();
    }

    /**
     * Delete cache rows matching the supplied criteria.
     *
     * @param array{post_id?:int,feature_prefix?:string} $criteria
     *   - post_id: only delete entries for this post.
     *   - feature_prefix: only delete entries whose feature_key starts with
     *     this string (e.g. 'translation' matches translation_fr, translation_de,
     *     while 'meta-description' is a literal full-key match).
     *   With no criteria, clears the whole table.
     *
     * @return int Number of cache table rows deleted.
     */
    public static function clear(array $criteria = []): int {

        self::ensure_table();

        global $wpdb;

        $table = self::table_name();

        $where  = [];
        $values = [];

        if (isset($criteria['post_id']) && (int) $criteria['post_id'] > 0) {
            $where[]  = 'post_id = %d';
            $values[] = (int) $criteria['post_id'];
        }

        if (!empty($criteria['feature_prefix']) && is_string($criteria['feature_prefix'])) {
            $where[]  = 'feature_key LIKE %s';
            $values[] = $wpdb->esc_like(self::sanitize_feature($criteria['feature_prefix'])) . '%';
        }

        // Whole-table clear (no criteria) — fastest path, lets us TRUNCATE
        // and skip the COUNT-then-DELETE round-trip.
        if (empty($where)) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- COUNT on the plugin's own table for the success-message; table name is built from $wpdb->prefix.
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- TRUNCATE on the plugin's own table; admin-initiated, no equivalent WP API.
            $wpdb->query("TRUNCATE TABLE {$table}");

            return $count;
        }

        // Scoped clear — DELETE returns affected-row count directly.
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Scoped DELETE on the plugin's own table; $sql is composed from a whitelisted format-string list ($where) above and $values are bound through %d/%s placeholders.
        $result = $wpdb->query($wpdb->prepare($sql, $values));

        return is_int($result) ? $result : 0;
    }

    /**
     * Compute a deterministic SHA-256 hash from an ordered list of inputs.
     * All values are cast to string before hashing.
     *
     * @param list<string> $inputs
     */
    public static function hash(array $inputs): string {

        return hash(
            'sha256',
            implode("\x00", array_map('strval', $inputs))
        );
    }

    // ── Schema migration ──────────────────────────────────────────────────────

    /**
     * Create or upgrade the cache table.
     *
     * Idempotent — dbDelta is harmless on an unchanged schema, and the option
     * check short-circuits the cost on every subsequent request once the
     * schema is up to date. The static $table_ensured flag short-circuits
     * within a single request even before the option lookup runs.
     */
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

        // dbDelta is whitespace-sensitive — keep two spaces after PRIMARY KEY
        // and one space after each column type. See the WP handbook.
        $sql = "CREATE TABLE {$table} (
            post_id BIGINT(20) UNSIGNED NOT NULL,
            feature_key VARCHAR(64) NOT NULL,
            hash CHAR(64) NOT NULL,
            payload LONGTEXT NOT NULL,
            cached_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (post_id, feature_key),
            KEY cached_at (cached_at)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);

        self::$table_ensured = true;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Atomic upsert (REPLACE on the composite primary key). Centralises the
     * JSON encoding and the wpdb format strings so every write path through
     * set() goes through exactly one codepath.
     */
    private static function write_row(
        int    $post_id,
        string $feature_key,
        string $hash,
        array  $payload,
        int    $cached_at
    ): void {

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Upsert on the plugin's own table; object-cache invalidation is unnecessary.
        $wpdb->replace(
            self::table_name(),
            [
                'post_id'     => $post_id,
                'feature_key' => $feature_key,
                'hash'        => $hash,
                'payload'     => (string) wp_json_encode($payload),
                'cached_at'   => $cached_at,
            ],
            ['%d', '%s', '%s', '%s', '%d']
        );
    }

    private static function table_name(): string {

        global $wpdb;
        return $wpdb->prefix . 'lingua_forge_ai_cache';
    }

    private static function sanitize_feature(string $feature): string {

        // sanitize_key gives [a-z0-9_\-]; the feature column is VARCHAR(64).
        return substr(sanitize_key($feature), 0, 64);
    }
}
