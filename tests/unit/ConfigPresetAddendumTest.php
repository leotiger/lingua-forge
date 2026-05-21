<?php
/**
 * Unit tests for Config::default_preset_addendum().
 *
 * default_preset_addendum() is a pure match expression with no WordPress
 * dependencies, so it belongs in the unit suite and needs no wp-env.
 *
 * Tests for Config::preset_addendum() and Config::apply_compliance_to_system()
 * (which call get_option / get_post_meta) live in the integration suite:
 * tests/integration/ConfigPresetAddendumIntegrationTest.php
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\Config;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/Config.php';

class ConfigPresetAddendumTest extends TestCase {

	// ── default_preset_addendum ───────────────────────────────────────────────

	public function test_default_addendum_for_legal_is_non_empty(): void {

		$result = Config::default_preset_addendum( 'legal' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'Strict-preservation mode', $result );
	}

	public function test_default_addendum_for_technical_is_non_empty(): void {

		$result = Config::default_preset_addendum( 'technical' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'Technical-precision mode', $result );
	}

	public function test_default_addendum_for_creative_is_non_empty(): void {

		$result = Config::default_preset_addendum( 'creative' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'Creative mode', $result );
	}

	public function test_default_addendum_for_standard_is_empty_string(): void {

		$this->assertSame( '', Config::default_preset_addendum( 'standard' ) );
	}

	public function test_default_addendum_for_unknown_preset_is_empty_string(): void {

		$this->assertSame( '', Config::default_preset_addendum( 'nonexistent' ) );
	}

	public function test_default_addendum_return_values_match_class_constants(): void {

		$this->assertSame( Config::LEGAL_ADDENDUM_DEFAULT,    Config::default_preset_addendum( 'legal' ) );
		$this->assertSame( Config::TECHNICAL_ADDENDUM_DEFAULT, Config::default_preset_addendum( 'technical' ) );
		$this->assertSame( Config::CREATIVE_ADDENDUM_DEFAULT,  Config::default_preset_addendum( 'creative' ) );
	}

	/** All three non-standard presets must produce distinct addendum text. */
	public function test_default_addenda_are_distinct_across_presets(): void {

		$legal     = Config::default_preset_addendum( 'legal' );
		$technical = Config::default_preset_addendum( 'technical' );
		$creative  = Config::default_preset_addendum( 'creative' );

		$this->assertNotSame( $legal,     $technical );
		$this->assertNotSame( $legal,     $creative  );
		$this->assertNotSame( $technical, $creative  );
	}
}
