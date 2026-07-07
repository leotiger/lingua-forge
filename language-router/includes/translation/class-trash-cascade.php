<?php
/**
 * Class LinguaForge\Router\Translation\TrashCascade
 *
 * Adds a "Trash + Siblings" row action, and a "Move to Trash (incl.
 * translations)" bulk action, to the Posts/Pages/CPT list tables — both
 * trash a post together with every other post in its TRID translation
 * group. Deliberately scoped to Trash only (not restore, not permanent
 * delete) — see lingua-forge-audit/PROPOSAL-trash-cascade-2026-07-07.md for
 * the full design rationale and the rejected alternatives (Quick Edit
 * button, JS-intercepted core Trash link, AJAX row removal).
 *
 * Both entry points are purely additive: the stock "Trash" link and "Move
 * to Trash" bulk action are untouched and keep trashing only the post(s)
 * they're applied to. The cascading variants only appear/act when a post
 * actually has TRID siblings, so they never clutter untranslated content
 * or post types Lingua Forge doesn't manage.
 *
 * @package LinguaForge\Router\Translation
 */

namespace LinguaForge\Router\Translation;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class TrashCascade {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// WP core dispatches row actions through one of these two filters
		// depending on internal post-type handling — registering the same
		// callback on both covers every post type without needing the
		// per-CPT filter-name loop that Columns::register_cpt_column_hooks()
		// uses for `manage_{pt}_posts_columns` (that filter genuinely is
		// per-post-type; row actions are not).
		add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_row_action' ], 10, 2 );

