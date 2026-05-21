/**
 * Lingua Forge — Settings › Behavior tab — preset preview panel
 *
 * Shows each preset's built-in addendum text when the Global AI Preset
 * dropdown changes, so editors can see what the preset does and learn
 * the format for writing their own custom instructions.
 *
 * Data (`lfPresetData`) is injected by SettingsPage::enqueue_settings_assets()
 * via wp_add_inline_script( …, 'before' ).
 */
(function () {
    var select = document.getElementById('linguaforge_active_preset');
    var wrap   = document.getElementById('lf-preset-preview');
    if (!select || !wrap || typeof lfPresetData === 'undefined') return;

    var label = wrap.querySelector('.lf-preset-preview-label');
    var pre   = wrap.querySelector('.lf-preset-preview-text');

    function update() {
        var key      = select.value;
        var addendum = (lfPresetData.presets[key] || '').trim();
        if (addendum) {
            label.textContent = lfPresetData.strings.label;
            pre.textContent   = addendum;
        } else {
            label.textContent = '';
            pre.textContent   = lfPresetData.strings.noInstructions;
        }
        wrap.hidden = false;
    }

    select.addEventListener('change', update);
    update();
}());
