<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- LfTestWpdb stub and LanguageUninstallerTest must coexist; same pattern as WcPolyfills.php and other unit test bootstrap files.
/**
 * Unit tests for LinguaForge\AI\Admin\Language\LanguageUninstaller.
 *
 * Tests the pure-logic methods that do not require a WordPress runtime:
 *
 *   • is_protected()          — source-language guard, WP-locale guard, unprotected pass.
 *   • collect_post_ids()      — delegates to $wpdb->get_col(); result casting.
 *   • collect_locale_files()  — filesystem glob + prefix filter (uses a real
 *                               temp directory; no WP needed).
 *
 * Integration-only methods (delete_posts, delete_locale_files, uninstall) are
 * not covered here — they require a live WordPress runtime and are tested via
 * the integration suite in wp-env.
 *
 * ── Stubs ────────────────────────────────────────────────────────────────────
 *
 * Router is injected via the inject_router() helper from WcUnitTestCase, which
 * builds a Context stub whose cached_source_language is set via Reflection —
 * no WP options or filters involved.
 *
 * get_locale() is polyfilled to return $GLOBALS['lf_test_locale'] so each test
 * can control the WP instance locale independently.
 *
 * $wpdb is stubbed as LfTestWpdb — a minimal object exposing get_col() and
 * prepare() backed by plain arrays configured per-test.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Admin\Language\LanguageUninstaller;
use LinguaForge\Tests\Unit\WooCommerce\WcUnitTestCase;

// ---------------------------------------------------------------------------
// Bootstrap constants
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}
if ( ! defined( 'WP_LANG_DIR' ) ) {
	define( 'WP_LANG_DIR', sys_get_temp_dir() . '/lf-unit-test-lang' );
}

// ---------------------------------------------------------------------------
// Polyfills shared with WcUnitTestCase bootstrap
// ---------------------------------------------------------------------------

require_once __DIR__ . '/WooCommerce/WcUnitTestCase.php';

// ---------------------------------------------------------------------------
// Additional polyfills needed only by LanguageUninstaller
// ---------------------------------------------------------------------------

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- polyfill functions and test class must coexist; same pattern as other unit test files.

// get_locale() is polyfilled in WcPolyfills.php (global namespace), loaded
// transitively via WcUnitTestCase. Setting $GLOBALS['lf_test_locale'] in each
// test controls the return value.

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $str ): string {
		return rtrim( $str, '/\\' ) . '/';
	}
}

// ---------------------------------------------------------------------------
// Require the classes under test
// ---------------------------------------------------------------------------

require_once LINGUAFORGE_AI_PATH . '/includes/Admin/Language/UninstallResult.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Admin/Language/LanguageUninstaller.php';

// ---------------------------------------------------------------------------
// $wpdb stub
// ---------------------------------------------------------------------------

/**
 * Minimal $wpdb stub for LanguageUninstaller unit tests.
 *
 * Tests configure:
 *   $stub->postmeta     — table name string (default 'wp_postmeta').
 *   $stub->prepared_sql — last SQL string produced by prepare().
 *   $stub->get_col_rows — array returned by the next get_col() call.
 */
class LfTestWpdb {

	public string $postmeta    = 'wp_postmeta';
	public string $prepared_sql = '';
	/** @var mixed[] */
	public array  $get_col_rows = [];

	/**
	 * Minimal prepare() — substitutes %i (identifier) and %s (string) placeholders.
	 * Mirrors real wpdb: %i → backtick-quoted, %s → single-quoted.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$formatted = $query;
		foreach ( $args as $arg ) {
			$formatted = preg_replace_callback(
				'/(%i|%s)/',
				static function ( array $m ) use ( $arg ): string {
					return $m[1] === '%i'
						? '`' . str_replace( '`', '``', (string) $arg ) . '`'
						: "'" . addslashes( (string) $arg ) . "'";
				},
				$formatted,
				1
			);
		}
		$this->prepared_sql = $formatted;
		return $formatted;
	}

	/**
	 * Returns the pre-configured $get_col_rows regardless of the SQL.
	 * The SQL is stored in $prepared_sql for assertion in tests.
	 *
	 * @return mixed[]
	 */
	public function get_col( string $query ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches wpdb signature; query already recorded via prepare().
		return $this->get_col_rows;
	}
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Admin\Language\LanguageUninstaller
 */
final class LanguageUninstallerTest extends WcUnitTestCase {

	private LfTestWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp(); // injects Router with source_language = 'en'
		$this->wpdb = new LfTestWpdb();
		$GLOBALS['lf_test_locale'] = 'en_US';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lf_test_locale'] );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function make_uninstaller(): LanguageUninstaller {
		return new LanguageUninstaller( $this->wpdb, \LinguaForge\Router\Router::get_instance() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test-only; $wpdb is the stub injected above.
	}

