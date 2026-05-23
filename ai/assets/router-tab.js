/**
 * Lingua Forge — Settings › Router tab
 *
 * Handles:
 *   • "Load available languages" fetch and per-locale install button.
 *   • Language template scaffold: per-template "Create" buttons and
 *     per-language "Create missing" button.
 *
 * Data (`lfRouterTab`) is injected by SettingsPage::enqueue_settings_assets()
 * via wp_add_inline_script( …, 'before' ).
 */
(function ($) {
    'use strict';

    var L                  = window.lfRouterTab || {};
    var ajaxUrl            = L.ajaxUrl            || '';
    var fetchNonce         = L.fetchNonce         || '';
    var instNonce          = L.installNonce       || '';
    var scaffoldNonce      = L.scaffoldNonce      || '';
    var scaffoldPartNonce  = L.scaffoldPartNonce  || '';
    var translateNonce     = L.translateNonce     || '';
    var fixLinksNonce      = L.fixLinksNonce      || '';
    var fixPartsNonce      = L.fixPartsNonce      || '';
    var translateNavNonce  = L.translateNavNonce  || '';
    var fixNavRefsNonce    = L.fixNavRefsNonce    || '';
    var s                  = L.strings            || {};

    // ── Language install ──────────────────────────────────────────────────────

    var $loadBtn    = $('#lf-load-langs-btn');
    var $row        = $('#lf-lang-install-row');
    var $select     = $('#lf-lang-install-select');
    var $installBtn = $('#lf-install-lang-btn');
    var $result     = $('#lf-lang-install-result');

    if ($loadBtn.length) {

        $loadBtn.on('click', function () {
            $loadBtn.prop('disabled', true).text(s.loading || 'Loading…');
            $.post(ajaxUrl, {
                action: 'linguaforge_get_available_languages',
                nonce:  fetchNonce
            }, function (resp) {
                $loadBtn.hide();
                if (!resp.success || !resp.data.languages.length) {
                    $result.addClass('lf-fail').text('Could not load language list.');
                    $row.show();
                    return;
                }
                resp.data.languages.forEach(function (lang) {
                    var label = lang.english_name + (lang.native_name && lang.native_name !== lang.english_name ? ' — ' + lang.native_name : '') + ' (' + lang.locale + ')';
                    $select.append($('<option>', { value: lang.locale, text: label }));
                });
                $select.prop('disabled', false);
                $installBtn.prop('disabled', false);
                $row.show();
            }).fail(function () {
                $loadBtn.prop('disabled', false).text('Load available languages');
                $result.addClass('lf-fail').text('Network error. Please try again.');
                $row.show();
            });
        });

        $installBtn.on('click', function () {
            var locale = $select.val();
            if (!locale) return;
            $installBtn.prop('disabled', true).text(s.installing || 'Installing…');
            $result.removeClass('lf-ok lf-fail').text('');
            $.post(ajaxUrl, {
                action: 'linguaforge_install_language',
                nonce:  instNonce,
                locale: locale
            }, function (resp) {
                $installBtn.prop('disabled', false).text('Install');
                if (resp.success) {
                    $result.addClass('lf-ok').text(resp.data.message || s.installed);
                    // Remove the installed locale from the dropdown.
                    $select.find('option[value="' + resp.data.locale + '"]').remove();
                    $select.val('');
                } else {
                    $result.addClass('lf-fail').text((s.error || '✗') + ' ' + (resp.data || 'Unknown error'));
                }
            }).fail(function () {
                $installBtn.prop('disabled', false).text('Install');
                $result.addClass('lf-fail').text('Network error. Please try again.');
            });
        });

    }

    // ── Language template scaffold ────────────────────────────────────────────

    /**
     * Call the scaffold endpoint for one template.
     *
     * @param {string}   lang     Two-char language code.
     * @param {string}   base     Base template slug (e.g. 'page', 'search').
     * @param {jQuery}   $cell    The <td> containing the Create button.
     * @param {Function} onDone   Callback(success: boolean) fired when done.
     */
    function scaffoldOne(lang, base, $cell, onDone) {
        $.post(ajaxUrl, {
            action:    'linguaforge_scaffold_template',
            nonce:     scaffoldNonce,
            lang:      lang,
            base_slug: base
        }, function (resp) {
            if (resp.success) {
                // Replace the button with a ✓ indicator.
                $cell.html(
                    '<span class="lf-tpl-exists" title="' +
                    resp.data.slug + '.html">✓</span>'
                );
            } else {
                // Re-enable the button so the user can retry.
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
        var $tplRow  = $btn.closest('tr.lf-tpl-row');
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

    // ── Language template parts ───────────────────────────────────────────────

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
                $cell.html(
                    '<span class="lf-tpl-exists" title="' +
                    resp.data.slug + '.html">✓</span>'
                );
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

    // Per-row "Create missing" button for template parts — creates every pending
    // language variant of that part in parallel.
    $(document).on('click', '.lf-scaffold-all-parts-btn', function () {
        var $btn     = $(this);
        var $partRow = $btn.closest('tr.lf-tpl-row');
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
                // Replace the button with a muted "translated" marker.
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

    // Per-row "Translate all" button — translates every existing template / part
    // in the row that hasn't been translated yet in this session.
    $(document).on('click', '.lf-translate-row-btn', function () {
        var $btn     = $(this);
        var $tplRow  = $btn.closest('tr.lf-tpl-row');
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var $pending = $tplRow.find('.lf-translate-one-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.allTranslated || '✓ All translated.');
            return;
        }

        $btn.prop('disabled', true);
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
                    $btn.prop('disabled', false);
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
                }
            });
        });
    });

    // ── FSE link fixer ────────────────────────────────────────────────────────

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

    // Per-row "Fix all parts" button — fixes every existing template in the row
    // in parallel.
    $(document).on('click', '.lf-fix-parts-row-btn', function () {
        var $btn     = $(this);
        var $tplRow  = $btn.closest('tr.lf-tpl-row');
        var $msg     = $tplRow.find('.lf-scaffold-row-msg');
        var $pending = $tplRow.find('.lf-fix-parts-btn').not(':disabled');

        if (!$pending.length) {
            $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                .text(s.partsFixed || '✓ Parts fixed.');
            return;
        }

        $btn.prop('disabled', true);
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
                    $btn.prop('disabled', false);
                    if (failed === 0) {
                        $msg.removeClass('lf-fail lf-warn').addClass('lf-ok')
                            .text(s.partsFixed || '✓ Parts fixed.');
                    } else {
                        $msg.removeClass('lf-ok lf-warn').addClass('lf-fail')
                            .text(s.partsFail || 'Some part fixes failed.');
                    }
                }
            });
        });
    });

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
        var $tplRow  = $btn.closest('tr.lf-tpl-row');
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
