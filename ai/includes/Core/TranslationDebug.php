<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Debug-file writer and configuration resolver for the AI module.
 *
 * Owns:
 *   • The debug directory location (default `wp-content/lingua-forge-debug`,
 *     filterable via `linguaforge_debug_dir`).
 *   • The on/off resolution order — runtime override (WP-CLI --debug) →
 *     `LINGUAFORGE_AI_DEBUG` constant → `linguaforge_ai_debug_enabled`
 *     option → false.
 *   • The `.htaccess` + `index.html` placeholders dropped into the
 *     debug directory on first write.
 *   • The Maintenance UI's "how many files? clear them" surface.
 *
 * Public-contract identifiers preserved across the v1.3.7 extraction:
 *   • Filter `linguaforge_debug_dir`
 *   • Constant `LINGUAFORGE_AI_DEBUG`
 *   • Option `linguaforge_ai_debug_enabled`
 *
 * Extracted from LinguaForge\AI\Features\Translation in v1.3.7 (audit
 * §2.1 / §5 item 6). The Maintenance UI and AbstractTranslateCommand
 * call into this class directly; Translation only uses
 * {@see debug_enabled()} and {@see debug_write()} from its
 * translation-flow hot path.
 */
class TranslationDebug {

    /**
     * Runtime debug override — set by WP-CLI's --debug flag so debug files
     * are written for that run without touching the DB option or wp-config.php.
     *
     * @var bool
     */
    private static bool $debug_override = false;

    /**
     * Resolve the absolute filesystem path of the debug directory.
     *
     * Default: wp-content/lingua-forge-debug
     *
     * Lives outside `wp-content/uploads/` because uploads/ is universally
     * web-readable on every WP host whereas wp-content/ is sometimes
     * tightened (and a future hardening step can place it elsewhere via
     * the filter). Combined with the random suffix in debug_write()
     * filenames, this makes URLs unguessable even when the directory
     * itself is listable.
     *
     * Filterable via `linguaforge_debug_dir` so security-tight sites can
     * redirect debug output to a non-public location (e.g. outside the
     * document root entirely). A filter return of empty / non-string
     * falls back to the default so debug-writes (and the Maintenance UI)
     * never silently disappear.
     *
     * Returns the path WITHOUT a trailing slash — callers concatenate
     * `/{filename}` themselves.
     */
    public static function debug_dir(): string {

        $default_dir = defined('WP_CONTENT_DIR')
            ? WP_CONTENT_DIR . '/lingua-forge-debug'
            : ABSPATH . 'wp-content/lingua-forge-debug';

        $debug_dir = (string) apply_filters('linguaforge_debug_dir', $default_dir);

        if ($debug_dir === '') {
            $debug_dir = $default_dir;
        }

        return untrailingslashit($debug_dir);
    }

    /**
     * Force debug on (or off) for the remainder of the current process.
     *
     * Called by WP-CLI commands when --debug is passed. Has no effect on
     * web requests because the flag is not persisted anywhere.
     */
    public static function force_debug( bool $value ): void {
        self::$debug_override = $value;
    }

    /**
     * Whether debug logging is currently enabled.
     *
     * Resolution order (constant wins, same pattern WP uses for WP_DEBUG):
     *   1. Runtime override set via force_debug() — WP-CLI --debug flag.
     *   2. LINGUAFORGE_AI_DEBUG constant defined in wp-config.php — value is
     *      returned verbatim, regardless of any option setting.
     *   3. linguaforge_ai_debug_enabled option set via Settings → Maintenance.
     *   4. Off by default.
     */
    public static function debug_enabled(): bool {

        if ( self::$debug_override ) {
            return true;
        }

        if (defined('LINGUAFORGE_AI_DEBUG')) {
            return (bool) LINGUAFORGE_AI_DEBUG;
        }

        return (bool) get_option('linguaforge_ai_debug_enabled', false);
    }

    /**
     * Whether the wp-config.php constant currently overrides the UI toggle.
     *
     * Used by the Settings → Maintenance → Debug Files panel to disable the
     * checkbox (and explain why) when the constant is in force.
     */
    public static function debug_constant_defined(): bool {

        return defined('LINGUAFORGE_AI_DEBUG');
    }

    /**
     * The literal value the LINGUAFORGE_AI_DEBUG constant currently holds.
     *
     * Returns null when the constant isn't defined. Used by the Maintenance
     * UI to render an accurate "forced on / forced off" message.
     */
    public static function debug_constant_value(): ?bool {

        return defined('LINGUAFORGE_AI_DEBUG') ? (bool) LINGUAFORGE_AI_DEBUG : null;
    }

