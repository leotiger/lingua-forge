<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Core\Glossary;
use LinguaForge\AI\Features\Translation;

defined('ABSPATH') || exit;

/**
 * Settings tab: Glossary
 *
 * Filter dropdown, entries table, and add-new form for the site glossary.
 * Handlers for adding and deleting entries are also registered here.
 *
 * This tab uses its own admin-post actions rather than the shared settings
 * form, so save() is not implemented.
 */
class GlossaryTab extends Tab {

    public static function slug(): string {
        return 'glossary';
    }

    public static function label(): string {
        return __( 'Glossary', 'lingua-forge' );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render_content(): void {

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET filter; no data is modified.
        $filter_source = sanitize_key( wp_unslash( $_GET['glossary_source'] ?? '' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET filter; no data is modified.
        $filter_target = sanitize_key( wp_unslash( $_GET['glossary_target'] ?? '' ) );

        $criteria = [];
        if ( $filter_source !== '' ) $criteria['source_lang'] = $filter_source;
        if ( $filter_target !== '' ) $criteria['target_lang'] = $filter_target;

        $entries  = Glossary::get_all( $criteria );
        $base_url = admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG );

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

        <!-- ── Filter form (GET) ─────────────────────────────────────────────── -->
        <form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="lingua-forge-glossary-filter">
            <input type="hidden" name="page" value="<?php echo esc_attr( SettingsPage::PAGE_SLUG ); ?>">

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

        <!-- ── Entries table ─────────────────────────────────────────────────── -->
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
                                    <input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) $entry['id'] ); ?>">
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

        <!-- ── Add-new form ──────────────────────────────────────────────────── -->
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

    // ── Handlers ──────────────────────────────────────────────────────────────

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

        $base = admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG );

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
            admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
        ) . '#glossary' );
        exit;
    }
}
