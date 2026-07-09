<?php
/**
 * Class LinguaForge\Router\Translation\Sync
 *
 * Tracks source-vs-translation freshness (the "outdated" flag), handles FSE
 * template auto-assignment based on language, and owns the wp_after_insert_post
 * save handler that ties it all together.
 */

namespace LinguaForge\Router\Translation;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Sync {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Priority 10 — fires before the cache-clear hook at 20.
		add_action( 'wp_after_insert_post', [ $this, 'handle_save_post' ], 10, 2 );

		// Priority 11 — fires after WordPress's own _wp_auto_add_pages_to_menus (priority 10).
		add_action( 'publish_page', [ $this, 'remove_cross_language_menu_auto_add' ], 11 );
	}

	// =========================================================
	// OUTDATED TRACKING
	// =========================================================

	public function mark_source_updated( int $post_id ): void {
		update_post_meta( $post_id, '_lf_source_updated_at', time() );
	}

	public function mark_translation_synced( int $post_id ): void {
		$translations = $this->router->trid_group->get_translations( $post_id );
		$source_id    = $translations[$this->router->context->source_language()] ?? 0;
		if ( ! $source_id ) return;

		$source_time = get_post_meta( $source_id, '_lf_source_updated_at', true );
		update_post_meta( $post_id, '_lf_translation_source_updated_at', $source_time );
	}

	public function is_outdated( int $post_id ): bool {
		$lang = $this->router->trid_group->get_lang( $post_id );
		if ( $lang === $this->router->context->source_language() ) return false;

		$source = get_post_meta( $post_id, '_lf_source_updated_at', true );
		$trans  = get_post_meta( $post_id, '_lf_translation_source_updated_at', true );

		if ( ! $source ) return false;
		if ( ! $trans  ) return true;

		return (int) $trans < (int) $source;
	}

	public function resolve_template_for_lang( $post, string $lang ): ?string {
		if ( ! $post || ! $lang ) return null;

		// Primary language uses WordPress's default template hierarchy
		// (page, single, etc.) — no language suffix is expected, and no
		// override is offered here: linguaforge_template_for_lang only fires
		// once LF has actually decided to assign an explicit, language-specific
		// template. Forcing one onto the source-language post is out of scope
		// (it would also change the "revert to default on language change back
		// to source" branch in assign_template_if_needed(), which currently
		// assumes a null return means "nothing to do here").
		if ( $lang === $this->router->context->source_language() ) return null;

		$type     = $post->post_type;
		$resolved = null;

		if ( $type === 'page' ) {
			// Use front-page-{lang} when this page is the static front page or
			// a translation of it — but only when the active theme actually ships
			// a base front-page.html. WordPress applies front-page.html
			// automatically for the static front page ONLY when the theme has
			// one; a theme without it uses page.html for the front page just
			// like any other static page, so assigning 'front-page-{lang}' on
			// such a theme would point at a template WordPress would never
			// select at the source language either. Fall through to the normal
			// 'page' base in that case.
			// Scoped lookup (theme//slug), not the generic template_exists() helper —
			// that helper's get_block_templates() fallback matches the slug across
			// ANY theme/plugin namespace, which would false-positive if some other
			// registered template happens to share the bare 'front-page' slug.
			$front_id            = (int) get_option( 'page_on_front' );
			$has_base_front_page = null !== get_block_template( get_stylesheet() . '//front-page' );
			if ( $front_id > 0 && $has_base_front_page ) {
				$post_trid  = $this->router->trid_group->get_trid( $post->ID );
				$front_trid = $this->router->trid_group->get_trid( $front_id );
				if ( $post->ID === $front_id ||
					( null !== $post_trid && $post_trid === $front_trid ) ) {
					$resolved = 'front-page-' . $lang;
				}
			}
			if ( $resolved === null ) {
				$resolved = 'page-' . $lang;
			}
		} elseif ( $type === 'post' ) {
			$resolved = 'single-' . $lang;
		} else {
			$resolved = 'single-' . $type . '-' . $lang; // CPT: e.g. single-product-es
		}

		/**
		 * Filter the language-specific FSE template slug Lingua Forge is about
		 * to assign to a translated post. Runs for every path that can assign a
		 * template — a normal editor save, the WP-CLI translate/retranslate
		 * commands, the post-list "Sync" button, and programmatic creation via
		 * linguaforge_trigger_translation()/linguaforge_queue_translation() —
		 * since they all resolve through this one method. Never fires for the
		 * source-language post (see the early return above).
		 *
		 * Return an empty string or null to suppress assignment entirely for
		 * this post/language — assign_template_if_needed() treats that exactly
		 * like the "no template" case, reverting a previously auto-assigned
		 * template to 'default' if one was set, and never touching an
		 * explicit user choice.
		 *
		 * @param string   $resolved The slug LF computed, e.g. 'single-agnosis_artwork-es'.
		 * @param \WP_Post $post     The post being resolved for.
		 * @param string   $lang     Target language code.
		 */
		$filtered = apply_filters( 'linguaforge_template_for_lang', $resolved, $post, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return ( $filtered === null || $filtered === '' ) ? null : (string) $filtered;
	}

	public function template_exists( string $slug ): bool {
		// ── 1. Direct filesystem check (most reliable) ────────────────────────
		// File-based FSE templates live at {theme}/templates/{slug}.html.
		// They have NO wp_template DB row until the user edits them in the
		// Site Editor, so any API-only approach misses them entirely.
		// Check the child theme first, then the parent theme.
		foreach ( [ get_stylesheet_directory(), get_template_directory() ] as $theme_dir ) {
			if ( file_exists( $theme_dir . '/templates/' . $slug . '.html' ) ) {
				return true;
			}
		}

		// ── 2. Block-template API (covers DB-stored / customised templates) ───
		// Minimum WP is 6.4; get_block_templates() is always available.
		return ! empty( get_block_templates( [ 'slug__in' => [ $slug ] ] ) );
	}

	/**
	 * Unconditionally assigns the language-specific FSE template when the
	 * user explicitly changes the Language metabox (nonce-verified POST).
	 * No existence check, no user-chosen-template guard — the user said
	 * "this post is in language X" and we honour that immediately.
	 *
	 * Reverting to the source language clears any auto-assigned template.
	 */
	public function force_lang_template( int $post_id, $post, string $lang ): void {
		$template_slug = $this->resolve_template_for_lang( $post, $lang );

		if ( $template_slug ) {
			update_post_meta( $post_id, '_wp_page_template', $template_slug );
			update_post_meta( $post_id, '_lf_auto_template', $template_slug );
		} else {
			// Source language — revert to default if we auto-assigned the template.
			$auto_prev = (string) get_post_meta( $post_id, '_lf_auto_template', true );
			if ( $auto_prev ) {
				update_post_meta( $post_id, '_wp_page_template', 'default' );
				delete_post_meta( $post_id, '_lf_auto_template' );
			}
		}
	}

	public function assign_template_if_needed( int $post_id, $post, string $lang ): void {
		$template_slug = $this->resolve_template_for_lang( $post, $lang );
		$auto_prev     = (string) get_post_meta( $post_id, '_lf_auto_template', true );
		$current       = (string) ( get_post_meta( $post_id, '_wp_page_template', true ) ?: '' );

		// Back-compat / migration: _lf_auto_template is new in 1.3.3.
		// If it has not been recorded yet but the current template matches the
		// {base}-{lang} naming convention for any active language, treat it as
		// a previously auto-assigned template so a language change can replace it.
		if ( empty( $auto_prev ) && ! empty( $current ) && $current !== 'default' ) {
			// Determine the template base name using the same logic as
			// resolve_template_for_lang(): 'page', 'single', or 'single-{cpt}'.
			if ( $post->post_type === 'page' ) {
				$base = 'page';
			} elseif ( $post->post_type === 'post' ) {
				$base = 'single';
			} else {
				$base = 'single-' . $post->post_type;
			}
			foreach ( $this->router->context->languages() as $l ) {
				if ( $current === $base . '-' . $l ) {
					$auto_prev = $current;
					break;
				}
			}
		}

		// Changing to the source language: revert _wp_page_template to 'default'
		// only when the current template was auto-assigned by us — never touch a
		// template the editor chose explicitly.
		if ( ! $template_slug ) {
			if ( $auto_prev && $current === $auto_prev ) {
				update_post_meta( $post_id, '_wp_page_template', 'default' );
				delete_post_meta( $post_id, '_lf_auto_template' );
			}
			return;
		}

		// No template_exists() guard here.  WordPress silently falls through to
		// its normal template hierarchy when _wp_page_template holds a slug
		// that is not registered, so assigning an as-yet-uncreated slug is
		// harmless: the correct template will be used as soon as the theme
		// file exists.  Any existence check that runs in the wp_after_insert_post
		// context (file_exists, get_block_templates) can silently return false
		// and leave the meta unset, which is the exact bug we are fixing.

		// If the slug is already set to what we'd assign, just ensure the
		// tracking key is recorded and return — avoids a redundant meta write.
		if ( $current === $template_slug ) {
			update_post_meta( $post_id, '_lf_auto_template', $template_slug );
			return;
		}

		// Protect only explicitly user-chosen templates — i.e. non-empty,
		// non-default, and NOT one we previously auto-assigned.  A previous
		// auto-assignment (tracked in _lf_auto_template, or matching the
		// {base}-{lang} naming convention for back-compat) is always replaceable
		// when the language changes.
		if ( ! empty( $current ) && $current !== 'default' && $current !== $auto_prev ) return;

		update_post_meta( $post_id, '_wp_page_template', $template_slug );
		update_post_meta( $post_id, '_lf_auto_template', $template_slug );
	}

	// =========================================================
	// MENU AUTO-ADD GUARD
	// =========================================================

	/**
	 * Prevents translated pages from being auto-added to navigation menus.
	 *
	 * WordPress's _wp_auto_add_pages_to_menus() fires on `publish_page` at
	 * priority 10 and adds new top-level pages to any classic nav menu that has
	 * "Automatically add new top-level pages" enabled. This is language-unaware:
	 * a newly published French page would be inserted into every auto-add menu,
	 * including the primary (source-language) menu.
	 *
	 * This callback runs at priority 11 — after the auto-add — and removes any
	 * item that references a non-source-language page. Source-language pages are
	 * allowed through normally; our translate_menu_items() filter in Redirector
	 * already handles dynamic URL swapping to translated permalinks at render time.
	 *
	 * @param int $post_id Published page ID.
	 */
	public function remove_cross_language_menu_auto_add( int $post_id ): void {
		$page_lang = get_post_meta( $post_id, '_lf_lang', true );
		if ( ! $page_lang ) return;
		if ( $page_lang === $this->router->context->source_language() ) return;

		$nav_menu_options = get_option( 'nav_menu_options', [] );
		if ( empty( $nav_menu_options['auto_add'] ) ) return;

		foreach ( (array) $nav_menu_options['auto_add'] as $menu_id ) {
			$menu_id = (int) $menu_id;
			$items   = wp_get_nav_menu_items( $menu_id );
			if ( ! $items ) continue;

			$removed = false;
			foreach ( $items as $item ) {
				if ( $item->type === 'post_type' && (int) $item->object_id === $post_id ) {
					wp_delete_post( (int) $item->ID, true );
					$removed = true;
				}
			}

			if ( $removed ) {
				// wp_delete_post() clears the item's own post cache but not the
				// nav menu items group cache keyed by menu term ID. Clear it
				// explicitly so the removed item doesn't persist until cache expiry.
				clean_term_cache( $menu_id, 'nav_menu' );
			}
		}
	}

	// =========================================================
	// HELPERS
	// =========================================================

	/**
	 * WordPress-internal post types that must never be treated as translatable
	 * content, regardless of their 'public' flag.
	 *
	 * INTENTIONAL DIVERGENCE — wp_navigation is NOT in this list.
	 * FSE navigation posts (wp_navigation) are public=>false, so they would
	 * normally be skipped by the `$pto->public` check in handle_save_post().
	 * However, the caller explicitly allows them through before this guard so
	 * they receive `_lf_lang` / `_lf_trid` assignment. Adding wp_navigation
	 * here would silently break FSE navigation translation.
	 *
	 * @return string[]
	 */
	private function internal_post_types(): array {
		return [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_block',
			'wp_global_styles', 'wp_font_family', 'wp_font_face',
			'wp_navigation_fallback',
		];
	}

	// =========================================================
	// SAVE HANDLER
	// =========================================================

	public function handle_save_post( int $post_id, $post ): void {
		// wp_navigation needs language assignment even though it is not a public
		// post type; all other types must be public and non-internal to qualify.
		if ( $post->post_type !== 'wp_navigation' ) {
			$pto = get_post_type_object( $post->post_type );
			if ( ! $pto || ! $pto->public || in_array( $post->post_type, $this->internal_post_types(), true ) ) return;
		}
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( wp_is_post_autosave( $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// Dedicated metabox nonces. Verified independently of WP core's
		// edit_post nonce so a CSRF on an unrelated POST endpoint that ends
		// up triggering save_post cannot rebind language or translation groups.
		//
		// Missing-or-invalid nonce means "the field wasn't submitted by our
		// metabox" — we silently skip the corresponding block rather than
		// aborting the handler, so REST saves and wp_insert_post() callers
		// (which post no nonce) continue to work for everything else.
		$has_lang_nonce  = isset( $_POST['lf_language_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_language_nonce'] ) ), 'lf_language_save' );
		$has_trans_nonce = isset( $_POST['lf_translations_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_translations_nonce'] ) ), 'lf_translations_save' );

		$trid_group = $this->router->trid_group;
		$context    = $this->router->context;

		// Language
		if ( $has_lang_nonce && isset( $_POST['lf_lang'] ) && $context->is_valid_lang( sanitize_key( wp_unslash( $_POST['lf_lang'] ) ) ) ) {
			$trid_group->set_lang( $post_id, sanitize_key( wp_unslash( $_POST['lf_lang'] ) ) );
		}
		if ( ! get_post_meta( $post_id, '_lf_lang', true ) ) {
			$trid_group->set_lang( $post_id, $context->source_language() );
		}

		$lang = $trid_group->get_lang( $post_id );

		// Skip template/TRID/timestamp for wp_navigation and all non-content
		// post types (internal WP types are excluded above; this gate is what
		// keeps wp_navigation — public => false — out of this block).
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || ! $pto->public || in_array( $post->post_type, $this->internal_post_types(), true ) ) return;

		// ── Manual template selection — write BEFORE auto-assignment ──────────
		// The lf_page_template POST field must be flushed to the DB before
		// assign_template_if_needed() runs so that method reads the user's
		// explicit choice as $current, not the stale DB value.
		//
		// Previous ordering had this block at the end of the function, which
		// caused a hard overwrite: force_lang_template / assign_template_if_needed
		// correctly assigned 'single-product-ca', then the POST block ran last
		// and wrote 'default' back, winning on every classic-editor save.
		$has_tpl_nonce = isset( $_POST['lf_template_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_template_nonce'] ) ), 'lf_template_save' );
		if ( $has_tpl_nonce && isset( $_POST['lf_page_template'] ) ) {
			update_post_meta(
				$post_id,
				'_wp_page_template',
				sanitize_text_field( wp_unslash( $_POST['lf_page_template'] ) )
			);
		}

		// Template auto-assignment.
		//
		// Fires whenever the post's language has changed OR when this is the
		// first save the post has ever seen ($previous_lang empty). The
		// in-method guard inside assign_template_if_needed() leaves any
		// explicit admin template choice intact — only acts when the
		// current template is 'default' or empty — so this is safe to
		// trigger more aggressively than the original "on change only" gate.
		//
		// Without the first-save branch, posts created programmatically (REST
		// inserts, wp_insert_post calls, duplicated-from-source flows) with
		// _lf_lang already set would never get their language-specific template
		// assigned, since there's no _lf_lang_previous to compare against.
		// Template auto-assignment runs on every save — not just on language
		// changes — so that posts saved before templates existed, or posts whose
		// tracking meta was cleared, also get the correct template on the next
		// ordinary update.  assign_template_if_needed() is idempotent and
		// protects user-chosen templates via its internal guard, so calling it
		// unconditionally is safe.
		$previous_lang = get_post_meta( $post_id, '_lf_lang_previous', true );
		update_post_meta( $post_id, '_lf_lang_previous', $lang );

		// Two paths for template assignment:
		//
		// • $has_lang_nonce  → explicit user action via the Language metabox.
		//   force_lang_template() runs after the manual POST write above and
		//   always wins — correct: a language change should auto-assign the
		//   matching template regardless of what the template dropdown showed.
		//
		// • no nonce         → REST save, autosave, or programmatic insert.
		//   assign_template_if_needed() reads the now-committed _wp_page_template
		//   value: if 'default' (user left it unchanged), it upgrades to the
		//   language-specific slug; if the user explicitly chose a template,
		//   the guard in assign_template_if_needed() leaves it alone.
		if ( $has_lang_nonce ) {
			$this->force_lang_template( $post_id, $post, $lang );
		} else {
			$this->assign_template_if_needed( $post_id, $post, $lang );
		}

		// TRID
		$trid = $trid_group->get_trid( $post_id );
		if ( ! $trid ) {
			$trid = wp_generate_uuid4();
			$trid_group->set_trid( $post_id, $trid );
		}

		// Timestamps
		if ( $lang === $context->source_language() ) {
			$this->mark_source_updated( $post_id );
			$translations = $trid_group->get_translations( $post_id );
			foreach ( $translations as $t ) {
				update_post_meta( $t, '_lf_translation_source_updated_at', 0 );
			}
		} else {
			$this->mark_translation_synced( $post_id );
		}

		// Group merge (collect submitted translations).
		// Only read lf_trans_* when the translations metabox nonce checks out;
		// otherwise the post still gets its language/timestamp updates but the
		// TRID translation group is untouched.
		$group_ids = [ $post_id ];

		if ( $has_trans_nonce ) {
			foreach ( $context->languages() as $l ) {
				if ( ! isset( $_POST['lf_trans_' . $l] ) ) continue;
				$target_id = absint( wp_unslash( $_POST['lf_trans_' . $l] ) );
				if ( ! $target_id || $target_id === $post_id ) continue;
				$group_ids[] = $target_id;
			}
		}

		// Expand translation group (graph completion)
		$expanded_ids = $group_ids;

		foreach ( $group_ids as $pid ) {
			$existing = $trid_group->get_translations( $pid );
			if ( empty( $existing ) ) continue;
			foreach ( $existing as $existing_id ) {
				if ( ! in_array( $existing_id, $expanded_ids, true ) ) {
					$expanded_ids[] = $existing_id;
				}
			}
		}

		$group_ids = array_unique( $expanded_ids );

		// Resolve shared TRID
		$trid = null;
		foreach ( $group_ids as $pid ) {
			$existing = $trid_group->get_trid( $pid );
			if ( $existing ) { $trid = $existing; break; }
		}
		if ( ! $trid ) $trid = wp_generate_uuid4();

		foreach ( $group_ids as $pid ) {
			$trid_group->set_trid( $pid, $trid );

			if ( $pid === $post_id ) continue;

			// Language-binding on related posts is only safe when the
			// translations metabox nonce verified — same gate as the group
			// collection loop above.
			if ( ! $has_trans_nonce ) continue;

			foreach ( $context->languages() as $l ) {
				if ( isset( $_POST['lf_trans_' . $l] ) && absint( wp_unslash( $_POST['lf_trans_' . $l] ) ) === $pid ) {
					$trid_group->set_lang( $pid, $l );
				}
			}
		}

		// Navigation menu exclusion flag.
		//
		// Accepted from two sources:
		//   • lf_language_nonce  — Language meta box in the full block/classic editor.
		//   • lf_page_menu_exclude_nonce — Quick Edit fieldset in the list table.
		//
		// "Apply to all language versions" propagates the flag to every TRID sibling
		// in a single save.  A static guard prevents recursive re-entry if
		// wp_after_insert_post fires again when we update sibling meta via
		// update_post_meta (which can trigger save_post on some hosts).
		$has_exclude_nonce = isset( $_POST['lf_page_menu_exclude_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_page_menu_exclude_nonce'] ) ), 'lf_page_menu_exclude_save' );

		if ( $has_lang_nonce || $has_exclude_nonce ) {
			static $propagating = false;

			$exclude     = ! empty( $_POST['lf_page_menu_exclude'] );
			$exclude_all = ! empty( $_POST['lf_page_menu_exclude_all'] );

			if ( $exclude ) {
				update_post_meta( $post_id, '_lf_page_menu_exclude', '1' );
			} else {
				delete_post_meta( $post_id, '_lf_page_menu_exclude' );
			}

			// Propagate to TRID siblings when "Apply to all language versions" is checked.
			if ( $exclude_all && ! $propagating ) {
				$propagating  = true;
				$translations = $trid_group->get_translations( $post_id );
				foreach ( $translations as $sibling_id ) {
					$sibling_id = (int) $sibling_id;
					if ( $sibling_id === $post_id ) continue;
					if ( $exclude ) {
						update_post_meta( $sibling_id, '_lf_page_menu_exclude', '1' );
					} else {
						delete_post_meta( $sibling_id, '_lf_page_menu_exclude' );
					}
				}
				$propagating = false;
			}
		}

		// Per-language noindex flag.
		if ( $has_lang_nonce ) {
			if ( ! empty( $_POST['lf_noindex'] ) ) {
				update_post_meta( $post_id, '_lf_noindex', '1' );
			} else {
				delete_post_meta( $post_id, '_lf_noindex' );
			}
		}

		// Search index
		$this->router->search_index->build_search_content( $post_id );
	}
}
