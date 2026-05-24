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
		] );

		if ( isset( $fallback_map[$lang] ) ) {
			return $cache[$lang] = $fallback_map[$lang];
		}

		// 4. Default
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $cache[$lang] = apply_filters( 'lf_lang_default_fallback', 'en_US' );
	}

	public function language_label( string $lang ): string {
		$locale = $this->locale_from_lang( $lang );

		if ( function_exists( 'locale_get_display_language' ) ) {
			$label = locale_get_display_language( $locale, $locale );
			return mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
		}

		return strtoupper( $lang );
	}

	// =========================================================
	// LOCALE HOOKS
	// =========================================================

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
