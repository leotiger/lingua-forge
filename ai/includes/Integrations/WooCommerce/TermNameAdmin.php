<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\TermNameAdmin
 *
 * Phase 1b admin surface: translated term name fields on the term edit screen.
 *
 * Adds a "Translated Names" section to the edit and add-new screens for every
 * WooCommerce taxonomy that participates in term name translation (product_cat,
 * product_tag, product_type, and all pa_* attribute taxonomies registered at
 * runtime by WooCommerce).
 *
 * Each active non-source language gets one plain text field.  Values are stored
 * as termmeta under the key `_lf_term_name_{lang}` (e.g. `_lf_term_name_es`).
 * An empty field means "fall back to the source-language name" — no entry is
 * stored, and `TermNameFilter` will leave the original name intact.
 *
 * Hooks registered:
 *   - {taxonomy}_edit_form_fields        — adds fields on the Edit Term screen
 *   - {taxonomy}_add_form_fields         — adds per-language fields on the Add Term screen
 *   - admin_notices                        — batch AI translate button (WC taxonomy screens only)
 *   - edited_{taxonomy}                  — saves on Edit Term submit
 *   - created_{taxonomy}                 — saves on Add Term submit
 *
 * All four hook types accept the taxonomy slug as a suffix, so they are
 * registered once per active WC taxonomy at `init` time (after WooCommerce has
 * registered its pa_* taxonomies).
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;
use WP_Term;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class TermNameAdmin {

	// =========================================================================
	// Boot
	// =========================================================================

	/** AJAX action key for the batch translate button. */
	private const AJAX_TRANSLATE_ALL = 'lf_translate_all_terms';

	public static function init(): void {

		// Only wire up the admin-side hooks on admin requests.
		if ( ! is_admin() ) {
			return;
		}

		// Register hooks after WooCommerce has registered its pa_* taxonomies.
		// WC registers product attribute taxonomies on `init` priority 5; we run
		// at priority 15 to be safely after them while still well ahead of the
		// default term list / edit screens.
		add_action( 'init', [ self::class, 'register_term_hooks' ], 15 );

		// Batch AI translate AJAX — logged-in admin users only.
		add_action( 'wp_ajax_' . self::AJAX_TRANSLATE_ALL, [ self::class, 'ajax_translate_all_terms' ] );
	}

	// =========================================================================
	// Hook registration
	// =========================================================================

	/**
	 * Register form-field and save hooks for every active WooCommerce taxonomy.
	 *
	 * Called on `init` priority 15 so pa_* taxonomies already exist.
	 */
	public static function register_term_hooks(): void {

		$languages = self::non_source_languages();

		// Nothing to show if only one language is configured.
		if ( empty( $languages ) ) {
			return;
		}

		foreach ( self::active_wc_taxonomies() as $taxonomy ) {
			// Edit-term screen (existing term)
			add_action( "{$taxonomy}_edit_form_fields", [ self::class, 'render_edit_fields' ], 10, 2 );
			// Add-term screen (new term form) — language fields only, no batch button here
			add_action( "{$taxonomy}_add_form_fields",  [ self::class, 'render_add_fields' ],  10, 1 );
			// Batch AI translate button — fires outside the <form> tag, before the add-new
			// heading.  WooCommerce uses the same hook (priority 10) for its description text;
			// we run at priority 20 so we appear after it, still outside the form.
			add_action( "{$taxonomy}_pre_add_form", [ self::class, 'render_before_terms_table' ], 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- standard WP hook
			// Save on edit — only term_id is needed; WordPress passes a 2nd arg
			// (tt_id) but save_fields does not use it, so accept 1 arg only.
			add_action( "edited_{$taxonomy}",  [ self::class, 'save_fields' ], 10, 1 );
			// Save on create
			add_action( "created_{$taxonomy}", [ self::class, 'save_fields' ], 10, 1 );
		}
	}

	// =========================================================================
	// Render: Edit Term screen
	// =========================================================================

	/**
	 * Output translated-name <tr> rows on the Edit Term screen.
	 *
	 * @param WP_Term $term     The term being edited.
	 * @param string  $taxonomy The taxonomy slug.
	 */
	public static function render_edit_fields( WP_Term $term, string $taxonomy ): void {

		$languages = self::non_source_languages();
		if ( empty( $languages ) ) {
			return;
		}

		wp_nonce_field( 'lf_term_name_save_' . $term->term_id, 'lf_term_name_nonce' );

		echo '<tr class="form-field lf-term-names">';
		echo '<th scope="row" colspan="2"><strong>' . esc_html__( 'Translated Names', 'lingua-forge' ) . '</strong></th>';
		echo '</tr>';

		foreach ( $languages as $lang ) {
			$meta_key = TermNameFilter::META_PREFIX . $lang;
			$value    = (string) get_term_meta( $term->term_id, $meta_key, true );
			$field_id = 'lf_term_name_' . esc_attr( $lang );

			echo '<tr class="form-field">';
			echo '<th scope="row"><label for="' . esc_attr( $field_id ) . '">';
			echo esc_html( strtoupper( $lang ) );
			echo '</label></th>';
			echo '<td>';
			echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			echo '<p class="description">' . esc_html__( 'Name displayed in this language. Leave empty to use the source name.', 'lingua-forge' ) . '</p>';
			echo '</td>';
			echo '</tr>';
		}
	}

	// =========================================================================
	// Render: Add Term screen
	// =========================================================================

	/**
	 * Output translated-name <div> rows on the Add Term screen.
	 *
	 * The Add Term screen uses <div class="form-field"> rather than <tr> rows.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 */
	public static function render_add_fields( string $taxonomy ): void {

		$languages = self::non_source_languages();
		if ( empty( $languages ) ) {
			return;
		}

		wp_nonce_field( 'lf_term_name_add_' . $taxonomy, 'lf_term_name_nonce' );

		// ── Section header ────────────────────────────────────────────────────
		echo '<div class="form-field lf-term-names">';
		echo '<strong>' . esc_html__( 'Translated Names', 'lingua-forge' ) . '</strong>';
		echo '</div>';

		// ── Per-language fields for the term being added ──────────────────────
		foreach ( $languages as $lang ) {
			$field_id = 'lf_term_name_' . esc_attr( $lang );

			echo '<div class="form-field">';
			echo '<label for="' . esc_attr( $field_id ) . '">';
			echo esc_html( strtoupper( $lang ) );
			echo '</label>';
			echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" value="" class="regular-text" />';
			echo '<p>' . esc_html__( 'Name displayed in this language. Leave empty to use the source name.', 'lingua-forge' ) . '</p>';
			echo '</div>';
		}

	}

	// =========================================================================
	// Render: batch translate button (admin_notices)
	// =========================================================================

	/**
	 * Output the "Translate all … (AI)" button above the add-new form.
	 *
	 * Hooked on `{taxonomy}_pre_add_form` at priority 20 — fires outside the
	 * <form> tag, after WooCommerce's own description text (priority 10).
	 * The taxonomy slug is passed as the sole argument by WordPress.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 */
	public static function render_before_terms_table( string $taxonomy ): void {

		if ( empty( self::non_source_languages() ) ) {
			return;
		}

		printf(
			'<p><button type="button" class="button lf-translate-all-terms" data-taxonomy="%s" data-nonce="%s">%s</button>'
			. ' <span class="lf-translate-status" style="display:none;margin-left:8px;"></span></p>',
			esc_attr( $taxonomy ),
			esc_attr( wp_create_nonce( self::AJAX_TRANSLATE_ALL ) ),
			esc_html( self::batch_button_label( $taxonomy ) )
		);

		self::print_batch_translate_script();
	}

	/**
	 * Return a taxonomy-specific label for the batch translate button.
	 *
	 * Gives users a clear indication of which terms will be affected:
	 *   product_tag  → "Translate all tags (AI)"
	 *   product_cat  → "Translate all categories (AI)"
	 *   pa_*         → "Translate all {attribute label} terms (AI)"
	 *   other        → "Translate all terms (AI)"
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @return string           Translated button label.
	 */
	private static function batch_button_label( string $taxonomy ): string {

		if ( 'product_tag' === $taxonomy ) {
			return __( 'Translate all tags (AI)', 'lingua-forge' );
		}

		if ( 'product_cat' === $taxonomy ) {
			return __( 'Translate all categories (AI)', 'lingua-forge' );
		}

		// pa_* — use the human-readable attribute label if available.
		if ( str_starts_with( $taxonomy, 'pa_' ) ) {
			$label = wc_attribute_label( $taxonomy );
			if ( $label && $label !== $taxonomy ) {
				/* translators: %s: WooCommerce attribute label, e.g. "Color" */
				return sprintf( __( 'Translate all %s terms (AI)', 'lingua-forge' ), $label );
			}
		}

		return __( 'Translate all terms (AI)', 'lingua-forge' );
	}

	// =========================================================================
	// Batch AI translate (AJAX)
	// =========================================================================

	/**
	 * AJAX handler: translate every untranslated term in a WC taxonomy via AI.
	 *
	 * Skips terms that already have a _lf_term_name_{lang} entry — identical
	 * semantics to TermNameTranslator::on_translation_complete(), so manual
	 * overrides are never overwritten.  Pass force=1 to bypass the skip check
	 * and overwrite existing values (e.g. to fix a previous run that stored
	 * wrong translations).
	 *
	 * Returns JSON: { translated: int, skipped: int, taxonomy: string }
	 */
	public static function ajax_translate_all_terms(): void {

		check_ajax_referer( self::AJAX_TRANSLATE_ALL, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lingua-forge' ) ] );
		}

		$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
		if ( ! TermNameFilter::is_wc_taxonomy( $taxonomy ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid taxonomy.', 'lingua-forge' ) ] );
		}

		// force=1 bypasses the "already translated" skip check, allowing the
		// caller to overwrite existing values (e.g. fix a previous bad run).
		$force = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above

		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( $terms instanceof WP_Error || empty( $terms ) ) {
			wp_send_json_success( [ 'translated' => 0, 'skipped' => 0, 'taxonomy' => $taxonomy ] );
		}

		$languages  = self::non_source_languages();
		$translated = 0;
		$skipped    = 0;

		foreach ( $languages as $lang ) {
			$meta_key = TermNameFilter::META_PREFIX . $lang;
			$pending  = [];

			foreach ( $terms as $term ) {
				if ( ! ( $term instanceof WP_Term ) ) {
					continue;
				}
				if ( ! $force ) {
					$existing = get_term_meta( $term->term_id, $meta_key, true );
					if ( '' !== $existing && false !== $existing ) {
						++$skipped;
						continue;
					}
				}
				$pending[ $term->term_id ] = $term->name;
			}

			if ( empty( $pending ) ) {
				continue;
			}

			// Single AI call per language — same batching strategy as TermNameTranslator.
			// Scale max_tokens to the batch size: ~20 tokens per term name (key +
			// translated value + JSON punctuation), minimum 512.
			$max_tokens   = max( 512, count( $pending ) * 20 );
			$translations = TermNameTranslator::translate_term_names( $pending, $lang, 0, $max_tokens );

			foreach ( $pending as $term_id => $source_name ) {
				if ( isset( $translations[ $source_name ] ) && '' !== (string) $translations[ $source_name ] ) {
					update_term_meta( $term_id, $meta_key, sanitize_text_field( (string) $translations[ $source_name ] ) );
					++$translated;
				}
			}
		}

		wp_send_json_success( [ 'translated' => $translated, 'skipped' => $skipped, 'taxonomy' => $taxonomy ] );
	}

	/**
	 * Print the inline JS that powers the batch-translate button.
	 * Guards against being called more than once per page load.
	 */
	private static function print_batch_translate_script(): void {

		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		$i18n = wp_json_encode( [
			'translating'  => __( 'Translating…', 'lingua-forge' ),
			'done_none'    => __( 'No terms found.', 'lingua-forge' ),
			/* translators: %d: number of term names translated */
			'done_count'   => __( '%d term(s) translated.', 'lingua-forge' ),
			/* translators: %d: number of terms skipped because already translated */
			'done_skipped' => __( '%d already translated — <a href="#" class="lf-force-translate">force retranslate</a>', 'lingua-forge' ),
			/* translators: 1: number translated, 2: number skipped */
			'done_mixed'   => __( '%1$d translated, %2$d skipped.', 'lingua-forge' ),
			'error'        => __( 'Translation failed. Check the browser console.', 'lingua-forge' ),
		] );
		?>
		<script>
		(function($){
			var i18n = <?php echo $i18n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output is safe ?>;

			function doTranslate( btn, force ) {
				var status   = btn.siblings('.lf-translate-status'),
					taxonomy = btn.attr('data-taxonomy'),
					nonce    = btn.attr('data-nonce');

				btn.prop('disabled', true);
				status.show().text(i18n.translating);

				$.post(ajaxurl, {
					action:   '<?php echo esc_js( self::AJAX_TRANSLATE_ALL ); ?>',
					taxonomy: taxonomy,
					nonce:    nonce,
					force:    force ? 1 : 0
				})
				.done(function(resp){
					if (resp.success) {
						var n       = resp.data.translated,
							skipped = resp.data.skipped || 0,
							tax     = resp.data.taxonomy || taxonomy,
							msg;
						if ( n > 0 && skipped > 0 ) {
							msg = i18n.done_mixed.replace('%1$d', n).replace('%2$d', skipped);
						} else if ( n > 0 ) {
							msg = i18n.done_count.replace('%d', n);
						} else if ( skipped > 0 ) {
							msg = i18n.done_skipped.replace('%d', skipped);
						} else {
							msg = i18n.done_none;
						}
						status.html(msg + ' <span style="color:#999">[' + tax + ']</span>');
					} else {
						status.text(resp.data && resp.data.message ? resp.data.message : i18n.error);
					}
				})
				.fail(function(){ status.text(i18n.error); })
				.always(function(){
					btn.prop('disabled', false);
					setTimeout(function(){ status.fadeOut(400, function(){ status.show(); }); }, 8000);
				});
			}

			$(document).on('click', '.lf-translate-all-terms', function(){
				doTranslate( $(this), false );
			});

			$(document).on('click', '.lf-force-translate', function(e){
				e.preventDefault();
				var btn = $(this).closest('p').find('.lf-translate-all-terms');
				doTranslate( btn, true );
			});
		}(jQuery));
		</script>
		<?php
	}

	// =========================================================================
	// Save
	// =========================================================================

	/**
	 * Save translated term names from the term add/edit form.
	 *
	 * Called on both `edited_{taxonomy}` and `created_{taxonomy}`.
	 *
	 * @param int $term_id The term ID being saved.
	 */
	public static function save_fields( int $term_id ): void {

		// ── Capability check (fast-fail before any nonce work) ────────────────
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		// ── Nonce verification ────────────────────────────────────────────────
		$nonce = isset( $_POST['lf_term_name_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['lf_term_name_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below

		// Accept both the edit nonce (term-id-keyed) and the add nonce (taxonomy-keyed).
		$valid_edit = wp_verify_nonce( $nonce, 'lf_term_name_save_' . $term_id );
		$valid_add  = false;
		if ( ! $valid_edit ) {
			// For the add form the nonce is keyed by taxonomy; read that from POST.
			$taxonomy  = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- we are only reading taxonomy name to reconstruct nonce action
			$valid_add = $taxonomy && wp_verify_nonce( $nonce, 'lf_term_name_add_' . $taxonomy );
		}

		if ( ! $valid_edit && ! $valid_add ) {
			return;
		}

		// ── Save each language ────────────────────────────────────────────────
		foreach ( self::non_source_languages() as $lang ) {
			$field_key = 'lf_term_name_' . $lang;
			$meta_key  = TermNameFilter::META_PREFIX . $lang;

			if ( ! isset( $_POST[ $field_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above

			if ( '' === $value ) {
				delete_term_meta( $term_id, $meta_key );
			} else {
				update_term_meta( $term_id, $meta_key, $value );
			}
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return all languages active on this install except the source language.
	 *
	 * @return string[]  e.g. ['es', 'en']
	 */
	private static function non_source_languages(): array {

		$router      = Router::get_instance();
		$source_lang = $router->source_language();
		$all         = $router->languages();

		return array_values( array_filter( $all, fn( string $l ) => $l !== $source_lang ) );
	}

	/**
	 * Return all currently registered WooCommerce taxonomies (product_cat,
	 * product_tag, product_type, and any pa_* attribute taxonomies that have
	 * been registered by WooCommerce at runtime).
	 *
	 * @return string[]
	 */
	private static function active_wc_taxonomies(): array {

		$registered = get_taxonomies();
		$wc         = [];

		foreach ( $registered as $slug ) {
			if ( TermNameFilter::is_wc_taxonomy( $slug ) ) {
				$wc[] = $slug;
			}
		}

		return $wc;
	}
}
