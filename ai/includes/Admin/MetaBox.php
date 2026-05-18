<?php

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;

defined('ABSPATH') || exit;

class MetaBox {

    public static function init(): void {

        add_action(
            'add_meta_boxes',
            [self::class, 'register']
        );

        add_action(
            'admin_enqueue_scripts',
            [self::class, 'enqueue']
        );

        // Editor top-toolbar button — loaded via two independent hooks so the
        // script is guaranteed to run in BOTH the post/page editor and the
        // FSE site/template editor regardless of which hook fires first.
        //
        // enqueue_block_editor_assets  → fires when the block editor boots
        //                                (post editor + usually site editor).
        // admin_enqueue_scripts        → fires for every admin page; we limit
        //                                it to site-editor.php as a hard
        //                                guarantee for the FSE context.
        add_action(
            'enqueue_block_editor_assets',
            [self::class, 'enqueue_editor_plugin']
        );
        add_action(
            'admin_enqueue_scripts',
            [self::class, 'enqueue_editor_plugin_for_site_editor']
        );

        // Block toolbar Translate / Revise button — loaded alongside the editor
        // plugin so it is available in both the post editor and the FSE editor.
        add_action(
            'enqueue_block_editor_assets',
            [self::class, 'enqueue_block_action']
        );
        add_action(
            'admin_enqueue_scripts',
            [self::class, 'enqueue_block_action_for_site_editor']
        );

        add_action(
            'save_post',
            [self::class, 'save_preset']
        );
    }

    public static function enqueue(string $hook): void {

        // Only load on post create / edit screens.
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_enqueue_script('wp-api');

        wp_enqueue_script(
            'lingua-forge-admin',
            LINGUAFORGE_AI_URL . '/assets/admin.js',
            ['wp-api', 'wp-i18n'],
            LINGUAFORGE_VERSION,
            true
        );

        wp_set_script_translations( 'lingua-forge-admin', 'lingua-forge', LINGUAFORGE_PATH . 'languages' );

        wp_localize_script(
            'lingua-forge-admin',
            'LinguaForgeAI',
            [
                'restUrl' => rest_url('lingua-forge/v1'),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]
        );

        wp_enqueue_style(
            'lingua-forge-admin',
            LINGUAFORGE_AI_URL . '/assets/admin.css',
            [],
            LINGUAFORGE_VERSION
        );
    }

    /**
     * Enqueue the Gutenberg editor plugin (Quick Translate modal).
     *
     * Uses enqueue_block_editor_assets so it loads in both:
     *   - the classic post/page block editor  (post.php, post-new.php)
     *   - the full site / template editor     (site-editor.php)
     *
     * The wp-edit-post and wp-edit-site handles are listed as dependencies
     * so WordPress loads whichever is relevant for the current editor
     * context before our script runs.
     */
    public static function enqueue_editor_plugin(): void {

        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'lingua-forge-editor',
            LINGUAFORGE_AI_URL . '/assets/editor-translate.css',
            ['wp-components'],
            LINGUAFORGE_VERSION
        );

        $editor_deps = ['wp-i18n'];

        wp_enqueue_script(
            'lingua-forge-editor',
            LINGUAFORGE_AI_URL . '/assets/editor-translate.js',
            $editor_deps,
            LINGUAFORGE_VERSION,
            true
        );

        wp_set_script_translations( 'lingua-forge-editor', 'lingua-forge', LINGUAFORGE_PATH . 'languages' );

