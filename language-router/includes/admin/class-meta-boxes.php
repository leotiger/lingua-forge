<?php
/**
 * Class LinguaForge\Router\Admin\MetaBoxes
 *
 * Registers and renders all post-editor meta boxes for the Language Router:
 * Language, Template, Translations, and Source Footnotes.
 * Also owns the AJAX handlers for language changes and translation imports.
 */

namespace LinguaForge\Router\Admin;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class MetaBoxes {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HELPERS
	// =========================================================

	/**
	 * Returns true if $post_type has been excluded from Lingua Forge routing
	 * via the admin System panel (option: linguaforge_secondary_query_excluded_types).
	 * Excluded CPTs must not receive any LF meta boxes.
	 */
	private function is_post_type_excluded( string $post_type ): bool {
		$saved    = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$excluded = $saved !== ''
			? array_filter( array_map( 'trim', explode( ',', $saved ) ) )
			: [];

		// Filterable so third-party plugins can add (or remove) post types
		// without touching the System panel option.
		$excluded = (array) apply_filters( 'linguaforge_metabox_excluded_post_types', $excluded );

		return in_array( $post_type, $excluded, true );
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		add_action( 'admin_menu',  [ $this, 'add_navigation_menu_page' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_language_meta_box' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_template_meta_box' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_translations_meta_box' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_source_footnotes_meta_box' ] );
		add_action( 'wp_ajax_lf_import_translation', [ $this, 'ajax_import_translation' ] );
		add_action( 'wp_ajax_lf_set_language',       [ $this, 'ajax_set_language' ] );
		add_action( 'wp_ajax_lf_set_user_locale',    [ $this, 'ajax_set_user_locale' ] );
		// Admin-bar locale switcher — quick locale toggle without visiting User Profile.
		add_action( 'admin_bar_menu', [ $this, 'add_locale_admin_bar_node' ], 999 );
		add_action( 'admin_head',     [ $this, 'output_locale_admin_bar_script' ] );
	}

	// =========================================================
	// NAVIGATION MENU PAGE
	// =========================================================

	public function add_navigation_menu_page(): void {
		add_menu_page(
			'Navigation (List)',
			'Navigation (List)',
			'edit_posts',
			'edit.php?post_type=wp_navigation',
			'',
			'dashicons-menu',
			20
		);
	}

	// =========================================================
	// LANGUAGE META BOX
	// =========================================================

	public function add_language_meta_box( string $post_type ): void {
		if ( $this->is_post_type_excluded( $post_type ) ) {
			return;
		}
		add_meta_box(
			'lf_lang',
			'Language',
			[ $this, 'render_language_meta_box' ],
			$post_type,
			'side'
		);
	}

	public function render_language_meta_box( $post ): void {
		// Dedicated nonce — verified in handle_save_post() before lf_lang is read.
		// Independent of the post-edit nonce so CSRF on third-party POST endpoints
		// can't rebind a post's language via a save_post side effect.
		wp_nonce_field( 'lf_language_save', 'lf_language_nonce' );

		$cur     = $this->router->trid_group->get_lang( $post->ID );
		$exclude = (bool) get_post_meta( $post->ID, '_lf_page_menu_exclude', true );

		echo '<select name="lf_lang" class="lf-lr-lang" id="lf_lr_lang">';
		foreach ( $this->router->context->languages() as $l ) {
			echo '<option value="' . esc_attr( $l ) . '" ' . selected( $cur, $l, false ) . '>' . esc_html( strtoupper( $l ) ) . '</option>';
		}
		echo '</select>';

		echo '<p style="margin-top:10px">';
		echo '<label>';
		echo '<input type="checkbox" name="lf_page_menu_exclude" value="1"' . checked( $exclude, true, false ) . ' />';
		echo ' ' . esc_html__( 'Exclude from navigation menus', 'lingua-forge' );
		echo '</label>';
		echo '</p>';
		echo '<p style="margin-top:4px">';
		echo '<label>';
		// "Apply to all" is a one-shot action — never pre-checked.
		echo '<input type="checkbox" name="lf_page_menu_exclude_all" value="1" />';
		echo ' ' . esc_html__( 'Apply to all language versions', 'lingua-forge' );
		echo '</label>';
		echo '</p>';

		$noindex = (bool) get_post_meta( $post->ID, '_lf_noindex', true );
		echo '<p style="margin-top:10px">';
		echo '<label>';
		echo '<input type="checkbox" name="lf_noindex" value="1"' . checked( $noindex, true, false ) . ' />';
		echo ' ' . esc_html__( 'Noindex (hide this language version from search engines)', 'lingua-forge' );
		echo '</label>';
		echo '</p>';
	}

