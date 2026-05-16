/**
 * LinguaForge AI — Block Toolbar Translate / Revise
 *
 * Injects a translate/revise icon button into the block toolbar of every
 * supported text block (paragraph, heading, list-item, quote, etc.).
 *
 * ── How it works ──────────────────────────────────────────────────────────────
 * Uses wp.hooks.addFilter('editor.BlockEdit', …) to wrap each block's Edit
 * component with a BlockControls slot that renders our ToolbarButton.
 * The button reads the block's content attribute, pre-fills a popover, and on
 * completion writes the result back via wp.data.dispatch('core/block-editor')
 * .updateBlockAttributes().
 *
 * ── Tabs ──────────────────────────────────────────────────────────────────────
 * Translate — language select (with post-language auto-detection + localStorage
 *             persistence shared with editor-translate.js / toolbar-translate.js)
 * Revise    — revision type select (Improve / Make Formal / Make Casual /
 *             Make Concise / Expand)
 *
 * Globals (LinguaForgeAIBlockAction, injected via wp_localize_script):
 *   .restUrl      — https://…/wp-json/lingua-forge/v1
 *   .nonce        — wp_rest nonce
 *   .languages    — { code: "Label", … }
 *   .postLanguage — detected language code for the current post, or null
 */

( function () {
    'use strict';

    if ( typeof LinguaForgeAIBlockAction === 'undefined' ) return;

    /* ── WordPress API aliases ─────────────────────────────────────────────── */

    const { addFilter }                    = wp.hooks    || {};
    const { createElement: el, Fragment }  = wp.element  || {};
    const { ToolbarGroup, ToolbarButton }  = wp.components || {};
    const { BlockControls }                = wp.blockEditor || {};
    const { select, dispatch }             = wp.data     || {};

    if ( !addFilter || !el || !BlockControls || !select || !dispatch ) return;

    const { __ } = wp.i18n;

    /* ── Supported block → content-attribute map ───────────────────────────── */

    /**
     * Maps Gutenberg block names to the attribute that holds their editable
     * HTML content.  Only blocks in this map receive the toolbar button.
     */
    const CONTENT_MAP = {
        'core/paragraph':    'content',
        'core/heading':      'content',
        'core/list-item':    'content',   // WP 6.x inner list blocks
        'core/verse':        'content',
        'core/preformatted': 'content',
        'core/quote':        'value',
        'core/pullquote':    'value',
        'core/button':       'text',
    };

    /* ── Revision type options ─────────────────────────────────────────────── */

    const REVISION_TYPES = {
        improve:  __( 'Improve writing', 'lingua-forge' ),
        formal:   __( 'Make Formal',     'lingua-forge' ),
        casual:   __( 'Make Casual',     'lingua-forge' ),
        concise:  __( 'Make Concise',    'lingua-forge' ),
        expand:   __( 'Expand',          'lingua-forge' ),
    };

    /* ── Shared localStorage key ───────────────────────────────────────────── */

    /**
     * Same key used by editor-translate.js and toolbar-translate.js so all
     * three popovers remember and share the last-chosen target language.
     */
    const LANG_STORAGE_KEY = 'linguaforge_last_lang';

    /* ── Active block state ────────────────────────────────────────────────── */

    let activeClientId  = null;
    let activeBlockName = null;

    /* ── Build and attach the popover (once) ───────────────────────────────── */

    const popoverEl = buildPopover();
    document.body.appendChild( popoverEl );
    wirePopoverEvents( popoverEl );

    /* ── Register block toolbar button via addFilter ───────────────────────── */

    addFilter(
        'editor.BlockEdit',
        'lingua-forge/block-actions',
        function ( BlockEdit ) {

            return function ( props ) {

                const contentAttr = CONTENT_MAP[ props.name ];

                const toolbar = contentAttr
                    ? el(
                          BlockControls,
                          { group: 'other' },
                          el(
                              ToolbarGroup,
                              null,
                              el( ToolbarButton, {
                                  // Dashicon — matches the Quick Translate button
                                  icon: el( 'span', {
                                      className: 'dashicons dashicons-translation',
                                      style: { fontSize: '20px', width: '20px', height: '20px' },
                                  } ),
                                  label:   __( 'Translate / Revise', 'lingua-forge' ),
                                  onClick: ( event ) => {
                                      activeClientId  = props.clientId;
                                      activeBlockName = props.name;

                                      const content = props.attributes[ contentAttr ] || '';
                                      openPopover( popoverEl, event.currentTarget, content );
                                  },
                              } )
                          )
                      )
                    : null;

                return el( Fragment, null, el( BlockEdit, props ), toolbar );
            };
        }
    );

    /* ── Build popover DOM ─────────────────────────────────────────────────── */

    function buildPopover() {

        const langOptions = Object.entries( LinguaForgeAIBlockAction.languages || {} )
            .map( ( [ code, label ] ) =>
                `<option value="${ esc( code ) }">${ escHtml( label ) }</option>`
            ).join( '' );

        const revisionOptions = Object.entries( REVISION_TYPES )
            .map( ( [ code, label ] ) =>
                `<option value="${ esc( code ) }">${ escHtml( label ) }</option>`
            ).join( '' );

        const wrap       = document.createElement( 'div' );
        wrap.id          = 'lingua-forge-ba';
        wrap.className   = 'lingua-forge-ba';
        wrap.hidden      = true;
        wrap.setAttribute( 'role',       'dialog' );
        wrap.setAttribute( 'aria-label', __( 'Translate / Revise block', 'lingua-forge' ) );

        wrap.innerHTML = `
            <div class="lingua-forge-ba__header">
                <div class="lingua-forge-ba__tabs" role="tablist">
                    <button type="button" role="tab" class="lingua-forge-ba__tab lingua-forge-ba__tab--active"
                        data-tab="translate" aria-selected="true">${ __( 'Translate', 'lingua-forge' ) }</button>
                    <button type="button" role="tab" class="lingua-forge-ba__tab"
                        data-tab="revise" aria-selected="false">${ __( 'Revision', 'lingua-forge' ) }</button>
                </div>
                <button type="button" class="lingua-forge-ba__close" aria-label="${ escHtml( __( 'Close', 'lingua-forge' ) ) }">✕</button>
            </div>

            <div class="lingua-forge-ba__panel" data-panel="translate">
                <label class="lingua-forge-ba__label" for="wpai-ba-lang">${ __( 'Target Language', 'lingua-forge' ) }</label>
                <select id="wpai-ba-lang" class="lingua-forge-ba__select">${ langOptions }</select>
                <span class="lingua-forge-ba__lang-hint" hidden></span>

                <label class="lingua-forge-ba__label" for="wpai-ba-tr-input">${ __( 'Block Content', 'lingua-forge' ) }</label>
                <textarea id="wpai-ba-tr-input" class="lingua-forge-ba__textarea" rows="5"></textarea>

                <div class="lingua-forge-ba__actions">
                    <button type="button" class="components-button is-primary lingua-forge-ba__run" data-action="translate">
                        ${ __( 'Translate', 'lingua-forge' ) }
                    </button>
                </div>
            </div>

            <div class="lingua-forge-ba__panel" data-panel="revise" hidden>
                <label class="lingua-forge-ba__label" for="wpai-ba-revision-type">${ __( 'Revision Type', 'lingua-forge' ) }</label>
                <select id="wpai-ba-revision-type" class="lingua-forge-ba__select">${ revisionOptions }</select>

                <label class="lingua-forge-ba__label" for="wpai-ba-rv-input">${ __( 'Block Content', 'lingua-forge' ) }</label>
                <textarea id="wpai-ba-rv-input" class="lingua-forge-ba__textarea" rows="5"></textarea>

                <div class="lingua-forge-ba__actions">
                    <button type="button" class="components-button is-primary lingua-forge-ba__run" data-action="revise">
                        ${ __( 'Revise', 'lingua-forge' ) }
                    </button>
                </div>
            </div>

            <div class="lingua-forge-ba__result" hidden>
                <div class="lingua-forge-ba__result-meta"></div>
                <textarea class="lingua-forge-ba__textarea lingua-forge-ba__textarea--output" rows="5" readonly></textarea>
                <div class="lingua-forge-ba__actions">
                    <button type="button" class="components-button is-primary lingua-forge-ba__apply">
                        ${ __( 'Apply to Block', 'lingua-forge' ) }
                    </button>
                    <button type="button" class="components-button lingua-forge-ba__copy">
                        ${ __( 'Copy', 'lingua-forge' ) }
                    </button>
                    <button type="button" class="components-button lingua-forge-ba__back">
                        ${ __( '← Back', 'lingua-forge' ) }
                    </button>
                </div>
            </div>`;

        return wrap;
    }

    /* ── Wire popover events ───────────────────────────────────────────────── */

    function wirePopoverEvents( popover ) {

        // Close button
        popover.querySelector( '.lingua-forge-ba__close' )
            .addEventListener( 'click', () => closePopover( popover ) );

        // Tab switching
        popover.querySelectorAll( '.lingua-forge-ba__tab' ).forEach( ( tab ) => {
            tab.addEventListener( 'click', () => switchTab( popover, tab.dataset.tab ) );
        } );

        // Run buttons (Translate / Revise)
        popover.querySelectorAll( '.lingua-forge-ba__run' ).forEach( ( btn ) => {
            btn.addEventListener( 'click', () => runAction( popover, btn.dataset.action ) );
        } );

        // Apply to block
        popover.querySelector( '.lingua-forge-ba__apply' )
            .addEventListener( 'click', () => applyToBlock( popover ) );

        // Copy
        popover.querySelector( '.lingua-forge-ba__copy' )
            .addEventListener( 'click', async ( e ) => {
                const output = popover.querySelector( '.lingua-forge-ba__textarea--output' );
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

        // Back — hide result and restore active panel
        popover.querySelector( '.lingua-forge-ba__back' )
            .addEventListener( 'click', () => {
                popover.querySelector( '.lingua-forge-ba__result' ).hidden = true;
                const activeTab = popover.querySelector( '.lingua-forge-ba__tab--active' );
                if ( activeTab ) {
                    popover.querySelector(
                        `.lingua-forge-ba__panel[data-panel="${ activeTab.dataset.tab }"]`
                    ).hidden = false;
                }
            } );

        // Close on outside click.
        // Guard with skipNextClick to prevent the button's own click event from
        // immediately closing the popover it just opened.
        let skipNextClick = false;

        document.addEventListener( 'click', ( e ) => {
            if ( popover.hidden ) return;
            if ( skipNextClick ) { skipNextClick = false; return; }
            if ( !popover.contains( e.target ) ) closePopover( popover );
        } );

        // Store the setter so openPopover can arm the guard.
        popover._armSkip = () => { skipNextClick = true; };

        // Close on Escape
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Escape' && !popover.hidden ) closePopover( popover );
        } );
    }

    /* ── Open / close ──────────────────────────────────────────────────────── */

    function openPopover( popover, anchorEl, content ) {

        // Arm the outside-click guard so the triggering click doesn't
        // immediately close the popover.
        if ( popover._armSkip ) popover._armSkip();

        // Pre-fill both textareas with the block's current content.
        popover.querySelector( '#wpai-ba-tr-input' ).value = content;
        popover.querySelector( '#wpai-ba-rv-input' ).value = content;

        // Reset result panel.
        popover.querySelector( '.lingua-forge-ba__result' ).hidden = true;

        // Apply language preference on first open (detection + localStorage).
        if ( !popover.dataset.langInitialised ) {
            initLanguageSelect( popover );
            popover.dataset.langInitialised = '1';
        }

        // Always open on the Translate tab.
        switchTab( popover, 'translate' );

        positionPopover( popover, anchorEl );
        popover.hidden = false;
    }

    function closePopover( popover ) {
        popover.hidden  = true;
        activeClientId  = null;
        activeBlockName = null;
    }

    function positionPopover( popover, anchorEl ) {

        const rect   = anchorEl.getBoundingClientRect();
        const popW   = 380;
        const margin = 8;
        const vpW    = window.innerWidth;

        let left = rect.left;
        if ( left + popW + margin > vpW ) left = vpW - popW - margin;
        if ( left < margin )             left = margin;

        popover.style.top  = ( rect.bottom + 6 ) + 'px';
        popover.style.left = left + 'px';
    }

    /* ── Tab switching ─────────────────────────────────────────────────────── */

    function switchTab( popover, tab ) {

        popover.querySelectorAll( '.lingua-forge-ba__tab' ).forEach( ( t ) => {
            const active = t.dataset.tab === tab;
            t.classList.toggle( 'lingua-forge-ba__tab--active', active );
            t.setAttribute( 'aria-selected', String( active ) );
        } );

        popover.querySelectorAll( '.lingua-forge-ba__panel' ).forEach( ( p ) => {
            p.hidden = p.dataset.panel !== tab;
        } );

        // Always hide the result panel when switching tabs.
        popover.querySelector( '.lingua-forge-ba__result' ).hidden = true;
    }

    /* ── Language preference (detection + localStorage) ───────────────────── */

    function initLanguageSelect( popover ) {

        const langSelect = popover.querySelector( '#wpai-ba-lang' );
        const langHint   = popover.querySelector( '.lingua-forge-ba__lang-hint' );

        if ( !langSelect ) return;

        const hasOption = ( code ) =>
            !!langSelect.querySelector( `option[value="${ esc( String( code ) ) }"]` );

        const detectedCode  = LinguaForgeAIBlockAction.postLanguage || null;
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

    /* ── Run translate / revise ────────────────────────────────────────────── */

    async function runAction( popover, action ) {

        const isTranslate = action === 'translate';
        const panelSel    = `.lingua-forge-ba__panel[data-panel="${ action }"]`;
        const panel       = popover.querySelector( panelSel );
        const inputSel    = isTranslate ? '#wpai-ba-tr-input' : '#wpai-ba-rv-input';
        const textarea    = popover.querySelector( inputSel );
        const chunkText   = textarea.value.trim();

        if ( !chunkText ) { textarea.focus(); return; }

        const resultPanel = popover.querySelector( '.lingua-forge-ba__result' );
        const resultMeta  = popover.querySelector( '.lingua-forge-ba__result-meta' );
        const outputArea  = popover.querySelector( '.lingua-forge-ba__textarea--output' );
        const runBtn      = panel.querySelector( '.lingua-forge-ba__run' );

        // Show result panel in loading state.
        panel.hidden         = true;
        resultPanel.hidden   = false;
        resultMeta.innerHTML = `<em class="lingua-forge-ba__status">${ __( 'Processing…', 'lingua-forge' ) }</em>`;
        outputArea.value     = '';
        runBtn.disabled      = true;

        try {

            let url, body;

            if ( isTranslate ) {

                const lang = popover.querySelector( '#wpai-ba-lang' )?.value || 'en';
                url  = `${ LinguaForgeAIBlockAction.restUrl }/translate-chunk`;
                body = { target_language: lang, chunk_text: chunkText };

            } else {

                const revisionType = popover.querySelector( '#wpai-ba-revision-type' )?.value || 'improve';
                url  = `${ LinguaForgeAIBlockAction.restUrl }/revise-block`;
                body = { revision_type: revisionType, chunk_text: chunkText };
            }

            const res  = await fetch( url, {
                method:  'POST',
                headers: {
                    'X-WP-Nonce':   LinguaForgeAIBlockAction.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify( body ),
            } );

            const data = await res.json();

            if ( data.success && data.output ) {

                const label = isTranslate
                    ? `${ __( 'Translated to:', 'lingua-forge' ) } <strong>${ escHtml( data.language || '' ) }</strong>`
                    : `<strong>${ escHtml( data.revision_label || '' ) }</strong>`;

                resultMeta.innerHTML = `<span class="lingua-forge-ba__ok">${ label }</span>`;
                outputArea.value     = data.output;

            } else {

                // WP REST errors use `message`; our own errors use `error`.
                // Keep resultPanel visible so the user can read the error;
                // the ← Back button returns them to the input panel.
                const errMsg = data.message || data.error || __( 'Failed. Please try again.', 'lingua-forge' );
                resultMeta.innerHTML =
                    `<span class="lingua-forge-ba__error">${ escHtml( errMsg ) }</span>`;
            }

        } catch ( _ ) {

            resultMeta.innerHTML =
                `<span class="lingua-forge-ba__error">${ __( 'Request failed. Check your connection.', 'lingua-forge' ) }</span>`;
        }

        runBtn.disabled = false;
    }

    /* ── Apply result to block ─────────────────────────────────────────────── */

    function applyToBlock( popover ) {

        if ( !activeClientId || !activeBlockName ) return;

        const contentAttr = CONTENT_MAP[ activeBlockName ];
        if ( !contentAttr ) return;

        const outputArea = popover.querySelector( '.lingua-forge-ba__textarea--output' );
        const newContent = outputArea?.value || '';
        if ( !newContent ) return;

        dispatch( 'core/block-editor' ).updateBlockAttributes(
            activeClientId,
            { [ contentAttr ]: newContent }
        );

        const applyBtn = popover.querySelector( '.lingua-forge-ba__apply' );
        if ( applyBtn ) {
            applyBtn.textContent = __( 'Applied ✓', 'lingua-forge' );
            setTimeout( () => { applyBtn.textContent = __( 'Apply to Block', 'lingua-forge' ); }, 2000 );
        }
    }

    /* ── Utilities ─────────────────────────────────────────────────────────── */

    function escHtml( v ) {
        return String( v )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;'  )
            .replace( />/g, '&gt;'  )
            .replace( /"/g, '&quot;')
            .replace( /'/g, '&#39;' );
    }

    /** Escape for use inside an HTML attribute value. */
    function esc( v ) {
        return String( v )
            .replace( /&/g,  '&amp;' )
            .replace( /"/g,  '&quot;')
            .replace( /'/g,  '&#39;' )
            .replace( /</g,  '&lt;'  )
            .replace( />/g,  '&gt;'  );
    }

} )();
