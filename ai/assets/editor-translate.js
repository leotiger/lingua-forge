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
 * CSS class selectors are tried in priority order and cover multiple WP
 * versions.  New versions can be appended to HEADER_SELECTORS without touching
 * the rest of the code.
 *
 * Globals (LinguaForgeAIEditor, injected via wp_localize_script):
 *   .restUrl   — https://…/wp-json/lingua-forge/v1
 *   .nonce     — wp_rest nonce
 *   .languages — { code: "Label", … }
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

    /* ── Language data ─────────────────────────────────────────────────────── */

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

        const options = LANG_ENTRIES.map(
            ( [ code, label ] ) =>
                `<option value="${ escAttr( code ) }">${ escHtml( label ) }</option>`
        ).join( '' );

        const el    = document.createElement( 'div' );
        el.id       = POPOVER_ID;
        el.className = 'lingua-forge-ep';
        el.hidden   = true;
        el.setAttribute( 'role',       'dialog' );
        el.setAttribute( 'aria-label', __( 'Quick Translate', 'lingua-forge' ) );

        el.innerHTML = `
            <div class="lingua-forge-ep__header">
                <span class="lingua-forge-ep__title">
                    <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                    ${ __( 'Quick Translate', 'lingua-forge' ) }
                </span>
                <button type="button" class="lingua-forge-ep__close" aria-label="${ escAttr( __( 'Close', 'lingua-forge' ) ) }">✕</button>
            </div>

            <div class="lingua-forge-ep__body">

                <label class="lingua-forge-ep__label" for="wpai-ep-lang">
                    ${ __( 'Target Language', 'lingua-forge' ) }
                </label>
                <select id="wpai-ep-lang" class="lingua-forge-ep__lang">${ options }</select>
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
            </div>`;

        return el;
    }

    /* ── Popover events ────────────────────────────────────────────────────── */

    function wirePopoverEvents( popover ) {

        // Close button
        popover.querySelector( '.lingua-forge-ep__close' )
            .addEventListener( 'click', () => closePopover( popover ) );

        // Clear input button
        popover.querySelector( '.lingua-forge-ep__clear-input' )
            .addEventListener( 'click', () => {
                const inputArea = popover.querySelector( '#wpai-ep-input' );
                inputArea.value = '';
                inputArea.focus();
            } );

        // Translate button
        popover.querySelector( '.lingua-forge-ep__translate' )
            .addEventListener( 'click', () => runTranslation( popover ) );

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

        // Clear all button
        popover.querySelector( '.lingua-forge-ep__clear-all' )
            .addEventListener( 'click', () => {
                const inputArea = popover.querySelector( '#wpai-ep-input' );
                const outputArea = popover.querySelector( '.lingua-forge-ep__textarea--output' );
                const resultPanel = popover.querySelector( '.lingua-forge-ep__result' );
                inputArea.value = '';
                outputArea.value = '';
                resultPanel.hidden = true;
                inputArea.focus();
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

        const langSelect = popover.querySelector( '.lingua-forge-ep__lang' );
        const langHint   = popover.querySelector( '.lingua-forge-ep__lang-hint' );

        if ( !langSelect ) return;

        const hasOption = ( code ) =>
            !!langSelect.querySelector( `option[value="${ escAttr( String( code ) ) }"]` );

        const detectedCode  = LinguaForgeAIEditor.postLanguage || null;
        const persistedCode = localStorage.getItem( LANG_STORAGE_KEY );

        if ( detectedCode && hasOption( detectedCode ) ) {

            langSelect.value = detectedCode;

            if ( langHint ) {
                langHint.textContent = __( '↑ Detected from current post', 'lingua-forge' );
                langHint.hidden      = false;
            }

        } else if ( persistedCode && hasOption( persistedCode ) ) {

            langSelect.value = persistedCode;
        }

        // Persist every manual change and dismiss the detected hint.
        langSelect.addEventListener( 'change', () => {
            localStorage.setItem( LANG_STORAGE_KEY, langSelect.value );
            if ( langHint ) langHint.hidden = true;
        } );
    }

    /* ── Open / close helpers ──────────────────────────────────────────────── */

    function openPopover( popover, anchorBtn ) {

        // Apply language preference on the very first open.
        if ( !popover.dataset.langInitialised ) {
            initLanguageSelect( popover );
            popover.dataset.langInitialised = '1';
        }

        // Pre-fill with any selected text.
        const selection = window.getSelection ? window.getSelection().toString().trim() : '';
        const textarea  = popover.querySelector( '.lingua-forge-ep__textarea:not(.lingua-forge-ep__textarea--output)' );
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
        const popW    = 360;
        const margin  = 8;
        const vpWidth = window.innerWidth;

        // Align right edge of popover with right edge of button; clamp to viewport.
        let left = rect.right - popW;
        if ( left < margin )                  left = margin;
        if ( left + popW + margin > vpWidth ) left = vpWidth - popW - margin;

        popover.style.top  = ( rect.bottom + 4 ) + 'px';
        popover.style.left = left + 'px';
    }

    /* ── Translation fetch ─────────────────────────────────────────────────── */

    async function runTranslation( popover ) {

        const langEl       = popover.querySelector( '.lingua-forge-ep__lang' );
        const inputEl      = popover.querySelector( '#wpai-ep-input' );
        const resultEl     = popover.querySelector( '.lingua-forge-ep__result' );
        const metaEl       = popover.querySelector( '.lingua-forge-ep__result-meta' );
        const outputEl     = popover.querySelector( '.lingua-forge-ep__textarea--output' );
        const translateBtn = popover.querySelector( '.lingua-forge-ep__translate' );

        const text = inputEl.value.trim();
        if ( !text ) { inputEl.focus(); return; }

        resultEl.hidden        = false;
        metaEl.innerHTML       = `<em style="color:#646970">${ __( 'Translating…', 'lingua-forge' ) }</em>`;
        outputEl.value         = '';
        translateBtn.disabled  = true;
        translateBtn.textContent = __( 'Translating…', 'lingua-forge' );

        try {

            const res  = await fetch(
                `${ LinguaForgeAIEditor.restUrl }/translate-chunk`,
                {
                    method:  'POST',
                    headers: {
                        'X-WP-Nonce':   LinguaForgeAIEditor.nonce,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify( {
                        target_language: langEl.value,
                        chunk_text:      text,
                    } ),
                }
            );

            const data = await res.json();

            if ( data.success && data.output ) {
                metaEl.innerHTML = `${ __( 'Translated to:', 'lingua-forge' ) } <strong>${ escHtml( data.language || '' ) }</strong>`;
                outputEl.value   = data.output;
            } else {
                metaEl.innerHTML =
                    `<span style="color:#d63638">${ escHtml( data.error || __( 'Translation failed.', 'lingua-forge' ) ) }</span>`;
            }

        } catch ( _ ) {

            metaEl.innerHTML = `<span style="color:#d63638">${ __( 'Request failed. Check your connection.', 'lingua-forge' ) }</span>`;
        }

        translateBtn.disabled    = false;
        translateBtn.textContent = __( 'Translate', 'lingua-forge' );
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
