/**
 * Lingua Forge — Settings › CPT block pattern translation
 *
 * Handles:
 *   • Per-row "Translate" / "Re-translate" button  (.lf-translate-pattern-btn)
 *   • Per-row "View" toggle button                 (.lf-view-pattern-btn)
 *
 * Depends on window.lfRouterTab (injected by SettingsPage before router-tab.js).
 */
(function ($) {
    'use strict';

    var L             = window.lfRouterTab || {};
    var ajaxUrl       = L.ajaxUrl          || '';
    var patternNonce  = L.patternNonce     || '';
    var s             = L.strings          || {};

    // ── Translate pattern ─────────────────────────────────────────────────────

    $(document).on('click', '.lf-translate-pattern-btn', function () {
        var $btn  = $(this);
        var $row  = $btn.closest('tr.lf-pattern-row');
        var $msg  = $row.find('.lf-scaffold-row-msg');
        var name  = $btn.data('name');
        var lang  = $btn.data('lang');

        $btn.prop('disabled', true).text(s.translatingPattern || 'Translating…');
        $msg.removeClass('lf-ok lf-fail lf-warn').text('');

        $.post(ajaxUrl, {
            action: 'linguaforge_translate_pattern',
            nonce:  patternNonce,
            name:   name,
            lang:   lang
        }, function (resp) {
            $btn.prop('disabled', false);

            if (resp.success) {
                $btn.text(s.retranslate || 'Re-translate');
                $msg.addClass(resp.data.warning ? 'lf-warn' : 'lf-ok')
                    .text(resp.data.message || s.patternTranslated || '✓ Pattern translated.');

                // Mark the row as having a saved translation.
                $row.find('td:first-child').find('.lf-pattern-translated-note').remove();
                $row.find('td:first-child code').after(
                    '<span class="lf-pattern-translated-note" style="color:#46b450;font-size:11px;display:block;">' +
                    (s.translationSaved || '✓ Translation saved') + '</span>'
                );

                // Show "View" button if it wasn't already present.
                if ( ! $row.find('.lf-view-pattern-btn').length ) {
                    var $view = $('<button>', {
                        type:  'button',
                        class: 'button button-small lf-view-pattern-btn',
                        text:  s.view || 'View'
                    }).data('name', name).data('lang', lang);
                    $btn.after(' ', $view);
                }

                // Reload the page after a short delay so the preview row
                // (rendered server-side) reflects the new translation.
                setTimeout(function () { window.location.reload(); }, 2000);
            } else {
                $btn.text(s.translatePattern || 'Translate');
                $msg.addClass('lf-fail').text((s.patternFail || 'Pattern translation failed.') + ' ' + (resp.data || ''));
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(s.translatePattern || 'Translate');
            $msg.addClass('lf-fail').text('Network error. Please try again.');
        });
    });

    // ── View / hide translated content ───────────────────────────────────────

    $(document).on('click', '.lf-view-pattern-btn', function () {
        var $btn      = $(this);
        var name      = $btn.data('name');
        var lang      = $btn.data('lang');
        // Build the preview row ID to match the PHP output.
        var safeKey   = (name + '').replace(/\//g, '__');
        var $preview  = $('#lf-pattern-preview-' + safeKey + '-' + lang);

        if ( $preview.length ) {
            var visible = $preview.is(':visible');
            $preview.toggle( ! visible );
            $btn.text( ! visible ? (s.hide || 'Hide') : (s.view || 'View') );
        }
    });

}(jQuery));
