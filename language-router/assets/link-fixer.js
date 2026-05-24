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
	var tplI18n   = L.templateI18n || {};
	var staleI18n = L.staleI18n   || {};

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
					tpl(i18n.noBrokenLinks || '✅ No broken links or template mismatches found for <strong>{lang}</strong>. Scanned <strong>{scanned}</strong> post(s) and page(s) — all checks passed. Template part links (header, footer, sidebar) are not included here — use the Fix Template Parts button to check those separately.',
						{ lang: esc(langUpper), scanned: data.scanned })
				);
			}
			actions.show();
			fixAllBtn.hide();
			return;
		}

		var totalFixes      = data.results.reduce(function (n, r) { return n + (r.fixes        ? r.fixes.length        : 0); }, 0);
		var totalStale      = data.results.reduce(function (n, r) { return n + (r.stale_fixes  ? r.stale_fixes.length  : 0); }, 0);
		var totalFlagged    = data.results.reduce(function (n, r) { return n + (r.flagged      ? r.flagged.length      : 0); }, 0);
		var totalTplIssue   = data.results.reduce(function (n, r) { return n + (r.template_issue ? 1 : 0); }, 0);
		var totalTplFixable = data.results.reduce(function (n, r) { return n + (r.template_issue && r.template_issue.can_fix ? 1 : 0); }, 0);

		var statusParts = [];
		if (totalFixes) {
			statusParts.push(tpl(i18n.autoFixableCount || '<strong>{n}</strong> auto-fixable link(s)', { n: totalFixes }));
		}
		if (totalStale) {
			statusParts.push(tpl(staleI18n.count || '<strong>{n}</strong> stale path(s)', { n: totalStale }));
		}
		if (totalFlagged) {
			statusParts.push(tpl(i18n.manualReviewCount || '<strong>{n}</strong> link(s) needing manual review', { n: totalFlagged }));
		}
		if (totalTplIssue) {
			statusParts.push(tpl(tplI18n.issues || '<strong>{n}</strong> template issue(s)', { n: totalTplIssue }));
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
			var fixes       = item.fixes        || [];
			var staleFixes  = item.stale_fixes  || [];
			var flagged     = item.flagged      || [];
			var tplIssue    = item.template_issue || null;
			var linkCount   = fixes.length + flagged.length;
			var hasAutoFix  = fixes.length || staleFixes.length;

			// Cross-language auto-fixable pairs (red → green)
			var pairs = fixes.map(function (f) {
				return '<div class="lsflr-fix-pair">'
					+ '<span class="lsflr-from">↳ ' + esc(stripHost(f.from)) + '</span><br>'
					+ '<span class="lsflr-to">→ '   + esc(stripHost(f.to))   + '</span>'
					+ '</div>';
			}).join('');

			// Stale-path pairs (amber — same language, path changed due to hierarchy move)
			var stalePairs = staleFixes.map(function (f) {
				return '<div class="lsflr-fix-pair lsflr-stale">'
					+ '<span class="lsflr-stale-label">' + esc(staleI18n.label || '📍 Stale path (page moved)') + '</span><br>'
					+ '<span class="lsflr-stale-from">↳ ' + esc(stripHost(f.from)) + '</span><br>'
					+ '<span class="lsflr-stale-to">→ '   + esc(stripHost(f.to))   + '</span>'
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

			// Template issue block
			var tplBlock = '';
			if (tplIssue) {
				var expectedStr = tpl(tplI18n.expected || 'Expected: {expected}', { expected: esc(tplIssue.expected) });
				var currentStr  = tpl(tplI18n.current  || 'Current: {current}',  { current:  esc(tplIssue.current)  });
				var noticeMsg   = tplIssue.can_fix
					? ''
					: '<br><span class="lsflr-flag-reason">' + tpl(tplI18n.notFound || 'Template "{expected}" does not exist — create it in the Site Editor first.', { expected: esc(tplIssue.expected) }) + '</span>';
				tplBlock = '<div class="lsflr-fix-pair lsflr-tpl-issue">'
					+ '<span class="lsflr-tpl-label">' + esc(tplI18n.label || '📄 Wrong template') + '</span><br>'
					+ '<span class="lsflr-from">' + expectedStr + '</span><br>'
					+ '<span class="lsflr-to">' + currentStr + '</span>'
					+ noticeMsg
					+ '</div>';
			}

			// Action buttons
			var fixBtn = hasAutoFix
				? '<button type="button" class="button lsflr-fix-single" data-post-id="' + item.post_id + '">' + esc(i18n.btnFix || 'Fix Links') + '</button>'
				: '';
			var fixTplBtn = (tplIssue && tplIssue.can_fix)
				? '<button type="button" class="button lsflr-fix-tpl" data-post-id="' + item.post_id + '">' + esc(tplI18n.btnFix || 'Fix Template') + '</button>'
				: '';

			var linkSuffix  = linkCount    ? linkCount + ' ' + esc(i18n.linksSuffix || 'link(s)') : '';
			var staleSuffix = staleFixes.length ? staleFixes.length + ' ' + esc(staleI18n.suffix || 'stale path(s)') : '';
			var tplSuffix   = tplIssue ? '1 template issue' : '';

			var subtitles = [linkSuffix, staleSuffix, tplSuffix].filter(Boolean).join(' &bull; ');

			html += '<tr id="lsflr-row-' + item.post_id + '">'
				+ '<td><strong>' + esc(item.title) + '</strong><br>'
				+ '<small style="color:#888">#' + item.post_id
				+ (subtitles ? ' &mdash; ' + subtitles : '')
				+ '</small></td>'
				+ '<td>' + pairs + stalePairs + flags + tplBlock + '</td>'
				+ '<td style="white-space:nowrap;vertical-align:top">'
				+ '<div style="display:flex;flex-direction:column;gap:4px">'
				+ fixBtn + fixTplBtn
				+ '</div></td>'
				+ '</tr>';
		});

		html += '</tbody></table>';
		results.html(html);

		// Show "Fix All" when there are auto-fixable links, stale paths, or fixable template issues.
		if (totalFixes || totalStale || totalTplFixable) {
			fixAllBtn.show();
		} else {
			fixAllBtn.hide();
		}
		actions.show();
	}

	// ---- Fix single post links (row button) ----
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

	// ---- Fix single post template (row button) ----
	$(document).on('click', '.lsflr-fix-tpl', function () {
		var btn    = $(this);
		var postId = btn.data('post-id');
		btn.prop('disabled', true).text(tplI18n.btnFixing || 'Fixing…');
		doFixTemplate(postId, function (ok, _template) {
			var row = $('#lsflr-row-' + postId);
			if (ok) {
				btn.text(tplI18n.btnFixed || '✅ Template fixed');
				// Mark the row fixed only if there are also no link issues pending.
				var linkBtn = row.find('.lsflr-fix-single');
				if (!linkBtn.length) {
					row.addClass('lsflr-fixed');
				}
			} else {
				btn.text(tplI18n.btnFailed || '❌ Failed').prop('disabled', false);
			}
		});
	});

	// ---- Fix all (sequential to avoid DB contention) ----
	// Fixes both broken links and template mismatches for every affected post.
	fixAllBtn.on('click', function () {
		if (!scanData || !scanData.results.length) return;
		fixAllBtn.prop('disabled', true);

		// Queue posts that have auto-fixable links, stale paths, OR a fixable template issue.
		var queue   = scanData.results.filter(function (r) {
			return (r.fixes && r.fixes.length)
				|| (r.stale_fixes && r.stale_fixes.length)
				|| (r.template_issue && r.template_issue.can_fix);
		});
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

			// hasLinks covers both cross-language fixes and stale-path fixes;
			// fix_post() on the server already merges both buckets.
			var hasLinks = (item.fixes && item.fixes.length) || (item.stale_fixes && item.stale_fixes.length);
			var hasTpl   = item.template_issue && item.template_issue.can_fix;

			function afterLinks(linksOk, applied) {
				var row    = $('#lsflr-row-' + item.post_id);
				var rowBtn = row.find('.lsflr-fix-single');

				if (linksOk && applied > 0) {
					rowBtn.text(tpl(i18n.btnFixed || '✅ Fixed ({n})', { n: applied }));
				} else if (linksOk) {
					rowBtn.text(i18n.btnNoChanges || '⚠ No changes');
				} else {
					rowBtn.text(i18n.btnFailed || '❌ Failed');
				}

				if (hasTpl) {
					doFixTemplate(item.post_id, function (tplOk) {
						var tplBtn = row.find('.lsflr-fix-tpl');
						tplBtn.text(tplOk ? (tplI18n.btnFixed || '✅ Template fixed') : (tplI18n.btnFailed || '❌ Failed'));
						if (linksOk || tplOk) { done++; } else { skipped++; }
						if (linksOk && tplOk) { row.addClass('lsflr-fixed'); }
						next();
					});
				} else {
					if (linksOk && applied > 0) { done++; row.addClass('lsflr-fixed'); } else { skipped++; }
					next();
				}
			}

			var rowBtn = $('#lsflr-row-' + item.post_id + ' .lsflr-fix-single');
			if (hasLinks) {
				rowBtn.prop('disabled', true).text(i18n.btnFixing || 'Fixing…');
				doFix(item.post_id, afterLinks);
			} else {
				afterLinks(true, 0);
			}
		}

		next();
	});

	// ---- AJAX helper: fix links — passes (ok, applied) to callback ----
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

	// ---- AJAX helper: fix template — passes (ok, templateSlug|null) to callback ----
	function doFixTemplate(postId, cb) {
		$.post(ajaxurl, {
			action  : 'lsflr_fix_template',
			post_id : postId,
			lang    : activeLang,
			nonce   : activeNonce
		}, function (resp) {
			var template = (resp.success && resp.data) ? (resp.data.template || null) : null;
			cb(resp.success, template);
		}).fail(function () {
			cb(false, null);
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