	/**
	 * Create a temporary directory tree for locale file tests and return its path.
	 * Caller is responsible for cleanup via teardown or rmdir.
	 *
	 * @param array<string,string[]> $tree  Map of subdir ('' for root) → filenames.
	 * @return string  Absolute path to the root temp dir.
	 */
	private function make_lang_dir( array $tree ): string {
		$base = sys_get_temp_dir() . '/lf-test-lang-' . uniqid();
		foreach ( array_keys( $tree ) as $subdir ) {
			$dir = $subdir === '' ? $base : $base . '/' . $subdir;
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test helper creates a temp fixture dir; WP_Filesystem is not available without a WordPress runtime.
			}
		}
		foreach ( $tree as $subdir => $files ) {
			$dir = $subdir === '' ? $base : $base . '/' . $subdir;
			foreach ( $files as $file ) {
				touch( $dir . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test helper creates empty fixture files; WP_Filesystem is not available without a WordPress runtime.
			}
		}
		return $base;
	}

	/**
	 * Recursively delete a temporary directory.
	 */
	private function rm_lang_dir( string $path ): void {
		if ( ! is_dir( $path ) ) return;
		// scandir() returns all entries including dotfiles; GLOB_BRACE is not
		// available on all PHP builds (e.g. Alpine), so avoid glob() here.
		foreach ( scandir( $path ) ?: [] as $entry ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.scandir_scandir -- scandir is used here as the PHP-native GLOB_BRACE alternative; WP has no equivalent helper.
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . DIRECTORY_SEPARATOR . $entry;
			is_dir( $full ) ? $this->rm_lang_dir( $full ) : unlink( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test teardown removes temp fixture files; wp_delete_file() is not available without a WordPress runtime.
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test teardown removes temp fixture dir; WP_Filesystem is not available without a WordPress runtime.
	}

	// =========================================================================
	// is_protected()
	// =========================================================================

	public function test_source_language_is_protected(): void {
		// WcUnitTestCase::inject_router() sets source_language to 'en'.
		$this->assertTrue( $this->make_uninstaller()->is_protected( 'en' ) );
	}

	public function test_wp_locale_language_is_protected(): void {
		$GLOBALS['lf_test_locale'] = 'de_DE';
		$this->assertTrue( $this->make_uninstaller()->is_protected( 'de' ) );
	}

	public function test_wp_locale_comparison_is_case_insensitive(): void {
		// get_locale() returning a locale with mixed case should still protect
		// the two-char code derived from it.
		$GLOBALS['lf_test_locale'] = 'FR_FR'; // unusual but defensively handled
		$this->assertTrue( $this->make_uninstaller()->is_protected( 'fr' ) );
	}

	public function test_unrelated_secondary_language_is_not_protected(): void {
		// source = 'en', WP locale = 'en_US' → 'de' is unprotected.
		$this->assertFalse( $this->make_uninstaller()->is_protected( 'de' ) );
	}

	public function test_secondary_lang_not_matching_wp_locale_is_not_protected(): void {
		$GLOBALS['lf_test_locale'] = 'ca_ES';
		// source = 'en', WP locale = 'ca' → 'de' is not protected.
		$this->assertFalse( $this->make_uninstaller()->is_protected( 'de' ) );
	}

	public function test_source_lang_matches_wp_locale_is_still_protected(): void {
		// Edge case: source language IS the WP locale language.
		// Both guards fire; is_protected must return true.
		$GLOBALS['lf_test_locale'] = 'en_US';
		$this->assertTrue( $this->make_uninstaller()->is_protected( 'en' ) );
	}

	// =========================================================================
	// collect_post_ids()
	// =========================================================================

	public function test_collect_post_ids_returns_empty_array_when_no_rows(): void {
		$this->wpdb->get_col_rows = [];
		$result = $this->make_uninstaller()->collect_post_ids( 'de' );
		$this->assertSame( [], $result );
	}

	public function test_collect_post_ids_returns_integer_array(): void {
		// $wpdb->get_col() returns strings; collect_post_ids() must cast to int.
		$this->wpdb->get_col_rows = [ '10', '42', '99' ];
		$result = $this->make_uninstaller()->collect_post_ids( 'de' );
		$this->assertSame( [ 10, 42, 99 ], $result );
	}

	public function test_collect_post_ids_queries_postmeta_table(): void {
		$this->wpdb->postmeta = 'wp_postmeta';
		$this->wpdb->get_col_rows = [ '7' ];
		$this->make_uninstaller()->collect_post_ids( 'de' );
		$this->assertStringContainsString( 'wp_postmeta', $this->wpdb->prepared_sql );
	}

	public function test_collect_post_ids_queries_by_lf_lang_key(): void {
		$this->wpdb->get_col_rows = [];
		$this->make_uninstaller()->collect_post_ids( 'de' );
		$this->assertStringContainsString( '_lf_lang', $this->wpdb->prepared_sql );
	}

	public function test_collect_post_ids_passes_lang_as_value(): void {
		$this->wpdb->get_col_rows = [];
		$this->make_uninstaller()->collect_post_ids( 'fr' );
		$this->assertStringContainsString( 'fr', $this->wpdb->prepared_sql );
	}

	// =========================================================================
	// collect_locale_files()
	// =========================================================================

	public function test_collect_locale_files_returns_empty_for_empty_dir(): void {
		$dir    = $this->make_lang_dir( [] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_locale_files_finds_matching_mo_in_root(): void {
		$dir = $this->make_lang_dir( [ '' => [ 'de_DE.mo', 'de_AT.mo', 'fr_FR.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'de_DE.mo', implode( ',', $result ) );
		$this->assertStringContainsString( 'de_AT.mo', implode( ',', $result ) );
	}

	public function test_collect_locale_files_finds_po_files(): void {
		$dir = $this->make_lang_dir( [ '' => [ 'de_DE.mo', 'de_DE.po' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 2, $result );
	}

	public function test_collect_locale_files_finds_files_in_plugins_subdir(): void {
		// AUDIT-2026-07-11 §4: plugins/themes files follow {textdomain}-{locale}.mo
		// (suffix), not the root dir's bare {locale}.mo — so the fixture must
		// actually look like a real plugin translation file, not a bare locale name.
		$dir = $this->make_lang_dir( [
			''        => [],
			'plugins' => [ 'some-plugin-de_DE.mo', 'some-plugin-fr_FR.mo' ],
		] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'plugins', $result[0] );
	}

	public function test_collect_locale_files_finds_files_in_themes_subdir(): void {
		$dir = $this->make_lang_dir( [
			''       => [],
			'themes' => [ 'some-theme-de_DE.mo' ],
		] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'themes', $result[0] );
	}

	public function test_collect_locale_files_plugins_subdir_uses_suffix_not_prefix(): void {
		// Regression guard for the pre-fix bug: a bare {locale}.mo (no
		// {textdomain}- prefix) in plugins/themes must NOT match — that shape
		// belongs to the root dir's naming convention, not this one.
		$dir = $this->make_lang_dir( [ 'plugins' => [ 'de_DE.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result, 'A bare {locale}.mo in plugins/ has no textdomain- prefix and must not match the suffix rule.' );
	}

	public function test_collect_locale_files_does_not_delete_unrelated_plugin_in_plugins_subdir(): void {
		// AUDIT-2026-07-11 §4: uninstalling 'ca' previously matched
		// cache-enabler-de_DE.mo (a prefix match on "ca" against "cache-enabler"),
		// silently deleting an unrelated plugin's translations of every locale.
		$dir = $this->make_lang_dir( [ 'plugins' => [ 'cache-enabler-de_DE.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'ca', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result, 'Uninstalling "ca" must not delete an unrelated "cache-enabler" plugin\'s translation files.' );
	}

	public function test_collect_locale_files_does_not_match_non_prefixed_files(): void {
		$dir = $this->make_lang_dir( [ '' => [ 'en_US.mo', 'fr_FR.mo', 'ca_ES.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_locale_files_aggregates_across_all_three_dirs(): void {
		$dir = $this->make_lang_dir( [
			''        => [ 'de_DE.mo' ],
			'plugins' => [ 'some-plugin-de_DE.mo' ],
			'themes'  => [ 'some-theme-de_DE.mo' ],
		] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 3, $result );
	}

	public function test_collect_locale_files_ignores_partial_prefix_match(): void {
		// AUDIT-2026-07-11 §4 fix: a plain prefix match previously included
		// 'den_DK.mo' (hypothetically Danish) when uninstalling 'de', since
		// 'den_DK.mo' starts with 'de'. locale_root_matches() now requires an
		// exact locale match — the character after 'de' must be '_' or the
		// extension, never another bare letter — so this no longer matches.
		$dir = $this->make_lang_dir( [ '' => [ 'de_DE.mo', 'den_DK.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'de_DE.mo', $result[0] );
	}

	// =========================================================================
	// collect_locale_files() — cross-language collisions (AUDIT-2026-07-11 §4)
	//
	// WordPress's own locale registry has sibling locale slugs where one is a
	// literal string prefix of another. A bare str_starts_with() prefix check
	// (the pre-fix implementation) matched all of these when uninstalling the
	// shorter code, deleting the wrong language's core pack.
	// =========================================================================

	public function test_collect_locale_files_ar_does_not_match_ary_or_arq(): void {
		// 'ary' = Moroccan Arabic, 'arq' = Algerian Arabic — both distinct from 'ar'.
		$dir = $this->make_lang_dir( [ '' => [ 'ar.mo', 'ary.mo', 'arq.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'ar', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'ar.mo', $result[0] );
	}

	public function test_collect_locale_files_ce_does_not_match_ceb(): void {
		// 'ceb' = Cebuano, distinct from 'ce' (Chechen).
		$dir = $this->make_lang_dir( [ '' => [ 'ceb.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'ce', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_locale_files_az_does_not_match_azb(): void {
		// 'azb' = South Azerbaijani, distinct from 'az' (Azerbaijani).
		$dir = $this->make_lang_dir( [ '' => [ 'azb.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'az', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_locale_files_ka_does_not_match_kab(): void {
		// 'kab' = Kabyle, distinct from 'ka' (Georgian).
		$dir = $this->make_lang_dir( [ '' => [ 'kab.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'ka', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_locale_files_root_matches_bare_three_letter_locale(): void {
		// Sanity check the fix doesn't overcorrect: a WP locale that IS a bare
		// 3-letter code (e.g. Yoruba's real slug "yor") must still match itself.
		$dir = $this->make_lang_dir( [ '' => [ 'yor.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'yor', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
	}

	public function test_collect_locale_files_root_matches_multi_segment_locale(): void {
		// A locale with more than one underscore-separated variant segment
		// (e.g. WordPress's "pt_PT_ao90") must still match its bare lang code.
		$dir = $this->make_lang_dir( [ '' => [ 'pt_PT_ao90.mo' ] ] );
		$result = $this->make_uninstaller()->collect_locale_files( 'pt', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
	}

	// =========================================================================
	// collect_override_files()
	//
	// Covers the i18n-overrides directory (LanguageOverridesPanel's uploads-
	// based storage, and the "Loco Translate — Copy to Safe Storage" feature).
	// Files here follow {textdomain}-{locale}.mo — the locale is a SUFFIX, the
	// opposite of collect_locale_files()'s prefix convention — so this is a
	// genuinely different matching rule, not just the same test reused.
	// =========================================================================

	public function test_collect_override_files_returns_empty_for_empty_dir(): void {
		$dir    = $this->make_lang_dir( [] );
		$result = $this->make_uninstaller()->collect_override_files( 'yo', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_override_files_finds_suffix_matching_mo(): void {
		// Reproduces the confirmed live bug: a Loco Translate file copied to
		// safe storage for a plugin's Yoruba strings, named with the locale as
		// a suffix. collect_locale_files() (prefix match) would never find
		// this — it lives in an entirely different directory.
		$dir = $this->make_lang_dir( [ '' => [ 'some-plugin-yo.mo', 'some-plugin-de.mo' ] ] );
		$result = $this->make_uninstaller()->collect_override_files( 'yo', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'some-plugin-yo.mo', $result[0] );
	}

	public function test_collect_override_files_finds_po_files(): void {
		$dir = $this->make_lang_dir( [ '' => [ 'lingua-forge-yo.mo', 'lingua-forge-yo.po' ] ] );
		$result = $this->make_uninstaller()->collect_override_files( 'yo', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 2, $result );
	}

	public function test_collect_override_files_matches_region_variant_suffix(): void {
		// {textdomain}-{lang}_{COUNTRY}.mo (e.g. de_DE) must still match the
		// bare two-char language code, same as collect_locale_files() does.
		$dir = $this->make_lang_dir( [ '' => [ 'vikbooking-de_DE.mo' ] ] );
		$result = $this->make_uninstaller()->collect_override_files( 'de', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertCount( 1, $result );
	}

	public function test_collect_override_files_does_not_match_other_languages(): void {
		$dir = $this->make_lang_dir( [ '' => [ 'some-plugin-de.mo', 'some-plugin-fr.mo' ] ] );
		$result = $this->make_uninstaller()->collect_override_files( 'yo', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}

	public function test_collect_override_files_ignores_files_with_no_locale_suffix(): void {
		// A file with no recognisable "-{locale}.mo" suffix (e.g. the
		// textdomain itself, or an unrelated file) must not match anything.
		$dir = $this->make_lang_dir( [ '' => [ 'readme.mo', 'plugin.mo' ] ] );
		$result = $this->make_uninstaller()->collect_override_files( 'yo', $dir );
		$this->rm_lang_dir( $dir );
		$this->assertSame( [], $result );
	}
}
