/**
 * Lingua Forge — Admin Toolbar Quick Translate popover
 *
 * Completely independent from admin.js / the editor meta-box feature.
 * Works on every page where the WordPress Admin Bar is shown (admin + front-end).
 *
 * Three modes (tabs):
 *   Translate — translate free-form text to a chosen language
 *   Create    — generate new content from hints (optional target language)
 *   (shared)  — after any result, an inline Refine row lets the editor
 *               iteratively improve the output with additional instructions
 *
 * Globals injected by wp_localize_script:
 *   LinguaForgeAIToolbar.restUrl      — base REST URL, e.g. https://…/wp-json/lingua-forge/v1
 *   LinguaForgeAIToolbar.nonce        — wp_rest nonce
 *   LinguaForgeAIToolbar.languages    — { code: label, … }
 *   LinguaForgeAIToolbar.tones        — { key: label, … }
 *   LinguaForgeAIToolbar.postLanguage — detected language code for current post, or null
 */

/* ─────────────────────────────────────────────────────────────────────────────
   Bootstrap on DOM ready
   ───────────────────────────────────────────────────────────────────────────── */

/* global wp */
( function () {

const { __ } = wp.i18n;

const RTL_LANGS = new Set(['ar', 'he', 'fa', 'ur']);
function isRtlLang(code) {
    return RTL_LANGS.has((code || '').toLowerCase());
}

// ── Per-result state (reset on each new Translate / Create call) ──────────────

let lastMode    = null;   // 'translate' | 'create'
let lastParams  = null;   // original request body, replayed for multi-turn refine
let refineCount = 0;      // number of refinements applied to the current result

document.addEventListener('DOMContentLoaded', () => {

    const toolbarItem = document.getElementById('wp-admin-bar-lingua-forge-translate');

    if (!toolbarItem || typeof LinguaForgeAIToolbar === 'undefined') {
        return;
    }

    const popover = buildPopover();
    document.body.appendChild(popover);

    const toolbarLink = toolbarItem.querySelector('a');

    // ── Open / close ──────────────────────────────────────────────────────────

    toolbarLink.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (popover.hidden) {
            openPopover(popover, toolbarItem);
        } else {
            closePopover(popover);
        }
    });

    document.addEventListener('click', (e) => {
        if (!popover.hidden && !popover.contains(e.target) && !toolbarItem.contains(e.target)) {
            closePopover(popover);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !popover.hidden) {
            closePopover(popover);
            toolbarLink.focus();
        }
    });

    // ── Tab switching ─────────────────────────────────────────────────────────

    popover.querySelectorAll('.lingua-forge-tp__tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            switchTab(popover, btn.dataset.tab);
        });
    });

    // ── Translate button ──────────────────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-translate').addEventListener('click', async () => {
        await runTranslation(popover);
    });

    // ── Generate button (Create tab) ──────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-generate').addEventListener('click', async () => {
        await runCreate(popover);
    });

    // ── Refine button (result panel) ──────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-refine').addEventListener('click', async () => {
        await runRefine(popover);
    });

    // ── Re-translate button — force-refresh a cached result ──────────────────

    popover.querySelector('.lingua-forge-tp__btn-retranslate').addEventListener('click', async () => {
        if (!lastMode || !lastParams) return;
        const forceParams = { ...lastParams, force_refresh: true };
        const endpoint = lastMode === 'create' ? '/create-chunk' : '/translate-chunk';
        refineCount = 0;
        await fetchResult(popover, endpoint, forceParams, lastMode, 0);
    });

    // ── Copy button (delegated — result area rebuilt on each run) ────────────

    popover.addEventListener('click', async (e) => {
        const btn = e.target.closest('.lingua-forge-tp__btn-copy');
        if (!btn) return;
        const output = popover.querySelector('.lingua-forge-tp__output');
        if (!output) return;
        try {
            await navigator.clipboard.writeText(output.value);
        } catch (_) {
            output.select();
            document.execCommand('copy');
        }
        btn.textContent = __( 'Copied ✓', 'lingua-forge' );
        setTimeout(() => { btn.textContent = __( 'Copy', 'lingua-forge' ); }, 2000);
    });

    // ── Clear input (active tab only) ─────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-clear-translate').addEventListener('click', () => {
        const inputArea = popover.querySelector('.lingua-forge-tp__input');
        inputArea.value = '';
        inputArea.focus();
    });

    popover.querySelector('.lingua-forge-tp__btn-clear-create').addEventListener('click', () => {
        const hintsArea = popover.querySelector('.lingua-forge-tp__hints');
        hintsArea.value = '';
        hintsArea.focus();
    });

    // ── Clear all (input + output) ────────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-clear-all').addEventListener('click', () => {
        const tab        = popover.dataset.activeTab || 'translate';
        const inputArea  = tab === 'create'
            ? popover.querySelector('.lingua-forge-tp__hints')
            : popover.querySelector('.lingua-forge-tp__input');
        const outputArea  = popover.querySelector('.lingua-forge-tp__output');
        const resultPanel = popover.querySelector('.lingua-forge-tp__result');

        if (inputArea)  inputArea.value  = '';
        if (outputArea) outputArea.value = '';
        resultPanel.hidden = true;
        lastMode = null; lastParams = null; refineCount = 0;
        if (inputArea) inputArea.focus();
    });

    // ── Close button ──────────────────────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__close').addEventListener('click', () => {
        closePopover(popover);
        toolbarLink.focus();
    });
});

