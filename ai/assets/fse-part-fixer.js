/**
 * Lingua Forge — FSE part-reference + navigation-ref fixer
 *
 * Handles:
 *   • Per-cell "Fix Parts" button        (.lf-fix-parts-btn)
 *   • Per-row "Fix all parts" button     (.lf-fix-parts-row-btn)
 *   • Per-cell "Fix Nav" button          (.lf-fix-nav-refs-btn)
 *   • Per-row "Fix all nav refs" button  (.lf-fix-nav-refs-row-btn)
 *
 * Depends on window.lfRouterTab (injected by SettingsPage before router-tab.js).
 */
(function ($) {
    'use strict';

    var L              = window.lfRouterTab || {};
    var ajaxUrl        = L.ajaxUrl          || '';
    var fixPartsNonce  = L.fixPartsNonce    || '';
    var fixNavRefsNonce = L.fixNavRefsNonce || '';
    var s              = L.strings          || {};

    // ── FSE part reference fixer ──────────────────────────────────────────────

    /**
     * Rewrite core/template-part slugs in a single FSE template so they point
     * at the language-specific variant (e.g. footer → footer-ca) when that
     * variant exists.
     *
     * The button is re-enabled after the call completes so the admin can run
     * it again (e.g. after scaffolding additional parts).
     *
     * @param {string}   slug    Full language-specific template slug (e.g. 'page-ca').
     * @param {jQuery}   $btn    The per-cell Fix Parts button.
     * @param {Function} onDone  Callback(success: boolean) fired when done.
     */
    function fixFseParts(slug, $btn, onDone) {
        $.post(ajaxUrl, {
            action: 'linguaforge_fix_fse_parts',
            nonce:  fixPartsNonce,
            slug:   slug
        }, function (resp) {
            $btn.prop('disabled', false).text(s.fixParts || 'Fix Parts');
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $btn.prop('disabled', false).text(s.fixParts || 'Fix Parts');
            if (typeof onDone === 'function') { onDone(false); }
        });
    }

    // Per-cell "Fix Parts" button.
    $(document).on('click', '.lf-fix-parts-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text(s.fixingParts || 'Fixing…');
        fixFseParts($btn.data('slug'), $btn, null);
    });

    /**
     * Fix core/template-part references in every existing template in a row.
     *
     * Shared by the row's own "Fix all parts" button and the global
     * cross-language "Recreate All Languages" orchestrator (fse-global-actions.js).
     * Does not touch the triggering button's disabled state — that's the
     * caller's responsibility.
     *
     * @param {jQuery}   $tplRow The `.lf-tpl-row` wrapper (Templates section, per language).
     * @param {Function} onDone  Callback(failedCount, totalCount) fired once every
     *                           pending button in the row has settled.
     */
    function fixAllPartsInRow($tplRow, onDone) {
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var $pending = $tplRow.find('.lf-fix-parts-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.partsFixed || '✓ Parts fixed.');
            if (typeof onDone === 'function') { onDone(0, 0); }
            return;
        }

        $msg.removeClass('lf-ok lf-fail lf-warn').text('');

        var total  = $pending.length;
        var done   = 0;
        var failed = 0;

        $pending.each(function () {
            var $oneBtn = $(this);
            $oneBtn.prop('disabled', true).text(s.fixingParts || 'Fixing…');
            fixFseParts($oneBtn.data('slug'), $oneBtn, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    if (failed === 0) {
                        $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                            .text(s.partsFixed || '✓ Parts fixed.');
                    } else {
                        $msg.removeClass('lf-ok lf-warn').addClass('lf-fail')
                            .text(s.partsFail || 'Some part fixes failed.');
                    }
                    if (typeof onDone === 'function') { onDone(failed, total); }
                }
            });
        });
    }

    // Per-row "Fix all parts" button — fixes every existing template in the row
    // in parallel.
    $(document).on('click', '.lf-fix-parts-row-btn', function () {
        var $btn    = $(this);
        var $tplRow = $btn.closest('.lf-tpl-row');
        $btn.prop('disabled', true);
        fixAllPartsInRow($tplRow, function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Expose for the global cross-language orchestrator ────────────────────
    window.lfFseActions = window.lfFseActions || {};
    window.lfFseActions.fixAllParts = fixAllPartsInRow;

    // ── FSE navigation ref fixer ──────────────────────────────────────────────

    /**
     * Rewrite wp:navigation "ref" attributes in a language-specific template
     * part so they point at the corresponding language-copy navigation post
     * (e.g. ref:42 → ref:87 for primary-navigation-ca) when that copy exists.
     *
     * The button is re-enabled after the call so the admin can run it again.
     *
     * @param {string}   slug    Full language-specific part slug (e.g. 'header-ca').
     * @param {jQuery}   $btn    The per-cell Fix Nav button.
     * @param {Function} onDone  Callback(success: boolean) fired when done.
     */
    function fixFseNavRefs(slug, $btn, onDone) {
        $.post(ajaxUrl, {
            action: 'linguaforge_fix_fse_nav_refs',
            nonce:  fixNavRefsNonce,
            slug:   slug
        }, function (resp) {
            $btn.prop('disabled', false).text(s.fixNavRefs || 'Fix Nav');
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $btn.prop('disabled', false).text(s.fixNavRefs || 'Fix Nav');
            if (typeof onDone === 'function') { onDone(false); }
        });
    }

    // Per-cell "Fix Nav" button.
    $(document).on('click', '.lf-fix-nav-refs-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text(s.fixingNavRefs || 'Fixing…');
        fixFseNavRefs($btn.data('slug'), $btn, null);
    });

    // Per-row "Fix all nav refs" button — fixes every existing part in the row
    // in parallel.
    $(document).on('click', '.lf-fix-nav-refs-row-btn', function () {
        var $btn     = $(this);
        var $tplRow  = $btn.closest('.lf-tpl-row');
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var $pending = $tplRow.find('.lf-fix-nav-refs-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.navRefsFixed || '✓ Nav refs fixed.');
            return;
        }

        $btn.prop('disabled', true);
        $msg.removeClass('lf-ok lf-fail lf-warn').text('');

        var total  = $pending.length;
        var done   = 0;
        var failed = 0;

        $pending.each(function () {
            var $oneBtn = $(this);
            $oneBtn.prop('disabled', true).text(s.fixingNavRefs || 'Fixing…');
            fixFseNavRefs($oneBtn.data('slug'), $oneBtn, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    $btn.prop('disabled', false);
                    if (failed === 0) {
                        $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                            .text(s.navRefsFixed || '✓ Nav refs fixed.');
                    } else {
                        $msg.removeClass('lf-ok lf-warn').addClass('lf-fail')
                            .text(s.navRefsFail || 'Some nav ref fixes failed.');
                    }
                }
            });
        });
    });

}(jQuery));
