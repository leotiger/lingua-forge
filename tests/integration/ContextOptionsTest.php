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

	public function test_source_language_falls_back_to_wp_locale_when_option_absent(): void {
		delete_option( 'linguaforge_primary_language' );
		$expected = sanitize_key( substr( get_locale(), 0, 2 ) );
		$this->assertSame( $expected, $this->ctx()->source_language() );
	}

	public function test_source_language_returns_stored_value(): void {
		update_option( 'linguaforge_primary_language', 'en', false );
		$this->assertSame( 'en', $this->ctx()->source_language() );
	}

	public function test_source_language_falls_back_to_wp_locale_when_option_is_empty(): void {
		// Empty stored value → falls back to the WordPress site locale (first two chars).
		update_option( 'linguaforge_primary_language', '', false );
		$expected = sanitize_key( substr( get_locale(), 0, 2 ) );
		$this->assertSame( $expected, $this->ctx()->source_language() );
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

	// ── Subdomain routing — §6.0.1 Medium (class-context.php, 52%) ───────────

	/**
	 * lang_base_url() must return https://{lang}.{base_domain}/ in subdomain mode
	 * for a non-source language.
	 */
	public function test_lang_base_url_returns_subdomain_url_in_subdomain_mode(): void {
		update_option( 'linguaforge_routing_mode',     'subdomain', false );
		update_option( 'linguaforge_primary_language', 'en',        false );
		add_filter( 'lf_base_domain',    static fn() => 'example.org' );
		add_filter( 'lf_languages_list', static fn( array $l ): array => array_values( array_unique( array_merge( $l, [ 'de' ] ) ) ) );

		$result = $this->ctx()->lang_base_url( 'de' );

		remove_all_filters( 'lf_base_domain' );
		remove_all_filters( 'lf_languages_list' );

		$this->assertStringContainsString( 'de.example.org', $result, 'lang_base_url() must include {lang}.{base_domain} in subdomain mode.' );
		$this->assertStringEndsWith( '/', $result, 'lang_base_url() must end with a trailing slash.' );
	}

	/**
	 * lang_base_url() must return home_url('/') for the source language in
	 * subdomain mode — source content lives at the root domain, not a subdomain.
	 */
	public function test_lang_base_url_returns_home_url_for_source_language_in_subdomain_mode(): void {
		update_option( 'linguaforge_routing_mode',     'subdomain', false );
		update_option( 'linguaforge_primary_language', 'en',        false );
		add_filter( 'lf_base_domain', static fn() => 'example.org' );

		$result = $this->ctx()->lang_base_url( 'en' );

		remove_all_filters( 'lf_base_domain' );

		$this->assertSame( home_url( '/' ), $result, 'lang_base_url() must return home_url("/") for the source language.' );
	}

	/**
	 * detect_lang() must resolve the language from the HTTP_HOST subdomain
	 * when subdomain routing mode is active.
	 *
	 * Simulates a request to de.example.org by setting $_SERVER['HTTP_HOST']
	 * and filtering lf_base_domain to the apex domain.
	 */
	public function test_detect_lang_reads_subdomain_in_subdomain_mode(): void {
		update_option( 'linguaforge_routing_mode',     'subdomain', false );
		update_option( 'linguaforge_primary_language', 'en',        false );

		$_SERVER['HTTP_HOST'] = 'de.example.org'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test-only assignment to simulate a subdomain request.

		add_filter( 'lf_base_domain',    static fn() => 'example.org' );
		add_filter( 'lf_languages_list', static fn( array $l ): array => array_values( array_unique( array_merge( $l, [ 'de' ] ) ) ) );

		$result = $this->ctx()->detect_lang();

		remove_all_filters( 'lf_base_domain' );
		remove_all_filters( 'lf_languages_list' );
		unset( $_SERVER['HTTP_HOST'] );

		$this->assertSame( 'de', $result, 'detect_lang() must return the subdomain language code in subdomain routing mode.' );
	}
}
