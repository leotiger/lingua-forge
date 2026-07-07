<?php
/**
 * Unit tests for LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery.
 *
 * Covers the option-backed translation-storage helpers, which have no WP
 * runtime dependency beyond get_option()/update_option():
 *
 *   • name_to_key()        — pattern name → option-key fragment.
 *   • save_translation()   / get_translation() / translation_exists().
 *   • delete_language()    — added alongside the language-uninstall fix so
 *                            that LanguageUninstaller::uninstall() can purge
 *                            a language's pattern translations from the
 *                            `linguaforge_pattern_translations` option (they
 *                            live there rather than as posts, so they're
 *                            otherwise invisible to the uninstall's postmeta
 *                            query and would survive indefinitely).
 *
 * get_cpt_patterns() is not covered here — it depends on
 * WP_Block_Patterns_Registry and get_post_types(), which belong to the
 * integration suite.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Admin/FseLocalisation/PatternDiscovery.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery
 */
final class PatternDiscoveryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['lf_test_options'] = [];
		parent::tearDown();
	}

	// =========================================================================
	// name_to_key()
	// =========================================================================

	public function test_name_to_key_replaces_slash_with_double_underscore(): void {
		$this->assertSame( 'mytheme__hero-block', PatternDiscovery::name_to_key( 'mytheme/hero-block' ) );
	}

	// =========================================================================
	// save_translation() / get_translation() / translation_exists()
	// =========================================================================

	public function test_translation_exists_is_false_when_nothing_saved(): void {
		$this->assertFalse( PatternDiscovery::translation_exists( 'theme/hero', 'de' ) );
	}

	public function test_save_and_get_translation_round_trips(): void {
		PatternDiscovery::save_translation( 'theme/hero', 'de', '<!-- wp:paragraph -->Hallo<!-- /wp:paragraph -->' );

		$this->assertTrue( PatternDiscovery::translation_exists( 'theme/hero', 'de' ) );
		$this->assertSame(
			'<!-- wp:paragraph -->Hallo<!-- /wp:paragraph -->',
			PatternDiscovery::get_translation( 'theme/hero', 'de' )
		);
	}

	public function test_save_translation_keeps_other_languages_for_same_pattern(): void {
		PatternDiscovery::save_translation( 'theme/hero', 'de', 'Hallo' );
		PatternDiscovery::save_translation( 'theme/hero', 'fr', 'Bonjour' );

		$this->assertSame( 'Hallo', PatternDiscovery::get_translation( 'theme/hero', 'de' ) );
		$this->assertSame( 'Bonjour', PatternDiscovery::get_translation( 'theme/hero', 'fr' ) );
	}

	public function test_get_translation_returns_empty_string_when_not_found(): void {
		$this->assertSame( '', PatternDiscovery::get_translation( 'theme/hero', 'de' ) );
	}

	// =========================================================================
	// delete_language()
	// =========================================================================

	public function test_delete_language_returns_zero_when_option_is_empty(): void {
		$this->assertSame( 0, PatternDiscovery::delete_language( 'de' ) );
	}

	public function test_delete_language_removes_only_the_target_language(): void {
		PatternDiscovery::save_translation( 'theme/hero', 'de', 'Hallo' );
		PatternDiscovery::save_translation( 'theme/hero', 'fr', 'Bonjour' );

		$removed = PatternDiscovery::delete_language( 'de' );

		$this->assertSame( 1, $removed );
		$this->assertFalse( PatternDiscovery::translation_exists( 'theme/hero', 'de' ) );
		$this->assertTrue( PatternDiscovery::translation_exists( 'theme/hero', 'fr' ), 'Other languages for the same pattern must survive.' );
	}

	public function test_delete_language_counts_across_multiple_patterns(): void {
		PatternDiscovery::save_translation( 'theme/hero', 'de', 'Hallo' );
		PatternDiscovery::save_translation( 'theme/footer-cta', 'de', 'Jetzt kaufen' );
		PatternDiscovery::save_translation( 'theme/footer-cta', 'fr', 'Acheter maintenant' );

		$removed = PatternDiscovery::delete_language( 'de' );

		$this->assertSame( 2, $removed, 'One removal per pattern that had a de translation.' );
		$this->assertFalse( PatternDiscovery::translation_exists( 'theme/hero', 'de' ) );
		$this->assertFalse( PatternDiscovery::translation_exists( 'theme/footer-cta', 'de' ) );
		$this->assertTrue( PatternDiscovery::translation_exists( 'theme/footer-cta', 'fr' ) );
	}

	public function test_delete_language_drops_pattern_entry_entirely_once_empty(): void {
		PatternDiscovery::save_translation( 'theme/hero', 'de', 'Hallo' );

		PatternDiscovery::delete_language( 'de' );

		$stored = $GLOBALS['lf_test_options']['linguaforge_pattern_translations'];
		$this->assertArrayNotHasKey(
			'theme__hero',
			$stored,
			'A pattern with no remaining language translations should not leave an empty array behind.'
		);
	}

	public function test_delete_language_is_idempotent_when_language_already_absent(): void {
		PatternDiscovery::save_translation( 'theme/hero', 'fr', 'Bonjour' );

		$removed = PatternDiscovery::delete_language( 'de' );

		$this->assertSame( 0, $removed );
		$this->assertTrue( PatternDiscovery::translation_exists( 'theme/hero', 'fr' ) );
	}

	public function test_delete_language_does_not_write_option_when_nothing_removed(): void {
		// Pre-populate the raw store directly so we can detect an unwanted rewrite:
		// update_option() in the ApiPolyfills stub always overwrites the value, so
		// if delete_language() skips the call entirely (no-op branch), the exact
		// same array instance/content remains untouched.
		$GLOBALS['lf_test_options']['linguaforge_pattern_translations'] = [
			'theme__hero' => [ 'fr' => 'Bonjour' ],
		];

		PatternDiscovery::delete_language( 'de' );

		$this->assertSame(
			[ 'theme__hero' => [ 'fr' => 'Bonjour' ] ],
			$GLOBALS['lf_test_options']['linguaforge_pattern_translations']
		);
	}
}
