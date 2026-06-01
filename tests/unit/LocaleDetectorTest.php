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
}
