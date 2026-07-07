<?php
/**
 * Class LinguaForge\Router\LocaleDetector
 *
 * Resolves a two-char language code to a WordPress locale string and
 * applies the correct locale for the current request.
 */

namespace LinguaForge\Router;

use WP_Locale_Switcher;

if ( ! defined( 'ABSPATH' ) ) exit;

class LocaleDetector {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_action( 'plugins_loaded',    [ $this, 'apply_locale' ], 0 );
		add_filter( 'determine_locale',  [ $this, 'filter_determine_locale' ], 0 );
		add_filter( 'locale',            [ $this, 'filter_locale' ], 0 );
		// Late locale override for singular posts whose _lf_lang differs from
		// LF_LANG (e.g. WC products served from a language-neutral /product/ URL
		// always have LF_LANG=source, even when the product itself is in another
		// language).  Fires after the main query resolves so the queried object
		// is available.  switch_to_locale() reloads all text domains, fixing WC
		// tab labels, "Add to cart", breadcrumb "Home", etc.
		add_action( 'wp', [ $this, 'maybe_switch_locale_for_post' ], 1 );
	}

	// =========================================================
	// LOCALE RESOLUTION
	// =========================================================

	public function locale_from_lang( string $lang ): string {
		static $cache = [];

		// Normalise first so the cache key is always lowercase.
		$lang = strtolower( $lang );

		if ( isset( $cache[$lang] ) ) return $cache[$lang];

		// 1. Hard overrides
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$force = apply_filters( 'lf_lang_force_locale', [
			'ca' => 'ca',
		] );

		if ( isset( $force[$lang] ) ) {
			return $cache[$lang] = $force[$lang];
		}

		// 2. Installed WP language packs + plugin-bundled locales
		$known_locales = array_merge( get_available_languages(), $this->router->context->discover_plugin_locales() );
		foreach ( $known_locales as $locale ) {
			$locale_l = strtolower( $locale );
			if ( $locale_l === $lang || str_starts_with( $locale_l, $lang . '_' ) ) {
				return $cache[$lang] = $locale;
			}
		}

		// 3. Fallback map — extend via the filter for custom or regional variants.
		//    'pt' defaults to pt_PT (Portugal); override with 'pt' => 'pt_BR' if needed.
		//
		//    Every language this plugin ships its own UI translation for (see
		//    languages/lingua-forge-*.po) must have an entry here, even when no
		//    WordPress core language pack for it is installed — without one, an
		//    unmapped code falls through to step 4's 'en_US' default and becomes
		//    indistinguishable from English to every caller that compares locale
		//    strings (e.g. the admin-bar language switcher's active-language
		//    check in class-meta-boxes.php, or filter_locale()/apply_locale()
		//    for front-end string translations). hi/ur/th/sw/km/eu were missing
		//    despite being bundled languages.
		//
		//    NOTE: this map intentionally does NOT need a 'yo' entry for Yoruba.
		//    An earlier version of this fix added 'yo' => 'yo' on the mistaken
		//    assumption that WordPress's locale for Yoruba is 'yo' — it's
		//    actually the bare 3-letter 'yor' (see Context::lang_from_locale()).
		//    Since Context::languages() now derives the lang code from the FULL
		//    locale instead of truncating to 2 characters, a real Yoruba install
		//    produces the lang code 'yor', which step 2 above already resolves
		//    correctly via a direct match against get_available_languages() —
		//    no fallback entry needed. A stray 'yo' => 'yo' entry here would be
		//    actively wrong: 'yo' is not an installable WordPress locale, so
		//    switch_to_locale('yo') would silently fail to load anything.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$fallback_map = apply_filters( 'lf_lang_fallback_map', [
			'ca' => 'ca',
			'en' => 'en_US',
			'es' => 'es_ES',
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'it' => 'it_IT',
			'pt' => 'pt_PT',
			'nl' => 'nl_NL',
			'pl' => 'pl_PL',
			'ru' => 'ru_RU',
			'sv' => 'sv_SE',
			'da' => 'da_DK',
			'nb' => 'nb_NO',
			'ro' => 'ro_RO',
			'hu' => 'hu_HU',
			'cs' => 'cs_CZ',
			'tr' => 'tr_TR',
			'el' => 'el',
			'ja' => 'ja',
			'zh' => 'zh_CN',
			'ko' => 'ko_KR',
			'ar' => 'ar',
			'he' => 'he_IL',
			'fa' => 'fa_IR',
			'id' => 'id_ID',
			'hi' => 'hi_IN',
			'ur' => 'ur',
			'th' => 'th',
			'sw' => 'sw',
			'km' => 'km',
			'eu' => 'eu',
		] );

		if ( isset( $fallback_map[$lang] ) ) {
			return $cache[$lang] = $fallback_map[$lang];
		}

		// 4. Default
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $cache[$lang] = apply_filters( 'lf_lang_default_fallback', 'en_US' );
	}

	public function language_label( string $lang ): string {
		if ( function_exists( 'locale_get_display_language' ) ) {
			// ICU/CLDR (which backs PHP's intl functions) generally doesn't
			// recognise WordPress's own bare 3-letter locale slugs for
			// languages that have a real ISO 639-1 code (e.g. "yor" for
			// Yoruba) — it expects the ISO 639-1 form ("yo") and silently
			// echoes an unrecognised identifier back unlabelled rather than
			// producing a real display name, so normalise via
			// Context::iso_639_1_from_lang() before asking ICU. This is a
			// display-only concern: locale_from_lang()'s own real, installable
			// WordPress locale is used everywhere else (loading translation
			// files, switch_to_locale(), etc.) and is untouched by this.
			$display_locale = Context::iso_639_1_from_lang( $lang );
			$label           = locale_get_display_language( $display_locale, $display_locale );
			return mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
		}

		return strtoupper( $lang );
	}

	// =========================================================
	// LOCALE HOOKS
	// =========================================================

	/**
	 * After the main query resolves, switch locale to match the queried post's
	 * _lf_lang when it differs from the URL-derived LF_LANG.
	 *
	 * Covers singular CPT posts (e.g. WC products) whose URLs carry no language
	 * prefix, so LF_LANG is always the source language regardless of which
	 * language the individual post is in.
	 */
	public function maybe_switch_locale_for_post(): void {
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;
		if ( ! is_singular() ) return;

		$post = get_queried_object();
		if ( ! ( $post instanceof \WP_Post ) ) return;

		$post_lang = get_post_meta( $post->ID, '_lf_lang', true );
		if ( ! $post_lang || $post_lang === LF_LANG ) return;

		$locale = $this->locale_from_lang( $post_lang );
		if ( $locale && $locale !== get_locale() ) {
			switch_to_locale( $locale );
		}
	}

	public function apply_locale(): void {
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $wp_locale_switcher is WordPress core's own global; we are initialising it defensively, not defining a plugin variable.
		if ( ! isset( $GLOBALS['wp_locale_switcher'] ) ) {
			$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
			$GLOBALS['wp_locale_switcher']->init();
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		$locale = $this->locale_from_lang( LF_LANG );
		if ( $locale !== get_locale() ) {
			switch_to_locale( $locale );
		}
	}

	public function filter_determine_locale( string $locale ): string {
		if ( ! defined( 'LF_LANG' ) ) return $locale;

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) return $locale;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $locale;

		// 1. REQUEST (AJAX or manual)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads REQUEST parameters for routing; not form processing.
		if ( ! empty( $_REQUEST['lang'] ) && $this->router->context->is_valid_lang( sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads REQUEST parameters for routing; not form processing.
			return $this->locale_from_lang( sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) );
		}

		// 2. Frontend LF_LANG
		if ( ! empty( LF_LANG ) ) {
			return $this->locale_from_lang( LF_LANG );
		}

		// 3. Cookie fallback
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
		if ( ! empty( $_COOKIE['lf_lang'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
			$lang = substr( strtolower( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ) ) ), 0, 2 );
			if ( $this->router->context->is_valid_lang( $lang ) ) {
				return $this->locale_from_lang( $lang );
			}
		}

		return $locale;
	}

	/**
	 * Enforce the active frontend locale on the `locale` filter.
	 *
	 * Some third-party plugins (e.g. booking or e-commerce plugins) read the
	 * `locale` filter directly instead of `determine_locale`, bypassing
	 * `filter_determine_locale()`. This callback ensures they receive the
	 * correct language-specific locale for the current frontend request.
	 */
	public function filter_locale( string $locale ): string {
		if ( is_admin() ) return $locale;
		if ( ! defined( 'LF_LANG' ) ) return $locale;
		return $this->locale_from_lang( LF_LANG );
	}
}
