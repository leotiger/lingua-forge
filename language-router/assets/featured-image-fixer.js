/**
 * LSFLR Featured Image Fixer — modal behavior
 *
 * Mirrors language-router/assets/link-fixer.js's structure (open/scan/render/
 * fix-single/fix-all), scoped to a much simpler problem: for each translated
 * post, is its featured image missing or out of sync with its source-language
 * sibling? All user-facing strings live on window.lsflrFeaturedImageFixer
 * (populated by wp_localize_script in PHP) so they can be translated via
 * .po/.mo files.
 *
 * Dependencies: jQuery (admin global), ajaxurl (admin global).
 */
(function ($) {
	'use strict';

	var L    = window.lsflrFeaturedImageFixer || {};
	var i18n = L.i18n || {};

	var overlay    = $('#lsflr-thumbfixer-overlay');
	var status     = $('#lsflr-thumbfixer-status');
	var results    = $('#lsflr-thumbfixer-results');
	var actions    = $('#lsflr-thumbfixer-actions');
	var fixAllBtn  = $('#lsflr-thumbfix-all');
	var recheckBtn = $('#lsflr-thumbfixer-recheck');
	var progress   = $('#lsflr-thumbfix-progress');

	var scanData    = null;   // last scan response
	var activeLang  = '';
	var activeNonce = '';

	// ---- Open ----
	$(document).on('click', '.lsflr-open-thumbfixer', function () {
		activeLang  = $(this).data('lang');
		activeNonce = $(this).data('nonce');

		// Reset state
		scanData = null;
		results.empty();
		actions.hide();
		fixAllBtn.show().prop('disabled', false);
		progress.text('');

		status.html('<span class="lsflr-thumbfixer-spinner"></span> ' + esc(i18n.scanning || 'Scanning posts for missing or out-of-sync featured images…'));
		overlay.css('display', 'flex');

		doScan();
	});

	// ---- Close: button or backdrop click ----
	$(document).on('click', '#lsflr-thumbfixer-close', function () {
		overlay.hide();
	});
	overlay.on('click', function (e) {
		if (e.target === this) overlay.hide();
	});
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') overlay.hide();
	});

	// ---- Re-scan button ----
	recheckBtn.on('click', function () {
		scanData = null;
		results.empty();
		fixAllBtn.show().prop('disabled', false);
		progress.text('');
		status.html('<span class="lsflr-thumbfixer-spinner"></span> ' + esc(i18n.rescanning || 'Re-scanning…'));
		doScan();
	});

	// ---- Scan ----
	function doScan() {
		$.post(ajaxurl, {
			action   : 'lsflr_scan_featured_images',
			lang     : activeLang,
			nonce    : activeNonce,
			_nocache : Date.now()   // prevent browser from returning a cached response
		}, function (resp) {
			if (!resp.success) {
				status.text((i18n.scanFailed || 'Scan failed: ') + (resp.data || (i18n.unknownError || 'unknown error')));
				actions.show();   // still show Re-scan so the user can retry
				return;
			}
			scanData = resp.data;
			renderResults(scanData);
		}).fail(function () {
			status.text(i18n.scanRequestFailed || 'Scan request failed. Please try again.');
			actions.show();
		});
	}

	// ---- Render scan results ----
	function renderResults(data) {
		var langUpper = String(data.lang || '').toUpperCase();

		if (!data.results.length) {
			if (!data.scanned) {
				status.html(
					tpl(i18n.noPostsFound || '⚠ No <strong>{lang}</strong> posts found. Make sure all translated posts have their Language meta set to <strong>{lang}</strong> in the Language metabox.',
						{ lang: esc(langUpper) })
				);
			} else {
				status.html(
					tpl(i18n.allInSync || '✅ All <strong>{lang}</strong> featured images are already in sync with their source post. Scanned <strong>{scanned}</strong> post(s).',
						{ lang: esc(langUpper), scanned: data.scanned })
				);
			}
			actions.show();
			fixAllBtn.hide();
			return;
		}

		status.html(
			tpl(i18n.foundSummary || 'Found <strong>{n}</strong> post(s) missing or out of sync with their source featured image, out of <strong>{scanned}</strong> scanned for <strong>{lang}</strong>.',
				{ n: data.total, scanned: data.scanned, lang: esc(langUpper) })
		);

		var html = '<table>'
			+ '<thead><tr>'
			+ '<th>' + esc(i18n.colPost   || 'Post')        + '</th>'
			+ '<th>' + esc(i18n.colCurrent || 'Current')    + '</th>'
			+ '<th></th>'
			+ '<th>' + esc(i18n.colSource  || 'Source (EN)') + '</th>'
			+ '<th></th>'
			+ '</tr></thead><tbody>';

		data.results.forEach(function (item) {
			var currentCell = item.current_id
				? '<img class="lsflr-thumb-preview" src="' + esc(item.current_url) + '" alt="">'
				: '<span class="lsflr-thumb-none">' + esc(i18n.none || 'None') + '</span>';

			var sourceCell = '<img class="lsflr-thumb-preview" src="' + esc(item.source_url) + '" alt="">';

			var fixBtn = '<button type="button" class="button lsflr-thumbfix-single" data-post-id="' + item.post_id + '">'
				+ esc(i18n.btnFix || 'Copy from source') + '</button>';

			html += '<tr id="lsflr-thumbrow-' + item.post_id + '">'
				+ '<td><strong>' + esc(item.title) + '</strong><br><small style="color:#888">#' + item.post_id + '</small></td>'
				+ '<td>' + currentCell + '</td>'
				+ '<td>&rarr;</td>'
				+ '<td>' + sourceCell + '</td>'
				+ '<td>' + fixBtn + '</td>'
				+ '</tr>';
		});

		html += '</tbody></table>';
		results.html(html);

		fixAllBtn.show();
		actions.show();
	}

	// ---- Fix single post (row button) ----
	$(document).on('click', '.lsflr-thumbfix-single', function () {
		var btn    = $(this);
		var postId = btn.data('post-id');
		btn.prop('disabled', true).text(i18n.btnFixing || 'Copying…');
		doFix(postId, function (ok, applied) {
			var row = $('#lsflr-thumbrow-' + postId);
			if (ok && applied) {
				row.addClass('lsflr-fixed');
				btn.text(i18n.btnFixed || '✅ Copied');
			} else {
				row.addClass('lsflr-failed');
				btn.text(i18n.btnFailed || '❌ Failed').prop('disabled', false);
			}
		});
	});

	// ---- Fix all (sequential to avoid DB contention) ----
	fixAllBtn.on('click', function () {
		if (!scanData || !scanData.results.length) return;
		fixAllBtn.prop('disabled', true);

		var queue = scanData.results.slice();
		var done  = 0;
		var total = queue.length;

		function next() {
			if (!queue.length) {
				progress.text(tpl(i18n.allDone || 'Done — {done} of {total} post(s) fixed.', { done: done, total: total }));
				return;
			}
			var item = queue.shift();
			progress.html('<span class="lsflr-thumbfixer-spinner"></span> ' + esc(tpl(i18n.fixingProgress || 'Fixing {n} / {total}…', { n: (total - queue.length), total: total })));

			var row    = $('#lsflr-thumbrow-' + item.post_id);
			var rowBtn = row.find('.lsflr-thumbfix-single');
			rowBtn.prop('disabled', true).text(i18n.btnFixing || 'Copying…');

			doFix(item.post_id, function (ok, applied) {
				if (ok && applied) {
					rowBtn.text(i18n.btnFixed || '✅ Copied');
					row.addClass('lsflr-fixed');
					done++;
				} else {
					rowBtn.text(i18n.btnFailed || '❌ Failed').prop('disabled', false);
					row.addClass('lsflr-failed');
				}
				next();
			});
		}

		next();
	});

	// ---- AJAX helper: fix single post — passes (ok, applied) to callback ----
	function doFix(postId, cb) {
		$.post(ajaxurl, {
			action  : 'lsflr_fix_featured_image',
			post_id : postId,
			lang    : activeLang,
			nonce   : activeNonce
		}, function (resp) {
			var applied = (resp.success && resp.data) ? !!resp.data.applied : false;
			cb(resp.success, applied);
		}).fail(function () {
			cb(false, false);
		});
	}

	// ---- Utilities ----
	function esc(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// Tiny {placeholder} substitution. Values are inserted verbatim — callers
	// must pre-escape any user-supplied data with esc() above.
	function tpl(str, vars) {
		return String(str).replace(/\{(\w+)\}/g, function (_, k) {
			return (vars && Object.prototype.hasOwnProperty.call(vars, k)) ? vars[k] : '';
		});
	}

}(jQuery));
