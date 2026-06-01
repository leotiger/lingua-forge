<?php

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;

defined('ABSPATH') || exit;

class MetaBox {

    // ── Language helper ───────────────────────────────────────────────────────

    /**
     * Returns the AI-supported language map filtered to languages active on
     * this WordPress instance.
     *
     * linguaforge_languages() (defined in language-router/language-router.php,
     * always loaded as part of the plugin) returns the 2-letter language codes
     * that the Language Router recognises as valid for this install — derived
     * from installed WP locale files, the site locale, plugin .mo files, and
     * the configured source language.
     *
     * The intersection ensures only languages that are BOTH understood by the
     * AI and actually managed by this instance appear in the dropdown.
     *
     * @return array<string,string>  e.g. ['en' => 'English', 'es' => 'Spanish']
     */
    private static function instance_languages(): array {
        return array_intersect_key(
            Translation::get_languages(),
            array_flip( linguaforge_languages() )
        );
    }

    /**
     * Filter callback — auto-injects any language active on this WordPress
     * instance (from linguaforge_languages()) that is absent from the built-in
     * AI language map.
     *
     * Registered at priority 5 so user-supplied filters (priority 10) can still
     * override the derived name if needed.
     *
     * English language names are resolved via PHP's Locale class (intl
     * extension). When intl is unavailable the locale code is uppercased as a
     * last-resort fallback (e.g. "EU"). The result is good enough for AI prompt
     * usage in all cases where intl is present (true on virtually every
     * modern PHP host).
     *
     * @param  array<string,string> $languages  Current language map.
     * @return array<string,string>
     */
    public static function inject_instance_languages( array $languages ): array {

        foreach ( linguaforge_languages() as $code ) {

            if ( isset( $languages[ $code ] ) ) {
                continue;
            }

            if ( class_exists( 'Locale' ) ) {
                $name = \Locale::getDisplayLanguage( $code, 'en' );
                // Locale::getDisplayLanguage() returns the code itself when it
                // cannot resolve the name — treat that as a failure.
                if ( $name === '' || $name === $code ) {
                    $name = strtoupper( $code );
                }
            } else {
                $name = strtoupper( $code );
            }

            $languages[ $code ] = $name;
        }

        return $languages;
    }

    // ─────────────────────────────────────────────────────────────────────────

