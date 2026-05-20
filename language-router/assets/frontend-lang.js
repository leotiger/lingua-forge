/**
 * Lingua Forge — frontend AJAX language interceptor.
 *
 * Appends `lang=X` to every jQuery AJAX request **targeting our own origin**
 * so backend handlers receive the active language even when the cookie isn't
 * available (e.g. in a fresh visitor's first request before set_lang_cookie()
 * has fired).
 *
 * Scoping rule (REVIEW §2.8 / audit §2.8):
 *   Only same-origin requests get the lang appended. Requests to third-party
 *   endpoints (Stripe, reCAPTCHA, analytics beacons, etc.) are left untouched —
 *   they don't know what `lang=` means, and adding it to their POST body has
 *   bitten unrelated plugins before. Relative URLs and bare-`location.href`
 *   requests resolve to same-origin and so still receive the param.
 *
 * Configuration is passed via wp_add_inline_script(…, 'before') under the
 * `lfFrontendLang` global:
 *   lfFrontendLang.lang  — the active language code (LF_LANG)
 *
 * Loaded via wp_enqueue_script() on every frontend request where LF_LANG
 * is defined; no-ops when the language code is empty.
 */

jQuery(function ($) {
	var lang = (typeof lfFrontendLang !== 'undefined') ? lfFrontendLang.lang : '';
	if (!lang) return;

	$(document).ajaxSend(function (event, xhr, settings) {

		// Scope to same-origin requests only. Third-party endpoints never
		// need our language hint and can misbehave if they introspect their
		// POST body. The URL constructor resolves relative URLs against
		// the current document, so e.g. 'admin-ajax.php', '/wp-json/...',
		// and undefined-url-defaults-to-location all resolve correctly.
		try {
			var resolved = new URL(settings.url || '', window.location.href);
			if (resolved.origin !== window.location.origin) return;
		} catch (e) {
			// Couldn't parse the URL — be conservative and skip rather
			// than risk appending the param to a foreign endpoint.
			return;
		}

		if (typeof settings.data === 'string' && settings.data.includes('lang=')) return;
		if (settings.data instanceof FormData) { settings.data.append('lang', lang); return; }
		if (typeof settings.data === 'string' && settings.data.length) {
			settings.data += '&lang=' + lang; return;
		}
		if (!settings.data) { settings.data = 'lang=' + lang; }
	});
});
