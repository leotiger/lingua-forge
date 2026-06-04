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
 *   - {taxonomy}_edit_form_fields   — adds fields on the Edit Term screen
 *   - {taxonomy}_add_form_fields    — adds fields on the Add Term screen
 *   - edited_{taxonomy}             — saves on Edit Term submit
 *   - created_{taxonomy}            — saves on Add Term submit
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

defined( 'ABSPATH' ) || exit;

class TermNameAdmin {

	// =========================================================================
	// Boot
	// =========================================================================

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
			// Add-term screen (new term form)
			add_action( "{$taxonomy}_add_form_fields",  [ self::class, 'render_add_fields' ],  10, 1 );
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

		echo '<div class="form-field lf-term-names">';
		echo '<strong>' . esc_html__( 'Translated Names', 'lingua-forge' ) . '</strong>';
		echo '</div>';

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
