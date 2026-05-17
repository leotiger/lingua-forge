/* LinguaForge AI – admin meta box interactions */
/* global wp */
( function () {

const { __ } = wp.i18n;

// ─── Conditional field visibility ────────────────────────────────────────────

/**
 * Show or hide field wrappers that carry data-condition-field / data-condition-value
 * attributes.  A wrapper is visible only when the referenced controller field
 * holds the expected value.  Runs once on DOMContentLoaded and again on every
 * change event on a controlling select.
 *
 * @param {Element} panel  .lingua-forge-panel root element.
 */
function initConditionalFields(panel) {

    panel.querySelectorAll('[data-condition-field]').forEach((wrapper) => {

        const condField = wrapper.dataset.conditionField;
        const condValue = wrapper.dataset.conditionValue;

        const controller = panel.querySelector(
            `[data-field="${condField}"]`
        );

        if (!controller) return;

        const sync = () => {
            wrapper.style.display = controller.value === condValue ? '' : 'none';
        };

        // Set initial state without transition flicker.
        sync();

        controller.addEventListener('change', sync);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lingua-forge-panel').forEach(initConditionalFields);
});

// ─── Feature button click ────────────────────────────────────────────────────

document.addEventListener('click', async (event) => {

    const button = event.target.closest('.lingua-forge-action');

    if (!button) return;

    const featureKey = button.dataset.feature;
    const postId     = button.dataset.postId;
    const panel      = button.closest('.lingua-forge-panel');
    const result     = button.closest('.lingua-forge-feature-group').querySelector('.lingua-forge-result');
    const params     = collectParams(panel, featureKey);

    button.disabled = true;

    await runFeature(featureKey, postId, params, result);

    button.disabled = false;
});

// ─── Force-refresh button click ──────────────────────────────────────────────

document.addEventListener('click', async (event) => {

    const button = event.target.closest('.lingua-forge-refresh');

    if (!button) return;

    const featureKey = button.dataset.feature;
    const postId     = button.dataset.postId;
    const panel      = button.closest('.lingua-forge-panel');
    const result     = button.closest('.lingua-forge-result');
    const params     = collectParams(panel, featureKey);

    params.force_refresh = true;

    button.disabled      = true;
    button.textContent   = __( '↺ Refreshing…', 'lingua-forge' );

    await runFeature(featureKey, postId, params, result);

    // Button is inside the result area and will have been replaced by
    // the re-render above, so no need to reset its state.
});

// ─── "Apply to Editor" button — opens preview-and-apply modal (§4.8) ─────────
//
// The translated content used to land directly in the editor on click,
// destructively overwriting whatever was there. The diff modal now sits in
// between: it shows the current editor title + content side-by-side with the
// translated title + content so the editor can verify before committing.
// Only the "Apply translation" button inside the modal performs the actual
// editPost dispatch.
document.addEventListener('click', (event) => {

    const button = event.target.closest('.lingua-forge-apply');

    if (!button) return;

    const panel           = button.closest('.lingua-forge-panel');
    const result          = button.closest('.lingua-forge-content-result');
    const textarea        = panel.querySelector('.lingua-forge-textarea');
    const translatedTitle = result?.dataset.translatedTitle || '';
    const footnotesJson   = result?.dataset.footnotes       || '';

    if (!textarea) return;

    clearApplyError(button);

    openApplyDiffModal({
        button,
        translatedContent: textarea.value,
        translatedTitle,
        footnotesJson,
    });
});

/**
 * Resolve the live editor store from whichever side of the meta-box iframe
 * boundary the code is running on. Returns null on classic-editor screens.
 */
function getEditorStore() {

    if (window.parent && window.parent !== window && window.parent.wp?.data) {
        return window.parent.wp.data;
    }
    if (window.wp?.data) {
        return window.wp.data;
    }
    return null;
}

/**
 * Snapshot the current editor title + content as a {title, content} pair.
 * Falls back to the classic-editor #title / #content fields when Gutenberg
 * isn't available.
 */
function snapshotCurrentEditorState() {

    const data = getEditorStore();

    if (data) {
        const sel = data.select('core/editor');
        return {
            title:   String(sel.getEditedPostAttribute('title')   ?? ''),
            content: String(sel.getEditedPostAttribute('content') ?? ''),
        };
    }

    return {
        title:   document.querySelector('#title')?.value   ?? '',
        content: document.querySelector('#content')?.value ?? '',
    };
}

/**
 * Build the modal DOM lazily on first use and append it to <body>. Subsequent
 * opens reuse the same node; the panes' innerHTML is repainted each time.
 */
function ensureDiffModal() {

    let modal = document.getElementById('lingua-forge-diff-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'lingua-forge-diff-modal';
    modal.className = 'lingua-forge-diff-modal';
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'lingua-forge-diff-title');

    modal.innerHTML = `
        <div class="lingua-forge-diff-modal__overlay" data-lf-action="cancel"></div>
        <div class="lingua-forge-diff-modal__panel" role="document">
            <header class="lingua-forge-diff-modal__header">
                <h2 id="lingua-forge-diff-title">${ escHtml( __( 'Review translation before applying', 'lingua-forge' ) ) }</h2>
                <button type="button" class="lingua-forge-diff-modal__close" data-lf-action="cancel" aria-label="${ escAttr( __( 'Close', 'lingua-forge' ) ) }">✕</button>
            </header>

            <section class="lingua-forge-diff-modal__title-row" data-lf-row="title" hidden>
                <div class="lingua-forge-diff-modal__pane">
                    <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Current title', 'lingua-forge' ) ) }</div>
                    <div class="lingua-forge-diff-modal__title" data-lf-pane="current-title"></div>
                </div>
                <div class="lingua-forge-diff-modal__pane lingua-forge-diff-modal__pane--new">
                    <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Translated title', 'lingua-forge' ) ) }</div>
                    <div class="lingua-forge-diff-modal__title" data-lf-pane="new-title"></div>
                </div>
            </section>

            <section class="lingua-forge-diff-modal__content-row">
                <div class="lingua-forge-diff-modal__pane">
                    <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Current content (will be overwritten)', 'lingua-forge' ) ) }</div>
                    <div class="lingua-forge-diff-modal__preview" data-lf-pane="current-content"></div>
                </div>
                <div class="lingua-forge-diff-modal__pane lingua-forge-diff-modal__pane--new">
                    <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Translated content', 'lingua-forge' ) ) }</div>
                    <div class="lingua-forge-diff-modal__preview" data-lf-pane="new-content"></div>
                </div>
            </section>

            <section class="lingua-forge-diff-modal__footnotes" data-lf-row="footnotes" hidden>
                <details>
                    <summary>${ escHtml( __( 'Footnotes payload (advanced)', 'lingua-forge' ) ) }</summary>
                    <pre class="lingua-forge-diff-modal__footnotes-json" data-lf-pane="footnotes"></pre>
                </details>
            </section>

            <footer class="lingua-forge-diff-modal__actions">
                <button type="button" class="components-button is-secondary" data-lf-action="cancel">
                    ${ escHtml( __( 'Cancel', 'lingua-forge' ) ) }
                </button>
                <button type="button" class="components-button is-primary lingua-forge-diff-modal__apply" data-lf-action="apply">
                    ${ escHtml( __( 'Apply translation', 'lingua-forge' ) ) }
                </button>
            </footer>
        </div>`;

    document.body.appendChild(modal);
    wireDiffModalEvents(modal);

    return modal;
}

/**
 * Per-modal event wiring. Click-on-overlay, Cancel, Close, Escape all close
 * without applying. Apply triggers the actual editPost dispatch via the
 * pending-apply context stored on the modal as a data property.
 */
function wireDiffModalEvents(modal) {

    modal.addEventListener('click', (e) => {
        const action = e.target.closest('[data-lf-action]')?.dataset.lfAction;
        if (action === 'cancel') closeDiffModal(modal);
        if (action === 'apply')  performApplyFromModal(modal);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) closeDiffModal(modal);
    });
}

/**
 * Populate the modal with the current/translated content+title and reveal it.
 *
 * The pending-apply context (button, translated payload) is stored on the
 * modal node itself so the Apply handler can reach it without globals.
 */
function openApplyDiffModal({ button, translatedContent, translatedTitle, footnotesJson }) {

    const modal   = ensureDiffModal();
    const current = snapshotCurrentEditorState();

    // Title row — show only when there's actually a translated title to apply.
    const titleRow = modal.querySelector('[data-lf-row="title"]');
    if (translatedTitle) {
        modal.querySelector('[data-lf-pane="current-title"]').textContent = current.title;
        modal.querySelector('[data-lf-pane="new-title"]').textContent     = translatedTitle;
        titleRow.hidden = false;
    } else {
        titleRow.hidden = true;
    }

    // Content panes — rendered as HTML so block markup displays close to how
    // it'll look post-apply. Block comments are HTML comments so they're
    // invisible; the actual <p>, <h2>, <ul>, etc. structure renders.
    //
    // Trust model: both sides are admin-authored (current editor state) or
    // admin-triggered (AI translation requested by an editor). Anyone who can
    // edit a post can already inject HTML/JS via the regular editor, so the
    // preview pane doesn't broaden attack surface. A future hardening pass
    // could move these into sandbox="allow-same-origin" iframes.
    modal.querySelector('[data-lf-pane="current-content"]').innerHTML = current.content;
    modal.querySelector('[data-lf-pane="new-content"]').innerHTML     = translatedContent;

    // Footnotes — collapsible JSON dump, only when the translation produced one.
    const footnoteRow = modal.querySelector('[data-lf-row="footnotes"]');
    if (footnotesJson) {
        try {
            const parsed = JSON.parse(footnotesJson);
            modal.querySelector('[data-lf-pane="footnotes"]').textContent =
                JSON.stringify(parsed, null, 2);
        } catch (_) {
            modal.querySelector('[data-lf-pane="footnotes"]').textContent = footnotesJson;
        }
        footnoteRow.hidden = false;
    } else {
        footnoteRow.hidden = true;
    }

    modal._lfPending = { button, translatedContent, translatedTitle, footnotesJson };
    modal.hidden = false;
}

function closeDiffModal(modal) {

    modal.hidden = true;
    modal._lfPending = null;
}

/**
 * The actual editor write. Same logic as the previous direct-apply path,
 * lifted into its own function so it only runs when the user confirms.
 */
async function performApplyFromModal(modal) {

    const ctx = modal._lfPending;
    if (!ctx) return;

    const { button, translatedContent, translatedTitle, footnotesJson } = ctx;

    const applyBtn = modal.querySelector('.lingua-forge-diff-modal__apply');
    applyBtn.disabled    = true;
    applyBtn.textContent = __( 'Applying…', 'lingua-forge' );

    try {

        const data = getEditorStore();

        if (data) {

            const payload = { content: translatedContent };
            if (translatedTitle) payload.title = translatedTitle;
            if (footnotesJson)   payload.meta  = { footnotes: footnotesJson };

            const editorSelect  = data.select('core/editor');
            const beforeContent = editorSelect.getEditedPostAttribute('content') ?? '';

            await data.dispatch('core/editor').editPost(payload);

            // Verify the dispatch actually took effect — Gutenberg sometimes
            // accepts the call but discards content if a block parse error
            // happens upstream. Idempotent re-apply also counts as "applied".
            const afterContent   = editorSelect.getEditedPostAttribute('content') ?? '';
            const contentChanged = afterContent !== beforeContent;
            const contentMatches = afterContent.trim() === translatedContent.trim();

            if (!contentChanged && !contentMatches) {
                throw new Error( __( 'The editor did not accept the content — please try again.', 'lingua-forge' ) );
            }

        } else {

            // Classic-editor fallback.
            const classicEditor = document.querySelector('#content');
            if (!classicEditor) throw new Error( __( 'Classic editor element not found.', 'lingua-forge' ) );

            classicEditor.value = translatedContent;

            if (translatedTitle) {
                const classicTitle = document.querySelector('#title');
                if (classicTitle) classicTitle.value = translatedTitle;
            }
        }

        // Success — update the calling button's state and close the modal.
        button.textContent = __( 'Applied ✓', 'lingua-forge' );
        button.disabled    = true;
        showApplyHint(button, __( 'Save the post to persist changes.', 'lingua-forge' ));

        closeDiffModal(modal);

    } catch (err) {

        applyBtn.disabled    = false;
        applyBtn.textContent = __( 'Apply translation', 'lingua-forge' );
        // Inline error inside the modal so the user can read it without
        // closing the preview.
        let errBar = modal.querySelector('.lingua-forge-diff-modal__error');
        if (!errBar) {
            errBar = document.createElement('div');
            errBar.className = 'lingua-forge-diff-modal__error';
            modal.querySelector('.lingua-forge-diff-modal__actions').before(errBar);
        }
        errBar.textContent = err.message || __( 'Apply failed — please try again.', 'lingua-forge' );
    }
}

/**
 * HTML-escape for text content sent through innerHTML interpolation in the
 * modal template. Same helper as elsewhere in admin.js.
 */
function escHtml(v) {
    return String(v)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;');
}

function escAttr(v) {
    return String(v)
        .replace(/&/g,  '&amp;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;');
}

/**
 * Show an inline hint beneath the Apply button's row (e.g. "click Update to save").
 * Replaces any previous hint. Auto-removed after 6 seconds.
 */
function showApplyHint(button, message) {

    const btnRow = button.closest('.lingua-forge-btn-row');
    if (!btnRow) return;

    let hint = btnRow.nextElementSibling?.classList.contains('lingua-forge-apply-hint')
        ? btnRow.nextElementSibling
        : null;

    if (!hint) {
        hint = document.createElement('span');
        hint.className = 'lingua-forge-apply-hint';
        btnRow.insertAdjacentElement('afterend', hint);
    }

    hint.textContent = message;
    setTimeout(() => hint.remove(), 6000);
}

/**
 * Show an inline error message beneath the Apply button's row.
 */
function showApplyError(button, message) {

    const btnRow = button.closest('.lingua-forge-btn-row');
    if (!btnRow) return;

    let notice = btnRow.querySelector('.lingua-forge-apply-error');

    if (!notice) {
        notice = document.createElement('span');
        notice.className = 'lingua-forge-apply-error';
        btnRow.insertAdjacentElement('afterend', notice);
    }

    notice.textContent = message;
}

/**
 * Remove any previously shown Apply error notice.
 */
function clearApplyError(button) {

    const btnRow = button.closest('.lingua-forge-btn-row');
    if (!btnRow) return;

    btnRow.nextElementSibling
        ?.classList.contains('lingua-forge-apply-error')
        && btnRow.nextElementSibling.remove();
}

// ─── "Copy" button ───────────────────────────────────────────────────────────

document.addEventListener('click', async (event) => {

    const button = event.target.closest('.lingua-forge-copy');

    if (!button) return;

    // Scope the textarea search to the nearest result container so it does
    // not accidentally grab a field textarea from another feature group.
    const resultContainer =
        button.closest('.lingua-forge-content-result') ||
        button.closest('.lingua-forge-result');

    const textarea = resultContainer
        ? resultContainer.querySelector('.lingua-forge-textarea')
        : button.closest('.lingua-forge-panel')?.querySelector('.lingua-forge-textarea');

    if (!textarea) return;

    try {

        await navigator.clipboard.writeText(textarea.value);

        button.textContent = __( 'Copied ✓', 'lingua-forge' );

        setTimeout(() => { button.textContent = __( 'Copy', 'lingua-forge' ); }, 2000);

    } catch (_) {

        // Fallback for browsers that restrict clipboard access.
        textarea.select();
        document.execCommand('copy');

        button.textContent = __( 'Copied ✓', 'lingua-forge' );

        setTimeout(() => { button.textContent = __( 'Copy', 'lingua-forge' ); }, 2000);
    }
});

// ─── "Apply to Meta Description" button ──────────────────────────────────────

document.addEventListener('click', (event) => {

    const button = event.target.closest('.lingua-forge-apply-meta');
    if (!button) return;

    const textarea = button
        .closest('.lingua-forge-result')
        ?.querySelector('.lingua-forge-textarea');

    if (!textarea) return;

    const value = textarea.value.trim();
    if (!value) return;

    button.disabled = true;

    // ── Write to the meta box field (same iframe as AI panel) ────────────────
    // Both meta boxes are rendered inside the Gutenberg meta box iframe, so
    // getElementById reaches lf_meta_description_field directly.
    const metaField = document.getElementById('lf_meta_description_field');
    if (metaField) {
        metaField.value = value;
        metaField.dispatchEvent(new Event('input')); // triggers character counter
    }

    // ── Write to the Gutenberg editor store ───────────────────────────────────
    // Keeps the value in sync so the block editor doesn't overwrite it with
    // the stale DB value when the user clicks Update.
    if (window.parent.wp?.data) {
        window.parent.wp.data
            .dispatch('core/editor')
            ?.editPost({ meta: { meta_description: value } });
    }

    // ── Feedback ──────────────────────────────────────────────────────────────
    button.textContent = __( 'Applied ✓', 'lingua-forge' );

    // Re-enable after a short pause; remind the user to save.
    setTimeout(() => {
        button.textContent = __( 'Apply to Meta Description', 'lingua-forge' );
        button.disabled    = false;
    }, 2000);
});

// ─── Core fetch + render ──────────────────────────────────────────────────────

/**
 * Call a feature endpoint and render the result into the given container.
 * Shared by the main action button and the force-refresh button.
 */
async function runFeature(featureKey, postId, params, resultEl) {

    resultEl.innerHTML = `<p class="lingua-forge-status">${ __( 'Generating…', 'lingua-forge' ) }</p>`;

    try {

        const response = await fetch(
            `${LinguaForgeAI.restUrl}/feature/${featureKey}/${postId}`,
            {
                method:  'POST',
                headers: {
                    'X-WP-Nonce':   LinguaForgeAI.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(params),
            }
        );

        const data = await response.json();

        if (!data.success || !data.output) {

            const msg = data.error || __( 'Generation failed.', 'lingua-forge' );
            resultEl.innerHTML = `<p class="lingua-forge-error">${escapeHtml(msg)}</p>`;
            return;
        }

        if (data.type === 'content') {

            renderContentResult(resultEl, data, featureKey, postId);

        } else if (data.type === 'chunk') {

            renderChunkResult(resultEl, data);

        } else {

            renderTextResult(resultEl, data, featureKey, postId);
        }

    } catch (_) {

        resultEl.innerHTML = `<p class="lingua-forge-error">${ __( 'Request failed.', 'lingua-forge' ) }</p>`;
    }
}

// ─── Render helpers ───────────────────────────────────────────────────────────

/**
 * Render the result area for full-content outputs (translations, generated content…).
 *
 * Builds the meta summary line dynamically based on which feature produced
 * the result, so new content-type features don't require JS changes.
 */
function renderContentResult(container, data, featureKey, postId) {

    const footnotesAttr = data.footnotes
        ? ` data-footnotes="${escapeAttr(data.footnotes)}"`
        : '';

    const titleAttr = data.translated_title
        ? ` data-translated-title="${escapeAttr(data.translated_title)}"`
        : '';

    const cachedBadge = data.cached
        ? ` <span class="lingua-forge-cached-badge">${ __( 'cached', 'lingua-forge' ) }</span>`
        : '';

    // ── Meta summary line (feature-specific) ──────────────────────────────────
    let metaSummary;

    if (featureKey === 'translation' && data.language) {

        metaSummary = `${ __( 'Translated to:', 'lingua-forge' ) } <strong>${escapeHtml(data.language)}</strong>`;

    } else if (featureKey === 'content-generator') {

        const parts = [];
        if (data.content_type) parts.push(escapeHtml(data.content_type));
        if (data.tone)         parts.push(escapeHtml(data.tone) + ' ' + __( 'tone', 'lingua-forge' ));
        metaSummary = parts.length
            ? parts.join(' · ')
            : __( 'Content generated', 'lingua-forge' );

    } else {

        metaSummary = __( 'Content ready', 'lingua-forge' );
    }

    // ── Footnotes note (translation only) ─────────────────────────────────────
    const footnotesNote = data.footnotes
        ? `<p class="lingua-forge-result-meta">
               ${ countFootnotes(data.footnotes) } ${ __( 'footnote(s) translated — applied together with content.', 'lingua-forge' ) }
           </p>`
        : '';

    const refreshRow = data.cached
        ? renderRefreshRow(featureKey, postId)
        : '';

    const applyLabel = __( 'Apply to Editor', 'lingua-forge' );
    const copyLabel  = __( 'Copy',            'lingua-forge' );

    container.innerHTML = `
        <div class="lingua-forge-content-result"${footnotesAttr}${titleAttr}>
            <p class="lingua-forge-result-meta">
                ${metaSummary}${cachedBadge}
            </p>
            ${footnotesNote}
            <textarea
                class="lingua-forge-textarea lingua-forge-textarea--large"
                rows="10"
            >${escapeHtml(data.output)}</textarea>
            <div class="lingua-forge-btn-row">
                <button
                    type="button"
                    class="button button-primary lingua-forge-apply"
                >${applyLabel}</button>
                <button
                    type="button"
                    class="button button-secondary lingua-forge-copy"
                >${copyLabel}</button>
            </div>
            ${refreshRow}
        </div>`;
}

/**
 * Render the result area for a chunk translation.
 *
 * No "Apply to Editor" button — the user copies and pastes the translated
 * snippet manually wherever it belongs (e.g. into a footnote field).
 */
function renderChunkResult(container, data) {

    const lang = data.language
        ? `${ __( 'Chunk translated to:', 'lingua-forge' ) } <strong>${escapeHtml(data.language)}</strong>`
        : __( 'Chunk translated', 'lingua-forge' );

    const chunkHint = __( 'Copy the result and paste it wherever needed (e.g. directly into the footnote field).', 'lingua-forge' );
    const copyLabel = __( 'Copy', 'lingua-forge' );

    container.innerHTML = `
        <div class="lingua-forge-content-result lingua-forge-chunk-result">
            <p class="lingua-forge-result-meta">${lang}</p>
            <p class="lingua-forge-chunk-hint">
                ${chunkHint}
            </p>
            <textarea
                class="lingua-forge-textarea lingua-forge-textarea--large"
                rows="8"
            >${escapeHtml(data.output)}</textarea>
            <div class="lingua-forge-btn-row">
                <button
                    type="button"
                    class="button button-secondary lingua-forge-copy"
                >${copyLabel}</button>
            </div>
        </div>`;
}

/**
 * Render the result area for short text outputs (meta descriptions, excerpts…).
 *
 * Always includes a Copy button.  For meta-description results an ⓘ info
 * button is also rendered; hovering/focusing it reveals a character-count
 * overlay with an SEO quality hint.
 */
function renderTextResult(container, data, featureKey, postId) {

    const text = data.output || '';

    const cachedBadge = data.cached
        ? ` <span class="lingua-forge-cached-badge">${ __( 'cached', 'lingua-forge' ) }</span>`
        : '';

    const refreshRow = data.cached
        ? renderRefreshRow(featureKey, postId)
        : '';

    const infoHtml = featureKey === 'meta-description'
        ? buildMetaInfoOverlay(text.length)
        : '';

    const copyLabel  = __( 'Copy', 'lingua-forge' );

    // "Apply to Meta Description" gets its own full-width row below the textarea
    // so it is always visible regardless of how many items are in the result bar.
    const applyMetaHtml = featureKey === 'meta-description'
        ? `<button
                type="button"
                class="button button-primary lingua-forge-apply-meta"
            >${ __( 'Apply to Meta Description', 'lingua-forge' ) }</button>`
        : '';

    container.innerHTML = `
        <p class="lingua-forge-result-meta">${cachedBadge}</p>
        <div class="lingua-forge-textarea-wrap">
            <textarea
                class="lingua-forge-textarea"
                rows="3"
            >${escapeHtml(text)}</textarea>
        </div>
        <div class="lingua-forge-result-bar">
            <button
                type="button"
                class="button button-secondary lingua-forge-copy"
            >${copyLabel}</button>
            ${infoHtml}
        </div>
        ${applyMetaHtml}
        ${refreshRow}`;
}

/**
 * Build the ⓘ info button + character-count tooltip for meta descriptions.
 *
 * Quality thresholds:
 *   140–160 chars → green  (optimal SERP real estate)
 *   120–139 / 161–180 → amber  (borderline)
 *   < 120 or > 180      → red    (too short / too long)
 */
function buildMetaInfoOverlay(charCount) {

    let quality, qualityClass;

    /* translators: %d is replaced by the number of characters */
    const charsLabel = __( 'chars', 'lingua-forge' );

    if (charCount >= 140 && charCount <= 160) {

        quality      = `${ __( '✓ Good length', 'lingua-forge' ) } (${charCount} ${charsLabel})`;
        qualityClass = '--good';

    } else if (charCount >= 120 && charCount <= 180) {

        quality      = `${ __( '⚠ Borderline', 'lingua-forge' ) } (${charCount} ${charsLabel})`;
        qualityClass = '--warn';

    } else {

        quality      = charCount < 120
            ? `${ __( '✗ Too short', 'lingua-forge' ) } (${charCount} ${charsLabel})`
            : `${ __( '✗ Too long',  'lingua-forge' ) } (${charCount} ${charsLabel})`;
        qualityClass = '--bad';
    }

    const targetHint = __( 'Target: 140–160 chars for optimal SERP display', 'lingua-forge' );

    return `
        <div class="lingua-forge-info-wrap">
            <button
                type="button"
                class="lingua-forge-info-btn"
                aria-label="${ escapeAttr( __( 'SEO character-count info', 'lingua-forge' ) ) }"
            >ⓘ</button>
            <div class="lingua-forge-info-overlay" role="tooltip">
                <span class="lingua-forge-info-quality ${qualityClass}">${quality}</span>
                <span class="lingua-forge-info-hint">${targetHint}</span>
            </div>
        </div>`;
}

/**
 * Render the force-refresh button with its explanatory hint.
 * Only shown when the current result came from the cache.
 */
function renderRefreshRow(featureKey, postId) {

    const refreshLabel = __( '↺ Refresh', 'lingua-forge' );
    const refreshHint  = __( 'Re-generates and updates the cached result.', 'lingua-forge' );

    return `
        <div class="lingua-forge-refresh-row">
            <button
                type="button"
                class="lingua-forge-refresh"
                data-feature="${escapeAttr(featureKey)}"
                data-post-id="${escapeAttr(postId)}"
            >${refreshLabel}</button>
            <span class="lingua-forge-refresh-hint">
                ${refreshHint}
            </span>
        </div>`;
}

// ─── Utilities ────────────────────────────────────────────────────────────────

/**
 * Collect the values of any extra UI fields belonging to a feature.
 * Handles both <select> and <textarea> input fields.
 *
 * For the translation feature, also reads the current footnotes value
 * from the Gutenberg editor meta store (window.parent.wp.data) so that
 * unsaved footnotes are captured even before the post is saved to the DB.
 */
function collectParams(panel, featureKey) {

    const params = {};

    panel
        .querySelectorAll(
            `.lingua-forge-select[data-feature-ref="${featureKey}"],` +
            `.lingua-forge-input-textarea[data-feature-ref="${featureKey}"]`
        )
        .forEach((field) => {
            params[field.dataset.field] = field.value;
        });

    // Pull footnotes from the live editor meta store so translation always
    // works against the current in-editor state, not the last-saved DB value.
    // The meta key is "footnotes" both in the Gutenberg editor store and in the DB.
    // Skip this in chunk mode — the user supplies the text to translate directly.
    if (featureKey === 'translation' && params.translate_mode !== 'chunk' && window.parent.wp?.data) {

        const meta = window.parent.wp.data
            .select('core/editor')
            ?.getEditedPostAttribute('meta');

        if (meta && typeof meta.footnotes === 'string' && meta.footnotes !== '') {
            params.footnotes_meta = meta.footnotes;
        }
    }

    return params;
}

/**
 * Count entries in a JSON-encoded footnotes array.
 */
function countFootnotes(json) {

    try {
        return JSON.parse(json).length;
    } catch (_) {
        return 0;
    }
}

/**
 * Escape a value for safe use inside an HTML attribute.
 */
function escapeAttr(value) {

    return String(value)
        .replace(/&/g,  '&amp;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;');
}

/**
 * Safely escape a string for use inside innerHTML.
 *
 * Replaces only the five characters that are unsafe in HTML contexts.
 * Intentionally avoids the div.innerText / div.innerHTML trick because
 * that approach converts \n newlines to <br> elements — which corrupts
 * Gutenberg block markup where newlines between block comments and inner
 * HTML are structurally meaningful.
 */
function escapeHtml(value) {

    return String(value)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;');
}

} )();
