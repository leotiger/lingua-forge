<?php

namespace LinguaForge\AI\Admin\Settings\Tabs\Sections;

use LinguaForge\AI\Admin\FseLocalisation\PartDiscovery;
use LinguaForge\AI\Admin\Settings\Tabs\RouterTab;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the FSE template parts scaffold table for one secondary language.
 */
class TemplatePartsSection {

    /**
     * Render the FSE template parts scaffold table for one secondary language.
     *
     * Two-column table: part name + area badge in the first column, status
     * and action buttons in the second.  Replaces the old parts × languages
     * cross-table with a compact per-language view.
     *
     * @param string $lang Two-char secondary language code (e.g. 'de').
     */
    public static function render( string $lang ): void {

        $theme            = get_stylesheet();
        $parts            = PartDiscovery::discover_template_parts( $theme );
        $ai_active        = RouterTab::ai_is_active();
        $translated_slugs = (array) get_option( 'linguaforge_fse_translated_slugs', [] );

        if ( empty( $parts ) ) {
            return;
        }
        ?>

        <h3><?php esc_html_e( 'Template Parts', 'lingua-forge' ); ?></h3>
        <p><?php esc_html_e( 'Language-specific copies of the theme\'s template parts. Once scaffolded, templates are updated to reference the language-specific part.', 'lingua-forge' ); ?></p>

        <!-- Wrapper serves as the `.lf-parts-group` anchor for bulk-action JS traversal. -->
        <div class="lf-parts-group" data-lang="<?php echo esc_attr( $lang ); ?>">

        <div class="lf-template-bulk-actions">
            <button type="button"
                    class="button lf-recreate-all-parts-btn"
                    data-lang="<?php echo esc_attr( $lang ); ?>">
                <?php esc_html_e( 'Re-create all', 'lingua-forge' ); ?>
            </button>
            <?php if ( $ai_active ) : ?>
            <button type="button"
                    class="button lf-translate-all-parts-btn"
                    data-lang="<?php echo esc_attr( $lang ); ?>">
                <?php esc_html_e( 'Translate all', 'lingua-forge' ); ?>
            </button>
            <?php endif; ?>
            <button type="button"
                    class="button lf-fix-links-all-parts-btn"
                    data-lang="<?php echo esc_attr( $lang ); ?>">
                <?php esc_html_e( 'Fix all links', 'lingua-forge' ); ?>
            </button>
            <button type="button"
                    class="button lf-fix-nav-refs-all-btn"
                    data-lang="<?php echo esc_attr( $lang ); ?>">
                <?php esc_html_e( 'Fix all navs', 'lingua-forge' ); ?>
            </button>
            <span class="lf-scaffold-row-msg"></span>
        </div>

        <table class="widefat striped lf-template-scaffold-table">
            <thead>
                <tr>
                    <th scope="col" style="width:220px"><?php esc_html_e( 'Part', 'lingua-forge' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Status / Actions', 'lingua-forge' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $parts as $part_slug => $area ) :
                $lang_slug          = $part_slug . '-' . $lang;
                $exists             = PartDiscovery::part_exists( $lang_slug );
                $already_translated = $exists && in_array( $lang_slug, $translated_slugs, true );
            ?>
                <tr class="lf-tpl-row" data-part="<?php echo esc_attr( $part_slug ); ?>">
                    <td>
                        <strong><?php echo esc_html( $part_slug ); ?></strong>
                        <span class="lf-area-badge lf-area-<?php echo esc_attr( $area ); ?>">
                            <?php echo esc_html( $area ); ?>
                        </span>
                    </td>
                    <td class="lf-tpl-cell lf-tpl-cell--inline" data-base="<?php echo esc_attr( $part_slug ); ?>">
                        <?php if ( $exists ) : ?>
                            <span class="lf-tpl-exists lf-tpl-exists--inline"
                                  title="<?php echo esc_attr( $lang_slug . '.html' ); ?>">✓</span>
                            <?php if ( $ai_active ) : ?>
                            <button type="button"
                                    class="button button-small lf-translate-one-btn"
                                    data-slug="<?php echo esc_attr( $lang_slug ); ?>"
                                    data-post-type="wp_template_part">
                                <?php echo $already_translated
                                    ? esc_html__( 'Retranslate', 'lingua-forge' )
                                    : esc_html__( 'Translate',   'lingua-forge' ); ?>
                            </button>
                            <?php endif; ?>
                            <button type="button"
                                    class="button button-small lf-fix-links-btn"
                                    data-slug="<?php echo esc_attr( $lang_slug ); ?>"
                                    data-post-type="wp_template_part">
                                <?php esc_html_e( 'Fix Links', 'lingua-forge' ); ?>
                            </button>
                            <button type="button"
                                    class="button button-small lf-fix-nav-refs-btn"
                                    data-slug="<?php echo esc_attr( $lang_slug ); ?>">
                                <?php esc_html_e( 'Fix Nav', 'lingua-forge' ); ?>
                            </button>
                            <button type="button"
                                    class="button button-small lf-recreate-part-btn"
                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                    data-base="<?php echo esc_attr( $part_slug ); ?>">
                                <?php esc_html_e( 'Re-create', 'lingua-forge' ); ?>
                            </button>
                        <?php else : ?>
                            <button type="button"
                                    class="button button-small lf-scaffold-part-btn"
                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                    data-base="<?php echo esc_attr( $part_slug ); ?>">
                                <?php esc_html_e( 'Create', 'lingua-forge' ); ?>
                            </button>
                        <?php endif; ?>
                        <span class="lf-scaffold-row-msg"></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        </div>

    <?php
    }
}
