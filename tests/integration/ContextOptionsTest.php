<?php
/**
 * Integration tests for LinguaForge\Router\Context.
 *
 * Context has no constructor — new Context() is clean and stateless until
 * the first method call. Each test creates a fresh instance so cached
 * instance properties never carry state across test cases.
 *
 * Covers:
 *   • source_language()      — reads linguaforge_primary_language option.
 *   • routing_mode()         — reads linguaforge_routing_mode option.
 *   • languages()            — always includes the source language; returns
 *                              unique codes; respects lf_languages_list filter.
 *   • is_valid_lang()        — true for known codes; false for unknown.
 *   • detect_browser_lang()  — Accept-Language parsing (exact, regional,
 *                              quality-ordered, no-match, empty-header paths).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use WP_UnitTestCase;

final class ContextOptionsTest extends WP_UnitTestCase {

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Always returns a fresh, uncached Context instance. */
	private function ctx(): Context {
		return new Context();
	}

	protected function tearDown(): void {
		// Restore HTTP_ACCEPT_LANGUAGE so browser-lang tests don't bleed.
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		// Remove any lf_* filters added during tests.
		remove_all_filters( 'lf_languages_list' );
		remove_all_filters( 'lf_primary_language' );
		parent::tearDown();
	}

	// ── source_language() ─────────────────────────────────────────────────────

	public function test_source_language_returns_ca_when_option_absent(): void {
		delete_option( 'linguaforge_primary_language' );
		$this->assertSame( 'ca', $this->ctx()->source_language() );
	}

	public function test_source_language_returns_stored_value(): void {
		update_option( 'linguaforge_primary_language', 'en', false );
		$this->assertSame( 'en', $this->ctx()->source_language() );
	}

	public function test_source_language_falls_back_to_ca_when_option_is_empty(): void {
		// sanitize_key( '' ) → '', and '' ?: 'ca' falls back to 'ca'.
		update_option( 'linguaforge_primary_language', '', false );
		$this->assertSame( 'ca', $this->ctx()->source_language() );
	}

	public function test_source_language_is_cached_on_repeated_calls(): void {
		update_option( 'linguaforge_primary_language', 'de', false );
		$ctx = $this->ctx();

		$first  = $ctx->source_language();
		// Change option — cached value must not change within same instance.
		update_option( 'linguaforge_primary_language', 'fr', false );
		$second = $ctx->source_language();

		$this->assertSame( $first, $second );
	}

	// ── routing_mode() ────────────────────────────────────────────────────────

	public function test_routing_mode_returns_path_when_option_absent(): void {
		delete_option( 'linguaforge_routing_mode' );
		$this->assertSame( 'path', $this->ctx()->routing_mode() );
	}

	public function test_routing_mode_returns_subdomain_when_stored(): void {
		update_option( 'linguaforge_routing_mode', 'subdomain', false );
		$this->assertSame( 'subdomain', $this->ctx()->routing_mode() );
	}

	public function test_routing_mode_returns_path_when_stored_path(): void {
		update_option( 'linguaforge_routing_mode', 'path', false );
		$this->assertSame( 'path', $this->ctx()->routing_mode() );
	}

	public function test_routing_mode_is_cached_on_repeated_calls(): void {
		update_option( 'linguaforge_routing_mode', 'subdomain', false );
		$ctx = $this->ctx();

		$first = $ctx->routing_mode();
		update_option( 'linguaforge_routing_mode', 'path', false );
		$second = $ctx->routing_mode();

		$this->assertSame( $first, $second );
	}

	// ── languages() / is_valid_lang() ────────────────────────────────────────

	public function test_languages_always_includes_source_language(): void {
		update_option( 'linguaforge_primary_language', 'de', false );
		$langs = $this->ctx()->languages();
		$this->assertContains( 'de', $langs );
	}

	public function test_languages_returns_unique_codes(): void {
		$langs = $this->ctx()->languages();
		$this->assertSame( $langs, array_values( array_unique( $langs ) ) );
	}

	public function test_languages_returns_indexed_array_of_strings(): void {
		$langs = $this->ctx()->languages();
		$this->assertIsArray( $langs );
		foreach ( $langs as $lang ) {
			$this->assertIsString( $lang );
			$this->assertSame( 2, strlen( $lang ), "Language code '{$lang}' must be two characters." );
		}
	}

	public function test_languages_respects_lf_languages_list_filter(): void {
		add_filter( 'lf_languages_list', fn() => [ 'xx', 'yy', 'zz' ] );
		$langs = $this->ctx()->languages();
		$this->assertSame( [ 'xx', 'yy', 'zz' ], $langs );
	}

	public function test_is_valid_lang_returns_true_for_source_language(): void {
		update_option( 'linguaforge_primary_language', 'ca', false );
		$this->assertTrue( $this->ctx()->is_valid_lang( 'ca' ) );
	}

	public function test_is_valid_lang_returns_false_for_unknown_code(): void {
		// 'zz' is not a real locale code and won't appear in any language list.
		$this->assertFalse( $this->ctx()->is_valid_lang( 'zz' ) );
	}

	public function test_is_valid_lang_returns_true_for_filter_injected_code(): void {
		add_filter( 'lf_languages_list', fn( array $langs ) => array_merge( $langs, [ 'xx' ] ) );
		$this->assertTrue( $this->ctx()->is_valid_lang( 'xx' ) );
	}

	public function test_is_valid_lang_is_strict_type_check(): void {
		// Passing a non-string must not match any language.
		$this->assertFalse( $this->ctx()->is_valid_lang( null ) );
		$this->assertFalse( $this->ctx()->is_valid_lang( 42 ) );
	}

	// ── detect_browser_lang() ────────────────────────────────────────────────

	public function test_detect_browser_lang_returns_empty_when_no_header(): void {
		unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
		$this->assertSame( '', $this->ctx()->detect_browser_lang( [ 'de', 'en', 'ca' ] ) );
	}

	public function test_detect_browser_lang_returns_empty_when_header_is_empty_string(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
		$this->assertSame( '', $this->ctx()->detect_browser_lang( [ 'de', 'en', 'ca' ] ) );
	}

	public function test_detect_browser_lang_exact_two_char_match(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,en;q=0.9,ca;q=0.8';
		$this->assertSame( 'de', $this->ctx()->detect_browser_lang( [ 'ca', 'de', 'en' ] ) );
	}

	public function test_detect_browser_lang_regional_tag_maps_to_prefix(): void {
		// 'de-DE' and 'de-AT' should both match the 'de' entry.
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,en;q=0.8';
		$this->assertSame( 'de', $this->ctx()->detect_browser_lang( [ 'ca', 'de', 'en' ] ) );
	}

	public function test_detect_browser_lang_zh_tw_regional_tag_maps_to_zh(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zh-TW,en;q=0.5';
		$this->assertSame( 'zh', $this->ctx()->detect_browser_lang( [ 'zh', 'en' ] ) );
	}

	public function test_detect_browser_lang_respects_quality_ordering(): void {
		// 'ca' has higher q than 'de' — must return 'ca'.
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de;q=0.5,ca;q=0.9';
		$this->assertSame( 'ca', $this->ctx()->detect_browser_lang( [ 'ca', 'de' ] ) );
	}

	public function test_detect_browser_lang_falls_through_to_second_choice(): void {
		// 'fr' is not in the known list — must continue to 'ca'.
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr,ca;q=0.8';
		$this->assertSame( 'ca', $this->ctx()->detect_browser_lang( [ 'ca', 'de' ] ) );
	}

	public function test_detect_browser_lang_returns_empty_when_no_match(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr,ja;q=0.8,it;q=0.5';
		$this->assertSame( '', $this->ctx()->detect_browser_lang( [ 'ca', 'de', 'en' ] ) );
	}

	public function test_detect_browser_lang_returns_empty_for_empty_langs_array(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,en;q=0.9';
		$this->assertSame( '', $this->ctx()->detect_browser_lang( [] ) );
	}

	public function test_detect_browser_lang_default_quality_is_1_0(): void {
		// 'de' has no explicit q, defaults to 1.0; 'ca' has q=0.9 — 'de' wins.
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,ca;q=0.9';
		$this->assertSame( 'de', $this->ctx()->detect_browser_lang( [ 'ca', 'de' ] ) );
	}
}
