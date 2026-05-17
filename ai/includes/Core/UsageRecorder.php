<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Per-day / per-user / per-feature / per-(provider,model) token-usage tracker.
 *
 * Schema:
 *
 *   {$wpdb->prefix}lingua_forge_ai_usage
 *     id            BIGINT UNSIGNED   auto-increment surrogate
 *     usage_date    DATE              UTC day bucket
 *     user_id       BIGINT UNSIGNED   actor (0 for system / WP-CLI)
 *     feature_key   VARCHAR(64)       'translation', 'meta-description', …
 *     provider      VARCHAR(32)       'Anthropic', 'OpenAI', 'Gemini'
 *     model         VARCHAR(128)      full model string actually used
 *     input_tokens  INT UNSIGNED      cumulative input tokens for the bucket
 *     output_tokens INT UNSIGNED      cumulative output tokens for the bucket
 *     request_count INT UNSIGNED      number of successful chat() calls aggregated
 *     UNIQUE KEY (usage_date, user_id, feature_key, provider, model)
 *     KEY usage_date
 *
 * Capture pipeline:
 *
 *   1. A feature wraps its $provider->chat() call in UsageRecorder::tracked().
 *      The static context stack remembers which feature_key is "in flight".
 *   2. AbstractProvider::chat() calls extract_usage() on a successful decoded
 *      response, then calls UsageRecorder::record() with the token counts.
 *   3. record() looks at the current context. If empty (e.g. the Test
 *      Connection ping or any not-yet-wrapped caller) it silently no-ops.
 *      Otherwise it does an INSERT … ON DUPLICATE KEY UPDATE keyed on the
 *      unique composite, incrementing the existing row's totals.
 *
 * Rationale for the context stack:
 *
 *   - chat() lives in AbstractProvider and has no idea which feature
 *     triggered the call. Passing feature_key through WorkerConfig was
 *     considered but rejected — WorkerConfig is about runtime AI params
 *     (model, tokens, temperature), not call metadata.
 *   - A stack (vs. single static variable) lets nested calls compose
 *     correctly, even though nesting is rare in practice.
 */
class UsageRecorder {

    private const DB_VERSION        = '1.0';
    private const DB_VERSION_OPTION = 'linguaforge_ai_usage_db_version';

    private static bool $table_ensured = false;

    /**
     * Stack of feature_keys currently "in flight". The top of the stack
     * (last element) is the active context that incoming record() calls
     * attribute to.
     *
     * @var list<string>
     */
    private static array $context_stack = [];

    // ── Context management ──────────────────────────────────────────────────

    /**
     * Run $callback with $feature_key set as the active recording context.
     *
     * Uses try/finally so the context is always popped, even if $callback
     * throws. Returns whatever $callback returns.
     *
     * @template T
     * @param string $feature_key e.g. 'translation', 'meta-description'
     * @param callable():T $callback
     * @return T
     */
    public static function tracked(string $feature_key, callable $callback) {

        self::push_context($feature_key);
        try {
            return $callback();
        } finally {
            self::pop_context();
        }
    }

    public static function push_context(string $feature_key): void {

        self::$context_stack[] = $feature_key;
    }

    public static function pop_context(): void {

        array_pop(self::$context_stack);
    }

    public static function current_context(): ?string {

        return self::$context_stack ? end(self::$context_stack) : null;
    }

    // ── Recording ───────────────────────────────────────────────────────────

