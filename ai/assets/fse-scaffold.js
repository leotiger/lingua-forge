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
     */
    function scaffoldOne(lang, base, $cell, onDone) {
        $.post(ajaxUrl, {
            action:    'linguaforge_scaffold_template',
            nonce:     scaffoldNonce,
            lang:      lang,
            base_slug: base
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
            }
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $cell.find('.lf-scaffold-one-btn').prop('disabled', false).text('Create');
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
     */
    function scaffoldPart(lang, base, $cell, onDone) {
        $.post(ajaxUrl, {
            action:    'linguaforge_scaffold_template_part',
            nonce:     scaffoldPartNonce,
            lang:      lang,
            base_slug: base
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
            }
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $cell.find('.lf-scaffold-part-btn').prop('disabled', false).text('Create');
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
