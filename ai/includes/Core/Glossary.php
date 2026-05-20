<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * User-managed terminology table — domain glossary per language pair.
 *
 * Schema:
 *
 *   {$wpdb->prefix}lingua_forge_ai_glossary
 *     id           BIGINT UNSIGNED  PK auto-increment
 *     source_term  VARCHAR(255)     literal source phrase
 *     target_term  VARCHAR(255)     preferred translation (or the same string,
 *                                   when the rule is "do not translate")
 *     source_lang  VARCHAR(8)       ISO code; empty = "any source language"
 *     target_lang  VARCHAR(8)       ISO code; empty = "any target language"
 *     notes        TEXT             free-form editor note
 *     created_at   INT UNSIGNED
 *     updated_at   INT UNSIGNED
 *
 * Two use cases drive the empty source_lang option:
 *
 *   - Brand names that should never translate regardless of source (e.g.
 *     "Cal Talaia" → "Cal Talaia" with source_lang='').
 *   - Industry-standard abbreviations that read the same in every language
 *     (e.g. "kWp" → "kWp" with source_lang='').
 *
 * Translation::run() (and run_chunk) call get_for_pair() and inject the
 * resulting entries into the system prompt before sending to the AI. The
 * hash_for_pair() hash is folded into the Translation Memory cache key so
 * a glossary edit invalidates affected TM rows on next translation.
 */
class Glossary {

    private const DB_VERSION        = '1.0';
    private const DB_VERSION_OPTION = 'linguaforge_ai_glossary_db_version';

    private static bool $table_ensured = false;

    // ── CRUD ────────────────────────────────────────────────────────────────

