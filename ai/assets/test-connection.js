/**
 * LinguaForge AI — Settings page "Test connection" buttons.
 *
 * Each <button class="lingua-forge-test-key" data-provider="..."> next to an
 * API key field POSTs a minimal "ping" chat through the
 * wp_ajax_linguaforge_test_provider endpoint and renders the outcome inline
 * in the sibling <span class="lingua-forge-test-result" data-for="...">.
 *
 * Config is provided via the localized window.linguaForgeTestConnection object
 * (see SettingsPage::enqueue_settings_assets in PHP).
 */
(function () {
	'use strict';

	const cfg = window.linguaForgeTestConnection;
	if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {
		return; // page not localized — nothing to wire up
	}

	const STRINGS = cfg.strings || {};

	function findResultSpan(provider) {
		return document.querySelector(
			'.lingua-forge-test-result[data-for="' + cssEscape(provider) + '"]'
		);
	}

	/**
	 * Minimal CSS.escape polyfill — restricts to the [a-z0-9_-] alphabet that
	 * provider slugs come from, so we avoid pulling in a full polyfill.
	 */
	function cssEscape(s) {
		return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
	}

	function render(span, statusClass, text) {
		if (!span) return;
		span.className = 'lingua-forge-test-result lingua-forge-test-result--' + statusClass;
		span.textContent = ' ' + text;
	}

	function handleClick(event) {

		const btn = event.currentTarget;
		const provider = btn.dataset.provider;
		const result = findResultSpan(provider);

		btn.disabled = true;
		render(result, 'pending', STRINGS.testing || 'Testing…');

		const body = new URLSearchParams();
		body.set('action',   'linguaforge_test_provider');
		body.set('provider', provider);
		body.set('nonce',    cfg.nonce);

		fetch(cfg.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body:        body.toString(),
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {

			btn.disabled = false;

			if (data && data.success) {
				const ok = STRINGS.ok || '✓ Connection OK';
				const reply = data.reply ? ' — “' + data.reply + '”' : '';
				render(result, 'ok', ok + reply);
				return;
			}

			const fail = STRINGS.fail || '✗ Failed:';
			const msg  = (data && data.message) ? data.message : (STRINGS.noResponse || 'No response.');
			render(result, 'fail', fail + ' ' + msg);
		})
		.catch(function () {
			btn.disabled = false;
			render(result, 'fail', (STRINGS.fail || '✗ Failed:') + ' ' + (STRINGS.network || 'Network error.'));
		});
	}

	function bind() {
		document.querySelectorAll('.lingua-forge-test-key').forEach(function (btn) {
			btn.addEventListener('click', handleClick);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}

}());
