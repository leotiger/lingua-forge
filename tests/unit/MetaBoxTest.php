<?php
/**
 * Unit tests for LinguaForge\AI\Admin\MetaBox::inject_instance_languages().
 *
 * inject_instance_languages() is the one method in MetaBox.php with real
 * branching logic worth unit-testing. Everything else is WP hook registration,
 * asset enqueueing, or HTML rendering — all low unit-test value.
 *
 * The method auto-injects any language active on the WordPress instance
 * (returned by linguaforge_languages()) that is absent from the AI language
 * map passed as $languages. Three branches:
 *
 *   1. Code already in $languages → skip (no change).
 *   2. Code not in $languages, intl extension available and Locale resolves
 *      the name → use the English display name from Locale::getDisplayLanguage().
 *   3. Code not in $languages, Locale cannot resolve (returns code itself or
 *      empty string) → fall back to strtoupper($code).
 *
 * linguaforge_languages() is controlled via $GLOBALS['lf_api_languages'] through
 * the ApiPolyfills polyfill.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Admin\MetaBox;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Admin/MetaBox.php';

// ---------------------------------------------------------------------------

/**
 * @covers \LinguaForge\AI\Admin\MetaBox::inject_instance_languages
 */
final class MetaBoxTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_api_languages'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['lf_api_languages'] );
		parent::tearDown();
	}

	// =========================================================================
	// Code already in $languages → skip
	// =========================================================================

	public function test_existing_language_not_overwritten(): void {

		$GLOBALS['lf_api_languages'] = [ 'de' ];

		$result = MetaBox::inject_instance_languages( [ 'de' => 'German' ] );

		$this->assertSame( 'German', $result['de'] );
		$this->assertCount( 1, $result );
	}

	// =========================================================================
	// No instance languages → $languages returned unchanged
	// =========================================================================

	public function test_empty_instance_languages_returns_map_unchanged(): void {

		$GLOBALS['lf_api_languages'] = [];
		$map = [ 'en' => 'English', 'de' => 'German' ];

		$result = MetaBox::inject_instance_languages( $map );

		$this->assertSame( $map, $result );
	}

	// =========================================================================
	// Code not in map, Locale resolves → English display name used
	// =========================================================================

	public function test_missing_language_injected_with_locale_name(): void {

		$GLOBALS['lf_api_languages'] = [ 'de' ];

		$result = MetaBox::inject_instance_languages( [] );

		$this->assertArrayHasKey( 'de', $result );

		if ( class_exists( 'Locale' ) ) {
			// PHP intl resolves 'de' → 'German'
			$this->assertSame( 'German', $result['de'] );
		} else {
			// Fallback: strtoupper
			$this->assertSame( 'DE', $result['de'] );
		}
	}

	// =========================================================================
	// Unresolvable code → strtoupper fallback
	// =========================================================================

	public function test_unresolvable_code_falls_back_to_uppercase(): void {

		// 'xx' is not a valid BCP 47 language tag — Locale::getDisplayLanguage()
		// returns 'xx' itself (i.e. the code), which the method treats as a
		// resolution failure and falls back to strtoupper.
		$GLOBALS['lf_api_languages'] = [ 'xx' ];

		$result = MetaBox::inject_instance_languages( [] );

		$this->assertArrayHasKey( 'xx', $result );
		$this->assertSame( 'XX', $result['xx'] );
	}

	// =========================================================================
	// Mix: some codes in map, some not
	// =========================================================================

	public function test_only_missing_codes_are_injected(): void {

		$GLOBALS['lf_api_languages'] = [ 'en', 'fr', 'de' ];

		// 'en' and 'fr' are already in the map; only 'de' is missing.
		$map    = [ 'en' => 'English', 'fr' => 'French' ];
		$result = MetaBox::inject_instance_languages( $map );

		$this->assertArrayHasKey( 'de', $result );
		$this->assertSame( 'English', $result['en'] ); // untouched
		$this->assertSame( 'French',  $result['fr'] ); // untouched
		$this->assertCount( 3, $result );
	}

	// =========================================================================
	// Multiple missing codes all injected
	// =========================================================================

	public function test_multiple_missing_codes_all_injected(): void {

		$GLOBALS['lf_api_languages'] = [ 'ca', 'es' ];

		$result = MetaBox::inject_instance_languages( [] );

		$this->assertArrayHasKey( 'ca', $result );
		$this->assertArrayHasKey( 'es', $result );
		$this->assertCount( 2, $result );
	}
}
