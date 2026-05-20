<?php
/**
 * Render callback for the lingua-forge/missing-translation-notice block.
 *
 * Frontend behaviour:
 *   • Emits the notice only when the current request has been resolved
 *     to a source-language post under a target-language URL — the case
 *     where Routing\Redirector::handle_singular_redirect() defines the
 *     LINGUAFORGE_LANG_FALLBACK_ACTIVE constant and returns without
 *     redirecting. That branch fires when WordPress matched a post by
 *     slug, the current LF_LANG differs from the post's source
 *     language, and no translation exists for LF_LANG in the post's
 *     TRID group.
 *   • Emits nothing in every other situation — including normal
 *     translated views, the source language at its canonical URL, and
 *     plain WordPress 404s. The constant check makes the block a
 *     no-op on the happy path.
 *
 * Editor behaviour:
 *   • When this render runs from a REST block-renderer call made by a
 *     logged-in user with edit_posts capability (i.e. the WordPress
 *     editor previewing the block), emit the notice unconditionally so
 *     the editor sees the block's intended output. The frontend
 *     visitor never reaches this branch because REST_REQUEST is not
 *     set on regular page renders.
 *
 * Variable scope note: WordPress's block runtime wraps the require() of
 * this file inside a closure created by register_block_type_from_metadata(),
 * so the local variables below (`$is_editor_preview`, `$fallback_active`,
 * `$message`, `$show_home_link`, `$home_link_text`, `$wrapper_attrs`) are
 * scoped to that closure — they never become globals despite being at
 * file-top level here. The plugin's phpcs.xml.dist excludes this file
 * pattern from PrefixAllGlobals's NonPrefixedVariableFound sub-code
 * accordingly.
 *
 * Available variables (passed by WordPress block runtime when the
 * file is loaded via block.json's "render": "file:./render.php"):
 *
 * @var array<string, mixed> $attributes Block attributes — see block.json.
 * @var string               $content    Inner content (unused — this is a
 *                                       leaf block with no inner blocks).
 * @var \WP_Block            $block      Block instance.
 *
 * @package LinguaForge\Router\Blocks
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Block-render template: WordPress wraps this file in a closure (see register_block_type_from_metadata in wp-includes/blocks.php), so the locals below are scoped to that closure and never reach the global namespace. The plugin's phpcs.xml.dist already excludes this path, but the inline directive is needed for the WordPress.org Plugin Check tool which ships its own ruleset.

$is_editor_preview = (
    defined( 'REST_REQUEST' ) && REST_REQUEST
    && current_user_can( 'edit_posts' )
);

$fallback_active = (
    defined( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE' )
    && LINGUAFORGE_LANG_FALLBACK_ACTIVE
);

if ( ! $is_editor_preview && ! $fallback_active ) {
    return;
}

$message        = isset( $attributes['messageText'] ) ? (string) $attributes['messageText'] : '';
$show_home_link = ! empty( $attributes['showHomeLink'] );
$home_link_text = isset( $attributes['homeLinkText'] ) ? (string) $attributes['homeLinkText'] : '';

// Defer to WP's wrapper-attrs API so theme + block-supports (color,
// spacing, typography, custom className, etc.) round-trip correctly.
// `role="status"` makes the notice an accessible live region — screen
// readers announce it without forcing focus.
$wrapper_attrs = get_block_wrapper_attributes( [
    'role' => 'status',
] );

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

?>
<div <?php echo $wrapper_attrs; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns a fully-escaped HTML attribute string. */ ?>>
    <?php if ( $message !== '' ) : ?>
        <p class="lingua-forge-missing-translation-notice__message">
            <?php echo esc_html( $message ); ?>
        </p>
    <?php endif; ?>

    <?php if ( $show_home_link && $home_link_text !== '' ) : ?>
        <p class="lingua-forge-missing-translation-notice__link">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
                <?php echo esc_html( $home_link_text ); ?>
            </a>
        </p>
    <?php endif; ?>
</div>
<?php
