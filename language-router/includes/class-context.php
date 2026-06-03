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

	/** @var string|null */
	private ?string $cached_routing_mode = null;

	/** @var string|null */
	private ?string $cached_base_domain  = null;

	public function routing_mode(): string {
		if ( $this->cached_routing_mode !== null ) return $this->cached_routing_mode;
		return $this->cached_routing_mode = sanitize_key( (string) get_option( 'linguaforge_routing_mode', 'path' ) );
	}

	/**
	 * The bare domain used to construct subdomain URLs in subdomain routing mode.
	 *
	 * Auto-derived from home_url() — e.g. 'https://example.com' → 'example.com'.
	 * If home_url() includes a www or other prefix (e.g. 'www.example.com'), use
	 * the lf_base_domain filter to return the apex domain instead.
	 *
	 * @return string  e.g. 'example.com'
	 */
	public function base_domain(): string {
		if ( $this->cached_base_domain !== null ) return $this->cached_base_domain;
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $this->cached_base_domain = (string) apply_filters( 'lf_base_domain', $host );
	}

	/**
	 * Returns the base URL for a given language code.
	 *
	 * Path mode  : always home_url('/') — language expressed as a URL path prefix.
	 * Subdomain  : home_url('/') for the source language; https://{lang}.{base_domain}/
	 *              for all other languages.
	 *
	 * @param  string $lang  Two-char language code.
	 * @return string        Trailing-slash URL.
	 */
	public function lang_base_url( string $lang ): string {
		if ( $this->routing_mode() !== 'subdomain' || $lang === $this->source_language() ) {
			return home_url( '/' );
		}
		$scheme = is_ssl() ? 'https' : 'http';
		return $scheme . '://' . $lang . '.' . $this->base_domain() . '/';
	}

	public function source_language(): string {
		if ( $this->cached_source_language !== null ) return $this->cached_source_language;
		$stored = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		// Fall back to the WordPress site locale (first two characters) so that an
		// unconfigured install behaves sensibly rather than returning an empty string.
		if ( $stored === '' ) {
			$stored = sanitize_key( substr( get_locale(), 0, 2 ) );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $this->cached_source_language = apply_filters( 'lf_primary_language', $stored );
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

		$src = $this->source_language();
		if ( $src !== '' ) {
			$langs[] = $src;
		}

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

	/**
	 * Attempt to detect the active language from the HTTP_HOST header.
	 *
	 * Only active in subdomain routing mode. Returns the language code when the
	 * current request host is {lang}.{base_domain}, or '' when no match is found.
	 *
	 * @param  array<string> $langs  Known router language codes.
	 * @return string                Matched language code, or '' on no match.
	 */
	private function detect_lang_from_host( array $langs ): string {
		if ( $this->routing_mode() !== 'subdomain' ) return '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value extracted via sanitize_key() and matched against a whitelist immediately.
		$host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$host = (string) preg_replace( '/:\d+$/', '', $host ); // strip port
		$base = $this->base_domain();
		if ( str_ends_with( $host, '.' . $base ) ) {
			$subdomain = sanitize_key( substr( $host, 0, strlen( $host ) - strlen( $base ) - 1 ) );
			if ( in_array( $subdomain, $langs, true ) ) return $subdomain;
		}
		return '';
	}

	public function detect_lang_safe(): string {
		$langs   = $this->languages();
		$default = $this->source_language();

		// 0. Subdomain — checked first in subdomain routing mode.
		$host_lang = $this->detect_lang_from_host( $langs );
		if ( $host_lang !== '' ) return $host_lang;

		// 1. URL path prefix — path mode only; in subdomain mode there is no prefix.
		// Parse only the PATH component of REQUEST_URI — trimming the raw value would
		// include the query string (e.g. "?s=foo&lang=de") as a fake path segment and
		// cause search requests at / to be misidentified as source-language pages.
		if ( $this->routing_mode() === 'path' ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied; only the path component is extracted and used for routing.
			$path_only = trim( (string) ( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?? '/' ), '/' );
			$seg       = explode( '/', $path_only );
			if ( ! empty( $seg[0] ) ) {
				$url_lang = strtolower( $seg[0] );
				if ( in_array( $url_lang, $langs, true ) ) {
					// Persist the URL-detected language in a cookie so that a
					// subsequent visit to / (no prefix) uses the cookie rather than
					// the browser Accept-Language header and lands on the right homepage.
					// Only write when the cookie is absent or stale to avoid a
					// superfluous Set-Cookie header on every page load.
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value validated immediately via in_array above.
					$existing = strtolower( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ?? '' ) ) );
					if ( $existing !== $url_lang ) {
						$this->set_lang_cookie( $url_lang );
					}
					return $url_lang;
				}
				// Non-empty path with no language prefix is authoritative in path mode:
				// it can only be a source-language URL. Update the cookie so that
				// a stale non-source cookie (e.g. lf_lang=en) cannot prevent the
				// visitor from returning to the source-language homepage via /.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value compared against a known-safe default string.
				$existing = strtolower( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ?? '' ) ) );
				if ( $existing !== $default ) {
					$this->set_lang_cookie( $default );
				}
				return $default;
			}
		}

		// 2. GET
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
		if ( ! empty( $_GET['lang'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$q_lang = strtolower( sanitize_key( wp_unslash( $_GET['lang'] ) ) );
			if ( in_array( $q_lang, $langs, true ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value validated immediately via in_array above.
				$existing = strtolower( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ?? '' ) ) );
				if ( $existing !== $q_lang ) {
					$this->set_lang_cookie( $q_lang );
				}
				return $q_lang;
			}
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

		// 4. Browser Accept-Language header (opt-in).
		// Only reachable when URL has no language prefix, no ?lang= param, and
		// no lf_lang cookie — i.e. the genuine first visit of a new visitor.
		// The cookie written at steps 1/2 above ensures this step is skipped on
		// all subsequent visits once the visitor has landed on any language-prefixed
		// page, preventing the browser header from overriding their last URL choice.
		if ( get_option( 'lf_browser_redirect', false ) ) {
			$browser_lang = $this->detect_browser_lang( $langs );
			if ( $browser_lang !== '' ) return $browser_lang;
		}

		return $default;
	}

	public function detect_lang(): string {
		$langs   = $this->languages();
		$default = $this->source_language();

		// 0. Subdomain — checked first in subdomain routing mode.
		$host_lang = $this->detect_lang_from_host( $langs );
		if ( $host_lang !== '' ) return $host_lang;

		// 1. URL path prefix — path mode only.
		if ( $this->routing_mode() === 'path' ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; only the path component is extracted via wp_parse_url to avoid treating query strings as path segments.
			$path_only = trim( (string) ( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?? '/' ), '/' );
			$seg       = explode( '/', $path_only );
			if ( ! empty( $seg[0] ) ) {
				$url_lang = strtolower( $seg[0] );
				if ( in_array( $url_lang, $langs, true ) ) return $url_lang;
				// Non-empty path with no language prefix = source language. Return
				// immediately so a stale cookie cannot override the URL signal.
				return $default;
			}
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
		// REST_REQUEST is only defined after parse_request fires — too late for
		// handle_init_redirects() which hooks to 'init' at priority 0.  Guard
		// REST calls early by matching the request URI directly against the REST
		// prefix (usually /wp-json/) and the legacy ?rest_route= form.
		$uri         = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only URI comparison; not output or used in queries.
		$rest_prefix = '/' . rest_get_url_prefix() . '/';

		return
			( defined( 'DOING_AJAX' )     && DOING_AJAX ) ||
			( defined( 'REST_REQUEST' )   && REST_REQUEST ) ||
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
			( defined( 'WP_CLI' )         && WP_CLI ) ||
			( '' !== $uri && str_contains( $uri, $rest_prefix ) ) ||
			( '' !== $uri && str_contains( $uri, '?rest_route=' ) );
	}

	public function set_lang_cookie( string $lang ): void {
		if ( ! $this->is_valid_lang( $lang ) ) return;

		// In subdomain mode scope the cookie to the apex domain (leading dot) so it
		// is shared across all language subdomains (de.example.com, fr.example.com…).
		$domain = $this->routing_mode() === 'subdomain'
			? '.' . $this->base_domain()
			: (string) wp_parse_url( home_url(), PHP_URL_HOST );

		setcookie(
			'lf_lang',
			$lang,
			time() + MONTH_IN_SECONDS,
			'/',
			$domain,
			is_ssl(),
			false  // Must NOT be HttpOnly — the JS switcher needs to overwrite this cookie
			       // when switching back to the source language (whose homepage has no URL
			       // prefix, so the cookie is the only detection signal for bare '/' requests).
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
