/**
 * Lingua Forge — frontend AJAX language interceptor (no jQuery).
 *
 * Appends `?lang=X` to the URL of every same-origin XHR and fetch()
 * request so that PHP's detect_lang_safe() can read it from $_GET['lang']
 * regardless of whether the request method is GET or POST.
 *
 * Why URL query string (not POST body):
 *   detect_lang_safe() reads $_GET['lang'] — query-string parameters are
 *   always available there for any HTTP method. Appending to the POST body
 *   (the old jQuery ajaxSend approach) silently missed every POST request
 *   because $_GET is populated from the URL only.
 *
 * Scoping rule (REVIEW §2.8 / audit §2.8):
 *   Only same-origin requests are patched. Third-party endpoints (Stripe,
 *   reCAPTCHA, analytics beacons, etc.) are left untouched — they do not
 *   know what `lang=` means and can misbehave if they see unexpected params.
 *   Relative URLs resolve to same-origin and are patched correctly.
 *
 * Configuration is passed via wp_add_inline_script(…, 'before') under the
 * `lfFrontendLang` global:
 *   lfFrontendLang.lang  — the active language code (LF_LANG)
 *
 * Loaded via wp_enqueue_script() on every frontend request where LF_LANG
 * is defined; no-ops immediately when the language code is empty.
 */

( function () {
	'use strict';

	var lang = ( typeof lfFrontendLang !== 'undefined' ) ? lfFrontendLang.lang : '';
	if ( ! lang ) return;

	/**
	 * Returns true when the given URL string resolves to the same origin as
	 * the current page. Unparseable URLs return false (conservative: skip).
	 *
	 * @param  {string} url
	 * @return {boolean}
	 */
	function isSameOrigin( url ) {
		try {
			return new URL( url, window.location.href ).origin === window.location.origin;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Returns true when the URL (or headers) belong to a WooCommerce /
	 * WordPress Interactivity API navigation or prefetch request.
	 *
	 * Two detection paths:
	 *
	 *  1. URL parameters (older WC ≤ 9.x):
	 *     `cst` — client-side transition param WC appended to server-render
	 *     fetches.  `query-N-page` — pagination argument on page-reload paths.
	 *
	 *  2. Request header (WP Interactivity Router, WP 6.5+ / WC 10+):
	 *     The WP Interactivity Router identifies its fetches with the
	 *     `X-WP-Interactivity-Router-Nonce` header instead of a URL param.
	 *     The header is present on both `Headers` objects (WP 6.5 native fetch)
	 *     and plain key-value objects passed as `init.headers`.
	 *
	 * These requests MUST NOT receive `?lang=X`:
	 *   - For translated languages the path prefix already establishes the lang.
	 *   - For the source language the server falls back to it by default.
	 *     Injecting `?lang=<source>` on the root path `/` prevents the
	 *     WooCommerce Product Collection block from executing its render
	 *     callback, producing an empty response for page 2+.
	 *
	 * @param  {string}                          url
	 * @param  {Headers|Object|null|undefined}   headers  Optional request headers.
	 * @return {boolean}
	 */
	function isInteractivityRequest( url, headers ) {
		try {
			var u    = new URL( url, window.location.href );
			if ( u.searchParams.has( 'cst' ) ) return true;
			var keys = Array.prototype.slice.call( u.searchParams.keys() );
			for ( var i = 0; i < keys.length; i++ ) {
				if ( /^query-\d+-page$/.test( keys[ i ] ) ) return true;
			}
		} catch ( e ) {
			return false;
		}

		// WP Interactivity Router (WP 6.5+ / WC 10+) uses a header, not a URL param.
		if ( headers ) {
			try {
				var nonce = ( typeof headers.get === 'function' )
					? headers.get( 'X-WP-Interactivity-Router-Nonce' )
					: ( headers[ 'X-WP-Interactivity-Router-Nonce' ] ||
					    headers[ 'x-wp-interactivity-router-nonce' ] || null );
				if ( nonce ) return true;
			} catch ( e ) { /* ignore — treat as not an interactivity request */ }
		}

		return false;
	}

	/**
	 * Appends `lang=X` to the query string of a URL if it is not already
	 * present. Always returns an absolute URL string.
	 *
	 * @param  {string} url
	 * @return {string}
	 */
	function appendLangParam( url ) {
		try {
			var u = new URL( url, window.location.href );
			if ( ! u.searchParams.has( 'lang' ) ) {
				u.searchParams.set( 'lang', lang );
			}
			return u.href;
		} catch ( e ) {
			return url;
		}
	}

	// -------------------------------------------------------------------------
	// Patch XMLHttpRequest.prototype.open
	// Covers jQuery.ajax, Backbone.sync, and any other XHR-based library.
	// -------------------------------------------------------------------------

	var _xhrOpen = XMLHttpRequest.prototype.open;
	XMLHttpRequest.prototype.open = function ( method, url ) {
		var args = Array.prototype.slice.call( arguments );
		// XHR headers are set after open(), so we can only check URL params here.
		// The WP Interactivity Router uses fetch (not XHR), so header detection is
		// not needed here — URL-param detection covers legacy WC ≤ 9.x paths.
		if ( typeof url === 'string' && isSameOrigin( url ) && ! isInteractivityRequest( url ) ) {
			args[ 1 ] = appendLangParam( url );
		}
		return _xhrOpen.apply( this, args );
	};

	// -------------------------------------------------------------------------
	// Patch window.fetch
	// Covers native fetch() calls. Skipped if the browser doesn't support it.
	// -------------------------------------------------------------------------

	if ( typeof window.fetch === 'function' ) {
		var _fetch = window.fetch;
		window.fetch = function ( input, init ) {
			try {
				var url     = ( input instanceof Request ) ? input.url : String( input || '' );
				var headers = ( init && init.headers ) ? init.headers
				            : ( input instanceof Request ? input.headers : null );
				if ( isSameOrigin( url ) && ! isInteractivityRequest( url, headers ) ) {
					var patched = appendLangParam( url );
					input = ( input instanceof Request )
						? new Request( patched, input )
						: patched;
				}
			} catch ( e ) {
				// Leave input untouched if anything goes wrong.
			}
			return _fetch.call( this, input, init );
		};
	}
} )();
