/**
 * Lingua Forge — admin metabox (post edit screens).
 *
 * Handles:
 *   - Import-translation button click: confirms, fires the lf_import_translation
 *     AJAX action, reloads on success.
 *   - Language-select change: calls the lf_set_language AJAX endpoint, which
 *     atomically writes _lang and _wp_page_template on the server, then reloads
 *     so the new template renders cleanly.
 *   - Translations-group change: requires confirmation, dispatches a savePost
 *     and reloads after the save settles.
 *
 * Nonces and source-language are passed in via wp_add_inline_script(…, 'before')
 * under the `lfAdminMetabox` global:
 *   lfAdminMetabox.importNonce   — lf_import_translation_nonce
 *   lfAdminMetabox.langNonce     — lf_set_language_nonce
 *   lfAdminMetabox.sourceLanguage
 *
 * Loaded via wp_enqueue_script() on post.php and post-new.php only.
 */

document.addEventListener('click', function (e) {
	if (!e.target.classList.contains('lf-import')) return;
	if (!confirm('Override content from desired language?')) return;

	var post_id = document.getElementById('post_ID').value;
	var lang    = e.target.dataset.lang;

	fetch(ajaxurl, {
		method:  'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body:    new URLSearchParams({
			action:  'lf_import_translation',
			post_id: post_id,
			lang:    lang,
			nonce:   lfAdminMetabox.importNonce
		})
	})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (!data.success) { alert('Import failed: ' + (data.data || 'unknown error')); return; }
			location.reload();
		})
		.catch(function (err) { alert('Import request failed: ' + err); });
});

document.addEventListener('change', function (e) {
	var isLangSelect  = e.target.classList.contains('lf-lr-lang');
	var isInsideTrans = e.target.closest && e.target.closest('#lf_trans');
	if (!isLangSelect && !isInsideTrans) return;

	// Classic editor or no block-editor API: fall back to a plain reload.
	if (typeof wp === 'undefined' || !wp.data) { location.reload(); return; }

	var dispatch = wp.data.dispatch;
	var sel      = wp.data.select;

	// ── Translation-group change ─────────────────────────────────────────────
	// Linked posts change, so a full reload is still required after save.
	if (isInsideTrans) {
		if (!confirm('Change relationship? The page will reload after saving.')) return;
		dispatch('core/editor').savePost();
		var check = setInterval(function () {
			if (!sel('core/editor').isSavingPost() && !sel('core/editor').isAutosavingPost()) {
				clearInterval(check); location.reload();
			}
		}, 300);
		return;
	}

	// ── Language change ──────────────────────────────────────────────────────
	// Call lf_set_language AJAX endpoint, which atomically writes _lang and
	// _wp_page_template on the server (force_lang_template). Only after the
	// server confirms the writes are committed do we reload. This eliminates
	// the race where location.reload() could fire before the metabox POST
	// (and force_lang_template) had finished — causing the editor to load
	// stale _wp_page_template from the DB.
	var post_id = document.getElementById('post_ID') ? document.getElementById('post_ID').value : 0;
	var newLang = e.target.value;

	fetch(ajaxurl, {
		method:  'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body:    new URLSearchParams({
			action:  'lf_set_language',
			nonce:   lfAdminMetabox.langNonce,
			post_id: post_id,
			lang:    newLang
		})
	})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (!data.success) {
				alert('Language update failed: ' + (data.data || 'unknown error'));
				return;
			}
			// Server has committed; safe to reload — the GET will see the new template.
			location.reload();
		})
		.catch(function (err) { alert('Language update request failed: ' + err); });
});
