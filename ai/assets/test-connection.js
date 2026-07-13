/**
 * LinguaForge AI — Settings page "Test connection" / "Test model" buttons.
 *
 * Each <button class="lingua-forge-test-key" data-provider="..."> next to an
 * API key field POSTs a minimal "ping" chat through the
 * wp_ajax_linguaforge_test_provider endpoint and renders the outcome inline
 * in the sibling <span class="lingua-forge-test-result" data-for="...">.
 *
 * Each <button class="lingua-forge-test-model" data-provider="..."
 * data-tier="..." data-input="..."> next to a Models field posts the exact
 * (possibly unsaved) string currently in the referenced <input> through the
 * wp_ajax_linguaforge_test_model endpoint. Unlike "Test connection" (a bare
 * ping against the saved *light*-tier model), this runs a real translation
 * of a real published post — through the same prompt-building and
 * response-parsing code the tier's actual feature uses, with the site's
 * current behaviour preset applied — and shows a preview of the translated
 * output, so it actually confirms the model works for us, not just that it
 * replies to something.
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

				// Refresh the datalist for this provider with the live model list
				// returned by the server (catalog + any newly-released models).
				if (Array.isArray(data.models) && data.models.length) {
					var datalist = document.getElementById('lf-models-' + provider);
					if (datalist) {
						datalist.innerHTML = '';
						data.models.forEach(function (id) {
							var opt = document.createElement('option');
							opt.value = String(id);
							datalist.appendChild(opt);
						});
					}
				}

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

	function findModelResultSpan(inputId) {
		return document.querySelector(
			'.lingua-forge-test-result[data-model-result-for="' + cssEscape(inputId) + '"]'
		);
	}

	function handleModelClick(event) {

		const btn = event.currentTarget;
		const provider = btn.dataset.provider;
		const tier = btn.dataset.tier;
		const inputId = btn.dataset.input;
		const input = inputId ? document.getElementById(inputId) : null;
		const result = findModelResultSpan(inputId);

		// Fall back to the field's placeholder (the built-in default) when the
		// override is blank, so "Test model" also works before anything has
		// been typed or saved.
		const model = input ? (input.value.trim() || input.placeholder.trim()) : '';

		if (!model) {
			render(result, 'fail', (STRINGS.fail || '✗ Failed:') + ' ' + (STRINGS.noModel || 'Enter a model identifier to test.'));
			return;
		}

		btn.disabled = true;
		render(result, 'pending', STRINGS.testingContent || 'Translating a real post with this model…');

		const body = new URLSearchParams();
		body.set('action',   'linguaforge_test_model');
		body.set('provider', provider);
		body.set('tier',     tier || '');
		body.set('model',    model);
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
				// Content-test payload — see ApiKeysTab::ajax_test_model():
				// sourceTitle/sourceLang/targetLang/preset describe what was
				// actually exercised; outputPreview is the real translated text.
				const ok = STRINGS.ok || '✓ Connection OK';
				const meta = [];
				if (data.sourceTitle && data.targetLanguage) {
					meta.push('"' + data.sourceTitle + '" → ' + data.targetLanguage);
				}
				if (data.preset) {
					meta.push((STRINGS.presetLabel || 'preset') + ': ' + data.preset);
				}
				const metaText = meta.length ? ' (' + meta.join(', ') + ')' : '';
				const titleLine = data.translatedTitle ? ' — “' + data.translatedTitle + '”' : '';
				const preview = data.outputPreview ? ' “' + data.outputPreview + '”' : '';
				render(result, 'ok', ok + metaText + titleLine + preview);
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
		document.querySelectorAll('.lingua-forge-test-model').forEach(function (btn) {
			btn.addEventListener('click', handleModelClick);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}

}());
