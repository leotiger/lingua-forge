/**
 * Lingua Forge — Editor Preview Language Switcher
 *
 * Injects a globe icon button into .interface-pinned-items (the same container
 * where Quick Translate lives) in both the block post editor and the Site
 * Editor.  Clicking it opens a small dropdown to switch the current admin
 * user's WordPress locale — so the canvas, meta boxes, and plugin .mo
 * translations all render in the chosen language.
 *
 * Uses the same DOM-injection + MutationObserver pattern as editor-translate.js.
 * registerPlugin / SlotFill is intentionally avoided: it has no reliable slot
 * for the top toolbar across all WP 6.x / 7.x versions.
 *
 * PHP inline var (before): window.lfLocaleSwitcher = {
 *   ajaxUrl  : string,
 *   nonce    : string,
 *   languages: [ { lang, label, active }, … ],
 * }
 */

( function () {
	'use strict';

	if ( window.lfLocaleSwitcherInit ) return;
	window.lfLocaleSwitcherInit = true;

	var data = window.lfLocaleSwitcher;
	if ( ! data || ! data.languages || ! data.languages.length ) return;

	/* ── CSS ──────────────────────────────────────────────────────────────────── */

	var style       = document.createElement( 'style' );
	style.textContent = [
		'.lf-locale-btn { padding:0!important; color:inherit; }',
		'.lf-locale-btn .dashicons { font-size:20px;line-height:1;width:20px;height:20px;display:block; }',
		'.lf-locale-btn[aria-expanded="true"],.lf-locale-btn:hover { color:#fff!important;background:var(--wp-admin-theme-color,#3858e9)!important; }',
		'#lf-locale-dropdown { position:fixed;z-index:100000;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.15);min-width:130px;padding:4px 0; }',
		'#lf-locale-dropdown button { display:block;width:100%;padding:6px 14px;text-align:left;background:none;border:none;cursor:pointer;font-size:13px;line-height:1.4; }',
		'#lf-locale-dropdown button:hover { background:#f0f0f0; }',
		'#lf-locale-dropdown button.is-active { font-weight:600; }',
	].join( '\n' );
	document.head.appendChild( style );

	/* ── Constants ────────────────────────────────────────────────────────────── */

	var BTN_CLASS = 'lf-locale-btn';
	var DDN_ID    = 'lf-locale-dropdown';

	var HEADER_SELECTORS = [
		'.interface-pinned-items',
		'.edit-site-header-edit-mode__end',
		'.edit-post-header__settings',
		'.editor-header__end',
		'.editor-header__settings',
	];

	/* ── Dropdown ─────────────────────────────────────────────────────────────── */

	var dropdown = document.createElement( 'div' );
	dropdown.id     = DDN_ID;
	dropdown.hidden = true;
	dropdown.setAttribute( 'role', 'menu' );

	data.languages.forEach( function ( item ) {
		var btn       = document.createElement( 'button' );
		btn.type      = 'button';
		btn.textContent = ( item.active ? '✓ ' : '' ) + item.label;
		if ( item.active ) btn.className = 'is-active';
		btn.addEventListener( 'click', function () {
			if ( item.active ) { closeDropdown(); return; }
			btn.textContent = '…';
			btn.disabled    = true;
			var fd = new FormData();
			fd.append( 'action', 'lf_set_user_locale' );
			fd.append( 'nonce',  data.nonce );
			fd.append( 'lang',   item.lang );
			fetch( data.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { if ( r.ok ) window.location.reload(); } );
		} );
		dropdown.appendChild( btn );
	} );

	document.body.appendChild( dropdown );

	/* ── Open / close ─────────────────────────────────────────────────────────── */

	function openDropdown( anchorBtn ) {
		var rect  = anchorBtn.getBoundingClientRect();
		dropdown.style.top  = ( rect.bottom + 4 ) + 'px';
		dropdown.style.left = rect.left + 'px';
		dropdown.hidden = false;
		anchorBtn.setAttribute( 'aria-expanded', 'true' );
	}

	function closeDropdown() {
		dropdown.hidden = true;
		var btn = document.querySelector( '.' + BTN_CLASS );
		if ( btn ) btn.setAttribute( 'aria-expanded', 'false' );
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! dropdown.hidden && ! dropdown.contains( e.target )
			&& ! e.target.closest( '.' + BTN_CLASS ) ) {
			closeDropdown();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && ! dropdown.hidden ) closeDropdown();
	} );

	/* ── Button factory ───────────────────────────────────────────────────────── */

	function buildButton() {
		var btn     = document.createElement( 'button' );
		btn.type    = 'button';
		btn.className = 'components-button is-compact has-icon ' + BTN_CLASS;

		var activeLabel = 'EN';
		data.languages.forEach( function ( item ) {
			if ( item.active ) activeLabel = item.label;
		} );

		btn.setAttribute( 'aria-label',    'Preview: ' + activeLabel );
		btn.setAttribute( 'aria-expanded', 'false' );
		btn.setAttribute( 'aria-haspopup', 'true' );
		btn.title     = 'Preview language: ' + activeLabel;
		btn.innerHTML = '<span class="dashicons dashicons-admin-site" aria-hidden="true"></span>';

		btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			if ( dropdown.hidden ) {
				openDropdown( btn );
			} else {
				closeDropdown();
			}
		} );

		return btn;
	}

	/* ── MutationObserver injection (mirrors editor-translate.js) ─────────────── */

	var monitoredContainers = typeof WeakSet !== 'undefined' ? new WeakSet() : null;

	function tryInject() {
		for ( var i = 0; i < HEADER_SELECTORS.length; i++ ) {
			var container = document.querySelector( HEADER_SELECTORS[ i ] );
			if ( ! container ) continue;

			// Remove any buttons in lower-priority containers from a prior inject.
			document.querySelectorAll( '.' + BTN_CLASS ).forEach( function ( b ) {
				if ( ! container.contains( b ) ) b.remove();
			} );

			if ( ! container.querySelector( '.' + BTN_CLASS ) ) {
				container.insertBefore( buildButton(), container.firstChild );
			}

			if ( monitoredContainers && ! monitoredContainers.has( container ) ) {
				monitoredContainers.add( container );
				( new MutationObserver( function () {
					if ( ! container.querySelector( '.' + BTN_CLASS ) ) {
						container.insertBefore( buildButton(), container.firstChild );
					}
				} ) ).observe( container, { childList: true } );
			}

			break; // first matching container wins
		}
	}

	function init() {
		tryInject();

		var rafId = null;
		var bodyObserver = new MutationObserver( function () {
			if ( rafId ) return;
			rafId = requestAnimationFrame( function () { tryInject(); rafId = null; } );
		} );
		bodyObserver.observe( document.body, { childList: true, subtree: true } );

		var poll = setInterval( tryInject, 750 );
		setTimeout( function () {
			clearInterval( poll );
			bodyObserver.disconnect();
		}, 30000 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

} )();
