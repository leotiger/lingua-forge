/**
 * LinguaForge AI — Settings page tab switching.
 *
 * Initial tab resolution order on each page load:
 *   1. URL hash (#general, #api-keys, #limits, #behavior, #maintenance)
 *   2. sessionStorage (preserves tab across the save-and-redirect cycle)
 *   3. "general" (default)
 *
 * Hidden tabs stay in the DOM, so a single form submit always carries every
 * field's value back to handle_save() regardless of which tab was active.
 *
 * Tab choice is remembered in sessionStorage (not localStorage) so a fresh
 * browser session opens on General — the expected default for first-time use.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'linguaForgeAiActiveTab';

	// Run init either now (DOM already parsed) or after DOMContentLoaded.
	// Caching / optimisation plugins sometimes move footer scripts to <head>,
	// which would otherwise leave the querySelectorAll calls empty.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	function boot() {

		var TAB_BUTTONS = document.querySelectorAll('.lingua-forge-tabs .nav-tab[data-lf-tab]');
		var PANELS      = document.querySelectorAll('.lingua-forge-tab-panel[data-lf-panel]');

		if (TAB_BUTTONS.length === 0 || PANELS.length === 0) {
			// Visible warning so a misconfigured deploy is obvious in the console
			// rather than failing silently. Only fires on the Settings page —
			// elsewhere the script either isn't enqueued or returns immediately.
			if (document.querySelector('.lingua-forge-tabs')) {
				console.warn('[LinguaForge] settings-tabs.js loaded but no .lingua-forge-tab-panel elements found. Did SettingsPage.php deploy correctly?');
			}
			return;
		}

		function cssEscape(s) {
			// Restricted alphabet ([a-z0-9_-]) — adequate for our tab slugs.
			return String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
		}

		function tabFromHash() {
			var h = String(window.location.hash || '').replace(/^#/, '');
			return (h && document.querySelector('.nav-tab[data-lf-tab="' + cssEscape(h) + '"]')) ? h : '';
		}

		function tabFromStorage() {
			try {
				var t = sessionStorage.getItem(STORAGE_KEY);
				return (t && document.querySelector('.nav-tab[data-lf-tab="' + cssEscape(t) + '"]')) ? t : '';
			} catch (e) {
				return '';
			}
		}

		function activate(tabName) {
			if (!tabName) return;

			Array.prototype.forEach.call(TAB_BUTTONS, function (btn) {
				var match = btn.dataset.lfTab === tabName;
				btn.classList.toggle('nav-tab-active', match);
				btn.setAttribute('aria-selected', match ? 'true' : 'false');
			});

			Array.prototype.forEach.call(PANELS, function (panel) {
				panel.classList.toggle('is-active', panel.dataset.lfPanel === tabName);
			});

			try {
				sessionStorage.setItem(STORAGE_KEY, tabName);
			} catch (e) { /* private mode / quota — non-fatal */ }
		}

		// Wire click handlers.
		Array.prototype.forEach.call(TAB_BUTTONS, function (btn) {
			btn.addEventListener('click', function (event) {
				event.preventDefault();
				var tab = btn.dataset.lfTab;
				activate(tab);

				// Keep the address bar in sync — bookmarkable + shareable.
				if (history.replaceState) {
					history.replaceState(null, '', '#' + tab);
				} else {
					window.location.hash = '#' + tab;
				}
			});
		});

		// Initial activation.
		var initial = tabFromHash() || tabFromStorage() || 'general';
		activate(initial);
	}

}());
