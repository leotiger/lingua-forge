<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers for scaffolding FSE templates and template parts.
 */
class ScaffoldHandler {

    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_scaffold_template',      [ self::class, 'ajax_scaffold_template' ] );
        add_action( 'wp_ajax_linguaforge_scaffold_template_part', [ self::class, 'ajax_scaffold_template_part' ] );
    }

    /**
     * Create a single language-specific FSE template by copying the base template.
     *
     * Called via wp_ajax_linguaforge_scaffold_template.
     * POST params:
     *   lang      – two-char language code (must be a non-primary active language).
     *   base_slug – base template slug; must be a key in TemplateDefinitions::get().
     *
     * Creates a wp_template post with slug "{base_slug}-{lang}" (e.g. page-de)
     * and title "{title_label} {LANG}" (e.g. "Page DE", "Search Results DE").
     * The post content is copied from the existing base template of the active
     * theme, falling back to the index template, then to empty content.
     */
    public static function ajax_scaffold_template(): void {
        check_ajax_referer( 'linguaforge_scaffold_template', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $lang      = sanitize_key( wp_unslash( $_POST['lang']      ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() covers [a-z0-9_-] which includes hyphens needed for 'front-page'.
        $base_slug = sanitize_key( wp_unslash( $_POST['base_slug'] ?? '' ) );

        // Validate language — must be an active, non-primary language.
        $router = \LinguaForge\Router\Router::get_instance();
        if ( ! $router->is_valid_lang( $lang ) || $lang === $router->source_language() ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        // Validate base slug against the allow-list.
        $defs    = TemplateDefinitions::get();
        $allowed = array_keys( $defs );
        if ( ! in_array( $base_slug, $allowed, true ) ) {
            wp_send_json_error( __( 'Invalid template type.', 'lingua-forge' ) );
        }

        $lang_slug = $base_slug . '-' . $lang;

        // Bail if the template already exists (file or DB).
        if ( $router->template_exists( $lang_slug ) ) {
            wp_send_json_error( sprintf(
                /* translators: %s: template slug such as page-de */
                __( 'Template "%s" already exists.', 'lingua-forge' ),
                $lang_slug
            ) );
        }

        // Fetch source template content.
        // Priority: active theme → any plugin that owns this slug (e.g. WooCommerce)
        // → theme index → empty string.
        // An empty template is valid FSE — the Site Editor can populate it.
        $theme  = get_stylesheet();
        $source = get_block_template( $theme . '//' . $base_slug );

        if ( ! $source ) {
            // Theme doesn't own this slug — look for a plugin-registered template
            // (e.g. woocommerce//cart, woocommerce//checkout).
            $candidates = get_block_templates( [ 'slug__in' => [ $base_slug ] ] );
            foreach ( $candidates as $candidate ) {
                // Accept any non-user-customised template from a different owner.
                // WooCommerce uses source='theme' with theme='woocommerce'; other
                // plugins may use source='plugin'. Exclude 'custom'/'user' (already-
                // edited Site-Editor templates) and anything from the active theme.
                if ( ! in_array( $candidate->source, [ 'custom', 'user' ], true ) &&
                     $candidate->theme !== $theme ) {
                    $source = $candidate;
                    break;
                }
            }
        }

        if ( ! $source ) {
            // Last resort: copy the theme's index template so the scaffolded
            // template at least has a valid block structure.
            $source = get_block_template( $theme . '//index' );
        }

        $content = $source ? (string) $source->content : '';

        // Build the human-readable title: e.g. "Search Results DE".
        $title_label = $defs[ $base_slug ]['title'];
        $title       = $title_label . ' ' . strtoupper( $lang );

        // Insert as a wp_template post (the same type the Site Editor manages).
        $post_id = wp_insert_post( [
            'post_name'      => $lang_slug,
            'post_title'     => $title,
            'post_content'   => $content,
            'post_status'    => 'publish',
            'post_type'      => 'wp_template',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( $post_id->get_error_message() );
        }

        // Associate the new template with the source template's actual owner so
        // the Site Editor groups it correctly — WooCommerce-derived templates
        // appear under WooCommerce rather than under the active theme.
        $namespace = ( $source && $source->theme ) ? (string) $source->theme : $theme;
        wp_set_post_terms( (int) $post_id, $namespace, 'wp_theme' );

        // Tag the template with its language so the theme-switch notice can
        // count localized templates from the previous theme (class-language-router.php
        // queries _lf_lang on wp_template / wp_template_part for this purpose).
        // TridGroup explicitly excludes wp_template from translation groups so
        // this does not affect post translation relationships.
        update_post_meta( (int) $post_id, '_lf_lang', $lang );

        // Build action-buttons HTML for the newly-created template.
        // Returned as `buttons_html` so the JS can inject it into the card's
        // action area immediately — no page reload required (§9.4.2).
        $ai_active = \LinguaForge\AI\Admin\Settings\Tabs\RouterTab::ai_is_active();
        ob_start();
        ?>
        <span class="lf-tpl-exists" title="<?php echo esc_attr( $lang_slug . '.html' ); ?>">✓</span>
        <?php if ( $ai_active ) : ?>
        <button type="button"
                class="button button-small lf-translate-one-btn"
                data-slug="<?php echo esc_attr( $lang_slug ); ?>"
                data-post-type="wp_template">
            <?php esc_html_e( 'Translate', 'lingua-forge' ); ?>
        </button>
        <?php endif; ?>
        <button type="button"
                class="button button-small lf-fix-links-btn"
                data-slug="<?php echo esc_attr( $lang_slug ); ?>"
                data-post-type="wp_template">
            <?php esc_html_e( 'Fix Links', 'lingua-forge' ); ?>
        </button>
        <button type="button"
                class="button button-small lf-fix-parts-btn"
                data-slug="<?php echo esc_attr( $lang_slug ); ?>">
            <?php esc_html_e( 'Fix Parts', 'lingua-forge' ); ?>
        </button>
        <?php
        $buttons_html = (string) ob_get_clean();

        wp_send_json_success( [
            'slug'         => $lang_slug,
            'title'        => $title,
            'buttons_html' => $buttons_html,
            'message'      => sprintf(
                /* translators: %s: template title such as "Page DE" */
                __( '"%s" created.', 'lingua-forge' ),
                $title
            ),
        ] );
    }

    /**
     * Create a single language-specific FSE template part.
     *
     * Called via wp_ajax_linguaforge_scaffold_template_part.
     * POST params:
     *   lang      – two-char language code (must be a non-primary active language).
     *   base_slug – base template part slug; must be discovered via PartDiscovery::discover_template_parts().
     *
     * Creates a wp_template_part post with slug "{base_slug}-{lang}" (e.g. header-de),
     * seeded from the active theme's base part. After creation, any existing DB-stored
     * language templates for that language are updated to reference the new part slug
     * instead of the base slug.
     */
    public static function ajax_scaffold_template_part(): void {
        check_ajax_referer( 'linguaforge_scaffold_template_part', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $lang      = sanitize_key( wp_unslash( $_POST['lang']      ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $base_slug = sanitize_key( wp_unslash( $_POST['base_slug'] ?? '' ) );

        // Validate language — must be an active, non-primary language.
        $router = \LinguaForge\Router\Router::get_instance();
        if ( ! $router->is_valid_lang( $lang ) || $lang === $router->source_language() ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        // Validate part slug — must be one discovered from the active theme templates.
        $theme = get_stylesheet();
        $parts = PartDiscovery::discover_template_parts( $theme );
        if ( ! array_key_exists( $base_slug, $parts ) ) {
            wp_send_json_error( __( 'Invalid template part.', 'lingua-forge' ) );
        }

        $lang_slug = $base_slug . '-' . $lang;

        // Bail if the part already exists (file or DB).
        if ( PartDiscovery::part_exists( $lang_slug ) ) {
            wp_send_json_error( sprintf(
                /* translators: %s: template part slug such as header-de */
                __( 'Template part "%s" already exists.', 'lingua-forge' ),
                $lang_slug
            ) );
        }

        // Fetch source content from the active theme's base part.
        // Falls back to empty string — a blank part is valid FSE.
        $source  = get_block_template( $theme . '//' . $base_slug, 'wp_template_part' );
        $content = $source ? (string) $source->content : '';

        // Resolve area: prefer the template part object's own area (read from
        // the wp_template_part_area taxonomy) over the value in $parts[], which
        // was discovered from block attributes and may be absent on some themes.
        $area = ( $source && $source->area ) ? $source->area : ( $parts[ $base_slug ] ?? 'uncategorized' );

        // Build the human-readable title: e.g. "Header DE", "Primary Menu DE".
        $title = ucwords( str_replace( '-', ' ', $base_slug ) ) . ' ' . strtoupper( $lang );

        // Insert as a wp_template_part post.
        $post_id = wp_insert_post( [
            'post_name'      => $lang_slug,
            'post_title'     => $title,
            'post_content'   => $content,
            'post_status'    => 'publish',
            'post_type'      => 'wp_template_part',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( $post_id->get_error_message() );
        }

        // Associate with the active theme.
        wp_set_post_terms( (int) $post_id, $theme, 'wp_theme' );

        // Associate with the correct area taxonomy (header, footer, sidebar, etc.).
        wp_set_post_terms( (int) $post_id, $area, 'wp_template_part_area' );

        // Tag with language — needed by the theme-switch notice query in
        // class-language-router.php which counts _lf_lang-tagged wp_template_part
        // posts to warn editors when switching themes.
        update_post_meta( (int) $post_id, '_lf_lang', $lang );

        // Update any existing DB-stored language templates for this language that
        // still reference the base part — swap the slug to the new localised one.
        // Only templates stored in the DB (wp_id is set) can be updated this way.
        $updated = 0;
        foreach ( array_keys( TemplateDefinitions::get() ) as $tpl_base ) {
            $tpl_slug = $tpl_base . '-' . $lang;
            $existing = get_block_templates(
                [ 'slug__in' => [ $tpl_slug ], 'theme' => $theme ],
                'wp_template'
            );
            if ( empty( $existing ) || ! $existing[0]->wp_id ) {
                continue;
            }
            $blocks = parse_blocks( (string) $existing[0]->content );
            if ( PartRefFixer::replace_part_slug_in_blocks( $blocks, $base_slug, $lang_slug ) ) {
                wp_update_post( [
                    'ID'           => (int) $existing[0]->wp_id,
                    'post_content' => serialize_blocks( $blocks ),
                ] );
                $updated++;
            }
        }

        // Build action-buttons HTML for the newly-created template part.
        // Returned as `buttons_html` so the JS can inject it into the cell
        // immediately — no page reload required (§9.4.2).
        $ai_active = \LinguaForge\AI\Admin\Settings\Tabs\RouterTab::ai_is_active();
        ob_start();
        ?>
        <span class="lf-tpl-exists lf-tpl-exists--inline" title="<?php echo esc_attr( $lang_slug . '.html' ); ?>">✓</span>
        <?php if ( $ai_active ) : ?>
        <button type="button"
                class="button button-small lf-translate-one-btn"
                data-slug="<?php echo esc_attr( $lang_slug ); ?>"
                data-post-type="wp_template_part">
            <?php esc_html_e( 'Translate', 'lingua-forge' ); ?>
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
        <span class="lf-scaffold-row-msg"></span>
        <?php
        $buttons_html = (string) ob_get_clean();

        wp_send_json_success( [
            'slug'         => $lang_slug,
            'title'        => $title,
            'updated'      => $updated,
            'buttons_html' => $buttons_html,
            'message'      => sprintf(
                /* translators: 1: template part title e.g. "Header DE", 2: count of templates updated */
                _n(
                    '"%1$s" created. %2$d template updated.',
                    '"%1$s" created. %2$d templates updated.',
                    $updated,
                    'lingua-forge'
                ),
                $title,
                $updated
            ),
        ] );
    }
}
