<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\REST\RateLimiter;

defined('ABSPATH') || exit;

/**
 * Settings tab: Router
 *
 * Primary language selection, permalink flush, active languages list, and
 * AJAX-driven language-pack installation.
 *
 * This tab uses its own admin-post actions rather than the shared settings
 * form, so save() is not implemented.
 */
class RouterTab extends Tab {

    public static function slug(): string {
        return 'router';
    }

    public static function label(): string {
        return __( 'Router', 'lingua-forge' );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        $router          = \LinguaForge\Router\Router::get_instance();
        $routing_mode    = $router->context->routing_mode();
        $primary_stored  = (string) get_option( 'linguaforge_primary_language', '' );
        $router_langs    = $router->languages();

        // Guarantee the currently stored value always appears in the dropdown.
        // Without this, if the stored language is not in $router_langs (e.g. 'en'
        // when no English locale pack is installed and the admin's personal
        // language is set to another locale), the select renders without that
        // option, the browser submits the first available option, and the stored
        // value is silently overwritten on the next save.
        if ( $primary_stored !== '' && ! in_array( $primary_stored, $router_langs, true ) ) {
            array_unshift( $router_langs, $primary_stored );
        }
        $installed_locales = get_available_languages();
        ?>

        <?php
        // ── Feedback notices ─────────────────────────────────────────────────
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flags set by wp_safe_redirect() after router actions; no data is modified here.
        if ( ! empty( $_GET['lf_router_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Primary language saved.', 'lingua-forge' ); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( ! empty( $_GET['lf_permalinks_flushed'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Permalink rules flushed successfully.', 'lingua-forge' ); ?></p>
            </div>
        <?php endif; ?>

        <!-- ── Primary Language ────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Primary Language', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'The primary language is always served at the root of your site — no URL prefix in path mode, no language subdomain in subdomain mode. All other languages use either a /lang/ path prefix (e.g. example.com/de/) or a language subdomain (e.g. de.example.com), depending on the URL structure setting below.', 'lingua-forge' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="linguaforge_save_router_settings">
            <?php wp_nonce_field( 'linguaforge_save_router_settings', 'linguaforge_router_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="linguaforge_primary_language">
                            <?php esc_html_e( 'Primary language', 'lingua-forge' ); ?>
                        </label>
                    </th>
                    <td>
                        <select id="linguaforge_primary_language" name="linguaforge_primary_language">
                            <?php foreach ( $router_langs as $code ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $primary_stored, $code ); ?>>
                                    <?php echo esc_html( strtoupper( $code ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'After changing the primary language, flush permalinks (section below) for the URL routing to update.', 'lingua-forge' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="linguaforge_routing_mode">
                            <?php esc_html_e( 'URL structure', 'lingua-forge' ); ?>
                        </label>
                    </th>
                    <td>
                        <select id="linguaforge_routing_mode" name="linguaforge_routing_mode">
                            <option value="path" <?php selected( $routing_mode, 'path' ); ?>>
                                <?php esc_html_e( 'Path prefix — example.com/de/', 'lingua-forge' ); ?>
                            </option>
                            <option value="subdomain" <?php selected( $routing_mode, 'subdomain' ); ?>>
                                <?php esc_html_e( 'Subdomain — de.example.com/', 'lingua-forge' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Subdomain mode requires your web server to point all language subdomains (e.g. de.example.com) to this WordPress installation. After switching modes, flush permalink rules and clear any caches. If your home URL includes www (e.g. www.example.com), use the lf_base_domain filter to set the bare apex domain so subdomains are constructed correctly.', 'lingua-forge' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Browser language redirect', 'lingua-forge' ); ?>
                    </th>
                    <td>
                        <label>
                            <input
                                type="checkbox"
                                name="lf_browser_redirect"
                                value="1"
                                <?php checked( get_option( 'lf_browser_redirect', false ) ); ?>
                            />
                            <?php esc_html_e( 'Redirect visitors to their preferred language based on the browser\'s Accept-Language header', 'lingua-forge' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'When enabled, first-time visitors with no language cookie and no language prefix in the URL are redirected to the closest matching language version. The redirect is skipped if the browser\'s preferred language is not among the active router languages. Once a visitor selects a language via the switcher, the cookie takes priority and the browser header is ignored on all future visits.', 'lingua-forge' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Router Settings', 'lingua-forge' ), 'secondary' ); ?>
        </form>

        <!-- ── Flush Permalinks ─────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Flush Permalinks', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Regenerates WordPress rewrite rules so URL prefixes, language-specific slugs, and archive rewrites are all in sync. Necessary after changing the primary language or adding new language support.', 'lingua-forge' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="linguaforge_flush_permalinks">
            <?php wp_nonce_field( 'linguaforge_flush_permalinks', 'linguaforge_flush_nonce' ); ?>
            <?php submit_button( __( 'Flush Permalink Rules', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
        </form>

        <!-- ── Active Languages ─────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Active Languages', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Languages currently known to the router (derived from installed WordPress locale packs plus the primary language). Install additional language packs in the section below to make more languages available for routing and translation.', 'lingua-forge' ); ?>
        </p>

        <div class="lf-installed-langs">
            <?php foreach ( $router_langs as $code ) : ?>
                <span class="lf-lang-chip"><?php echo esc_html( $code ); ?></span>
            <?php endforeach; ?>
        </div>

        <?php if ( ! empty( $installed_locales ) ) : ?>
            <p class="description">
                <?php
                echo esc_html( sprintf(
                    /* translators: %d: count of installed locale packs */
                    _n( '%d locale pack installed.', '%d locale packs installed.', count( $installed_locales ), 'lingua-forge' ),
                    count( $installed_locales )
                ) );
                ?>
            </p>
        <?php endif; ?>

        <!-- ── Install Language ─────────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Install a Language', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Download and install a WordPress core language pack directly from WordPress.org. Once installed, the locale becomes available for URL routing and the AI translation workflow. The list of available languages is fetched on demand — click the button below to load it.', 'lingua-forge' ); ?>
        </p>

        <?php if ( ! wp_is_file_mod_allowed( 'download_language_pack' ) ) : ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e( 'Language installation is disabled on this server. The DISALLOW_FILE_MODS constant is set in wp-config.php. Install language packs manually via WP-CLI: wp language core install de_DE', 'lingua-forge' ); ?></p>
            </div>
        <?php else : ?>
            <p>
                <button type="button" id="lf-load-langs-btn" class="button">
                    <?php esc_html_e( 'Load available languages', 'lingua-forge' ); ?>
                </button>
            </p>

            <p id="lf-lang-install-row" style="display:none;">
                <select id="lf-lang-install-select" disabled>
                    <option value=""><?php esc_html_e( '— select a language —', 'lingua-forge' ); ?></option>
                </select>
                <button type="button" id="lf-install-lang-btn" class="button button-primary" disabled>
                    <?php esc_html_e( 'Install', 'lingua-forge' ); ?>
                </button>
                <span id="lf-lang-install-result"></span>
            </p>
        <?php endif; ?>

        <?php self::render_templates_section(); ?>

    <?php
    }

    // ── AI helpers ────────────────────────────────────────────────────────────

    /**
     * Return true when an AI provider is selected and a key is available.
     *
     * Used to gate the Translate buttons — they are only shown when the plugin
     * can actually make an AI call.
     */
    private static function ai_is_active(): bool {
        $provider = \LinguaForge\AI\Core\Config::provider();
        return ! empty( \LinguaForge\AI\Core\KeyStore::get( $provider ) );
    }

    // ── Language Templates ────────────────────────────────────────────────────

    /**
     * Standard FSE template types available for per-language scaffolding.
     *
     * Keys are the base template slugs used by WordPress's template hierarchy.
     * 'label'  – short column header shown in the scaffold table.
     * 'title'  – prefix used in the generated wp_template post title
     *            (e.g. 'Search Results' → title becomes 'Search Results DE').
     *
     * @return array<string, array{label: string, title: string}>
     */
    private static function template_definitions(): array {
        return [
            'page'       => [
                'label' => __( 'Page',           'lingua-forge' ),
                'title' => __( 'Page',           'lingua-forge' ),
            ],
            'single'     => [
                'label' => __( 'Single',         'lingua-forge' ),
                'title' => __( 'Single',         'lingua-forge' ),
            ],
            'search'     => [
                'label' => __( 'Search',         'lingua-forge' ),
                'title' => __( 'Search Results', 'lingua-forge' ),
            ],
            'archive'    => [
                'label' => __( 'Archive',        'lingua-forge' ),
                'title' => __( 'Archive',        'lingua-forge' ),
            ],
            'front-page' => [
                'label' => __( 'Front Page',     'lingua-forge' ),
                'title' => __( 'Front Page',     'lingua-forge' ),
            ],
        ];
    }

    /**
     * Render the Language Templates scaffold section.
     *
     * Shows a table of secondary languages × standard template types.
     * Each cell displays ✓ if the template already exists, or a "Create"
     * button if it is missing. A "Create missing" button per row creates
     * all absent templates for that language in one click.
     */
    private static function render_templates_section(): void {
        $router          = \LinguaForge\Router\Router::get_instance();
        $source_lang     = $router->source_language();
        $router_langs    = $router->languages();
        $secondary_langs = array_values( array_filter( $router_langs, fn( $l ) => $l !== $source_lang ) );
        $template_defs   = self::template_definitions();
        $ai_active        = self::ai_is_active();
        $translated_slugs = (array) get_option( 'linguaforge_fse_translated_slugs', [] );
        ?>

        <!-- ── Language Templates ──────────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Language Templates', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Create language-specific FSE templates for each active secondary language. Each template is seeded from the corresponding default theme template and named to match the routing model (e.g. page-de, search-de). Templates can then be customised in the WordPress Site Editor.', 'lingua-forge' ); ?>
        </p>

        <?php if ( empty( $secondary_langs ) ) : ?>
            <p class="description">
                <?php esc_html_e( 'No secondary languages configured. Install a language pack above to enable template scaffolding.', 'lingua-forge' ); ?>
            </p>
        <?php else : ?>

        <table class="widefat striped lf-template-scaffold-table">
            <thead>
                <tr>
                    <th scope="col" style="width:72px"><?php esc_html_e( 'Language', 'lingua-forge' ); ?></th>
                    <?php foreach ( $template_defs as $def ) : ?>
                        <th scope="col"><?php echo esc_html( $def['label'] ); ?></th>
                    <?php endforeach; ?>
                    <th scope="col" style="width:200px"><?php esc_html_e( 'Actions', 'lingua-forge' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $secondary_langs as $lang ) :
                // Pre-compute existence for every template type in this row so
                // we can decide whether "Create missing" is needed before rendering.
                $row_exists = [];
                foreach ( array_keys( $template_defs ) as $base ) {
                    $row_exists[ $base ] = $router->template_exists( $base . '-' . $lang );
                }
                $has_missing = in_array( false, $row_exists, true );
            ?>
                <tr class="lf-tpl-row" data-lang="<?php echo esc_attr( $lang ); ?>">
                    <td>
                        <span class="lf-lang-chip"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
                    </td>
                    <?php foreach ( $template_defs as $base => $def ) :
                        $slug               = $base . '-' . $lang;
                        $exists             = $row_exists[ $base ];
                        $already_translated = $exists && in_array( $slug, $translated_slugs, true );
                    ?>
                    <td class="lf-tpl-cell" data-base="<?php echo esc_attr( $base ); ?>">
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
                        <?php else : ?>
                            <button type="button"
                                    class="button button-small lf-scaffold-one-btn"
                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                    data-base="<?php echo esc_attr( $base ); ?>">
                                <?php esc_html_e( 'Create', 'lingua-forge' ); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="lf-tpl-actions">
                        <?php if ( $has_missing ) : ?>
                        <button type="button"
                                class="button lf-scaffold-all-btn"
                                data-lang="<?php echo esc_attr( $lang ); ?>">
                            <?php esc_html_e( 'Create missing', 'lingua-forge' ); ?>
                        </button>
                        <?php endif; ?>
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
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif;

        self::render_parts_section();
    }

    // ── Language Template Parts ───────────────────────────────────────────────

    /**
     * Check whether a template part slug exists on the active theme (file or DB).
     *
     * Template parts live in {theme}/parts/ rather than {theme}/templates/.
     *
     * @param string $slug Part slug, e.g. 'header', 'header-de'.
     */
    private static function part_exists( string $slug ): bool {
        // Filesystem — active (child) theme.
        if ( file_exists( get_stylesheet_directory() . '/parts/' . $slug . '.html' ) ) {
            return true;
        }
        // DB — wp_template_part posts for the active theme.
        $found = get_block_templates(
            [ 'slug__in' => [ $slug ], 'theme' => get_stylesheet() ],
            'wp_template_part'
        );
        return ! empty( $found );
    }

    /**
     * Recursive helper: collect all core/template-part slugs + areas from a
     * parsed block tree.
     *
     * @param array<int, array<string, mixed>> $blocks Output of parse_blocks().
     * @param array<string, string>            $parts  Accumulator: slug → area.
     */
    private static function collect_parts_from_blocks( array $blocks, array &$parts ): void {
        foreach ( $blocks as $block ) {
            if ( 'core/template-part' === ( $block['blockName'] ?? '' ) ) {
                $slug = (string) ( $block['attrs']['slug'] ?? '' );
                $area = (string) ( $block['attrs']['area'] ?? 'uncategorized' );
                if ( $slug !== '' && ! isset( $parts[ $slug ] ) ) {
                    $parts[ $slug ] = $area;
                }
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::collect_parts_from_blocks( $block['innerBlocks'], $parts );
            }
        }
    }

    /**
     * Discover which template parts are referenced by the theme's base templates.
     *
     * Two-pass approach:
     *   Pass 1 – collect every core/template-part slug found in the base
     *            template block markup (area attribute from the block is kept
     *            only as a last-resort fallback because many themes omit it).
     *   Pass 2 – resolve the canonical area from the wp_template_part object
     *            itself (read from the wp_template_part_area taxonomy), so that
     *            the Site Editor groups the scaffolded part correctly even when
     *            the block comment carries no area attribute.
     *
     * @param string $theme Active theme stylesheet (get_stylesheet()).
     * @return array<string, string> Slug → area map, sorted by slug.
     */
    private static function discover_template_parts( string $theme ): array {
        // Pass 1 — harvest slugs (and block-level area as a fallback).
        $raw = [];
        foreach ( array_keys( self::template_definitions() ) as $base_slug ) {
            $tpl = get_block_template( $theme . '//' . $base_slug );
            if ( $tpl && $tpl->content ) {
                self::collect_parts_from_blocks( parse_blocks( $tpl->content ), $raw );
            }
        }

        // Pass 2 — authoritative area from the template part object.
        $parts = [];
        foreach ( array_keys( $raw ) as $slug ) {
            $part           = get_block_template( $theme . '//' . $slug, 'wp_template_part' );
            $parts[ $slug ] = ( $part && $part->area ) ? $part->area : ( $raw[ $slug ] ?? 'uncategorized' );
        }

        ksort( $parts );
        return $parts;
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
    private static function replace_part_slug_in_blocks( array &$blocks, string $old_slug, string $new_slug ): bool {
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
     * Render the Language Template Parts scaffold section.
     *
     * Discovers template parts used by the active theme's base templates, then
     * renders a table with part slugs as rows and secondary languages as columns.
     * Each cell shows ✓ if the language-specific part already exists, or a
     * "Create" button otherwise. A "Create missing" button per row creates all
     * absent language variants of that part in one click.
     */
    private static function render_parts_section(): void {
        $router          = \LinguaForge\Router\Router::get_instance();
        $source_lang     = $router->source_language();
        $router_langs    = $router->languages();
        $secondary_langs = array_values( array_filter( $router_langs, fn( $l ) => $l !== $source_lang ) );

        if ( empty( $secondary_langs ) ) {
            return; // Notice already shown in the templates section above.
        }

        $theme            = get_stylesheet();
        $parts            = self::discover_template_parts( $theme );
        $ai_active        = self::ai_is_active();
        $translated_slugs = (array) get_option( 'linguaforge_fse_translated_slugs', [] );
        ?>

        <!-- ── Language Template Parts ──────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Language Template Parts', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Create language-specific FSE template parts (header, footer, navigation, etc.) for each secondary language. Parts are discovered from the base templates above. Once a localised part is scaffolded, any already-created language templates for that language are automatically updated to reference it instead of the base part.', 'lingua-forge' ); ?>
        </p>

        <?php if ( empty( $parts ) ) : ?>
            <p class="description">
                <?php esc_html_e( 'No template parts discovered in the active theme\'s base templates. Template parts appear in templates as core/template-part blocks (header, footer, navigation, etc.).', 'lingua-forge' ); ?>
            </p>
        <?php else : ?>

        <table class="widefat striped lf-template-scaffold-table">
            <thead>
                <tr>
                    <th scope="col" style="width:180px"><?php esc_html_e( 'Part', 'lingua-forge' ); ?></th>
                    <?php foreach ( $secondary_langs as $lang ) : ?>
                        <th scope="col">
                            <span class="lf-lang-chip"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
                        </th>
                    <?php endforeach; ?>
                    <th scope="col" style="width:200px"><?php esc_html_e( 'Actions', 'lingua-forge' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $parts as $part_slug => $area ) :
                // Pre-compute existence per language for this part row.
                $row_part_exists = [];
                foreach ( $secondary_langs as $lang ) {
                    $row_part_exists[ $lang ] = self::part_exists( $part_slug . '-' . $lang );
                }
                $has_missing_parts = in_array( false, $row_part_exists, true );
            ?>
                <tr class="lf-tpl-row" data-part="<?php echo esc_attr( $part_slug ); ?>">
                    <td>
                        <strong><?php echo esc_html( $part_slug ); ?></strong>
                        <span class="lf-area-badge lf-area-<?php echo esc_attr( $area ); ?>">
                            <?php echo esc_html( $area ); ?>
                        </span>
                    </td>
                    <?php foreach ( $secondary_langs as $lang ) :
                        $lang_slug          = $part_slug . '-' . $lang;
                        $exists             = $row_part_exists[ $lang ];
                        $already_translated = $exists && in_array( $lang_slug, $translated_slugs, true );
                    ?>
                    <td class="lf-tpl-cell" data-base="<?php echo esc_attr( $part_slug ); ?>">
                        <?php if ( $exists ) : ?>
                            <span class="lf-tpl-exists" title="<?php echo esc_attr( $lang_slug . '.html' ); ?>">✓</span>
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
                        <?php else : ?>
                            <button type="button"
                                    class="button button-small lf-scaffold-part-btn"
                                    data-lang="<?php echo esc_attr( $lang ); ?>"
                                    data-base="<?php echo esc_attr( $part_slug ); ?>">
                                <?php esc_html_e( 'Create', 'lingua-forge' ); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="lf-tpl-actions">
                        <?php if ( $has_missing_parts ) : ?>
                        <button type="button"
                                class="button lf-scaffold-all-parts-btn"
                                data-part="<?php echo esc_attr( $part_slug ); ?>">
                            <?php esc_html_e( 'Create missing', 'lingua-forge' ); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ( $ai_active ) : ?>
                        <button type="button"
                                class="button lf-translate-row-btn">
                            <?php esc_html_e( 'Translate all', 'lingua-forge' ); ?>
                        </button>
                        <?php endif; ?>
                        <button type="button"
                                class="button lf-fix-links-row-btn">
                            <?php esc_html_e( 'Fix all links', 'lingua-forge' ); ?>
                        </button>
                        <button type="button"
                                class="button lf-fix-nav-refs-row-btn">
                            <?php esc_html_e( 'Fix all nav refs', 'lingua-forge' ); ?>
                        </button>
                        <span class="lf-scaffold-row-msg"></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif;

        self::render_navigations_section();
    }

    // ── Language Navigations ──────────────────────────────────────────────────

    /**
     * Render the Language Navigations section.
     *
     * Lists every published wp_navigation post that is not itself a
     * language-specific copy (detected by the -{lang} suffix convention).
     * For each base navigation × secondary language, shows a ✓ if a
     * translated copy already exists or a Translate button if it does not.
     *
     * Translation creates a new wp_navigation post named {base}-{lang} with
     * AI-translated label values and language-prefixed internal URLs.
     */
    private static function render_navigations_section(): void {

        $router          = \LinguaForge\Router\Router::get_instance();
        $source_lang     = $router->source_language();
        $router_langs    = $router->languages();
        $secondary_langs = array_values( array_filter( $router_langs, fn( $l ) => $l !== $source_lang ) );

        if ( empty( $secondary_langs ) ) {
            return;
        }

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

        // Only show base navs — exclude any post whose post_name already ends
        // with a secondary-language suffix (those are the translated copies).
        $lang_suffixes = array_map( fn( $l ) => '-' . $l, $secondary_langs );
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

        $ai_active = self::ai_is_active();
        ?>

        <!-- ── Language Navigations ──────────────────────────────────────── -->
        <h2><?php esc_html_e( 'Language Navigations', 'lingua-forge' ); ?></h2>

        <p>
            <?php esc_html_e( 'Create language-specific copies of your navigation menus with translated labels and language-prefixed internal URLs. Translated menus are saved as new navigation posts following the {name}-{lang} convention (e.g. primary-navigation-de) and can be referenced from language-specific template parts.', 'lingua-forge' ); ?>
        </p>

        <div class="notice notice-warning inline">
            <p>
                <strong><?php esc_html_e( 'Known limitation — Page List block:', 'lingua-forge' ); ?></strong>
                <?php esc_html_e( 'If a language navigation uses the Page List block (WordPress\'s default before manual editing), it lists all pages regardless of language. This is a WordPress core limitation: the block has no filterable query hook. Workaround: open each language navigation in the Site Editor, click Edit to convert it to static links, then use Fix Links to correct the URLs. A proper fix is planned for a future release.', 'lingua-forge' ); ?>
            </p>
        </div>

        <?php if ( ! $ai_active ) : ?>
            <p class="description">
                <?php esc_html_e( 'Navigation translation requires an active AI provider. Configure an API key in the API Keys tab.', 'lingua-forge' ); ?>
            </p>
        <?php else : ?>

        <table class="widefat striped lf-template-scaffold-table">
            <thead>
                <tr>
                    <th scope="col" style="width:260px"><?php esc_html_e( 'Navigation', 'lingua-forge' ); ?></th>
                    <?php foreach ( $secondary_langs as $lang ) : ?>
                        <th scope="col">
                            <span class="lf-lang-chip"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $base_navs as $nav ) : ?>
                <tr class="lf-tpl-row">
                    <td>
                        <strong><?php echo esc_html( $nav->post_title ); ?></strong>
                        <code class="lf-nav-name"><?php echo esc_html( $nav->post_name ); ?></code>
                    </td>
                    <?php foreach ( $secondary_langs as $lang ) :
                        $lang_name = $nav->post_name . '-' . $lang;
                        $exists    = isset( $nav_by_name[ $lang_name ] );
                    ?>
                    <td class="lf-tpl-cell">
                        <?php if ( $exists ) : ?>
                            <span class="lf-tpl-exists" title="<?php echo esc_attr( $lang_name ); ?>">✓</span>
                        <?php endif; ?>
                        <button type="button"
                                class="button button-small lf-translate-nav-btn"
                                data-nav-id="<?php echo esc_attr( (string) $nav->ID ); ?>"
                                data-lang="<?php echo esc_attr( $lang ); ?>">
                            <?php echo $exists
                                ? esc_html__( 'Re-translate', 'lingua-forge' )
                                : esc_html__( 'Translate',    'lingua-forge' ); ?>
                        </button>
                    </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif;
    }

    // ── Pattern resolution ───────────────────────────────────────────────────

    /**
     * Expand every wp:pattern block reference inside a content string to its
     * registered markup, so the FSE translator sees actual translatable text
     * rather than a bare pattern pointer.
     *
     * Resolution order per slug:
     *   1. WP_Block_Patterns_Registry (theme / plugin PHP-registered patterns
     *      and theme patterns/ directory entries).
     *   2. wp_block synced-pattern posts (formerly "reusable blocks") — matched
     *      by post_name derived from the slug's tail segment.
     *
     * If a slug can't be resolved the original <!-- wp:pattern … /--> comment
     * is left untouched so the AI still sees a valid block structure.
     *
     * @param  string $content  Raw block markup that may contain wp:pattern refs.
     * @return string           Markup with pattern references expanded.
     */
    private static function expand_pattern_refs( string $content ): string {

        $registry = \WP_Block_Patterns_Registry::get_instance();

        $expanded = preg_replace_callback(
            '/<!--\s*wp:pattern\s+(\{[^}]+\})\s*\/-->/i',
            static function ( array $m ) use ( $registry ): string {

                $attrs = json_decode( $m[1], true );
                $slug  = isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';

                if ( $slug === '' ) {
                    return $m[0];
                }

                // 1 — PHP-registered / theme-directory pattern.
                $pattern = $registry->is_registered( $slug )
                    ? $registry->get_registered( $slug )
                    : null;

                if ( $pattern && ! empty( $pattern['content'] ) ) {
                    return (string) $pattern['content'];
                }

                // 2 — Synced pattern stored as wp_block post.
                // The post_name is the final path segment of the slug.
                $name = ltrim( (string) strrchr( $slug, '/' ), '/' );
                if ( $name !== '' ) {
                    $posts = get_posts( [
                        'post_type'      => 'wp_block',
                        'name'           => $name,
                        'posts_per_page' => 1,
                        'post_status'    => 'publish',
                    ] );
                    if ( ! empty( $posts ) ) {
                        return (string) $posts[0]->post_content;
                    }
                }

                return $m[0]; // Unresolvable — leave the reference intact.
            },
            $content
        );

        return is_string( $expanded ) ? $expanded : $content;
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * Save the primary language setting from the Router tab.
     */
    public static function handle_save_router_settings(): void {
        check_admin_referer( 'linguaforge_save_router_settings', 'linguaforge_router_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'lingua-forge' ), 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-] which is sufficient for a two-char language code.
        $lang = sanitize_key( wp_unslash( $_POST['linguaforge_primary_language'] ?? '' ) );
        // Only write the option when we actually received a non-empty value.
        // The old fallback to 'ca' would silently overwrite the stored language
        // if the POST field was somehow absent (e.g. the select had no options).
        if ( $lang !== '' ) {
            update_option( 'linguaforge_primary_language', $lang, false );
        }

        $mode = sanitize_key( wp_unslash( $_POST['linguaforge_routing_mode'] ?? 'path' ) );
        if ( in_array( $mode, [ 'path', 'subdomain' ], true ) ) {
            update_option( 'linguaforge_routing_mode', $mode, false );
        }

        update_option( 'lf_browser_redirect', ! empty( $_POST['lf_browser_redirect'] ), false );

        wp_safe_redirect( admin_url( 'options-general.php' ) . '?page=' . SettingsPage::PAGE_SLUG . '&lf_router_saved=1#router' );
        exit;
    }

    /**
     * Flush WordPress rewrite rules from the Router tab.
     */
    public static function handle_flush_permalinks(): void {
        check_admin_referer( 'linguaforge_flush_permalinks', 'linguaforge_flush_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'lingua-forge' ), 403 );
        }

        flush_rewrite_rules();

        wp_safe_redirect( admin_url( 'options-general.php' ) . '?page=' . SettingsPage::PAGE_SLUG . '&lf_permalinks_flushed=1#router' );
        exit;
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    /**
     * Return the list of WordPress.org translations not yet installed locally.
     *
     * Called via wp_ajax_linguaforge_get_available_languages.
     * Fetches from translate.wordpress.org; the result is cached in a transient
     * (~12 h) by wp_get_available_translations() so only the first call is slow.
     */
    public static function ajax_get_available_languages(): void {
        check_ajax_referer( 'linguaforge_get_available_languages', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        if ( ! function_exists( 'wp_get_available_translations' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $available  = wp_get_available_translations();
        $installed  = get_available_languages();

        // Build a set of installed two-char prefixes (e.g. 'de' from 'de_DE').
        $installed_codes = [];
        foreach ( $installed as $locale ) {
            $installed_codes[ $locale ] = true;
            // Also mark the two-char code so e.g. 'de_DE' suppresses 'de_DE' variants.
        }

        $options = [];
        foreach ( $available as $locale => $meta ) {
            if ( isset( $installed_codes[ $locale ] ) ) {
                continue; // already installed
            }
            $options[] = [
                'locale'       => esc_attr( $locale ),
                'english_name' => esc_html( $meta['english_name'] ?? $locale ),
                'native_name'  => esc_html( $meta['native_name']  ?? '' ),
            ];
        }

        // Sort by English name for readability.
        usort( $options, fn( $a, $b ) => strcmp( $a['english_name'], $b['english_name'] ) );

        wp_send_json_success( [ 'languages' => $options ] );
    }

    /**
     * Create a single language-specific FSE template by copying the base template.
     *
     * Called via wp_ajax_linguaforge_scaffold_template.
     * POST params:
     *   lang      – two-char language code (must be a non-primary active language).
     *   base_slug – base template slug; must be a key in template_definitions().
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

        // Validate base slug against the fixed allow-list.
        $defs    = self::template_definitions();
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
     *   base_slug – base template part slug; must be discovered via discover_template_parts().
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
        $parts = self::discover_template_parts( $theme );
        if ( ! array_key_exists( $base_slug, $parts ) ) {
            wp_send_json_error( __( 'Invalid template part.', 'lingua-forge' ) );
        }

        $lang_slug = $base_slug . '-' . $lang;

        // Bail if the part already exists (file or DB).
        if ( self::part_exists( $lang_slug ) ) {
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
        foreach ( array_keys( self::template_definitions() ) as $tpl_base ) {
            $tpl_slug = $tpl_base . '-' . $lang;
            $existing = get_block_templates(
                [ 'slug__in' => [ $tpl_slug ], 'theme' => $theme ],
                'wp_template'
            );
            if ( empty( $existing ) || ! $existing[0]->wp_id ) {
                continue;
            }
            $blocks = parse_blocks( (string) $existing[0]->content );
            if ( self::replace_part_slug_in_blocks( $blocks, $base_slug, $lang_slug ) ) {
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

        if ( ! self::ai_is_active() ) {
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
            $content = self::expand_pattern_refs( $content );
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
     * Rewrite internal links inside an FSE template or template part so they
     * carry the correct language URL prefix (e.g. /contact/ → /de/contact/).
     *
     * Called via wp_ajax_linguaforge_fix_fse_links.
     * POST params:
     *   slug      – full language-specific slug (e.g. 'page-de', 'header-de').
     *   post_type – 'wp_template' or 'wp_template_part'.
     *
     * Two patterns are rewritten in the raw block markup:
     *   1. href="https://site.com/path/"     — HTML anchor attributes.
     *   2. "url":"https://site.com/path/"    — block JSON attributes used by
     *                                          core/navigation-link and similar.
     *
     * URLs that already start with the language prefix are left untouched.
     * The target language is inferred from the slug suffix.
     */
    public static function ajax_fix_fse_links(): void {
        check_ajax_referer( 'linguaforge_fix_fse_links', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $slug      = sanitize_key( wp_unslash( $_POST['slug']      ?? '' ) );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-].
        $post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );

        // Infer the target language from the slug suffix (e.g. 'page-de' → 'de').
        // Computed before the post_type check so PHPStan can follow the control
        // flow without treating the subsequent if block as unreachable.
        $last_hyphen = strrpos( $slug, '-' );
        if ( $last_hyphen === false ) {
            wp_send_json_error( __( 'Cannot determine target language from slug.', 'lingua-forge' ) );
        }
        $lang = substr( $slug, $last_hyphen + 1 );

        if ( ! in_array( $post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
            wp_send_json_error( __( 'Invalid post type.', 'lingua-forge' ) );
        }

        $router      = \LinguaForge\Router\Router::get_instance();
        $source_lang = $router->source_language();

        if ( ! $router->is_valid_lang( $lang ) || $lang === $source_lang ) {
            wp_send_json_error( __( 'Invalid or primary language.', 'lingua-forge' ) );
        }

        // Fetch the template / part — must be DB-stored (wp_id set) to be writable.
        $theme    = get_stylesheet();
        $existing = get_block_templates( [ 'slug__in' => [ $slug ], 'theme' => $theme ], $post_type );
        if ( empty( $existing ) || ! $existing[0]->wp_id ) {
            wp_send_json_error( __( 'Template not found or not stored in the database.', 'lingua-forge' ) );
        }

        $post_id = (int) $existing[0]->wp_id;
        $content = (string) $existing[0]->content;

        if ( trim( $content ) === '' ) {
            wp_send_json_error( __( 'Template has no content.', 'lingua-forge' ) );
        }

        $count  = 0;
        $home   = untrailingslashit( home_url() );
        $prefix = '/' . $lang . '/';

        // Pattern 1 — href="https://site.com/path/" in HTML block markup.
        $content = preg_replace_callback(
            '/\bhref=(["\'])(' . preg_quote( $home, '/' ) . ')(\/[^"\']*?)(\1)/i',
            static function ( array $m ) use ( $lang, $prefix, &$count ): string {
                $path = $m[3]; // e.g. /contact/ or /
                // Already carries the language prefix — skip.
                if ( str_starts_with( ltrim( $path, '/' ), $lang . '/' ) || ltrim( $path, '/' ) === $lang ) {
                    return $m[0];
                }
                $count++;
                return 'href=' . $m[1] . $m[2] . $prefix . ltrim( $path, '/' ) . $m[1];
            },
            $content
        );

        // Pattern 2 — "url":"https://site.com/path/" in block JSON attributes
        // (core/navigation-link, core/button, etc.).
        $content = preg_replace_callback(
            '/"url":"(' . preg_quote( $home, '/' ) . ')(\/[^"]*?)"/i',
            static function ( array $m ) use ( $lang, $prefix, &$count ): string {
                $path = $m[2]; // e.g. /contact/
                if ( str_starts_with( ltrim( $path, '/' ), $lang . '/' ) || ltrim( $path, '/' ) === $lang ) {
                    return $m[0];
                }
                $count++;
                return '"url":"' . $m[1] . $prefix . ltrim( $path, '/' ) . '"';
            },
            $content
        );

        // ── Save prefix-rewritten content if any changes were made ───────────
        if ( $count > 0 ) {
            $result = wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $content,
            ], true );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
        }

        // ── Stale-path pass ───────────────────────────────────────────────────
        // Fix links that already carry the correct language prefix but whose
        // path has changed — e.g. a page was moved or its slug was updated after
        // the template part was last saved. LinkFixer::fix_post() uses data-id
        // as ground truth: if get_permalink(data-id) no longer matches the
        // stored href the link is stale and gets rewritten. Works for any post
        // type, including wp_template_part.
        $stale = $router->link_fixer->fix_post( $post_id, $lang );
        $total = $count + ( $stale['applied'] ?? 0 );

        if ( $total === 0 ) {
            wp_send_json_success( [
                'slug'    => $slug,
                'count'   => 0,
                'message' => __( 'No internal links found to update.', 'lingua-forge' ),
            ] );
        }

        wp_send_json_success( [
            'slug'  => $slug,
            'count' => $total,
            /* translators: %d: number of links rewritten */
            'message' => sprintf( _n( '%d link updated.', '%d links updated.', $total, 'lingua-forge' ), $total ),
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

        if ( ! self::ai_is_active() ) {
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
            $result = wp_update_post( [
                'ID'           => $existing[0]->ID,
                'post_content' => $final,
            ], true );
        } else {
            $result = wp_insert_post( [
                'post_name'    => $lang_post_name,
                'post_title'   => $source_nav->post_title . ' ' . strtoupper( $lang ),
                'post_content' => $final,
                'post_status'  => 'publish',
                'post_type'    => 'wp_navigation',
            ], true );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [
            'nav_id'   => $nav_id,
            'lang'     => $lang,
            'new_id'   => (int) $result,
            'nav_name' => $lang_post_name,
            'message'  => sprintf(
                /* translators: 1: navigation title e.g. "Primary Navigation", 2: language code e.g. "DE" */
                __( '"%1$s %2$s" saved.', 'lingua-forge' ),
                esc_html( $source_nav->post_title ),
                strtoupper( $lang )
            ),
        ] );
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

        $content = self::expand_pattern_refs( $content );

        // Use get_block_templates() to enumerate every template part registered
        // for this theme, then build a map of base-slug → lang-slug for parts
        // whose language variant already exists.
        $all_parts   = get_block_templates( [ 'theme' => $theme ], 'wp_template_part' );
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
        $replaced = 0;
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

        $content = self::expand_pattern_refs( $content );

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

    /**
     * Download and install a WordPress core language pack.
     *
     * Called via wp_ajax_linguaforge_install_language.
     * Uses wp_download_language_pack() — requires file modifications to be
     * allowed (DISALLOW_FILE_MODS must not be set).
     */
    public static function ajax_install_language(): void {
        check_ajax_referer( 'linguaforge_install_language', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'lingua-forge' ) );
        }

        if ( ! wp_is_file_mod_allowed( 'download_language_pack' ) ) {
            wp_send_json_error( __( 'Language installation is disabled on this server (DISALLOW_FILE_MODS is set).', 'lingua-forge' ) );
        }

        $locale = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );
        if ( ! $locale || ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2,4})?$/', $locale ) ) {
            wp_send_json_error( __( 'Invalid locale code.', 'lingua-forge' ) );
        }

        if ( ! function_exists( 'wp_download_language_pack' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }
        if ( ! class_exists( 'Language_Pack_Upgrader' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        ob_start();
        $result = wp_download_language_pack( $locale );
        ob_end_clean();

        if ( $result ) {
            wp_send_json_success( [
                'locale'  => $result,
                /* translators: %s: locale code such as de_DE */
                'message' => sprintf( __( 'Language %s installed successfully.', 'lingua-forge' ), esc_html( $result ) ),
            ] );
        } else {
            wp_send_json_error( __( 'Language pack installation failed. The language may already be installed, the locale code may be incorrect, or your server may block file writes.', 'lingua-forge' ) );
        }
    }
}
