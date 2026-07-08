<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

/**
 * Settings tab: Behavior
 *
 * Block Editor restrictions and Global AI Preset (compliance mode).
 * Translation Memory and API Response Cache toggles live in the
 * AI Usage &amp; Cache tab → Translation Caching inner tabs.
 */
class BehaviorTab extends Tab {

    public static function slug(): string {
        return 'behavior';
    }

    public static function label(): string {
        return __( 'Behavior', 'lingua-forge' );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        ?>
        <!-- ── Block Editor (§2.7) ─────────────────────────────── -->
        <h2><?php esc_html_e('Block Editor', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Lingua Forge restricts two Gutenberg features by default to keep editorial behavior consistent across languages. Opt in here when you need full Gutenberg / FSE capabilities.', 'lingua-forge' );
            ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <?php esc_html_e('Block locking', 'lingua-forge'); ?>
                </th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="linguaforge_block_editor_allow_lock_blocks"
                            value="1"
                            <?php checked( (bool) get_option('linguaforge_block_editor_allow_lock_blocks', false) ); ?>
                        >
                        <?php esc_html_e('Allow editors to lock individual blocks', 'lingua-forge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Re-enables Gutenberg\'s canLockBlocks. Useful for editorial templates that need to prevent contributors from moving or deleting specific blocks.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <?php esc_html_e('Template mode', 'lingua-forge'); ?>
                </th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="linguaforge_block_editor_allow_template_mode"
                            value="1"
                            <?php checked( (bool) get_option('linguaforge_block_editor_allow_template_mode', false) ); ?>
                        >
                        <?php esc_html_e('Allow Gutenberg template-editing mode on post screens', 'lingua-forge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Re-enables supportsTemplateMode. Core to Full-Site-Editing workflows where post-level template overrides are intentional.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <!-- ── Compliance Preset (§2.8) ────────────────────────── -->
        <h2><?php esc_html_e('Compliance Preset', 'lingua-forge'); ?></h2>

        <p>
            <?php
            esc_html_e( 'Strict-preservation mode for legal, regulatory, medical, or technical content. When on, every AI feature uses a low sampling temperature and appends the addendum below to its system prompt — terminology, article numbers, units, brand names, and regulatory language are preserved verbatim rather than paraphrased.', 'lingua-forge' );
            ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="linguaforge_active_preset">
                        <?php esc_html_e('Global AI preset', 'lingua-forge'); ?>
                    </label>
                </th>
                <td>
                    <?php
                    $presets        = \LinguaForge\AI\Core\Config::presets();
                    $current_preset = \LinguaForge\AI\Core\Config::active_preset();
                    ?>
                    <select id="linguaforge_active_preset" name="linguaforge_active_preset">
                        <?php foreach ($presets as $key => $meta): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($current_preset, $key); ?>>
                                <?php echo esc_html($meta['label']); ?>
                                <?php if ($meta['temperature'] !== null): ?>
                                    (<?php
                                    /* translators: %s: sampling temperature value, e.g. 0.2 */
                                    printf( esc_html__( 'T=%s', 'lingua-forge' ), esc_html( (string) $meta['temperature'] ) );
                                ?>)
                                <?php else: ?>
                                    (<?php
                                    /* translators: temperature range shown for the Standard preset, e.g. "T=0.2–0.6, per feature" */
                                    esc_html_e( 'T=0.2–0.6, per feature', 'lingua-forge' );
                                ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Sets the default AI behaviour for all features site-wide. Individual posts can override this for Translation and Content Generation via the Lingua Forge meta box.', 'lingua-forge'); ?>
                        <?php esc_html_e('Standard uses each feature\'s own tuned temperature: Translation T=0.2 (precise), Quick Translate T=0.4, Content Generator T=0.6 (creative). The other presets apply a single fixed temperature across all features.', 'lingua-forge'); ?>
                    </p>
                    <div id="lf-preset-preview" class="lf-preset-preview" hidden>
                        <p class="lf-preset-preview-label"></p>
                        <pre class="lf-preset-preview-text"></pre>
                    </div>
                </td>
            </tr>
            <?php
            $addendum_presets = [
                'technical' => __( 'Technical / Scientific instructions', 'lingua-forge' ),
                'legal'     => __( 'Legal / Compliance instructions',     'lingua-forge' ),
                'creative'  => __( 'Creative / Marketing instructions',   'lingua-forge' ),
            ];
            foreach ( $addendum_presets as $preset_key => $preset_label ) :
                $opt_key        = 'linguaforge_preset_addendum_' . $preset_key;
                $field_id       = 'linguaforge_preset_addendum_' . $preset_key;
                $stored_text    = (string) get_option( $opt_key, '' );
                $default_text   = \LinguaForge\AI\Core\Config::default_preset_addendum( $preset_key );
            ?>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr( $field_id ); ?>">
                        <?php echo esc_html( $preset_label ); ?>
                    </label>
                </th>
                <td>
                    <textarea
                        id="<?php echo esc_attr( $field_id ); ?>"
                        name="<?php echo esc_attr( $opt_key ); ?>"
                        rows="6"
                        class="large-text code"
                        placeholder="<?php echo esc_attr( $default_text ); ?>"
                    ><?php echo esc_textarea( $stored_text ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Leave blank to use the built-in default. Clearing a saved override restores the default on next save.', 'lingua-forge' ); ?>
                    </p>
                    <details style="margin-top:.4em">
                        <summary style="cursor:pointer;color:#646970"><?php esc_html_e( 'View built-in default', 'lingua-forge' ); ?></summary>
                        <pre style="margin:.5em 0 0;white-space:pre-wrap;font-size:12px;background:#f6f7f7;padding:.6em .8em;border-radius:3px"><?php echo esc_html( $default_text ); ?></pre>
                    </details>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <!-- ── Sync — general secondary-language safeguard ─────────── -->
        <h2><?php esc_html_e('Sync', 'lingua-forge'); ?></h2>

        <p>
            <?php esc_html_e( 'The "Sync" button in the post list Lang column retranslates a post out into every other language, including the primary/source post when it\'s run from a translation. That back-translation direction is restricted by default for every post type. (WooCommerce products and variations have their own separate setting below — this one does not affect them.)', 'lingua-forge' ); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <?php esc_html_e('Secondary-language Sync', 'lingua-forge'); ?>
                </th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="linguaforge_allow_secondary_sync"
                            value="1"
                            <?php checked( (bool) get_option('linguaforge_allow_secondary_sync', false) ); ?>
                        >
                        <?php esc_html_e('Allow "Sync" on a translated post to overwrite the primary post', 'lingua-forge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Off by default: clicking "Sync" on a translation is blocked from overwriting the primary post. Syncing FROM the primary post to every translation is always allowed regardless of this setting. Turn this on only if you intentionally want a translation to be able to become the new source content.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php if ( class_exists( 'WooCommerce' ) ) : ?>

        <!-- ── WooCommerce — Sync safeguard ───────────────────────── -->
        <h2><?php esc_html_e('WooCommerce', 'lingua-forge'); ?></h2>

        <p>
            <?php esc_html_e( 'The primary-language product is WooCommerce\'s operational source of truth for price, SKU, and stock, which are always served from it regardless of which language a shopper is viewing. This is a separate, independent setting from the general "Sync" restriction above — enabling one does not enable the other.', 'lingua-forge' ); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <?php esc_html_e('Secondary-language Sync', 'lingua-forge'); ?>
                </th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="linguaforge_wc_allow_secondary_sync"
                            value="1"
                            <?php checked( (bool) get_option('linguaforge_wc_allow_secondary_sync', false) ); ?>
                        >
                        <?php esc_html_e('Allow "Sync" on a translated product to overwrite the primary product', 'lingua-forge'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Off by default: clicking "Sync" on a translated WooCommerce product is blocked, since it would back-translate onto the primary product\'s title, description, and excerpt. Syncing FROM the primary product to every translation is always allowed regardless of this setting. Turn this on only if you intentionally want a translation to be able to become the new source content.', 'lingua-forge'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php endif; ?>

        <?php
    }
}
