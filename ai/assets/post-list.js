/**
 * Post-list action button handlers.
 *
 * Handles two buttons injected into the "Lang" column by PostListColumn:
 *
 *   .lf-fill-missing  — "Translate missing" on source posts with ⭕ indicator.
 *   .lf-retranslate   — "Retranslate" on target posts with ⚠ indicator.
 *
 * On click (both buttons):
 *   • Disables the button and shows a spinner label
 *   • POSTs to the respective wp_ajax_* handler
 *   • On success: removes the status indicator and replaces the button with "✓ Done"
 *   • On error:   re-enables the button and appends a small error note
 *
 * Depends on: jQuery (wp-includes), lfPostList (wp_localize_script).
 *
 * @since 1.8.1
 */
( function ( $ ) {
	'use strict';

	var cfg = window.lfPostList || {};

	// ── "Translate missing" ───────────────────────────────────────────────────

	$( document ).on( 'click', '.lf-fill-missing', function () {
		var $btn   = $( this );
		var postId = $btn.data( 'post-id' );
		var $cell  = $btn.closest( 'td' );

		if ( $btn.prop( 'disabled' ) ) {
			return;
		}

		$btn.prop( 'disabled', true )
		    .addClass( 'lf-fill-missing--busy' )
		    .text( cfg.l10n && cfg.l10n.translating ? cfg.l10n.translating : 'Translating…' );

		$cell.find( '.lf-fill-error' ).remove();

		$.post(
			cfg.ajaxUrl || ajaxurl,
			{
				action:  cfg.action || 'lf_fill_missing',
				nonce:   cfg.nonce  || '',
				post_id: postId,
			},
			function ( response ) {
				if ( ! response || ! response.success ) {
					var msg = ( response && response.data && response.data.message )
						? response.data.message
						: ( cfg.l10n && cfg.l10n.error ? cfg.l10n.error : 'Error' );

					$btn.prop( 'disabled', false )
					    .removeClass( 'lf-fill-missing--busy' )
					    .text( 'Translate missing' );

					$btn.after(
						$( '<span class="lf-fill-error"></span>' ).text( ' ' + msg )
					);
					return;
				}

				$cell.find( '.lf-missing-langs' ).remove();
				$btn.replaceWith(
					$( '<span class="lf-fill-done"></span>' )
						.text( cfg.l10n && cfg.l10n.done ? cfg.l10n.done : '✓ Done' )
				);
			}
		).fail( function () {
			$btn.prop( 'disabled', false )
			    .removeClass( 'lf-fill-missing--busy' )
			    .text( 'Translate missing' );
		} );
	} );

	// ── "Retranslate" ────────────────────────────────────────────────────────

	$( document ).on( 'click', '.lf-retranslate', function () {
		var $btn   = $( this );
		var postId = $btn.data( 'post-id' );
		var $cell  = $btn.closest( 'td' );

		if ( $btn.prop( 'disabled' ) ) {
			return;
		}

		$btn.prop( 'disabled', true )
		    .addClass( 'lf-retranslate--busy' )
		    .text( cfg.l10n && cfg.l10n.retranslating ? cfg.l10n.retranslating : 'Retranslating…' );

		$cell.find( '.lf-retranslate-error' ).remove();

		var fromLang = $btn.closest( '.lf-retranslate-wrap' ).find( '.lf-retranslate-from' ).val() || '';

		$.post(
			cfg.ajaxUrl || ajaxurl,
			{
				action:    cfg.actionRetranslate || 'lf_retranslate',
				nonce:     cfg.nonceRetranslate  || '',
				post_id:   postId,
				from_lang: fromLang,
			},
			function ( response ) {
				if ( ! response || ! response.success ) {
					var msg = ( response && response.data && response.data.message )
						? response.data.message
						: ( cfg.l10n && cfg.l10n.error ? cfg.l10n.error : 'Error' );

					$btn.prop( 'disabled', false )
					    .removeClass( 'lf-retranslate--busy' )
					    .text( 'Retranslate' );

					$btn.closest( '.lf-retranslate-wrap' ).after(
						$( '<span class="lf-retranslate-error"></span>' ).text( ' ' + msg )
					);
					return;
				}

				// Remove ⚠ indicator and replace button with ✓ Done.
				$cell.find( '.lf-outdated-indicator' ).remove();
				$btn.replaceWith(
					$( '<span class="lf-fill-done"></span>' )
						.text( cfg.l10n && cfg.l10n.done ? cfg.l10n.done : '✓ Done' )
				);
			}
		).fail( function () {
			$btn.prop( 'disabled', false )
			    .removeClass( 'lf-retranslate--busy' )
			    .text( 'Retranslate' );
		} );
	} );

} )( jQuery );
