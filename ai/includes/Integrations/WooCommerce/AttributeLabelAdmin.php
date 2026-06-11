<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\AttributeLabelAdmin
 *
 * Admin surface for translating WooCommerce product attribute labels.
 *
 * Attribute *labels* (e.g. "Color", "Size") are separate from attribute *term
 * names* (e.g. "Blue", "Red") — they live in wp_woocommerce_attribute_taxonomies
 * rather than termmeta and are displayed by wc_attribute_label().
 *
 * This class adds per-language label fields to the WooCommerce Product
 * Attributes edit/add forms and persists translations to wp_options under the
 * key `linguaforge_attr_labels_{$taxonomy}` (e.g.
 * `linguaforge_attr_labels_pa_color`), storing an associative array of
 * `[ lang => translated_label ]`.
 *
 * Frontend filtering is handled in TermNameFilter::translate_attribute_label()
 * via the `woocommerce_attribute_label` filter.
 *
 * Hooks registered:
 *   - woocommerce_before_add_attribute_fields — batch AI translate button (top of add form)
 *   - woocommerce_after_edit_attribute_fields — translated label fields on edit form
 *   - woocommerce_after_add_attribute_fields  — translated label fields on add form
 *   - woocommerce_attribute_updated           — save on edit
 *   - woocommerce_attribute_added             — save on add
 *   - wp_ajax_lf_translate_all_attr_labels    — AJAX batch translate handler
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class AttributeLabelAdmin {

	// =========================================================================
	// Boot
	// =========================================================================

	/** Option key prefix for stored translations. */
	public const OPTION_PREFIX = 'linguaforge_attr_labels_';

	/** POST/form field name prefix. */
	private const FIELD_PREFIX = 'lf_attr_label_';

	/** AJAX action key for the batch translate button. */
	private const AJAX_TRANSLATE_ALL = 'lf_translate_all_attr_labels';

	public static function init(): void {

		if ( ! is_admin() ) {
			return;
		}

		// Batch AI translate button — fires inside the add-attribute <form>,
		// at the top before the default fields.
		add_action( 'woocommerce_before_add_attribute_fields', [ self::class, 'render_batch_button' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook

		// Form fields.
		add_action( 'woocommerce_after_edit_attribute_fields', [ self::class, 'render_edit_fields' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook
		add_action( 'woocommerce_after_add_attribute_fields',  [ self::class, 'render_add_fields'  ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook

		// Save — WooCommerce already verifies its own nonce before firing these.
		add_action( 'woocommerce_attribute_updated', [ self::class, 'save' ], 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook
		add_action( 'woocommerce_attribute_added',   [ self::class, 'save' ], 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook

		// Batch AI translate AJAX — logged-in admin users only.
		add_action( 'wp_ajax_' . self::AJAX_TRANSLATE_ALL, [ self::class, 'ajax_translate_all_attr_labels' ] );
	}

	// =========================================================================
	// Render: Edit Attribute screen
	// =========================================================================

	/**
	 * Output translated-label <tr> rows on the Edit Attribute screen.
	 *
	 * Fires inside the <table class="form-table"> on the WooCommerce Product
	 * Attributes edit form.  No arguments are passed by WordPress — we read the
	 * attribute ID from $_GET['edit'].
	 */
	public static function render_edit_fields(): void {

		$languages = self::non_source_languages();
		if ( empty( $languages ) ) {
			return;
		}

		$attribute_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; display only
		if ( ! $attribute_id ) {
			return;
		}

		$attribute = wc_get_attribute( $attribute_id );
		if ( ! $attribute ) {
			return;
		}

		// $attribute->slug is the full taxonomy name, e.g. 'pa_color'.
		$taxonomy     = $attribute->slug;
		$translations = (array) get_option( self::OPTION_PREFIX . $taxonomy, [] );

		echo '<tr class="form-field lf-attr-labels"><th scope="row" colspan="2"><strong>';
		echo esc_html__( 'Translated Labels', 'lingua-forge' );
		echo '</strong></th></tr>';

		foreach ( $languages as $lang ) {
			$field_id = self::FIELD_PREFIX . $lang;
			$value    = isset( $translations[ $lang ] ) ? (string) $translations[ $lang ] : '';

			echo '<tr class="form-field">';
			echo '<th scope="row" valign="top">';
			echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html( strtoupper( $lang ) ) . '</label>';
			echo '</th>';
			echo '<td>';
			echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
			echo '<p class="description">' . esc_html__( 'Attribute label displayed in this language. Leave empty to use the default label.', 'lingua-forge' ) . '</p>';
			echo '</td>';
			echo '</tr>';
		}
	}

	// =========================================================================
	// Render: Add Attribute screen
	// =========================================================================

	/**
	 * Output translated-label <div> rows on the Add Attribute screen.
	 *
	 * Fires inside the add-attribute <form>.  The add form uses
	 * <div class="form-field"> rather than <tr> rows.
	 */
	public static function render_add_fields(): void {

		$languages = self::non_source_languages();
		if ( empty( $languages ) ) {
			return;
		}

		echo '<div class="form-field lf-attr-labels">';
		echo '<strong>' . esc_html__( 'Translated Labels', 'lingua-forge' ) . '</strong>';
		echo '</div>';

		foreach ( $languages as $lang ) {
			$field_id = self::FIELD_PREFIX . $lang;

			echo '<div class="form-field">';
			echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html( strtoupper( $lang ) ) . '</label>';
			echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_id ) . '" value="" class="regular-text" />';
			echo '<p class="description">' . esc_html__( 'Attribute label displayed in this language. Leave empty to use the default label.', 'lingua-forge' ) . '</p>';
			echo '</div>';
		}
	}

	// =========================================================================
	// Save
	// =========================================================================

	/**
	 * Persist translated attribute labels from the edit or add form.
	 *
	 * Called on both `woocommerce_attribute_updated` and
	 * `woocommerce_attribute_added`.  WooCommerce verifies its own nonce before
	 * firing these actions, so the form submission is already authenticated.
	 *
	 * @param int   $id   Attribute ID (unused — slug comes from $data).
	 * @param array $data Attribute data; `attribute_name` holds the slug
	 *                    without the pa_ prefix (e.g. 'color').
	 */
	public static function save( int $id, array $data ): void {

		$languages = self::non_source_languages();
		if ( empty( $languages ) ) {
			return;
		}

		// Build the full taxonomy slug, e.g. 'pa_color'.
		$taxonomy = 'pa_' . ( $data['attribute_name'] ?? '' );
		if ( 'pa_' === $taxonomy ) {
			return;
		}

		$existing     = (array) get_option( self::OPTION_PREFIX . $taxonomy, [] );
		$translations = $existing;

		foreach ( $languages as $lang ) {
			$field_key = self::FIELD_PREFIX . $lang;

			if ( ! isset( $_POST[ $field_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by WooCommerce before this hook fires
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $field_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by WooCommerce before this hook fires

			if ( '' === $value ) {
				unset( $translations[ $lang ] );
			} else {
				$translations[ $lang ] = $value;
			}
		}

		if ( $translations !== $existing ) {
			if ( empty( $translations ) ) {
				delete_option( self::OPTION_PREFIX . $taxonomy );
			} else {
				update_option( self::OPTION_PREFIX . $taxonomy, $translations, false );
			}
		}
	}

	// =========================================================================
	// Render: Batch translate button
	// =========================================================================

	/**
	 * Output the "Translate all labels (AI)" button at the top of the add-
	 * attribute form.
	 *
	 * Hooked on `woocommerce_before_add_attribute_fields` — fires inside the
	 * <form> tag so we use type="button" to prevent accidental form submission.
	 * Placed at the top so it is visible even before the language fields below.
	 */
	public static function render_batch_button(): void {

		if ( empty( self::non_source_languages() ) ) {
			return;
		}

		printf(
			'<div class="form-field">'
			. '<button type="button" class="button lf-translate-all-attr-labels" data-nonce="%s">%s</button>'
			. ' <span class="lf-translate-attr-status" style="display:none;margin-left:8px;"></span>'
			. '</div>',
			esc_attr( wp_create_nonce( self::AJAX_TRANSLATE_ALL ) ),
			esc_html__( 'Translate all labels (AI)', 'lingua-forge' )
		);

		self::print_batch_translate_script();
	}

	// =========================================================================
	// Batch AI translate (AJAX)
	// =========================================================================

	/**
	 * AJAX handler: translate every untranslated attribute label via AI.
	 *
	 * Loops all registered WooCommerce attribute taxonomies, collects labels
	 * that are missing a translation for each target language, sends them as a
	 * single batch to the AI (reusing TermNameTranslator::translate_term_names),
	 * and stores the results in the same wp_options structure used by the manual
	 * fields.  Existing manual translations are never overwritten.
	 *
	 * Returns JSON: { translated: int, skipped: int }
	 */
	public static function ajax_translate_all_attr_labels(): void {

		check_ajax_referer( self::AJAX_TRANSLATE_ALL, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lingua-forge' ) ] );
		}

		$attributes = wc_get_attribute_taxonomies();
		if ( empty( $attributes ) ) {
			wp_send_json_success( [ 'translated' => 0, 'skipped' => 0 ] );
		}

		$languages  = self::non_source_languages();
		$translated = 0;
		$skipped    = 0;

		foreach ( $languages as $lang ) {

			// Build pending map: taxonomy => source_label, for labels not yet translated.
			$pending = [];

			foreach ( $attributes as $attr ) {
				$taxonomy = 'pa_' . $attr->attribute_name;
				$existing = (array) get_option( self::OPTION_PREFIX . $taxonomy, [] );

				if ( isset( $existing[ $lang ] ) && '' !== $existing[ $lang ] ) {
					++$skipped;
					continue;
				}

				// Key by taxonomy so we can look it up after the AI call.
				$pending[ $taxonomy ] = $attr->attribute_label;
			}

			if ( empty( $pending ) ) {
				continue;
			}

			// Single AI call per language — reuses the same batching strategy as
			// TermNameTranslator. $pending values are the source labels; the
			// returned map is keyed by source label → translated label.
			$translations = TermNameTranslator::translate_term_names( $pending, $lang );

			foreach ( $pending as $taxonomy => $source_label ) {
				if ( ! isset( $translations[ $source_label ] ) || '' === (string) $translations[ $source_label ] ) {
					continue;
				}

				$existing = (array) get_option( self::OPTION_PREFIX . $taxonomy, [] );
				$existing[ $lang ] = sanitize_text_field( (string) $translations[ $source_label ] );
				update_option( self::OPTION_PREFIX . $taxonomy, $existing, false );
				++$translated;
			}
		}

		wp_send_json_success( [ 'translated' => $translated, 'skipped' => $skipped ] );
	}

	/**
	 * Print the inline JS that powers the batch-translate button.
	 * Called once per page from render_batch_button().
	 */
	private static function print_batch_translate_script(): void {
		$i18n = wp_json_encode( [
			'translating' => __( 'Translating…', 'lingua-forge' ),
			'done_none'   => __( 'All labels already translated.', 'lingua-forge' ),
			/* translators: %d: number of attribute labels translated */
			'done_count'  => __( '%d label(s) translated.', 'lingua-forge' ),
			'error'       => __( 'Translation failed. Check the browser console.', 'lingua-forge' ),
		] );
		?>
		<script>
		(function($){
			var i18n = <?php echo $i18n; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output is safe ?>;
			// Move the button before the "Add new attribute" heading — the only
			// available WooCommerce hook fires inside the <form>, after the <h2>.
			$(function(){ $('.lf-translate-all-attr-labels').closest('.form-field').insertBefore('#col-left h2'); });
			$(document).on('click', '.lf-translate-all-attr-labels', function(){
				var btn    = $(this),
					status = btn.siblings('.lf-translate-attr-status'),
					nonce  = btn.attr('data-nonce');

				btn.prop('disabled', true);
				status.show().text(i18n.translating);

				$.post(ajaxurl, {
					action: '<?php echo esc_js( self::AJAX_TRANSLATE_ALL ); ?>',
					nonce:  nonce
				})
				.done(function(resp){
					if (resp.success) {
						var n   = resp.data.translated,
							msg = n > 0
								? i18n.done_count.replace('%d', n)
								: i18n.done_none;
						status.text(msg);
					} else {
						status.text(resp.data && resp.data.message ? resp.data.message : i18n.error);
					}
				})
				.fail(function(){ status.text(i18n.error); })
				.always(function(){
					btn.prop('disabled', false);
					setTimeout(function(){ status.fadeOut(); }, 5000);
				});
			});
		}(jQuery));
		</script>
		<?php
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return all active languages except the source language.
	 *
	 * @return string[]
	 */
	private static function non_source_languages(): array {
		$router      = Router::get_instance();
		$source_lang = $router->source_language();
		$all         = $router->languages();
		return array_values( array_filter( $all, fn( string $l ) => $l !== $source_lang ) );
	}
}