/* ─────────────────────────────────────────────────────────────────────────────
   Build popover DOM
   ───────────────────────────────────────────────────────────────────────────── */

function buildPopover() {

    const languages = LinguaForgeAIToolbar.languages || {};
    const tones     = LinguaForgeAIToolbar.tones     || {};

    // Language <option> list (shared by both translate and create panels)
    const langOptions = Object.entries(languages)
        .map(([code, label]) =>
            `<option value="${escAttr(code)}">${escHtml(label)}</option>`
        )
        .join('');

    // Tone <option> list for the Create panel
    const toneOptions = Object.entries(tones)
        .map(([key, label]) =>
            `<option value="${escAttr(key)}">${escHtml(label)}</option>`
        )
        .join('');

    const el = document.createElement('div');
    el.id        = 'lingua-forge-tp';
    el.className = 'lingua-forge-tp';
    el.hidden    = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', __( 'Lingua Forge AI Tools', 'lingua-forge' ));
    el.dataset.activeTab = 'translate';

    el.innerHTML = `
        <!-- Header -->
        <div class="lingua-forge-tp__header">
            <span class="lingua-forge-tp__title">
                <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                ${ __( 'Lingua Forge', 'lingua-forge' ) }
            </span>
            <button
                type="button"
                class="lingua-forge-tp__close"
                aria-label="${ escHtml( __( 'Close popover', 'lingua-forge' ) ) }"
            >✕</button>
        </div>

        <!-- Tab bar -->
        <div class="lingua-forge-tp__tabs" role="tablist">
            <button
                type="button"
                class="lingua-forge-tp__tab-btn is-active"
                data-tab="translate"
                role="tab"
                aria-selected="true"
            >${ __( 'Translate', 'lingua-forge' ) }</button>
            <button
                type="button"
                class="lingua-forge-tp__tab-btn"
                data-tab="create"
                role="tab"
                aria-selected="false"
            >${ __( 'Create', 'lingua-forge' ) }</button>
        </div>

        <!-- ── Translate panel ── -->
        <div class="lingua-forge-tp__tab-pane" data-tab="translate" role="tabpanel">

            <label class="lingua-forge-tp__label" for="lingua-forge-tp-lang">
                ${ __( 'Target Language', 'lingua-forge' ) }
            </label>
            <select
                id="lingua-forge-tp-lang"
                class="lingua-forge-tp__lang"
            >${langOptions}</select>
            <span class="lingua-forge-tp__lang-hint" hidden></span>

            <label class="lingua-forge-tp__label" for="lingua-forge-tp-input">
                ${ __( 'Text to translate', 'lingua-forge' ) }
            </label>
            <textarea
                id="lingua-forge-tp-input"
                class="lingua-forge-tp__input"
                rows="5"
                placeholder="${ escHtml( __( 'Paste text, or select text on the page first…', 'lingua-forge' ) ) }"
            ></textarea>

            <div class="lingua-forge-tp__pane-actions">
                <button
                    type="button"
                    class="button button-primary lingua-forge-tp__btn-translate"
                >${ __( 'Translate', 'lingua-forge' ) }</button>
                <button
                    type="button"
                    class="button lingua-forge-tp__btn-clear-translate"
                    aria-label="${ escHtml( __( 'Clear input', 'lingua-forge' ) ) }"
                >${ __( 'Clear', 'lingua-forge' ) }</button>
            </div>

        </div>

        <!-- ── Create panel ── -->
        <div class="lingua-forge-tp__tab-pane" data-tab="create" role="tabpanel" hidden>

            <label class="lingua-forge-tp__label" for="lingua-forge-tp-hints">
                ${ __( 'Instructions / key points', 'lingua-forge' ) }
            </label>
            <textarea
                id="lingua-forge-tp-hints"
                class="lingua-forge-tp__hints"
                rows="4"
                placeholder="${ escHtml( __( 'Describe what to write — topic, key points, structure…', 'lingua-forge' ) ) }"
            ></textarea>

            <div class="lingua-forge-tp__create-row">
                <div class="lingua-forge-tp__create-field">
                    <label class="lingua-forge-tp__label" for="lingua-forge-tp-tone">
                        ${ __( 'Tone', 'lingua-forge' ) }
                    </label>
                    <select id="lingua-forge-tp-tone" class="lingua-forge-tp__tone">${toneOptions}</select>
                </div>
                <div class="lingua-forge-tp__create-field">
                    <label class="lingua-forge-tp__label" for="lingua-forge-tp-create-lang">
                        ${ __( 'Language (optional)', 'lingua-forge' ) }
                    </label>
                    <select id="lingua-forge-tp-create-lang" class="lingua-forge-tp__create-lang">
                        <option value="">${ escHtml( __( '— auto-detect —', 'lingua-forge' ) ) }</option>
                        ${langOptions}
                    </select>
                </div>
            </div>

            <div class="lingua-forge-tp__pane-actions">
                <button
                    type="button"
                    class="button button-primary lingua-forge-tp__btn-generate"
                >${ __( 'Generate', 'lingua-forge' ) }</button>
                <button
                    type="button"
                    class="button lingua-forge-tp__btn-clear-create"
                    aria-label="${ escHtml( __( 'Clear hints', 'lingua-forge' ) ) }"
                >${ __( 'Clear', 'lingua-forge' ) }</button>
            </div>

        </div>

        <!-- ── Shared result panel ── -->
        <div class="lingua-forge-tp__result" hidden>

            <div class="lingua-forge-tp__result-meta"></div>

            <textarea
                class="lingua-forge-tp__output"
                rows="5"
                readonly
                placeholder="${ escHtml( __( 'Result will appear here…', 'lingua-forge' ) ) }"
            ></textarea>

            <div class="lingua-forge-tp__result-actions">
                <button type="button" class="button button-secondary lingua-forge-tp__btn-copy">${ __( 'Copy', 'lingua-forge' ) }</button>
                <button type="button" class="button lingua-forge-tp__btn-clear-all">${ __( 'Clear All', 'lingua-forge' ) }</button>
            </div>

            <!-- Re-translate button — shown only when result came from cache -->
            <div class="lingua-forge-refresh-row" hidden>
                <button
                    type="button"
                    class="button lingua-forge-tp__btn-retranslate"
                    hidden
                >${ __( '↺ Re-translate', 'lingua-forge' ) }</button>
                <span class="lingua-forge-refresh-hint">${ __( 'Re-generates and updates the cached result.', 'lingua-forge' ) }</span>
            </div>

            <!-- Refine row — appears after any result -->
            <div class="lingua-forge-tp__refine">
                <div class="lingua-forge-tp__refine-row">
                    <textarea
                        class="lingua-forge-tp__refine-input"
                        rows="2"
                        placeholder="${ escHtml( __( 'Refine further — e.g. "make it shorter" or "use a formal tone"…', 'lingua-forge' ) ) }"
                    ></textarea>
                    <button type="button" class="button lingua-forge-tp__btn-refine">
                        ${ __( '↺ Refine', 'lingua-forge' ) }
                    </button>
                </div>
                <p class="lingua-forge-tp__refine-status" hidden></p>
            </div>

        </div>`;

    return el;
}

