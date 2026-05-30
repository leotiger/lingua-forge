<?php

namespace LinguaForge\AI\Admin\Settings\Tabs\Sections;

use LinguaForge\AI\Admin\FseLocalisation\PatternDiscovery;
use LinguaForge\AI\Admin\Settings\Tabs\RouterTab;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the CPT-scoped block patterns section for one secondary language.
 *
 * Only shown when at least one registered block pattern carries a `postTypes`
 * list containing a public custom post type.
 */
class PatternsSection {

    /**
     * Render the patterns table for one secondary language.
     *
     * If there are no CPT-scoped patterns registered, nothing is output.
     *
     * @param string $lang Two-char secondary language code (e.g. 'ca').
     */
    public static function render( string $lang ): void {

        $patterns  = PatternDiscovery::get_cpt_patterns();
        $ai_active = RouterTab::ai_is_active();

        if ( empty( $patterns ) ) {
            return;
        }

        if ( ! $ai_active ) {
            return; // Whole section needs AI — silent skip like NavSection.
        }

        ?>

        <h3><?php esc_html_e( 'CPT Block Patterns', 'lingua-forge' ); ?></h3>

        <p class="description">
            <?php esc_html_e( 'Block patterns registered for custom post types. Use the Translate button to produce a language variant. The translated content is saved here and can be copied into any CPT post from the block editor Pattern inserter or used as a starting point for a Synced Pattern (Reusable Block).', 'lingua-forge' ); ?>
        </p>

        <table class="widefat striped lf-template-scaffold-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Pattern', 'lingua-forge' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Post Types', 'lingua-forge' ); ?></th>
                    <th scope="col" style="width:160px"><?php esc_html_e( 'Action', 'lingua-forge' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $patterns as $pattern ) :
                $name       = (string) ( $pattern['name']  ?? '' );
                $title      = (string) ( $pattern['title'] ?? $name );
                $cpt_labels = (array)  ( $pattern['cpt_labels'] ?? [] );
                $exists     = PatternDiscovery::translation_exists( $name, $lang );
            ?>
                <tr class="lf-tpl-row lf-pattern-row"
                    data-pattern-name="<?php echo esc_attr( $name ); ?>"
                    data-lang="<?php echo esc_attr( $lang ); ?>">

                    <td>
                        <strong><?php echo esc_html( $title ); ?></strong>
                        <code style="display:block;font-size:11px;color:#666;margin-top:2px;"><?php echo esc_html( $name ); ?></code>
                        <?php if ( $exists ) : ?>
                            <span style="color:#46b450;font-size:11px;">
                                <?php esc_html_e( '✓ Translation saved', 'lingua-forge' ); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php foreach ( $cpt_labels as $cpt => $label ) : ?>
                            <span class="lf-lang-chip" style="font-size:11px;"><?php echo esc_html( $label ); ?></span>
                        <?php endforeach; ?>
                    </td>

                    <td class="lf-tpl-actions">
                        <button type="button"
                                class="button button-small lf-translate-pattern-btn"
                                data-name="<?php echo esc_attr( $name ); ?>"
                                data-lang="<?php echo esc_attr( $lang ); ?>">
                            <?php echo $exists
                                ? esc_html__( 'Re-translate', 'lingua-forge' )
                                : esc_html__( 'Translate',   'lingua-forge' ); ?>
                        </button>
                        <?php if ( $exists ) : ?>
                        <button type="button"
                                class="button button-small lf-view-pattern-btn"
                                data-name="<?php echo esc_attr( $name ); ?>"
                                data-lang="<?php echo esc_attr( $lang ); ?>">
                            <?php esc_html_e( 'View', 'lingua-forge' ); ?>
                        </button>
                        <?php endif; ?>
                        <span class="lf-scaffold-row-msg"></span>
                    </td>
                </tr>

                <?php if ( $exists ) : ?>
                <tr class="lf-pattern-preview-row"
                    id="lf-pattern-preview-<?php echo esc_attr( PatternDiscovery::name_to_key( $name ) ); ?>-<?php echo esc_attr( $lang ); ?>"
                    style="display:none;">
                    <td colspan="3">
                        <div style="background:#f6f7f7;border:1px solid #ddd;border-radius:3px;padding:12px;max-height:300px;overflow:auto;">
                            <pre style="margin:0;white-space:pre-wrap;font-size:12px;line-height:1.5;"><?php
                                echo esc_html( PatternDiscovery::get_translation( $name, $lang ) );
                            ?></pre>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
        </table>

    <?php
    }
}