    /**
     * Insert a new glossary entry. Returns the new row ID, or 0 on failure
     * (invalid input — duplicate (source_term, source_lang, target_lang)
     * triples are NOT enforced at the schema level so the caller is
     * responsible for dedup if desired).
     */
    public static function insert(
        string $source_term,
        string $target_term,
        string $source_lang,
        string $target_lang,
        string $notes = ''
    ): int {

        self::ensure_table();

        $source_term = trim($source_term);
        $target_term = trim($target_term);
        $target_lang = sanitize_key($target_lang);

        // source_term and target_term are always required; both lang fields
        // may be empty ('' = "any language" wildcard).
        if ($source_term === '' || $target_term === '') {
            return 0;
        }

        global $wpdb;

        $now = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into the plugin's own glossary table; $wpdb->insert handles all escaping. No WP API equivalent for custom tables.
        $result = $wpdb->insert(
            self::table_name(),
            [
                'source_term' => substr($source_term, 0, 255),
                'target_term' => substr($target_term, 0, 255),
                'source_lang' => substr(sanitize_key($source_lang), 0, 8),
                'target_lang' => substr($target_lang, 0, 8),
                'notes'       => sanitize_textarea_field($notes),
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d']
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Delete a single entry by ID. Returns true on success.
     */
    public static function delete(int $id): bool {

        if ($id <= 0) return false;

        self::ensure_table();

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete from the plugin's own glossary table by primary key; $wpdb->delete handles escaping. No WP API equivalent for custom tables; caching is not applicable to a write operation.
        return (bool) $wpdb->delete(
            self::table_name(),
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Empty the glossary table. Returns the row count before truncation.
     */
    public static function clear_all(): int {

        self::ensure_table();

        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- COUNT on the plugin's own table.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- TRUNCATE on the plugin's own table; admin-initiated.
        $wpdb->query("TRUNCATE TABLE {$table}");

        return $count;
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    /**
     * Return every entry, optionally filtered by language codes.
     *
     * @param array{source_lang?:string,target_lang?:string} $criteria
     * @return list<array{id:int,source_term:string,target_term:string,source_lang:string,target_lang:string,notes:string,created_at:int,updated_at:int}>
     */
    public static function get_all(array $criteria = []): array {

        self::ensure_table();

        global $wpdb;

        $where  = [];
        $values = [];

        if (!empty($criteria['source_lang'])) {
            $where[]  = 'source_lang = %s';
            $values[] = sanitize_key((string) $criteria['source_lang']);
        }
        if (!empty($criteria['target_lang'])) {
            $where[]  = 'target_lang = %s';
            $values[] = sanitize_key((string) $criteria['target_lang']);
        }

        $sql = "SELECT * FROM " . self::table_name();
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY target_lang ASC, source_term ASC';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read on the plugin's own table; WHERE built from a whitelisted format-string set above; the bare $sql branch (empty $values) contains no caller-supplied data.
        $rows = $values
            ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array($rows) ? array_map([self::class, 'cast_row'], $rows) : [];
    }

    /**
     * Return every entry that applies when translating FROM $source_lang TO
     * $target_lang. Includes wildcard rows (source_lang = '') so brand names
     * and language-agnostic terms are always picked up.
     *
     * @return list<array{
     *     id:           int,
     *     source_term:  string,
     *     target_term:  string,
     *     source_lang:  string,
     *     target_lang:  string,
     *     notes:        string,
     *     created_at:   int,
     *     updated_at:   int
     * }>
     */
    public static function get_for_pair(string $source_lang, string $target_lang): array {

        self::ensure_table();

        $source_lang = sanitize_key($source_lang);
        $target_lang = sanitize_key($target_lang);

        // A blank $target_lang means the caller doesn't know the target yet;
        // return an empty set so nothing is injected into the prompt.
        if ($target_lang === '') {
            return [];
        }

        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read on the plugin's own table; the prepare() first argument is "SELECT * FROM " . self::table_name() . "…" — concat with a class method that resolves to $wpdb->prefix + hardcoded suffix. $source_lang / $target_lang bound through %s.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . "
                 WHERE (target_lang = %s OR target_lang = '')
                   AND (source_lang = %s OR source_lang = '')
                 ORDER BY source_term ASC",
                $target_lang,
                $source_lang
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return is_array($rows) ? array_map([self::class, 'cast_row'], $rows) : [];
    }

    /**
     * Stable hash of all glossary entries applicable to a translation pair.
     *
     * Used as part of the Translation Memory cache-key composition: when the
     * admin adds, edits, or removes a glossary entry, the hash changes,
     * which invalidates affected TM rows on the next translation. Without
     * this, a glossary change wouldn't propagate to already-cached
     * translations until their source content also changed.
     */
    public static function hash_for_pair(string $source_lang, string $target_lang): string {

        $rows = self::get_for_pair($source_lang, $target_lang);

        if (empty($rows)) {
            return 'none';
        }

        // Stable serialization: sort by id then concatenate compact tuples.
        // We don't include `notes` or timestamps — those don't affect the
        // semantic translation contract.
        usort($rows, static fn($a, $b): int => $a['id'] <=> $b['id']);

        $serialized = implode("\x00", array_map(
            static fn(array $r): string => "{$r['source_term']}|{$r['target_term']}|{$r['source_lang']}|{$r['target_lang']}",
            $rows
        ));

        return substr(hash('sha256', $serialized), 0, 16);
    }

    /**
     * Format the glossary as a system-prompt section ready to append to the
     * translator's instructions. Returns an empty string when no entries
     * apply to the language pair (so callers can append unconditionally).
     */
    public static function format_for_prompt(string $source_lang, string $target_lang): string {

        $rows = self::get_for_pair($source_lang, $target_lang);

        if (empty($rows)) {
            return '';
        }

        $lines = ['Mandatory terminology — apply these substitutions exactly:'];

        foreach ($rows as $r) {

            // "do not translate" rule: source_term and target_term are the
            // same string. Tag the line so the AI knows it's a preserve
            // directive rather than a substitution.
            if ($r['source_term'] === $r['target_term']) {
                $lines[] = sprintf(
                    '- Preserve "%s" verbatim (do not translate).',
                    $r['source_term']
                );
            } else {
                $lines[] = sprintf(
                    '- "%s" → "%s"',
                    $r['source_term'],
                    $r['target_term']
                );
            }

            if ($r['notes'] !== '') {
                $lines[] = '  (' . $r['notes'] . ')';
            }
        }

        return implode("\n", $lines);
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
            source_term VARCHAR(255) NOT NULL,
            target_term VARCHAR(255) NOT NULL,
            source_lang VARCHAR(8) NOT NULL DEFAULT '',
            target_lang VARCHAR(8) NOT NULL,
            notes TEXT,
            created_at INT UNSIGNED NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            KEY lang_pair (source_lang, target_lang),
            KEY target_lang (target_lang)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);

        self::$table_ensured = true;
    }

    private static function table_name(): string {

        global $wpdb;
        return $wpdb->prefix . 'lingua_forge_ai_glossary';
    }

    /**
     * Normalize a raw $wpdb row to the typed shape callers expect.
     *
     * @param array<string, mixed> $row
     * @return array{id:int,source_term:string,target_term:string,source_lang:string,target_lang:string,notes:string,created_at:int,updated_at:int}
     */
    private static function cast_row(array $row): array {

        return [
            'id'          => (int)    $row['id'],
            'source_term' => (string) $row['source_term'],
            'target_term' => (string) $row['target_term'],
            'source_lang' => (string) $row['source_lang'],
            'target_lang' => (string) $row['target_lang'],
            'notes'       => (string) ($row['notes'] ?? ''),
            'created_at'  => (int)    $row['created_at'],
            'updated_at'  => (int)    $row['updated_at'],
        ];
    }
}
