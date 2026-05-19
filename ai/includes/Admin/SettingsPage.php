<?php

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Core\TranslationMemory;
use LinguaForge\AI\Core\UsageRecorder;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Providers\Anthropic;
use LinguaForge\AI\Providers\OpenAI;
use LinguaForge\AI\Providers\Gemini;
use LinguaForge\AI\Providers\WorkerConfig;

defined('ABSPATH') || exit;

/**
 * Settings → Lingua Forge AI
 *
 * Provides a standard WordPress settings page where administrators can:
 *   - Choose the active AI provider (Anthropic / OpenAI / Gemini)
 *   - Enter and store API keys (AES-256 encrypted via KeyStore)
 *   - Override the model string for the Light and Quality tiers of each provider
 *   - See where each key is currently sourced from
 *   - Remove a stored database key
 *
 * Keys entered here are encrypted before being saved to wp_options.
 * If a key is already configured via an env var or a wp-config.php constant,
 * that source takes lower priority than the database — but it is shown to
 * the administrator so they know a fallback is active.
 *
 * Model overrides are stored as plain text option values under
 * linguaforge_model_{provider}_{tier}.  Leaving a field blank resets it to
 * the built-in default (shown as placeholder text).
 */
class SettingsPage {

    private const PAGE_SLUG    = 'lingua-forge';
    private const NONCE_ACTION = 'linguaforge_save_settings';
    private const NONCE_FIELD  = 'linguaforge_nonce';
    private const OPT_PROVIDER = 'linguaforge_provider';

    /**
     * Provider slugs → human labels.
     * Defined as a method (not a constant) so labels can be wrapped with __().
     *
     * @return array<string, string>
     */
    private static function providers(): array {

        return [
            'anthropic' => __( 'Anthropic (Claude)', 'lingua-forge' ),
            'openai'    => __( 'OpenAI (GPT)',        'lingua-forge' ),
            'gemini'    => __( 'Google (Gemini)',     'lingua-forge' ),
        ];
    }

    /**
     * Model tiers: slug → label and description shown in the settings table.
     * Defined as a method (not a constant) so strings can be wrapped with __().
     *
     * @return array<string, array{label: string, used_by: string}>
     */
    private static function tiers(): array {

        return [
            'light' => [
                'label'   => __( 'Light',                               'lingua-forge' ),
                'used_by' => __( 'Meta Description, Excerpt Generator', 'lingua-forge' ),
            ],
            'quality' => [
                'label'   => __( 'Quality',                        'lingua-forge' ),
                'used_by' => __( 'Translation, Content Generator', 'lingua-forge' ),
            ],
        ];
    }

