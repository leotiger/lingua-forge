<?php
/**
 * Class LinguaForge\AI\Admin\Language\LanguageUninstaller
 *
 * Removes all Lingua Forge content for a given language code and deletes the
 * corresponding WordPress locale pack files so the language no longer appears
 * in the router's active language list.
 *
 * Deletion is intentionally synchronous. On large catalogs this may approach
 * the PHP time limit on shared hosting; §10.2 (Action Scheduler async dispatch)
 * is the planned future wrapper and will call this class unchanged.
 *
 * Two languages are permanently protected:
 *   • The primary content language  (linguaforge_primary_language option).
 *   • The WordPress instance locale (get_locale(), first two characters).
 *
 * is_protected() must be checked before calling uninstall(). The method itself
 * also enforces the guard and returns an empty result if called on a protected
 * language.
 *
 * ── Testability ──────────────────────────────────────────────────────────────
 *
 * Constructor-injected dependencies:
 *   • \wpdb        $wpdb    — for collect_post_ids(); mock in unit tests.
 *   • Router       $router  — for source_language() guard; stub via inject_router().
 *
 * Unit-testable methods (no WP runtime needed):
 *   • collect_post_ids()   — pure $wpdb->get_col() call.
 *   • collect_locale_files() — filesystem glob + prefix filter; pass a temp dir.
 *   • is_protected()       — Router + get_locale() polyfill.
 *
 * Integration-only methods (require wp-env):
 *   • delete_posts()       — calls wp_delete_post().
 *   • delete_locale_files() — calls wp_delete_file() + file_exists().
 *   • uninstall()          — full orchestration, calls flush_rewrite_rules().
 *
 * @package LinguaForge\AI\Admin\Language
 * @since   2.1.8
 */

namespace LinguaForge\AI\Admin\Language;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class LanguageUninstaller {

	/**
	 * @param \wpdb  $wpdb    WordPress database object. Typed as object so unit
	 *                        tests can pass a stub without a full WP bootstrap.
	 *                        In production $GLOBALS['wpdb'] is always \wpdb.
	 * @param Router $router
	 */
	public function __construct(
		private object $wpdb, // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__wpdb -- object type used intentionally for unit-test stub compatibility; production always receives \wpdb via $GLOBALS['wpdb'].
		private Router $router,
	) {}

	// =========================================================================
	// Guard
	// =========================================================================

	/**
	 * Return true when the given 2-char language code must not be removed.
	 *
	 * Protected languages:
	 *   1. The primary content language — removing it would orphan all source posts.
	 *   2. The WordPress instance locale — removing it breaks WP admin strings and
	 *      causes get_locale() to re-add it to the router on the next request.
	 *
	 * @param string $lang  Two-character language code (e.g. 'de').
	 */
	public function is_protected( string $lang ): bool {

		// Primary content language.
		if ( $lang === $this->router->source_language() ) {
			return true;
		}

		// WordPress instance locale (e.g. 'de' from 'de_DE').
		$wp_locale_lang = strtolower( substr( (string) \get_locale(), 0, 2 ) );
		if ( $lang === $wp_locale_lang ) {
			return true;
		}

		return false;
	}

	// =========================================================================
	// Collection
	// =========================================================================

