/**
 * LinguaForge AI — Admin Toolbar Quick Translate popover
 *
 * Completely independent from admin.js / the editor meta-box feature.
 * Works on every page where the WordPress Admin Bar is shown (admin + front-end).
 *
 * Globals injected by wp_localize_script:
 *   LinguaForgeAIToolbar.restUrl   — base REST URL, e.g. https://…/wp-json/lingua-forge/v1
 *   LinguaForgeAIToolbar.nonce     — wp_rest nonce
 *   LinguaForgeAIToolbar.languages — { code: label, … }
 */

/* ─────────────────────────────────────────────────────────────────────────────
   Bootstrap on DOM ready
   ───────────────────────────────────────────────────────────────────────────── */

/* global wp */
const { __ } = wp.i18n;

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

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!popover.hidden && !popover.contains(e.target) && !toolbarItem.contains(e.target)) {
            closePopover(popover);
        }
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !popover.hidden) {
            closePopover(popover);
            toolbarLink.focus();
        }
    });

    // ── Translate button ──────────────────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-translate').addEventListener('click', async () => {
        await runTranslation(popover);
    });

    // ── Copy button (delegated — result area rebuilt on each translation) ─────

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

    // ── Clear input button ────────────────────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-clear-input').addEventListener('click', () => {
        const inputArea = popover.querySelector('.lingua-forge-tp__input');
        inputArea.value = '';
        inputArea.focus();
    });

    // ── Clear all button (input + output) ──────────────────────────────────────

    popover.querySelector('.lingua-forge-tp__btn-clear-all').addEventListener('click', () => {
        const inputArea = popover.querySelector('.lingua-forge-tp__input');
        const outputArea = popover.querySelector('.lingua-forge-tp__output');
        const resultPanel = popover.querySelector('.lingua-forge-tp__result');
        inputArea.value = '';
        outputArea.value = '';
        resultPanel.hidden = true;
        inputArea.focus();
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

    // Build <option> list
    const options = Object.entries(languages)
        .map(([code, label]) =>
            `<option value="${escAttr(code)}">${escHtml(label)}</option>`
        )
        .join('');

    const el = document.createElement('div');
    el.id = 'lingua-forge-tp';
    el.className = 'lingua-forge-tp';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', __( 'Quick Translate', 'lingua-forge' ));

    el.innerHTML = `
        <div class="lingua-forge-tp__header">
            <span class="lingua-forge-tp__title">
                <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                ${ __( 'Quick Translate', 'lingua-forge' ) }
            </span>
            <button
                type="button"
                class="lingua-forge-tp__close"
                aria-label="${ escHtml( __( 'Close translate popover', 'lingua-forge' ) ) }"
            >✕</button>
        </div>

        <div class="lingua-forge-tp__body">

            <label class="lingua-forge-tp__label" for="lingua-forge-tp-lang">
                ${ __( 'Target Language', 'lingua-forge' ) }
            </label>
            <select
                id="lingua-forge-tp-lang"
                class="lingua-forge-tp__lang"
            >${options}</select>
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

            <div class="lingua-forge-tp__input-actions">
                <button
                    type="button"
                    class="button button-primary lingua-forge-tp__btn-translate"
                >${ __( 'Translate', 'lingua-forge' ) }</button>
                <button
                    type="button"
                    class="button lingua-forge-tp__btn-clear-input"
                    aria-label="${ escHtml( __( 'Clear input', 'lingua-forge' ) ) }"
                >${ __( 'Clear', 'lingua-forge' ) }</button>
            </div>

        </div>

        <div class="lingua-forge-tp__result" hidden>
            <div class="lingua-forge-tp__result-meta"></div>
            <textarea
                class="lingua-forge-tp__output"
                rows="5"
                readonly
                placeholder="${ escHtml( __( 'Translation will appear here…', 'lingua-forge' ) ) }"
            ></textarea>
            <div class="lingua-forge-tp__result-actions">
                <button type="button" class="button button-secondary lingua-forge-tp__btn-copy">${ __( 'Copy', 'lingua-forge' ) }</button>
                <button type="button" class="button lingua-forge-tp__btn-clear-all">${ __( 'Clear All', 'lingua-forge' ) }</button>
            </div>
        </div>`;

    return el;
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
 *
 * Priority order:
 *   1. Post language detected by PHP (LinguaForgeAIToolbar.postLanguage) —
 *      most specific: matches the post/page currently being edited or viewed.
 *   2. Last language persisted in localStorage — used when there is no post
 *      context (e.g. a generic admin screen) so the user's habitual target
 *      language is pre-selected without them having to pick it every time.
 *   3. Default <select> value — whatever the first <option> is.
 *
 * A change listener is attached once here to persist every subsequent manual
 * selection to localStorage and to dismiss the auto-detected hint.
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

        // Post-context wins — show the "detected" hint.
        langSelect.value = detectedCode;

        if (langHint) {
            langHint.textContent = __( '↑ Detected from current post', 'lingua-forge' );
            langHint.hidden      = false;
        }

    } else if (persistedCode && hasOption(persistedCode)) {

        // Fallback to last persisted choice — no hint needed.
        langSelect.value = persistedCode;
    }

    // Persist every manual change and dismiss the detected hint.
    langSelect.addEventListener('change', () => {
        localStorage.setItem(LANG_STORAGE_KEY, langSelect.value);
        if (langHint) langHint.hidden = true;
    });
}

