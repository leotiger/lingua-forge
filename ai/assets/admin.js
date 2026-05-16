/* LinguaForge AI – admin meta box interactions */
/* global wp */

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

// ─── "Apply to Editor" button ────────────────────────────────────────────────

document.addEventListener('click', async (event) => {

    const button = event.target.closest('.lingua-forge-apply');

    if (!button) return;

    const panel           = button.closest('.lingua-forge-panel');
    const result          = button.closest('.lingua-forge-content-result');
    const textarea        = panel.querySelector('.lingua-forge-textarea');
    const translatedTitle = result?.dataset.translatedTitle || '';

    if (!textarea) return;

    // Clear any previous error and show an in-progress state.
    clearApplyError(button);
    button.disabled    = true;
    button.textContent = __( 'Applying…', 'lingua-forge' );

    try {

        const cleanContent = textarea.value;

        // Gutenberg loads meta boxes inside an iframe — wp.data lives in the
        // parent window, not in this iframe context.  window.parent.wp.data
        // is the live editor store; dispatching to window.wp.data (the iframe)
        // is a no-op that leaves the editor unchanged.
        if (window.parent.wp?.data) {

            const payload = { content: cleanContent };
            if (translatedTitle) payload.title = translatedTitle;

            // Apply footnotes through the Gutenberg store so they are part of
            // the same save cycle as the content.  Writing directly to the DB
            // via a REST call would be overwritten the moment the user hits
            // Save, because Gutenberg flushes its own meta.footnotes on save.
            const footnotesJson = result?.dataset.footnotes || '';
            if (footnotesJson) payload.meta = { footnotes: footnotesJson };

            // Snapshot the editor's current content so we can verify the
            // dispatch actually took effect after it resolves.
            const editorSelect  = window.parent.wp.data.select('core/editor');
            const beforeContent = editorSelect.getEditedPostAttribute('content') ?? '';

            await window.parent.wp.data
                .dispatch('core/editor')
                .editPost(payload);

            // Verify: the store must now hold different content, or content
            // that already matched what we sent (idempotent re-apply).
            const afterContent  = editorSelect.getEditedPostAttribute('content') ?? '';
            const contentChanged = afterContent !== beforeContent;
            const contentMatches = afterContent.trim() === cleanContent.trim();

            if (!contentChanged && !contentMatches) {
                throw new Error('The editor did not accept the content — please try again.');
            }

        } else {

            // Classic editor: no iframe, #content and #title are in this document.
            const classicEditor = document.querySelector('#content');
            if (!classicEditor) throw new Error('Classic editor element not found.');

            classicEditor.value = cleanContent;

            if (translatedTitle) {
                const classicTitle = document.querySelector('#title');
                if (classicTitle) classicTitle.value = translatedTitle;
            }
        }

        // ── Auto-save ─────────────────────────────────────────────────────────
        // Trigger a native Gutenberg save so the DB reflects the translated
        // content immediately.  This is needed because other features (e.g.
        // Meta Description Generator) read post_content from the database —
        // without saving here they would still see the pre-translation content.
        button.textContent = __( 'Saving…', 'lingua-forge' );

        try {
            await window.parent.wp.data
                .dispatch('core/editor')
                .savePost();

            button.textContent = __( 'Saved ✓', 'lingua-forge' );

        } catch (_saveErr) {
            // Non-fatal: the editor content was applied; only the auto-save
            // failed.  Show a degraded confirmation so the user knows.
            button.textContent = __( 'Applied ✓ (auto-save failed)', 'lingua-forge' );
        }

        // Leave disabled — re-applying the same content is a no-op and confusing.

    } catch (err) {

        // Restore the button so the user can retry.
        button.textContent = __( 'Apply to Editor', 'lingua-forge' );
        button.disabled    = false;
        showApplyError(button, err.message || __( 'Apply failed — please try again.', 'lingua-forge' ));
    }
});

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

document.addEventListener('click', async (event) => {

    const button = event.target.closest('.lingua-forge-apply-meta');
    if (!button) return;

    const textarea = button
        .closest('.lingua-forge-result')
        ?.querySelector('.lingua-forge-textarea');

    if (!textarea) return;

    const value = textarea.value.trim();
    if (!value) return;

    button.disabled = true;

    // ── Write to the meta box field (classic meta box / same iframe) ──────────
    // Both the AI panel and the Meta Description meta box are rendered in the
    // same meta box iframe by Gutenberg, so getElementById reaches it directly.
    const metaField = document.getElementById('lf_meta_description_field');
    if (metaField) {
        metaField.value = value;
        // Trigger the character-counter script that listens for 'input'.
        metaField.dispatchEvent(new Event('input'));
    }

    // ── Write to the Gutenberg editor store ───────────────────────────────────
    // meta_description is registered with show_in_rest: true, so the block
    // editor tracks it.  Dispatching here ensures the value is included in
    // the next save cycle rather than being overwritten by Gutenberg flushing
    // stale meta on its own save.
    if (window.parent.wp?.data) {
        await window.parent.wp.data
            .dispatch('core/editor')
            ?.editPost({ meta: { meta_description: value } });
    }

    // ── Auto-save ─────────────────────────────────────────────────────────────
    // Persist immediately so the stored meta description is up to date in the
    // DB — consistent with "Apply to Editor" behaviour for translation.
    button.textContent = __( 'Saving…', 'lingua-forge' );

    try {
        if (window.parent.wp?.data) {
            await window.parent.wp.data
                .dispatch('core/editor')
                .savePost();
        }

        button.textContent = __( 'Saved ✓', 'lingua-forge' );

    } catch (_) {
        button.textContent = __( 'Applied ✓ (auto-save failed)', 'lingua-forge' );
    }

    // Re-enable after a short pause so a second click doesn't trigger a
    // duplicate save request.
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
    const applyHtml  = featureKey === 'meta-description'
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
            ${applyHtml}
            ${infoHtml}
        </div>
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
