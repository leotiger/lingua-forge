/**
 * Post-list "Translate missing" button handler.
 *
 * The button is rendered by PostListColumn::render_fill_button() inside the
 * existing "Lang" column cell, right after the ⭕ missing-language indicator
 * (wrapped in <span class="lf-missing-langs">).
 *
 * On click:
 *   • Disables the button and shows "Translating…"
 *   • POSTs to wp_ajax_lf_fill_missing
 *   • On success: removes the missing-langs span and replaces the button with "✓ Done"
 *   • On error:   re-enables the button and appends a small error note
 *
 * Depends on: jQuery (wp-includes), lfPostList (wp_localize_script).
 *
 * @since 1.8.1
 */
( function ( $ ) {
	'use strict';

	var cfg = window.lfPostList || {};

	$( document ).on( 'click', '.lf-fill-missing', function () {
		var $btn   = $( this );
		var postId = $btn.data( 'post-id' );
		var $cell  = $btn.closest( 'td' );

		if ( $btn.prop( 'disabled' ) ) {
			return;
		}

		// ── Spinner state ─────────────────────────────────────────────────────
		$btn.prop( 'disabled', true )
		    .addClass( 'lf-fill-missing--busy' )
		    .text( cfg.l10n && cfg.l10n.translating ? cfg.l10n.translating : 'Translating…' );

		$cell.find( '.lf-fill-error' ).remove();

		// ── AJAX ──────────────────────────────────────────────────────────────
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

				// ── Success — remove ⭕ indicator and replace button with ✓ ──
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

} )( jQuery );
