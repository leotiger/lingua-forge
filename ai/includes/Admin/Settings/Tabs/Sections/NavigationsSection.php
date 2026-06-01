<?php

namespace LinguaForge\AI\Admin\Settings\Tabs\Sections;

use LinguaForge\AI\Admin\Settings\Tabs\RouterTab;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Language Navigations section for one secondary language.
 */
class NavigationsSection {

    /**
     * Render the Language Navigations section.
     *
     * Lists every published base wp_navigation post (excludes language copies
     * identified by their -{lang} suffix).  For each base nav, shows a
     * Translate / Re-translate button for the given language.
     *
     * @param string $lang Two-char secondary language code (e.g. 'de').
     */
    public static function render( string $lang ): void {

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        $all_navs = get_posts( [
            'post_type'     => 'wp_navigation',
            'post_status'   => 'publish',
            'numberposts'   => -1,
            'orderby'       => 'title',
            'order'         => 'ASC',
            'no_found_rows' => true,
        ] );

        if ( empty( $all_navs ) ) {
            return;
        }

        // Index all navs by post_name for O(1) existence checks.
        $nav_by_name = [];
        foreach ( $all_navs as $nav ) {
            $nav_by_name[ $nav->post_name ] = true;
        }

        // Exclude language copies — posts whose name ends with a -{lang} suffix.
        $router_langs  = $router->languages();
        $secondary_all = array_values( array_filter( $router_langs, fn( $l ) => $l !== $source_lang ) );
        $lang_suffixes = array_map( fn( $l ) => '-' . $l, $secondary_all );
        $base_navs     = array_filter(
            $all_navs,
            static function ( \WP_Post $nav ) use ( $lang_suffixes ): bool {
                foreach ( $lang_suffixes as $suffix ) {
                    if ( str_ends_with( $nav->post_name, $suffix ) ) {
                        return false;
                    }
                }
                return true;
            }
        );

        if ( empty( $base_navs ) ) {
            return;
        }

        $ai_active = RouterTab::ai_is_active();
        ?>

        <h3><?php esc_html_e( 'Navigations', 'lingua-forge' ); ?></h3>

        <?php if ( ! $ai_active ) : ?>
            <p class="description">
                <?php esc_html_e( 'Navigation translation requires an active AI provider. Configure an API key in the API Keys tab.', 'lingua-forge' ); ?>
            </p>
        <?php return; endif; ?>

        <table class="widefat striped lf-template-scaffold-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Navigation', 'lingua-forge' ); ?></th>
                    <th scope="col" style="width:140px"><?php esc_html_e( 'Action', 'lingua-forge' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $base_navs as $nav ) :
                $lang_name = $nav->post_name . '-' . $lang;
                $exists    = isset( $nav_by_name[ $lang_name ] );
            ?>
                <tr class="lf-tpl-row">
                    <td>
                        <strong><?php echo esc_html( $nav->post_title ); ?></strong>
                        <code class="lf-nav-name"><?php echo esc_html( $nav->post_name ); ?></code>
                        <?php if ( $exists ) : ?>
                            <span style="color:#46b450;font-weight:700;margin-left:6px;">✓</span>
                        <?php endif; ?>
                    </td>
                    <td class="lf-tpl-actions">
                        <button type="button"
                                class="button button-small lf-translate-nav-btn"
                                data-nav-id="<?php echo esc_attr( (string) $nav->ID ); ?>"
                                data-lang="<?php echo esc_attr( $lang ); ?>">
                            <?php echo $exists
                                ? esc_html__( 'Re-translate', 'lingua-forge' )
                                : esc_html__( 'Translate',    'lingua-forge' ); ?>
                        </button>
                        <span class="lf-scaffold-row-msg"></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php
    }
}
