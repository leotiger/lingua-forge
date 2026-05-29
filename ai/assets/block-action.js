/**
 * LinguaForge AI — Block Toolbar Translate / Revise
 *
 * Injects a translate/revise icon button into the block toolbar of every
 * supported text block (paragraph, heading, list-item, quote, etc.).
 *
 * ── How it works ──────────────────────────────────────────────────────────────
 * Uses wp.richText.registerFormatType so the button's edit() callback fires
 * for every RichText instance — block toolbar and footnote-editor popover alike.
 * BlockFormatControls fills the format-controls slot in both contexts with no
 * MutationObserver needed.  On completion the result is written back via
 * wp.data.dispatch('core/block-editor').updateBlockAttributes().
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

    const { registerFormatType }                       = wp.richText    || {};
    const { createElement: el }                         = wp.element     || {};
    const { BlockFormatControls }                      = wp.blockEditor || {};
    const { select, dispatch, subscribe }              = wp.data        || {};

    if ( !registerFormatType || !el || !BlockFormatControls || !select || !dispatch || !subscribe ) return;

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

    // Must stay in sync with REVISION_TYPES in FeatureController.php.
    // Each key here is sent verbatim as `revision_type` to the REST endpoint;
    // the server-side whitelist gates which ones actually run.
    const REVISION_TYPES = {
        improve:        __( 'Improve writing',          'lingua-forge' ),
        formal:         __( 'Make formal',              'lingua-forge' ),
        casual:         __( 'Make casual',              'lingua-forge' ),
        concise:        __( 'Make concise',             'lingua-forge' ),
        expand:         __( 'Expand',                   'lingua-forge' ),
        bulletize:      __( 'Bulletize (•-prefixed)',   'lingua-forge' ),
        lead_paragraph: __( 'Lead paragraph (≤60 w)',   'lingua-forge' ),
        cite:           __( 'Mark citations needed',    'lingua-forge' ),
        plain_language: __( 'Plain language',           'lingua-forge' ),
    };

    /* ── Shared localStorage key ───────────────────────────────────────────── */

    /**
     * Same key used by editor-translate.js and toolbar-translate.js so all
     * three popovers remember and share the last-chosen target language.
     */
    const LANG_STORAGE_KEY = 'linguaforge_last_lang';

    /* ── Active block state ────────────────────────────────────────────────── */

    let activeClientId    = null;
    let activeBlockName   = null;

    /* ── Active footnote state ─────────────────────────────────────────────── */

    /**
     * Footnotes that belong to the currently open block.
     * Each entry: { id: string, content: string }.
     * Populated in the registerFormatType onClick; empty when block has no footnotes.
     */
    let activeFootnoteItems = [];

    /**
     * The footnote ID whose result should be written on "Apply to Footnote".
     * Kept in sync with the footnote <select> value.
     */
    let activeFootnoteId = null;

    /**
     * Controls what "Apply" does:
     *   'block'    — updateBlockAttributes (default)
     *   'footnote' — editPost({ meta: { footnotes: … } })
     */
    let applyMode = 'block';

    /* ── Build and attach the popover (once) ───────────────────────────────── */

    const popoverEl = buildPopover();
    document.body.appendChild( popoverEl );
    wirePopoverEvents( popoverEl );

    /* ── Translate icon SVG ────────────────────────────────────────────────── */

    const translateIconSvg = el(
        'svg',
        {
            xmlns:         'http://www.w3.org/2000/svg',
            viewBox:       '0 0 24 24',
            width:         '20',
            height:        '20',
            fill:          'currentColor',
            'aria-hidden': 'true',
            focusable:     'false',
        },
        el( 'path', {
            d: 'M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0 0 14.07 6H17V4h-7V2H8v2H1v2h11.17A15.5 15.5 0 0 1 9 11.35 15.06 15.06 0 0 1 6.69 8H4.68A17.1 17.1 0 0 0 9 13.56l-5.09 5.02L5.5 20l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7 1.62-4.33L19.12 17h-3.24z',
        } )
    );

    /* ── Register toolbar button via registerFormatType ───────────────────── */
    //
    // Using registerFormatType rather than addFilter('editor.BlockEdit') so that
    // the button's edit() callback is invoked for every RichText instance —
    // including the WordPress footnote editing popover — without any
    // MutationObserver hackery.  BlockFormatControls fills the format-controls
    // slot that Gutenberg renders in both the block toolbar and the footnote
    // popover toolbar automatically.

    registerFormatType( 'lingua-forge/translate', {
        title:     __( 'Translate / Revise', 'lingua-forge' ),
        tagName:   'span',
        className: 'lf-ai-tr',   // sentinel; never persisted in saved content

        // Always render — same pattern as the reference plugin (gutenberg-enhancements.php).
        // Context detection uses props.value.text: every registerFormatType edit()
        // receives a value object whose .text is the plain-text content of exactly
        // the RichText instance it is running inside.  For a footnote editor that is
        // the footnote text; for a block it is the block's content.  Checking whether
        // that text matches a footnote in post meta is iframe-safe and DOM-free.
        edit: function ( props ) {

            const { value } = props;

            return el(
                BlockFormatControls,
                {},
                el( 'button', {
                    type:         'button',
                    className:    'components-button has-icon lingua-forge-ba__trigger',
                    'aria-label': __( 'Translate / Revise', 'lingua-forge' ),
                    title:        __( 'Translate / Revise', 'lingua-forge' ),

                    // Prevent focus-steal so getSelectedBlock() is still valid in onClick.
                    onMouseDown: ( e ) => e.preventDefault(),

                    onClick: ( event ) => {

                        // value.text is the plain-text content of this RichText instance.
                        // If it matches a footnote stored in post meta we are inside the
                        // footnote editor; otherwise treat as a regular block.
                        const currentText = ( ( value && value.text ) || '' ).trim();
                        const meta        = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
                        const allFn       = JSON.parse( ( meta && meta.footnotes ) || '[]' );
                        const matchedFn   = Array.isArray( allFn ) && currentText
                            ? allFn.find( ( f ) =>
                                ( f.content || '' ).replace( /<[^>]*>/g, '' ).trim() === currentText
                              )
                            : null;

                        if ( matchedFn ) {
                            // ── Footnote mode ────────────────────────────────
                            activeFootnoteItems = [ matchedFn ];
                            activeFootnoteId    = matchedFn.id;
                            activeClientId      = null;
                            activeBlockName     = null;
                            applyMode           = 'footnote';

                            openPopover( popoverEl, event.currentTarget, '', 'footnotes' );

                        } else {
                            // ── Block mode ───────────────────────────────────
                            const block       = select( 'core/block-editor' ).getSelectedBlock();
                            const contentAttr = block ? CONTENT_MAP[ block.name ] : null;

                            if ( ! contentAttr ) return; // unsupported block

                            const content = block.attributes[ contentAttr ] || '';

                            activeClientId      = block.clientId;
                            activeBlockName     = block.name;
                            activeFootnoteItems = extractBlockFootnotes( content );
                            applyMode           = 'block';

                            openPopover( popoverEl, event.currentTarget, content );
                        }
                    },
                }, translateIconSvg )
            );
        },
    } );

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
                    <button type="button" role="tab" class="lingua-forge-ba__tab lingua-forge-ba__tab--footnotes"
                        data-tab="footnotes" aria-selected="false" hidden>${ __( 'Footnotes', 'lingua-forge' ) }</button>
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

            <div class="lingua-forge-ba__panel" data-panel="footnotes" hidden>
                <select id="wpai-ba-fn-select" class="lingua-forge-ba__select" hidden></select>

                <div class="lingua-forge-ba__tabs lingua-forge-ba__fn-subtabs" role="tablist" style="margin-top:8px;border-bottom:1px solid #dcdcde;">
                    <button type="button" role="tab" class="lingua-forge-ba__tab lingua-forge-ba__tab--active lingua-forge-ba__fn-subtab"
                        data-subtab="fn-translate" aria-selected="true" style="font-size:12px;padding:6px 10px;">${ __( 'Translate', 'lingua-forge' ) }</button>
                    <button type="button" role="tab" class="lingua-forge-ba__tab lingua-forge-ba__fn-subtab"
                        data-subtab="fn-revise" aria-selected="false" style="font-size:12px;padding:6px 10px;">${ __( 'Revision', 'lingua-forge' ) }</button>
                </div>

                <div data-subpanel="fn-translate">
                    <label class="lingua-forge-ba__label" for="wpai-ba-fn-lang" style="margin-top:8px;">${ __( 'Target Language', 'lingua-forge' ) }</label>
                    <select id="wpai-ba-fn-lang" class="lingua-forge-ba__select">${ langOptions }</select>
                    <span class="lingua-forge-ba__lang-hint lingua-forge-ba__fn-lang-hint" hidden></span>
                    <label class="lingua-forge-ba__label" for="wpai-ba-fn-tr-input" style="margin-top:6px;">${ __( 'Footnote Content', 'lingua-forge' ) }</label>
                    <textarea id="wpai-ba-fn-tr-input" class="lingua-forge-ba__textarea" rows="4"></textarea>
                    <div class="lingua-forge-ba__actions">
                        <button type="button" class="components-button is-primary lingua-forge-ba__fn-run" data-fn-action="translate">
                            ${ __( 'Translate', 'lingua-forge' ) }
                        </button>
                    </div>
                </div>

                <div data-subpanel="fn-revise" hidden>
                    <label class="lingua-forge-ba__label" for="wpai-ba-fn-revision-type" style="margin-top:8px;">${ __( 'Revision Type', 'lingua-forge' ) }</label>
                    <select id="wpai-ba-fn-revision-type" class="lingua-forge-ba__select">${ revisionOptions }</select>
                    <label class="lingua-forge-ba__label" for="wpai-ba-fn-rv-input" style="margin-top:6px;">${ __( 'Footnote Content', 'lingua-forge' ) }</label>
                    <textarea id="wpai-ba-fn-rv-input" class="lingua-forge-ba__textarea" rows="4"></textarea>
                    <div class="lingua-forge-ba__actions">
                        <button type="button" class="components-button is-primary lingua-forge-ba__fn-run" data-fn-action="revise">
                            ${ __( 'Revise', 'lingua-forge' ) }
                        </button>
                    </div>
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

        // ── Programmatic insertion of Instructions textareas ──────────────────
        //
        // HTML parsers treat <textarea> content as raw text: once the parser
        // enters a textarea it consumes everything until </textarea>, which
        // means sibling elements written in the template literal after the first
        // textarea (the Block Content one in the translate panel) can silently
        // vanish from the DOM even though the source looks correct.
        //
        // Creating these elements via the DOM API bypasses the parser entirely
        // and guarantees they always appear between the revision-type select and
        // the Block Content label regardless of browser or parsing quirks.

        // Revise panel — instructions field (between type select and content textarea).
        const rvSelect = wrap.querySelector( '#wpai-ba-revision-type' );
        if ( rvSelect ) {
            const rvInstrLabel       = document.createElement( 'label' );
            rvInstrLabel.className   = 'lingua-forge-ba__label';
            rvInstrLabel.htmlFor     = 'wpai-ba-rv-instructions';
            rvInstrLabel.textContent = __( 'Instructions (optional)', 'lingua-forge' );

            const rvInstrArea       = document.createElement( 'textarea' );
            rvInstrArea.id          = 'wpai-ba-rv-instructions';
            rvInstrArea.className   = 'lingua-forge-ba__textarea lingua-forge-ba__textarea--instructions';
            rvInstrArea.rows        = 2;
            rvInstrArea.placeholder = __( 'Extra guidance — tone, style, constraints, target audience…', 'lingua-forge' );

            rvSelect.insertAdjacentElement( 'afterend', rvInstrLabel );
            rvInstrLabel.insertAdjacentElement( 'afterend', rvInstrArea );
        }

        // Footnote Revision sub-panel — same field between type select and content textarea.
        const fnRvSelect = wrap.querySelector( '#wpai-ba-fn-revision-type' );
        if ( fnRvSelect ) {
            const fnInstrLabel       = document.createElement( 'label' );
            fnInstrLabel.className   = 'lingua-forge-ba__label';
            fnInstrLabel.style.marginTop = '6px';
            fnInstrLabel.htmlFor     = 'wpai-ba-fn-rv-instructions';
            fnInstrLabel.textContent = __( 'Instructions (optional)', 'lingua-forge' );

            const fnInstrArea       = document.createElement( 'textarea' );
            fnInstrArea.id          = 'wpai-ba-fn-rv-instructions';
            fnInstrArea.className   = 'lingua-forge-ba__textarea lingua-forge-ba__textarea--instructions';
            fnInstrArea.rows        = 2;
            fnInstrArea.placeholder = __( 'Extra guidance — tone, style, constraints…', 'lingua-forge' );

            fnRvSelect.insertAdjacentElement( 'afterend', fnInstrLabel );
            fnInstrLabel.insertAdjacentElement( 'afterend', fnInstrArea );
        }

        return wrap;
    }

    /* ── Wire popover events ───────────────────────────────────────────────── */

    function wirePopoverEvents( popover ) {

        // Close button
        popover.querySelector( '.lingua-forge-ba__close' )
            .addEventListener( 'click', () => closePopover( popover ) );

        // Top-level tab switching (Translate / Revision / Footnotes).
        // Exclude fn-subtab buttons — they share the class but must not trigger
        // switchTab (they have data-subtab, not data-tab, so the call would be
        // switchTab(popover, undefined) which hides all panels).
        popover.querySelectorAll( '.lingua-forge-ba__tab:not(.lingua-forge-ba__fn-subtab)' ).forEach( ( tab ) => {
            tab.addEventListener( 'click', () => switchTab( popover, tab.dataset.tab ) );
        } );

        // Footnote sub-tab switching (Translate / Revision within the Footnotes panel)
        popover.querySelectorAll( '.lingua-forge-ba__fn-subtab' ).forEach( ( tab ) => {
            tab.addEventListener( 'click', () => switchFnSubtab( popover, tab.dataset.subtab ) );
        } );

        // Footnote selector — update the content textarea when the user picks a different footnote
        const fnSelect = popover.querySelector( '#wpai-ba-fn-select' );
        if ( fnSelect ) {
            fnSelect.addEventListener( 'change', () => syncFnContent( popover ) );
        }

        // Run buttons (Translate / Revise) for the block panels
        popover.querySelectorAll( '.lingua-forge-ba__run' ).forEach( ( btn ) => {
            btn.addEventListener( 'click', () => runAction( popover, btn.dataset.action ) );
        } );

        // Run buttons for the footnote panel (Translate / Revise)
        popover.querySelectorAll( '.lingua-forge-ba__fn-run' ).forEach( ( btn ) => {
            btn.addEventListener( 'click', () => runFootnoteAction( popover, btn.dataset.fnAction ) );
        } );

        // Apply — behaviour depends on applyMode set when opening the result
        popover.querySelector( '.lingua-forge-ba__apply' )
            .addEventListener( 'click', () => {
                if ( applyMode === 'footnote' ) {
                    applyToFootnote( popover );
                } else {
                    applyToBlock( popover );
                }
            } );

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

        // Close when the user focuses a different block.
        // subscribe fires on every store change; bail fast when the popover is
        // hidden or no block is active to keep the hot path cheap.
        subscribe( () => {
            if ( popover.hidden || ! activeClientId ) return;
            const selected = select( 'core/block-editor' ).getSelectedBlockClientId();
            if ( selected !== activeClientId ) closePopover( popover );
        } );
    }

    /* ── Open / close ──────────────────────────────────────────────────────── */

    function openPopover( popover, anchorEl, content, initialTab ) {

        // Arm the outside-click guard so the triggering click doesn't
        // immediately close the popover.
        if ( popover._armSkip ) popover._armSkip();

        // Pre-fill both block textareas with the block's current content.
        popover.querySelector( '#wpai-ba-tr-input' ).value = content;
        popover.querySelector( '#wpai-ba-rv-input' ).value = content;

        // Clear the instructions textarea on every open so guidance typed for one
        // block doesn't silently carry over to the next block revision.
        const rvInstructions = popover.querySelector( '#wpai-ba-rv-instructions' );
        if ( rvInstructions ) rvInstructions.value = '';

        // Reset result panel and apply button text.
        // Note: applyMode is already set by the caller ('block' or 'footnote') —
        // don't override it here.
        popover.querySelector( '.lingua-forge-ba__result' ).hidden = true;
        const applyBtn = popover.querySelector( '.lingua-forge-ba__apply' );
        if ( applyBtn ) applyBtn.textContent = __( 'Apply to Block', 'lingua-forge' );

        // Apply language preference on first open (detection + localStorage).
        if ( !popover.dataset.langInitialised ) {
            initLanguageSelect( popover );
            popover.dataset.langInitialised = '1';
        }

        // In footnote-only mode (opened from the footnote editor toolbar) the
        // top-level Translate and Revision tabs operate on block content and are
        // irrelevant — hide them so only the Footnotes tab is visible.
        // In block mode they must be restored in case the popover was previously
        // opened from a footnote.
        const footnoteOnly = ( initialTab === 'footnotes' );
        const trTab = popover.querySelector( '[data-tab="translate"]' );
        const rvTab = popover.querySelector( '[data-tab="revise"]' );
        if ( trTab ) trTab.hidden = footnoteOnly;
        if ( rvTab ) rvTab.hidden = footnoteOnly;

        // Footnotes tab — show only when this block has footnote references,
        // or always when opened directly from the footnote editor toolbar.
        const fnTab    = popover.querySelector( '.lingua-forge-ba__tab--footnotes' );
        const fnSelect = popover.querySelector( '#wpai-ba-fn-select' );

        if ( activeFootnoteItems.length && fnTab && fnSelect ) {

            // Show the Footnotes tab only when opened directly from the footnote
            // editor toolbar — not when a regular block happens to reference footnotes.
            fnTab.hidden = ( initialTab !== 'footnotes' );

            // Rebuild the footnote <select> from the current block's footnotes.
            fnSelect.innerHTML = activeFootnoteItems.map( ( fn, i ) => {
                const preview = stripHtml( fn.content || '' ).slice( 0, 60 );
                return `<option value="${ esc( fn.id ) }">${ escHtml( preview || `Footnote ${ i + 1 }` ) }</option>`;
            } ).join( '' );

            // Show selector only when block has multiple footnotes.
            fnSelect.hidden = activeFootnoteItems.length <= 1;

            // Pre-fill footnote textareas with the first footnote and init lang.
            activeFootnoteId = activeFootnoteItems[ 0 ].id;
            syncFnContent( popover );
            initFnLangSelect( popover );
            switchFnSubtab( popover, 'fn-translate' );

        } else if ( fnTab ) {
            fnTab.hidden = true;
        }

        // Open on the requested tab, defaulting to Translate.
        switchTab( popover, initialTab || 'translate' );

        positionPopover( popover, anchorEl );
        popover.hidden = false;
    }

    function closePopover( popover ) {
        popover.hidden      = true;
        activeClientId      = null;
        activeBlockName     = null;
        activeFootnoteItems = [];
        activeFootnoteId    = null;
        applyMode           = 'block';
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

        // Exclude fn-subtab buttons — they share the .lingua-forge-ba__tab class
        // but are managed independently by switchFnSubtab.
        popover.querySelectorAll( '.lingua-forge-ba__tab:not(.lingua-forge-ba__fn-subtab)' ).forEach( ( t ) => {
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

                const revisionType      = popover.querySelector( '#wpai-ba-revision-type' )?.value || 'improve';
                const customInstruction = popover.querySelector( '#wpai-ba-rv-instructions' )?.value.trim() || '';
                url  = `${ LinguaForgeAIBlockAction.restUrl }/revise-block`;
                body = { revision_type: revisionType, chunk_text: chunkText };
                if ( customInstruction ) body.custom_instruction = customInstruction;
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

                // Restore apply mode to 'block' (undoes any footnote action
                // that may have set applyMode = 'footnote').
                applyMode = 'block';
                const applyBtnEl = popover.querySelector( '.lingua-forge-ba__apply' );
                if ( applyBtnEl ) {
                    applyBtnEl.textContent = __( 'Apply to Block', 'lingua-forge' );
                }

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

    /* ── Footnote helpers ─────────────────────────────────────────────────── */

    /**
     * Extract footnote items that belong to the given block content.
     * Looks for `data-fn="uuid"` references, then matches them against the
     * `footnotes` post meta managed by the core/footnotes block.
     *
     * @param  {string} content  Block's HTML content attribute value.
     * @return {Array}           Array of { id, content } objects, in DOM order.
     */
    function extractBlockFootnotes( content ) {

        const ids = [ ...( content || '' ).matchAll( /data-fn="([^"]+)"/g ) ].map( m => m[1] );
        if ( !ids.length ) return [];

        try {
            const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
            const all  = JSON.parse( ( meta && meta.footnotes ) || '[]' );
            return ids
                .map( id => Array.isArray( all ) && all.find( fn => fn.id === id ) )
                .filter( Boolean );
        } catch ( _ ) {
            return [];
        }
    }

    /**
     * Initialise the footnote panel's language select, mirroring whatever the
     * block-level language select already has (so all three popovers stay in sync).
     * Wires the change listener once and keeps both selects in sync.
     */
    function initFnLangSelect( popover ) {

        const fnLangSelect    = popover.querySelector( '#wpai-ba-fn-lang' );
        const fnLangHint      = popover.querySelector( '.lingua-forge-ba__fn-lang-hint' );
        const blockLangSelect = popover.querySelector( '#wpai-ba-lang' );

        if ( !fnLangSelect ) return;

        // Mirror the block lang select's current value.
        if ( blockLangSelect ) {
            fnLangSelect.value = blockLangSelect.value;
        }

        // Show detected-language hint when relevant.
        const detectedCode = LinguaForgeAIBlockAction.postLanguage || null;
        if ( fnLangHint ) {
            if ( detectedCode && fnLangSelect.value === detectedCode ) {
                fnLangHint.textContent = __( '↑ Detected from current post', 'lingua-forge' );
                fnLangHint.hidden      = false;
            } else {
                fnLangHint.hidden = true;
            }
        }

        // Wire once — guard prevents double-binding across multiple openPopover calls.
        if ( !fnLangSelect._wired ) {
            fnLangSelect._wired = true;
            fnLangSelect.addEventListener( 'change', () => {
                localStorage.setItem( LANG_STORAGE_KEY, fnLangSelect.value );
                if ( fnLangHint ) fnLangHint.hidden = true;
                // Keep block lang select in sync.
                if ( blockLangSelect ) blockLangSelect.value = fnLangSelect.value;
            } );
        }
    }

    /**
     * Switch between the Translate and Revision sub-tabs inside the Footnotes panel.
     */
    function switchFnSubtab( popover, subtab ) {

        popover.querySelectorAll( '.lingua-forge-ba__fn-subtab' ).forEach( ( t ) => {
            const active = t.dataset.subtab === subtab;
            t.classList.toggle( 'lingua-forge-ba__tab--active', active );
            t.setAttribute( 'aria-selected', String( active ) );
        } );

        popover.querySelectorAll( '[data-subpanel]' ).forEach( ( p ) => {
            p.hidden = p.dataset.subpanel !== subtab;
        } );

        // Always hide the result panel when switching sub-tabs.
        popover.querySelector( '.lingua-forge-ba__result' ).hidden = true;
    }

    /**
     * Update both footnote textareas (translate + revise) to match the currently
     * selected footnote in the footnote <select>.  Called on change events and
     * whenever the popover is populated.
     */
    function syncFnContent( popover ) {

        const fnSelect = popover.querySelector( '#wpai-ba-fn-select' );
        if ( !fnSelect ) return;

        activeFootnoteId = fnSelect.value;

        const fn  = activeFootnoteItems.find( f => f.id === activeFootnoteId );
        const raw = fn ? ( fn.content || '' ) : '';

        popover.querySelector( '#wpai-ba-fn-tr-input' ).value = raw;
        popover.querySelector( '#wpai-ba-fn-rv-input' ).value = raw;

        // Hide stale result whenever the selected footnote changes.
        popover.querySelector( '.lingua-forge-ba__result' ).hidden = true;
    }

    /**
     * Run a translate or revise action against the selected footnote's content.
     * On success sets applyMode = 'footnote' so Apply writes to post meta.
     */
    async function runFootnoteAction( popover, action ) {

        const isTranslate = action === 'translate';
        const inputSel    = isTranslate ? '#wpai-ba-fn-tr-input' : '#wpai-ba-fn-rv-input';
        const textarea    = popover.querySelector( inputSel );
        const chunkText   = textarea ? textarea.value.trim() : '';

        if ( !chunkText ) { if ( textarea ) textarea.focus(); return; }

        const fnPanel     = popover.querySelector( '[data-panel="footnotes"]' );
        const resultPanel = popover.querySelector( '.lingua-forge-ba__result' );
        const resultMeta  = popover.querySelector( '.lingua-forge-ba__result-meta' );
        const outputArea  = popover.querySelector( '.lingua-forge-ba__textarea--output' );
        const applyBtn    = popover.querySelector( '.lingua-forge-ba__apply' );
        const runBtn      = textarea?.closest( '[data-subpanel]' )?.querySelector( '.lingua-forge-ba__fn-run' );

        // Show result panel in loading state.
        if ( fnPanel )   fnPanel.hidden   = true;
        resultPanel.hidden   = false;
        resultMeta.innerHTML = `<em class="lingua-forge-ba__status">${ __( 'Processing…', 'lingua-forge' ) }</em>`;
        outputArea.value     = '';
        if ( runBtn ) runBtn.disabled = true;

        try {

            let url, body;

            if ( isTranslate ) {
                const lang = popover.querySelector( '#wpai-ba-fn-lang' )?.value || 'en';
                url  = `${ LinguaForgeAIBlockAction.restUrl }/translate-chunk`;
                body = { target_language: lang, chunk_text: chunkText };
            } else {
                const revisionType      = popover.querySelector( '#wpai-ba-fn-revision-type' )?.value || 'improve';
                const customInstruction = popover.querySelector( '#wpai-ba-fn-rv-instructions' )?.value.trim() || '';
                url  = `${ LinguaForgeAIBlockAction.restUrl }/revise-block`;
                body = { revision_type: revisionType, chunk_text: chunkText };
                if ( customInstruction ) body.custom_instruction = customInstruction;
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

                // Switch apply mode so the Apply button writes to footnote meta.
                applyMode = 'footnote';
                if ( applyBtn ) applyBtn.textContent = __( 'Apply to Footnote', 'lingua-forge' );

            } else {

                const errMsg = data.message || data.error || __( 'Failed. Please try again.', 'lingua-forge' );
                resultMeta.innerHTML =
                    `<span class="lingua-forge-ba__error">${ escHtml( errMsg ) }</span>`;
            }

        } catch ( _ ) {

            resultMeta.innerHTML =
                `<span class="lingua-forge-ba__error">${ __( 'Request failed. Check your connection.', 'lingua-forge' ) }</span>`;
        }

        if ( runBtn ) runBtn.disabled = false;
    }

    /**
     * Write the output textarea's content back to the selected footnote in the
     * editor's `footnotes` post meta via the core/editor data store.
     */
    function applyToFootnote( popover ) {

        if ( !activeFootnoteId ) return;

        const outputArea = popover.querySelector( '.lingua-forge-ba__textarea--output' );
        const newContent = outputArea?.value || '';
        if ( !newContent ) return;

        try {
            const meta    = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
            const all     = JSON.parse( ( meta && meta.footnotes ) || '[]' );
            const updated = Array.isArray( all )
                ? all.map( fn => fn.id === activeFootnoteId ? { ...fn, content: newContent } : fn )
                : all;

            dispatch( 'core/editor' ).editPost( { meta: { footnotes: JSON.stringify( updated ) } } );

            // Refresh in-memory item so syncFnContent shows the applied content.
            const item = activeFootnoteItems.find( f => f.id === activeFootnoteId );
            if ( item ) item.content = newContent;

        } catch ( _ ) { return; }

        const applyBtn = popover.querySelector( '.lingua-forge-ba__apply' );
        if ( applyBtn ) {
            applyBtn.textContent = __( 'Applied ✓', 'lingua-forge' );
            setTimeout( () => { applyBtn.textContent = __( 'Apply to Footnote', 'lingua-forge' ); }, 2000 );
        }
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

    /** Strip HTML tags from a string (used to build readable footnote labels). */
    function stripHtml( html ) {
        const tmp = document.createElement( 'div' );
        tmp.innerHTML = html;
        return tmp.textContent || tmp.innerText || '';
    }

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
