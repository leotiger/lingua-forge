<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

use LinguaForge\AI\REST\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handlers for AI translation of FSE templates, template parts, and navigations.
 */
class TranslateHandler {

    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_translate_fse_content',    [ self::class, 'ajax_translate_fse_content' ] );
        add_action( 'wp_ajax_linguaforge_translate_fse_navigation', [ self::class, 'ajax_translate_fse_navigation' ] );
    }

    /**
     * Apply a rudimentary AI translation to an existing FSE template or
     * template part, overwriting its stored post content.
     *
     * Called via wp_ajax_linguaforge_translate_fse_content.
     * POST params:
     *   slug      – full language-specific slug (e.g. 'page-de', 'header-de').
     *   post_type – 'wp_template' or 'wp_template_part'.
     *
     * The target language is inferred from the slug suffix (segment after the
     * last hyphen).  Translation is performed with the configured AI provider
     * using the same model tier and token limits as the full-page Translation
     * feature.  Block comment delimiters, JSON attributes, HTML attributes,
     * and URLs are preserved; only human-visible text between HTML tags is
     * translated.
     *
     * Always returns a warning flag — the result needs human review because
     * internal links, navigation URLs, and template slugs are NOT updated.
     */
    public static function ajax_translate_fse_content(): void {
        check_ajax_referer( 'linguaforge_translate_fse_content', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        if ( ! \LinguaForge\AI\Admin\Settings\Tabs\RouterTab::ai_is_active() ) {
            wp_send_json_error( __( 'No AI provider configured.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $slug      = sanitize_key( wp_unslash( $_POST['slug']      ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );

        if ( ! in_array( $post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
            wp_send_json_error( __( 'Invalid post type.', 'lingua-forge' ) );
        }

        // Infer the target language from the slug suffix (e.g. 'page-de' → 'de').
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

        // The base slug is the lang-specific slug with the lang suffix stripped.
        // e.g. 'footer-ca' → 'footer', 'front-page-de' → 'front-page'.
        $base_slug = substr( $slug, 0, $last_hyphen );

        $theme = get_stylesheet();

        // Find the target DB post — we need its ID to save the translation back.
        // Must be DB-stored (wp_id set); file-only templates are not writable here.
        $existing = get_block_templates( [ 'slug__in' => [ $slug ], 'theme' => $theme ], $post_type );
        if ( empty( $existing ) || ! $existing[0]->wp_id ) {
            wp_send_json_error( __( 'Template not found or not stored in the database.', 'lingua-forge' ) );
        }
        $post_id = (int) $existing[0]->wp_id;

        // Fetch the raw block markup from the already-scaffolded DB post.
        // The scaffold copied the base template's content at creation time, so
        // the DB post IS the right source of truth — we do not re-read the base
        // template here because doing so would give us the same content the
        // scaffold already stored, and it risks returning a bare wp:pattern
        // pointer instead of the real markup (some themes ship template parts as
        // <!-- wp:pattern {"slug":"theme/footer"} /--> references).
        $target_post = get_post( $post_id );
        $content     = $target_post ? trim( (string) $target_post->post_content ) : '';

        // Expand wp:pattern block references to their actual registered markup.
        // Many themes store template parts as a single pattern pointer; without
        // this step the AI receives only the pointer and produces nothing useful.
        if ( $content !== '' ) {
            $content = PatternExpander::expand( $content );
        }

        if ( $content === '' ) {
            wp_send_json_error( __( 'No source content found to translate.', 'lingua-forge' ) );
        }

        // Budget protection — same per-user sliding window + site-wide UTC
        // daily ceiling that guard the REST chunk / revise / create endpoints.
        // Runs after structural validation (so bad-request calls don't burn
        // the user's budget) but before the paid AI call. Exits with a 429
        // JSON envelope on either limit hit; never returns in that case.
        RateLimiter::gate_ajax_or_die( 'translate-fse-content' );

        // Resolve human-readable language names for the AI prompt.
        $languages   = \LinguaForge\AI\Features\Translation::get_languages();
        $source_name = $languages[ $source_lang ] ?? strtoupper( $source_lang );
        $target_name = $languages[ $lang ]         ?? strtoupper( $lang );

        // FSE templates carry most of their visible text inside block comment JSON
        // attributes (e.g. "label":"Home", "placeholder":"Search…") rather than in
        // innerHTML. BlockTextExtractor protects those attributes by replacing them
        // with __WPAI_N__ tokens and reinserting the *original* values — meaning
        // nothing would be translated. Instead we send the raw markup to the AI
        // with an explicit rule-set that enumerates exactly which JSON keys may be
        // translated and which must be preserved verbatim.
        $system_prompt =
            "You are a professional translation system. Translate the WordPress FSE template content from {$source_name} to {$target_name}.\n\n"
            . "Rules — follow every one precisely:\n"
            . "1. Translate human-visible text inside HTML tags: <p>, <h1>–<h6>, <li>, <button>, <a>, <span>, <strong>, <em>, and similar text-bearing tags.\n"
            . "2. Inside WordPress block comment JSON (between <!-- wp:… --> delimiters), translate ONLY the string VALUES of these specific keys:\n"
            . '   "label", "alt", "caption", "placeholder", "buttonText", "title", "description", "summary".' . "\n"
            . "3. Do NOT translate: URLs, slugs, theme names, block type names (e.g. wp:paragraph), CSS class names, or any other JSON keys not listed above.\n"
            . "4. Preserve ALL HTML tag attributes exactly — class, id, href, src, style, data-*, aria-*.\n"
            . "5. Preserve ALL block comment delimiters exactly: <!-- wp:… --> and <!-- /wp:… -->.\n"
            . "6. Preserve ALL JSON structure exactly — braces, brackets, colons, commas — only string values of the listed keys may change.\n"
            . "7. Do not add, remove, or reorder any blocks.\n"
            . "8. Return ONLY the translated template content — no preamble, no explanation, no code fences.";

        // Run the translation via the configured AI provider.
        try {
            $config   = new \LinguaForge\AI\Providers\WorkerConfig(
                model:       \LinguaForge\AI\Core\Config::model( \LinguaForge\AI\Core\Config::translation_tier() ),
                max_tokens:  \LinguaForge\AI\Core\Config::translation_max_tokens(),
                temperature: 0.2,
            );
            $provider   = \LinguaForge\AI\Providers\ProviderFactory::make( $config );
            $translated = $provider->chat( [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $content ],
            ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }

        if ( empty( $translated ) ) {
            wp_send_json_error( __( 'AI provider returned an empty response.', 'lingua-forge' ) );
        }

        // Strip hallucinated inter-block <br> tags; no placeholder reinsertion needed.
        $final = \LinguaForge\AI\Core\BlockTextExtractor::strip_interblock_br( $translated );

        // Write debug files when debug logging is enabled (constant or UI toggle).
        // Uses post_id = 0 as a sentinel for FSE templates (no real post context).
        if ( \LinguaForge\AI\Core\TranslationDebug::debug_enabled() ) {
            \LinguaForge\AI\Core\TranslationDebug::debug_write(
                0, $lang, 'fse-source',
                "Template: {$slug} ({$post_type})\nBase slug: {$base_slug}\n\n{$system_prompt}\n\n---\n\n{$content}"
            );
            \LinguaForge\AI\Core\TranslationDebug::debug_write(
                0, $lang, 'fse-response',
                "Template: {$slug} ({$post_type})\n\n{$final}"
            );
        }

        // Save the translated content back to the DB post.
        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $final,
        ], true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Record this slug as translated so the UI can show "Retranslate" next time.
        $done   = (array) get_option( 'linguaforge_fse_translated_slugs', [] );
        $done[] = $slug;
        update_option( 'linguaforge_fse_translated_slugs', array_values( array_unique( $done ) ), false );

        wp_send_json_success( [
            'slug'    => $slug,
            'warning' => true,
            'message' => __( 'Translated. Review in the Site Editor — internal links, navigation URLs, and template slugs are not updated automatically.', 'lingua-forge' ),
        ] );
    }

    /**
     * Create or update a language-specific copy of a wp_navigation post.
     *
     * The AI translates the 'label' values of wp:navigation-link and
     * wp:navigation-submenu blocks; internal URLs are then rewritten to carry
     * the target language's URL prefix.  The result is saved as a new post
     * with post_name = {source_name}-{lang} (e.g. primary-navigation-de).
     * If a post with that name already exists it is overwritten, so the
     * button doubles as a Re-translate action.
     *
     * Called via wp_ajax_linguaforge_translate_fse_navigation.
     * POST params:
     *   nav_id – ID of the source wp_navigation post.
     *   lang   – target two-char language code.
     */
    public static function ajax_translate_fse_navigation(): void {
        check_ajax_referer( 'linguaforge_translate_fse_navigation', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        if ( ! \LinguaForge\AI\Admin\Settings\Tabs\RouterTab::ai_is_active() ) {
            wp_send_json_error( __( 'No AI provider configured.', 'lingua-forge' ) );
        }

        $nav_id = absint( wp_unslash( $_POST['nav_id'] ?? 0 ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $lang   = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        if ( ! $router->is_valid_lang( $lang ) || $lang === $source_lang ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        $source_nav = get_post( $nav_id );
        if ( ! $source_nav || $source_nav->post_type !== 'wp_navigation' ) {
            wp_send_json_error( __( 'Navigation not found.', 'lingua-forge' ) );
        }

        $content = trim( (string) $source_nav->post_content );
        if ( $content === '' ) {
            wp_send_json_error( __( 'Navigation has no content to translate.', 'lingua-forge' ) );
        }

        // Budget protection — same per-user sliding window + site-wide UTC
        // daily ceiling that guard the REST chunk / revise / create endpoints.
        // Runs after structural validation (so bad-request calls don't burn
        // the user's budget) but before the paid AI call. Exits with a 429
        // JSON envelope on either limit hit; never returns in that case.
        RateLimiter::gate_ajax_or_die( 'translate-fse-navigation' );

        // Resolve human-readable language names for the prompt.
        $languages   = \LinguaForge\AI\Features\Translation::get_languages();
        $source_name = $languages[ $source_lang ] ?? strtoupper( $source_lang );
        $target_name = $languages[ $lang ]         ?? strtoupper( $lang );

        // Navigation blocks carry translatable text only in 'label' attributes
        // of wp:navigation-link and wp:navigation-submenu.  Everything else —
        // URLs, IDs, kind, type, isTopLevelLink, etc. — must be preserved so
        // that the menu items resolve correctly after translation.
        $system_prompt =
            "You are a professional translation system. Translate WordPress navigation block content from {$source_name} to {$target_name}.\n\n"
            . "Rules — follow every one precisely:\n"
            . "1. Translate ONLY the string values of the 'label' key in wp:navigation-link and wp:navigation-submenu block comments.\n"
            . "2. Preserve ALL other JSON attributes exactly — url, id, kind, type, isTopLevelLink, opensInNewTab, className, rel, title, description, etc.\n"
            . "3. Preserve ALL block comment delimiters exactly: <!-- wp:… --> and <!-- /wp:… -->.\n"
            . "4. Preserve ALL JSON structure exactly — braces, brackets, colons, commas.\n"
            . "5. Do NOT translate URLs, slugs, post IDs, or any key other than 'label'.\n"
            . "6. Return ONLY the translated block content — no preamble, no explanation, no code fences.";

        try {
            $config   = new \LinguaForge\AI\Providers\WorkerConfig(
                model:       \LinguaForge\AI\Core\Config::model( \LinguaForge\AI\Core\Config::translation_tier() ),
                max_tokens:  \LinguaForge\AI\Core\Config::translation_max_tokens(),
                temperature: 0.2,
            );
            $provider   = \LinguaForge\AI\Providers\ProviderFactory::make( $config );
            $translated = $provider->chat( [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $content ],
            ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }

        if ( empty( $translated ) ) {
            wp_send_json_error( __( 'AI provider returned an empty response.', 'lingua-forge' ) );
        }

        // Rewrite internal URLs for the target language.
        // • Path mode:      example.com/contact/      → example.com/de/contact/
        // • Subdomain mode: example.com/contact/      → de.example.com/contact/
        $home    = untrailingslashit( home_url() );
        $context = $router->context;
        $pattern = '/"url":"(' . preg_quote( $home, '/' ) . ')(\/[^"]*?)"/i';

        if ( $context->routing_mode() === 'subdomain' ) {
            $lang_base = untrailingslashit( $context->lang_base_url( $lang ) );
            $fixed     = preg_replace_callback(
                $pattern,
                static function ( array $m ) use ( $lang_base ): string {
                    // $m[2] is the path component, e.g. /contact/ — keep as-is,
                    // only swap the host from home_url() to the lang subdomain.
                    return '"url":"' . $lang_base . $m[2] . '"';
                },
                $translated
            );
        } else {
            $prefix = '/' . $lang . '/';
            $fixed  = preg_replace_callback(
                $pattern,
                static function ( array $m ) use ( $lang, $prefix ): string {
                    $path = $m[2];
                    if ( str_starts_with( ltrim( $path, '/' ), $lang . '/' ) || ltrim( $path, '/' ) === $lang ) {
                        return $m[0]; // Already prefixed — skip.
                    }
                    return '"url":"' . $m[1] . $prefix . ltrim( $path, '/' ) . '"';
                },
                $translated
            );
        }

        $final = is_string( $fixed ) ? $fixed : $translated;

        // Create or overwrite the lang-specific navigation post.
        $lang_post_name = $source_nav->post_name . '-' . $lang;
        $existing       = get_posts( [
            'post_type'     => 'wp_navigation',
            'post_status'   => 'publish',
            'name'          => $lang_post_name,
            'numberposts'   => 1,
            'no_found_rows' => true,
        ] );

        if ( ! empty( $existing ) ) {
            $existing_id = (int) $existing[0]->ID;
            $result      = wp_update_post( [
                'ID'           => $existing_id,
                'post_content' => $final,
            ], true );
            $new_post_id = is_wp_error( $result ) ? 0 : $existing_id;
        } else {
            $result      = wp_insert_post( [
                'post_name'    => $lang_post_name,
                'post_title'   => $source_nav->post_title . ' ' . strtoupper( $lang ),
                'post_content' => $final,
                'post_status'  => 'publish',
                'post_type'    => 'wp_navigation',
            ], true );
            $new_post_id = is_wp_error( $result ) ? 0 : (int) $result;
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Tag the navigation post with its language and link it to the source
        // navigation via TRID so the admin Translation column shows the group
        // correctly (instead of treating each navigation as standalone).
        if ( $new_post_id > 0 ) {
            // Ensure the source navigation has a TRID, then share it.
            $trid = $router->get_trid( $nav_id );
            if ( ! $trid ) {
                $trid = wp_generate_uuid4();
                $router->set_trid( $nav_id, $trid );
                // Also tag the source with its own language if not already set.
                if ( ! $router->get_lang( $nav_id ) ) {
                    $router->set_lang( $nav_id, $source_lang );
                }
            }
            $router->set_trid( $new_post_id, $trid );
            $router->set_lang( $new_post_id, $lang );
        }

        wp_send_json_success( [
            'nav_id'   => $nav_id,
            'lang'     => $lang,
            'new_id'   => $new_post_id,
            'nav_name' => $lang_post_name,
            'message'  => sprintf(
                /* translators: 1: navigation title e.g. "Primary Navigation", 2: language code e.g. "DE" */
                __( '"%1$s %2$s" saved.', 'lingua-forge' ),
                esc_html( $source_nav->post_title ),
                strtoupper( $lang )
            ),
        ] );
    }
}
