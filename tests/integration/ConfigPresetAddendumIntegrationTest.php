<?php
/**
 * Integration tests for Config::preset_addendum() and
 * Config::apply_compliance_to_system().
 *
 * These tests require a live WordPress install (wp-env) because both methods
 * call get_option() / update_option() / get_post_meta() under the hood.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\Config;
use WP_UnitTestCase;

final class ConfigPresetAddendumIntegrationTest extends WP_UnitTestCase {

	// ── Housekeeping ──────────────────────────────────────────────────────────

	/**
	 * WP_UnitTestCase rolls back all option and post-meta changes between
	 * tests via a DB transaction, so no explicit tearDown is needed.
	 */

	// ── preset_addendum — stored override ─────────────────────────────────────

	public function test_preset_addendum_returns_stored_value_when_set(): void {

		update_option( 'linguaforge_preset_addendum_legal', 'Custom legal text.', false );

		$this->assertSame( 'Custom legal text.', Config::preset_addendum( 'legal' ) );
	}

	public function test_preset_addendum_trims_stored_value(): void {

		update_option( 'linguaforge_preset_addendum_legal', '  trimmed  ', false );

		$this->assertSame( 'trimmed', Config::preset_addendum( 'legal' ) );
	}

	public function test_preset_addendum_falls_back_to_default_when_stored_is_empty(): void {

		update_option( 'linguaforge_preset_addendum_legal', '', false );

		$this->assertSame(
			Config::LEGAL_ADDENDUM_DEFAULT,
			Config::preset_addendum( 'legal' )
		);
	}

	public function test_preset_addendum_falls_back_to_default_when_stored_is_whitespace_only(): void {

		update_option( 'linguaforge_preset_addendum_technical', '   ', false );

		$this->assertSame(
			Config::TECHNICAL_ADDENDUM_DEFAULT,
			Config::preset_addendum( 'technical' )
		);
	}

	public function test_preset_addendum_falls_back_to_default_when_option_absent(): void {

		delete_option( 'linguaforge_preset_addendum_creative' );

		$this->assertSame(
			Config::CREATIVE_ADDENDUM_DEFAULT,
			Config::preset_addendum( 'creative' )
		);
	}

	public function test_preset_addendum_returns_empty_string_for_standard(): void {

		// Standard has no addendum regardless of any stored option.
		$this->assertSame( '', Config::preset_addendum( 'standard' ) );
	}

	// ── apply_compliance_to_system ────────────────────────────────────────────

	public function test_apply_compliance_returns_unchanged_prompt_for_standard_preset(): void {

		update_option( 'linguaforge_active_preset', 'standard', false );

		$base   = 'You are a helpful assistant.';
		$result = Config::apply_compliance_to_system( $base );

		$this->assertSame( $base, $result );
	}

	public function test_apply_compliance_appends_default_addendum_for_legal_preset(): void {

		update_option( 'linguaforge_active_preset', 'legal', false );
		delete_option( 'linguaforge_preset_addendum_legal' );

		$base   = 'You are a helpful assistant.';
		$result = Config::apply_compliance_to_system( $base );

		$this->assertStringContainsString( $base,                        $result );
		$this->assertStringContainsString( Config::LEGAL_ADDENDUM_DEFAULT, $result );
		$this->assertStringContainsString( "\n\n",                        $result );
	}

	public function test_apply_compliance_appends_stored_addendum_when_set(): void {

		update_option( 'linguaforge_active_preset',          'technical',          false );
		update_option( 'linguaforge_preset_addendum_technical', 'Custom tech rules.', false );

		$base   = 'Base prompt.';
		$result = Config::apply_compliance_to_system( $base );

		$this->assertStringEndsWith( "\n\nCustom tech rules.", $result );
	}

	public function test_apply_compliance_returns_unchanged_when_addendum_is_empty(): void {

		// A hypothetical future preset that has no built-in addendum and
		// no stored override — apply_compliance_to_system must pass through.
		update_option( 'linguaforge_active_preset',            'standard', false );
		update_option( 'linguaforge_preset_addendum_standard', '',         false );

		$base   = 'Base prompt.';
		$result = Config::apply_compliance_to_system( $base );

		$this->assertSame( $base, $result );
	}

	public function test_apply_compliance_trims_base_prompt_before_appending(): void {

		update_option( 'linguaforge_active_preset', 'legal', false );
		delete_option( 'linguaforge_preset_addendum_legal' );

		$base   = "Base prompt with trailing space.  ";
		$result = Config::apply_compliance_to_system( $base );

		$this->assertStringStartsWith( 'Base prompt with trailing space.', $result );
		// Must not have double whitespace between base and addendum.
		$this->assertStringNotContainsString( "  \n\n", $result );
	}

	public function test_apply_compliance_respects_per_page_preset_over_global(): void {

		update_option( 'linguaforge_active_preset', 'standard', false );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_linguaforge_preset', 'legal' );
		delete_option( 'linguaforge_preset_addendum_legal' );

		$base   = 'You are a helpful assistant.';
		$result = Config::apply_compliance_to_system( $base, $post_id );

		$this->assertStringContainsString( Config::LEGAL_ADDENDUM_DEFAULT, $result );
	}
}
