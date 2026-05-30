/**
 * Lingua Forge — Settings › Router tab
 *
 * Handles:
 *   • Language panel tab switching (sessionStorage-persisted).
 *   • "Load available languages" fetch and per-locale install button.
 *
 * FSE localisation actions (scaffold, translate, fix links/parts/nav-refs)
 * are handled by fse-scaffold.js, fse-translate.js, fse-link-fixer.js,
 * and fse-part-fixer.js — all enqueued with this script as a dependency.
 *
 * Data (`lfRouterTab`) is injected by SettingsPage::enqueue_settings_assets()
 * via wp_add_inline_script( …, 'before' ).
 */
(function ($) {
    'use strict';

    var L           = window.lfRouterTab || {};
    var ajaxUrl     = L.ajaxUrl          || '';
    var fetchNonce  = L.fetchNonce       || '';
    var instNonce   = L.installNonce     || '';
    var s           = L.strings          || {};

    // ── Language panel tabs ───────────────────────────────────────────────────

    function activateTab( lang ) {
        $('.lf-lang-tab').removeClass('is-active').attr('aria-selected', 'false');
        $('.lf-lang-panel').removeClass('is-active');
        var $tab   = $('.lf-lang-tab[data-tab="' + lang + '"]');
        var $panel = $('.lf-lang-panel[data-panel="' + lang + '"]');
        if ( $tab.length ) {
            $tab.addClass('is-active').attr('aria-selected', 'true');
            $panel.addClass('is-active');
        }
    }
    if ( $('.lf-lang-tabs').length ) {
        var savedTab = sessionStorage.getItem('lf_router_active_tab');
        if ( savedTab ) { activateTab( savedTab ); }
        $(document).on('click', '.lf-lang-tab', function () {
            var lang = $(this).data('tab');
            activateTab( lang );
            sessionStorage.setItem('lf_router_active_tab', lang);
        });
    }

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
                    // Remove the installed locale from the dropdown so it
                    // can't be submitted again in the same session.
                    $select.find('option[value="' + resp.data.locale + '"]').remove();
                    $select.val('');

                    // Show success message with a reload notice, then reload.
                    // The reload is required because:
                    //   • The Active Languages chip list is rendered server-side
                    //     and won't show the new language without a fresh page.
                    //   • The Language Templates / Template Parts tables also
                    //     need to be rebuilt to include the new language columns.
                    // Permalinks were already flushed server-side inside
                    // ajax_install_language() so no manual flush step is needed.
                    $result.addClass('lf-ok').text(
                        (resp.data.message || s.installed || 'Installed.') +
                        ' ' + (s.reloading || 'Reloading page…')
                    );
                    setTimeout(function () { window.location.reload(); }, 1500);
                } else {
                    $result.addClass('lf-fail').text((s.error || '✗') + ' ' + (resp.data || 'Unknown error'));
                }
            }).fail(function () {
                $installBtn.prop('disabled', false).text('Install');
                $result.addClass('lf-fail').text('Network error. Please try again.');
            });
        });

    }

}(jQuery));