    /**
     * Aggregate-add a successful chat call's token usage.
     *
     * Silently no-ops when no tracking context is active — that's how the
     * Test Connection ping (and any future bare-chat callers) avoid polluting
     * the usage table.
     */
    public static function record(string $provider, string $model, int $input_tokens, int $output_tokens): void {

        $feature_key = self::current_context();
        if ($feature_key === null) {
            return;
        }

        // Don't store negative or implausibly huge values — extract_usage
        // should already cap, but defense-in-depth.
        $input  = max(0, $input_tokens);
        $output = max(0, $output_tokens);

        self::ensure_table();

        global $wpdb;

        $table   = self::table_name();
        $date    = gmdate('Y-m-d');
        $user_id = (int) get_current_user_id();

        // INSERT ... ON DUPLICATE KEY UPDATE: atomic upsert keyed on the
        // composite unique. On a fresh bucket, INSERT. On an existing one,
        // increment counters. wpdb has no native helper for this idiom;
        // fall back to query() with a prepared statement.
        $sql = "INSERT INTO {$table}
                  (usage_date, user_id, feature_key, provider, model, input_tokens, output_tokens, request_count)
                VALUES
                  (%s, %d, %s, %s, %s, %d, %d, 1)
                ON DUPLICATE KEY UPDATE
                  input_tokens  = input_tokens  + VALUES(input_tokens),
                  output_tokens = output_tokens + VALUES(output_tokens),
                  request_count = request_count + 1";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- INSERT ... ON DUPLICATE on the plugin's own table; the prepare() first argument is the $sql variable built immediately above from "INSERT INTO {$table} … VALUES (%s, %d, %s, %s, %s, %d, %d, 1)" with $table = $wpdb->prefix + hardcoded suffix. All caller data is bound through %s/%d.
        $wpdb->query($wpdb->prepare(
            $sql,
            $date,
            $user_id,
            substr(sanitize_key($feature_key), 0, 64),
            substr($provider, 0, 32),
            substr($model, 0, 128),
            $input,
            $output
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    // ── Queries (for the Settings UI) ───────────────────────────────────────

    /**
     * Read usage rows aggregated by feature_key + provider + model over a
     * date range. Returns flat rows ordered by total tokens descending.
     *
     * Criteria:
     *   - 'since' (string, Y-m-d UTC) — lower bound inclusive.
     *   - 'until' (string, Y-m-d UTC) — upper bound inclusive. Omit for "now".
     *   - 'user_id' (int) — restrict to a single user.
     *
     * @return list<array{feature_key:string,provider:string,model:string,input_tokens:int,output_tokens:int,total_tokens:int,request_count:int}>
     */
    public static function query(array $criteria = []): array {

        self::ensure_table();

        global $wpdb;

        $table = self::table_name();

        $where  = [];
        $values = [];

        if (!empty($criteria['since'])) {
            $where[]  = 'usage_date >= %s';
            $values[] = (string) $criteria['since'];
        }
        if (!empty($criteria['until'])) {
            $where[]  = 'usage_date <= %s';
            $values[] = (string) $criteria['until'];
        }
        if (isset($criteria['user_id']) && (int) $criteria['user_id'] > 0) {
            $where[]  = 'user_id = %d';
            $values[] = (int) $criteria['user_id'];
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT
                    feature_key,
                    provider,
                    model,
                    SUM(input_tokens)  AS input_tokens,
                    SUM(output_tokens) AS output_tokens,
                    SUM(input_tokens + output_tokens) AS total_tokens,
                    SUM(request_count) AS request_count
                FROM {$table}
                {$where_sql}
                GROUP BY feature_key, provider, model
                ORDER BY total_tokens DESC";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read on the plugin's own table; WHERE is built from a whitelisted format-string set above; the bare $sql branch (empty $values) contains no caller-supplied data.
        $rows = $values
            ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!is_array($rows)) {
            return [];
        }

        return array_map(
            static fn(array $r): array => [
                'feature_key'   => (string) $r['feature_key'],
                'provider'      => (string) $r['provider'],
                'model'         => (string) $r['model'],
                'input_tokens'  => (int) $r['input_tokens'],
                'output_tokens' => (int) $r['output_tokens'],
                'total_tokens'  => (int) $r['total_tokens'],
                'request_count' => (int) $r['request_count'],
            ],
            $rows
        );
    }

    /**
     * Count the distinct rows in the usage table (for the "no data yet"
     * empty-state in the Settings UI).
     */
    public static function row_count(): int {

        self::ensure_table();

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- COUNT on the plugin's own table; "SELECT COUNT(*) FROM " . self::table_name() — concat with a class method that resolves to $wpdb->prefix + hardcoded suffix, no caller-supplied data.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name());
    }

    /**
     * Empty the usage table.
     *
     * Returns the number of rows removed. Used by uninstall and (optionally
     * in the future) a Maintenance → Clear Usage History button.
     */
    public static function clear_all(): int {

        self::ensure_table();

        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- COUNT on the plugin's own table; for the success-message count.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- TRUNCATE on the plugin's own table; admin-initiated, no equivalent WP API.
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

        // dbDelta is whitespace-sensitive — preserve the two spaces after
        // "PRIMARY KEY" and "UNIQUE KEY".
        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            usage_date DATE NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            feature_key VARCHAR(64) NOT NULL,
            provider VARCHAR(32) NOT NULL,
            model VARCHAR(128) NOT NULL,
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY day_user_feature_provider_model (usage_date, user_id, feature_key, provider, model),
            KEY usage_date (usage_date)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);

        self::$table_ensured = true;
    }

    private static function table_name(): string {

        global $wpdb;
        return $wpdb->prefix . 'lingua_forge_ai_usage';
    }
}
