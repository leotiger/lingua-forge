/**
 * Lingua Forge — FSE template + template part scaffold
 *
 * Handles:
 *   • Single-template "Create" button   (.lf-scaffold-one-btn)
 *   • Per-row "Create missing" button   (.lf-scaffold-all-btn)
 *   • Single-part "Create" button       (.lf-scaffold-part-btn)
 *   • Per-row "Create all parts" button (.lf-scaffold-all-parts-btn)
 *
 * Depends on window.lfRouterTab (injected by SettingsPage before router-tab.js).
 */
(function ($) {
    'use strict';

    var L                 = window.lfRouterTab || {};
    var ajaxUrl           = L.ajaxUrl           || '';
    var scaffoldNonce     = L.scaffoldNonce     || '';
    var scaffoldPartNonce = L.scaffoldPartNonce || '';
    var s                 = L.strings           || {};

    // ── Template scaffold ─────────────────────────────────────────────────────

    /**
     * Call the scaffold endpoint for one template.
     *
     * @param {string}   lang    Two-char language code.
     * @param {string}   base    Base template slug (e.g. 'page', 'search').
     * @param {jQuery}   $cell   The <td> containing the Create button.
     * @param {Function} onDone  Callback(success: boolean) fired when done.
     * @param {boolean}  [force] When true, overwrites an existing template
     *                           instead of failing ("Re-create").
     */
    function scaffoldOne(lang, base, $cell, onDone, force) {
        $.post(ajaxUrl, {
            action:    'linguaforge_scaffold_template',
            nonce:     scaffoldNonce,
            lang:      lang,
            base_slug: base,
            force:     force ? 1 : 0
        }, function (resp) {
            if (resp.success) {
                // Prefer server-rendered buttons_html so action buttons appear
                // immediately without a page reload (§9.4.2).
                if (resp.data && resp.data.buttons_html) {
                    $cell.html(resp.data.buttons_html);
                } else {
                    $cell.html(
                        '<span class="lf-tpl-exists" title="' +
                        resp.data.slug + '.html">✓</span>'
                    );
                }
            } else {
                $cell.find('.lf-scaffold-one-btn').prop('disabled', false).text('Create');
                $cell.find('.lf-recreate-one-btn').prop('disabled', false).text(s.recreate || 'Re-create');
            }
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $cell.find('.lf-scaffold-one-btn').prop('disabled', false).text('Create');
            $cell.find('.lf-recreate-one-btn').prop('disabled', false).text(s.recreate || 'Re-create');
            if (typeof onDone === 'function') { onDone(false); }
        });
    }

    // Single-template "Create" button.
    $(document).on('click', '.lf-scaffold-one-btn', function () {
        var $btn  = $(this);
        var $cell = $btn.closest('.lf-tpl-cell');
        $btn.prop('disabled', true).text(s.creating || 'Creating…');
        scaffoldOne($btn.data('lang'), $btn.data('base'), $cell, null);
    });

    // Single-template "Re-create" button — overwrites an existing template
    // with a fresh copy from the active theme. Destructive (discards any
    // Site Editor customisations), so confirm first.
    $(document).on('click', '.lf-recreate-one-btn', function () {
        var $btn  = $(this);
        var $cell = $btn.closest('.lf-tpl-cell');

        var confirmMsg = s.recreateConfirm ||
            'This overwrites this template with a fresh copy from the active theme, discarding any Site Editor customisations made to it. This cannot be undone. Continue?';
        if (!window.confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true).text(s.recreating || 'Recreating…');
        scaffoldOne($btn.data('lang'), $btn.data('base'), $cell, null, true);
    });

    /**
     * Force-recreate every template in one language's Templates row.
     *
     * Shared by the row's own "Re-create all" button and the global
     * cross-language "Recreate All Languages" orchestrator (fse-global-actions.js).
     * Does not touch the triggering button's disabled state — that's the
     * caller's responsibility, since the global orchestrator doesn't have
     * "a button" for each row in the same sense the per-row click handler does.
     *
     * @param {jQuery}   $tplRow The `.lf-tpl-row` wrapper (Templates section, per language).
     * @param {Function} onDone  Callback(failedCount, totalCount) fired once every
     *                           cell in the row has settled.
     */
    function recreateAllTemplatesInRow($tplRow, onDone) {
        var lang   = $tplRow.data('lang');
        var $msg   = $tplRow.find('.lf-scaffold-row-msg');
        var $cells = $tplRow.find('.lf-tpl-cell');

        if (!$cells.length) {
            if (typeof onDone === 'function') { onDone(0, 0); }
            return;
        }

        $msg.removeClass('lf-ok lf-fail').text('');

        var total  = $cells.length;
        var done   = 0;
        var failed = 0;

        $cells.each(function () {
            var $cell = $(this);
            var base  = $cell.data('base');
            $cell.find('.lf-scaffold-one-btn, .lf-recreate-one-btn')
                .prop('disabled', true)
                .text(s.recreating || 'Recreating…');
            scaffoldOne(lang, base, $cell, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    if (failed === 0) {
                        $msg.removeClass('lf-fail').addClass('lf-ok')
                            .text(s.allRecreated || '✓ All templates recreated.');
                    } else {
                        $msg.removeClass('lf-ok').addClass('lf-fail')
                            .text(s.recreateFail || 'Some templates could not be recreated.');
                    }
                    if (typeof onDone === 'function') { onDone(failed, total); }
                }
            }, true);
        });
    }

    // Per-row "Re-create all" button — force-refreshes every template in the
    // row, whether it already existed or not. Destructive, so confirm first.
    $(document).on('click', '.lf-recreate-all-btn', function () {
        var $btn    = $(this);
        var $tplRow = $btn.closest('.lf-tpl-row');

        if (!$tplRow.find('.lf-tpl-cell').length) {
            return;
        }

        var confirmMsg = s.recreateAllConfirm ||
            'This overwrites every template with a fresh copy from the active theme, discarding any Site Editor customisations made to them. This cannot be undone. Continue?';
        if (!window.confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true);
        recreateAllTemplatesInRow($tplRow, function () {
            $btn.prop('disabled', false);
        });
    });

    // Per-row "Create missing" button — creates every pending template in the row.
    $(document).on('click', '.lf-scaffold-all-btn', function () {
        var $btn     = $(this);
        var $tplRow  = $btn.closest('.lf-tpl-row');
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var lang     = $btn.data('lang');
        var $pending = $tplRow.find('.lf-scaffold-one-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail').addClass('lf-ok')
                .text(s.allDone || '✓ All templates created.');
            return;
        }

        $btn.prop('disabled', true);
        $msg.removeClass('lf-ok lf-fail').text('');

        var total  = $pending.length;
        var done   = 0;
        var failed = 0;

        $pending.each(function () {
            var $oneBtn = $(this);
            var $cell   = $oneBtn.closest('.lf-tpl-cell');
            $oneBtn.prop('disabled', true).text(s.creating || 'Creating…');
            scaffoldOne(lang, $oneBtn.data('base'), $cell, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    $btn.prop('disabled', false);
                    if (failed === 0) {
                        $msg.removeClass('lf-fail').addClass('lf-ok')
                            .text(s.allDone || '✓ All templates created.');
                    } else {
                        $msg.removeClass('lf-ok').addClass('lf-fail')
                            .text(s.allFail || 'Some templates could not be created.');
                    }
                }
            });
        });
    });

    // ── Template part scaffold ────────────────────────────────────────────────

    /**
     * Call the scaffold endpoint for one template part.
     *
     * @param {string}   lang    Two-char language code.
     * @param {string}   base    Base part slug (e.g. 'header', 'footer').
     * @param {jQuery}   $cell   The <td> containing the Create button.
     * @param {Function} onDone  Callback(success: boolean) fired when done.
     * @param {boolean}  [force] When true, overwrites an existing part
     *                           instead of failing ("Re-create").
     */
    function scaffoldPart(lang, base, $cell, onDone, force) {
        $.post(ajaxUrl, {
            action:    'linguaforge_scaffold_template_part',
            nonce:     scaffoldPartNonce,
            lang:      lang,
            base_slug: base,
            force:     force ? 1 : 0
        }, function (resp) {
            if (resp.success) {
                // Prefer server-rendered buttons_html so action buttons appear
                // immediately without a page reload (§9.4.2).
                if (resp.data && resp.data.buttons_html) {
                    $cell.html(resp.data.buttons_html);
                } else {
                    $cell.html(
                        '<span class="lf-tpl-exists" title="' +
                        resp.data.slug + '.html">✓</span>'
                    );
                }
            } else {
                $cell.find('.lf-scaffold-part-btn').prop('disabled', false).text('Create');
                $cell.find('.lf-recreate-part-btn').prop('disabled', false).text(s.recreate || 'Re-create');
            }
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $cell.find('.lf-scaffold-part-btn').prop('disabled', false).text('Create');
            $cell.find('.lf-recreate-part-btn').prop('disabled', false).text(s.recreate || 'Re-create');
            if (typeof onDone === 'function') { onDone(false); }
        });
    }

    // Single-part "Create" button.
    $(document).on('click', '.lf-scaffold-part-btn', function () {
        var $btn  = $(this);
        var $cell = $btn.closest('.lf-tpl-cell');
        $btn.prop('disabled', true).text(s.creating || 'Creating…');
        scaffoldPart($btn.data('lang'), $btn.data('base'), $cell, null);
    });

    // Single-part "Re-create" button — overwrites an existing part with a
    // fresh copy from the active theme. Destructive, so confirm first.
    $(document).on('click', '.lf-recreate-part-btn', function () {
        var $btn  = $(this);
        var $cell = $btn.closest('.lf-tpl-cell');

        var confirmMsg = s.recreatePartConfirm ||
            'This overwrites this template part with a fresh copy from the active theme, discarding any Site Editor customisations made to it. This cannot be undone. Continue?';
        if (!window.confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true).text(s.recreating || 'Recreating…');
        scaffoldPart($btn.data('lang'), $btn.data('base'), $cell, null, true);
    });

    /**
     * Force-recreate every part in one language's Template Parts group.
     *
     * Shared by the group's own "Re-create all" button and the global
     * cross-language "Recreate All Languages" orchestrator (fse-global-actions.js).
     * Does not touch the triggering button's disabled state — see the
     * matching note on recreateAllTemplatesInRow() above.
     *
     * @param {jQuery}   $group  The `.lf-parts-group` wrapper (Template Parts section, per language).
     * @param {Function} onDone  Callback(failedCount, totalCount) fired once every
     *                           cell in the group has settled.
     */
    function recreateAllPartsInGroup($group, onDone) {
        var lang   = $group.data('lang');
        var $msg   = $group.find('.lf-template-bulk-actions').find('.lf-scaffold-row-msg');
        var $cells = $group.find('.lf-tpl-cell');

        if (!$cells.length) {
            if (typeof onDone === 'function') { onDone(0, 0); }
            return;
        }

        $msg.removeClass('lf-ok lf-fail').text('');

        var total  = $cells.length;
        var done   = 0;
        var failed = 0;

        $cells.each(function () {
            var $cell = $(this);
            var base  = $cell.data('base');
            $cell.find('.lf-scaffold-part-btn, .lf-recreate-part-btn')
                .prop('disabled', true)
                .text(s.recreating || 'Recreating…');
            scaffoldPart(lang, base, $cell, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    if (failed === 0) {
                        $msg.removeClass('lf-fail').addClass('lf-ok')
                            .text(s.allPartsRecreated || '✓ All parts recreated.');
                    } else {
                        $msg.removeClass('lf-ok').addClass('lf-fail')
                            .text(s.partsRecreateFail || 'Some parts could not be recreated.');
                    }
                    if (typeof onDone === 'function') { onDone(failed, total); }
                }
            }, true);
        });
    }

    // "Re-create all" button — force-refreshes every part in the group,
    // whether it already existed or not. Destructive, so confirm first.
    $(document).on('click', '.lf-recreate-all-parts-btn', function () {
        var $btn   = $(this);
        var $group = $btn.closest('.lf-parts-group');

        if (!$group.find('.lf-tpl-cell').length) {
            return;
        }

        var confirmMsg = s.recreateAllPartsConfirm ||
            'This overwrites every template part with a fresh copy from the active theme, discarding any Site Editor customisations made to them. This cannot be undone. Continue?';
        if (!window.confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true);
        recreateAllPartsInGroup($group, function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Expose for the global cross-language orchestrator ────────────────────
    window.lfFseActions = window.lfFseActions || {};
    window.lfFseActions.recreateAllTemplates = recreateAllTemplatesInRow;
    window.lfFseActions.recreateAllParts     = recreateAllPartsInGroup;

    // Per-row "Create all parts" button — creates every pending part in the row.
    $(document).on('click', '.lf-scaffold-all-parts-btn', function () {
        var $btn     = $(this);
        var $partRow = $btn.closest('.lf-tpl-row');
        var $msg     = $partRow.find('.lf-scaffold-row-msg');
        var $pending = $partRow.find('.lf-scaffold-part-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail').addClass('lf-ok')
                .text(s.allPartsDone || '✓ All parts created.');
            return;
        }

        $btn.prop('disabled', true);
        $msg.removeClass('lf-ok lf-fail').text('');

        var total  = $pending.length;
        var done   = 0;
        var failed = 0;

        $pending.each(function () {
            var $oneBtn = $(this);
            var $cell   = $oneBtn.closest('.lf-tpl-cell');
            $oneBtn.prop('disabled', true).text(s.creating || 'Creating…');
            scaffoldPart($oneBtn.data('lang'), $oneBtn.data('base'), $cell, function (success) {
                done++;
                if (!success) { failed++; }
                if (done === total) {
                    $btn.prop('disabled', false);
                    if (failed === 0) {
                        $msg.removeClass('lf-fail').addClass('lf-ok')
                            .text(s.allPartsDone || '✓ All parts created.');
                    } else {
                        $msg.removeClass('lf-ok').addClass('lf-fail')
                            .text(s.allPartsFail || 'Some parts could not be created.');
                    }
                }
            });
        });
    });

}(jQuery));