	/**
	 * Return all post IDs that carry _lf_lang = $lang.
	 *
	 * Covers every managed post type in one query: posts, pages, CPTs,
	 * wp_template, wp_template_part, wp_block, wp_navigation, product,
	 * product_variation — any post type that the Sync class has written
	 * _lf_lang meta to.
	 *
	 * @param  string $lang  Two-character language code.
	 * @return int[]
	 */
	public function collect_post_ids( string $lang ): array {
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-off lifecycle query; no persistent cache needed. Table name passed via %i placeholder which escapes it correctly.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s',
				$wpdb->postmeta,
				'_lf_lang',
				$lang
			)
		);

		return array_map( 'intval', $ids ?? [] );
	}

	/**
	 * Return all locale file paths (*.mo and *.po) whose basename starts with
	 * the two-character language code.
	 *
	 * Searches three directories:
	 *   • $lang_dir/              — WP core language files (drives get_available_languages())
	 *   • $lang_dir/plugins/      — plugin translation files
	 *   • $lang_dir/themes/       — theme translation files
	 *
	 * Passing a custom $lang_dir is the unit-test seam — tests use a temp
	 * directory with known fixtures instead of WP_LANG_DIR.
	 *
	 * @param  string $lang      Two-character language code (e.g. 'de').
	 * @param  string $lang_dir  Root language directory. Defaults to WP_LANG_DIR.
	 * @return string[]  Absolute file paths.
	 */
	public function collect_locale_files( string $lang, string $lang_dir = WP_LANG_DIR ): array {
		$lang_dir = \trailingslashit( $lang_dir );
		$dirs     = [
			$lang_dir,
			$lang_dir . 'plugins/',
			$lang_dir . 'themes/',
		];

		$files = [];
		foreach ( $dirs as $dir ) {
			foreach ( [ '*.mo', '*.po' ] as $ext ) {
				foreach ( glob( $dir . $ext ) ?: [] as $path ) {
					if ( str_starts_with( basename( $path ), $lang ) ) {
						$files[] = $path;
					}
				}
			}
		}

		return $files;
	}

	// =========================================================================
	// Deletion
	// =========================================================================

	/**
	 * Force-delete an array of post IDs, bypassing the Trash.
	 *
	 * wp_delete_post() with $force_delete = true removes the post and all its
	 * associated postmeta (_lf_lang, _lf_trid, etc.) in one operation. Source
	 * posts that referenced deleted translations via _lf_trid retain their own
	 * meta intact — TridGroup queries dynamically so no orphan cleanup is needed.
	 *
	 * @param  int[] $post_ids
	 * @return int  Number of posts successfully deleted.
	 */
	public function delete_posts( array $post_ids ): int {
		$count = 0;
		foreach ( $post_ids as $id ) {
			if ( \wp_delete_post( $id, true ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Delete locale files from disk.
	 *
	 * Uses wp_delete_file() (wraps unlink with error suppression) then checks
	 * file_exists() to determine success — wp_delete_file() does not return a
	 * meaningful value across all WP versions.
	 *
	 * @param  string[] $paths  Absolute paths returned by collect_locale_files().
	 * @return array{deleted: string[], failed: string[]}
	 */
	public function delete_locale_files( array $paths ): array {
		$deleted = [];
		$failed  = [];

		foreach ( $paths as $path ) {
			\wp_delete_file( $path );
			if ( ! file_exists( $path ) ) {
				$deleted[] = $path;
			} else {
				$failed[] = $path;
			}
		}

		return [
			'deleted' => $deleted,
			'failed'  => $failed,
		];
	}

	// =========================================================================
	// Orchestrator
	// =========================================================================

	/**
	 * Uninstall a language: delete all content, remove locale files, flush rules.
	 *
	 * Returns an empty UninstallResult (posts_deleted = 0, empty file lists) if
	 * $lang is protected — callers should call is_protected() first for a clean
	 * early-return UX, but this guard prevents accidental damage if skipped.
	 *
	 * Order of operations:
	 *   1. Guard check.
	 *   2. Collect and delete all posts with _lf_lang = $lang.
	 *   3. If file mods are allowed: collect and delete locale files.
	 *      If not: collect the paths so the caller can surface them in a notice.
	 *   4. flush_rewrite_rules() — removes language-prefix rewrite rules that
	 *      referenced the now-deleted locale pack.
	 *
	 * @param  string $lang  Two-character language code.
	 */
	public function uninstall( string $lang ): UninstallResult {

		$mods_allowed = \wp_is_file_mod_allowed( 'download_language_pack' );

		// Guard — fail safe, no deletions.
		if ( $this->is_protected( $lang ) ) {
			return new UninstallResult( 0, [], [], $mods_allowed );
		}

		// ── 1. Delete content ─────────────────────────────────────────────────
		$post_ids      = $this->collect_post_ids( $lang );
		$posts_deleted = $this->delete_posts( $post_ids );

		// ── 2. Delete locale files ────────────────────────────────────────────
		$locale_files  = $this->collect_locale_files( $lang );
		$files_deleted = [];
		$files_skipped = [];

		if ( $mods_allowed ) {
			$file_result   = $this->delete_locale_files( $locale_files );
			$files_deleted = $file_result['deleted'];
			$files_skipped = $file_result['failed'];
		} else {
			// Surface the paths so the caller can show a manual-deletion notice.
			$files_skipped = $locale_files;
		}

		// ── 3. Flush rewrite rules ─────────────────────────────────────────────
		\flush_rewrite_rules();

		return new UninstallResult( $posts_deleted, $files_deleted, $files_skipped, $mods_allowed );
	}
}
