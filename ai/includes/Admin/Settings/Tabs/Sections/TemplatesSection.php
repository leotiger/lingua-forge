<?php

namespace LinguaForge\AI\Admin\Settings\Tabs\Sections;

use LinguaForge\AI\Admin\FseLocalisation\TemplateDefinitions;
use LinguaForge\AI\Admin\Settings\Tabs\RouterTab;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the FSE templates scaffold table for one secondary language.
 */
class TemplatesSection {

    /**
     * Render the FSE templates scaffold table for one secondary language.
     *
     * Single-row table with one column per template type. The Actions column
     * provides per-language bulk Create / Translate / Fix operations via the
     * same JS handlers used by the old multi-language layout.
     *
     * @param string $lang Two-char secondary language code (e.g. 'de').
     */
    public static function render( string $lang ): void {

        $router           = \LinguaForge\Router\Router::get_instance();
        $template_defs    = TemplateDefinitions::get();
        $ai_active        = RouterTab::ai_is_active();
        $translated_slugs = (array) get_option( 'linguaforge_fse_translated_slugs', [] );

        // Pre-compute existence for every template type in one pass.
        $row_exists = [];
        foreach ( array_keys( $template_defs ) as $base ) {
            $row_exists[ $base ] = $router->template_exists( $base . '-' . $lang );
        }
        $has_missing = in_array( false, $row_exists, true );
        ?>

        <h3><?php esc_html_e( 'Templates', 'lingua-forge' ); ?></h3>
        <p><?php esc_html_e( 'FSE templates seeded from the active theme. Create then customise in the Site Editor.', 'lingua-forge' ); ?></p>

        <!-- Wrapper serves as the `.lf-tpl-row` anchor for bulk-action JS traversal. -->
        <div class="lf-tpl-row" data-lang="<?php echo esc_attr( $lang ); ?>">

            <!-- Bulk actions toolbar -->
            <div class="lf-template-bulk-actions">
                <?php if ( $has_missing ) : ?>
                <button type="button"
                        class="button lf-scaffold-all-btn"
                        data-lang="<?php echo esc_attr( $lang ); ?>">
                    <?php esc_html_e( 'Create missing', 'lingua-forge' ); ?>
                </button>
                <?php endif; ?>
                <button type="button"
                        class="button lf-recreate-all-btn"
                        data-lang="<?php echo esc_attr( $lang ); ?>">
                    <?php esc_html_e( 'Re-create all', 'lingua-forge' ); ?>
                </button>
                <?php if ( $ai_active ) : ?>
                <button type="button"
                        class="button lf-translate-row-btn"
                        data-lang="<?php echo esc_attr( $lang ); ?>">
                    <?php esc_html_e( 'Translate all', 'lingua-forge' ); ?>
                </button>
                <?php endif; ?>
                <button type="button"
                        class="button lf-fix-parts-row-btn"
                        data-lang="<?php echo esc_attr( $lang ); ?>">
                    <?php esc_html_e( 'Fix all parts', 'lingua-forge' ); ?>
                </button>
                <button type="button"
                        class="button lf-fix-links-row-btn"
                        data-lang="<?php echo esc_attr( $lang ); ?>">
                    <?php esc_html_e( 'Fix all links', 'lingua-forge' ); ?>
                </button>
                <span class="lf-scaffold-row-msg"></span>
            </div>

            <!-- One card per template type — wraps at any count without overflow. -->
            <div class="lf-template-grid">
                <?php foreach ( $template_defs as $base => $def ) :
                    $slug               = $base . '-' . $lang;
                    $exists             = $row_exists[ $base ];
                    $already_translated = $exists && in_array( $slug, $translated_slugs, true );
                ?>
                <div class="lf-template-card">
                    <div class="lf-template-card__name"><?php echo esc_html( $def['label'] ); ?></div>
                    <div class="lf-tpl-cell lf-template-card__actions" data-base="<?php echo esc_attr( $base ); ?>">
                        <?php if ( $exists ) : ?>
                            <span class="lf-tpl-exists" title="<?php echo esc_attr( $slug . '.html' ); ?>">✓</span>
                            <?php if ( $ai_active ) : ?>
                            <button type="button"
                                    class="button button-small lf-translate-one-btn"
                                    data-slug="<?php echo esc_attr( $slug ); ?>"
                                    data-post-type="wp_template">
                                <?php echo $already_translated
                                    ? esc_html__( 'Retranslate', 'lingua-forge' )
                                    : esc_html__( 'Translate',   'lingua-forge' ); ?>
                            </button>
                            <?php endif; ?>
                            <button type="button"
                                    class="button button-small lf-fix-links-btn"
                                    data-slug="<?php echo esc_attr( $slug ); ?>"
                                    data-post-type="wp_template">
                                <?php esc_html_e( 'Fix Links', 'lingua-forge' ); ?>
                            </button>
                            <button type="button"
                                    class="button button-small lf-fix-parts-btn"
                                    data-slug="<?php echo esc_attr( $slug ); ?>">
                                <?php esc_html_e( 'Fix Parts', 'lingua-forge' ); ?>
                            </button>
                            <button type="button"
                                    class="button button-small lf-recreate-one-btn"
                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                    data-base="<?php echo esc_attr( $base ); ?>">
                                <?php esc_html_e( 'Re-create', 'lingua-forge' ); ?>
                            </button>
                        <?php else : ?>
                            <button type="button"
                                    class="button button-small lf-scaffold-one-btn"
                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                    data-base="<?php echo esc_attr( $base ); ?>">
                                <?php esc_html_e( 'Create', 'lingua-forge' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div>

    <?php
    }
}