    /**
     * Whitelisted capability choices for the "Minimum role" Settings field.
     *
     * Maps the WP capability string (passed to current_user_can) to a
     * human-readable label. The capability column is what gets stored;
     * the label is only used to render the dropdown.
     */
    /**
     * Render the Glossary tab — filter dropdown + entries list + add form.
     *
     * Read-write panel with two admin-post forms (add / delete). Filter by
     * language pair via GET params source_lang / target_lang.
     */
    private static function render_glossary_tab(): void {

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET filter; no data is modified.
        $filter_source = sanitize_key( wp_unslash( $_GET['glossary_source'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET filter; no data is modified.
        $filter_target = sanitize_key( wp_unslash( $_GET['glossary_target'] ?? '' ) );

        $criteria = [];
        if ( $filter_source !== '' ) $criteria['source_lang'] = $filter_source;
        if ( $filter_target !== '' ) $criteria['target_lang'] = $filter_target;

        $entries  = Glossary::get_all( $criteria );
        $base_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

        // Available languages for the dropdowns — only the languages the
        // router actively knows about (installed locale packs + primary
        // language). Filtered through Translation::get_languages() for
        // proper English labels; any router code not in that map falls back
        // to its uppercase ISO code.
        $all_labels   = Translation::get_languages();
        $router_codes = \LinguaForge\Router\Router::get_instance()->languages();
        $languages    = [];
        foreach ( $router_codes as $code ) {
            $languages[ $code ] = $all_labels[ $code ] ?? strtoupper( $code );
        }
        asort( $languages );

        ?>
        <h2><?php esc_html_e( 'Glossary', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'User-managed terminology table per language pair. Entries are appended to the translation system prompt so the AI uses preferred terms consistently — critical for domain vocabulary ("kWp", "PPA", "interconnection point"), brand names that must not translate, and standardised regulatory phrasing. Both language fields are optional: leave "Source language" blank to apply the entry regardless of which language you are translating from; leave "Target language" blank to apply it to all target languages at once.',
                'lingua-forge'
            );
            ?>
        </p>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect after add/delete.
        if ( isset( $_GET['lf_glossary_added'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Glossary entry added.', 'lingua-forge' ); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( isset( $_GET['lf_glossary_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Glossary entry removed.', 'lingua-forge' ); ?></p>
            </div>
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        elseif ( isset( $_GET['lf_glossary_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
                    $code = sanitize_key( wp_unslash( $_GET['lf_glossary_error'] ?? '' ) );
                    if ( $code === 'missing_fields' ) {
                        esc_html_e( 'Could not add entry: source term and target term are required.', 'lingua-forge' );
                    } else {
                        esc_html_e( 'Could not save the glossary entry.', 'lingua-forge' );
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- ── Filter form (GET) ─────────────────────────────────────── -->
        <form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="lingua-forge-glossary-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

            <label for="lf_glossary_filter_source">
                <?php esc_html_e( 'Source language', 'lingua-forge' ); ?>
            </label>
            <select id="lf_glossary_filter_source" name="glossary_source">
                <option value=""><?php esc_html_e( '— Any —', 'lingua-forge' ); ?></option>
                <?php foreach ( $languages as $code => $label ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filter_source, $code ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="lf_glossary_filter_target">
                <?php esc_html_e( 'Target language', 'lingua-forge' ); ?>
            </label>
            <select id="lf_glossary_filter_target" name="glossary_target">
                <option value=""><?php esc_html_e( '— Any target —', 'lingua-forge' ); ?></option>
                <?php foreach ( $languages as $code => $label ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filter_target, $code ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'lingua-forge' ); ?></button>
            <a class="button-link" href="<?php echo esc_url( $base_url ); ?>#glossary"><?php esc_html_e( 'Reset', 'lingua-forge' ); ?></a>
        </form>

        <!-- ── Entries table ─────────────────────────────────────────── -->
        <?php if ( empty( $entries ) ) : ?>
            <div class="lingua-forge-settings-note">
                <p>
                    <?php
                    if ( $filter_source !== '' || $filter_target !== '' ) {
                        esc_html_e( 'No glossary entries match the current filter. Clear the filter to see all entries, or add a new entry below.', 'lingua-forge' );
                    } else {
                        esc_html_e( 'No glossary entries yet. Add one below to start enforcing terminology in translations.', 'lingua-forge' );
                    }
                    ?>
                </p>
            </div>
        <?php else : ?>
            <table class="widefat striped lingua-forge-glossary-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Source term', 'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Target term', 'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Source lang', 'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Target lang', 'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Notes',       'lingua-forge' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $entry['source_term'] ); ?></strong></td>
                            <td><strong><?php echo esc_html( $entry['target_term'] ); ?></strong></td>
                            <td>
                                <?php
                                if ( $entry['source_lang'] === '' ) {
                                    echo '<em>' . esc_html__( 'any', 'lingua-forge' ) . '</em>';
                                } else {
                                    echo '<code>' . esc_html( $entry['source_lang'] ) . '</code>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ( $entry['target_lang'] === '' ) {
                                    echo '<em>' . esc_html__( 'any', 'lingua-forge' ) . '</em>';
                                } else {
                                    echo '<code>' . esc_html( $entry['target_lang'] ) . '</code>';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $entry['notes'] ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                                    <input type="hidden" name="action"   value="linguaforge_glossary_delete">
                                    <input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry['id'] ); ?>">
                                    <?php wp_nonce_field( 'linguaforge_glossary_delete', 'linguaforge_glossary_nonce' ); ?>
                                    <button
                                        type="submit"
                                        class="button button-link-delete"
                                        onclick="return confirm('<?php echo esc_js( __( 'Delete this glossary entry?', 'lingua-forge' ) ); ?>');"
                                    >
                                        <?php esc_html_e( 'Delete', 'lingua-forge' ); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- ── Add-new form ──────────────────────────────────────────── -->
        <h3><?php esc_html_e( 'Add new entry', 'lingua-forge' ); ?></h3>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lingua-forge-glossary-add">
            <input type="hidden" name="action" value="linguaforge_glossary_add">
            <?php wp_nonce_field( 'linguaforge_glossary_add', 'linguaforge_glossary_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="lf_g_source_term"><?php esc_html_e( 'Source term', 'lingua-forge' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="lf_g_source_term" name="source_term" class="regular-text" maxlength="255" required>
                        <p class="description"><?php esc_html_e( 'The phrase as it appears in the source text.', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lf_g_target_term"><?php esc_html_e( 'Target term', 'lingua-forge' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="lf_g_target_term" name="target_term" class="regular-text" maxlength="255" required>
                        <p class="description"><?php esc_html_e( 'How it should appear in the translation. Type the same value as the source term to instruct the AI to preserve it verbatim.', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lf_g_source_lang"><?php esc_html_e( 'Source language', 'lingua-forge' ); ?></label>
                    </th>
                    <td>
                        <select id="lf_g_source_lang" name="source_lang">
                            <option value=""><?php esc_html_e( '— Any source language —', 'lingua-forge' ); ?></option>
                            <?php foreach ( $languages as $code => $label ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Leave blank for brand names and language-agnostic terms that should be enforced regardless of which language we are translating from.', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lf_g_target_lang"><?php esc_html_e( 'Target language', 'lingua-forge' ); ?></label>
                    </th>
                    <td>
                        <select id="lf_g_target_lang" name="target_lang">
                            <option value=""><?php esc_html_e( '— Any target language —', 'lingua-forge' ); ?></option>
                            <?php foreach ( $languages as $code => $label ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Leave blank to apply this entry to all target languages — ideal for brand names, abbreviations, and terms that must be preserved verbatim in every translation.', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lf_g_notes"><?php esc_html_e( 'Notes', 'lingua-forge' ); ?></label>
                    </th>
                    <td>
                        <textarea id="lf_g_notes" name="notes" rows="2" class="large-text" maxlength="500"></textarea>
                        <p class="description"><?php esc_html_e( 'Optional context for editors (e.g. "ASIC = Application-Specific Integrated Circuit; preserve in English in technical contexts").', 'lingua-forge' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Add entry', 'lingua-forge' ), 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    /**
     * Date-range choices for the AI Usage tab.
     *
     * Maps the GET-param value to a label and to a "since" date (UTC) used by
     * UsageRecorder::query(). All-time is represented by an empty since.
     */
    private static function usage_ranges(): array {

        return [
            'today' => [
                'label' => __( 'Today', 'lingua-forge' ),
                'since' => gmdate( 'Y-m-d' ),
            ],
            '7' => [
                'label' => __( 'Last 7 days', 'lingua-forge' ),
                'since' => gmdate( 'Y-m-d', strtotime( '-6 days' ) ),
            ],
            '30' => [
                'label' => __( 'Last 30 days', 'lingua-forge' ),
                'since' => gmdate( 'Y-m-d', strtotime( '-29 days' ) ),
            ],
            'all' => [
                'label' => __( 'All time', 'lingua-forge' ),
                'since' => '',
            ],
        ];
    }

    /**
     * Render the AI Usage tab — date-range buttons + summary table.
     *
     * Read-only; no form submission. Date range is driven by the `range` GET
     * param so each button is a regular link (bookmarkable and back/forward
     * friendly). Default range is 30 days.
     */
    private static function render_ai_usage_tab(): void {

        $ranges = self::usage_ranges();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param controlling the displayed date range; no data is modified.
        $active_range = sanitize_key( wp_unslash( $_GET['range'] ?? '30' ) );
        if ( ! array_key_exists( $active_range, $ranges ) ) {
            $active_range = '30';
        }

        $criteria = [];
        if ( $ranges[ $active_range ]['since'] !== '' ) {
            $criteria['since'] = $ranges[ $active_range ]['since'];
        }

        $rows         = UsageRecorder::query( $criteria );
        $total_inputs = array_sum( array_column( $rows, 'input_tokens' ) );
        $total_outpts = array_sum( array_column( $rows, 'output_tokens' ) );
        $total_total  = array_sum( array_column( $rows, 'total_tokens' ) );
        $total_reqs   = array_sum( array_column( $rows, 'request_count' ) );

        $base_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

        ?>
        <h2><?php esc_html_e( 'AI Usage', 'lingua-forge' ); ?></h2>

        <p>
            <?php
            esc_html_e(
                'Token consumption rolled up by feature, provider, and model. Recorded on every successful AI call; Test Connection pings are deliberately excluded so they don\'t skew the totals. The underlying table aggregates daily — pick a window below to view it.',
                'lingua-forge'
            );
            ?>
        </p>

        <p class="lingua-forge-range-buttons">
            <?php foreach ( $ranges as $range_key => $range ) :
                $href      = add_query_arg( 'range', $range_key, $base_url ) . '#ai-usage';
                $is_active = ( $range_key === $active_range );
                ?>
                <a
                    href="<?php echo esc_url( $href ); ?>"
                    class="button <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?>"
                ><?php echo esc_html( $range['label'] ); ?></a>
            <?php endforeach; ?>
        </p>

        <?php if ( UsageRecorder::row_count() === 0 ) : ?>

            <div class="lingua-forge-settings-note">
                <p>
                    <?php
                    esc_html_e(
                        'No AI usage recorded yet. Once an editor runs a translation, generates a meta description, or uses any other AI feature, totals will appear here.',
                        'lingua-forge'
                    );
                    ?>
                </p>
            </div>

        <?php elseif ( empty( $rows ) ) : ?>

            <div class="lingua-forge-settings-note">
                <p>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %s is a date-range label like "Today" or "Last 7 days". */
                        __( 'No AI usage recorded in the selected window (%s). Pick a wider range above.', 'lingua-forge' ),
                        $ranges[ $active_range ]['label']
                    ) );
                    ?>
                </p>
            </div>

        <?php else : ?>

            <table class="widefat striped lingua-forge-usage-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Feature',  'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Provider', 'lingua-forge' ); ?></th>
                        <th><?php esc_html_e( 'Model',    'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Requests', 'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Input tokens',  'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Output tokens', 'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php esc_html_e( 'Total tokens',  'lingua-forge' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $row['feature_key'] ); ?></code></td>
                            <td><?php echo esc_html( $row['provider'] ); ?></td>
                            <td><code><?php echo esc_html( $row['model'] ); ?></code></td>
                            <td class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $row['request_count'] ) ); ?></td>
                            <td class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $row['input_tokens']  ) ); ?></td>
                            <td class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $row['output_tokens'] ) ); ?></td>
                            <td class="lingua-forge-num"><strong><?php echo esc_html( number_format_i18n( $row['total_tokens'] ) ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3"><?php esc_html_e( 'Total', 'lingua-forge' ); ?></th>
                        <th class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $total_reqs   ) ); ?></th>
                        <th class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $total_inputs ) ); ?></th>
                        <th class="lingua-forge-num"><?php echo esc_html( number_format_i18n( $total_outpts ) ); ?></th>
                        <th class="lingua-forge-num"><strong><?php echo esc_html( number_format_i18n( $total_total ) ); ?></strong></th>
                    </tr>
                </tfoot>
            </table>

        <?php endif; ?>
        <?php
    }

    private static function capability_choices(): array {

        return [
            'edit_published_posts' => __( 'Authors and above (edit_published_posts)', 'lingua-forge' ),
            'edit_posts'           => __( 'Contributors and above — default (edit_posts)', 'lingua-forge' ),
            'edit_others_posts'    => __( 'Editors and above (edit_others_posts)', 'lingua-forge' ),
            'manage_options'       => __( 'Administrators only (manage_options)', 'lingua-forge' ),
        ];
    }

    // ── Initialisation ────────────────────────────────────────────────────────

    public static function init(): void {

        add_action('admin_menu',                    [self::class, 'register_menu']);
        add_action('admin_post_' . self::PAGE_SLUG, [self::class, 'handle_save']);

        // Language override file management
        add_action('admin_post_linguaforge_upload_i18n_override', [self::class, 'handle_upload_override']);
        add_action('admin_post_linguaforge_delete_i18n_override', [self::class, 'handle_delete_override']);

        // AI cache maintenance
        add_action('admin_post_linguaforge_clear_ai_cache',    [self::class, 'handle_clear_ai_cache']);
        add_action('admin_post_linguaforge_clear_debug_files', [self::class, 'handle_clear_debug_files']);
        add_action('admin_post_linguaforge_save_debug_setting', [self::class, 'handle_save_debug_setting']);

        // Glossary management (§4.6)
        add_action('admin_post_linguaforge_glossary_add',    [self::class, 'handle_glossary_add']);
        add_action('admin_post_linguaforge_glossary_delete', [self::class, 'handle_glossary_delete']);

        // Translation Memory maintenance (§4.5)
        add_action('admin_post_linguaforge_clear_translation_memory', [self::class, 'handle_clear_translation_memory']);

        // Language Router tab
        add_action('admin_post_linguaforge_save_router_settings',     [self::class, 'handle_save_router_settings']);
        add_action('admin_post_linguaforge_flush_permalinks',          [self::class, 'handle_flush_permalinks']);
        add_action('wp_ajax_linguaforge_get_available_languages',      [self::class, 'ajax_get_available_languages']);
        add_action('wp_ajax_linguaforge_install_language',             [self::class, 'ajax_install_language']);

        // Test-connection AJAX endpoint — scoped to logged-in admins via the
        // capability check inside the handler.
        add_action('wp_ajax_linguaforge_test_provider', [self::class, 'ajax_test_provider']);

        // Settings-screen-only asset enqueue for the Test Connection JS.
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_settings_assets']);
    }

    /**
     * Enqueue the small JS file that powers the Test Connection buttons.
     *
     * Scoped to the Settings → Lingua Forge AI screen only (matched via the
     * $hook_suffix WordPress hands to admin_enqueue_scripts).
     */
    public static function enqueue_settings_assets(string $hook_suffix): void {

        // Hook suffix for an options-page screen registered via add_options_page
        // is "settings_page_{slug}".
        if ($hook_suffix !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        $version = defined('LINGUAFORGE_VERSION') ? LINGUAFORGE_VERSION : false;

        wp_enqueue_script(
            'linguaforge-settings-tabs',
            LINGUAFORGE_AI_URL . '/assets/settings-tabs.js',
            [],
            $version,
            true
        );

        wp_enqueue_script(
            'linguaforge-test-connection',
            LINGUAFORGE_AI_URL . '/assets/test-connection.js',
            ['wp-i18n'],
            $version,
            true
        );

        wp_localize_script('linguaforge-test-connection', 'linguaForgeTestConnection', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('linguaforge_test_provider'),
            'strings'   => [
                'testing'    => __( 'Testing…',          'lingua-forge' ),
                'ok'         => __( '✓ Connection OK',   'lingua-forge' ),
                'fail'       => __( '✗ Failed:',         'lingua-forge' ),
                'noResponse' => __( 'No response from provider — check the error log for details.', 'lingua-forge' ),
                'network'    => __( 'Network error — could not reach the WordPress AJAX endpoint.',  'lingua-forge' ),
            ],
        ]);

        // Router tab — language fetch + install JS.
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version supplied via $version.
        wp_register_script( 'linguaforge-router-tab', false, ['jquery'], $version, true );
        wp_enqueue_script( 'linguaforge-router-tab' );
        wp_add_inline_script(
            'linguaforge-router-tab',
            'var lfRouterTab = ' . wp_json_encode( [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'fetchNonce'   => wp_create_nonce( 'linguaforge_get_available_languages' ),
                'installNonce' => wp_create_nonce( 'linguaforge_install_language' ),
                'strings'      => [
                    'loading'           => __( 'Loading…',                'lingua-forge' ),
                    'installing'        => __( 'Installing…',             'lingua-forge' ),
                    'installed'         => __( '✓ Language installed.',   'lingua-forge' ),
                    'error'             => __( '✗ Error:',                'lingua-forge' ),
                    'selectPlaceholder' => __( '— select a language —',   'lingua-forge' ),
                    'noModify'          => __( 'Language installation is disabled on this server (DISALLOW_FILE_MODS is set).', 'lingua-forge' ),
                ],
            ] ) . ';',
            'before'
        );
        wp_add_inline_script( 'linguaforge-router-tab', self::router_tab_js() );

        // Preset preview — shows each preset's built-in addendum text when the
        // Global AI Preset dropdown changes, so editors can see what the preset
        // does and learn the format for writing their own custom instructions.
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version supplied via $version.
        wp_register_script( 'linguaforge-preset-preview', false, [], $version, true );
        wp_enqueue_script( 'linguaforge-preset-preview' );

        $preset_addenda = [];
        foreach ( Config::presets() as $key => $meta ) {
            $preset_addenda[ $key ] = $meta['addendum'];
        }

        wp_add_inline_script(
            'linguaforge-preset-preview',
            'var lfPresetData = ' . wp_json_encode( [
                'presets' => $preset_addenda,
                'strings' => [
                    'label'          => __( 'Built-in preset instructions:', 'lingua-forge' ),
                    'noInstructions' => __( 'No built-in instructions — each AI feature uses its own tuned defaults. Fill in the Custom prompt instructions field below to add your own site-wide rules.', 'lingua-forge' ),
                ],
            ] ) . ';',
            'before'
        );
        wp_add_inline_script( 'linguaforge-preset-preview', self::preset_preview_js() );

        // Settings page styles — registered as a dummy handle so wp_add_inline_style
        // can attach the CSS without requiring a separate external file.
        wp_register_style( 'linguaforge-settings', false, [], $version ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version supplied via $version.
        wp_enqueue_style( 'linguaforge-settings' );
        wp_add_inline_style( 'linguaforge-settings', self::settings_page_css() );
    }

    /**
     * CSS for the settings page — tabs, glossary table, usage table, key badges,
     * model table, and the API key security note.
     *
     * Returned as a string and attached via wp_add_inline_style() in
     * enqueue_settings_assets() rather than output as a raw <style> tag.
     */
    private static function settings_page_css(): string {
        return '
            /* ── Tab panels ─────────────────────────────────────────────── */
            .lingua-forge-tabs { margin-bottom: 1.2em; }
            .lingua-forge-tab-panel { display: none; }
            .lingua-forge-tab-panel.is-active { display: block; }

            /* ── Glossary ──────────────────────────────────────────────── */
            .lingua-forge-glossary-filter {
                display: flex; align-items: center; gap: 8px;
                margin: 12px 0 18px; flex-wrap: wrap;
            }
            .lingua-forge-glossary-filter label { font-weight: 600; }
            .lingua-forge-glossary-table th,
            .lingua-forge-glossary-table td { vertical-align: middle; }
            .lingua-forge-glossary-table code { font-size: 12px; }

            /* ── AI Usage ───────────────────────────────────────────────── */
            .lingua-forge-range-buttons .button { margin-right: 4px; }
            .lingua-forge-usage-table .lingua-forge-num {
                text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;
            }
            .lingua-forge-usage-table tfoot th { background: #f6f7f7; }

            /* ── Key status badges ─────────────────────────────────────── */
            .lingua-forge-key-badge {
                display: inline-block; margin-left: 8px;
                font-size: 12px; font-weight: 600; vertical-align: middle;
            }
            .lingua-forge-badge--ok      { color: #46b450; }
            .lingua-forge-badge--missing { color: #dc3232; }
            .lingua-forge-key-source { font-weight: 400; color: #646970; }

            /* ── Test Connection inline result ────────────────────────── */
            .lingua-forge-test-key  { margin-left: 8px; vertical-align: middle; }
            .lingua-forge-test-result {
                margin-left: 6px; font-size: 12px; font-weight: 600; vertical-align: middle;
            }
            .lingua-forge-test-result--pending { color: #646970; font-weight: 400; }
            .lingua-forge-test-result--ok      { color: #46b450; }
            .lingua-forge-test-result--fail    { color: #dc3232; }

            /* ── Models table ──────────────────────────────────────────── */
            .lingua-forge-models-table { border-collapse: collapse; width: 100%; max-width: 860px; }
            .lingua-forge-models-table thead th {
                padding: 8px 10px; text-align: left; font-weight: 600;
                border-bottom: 2px solid #dcdcde; vertical-align: bottom;
            }
            .lingua-forge-models-table tbody tr th,
            .lingua-forge-models-table tbody tr td {
                padding: 10px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: middle;
            }
            .lingua-forge-active-provider-row { background: #f0f6fc; }
            .lingua-forge-active-provider-row th { font-weight: 600; }
            .lingua-forge-active-badge {
                display: inline-block; margin-left: 6px; padding: 1px 7px;
                border-radius: 10px; background: #0073aa; color: #fff;
                font-size: 11px; font-weight: 600; letter-spacing: 0.03em; vertical-align: middle;
            }
            .lingua-forge-tier-used-by {
                display: block; font-size: 11px; font-weight: 400;
                color: #646970; margin-top: 2px;
            }
            .lingua-forge-model-input {
                font-family: monospace; font-size: 12px; width: 100%; max-width: 340px;
            }
            .lingua-forge-model-override-badge {
                display: inline-block; margin-left: 6px; padding: 1px 6px;
                border-radius: 3px; background: #fff8e5; color: #996800;
                border: 1px solid #f0c33c; font-size: 11px; font-weight: 600; vertical-align: middle;
            }

            /* ── Security note ─────────────────────────────────────────── */
            .lingua-forge-settings-note {
                background: #f6f7f7; border-left: 4px solid #c3c4c7;
                padding: 12px 16px; margin: 20px 0; max-width: 600px;
            }
            .lingua-forge-settings-note p { margin: 6px 0; }
            .lingua-forge-code-sample {
                background: #fff; border: 1px solid #dcdcde;
                padding: 8px 12px; font-size: 12px; margin: 6px 0 10px; overflow-x: auto;
            }

            /* ── Router tab: language installer ───────────────────────── */
            .lf-installed-langs {
                display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 20px;
            }
            .lf-installed-langs .lf-lang-chip {
                display: inline-block; padding: 2px 10px; border-radius: 10px;
                background: #e7f3ff; color: #0073aa; font-size: 12px;
                font-weight: 600; font-family: monospace; border: 1px solid #b9d9f0;
            }
            #lf-lang-install-select {
                min-width: 260px; max-width: 380px;
            }
            #lf-lang-install-result {
                margin-left: 10px; font-size: 12px; font-weight: 600;
                vertical-align: middle;
            }
            #lf-lang-install-result.lf-ok   { color: #46b450; }
            #lf-lang-install-result.lf-fail { color: #dc3232; }

            /* ── Preset preview panel ──────────────────────────────────────── */
            .lf-preset-preview {
                margin-top: 10px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-left: 3px solid #2271b1;
                border-radius: 2px;
                padding: 10px 14px;
                max-width: 600px;
            }
            .lf-preset-preview .lf-preset-preview-label {
                margin: 0 0 6px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: #646970;
            }
            .lf-preset-preview .lf-preset-preview-text {
                margin: 0;
                font-family: Consolas, "Courier New", monospace;
                font-size: 12px;
                line-height: 1.6;
                color: #3c434a;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
        ';
    }

    // ── i18n overrides directory ──────────────────────────────────────────────

    /**
     * Absolute path to the uploads-based i18n overrides directory.
     * Matches the path used by \LinguaForge\Router\Router::i18n_overrides_dir()
     * (aliased as Language_Router for back-compat).
     *
     * @return string  Trailing-slash path.
     */
    private static function overrides_dir(): string {

        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides/';
    }

    public static function register_menu(): void {

        add_options_page(
            'Lingua Forge AI',
            'Lingua Forge AI',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    // ── Form handler ──────────────────────────────────────────────────────────

    public static function handle_save(): void {

        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have permission to manage these settings.', 'lingua-forge'),
                403
            );
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        // ── Provider ──────────────────────────────────────────────────────────
        $provider = sanitize_key($_POST[self::OPT_PROVIDER] ?? '');

        if (array_key_exists($provider, self::providers())) {
            update_option(self::OPT_PROVIDER, $provider, false);
        }

        // ── API keys — save if non-empty, remove if checkbox checked ──────────
        foreach (array_keys(self::providers()) as $slug) {

            // Explicit removal takes precedence over a new value.
            if (!empty($_POST["linguaforge_remove_{$slug}"])) {
                KeyStore::delete($slug);
                continue;
            }

            $new_key = sanitize_text_field(
                wp_unslash( $_POST["linguaforge_key_{$slug}"] ?? '' )
            );

            if ($new_key !== '') {
                KeyStore::set($slug, $new_key);
            }
            // If the field was left blank, the existing key is preserved.
        }

        // ── AI Limits & Security ──────────────────────────────────────────────
        // Daily quota: 0 (or empty) = unlimited. Negative values are clamped.
        $daily_quota = intval( wp_unslash( $_POST['linguaforge_ai_daily_quota'] ?? 0 ) );
        update_option(
            'linguaforge_ai_daily_quota',
            max(0, $daily_quota),
            false
        );

        // Required capability: must be one of the whitelisted choices.
        // Defending against arbitrary capability strings here so a misclick
        // in the dropdown can't lock everyone out via an unknown cap name.
        $cap = sanitize_key( wp_unslash( $_POST['linguaforge_required_capability'] ?? 'edit_posts' ) );
        $allowed_caps = array_keys(self::capability_choices());
        if (!in_array($cap, $allowed_caps, true)) {
            $cap = 'edit_posts';
        }
        update_option('linguaforge_required_capability', $cap, false);

        // ── Behavior — Block Editor restrictions (§2.7) ───────────────────────
        // Checkboxes: absent in $_POST = unchecked = restriction stays ON.
        update_option(
            'linguaforge_block_editor_allow_lock_blocks',
            !empty($_POST['linguaforge_block_editor_allow_lock_blocks']) ? 1 : 0,
            false
        );
        update_option(
            'linguaforge_block_editor_allow_template_mode',
            !empty($_POST['linguaforge_block_editor_allow_template_mode']) ? 1 : 0,
            false
        );

        // ── Behavior — AI preset (replaces old compliance toggle) ────────────
        $preset_raw   = sanitize_key($_POST['linguaforge_active_preset'] ?? '');
        $valid_presets = array_keys(\LinguaForge\AI\Core\Config::presets());
        update_option(
            'linguaforge_active_preset',
            in_array($preset_raw, $valid_presets, true) ? $preset_raw : 'standard',
            false
        );

        // Addendum: free-form override appended to the active preset's system
        // prompt. Empty string means "use the preset's built-in default".
        $compliance_addendum = sanitize_textarea_field(
            (string) wp_unslash($_POST['linguaforge_compliance_addendum'] ?? '')
        );
        update_option('linguaforge_compliance_addendum', $compliance_addendum, false);

        // ── Behavior — Translation Memory (§4.5) ─────────────────────────────
        update_option(
            'linguaforge_translation_memory_enabled',
            !empty($_POST['linguaforge_translation_memory_enabled']) ? 1 : 0,
            false
        );

        // ── Model overrides ───────────────────────────────────────────────────
        // Store whatever the admin submitted (even empty string).
        // Config::model() treats an empty stored value as "use built-in default",
        // so clearing a field in the form is how you reset to the default.
        foreach (array_keys(self::providers()) as $slug) {
            foreach (array_keys(self::tiers()) as $tier) {

                $option_key  = "linguaforge_model_{$slug}_{$tier}";
                $model_value = sanitize_text_field(
                    wp_unslash( $_POST[$option_key] ?? '' )
                );

                // Allow saving an empty string to reset to the built-in default.
                update_option($option_key, $model_value, false);
            }
        }

        // ── Translation limits ────────────────────────────────────────────────
        // Store as integers; 0 / empty means "use built-in default".

        $translation_tier = sanitize_key($_POST['linguaforge_translation_tier'] ?? '');
        update_option(
            'linguaforge_translation_tier',
            in_array($translation_tier, ['light', 'quality'], true) ? $translation_tier : '',
            false
        );

        $max_tokens = intval( wp_unslash( $_POST['linguaforge_translation_max_tokens'] ?? 0 ) );
        update_option(
            'linguaforge_translation_max_tokens',
            $max_tokens > 0 ? $max_tokens : '',
            false
        );

        $max_input = intval( wp_unslash( $_POST['linguaforge_translation_max_input_chars'] ?? 0 ) );
        // 0 is a valid value here (means no limit), so store whatever was submitted.
        update_option(
            'linguaforge_translation_max_input_chars',
            max(0, $max_input),
            false
        );

        // ── Quick Translation limits ──────────────────────────────────────────

        $qt_tier = sanitize_key($_POST['linguaforge_quick_translate_tier'] ?? '');
        update_option(
            'linguaforge_quick_translate_tier',
            in_array($qt_tier, ['light', 'quality'], true) ? $qt_tier : '',
            false
        );

        $qt_tokens = intval( wp_unslash( $_POST['linguaforge_quick_translate_max_tokens'] ?? 0 ) );
        update_option(
            'linguaforge_quick_translate_max_tokens',
            $qt_tokens > 0 ? $qt_tokens : '',
            false
        );

        $qt_input = intval( wp_unslash( $_POST['linguaforge_quick_translate_max_input_chars'] ?? 0 ) );
        update_option(
            'linguaforge_quick_translate_max_input_chars',
            $qt_input > 0 ? $qt_input : '',
            false
        );

        // ── Content Generator limits ──────────────────────────────────────────

        $cg_tokens = intval( wp_unslash( $_POST['linguaforge_content_generator_max_tokens'] ?? 0 ) );
        update_option(
            'linguaforge_content_generator_max_tokens',
            $cg_tokens > 0 ? $cg_tokens : '',
            false
        );

        $cg_hints = intval( wp_unslash( $_POST['linguaforge_content_generator_max_hints_chars'] ?? 0 ) );
        update_option(
            'linguaforge_content_generator_max_hints_chars',
            $cg_hints > 0 ? $cg_hints : '',
            false
        );

        $cg_context = intval( wp_unslash( $_POST['linguaforge_content_generator_max_context_chars'] ?? 0 ) );
        update_option(
            'linguaforge_content_generator_max_context_chars',
            $cg_context > 0 ? $cg_context : '',
            false
        );

        wp_safe_redirect(
            add_query_arg(
                'linguaforge_saved',
                '1',
                admin_url('options-general.php?page=' . self::PAGE_SLUG)
            )
        );
        exit;
    }

    // ── Language override handlers ────────────────────────────────────────────

    public static function handle_upload_override(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_upload_override', 'linguaforge_override_nonce');

        $redirect_base = admin_url('options-general.php?page=' . self::PAGE_SLUG);

        // No file submitted
        if (empty($_FILES['linguaforge_mo_file']['name'])) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'empty', $redirect_base));
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES array is passed directly to wp_handle_upload() which performs its own validation.
        $file = $_FILES['linguaforge_mo_file'];

        // Validate extension — only .mo files are loaded at runtime
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'mo') {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_type', $redirect_base));
            exit;
        }

        // Validate upload integrity
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'upload_error', $redirect_base));
            exit;
        }

        $dir = self::overrides_dir();

        wp_mkdir_p($dir);

        // Redirect wp_handle_upload() to our override directory and preserve the
        // exact filename so the {textdomain}-{locale}.mo convention is maintained.
        $upload_dir_cb = static function ( $dirs ) use ( $dir ) {
            $dirs['path']   = untrailingslashit( $dir );
            $dirs['url']    = '';
            $dirs['subdir'] = '';
            return $dirs;
        };

        add_filter( 'upload_dir', $upload_dir_cb );

        $uploaded = wp_handle_upload(
            $file,
            [
                'test_form'                => false, // nonce already verified via check_admin_referer
                'test_type'                => false, // extension already validated above
                'unique_filename_callback' => static fn( $d, $n, $e ) => $n, // keep exact name
            ]
        );

        remove_filter( 'upload_dir', $upload_dir_cb );

        if ( isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'move_failed', $redirect_base));
            exit;
        }

        // A new .mo override may introduce a previously-unknown locale to
        // Language_Router::languages(), which in turn feeds the rewrite rule
        // set built on init. Mark the rules dirty so the init-priority-99 hook
        // in lingua-forge.php picks the flush up on the next request — without
        // this the new /xx/ URLs return 404 until Settings → Permalinks → Save.
        update_option( 'linguaforge_flush_rewrite_rules', true );

        wp_safe_redirect(add_query_arg('lf_override_uploaded', '1', $redirect_base));
        exit;
    }

    public static function handle_delete_override(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_delete_override', 'linguaforge_override_nonce');

        $redirect_base = admin_url('options-general.php?page=' . self::PAGE_SLUG);

        $filename = sanitize_file_name( wp_unslash( $_POST['linguaforge_override_file'] ?? '' ) );

        // Validate: must be a .mo filename, no path separators
        if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_file', $redirect_base));
            exit;
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'mo') {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_type', $redirect_base));
            exit;
        }

        $dir     = self::overrides_dir();
        $real_dir = realpath($dir);

        if ($real_dir === false) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_path', $redirect_base));
            exit;
        }

        // Delete both .mo and .po for the given base name.
        $base      = pathinfo($filename, PATHINFO_FILENAME); // strip extension
        $deleted   = false;

        foreach (['mo', 'po'] as $ext) {
            $filepath  = $dir . $base . '.' . $ext;
            $real_file = realpath($filepath);

            // Path-traversal guard: resolved path must still be inside the overrides dir
            if ($real_file === false || strpos($real_file, $real_dir . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }

            wp_delete_file($filepath);
            $deleted = true;
        }

        if (!$deleted) {
            wp_safe_redirect(add_query_arg('lf_override_error', 'invalid_path', $redirect_base));
            exit;
        }

        // Deleting the last .mo for a discovered-only locale removes it from
        // Language_Router::languages(), so the rewrite-rule set must rebuild.
        // Same mechanism as on upload — defer to init-priority-99 in lingua-forge.php.
        update_option( 'linguaforge_flush_rewrite_rules', true );

        wp_safe_redirect(add_query_arg('lf_override_deleted', '1', $redirect_base));
        exit;
    }

    // ── AI cache maintenance ──────────────────────────────────────────────────

    /**
     * Empty the wp_lingua_forge_ai_cache table (and any leftover pre-1.4
     * post-meta cache rows) when an admin clicks the "Clear AI cache" button.
     *
     * The wipe is cheap — all cached entries regenerate on next use via a
     * fresh API call. Useful when admins want to reclaim DB space, force a
     * resync after switching providers or changing prompts, or troubleshoot
     * a cache-related bug.
     */
    public static function handle_clear_ai_cache(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_clear_ai_cache', 'linguaforge_clear_ai_cache_nonce');

        $count = CacheStore::clear_all();

        wp_safe_redirect(add_query_arg(
            'lf_cache_cleared',
            (int) $count,
            admin_url('options-general.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Empty the debug-file directory when an admin clicks the
     * "Clear debug files" button in Maintenance → Debug Files.
     *
     * Configuration of WHERE the files live remains the filter's job
     * (`linguaforge_debug_dir`); this handler only operates on the resolved
     * directory and deletes every *.txt found there.
     */
    public static function handle_clear_debug_files(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_clear_debug_files', 'linguaforge_clear_debug_files_nonce');

        $count = Translation::clear_debug_files();

        wp_safe_redirect(add_query_arg(
            'lf_debug_cleared',
            (int) $count,
            admin_url('options-general.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Persist the on/off state of the Settings → Maintenance debug toggle.
     *
     * Only writes the option. If the LINGUAFORGE_AI_DEBUG constant is defined
     * in wp-config.php, Translation::debug_enabled() still resolves to the
     * constant value regardless of what the option says — so this handler
     * doesn't fight the constant, it just records the admin's preference for
     * sites that don't use the constant.
     */
    /**
     * Empty the Translation Memory table from the Maintenance tab.
     */
    public static function handle_clear_translation_memory(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_clear_translation_memory', 'linguaforge_clear_tm_nonce' );

        $count = TranslationMemory::clear_all();

        wp_safe_redirect( add_query_arg(
            'lf_tm_cleared',
            (int) $count,
            admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
        ) . '#maintenance' );
        exit;
    }

    // ── Language Router tab handlers ──────────────────────────────────────────

    /**
     * Save the primary language setting from the Router tab.
     */
    public static function handle_save_router_settings(): void {
        check_admin_referer( 'linguaforge_save_router_settings', 'linguaforge_router_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'lingua-forge' ), 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() normalises to [a-z0-9_-] which is sufficient for a two-char language code.
        $lang = sanitize_key( wp_unslash( $_POST['linguaforge_primary_language'] ?? 'ca' ) );
        update_option( 'linguaforge_primary_language', $lang ?: 'ca', false );

        update_option( 'lf_browser_redirect', ! empty( $_POST['lf_browser_redirect'] ), false );

        wp_safe_redirect( admin_url( 'options-general.php' ) . '?page=' . self::PAGE_SLUG . '&lf_router_saved=1#router' );
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

        wp_safe_redirect( admin_url( 'options-general.php' ) . '?page=' . self::PAGE_SLUG . '&lf_permalinks_flushed=1#router' );
        exit;
    }

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

    /**
     * Render the Router settings tab.
     *
     * Sections:
     *   1. Primary Language — sets linguaforge_primary_language option.
     *   2. Flush Permalinks — calls flush_rewrite_rules().
     *   3. Active Languages — read-only list of installed locales.
     *   4. Install Language — AJAX-driven install of additional WP core language packs.
     */
    private static function render_router_tab(): void {

        $router          = \LinguaForge\Router\Router::get_instance();
        $primary_stored  = (string) get_option( 'linguaforge_primary_language', 'ca' );
        $router_langs    = $router->languages();
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
            <?php esc_html_e( 'The primary language is served at the root of your site (no URL prefix) and uses the default WordPress FSE templates (page, single, etc.). All other languages get a /lang/ URL prefix and are expected to use language-specific templates such as page-de or single-de.', 'lingua-forge' ); ?>
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

        <?php
    }

    /**
     * Inline JS for the Global AI Preset preview panel.
     * Reads lfPresetData (injected before this script) and updates a read-only
     * <pre> block below the preset dropdown whenever the selection changes,
     * showing the preset's built-in system-prompt addendum so editors can see
     * what each preset does and learn the format for custom instructions.
     */
    private static function preset_preview_js(): string {
        return <<<'JS'
(function () {
    var select = document.getElementById('linguaforge_active_preset');
    var wrap   = document.getElementById('lf-preset-preview');
    if (!select || !wrap || typeof lfPresetData === 'undefined') return;

    var label = wrap.querySelector('.lf-preset-preview-label');
    var pre   = wrap.querySelector('.lf-preset-preview-text');

    function update() {
        var key      = select.value;
        var addendum = (lfPresetData.presets[key] || '').trim();
        if (addendum) {
            label.textContent = lfPresetData.strings.label;
            pre.textContent   = addendum;
        } else {
            label.textContent = '';
            pre.textContent   = lfPresetData.strings.noInstructions;
        }
        wrap.hidden = false;
    }

    select.addEventListener('change', update);
    update();
}());
JS;
    }

    /**
     * Inline JS for the Router tab — language list fetch and install interactions.
     * Returned as a string and attached via wp_add_inline_script().
     */
    private static function router_tab_js(): string {
        return <<<'JS'
(function ($) {
    'use strict';

    var L          = window.lfRouterTab || {};
    var ajaxUrl    = L.ajaxUrl    || '';
    var fetchNonce = L.fetchNonce || '';
    var instNonce  = L.installNonce || '';
    var s          = L.strings   || {};

    var $loadBtn   = $('#lf-load-langs-btn');
    var $row       = $('#lf-lang-install-row');
    var $select    = $('#lf-lang-install-select');
    var $installBtn = $('#lf-install-lang-btn');
    var $result    = $('#lf-lang-install-result');

    if (!$loadBtn.length) return;

    $loadBtn.on('click', function () {
        $loadBtn.prop('disabled', true).text(s.loading || 'Loading…');
        $.post(ajaxUrl, {
            action: 'linguaforge_get_available_languages',
            nonce:  fetchNonce
        }, function (resp) {
            $loadBtn.hide();
            if (!resp.success || !resp.data.languages.length) {
                $result.addClass('lf-fail').text('Could not load language list.');
                $row.show();
                return;
            }
            resp.data.languages.forEach(function (lang) {
                var label = lang.english_name + (lang.native_name && lang.native_name !== lang.english_name ? ' — ' + lang.native_name : '') + ' (' + lang.locale + ')';
                $select.append($('<option>', { value: lang.locale, text: label }));
            });
            $select.prop('disabled', false);
            $installBtn.prop('disabled', false);
            $row.show();
        }).fail(function () {
            $loadBtn.prop('disabled', false).text('Load available languages');
            $result.addClass('lf-fail').text('Network error. Please try again.');
            $row.show();
        });
    });

    $installBtn.on('click', function () {
        var locale = $select.val();
        if (!locale) return;
        $installBtn.prop('disabled', true).text(s.installing || 'Installing…');
        $result.removeClass('lf-ok lf-fail').text('');
        $.post(ajaxUrl, {
            action: 'linguaforge_install_language',
            nonce:  instNonce,
            locale: locale
        }, function (resp) {
            $installBtn.prop('disabled', false).text('Install');
            if (resp.success) {
                $result.addClass('lf-ok').text(resp.data.message || s.installed);
                // Remove the installed locale from the dropdown.
                $select.find('option[value="' + resp.data.locale + '"]').remove();
                $select.val('');
            } else {
                $result.addClass('lf-fail').text((s.error || '✗') + ' ' + (resp.data || 'Unknown error'));
            }
        }).fail(function () {
            $installBtn.prop('disabled', false).text('Install');
            $result.addClass('lf-fail').text('Network error. Please try again.');
        });
    });

}(jQuery));
JS;
    }

    /**
     * Add a new glossary entry.
     *
     * Source term and target term are required. Both language fields are
     * optional: empty source_lang = "any source", empty target_lang = "any
     * target". Inserts via Glossary::insert(), redirects back to the
     * Glossary tab with a feedback query arg. Cap-protected + nonce-verified.
     */
    public static function handle_glossary_add(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_glossary_add', 'linguaforge_glossary_nonce' );

        $source_term = trim( sanitize_text_field( wp_unslash( $_POST['source_term'] ?? '' ) ) );
        $target_term = trim( sanitize_text_field( wp_unslash( $_POST['target_term'] ?? '' ) ) );
        $source_lang = sanitize_key( wp_unslash( $_POST['source_lang'] ?? '' ) );
        $target_lang = sanitize_key( wp_unslash( $_POST['target_lang'] ?? '' ) );
        $notes       = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        $base = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

        if ( $source_term === '' || $target_term === '' ) {
            wp_safe_redirect( add_query_arg( 'lf_glossary_error', 'missing_fields', $base ) . '#glossary' );
            exit;
        }

        $id = Glossary::insert( $source_term, $target_term, $source_lang, $target_lang, $notes );

        if ( $id <= 0 ) {
            wp_safe_redirect( add_query_arg( 'lf_glossary_error', 'insert_failed', $base ) . '#glossary' );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'lf_glossary_added', '1', $base ) . '#glossary' );
        exit;
    }

    /**
     * Delete a glossary entry by ID.
     */
    public static function handle_glossary_delete(): void {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
        }

        check_admin_referer( 'linguaforge_glossary_delete', 'linguaforge_glossary_nonce' );

        $entry_id = absint( $_POST['entry_id'] ?? 0 );

        if ( $entry_id > 0 ) {
            Glossary::delete( $entry_id );
        }

        wp_safe_redirect( add_query_arg(
            'lf_glossary_deleted',
            '1',
            admin_url( 'options-general.php?page=' . self::PAGE_SLUG )
        ) . '#glossary' );
        exit;
    }

    public static function handle_save_debug_setting(): void {

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'lingua-forge'), 403);
        }

        check_admin_referer('linguaforge_save_debug_setting', 'linguaforge_save_debug_setting_nonce');

        $enabled = !empty($_POST['linguaforge_ai_debug_enabled']);

        update_option('linguaforge_ai_debug_enabled', $enabled ? 1 : 0, false);

        wp_safe_redirect(add_query_arg(
            'lf_debug_setting_saved',
            $enabled ? '1' : '0',
            admin_url('options-general.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }

    // ── Test Connection (AJAX) ───────────────────────────────────────────────

    /**
     * Run a minimal "ping" chat call against a single provider and report
     * back as JSON. Wired to wp_ajax_linguaforge_test_provider.
     *
     * Why per-provider rather than always-active-provider: admins frequently
     * configure multiple keys and want to validate each one independently
     * before flipping the active provider in Settings → Active Provider.
     *
     * Response payload (always JSON, status 200 so the JS client can read it):
     *   {
     *     success: bool,
     *     provider: 'anthropic'|'openai'|'gemini',
     *     message?: string,  // present on failure
     *     reply?:  string,   // present on success (truncated provider text)
     *   }
     */
    public static function ajax_test_provider(): void {

        if (!current_user_can('manage_options')) {
            wp_send_json([
                'success' => false,
                'message' => __('Permission denied.', 'lingua-forge'),
            ]);
        }

        check_ajax_referer('linguaforge_test_provider', 'nonce');

        $provider_slug = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $providers     = self::providers();

        if (!array_key_exists($provider_slug, $providers)) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Unknown provider.', 'lingua-forge'),
            ]);
        }

        if (!KeyStore::get($provider_slug)) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('No API key configured for this provider.', 'lingua-forge'),
            ]);
        }

        // Build a low-cost WorkerConfig for the ping — light tier, tight token
        // budget so an accidental verbose model can't run up much cost.
        // Config::model() always uses the active provider, so we resolve the
        // light-tier model for the requested provider here: stored override
        // first, fall back to the hard-coded default.
        $model_option = (string) get_option("linguaforge_model_{$provider_slug}_light", '');
        $model        = $model_option !== ''
            ? $model_option
            : Config::default_model($provider_slug, 'light');

        $config = new WorkerConfig(
            model:       $model,
            max_tokens:  16,
            temperature: 0.0,
        );

        $provider_instance = match ($provider_slug) {
            'anthropic' => new Anthropic($config),
            'openai'    => new OpenAI($config),
            'gemini'    => new Gemini($config),
            default     => null,
        };

        if ($provider_instance === null) {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Could not instantiate the provider.', 'lingua-forge'),
            ]);
        }

        $reply = $provider_instance->chat([
            [
                'role'    => 'user',
                'content' => 'Reply with the single word: ping',
            ],
        ]);

        if ($reply === null || $reply === '') {
            wp_send_json([
                'success'  => false,
                'provider' => $provider_slug,
                'message'  => __('Provider returned no text. Check the WordPress error log for the detailed failure reason.', 'lingua-forge'),
            ]);
        }

        wp_send_json([
            'success'  => true,
            'provider' => $provider_slug,
            'reply'    => mb_substr((string) $reply, 0, 200),
        ]);
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render(): void {

        if (!current_user_can('manage_options')) {
            return;
        }

        $saved_provider  = (string) get_option(self::OPT_PROVIDER, '');
        $active_provider = $saved_provider !== ''
            ? $saved_provider
            : (defined('LINGUAFORGE_PROVIDER') ? LINGUAFORGE_PROVIDER : 'anthropic');

        ?>
        <div class="wrap">

            <h1><?php esc_html_e('Lingua Forge AI — Settings', 'lingua-forge'); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after a successful save; no data is processed here.
            if (!empty($_GET['linguaforge_saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'lingua-forge'); ?></p>
                </div>
            <?php endif; ?>

            <!-- ── Tab navigation ──────────────────────────────────────── -->
            <h2 class="nav-tab-wrapper lingua-forge-tabs" role="tablist">
                <a href="#general"     class="nav-tab nav-tab-active" data-lf-tab="general"><?php     esc_html_e('General',     'lingua-forge'); ?></a>
                <a href="#api-keys"    class="nav-tab"                data-lf-tab="api-keys"><?php    esc_html_e('API Keys',    'lingua-forge'); ?></a>
                <a href="#limits"      class="nav-tab"                data-lf-tab="limits"><?php      esc_html_e('Limits',      'lingua-forge'); ?></a>
                <a href="#behavior"    class="nav-tab"                data-lf-tab="behavior"><?php    esc_html_e('Behavior',    'lingua-forge'); ?></a>
                <a href="#router"      class="nav-tab"                data-lf-tab="router"><?php      esc_html_e('Router',      'lingua-forge'); ?></a>
                <a href="#glossary"    class="nav-tab"                data-lf-tab="glossary"><?php    esc_html_e('Glossary',    'lingua-forge'); ?></a>
                <a href="#ai-usage"    class="nav-tab"                data-lf-tab="ai-usage"><?php    esc_html_e('AI Usage',    'lingua-forge'); ?></a>
                <a href="#maintenance" class="nav-tab"                data-lf-tab="maintenance"><?php esc_html_e('Maintenance', 'lingua-forge'); ?></a>
            </h2>

            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            >
                <input
                    type="hidden"
                    name="action"
                    value="<?php echo esc_attr(self::PAGE_SLUG); ?>"
                >

                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

                <!-- ───── Tab: General ───── -->
                <div class="lingua-forge-tab-panel is-active" data-lf-panel="general">

                <!-- ── Provider ──────────────────────────────────────────── -->
                <h2><?php esc_html_e('Active Provider', 'lingua-forge'); ?></h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="linguaforge_provider">
                                <?php esc_html_e('Provider', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <select
                                name="<?php echo esc_attr(self::OPT_PROVIDER); ?>"
                                id="linguaforge_provider"
                            >
                                <?php foreach (self::providers() as $slug => $label): ?>
                                    <option
                                        value="<?php echo esc_attr($slug); ?>"
                                        <?php selected($active_provider, $slug); ?>
                                    >
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if ($saved_provider === '' && defined('LINGUAFORGE_PROVIDER')): ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s is the PHP constant name LINGUAFORGE_PROVIDER, wrapped in <code> tags. */
                                        esc_html__(
                                            'Currently inherited from the %s constant. Selecting a value here will override it.',
                                            'lingua-forge'
                                        ),
                                        '<code>LINGUAFORGE_PROVIDER</code>'
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <!-- ── Models ────────────────────────────────────────────── -->
                <h2><?php esc_html_e('Models', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e(
                        'Features are grouped into two tiers. Enter the exact model identifier for each tier and provider. Leave a field blank to use the built-in default (shown as placeholder). Only the active provider\'s models are called at runtime — configure the others in advance if you plan to switch.',
                        'lingua-forge'
                    );
                    ?>
                </p>

                <table class="form-table lingua-forge-models-table" role="presentation">

                    <thead>
                        <tr>
                            <th><?php esc_html_e('Provider', 'lingua-forge'); ?></th>
                            <?php foreach (self::tiers() as $tier_slug => $tier): ?>
                                <th>
                                    <?php echo esc_html($tier['label']); ?>
                                    <span class="lingua-forge-tier-used-by">
                                        <?php echo esc_html($tier['used_by']); ?>
                                    </span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach (self::providers() as $slug => $label): ?>

                        <?php $is_active = ($slug === $active_provider); ?>

                        <tr class="<?php echo $is_active ? 'lingua-forge-active-provider-row' : ''; ?>">
                            <th scope="row">
                                <?php echo esc_html($label); ?>
                                <?php if ($is_active): ?>
                                    <span class="lingua-forge-active-badge">
                                        <?php esc_html_e('active', 'lingua-forge'); ?>
                                    </span>
                                <?php endif; ?>
                            </th>

                            <?php foreach (self::tiers() as $tier_slug => $tier): ?>

                                <?php
                                $option_key    = "linguaforge_model_{$slug}_{$tier_slug}";
                                $stored_model  = (string) get_option($option_key, '');
                                $default_model = Config::default_model($slug, $tier_slug);
                                $input_id      = "linguaforge_model_{$slug}_{$tier_slug}";
                                ?>

                                <td>
                                    <input
                                        type="text"
                                        id="<?php echo esc_attr($input_id); ?>"
                                        name="<?php echo esc_attr($option_key); ?>"
                                        class="regular-text lingua-forge-model-input"
                                        value="<?php echo esc_attr($stored_model); ?>"
                                        placeholder="<?php echo esc_attr($default_model); ?>"
                                        spellcheck="false"
                                        autocomplete="off"
                                    >
                                    <?php if ($stored_model !== ''): ?>
                                        <span class="lingua-forge-model-override-badge">
                                            <?php esc_html_e('overridden', 'lingua-forge'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                            <?php endforeach; ?>
                        </tr>

                    <?php endforeach; ?>
                    </tbody>

                </table>

                <p class="description">
                    <?php
                    esc_html_e('Tip: to reset a model to the built-in default, clear the field and save.', 'lingua-forge');
                    ?>
                </p>

                </div><!-- /lingua-forge-tab-panel: general -->

                <!-- ───── Tab: API Keys ───── -->
                <div class="lingua-forge-tab-panel" data-lf-panel="api-keys">

                <!-- ── API Keys ──────────────────────────────────────────── -->
                <h2><?php esc_html_e('API Keys', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e(
                        'Keys are encrypted with AES-256-CBC before being stored in the WordPress database. The encryption secret is derived from your WordPress auth salts (wp-config.php), so plaintext keys never touch the database.',
                        'lingua-forge'
                    );
                    ?>
                </p>

                <table class="form-table" role="presentation">

                    <?php foreach (self::providers() as $slug => $label): ?>

                        <?php
                        $source     = KeyStore::source($slug);
                        $configured = $source !== null;
                        ?>

                        <tr>
                            <th scope="row">
                                <label for="linguaforge_key_<?php echo esc_attr($slug); ?>">
                                    <?php echo esc_html($label); ?>
                                    <?php esc_html_e('API Key', 'lingua-forge'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="password"
                                    id="linguaforge_key_<?php echo esc_attr($slug); ?>"
                                    name="linguaforge_key_<?php echo esc_attr($slug); ?>"
                                    class="regular-text"
                                    autocomplete="new-password"
                                    placeholder="<?php
                                        echo $configured
                                            ? esc_attr( '••••••••••••••••' )
                                            : esc_attr( __( 'Paste your API key…', 'lingua-forge' ) );
                                    ?>"
                                >

                                <span class="lingua-forge-key-badge <?php
                                    echo $configured ? 'lingua-forge-badge--ok' : 'lingua-forge-badge--missing';
                                ?>">
                                    <?php if ($configured): ?>
                                        <?php esc_html_e( '✓ Configured', 'lingua-forge' ); ?>
                                        <span class="lingua-forge-key-source">
                                            (<?php echo esc_html($source); ?>)
                                        </span>
                                    <?php else: ?>
                                        <?php esc_html_e('✗ Not configured', 'lingua-forge'); ?>
                                    <?php endif; ?>
                                </span>

                                <?php if ($configured): ?>
                                    <button
                                        type="button"
                                        class="button button-secondary lingua-forge-test-key"
                                        data-provider="<?php echo esc_attr($slug); ?>"
                                    >
                                        <?php esc_html_e( 'Test connection', 'lingua-forge' ); ?>
                                    </button>
                                    <span
                                        class="lingua-forge-test-result"
                                        data-for="<?php echo esc_attr($slug); ?>"
                                        aria-live="polite"
                                    ></span>
                                <?php endif; ?>

                                <p class="description">
                                    <?php
                                    esc_html_e(
                                        'Leave blank to keep the existing key. Enter a new value to replace it.',
                                        'lingua-forge'
                                    );
                                    ?>
                                </p>

                                <?php if ($source === 'database'): ?>
                                    <p>
                                        <label>
                                            <input
                                                type="checkbox"
                                                name="linguaforge_remove_<?php echo esc_attr($slug); ?>"
                                                value="1"
                                            >
                                            <?php esc_html_e('Remove stored key', 'lingua-forge'); ?>
                                        </label>
                                    </p>
                                <?php elseif ($source === 'environment' || $source === 'constant'): ?>
                                    <p class="description">
                                        <?php
                                        printf(
                                            /* translators: %s is the key source: either "environment variable" or "PHP constant". */
                                            esc_html__(
                                                'This key is currently supplied by a server %s and cannot be removed here. Enter a new key above to override it with a database value.',
                                                'lingua-forge'
                                            ),
                                            $source === 'environment'
                                                ? esc_html__('environment variable', 'lingua-forge')
                                                : esc_html__('PHP constant', 'lingua-forge')
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>

                            </td>
                        </tr>

                    <?php endforeach; ?>

                </table>

                <!-- ── Server-side key sources ──────────────────────── -->
                <div class="lingua-forge-settings-note">
                    <p>
                        <strong><?php esc_html_e('Alternative (server-side):', 'lingua-forge'); ?></strong>
                        <?php
                        esc_html_e( 'You can also define keys as constants or environment variables (e.g. in wp-config.php). Those sources are used automatically as a fallback when no database key is stored.', 'lingua-forge' );
                        ?>
                    </p>
                    <pre class="lingua-forge-code-sample">define( 'ANTHROPIC_API_KEY', 'sk-ant-…' );
define( 'OPENAI_API_KEY',    'sk-…' );</pre>
                    <p>
                        <?php
                        esc_html_e(
                            'To use a custom encryption secret (instead of the derived wp_salt value), add this to wp-config.php:',
                            'lingua-forge'
                        );
                        ?>
                    </p>
                    <pre class="lingua-forge-code-sample">define( 'LINGUAFORGE_SECRET', 'your-random-secret' );</pre>
                </div>

                </div><!-- /lingua-forge-tab-panel: api-keys -->

                <!-- ───── Tab: Limits ───── -->
                <div class="lingua-forge-tab-panel" data-lf-panel="limits">

                <!-- ── AI Limits & Security ─────────────────────────────── -->
                <h2><?php esc_html_e('AI Limits & Security', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Cap how much AI usage the site can generate and restrict which user roles may trigger paid AI calls. Sits on top of the per-user rate limit (30 requests / minute, hardcoded) that already protects against single-user runaway loops.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_ai_daily_quota">
                                <?php esc_html_e( 'Daily request limit', 'lingua-forge' ); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_ai_daily_quota"
                                name="linguaforge_ai_daily_quota"
                                value="<?php echo esc_attr( (string) (int) get_option( 'linguaforge_ai_daily_quota', 0 ) ); ?>"
                                min="0"
                                step="1"
                                class="small-text"
                            >
                            <p class="description">
                                <?php
                                esc_html_e( 'Site-wide ceiling on AI requests per UTC day (counts both Toolbar translations and block revisions). Counter resets at UTC midnight. Set to 0 to disable the cap.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_required_capability">
                                <?php esc_html_e( 'Minimum role', 'lingua-forge' ); ?>
                            </label>
                        </th>
                        <td>
                            <?php $current_cap = (string) get_option( 'linguaforge_required_capability', 'edit_posts' ); ?>
                            <select
                                id="linguaforge_required_capability"
                                name="linguaforge_required_capability"
                            >
                                <?php foreach ( self::capability_choices() as $cap_value => $cap_label ) : ?>
                                    <option
                                        value="<?php echo esc_attr( $cap_value ); ?>"
                                        <?php selected( $current_cap, $cap_value ); ?>
                                    >
                                        <?php echo esc_html( $cap_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php
                                esc_html_e( 'Lowest WordPress capability allowed to trigger AI features. Tightening this on multi-author sites prevents Contributors or trial accounts from running paid AI calls. Override per-feature via the linguaforge_required_capability filter.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <!-- ── Translation limits ───────────────────────────────── -->
                <h2><?php esc_html_e('Translation Limits', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Control how much content is sent to the AI and how large the response can be. Leave a field blank to use the built-in default (shown as placeholder). Raise these values if large pages are being cut off; lower them to reduce API costs.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_translation_tier">
                                <?php esc_html_e('Model tier', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <?php
                            $translation_tier = Config::translation_tier();
                            ?>
                            <select
                                id="linguaforge_translation_tier"
                                name="linguaforge_translation_tier"
                            >
                                <option value="quality" <?php selected($translation_tier, 'quality'); ?>>
                                    <?php esc_html_e('Quality (default — Sonnet / GPT-4o / Gemini Pro)', 'lingua-forge'); ?>
                                </option>
                                <option value="light" <?php selected($translation_tier, 'light'); ?>>
                                    <?php esc_html_e('Light (fast and cost-effective — Haiku / Flash)', 'lingua-forge'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php
                                esc_html_e( 'Which model tier to use for full-page translation. Quality uses the model configured in the Models table above and is recommended for accurate, long-form translation. Switch to Light only if speed or cost is the priority and the content is short.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_translation_max_tokens">
                                <?php esc_html_e('Max output tokens', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_translation_max_tokens"
                                name="linguaforge_translation_max_tokens"
                                class="small-text"
                                min="1000"
                                max="128000"
                                step="1000"
                                value="<?php echo esc_attr((string) get_option('linguaforge_translation_max_tokens', '')); ?>"
                                placeholder="16000"
                            >
                            <p class="description">
                                <?php
                                esc_html_e( 'Maximum number of tokens the AI may produce in a single translation response. If a translation is silently cut off at the end, increase this value. Default: 16 000.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_translation_max_input_chars">
                                <?php esc_html_e('Max input characters', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_translation_max_input_chars"
                                name="linguaforge_translation_max_input_chars"
                                class="small-text"
                                min="0"
                                max="500000"
                                step="1000"
                                value="<?php echo esc_attr((string) get_option('linguaforge_translation_max_input_chars', '')); ?>"
                                placeholder="0 (no limit)"
                            >
                            <p class="description">
                                <?php
                                esc_html_e( 'Maximum number of characters of post content forwarded to the AI. 0 means no limit — the full content is always sent (recommended). Set a non-zero value only if your provider has a tight context window. A warning is written to the PHP error log whenever the content is trimmed.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <!-- ── Quick Translation limits ─────────────────────────── -->
                <h2><?php esc_html_e('Quick Translation', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Quick Translation is used for snippet/chunk mode — short passages translated on demand from the toolbar, editor, or block popovers. It uses a separate, lighter configuration from the full-page translation to keep responses fast and cost-effective.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_quick_translate_tier">
                                <?php esc_html_e('Model tier', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <?php
                            $qt_tier = Config::quick_translate_tier();
                            ?>
                            <select
                                id="linguaforge_quick_translate_tier"
                                name="linguaforge_quick_translate_tier"
                            >
                                <option value="light" <?php selected($qt_tier, 'light'); ?>>
                                    <?php esc_html_e('Light (default — fast and cost-effective)', 'lingua-forge'); ?>
                                </option>
                                <option value="quality" <?php selected($qt_tier, 'quality'); ?>>
                                    <?php esc_html_e('Quality (Sonnet / GPT-4o / Gemini Pro)', 'lingua-forge'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php
                                esc_html_e( 'The Light tier uses the fast model configured in the Models table above (default: Haiku / Flash). Switch to Quality if you need the same translation accuracy as full-page mode for snippets.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_quick_translate_max_tokens">
                                <?php esc_html_e('Max output tokens', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_quick_translate_max_tokens"
                                name="linguaforge_quick_translate_max_tokens"
                                class="small-text"
                                min="256"
                                max="16000"
                                step="256"
                                value="<?php echo esc_attr((string) get_option('linguaforge_quick_translate_max_tokens', '')); ?>"
                                placeholder="2000"
                            >
                            <p class="description">
                                <?php esc_html_e('Maximum tokens the AI may produce per quick translation. Default: 2 000.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_quick_translate_max_input_chars">
                                <?php esc_html_e('Max input characters', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_quick_translate_max_input_chars"
                                name="linguaforge_quick_translate_max_input_chars"
                                class="small-text"
                                min="256"
                                max="32000"
                                step="256"
                                value="<?php echo esc_attr((string) get_option('linguaforge_quick_translate_max_input_chars', '')); ?>"
                                placeholder="8000"
                            >
                            <p class="description">
                                <?php esc_html_e('Maximum characters accepted from the input field. Default: 8 000.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <!-- ── Content Generator limits ─────────────────────────── -->
                <h2><?php esc_html_e('Content Generator', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Controls the token budget and input limits for the AI Content Generator feature. Leave fields blank to use the built-in defaults.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_content_generator_max_tokens">
                                <?php esc_html_e('Max output tokens', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_content_generator_max_tokens"
                                name="linguaforge_content_generator_max_tokens"
                                class="small-text"
                                min="1000"
                                max="128000"
                                step="1000"
                                value="<?php echo esc_attr((string) get_option('linguaforge_content_generator_max_tokens', '')); ?>"
                                placeholder="8192"
                            >
                            <p class="description">
                                <?php esc_html_e('Maximum tokens the AI may produce per generation run. Raise this if full articles are being cut off. Default: 8 192.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_content_generator_max_hints_chars">
                                <?php esc_html_e('Max hints characters', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_content_generator_max_hints_chars"
                                name="linguaforge_content_generator_max_hints_chars"
                                class="small-text"
                                min="256"
                                max="32000"
                                step="256"
                                value="<?php echo esc_attr((string) get_option('linguaforge_content_generator_max_hints_chars', '')); ?>"
                                placeholder="2000"
                            >
                            <p class="description">
                                <?php esc_html_e('Maximum characters read from the Hints field. Default: 2 000.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="linguaforge_content_generator_max_context_chars">
                                <?php esc_html_e('Max context characters', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="linguaforge_content_generator_max_context_chars"
                                name="linguaforge_content_generator_max_context_chars"
                                class="small-text"
                                min="256"
                                max="32000"
                                step="256"
                                value="<?php echo esc_attr((string) get_option('linguaforge_content_generator_max_context_chars', '')); ?>"
                                placeholder="6000"
                            >
                            <p class="description">
                                <?php esc_html_e('Maximum characters of existing post content passed to the AI as seed context when no Hints are provided. Default: 6 000.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                </div><!-- /lingua-forge-tab-panel: limits -->

                <!-- ───── Tab: Behavior ───── -->
                <div class="lingua-forge-tab-panel" data-lf-panel="behavior">

                <!-- ── Block Editor (§2.7) ─────────────────────────────── -->
                <h2><?php esc_html_e('Block Editor', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Lingua Forge restricts two Gutenberg features by default to keep editorial behavior consistent across languages. Opt in here when you need full Gutenberg / FSE capabilities.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Block locking', 'lingua-forge'); ?>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="linguaforge_block_editor_allow_lock_blocks"
                                    value="1"
                                    <?php checked( (bool) get_option('linguaforge_block_editor_allow_lock_blocks', false) ); ?>
                                >
                                <?php esc_html_e('Allow editors to lock individual blocks', 'lingua-forge'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Re-enables Gutenberg\'s canLockBlocks. Useful for editorial templates that need to prevent contributors from moving or deleting specific blocks.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Template mode', 'lingua-forge'); ?>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="linguaforge_block_editor_allow_template_mode"
                                    value="1"
                                    <?php checked( (bool) get_option('linguaforge_block_editor_allow_template_mode', false) ); ?>
                                >
                                <?php esc_html_e('Allow Gutenberg template-editing mode on post screens', 'lingua-forge'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Re-enables supportsTemplateMode. Core to Full-Site-Editing workflows where post-level template overrides are intentional.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── Compliance Preset (§2.8) ────────────────────────── -->
                <h2><?php esc_html_e('Compliance Preset', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Strict-preservation mode for legal, regulatory, medical, or technical content. When on, every AI feature uses a low sampling temperature and appends the addendum below to its system prompt — terminology, article numbers, units, brand names, and regulatory language are preserved verbatim rather than paraphrased.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="linguaforge_active_preset">
                                <?php esc_html_e('Global AI preset', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <?php
                            $presets        = \LinguaForge\AI\Core\Config::presets();
                            $current_preset = \LinguaForge\AI\Core\Config::active_preset();
                            ?>
                            <select id="linguaforge_active_preset" name="linguaforge_active_preset">
                                <?php foreach ($presets as $key => $meta): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current_preset, $key); ?>>
                                        <?php echo esc_html($meta['label']); ?>
                                        <?php if ($meta['temperature'] !== null): ?>
                                            (<?php
                                            /* translators: %s: sampling temperature value, e.g. 0.2 */
                                            printf( esc_html__( 'T=%s', 'lingua-forge' ), esc_html( (string) $meta['temperature'] ) );
                                        ?>)
                                        <?php else: ?>
                                            (<?php
                                            /* translators: temperature range shown for the Standard preset, e.g. "T=0.2–0.6, per feature" */
                                            esc_html_e( 'T=0.2–0.6, per feature', 'lingua-forge' );
                                        ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Sets the default AI behaviour for all features site-wide. Individual posts can override this for Translation and Content Generation via the Lingua Forge AI meta box.', 'lingua-forge'); ?>
                                <?php esc_html_e('Standard uses each feature\'s own tuned temperature: Translation T=0.2 (precise), Quick Translate T=0.4, Content Generator T=0.6 (creative). The other presets apply a single fixed temperature across all features.', 'lingua-forge'); ?>
                            </p>
                            <div id="lf-preset-preview" class="lf-preset-preview" hidden>
                                <p class="lf-preset-preview-label"></p>
                                <pre class="lf-preset-preview-text"></pre>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="linguaforge_compliance_addendum">
                                <?php esc_html_e('Custom prompt instructions', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <?php
                            $stored_addendum = (string) get_option('linguaforge_compliance_addendum', '');
                            ?>
                            <textarea
                                id="linguaforge_compliance_addendum"
                                name="linguaforge_compliance_addendum"
                                rows="7"
                                class="large-text code"
                                placeholder="<?php echo esc_attr__( "Leave blank to use the selected preset's built-in instructions.\n\nExample — domain-specific overrides:\n- Preserve \"kWp\", \"PPA\", \"BESS\", \"self-consumption\" verbatim in all target languages.\n- Do not translate project or company names.\n- Use formal register throughout.\n- Flag any term with no direct equivalent rather than guessing.", 'lingua-forge' ); ?>"
                            ><?php echo esc_textarea( $stored_addendum ); ?></textarea>
                            <p class="description">
                                <?php if ( trim($stored_addendum) !== '' ): ?>
                                    <strong><?php esc_html_e('Active — these instructions are appended to every AI system prompt, overriding the selected preset\'s built-in rules.', 'lingua-forge'); ?></strong>
                                    <?php esc_html_e('Clear the field to fall back to the preset\'s default instructions.', 'lingua-forge'); ?>
                                <?php else: ?>
                                    <?php esc_html_e('Leave blank to use the selected preset\'s built-in instructions (Technical, Legal, or Creative rules are applied automatically). When you fill this in, it replaces the preset\'s instructions entirely — use it for domain-specific rules that apply to your whole site, such as preserving abbreviations, brand names, or citation formats. Works with all presets, including Standard.', 'lingua-forge'); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── Translation Memory (§4.5) ────────────────────────── -->
                <h2><?php esc_html_e('Translation Memory', 'lingua-forge'); ?></h2>

                <p>
                    <?php
                    esc_html_e( 'Cache translated equivalents at the block level so reusable content (shared footers, sidebars, accordions, boilerplate-heavy legal sections) does not pay an API call every time. When enabled, the translation flow parses each post into blocks, looks each block up in the cache, and sends only uncached blocks to the AI in a single batched request. Glossary edits and Compliance preset changes automatically invalidate affected cached translations. View statistics and clear the cache under Maintenance.', 'lingua-forge' );
                    ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Translation Memory', 'lingua-forge'); ?>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="linguaforge_translation_memory_enabled"
                                    value="1"
                                    <?php checked( (bool) get_option('linguaforge_translation_memory_enabled', false) ); ?>
                                >
                                <?php esc_html_e('Enable block-level translation cache reuse across posts', 'lingua-forge'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Currently skipped for posts that use block-comment attribute placeholders (wp:details summary fields, etc.) — they fall through to the existing single-call translation path.', 'lingua-forge'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                </div><!-- /lingua-forge-tab-panel: behavior -->

                <?php submit_button( __( 'Save Settings', 'lingua-forge' ) ); ?>

            </form>

            <!-- ───── Tab: Router ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="router">

            <?php self::render_router_tab(); ?>

            </div><!-- /lingua-forge-tab-panel: router -->

            <!-- ───── Tab: Glossary ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="glossary">

            <?php self::render_glossary_tab(); ?>

            </div><!-- /lingua-forge-tab-panel: glossary -->

            <!-- ───── Tab: AI Usage ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="ai-usage">

            <?php self::render_ai_usage_tab(); ?>

            </div><!-- /lingua-forge-tab-panel: ai-usage -->

            <!-- ───── Tab: Maintenance ───── -->
            <div class="lingua-forge-tab-panel" data-lf-panel="maintenance">

            <!-- ── Language Overrides ──────────────────────────────────── -->
            <hr>

            <h2><?php esc_html_e('Language Overrides', 'lingua-forge'); ?></h2>

            <p>
                <?php
                esc_html_e( 'Upload compiled .mo files to override third-party plugin strings for specific locales — for example, a custom VikBooking translation that uses "apartment" instead of "room". Files must follow the WordPress naming convention: {textdomain}-{locale}.mo (e.g. vikbooking-ca.mo). They are stored in the uploads folder and survive plugin updates.', 'lingua-forge' );
                ?>
            </p>

            <?php
            // ── Feedback notices ─────────────────────────────────────────────
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flags set by wp_safe_redirect() after upload/delete actions; no data is modified here.
            if (!empty($_GET['lf_override_uploaded'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Override file uploaded successfully.', 'lingua-forge'); ?></p>
                </div>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            elseif (!empty($_GET['lf_override_deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Override file deleted.', 'lingua-forge'); ?></p>
                </div>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            elseif (!empty($_GET['lf_override_error'])):
                $error_map = [
                    'empty'        => __('No file was selected.', 'lingua-forge'),
                    'invalid_type' => __('Only .mo files are accepted.', 'lingua-forge'),
                    'upload_error' => __('The upload failed — please try again.', 'lingua-forge'),
                    'move_failed'  => __('Could not save the file. Check that the uploads folder is writable.', 'lingua-forge'),
                    'invalid_file' => __('Invalid filename.', 'lingua-forge'),
                    'invalid_path' => __('Security check failed — file path is not permitted.', 'lingua-forge'),
                ];
                $error_key = sanitize_key( wp_unslash( $_GET['lf_override_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- value is used only as a lookup key in a hardcoded error-message map; no nonce is meaningful for a redirect-back GET param.
                $error_msg = $error_map[$error_key] ?? __('An unknown error occurred.', 'lingua-forge');
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error_msg); ?></p>
                </div>
            <?php endif; ?>

            <!-- ── Current override files ────────────────────────────────── -->
            <?php
            $dir   = self::overrides_dir();
            $mo_files = array_map('basename', glob($dir . '*.mo') ?: []);
            $po_files = array_map('basename', glob($dir . '*.po') ?: []);

            // Merge: show .mo files with a note if a matching .po source exists.
            // Also show any orphaned .po files (no compiled .mo yet).
            $all_bases = array_unique(array_merge(
                array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $mo_files),
                array_map(fn($f) => pathinfo($f, PATHINFO_FILENAME), $po_files)
            ));
            sort($all_bases);
            $files = $all_bases; // used below as the loop driver
            ?>

            <?php if (!empty($files)): ?>

                <table class="widefat striped" style="max-width:680px;margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Text domain / locale', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Files', 'lingua-forge'); ?></th>
                            <th><?php esc_html_e('Size', 'lingua-forge'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $base):
                            $has_mo   = in_array($base . '.mo', $mo_files, true);
                            $has_po   = in_array($base . '.po', $po_files, true);
                            $mo_path  = $dir . $base . '.mo';
                            $size     = $has_mo ? size_format(filesize($mo_path)) : '—';
                            $badges   = [];
                            if ($has_mo) $badges[] = '<code>.mo</code>';
                            if ($has_po) $badges[] = '<code>.po</code>';
                        ?>
                            <tr>
                                <td><code><?php echo esc_html($base); ?></code></td>
                                <td><?php echo wp_kses( implode( ' ', $badges ), [ 'code' => [] ] ); ?></td>
                                <td><?php echo esc_html($size); ?></td>
                                <td>
                                    <form
                                        method="post"
                                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        style="display:inline;"
                                        onsubmit="return confirm('<?php echo esc_js(__('Delete all files for this override (both .mo and .po)?', 'lingua-forge')); ?>')"
                                    >
                                        <input type="hidden" name="action" value="linguaforge_delete_i18n_override">
                                        <input type="hidden" name="linguaforge_override_file" value="<?php echo esc_attr($base . '.mo'); ?>">
                                        <?php wp_nonce_field('linguaforge_delete_override', 'linguaforge_override_nonce'); ?>
                                        <button type="submit" class="button button-link-delete">
                                            <?php esc_html_e('Delete', 'lingua-forge'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php else: ?>

                <p class="description" style="margin-bottom:16px;">
                    <?php esc_html_e('No override files uploaded yet.', 'lingua-forge'); ?>
                </p>

            <?php endif; ?>

            <!-- ── Upload form ───────────────────────────────────────────── -->
            <form
                method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                enctype="multipart/form-data"
            >
                <input type="hidden" name="action" value="linguaforge_upload_i18n_override">
                <?php wp_nonce_field('linguaforge_upload_override', 'linguaforge_override_nonce'); ?>

                <table class="form-table" role="presentation" style="max-width:680px;">
                    <tr>
                        <th scope="row">
                            <label for="linguaforge_mo_file">
                                <?php esc_html_e('Upload .mo file', 'lingua-forge'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="file"
                                id="linguaforge_mo_file"
                                name="linguaforge_mo_file"
                                accept=".mo"
                            >
                            <p class="description">
                                <?php
                                esc_html_e( 'Accepts compiled .mo files only. Filename must follow the pattern {textdomain}-{locale}.mo. Uploading a file with the same name as an existing one will replace it.', 'lingua-forge' );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Upload Override', 'lingua-forge' ), 'secondary' ); ?>

            </form>

            <!-- ── AI Cache ─────────────────────────────────────────────── -->
            <hr>

            <h2><?php esc_html_e( 'AI Cache', 'lingua-forge' ); ?></h2>

            <p>
                <?php
                esc_html_e(
                    'Lingua Forge caches AI-generated translations, meta descriptions, excerpts, and generated content per-post so unchanged inputs do not re-trigger a paid API call. Cached entries are automatically invalidated when their inputs change. Clear the cache manually to reclaim database space, force a resync after switching providers or editing prompt templates, or troubleshoot a cache-related issue.',
                    'lingua-forge'
                );
                ?>
            </p>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the clear action; no data is modified here.
            if ( isset( $_GET['lf_cache_cleared'] ) ) :
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only GET flag; absint() bounds it.
                $linguaforge_cleared_count = absint( $_GET['lf_cache_cleared'] );
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d is the number of cleared cache entries. */
                            _n(
                                'AI cache cleared. %d entry was removed.',
                                'AI cache cleared. %d entries were removed.',
                                $linguaforge_cleared_count,
                                'lingua-forge'
                            ),
                            $linguaforge_cleared_count
                        ) );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                onsubmit="return confirm('<?php echo esc_js( __( 'Clear all cached AI results? Future requests will trigger fresh API calls until the cache rebuilds.', 'lingua-forge' ) ); ?>');"
            >
                <input type="hidden" name="action" value="linguaforge_clear_ai_cache">
                <?php wp_nonce_field( 'linguaforge_clear_ai_cache', 'linguaforge_clear_ai_cache_nonce' ); ?>

                <?php submit_button(
                    __( 'Clear AI Cache', 'lingua-forge' ),
                    'secondary',
                    'submit',
                    false
                ); ?>
            </form>

            <!-- ── Debug Files ─────────────────────────────────────────── -->
            <hr>

            <h2><?php esc_html_e( 'Debug Files', 'lingua-forge' ); ?></h2>

            <p>
                <?php
                esc_html_e(
                    'When LINGUAFORGE_AI_DEBUG is defined in wp-config.php, the Translation feature writes its raw AI prompts and responses to disk for troubleshooting. Use this section to monitor that output and clear it once you have what you need — the files can grow quickly on large pages. Configure the destination directory via the linguaforge_debug_dir filter.',
                    'lingua-forge'
                );
                ?>
            </p>

            <?php
            $linguaforge_debug_enabled       = Translation::debug_enabled();
            $linguaforge_debug_dir           = Translation::debug_dir();
            $linguaforge_debug_count         = Translation::debug_file_count();
            $linguaforge_debug_const_defined = Translation::debug_constant_defined();
            $linguaforge_debug_const_value   = Translation::debug_constant_value();
            $linguaforge_debug_option_state  = (bool) get_option('linguaforge_ai_debug_enabled', false);
            ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the clear action.
            if ( isset( $_GET['lf_debug_cleared'] ) ) :
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only flag; absint() bounds it.
                $linguaforge_debug_removed = absint( $_GET['lf_debug_cleared'] );
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d is the number of removed debug files. */
                            _n(
                                'Debug files cleared. %d file was removed.',
                                'Debug files cleared. %d files were removed.',
                                $linguaforge_debug_removed,
                                'lingua-forge'
                            ),
                            $linguaforge_debug_removed
                        ) );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the toggle save action.
            if ( isset( $_GET['lf_debug_setting_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect flag from settings save handler; no data is modified.
                        if ( sanitize_key( wp_unslash( $_GET['lf_debug_setting_saved'] ) ) === '1' ) {
                            esc_html_e( 'Debug logging enabled.', 'lingua-forge' );
                        } else {
                            esc_html_e( 'Debug logging disabled.', 'lingua-forge' );
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
            >
                <input type="hidden" name="action" value="linguaforge_save_debug_setting">
                <?php wp_nonce_field( 'linguaforge_save_debug_setting', 'linguaforge_save_debug_setting_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Debug logging', 'lingua-forge' ); ?></th>
                        <td>
                            <?php if ( $linguaforge_debug_const_defined ) : ?>

                                <?php // Constant in wp-config.php overrides — show the locked state. ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        disabled
                                        <?php checked( (bool) $linguaforge_debug_const_value ); ?>
                                    >
                                    <?php esc_html_e( 'Write AI prompts and responses to disk for troubleshooting', 'lingua-forge' ); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    if ( $linguaforge_debug_const_value ) {
                                        esc_html_e( 'Forced ON by the LINGUAFORGE_AI_DEBUG constant in wp-config.php. Remove that line to control this toggle from here.', 'lingua-forge' );
                                    } else {
                                        esc_html_e( 'Forced OFF by the LINGUAFORGE_AI_DEBUG constant in wp-config.php. Remove that line to control this toggle from here.', 'lingua-forge' );
                                    }
                                    ?>
                                </p>

                            <?php else : ?>

                                <label>
                                    <input
                                        type="checkbox"
                                        name="linguaforge_ai_debug_enabled"
                                        value="1"
                                        <?php checked( $linguaforge_debug_option_state ); ?>
                                    >
                                    <?php esc_html_e( 'Write AI prompts and responses to disk for troubleshooting', 'lingua-forge' ); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    esc_html_e( 'Files land in the directory below. Useful for diagnosing translation issues — turn off once you have what you need so the files do not accumulate. You can also force this from wp-config.php with `define( \'LINGUAFORGE_AI_DEBUG\', true );` which overrides the toggle.', 'lingua-forge' );
                                    ?>
                                </p>

                            <?php endif; ?>

                            <p>
                                <strong><?php esc_html_e( 'Currently:', 'lingua-forge' ); ?></strong>
                                <?php if ( $linguaforge_debug_enabled ) : ?>
                                    <span class="lingua-forge-key-badge lingua-forge-badge--ok">
                                        <?php esc_html_e( '✓ Enabled', 'lingua-forge' ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="lingua-forge-key-badge lingua-forge-badge--missing">
                                        <?php esc_html_e( '✗ Disabled', 'lingua-forge' ); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Directory', 'lingua-forge' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $linguaforge_debug_dir ); ?></code>
                            <p class="description">
                                <?php esc_html_e( 'Filter with linguaforge_debug_dir to redirect debug output to a non-public location.', 'lingua-forge' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Files', 'lingua-forge' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( number_format_i18n( $linguaforge_debug_count ) ); ?></strong>
                            <?php esc_html_e( '.txt file(s) in the directory', 'lingua-forge' ); ?>
                        </td>
                    </tr>
                </table>

                <?php if ( ! $linguaforge_debug_const_defined ) : ?>
                    <?php submit_button(
                        __( 'Save Debug Setting', 'lingua-forge' ),
                        'secondary',
                        'submit',
                        false
                    ); ?>
                <?php endif; ?>
            </form>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                onsubmit="return confirm('<?php echo esc_js( __( 'Delete all .txt files in the debug directory? The directory itself will remain so future debug writes still land cleanly.', 'lingua-forge' ) ); ?>');"
            >
                <input type="hidden" name="action" value="linguaforge_clear_debug_files">
                <?php wp_nonce_field( 'linguaforge_clear_debug_files', 'linguaforge_clear_debug_files_nonce' ); ?>

                <?php submit_button(
                    __( 'Clear Debug Files', 'lingua-forge' ),
                    'secondary',
                    'submit',
                    false,
                    $linguaforge_debug_count > 0 ? [] : ['disabled' => 'disabled']
                ); ?>
            </form>

            <!-- ── Translation Memory ──────────────────────────────────── -->
            <hr>

            <h2><?php esc_html_e( 'Translation Memory', 'lingua-forge' ); ?></h2>

            <p>
                <?php
                esc_html_e(
                    'Per-block translation cache shared across posts. Configure on/off in Settings → Behavior. Stats below show what is currently cached; clearing forces every block to be re-translated on next request (useful after upgrading models or to recover database space).',
                    'lingua-forge'
                );
                ?>
            </p>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect after the clear action.
            if ( isset( $_GET['lf_tm_cleared'] ) ) :
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only flag; absint() bounds it.
                $linguaforge_tm_removed = absint( $_GET['lf_tm_cleared'] );
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %d is the number of removed cached blocks. */
                            _n(
                                'Translation Memory cleared. %d cached block was removed.',
                                'Translation Memory cleared. %d cached blocks were removed.',
                                $linguaforge_tm_removed,
                                'lingua-forge'
                            ),
                            $linguaforge_tm_removed
                        ) );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $linguaforge_tm_enabled = (bool) get_option( 'linguaforge_translation_memory_enabled', false );
            $linguaforge_tm_stats   = TranslationMemory::stats();
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Status', 'lingua-forge' ); ?></th>
                    <td>
                        <?php if ( $linguaforge_tm_enabled ) : ?>
                            <span class="lingua-forge-key-badge lingua-forge-badge--ok">
                                <?php esc_html_e( '✓ Enabled', 'lingua-forge' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="lingua-forge-key-badge lingua-forge-badge--missing">
                                <?php esc_html_e( '✗ Disabled', 'lingua-forge' ); ?>
                            </span>
                            <?php esc_html_e( '— toggle in Settings → Behavior.', 'lingua-forge' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Cached blocks', 'lingua-forge' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( number_format_i18n( $linguaforge_tm_stats['rows'] ) ); ?></strong>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Cumulative cache hits', 'lingua-forge' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( number_format_i18n( $linguaforge_tm_stats['total_hits'] ) ); ?></strong>
                        <?php
                        if ( $linguaforge_tm_stats['rows'] > 0 ) {
                            $avg = $linguaforge_tm_stats['total_hits'] / $linguaforge_tm_stats['rows'];
                            echo ' <span style="color:#646970">' . esc_html( sprintf(
                                /* translators: %s is the average hits per cached block. */
                                __( '(avg %.1f hits/block)', 'lingua-forge' ),
                                $avg
                            ) ) . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php if ( $linguaforge_tm_stats['oldest'] !== '' ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Oldest entry', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( $linguaforge_tm_stats['oldest'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Newest entry', 'lingua-forge' ); ?></th>
                        <td><?php echo esc_html( $linguaforge_tm_stats['newest'] ); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Approximate size', 'lingua-forge' ); ?></th>
                    <td>
                        <?php
                        echo esc_html( size_format( $linguaforge_tm_stats['bytes_estimate'] ) ?: '0 B' );
                        ?>
                    </td>
                </tr>
            </table>

            <form
                method="post"
                action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                onsubmit="return confirm('<?php echo esc_js( __( 'Clear the entire Translation Memory? Future translations will rebuild the cache as they run.', 'lingua-forge' ) ); ?>');"
            >
                <input type="hidden" name="action" value="linguaforge_clear_translation_memory">
                <?php wp_nonce_field( 'linguaforge_clear_translation_memory', 'linguaforge_clear_tm_nonce' ); ?>

                <?php submit_button(
                    __( 'Clear Translation Memory', 'lingua-forge' ),
                    'secondary',
                    'submit',
                    false,
                    $linguaforge_tm_stats['rows'] > 0 ? [] : ['disabled' => 'disabled']
                ); ?>
            </form>

            </div><!-- /lingua-forge-tab-panel: maintenance -->

        </div>

        <?php
    }
}
