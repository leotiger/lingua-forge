/**
 * LinguaForge AI — Editor Top-Toolbar Quick Translate
 *
 * Injects a translate icon button directly into the Gutenberg editor's top
 * toolbar — works in both the post/page block editor and the full site /
 * template editor (FSE), even when the WP admin bar is completely hidden.
 *
 * ── Why DOM injection instead of registerPlugin / SlotFill ───────────────────
 * The SlotFill API (PluginMoreMenuItem, PluginSidebar, etc.) has no reliable
 * slot for the top toolbar in the site editor across all WP 6.x versions.
 * PluginMoreMenuItem only targets the "⋮" overflow dropdown, and its scope
 * handling differs between WP versions.  DOM injection targets the actual
 * header element directly — the same technique used when SlotFill falls short.
 *
 * ── Strategy ─────────────────────────────────────────────────────────────────
 * 1. Build the translate popover once and append it to <body>.
 * 2. Use MutationObserver to detect when the editor header renders (React
 *    renders it asynchronously — it is not present on DOMContentLoaded).
 * 3. Insert a <button class="components-button has-icon"> into the header's
 *    right-side toolbar area so it looks native alongside Save / Settings.
 * 4. Clicking the button positions and toggles the popover (same pattern as
 *    the admin-bar toolbar-translate.js popover).
 *
 * ── Two tabs ─────────────────────────────────────────────────────────────────
 * Translate — translate free-form text to a chosen language
 * Create    — generate new content from hints (optional tone + language)
 * (shared)  — after any result, an inline Refine row lets the editor
 *             iteratively improve the output with additional instructions
 *
 * CSS class selectors are tried in priority order and cover multiple WP
 * versions.  New versions can be appended to HEADER_SELECTORS without touching
 * the rest of the code.
 *
 * Globals (LinguaForgeAIEditor, injected via wp_localize_script):
 *   .restUrl      — https://…/wp-json/lingua-forge/v1
 *   .nonce        — wp_rest nonce
 *   .languages    — { code: "Label", … }
 *   .tones        — { key: "Label", … }
 *   .postLanguage — detected language code for current post, or null
 */