	// =========================================================
	// TEMPLATE META BOX
	// =========================================================

	public function add_template_meta_box( string $post_type ): void {
		if ( $this->is_post_type_excluded( $post_type ) ) {
			return;
		}
		add_meta_box(
			'lf_page_template',
			'Template',
			[ $this, 'render_template_meta_box' ],
			$post_type,
			'side',
			'default'
		);
	}

	public function render_template_meta_box( $post ): void {
		// Show for post, page, and any public CPT managed by Lingua Forge.
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || ! $pto->public ) return;
		// wp_navigation_fallback is intentionally absent: the `! $pto->public`
		// guard two lines above already rejects it before this list is reached.
		$internal = [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_navigation',
			'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
		];
		if ( in_array( $post->post_type, $internal, true ) ) return;

		$current = get_post_meta( $post->ID, '_wp_page_template', true ) ?: 'default';

		// get_block_templates() with no post_type filter returns all registered
		// templates (theme + plugin, DB-stored + filesystem) — same set that
		// WP core exposes in Quick Edit.
		$templates   = get_block_templates();
		$post_lang   = $this->router->trid_group->get_lang( $post->ID );
		$valid_langs = $this->router->context->languages();

		// ── Determine the relevant template bases for this post type ──────────
		// For `page` → ['page', 'singular']
		// For `post` → ['single', 'singular']
		// For CPT (e.g. product) → ['single-product', 'single', 'singular']
		// These are the base slugs (without lang suffix) we accept. A template
		// whose base is not in this list is for a different post type and is
		// silently excluded so products don't see page-ca, etc.
		if ( $post->post_type === 'page' ) {
			$bases = [ 'page', 'singular' ];
			// Include front-page-{lang} templates only when this page is the
			// static front page or a translation of it (same trid group).
			// For all other pages, front-page templates are irrelevant.
			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id > 0 ) {
				$post_trid  = $this->router->trid_group->get_trid( $post->ID );
				$front_trid = $this->router->trid_group->get_trid( $front_id );
				if ( $post->ID === $front_id ||
					( null !== $post_trid && $post_trid === $front_trid ) ) {
					$bases[] = 'front-page';
				}
			}
		} elseif ( $post->post_type === 'post' ) {
			$bases = [ 'single', 'singular' ];
		} else {
			$bases = [ 'single-' . $post->post_type, 'single', 'singular' ];
		}

		wp_nonce_field( 'lf_template_save', 'lf_template_nonce' );
		echo '<select name="lf_page_template" style="width:100%">';
		echo '<option value="default"' . selected( $current, 'default', false ) . '>Default</option>';

		$current_rendered = false;

		foreach ( $templates as $tpl ) {
			$slug = $tpl->slug;

			// Extract any recognised language suffix from the slug
			// (e.g. "single-product-ca" → lang "ca", base "single-product").
			// Same {2,3}-char cap used in extractLangFromSlug() JS / class-scripts.php.
			$tpl_lang = '';
			if ( preg_match( '/-([a-z]{2,3}(?:-[a-z]{2,4})?)$/', $slug, $m )
				&& in_array( $m[1], $valid_langs, true ) ) {
				$tpl_lang = $m[1];
			}

			// Language filter: skip templates that belong to a different language.
			if ( $tpl_lang !== '' && $tpl_lang !== $post_lang ) continue;

			// Post-type filter: derive the base slug (strip lang suffix if present)
			// and check it against the relevant bases for this post type.
			$base = $tpl_lang !== ''
				? substr( $slug, 0, -( strlen( $tpl_lang ) + 1 ) )
				: $slug;
			if ( ! in_array( $base, $bases, true ) ) continue;

			if ( $slug === $current ) {
				$current_rendered = true;
			}

			$label = $tpl->title ?: $slug;
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}

		// If the assigned template was not in the filtered list (e.g. it was
		// created after this page load or belongs to a plugin not yet active),
		// add it as an explicit selected option so the UI reflects reality
		// rather than silently falling back to "Default" visually.
		if ( ! $current_rendered && $current !== 'default' ) {
			echo '<option value="' . esc_attr( $current ) . '" selected="selected">' . esc_html( $current ) . ' *</option>';
		}

		echo '</select>';
		echo '<p style="margin-top:8px;color:#666;">Current: <code>' . esc_html( $current ) . '</code></p>';
	}

	// =========================================================
	// TRANSLATIONS META BOX
	// =========================================================

	public function add_translations_meta_box( string $post_type ): void {
		if ( $this->is_post_type_excluded( $post_type ) ) {
			return;
		}
		add_meta_box(
			'lf_trans',
			'Translations',
			[ $this, 'render_translations_meta_box' ],
			$post_type,
			'side'
		);
	}

	public function render_translations_meta_box( $post ): void {
		// Dedicated nonce — verified in handle_save_post() before any
		// lf_trans_* post-id input is consumed. Prevents a forged save from
		// rewriting a post's TRID translation group via cross-post side effects.
		wp_nonce_field( 'lf_translations_save', 'lf_translations_nonce' );

		$current_lang = $this->router->trid_group->get_lang( $post->ID );
		$translations = $this->router->trid_group->get_translations( $post->ID );

		echo '<p><strong>Current language:</strong> ' . esc_html( strtoupper( $current_lang ) ) . '</p>';

		// Pre-sort languages into linked (TRID-connected post exists) and
		// unlinked (no post yet).  An ID in the translations map is only
		// considered "linked" when the post actually exists — this prevents
		// stale TRID entries (e.g. a post whose language was changed after
		// translations were linked) from surfacing spurious Override buttons.
		$linked   = []; // [ lang => post_id ]
		$unlinked = []; // [ lang, … ]

		// Sort by language code so both the linked and unlinked lists render in
		// a predictable order rather than Context::languages()'s discovery order.
		$langs = $this->router->context->languages();
		sort( $langs );

		foreach ( $langs as $l ) {
			if ( $l === $current_lang ) {
				continue;
			}
			$id = isset( $translations[ $l ] ) ? (int) $translations[ $l ] : 0;
			if ( $id && get_post( $id ) instanceof \WP_Post ) {
				$linked[ $l ] = $id;
			} else {
				$unlinked[] = $l;
			}
		}

		// ── Linked languages (expanded) ──────────────────────────────────────
		foreach ( $linked as $l => $id ) {
			echo '<p><strong>' . esc_html( strtoupper( $l ) ) . '</strong>';
			if ( $this->router->sync->is_outdated( $id ) ) {
				echo ' ⚠';
			}
			echo '<br>';

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- wp_dropdown_pages() escapes all its own output; meta_key/_lf_lang is indexed and intentional for per-language page filtering.
			wp_dropdown_pages( [
				'name'             => 'lf_trans_' . $l,
				'show_option_none' => '—',
				'meta_key'         => '_lf_lang',
				'meta_value'       => $l,
				'include'          => [ $id ],
				'selected'         => $id,
			] );
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

			echo '<br>';
			echo '<button type="button" class="button lf-import" data-lang="' . esc_attr( $l ) . '">Override</button>';
			echo '</p>';
		}

		// ── Unlinked languages (collapsed) ───────────────────────────────────
		// Displayed inside a native <details> element so the panel stays compact
		// when many languages have no translation yet.  Each row still renders a
		// dropdown so the editor can establish the TRID link on save; no Override
		// button is shown because there is nothing to override.
		if ( ! empty( $unlinked ) ) {
			$count = count( $unlinked );
			echo '<details class="lf-unlinked-langs">';
			echo '<summary class="lf-unlinked-langs__summary">';
			echo esc_html( sprintf(
				/* translators: %d: number of languages without a translation post */
				_n( '%d language not yet linked', '%d languages not yet linked', $count, 'lingua-forge' ),
				$count
			) );
			echo '</summary>';
			echo '<div class="lf-unlinked-langs__list">';
			foreach ( $unlinked as $l ) {
				echo '<p class="lf-unlinked-langs__row">';
				echo '<strong>' . esc_html( strtoupper( $l ) ) . '</strong><br>';
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- wp_dropdown_pages() escapes all its own output; meta_key/_lf_lang is indexed and intentional for per-language page filtering.
				wp_dropdown_pages( [
					'name'             => 'lf_trans_' . $l,
					'show_option_none' => '—',
					'meta_key'         => '_lf_lang',
					'meta_value'       => $l,
				] );
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				echo '</p>';
			}
			echo '</div>';
			echo '</details>';
		}
	}

	// =========================================================
	// SOURCE FOOTNOTES META BOX
	// =========================================================

	public function add_source_footnotes_meta_box(): void {

		// Footnotes are a Gutenberg-only feature (UUID-based block state).
		// Exclude post types that never use the block editor — WooCommerce
		// products being the primary case — to avoid a confusing empty box.
		// Filterable so third-party CPTs can opt in or out as needed.
		$excluded = (array) apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			'linguaforge_source_footnotes_excluded_post_types',
			[ 'product' ]
		);

		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
			if ( in_array( $type, $excluded, true ) ) {
				continue;
			}
			if ( $this->is_post_type_excluded( $type ) ) {
				continue;
			}
			add_meta_box(
				'lf_source_footnotes',
				'Source Footnotes',
				[ $this, 'render_source_footnotes_meta_box' ],
				$type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Show the source page's footnotes as a read-only reference on translation pages.
	 *
	 * Footnotes are stripped from imported content (Gutenberg's UUID-based system
	 * makes cross-page copying fragile). This metabox lets translators see what
	 * footnotes the source has so they can recreate them manually via the block editor.
	 */
	public function render_source_footnotes_meta_box( $post ): void {
		$lang = $this->router->trid_group->get_lang( $post->ID );

		// Only relevant on non-source translation pages.
		if ( $lang === $this->router->context->source_language() ) {
			echo '<p style="color:#888;">' . esc_html__('This is the source page. Footnotes are edited directly in the block editor.', 'lingua-forge') . '</p>';
			return;
		}

		$translations = $this->router->trid_group->get_translations( $post->ID );
		$source_id    = $translations[ $this->router->context->source_language() ] ?? 0;

		if ( ! $source_id ) {
			echo '<p style="color:#888;">' . esc_html__('No source page linked yet.', 'lingua-forge') . '</p>';
			return;
		}

		$raw = get_post_meta( $source_id, 'footnotes', true );

		if ( empty( $raw ) || $raw === '[]' ) {
			echo '<p style="color:#888;">' . esc_html__('The source page has no footnotes.', 'lingua-forge') . '</p>';
			return;
		}

		$footnotes = json_decode( $raw, true );

		if ( ! is_array( $footnotes ) || empty( $footnotes ) ) {
			echo '<p style="color:#888;">' . esc_html__('The source page has no footnotes.', 'lingua-forge') . '</p>';
			return;
		}

		echo '<p style="color:#888;font-style:italic;">'
			. esc_html__('These footnotes come from the source page and are shown here for reference only. Add them to this page using the block editor.', 'lingua-forge')
			. '</p>';
		echo '<ol style="margin-left:1.5em;">';
		foreach ( $footnotes as $fn ) {
			echo '<li style="margin-bottom:.5em;">' . wp_kses_post( $fn['content'] ?? '' ) . '</li>';
		}
		echo '</ol>';
	}

	// =========================================================
	// AJAX — SET LANGUAGE
	// =========================================================

	/**
	 * AJAX handler: atomically set language + assign template.
	 *
	 * Called by the JS language-change handler via fetch() BEFORE location.reload(),
	 * guaranteeing the DB writes are committed when the reload's GET fires.
	 * This eliminates the race between Gutenberg's isSavingPost() becoming false
	 * (which triggers the reload) and the metabox form POST completing.
	 *
	 * Action:  wp_ajax_lf_set_language
	 * POST:    nonce, post_id, lang
	 * Returns: {success:true, data:{lang, template}} on success.
	 */
	public function ajax_set_language(): void {
		check_ajax_referer( 'lf_set_language_nonce', 'nonce' );

		$post_id = intval( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$lang    = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Permission denied', 403 );
		}

		// Allow the source language through (empty string maps to source)
		// but reject anything that isn't in our languages list.
		if ( $lang !== '' && ! $this->router->context->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid language', 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( 'Post not found', 404 );
		}

		// If lang is empty string, treat as source language.
		if ( $lang === '' ) {
			$lang = $this->router->context->source_language();
		}

		$this->router->trid_group->set_lang( $post_id, $lang );
		// Flush the TRID object-cache entry immediately so the next page load
		// (triggered by location.reload() in the JS) reads fresh DB state.
		// set_lang() only calls update_post_meta(), which does not fire
		// wp_after_insert_post — the normal cache-clear hook — so without this
		// line the stale group (e.g. ['en' => $post_id]) survives in Redis and
		// the Translations metabox renders a spurious Override button on reload.
		$this->router->trid_group->clear_translation_cache( $post_id );
		$this->router->sync->force_lang_template( $post_id, $post, $lang );

		wp_send_json_success( [
			'lang'     => $lang,
			'template' => (string) ( get_post_meta( $post_id, '_wp_page_template', true ) ?: 'default' ),
		] );
	}

	// =========================================================
	// AJAX — IMPORT TRANSLATION
	// =========================================================

	public function ajax_import_translation(): void {
		check_ajax_referer( 'lf_import_translation_nonce', 'nonce' );

		$target_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$source_lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

		// Validate the requested source language; fall back to the primary language
		// if the parameter is missing or not in the active language list.
		if ( ! $source_lang || ! $this->router->context->is_valid_lang( $source_lang ) ) {
			$source_lang = $this->router->context->source_language();
		}

		$translations = $this->router->trid_group->get_translations( $target_id );
		$source_id    = $translations[ $source_lang ] ?? 0;

		if ( ! $source_id ) wp_send_json_error( 'No source found for language: ' . $source_lang );
		if ( $target_id === $source_id ) wp_send_json_error( 'Cannot update from itself' );

		$source = get_post( $source_id );
		$target = get_post( $target_id );

		if ( ! $source || ! $target ) wp_send_json_error();

		$original_lang = $this->router->trid_group->get_lang( $target_id );
		$content       = $source->post_content;
		$blocks        = parse_blocks( $content );
		$content       = serialize_blocks( $blocks );

		// Strip all footnote markup from the imported content.
		// Gutenberg footnotes are tightly coupled to post-specific UUIDs and
		// internal block state — copying them verbatim breaks the block editor on
		// the target page. The source footnotes are displayed in a read-only
		// metabox on the target page so the translator can recreate them manually.
		//
		// 1. Remove the footnotes block comment.
		$content = preg_replace( '/<!--\s*wp:footnotes\s*\/-->\n?/', '', $content );
		// 2. Remove inline footnote markers (<sup data-fn="…">…</sup>), leaving
		//    the surrounding prose intact.
		$content = preg_replace( '/<sup[^>]+data-fn="[^"]*"[^>]*>.*?<\/sup>/s', '', $content );

		wp_update_post( [
			'ID'           => $target_id,
			'post_title'   => $source->post_title,
			'post_content' => $content,
			'post_excerpt' => $source->post_excerpt,
		] );

		// Reset footnotes meta to an empty array so the block editor starts from
		// a clean state identical to a fresh page. Without this, stale UUID data
		// from the target's previous content remains in the meta, causing the
		// editor's footnotes store to initialise in an inconsistent state
		// (meta has UUIDs, content has none) which crashes the footnotes block.
		update_post_meta( $target_id, 'footnotes', '[]' );

		$this->router->trid_group->set_lang( $target_id, $original_lang );

		$source_time = get_post_meta( $source_id, '_lf_source_updated_at', true );
		update_post_meta( $target_id, '_lf_translation_source_updated_at', $source_time );

		wp_send_json_success();
	}

	// =========================================================
	// AJAX — SET USER LOCALE (Editor Locale Switcher)
	// =========================================================

	/**
	 * Switch the current admin/editor user's WordPress locale so the block editor
	 * canvas and plugin translations render in the target language. Accepts a Lingua
	 * Forge language code ('ca', 'de', …) and maps it to a WP locale via
	 * locale_from_lang(). Passing the source language resets the user locale to the
	 * WP site default (empty string).
	 *
	 * Action:  wp_ajax_lf_set_user_locale
	 * POST:    nonce, lang
	 */
	public function ajax_set_user_locale(): void {
		check_ajax_referer( 'lf_set_user_locale_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied', 403 );
		}

		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		// 'default' = revert to site language.
		if ( $lang === 'default' ) {
			wp_update_user( [ 'ID' => get_current_user_id(), 'locale' => '' ] );
			wp_send_json_success();
		}

		if ( ! $this->router->context->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid language', 400 );
		}

		$locale = $this->router->locale_from_lang( $lang );
		wp_update_user( [ 'ID' => get_current_user_id(), 'locale' => $locale ] );
		wp_send_json_success( [ 'locale' => $locale ] );
	}

	// =========================================================
	// ADMIN BAR — LOCALE SWITCHER
	// =========================================================

	/**
	 * Add a "Preview Language" node to the WP admin bar so any logged-in editor
	 * can switch their user locale from any admin page — without visiting their
	 * User Profile. Reuses the existing lf_set_user_locale AJAX endpoint.
	 *
	 * Appears as a globe icon + two-letter code in the top-right bar cluster,
	 * with a flyout listing every active language. A ✓ marks the current one.
	 *
	 * $current_lang is resolved exactly once, then reused for both the parent
	 * label and every flyout item's checkmark — previously the flyout loop
	 * independently re-derived "is this language active?" per item by comparing
	 * locale_from_lang( $lang ) against $user_locale again. Two different
	 * language codes that happen to resolve to the identical locale string (e.g.
	 * an active router language with no fallback-map entry, which
	 * locale_from_lang() silently resolves to 'en_US' — colliding with English
	 * itself) would then BOTH satisfy that per-item check, showing two
	 * checkmarks and, since the top loop has the same flaw, a parent label that
	 * could pick the wrong one of the two (confirmed live: an unmapped 'yo'
	 * router language showed as the current language and was double-checked
	 * alongside the real current language, 'en'). Comparing against the single
	 * $current_lang value instead makes exactly one item active by construction,
	 * regardless of any such collision — see also the locale_from_lang()
	 * fallback-map fix in class-locale-detector.php, which addresses the
	 * collision itself.
	 */
	public function add_locale_admin_bar_node( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$languages = $this->router->context->languages();
		if ( count( $languages ) < 2 ) {
			return;
		}

		$user_locale  = get_user_locale();
		$source_lang  = $this->router->context->source_language();
		$current_lang = $source_lang;

		foreach ( $languages as $lang ) {
			$locale = $this->router->locale_from_lang( $lang );
			if ( $locale === $user_locale || ( $lang === $source_lang && $user_locale === '' ) ) {
				$current_lang = $lang;
				break;
			}
		}

		// Parent node — globe icon + active language code.
		$wp_admin_bar->add_node( [
			'id'    => 'lf-locale-switcher',
			'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span>'
			           . '<span class="ab-label">' . esc_html( strtoupper( $current_lang ) ) . '</span>',
			'href'  => '',
			'meta'  => [ 'class' => 'lf-admin-locale-node' ],
		] );

		$nonce    = wp_create_nonce( 'lf_set_user_locale_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );

		foreach ( $languages as $lang ) {
			$is_active = ( $lang === $current_lang );

			$wp_admin_bar->add_node( [
				'parent' => 'lf-locale-switcher',
				'id'     => 'lf-locale-' . $lang,
				'title'  => ( $is_active ? '&#10003;&nbsp;' : '&nbsp;&nbsp;&nbsp;' ) . esc_html( strtoupper( $lang ) ),
				'href'   => '#',
				'meta'   => [
					'class'   => 'lf-locale-item' . ( $is_active ? ' lf-locale-current' : '' ),
					'onclick' => 'lfSetAdminLocale('
					             . wp_json_encode( $lang ) . ','
					             . wp_json_encode( $nonce ) . ','
					             . wp_json_encode( $ajax_url )
					             . ');return false;',
				],
			] );
		}
	}

	/**
	 * Output the tiny JS helper used by the admin-bar locale nodes.
	 * POSTs to lf_set_user_locale and reloads on success.
	 */
	public function output_locale_admin_bar_script(): void {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( count( $this->router->context->languages() ) < 2 ) {
			return;
		}
		?>
		<script>
		function lfSetAdminLocale( lang, nonce, ajaxUrl ) {
			var body = new URLSearchParams( { action: 'lf_set_user_locale', nonce: nonce, lang: lang } );
			fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function() { location.reload(); } );
		}
		</script>
		<style>
		#wpadminbar #wp-admin-bar-lf-locale-switcher .ab-icon.dashicons {
			font: 400 20px/1 dashicons;
			vertical-align: middle;
			margin-right: 4px;
			top: 2px;
			position: relative;
		}
		#wpadminbar #wp-admin-bar-lf-locale-switcher > .ab-item { font-weight: 600; }
		#wpadminbar .lf-locale-current > .ab-item { font-weight: 700; }
		</style>
		<?php
	}
}
