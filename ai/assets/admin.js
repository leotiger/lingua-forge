/* LinguaForge AI – admin meta box interactions */
/* global wp */
( function () {

const { __ } = wp.i18n;

const RTL_LANGS = new Set(['ar', 'he', 'fa', 'ur']);
function isRtlLang(code) {
    return RTL_LANGS.has((code || '').toLowerCase());
}

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

    const panel             = button.closest('.lingua-forge-panel');
    const result            = button.closest('.lingua-forge-content-result');
    const textarea          = panel.querySelector('.lingua-forge-textarea');
    const translatedTitle   = result?.dataset.translatedTitle   || '';
    const footnotesJson     = result?.dataset.footnotes         || '';
    const metaDescription   = result?.dataset.metaDescription   || '';
    const translatedExcerpt = result?.dataset.translatedExcerpt || '';
    const targetLang        = result?.dataset.targetLang        || '';

    if (!textarea) return;

    clearApplyError(button);

    // ── Classic editor (TinyMCE) fast path ───────────────────────────────────
    // The diff modal relies on snapshotCurrentEditorState() reading the hidden
    // #content textarea, which TinyMCE never syncs until save — so the "current"
    // side would be empty or stale. Skip the modal and apply directly instead.
    // Use isGutenbergActive() not getEditorStore(): wp.data is loaded on classic
    // editor screens too (e.g. WooCommerce product pages), so a non-null store
    // does not mean the block editor is actually mounted.
    if (!isGutenbergActive()) {
        applyToClassicEditor({
            translatedContent: textarea.value,
            translatedTitle,
            translatedExcerpt,
            metaDescription,
            button,
        });
        return;
    }

    window.LfAdmin.openApplyDiffModal({
        button,
        translatedContent: textarea.value,
        translatedTitle,
        footnotesJson,
        metaDescription,
        translatedExcerpt,
        targetLang,
    });
});

/**
 * Resolve the live editor store from whichever side of the meta-box iframe
 * boundary the code is running on. Returns null when wp.data is unavailable.
 *
 * NOTE: wp.data is loaded as a JS dependency on almost every admin screen,
 * so a non-null return does NOT mean Gutenberg is active. Use
 * isGutenbergActive() to test whether core/editor is actually editing a post.
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
 * Return true only when the block editor (Gutenberg) is actually mounted and
 * managing a post on the current screen.
 *
 * wp.data / core/editor are loaded as JS dependencies on many admin pages
 * (including classic WooCommerce product edit screens), so getEditorStore()
 * being non-null is not sufficient. We confirm by checking that core/editor
 * has a real post ID — that selector returns 0 / undefined on screens where
 * the block editor is not active.
 */
function isGutenbergActive() {

    const store = getEditorStore();
    if (!store) return false;

    try {
        const postId = store.select('core/editor')?.getCurrentPostId();
        return !! postId;
    } catch (_) {
        return false;
    }
}

/**
 * Find an element by ID inside any accessible <iframe> on the page.
 *
 * Used as a fallback when code runs in the main-window context but the
 * target element (e.g. lf_meta_description_field) lives inside the
 * Gutenberg classic-metabox iframe.  Same-origin only — cross-origin
 * iframes will throw and are silently skipped.
 *
 * @param  {string}      id  Element ID to look for.
 * @return {Element|null}    First matching element, or null.
 */
function findInIframes(id) {

    for (const frame of document.querySelectorAll('iframe')) {
        try {
            const el = frame.contentDocument?.getElementById(id);
            if (el) return el;
        } catch (_) {
            // Cross-origin iframe — skip silently.
        }
    }
    return null;
}

/**
 * Snapshot the current editor title + content as a {title, content} pair.
 * Falls back to the classic-editor #title / #content fields when Gutenberg
 * isn't available.
 */