( function () {
    'use strict';

    // Guard against double-execution (script enqueued via two separate hooks
    // or loaded twice in any other edge case).
    if ( window.linguaForgeEditorTranslateInit ) return;
    window.linguaForgeEditorTranslateInit = true;

    if ( typeof LinguaForgeAIEditor === 'undefined' ) return;

    /* global wp */
    const { __ } = wp.i18n;

    // ── Per-result state (reset on each new Translate / Create call) ──────────
    let lastMode    = null;   // 'translate' | 'create'
    let lastParams  = null;   // original request body, replayed for multi-turn refine
    let refineCount = 0;      // number of refinements applied to the current result

    /* ── Header selectors (priority order, multiple WP version variants) ───── */
    // These target the RIGHT-HAND side of the editor header toolbar where
    // native buttons (Save, Settings, Preview…) already live.

    const HEADER_SELECTORS = [
        // Pinned items bar — present in BOTH post editor and site editor
        // across all WP 6.x versions.  This is where "Create Block Theme",
        // "Settings", and other plugin icon buttons live.
        '.interface-pinned-items',
        // Fallbacks for edge cases / future WP restructuring
        '.edit-site-header-edit-mode__end',
        '.edit-post-header__settings',
        '.editor-header__end',
        '.editor-header__settings',
    ];

    const BTN_CLASS   = 'lingua-forge-editor-btn';
    const POPOVER_ID  = 'lingua-forge-editor-popover';

    /* ── Language / tone data ─────────────────────────────────────────────── */

    const LANG_ENTRIES = Object.entries( LinguaForgeAIEditor.languages || {} );
    // eslint-disable-next-line no-unused-vars -- Reserved for future "default to first available language" behaviour.
    const DEFAULT_LANG = LANG_ENTRIES.length ? LANG_ENTRIES[ 0 ][ 0 ] : 'en';

    /* ── Boot ──────────────────────────────────────────────────────────────── */

    function init() {

        const popover = buildPopover();
        document.body.appendChild( popover );
        wirePopoverEvents( popover );
        watchAndInject( popover );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        // DOMContentLoaded already fired — init immediately (footer script).
        init();
    }

    /* ── MutationObserver injection ────────────────────────────────────────── */

    // Track containers we are already watching so we do not attach multiple
    // per-container observers to the same node.
    const monitoredContainers = new WeakSet();

    function watchAndInject( popover ) {

        // Initial attempt — header may already exist if script loaded late.
        tryInject( popover );

        // Body-level observer: detects when the editor header first renders
        // (React renders it asynchronously) and when it re-mounts after
        // navigating between list / canvas views in FSE.
        let rafId = null;
        const bodyObserver = new MutationObserver( () => {
            if ( rafId ) return;
            rafId = requestAnimationFrame( () => {
                tryInject( popover );
                rafId = null;
            } );
        } );
        bodyObserver.observe( document.body, { childList: true, subtree: true } );

        // Belt-and-suspenders poll for the first 30 s.
        const poll = setInterval( () => tryInject( popover ), 750 );
        setTimeout( () => {
            clearInterval( poll );
            bodyObserver.disconnect();
        }, 30000 );
    }

    function tryInject( popover ) {

        for ( const sel of HEADER_SELECTORS ) {

            const container = document.querySelector( sel );
            if ( !container ) continue;

            // Remove any buttons that ended up in lower-priority containers from
            // a previous tryInject call — happens when a fallback container was
            // used before the preferred one appeared in the DOM.
            document.querySelectorAll( '.' + BTN_CLASS ).forEach( ( btn ) => {
                if ( !container.contains( btn ) ) btn.remove();
            } );

            // Inject our button if it isn't already there.
            if ( !container.querySelector( '.' + BTN_CLASS ) ) {
                container.insertBefore( buildButton( popover ), container.firstChild );
            }

            // Attach a persistent per-container observer the first time we
            // see this container node.  It watches only the container's direct
            // children so it fires the instant React's reconciliation removes
            // our button — and immediately puts it back, with no poll-tick gap.
            if ( !monitoredContainers.has( container ) ) {
                monitoredContainers.add( container );
                new MutationObserver( () => {
                    if ( !container.querySelector( '.' + BTN_CLASS ) ) {
                        container.insertBefore( buildButton( popover ), container.firstChild );
                    }
                } ).observe( container, { childList: true } );
            }

            // Selectors are a priority fallback list — stop at the first match
            // so we never inject into more than one container at a time.
            break;
        }
    }

    /* ── Toolbar button ────────────────────────────────────────────────────── */

    function buildButton( popover ) {

        const btn       = document.createElement( 'button' );
        btn.type        = 'button';
        btn.className   = 'components-button is-compact has-icon ' + BTN_CLASS;
        btn.setAttribute( 'aria-label',    __( 'Quick Translate', 'lingua-forge' ) );
        btn.setAttribute( 'aria-expanded', 'false' );
        btn.title       = __( 'Quick Translate', 'lingua-forge' );
        btn.innerHTML   =
            '<span class="dashicons dashicons-translation" aria-hidden="true"></span>';

        btn.addEventListener( 'click', ( e ) => {
            e.stopPropagation();
            popover.hidden ? openPopover( popover, btn ) : closePopover( popover );
        } );

        return btn;
    }

    /* ── Popover DOM ───────────────────────────────────────────────────────── */

    function buildPopover() {

        const langOptions = LANG_ENTRIES.map(
            ( [ code, label ] ) =>
                `<option value="${ escAttr( code ) }">${ escHtml( label ) }</option>`
        ).join( '' );

        const tones = LinguaForgeAIEditor.tones || {};
        const toneOptions = Object.entries( tones ).map(
            ( [ key, label ] ) =>
                `<option value="${ escAttr( key ) }">${ escHtml( label ) }</option>`
        ).join( '' );

        const el    = document.createElement( 'div' );
        el.id       = POPOVER_ID;
        el.className = 'lingua-forge-ep';
        el.hidden   = true;
        el.setAttribute( 'role',       'dialog' );
        el.setAttribute( 'aria-label', __( 'Quick Translate', 'lingua-forge' ) );
        el.dataset.activeTab = 'translate';

        el.innerHTML = `
            <div class="lingua-forge-ep__header">
                <span class="lingua-forge-ep__title">
                    <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                    ${ __( 'Quick Translate', 'lingua-forge' ) }
                </span>
                <button type="button" class="lingua-forge-ep__close" aria-label="${ escAttr( __( 'Close', 'lingua-forge' ) ) }">✕</button>
            </div>

            <div class="lingua-forge-ep__tabs" role="tablist">
                <button type="button" class="lingua-forge-ep__tab lingua-forge-ep__tab--active" data-tab="translate" role="tab" aria-selected="true">
                    ${ __( 'Translate', 'lingua-forge' ) }
                </button>
                <button type="button" class="lingua-forge-ep__tab" data-tab="create" role="tab" aria-selected="false">
                    ${ __( 'Create', 'lingua-forge' ) }
                </button>
            </div>

            <!-- ── Translate panel ── -->
            <div class="lingua-forge-ep__body" data-tab="translate" role="tabpanel">

                <label class="lingua-forge-ep__label" for="wpai-ep-lang">
                    ${ __( 'Target Language', 'lingua-forge' ) }
                </label>
                <select id="wpai-ep-lang" class="lingua-forge-ep__lang">${ langOptions }</select>
                <span class="lingua-forge-ep__lang-hint" hidden></span>

                <label class="lingua-forge-ep__label" for="wpai-ep-input">
                    ${ __( 'Text to translate', 'lingua-forge' ) }
                </label>
                <textarea
                    id="wpai-ep-input"
                    class="lingua-forge-ep__textarea"
                    rows="5"
                    placeholder="${ escAttr( __( 'Paste text, or select text in the editor first…', 'lingua-forge' ) ) }"
                ></textarea>

                <div class="lingua-forge-ep__input-actions">
                    <button type="button" class="components-button is-primary lingua-forge-ep__translate">
                        ${ __( 'Translate', 'lingua-forge' ) }
                    </button>
                    <button type="button" class="components-button lingua-forge-ep__clear-input" aria-label="${ escAttr( __( 'Clear input', 'lingua-forge' ) ) }">
                        ${ __( 'Clear', 'lingua-forge' ) }
                    </button>
                </div>

            </div>

            <!-- ── Create panel ── -->
            <div class="lingua-forge-ep__body lingua-forge-ep__body--create" data-tab="create" role="tabpanel" hidden>

                <label class="lingua-forge-ep__label" for="wpai-ep-hints">
                    ${ __( 'Instructions / key points', 'lingua-forge' ) }
                </label>
                <textarea
                    id="wpai-ep-hints"
                    class="lingua-forge-ep__textarea lingua-forge-ep__hints"
                    rows="4"
                    placeholder="${ escAttr( __( 'Describe what to write — topic, key points, structure…', 'lingua-forge' ) ) }"
                ></textarea>

                <div class="lingua-forge-ep__create-row">
                    <div class="lingua-forge-ep__create-field">
                        <label class="lingua-forge-ep__label" for="wpai-ep-tone">
                            ${ __( 'Tone', 'lingua-forge' ) }
                        </label>
                        <select id="wpai-ep-tone" class="lingua-forge-ep__tone">${ toneOptions }</select>
                    </div>
                    <div class="lingua-forge-ep__create-field">
                        <label class="lingua-forge-ep__label" for="wpai-ep-create-lang">
                            ${ __( 'Language (optional)', 'lingua-forge' ) }
                        </label>
                        <select id="wpai-ep-create-lang" class="lingua-forge-ep__create-lang">
                            <option value="">${ escHtml( __( '— auto-detect —', 'lingua-forge' ) ) }</option>
                            ${ langOptions }
                        </select>
                    </div>
                </div>

                <div class="lingua-forge-ep__input-actions">
                    <button type="button" class="components-button is-primary lingua-forge-ep__generate">
                        ${ __( 'Generate', 'lingua-forge' ) }
                    </button>
                    <button type="button" class="components-button lingua-forge-ep__clear-create" aria-label="${ escAttr( __( 'Clear hints', 'lingua-forge' ) ) }">
                        ${ __( 'Clear', 'lingua-forge' ) }
                    </button>
                </div>

            </div>

            <!-- ── Shared result panel ── -->
            <div class="lingua-forge-ep__result" hidden>

                <div class="lingua-forge-ep__result-meta"></div>

                <textarea
                    class="lingua-forge-ep__textarea lingua-forge-ep__textarea--output"
                    rows="5"
                    readonly
                ></textarea>

                <div class="lingua-forge-ep__result-actions">
                    <button type="button" class="components-button is-secondary lingua-forge-ep__copy">
                        ${ __( 'Copy', 'lingua-forge' ) }
                    </button>
                    <button type="button" class="components-button lingua-forge-ep__clear-all">
                        ${ __( 'Clear All', 'lingua-forge' ) }
                    </button>
                </div>

                <!-- Refine row — appears after any result -->
                <div class="lingua-forge-ep__refine">
                    <div class="lingua-forge-ep__refine-row">
                        <textarea
                            class="lingua-forge-ep__refine-input"
                            rows="2"
                            placeholder="${ escAttr( __( 'Refine further — e.g. "make it shorter" or "use a formal tone"…', 'lingua-forge' ) ) }"
                        ></textarea>
                        <button type="button" class="components-button lingua-forge-ep__btn-refine">
                            ${ __( '↺ Refine', 'lingua-forge' ) }
                        </button>
                    </div>
                    <p class="lingua-forge-ep__refine-status" hidden></p>
                </div>

            </div>`;

        return el;
    }

    /* ── Popover events ────────────────────────────────────────────────────── */

    function wirePopoverEvents( popover ) {

        // Close button
        popover.querySelector( '.lingua-forge-ep__close' )
            .addEventListener( 'click', () => closePopover( popover ) );

        // Tab switching
        popover.querySelectorAll( '.lingua-forge-ep__tab' ).forEach( ( tab ) => {
            tab.addEventListener( 'click', () => switchTab( popover, tab.dataset.tab ) );
        } );

        // Translate button
        popover.querySelector( '.lingua-forge-ep__translate' )
            .addEventListener( 'click', () => runTranslation( popover ) );

        // Generate button (Create tab)
        popover.querySelector( '.lingua-forge-ep__generate' )
            .addEventListener( 'click', () => runCreate( popover ) );

        // Refine button
        popover.querySelector( '.lingua-forge-ep__btn-refine' )
            .addEventListener( 'click', () => runRefine( popover ) );

        // Copy button
        popover.querySelector( '.lingua-forge-ep__copy' )
            .addEventListener( 'click', async ( e ) => {
                const output = popover.querySelector( '.lingua-forge-ep__textarea--output' );
                if ( !output ) return;
                try {
                    await navigator.clipboard.writeText( output.value );
                } catch ( _ ) {
                    output.select();
                    document.execCommand( 'copy' );
                }
                const btn = e.currentTarget;
                btn.textContent = __( 'Copied ✓', 'lingua-forge' );
                setTimeout( () => { btn.textContent = __( 'Copy', 'lingua-forge' ); }, 2000 );
            } );

        // Clear input (translate tab)
        popover.querySelector( '.lingua-forge-ep__clear-input' )
            .addEventListener( 'click', () => {
                const inputArea = popover.querySelector( '#wpai-ep-input' );
                inputArea.value = '';
                inputArea.focus();
            } );

        // Clear hints (create tab)
        popover.querySelector( '.lingua-forge-ep__clear-create' )
            .addEventListener( 'click', () => {
                const hintsArea = popover.querySelector( '#wpai-ep-hints' );
                hintsArea.value = '';
                hintsArea.focus();
            } );

        // Clear all (input + output)
        popover.querySelector( '.lingua-forge-ep__clear-all' )
            .addEventListener( 'click', () => {
                const tab       = popover.dataset.activeTab || 'translate';
                const inputArea = tab === 'create'
                    ? popover.querySelector( '#wpai-ep-hints' )
                    : popover.querySelector( '#wpai-ep-input' );
                const outputArea  = popover.querySelector( '.lingua-forge-ep__textarea--output' );
                const resultPanel = popover.querySelector( '.lingua-forge-ep__result' );

                if ( inputArea )  inputArea.value  = '';
                if ( outputArea ) outputArea.value = '';
                resultPanel.hidden = true;
                lastMode = null; lastParams = null; refineCount = 0;
                if ( inputArea ) inputArea.focus();
            } );

        // Close on outside click
        document.addEventListener( 'click', ( e ) => {
            if ( popover.hidden ) return;
            const isInsidePopover = popover.contains( e.target );
            const isToolbarBtn    = !!e.target.closest( '.' + BTN_CLASS );
            if ( !isInsidePopover && !isToolbarBtn ) closePopover( popover );
        } );

        // Close on Escape
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Escape' && !popover.hidden ) closePopover( popover );
        } );
    }

    /* ── Tab switching ─────────────────────────────────────────────────────── */

    function switchTab( popover, tab ) {

        // Show / hide panels
        popover.querySelectorAll( '.lingua-forge-ep__body' ).forEach( ( panel ) => {
            panel.hidden = panel.dataset.tab !== tab;
        } );

        // Update tab button states
        popover.querySelectorAll( '.lingua-forge-ep__tab' ).forEach( ( btn ) => {
            const active = btn.dataset.tab === tab;
            btn.classList.toggle( 'lingua-forge-ep__tab--active', active );
            btn.setAttribute( 'aria-selected', active ? 'true' : 'false' );
        } );

        popover.dataset.activeTab = tab;

        // Hide result + reset refine state when switching tabs
        popover.querySelector( '.lingua-forge-ep__result' ).hidden = true;
        lastMode = null; lastParams = null; refineCount = 0;

        // Focus the primary input of the new tab
        const focus = tab === 'create'
            ? popover.querySelector( '#wpai-ep-hints' )
            : popover.querySelector( '#wpai-ep-input' );
        if ( focus ) focus.focus();
    }

    /* ── Language preference (detection + persistence) ────────────────────── */

    /**
     * localStorage key shared with toolbar-translate.js so both popovers
     * remember — and restore — the same last-used language.
     */
    const LANG_STORAGE_KEY = 'linguaforge_last_lang';

    /**
     * Set the language <select> on the very first popover open.
     *
     * Priority:
     *   1. Post language detected by PHP (LinguaForgeAIEditor.postLanguage) —
     *      most specific: the language of the post/page being edited.
     *   2. Last language persisted in localStorage — used when there is no
     *      post context (e.g. the FSE template editor) so the user's habitual
     *      target language is pre-selected automatically.
     *   3. Default <select> value — whatever the first <option> is.
     *
     * Every subsequent manual change is written to localStorage immediately.
     */
    function initLanguageSelect( popover ) {

        const langSelect     = popover.querySelector( '.lingua-forge-ep__lang' );
        const langHint       = popover.querySelector( '.lingua-forge-ep__lang-hint' );
        const createLangSel  = popover.querySelector( '#wpai-ep-create-lang' );

        if ( !langSelect ) return;

        const hasOption = ( select, code ) =>
            !!select.querySelector( `option[value="${ escAttr( String( code ) ) }"]` );

        const detectedCode  = LinguaForgeAIEditor.postLanguage || null;
        const persistedCode = localStorage.getItem( LANG_STORAGE_KEY );

        // ── Translate tab language ────────────────────────────────────────────
        if ( detectedCode && hasOption( langSelect, detectedCode ) ) {

            langSelect.value = detectedCode;

            if ( langHint ) {
                langHint.textContent = __( '↑ Detected from current post', 'lingua-forge' );
                langHint.hidden      = false;
            }

        } else if ( persistedCode && hasOption( langSelect, persistedCode ) ) {

            langSelect.value = persistedCode;
        }

        // Persist every manual change and dismiss the detected hint.
        langSelect.addEventListener( 'change', () => {
            localStorage.setItem( LANG_STORAGE_KEY, langSelect.value );
            if ( langHint ) langHint.hidden = true;
        } );

        // ── Create tab language — pre-select detected post language ───────────
        // The create selector has an "— auto-detect —" empty option as fallback;
        // we only override it when a post language is positively identified.
        if ( createLangSel && detectedCode && hasOption( createLangSel, detectedCode ) ) {
            createLangSel.value = detectedCode;
        }
    }

    /* ── Open / close helpers ──────────────────────────────────────────────── */

    function openPopover( popover, anchorBtn ) {

        // Apply language preference on the very first open.
        if ( !popover.dataset.langInitialised ) {
            initLanguageSelect( popover );
            popover.dataset.langInitialised = '1';
        }

        // Pre-fill with any selected text into the Translate input.
        const selection = window.getSelection ? window.getSelection().toString().trim() : '';
        const textarea  = popover.querySelector( '#wpai-ep-input' );
        if ( selection && textarea && !textarea.value ) {
            textarea.value = selection;
        }

        positionPopover( popover, anchorBtn );
        popover.hidden = false;

        document.querySelectorAll( '.' + BTN_CLASS ).forEach(
            ( b ) => b.setAttribute( 'aria-expanded', 'true' )
        );
    }

    function closePopover( popover ) {

        popover.hidden = true;
        document.querySelectorAll( '.' + BTN_CLASS ).forEach(
            ( b ) => b.setAttribute( 'aria-expanded', 'false' )
        );
    }

    function positionPopover( popover, anchorBtn ) {

        const rect    = anchorBtn.getBoundingClientRect();
        const popW    = 450;
        const margin  = 8;
        const vpWidth = window.innerWidth;

        // Align right edge of popover with right edge of button; clamp to viewport.
        let left = rect.right - popW;
        if ( left < margin )                  left = margin;
        if ( left + popW + margin > vpWidth ) left = vpWidth - popW - margin;

        popover.style.top  = ( rect.bottom + 4 ) + 'px';
        popover.style.left = left + 'px';
    }

    /* ── Mode runners ──────────────────────────────────────────────────────── */

    async function runTranslation( popover ) {

        const langEl    = popover.querySelector( '.lingua-forge-ep__lang' );
        const inputEl   = popover.querySelector( '#wpai-ep-input' );
        const chunkText = inputEl.value.trim();

        if ( !chunkText ) {
            inputEl.focus();
            inputEl.placeholder = __( 'Please enter some text first…', 'lingua-forge' );
            return;
        }

        const params = {
            target_language: langEl.value,
            chunk_text:      chunkText,
        };

        lastMode    = 'translate';
        lastParams  = { ...params };
        refineCount = 0;

        await fetchResult( popover, '/translate-chunk', params, 'translate', 0 );
    }

    async function runCreate( popover ) {

        const hintsEl    = popover.querySelector( '#wpai-ep-hints' );
        const toneEl     = popover.querySelector( '#wpai-ep-tone' );
        const langEl     = popover.querySelector( '#wpai-ep-create-lang' );
        const hints      = hintsEl.value.trim();

        if ( !hints ) {
            hintsEl.focus();
            hintsEl.placeholder = __( 'Please enter some instructions first…', 'lingua-forge' );
            return;
        }

        const params = {
            hints:           hints,
            tone:            toneEl.value,
            target_language: langEl.value,
        };

        lastMode    = 'create';
        lastParams  = { ...params };
        refineCount = 0;

        await fetchResult( popover, '/create-chunk', params, 'create', 0 );
    }

    async function runRefine( popover ) {

        if ( !lastMode || !lastParams ) return;

        const refineInput = popover.querySelector( '.lingua-forge-ep__refine-input' );
        const outputArea  = popover.querySelector( '.lingua-forge-ep__textarea--output' );
        const statusEl    = popover.querySelector( '.lingua-forge-ep__refine-status' );
        const refineHint  = refineInput.value.trim();

        if ( !refineHint ) {
            refineInput.focus();
            return;
        }

        if ( statusEl ) { statusEl.hidden = true; statusEl.textContent = ''; }

        const endpoint = lastMode === 'create' ? '/create-chunk' : '/translate-chunk';
        const params   = {
            ...lastParams,
            refine_hint:     refineHint,
            previous_output: outputArea.value,
        };

        refineCount++;
        const thisRefinement = refineCount;

        const refineBtn = popover.querySelector( '.lingua-forge-ep__btn-refine' );
        refineBtn.disabled    = true;
        refineBtn.textContent = __( 'Refining…', 'lingua-forge' );

        await fetchResult( popover, endpoint, params, lastMode, thisRefinement );

        if ( outputArea.value ) {
            refineInput.value = '';
        }

        refineBtn.disabled    = false;
        refineBtn.textContent = __( '↺ Refine', 'lingua-forge' );
    }

    /* ── Shared fetch + result update ──────────────────────────────────────── */

    async function fetchResult( popover, endpointPath, bodyParams, mode, refinement ) {

        const resultPanel = popover.querySelector( '.lingua-forge-ep__result' );
        const metaEl      = popover.querySelector( '.lingua-forge-ep__result-meta' );
        const outputEl    = popover.querySelector( '.lingua-forge-ep__textarea--output' );

        const primaryBtn = mode === 'create'
            ? popover.querySelector( '.lingua-forge-ep__generate' )
            : popover.querySelector( '.lingua-forge-ep__translate' );

        const loadingLabel = mode === 'create'
            ? __( 'Generating…', 'lingua-forge' )
            : __( 'Translating…', 'lingua-forge' );

        resultPanel.hidden   = false;
        metaEl.innerHTML     = `<em style="color:#646970">${ escHtml( loadingLabel ) }</em>`;
        outputEl.value       = '';

        if ( primaryBtn && refinement === 0 ) {
            primaryBtn.disabled    = true;
            primaryBtn.textContent = loadingLabel;
        }

        try {

            const res  = await fetch(
                `${ LinguaForgeAIEditor.restUrl }${ endpointPath }`,
                {
                    method:  'POST',
                    headers: {
                        'X-WP-Nonce':   LinguaForgeAIEditor.nonce,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify( bodyParams ),
                }
            );

            const data = await res.json();

            if ( !data.success || !data.output ) {

                const msg = data.error || __( 'Request failed. Please try again.', 'lingua-forge' );
                metaEl.innerHTML = `<span style="color:#d63638">${ escHtml( msg ) }</span>`;
                outputEl.value   = '';

            } else {

                const parts = [];

                if ( mode === 'translate' && data.language ) {
                    parts.push( __( 'Translated to:', 'lingua-forge' ) + ` <strong>${ escHtml( data.language ) }</strong>` );
                }
                if ( mode === 'create' ) {
                    if ( data.tone )     parts.push( __( 'Tone:', 'lingua-forge' )     + ` <strong>${ escHtml( data.tone ) }</strong>` );
                    if ( data.language ) parts.push( __( 'Language:', 'lingua-forge' ) + ` <strong>${ escHtml( data.language ) }</strong>` );
                }
                if ( refinement > 0 ) {
                    parts.push( __( 'Refinement', 'lingua-forge' ) + ` #${ refinement }` );
                }

                const metaText = parts.length
                    ? parts.join( ' &nbsp;·&nbsp; ' )
                    : __( 'Done', 'lingua-forge' );

                metaEl.innerHTML = metaText;
                outputEl.value   = data.output;
            }

        } catch ( _ ) {

            metaEl.innerHTML = `<span style="color:#d63638">${ __( 'Request failed. Check your connection.', 'lingua-forge' ) }</span>`;
            outputEl.value   = '';
        }

        if ( primaryBtn && refinement === 0 ) {
            primaryBtn.disabled    = false;
            primaryBtn.textContent = mode === 'create'
                ? __( 'Generate', 'lingua-forge' )
                : __( 'Translate', 'lingua-forge' );
        }
    }

    /* ── Utilities ─────────────────────────────────────────────────────────── */

    function escHtml( v ) {
        return String( v )
            .replace( /&/g,  '&amp;' )
            .replace( /</g,  '&lt;'  )
            .replace( />/g,  '&gt;'  )
            .replace( /"/g,  '&quot;')
            .replace( /'/g,  '&#39;' );
    }

    function escAttr( v ) {
        return String( v )
            .replace( /&/g,  '&amp;' )
            .replace( /"/g,  '&quot;')
            .replace( /'/g,  '&#39;' )
            .replace( /</g,  '&lt;'  )
            .replace( />/g,  '&gt;'  );
    }

} )();