		add_action( 'admin_post_linguaforge_trash_with_siblings', [ $this, 'handle_trash_with_siblings' ] );
		add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );

		// bulk_actions-edit-{$post_type} / handle_bulk_actions-edit-{$post_type}
		// ARE genuinely per-post-type filter names (unlike post_row_actions
		// above), so — same as Columns::register_cpt_column_hooks() — these
		// are registered per type, after init so CPTs are already registered.
		add_action( 'init', [ $this, 'register_bulk_action_hooks' ], 20 );
	}

	/**
	 * Registers the bulk-action filter pair for every eligible public post
	 * type. Priority 20 on `init` mirrors Columns::register_cpt_column_hooks()
	 * — most plugins register their CPTs at the default priority 10.
	 */
	public function register_bulk_action_hooks(): void {
		foreach ( $this->bulk_action_post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", [ $this, 'add_bulk_action' ] );
			add_filter( "handle_bulk_actions-edit-{$post_type}", [ $this, 'handle_bulk_action' ], 10, 3 );
		}
	}

	/**
	 * Public post types eligible for the cascading bulk action — every
	 * public type except WordPress-internal ones (same exclusion list Sync
	 * and Columns each keep locally) and any type excluded via
	 * is_post_type_excluded(). Unlike Columns::cpt_post_types(), 'post' and
	 * 'page' are included here: bulk_actions-edit-{$post_type} uses the same
	 * naming scheme for every post type, so there's no dedicated-hook split
	 * to carve them out of.
	 *
	 * @return string[]
	 */
	private function bulk_action_post_types(): array {
		$internal = [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_navigation',
			'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
			'wp_navigation_fallback',
		];

		$types = array_diff( array_keys( get_post_types( [ 'public' => true ] ) ), $internal );

		return array_values( array_filter( $types, function ( string $post_type ): bool {
			return ! $this->is_post_type_excluded( $post_type );
		} ) );
	}

	// =========================================================
	// EXCLUSION GUARD
	// =========================================================

	/**
	 * Same option + filter MetaBoxes::is_post_type_excluded() uses to hide
	 * Lingua Forge meta boxes. Duplicated rather than shared across classes —
	 * matches the existing precedent of Sync::internal_post_types() vs
	 * Columns' own internal-types list, each kept local to its own file.
	 */
	private function is_post_type_excluded( string $post_type ): bool {
		$saved    = (string) get_option( 'linguaforge_secondary_query_excluded_types', '' );
		$excluded = $saved !== ''
			? array_filter( array_map( 'trim', explode( ',', $saved ) ) )
			: [];

		$excluded = (array) apply_filters( 'linguaforge_metabox_excluded_post_types', $excluded ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix; reusing the existing metabox-exclusion hook so both surfaces stay in sync.

		return in_array( $post_type, $excluded, true );
	}

	// =========================================================
	// ROW ACTION
	// =========================================================

	/**
	 * Appends "Trash + Siblings" to the row-actions list, immediately after
	 * the stock "Trash" link, when the post has at least one TRID sibling.
	 *
	 * @param array<string,string> $actions Existing row action links, keyed by slug.
	 * @param \WP_Post              $post    The post row being rendered.
	 * @return array<string,string>
	 */
	public function add_row_action( array $actions, \WP_Post $post ): array {
		// Only offered from the live list. Once a post is in the Trash, the
		// row actions are Restore/Delete Permanently — out of scope for v1
		// (see "Explicitly out of scope" in the proposal doc).
		if ( 'trash' === $post->post_status ) {
			return $actions;
		}

		if ( $this->is_post_type_excluded( $post->post_type ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return $actions;
		}

		$sibling_count = count( $this->get_sibling_ids( $post->ID ) );
		if ( $sibling_count < 1 ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=linguaforge_trash_with_siblings&post=' . $post->ID ),
			'lf_trash_with_siblings_' . $post->ID
		);

		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Trash + Siblings', 'lingua-forge' ) . '</a>';

		// Insert right after 'trash' so it reads Edit | Quick Edit | Trash |
		// Trash + Siblings | View, matching the mockup. Falls back to
		// appending at the end if a row-actions filter ever omits 'trash'
		// (e.g. a post type without delete_post capability granted, or a
		// third-party filter running at a later priority that removed it).
		if ( ! isset( $actions['trash'] ) ) {
			$actions['lf_trash_siblings'] = $link;
			return $actions;
		}

		$reordered = [];
		foreach ( $actions as $key => $value ) {
			$reordered[ $key ] = $value;
			if ( 'trash' === $key ) {
				$reordered['lf_trash_siblings'] = $link;
			}
		}

		return $reordered;
	}

	// =========================================================
	// BULK ACTION
	// =========================================================

	/**
	 * Adds "Move to Trash (incl. translations)" immediately after the stock
	 * "trash" bulk action. Always registered for every eligible post type
	 * (see bulk_action_post_types()) — unlike the row action, there's no
	 * cheap way to know in advance whether *any* row on the current page has
	 * TRID siblings, so the option is offered unconditionally; picking it
	 * for posts without siblings is simply a no-op for those posts (handled
	 * in handle_bulk_action() via trash_group()'s existing per-ID logic).
	 *
	 * Skipped on the Trash screen: core only includes a 'trash' key in
	 * $actions on the live list — the Trash screen's own bulk actions are
	 * 'untrash' / 'delete', neither of which this filter should touch (out
	 * of scope for v1, see the proposal doc).
	 *
	 * @param array<string,string> $actions Existing bulk action labels, keyed by slug.
	 * @return array<string,string>
	 */
	public function add_bulk_action( array $actions ): array {
		if ( ! isset( $actions['trash'] ) ) {
			return $actions;
		}

		$reordered = [];
		foreach ( $actions as $key => $label ) {
			$reordered[ $key ] = $label;
			if ( 'trash' === $key ) {
				$reordered['lf_trash_with_siblings'] = __( 'Move to Trash (incl. translations)', 'lingua-forge' );
			}
		}

		return $reordered;
	}

	/**
	 * Handles the "Move to Trash (incl. translations)" bulk action. WP core
	 * already verifies the bulk-action nonce ('bulk-posts') in edit.php
	 * before this filter runs, so no additional check_admin_referer() call
	 * is needed here — same as any other handle_bulk_actions-* callback.
	 *
	 * Cascades every selected post via the same trash_group() engine the row
	 * action uses, de-duplicating across the whole selection so a post and
	 * an already-selected sibling of it are never processed twice (which
	 * would double-count the notice totals).
	 *
	 * @param string $redirect_to Redirect URL core is about to send the browser to.
	 * @param string $doaction    The bulk action slug that was submitted.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( string $redirect_to, string $doaction, array $post_ids ): string {
		if ( 'lf_trash_with_siblings' !== $doaction ) {
			return $redirect_to;
		}

		$total_trashed = 0;
		$total_skipped = 0;
		$seen          = [];

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( isset( $seen[ $post_id ] ) ) {
				continue; // Already covered as another selected post's sibling.
			}

			foreach ( array_merge( [ $post_id ], $this->get_sibling_ids( $post_id ) ) as $group_id ) {
				$seen[ $group_id ] = true;
			}

			$result         = $this->trash_group( $post_id );
			$total_trashed += $result['trashed'];
			$total_skipped += $result['skipped'];
		}

		$redirect_to = remove_query_arg( [ 'lf_trashed', 'lf_skipped' ], $redirect_to );

		return add_query_arg(
			[
				'lf_trashed' => $total_trashed,
				'lf_skipped' => $total_skipped,
			],
			$redirect_to
		);
	}

	/**
	 * Returns the TRID sibling post IDs for $post_id — every post sharing
	 * its TRID group, excluding $post_id itself.
	 *
	 * @return int[]
	 */
	private function get_sibling_ids( int $post_id ): array {
		$siblings = [];
		foreach ( $this->router->trid_group->get_translations( $post_id ) as $id ) {
			$id = (int) $id;
			if ( $id !== $post_id ) {
				$siblings[] = $id;
			}
		}
		return array_values( array_unique( $siblings ) );
	}

	// =========================================================
	// ADMIN-POST HANDLER
	// =========================================================

	/**
	 * Handles the "Trash + Siblings" link. No confirm() prompt — matches
	 * WP core's own single-post "Trash" link, which also acts immediately
	 * because Trash is reversible via the Trash screen.
	 */
	public function handle_trash_with_siblings(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( ! $post_id || ! current_user_can( 'delete_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'lf_trash_with_siblings_' . $post_id );

		$result = $this->trash_group( $post_id );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php' );
		}
		$redirect = remove_query_arg( [ 'lf_trashed', 'lf_skipped' ], $redirect );
		$redirect = add_query_arg(
			[
				'lf_trashed' => $result['trashed'],
				'lf_skipped' => $result['skipped'],
			],
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// =========================================================
	// CASCADE ENGINE
	// =========================================================

	/**
	 * Trashes $post_id plus every TRID sibling. Shared by the row-action
	 * handler and the bulk-action handler; also the engine behind the public
	 * `linguaforge_trash_translation_group()` wrapper function.
	 *
	 * @param int  $post_id    Post ID the action was triggered from.
	 * @param bool $check_caps When true (the default — used by both wp-admin
	 *                         entry points above, where a real logged-in user
	 *                         is always present), each sibling is skipped
	 *                         unless `current_user_can('delete_post', $id)`.
	 *                         Pass false for a trusted programmatic caller —
	 *                         e.g. a REST endpoint that has already run its
	 *                         own authorization (a signed token, an
	 *                         "is this the post's own author" check, etc.)
	 *                         against a request that may have no meaningful
	 *                         current-WP-user context at all (anonymous,
	 *                         token-authenticated). Matches the existing
	 *                         `linguaforge_trigger_translation()` /
	 *                         `linguaforge_queue_translation()` convention of
	 *                         not enforcing `current_user_can()` themselves —
	 *                         the calling integration is responsible for its
	 *                         own authorization before calling in.
	 * @return array{trashed:int,skipped:int}
	 */
	public function trash_group( int $post_id, bool $check_caps = true ): array {
		$ids = array_merge( [ $post_id ], $this->get_sibling_ids( $post_id ) );

		/**
		 * Filters the set of post IDs about to be trashed together as a TRID group.
		 *
		 * @param int[] $ids     Post IDs (triggering post first, then siblings).
		 * @param int   $post_id The post ID the action was triggered from.
		 */
		$ids = (array) apply_filters( 'linguaforge_trash_cascade_post_ids', $ids, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		$front_id = (int) get_option( 'page_on_front' );
		$posts_id = (int) get_option( 'page_for_posts' );

		$trashed = [];
		$skipped = [];

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$post = get_post( $id );

			// Already gone or already trashed — nothing to do, and not a
			// meaningful "skip" worth reporting.
			if ( ! $post || 'trash' === $post->post_status ) {
				continue;
			}

			if ( $check_caps && ! current_user_can( 'delete_post', $id ) ) {
				$skipped[] = $id;
				continue;
			}

			// Core refuses to trash the static front page / posts page —
			// surface that as a skip rather than letting wp_trash_post()
			// fail silently.
			if ( $id === $front_id || ( $posts_id > 0 && $id === $posts_id ) ) {
				$skipped[] = $id;
				continue;
			}

			if ( wp_trash_post( $id ) ) {
				$trashed[] = $id;
			} else {
				$skipped[] = $id;
			}
		}

		/**
		 * Fires after a TRID group trash-cascade completes.
		 *
		 * @param int[] $trashed Post IDs successfully trashed.
		 * @param int[] $skipped Post IDs skipped (no permission, already trashed, or protected).
		 * @param int   $post_id The post ID the action was triggered from.
		 */
		do_action( 'linguaforge_trash_cascade_complete', $trashed, $skipped, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		return [
			'trashed' => count( $trashed ),
			'skipped' => count( $skipped ),
		];
	}

	// =========================================================
	// ADMIN NOTICE
	// =========================================================

	public function render_admin_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only integer GET flags set by wp_safe_redirect() in handle_trash_with_siblings() after the nonce-verified action already ran; no data is modified here, and absint() is the effective sanitization.
		if ( ! isset( $_GET['lf_trashed'] ) ) {
			return;
		}

		$trashed = absint( wp_unslash( $_GET['lf_trashed'] ) );
		$skipped = isset( $_GET['lf_skipped'] ) ? absint( wp_unslash( $_GET['lf_skipped'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of posts moved to Trash, including the triggering post. */
			_n( 'Trashed %d post (including translations).', 'Trashed %d posts (including translations).', $trashed, 'lingua-forge' ),
			$trashed
		);

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of posts skipped (no permission, or a protected front/posts page). */
				_n( '%d post was skipped (no permission or protected).', '%d posts were skipped (no permission or protected).', $skipped, 'lingua-forge' ),
				$skipped
			);
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}
