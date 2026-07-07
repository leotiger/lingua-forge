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
		$expected = sanitize_key( Context::lang_from_locale( get_locale() ) );
		$this->assertSame( $expected, $this->ctx()->source_language() );
	}

	public function test_source_language_returns_stored_value(): void {
		update_option( 'linguaforge_primary_language', 'en', false );
		$this->assertSame( 'en', $this->ctx()->source_language() );
	}

	public function test_source_language_falls_back_to_wp_locale_when_option_is_empty(): void {
		// Empty stored value → falls back to the WordPress site locale (first two chars).
		update_option( 'linguaforge_primary_language', '', false );
		$expected = sanitize_key( Context::lang_from_locale( get_locale() ) );
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
			// Most lang codes are exactly 2 characters, but WordPress's own
			// locale registry has bare 3-letter-only codes for languages with
			// no ISO 639-1 code of their own (e.g. "yor" Yoruba) — see
			// Context::lang_from_locale(). A hardcoded assertSame(2, ...) here
			// would fail the moment such a locale is installed; >= 2 (never
			// empty) is the actual invariant.
			$this->assertGreaterThanOrEqual( 2, strlen( $lang ), "Language code '{$lang}' must be at least two characters." );
		}
	}

	/**
	 * Confirmed live bug: languages() used to derive every lang code —
	 * including the WP site locale itself (get_locale(), always added to the
	 * candidate list) — by truncating to the first two characters, which
	 * silently mangled any bare 3-letter-only WordPress locale (e.g. "sah"
	 * Sakha truncated to "sa", Sanskrit's real code). Context::lang_from_locale()
	 * now keeps such locales whole.
	 *
	 * Exercised via the core 'locale' filter (get_locale()'s own extension
	 * point) rather than a real installed locale pack, since wp-env's test
	 * environment has no 3-letter-locale pack available to switch to. Uses a
	 * named callback so only this test's override is removed afterward —
	 * removing ALL 'locale' filters would also strip
	 * LocaleDetector::filter_locale(), which is registered for the lifetime
	 * of the test process and other integration tests depend on.
	 */
	public function test_languages_keeps_bare_three_letter_wp_locale_whole(): void {
		// Priority 20 so this override wins over LocaleDetector::filter_locale(),
		// which is registered at priority 0.
		$override = static fn(): string => 'yor';
		add_filter( 'locale', $override, 20 );

		try {
			$langs = $this->ctx()->languages();
		} finally {
			remove_filter( 'locale', $override, 20 );
		}

		$this->assertContains( 'yor', $langs );
		$this->assertNotContains( 'yo', $langs );
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

	/**
	 * A router language whose lang code is one of WordPress's bare
	 * 3-letter-only locale slugs (e.g. 'yor' for Yoruba) must still be
	 * auto-detected when the browser correctly reports the real ISO 639-1
	 * code ('yo') rather than WordPress's own slug — 'yo' itself never
	 * appears in $langs, only 'yor' does, so this only works via
	 * Context::iso_639_1_from_lang()'s reverse match.
	 */
	public function test_detect_browser_lang_matches_iso_639_1_code_to_bare_three_letter_locale(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'yo,en;q=0.8';
		$this->assertSame( 'yor', $this->ctx()->detect_browser_lang( [ 'en', 'yor' ] ) );
	}

	/**
	 * Same as above but with a regional variant of the ISO code (e.g. a
	 * Nigerian browser locale) — the hyphen-splitting must not assume the
	 * primary subtag is exactly two characters.
	 */
	public function test_detect_browser_lang_matches_iso_639_1_regional_variant(): void {
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'yo-NG,en;q=0.8';
		$this->assertSame( 'yor', $this->ctx()->detect_browser_lang( [ 'en', 'yor' ] ) );
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
