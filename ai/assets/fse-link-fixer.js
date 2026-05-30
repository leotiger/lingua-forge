/**
 * Lingua Forge — FSE link fixer
 *
 * Handles:
 *   • Per-cell "Fix Links" button    (.lf-fix-links-btn)
 *   • Per-row "Fix all links" button (.lf-fix-links-row-btn)
 *
 * Depends on window.lfRouterTab (injected by SettingsPage before router-tab.js).
 */
(function ($) {
    'use strict';

    var L              = window.lfRouterTab || {};
    var ajaxUrl        = L.ajaxUrl          || '';
    var fixLinksNonce  = L.fixLinksNonce    || '';
    var s              = L.strings          || {};

    /**
     * Rewrite internal links in a single FSE template or part to carry the
     * language URL prefix (e.g. /contact/ → /de/contact/).
     *
     * The button is re-enabled after the call completes so the admin can run
     * it again (e.g. after editing the template content).
     *
     * @param {string}   slug      Full language-specific slug (e.g. 'page-de').
     * @param {string}   postType  'wp_template' or 'wp_template_part'.
     * @param {jQuery}   $btn      The per-cell Fix Links button.
     * @param {Function} onDone    Callback(success: boolean) fired when done.
     */
    function fixFseLinks(slug, postType, $btn, onDone) {
        $.post(ajaxUrl, {
            action:    'linguaforge_fix_fse_links',
            nonce:     fixLinksNonce,
            slug:      slug,
            post_type: postType
        }, function (resp) {
            $btn.prop('disabled', false).text(s.fixLinks || 'Fix Links');
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $btn.prop('disabled', false).text(s.fixLinks || 'Fix Links');
            if (typeof onDone === 'function') { onDone(false); }
        });
    }

    // Per-cell "Fix Links" button.
    $(document).on('click', '.lf-fix-links-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text(s.fixingLinks || 'Fixing…');
        fixFseLinks($btn.data('slug'), $btn.data('post-type'), $btn, null);
    });

    // Per-row "Fix all links" button — fixes every existing template / part
    // in the row in parallel.
    $(document).on('click', '.lf-fix-links-row-btn', function () {
        var $btn     = $(this);
        var $tplRow  = $btn.closest('tr.lf-tpl-row');
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var $pending = $tplRow.find('.lf-fix-links-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.linksFixed || '✓ Links fixed.');
            return;
        }

        $btn.prop('disabled', true);
        $msg.removeClass('lf-ok lf-fail lf-warn').text('');

        var total  = $pending.length;
        var done   = 0;
        var failed = 0;

        $pending.each(function () {
            var $oneBtn = $(this);
            $oneBtn.prop('disabled', true).text(s.fixingLinks || 'Fixing…');
            fixFseLinks($oneBtn.data('slug'), $oneBtn.data('post-type'), $oneBtn, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    $btn.prop('disabled', false);
                    if (failed === 0) {
                        $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                            .text(s.linksFixed || '✓ Links fixed.');
                    } else {
                        $msg.removeClass('lf-ok lf-warn').addClass('lf-fail')
                            .text(s.linksFail || 'Some link fixes failed.');
                    }
                }
            });
        });
    });

}(jQuery));
