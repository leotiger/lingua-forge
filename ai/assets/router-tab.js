/**
 * Lingua Forge AI — Settings › Router tab
 *
 * Handles the "Load available languages" fetch and the per-locale install
 * button. Data (`lfRouterTab`) is injected by SettingsPage::enqueue_settings_assets()
 * via wp_add_inline_script( …, 'before' ).
 */
(function ($) {
    'use strict';

    var L          = window.lfRouterTab || {};
    var ajaxUrl    = L.ajaxUrl    || '';
    var fetchNonce = L.fetchNonce || '';
    var instNonce  = L.installNonce || '';
    var s          = L.strings   || {};

    var $loadBtn    = $('#lf-load-langs-btn');
    var $row        = $('#lf-lang-install-row');
    var $select     = $('#lf-lang-install-select');
    var $installBtn = $('#lf-install-lang-btn');
    var $result     = $('#lf-lang-install-result');

    if (!$loadBtn.length) return;

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

}(jQuery));
