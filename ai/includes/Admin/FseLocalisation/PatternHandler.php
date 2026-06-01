<?php

namespace LinguaForge\AI\Admin\FseLocalisation;

use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\REST\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler for AI translation of CPT-scoped block patterns.
 */
class PatternHandler {

    public static function register_hooks(): void {
        add_action( 'wp_ajax_linguaforge_translate_pattern', [ self::class, 'ajax_translate_pattern' ] );
    }

    /**
     * Translate a CPT-scoped block pattern into a target language and store it.
     *
     * Called via wp_ajax_linguaforge_translate_pattern.
     * POST params:
     *   name – registered pattern name (e.g. 'mytheme/hero-block').
     *   lang – target two-char language code.
     *
     * The translated content is persisted via PatternDiscovery::save_translation()
     * as a row in the `linguaforge_pattern_translations` option so the admin can
     * copy-paste or reference it when building language-specific CPT posts.
     */
    public static function ajax_translate_pattern(): void {
        check_ajax_referer( 'linguaforge_translate_pattern', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        if ( ! \LinguaForge\AI\Admin\Settings\Tabs\RouterTab::ai_is_active() ) {
            wp_send_json_error( __( 'No AI provider configured.', 'lingua-forge' ) );
        }

        // Pattern names follow the format "namespace/slug". sanitize_text_field()
        // strips HTML tags and extra whitespace while preserving forward slashes,
        // hyphens, and alphanumerics — all characters legal in a pattern name.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash() is called inline.
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

        if ( '' === $name || '' === $lang ) {
            wp_send_json_error( __( 'Missing pattern name or language.', 'lingua-forge' ) );
        }

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        if ( ! $router->is_valid_lang( $lang ) || $lang === $source_lang ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        // Look up the pattern in the registry.
        $registry = \WP_Block_Patterns_Registry::get_instance();
        if ( ! $registry->is_registered( $name ) ) {
            wp_send_json_error( __( 'Pattern not found.', 'lingua-forge' ) );
        }

        $pattern = $registry->get_registered( $name );
        $content = trim( (string) ( $pattern['content'] ?? '' ) );

        if ( '' === $content ) {
            wp_send_json_error( __( 'Pattern has no content to translate.', 'lingua-forge' ) );
        }

        // Expand any wp:pattern references embedded in the pattern content.
        $content = PatternExpander::expand( $content );

        // Budget protection — same per-user sliding window + site-wide UTC
        // daily ceiling that guard the REST chunk / revise / create endpoints.
        RateLimiter::gate_ajax_or_die( 'translate-pattern' );

        // Resolve human-readable language names for the AI prompt.
        $languages   = \LinguaForge\AI\Features\Translation::get_languages();
        $source_name = $languages[ $source_lang ] ?? strtoupper( $source_lang );
        $target_name = $languages[ $lang ]         ?? strtoupper( $lang );

        $system_prompt =
            "You are a professional translation system. Translate the WordPress block pattern content from {$source_name} to {$target_name}.\n\n"
            . "Rules — follow every one precisely:\n"
            . "1. Translate human-visible text inside HTML tags: <p>, <h1>–<h6>, <li>, <button>, <a>, <span>, <strong>, <em>, and similar text-bearing tags.\n"
            . "2. Inside WordPress block comment JSON (between <!-- wp:… --> delimiters), translate ONLY the string VALUES of these specific keys:\n"
            . '   "label", "alt", "caption", "placeholder", "buttonText", "title", "description", "summary".' . "\n"
            . "3. Do NOT translate: URLs, slugs, theme names, block type names, CSS class names, or any JSON key not listed above.\n"
            . "4. Preserve ALL HTML tag attributes exactly — class, id, href, src, style, data-*, aria-*.\n"
            . "5. Preserve ALL block comment delimiters exactly: <!-- wp:… --> and <!-- /wp:… -->.\n"
            . "6. Preserve ALL JSON structure exactly — braces, brackets, colons, commas.\n"
            . "7. Do not add, remove, or reorder any blocks.\n"
            . "8. Return ONLY the translated block content — no preamble, no explanation, no code fences.";

        $glossary_section = Glossary::format_for_prompt( $source_lang, $lang );
        if ( $glossary_section !== '' ) {
            $system_prompt .= "\n\n" . $glossary_section;
        }

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

        $final = \LinguaForge\AI\Core\BlockTextExtractor::strip_interblock_br( $translated );

        // Persist the translated variant.
        PatternDiscovery::save_translation( $name, $lang, $final );

        wp_send_json_success( [
            'name'    => $name,
            'lang'    => $lang,
            'warning' => true,
            'message' => __( 'Pattern translated and saved. Copy the content into your CPT post via the block editor Pattern inserter or use it as a starting point for a Reusable Block.', 'lingua-forge' ),
        ] );
    }
}
