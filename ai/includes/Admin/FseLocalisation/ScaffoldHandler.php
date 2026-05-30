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

        // Fetch source template content from the active theme.
        // Falls back: base template → index template → empty string.
        // An empty template is valid FSE — the Site Editor can populate it.
        $theme   = get_stylesheet();
        $source  = get_block_template( $theme . '//' . $base_slug );
        if ( ! $source ) {
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

        // Associate the new template with the active theme so the Site Editor
        // can find and display it under that theme's template list.
        wp_set_post_terms( (int) $post_id, $theme, 'wp_theme' );

        wp_send_json_success( [
            'slug'    => $lang_slug,
            'title'   => $title,
            'message' => sprintf(
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

        wp_send_json_success( [
            'slug'    => $lang_slug,
            'title'   => $title,
            'updated' => $updated,
            'message' => sprintf(
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