    public static function init(): void {

        // Auto-inject any instance-configured language that is absent from the
        // built-in AI language map. Runs at priority 5 (before user filters at 10)
        // so site-specific overrides still win. English names are derived from
        // PHP's Locale class (intl extension); falls back to the uppercased code
        // when intl is unavailable.
        add_filter(
            'linguaforge_translation_languages',
            [self::class, 'inject_instance_languages'],
            5
        );

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

        // Block toolbar Translate / Revise button.
        // enqueue_block_editor_assets fires at the right moment in both the
        // post editor and the FSE site editor (WP 5.8+), so a single hook is
        // sufficient — no admin_enqueue_scripts fallback needed here.
        add_action(
            'enqueue_block_editor_assets',
            [self::class, 'enqueue_block_action']
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
                'languages'    => self::instance_languages(),
                'postLanguage' => Translation::detect_post_language(),
                'tones'        => [
                    'informative'    => __( 'Informative',    'lingua-forge' ),
                    'persuasive'     => __( 'Persuasive',     'lingua-forge' ),
                    'storytelling'   => __( 'Storytelling',   'lingua-forge' ),
                    'technical'      => __( 'Technical',      'lingua-forge' ),
                    'conversational' => __( 'Conversational', 'lingua-forge' ),
                ],
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
            ['wp-rich-text', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n'],
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
                'languages'    => self::instance_languages(),
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

        $internal = [
            'attachment', 'revision', 'nav_menu_item',
            'wp_template', 'wp_template_part', 'wp_navigation',
            'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
            'wp_navigation_fallback',
        ];
        $types = array_values( array_diff(
            array_keys( get_post_types( [ 'public' => true ] ) ),
            $internal
        ) );
        /**
         * Filters the post types that receive the Lingua Forge AI metabox.
         *
         * @param string[] $types Post type slugs (includes 'post' and 'page' by default).
         */
        $types = (array) apply_filters( 'linguaforge_ai_metabox_post_types', $types );

        add_meta_box(
            'lingua-forge-ai',
            'Lingua Forge',
            [self::class, 'render'],
            $types,
            'normal',
            'high'
        );
    }

    // ── Field renderer (shared by inline + overlay paths) ─────────────────────

    /**
     * Render the UI fields (textareas, selects, checkboxes) for one feature.
     *
     * Extracted to avoid duplication between the inline rendering path and the
     * overlay rendering path introduced in 1.7.3.  Outputs HTML directly.
     *
     * @param array<int,array<string,mixed>> $ui_fields   Field defs from Feature::get_ui_fields().
     * @param string                         $feature_key Feature slug for data-feature-ref attrs.
     * @param array<string,mixed>            $defaults    Default / persisted field values.
     */
    private static function render_feature_fields(
        array  $ui_fields,
        string $feature_key,
        array  $defaults
    ): void {

        foreach ( $ui_fields as $field ) :

            $has_condition   = ! empty( $field['condition'] ) && is_array( $field['condition'] );
            $condition_field = $has_condition ? (string) array_key_first( $field['condition'] ) : '';
            $condition_value = $has_condition ? (string) $field['condition'][ $condition_field ] : '';

            ?>
            <div
                class="lingua-forge-field-wrapper"
                <?php if ( $has_condition ) : ?>
                    data-condition-field="<?php echo esc_attr( $condition_field ); ?>"
                    data-condition-value="<?php echo esc_attr( $condition_value ); ?>"
                <?php endif; ?>
            >

            <?php if ( $field['type'] === 'textarea' ) : ?>
                <label class="lingua-forge-label">
                    <?php echo esc_html( $field['label'] ); ?>
                    <textarea
                        class="lingua-forge-input-textarea"
                        data-field="<?php echo esc_attr( $field['name'] ); ?>"
                        data-feature-ref="<?php echo esc_attr( $feature_key ); ?>"
                        rows="<?php echo esc_attr( $field['rows'] ?? 4 ); ?>"
                        placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                    ><?php echo esc_textarea( $defaults[ $field['name'] ] ?? '' ); ?></textarea>
                </label>
            <?php endif; ?>

            <?php if ( $field['type'] === 'select' ) : ?>
                <label class="lingua-forge-label">
                    <?php echo esc_html( $field['label'] ); ?>
                    <select
                        class="lingua-forge-select"
                        data-field="<?php echo esc_attr( $field['name'] ); ?>"
                        data-feature-ref="<?php echo esc_attr( $feature_key ); ?>"
                    >
                        <?php
                        $selected_val = $defaults[ $field['name'] ] ?? '';
                        foreach ( $field['options'] as $val => $label ) :
                        ?>
                            <option
                                value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $selected_val, $val ); ?>
                            >
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if ( $field['type'] === 'checkbox' ) : ?>
                <label class="lingua-forge-label lingua-forge-label--inline">
                    <input
                        type="checkbox"
                        class="lingua-forge-checkbox"
                        data-field="<?php echo esc_attr( $field['name'] ); ?>"
                        data-feature-ref="<?php echo esc_attr( $feature_key ); ?>"
                        value="1"
                        <?php checked( ! empty( $defaults[ $field['name'] ] ) ); ?>
                    />
                    <?php echo esc_html( $field['label'] ); ?>
                </label>
            <?php endif; ?>

            </div><!-- .lingua-forge-field-wrapper -->

        <?php
        endforeach;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render(
        \WP_Post $post
    ): void {

        $features = Registry::all();

        $presets       = Config::presets();
        $global_preset = Config::active_preset();
        $page_preset   = (string) get_post_meta( $post->ID, '_linguaforge_preset', true );

        // True when the editor has saved a per-preset addendum override
        // for the currently-active global preset (Settings → Behavior).
        $has_custom_addendum = $global_preset !== 'standard'
            && Config::preset_addendum( $global_preset ) !== Config::default_preset_addendum( $global_preset );

        // Features that open in a focused overlay instead of rendering inline.
        // Everything else (meta-description, etc.) stays in the compact panel.
        $overlay_keys = [ 'translation', 'content-generator' ];

        // Compact trigger-button labels shown in the main panel.
        $trigger_labels = [
            'translation'       => __( 'Translate page…',   'lingua-forge' ),
            'content-generator' => __( 'Generate content…', 'lingua-forge' ),
        ];

        $inline_features  = [];
        $overlay_features = [];

        foreach ( $features as $feature ) {
            if ( in_array( $feature->get_key(), $overlay_keys, true ) ) {
                $overlay_features[] = $feature;
            } else {
                $inline_features[] = $feature;
            }
        }

        ?>
        <div class="lingua-forge-panel">

            <div class="lingua-forge-feature-group lf-preset-row">
                <?php wp_nonce_field( 'linguaforge_preset_save', '_linguaforge_preset_nonce' ); ?>
                <label class="lingua-forge-label" for="lf-page-preset">
                    <?php esc_html_e( 'AI Behaviour Preset', 'lingua-forge' ); ?>
                </label>
                <select id="lf-page-preset" name="_linguaforge_preset" class="lingua-forge-select" style="width:100%;margin-top:4px;">
                    <option value="global" <?php selected( $page_preset, '' ); ?>>
                        <?php
                        if ( $has_custom_addendum ) {
                            $linguaforge_preset_label = esc_html__( 'Custom', 'lingua-forge' );
                        } else {
                            $linguaforge_preset_label = esc_html( $presets[ $global_preset ]['label'] ?? $global_preset );
                        }
                        // translators: %s: name of the globally configured AI preset, or "Custom" when a per-preset addendum override is active
                        echo sprintf( esc_html__( 'Global default (%s)', 'lingua-forge' ), $linguaforge_preset_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $linguaforge_preset_label is already esc_html'd above (lines 508/510).
                        ?>
                    </option>
                    <?php foreach ( $presets as $key => $meta ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $page_preset, $key ); ?>>
                            <?php echo esc_html( $meta['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description" style="font-size:11px;color:#646970;margin-top:4px;">
                    <?php
                    if ( $has_custom_addendum ) {
                        esc_html_e(
                            'Overrides the AI preset for Translation and Content Generation on this page. Site-wide custom prompt instructions (Settings → Behavior) are always appended to every request regardless of this selection.',
                            'lingua-forge'
                        );
                    } else {
                        esc_html_e(
                            'Overrides the AI preset for Translation and Content Generation on this page. All other features always use the global preset.',
                            'lingua-forge'
                        );
                    }
                    ?>
                </p>
            </div>

            <?php foreach ( $inline_features as $feature ) : ?>

                <div class="lingua-forge-feature-group">

                    <?php
                    $ui_fields = $feature->get_ui_fields();
                    $defaults  = $feature->get_field_defaults( $post->ID );
                    ?>

                    <?php if ( ! empty( $ui_fields ) ) : ?>
                        <div class="lingua-forge-feature-fields">
                            <?php self::render_feature_fields( $ui_fields, $feature->get_key(), $defaults ); ?>
                        </div>
                    <?php endif; ?>

                    <button
                        type="button"
                        class="button button-secondary lingua-forge-action"
                        data-feature="<?php echo esc_attr( $feature->get_key() ); ?>"
                        data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
                    >
                        <?php echo esc_html( $feature->get_label() ); ?>
                    </button>

                    <div class="lingua-forge-result"></div>

                </div>

            <?php endforeach; ?>

            <?php if ( ! empty( $overlay_features ) ) : ?>
                <div class="lf-trigger-row">
                    <?php foreach ( $overlay_features as $feature ) : ?>
                        <button
                            type="button"
                            class="button button-secondary lf-overlay-trigger"
                            data-lf-overlay-target="lf-overlay-<?php echo esc_attr( $feature->get_key() ); ?>"
                        >
                            <?php echo esc_html( $trigger_labels[ $feature->get_key() ] ?? ( $feature->get_label() . '…' ) ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <?php
        // Overlay dialogs — position:fixed relative to the metabox iframe
        // viewport, so they can live anywhere in the DOM.
        foreach ( $overlay_features as $feature ) :
            $ui_fields   = $feature->get_ui_fields();
            $defaults    = $feature->get_field_defaults( $post->ID );
            $feature_key = $feature->get_key();
        ?>
            <div
                class="lf-feature-overlay"
                id="lf-overlay-<?php echo esc_attr( $feature_key ); ?>"
                hidden
                role="dialog"
                aria-modal="true"
                aria-labelledby="lf-overlay-title-<?php echo esc_attr( $feature_key ); ?>"
            >
                <div class="lf-feature-overlay__backdrop" data-lf-overlay="close"></div>
                <div class="lf-feature-overlay__panel" role="document">
                    <header class="lf-feature-overlay__header">
                        <h2 id="lf-overlay-title-<?php echo esc_attr( $feature_key ); ?>">
                            <?php echo esc_html( $feature->get_label() ); ?>
                        </h2>
                        <button
                            type="button"
                            class="lf-feature-overlay__close"
                            data-lf-overlay="close"
                            aria-label="<?php esc_attr_e( 'Close', 'lingua-forge' ); ?>"
                        >✕</button>
                    </header>
                    <div class="lf-feature-overlay__body">
                        <!-- Config phase: fields + action button. Hidden after action runs. -->
                        <div class="lf-feature-overlay__config-section">
                            <div class="lingua-forge-panel">
                                <div class="lingua-forge-feature-group">

                                    <?php if ( ! empty( $ui_fields ) ) : ?>
                                        <div class="lingua-forge-feature-fields">
                                            <?php self::render_feature_fields( $ui_fields, $feature_key, $defaults ); ?>
                                        </div>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        class="button button-primary lingua-forge-action"
                                        data-feature="<?php echo esc_attr( $feature_key ); ?>"
                                        data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
                                    >
                                        <?php echo esc_html( $feature->get_label() ); ?>
                                    </button>

                                    <div class="lingua-forge-result"></div>

                                </div>
                            </div>
                        </div>
                        <!-- Result phase: diff or CG preview populated by JS, shown after action. -->
                        <div class="lf-feature-overlay__result-section" hidden></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php
    }
}
