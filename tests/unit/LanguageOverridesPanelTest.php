<?php
/**
 * Unit tests for LinguaForge\AI\Admin\Settings\Panels\LanguageOverridesPanel.
 *
 * Tests the three public static filesystem helpers without a WordPress runtime.
 * Temporary directories are created and cleaned up by each test so there is no
 * shared state between runs.
 *
 * Helpers tested:
 *   • loco_is_active()      — returns false when loco_plugin_version() is not defined.
 *   • loco_custom_files()   — the non-trivial scanner: maps Loco .mo files to the
 *                             correct shape including has_po, in_overrides, size, type,
 *                             and returns them sorted by base name.
 *   • overrides_dir()       — returns uploads basedir + the expected suffix.
 *
 * LOCO_LANG_DIR is defined once in this file to a temp directory so the scanner
 * has a controlled filesystem to work against. The uploads path comes from the
 * existing wp_upload_dir() polyfill in ApiPolyfills.php.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Admin\Settings\Panels\LanguageOverridesPanel;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Admin/Settings/Panels/LanguageOverridesPanel.php';

// ---------------------------------------------------------------------------
// LOCO_LANG_DIR: define to a predictable temp root.
// ---------------------------------------------------------------------------
if ( ! defined( 'LOCO_LANG_DIR' ) ) {
	define( 'LOCO_LANG_DIR', sys_get_temp_dir() . '/lf-loco-test' );
}

/**
 * @covers \LinguaForge\AI\Admin\Settings\Panels\LanguageOverridesPanel
 */
final class LanguageOverridesPanelTest extends TestCase {

	/** Loco plugins/ directory. */
	private string $loco_plugins;
	/** Loco themes/ directory. */
	private string $loco_themes;
	/** The i18n-overrides directory resolved via overrides_dir(). */
	private string $overrides;

	protected function setUp(): void {
		parent::setUp();

		$this->loco_plugins = LOCO_LANG_DIR . '/plugins/';
		$this->loco_themes  = LOCO_LANG_DIR . '/themes/';
		$this->overrides    = LanguageOverridesPanel::overrides_dir();

		// Create clean directories for this test.
		foreach ( [ $this->loco_plugins, $this->loco_themes, $this->overrides ] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test bootstrap; WP_Filesystem is not available without a full WP runtime.
			}
		}
	}

	protected function tearDown(): void {
		// Clean up all temp files created during the test.
		foreach ( [ $this->loco_plugins, $this->loco_themes, $this->overrides ] as $dir ) {
			foreach ( glob( $dir . '*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test teardown; wp_delete_file() is not available without a full WP runtime.
			}
		}
		parent::tearDown();
	}

	private function touch( string $path ): void {
		file_put_contents( $path, 'stub' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture; WP_Filesystem is not available without a full WP runtime.
	}

	// =========================================================================
	// loco_is_active()
	// =========================================================================

	public function test_loco_is_active_returns_false_when_loco_not_loaded(): void {
		// loco_plugin_version() is not defined in the unit-test environment.
		$this->assertFalse( LanguageOverridesPanel::loco_is_active() );
	}

	// =========================================================================
	// loco_custom_files() — empty directories
	// =========================================================================

	public function test_loco_custom_files_empty_loco_dir_returns_empty(): void {
		$this->assertSame( [], LanguageOverridesPanel::loco_custom_files() );
	}

	// =========================================================================
	// loco_custom_files() — single plugin .mo file
	// =========================================================================

	public function test_loco_custom_files_single_mo_has_correct_shape(): void {
		$this->touch( $this->loco_plugins . 'vikbooking-ca.mo' );

		$files = LanguageOverridesPanel::loco_custom_files();

		$this->assertCount( 1, $files );
		$f = $files[0];

		$this->assertSame( 'plugins',      $f['type'] );
		$this->assertSame( 'vikbooking-ca', $f['base'] );
		$this->assertSame( $this->loco_plugins . 'vikbooking-ca.mo', $f['mo_path'] );
		$this->assertFalse( $f['has_po'] );
		$this->assertFalse( $f['in_overrides'] );
		$this->assertNotEmpty( $f['size'] ); // '4 B' or similar
	}

	// =========================================================================
	// loco_custom_files() — .po companion detected
	// =========================================================================

	public function test_loco_custom_files_detects_po_companion(): void {
		$this->touch( $this->loco_plugins . 'vikbooking-ca.mo' );
		$this->touch( $this->loco_plugins . 'vikbooking-ca.po' );

		$files = LanguageOverridesPanel::loco_custom_files();

		$this->assertTrue( $files[0]['has_po'] );
	}

	// =========================================================================
	// loco_custom_files() — in_overrides flag
	// =========================================================================

	public function test_loco_custom_files_detects_already_in_overrides(): void {
		$this->touch( $this->loco_plugins . 'vikbooking-ca.mo' );
		$this->touch( $this->overrides . 'vikbooking-ca.mo' ); // already copied

		$files = LanguageOverridesPanel::loco_custom_files();

		$this->assertTrue( $files[0]['in_overrides'] );
	}

	public function test_loco_custom_files_not_in_overrides_when_absent(): void {
		$this->touch( $this->loco_plugins . 'vikbooking-ca.mo' );
		// No matching file in overrides dir.

		$files = LanguageOverridesPanel::loco_custom_files();

		$this->assertFalse( $files[0]['in_overrides'] );
	}

	// =========================================================================
	// loco_custom_files() — themes type
	// =========================================================================

	public function test_loco_custom_files_includes_theme_files_with_correct_type(): void {
		$this->touch( $this->loco_themes . 'twentytwentyfour-de_DE.mo' );

		$files = LanguageOverridesPanel::loco_custom_files();

		$this->assertCount( 1, $files );
		$this->assertSame( 'themes', $files[0]['type'] );
	}

	// =========================================================================
	// loco_custom_files() — sorted by base name
	// =========================================================================

	public function test_loco_custom_files_sorted_by_base_name(): void {
		$this->touch( $this->loco_plugins . 'zzz-ca.mo' );
		$this->touch( $this->loco_plugins . 'aaa-ca.mo' );
		$this->touch( $this->loco_themes  . 'mmm-ca.mo' );

		$files  = LanguageOverridesPanel::loco_custom_files();
		$bases  = array_column( $files, 'base' );
		$sorted = $bases;
		sort( $sorted );

		$this->assertSame( $sorted, $bases );
	}

	// =========================================================================
	// overrides_dir()
	// =========================================================================

	public function test_overrides_dir_ends_with_trailing_slash(): void {
		$dir = LanguageOverridesPanel::overrides_dir();
		$this->assertStringEndsWith( '/', $dir );
	}

	public function test_overrides_dir_contains_expected_suffix(): void {
		$dir = LanguageOverridesPanel::overrides_dir();
		$this->assertStringContainsString( 'lingua-forge/i18n-overrides/', $dir );
	}
}
