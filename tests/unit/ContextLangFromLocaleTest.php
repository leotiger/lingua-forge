<?php
/**
 * Unit tests for LinguaForge\Router\Context::lang_from_locale().
 *
 * A pure static helper — no WordPress runtime needed beyond the class file
 * loading successfully (ABSPATH must be defined for its `defined( 'ABSPATH' )
 * || exit;` guard, but the method itself calls no WP functions).
 *
 * Covers the fix for a confirmed live bug: Context::languages() used to
 * derive every "lang" code by blindly truncating a WordPress locale string
 * to its first two characters. That's correct for "xx" and "xx_YY" locales,
 * but WordPress's own locale registry also has roughly two dozen bare
 * THREE-character-only locale codes for languages with no ISO 639-1 code of
 * their own (Yoruba "yor", Sorani Kurdish "ckb", Lower Sorbian "dsb", Sakha
 * "sah", and others) — truncating those produces either a coincidentally
 * "lucky" match (yor → yo, which happens to equal Yoruba's real ISO code) or
 * an outright wrong one (sah → sa, which is Sanskrit). Either way, the
 * reverse lookup in LocaleDetector::locale_from_lang() can never find the
 * real 3-letter locale again from its truncated form, which is what made a
 * live Yoruba install permanently un-uninstallable: it kept showing up as an
 * active language, but nothing could resolve it back to a real, loadable
 * WordPress locale.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Context;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-context.php';

/**
 * @covers \LinguaForge\Router\Context::lang_from_locale
 */
final class ContextLangFromLocaleTest extends TestCase {

	// =========================================================================
	// Locales with an underscore — segment before the first underscore
	// =========================================================================

	public function test_two_letter_plus_region_locale(): void {
		$this->assertSame( 'de', Context::lang_from_locale( 'de_DE' ) );
	}

	public function test_chinese_region_locale(): void {
		$this->assertSame( 'zh', Context::lang_from_locale( 'zh_CN' ) );
	}

	public function test_locale_with_multiple_underscores_uses_first_segment(): void {
		// "pt_PT_ao90" and "de_CH_informal" — only the first segment is the
		// language subtag; everything after is a variant/orthography suffix.
		$this->assertSame( 'pt', Context::lang_from_locale( 'pt_PT_ao90' ) );
		$this->assertSame( 'de', Context::lang_from_locale( 'de_CH_informal' ) );
	}

	// =========================================================================
	// Bare locales with no underscore — returned unchanged
	// =========================================================================

	public function test_bare_two_letter_locale_is_unchanged(): void {
		$this->assertSame( 'ca', Context::lang_from_locale( 'ca' ) );
		$this->assertSame( 'ja', Context::lang_from_locale( 'ja' ) );
	}

	/**
	 * The core fix: WordPress's bare 3-letter locale slugs must be kept
	 * whole, not truncated to their first two characters.
	 */
	public function test_bare_three_letter_locale_is_kept_whole(): void {
		$this->assertSame( 'yor', Context::lang_from_locale( 'yor' ) );
	}

	public function test_other_bare_three_letter_locales_are_kept_whole(): void {
		// A sample of WordPress's other bare 3-letter-only locales, to
		// confirm this isn't a special case hardcoded for Yoruba alone.
		$this->assertSame( 'ckb', Context::lang_from_locale( 'ckb' ) ); // Sorani Kurdish
		$this->assertSame( 'dsb', Context::lang_from_locale( 'dsb' ) ); // Lower Sorbian
		$this->assertSame( 'sah', Context::lang_from_locale( 'sah' ) ); // Sakha — old logic wrongly produced "sa" (Sanskrit's real code)
	}

	// =========================================================================
	// Normalisation
	// =========================================================================

	public function test_result_is_lowercased(): void {
		$this->assertSame( 'de', Context::lang_from_locale( 'DE_DE' ) );
		$this->assertSame( 'yor', Context::lang_from_locale( 'YOR' ) );
	}

	public function test_empty_string_returns_empty_string(): void {
		$this->assertSame( '', Context::lang_from_locale( '' ) );
	}

	// =========================================================================
	// iso_639_1_from_lang()
	//
	// Outbound-facing normalisation (hreflang, og:locale, ICU display names,
	// browser Accept-Language matching) for the handful of WordPress bare
	// 3-letter locale slugs that DO have a real ISO 639-1 equivalent. The
	// internal lang code from lang_from_locale() above is never changed by
	// this — it's a separate, purpose-specific lookup.
	// =========================================================================

	public function test_yoruba_normalises_to_yo(): void {
		$this->assertSame( 'yo', Context::iso_639_1_from_lang( 'yor' ) );
	}

	public function test_other_confirmed_mappings(): void {
		// Verified against https://en.wikipedia.org/wiki/List_of_ISO_639_language_codes
		$this->assertSame( 'an', Context::iso_639_1_from_lang( 'arg' ) ); // Aragonese
		$this->assertSame( 'be', Context::iso_639_1_from_lang( 'bel' ) ); // Belarusian
		$this->assertSame( 'dz', Context::iso_639_1_from_lang( 'dzo' ) ); // Dzongkha
		$this->assertSame( 'ky', Context::iso_639_1_from_lang( 'kir' ) ); // Kyrgyz
		$this->assertSame( 'oc', Context::iso_639_1_from_lang( 'oci' ) ); // Occitan
		$this->assertSame( 'sd', Context::iso_639_1_from_lang( 'snd' ) ); // Sindhi
		$this->assertSame( 'ty', Context::iso_639_1_from_lang( 'tah' ) ); // Tahitian
	}

	/**
	 * Locales deliberately NOT mapped: either they have no real ISO 639-1
	 * code of their own (ckb, dsb, sah), or mapping them onto a related
	 * macrolanguage code would silently merge two distinct languages (ary/az
	 * onto Arabic's "ar", azb onto Azerbaijani's "az" — both of which
	 * WordPress already uses for a different, more general locale).
	 */
	public function test_locales_with_no_safe_iso_639_1_equivalent_are_unchanged(): void {
		$this->assertSame( 'ckb', Context::iso_639_1_from_lang( 'ckb' ) );
		$this->assertSame( 'dsb', Context::iso_639_1_from_lang( 'dsb' ) );
		$this->assertSame( 'sah', Context::iso_639_1_from_lang( 'sah' ) );
		$this->assertSame( 'ary', Context::iso_639_1_from_lang( 'ary' ) );
		$this->assertSame( 'azb', Context::iso_639_1_from_lang( 'azb' ) );
	}

	public function test_ordinary_two_letter_lang_is_unchanged(): void {
		$this->assertSame( 'de', Context::iso_639_1_from_lang( 'de' ) );
	}
}
