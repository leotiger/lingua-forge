/**
 * Lingua Forge — Social Share JS
 *
 * Handles the three JavaScript-powered share actions that cannot be resolved
 * to a static URL at render time:
 *
 *   share:copy   — copies the current page URL to the clipboard.
 *   share:native — opens the browser's native Web Share sheet (mobile).
 *   share:auto   — native share when available, clipboard copy as fallback.
 *
 * These actions are wired up by LinguaForge\Router\Seo\SocialShare which
 * adds a data-lf-share attribute to the Social Icons block link element
 * via the render_block_core/social-link filter.
 *
 * No global namespace pollution — everything lives inside the IIFE.
 */
( function () {
	'use strict';

	// ── i18n ────────────────────────────────────────────────────────────────────
	// Strings are injected by wp_localize_script as lfSocialShare.
	var strings = ( window.lfSocialShare && window.lfSocialShare.strings ) || {};
	var i18n = {
		copied:  strings.copied  || 'Link copied',
		failed:  strings.failed  || 'Copy failed — please copy the URL manually.',
	};

	// ── Toast notification ───────────────────────────────────────────────────────
	function showToast( message ) {

		var toast = document.createElement( 'div' );

		toast.textContent      = message;
		toast.style.position   = 'fixed';
		toast.style.bottom     = '20px';
		toast.style.left       = '50%';
		toast.style.transform  = 'translateX(-50%)';
		toast.style.padding    = '10px 14px';
		toast.style.background = '#111';
		toast.style.color      = '#fff';
		toast.style.borderRadius = '4px';
		toast.style.zIndex     = '999999';
		toast.style.fontSize   = '14px';
		toast.setAttribute( 'role', 'status' );
		toast.setAttribute( 'aria-live', 'polite' );

		document.body.appendChild( toast );

		setTimeout( function () { toast.remove(); }, 2000 );
	}

	// ── Share actions ────────────────────────────────────────────────────────────
	function copyLink() {

		if ( ! navigator.clipboard ) {
			window.prompt( i18n.failed, window.location.href );
			return;
		}

		navigator.clipboard.writeText( window.location.href )
			.then( function () {
				showToast( i18n.copied );
			} )
			.catch( function () {
				window.prompt( i18n.failed, window.location.href );
			} );
	}

	function nativeShare() {

		if ( ! navigator.share ) {
			copyLink();
			return;
		}

		navigator.share( {
			title: document.title,
			url:   window.location.href,
		} ).catch( function () {} );
	}

	// ── Click delegation ─────────────────────────────────────────────────────────
	document.addEventListener( 'click', function ( event ) {

		var link = event.target.closest( '[data-lf-share]' );
		if ( ! link ) { return; }

		event.preventDefault();

		switch ( link.dataset.lfShare ) {

			case 'copy':
				copyLink();
				break;

			case 'native':
				nativeShare();
				break;

			case 'auto':
				if ( navigator.share ) {
					nativeShare();
				} else {
					copyLink();
				}
				break;
		}
	} );

} () );
