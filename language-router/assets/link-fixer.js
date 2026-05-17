/**
 * LSFLR Link Fixer — modal behavior
 *
 * Extracted from LSFLR_Link_Fixer::render_modal() inline <script> block.
 * All user-facing strings live on window.lsflrLinkFixer (populated by
 * wp_localize_script in PHP) so they can be translated via .po/.mo files.
 *
 * Dependencies: jQuery (admin global), ajaxurl (admin global).
 */
(function ($) {
	'use strict';

	var L = window.lsflrLinkFixer || {};
	var i18n = L.i18n || {};
	var reasonLabelTemplates = L.reasonLabels || {
		unresolved: 'URL could not be mapped to a post',
		no_translation: 'No translation registered (TRID missing)',
		permalink_error: 'Translation found but permalink could not be generated'
	};

	var overlay    = $('#lsflr-fixer-overlay');
	var status     = $('#lsflr-fixer-status');
	var results    = $('#lsflr-fixer-results');
	var actions    = $('#lsflr-fixer-actions');
	var fixAllBtn  = $('#lsflr-fix-all');
	var recheckBtn = $('#lsflr-recheck');
	var progress   = $('#lsflr-fix-progress');

	var scanData    = null;   // last scan response
	var activeLang  = '';
	var activeNonce = '';

	// ---- Open ----
	$(document).on('click', '.lsflr-open-fixer', function () {
		activeLang  = $(this).data('lang');
		activeNonce = $(this).data('nonce');

		// Reset state
		scanData = null;
		results.empty();
		actions.hide();
		fixAllBtn.show().prop('disabled', false);
		progress.text('');

		status.html('<span class="lsflr-spinner"></span> ' + esc(i18n.scanning || 'Scanning posts for broken language links…'));
		overlay.css('display', 'flex');

		doScan();
	});

	// ---- Close: button or backdrop click ----
	$(document).on('click', '#lsflr-fixer-close', function () {
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
		fixAllBtn.prop('disabled', false);
		progress.text('');
		status.html('<span class="lsflr-spinner"></span> ' + esc(i18n.rescanning || 'Re-scanning…'));
		doScan();
	});

	// ---- Scan ----
	function doScan() {
		$.post(ajaxurl, {
			action   : 'lsflr_scan_links',
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
				// No posts were found at all — likely a missing _lang meta
				status.html(
					tpl(i18n.noPostsFound || '⚠ No <strong>{lang}</strong> posts found. Make sure all translated posts have their Language meta set to <strong>{lang}</strong> in the Language metabox.',
						{ lang: esc(langUpper) })
				);
			} else {
				status.html(
					tpl(i18n.noBrokenLinks || '✅ No broken links found for <strong>{lang}</strong>. Scanned <strong>{scanned}</strong> post(s) — all internal links are already correct.',
						{ lang: esc(langUpper), scanned: data.scanned })
				);
			}
			actions.show();
			fixAllBtn.hide();
			return;
		}

		var totalFixes   = data.results.reduce(function (n, r) { return n + (r.fixes   ? r.fixes.length   : 0); }, 0);
		var totalFlagged = data.results.reduce(function (n, r) { return n + (r.flagged ? r.flagged.length : 0); }, 0);

		var statusParts = [];
		if (totalFixes) {
			statusParts.push(tpl(i18n.autoFixableCount || '<strong>{n}</strong> auto-fixable link(s)', { n: totalFixes }));
		}
		if (totalFlagged) {
			statusParts.push(tpl(i18n.manualReviewCount || '<strong>{n}</strong> link(s) needing manual review', { n: totalFlagged }));
		}
		var joinedParts = statusParts.join(' ' + (i18n.and || 'and') + ' ');
		status.html(
			tpl(i18n.foundSummary || 'Found {parts} across <strong>{total}</strong> of <strong>{scanned}</strong> scanned post(s) for <strong>{lang}</strong>.',
				{ parts: joinedParts, total: data.total, scanned: data.scanned, lang: esc(langUpper) })
		);

		var reasonLabel = {
			unresolved      : '⚠ ' + (reasonLabelTemplates.unresolved || 'URL could not be mapped to a post — check the link target exists'),
			no_translation  : '⚠ ' + tpl(reasonLabelTemplates.no_translation || 'No {lang} translation registered (TRID missing)', { lang: langUpper }),
			permalink_error : '⚠ ' + (reasonLabelTemplates.permalink_error || 'Translation found but permalink could not be generated')
		};

		var html = '<table>'
			+ '<thead><tr>'
			+ '<th>' + esc(i18n.colPost  || 'Post')  + '</th>'
			+ '<th>' + esc(i18n.colLinks || 'Links') + '</th>'
			+ '<th></th>'
			+ '</tr></thead><tbody>';

		data.results.forEach(function (item) {
			var fixes     = item.fixes   || [];
			var flagged   = item.flagged || [];
			var linkCount = fixes.length + flagged.length;

			// Auto-fixable pairs (red → green)
			var pairs = fixes.map(function (f) {
				return '<div class="lsflr-fix-pair">'
					+ '<span class="lsflr-from">↳ ' + esc(stripHost(f.from)) + '</span><br>'
					+ '<span class="lsflr-to">→ '   + esc(stripHost(f.to))   + '</span>'
					+ '</div>';
			}).join('');

			// Flagged links (orange — needs manual attention)
			var flags = flagged.map(function (f) {
				var label = reasonLabel[f.reason] || ('⚠ ' + esc(f.reason));
				var detail = f.linked_post_title ? ' <em>(' + esc(f.linked_post_title) + ')</em>' : '';
				return '<div class="lsflr-fix-pair lsflr-flagged">'
					+ '<span class="lsflr-flag-url">⚑ ' + esc(stripHost(f.url)) + '</span><br>'
					+ '<span class="lsflr-flag-reason">' + label + detail + '</span>'
					+ '</div>';
			}).join('');

			var fixBtn = fixes.length
				? '<button type="button" class="button lsflr-fix-single" data-post-id="' + item.post_id + '">' + esc(i18n.btnFix || 'Fix') + '</button>'
				: '';

			html += '<tr id="lsflr-row-' + item.post_id + '">'
				+ '<td><strong>' + esc(item.title) + '</strong><br>'
				+ '<small style="color:#888">#' + item.post_id + ' &mdash; ' + linkCount + ' ' + esc(i18n.linksSuffix || 'link(s)') + '</small></td>'
				+ '<td>' + pairs + flags + '</td>'
				+ '<td style="white-space:nowrap">' + fixBtn + '</td>'
				+ '</tr>';
		});

		html += '</tbody></table>';
		results.html(html);

		if (totalFixes) {
			fixAllBtn.show();
		} else {
			fixAllBtn.hide();
		}
		actions.show();
	}

	// ---- Fix single post (row button) ----
	$(document).on('click', '.lsflr-fix-single', function () {
		var btn    = $(this);
		var postId = btn.data('post-id');
		btn.prop('disabled', true).text(i18n.btnFixing || 'Fixing…');
		doFix(postId, function (ok, applied) {
			var row = $('#lsflr-row-' + postId);
			if (ok && applied > 0) {
				row.addClass('lsflr-fixed');
				btn.text(tpl(i18n.btnFixed || '✅ Fixed ({n})', { n: applied }));
			} else if (ok && applied === 0) {
				row.addClass('lsflr-failed');
				btn.text(i18n.btnNoChangesRescan || '⚠ No changes — re-scan?').prop('disabled', false);
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

		// Only queue posts that actually have auto-fixable links.
		var queue   = scanData.results.filter(function (r) { return r.fixes && r.fixes.length; });
		var done    = 0;
		var skipped = 0;
		var total   = queue.length;

		function next() {
			if (!queue.length) {
				var msg = tpl(i18n.allDone || 'Done — {done} of {total} post(s) fixed.', { done: done, total: total });
				if (skipped) {
					msg += ' ' + tpl(i18n.skippedSuffix || '({skipped} had no replaceable links — re-scan to investigate)', { skipped: skipped });
				}
				progress.text(msg);
				return;
			}
			var item = queue.shift();
			progress.html('<span class="lsflr-spinner"></span> ' + esc(tpl(i18n.fixingProgress || 'Fixing {n} / {total}…', { n: (done + skipped + 1), total: total })));

			var rowBtn = $('#lsflr-row-' + item.post_id + ' .lsflr-fix-single');
			rowBtn.prop('disabled', true).text(i18n.btnFixing || 'Fixing…');

			doFix(item.post_id, function (ok, applied) {
				var row = $('#lsflr-row-' + item.post_id);
				if (ok && applied > 0) {
					done++;
					row.addClass('lsflr-fixed');
					rowBtn.text(tpl(i18n.btnFixed || '✅ Fixed ({n})', { n: applied }));
				} else if (ok && applied === 0) {
					skipped++;
					row.addClass('lsflr-failed');
					rowBtn.text(i18n.btnNoChanges || '⚠ No changes');
				} else {
					skipped++;
					row.addClass('lsflr-failed');
					rowBtn.text(i18n.btnFailed || '❌ Failed');
				}
				next();
			});
		}

		next();
	});

	// ---- AJAX helper — passes (ok, applied) to callback ----
	function doFix(postId, cb) {
		$.post(ajaxurl, {
			action  : 'lsflr_fix_post',
			post_id : postId,
			lang    : activeLang,
			nonce   : activeNonce
		}, function (resp) {
			var applied = (resp.success && resp.data) ? (resp.data.applied || 0) : 0;
			cb(resp.success, applied);
		}).fail(function () {
			cb(false, 0);
		});
	}

	// ---- Utilities ----
	function stripHost(url) {
		return String(url).replace(/^https?:\/\/[^/]+/, '');
	}

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
