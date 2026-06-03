/* LinguaForge AI – translation diff/apply modal
 *
 * Depends on window.LfAdmin being set by admin.js (loaded first).
 * Exposes: window.LfAdmin.openApplyDiffModal
 */
/* global wp */
( function () {

const { __ } = wp.i18n;

// ─── Helpers (resolved from admin.js shared namespace) ────────────────────────

function escHtml( v )                  { return window.LfAdmin.escHtml( v ); }
function escAttr( v )                  { return window.LfAdmin.escAttr( v ); }
function sanitizeHtml( html )          { return window.LfAdmin.sanitizeHtml( html ); }
function isRtlLang( code )             { return window.LfAdmin.isRtlLang( code ); }
function snapshotCurrentEditorState()  { return window.LfAdmin.snapshotCurrentEditorState(); }
function isGutenbergActive()           { return window.LfAdmin.isGutenbergActive(); }
function getEditorStore()              { return window.LfAdmin.getEditorStore(); }
function findInIframes( id )           { return window.LfAdmin.findInIframes( id ); }
function lfSlugify( str )              { return window.LfAdmin.lfSlugify( str ); }
function applyToClassicEditor( opts )  { return window.LfAdmin.applyToClassicEditor( opts ); }
function showApplyHint( btn, msg )     { return window.LfAdmin.showApplyHint( btn, msg ); }

// ─── DOM builder ─────────────────────────────────────────────────────────────

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

            <section class="lingua-forge-diff-modal__meta-desc" data-lf-row="meta-desc" hidden>
                <div class="lingua-forge-diff-modal__label">${ escHtml( __( 'Generated meta description', 'lingua-forge' ) ) }</div>
                <p class="lingua-forge-diff-modal__meta-desc-text" data-lf-pane="meta-desc"></p>
                <p class="lingua-forge-diff-modal__meta-desc-note">${ escHtml( __( 'Saved to the meta description field when you apply.', 'lingua-forge' ) ) }</p>
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
function openApplyDiffModal({ button, translatedContent, translatedTitle, footnotesJson, metaDescription = '', translatedExcerpt = '', targetLang = '' }) {

    const modal   = ensureDiffModal();
    const current = snapshotCurrentEditorState();
    const rtl     = isRtlLang(targetLang);

    // Title row — show only when there's actually a translated title to apply.
    const titleRow = modal.querySelector('[data-lf-row="title"]');
    if (translatedTitle) {
        modal.querySelector('[data-lf-pane="current-title"]').textContent = current.title;
        const newTitlePane = modal.querySelector('[data-lf-pane="new-title"]');
        newTitlePane.textContent = translatedTitle;
        newTitlePane.dir         = rtl ? 'rtl' : '';
        titleRow.hidden = false;
    } else {
        titleRow.hidden = true;
    }

    // Content panes — rendered as HTML so block markup displays close to how
    // it'll look post-apply. Block comments are HTML comments so they're
    // invisible; the actual <p>, <h2>, <ul>, etc. structure renders.
    // Content is sanitized via sanitizeHtml() before assignment to satisfy
    // the CodeQL js/xss-through-dom rule.
    modal.querySelector('[data-lf-pane="current-content"]').innerHTML = sanitizeHtml( current.content );
    const newContentPane = modal.querySelector('[data-lf-pane="new-content"]');
    newContentPane.innerHTML = sanitizeHtml( translatedContent );
    newContentPane.dir       = rtl ? 'rtl' : '';

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

    // Meta description — shown when Translation chained a meta description.
    const metaDescRow = modal.querySelector('[data-lf-row="meta-desc"]');
    if (metaDescription) {
        modal.querySelector('[data-lf-pane="meta-desc"]').textContent = metaDescription;
        metaDescRow.hidden = false;
    } else {
        metaDescRow.hidden = true;
    }

    modal._lfPending = { button, translatedContent, translatedTitle, footnotesJson, metaDescription, translatedExcerpt };
    modal.hidden = false;
}

function closeDiffModal(modal) {

    modal.hidden = true;
    modal._lfPending = null;
}

// ─── Apply ────────────────────────────────────────────────────────────────────

/**
 * The actual editor write. Only runs when the user clicks "Apply translation"
 * inside the modal.
 */
async function performApplyFromModal(modal) {

    const ctx = modal._lfPending;
    if (!ctx) return;

    const { button, translatedContent, translatedTitle, footnotesJson, metaDescription, translatedExcerpt } = ctx;

    const applyBtn = modal.querySelector('.lingua-forge-diff-modal__apply');
    applyBtn.disabled    = true;
    applyBtn.textContent = __( 'Applying…', 'lingua-forge' );

    try {

        const data = isGutenbergActive() ? getEditorStore() : null;

        if (data) {

            const payload = { content: translatedContent };
            if (translatedTitle) {
                payload.title = translatedTitle;
                // wp_update_post() does not auto-regenerate post_name when
                // post_title changes.  Passing `slug` here sets the pending
                // slug in the editor; WordPress sanitizes it via
                // sanitize_title() + wp_unique_post_slug() on save.
                payload.slug  = lfSlugify(translatedTitle);
            }
            if (translatedExcerpt) payload.excerpt = translatedExcerpt;
            if (footnotesJson)     payload.meta    = { footnotes: footnotesJson };

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

            // Classic-editor fallback — uses applyToClassicEditor() which
            // handles TinyMCE instances for both content and excerpt.
            applyToClassicEditor({ translatedContent, translatedTitle, translatedExcerpt, metaDescription: '' });
        }

        // If the translation was accompanied by a generated meta description,
        // write it to the meta description textarea in the Classic metabox so
        // the editor sees the result immediately without a second click.
        if (metaDescription) {

            // Stage the value in the Gutenberg editor store so the REST PATCH
            // on Save includes the new meta description alongside the content.
            if (data) {
                data.dispatch('core/editor')
                    ?.editPost({ meta: { _linguaforge_meta_description: metaDescription } });
            }

            // Update the Classic metabox textarea so the editor can see and
            // optionally tweak the value before hitting Update.
            const metaField = document.getElementById('lf_meta_description_field')
                || findInIframes('lf_meta_description_field');
            if (metaField) {
                metaField.value = metaDescription;
                // Fire 'input' so any character counter updates live.
                metaField.dispatchEvent(new Event('input'));
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

// ─── Export ───────────────────────────────────────────────────────────────────

window.LfAdmin.openApplyDiffModal = openApplyDiffModal;

} )();
