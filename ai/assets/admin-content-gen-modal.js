/* LinguaForge AI – standalone content-generation modal
 *
 * Depends on window.LfAdmin being set by admin.js (loaded first).
 * Exposes: window.LfAdmin.openContentGenOverlay
 *
 * This is the *standalone* content-gen modal (opened from the meta box when no
 * .lf-feature-overlay is in context). The inline overlay variant
 * (showContentGenInOverlay) remains in admin.js because it is tightly coupled
 * to overlay state management.
 */
/* global wp */
( function () {

const { __ } = wp.i18n;

// ─── Helpers (resolved from admin.js shared namespace) ────────────────────────

function escHtml( v )         { return window.LfAdmin.escHtml( v ); }
function escAttr( v )         { return window.LfAdmin.escAttr( v ); }
function sanitizeHtml( html ) { return window.LfAdmin.sanitizeHtml( html ); }
function getEditorStore()     { return window.LfAdmin.getEditorStore(); }
function findInIframes( id )  { return window.LfAdmin.findInIframes( id ); }

// ─── DOM builder ─────────────────────────────────────────────────────────────

/**
 * Build (lazily) and return the content-generator overlay element.
 */
function ensureContentGenOverlay() {

    let modal = document.getElementById('lf-cg-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id        = 'lf-cg-modal';
    modal.className = 'lf-cg-modal';
    modal.hidden    = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'lf-cg-modal-title');

    modal.innerHTML = `
        <div class="lf-cg-modal__backdrop" data-lf-cg="cancel"></div>
        <div class="lf-cg-modal__panel" role="document">
            <header class="lf-cg-modal__header">
                <div class="lf-cg-modal__header-text">
                    <h2 id="lf-cg-modal-title">${ escHtml( __( 'Generated Content', 'lingua-forge' ) ) }</h2>
                    <p class="lf-cg-modal__meta" data-lf-cg="meta"></p>
                </div>
                <button type="button" class="lf-cg-modal__close" data-lf-cg="cancel"
                    aria-label="${ escAttr( __( 'Close', 'lingua-forge' ) ) }">✕</button>
            </header>

            <div class="lf-cg-modal__body">
                <div class="lf-cg-modal__preview" data-lf-cg="preview"></div>
            </div>

            <section class="lf-cg-modal__meta-desc" data-lf-cg="meta-desc-section" hidden>
                <div class="lf-cg-modal__meta-desc-label">${ escHtml( __( 'Generated meta description', 'lingua-forge' ) ) }</div>
                <p class="lf-cg-modal__meta-desc-text" data-lf-cg="meta-desc"></p>
            </section>

            <section class="lf-cg-modal__refine">
                <label class="lf-cg-modal__refine-label" for="lf-cg-refine-input">
                    ${ escHtml( __( 'Refine further:', 'lingua-forge' ) ) }
                </label>
                <div class="lf-cg-modal__refine-row">
                    <textarea
                        id="lf-cg-refine-input"
                        class="lf-cg-modal__refine-input"
                        rows="3"
                        placeholder="${ escAttr( __( 'Add instructions to improve the draft — e.g. make it shorter, add a conclusion section, use a more formal register, include an FAQ block…', 'lingua-forge' ) ) }"
                    ></textarea>
                    <button type="button" class="button lf-cg-modal__refine-btn" data-lf-cg="refine">
                        ${ escHtml( __( '↺ Refine', 'lingua-forge' ) ) }
                    </button>
                </div>
                <p class="lf-cg-modal__refine-status" data-lf-cg="refine-status" hidden></p>
            </section>

            <footer class="lf-cg-modal__actions">
                <button type="button" class="button" data-lf-cg="cancel">
                    ${ escHtml( __( 'Cancel', 'lingua-forge' ) ) }
                </button>
                <button type="button" class="button button-secondary lf-cg-modal__copy-btn" data-lf-cg="copy">
                    ${ escHtml( __( 'Copy markup', 'lingua-forge' ) ) }
                </button>
                <button type="button" class="button button-primary lf-cg-modal__apply-btn" data-lf-cg="apply">
                    ${ escHtml( __( 'Apply to Editor', 'lingua-forge' ) ) }
                </button>
            </footer>
        </div>`;

    document.body.appendChild(modal);
    wireContentGenOverlay(modal);
    return modal;
}

/**
 * Populate the overlay with a generation result and show it.
 *
 * @param {object} data    REST response payload (output, tone, content_type…).
 * @param {object} params  Original request params (hints, tone, content_type…).
 * @param {string} postId  WordPress post ID string.
 */
function openContentGenOverlay(data, params, postId) {

    const modal = ensureContentGenOverlay();

    // Meta summary line.
    const parts = [];
    if (data.content_type) parts.push(data.content_type);
    if (data.tone)         parts.push(data.tone);
    modal.querySelector('[data-lf-cg="meta"]').textContent = parts.join(' · ');

    // Render content as HTML so block markup displays naturally in the preview.
    // Sanitized to satisfy the CodeQL js/xss-through-dom rule.
    modal.querySelector('[data-lf-cg="preview"]').innerHTML = sanitizeHtml( data.output );

    // Meta description — shown when the server chained one from the generation.
    const metaDescSection = modal.querySelector('[data-lf-cg="meta-desc-section"]');
    if (data.meta_description) {
        modal.querySelector('[data-lf-cg="meta-desc"]').textContent = data.meta_description;
        metaDescSection.hidden = false;
    } else {
        metaDescSection.hidden = true;
    }

    // Reset refinement UI.
    const refineInput = modal.querySelector('.lf-cg-modal__refine-input');
    const statusEl    = modal.querySelector('[data-lf-cg="refine-status"]');
    refineInput.value  = '';
    statusEl.hidden    = true;
    statusEl.textContent = '';
    statusEl.classList.remove('lf-cg-modal__refine-status--error');

    // Reset action buttons.
    const applyBtn = modal.querySelector('[data-lf-cg="apply"]');
    applyBtn.disabled    = false;
    applyBtn.textContent = __( 'Apply to Editor', 'lingua-forge' );

    // Store state for refinement and apply.
    modal._lfCgState = {
        postId,
        params:                { ...params },
        currentOutput:         data.output,
        currentMetaDescription: data.meta_description || '',
        generation:            0,
    };

    modal.hidden = false;
    refineInput.focus();
}

/**
 * Wire click / keyboard events for the content-generator overlay.
 */
function wireContentGenOverlay(modal) {

    modal.addEventListener('click', async (e) => {

        const action = e.target.closest('[data-lf-cg]')?.dataset.lfCg;
        if (!action) return;

        if (action === 'cancel') {
            closeContentGenOverlay(modal);
            return;
        }

        if (action === 'copy') {
            const output = modal._lfCgState?.currentOutput ?? '';
            try {
                await navigator.clipboard.writeText(output);
            } catch (_) {
                // Clipboard API unavailable — select as fallback (no useful element
                // to select here, so just silently proceed to the button feedback).
            }
            const btn  = modal.querySelector('[data-lf-cg="copy"]');
            const prev = btn.textContent;
            btn.textContent = __( 'Copied ✓', 'lingua-forge' );
            setTimeout(() => { btn.textContent = prev; }, 2000);
            return;
        }

        if (action === 'apply') {
            applyContentGenToEditor(modal);
            return;
        }

        if (action === 'refine') {
            await runContentGenRefinement(modal);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) closeContentGenOverlay(modal);
    });
}

function closeContentGenOverlay(modal) {

    modal.hidden      = true;
    modal._lfCgState  = null;
}

/**
 * Write the current draft directly to the Gutenberg / classic editor and close.
 */
function applyContentGenToEditor(modal) {

    const output   = modal._lfCgState?.currentOutput          ?? '';
    const metaDesc = modal._lfCgState?.currentMetaDescription ?? '';
    if (!output) return;

    const applyBtn = modal.querySelector('[data-lf-cg="apply"]');
    applyBtn.disabled    = true;
    applyBtn.textContent = __( 'Applying…', 'lingua-forge' );

    const data = getEditorStore();

    if (data) {
        data.dispatch('core/editor').editPost({ content: output });
        // WP 6.7+ canvas sync: editPost() updates the entity record but may
        // not trigger a visual re-render of the block editor canvas.
        // resetBlocks() ensures the canvas reflects the new content immediately.
        try {
            if ( typeof wp !== 'undefined' && wp.blocks?.parse ) {
                data.dispatch( 'core/block-editor' ).resetBlocks( wp.blocks.parse( output ) );
            }
        } catch ( _ ) {
            // Non-fatal — editPost() already staged the content for save.
        }
    } else {
        const el = document.querySelector('#content');
        if (el) el.value = output;
    }

    // Write meta description to the Gutenberg store and the Classic metabox
    // textarea so it is persisted and visible before the user hits Update.
    if (metaDesc) {

        // Stage in the editor store — same meta key used by the REST endpoint.
        if (data) {
            data.dispatch('core/editor')
                ?.editPost({ meta: { _linguaforge_meta_description: metaDesc } });
        }

        // Update the Classic metabox textarea with fallback for cross-frame access.
        const metaField = document.getElementById('lf_meta_description_field')
            || findInIframes('lf_meta_description_field');
        if (metaField) {
            metaField.value = metaDesc;
            metaField.dispatchEvent(new Event('input'));
        }
    }

    applyBtn.textContent = __( 'Applied ✓', 'lingua-forge' );
    setTimeout(() => closeContentGenOverlay(modal), 900);
}

/**
 * Send the current draft + refinement hint to the REST API and update the preview.
 */
async function runContentGenRefinement(modal) {

    const state = modal._lfCgState;
    if (!state) return;

    const refineInput = modal.querySelector('.lf-cg-modal__refine-input');
    const refineHint  = refineInput.value.trim();

    if (!refineHint) {
        refineInput.focus();
        return;
    }

    const refineBtn = modal.querySelector('[data-lf-cg="refine"]');
    const statusEl  = modal.querySelector('[data-lf-cg="refine-status"]');
    const preview   = modal.querySelector('[data-lf-cg="preview"]');
    const metaEl    = modal.querySelector('[data-lf-cg="meta"]');

    refineBtn.disabled   = true;
    statusEl.classList.remove('lf-cg-modal__refine-status--error');
    statusEl.textContent = __( 'Refining…', 'lingua-forge' );
    statusEl.hidden      = false;

    const params = {
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
                headers: {
                    'X-WP-Nonce':   LinguaForgeAI.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(params),
            }
        );

        const data = await response.json();

        if (!data.success || !data.output) {

            statusEl.textContent = data.error || __( 'Refinement failed — please try again.', 'lingua-forge' );
            statusEl.classList.add('lf-cg-modal__refine-status--error');

        } else {

            state.currentOutput = data.output;
            state.generation++;

            // Update preview with the refined content.
            preview.innerHTML = sanitizeHtml( data.output );

            // Update meta description alongside the preview.
            const metaDescSection = modal.querySelector('[data-lf-cg="meta-desc-section"]');
            if (data.meta_description) {
                modal.querySelector('[data-lf-cg="meta-desc"]').textContent = data.meta_description;
                metaDescSection.hidden = false;
                state.currentMetaDescription = data.meta_description;
            }

            // Update meta line with refinement counter.
            const metaParts = [];
            if (data.content_type) metaParts.push(data.content_type);
            if (data.tone)         metaParts.push(data.tone);
            /* translators: %d is the refinement iteration number */
            metaParts.push( __( 'Refinement', 'lingua-forge' ) + ' #' + state.generation );
            metaEl.textContent = metaParts.join(' · ');

            // Clear refinement input and show brief success.
            refineInput.value    = '';
            statusEl.textContent = __( '✓ Refined — review the updated draft above.', 'lingua-forge' );
            setTimeout(() => {
                statusEl.hidden      = true;
                statusEl.textContent = '';
            }, 3000);
        }

    } catch (_) {

        statusEl.textContent = __( 'Request failed — please try again.', 'lingua-forge' );
        statusEl.classList.add('lf-cg-modal__refine-status--error');
    }

    refineBtn.disabled = false;
}

// ─── Export ───────────────────────────────────────────────────────────────────

window.LfAdmin.openContentGenOverlay = openContentGenOverlay;

} )();
