<?php
/**
 * Unit tests for LinguaForge\Router\LocaleDetector.
 *
 * Covers:
 *   • locale_from_lang() — hard-override, fallback-map, and default paths.
 *   • language_label()   — display-name derivation from locale.
 *
 * locale_from_lang() holds an internal static cache.  To avoid cross-test
 * interference we use a distinct language code in each test that exercises
 * the fallback-map branch, so every assertion hits a fresh cache slot.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Context;
use LinguaForge\Router\LocaleDetector;
use LinguaForge\Router\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/ApiPolyfills.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-context.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-language-router.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-locale-detector.php';

final class LocaleDetectorTest extends TestCase {

	private LocaleDetector $detector;

	protected function setUp(): void {
		parent::setUp();
		Router::reset_instance();
		$this->detector = $this->make_detector( 'en' );
	}

	protected function tearDown(): void {
		Router::reset_instance();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build a LocaleDetector backed by a stub Router whose source_language()
	 * returns $source_lang without touching WordPress options or filters.
	 */
	private function make_detector( string $source_lang = 'en' ): LocaleDetector {

		$ctx_ref  = new ReflectionClass( Context::class );
		$context  = $ctx_ref->newInstanceWithoutConstructor();

		$lang_prop = $ctx_ref->getProperty( 'cached_source_language' );
		$lang_prop->setAccessible( true );
		$lang_prop->setValue( $context, $source_lang );

		$router_ref = new ReflectionClass( Router::class );
		$router     = $router_ref->newInstanceWithoutConstructor();

		$ctx_field = $router_ref->getProperty( 'context' );
		$ctx_field->setAccessible( true );
		$ctx_field->setValue( $router, $context );

		$inst_prop = $router_ref->getProperty( 'instance' );
		$inst_prop->setAccessible( true );
		$inst_prop->setValue( null, $router );

		return new LocaleDetector( $router );
	}

	// =========================================================================
	// locale_from_lang() — fallback map
	// =========================================================================

	public function test_english_maps_to_en_US(): void {
		$this->assertSame( 'en_US', $this->detector->locale_from_lang( 'en' ) );
	}

	public function test_spanish_maps_to_es_ES(): void {
		$this->assertSame( 'es_ES', $this->detector->locale_from_lang( 'es' ) );
	}

	public function test_german_maps_to_de_DE(): void {
		$this->assertSame( 'de_DE', $this->detector->locale_from_lang( 'de' ) );
	}

	public function test_french_maps_to_fr_FR(): void {
		$this->assertSame( 'fr_FR', $this->detector->locale_from_lang( 'fr' ) );
	}

	public function test_japanese_maps_to_ja(): void {
		$this->assertSame( 'ja', $this->detector->locale_from_lang( 'ja' ) );
	}

	public function test_chinese_maps_to_zh_CN(): void {
		$this->assertSame( 'zh_CN', $this->detector->locale_from_lang( 'zh' ) );
	}

	public function test_arabic_maps_to_ar(): void {
		$this->assertSame( 'ar', $this->detector->locale_from_lang( 'ar' ) );
	}

	public function test_portuguese_defaults_to_pt_PT(): void {
		// The map defaults 'pt' to pt_PT; pt_BR requires a filter override.
		$this->assertSame( 'pt_PT', $this->detector->locale_from_lang( 'pt' ) );
	}

	public function test_unknown_lang_falls_back_to_en_US(): void {
		// No entry in the fallback map, no WP language pack → en_US default.
		$this->assertSame( 'en_US', $this->detector->locale_from_lang( 'xx' ) );
	}

	// =========================================================================
	// locale_from_lang() — fallback map additions (2.5.1)
	//
	// Auditing the fallback map against languages/lingua-forge-*.po (this
	// plugin's own bundled UI translations) turned up six languages with no
	// entry, which fell through to the en_US default and became
	// indistinguishable from English to any caller comparing locale strings —
	// surfacing as a double-checked language in the admin-bar switcher (see
	// AdminBarLocaleSwitcherIntegrationTest): hi, ur, th, sw, km, eu.
	//
	// Yoruba ('yo') is NOT one of these, despite an earlier version of this
	// fix treating it as though it were (see the fallback_map's own comment
	// in class-locale-detector.php for the full story: WordPress's real
	// locale slug for Yoruba is the bare 3-letter 'yor', not 'yo' — a fact
	// only discovered once a live Yoruba install proved un-uninstallable).
	// Since Context::lang_from_locale() no longer truncates bare 3-letter
	// locales to 2 characters, 'yor' now resolves correctly via step 2's
	// direct match against installed locales with no fallback-map entry at
	// all — see test_yor_resolves_via_override_directory_discovery() below.
	// =========================================================================

	public function test_hindi_maps_to_hi_IN(): void {
		$this->assertSame( 'hi_IN', $this->detector->locale_from_lang( 'hi' ) );
	}

	public function test_urdu_maps_to_ur(): void {
		$this->assertSame( 'ur', $this->detector->locale_from_lang( 'ur' ) );
	}

	public function test_thai_maps_to_th(): void {
		$this->assertSame( 'th', $this->detector->locale_from_lang( 'th' ) );
	}

	public function test_swahili_maps_to_sw(): void {
		$this->assertSame( 'sw', $this->detector->locale_from_lang( 'sw' ) );
	}

	public function test_khmer_maps_to_km(): void {
		$this->assertSame( 'km', $this->detector->locale_from_lang( 'km' ) );
	}

	public function test_basque_maps_to_eu(): void {
		$this->assertSame( 'eu', $this->detector->locale_from_lang( 'eu' ) );
	}

	/**
	 * Confirmed live bug (the actual root cause, found only after a manual
	 * 'yo' fallback-map entry failed to make Yoruba fully uninstallable):
	 * WordPress's real locale slug for Yoruba is the bare 3-letter 'yor',
	 * not the 2-letter 'yo' this codebase previously assumed everywhere via
	 * substr($locale, 0, 2) truncation.
	 *
	 * Creates a real fixture file in the i18n-overrides directory (the unit
	 * test polyfill for wp_upload_dir() points at a real temp directory) so
	 * discover_plugin_locales() reports 'yor' as a known locale, then asserts
	 * locale_from_lang('yor') resolves it via step 2's direct match — no
	 * fallback-map entry needed or present for it at all.
	 */
	public function test_yor_resolves_via_override_directory_discovery(): void {
		$dir = sys_get_temp_dir() . '/lf-unit-test-uploads/lingua-forge/i18n-overrides/';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture dir; WP_Filesystem is not available without a WordPress runtime.
		}
		$file = $dir . 'some-plugin-yor.mo';
		touch( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test fixture file; WP_Filesystem is not available without a WordPress runtime.

		try {
			$this->assertSame( 'yor', $this->detector->locale_from_lang( 'yor' ) );
		} finally {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
			}
		}
	}

	public function test_unmapped_yo_still_falls_back_to_en_US(): void {
		// 'yo' itself is not a real WordPress locale and has no fallback-map
		// entry (see the note above) — it must behave exactly like any other
		// unrecognised code and default to en_US, not silently resolve to
		// something that looks plausible but isn't installable.
		$this->assertSame( 'en_US', $this->detector->locale_from_lang( 'yo' ) );
	}

	// =========================================================================
	// locale_from_lang() — hard override
	// =========================================================================

	public function test_catalan_uses_hard_override(): void {
		// 'ca' is in the force-locale map as a hard override (step 1), so it
		// must be returned without going through the fallback map at all.
		$this->assertSame( 'ca', $this->detector->locale_from_lang( 'ca' ) );
	}

	// =========================================================================
	// locale_from_lang() — normalisation
	// =========================================================================

	public function test_uppercase_lang_code_is_normalised(): void {
		$this->assertSame( 'de_DE', $this->detector->locale_from_lang( 'DE' ) );
	}

	// =========================================================================
	// locale_from_lang() — static cache
	// =========================================================================

	public function test_repeated_call_returns_same_value(): void {
		$first  = $this->detector->locale_from_lang( 'it' );
		$second = $this->detector->locale_from_lang( 'it' );
		$this->assertSame( $first, $second );
		$this->assertSame( 'it_IT', $first );
	}

	// =========================================================================
	// language_label()
	// =========================================================================

	public function test_language_label_returns_non_empty_string_for_known_lang(): void {
		// We don't assert the exact label because it depends on whether the
		// PHP intl extension is available — but it must not be empty.
		$label = $this->detector->language_label( 'en' );
		$this->assertIsString( $label );
		$this->assertNotSame( '', $label );
	}

	public function test_language_label_for_unknown_lang_returns_non_empty_string(): void {
		$label = $this->detector->language_label( 'xx' );
		$this->assertIsString( $label );
		$this->assertNotSame( '', $label );
	}

	/**
	 * language_label('yor') must not crash, and — same caveat as the two
	 * tests above re: intl extension availability — must not return an
	 * empty string. The interesting case here is internal: it exercises
	 * Context::iso_639_1_from_lang('yor') === 'yo' being fed to
	 * locale_get_display_language() instead of the bare 'yor', which ICU
	 * generally doesn't recognise as a real locale identifier.
	 */
	public function test_language_label_for_bare_three_letter_locale_returns_non_empty_string(): void {
		$label = $this->detector->language_label( 'yor' );
		$this->assertIsString( $label );
		$this->assertNotSame( '', $label );
	}
}