    /**
     * Count the *.txt files currently in the debug directory.
     *
     * Returns 0 when the directory doesn't exist yet (e.g. nobody has run an
     * AI feature since debug was enabled). Glob is wrapped in a defensive
     * `is_dir()` check so we don't fire a PHP warning on missing paths.
     */
    public static function debug_file_count(): int {

        $dir = self::debug_dir();

        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.txt');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Delete every *.txt file in the debug directory.
     *
     * Returns the number of files actually removed. Leaves the directory
     * itself (and its .htaccess block) in place so subsequent debug writes
     * still land cleanly.
     */
    public static function clear_debug_files(): int {

        $dir = self::debug_dir();

        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.txt');
        if (!is_array($files) || empty($files)) {
            return 0;
        }

        // Defensive: only delete *.txt entries whose resolved path is still
        // inside the debug directory. Guards against a hostile symlink that
        // glob might surface (paranoia, but the cost is one realpath() per file).
        $real_dir = realpath($dir);
        if ($real_dir === false) {
            return 0;
        }

        $removed = 0;
        foreach ($files as $path) {
            $real = realpath($path);
            if ($real === false) continue;
            if (strpos($real, $real_dir . DIRECTORY_SEPARATOR) !== 0) continue;

            // wp_delete_file is the WP wrapper around unlink() with proper
            // filter coverage (other plugins can hook in to e.g. archive the
            // file before deletion).
            wp_delete_file($path);
            $removed++;
        }

        return $removed;
    }

    /**
     * Write a debug file to the configured debug directory.
     *
     * Enabled only when LINGUAFORGE_AI_DEBUG is defined in wp-config.php
     * or the linguaforge_ai_debug_enabled option is on.
     *
     * Files are named: {post_id}-{lang}-{timestamp}-{random}-{suffix}.txt
     *
     * The 8-char random token sits BEFORE the suffix so the existing glob
     * pattern in AbstractTranslateCommand::dump_debug_files()
     * ({post_id}-{lang}-*-{suffix}.txt) still matches — the wildcard
     * covers both the timestamp and the random token.
     *
     * The random token guarantees URLs are unguessable even if the
     * directory ends up listable, which complements the
     * outside-of-/uploads/ default in debug_dir().
     *
     * Visibility note: was `private static` while this lived inside
     * Translation; promoted to `public static` on extraction because the
     * Translation feature now calls it from outside this class.
     *
     * @param  int    $post_id   Post being translated.
     * @param  string $lang      Target language code.
     * @param  string $suffix    'source', 'response', 'tm-source', 'tm-response'.
     * @param  string $content   Content to write.
     */
    public static function debug_write(int $post_id, string $lang, string $suffix, string $content): void {

        $debug_dir = self::debug_dir();

        if (!is_dir($debug_dir)) {
            wp_mkdir_p($debug_dir);

            // Drop an .htaccess "Deny from all" for Apache, and an
            // index.html placeholder that blocks directory listing on
            // every server type (nginx / IIS ignore .htaccess). Both
            // run only on first directory create — file_exists() short
            // circuits any subsequent calls.
            $htaccess_path = $debug_dir . '/.htaccess';
            if (!file_exists($htaccess_path)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem would require request_filesystem_credentials() and on FTP-based hosts would block silently — that defeats the diagnostic write here, which only runs once on first directory create inside a plugin-owned path. The `.htaccess` is a one-line "Deny from all" guard, not user content.
                file_put_contents($htaccess_path, "Deny from all\n");
            }
            $index_path = $debug_dir . '/index.html';
            if (!file_exists($index_path)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same WP_Filesystem rationale as the .htaccess write above. Static placeholder content, fires once per debug-dir lifetime.
                file_put_contents($index_path, "<!-- Silence is golden. -->\n");
            }
        }

        $timestamp = gmdate('Ymd-His');
        $random    = wp_generate_password(8, false);
        $filename  = "{$post_id}-{$lang}-{$timestamp}-{$random}-{$suffix}.txt";

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Debug-mode-only write into a plugin-owned directory; the whole code path is gated by debug_enabled() in the caller. Using WP_Filesystem here would require interactive filesystem credentials in some host configurations, which defeats the silent-diagnostics purpose.
        file_put_contents(
            $debug_dir . '/' . $filename,
            $content
        );
    }
}
