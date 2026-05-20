<?php
/**
 * Class LinguaForge\Router\Context
 *
 * Holds site-wide language configuration and per-request language detection.
 * Sub-objects that need the active language list, source language, or cookie
 * state call methods on this class via $router->context.
 */

namespace LinguaForge\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Context {

	/** @var string[]|null */
	private ?array  $cached_languages       = null;
	private ?string $cached_source_language = null;

	// =========================================================
	// CONFIG
	// =========================================================

	public function source_language(): string {
		if ( $this->cached_source_language !== null ) return $this->cached_source_language;
		$stored = sanitize_key( (string) get_option( 'linguaforge_primary_language', 'ca' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $this->cached_source_language = apply_filters( 'lf_primary_language', $stored ?: 'ca' );
	}

	public function languages(): array {
		if ( $this->cached_languages !== null ) return $this->cached_languages;

		// Start with languages WordPress core knows about.
		$locales   = get_available_languages();
		$locales[] = get_locale();

		// Also auto-discover languages from the plugin's own .mo files so that
		// adding e.g. vikbooking-it_IT.mo is sufficient — no WP core language
		// pack and no manual filter needed.
		foreach ( $this->discover_plugin_locales() as $locale ) {
			$locales[] = $locale;
		}

		$langs = [];
		foreach ( $locales as $locale ) {
			$langs[] = strtolower( substr( $locale, 0, 2 ) );
		}

		$langs[] = $this->source_language();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $this->cached_languages = apply_filters( 'lf_languages_list', array_values( array_unique( $langs ) ) );
	}

	public function is_valid_lang( $lang ): bool {
		return in_array( $lang, $this->languages(), true );
	}

	// =========================================================
	// I18N OVERRIDES DIR
	// =========================================================

	/**
	 * Absolute path to the user-managed i18n overrides directory.
	 *
	 * Stored in the uploads folder so files survive plugin updates and are
	 * never part of the plugin codebase:
	 *   wp-content/uploads/lingua-forge/i18n-overrides/
	 *
	 * Drop {textdomain}-{locale}.mo files here to override a plugin's strings
	 * (e.g. vikbooking-ca.mo to swap "room" → "apartment" in Catalan).
	 * No code changes needed when adding new plugins or locales.
	 *
	 * Filterable via lf_i18n_overrides_dir for custom storage locations.
	 *
	 * @return string  Trailing-slash path.
	 */
	public function i18n_overrides_dir(): string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides/';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return (string) apply_filters( 'lf_i18n_overrides_dir', $dir );
	}

	/**
	 * Scan the i18n-overrides/ directory and return every unique locale code
	 * found across all third-party .mo override files.
	 *
	 * Files follow the standard WordPress naming convention:
	 *   {textdomain}-{locale}.mo
	 *
	 * The locale suffix is either a bare two-letter code ("ca", "ja") or a
	 * language_COUNTRY pair ("it_IT", "pt_PT", "de_DE"). Both forms are matched
	 * by the regex so any plugin's translation file is accepted automatically —
	 * no anchor on a specific text domain is needed.
	 *
	 * @return string[]  e.g. ['it_IT', 'pt_PT', 'de_DE', 'ca', …]
	 */
	public function discover_plugin_locales(): array {
		$files   = glob( $this->i18n_overrides_dir() . '*.mo' ) ?: [];
		$locales = [];

		foreach ( $files as $file ) {
			// Match the locale at the end: either "xx_XX" or bare "xx".
			if ( preg_match( '/-([a-z]{2}(?:_[A-Z]{2})?)\.mo$/i', $file, $m ) ) {
				$locales[] = $m[1];
			}
		}

		return array_unique( $locales );
	}

	// =========================================================
	// LANGUAGE DETECTION
	// =========================================================

	public function detect_lang_safe(): string {
		$langs   = $this->languages();
		$default = $this->source_language();

		// 1. URL
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = trim( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '/' );
		$seg = explode( '/', $uri );
		if ( ! empty( $seg[0] ) ) {
			$url_lang = strtolower( $seg[0] );
			if ( in_array( $url_lang, $langs, true ) ) return $url_lang;
		}

		// 2. GET
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
		if ( ! empty( $_GET['lang'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$q_lang = strtolower( sanitize_key( wp_unslash( $_GET['lang'] ) ) );
			if ( in_array( $q_lang, $langs, true ) ) return $q_lang;
		}

		// 3. Cookie
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
		if ( ! empty( $_COOKIE['lf_lang'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
			$cookie_lang = strtolower( trim( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ) ) ) );
			if ( str_contains( $cookie_lang, '-' ) ) {
				$cookie_lang = substr( $cookie_lang, 0, 2 );
			}
			if ( in_array( $cookie_lang, $langs, true ) ) return $cookie_lang;
		}

		// 4. Browser Accept-Language header (opt-in, homepage visits only).
		// Only reachable when URL has no language prefix, no ?lang= param, and
		// no lf_lang cookie — i.e. the genuine first visit of a new visitor.
		// The existing redirect handlers (handle_homepage_redirect etc.) pick up
		// the LF_LANG value set here and issue the actual redirect; no extra
		// redirect code is needed. Once the visitor switches language via the
		// switcher, set_lang_cookie() fires and step 3 wins on all future visits.
		if ( get_option( 'lf_browser_redirect', false ) ) {
			$browser_lang = $this->detect_browser_lang( $langs );
			if ( $browser_lang !== '' ) return $browser_lang;
		}

		return $default;
	}

	public function detect_lang(): string {
		$langs   = $this->languages();
		$default = $this->source_language();

		// 1. URL
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = trim( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '/' );
		$seg = explode( '/', $uri );
		if ( ! empty( $seg[0] ) ) {
			$url_lang = strtolower( $seg[0] );
			if ( in_array( $url_lang, $langs, true ) ) return $url_lang;
		}

		// 2. Cookie
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
		if ( ! empty( $_COOKIE['lf_lang'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
			$cookie_lang = strtolower( trim( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ) ) ) );
			if ( str_contains( $cookie_lang, '-' ) ) {
				$cookie_lang = substr( $cookie_lang, 0, 2 );
			}
			if ( in_array( $cookie_lang, $langs, true ) ) return $cookie_lang;
		}

		// 3. Browser Accept-Language header (opt-in).
		if ( get_option( 'lf_browser_redirect', false ) ) {
			$browser_lang = $this->detect_browser_lang( $langs );
			if ( $browser_lang !== '' ) return $browser_lang;
		}

		return $default;
	}

	/**
	 * Parse the HTTP Accept-Language header and return the best matching
	 * router-known language code, or an empty string when no match is found.
	 *
	 * The header value is already sanitized via sanitize_text_field() before
	 * parsing. Quality values default to 1.0 when absent (RFC 4647). Both
	 * exact two-char codes ('de') and regional tags ('de-DE', 'de-AT') are
	 * matched against the router's known two-char language list.
	 *
	 * @param  array<string> $langs  Known router language codes (e.g. ['ca','de','en']).
	 * @return string                Matched two-char language code, or '' on no match.
	 */
	public function detect_browser_lang( array $langs ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately via sanitize_text_field() below; value used only for string parsing against a whitelist.
		$raw_header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) )
			: '';

		if ( $raw_header === '' ) {
			return '';
		}

		// Parse "de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7,ca;q=0.6".
		$entries = [];
		foreach ( array_map( 'trim', explode( ',', $raw_header ) ) as $entry ) {
			$parts   = explode( ';', $entry );
			$tag     = strtolower( trim( $parts[0] ) );
			$quality = 1.0;
			if ( isset( $parts[1] ) ) {
				$qstr = trim( $parts[1] );
				if ( str_starts_with( $qstr, 'q=' ) ) {
					$quality = (float) substr( $qstr, 2 );
				}
			}
			$entries[] = [ 'tag' => $tag, 'q' => $quality ];
		}

		// Sort highest quality first.
		usort( $entries, static fn( array $a, array $b ): int => $b['q'] <=> $a['q'] );

		foreach ( $entries as $entry ) {
			$tag = $entry['tag'];

			// Exact two-char match (e.g. 'de').
			if ( strlen( $tag ) === 2 && in_array( $tag, $langs, true ) ) {
				return $tag;
			}

			// Prefix match for regional tags (e.g. 'de-de' → 'de', 'de-at' → 'de').
			if ( strlen( $tag ) > 2 && $tag[2] === '-' ) {
				$prefix = substr( $tag, 0, 2 );
				if ( in_array( $prefix, $langs, true ) ) {
					return $prefix;
				}
			}
		}

		return '';
	}

	// =========================================================
	// UTILITY HELPERS
	// =========================================================

	public function is_system_request(): bool {
		return
			( defined( 'DOING_AJAX' )     && DOING_AJAX ) ||
			( defined( 'REST_REQUEST' )   && REST_REQUEST ) ||
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
			( defined( 'WP_CLI' )         && WP_CLI );
	}

	public function set_lang_cookie( string $lang ): void {
		if ( ! $this->is_valid_lang( $lang ) ) return;

		setcookie(
			'lf_lang',
			$lang,
			time() + MONTH_IN_SECONDS,
			'/',
			'',
			is_ssl(),
			true
		);
	}

	public function safe_query_args( string $url ): string {
		$allowed = [ 'paged', 's' ];
		$params  = [];

		foreach ( $allowed as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			if ( isset( $_GET[$key] ) && $_GET[$key] !== '' ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
				$params[$key] = sanitize_text_field( wp_unslash( $_GET[$key] ) );
			}
		}

		return empty( $params ) ? $url : add_query_arg( $params, $url );
	}
}
