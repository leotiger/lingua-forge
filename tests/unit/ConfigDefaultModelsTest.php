<?php
/**
 * Unit tests for Config::default_model() and Config::all_model_defaults().
 *
 * Both methods are pure — they read from a private class constant with no
 * WordPress dependencies — so they belong in the unit suite.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\Config;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/Config.php';

final class ConfigDefaultModelsTest extends TestCase {

	// ── default_model — known providers ──────────────────────────────────────

	public function test_default_model_returns_anthropic_light(): void {
		$this->assertSame( 'claude-haiku-4-5-20251001', Config::default_model( 'anthropic', 'light' ) );
	}

	public function test_default_model_returns_anthropic_quality(): void {
		$this->assertSame( 'claude-sonnet-4-6', Config::default_model( 'anthropic', 'quality' ) );
	}

	public function test_default_model_returns_openai_light(): void {
		$this->assertSame( 'gpt-4o-mini', Config::default_model( 'openai', 'light' ) );
	}

	public function test_default_model_returns_openai_quality(): void {
		$this->assertSame( 'gpt-4o', Config::default_model( 'openai', 'quality' ) );
	}

	public function test_default_model_returns_gemini_light(): void {
		$this->assertSame( 'gemini-2.5-flash-lite', Config::default_model( 'gemini', 'light' ) );
	}

	public function test_default_model_returns_gemini_quality(): void {
		$this->assertSame( 'gemini-2.5-flash', Config::default_model( 'gemini', 'quality' ) );
	}

	// ── default_model — unknown inputs ───────────────────────────────────────

	public function test_default_model_returns_empty_string_for_unknown_provider(): void {
		$this->assertSame( '', Config::default_model( 'unknown_provider', 'light' ) );
	}

	public function test_default_model_returns_empty_string_for_unknown_tier(): void {
		$this->assertSame( '', Config::default_model( 'anthropic', 'ultra' ) );
	}

	public function test_default_model_returns_empty_string_for_both_unknown(): void {
		$this->assertSame( '', Config::default_model( 'nonexistent', 'nonexistent' ) );
	}

	// ── all_model_defaults — structure ───────────────────────────────────────

	public function test_all_model_defaults_contains_all_three_providers(): void {
		$defaults = Config::all_model_defaults();

		$this->assertArrayHasKey( 'anthropic', $defaults );
		$this->assertArrayHasKey( 'openai',    $defaults );
		$this->assertArrayHasKey( 'gemini',    $defaults );
	}

	public function test_all_model_defaults_each_provider_has_light_and_quality_tiers(): void {
		foreach ( Config::all_model_defaults() as $provider => $tiers ) {
			$this->assertArrayHasKey( 'light',   $tiers, "Provider '{$provider}' missing 'light' tier." );
			$this->assertArrayHasKey( 'quality', $tiers, "Provider '{$provider}' missing 'quality' tier." );
		}
	}

	public function test_all_model_defaults_all_values_are_non_empty_strings(): void {
		foreach ( Config::all_model_defaults() as $provider => $tiers ) {
			foreach ( $tiers as $tier => $model ) {
				$this->assertIsString( $model, "{$provider}/{$tier} must be a string." );
				$this->assertNotEmpty( $model,  "{$provider}/{$tier} model string must not be empty." );
			}
		}
	}

	// ── consistency: all_model_defaults mirrors individual default_model calls ─

	public function test_all_model_defaults_matches_individual_default_model_calls(): void {
		foreach ( Config::all_model_defaults() as $provider => $tiers ) {
			foreach ( $tiers as $tier => $expected ) {
				$this->assertSame(
					$expected,
					Config::default_model( $provider, $tier ),
					"all_model_defaults()['{$provider}']['{$tier}'] diverges from default_model('{$provider}', '{$tier}')."
				);
			}
		}
	}
}