/* ─────────────────────────────────────────────────────────────────────────────
   Tab switching
   ───────────────────────────────────────────────────────────────────────────── */

function switchTab(popover, tab) {

    // Update panels
    popover.querySelectorAll('.lingua-forge-tp__tab-pane').forEach((pane) => {
        pane.hidden = pane.dataset.tab !== tab;
    });

    // Update tab buttons
    popover.querySelectorAll('.lingua-forge-tp__tab-btn').forEach((btn) => {
        const active = btn.dataset.tab === tab;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    popover.dataset.activeTab = tab;

    // Hide result + reset refine state when switching tabs
    popover.querySelector('.lingua-forge-tp__result').hidden = true;
    lastMode = null; lastParams = null; refineCount = 0;

    // Focus the primary input of the new tab
    const focus = tab === 'create'
        ? popover.querySelector('.lingua-forge-tp__hints')
        : popover.querySelector('.lingua-forge-tp__input');
    if (focus) focus.focus();
}

/* ─────────────────────────────────────────────────────────────────────────────
   Open / close helpers
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * localStorage key used to persist the user's last chosen language across
 * page loads.  Scoped to this plugin to avoid collisions with other scripts.
 */
const LANG_STORAGE_KEY = 'linguaforge_last_lang';

/**
 * Initialise the language <select> on the very first popover open.
 * Applies to the Translate tab's language selector.
 */
function initLanguageSelect(popover) {

    const langSelect = popover.querySelector('.lingua-forge-tp__lang');
    const langHint   = popover.querySelector('.lingua-forge-tp__lang-hint');

    if (!langSelect) return;

    const hasOption = (code) =>
        !!langSelect.querySelector(`option[value="${escAttr(String(code))}"]`);

    const detectedCode  = LinguaForgeAIToolbar.postLanguage || null;
    const persistedCode = localStorage.getItem(LANG_STORAGE_KEY);

    if (detectedCode && hasOption(detectedCode)) {
        langSelect.value = detectedCode;
        if (langHint) {
            langHint.textContent = __( '↑ Detected from current post', 'lingua-forge' );
            langHint.hidden      = false;
        }
    } else if (persistedCode && hasOption(persistedCode)) {
        langSelect.value = persistedCode;
    }

    langSelect.addEventListener('change', () => {
        localStorage.setItem(LANG_STORAGE_KEY, langSelect.value);
        if (langHint) langHint.hidden = true;
    });
}

function openPopover(popover, anchorEl) {

    if (!popover.dataset.langInitialised) {
        initLanguageSelect(popover);
        popover.dataset.langInitialised = '1';
    }

    // Pre-fill selected page text into the Translate input
    const selection = window.getSelection
        ? window.getSelection().toString().trim()
        : '';

    if (selection) {
        const textarea = popover.querySelector('.lingua-forge-tp__input');
        if (textarea && textarea.value === '') {
            textarea.value = selection;
        }
    }

    positionPopover(popover, anchorEl);
    popover.hidden = false;

    const firstFocusable = popover.querySelector('select, textarea, button');
    if (firstFocusable) firstFocusable.focus();
}

function closePopover(popover) {
    popover.hidden = true;
}

/**
 * Position the popover so it appears directly below the toolbar node
 * and stays within the viewport horizontally.
 */
function positionPopover(popover, anchorEl) {

    const rect     = anchorEl.getBoundingClientRect();
    const popWidth = 400; // matches CSS width
    const margin   = 8;
    const vpWidth  = window.innerWidth;

    let left = rect.left;

    if (left + popWidth + margin > vpWidth) {
        left = vpWidth - popWidth - margin;
    }
    if (left < margin) {
        left = margin;
    }

    popover.style.top  = (rect.bottom + 2) + 'px';
    popover.style.left = left + 'px';
}

/* ─────────────────────────────────────────────────────────────────────────────
   Mode runners
   ───────────────────────────────────────────────────────────────────────────── */

async function runTranslation(popover) {

    const langSelect = popover.querySelector('.lingua-forge-tp__lang');
    const inputArea  = popover.querySelector('.lingua-forge-tp__input');
    const chunkText  = inputArea.value.trim();

    if (!chunkText) {
        inputArea.focus();
        inputArea.placeholder = __( 'Please enter some text first…', 'lingua-forge' );
        return;
    }

    const params = {
        target_language: langSelect.value,
        chunk_text:      chunkText,
    };

    lastMode    = 'translate';
    lastParams  = { ...params };
    refineCount = 0;

    await fetchResult(popover, '/translate-chunk', params, 'translate', 0);
}

async function runCreate(popover) {

    const hintsArea  = popover.querySelector('.lingua-forge-tp__hints');
    const toneSelect = popover.querySelector('.lingua-forge-tp__tone');
    const langSelect = popover.querySelector('.lingua-forge-tp__create-lang');
    const hints      = hintsArea.value.trim();

    if (!hints) {
        hintsArea.focus();
        hintsArea.placeholder = __( 'Please enter some instructions first…', 'lingua-forge' );
        return;
    }

    const params = {
        hints:           hints,
        tone:            toneSelect.value,
        target_language: langSelect.value,
    };

    lastMode    = 'create';
    lastParams  = { ...params };
    refineCount = 0;

    await fetchResult(popover, '/create-chunk', params, 'create', 0);
}

async function runRefine(popover) {

    if (!lastMode || !lastParams) return;

    const refineInput = popover.querySelector('.lingua-forge-tp__refine-input');
    const outputArea  = popover.querySelector('.lingua-forge-tp__output');
    const statusEl    = popover.querySelector('.lingua-forge-tp__refine-status');
    const refineHint  = refineInput.value.trim();

    if (!refineHint) {
        refineInput.focus();
        return;
    }

    // Clear any previous refine status
    if (statusEl) { statusEl.hidden = true; statusEl.textContent = ''; }

    const endpoint = lastMode === 'create' ? '/create-chunk' : '/translate-chunk';
    const params   = {
        ...lastParams,
        refine_hint:     refineHint,
        previous_output: outputArea.value,
    };

    refineCount++;
    const thisRefinement = refineCount;

    // Disable refine button during the call
    const refineBtn = popover.querySelector('.lingua-forge-tp__btn-refine');
    refineBtn.disabled    = true;
    refineBtn.textContent = __( 'Refining…', 'lingua-forge' );

    await fetchResult(popover, endpoint, params, lastMode, thisRefinement);

    // Clear the refine input on success; the result panel handles errors
    if (outputArea.value) {
        refineInput.value = '';
    }

    refineBtn.disabled    = false;
    refineBtn.textContent = __( '↺ Refine', 'lingua-forge' );
}

/* ─────────────────────────────────────────────────────────────────────────────
   Shared fetch + result update
   ───────────────────────────────────────────────────────────────────────────── */

async function fetchResult(popover, endpointPath, bodyParams, mode, refinement) {

    const resultPanel = popover.querySelector('.lingua-forge-tp__result');
    const resultMeta  = popover.querySelector('.lingua-forge-tp__result-meta');
    const outputArea  = popover.querySelector('.lingua-forge-tp__output');

    // Determine the primary action button for this mode
    const primaryBtn = mode === 'create'
        ? popover.querySelector('.lingua-forge-tp__btn-generate')
        : popover.querySelector('.lingua-forge-tp__btn-translate');

    const loadingLabel = mode === 'create'
        ? __( 'Generating…', 'lingua-forge' )
        : __( 'Translating…', 'lingua-forge' );

    // ── Loading state ─────────────────────────────────────────────────────────

    resultPanel.hidden     = false;
    resultMeta.innerHTML   = `<span class="lingua-forge-tp__status">${escHtml(loadingLabel)}</span>`;
    outputArea.value       = '';

    // Hide re-translate button while loading — it will be shown again if the
    // response comes back with data.cached = true.
    const reTranslateBtn = popover.querySelector('.lingua-forge-tp__btn-retranslate');
    if (reTranslateBtn) reTranslateBtn.hidden = true;

    if (primaryBtn && refinement === 0) {
        primaryBtn.disabled    = true;
        primaryBtn.textContent = loadingLabel;
    }

    // ── Fetch ─────────────────────────────────────────────────────────────────

    try {

        const response = await fetch(
            `${LinguaForgeAIToolbar.restUrl}${endpointPath}`,
            {
                method:  'POST',
                headers: {
                    'X-WP-Nonce':   LinguaForgeAIToolbar.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(bodyParams),
            }
        );

        const data = await response.json();

        if (!data.success || !data.output) {

            const msg = data.error || __( 'Request failed. Please try again.', 'lingua-forge' );
            resultMeta.innerHTML = `<span class="lingua-forge-tp__error">${escHtml(msg)}</span>`;
            outputArea.value     = '';
            outputArea.dir       = '';

        } else {

            // ── Build meta line ───────────────────────────────────────────────
            const parts = [];

            if (mode === 'translate' && data.language) {
                parts.push( __( 'Translated to:', 'lingua-forge' ) + ` <strong>${escHtml(data.language)}</strong>` );
            }
            if (mode === 'create') {
                if (data.tone) {
                    parts.push( __( 'Tone:', 'lingua-forge' ) + ` <strong>${escHtml(data.tone)}</strong>` );
                }
                if (data.language) {
                    parts.push( __( 'Language:', 'lingua-forge' ) + ` <strong>${escHtml(data.language)}</strong>` );
                }
            }
            if (refinement > 0) {
                /* translators: %d is the refinement iteration number */
                parts.push( __( 'Refinement', 'lingua-forge' ) + ` #${refinement}` );
            }

            if (data.cached) {
                parts.push( `<span class="lingua-forge-cached-badge">${ __( 'cached', 'lingua-forge' ) }</span>` );
            }

            const metaText = parts.length
                ? parts.join( ' &nbsp;·&nbsp; ' )
                : __( 'Done', 'lingua-forge' );

            resultMeta.innerHTML = `<span class="lingua-forge-tp__success">${metaText}</span>`;
            outputArea.value     = data.output;
            outputArea.dir       = isRtlLang(bodyParams.target_language) ? 'rtl' : '';

            // Show / hide the re-translate button depending on whether this
            // result came from cache. When cached, the editor should be able
            // to force a fresh API call.
            if (reTranslateBtn) {
                reTranslateBtn.hidden = !data.cached;
            }
        }

    } catch (_) {

        resultMeta.innerHTML = `<span class="lingua-forge-tp__error">${ __( 'Request failed. Check your connection.', 'lingua-forge' ) }</span>`;
        outputArea.value     = '';
        outputArea.dir       = '';
    }

    // ── Restore primary button ────────────────────────────────────────────────

    if (primaryBtn && refinement === 0) {
        primaryBtn.disabled    = false;
        primaryBtn.textContent = mode === 'create'
            ? __( 'Generate', 'lingua-forge' )
            : __( 'Translate', 'lingua-forge' );
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Utilities
   ───────────────────────────────────────────────────────────────────────────── */

function escHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escAttr(value) {
    return String(value)
        .replace(/&/g,  '&amp;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;');
}

} )();