        wp_localize_script(
            'lingua-forge-editor',
            'LinguaForgeAIEditor',
            [
                'restUrl'      => rest_url('lingua-forge/v1'),
                'nonce'        => wp_create_nonce('wp_rest'),
                'languages'    => Translation::get_languages(),
                'postLanguage' => Translation::detect_post_language(),
            ]
        );
    }

    /**
     * Hard-guarantee that the editor translate script loads on the site editor
     * page via admin_enqueue_scripts, which always fires for every admin page.
     *
     * Covers WP installations where enqueue_block_editor_assets does not fire
     * reliably during site-editor.php initialisation.  wp_script_is() prevents
     * double-enqueuing when both hooks fire on the same page load.
     */
    public static function enqueue_editor_plugin_for_site_editor( string $hook ): void {

        // site-editor.php is the top-level FSE page slug in WP 6.x core.
        // The Gutenberg plugin may use a different slug — add variants here if needed.
        if ( $hook !== 'site-editor.php' ) {
            return;
        }

        // Avoid double-enqueue if enqueue_block_editor_assets already ran.
        if ( wp_script_is( 'lingua-forge-editor', 'enqueued' ) ) {
            return;
        }

        self::enqueue_editor_plugin();
    }

    /**
     * Enqueue the block-toolbar Translate / Revise button and its popover.
     * The Footnotes tab within the popover is handled by block-action.js itself.
     *
     * Depends on the WordPress block-editor packages so that wp.hooks,
     * wp.element, wp.components, and wp.blockEditor are available when
     * our script runs.
     */
    public static function enqueue_block_action(): void {

        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'lingua-forge-block-action',
            LINGUAFORGE_AI_URL . '/assets/block-action.css',
            ['wp-components'],
            LINGUAFORGE_VERSION
        );

        wp_enqueue_script(
            'lingua-forge-block-action',
            LINGUAFORGE_AI_URL . '/assets/block-action.js',
            ['wp-hooks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n'],
            LINGUAFORGE_VERSION,
            true
        );

        wp_set_script_translations( 'lingua-forge-block-action', 'lingua-forge', LINGUAFORGE_PATH . 'languages' );

        wp_localize_script(
            'lingua-forge-block-action',
            'LinguaForgeAIBlockAction',
            [
                'restUrl'      => rest_url('lingua-forge/v1'),
                'nonce'        => wp_create_nonce('wp_rest'),
                'languages'    => Translation::get_languages(),
                'postLanguage' => Translation::detect_post_language(),
            ]
        );

    }

    /**
     * Hard-guarantee the block-action script loads on site-editor.php via
     * admin_enqueue_scripts when enqueue_block_editor_assets may not fire.
     */
    public static function enqueue_block_action_for_site_editor(string $hook): void {

        if ($hook !== 'site-editor.php') {
            return;
        }

        if (wp_script_is('lingua-forge-block-action', 'enqueued')) {
            return;
        }

        self::enqueue_block_action();
    }

    /**
     * Save the per-page preset override from the meta box select.
     */
    public static function save_preset(int $post_id): void {

        if (!isset($_POST['_linguaforge_preset_nonce'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (!wp_verify_nonce(wp_unslash($_POST['_linguaforge_preset_nonce']), 'linguaforge_preset_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id))    return;

        $value = sanitize_key($_POST['_linguaforge_preset'] ?? '');
        $valid = array_keys(Config::presets());

        if ($value === '' || $value === 'global') {
            delete_post_meta($post_id, '_linguaforge_preset');
        } elseif (in_array($value, $valid, true)) {
            update_post_meta($post_id, '_linguaforge_preset', $value);
        }
    }

    public static function register(): void {

        add_meta_box(
            'lingua-forge-ai',
            'Lingua Forge AI',
            [self::class, 'render'],
            ['post', 'page'],
            'normal',
            'high'
        );
    }

    public static function render(
        \WP_Post $post
    ): void {

        $features = Registry::all();

        $presets       = Config::presets();
        $global_preset = Config::active_preset();
        $page_preset   = (string) get_post_meta($post->ID, '_linguaforge_preset', true);

        ?>
        <div class="lingua-forge-panel">

            <div class="lingua-forge-feature-group" style="border-bottom:1px solid #dcdcde;padding-bottom:12px;margin-bottom:4px;">
                <?php wp_nonce_field('linguaforge_preset_save', '_linguaforge_preset_nonce'); ?>
                <label class="lingua-forge-label" for="lf-page-preset">
                    <?php esc_html_e('AI Behaviour Preset', 'lingua-forge'); ?>
                </label>
                <select id="lf-page-preset" name="_linguaforge_preset" class="lingua-forge-select" style="width:100%;margin-top:4px;">
                    <option value="global" <?php selected($page_preset, ''); ?>>
                        <?php
                        printf(
                            /* translators: %s: name of the globally configured preset */
                            esc_html__('Global default (%s)', 'lingua-forge'),
                            esc_html($presets[$global_preset]['label'] ?? $global_preset)
                        );
                        ?>
                    </option>
                    <?php foreach ($presets as $key => $meta): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($page_preset, $key); ?>>
                            <?php echo esc_html($meta['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description" style="font-size:11px;color:#646970;margin-top:4px;">
                    <?php esc_html_e('Applies to Translation and Content Generation on this page only. Other features use the global preset.', 'lingua-forge'); ?>
                </p>
            </div>

            <?php foreach ($features as $feature): ?>

                <div class="lingua-forge-feature-group">

                    <?php
                    $ui_fields = $feature->get_ui_fields();
                    $defaults  = $feature->get_field_defaults($post->ID);
                    ?>

                    <?php if (!empty($ui_fields)): ?>
                        <div class="lingua-forge-feature-fields">
                            <?php foreach ($ui_fields as $field):

                                // Conditional visibility: wrap in a div with data-condition-* attrs
                                // so JS can show/hide based on another field's current value.
                                $has_condition   = !empty($field['condition']) && is_array($field['condition']);
                                $condition_field = $has_condition ? (string) array_key_first($field['condition']) : '';
                                $condition_value = $has_condition ? (string) $field['condition'][$condition_field] : '';
                            ?>

                                <div
                                    class="lingua-forge-field-wrapper"
                                    <?php if ($has_condition): ?>
                                        data-condition-field="<?php echo esc_attr($condition_field); ?>"
                                        data-condition-value="<?php echo esc_attr($condition_value); ?>"
                                    <?php endif; ?>
                                >

                                <?php if ($field['type'] === 'textarea'): ?>
                                    <label class="lingua-forge-label">
                                        <?php echo esc_html($field['label']); ?>
                                        <textarea
                                            class="lingua-forge-input-textarea"
                                            data-field="<?php echo esc_attr($field['name']); ?>"
                                            data-feature-ref="<?php echo esc_attr($feature->get_key()); ?>"
                                            rows="<?php echo esc_attr($field['rows'] ?? 4); ?>"
                                            placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>"
                                        ><?php echo esc_textarea($defaults[$field['name']] ?? ''); ?></textarea>
                                    </label>
                                <?php endif; ?>

                                <?php if ($field['type'] === 'select'): ?>
                                    <label class="lingua-forge-label">
                                        <?php echo esc_html($field['label']); ?>
                                        <select
                                            class="lingua-forge-select"
                                            data-field="<?php echo esc_attr($field['name']); ?>"
                                            data-feature-ref="<?php echo esc_attr($feature->get_key()); ?>"
                                        >
                                            <?php
                                            $selected_val = $defaults[$field['name']] ?? '';
                                            foreach ($field['options'] as $val => $label):
                                            ?>
                                                <option
                                                    value="<?php echo esc_attr($val); ?>"
                                                    <?php selected($selected_val, $val); ?>
                                                >
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>

                                <?php if ($field['type'] === 'checkbox'): ?>
                                    <label class="lingua-forge-label lingua-forge-label--inline">
                                        <input
                                            type="checkbox"
                                            class="lingua-forge-checkbox"
                                            data-field="<?php echo esc_attr($field['name']); ?>"
                                            data-feature-ref="<?php echo esc_attr($feature->get_key()); ?>"
                                            value="1"
                                            <?php checked(!empty($defaults[$field['name']])); ?>
                                        />
                                        <?php echo esc_html($field['label']); ?>
                                    </label>
                                <?php endif; ?>

                                </div><!-- .lingua-forge-field-wrapper -->

                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <button
                        type="button"
                        class="button button-secondary lingua-forge-action"
                        data-feature="<?php echo esc_attr($feature->get_key()); ?>"
                        data-post-id="<?php echo esc_attr($post->ID); ?>"
                    >
                        <?php echo esc_html($feature->get_label()); ?>
                    </button>

                    <div class="lingua-forge-result"></div>

                </div>

            <?php endforeach; ?>

        </div>
        <?php
    }
}
