<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers and AJAX handlers for fixing template-part and navigation-ref
 * block attributes in FSE templates and template parts.
 */
class PartRefFixer {

    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_fix_fse_parts',    [ self::class, 'ajax_fix_fse_parts' ] );
        add_action( 'wp_ajax_linguaforge_fix_fse_nav_refs', [ self::class, 'ajax_fix_fse_nav_refs' ] );
    }

    /**
     * Recursively replace a core/template-part slug reference inside a block tree.
     *
     * Modifies the $blocks array in place. Returns true if any replacement
     * was made (so the caller knows whether to re-serialise the template).
     *
     * @param array<int, array<string, mixed>> $blocks    Block tree (by reference).
     * @param string                           $old_slug  Slug to look for.
     * @param string                           $new_slug  Replacement slug.
     */
    public static function replace_part_slug_in_blocks( array &$blocks, string $old_slug, string $new_slug ): bool {
        $changed = false;
        foreach ( $blocks as &$block ) {
            if (
                'core/template-part' === ( $block['blockName'] ?? '' ) &&
                ( $block['attrs']['slug'] ?? '' ) === $old_slug
            ) {
                $block['attrs']['slug'] = $new_slug;
                $changed = true;
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                if ( self::replace_part_slug_in_blocks( $block['innerBlocks'], $old_slug, $new_slug ) ) {
                    $changed = true;
                }
            }
        }
        unset( $block );
        return $changed;
    }

    /**
     * Rewrite core/template-part block references inside an FSE template so
     * they point at language-specific part variants when those variants exist.
     *
     * For example, if the template 'page-ca' still contains:
     *   <!-- wp:template-part {"slug":"footer","theme":"…"} /-->
     * and 'footer-ca' has already been scaffolded, this handler updates the
     * block attribute to:
     *   <!-- wp:template-part {"slug":"footer-ca","theme":"…"} /-->
     *
     * Only applies to wp_template posts — template parts do not nest other
     * template parts in the standard WordPress FSE model.
     *
     * Called via wp_ajax_linguaforge_fix_fse_parts.
     * POST params:
     *   slug – full language-specific template slug (e.g. 'page-ca').
     */
    public static function ajax_fix_fse_parts(): void {
        check_ajax_referer( 'linguaforge_fix_fse_parts', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );

        // Infer the target language from the slug suffix (e.g. 'page-ca' → 'ca').
        $last_hyphen = strrpos( $slug, '-' );
        if ( $last_hyphen === false ) {
            wp_send_json_error( __( 'Cannot determine target language from slug.', 'lingua-forge' ) );
        }
        $lang = substr( $slug, $last_hyphen + 1 );

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        if ( ! $router->is_valid_lang( $lang ) || $lang === $source_lang ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        // Must be a DB-stored wp_template — template parts don't reference
        // other template parts, so the fix-parts action only applies here.
        $theme    = get_stylesheet();
        $existing = get_block_templates( [ 'slug__in' => [ $slug ], 'theme' => $theme ], 'wp_template' );
        if ( empty( $existing ) || ! $existing[0]->wp_id ) {
            wp_send_json_error( __( 'Template not found or not stored in the database.', 'lingua-forge' ) );
        }

        $post_id = (int) $existing[0]->wp_id;

        // Read the raw post_content from the DB and expand any wp:pattern
        // pointer blocks so the actual wp:template-part comments are visible.
        $db_post = get_post( $post_id );
        $content = $db_post ? trim( (string) $db_post->post_content ) : '';

        if ( $content === '' ) {
            wp_send_json_error( __( 'Template has no content.', 'lingua-forge' ) );
        }

        $content = PatternExpander::expand( $content );

        // Use get_block_templates() to enumerate every template part registered
        // for this theme, then build a map of base-slug → lang-slug for parts
        // whose language variant already exists.
        $all_parts    = get_block_templates( [ 'theme' => $theme ], 'wp_template_part' );
        $replacements = [];
        foreach ( $all_parts as $part ) {
            $part_slug = (string) $part->slug;
            // Skip parts that already carry a language suffix.
            if ( str_ends_with( $part_slug, '-' . $lang ) ) {
                continue;
            }
            $lang_slug = $part_slug . '-' . $lang;
            // Only map the replacement when the language variant exists.
            foreach ( $all_parts as $candidate ) {
                if ( $candidate->slug === $lang_slug ) {
                    $replacements[ $part_slug ] = $lang_slug;
                    break;
                }
            }
        }

        if ( empty( $replacements ) ) {
            wp_send_json_success( [
                'slug'    => $slug,
                'count'   => 0,
                'message' => __( 'No language-specific template parts found to map.', 'lingua-forge' ),
            ] );
        }

        // Replace each wp:template-part slug attribute directly in the raw
        // block comment markup — no parse/serialize round-trip needed.
        $replaced    = 0;
        $new_content = preg_replace_callback(
            '/<!--\s*wp:template-part\s+(\{[^}]+\})\s*\/-->/i',
            static function ( array $m ) use ( $replacements, &$replaced ): string {
                $attrs = json_decode( $m[1], true );
                if ( ! isset( $attrs['slug'] ) ) {
                    return $m[0];
                }
                $base = (string) $attrs['slug'];
                if ( ! isset( $replacements[ $base ] ) ) {
                    return $m[0];
                }
                $attrs['slug'] = $replacements[ $base ];
                $replaced++;
                return '<!-- wp:template-part ' .
                    (string) wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) .
                    ' /-->';
            },
            $content
        );

        $new_content = is_string( $new_content ) ? $new_content : $content;

        if ( $replaced === 0 ) {
            wp_send_json_success( [
                'slug'    => $slug,
                'count'   => 0,
                'message' => __( 'No template part references needed updating.', 'lingua-forge' ),
            ] );
        }

        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [
            'slug'  => $slug,
            'count' => $replaced,
            'message' => sprintf(
                /* translators: %d: number of template part references rewritten */
                _n( '%d part reference updated.', '%d part references updated.', $replaced, 'lingua-forge' ),
                $replaced
            ),
        ] );
    }

    /**
     * Fix wp:navigation ref attributes in a language-specific template part.
     *
     * When a template part such as header-ca still contains a
     * <!-- wp:navigation {"ref":42} /--> block pointing at the original
     * navigation post, it will render the wrong navigation in the Site Editor.
     * This handler:
     *   1. Reads the raw post_content of the target template part from the DB.
     *   2. Expands any wp:pattern pointer blocks so nested nav refs are visible.
     *   3. Finds every wp:navigation block that carries a "ref" attribute.
     *   4. Looks up the referenced post's post_name (the base nav name).
     *   5. Checks whether a {post_name}-{lang} wp_navigation post exists.
     *   6. If it does, replaces the "ref" integer with the language-copy's ID.
     *   7. Saves the updated content via wp_update_post().
     *
     * Called via wp_ajax_linguaforge_fix_fse_nav_refs.
     * POST params:
     *   slug – full language-specific template-part slug (e.g. 'header-ca').
     */
    public static function ajax_fix_fse_nav_refs(): void {
        check_ajax_referer( 'linguaforge_fix_fse_nav_refs', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );

        // Infer the target language from the slug suffix (e.g. 'header-ca' → 'ca').
        $last_hyphen = strrpos( $slug, '-' );
        if ( $last_hyphen === false ) {
            wp_send_json_error( __( 'Cannot determine target language from slug.', 'lingua-forge' ) );
        }
        $lang = substr( $slug, $last_hyphen + 1 );

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        // Source-language template parts are valid targets: 'header-ca' must be
        // fixable so it points at the base navigation (no lang suffix), not at
        // whichever nav WordPress happened to auto-assign first.
        if ( ! $router->is_valid_lang( $lang ) ) {
            wp_send_json_error( __( 'Invalid language.', 'lingua-forge' ) );
        }

        // Resolve the template part from the DB — filesystem-only parts can't
        // be updated programmatically, so we require a wp_id.
        $theme    = get_stylesheet();
        $existing = get_block_templates( [ 'slug__in' => [ $slug ], 'theme' => $theme ], 'wp_template_part' );
        if ( empty( $existing ) || ! $existing[0]->wp_id ) {
            wp_send_json_error( __( 'Template part not found or not stored in the database.', 'lingua-forge' ) );
        }

        $post_id = (int) $existing[0]->wp_id;

        // Read raw post_content and expand wp:pattern pointer blocks so that
        // any navigation blocks inside patterns are also visible for replacement.
        $db_post = get_post( $post_id );
        $content = $db_post ? trim( (string) $db_post->post_content ) : '';

        if ( $content === '' ) {
            wp_send_json_error( __( 'Template part has no content.', 'lingua-forge' ) );
        }

        $content = PatternExpander::expand( $content );

        // Replace each wp:navigation "ref" with the correct lang nav post ID.
        $replaced    = 0;
        $new_content = preg_replace_callback(
            '/<!--\s*wp:navigation\s+(\{[^}]+\})\s*\/-->/i',
            static function ( array $m ) use ( $lang, $source_lang, &$replaced ): string {
                $attrs = json_decode( $m[1], true );
                if ( ! isset( $attrs['ref'] ) || ! is_numeric( $attrs['ref'] ) ) {
                    return $m[0];
                }

                $ref_id  = (int) $attrs['ref'];
                $src_nav = get_post( $ref_id );
                if ( ! $src_nav || $src_nav->post_type !== 'wp_navigation' ) {
                    return $m[0];
                }

                // Derive the canonical base post_name by stripping any existing
                // language suffix from the currently referenced nav.  WordPress
                // sometimes auto-assigns the first navigation in the list (e.g.
                // navigation-it) rather than the correct one, so the ref may
                // already be wrong.  Reading _lf_lang from the referenced post
                // and stripping that suffix recovers the true base name:
                //   navigation-it  (_lf_lang = 'it')  → base: navigation
                //   navigation     (_lf_lang = 'ca')  → base: navigation (no change)
                $base_name = $src_nav->post_name;
                $ref_lang  = (string) get_post_meta( $ref_id, '_lf_lang', true );
                if ( $ref_lang && $ref_lang !== $source_lang
                    && str_ends_with( $base_name, '-' . $ref_lang )
                ) {
                    $base_name = substr( $base_name, 0, -( strlen( $ref_lang ) + 1 ) );
                }

                // Source language → target is the base nav (no suffix).
                // Other languages  → target is {base_name}-{lang}.
                $target_name = ( $lang === $source_lang )
                    ? $base_name
                    : $base_name . '-' . $lang;

                $lang_navs = get_posts( [
                    'post_type'      => 'wp_navigation',
                    'name'           => $target_name,
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                    'fields'         => 'ids',
                ] );

                if ( empty( $lang_navs ) ) {
                    return $m[0]; // Target nav does not exist yet — leave untouched.
                }

                $lang_nav_id  = (int) $lang_navs[0];
                $attrs['ref'] = $lang_nav_id;
                $replaced++;
                return '<!-- wp:navigation ' .
                    (string) wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) .
                    ' /-->';
            },
            $content
        );

        $new_content = is_string( $new_content ) ? $new_content : $content;

        if ( $replaced === 0 ) {
            wp_send_json_success( [
                'slug'    => $slug,
                'count'   => 0,
                'message' => __( 'No navigation references needed updating.', 'lingua-forge' ),
            ] );
        }

        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [
            'slug'    => $slug,
            'count'   => $replaced,
            'message' => sprintf(
                /* translators: %d: number of navigation block references rewritten */
                _n( '%d navigation reference updated.', '%d navigation references updated.', $replaced, 'lingua-forge' ),
                $replaced
            ),
        ] );
    }
}
