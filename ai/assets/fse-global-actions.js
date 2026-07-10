/**
 * Lingua Forge — global cross-language FSE actions
 *
 * "Recreate All Languages" runs, for every active secondary language in
 * sequence — never in parallel, since many languages at once risks
 * overwhelming the server and, for "Translate all", the AI provider —
 * these five steps in order: Re-create all templates, Re-create all parts,
 * Translate all, Fix all parts, Fix all links.
 *
 * "Recreate All Languages (Template Parts Only)" runs the same
 * language-by-language workflow but with only the Re-create all parts step —
 * for touching up headers/footers/etc. across every language without also
 * re-translating or rebuilding page-level templates.
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

    // Individual step definitions. Each `run` delegates to the shared
    // window.lfFseActions function for that action, scoped to the given
    // panel's Templates row ('div.lf-tpl-row' — tag-qualified because
    // Template Parts rows also carry the '.lf-tpl-row' class on a <tr>, a
    // pre-existing naming overlap) or Template Parts group ('.lf-parts-group').
    var STEP_RECREATE_TEMPLATES = {
        label: function () { return s.stepRecreateTemplates || 'Re-create all templates'; },
        run: function ($panel, cb) {
            var $row = $panel.find('div.lf-tpl-row');
            if (!$row.length || !window.lfFseActions || !window.lfFseActions.recreateAllTemplates) {
                cb(0, 0); return;
            }
            window.lfFseActions.recreateAllTemplates($row, cb);
        }
    };
    var STEP_RECREATE_PARTS = {
        label: function () { return s.stepRecreateParts || 'Re-create all parts'; },
        run: function ($panel, cb) {
            var $group = $panel.find('.lf-parts-group');
            if (!$group.length || !window.lfFseActions || !window.lfFseActions.recreateAllParts) {
                cb(0, 0); return;
            }
            window.lfFseActions.recreateAllParts($group, cb);
        }
    };
    var STEP_TRANSLATE_ALL = {
        label: function () { return s.stepTranslateAll || 'Translate all'; },
        run: function ($panel, cb) {
            var $row = $panel.find('div.lf-tpl-row');
            if (!$row.length || !window.lfFseActions || !window.lfFseActions.translateAll) {
                cb(0, 0); return;
            }
            window.lfFseActions.translateAll($row, cb);
        }
    };
    var STEP_FIX_ALL_PARTS = {
        label: function () { return s.stepFixAllParts || 'Fix all parts'; },
        run: function ($panel, cb) {
            var $row = $panel.find('div.lf-tpl-row');
            if (!$row.length || !window.lfFseActions || !window.lfFseActions.fixAllParts) {
                cb(0, 0); return;
            }
            window.lfFseActions.fixAllParts($row, cb);
        }
    };
    var STEP_FIX_ALL_LINKS = {
        label: function () { return s.stepFixAllLinks || 'Fix all links'; },
        run: function ($panel, cb) {
            var $row = $panel.find('div.lf-tpl-row');
            if (!$row.length || !window.lfFseActions || !window.lfFseActions.fixAllLinks) {
                cb(0, 0); return;
            }
            window.lfFseActions.fixAllLinks($row, cb);
        }
    };

    // The three runnable sequences. A run is always one of these — never a
    // one-off custom mix — so there is exactly one shared progress/summary
    // UI and one `running` flag guarding all trigger buttons at once.
    // TEMPLATES_ONLY_STEPS and PARTS_ONLY_STEPS are a clean partition of
    // ALL_STEPS: every step in ALL_STEPS appears in exactly one of the two.
    var ALL_STEPS            = [ STEP_RECREATE_TEMPLATES, STEP_RECREATE_PARTS, STEP_TRANSLATE_ALL, STEP_FIX_ALL_PARTS, STEP_FIX_ALL_LINKS ];
    var TEMPLATES_ONLY_STEPS = [ STEP_RECREATE_TEMPLATES, STEP_TRANSLATE_ALL, STEP_FIX_ALL_PARTS, STEP_FIX_ALL_LINKS ];
    var PARTS_ONLY_STEPS     = [ STEP_RECREATE_PARTS ];

    var running   = false;
    var cancelled = false;

    /**
     * Run every step in `steps`, in order, against one language's panel.
     *
     * @param {Array}    steps      Ordered list of step definitions to run.
     * @param {jQuery}   $panel     The `.lf-lang-panel` for this language.
     * @param {string}   langLabel  Upper-case language code, for progress text.
     * @param {number}   langIndex  1-based position of this language in the run.
     * @param {number}   langTotal  Total number of languages in the run.
     * @param {jQuery}   $progress  Live progress text element.
     * @param {Function} onPanelDone Callback(failedCount, issues[]) fired once
     *                               every step for this language has settled.
     */
    function runStepsForPanel(steps, $panel, langLabel, langIndex, langTotal, $progress, onPanelDone) {
        var stepIndex   = 0;
        var panelFailed = 0;
        var panelIssues = [];

        function nextStep() {
            if (stepIndex >= steps.length) {
                onPanelDone(panelFailed, panelIssues);
                return;
            }
            var step = steps[stepIndex];
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
     * Run `steps` across every language panel, one language at a time.
     * Checks the module-level `cancelled` flag between languages (not
     * mid-language — a language already in progress always finishes).
     *
     * @param {Array}    steps      Ordered list of step definitions to run.
     * @param {jQuery}   $panels    All `.lf-lang-panel` elements, in DOM order.
     * @param {jQuery}   $progress  Live progress text element.
     * @param {jQuery}   $summary   Final summary element.
     * @param {Function} onFinished Callback fired once the run ends (completed
     *                              or cancelled).
     */
    function runGlobal(steps, $panels, $progress, $summary, onFinished) {
        var index       = 0;
        var total       = $panels.length;
        var summaryLines = [];
        var anyIssues    = false;

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

            runStepsForPanel(steps, $panel, lang, index, total, $progress, function (failedCount, issues) {
                if (failedCount > 0) {
                    anyIssues = true;
                    summaryLines.push('<strong>' + lang + '</strong>: ' + issues.join(', '));
                }
                nextLanguage();
            });
        }

        nextLanguage();
    }

    /**
     * Confirm, then kick off a global run for the given step sequence.
     * Both trigger buttons (`.lf-global-run-btn`) are disabled for the
     * duration of any run so the two variants can never overlap.
     *
     * @param {Array}  steps      Ordered list of step definitions to run.
     * @param {string} confirmTpl Confirmation message template (with {total}).
     */
    function startGlobalRun(steps, confirmTpl) {
        if (running) {
            return;
        }

        var $panels = $('.lf-lang-panel');
        if (!$panels.length) {
            return;
        }

        if (!window.confirm(fmt(confirmTpl, { total: $panels.length }))) {
            return;
        }

        running   = true;
        cancelled = false;

        var $runBtns   = $('.lf-global-run-btn');
        var $cancelBtn = $('#lf-global-cancel-btn');
        var $progress  = $('#lf-global-progress');
        var $summary   = $('#lf-global-summary');

        $runBtns.prop('disabled', true);
        $cancelBtn.show().prop('disabled', false);
        $progress.show().text(s.globalStarting || 'Starting…');
        $summary.hide().removeClass('lf-ok lf-fail').empty();

        runGlobal(steps, $panels, $progress, $summary, function () {
            running = false;
            $runBtns.prop('disabled', false);
            $cancelBtn.hide();
            $progress.hide();
            $summary.show();
        });
    }

    $(document).on('click', '#lf-global-recreate-btn', function () {
        var confirmTpl = s.globalConfirm ||
            'This runs Re-create all templates, Re-create all parts, Translate all, Fix all parts, and Fix all links for every one of your {total} active languages, one language at a time. It overwrites existing templates and parts with fresh copies from the active theme (discarding Site Editor customisations) and re-translates content — none of this can be undone. Translate all makes real AI API calls for every template and part, which may take a while and may incur cost depending on your provider. Continue?';
        startGlobalRun(ALL_STEPS, confirmTpl);
    });

    $(document).on('click', '#lf-global-recreate-templates-btn', function () {
        var confirmTpl = s.globalTemplatesConfirm ||
            'This runs Re-create all templates, Translate all, Fix all parts, and Fix all links for every one of your {total} active languages, one language at a time — template parts (headers, footers, etc.) are left untouched. It overwrites existing templates with fresh copies from the active theme (discarding Site Editor customisations) and re-translates content — none of this can be undone. Translate all makes real AI API calls for every template, which may take a while and may incur cost depending on your provider. Continue?';
        startGlobalRun(TEMPLATES_ONLY_STEPS, confirmTpl);
    });

    $(document).on('click', '#lf-global-recreate-parts-btn', function () {
        var confirmTpl = s.globalPartsConfirm ||
            'This runs Re-create all parts (headers, footers, and every other template part) for every one of your {total} active languages, one language at a time. It overwrites existing template parts with fresh copies from the active theme, discarding any Site Editor customisations made to them. This cannot be undone. Continue?';
        startGlobalRun(PARTS_ONLY_STEPS, confirmTpl);
    });

    $(document).on('click', '#lf-global-cancel-btn', function () {
        cancelled = true;
        $(this).prop('disabled', true);
    });

}(jQuery));
