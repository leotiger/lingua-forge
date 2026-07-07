<?php
/**
 * Integration tests for LinguaForge\AI\Admin\Language\LanguageUninstaller::uninstall().
 *
 * uninstall() is the orchestration method that:
 *   1. Guards protected languages (source lang, WP instance locale).
 *   2. Collects and force-deletes all posts with _lf_lang = $lang.
 *   3. Collects and deletes locale pack files (when file mods are allowed).
 *   4. Flushes rewrite rules.
 *
 * Unit tests (LanguageUninstallerTest) already cover the pure-logic helpers
 * (is_protected, collect_post_ids, collect_locale_files). These integration
 * tests exercise the full orchestration cycle that requires wp_delete_post(),
 * wp_is_file_mod_allowed(), and flush_rewrite_rules() inside wp-env.
 *
 * Coverage — §6.0.1 High (LanguageUninstaller.php, 48%):
 *   1. Uninstall a secondary language → all its posts deleted, result count correct.
 *   2. Uninstall the source language → protected guard fires, no posts deleted.
 *   3. Uninstall with file mods disallowed → posts deleted, files surfaced in skipped list.
 *   4. Uninstall also purges the target language's CPT Block Pattern translations
 *      from the `linguaforge_pattern_translations` option, leaving other languages'
 *      translations for the same pattern untouched (added alongside the 2.5.1
 *      Danger Zone redirect fix, once patterns were found to be invisible to
 *      collect_post_ids()'s postmeta query — they live in an option, not a post).
 *   5. Uninstall also removes matching files from the uploads-based
 *      i18n-overrides directory (Loco Translate "Copy to Safe Storage",
 *      manual .mo uploads) — a second confirmed live gap in the 2.5.1 cycle,
 *      found after Yoruba stayed active post-uninstall because its only
 *      footprint was an override file, which collect_locale_files() (scoped
 *      to WP_LANG_DIR only) never looked at.
 *
 * Design notes:
 *   • $GLOBALS['wpdb'] is injected directly — this is the real wpdb in wp-env.
 *   • WP_UnitTestCase wraps every test in a DB transaction rolled back on
 *     tearDown — no manual post/meta or option cleanup needed.
 *   • Context caches are reset in setUp() so option changes are re-read fresh.
 *   • WP_LANG_DIR file deletion is not tested here because wp-env's WP_LANG_DIR
 *     contains no matching locale files for test language codes ('de'). The
 *     DISALLOW_FILE_MODS path (test 3) verifies the mods_allowed/files_skipped
 *     contract without touching the real filesystem. i18n-overrides file
 *     deletion (test 5) IS exercised against the real filesystem, since that
 *     directory is uploads-based and always writable in wp-env.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery;
use LinguaForge\AI\Admin\Language\LanguageUninstaller;
use LinguaForge\AI\Admin\Language\UninstallResult;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class LanguageUninstallerIntegrationTest extends WP_UnitTestCase {

	/** Source language — must match the linguaforge_primary_language option. */
	private const SOURCE_LANG = 'en';

	/** Secondary language used as the uninstall target. */
	private const TARGET_LANG = 'de';

	protected function setUp(): void {
		parent::setUp();

		// Fix source language so Router::source_language() is deterministic.
		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );

		// Reset per-request Context caches — the Router singleton persists
		// across tests; without this, stale option values bleed through.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'file_mod_allowed' );
		parent::tearDown();
	}

	// =========================================================================
	// Helper
	// =========================================================================

	private function make_uninstaller(): LanguageUninstaller {
		return new LanguageUninstaller(
			$GLOBALS['wpdb'], // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: injecting the real wpdb for integration tests.
			Router::get_instance()
		);
	}

	// =========================================================================
	// 1. Uninstall a secondary language → posts deleted
	// =========================================================================

	/**
	 * uninstall() must force-delete every post carrying _lf_lang = $lang and
	 * report the correct count in the UninstallResult. Posts in other languages
	 * must survive.
	 */
	public function test_uninstall_deletes_all_posts_for_target_language(): void {
		// Create two TARGET_LANG posts that should be deleted.
		$de1 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$de2 = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $de1, '_lf_lang', self::TARGET_LANG );
		update_post_meta( $de2, '_lf_lang', self::TARGET_LANG );

		// Create a SOURCE_LANG post that must survive.
		$en = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $en, '_lf_lang', self::SOURCE_LANG );

		$result = $this->make_uninstaller()->uninstall( self::TARGET_LANG );

		$this->assertInstanceOf( UninstallResult::class, $result );
		$this->assertSame( 2, $result->posts_deleted, 'Two de-language posts must be reported as deleted.' );

		// Both de posts must be gone (wp_delete_post with force_delete = true).
		$this->assertNull( get_post( $de1 ), 'First de post must be force-deleted.' );
		$this->assertNull( get_post( $de2 ), 'Second de post must be force-deleted.' );

		// Source-language post must survive.
		$this->assertNotNull( get_post( $en ), 'Source-language post must not be touched.' );
	}

	// =========================================================================
	// 2. Uninstall the source language → protected, no posts deleted
	// =========================================================================

	/**
	 * The source language is permanently protected. uninstall() must detect this
	 * via is_protected(), skip all deletions, and return an empty UninstallResult.
	 */
	public function test_uninstall_protected_source_language_is_noop(): void {
		// Create a source-language post that must survive.
		$en = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $en, '_lf_lang', self::SOURCE_LANG );

		$result = $this->make_uninstaller()->uninstall( self::SOURCE_LANG );

		$this->assertSame( 0, $result->posts_deleted, 'Protected language uninstall must delete zero posts.' );
		$this->assertSame( [], $result->files_deleted, 'Protected language uninstall must report no deleted files.' );
		$this->assertNotNull( get_post( $en ), 'Source-language post must survive a protected-language uninstall.' );
	}

	// =========================================================================
	// 3. Uninstall with file mods disallowed → posts deleted, files skipped
	// =========================================================================

	/**
	 * When file modifications are disabled (DISALLOW_FILE_MODS or the
	 * file_mod_allowed filter), uninstall() must still delete posts but must
	 * not attempt to delete locale files. The paths are surfaced in
	 * UninstallResult::$files_skipped for the caller to display a manual-
	 * deletion notice.
	 *
	 * The filter is used here because DISALLOW_FILE_MODS is a constant —
	 * it cannot be toggled at runtime. The filter produces identical behaviour:
	 * wp_is_file_mod_allowed() respects it before the constant check fails.
	 */
	public function test_uninstall_skips_file_deletion_when_mods_disallowed(): void {
		// Simulate DISALLOW_FILE_MODS via the filter WordPress provides.
		add_filter( 'file_mod_allowed', '__return_false' );

		$de = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $de, '_lf_lang', self::TARGET_LANG );

		$result = $this->make_uninstaller()->uninstall( self::TARGET_LANG );

		$this->assertSame( 1, $result->posts_deleted, 'Posts must be deleted even when file mods are disallowed.' );
		$this->assertFalse( $result->mods_allowed, 'mods_allowed must be false when the filter blocks file modifications.' );
		$this->assertSame( [], $result->files_deleted, 'No files must be deleted when mods are disallowed.' );
		// files_skipped holds the paths that would have been deleted — may be empty
		// when wp-env has no matching locale files, but the contract holds.
		$this->assertIsArray( $result->files_skipped, 'files_skipped must always be an array.' );
		$this->assertNull( get_post( $de ), 'The de post must be force-deleted regardless of file_mod status.' );
	}

	// =========================================================================
	// 4. Uninstall purges the target language's pattern translations
	// =========================================================================

	/**
	 * CPT Block Pattern translations are stored in the
	 * `linguaforge_pattern_translations` option, not as posts, so they are
	 * invisible to collect_post_ids()'s postmeta query. uninstall() must purge
	 * the target language's entries via PatternDiscovery::delete_language() and
	 * report the count in UninstallResult::$patterns_deleted, while leaving
	 * other languages' translations of the same pattern intact.
	 */
	public function test_uninstall_removes_pattern_translations_for_target_language_only(): void {
		PatternDiscovery::save_translation( 'theme/hero', self::TARGET_LANG, 'Hallo' );
		PatternDiscovery::save_translation( 'theme/hero', 'fr', 'Bonjour' );
		PatternDiscovery::save_translation( 'theme/footer-cta', self::TARGET_LANG, 'Jetzt kaufen' );

		$result = $this->make_uninstaller()->uninstall( self::TARGET_LANG );

		$this->assertSame( 2, $result->patterns_deleted, 'One removal per pattern that had a target-language translation.' );
		$this->assertFalse( PatternDiscovery::translation_exists( 'theme/hero', self::TARGET_LANG ) );
		$this->assertFalse( PatternDiscovery::translation_exists( 'theme/footer-cta', self::TARGET_LANG ) );
		$this->assertTrue( PatternDiscovery::translation_exists( 'theme/hero', 'fr' ), 'Other languages for the same pattern must survive.' );
	}

	/**
	 * The protected-language guard must short-circuit before any pattern
	 * translations are touched, matching the existing posts_deleted = 0
	 * contract for a protected-language uninstall.
	 */
	public function test_uninstall_protected_language_does_not_touch_pattern_translations(): void {
		PatternDiscovery::save_translation( 'theme/hero', self::SOURCE_LANG, 'Hello' );

		$result = $this->make_uninstaller()->uninstall( self::SOURCE_LANG );

		$this->assertSame( 0, $result->patterns_deleted );
		$this->assertTrue( PatternDiscovery::translation_exists( 'theme/hero', self::SOURCE_LANG ) );
	}

	// =========================================================================
	// 5. Uninstall removes i18n-overrides files (Loco Translate safe storage)
	// =========================================================================

	/**
	 * Reproduces the confirmed live bug: a language whose only footprint is a
	 * file in the uploads-based i18n-overrides directory (e.g. a Loco
	 * Translate custom translation copied via LanguageOverridesPanel's
	 * "Copy to Safe Storage" feature) survived uninstall entirely —
	 * collect_locale_files() only ever looked at WP_LANG_DIR, never at this
	 * directory, so a target language with zero WP_LANG_DIR files and zero
	 * _lf_lang posts reported a clean "0 posts / 0 files" success while
	 * remaining active in Context::discover_plugin_locales() forever.
	 *
	 * Uses the real uploads-based path (Context::i18n_overrides_dir()) rather
	 * than a temp dir — this is the one collect_locale_files() test class
	 * intentionally can't cover (it requires a live WP_UPLOAD_DIR), so it's
	 * verified here instead.
	 */
	public function test_uninstall_removes_i18n_override_files_for_target_language(): void {
		$dir = Router::get_instance()->context->i18n_overrides_dir();
		wp_mkdir_p( $dir );

		$target_mo = $dir . 'some-plugin-' . self::TARGET_LANG . '.mo';
		$other_mo  = $dir . 'some-plugin-fr.mo';
		touch( $target_mo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture; mirrors a real Loco Translate "Copy to Safe Storage" file.
		touch( $other_mo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture for the "other languages must survive" assertion.

		try {
			$result = $this->make_uninstaller()->uninstall( self::TARGET_LANG );

			$this->assertContains( $target_mo, $result->files_deleted, 'The target language\'s override file must be reported as deleted.' );
			$this->assertFileDoesNotExist( $target_mo, 'The target language\'s override file must actually be removed from disk.' );
			$this->assertFileExists( $other_mo, 'Other languages\' override files must survive.' );
		} finally {
			// Belt-and-braces cleanup in case an assertion above failed before
			// uninstall() ran (or deletion itself failed) — don't leak fixture
			// files into subsequent test runs on the same wp-env volume.
			if ( file_exists( $target_mo ) ) {
				unlink( $target_mo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
			}
			if ( file_exists( $other_mo ) ) {
				unlink( $other_mo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
			}
		}
	}
}