function openPopover(popover, anchorEl) {

    // ── Apply language preference (first open only) ───────────────────────────
    if (!popover.dataset.langInitialised) {
        initLanguageSelect(popover);
        popover.dataset.langInitialised = '1';
    }

    // ── Pre-fill with selected text (if any) from the current page ───────────

    const selection = window.getSelection
        ? window.getSelection().toString().trim()
        : '';

    if (selection) {
        const textarea = popover.querySelector('.lingua-forge-tp__input');
        if (textarea && textarea.value === '') {
            textarea.value = selection;
        }
    }

    // Position below the admin bar anchor.
    positionPopover(popover, anchorEl);

    popover.hidden = false;

    // Focus the language selector for keyboard users.
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

    const rect      = anchorEl.getBoundingClientRect();
    const popWidth  = 360; // matches CSS min-width
    const margin    = 8;
    const vpWidth   = window.innerWidth;

    let left = rect.left;

    // Keep within right edge of viewport.
    if (left + popWidth + margin > vpWidth) {
        left = vpWidth - popWidth - margin;
    }
    // Keep within left edge.
    if (left < margin) {
        left = margin;
    }

    popover.style.top  = (rect.bottom + 2) + 'px';
    popover.style.left = left + 'px';
}

/* ─────────────────────────────────────────────────────────────────────────────
   Translation fetch
   ───────────────────────────────────────────────────────────────────────────── */

async function runTranslation(popover) {

    const langSelect  = popover.querySelector('.lingua-forge-tp__lang');
    const inputArea   = popover.querySelector('.lingua-forge-tp__input');
    const resultPanel = popover.querySelector('.lingua-forge-tp__result');
    const resultMeta  = popover.querySelector('.lingua-forge-tp__result-meta');
    const outputArea  = popover.querySelector('.lingua-forge-tp__output');
    const translateBtn = popover.querySelector('.lingua-forge-tp__btn-translate');

    const chunkText = inputArea.value.trim();

    if (!chunkText) {
        inputArea.focus();
        inputArea.placeholder = __( 'Please enter some text first…', 'lingua-forge' );
        return;
    }

    // ── Loading state ─────────────────────────────────────────────────────────

    resultPanel.hidden      = false;
    resultMeta.textContent  = '';
    outputArea.value        = '';
    translateBtn.disabled   = true;
    translateBtn.textContent = __( 'Translating…', 'lingua-forge' );

    resultMeta.innerHTML = `<span class="lingua-forge-tp__status">${ __( 'Translating…', 'lingua-forge' ) }</span>`;

    // ── Fetch ─────────────────────────────────────────────────────────────────

    try {

        const response = await fetch(
            `${LinguaForgeAIToolbar.restUrl}/translate-chunk`,
            {
                method:  'POST',
                headers: {
                    'X-WP-Nonce':   LinguaForgeAIToolbar.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    target_language: langSelect.value,
                    chunk_text:      chunkText,
                }),
            }
        );

        const data = await response.json();

        if (!data.success || !data.output) {
            const msg = data.error || __( 'Translation failed. Please try again.', 'lingua-forge' );
            resultMeta.innerHTML = `<span class="lingua-forge-tp__error">${escHtml(msg)}</span>`;
            outputArea.value     = '';
        } else {
            const langLabel = data.language
                ? `${ __( 'Translated to:', 'lingua-forge' ) } <strong>${escHtml(data.language)}</strong>`
                : __( 'Translation complete', 'lingua-forge' );
            resultMeta.innerHTML = `<span class="lingua-forge-tp__success">${langLabel}</span>`;
            outputArea.value     = data.output;
        }

    } catch (_) {

        resultMeta.innerHTML = `<span class="lingua-forge-tp__error">${ __( 'Request failed. Check your connection.', 'lingua-forge' ) }</span>`;
        outputArea.value     = '';
    }

    // ── Restore button ────────────────────────────────────────────────────────

    translateBtn.disabled    = false;
    translateBtn.textContent = __( 'Translate', 'lingua-forge' );
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
