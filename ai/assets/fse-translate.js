/**
 * Lingua Forge — FSE content + navigation translation
 *
 * Handles:
 *   • Per-cell "Translate" button             (.lf-translate-one-btn)
 *   • Per-row "Translate all" button          (.lf-translate-row-btn)
 *   • Per-cell nav "Translate / Re-translate" (.lf-translate-nav-btn)
 *
 * Depends on window.lfRouterTab (injected by SettingsPage before router-tab.js).
 */
(function ($) {
    'use strict';

    var L                = window.lfRouterTab || {};
    var ajaxUrl          = L.ajaxUrl          || '';
    var translateNonce   = L.translateNonce   || '';
    var translateNavNonce = L.translateNavNonce || '';
    var s                = L.strings          || {};

    // ── FSE content translation ───────────────────────────────────────────────

    /**
     * Apply a rudimentary AI translation to a single FSE template or part.
     *
     * On success the button is replaced with a muted "✓" indicator.
     * The caller receives a `warning` flag so the row message can tell the
     * user that the result needs review.
     *
     * @param {string}   slug      Full language-specific slug (e.g. 'page-de').
     * @param {string}   postType  'wp_template' or 'wp_template_part'.
     * @param {jQuery}   $btn      The per-cell Translate button.
     * @param {Function} onDone    Callback(success, warning) fired when done.
     */
    function translateFse(slug, postType, $btn, onDone) {
        $.post(ajaxUrl, {
            action:    'linguaforge_translate_fse_content',
            nonce:     translateNonce,
            slug:      slug,
            post_type: postType
        }, function (resp) {
            if (resp.success) {
                $btn.prop('disabled', true)
                    .addClass('lf-translated-btn')
                    .text('✓');
            } else {
                $btn.prop('disabled', false)
                    .text(s.translate || 'Translate');
            }
            var warning = resp.success && resp.data && resp.data.warning;
            if (typeof onDone === 'function') { onDone(resp.success, warning); }
        }).fail(function () {
            $btn.prop('disabled', false).text(s.translate || 'Translate');
            if (typeof onDone === 'function') { onDone(false, false); }
        });
    }

    // Per-cell "Translate" button.
    $(document).on('click', '.lf-translate-one-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text(s.translating || 'Translating…');
        translateFse($btn.data('slug'), $btn.data('post-type'), $btn, null);
    });

    /**
     * Translate every existing template / part in a row that hasn't been
     * translated yet in this session.
     *
     * Shared by the row's own "Translate all" button and the global
     * cross-language "Recreate All Languages" orchestrator (fse-global-actions.js).
     * Does not touch the triggering button's disabled state — that's the
     * caller's responsibility.
     *
     * @param {jQuery}   $tplRow The `.lf-tpl-row` wrapper (Templates section, per language).
     * @param {Function} onDone  Callback(failedCount, totalCount) fired once every
     *                           pending button in the row has settled.
     */
    function translateAllInRow($tplRow, onDone) {
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var $pending = $tplRow.find('.lf-translate-one-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.allTranslated || '✓ All translated.');
            if (typeof onDone === 'function') { onDone(0, 0); }
            return;
        }

        $msg.removeClass('lf-ok lf-fail lf-warn').text('');

        var total  = $pending.length;
        var done   = 0;
        var failed = 0;
        var warned = false;

        $pending.each(function () {
            var $oneBtn = $(this);
            $oneBtn.prop('disabled', true).text(s.translating || 'Translating…');
            translateFse($oneBtn.data('slug'), $oneBtn.data('post-type'), $oneBtn, function (success, warning) {
                done++;
                if (!success) { failed++; }
                if (warning)  { warned = true; }
                if (done === total) {
                    if (failed === 0) {
                        var msg = s.allTranslated || '✓ All translated.';
                        if (warned) {
                            $msg.removeClass('lf-fail').addClass('lf-warn').text(
                                msg + ' ' + (s.translateWarning || 'Review carefully — links and slugs not updated.')
                            );
                        } else {
                            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok').text(msg);
                        }
                    } else {
                        $msg.removeClass('lf-ok lf-warn').addClass('lf-fail')
                            .text(s.translateFail || 'Some translations failed.');
                    }
                    if (typeof onDone === 'function') { onDone(failed, total); }
                }
            });
        });
    }

    // Per-row "Translate all" button — translates every existing template / part
    // in the row that hasn't been translated yet in this session.
    $(document).on('click', '.lf-translate-row-btn', function () {
        var $btn    = $(this);
        var $tplRow = $btn.closest('.lf-tpl-row');
        $btn.prop('disabled', true);
        translateAllInRow($tplRow, function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Expose for the global cross-language orchestrator ────────────────────
    window.lfFseActions = window.lfFseActions || {};
    window.lfFseActions.translateAll = translateAllInRow;

    // ── Navigation translation ────────────────────────────────────────────────

    /**
     * Translate a wp_navigation post into a language-specific copy.
     *
     * The server translates label values in navigation-link/submenu blocks,
     * rewrites internal URLs to carry the language prefix, then creates or
     * overwrites the {name}-{lang} navigation post.
     *
     * On success the cell gets a ✓ (if not already present) and the button
     * text switches to "Re-translate" so it can be run again after edits.
     *
     * @param {string}   navId   Source wp_navigation post ID.
     * @param {string}   lang    Two-char target language code.
     * @param {jQuery}   $btn    The per-cell Translate / Re-translate button.
     * @param {Function} onDone  Callback(success: boolean) fired when done.
     */
    function translateFseNav(navId, lang, $btn, onDone) {
        $.post(ajaxUrl, {
            action: 'linguaforge_translate_fse_navigation',
            nonce:  translateNavNonce,
            nav_id: navId,
            lang:   lang
        }, function (resp) {
            if (resp.success) {
                var $cell = $btn.closest('.lf-tpl-cell');
                if (!$cell.find('.lf-tpl-exists').length) {
                    $btn.before('<span class="lf-tpl-exists">✓</span> ');
                }
                $btn.prop('disabled', false).text(s.retranslate || 'Re-translate');
            } else {
                $btn.prop('disabled', false)
                    .text($btn.data('original-label') || s.translateNav || 'Translate');
            }
            if (typeof onDone === 'function') { onDone(resp.success); }
        }).fail(function () {
            $btn.prop('disabled', false)
                .text($btn.data('original-label') || s.translateNav || 'Translate');
            if (typeof onDone === 'function') { onDone(false); }
        });
    }

    // Per-cell "Translate" / "Re-translate" button for navigations.
    $(document).on('click', '.lf-translate-nav-btn', function () {
        var $btn = $(this);
        $btn.data('original-label', $btn.text().trim());
        $btn.prop('disabled', true).text(s.translatingNav || 'Translating…');
        translateFseNav($btn.data('nav-id'), $btn.data('lang'), $btn, null);
    });

}(jQuery));
