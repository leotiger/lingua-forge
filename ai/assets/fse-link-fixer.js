/**
 * Lingua Forge — FSE link fixer
 *
 * Handles:
 *   • Per-cell "Fix Links" button                     (.lf-fix-links-btn)
 *   • Per-row "Fix all links" button (Templates)      (.lf-fix-links-row-btn)
 *   • "Fix all links" button (Template Parts)         (.lf-fix-links-all-parts-btn)
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

    /**
     * Fix internal links in every existing template / part in a Templates
     * row or Template Parts group.
     *
     * Shared by both the Templates row's and the Template Parts group's own
     * "Fix all links" button, and the global cross-language "Recreate All
     * Languages" orchestrator (fse-global-actions.js). Does not touch the
     * triggering button's disabled state — that's the caller's responsibility.
     *
     * @param {jQuery}   $scope The `.lf-tpl-row` (Templates) or `.lf-parts-group`
     *                          (Template Parts) wrapper, per language — see the
     *                          matching note on translateAllInRow() in
     *                          fse-translate.js for why `.lf-parts-group`
     *                          needs its own message-span scoping.
     * @param {Function} onDone Callback(failedCount, totalCount) fired once every
     *                          pending button in scope has settled.
     */
    function fixAllLinksInRow($scope, onDone) {
        var $msg     = $scope.hasClass('lf-parts-group')
            ? $scope.find('.lf-template-bulk-actions').find('.lf-scaffold-row-msg')
            : $scope.find('.lf-scaffold-row-msg');
        var $pending = $scope.find('.lf-fix-links-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.linksFixed || '✓ Links fixed.');
            if (typeof onDone === 'function') { onDone(0, 0); }
            return;
        }

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
                    if (failed === 0) {
                        $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                            .text(s.linksFixed || '✓ Links fixed.');
                    } else {
                        $msg.removeClass('lf-ok lf-warn').addClass('lf-fail')
                            .text(s.linksFail || 'Some link fixes failed.');
                    }
                    if (typeof onDone === 'function') { onDone(failed, total); }
                }
            });
        });
    }

    // Per-row "Fix all links" button (Templates) — fixes every existing
    // template in the row in parallel.
    $(document).on('click', '.lf-fix-links-row-btn', function () {
        var $btn    = $(this);
        var $tplRow = $btn.closest('.lf-tpl-row');
        $btn.prop('disabled', true);
        fixAllLinksInRow($tplRow, function () {
            $btn.prop('disabled', false);
        });
    });

    // "Fix all links" button (Template Parts) — fixes every existing part
    // in the group in parallel.
    $(document).on('click', '.lf-fix-links-all-parts-btn', function () {
        var $btn   = $(this);
        var $group = $btn.closest('.lf-parts-group');
        $btn.prop('disabled', true);
        fixAllLinksInRow($group, function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Expose for the global cross-language orchestrator ────────────────────
    window.lfFseActions = window.lfFseActions || {};
    window.lfFseActions.fixAllLinks      = fixAllLinksInRow;
    window.lfFseActions.fixAllLinksParts = fixAllLinksInRow;

}(jQuery));
