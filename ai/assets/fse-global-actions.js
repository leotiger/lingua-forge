/**
 * Lingua Forge — global cross-language FSE actions
 *
 * "Recreate All Languages" runs, for every active secondary language in
 * sequence — never in parallel, since many languages at once risks
 * overwhelming the server and, for "Translate all", the AI provider —
 * these five steps in order: Re-create all templates, Re-create all parts,
 * Translate all, Fix all parts, Fix all links.
 *
 * This file owns only the language-by-language sequencing, progress
 * reporting, and cancellation. The actual work for each step is delegated
 * to the shared row-level functions exposed on window.lfFseActions by
 * fse-scaffold.js / fse-translate.js / fse-part-fixer.js / fse-link-fixer.js —
 * the same functions their own per-language "Re-create all" / "Translate
 * all" / "Fix all parts" / "Fix all links" buttons call.
 *
 * Every language's panel is already present in the DOM (RouterTab renders
 * all of them up front and hides inactive ones via CSS), so no tab-switching
 * or extra AJAX round-trip is needed to reach a language's markup.
 *
 * Depends on window.lfRouterTab (injected by SettingsPage before router-tab.js)
 * and window.lfFseActions (populated by the four action files above — all
 * enqueued before this file).
 */
(function ($) {
    'use strict';

    var L = window.lfRouterTab || {};
    var s = L.strings || {};

    /**
     * Replace {token} placeholders in a template string.
     *
     * @param {string} tpl  Template string with {token} placeholders.
     * @param {Object} vars Map of token name -> replacement value.
     * @return {string}
     */
    function fmt(tpl, vars) {
        return tpl.replace(/\{(\w+)\}/g, function (whole, token) {
            return Object.prototype.hasOwnProperty.call(vars, token) ? vars[token] : whole;
        });
    }

    // Ordered list of steps run for every language. Each `run` delegates to
    // the shared window.lfFseActions function for that action, scoped to the
    // given panel's Templates row ('div.lf-tpl-row' — tag-qualified because
    // Template Parts rows also carry the '.lf-tpl-row' class on a <tr>, a
    // pre-existing naming overlap) or Template Parts group ('.lf-parts-group').
    var STEPS = [
        {
            label: function () { return s.stepRecreateTemplates || 'Re-create all templates'; },
            run: function ($panel, cb) {
                var $row = $panel.find('div.lf-tpl-row');
                if (!$row.length || !window.lfFseActions || !window.lfFseActions.recreateAllTemplates) {
                    cb(0, 0); return;
                }
                window.lfFseActions.recreateAllTemplates($row, cb);
            }
        },
        {
            label: function () { return s.stepRecreateParts || 'Re-create all parts'; },
            run: function ($panel, cb) {
                var $group = $panel.find('.lf-parts-group');
                if (!$group.length || !window.lfFseActions || !window.lfFseActions.recreateAllParts) {
                    cb(0, 0); return;
                }
                window.lfFseActions.recreateAllParts($group, cb);
            }
        },
        {
            label: function () { return s.stepTranslateAll || 'Translate all'; },
            run: function ($panel, cb) {
                var $row = $panel.find('div.lf-tpl-row');
                if (!$row.length || !window.lfFseActions || !window.lfFseActions.translateAll) {
                    cb(0, 0); return;
                }
                window.lfFseActions.translateAll($row, cb);
            }
        },
        {
            label: function () { return s.stepFixAllParts || 'Fix all parts'; },
            run: function ($panel, cb) {
                var $row = $panel.find('div.lf-tpl-row');
                if (!$row.length || !window.lfFseActions || !window.lfFseActions.fixAllParts) {
                    cb(0, 0); return;
                }
                window.lfFseActions.fixAllParts($row, cb);
            }
        },
        {
            label: function () { return s.stepFixAllLinks || 'Fix all links'; },
            run: function ($panel, cb) {
                var $row = $panel.find('div.lf-tpl-row');
                if (!$row.length || !window.lfFseActions || !window.lfFseActions.fixAllLinks) {
                    cb(0, 0); return;
                }
                window.lfFseActions.fixAllLinks($row, cb);
            }
        }
    ];

    var running   = false;
    var cancelled = false;

    /**
     * Run every step in STEPS, in order, against one language's panel.
     *
     * @param {jQuery}   $panel     The `.lf-lang-panel` for this language.
     * @param {string}   langLabel  Upper-case language code, for progress text.
     * @param {number}   langIndex  1-based position of this language in the run.
     * @param {number}   langTotal  Total number of languages in the run.
     * @param {jQuery}   $progress  Live progress text element.
     * @param {Function} onPanelDone Callback(failedCount, issues[]) fired once
     *                               every step for this language has settled.
     */
    function runStepsForPanel($panel, langLabel, langIndex, langTotal, $progress, onPanelDone) {
        var stepIndex    = 0;
        var panelFailed  = 0;
        var panelIssues  = [];

        function nextStep() {
            if (stepIndex >= STEPS.length) {
                onPanelDone(panelFailed, panelIssues);
                return;
            }
            var step = STEPS[stepIndex];
            stepIndex++;

            var progressTpl = s.globalProgress || 'Processing {lang} ({index} of {total}) — {step}…';
            $progress.text(fmt(progressTpl, {
                lang: langLabel, index: langIndex, total: langTotal, step: step.label()
            }));

            step.run($panel, function (failed, total) {
                if (failed > 0) {
                    panelIssues.push(step.label() + ' (' + failed + '/' + total + ')');
                    panelFailed += failed;
                }
                nextStep();
            });
        }

        nextStep();
    }

    /**
     * Run the full global sequence across every language panel, one language
     * at a time. Checks the module-level `cancelled` flag between languages
     * (not mid-language — a language already in progress always finishes).
     *
     * @param {jQuery}   $panels    All `.lf-lang-panel` elements, in DOM order.
     * @param {jQuery}   $progress  Live progress text element.
     * @param {jQuery}   $summary   Final summary element.
     * @param {Function} onFinished Callback fired once the run ends (completed
     *                              or cancelled).
     */
    function runGlobal($panels, $progress, $summary, onFinished) {
        var index         = 0;
        var total          = $panels.length;
        var summaryLines   = [];
        var anyIssues      = false;

        function nextLanguage() {
            if (cancelled) {
                $summary.removeClass('lf-ok').addClass('lf-fail').html(
                    (s.globalCancelled || 'Cancelled.') + ' ' +
                    fmt(s.globalProcessedOf || '{done} of {total} languages processed.', { done: index, total: total }) +
                    (summaryLines.length ? '<br>' + summaryLines.join('<br>') : '')
                );
                onFinished();
                return;
            }
            if (index >= total) {
                if (anyIssues) {
                    $summary.removeClass('lf-ok').addClass('lf-fail').html(
                        (s.globalDoneWithIssues || '⚠ Done with issues.') + '<br>' + summaryLines.join('<br>')
                    );
                } else {
                    $summary.removeClass('lf-fail').addClass('lf-ok').text(
                        fmt(s.globalAllDone || '✓ All {total} languages processed.', { total: total })
                    );
                }
                onFinished();
                return;
            }

            var $panel = $($panels[index]);
            var lang   = String($panel.data('panel') || '').toUpperCase();
            index++;

            runStepsForPanel($panel, lang, index, total, $progress, function (failedCount, issues) {
                if (failedCount > 0) {
                    anyIssues = true;
                    summaryLines.push('<strong>' + lang + '</strong>: ' + issues.join(', '));
                }
                nextLanguage();
            });
        }

        nextLanguage();
    }

    $(document).on('click', '#lf-global-recreate-btn', function () {
        if (running) {
            return;
        }

        var $panels = $('.lf-lang-panel');
        if (!$panels.length) {
            return;
        }

        var confirmTpl = s.globalConfirm ||
            'This runs Re-create all templates, Re-create all parts, Translate all, Fix all parts, and Fix all links for every one of your {total} active languages, one language at a time. It overwrites existing templates and parts with fresh copies from the active theme (discarding Site Editor customisations) and re-translates content — none of this can be undone. Translate all makes real AI API calls for every template and part, which may take a while and may incur cost depending on your provider. Continue?';
        if (!window.confirm(fmt(confirmTpl, { total: $panels.length }))) {
            return;
        }

        running   = true;
        cancelled = false;

        var $startBtn  = $(this);
        var $cancelBtn = $('#lf-global-cancel-btn');
        var $progress  = $('#lf-global-progress');
        var $summary   = $('#lf-global-summary');

        $startBtn.prop('disabled', true);
        $cancelBtn.show().prop('disabled', false);
        $progress.show().text(s.globalStarting || 'Starting…');
        $summary.hide().removeClass('lf-ok lf-fail').empty();

        runGlobal($panels, $progress, $summary, function () {
            running = false;
            $startBtn.prop('disabled', false);
            $cancelBtn.hide();
            $progress.hide();
            $summary.show();
        });
    });

    $(document).on('click', '#lf-global-cancel-btn', function () {
        cancelled = true;
        $(this).prop('disabled', true);
    });

}(jQuery));
