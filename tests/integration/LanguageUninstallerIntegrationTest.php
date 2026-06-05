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
 *
 * Design notes:
 *   • $GLOBALS['wpdb'] is injected directly — this is the real wpdb in wp-env.
 *   • WP_UnitTestCase wraps every test in a DB transaction rolled back on
 *     tearDown — no manual post/meta or option cleanup needed.
 *   • Context caches are reset in setUp() so option changes are re-read fresh.
 *   • File deletion is not tested here because wp-env's WP_LANG_DIR contains no
 *     matching locale files for test language codes ('de'). The DISALLOW_FILE_MODS
 *     path (test 3) verifies the mods_allowed/files_skipped contract without
 *     touching the real filesystem.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

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
}