function snapshotCurrentEditorState() {

    if (isGutenbergActive()) {
        const sel = getEditorStore().select('core/editor');
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

// Standalone content gen modal (ensureContentGenOverlay, openContentGenOverlay,
// wireContentGenOverlay, closeContentGenOverlay, applyContentGenToEditor,
// runContentGenRefinement) — see admin-content-gen-modal.js.

/**
 * Convert a translated title into a URL-safe slug for the Gutenberg editor's
 * slug field.  Latin Extended A/B characters (accented letters used in
 * Spanish, Catalan, French, German, etc.) are preserved so WordPress can
 * transliterate them correctly server-side.  The server always runs the value
 * through sanitize_title() + wp_unique_post_slug(), so this is a best-effort
 * pre-clean to avoid the permalink panel showing raw text with spaces.
 *
 * @param  {string} str  Translated post title.
 * @return {string}      Slug-safe string.
 */
function lfSlugify(str) {
    return str
        .trim()
        .toLowerCase()
        .replace(/[\s_]+/g, '-')             // spaces / underscores → hyphens
        .replace(/[^\wÀ-ɏ-]/g, '') // keep ASCII word chars, Latin Extended A-B, hyphens
        .replace(/-{2,}/g, '-')              // collapse consecutive hyphens
        .replace(/^-+|-+$/g, '');            // trim leading / trailing hyphens
}

/**
 * Apply translated content directly to the classic (TinyMCE) editor,
 * bypassing the diff modal which cannot snapshot TinyMCE state reliably.
 *
 * Uses tinyMCE.get('content').setContent() when TinyMCE is active so the
 * visual canvas updates immediately. Falls back to writing the hidden
 * #content textarea directly (e.g. when TinyMCE is temporarily disabled).
 */
function applyToClassicEditor({ translatedContent, translatedTitle, translatedExcerpt, metaDescription, button }) {

    // ── Content ───────────────────────────────────────────────────────────────
    /* global tinyMCE */
    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
        tinyMCE.get('content').setContent(translatedContent);
        // Keep the hidden textarea in sync so WP picks up the value on save.
        const ta = document.querySelector('#content');
        if (ta) ta.value = tinyMCE.get('content').getContent();
    } else {
        const ta = document.querySelector('#content');
        if (ta) ta.value = translatedContent;
    }

    // ── Title ─────────────────────────────────────────────────────────────────
    if (translatedTitle) {
        const titleEl = document.querySelector('#title');
        if (titleEl) titleEl.value = translatedTitle;
    }

    // ── Excerpt / Short Description ───────────────────────────────────────────
    // WooCommerce Short Description renders a separate TinyMCE instance with
    // id 'excerpt'. Try that first; fall back to the plain <textarea id="excerpt">.
    if (translatedExcerpt) {
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('excerpt')) {
            tinyMCE.get('excerpt').setContent(translatedExcerpt);
            const ea = document.querySelector('#excerpt');
            if (ea) ea.value = tinyMCE.get('excerpt').getContent();
        } else {
            const ea = document.querySelector('#excerpt');
            if (ea) ea.value = translatedExcerpt;
        }
    }

    // ── Meta description ──────────────────────────────────────────────────────
    if (metaDescription) {
        const metaField = document.getElementById('lf_meta_description_field')
            || findInIframes('lf_meta_description_field');
        if (metaField) {
            metaField.value = metaDescription;
            metaField.dispatchEvent(new Event('input'));
        }
    }

    // ── Feedback on the button ────────────────────────────────────────────────
    if (button) {
        button.disabled    = true;
        button.textContent = __( 'Applied ✓', 'lingua-forge' );
        setTimeout(() => {
            button.disabled    = false;
            button.textContent = __( 'Apply to Editor', 'lingua-forge' );
        }, 2000);
    }
}

// performApplyFromModal — see admin-diff-modal.js.

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

/**
 * Sanitize an HTML string before assigning to innerHTML.
 *
 * Parses via DOMParser (no script execution during parsing), walks the result
 * tree to strip dangerous elements and attributes, then serialises back to a
 * string via body.innerHTML.  Preserves all structural content markup needed
 * for diff and preview panes (<p>, <h2>, <ul>, block comments, etc.).
 *
 * Applied to every innerHTML assignment whose value originates from the
 * network (AI provider response) or the DOM (editor state) to resolve the
 * CodeQL js/xss-through-dom findings.
 *
 * @param {string} html  Untrusted HTML string.
 * @returns {string}     Sanitized HTML string safe for innerHTML assignment.
 */
