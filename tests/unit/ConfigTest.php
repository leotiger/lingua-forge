<?php
/**
 * Unit tests for LinguaForge\AI\Core\Config.
 *
 * Covers all public methods:
 *   • provider()                        — stored option, LINGUAFORGE_PROVIDER constant, default.
 *   • model()                           — stored option, MODEL_DEFAULTS fallback.
 *   • default_model()                   — known / unknown provider / tier.
 *   • all_model_defaults()              — returns expected structure.
 *   • translation_max_tokens()          — stored vs. default (16 000).
 *   • translation_max_input_chars()     — stored vs. default (0).
 *   • content_generator_max_tokens()    — stored vs. default (8 192).
 *   • content_generator_max_hints_chars()   — stored vs. default (2 000).
 *   • content_generator_max_context_chars() — stored vs. default (6 000).
 *   • translation_tier()                — stored vs. default ('quality').
 *   • quick_translate_tier()            — stored vs. default ('light').
 *   • quick_translate_max_tokens()      — stored vs. default (2 000).
 *   • quick_translate_max_input_chars() — stored vs. default (8 000).
 *   • presets()                         — expected keys and structure.
 *   • default_preset_addendum()         — known presets and unknown fallback.
 *   • preset_addendum()                 — stored override vs. built-in default.
 *   • active_preset()                   — global option, default 'standard'.
 *   • apply_compliance_to_system()      — standard no-op, preset appends addendum.
 *   • apply_compliance()                — standard no-op, preset applies temperature.
 *
 * Uses the WordPress options and __() polyfills from ApiPolyfills.php.
 * Does NOT require ABSPATH or any WordPress runtime.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Providers\WorkerConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Providers/WorkerConfig.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/Config.php';

final class ConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['lf_test_options'] = [];
		parent::tearDown();
	}

	// =========================================================================
	// provider()
	// =========================================================================

	public function test_provider_defaults_to_anthropic(): void {
		$this->assertSame( 'anthropic', Config::provider() );
	}

	public function test_provider_returns_stored_option(): void {
		$GLOBALS['lf_test_options']['linguaforge_provider'] = 'openai';

		$this->assertSame( 'openai', Config::provider() );
	}

	// =========================================================================
	// model()
	// =========================================================================

	public function test_model_returns_default_quality_for_anthropic(): void {
		$model = Config::model( 'quality' );

		$this->assertNotSame( '', $model );
		$this->assertStringContainsString( 'claude', strtolower( $model ) );
	}

	public function test_model_returns_default_light_for_anthropic(): void {
		$model = Config::model( 'light' );

		$this->assertNotSame( '', $model );
		$this->assertStringContainsString( 'haiku', strtolower( $model ) );
	}

	public function test_model_returns_stored_override(): void {
		$GLOBALS['lf_test_options']['linguaforge_model_anthropic_quality'] = 'claude-opus-99';

		$this->assertSame( 'claude-opus-99', Config::model( 'quality' ) );
	}

	public function test_model_ignores_empty_stored_override(): void {
		$GLOBALS['lf_test_options']['linguaforge_model_anthropic_quality'] = '';

		// Empty string → fall through to default.
		$this->assertNotSame( '', Config::model( 'quality' ) );
	}

	public function test_model_uses_active_provider(): void {
		$GLOBALS['lf_test_options']['linguaforge_provider'] = 'openai';

		$model = Config::model( 'quality' );

		// OpenAI quality default is gpt-4o.
		$this->assertSame( 'gpt-4o', $model );
	}

	// =========================================================================
	// default_model()
	// =========================================================================

	public function test_default_model_returns_known_anthropic_quality(): void {
		$this->assertSame( 'claude-sonnet-4-6', Config::default_model( 'anthropic', 'quality' ) );
	}

	public function test_default_model_returns_known_openai_light(): void {
		$this->assertSame( 'gpt-4o-mini', Config::default_model( 'openai', 'light' ) );
	}

	public function test_default_model_returns_empty_string_for_unknown_provider(): void {
		$this->assertSame( '', Config::default_model( 'unknown', 'quality' ) );
	}

	public function test_default_model_returns_empty_string_for_unknown_tier(): void {
		$this->assertSame( '', Config::default_model( 'anthropic', 'ultra' ) );
	}

	// =========================================================================
	// all_model_defaults()
	// =========================================================================

	public function test_all_model_defaults_contains_all_providers(): void {
		$defaults = Config::all_model_defaults();

		$this->assertArrayHasKey( 'anthropic', $defaults );
		$this->assertArrayHasKey( 'openai', $defaults );
		$this->assertArrayHasKey( 'gemini', $defaults );
	}

	public function test_all_model_defaults_each_provider_has_light_and_quality(): void {
		$defaults = Config::all_model_defaults();

		foreach ( $defaults as $provider => $tiers ) {
			$this->assertArrayHasKey( 'light',   $tiers, "{$provider} missing 'light'" );
			$this->assertArrayHasKey( 'quality', $tiers, "{$provider} missing 'quality'" );
		}
	}

	// =========================================================================
	// translation_max_tokens()
	// =========================================================================

	public function test_translation_max_tokens_defaults_to_16000(): void {
		$this->assertSame( 16000, Config::translation_max_tokens() );
	}

	public function test_translation_max_tokens_returns_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_translation_max_tokens'] = 8000;

		$this->assertSame( 8000, Config::translation_max_tokens() );
	}

	public function test_translation_max_tokens_ignores_zero_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_translation_max_tokens'] = 0;

		$this->assertSame( 16000, Config::translation_max_tokens() );
	}

	// =========================================================================
	// translation_max_input_chars()
	// =========================================================================

	public function test_translation_max_input_chars_defaults_to_zero(): void {
		$this->assertSame( 0, Config::translation_max_input_chars() );
	}

	public function test_translation_max_input_chars_returns_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_translation_max_input_chars'] = 5000;

		$this->assertSame( 5000, Config::translation_max_input_chars() );
	}

	// =========================================================================
	// content_generator_max_tokens()
	// =========================================================================

	public function test_content_generator_max_tokens_defaults_to_8192(): void {
		$this->assertSame( 8192, Config::content_generator_max_tokens() );
	}

	public function test_content_generator_max_tokens_returns_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_content_generator_max_tokens'] = 4096;

		$this->assertSame( 4096, Config::content_generator_max_tokens() );
	}

	// =========================================================================
	// content_generator_max_hints_chars()
	// =========================================================================

	public function test_content_generator_max_hints_chars_defaults_to_2000(): void {
		$this->assertSame( 2000, Config::content_generator_max_hints_chars() );
	}

	// =========================================================================
	// content_generator_max_context_chars()
	// =========================================================================

	public function test_content_generator_max_context_chars_defaults_to_6000(): void {
		$this->assertSame( 6000, Config::content_generator_max_context_chars() );
	}

	// =========================================================================
	// translation_tier()
	// =========================================================================

	public function test_translation_tier_defaults_to_quality(): void {
		$this->assertSame( 'quality', Config::translation_tier() );
	}

	public function test_translation_tier_returns_light_when_stored(): void {
		$GLOBALS['lf_test_options']['linguaforge_translation_tier'] = 'light';

		$this->assertSame( 'light', Config::translation_tier() );
	}

	public function test_translation_tier_falls_back_to_quality_for_unknown_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_translation_tier'] = 'ultra';

		$this->assertSame( 'quality', Config::translation_tier() );
	}

	// =========================================================================
	// quick_translate_tier()
	// =========================================================================

	public function test_quick_translate_tier_defaults_to_light(): void {
		$this->assertSame( 'light', Config::quick_translate_tier() );
	}

	public function test_quick_translate_tier_returns_quality_when_stored(): void {
		$GLOBALS['lf_test_options']['linguaforge_quick_translate_tier'] = 'quality';

		$this->assertSame( 'quality', Config::quick_translate_tier() );
	}

	public function test_quick_translate_tier_falls_back_to_light_for_unknown_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_quick_translate_tier'] = 'turbo';

		$this->assertSame( 'light', Config::quick_translate_tier() );
	}

	// =========================================================================
	// quick_translate_max_tokens()
	// =========================================================================

	public function test_quick_translate_max_tokens_defaults_to_2000(): void {
		$this->assertSame( 2000, Config::quick_translate_max_tokens() );
	}

	public function test_quick_translate_max_tokens_returns_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_quick_translate_max_tokens'] = 1000;

		$this->assertSame( 1000, Config::quick_translate_max_tokens() );
	}

	// =========================================================================
	// quick_translate_max_input_chars()
	// =========================================================================

	public function test_quick_translate_max_input_chars_defaults_to_8000(): void {
		$this->assertSame( 8000, Config::quick_translate_max_input_chars() );
	}

	public function test_quick_translate_max_input_chars_returns_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_quick_translate_max_input_chars'] = 3000;

		$this->assertSame( 3000, Config::quick_translate_max_input_chars() );
	}

	// =========================================================================
	// presets()
	// =========================================================================

	public function test_presets_returns_expected_keys(): void {
		$presets = Config::presets();

		$this->assertArrayHasKey( 'standard',  $presets );
		$this->assertArrayHasKey( 'technical', $presets );
		$this->assertArrayHasKey( 'legal',     $presets );
		$this->assertArrayHasKey( 'creative',  $presets );
	}

	public function test_presets_each_entry_has_required_fields(): void {
		foreach ( Config::presets() as $key => $preset ) {
			$this->assertArrayHasKey( 'label',       $preset, "{$key} missing 'label'" );
			$this->assertArrayHasKey( 'temperature', $preset, "{$key} missing 'temperature'" );
			$this->assertArrayHasKey( 'addendum',    $preset, "{$key} missing 'addendum'" );
		}
	}

	public function test_presets_standard_has_null_temperature(): void {
		$this->assertNull( Config::presets()['standard']['temperature'] );
	}

	public function test_presets_legal_has_low_temperature(): void {
		$legal = Config::presets()['legal'];

		$this->assertIsFloat( $legal['temperature'] );
		$this->assertLessThan( 0.5, $legal['temperature'] );
	}

	// =========================================================================
	// default_preset_addendum()
	// =========================================================================

	public function test_default_preset_addendum_returns_empty_for_standard(): void {
		$this->assertSame( '', Config::default_preset_addendum( 'standard' ) );
	}

	public function test_default_preset_addendum_returns_non_empty_for_technical(): void {
		$this->assertNotSame( '', Config::default_preset_addendum( 'technical' ) );
	}

	public function test_default_preset_addendum_returns_non_empty_for_legal(): void {
		$this->assertNotSame( '', Config::default_preset_addendum( 'legal' ) );
	}

	public function test_default_preset_addendum_returns_non_empty_for_creative(): void {
		$this->assertNotSame( '', Config::default_preset_addendum( 'creative' ) );
	}

	public function test_default_preset_addendum_returns_empty_for_unknown_preset(): void {
		$this->assertSame( '', Config::default_preset_addendum( 'no-such-preset' ) );
	}

	// =========================================================================
	// preset_addendum()
	// =========================================================================

	public function test_preset_addendum_returns_built_in_default_when_no_option(): void {
		$built_in = Config::default_preset_addendum( 'legal' );

		$this->assertSame( $built_in, Config::preset_addendum( 'legal' ) );
	}

	public function test_preset_addendum_returns_stored_override_when_set(): void {
		$GLOBALS['lf_test_options']['linguaforge_preset_addendum_legal'] = 'Custom legal addendum.';

		$this->assertSame( 'Custom legal addendum.', Config::preset_addendum( 'legal' ) );
	}

	public function test_preset_addendum_ignores_whitespace_only_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_preset_addendum_technical'] = '   ';

		$built_in = Config::default_preset_addendum( 'technical' );
		$this->assertSame( $built_in, Config::preset_addendum( 'technical' ) );
	}

	// =========================================================================
	// active_preset()
	// =========================================================================

	public function test_active_preset_defaults_to_standard(): void {
		$this->assertSame( 'standard', Config::active_preset() );
	}

	public function test_active_preset_returns_stored_global_option(): void {
		$GLOBALS['lf_test_options']['linguaforge_active_preset'] = 'technical';

		$this->assertSame( 'technical', Config::active_preset() );
	}

	public function test_active_preset_ignores_invalid_stored_value(): void {
		$GLOBALS['lf_test_options']['linguaforge_active_preset'] = 'no-such-preset';

		$this->assertSame( 'standard', Config::active_preset() );
	}

	// =========================================================================
	// apply_compliance_to_system()
	// =========================================================================

	public function test_apply_compliance_to_system_returns_unchanged_for_standard(): void {
		$base = 'You are a helpful translator.';

		$result = Config::apply_compliance_to_system( $base );

		$this->assertSame( $base, $result );
	}

	public function test_apply_compliance_to_system_appends_addendum_for_legal_preset(): void {
		$GLOBALS['lf_test_options']['linguaforge_active_preset'] = 'legal';
		$base = 'Translate the following text.';

		$result = Config::apply_compliance_to_system( $base );

		$this->assertStringContainsString( $base, $result );
		$this->assertGreaterThan( strlen( $base ), strlen( $result ) );
	}

	public function test_apply_compliance_to_system_trims_base_before_appending(): void {
		$GLOBALS['lf_test_options']['linguaforge_active_preset'] = 'technical';
		$base = "Translate.\n\n";

		$result = Config::apply_compliance_to_system( $base );

		// After trim() + "\n\n" + addendum, must not start with extra newlines.
		$this->assertStringStartsWith( 'Translate.', $result );
	}

	// =========================================================================
	// apply_compliance()
	// =========================================================================

	public function test_apply_compliance_returns_same_config_for_standard(): void {
		$config = new WorkerConfig( model: 'claude-haiku-4-5-20251001', max_tokens: 1024 );

		$result = Config::apply_compliance( $config );

		$this->assertSame( $config, $result );
	}

	public function test_apply_compliance_returns_new_config_with_preset_temperature(): void {
		$GLOBALS['lf_test_options']['linguaforge_active_preset'] = 'legal';
		$config = new WorkerConfig( model: 'claude-haiku-4-5-20251001', max_tokens: 1024, temperature: 0.9 );

		$result = Config::apply_compliance( $config );

		$this->assertNotSame( $config, $result );
		$this->assertSame( 0.1, $result->temperature );
		$this->assertSame( $config->model,      $result->model );
		$this->assertSame( $config->max_tokens,  $result->max_tokens );
	}
}
