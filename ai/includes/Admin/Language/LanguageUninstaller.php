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
 *   • collect_override_files() — i18n-overrides dir glob + suffix filter; pass a temp dir.
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

use LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery;
use LinguaForge\Router\Context;
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

		// WordPress instance locale (e.g. 'de' from 'de_DE', or the full 'yor'
		// from a bare 3-letter locale — see Context::lang_from_locale()).
		$wp_locale_lang = Context::lang_from_locale( (string) \get_locale() );
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
	 * Return all locale file paths (*.mo and *.po) belonging to $lang.
	 *
	 * Searches three directories, each with its own correct matching rule
	 * (AUDIT-2026-07-11 §4 — both were previously a single naive
	 * `str_starts_with( basename, $lang )` prefix check, which is wrong-shaped
	 * for two independent reasons):
	 *   • $lang_dir/          — WP core language files (drives
	 *     get_available_languages()). Root files are named exactly
	 *     `{locale}.mo`/`.po` (e.g. `de_DE.mo`, `yor.mo`) — an EXACT-locale
	 *     match via locale_root_matches() is required here, not a bare prefix:
	 *     a plain prefix check on 'ar' also matches `ary.mo`/`arq.mo`
	 *     (Moroccan/Algerian Arabic), 'ce' matches `ceb.mo` (Cebuano), 'az'
	 *     matches `azb.mo`, 'ka' matches `kab.mo` — all of WordPress's own
	 *     locale registry, where one language's slug happens to be a prefix
	 *     of an unrelated sibling's. Uninstalling the wrong one deleted the
	 *     wrong language's core pack.
	 *   • $lang_dir/plugins/  — plugin translation files, named
	 *     `{textdomain}-{locale}.mo` — the locale is a SUFFIX, not a prefix,
	 *     so the old prefix check (a) never matched the target language's own
	 *     packs there (`woocommerce-de_DE.mo` doesn't start with `de`) and
	 *     (b) could match a wholly unrelated textdomain sharing the same
	 *     leading letters (uninstalling 'ca' matched `cache-enabler-de_DE.mo`
	 *     — every locale of an unrelated plugin — since "ca" is a prefix of
	 *     "cache"). Now uses locale_suffix_matches(), the same matcher
	 *     collect_override_files() already used correctly for this exact
	 *     naming convention.
	 *   • $lang_dir/themes/   — same convention and same fix as plugins/.
	 *
	 * Passing a custom $lang_dir is the unit-test seam — tests use a temp
	 * directory with known fixtures instead of WP_LANG_DIR.
	 *
	 * @param  string $lang      Language code (e.g. 'de', or a bare 3-letter
	 *                            code like 'yor' — see Context::lang_from_locale()).
	 * @param  string $lang_dir  Root language directory. Defaults to WP_LANG_DIR.
	 * @return string[]  Absolute file paths.
	 */
	public function collect_locale_files( string $lang, string $lang_dir = WP_LANG_DIR ): array {
		$lang_dir = \trailingslashit( $lang_dir );

		$files = [];

		foreach ( [ '*.mo', '*.po' ] as $ext ) {
			foreach ( glob( $lang_dir . $ext ) ?: [] as $path ) {
				if ( $this->locale_root_matches( basename( $path ), $lang ) ) {
					$files[] = $path;
				}
			}
		}

		foreach ( [ $lang_dir . 'plugins/', $lang_dir . 'themes/' ] as $dir ) {
			foreach ( [ '*.mo', '*.po' ] as $ext ) {
				foreach ( glob( $dir . $ext ) ?: [] as $path ) {
					if ( $this->locale_suffix_matches( basename( $path ), $lang ) ) {
						$files[] = $path;
					}
				}
			}
		}

		return $files;
	}

	/**
	 * True when $basename is an exact WP-core-style locale file for $lang —
	 * `{lang}.mo`/`.po` or `{lang}_{VARIANT}[_...].mo`/`.po` — not merely
	 * prefixed by it. Used for the root $lang_dir scan; see collect_locale_files()'s
	 * docblock for the collision class this closes (e.g. 'ar' no longer
	 * matches 'ary.mo').
	 */
	private function locale_root_matches( string $basename, string $lang ): bool {
		return (bool) preg_match(
			'/^' . preg_quote( $lang, '/' ) . '(?:_[a-z0-9]+)*\.(?:mo|po)$/i',
			$basename
		);
	}

	/**
	 * True when $basename's trailing `-{locale}.mo`/`.po` suffix (WordPress's
	 * `{textdomain}-{locale}.mo` convention for plugin/theme translations and
	 * this plugin's own i18n-overrides storage) resolves to $lang.
	 *
	 * Shared by collect_locale_files()'s plugins/themes scan and
	 * collect_override_files() — both directories use this exact naming
	 * convention, so both need the same suffix-anchored matcher rather than
	 * the root directory's prefix-anchored one.
	 *
	 * @param  string $basename  File basename, e.g. 'woocommerce-de_DE.mo'.
	 * @param  string $lang      Language code to match, e.g. 'de'.
	 */
	private function locale_suffix_matches( string $basename, string $lang ): bool {
		return (bool) preg_match( '/-([a-z]{2,3})(?:_[a-z]{2})?\.(?:mo|po)$/i', $basename, $m )
			&& strtolower( $m[1] ) === strtolower( $lang );
	}

	/**
	 * Return override .mo/.po files (LanguageOverridesPanel's uploads-based
	 * i18n-overrides directory) belonging to the given language.
	 *
	 * Files here follow WordPress's {textdomain}-{locale}.mo convention — the
	 * locale is a SUFFIX (e.g. "vikbooking-yo.mo", "lingua-forge-de_DE.mo") —
	 * the same convention (and the same locale_suffix_matches() matcher) as
	 * collect_locale_files()'s plugins/themes scan. This directory is also
	 * entirely separate from WP_LANG_DIR: it is
	 * Lingua Forge's own uploads-based storage, explicitly designed (see
	 * LanguageOverridesPanel::render(), the "Loco Translate — Copy to Safe
	 * Storage" section) to survive plugin/theme reinstalls and WP core
	 * updates. A language that became active purely via a file dropped
	 * here — e.g. a Loco Translate custom translation an admin copied to
	 * safe storage — has no presence in WP_LANG_DIR at all, so without this
	 * method uninstall() could report success (0 files found, 0 skipped)
	 * while Context::discover_plugin_locales() kept the language in the
	 * router's active list forever. This scenario was suspected during a
	 * live Yoruba uninstall that reported 0 posts / 0 files removed while
	 * the language stayed active — that specific case actually turned out
	 * to be caused by a different bug (WordPress's real locale slug for
	 * Yoruba is the 3-letter "yor", not "yo" — see
	 * Context::lang_from_locale()), but an override-only language is a
	 * real, independently reachable gap this method still needs to cover.
	 *
	 * Matches a locale suffix of two OR three letters — the three-letter
	 * case covers a {textdomain}-yor.mo style file for one of WordPress's
	 * bare 3-character-only locales (see Context::lang_from_locale() for
	 * the full list/explanation).
	 *
	 * @param  string $lang           Language code (2 or 3 characters).
	 * @param  string $overrides_dir  Root overrides directory. Defaults to
	 *                                 the real Context::i18n_overrides_dir()
	 *                                 path; pass a temp dir in tests.
	 * @return string[]  Absolute file paths.
	 */
	public function collect_override_files( string $lang, string $overrides_dir = '' ): array {
		if ( $overrides_dir === '' ) {
			$overrides_dir = $this->router->context->i18n_overrides_dir();
		}
		$overrides_dir = \trailingslashit( $overrides_dir );

		$files = [];
		foreach ( [ '*.mo', '*.po' ] as $ext ) {
			foreach ( glob( $overrides_dir . $ext ) ?: [] as $path ) {
				if ( $this->locale_suffix_matches( basename( $path ), $lang ) ) {
					$files[] = $path;
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
	 * Returns an empty UninstallResult (posts_deleted = 0, empty file lists,
	 * patterns_deleted = 0) if $lang is protected — callers should call
	 * is_protected() first for a clean early-return UX, but this guard prevents
	 * accidental damage if skipped.
	 *
	 * Order of operations:
	 *   1. Guard check.
	 *   2. Collect and delete all posts with _lf_lang = $lang — this covers
	 *      every post-based entity (posts, pages, CPTs, templates, template
	 *      parts, navigation menus; see collect_post_ids() docblock).
	 *   3. If file mods are allowed: collect and delete locale files.
	 *      If not: collect the paths so the caller can surface them in a notice.
	 *   4. Remove the language's CPT Block Pattern translations. Patterns are
	 *      stored in the `linguaforge_pattern_translations` option rather than
	 *      as posts (see PatternDiscovery::save_translation()), so step 2's
	 *      postmeta query never touches them — without this step they would
	 *      survive uninstall indefinitely as orphaned option data.
	 *   5. flush_rewrite_rules() — removes language-prefix rewrite rules that
	 *      referenced the now-deleted locale pack.
	 *
	 * @param  string $lang  Two-character language code.
	 */
	public function uninstall( string $lang ): UninstallResult {

		$mods_allowed = \wp_is_file_mod_allowed( 'download_language_pack' );

		// Guard — fail safe, no deletions.
		if ( $this->is_protected( $lang ) ) {
			return new UninstallResult( 0, [], [], $mods_allowed, 0 );
		}

		// ── 1. Delete content ─────────────────────────────────────────────────
		$post_ids      = $this->collect_post_ids( $lang );
		$posts_deleted = $this->delete_posts( $post_ids );

		// ── 2. Delete locale files ────────────────────────────────────────────
		// Both WP_LANG_DIR (core/plugin/theme packs) and the i18n-overrides
		// directory (Loco Translate copies, manual uploads) must be checked —
		// a language can be active in the router via either source alone.
		$locale_files  = array_merge(
			$this->collect_locale_files( $lang ),
			$this->collect_override_files( $lang )
		);
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

		// ── 3. Remove CPT Block Pattern translations ────────────────────────────
		$patterns_deleted = PatternDiscovery::delete_language( $lang );

		// ── 4. Flush rewrite rules ─────────────────────────────────────────────
		\flush_rewrite_rules();

		return new UninstallResult( $posts_deleted, $files_deleted, $files_skipped, $mods_allowed, $patterns_deleted );
	}
}