function sanitizeHtml( html ) {
    // Intentional use of DOMParser: this IS the sanitizer. The untrusted string is
    // parsed in a detached document (no script execution, no live DOM attachment).
    // The walk below strips all dangerous tags and attributes before returning
    // doc.body.innerHTML. The codeql suppression is on the triggering line itself.
    const doc = new DOMParser().parseFromString( String( html ), 'text/html' ); // codeql[js/xss-through-dom]

    // Tags whose presence — regardless of content — constitutes a risk.
    const DANGEROUS_TAGS = new Set( [
        'script', 'style', 'iframe', 'object', 'embed',
        'base', 'form', 'input', 'button', 'select',
        'textarea', 'noscript', 'template', 'link', 'meta',
    ] );

    // Attributes whose name starts with "on" are event handlers.
    const IS_HANDLER = /^on/i;

    // Attributes that carry a URL where javascript: is dangerous.
    const URL_ATTRS = new Set( [ 'href', 'src', 'action', 'formaction', 'data' ] );

    ( function walk( node ) {
        let child = node.firstChild;
        while ( child ) {
            const next = child.nextSibling;
            if ( child.nodeType === Node.ELEMENT_NODE ) {
                if ( DANGEROUS_TAGS.has( child.tagName.toLowerCase() ) ) {
                    node.removeChild( child );
                } else {
                    // Strip event-handler attributes and javascript: URLs.
                    for ( const attr of Array.from( child.attributes ) ) {
                        if ( IS_HANDLER.test( attr.name ) ) {
                            child.removeAttribute( attr.name );
                        } else if (
                            URL_ATTRS.has( attr.name.toLowerCase() ) &&
                            /^\s*javascript\s*:/i.test( attr.value )
                        ) {
                            child.removeAttribute( attr.name );
                        }
                    }
                    walk( child );
                }
            }
            child = next;
        }
    } )( doc.body );

    return doc.body.innerHTML;
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
// eslint-disable-next-line no-unused-vars -- Reserved for future error-display wiring; pairs with clearApplyError below. Currently unused after an earlier refactor removed the call site.
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
    // Must use the REST-registered meta key (_linguaforge_meta_description),
    // not the legacy key (meta_description) which is not exposed to the store.
    const store = getEditorStore();
    if (store) {
        store.dispatch('core/editor')
            ?.editPost({ meta: { _linguaforge_meta_description: value } });
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

            // When the action button lives inside a feature overlay, show the
            // result inline in that same overlay (no second modal on top).
            const featureOverlay = resultEl.closest('.lf-feature-overlay');

            if (featureKey === 'content-generator') {
                if (featureOverlay) {
                    showContentGenInOverlay(featureOverlay, data, params, postId);
                } else {
                    // Content generation gets its own dedicated overlay with
                    // iterative refinement — no side-by-side diff needed here.
                    resultEl.innerHTML =
                        `<p class="lingua-forge-status">${ escHtml( __( '✓ Content generated — see the overlay to review, refine, and apply.', 'lingua-forge' ) ) }</p>`;
                    window.LfAdmin.openContentGenOverlay(data, params, postId);
                }
            } else if (featureOverlay) {
                showTranslationDiffInOverlay(featureOverlay, data, params);
            } else {
                renderContentResult(resultEl, data, featureKey, postId, params.target_language);
            }

        } else if (data.type === 'chunk') {

            renderChunkResult(resultEl, data, featureKey, postId, params.target_language);

        } else {

            renderTextResult(resultEl, data, featureKey, postId, params.target_language);
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
function renderContentResult(container, data, featureKey, postId, targetLang = '') {

    const footnotesAttr = data.footnotes
        ? ` data-footnotes="${escapeAttr(data.footnotes)}"`
        : '';

    const titleAttr = data.translated_title
        ? ` data-translated-title="${escapeAttr(data.translated_title)}"`
        : '';

    const metaDescAttr = data.meta_description
        ? ` data-meta-description="${escapeAttr(data.meta_description)}"`
        : '';

    const excerptAttr = data.translated_excerpt
        ? ` data-translated-excerpt="${escapeAttr(data.translated_excerpt)}"`
        : '';

    const targetLangAttr = targetLang
        ? ` data-target-lang="${escapeAttr(targetLang)}"`
        : '';

    const dirAttr = isRtlLang(targetLang) ? ' dir="rtl"' : '';

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
        <div class="lingua-forge-content-result"${footnotesAttr}${titleAttr}${metaDescAttr}${excerptAttr}${targetLangAttr}>
            <p class="lingua-forge-result-meta">
                ${metaSummary}${cachedBadge}
            </p>
            ${footnotesNote}
            <textarea
                class="lingua-forge-textarea lingua-forge-textarea--large"
                rows="10"${dirAttr}
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
function renderChunkResult(container, data, featureKey, postId, targetLang = '') {

    const langLabel = data.language
        ? `${ __( 'Chunk translated to:', 'lingua-forge' ) } <strong>${escapeHtml(data.language)}</strong>`
        : __( 'Chunk translated', 'lingua-forge' );

    const cachedBadge = data.cached
        ? ` <span class="lingua-forge-cached-badge">${ __( 'cached', 'lingua-forge' ) }</span>`
        : '';

    const refreshRow = data.cached
        ? renderRefreshRow(featureKey, postId)
        : '';

    const chunkHint = __( 'Copy the result and paste it wherever needed (e.g. directly into the footnote field).', 'lingua-forge' );
    const copyLabel = __( 'Copy', 'lingua-forge' );
    const dirAttr   = isRtlLang(targetLang) ? ' dir="rtl"' : '';

    container.innerHTML = `
        <div class="lingua-forge-content-result lingua-forge-chunk-result">
            <p class="lingua-forge-result-meta">${langLabel}${cachedBadge}</p>
            <p class="lingua-forge-chunk-hint">
                ${chunkHint}
            </p>
            <textarea
                class="lingua-forge-textarea lingua-forge-textarea--large"
                rows="8"${dirAttr}
            >${escapeHtml(data.output)}</textarea>
            <div class="lingua-forge-btn-row">
                <button
                    type="button"
                    class="button button-secondary lingua-forge-copy"
                >${copyLabel}</button>
            </div>
            ${refreshRow}
        </div>`;
}

/**
 * Render the result area for short text outputs (meta descriptions, excerpts…).
 *
 * Always includes a Copy button.  For meta-description results an ⓘ info
 * button is also rendered; hovering/focusing it reveals a character-count
 * overlay with an SEO quality hint.
 */
function renderTextResult(container, data, featureKey, postId, targetLang = '') {

    const text = data.output || '';
    const dirAttr = isRtlLang(targetLang) ? ' dir="rtl"' : '';

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
                rows="3"${dirAttr}
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
        qualityClass = 'lingua-forge-info-quality--good';

    } else if (charCount >= 120 && charCount <= 180) {

        quality      = `${ __( '⚠ Borderline', 'lingua-forge' ) } (${charCount} ${charsLabel})`;
        qualityClass = 'lingua-forge-info-quality--warn';

    } else {

        quality      = charCount < 120
            ? `${ __( '✗ Too short', 'lingua-forge' ) } (${charCount} ${charsLabel})`
            : `${ __( '✗ Too long',  'lingua-forge' ) } (${charCount} ${charsLabel})`;
        qualityClass = 'lingua-forge-info-quality--bad';
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
            `.lingua-forge-input-textarea[data-feature-ref="${featureKey}"],` +
            `.lingua-forge-checkbox[data-feature-ref="${featureKey}"]`
        )
        .forEach((field) => {
            params[field.dataset.field] = field.type === 'checkbox'
                ? (field.checked ? '1' : '')
                : field.value;
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

// ─── Feature overlay triggers ─────────────────────────────────────────────────
//
// "Translate page…" / "Generate content…" compact buttons open a single
// focused overlay.  The overlay starts in config mode (fields + action
// button); after the action runs the body transitions to the result view —
// two-column diff for translation or content-gen preview with refinement.
// No second modal ever opens on top.

/**
 * Reset an overlay back to its initial config state.
 *
 * Called on close (✕, backdrop, Escape) and on the "← Back" button inside
 * the result view.  Restores the config section, clears the result section,
 * and removes the wide-panel modifier added for the translation diff view.
 *
 * @param {Element} overlay  .lf-feature-overlay root element.
 */
function resetOverlayToConfig(overlay) {

    overlay.classList.remove('lf-feature-overlay--wide');

    const configSection = overlay.querySelector('.lf-feature-overlay__config-section');
    const resultSection = overlay.querySelector('.lf-feature-overlay__result-section');

    if (configSection) configSection.hidden = false;

    if (resultSection) {
        resultSection.hidden   = true;
        resultSection.innerHTML = '';
    }

    // Clear any inline result left over in the config section's result div.
    const resultEl = overlay.querySelector('.lingua-forge-result');
    if (resultEl) resultEl.innerHTML = '';

    overlay._lfPending  = null;
    overlay._lfCgState  = null;
}

// Open: reveal the overlay.
document.addEventListener('click', (event) => {

    const trigger = event.target.closest('.lf-overlay-trigger');
    if (!trigger) return;

    const targetId = trigger.dataset.lfOverlayTarget;
    if (!targetId) return;

    const overlay = document.getElementById(targetId);
    if (!overlay) return;

    // Hoist to <body> on first open so position:fixed is relative to the
    // full iframe viewport.  WP Admin ancestor elements (postbox wrappers,
    // theme styles) can create stacking contexts that clip fixed children,
    // exactly as they do in the existing diff-modal / CG-modal pattern.
    if (overlay.parentElement !== document.body) {
        document.body.appendChild(overlay);
    }

    overlay.hidden = false;
});

// Close: ✕ button or backdrop click.
document.addEventListener('click', (event) => {

    const btn = event.target.closest('[data-lf-overlay="close"]');
    if (!btn) return;

    const overlay = btn.closest('.lf-feature-overlay');
    if (!overlay) return;

    overlay.hidden = true;
    resetOverlayToConfig(overlay);
});

// Close: Escape key.
document.addEventListener('keydown', (event) => {

    if (event.key !== 'Escape') return;

    document.querySelectorAll('.lf-feature-overlay:not([hidden])').forEach((overlay) => {
        overlay.hidden = true;
        resetOverlayToConfig(overlay);
    });
});

// ─── Inline result renderers ──────────────────────────────────────────────────

/**
 * Transition a feature overlay to the translation diff view.
 *
 * Replaces the config section with a two-column diff (current vs translated)
 * using the existing diff-modal CSS classes so no extra styles are needed for
 * the content layout.  "Apply translation" applies directly; "← Back" returns
 * to the config view.  No second modal is opened.
 *
 * @param {Element} overlay  .lf-feature-overlay root.
 * @param {object}  data     REST response payload.
 * @param {object}  params   Original request params (target_language, …).
 */
function showTranslationDiffInOverlay(overlay, data, params) {

    const current       = snapshotCurrentEditorState();
    const targetLang    = params.target_language || '';
    const rtl           = isRtlLang(targetLang);
    const transContent   = data.output;
    const transTitle     = data.translated_title    || '';
    const footnotesJson  = data.footnotes           || '';
    const metaDesc       = data.meta_description    || '';
    const transExcerpt   = data.translated_excerpt  || '';

    // Widen the panel for two-column content.
    overlay.classList.add('lf-feature-overlay--wide');

    const titleRowHtml = transTitle ? `
        <section class="lingua-forge-diff-modal__title-row">
            <div class="lingua-forge-diff-modal__pane">
                <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Current title', 'lingua-forge' ) ) }</div>
                <div class="lingua-forge-diff-modal__title" data-lf-odiff="current-title"></div>
            </div>
            <div class="lingua-forge-diff-modal__pane lingua-forge-diff-modal__pane--new">
                <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Translated title', 'lingua-forge' ) ) }</div>
                <div class="lingua-forge-diff-modal__title" data-lf-odiff="new-title"></div>
            </div>
        </section>` : '';

    const footnotesHtml = footnotesJson ? `
        <section class="lingua-forge-diff-modal__footnotes">
            <details>
                <summary>${ escHtml( __( 'Footnotes payload (advanced)', 'lingua-forge' ) ) }</summary>
                <pre class="lingua-forge-diff-modal__footnotes-json" data-lf-odiff="footnotes"></pre>
            </details>
        </section>` : '';

    const metaDescHtml = metaDesc ? `
        <section class="lingua-forge-diff-modal__meta-desc">
            <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Generated meta description', 'lingua-forge' ) ) }</div>
            <p class="lingua-forge-diff-modal__meta-desc-text" data-lf-odiff="meta-desc"></p>
            <p class="lingua-forge-diff-modal__meta-desc-note">${ escHtml( __( 'Saved to the meta description field when you apply.', 'lingua-forge' ) ) }</p>
        </section>` : '';

    const resultSection = overlay.querySelector('.lf-feature-overlay__result-section');

    resultSection.innerHTML = `
        ${ titleRowHtml }
        <section class="lingua-forge-diff-modal__content-row lf-overlay-content-row">
            <div class="lingua-forge-diff-modal__pane">
                <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Current content (will be overwritten)', 'lingua-forge' ) ) }</div>
                <div class="lingua-forge-diff-modal__preview" data-lf-odiff="current-content"></div>
            </div>
            <div class="lingua-forge-diff-modal__pane lingua-forge-diff-modal__pane--new">
                <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Translated content', 'lingua-forge' ) ) }</div>
                <div class="lingua-forge-diff-modal__preview" data-lf-odiff="new-content"></div>
            </div>
        </section>
        ${ footnotesHtml }
        ${ metaDescHtml }
        <footer class="lf-overlay-result-footer">
            <button type="button" class="button" data-lf-odiff="back">
                ${ escHtml( __( '← Back', 'lingua-forge' ) ) }
            </button>
            <button type="button" class="button button-primary lf-overlay-apply-btn" data-lf-odiff="apply">
                ${ escHtml( __( 'Apply translation', 'lingua-forge' ) ) }
            </button>
        </footer>`;

    // Populate content — trust model matches the standalone diff modal:
    // both sides are admin-authored or AI output triggered by an editor.
    if (transTitle) {
        resultSection.querySelector('[data-lf-odiff="current-title"]').textContent = current.title;
        const newTitleEl = resultSection.querySelector('[data-lf-odiff="new-title"]');
        newTitleEl.textContent = transTitle;
        newTitleEl.dir         = rtl ? 'rtl' : '';
    }

    resultSection.querySelector('[data-lf-odiff="current-content"]').innerHTML = sanitizeHtml( current.content );
    const newContentEl = resultSection.querySelector('[data-lf-odiff="new-content"]');
    newContentEl.innerHTML = sanitizeHtml( transContent );
    newContentEl.dir       = rtl ? 'rtl' : '';

    if (footnotesJson) {
        try {
            const parsed = JSON.parse(footnotesJson);
            resultSection.querySelector('[data-lf-odiff="footnotes"]').textContent =
                JSON.stringify(parsed, null, 2);
        } catch (_) {
            resultSection.querySelector('[data-lf-odiff="footnotes"]').textContent = footnotesJson;
        }
    }

    if (metaDesc) {
        resultSection.querySelector('[data-lf-odiff="meta-desc"]').textContent = metaDesc;
    }

    overlay._lfPending = { transContent, transTitle, footnotesJson, metaDesc, transExcerpt };

    // Back button — restore config view.
    resultSection.querySelector('[data-lf-odiff="back"]').addEventListener('click', () => {
        resetOverlayToConfig(overlay);
    });

    // Apply button — same logic as performApplyFromModal, no outer modal needed.
    const applyBtn = resultSection.querySelector('[data-lf-odiff="apply"]');

    applyBtn.addEventListener('click', async () => {

        const ctx = overlay._lfPending;
        if (!ctx) return;

        applyBtn.disabled    = true;
        applyBtn.textContent = __( 'Applying…', 'lingua-forge' );

        try {

            const store = isGutenbergActive() ? getEditorStore() : null;

            if (store) {
                const payload = { content: ctx.transContent };
                if (ctx.transTitle)    {
                    payload.title = ctx.transTitle;
                    payload.slug  = lfSlugify(ctx.transTitle);
                }
                if (ctx.transExcerpt)  payload.excerpt = ctx.transExcerpt;
                if (ctx.footnotesJson) payload.meta    = { footnotes: ctx.footnotesJson };
                await store.dispatch('core/editor').editPost(payload);
            } else {
                applyToClassicEditor({
                    translatedContent: ctx.transContent,
                    translatedTitle:   ctx.transTitle,
                    translatedExcerpt: ctx.transExcerpt,
                    metaDescription:   '',
                });
            }

            if (ctx.metaDesc) {
                if (store) {
                    store.dispatch('core/editor')
                        ?.editPost({ meta: { _linguaforge_meta_description: ctx.metaDesc } });
                }
                const metaField = document.getElementById('lf_meta_description_field')
                    || findInIframes('lf_meta_description_field');
                if (metaField) {
                    metaField.value = ctx.metaDesc;
                    metaField.dispatchEvent(new Event('input'));
                }
            }

            applyBtn.textContent = __( 'Applied ✓', 'lingua-forge' );
            setTimeout(() => {
                overlay.hidden = true;
                resetOverlayToConfig(overlay);
            }, 900);

        } catch (err) {

            applyBtn.disabled    = false;
            applyBtn.textContent = __( 'Apply translation', 'lingua-forge' );

            let errBar = resultSection.querySelector('.lf-overlay-apply-error');
            if (!errBar) {
                errBar = document.createElement('div');
                errBar.className = 'lf-overlay-apply-error';
                applyBtn.closest('.lf-overlay-result-footer').before(errBar);
            }
            errBar.textContent = err.message || __( 'Apply failed — please try again.', 'lingua-forge' );
        }
    });

    // Transition: show result, hide config.
    resultSection.hidden = false;
    overlay.querySelector('.lf-feature-overlay__config-section').hidden = true;
}

/**
 * Transition a feature overlay to the content-generation result view.
 *
 * Renders the generated content preview with meta description and iterative
 * refinement — same UX as the standalone lf-cg-modal but entirely inline
 * in the overlay so no second modal ever opens on top.
 *
 * @param {Element} overlay  .lf-feature-overlay root.
 * @param {object}  data     REST response payload (output, tone, content_type…).
 * @param {object}  params   Original request params.
 * @param {string}  postId   WordPress post ID.
 */
function showContentGenInOverlay(overlay, data, params, postId) {

    const metaLine = [data.content_type, data.tone].filter(Boolean).join(' · ');

    const metaDescHtml = data.meta_description
        ? `<section class="lf-cg-modal__meta-desc" data-lf-ocg="meta-desc-section">
               <div class="lf-cg-modal__meta-desc-label">${ escHtml( __( 'Generated meta description', 'lingua-forge' ) ) }</div>
               <p class="lf-cg-modal__meta-desc-text" data-lf-ocg="meta-desc"></p>
           </section>`
        : `<section data-lf-ocg="meta-desc-section" hidden></section>`;

    const resultSection = overlay.querySelector('.lf-feature-overlay__result-section');

    resultSection.innerHTML = `
        <div class="lf-overlay-cg-preview">
            <p class="lf-cg-modal__meta" data-lf-ocg="meta">${ escHtml(metaLine) }</p>
            <div class="lf-cg-modal__preview" data-lf-ocg="preview"></div>
        </div>
        ${ metaDescHtml }
        <section class="lf-cg-modal__refine">
            <label class="lf-cg-modal__refine-label" for="lf-ocg-refine-input">
                ${ escHtml( __( 'Refine further:', 'lingua-forge' ) ) }
            </label>
            <div class="lf-cg-modal__refine-row">
                <textarea
                    id="lf-ocg-refine-input"
                    class="lf-cg-modal__refine-input"
                    rows="3"
                    placeholder="${ escAttr( __( 'Add instructions to improve the draft — e.g. make it shorter, add a conclusion section, use a more formal register…', 'lingua-forge' ) ) }"
                ></textarea>
                <button type="button" class="button lf-cg-modal__refine-btn" data-lf-ocg="refine">
                    ${ escHtml( __( '↺ Refine', 'lingua-forge' ) ) }
                </button>
            </div>
            <p class="lf-cg-modal__refine-status" data-lf-ocg="refine-status" hidden></p>
        </section>
        <footer class="lf-overlay-result-footer">
            <button type="button" class="button" data-lf-ocg="back">
                ${ escHtml( __( '← Back', 'lingua-forge' ) ) }
            </button>
            <button type="button" class="button button-secondary" data-lf-ocg="copy">
                ${ escHtml( __( 'Copy markup', 'lingua-forge' ) ) }
            </button>
            <button type="button" class="button button-primary lf-overlay-apply-btn" data-lf-ocg="apply">
                ${ escHtml( __( 'Apply to Editor', 'lingua-forge' ) ) }
            </button>
        </footer>`;

    // Populate preview.
    resultSection.querySelector('[data-lf-ocg="preview"]').innerHTML = sanitizeHtml( data.output );
    if (data.meta_description) {
        resultSection.querySelector('[data-lf-ocg="meta-desc"]').textContent = data.meta_description;
    }

    // State kept on the overlay node so the refinement loop can update it.
    overlay._lfCgState = {
        postId,
        params:               { ...params },
        currentOutput:        data.output,
        currentMetaDescription: data.meta_description || '',
        generation:           0,
    };

    // ── Back ──────────────────────────────────────────────────────────────────
    resultSection.querySelector('[data-lf-ocg="back"]').addEventListener('click', () => {
        resetOverlayToConfig(overlay);
    });

    // ── Copy ──────────────────────────────────────────────────────────────────
    resultSection.querySelector('[data-lf-ocg="copy"]').addEventListener('click', async () => {

        const output = overlay._lfCgState?.currentOutput ?? '';
        try { await navigator.clipboard.writeText(output); } catch (_) {}

        const btn  = resultSection.querySelector('[data-lf-ocg="copy"]');
        const prev = btn.textContent;
        btn.textContent = __( 'Copied ✓', 'lingua-forge' );
        setTimeout(() => { btn.textContent = prev; }, 2000);
    });

    // ── Apply ─────────────────────────────────────────────────────────────────
    const applyBtn = resultSection.querySelector('[data-lf-ocg="apply"]');

    applyBtn.addEventListener('click', () => {

        const output  = overlay._lfCgState?.currentOutput          ?? '';
        const metaOut = overlay._lfCgState?.currentMetaDescription ?? '';
        if (!output) return;

        applyBtn.disabled    = true;
        applyBtn.textContent = __( 'Applying…', 'lingua-forge' );

        const store = getEditorStore();
        if (store) {
            store.dispatch('core/editor').editPost({ content: output });
        } else {
            const el = document.querySelector('#content');
            if (el) el.value = output;
        }

        if (metaOut) {
            if (store) {
                store.dispatch('core/editor')
                    ?.editPost({ meta: { _linguaforge_meta_description: metaOut } });
            }
            const metaField = document.getElementById('lf_meta_description_field')
                || findInIframes('lf_meta_description_field');
            if (metaField) {
                metaField.value = metaOut;
                metaField.dispatchEvent(new Event('input'));
            }
        }

        applyBtn.textContent = __( 'Applied ✓', 'lingua-forge' );
        setTimeout(() => {
            overlay.hidden = true;
            resetOverlayToConfig(overlay);
        }, 900);
    });

    // ── Refine ────────────────────────────────────────────────────────────────
    resultSection.querySelector('[data-lf-ocg="refine"]').addEventListener('click', async () => {

        const state = overlay._lfCgState;
        if (!state) return;

        const refineInput = resultSection.querySelector('.lf-cg-modal__refine-input');
        const refineHint  = refineInput.value.trim();
        if (!refineHint) { refineInput.focus(); return; }

        const refineBtn = resultSection.querySelector('[data-lf-ocg="refine"]');
        const statusEl  = resultSection.querySelector('[data-lf-ocg="refine-status"]');
        const preview   = resultSection.querySelector('[data-lf-ocg="preview"]');
        const metaEl    = resultSection.querySelector('[data-lf-ocg="meta"]');

        refineBtn.disabled   = true;
        statusEl.classList.remove('lf-cg-modal__refine-status--error');
        statusEl.textContent = __( 'Refining…', 'lingua-forge' );
        statusEl.hidden      = false;

        const refineParams = {
            ...state.params,
            previous_output: state.currentOutput,
            refine_hint:     refineHint,
            force_refresh:   true,
        };

        try {

            const response = await fetch(
                `${ LinguaForgeAI.restUrl }/feature/content-generator/${ state.postId }`,
                {
                    method:  'POST',
                    headers: { 'X-WP-Nonce': LinguaForgeAI.nonce, 'Content-Type': 'application/json' },
                    body:    JSON.stringify(refineParams),
                }
            );
            const refined = await response.json();

            if (!refined.success || !refined.output) {
                statusEl.textContent = refined.error || __( 'Refinement failed — please try again.', 'lingua-forge' );
                statusEl.classList.add('lf-cg-modal__refine-status--error');
            } else {
                state.currentOutput = refined.output;
                state.generation++;
                preview.innerHTML   = sanitizeHtml( refined.output );

                if (refined.meta_description) {
                    const mdSection = resultSection.querySelector('[data-lf-ocg="meta-desc-section"]');
                    if (mdSection) mdSection.hidden = false;
                    const mdEl = resultSection.querySelector('[data-lf-ocg="meta-desc"]');
                    if (mdEl) mdEl.textContent = refined.meta_description;
                    state.currentMetaDescription = refined.meta_description;
                }

                const parts = [];
                if (refined.content_type) parts.push(escHtml(refined.content_type));
                if (refined.tone)         parts.push(escHtml(refined.tone));
                /* translators: %d is the refinement iteration number */
                parts.push( __( 'Refinement', 'lingua-forge' ) + ' #' + state.generation );
                metaEl.textContent = parts.join(' · ');

                refineInput.value    = '';
                statusEl.textContent = __( '✓ Refined — review the updated draft above.', 'lingua-forge' );
                setTimeout(() => { statusEl.hidden = true; statusEl.textContent = ''; }, 3000);
            }

        } catch (_) {
            statusEl.textContent = __( 'Request failed — please try again.', 'lingua-forge' );
            statusEl.classList.add('lf-cg-modal__refine-status--error');
        }

        refineBtn.disabled = false;
    });

    // Transition: show result, hide config.
    resultSection.hidden = false;
    overlay.querySelector('.lf-feature-overlay__config-section').hidden = true;

    resultSection.querySelector('.lf-cg-modal__refine-input')?.focus();
}

// ─── Shared namespace for modal files ────────────────────────────────────────
//
// admin-diff-modal.js and admin-content-gen-modal.js are loaded after this
// file (via wp_enqueue_script deps) and read shared utilities from here.
// They also write their public entry points back into this object.

window.LfAdmin = {
    // Escaping / sanitization
    escHtml,
    escAttr,
    sanitizeHtml,
    // Editor state
    isRtlLang,
    getEditorStore,
    isGutenbergActive,
    findInIframes,
    snapshotCurrentEditorState,
    // Apply helpers
    lfSlugify,
    applyToClassicEditor,
    showApplyHint,
    clearApplyError,
};

} )();
