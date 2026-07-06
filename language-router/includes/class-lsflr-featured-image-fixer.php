<?php
/**
 * Class LinguaForge\Router\FeaturedImageFixer
 *
 * Finds translated posts/pages/CPTs whose featured image is missing or
 * out of sync with their source-language sibling, and lets an editor copy
 * the source's featured image across — individually or in bulk.
 *
 * Why this exists: none of the built-in translation-creation paths
 * (TranslationTrigger, AbstractTranslateCommand, PostListColumn's bulk
 * "Translate" action) copy `_thumbnail_id` onto a newly created translation.
 * A translated post is therefore born with no featured image unless an
 * integration explicitly supplies one via the `linguaforge_translated_post_meta`
 * filter. Translation-creation now copies the source's thumbnail going
 * forward (see those three classes); this fixer covers translations that
 * already existed before that change, plus any post whose thumbnail was
 * changed on the source afterward and never re-synced on the translation.
 *
 * WooCommerce products are intentionally excluded: WooCommerce\MetaDelegate
 * already serves `_thumbnail_id` from the source product at read time for
 * every translation, so there is nothing to "fix" there — writing a copy
 * would be silently shadowed by that delegation.
 *
 * UI: a "Fix Featured Images" button appears in the posts/pages/CPT list
 * view whenever a language filter is active — same location and pattern as
 * LinkFixer's "Fix Links" button. Clicking it opens a modal showing a
 * dry-run scan, then lets the editor fix posts individually or all at once.
 *
 * Instantiated by the Router constructor as a sub-object
 * ($router->featured_image_fixer).
 */

namespace LinguaForge\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class FeaturedImageFixer {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
		$this->register_hooks();
	}

	// =========================================================
	// HOOKS
	// =========================================================

	private function register_hooks(): void {
		add_action( 'restrict_manage_posts',              [ $this, 'render_fix_button' ] );
		add_action( 'admin_enqueue_scripts',               [ $this, 'enqueue_assets' ] );
		add_action( 'admin_footer',                        [ $this, 'render_modal' ] );
		add_action( 'wp_ajax_lsflr_scan_featured_images',  [ $this, 'ajax_scan' ] );
		add_action( 'wp_ajax_lsflr_fix_featured_image',    [ $this, 'ajax_fix_post' ] );
	}

	// =========================================================
	// ADMIN UI: ASSET ENQUEUE
	// =========================================================

	/**
	 * Enqueue modal CSS and JS, plus localized strings.
	 *
	 * Scoped to the post/page/CPT list screen (`edit.php`) with editor
	 * capability — same guard set as render_modal().
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$base_url = LINGUAFORGE_URL . 'language-router/assets/';
		$version  = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;

		wp_enqueue_style(
			'lsflr-featured-image-fixer',
			$base_url . 'featured-image-fixer.css',
			[],
			$version
		);

		wp_enqueue_script(
			'lsflr-featured-image-fixer',
			$base_url . 'featured-image-fixer.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script( 'lsflr-featured-image-fixer', 'lsflrFeaturedImageFixer', [
			'i18n' => [
				'scanning'          => __( 'Scanning posts for missing or out-of-sync featured images…', 'lingua-forge' ),
				'rescanning'        => __( 'Re-scanning…', 'lingua-forge' ),
				'scanFailed'        => __( 'Scan failed: ', 'lingua-forge' ),
				'unknownError'      => __( 'unknown error', 'lingua-forge' ),
				'scanRequestFailed' => __( 'Scan request failed. Please try again.', 'lingua-forge' ),
				'noPostsFound'      => __( '⚠ No <strong>{lang}</strong> posts found. Make sure all translated posts have their Language meta set to <strong>{lang}</strong> in the Language metabox.', 'lingua-forge' ),
				'allInSync'         => __( '✅ All <strong>{lang}</strong> featured images are already in sync with their source post. Scanned <strong>{scanned}</strong> post(s).', 'lingua-forge' ),
				'foundSummary'      => __( 'Found <strong>{n}</strong> post(s) missing or out of sync with their source featured image, out of <strong>{scanned}</strong> scanned for <strong>{lang}</strong>.', 'lingua-forge' ),
				'colPost'           => __( 'Post', 'lingua-forge' ),
				'colCurrent'        => __( 'Current', 'lingua-forge' ),
				'colSource'         => __( 'Source (EN)', 'lingua-forge' ),
				'none'              => __( 'None', 'lingua-forge' ),
				'btnFix'            => __( 'Copy from source', 'lingua-forge' ),
				'btnFixing'         => __( 'Copying…', 'lingua-forge' ),
				'btnFixed'          => __( '✅ Copied', 'lingua-forge' ),
				'btnFailed'         => __( '❌ Failed', 'lingua-forge' ),
				'allDone'           => __( 'Done — {done} of {total} post(s) fixed.', 'lingua-forge' ),
				'fixingProgress'    => __( 'Fixing {n} / {total}…', 'lingua-forge' ),
			],
		] );
	}

	// =========================================================
	// CORE: SCAN
	// =========================================================

	/**
	 * Compare one translated post's featured image against its source-language
	 * sibling in the same TRID group.
	 *
	 * Returns null when there is nothing to fix: no source translation exists,
	 * the source itself has no featured image, or the target's featured image
	 * already matches the source's.
	 *
	 * @return array{
	 *   post_id:       int,
	 *   title:         string,
	 *   current_id:    int,
	 *   current_url:   string,
	 *   source_id:     int,
	 *   source_thumb:  int,
	 *   source_url:    string
	 * }|null
	 */
	public function scan_post( int $post_id, string $target_lang ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) return null;

		$source_lang = $this->router->context->source_language();
		if ( $target_lang === $source_lang ) return null; // nothing to compare the source against

		$translations = $this->router->get_translations( $post_id );
		$source_id    = (int) ( $translations[ $source_lang ] ?? 0 );
		if ( ! $source_id || $source_id === $post_id ) return null;

		$source_thumb = (int) get_post_thumbnail_id( $source_id );
		if ( ! $source_thumb ) return null; // nothing to copy

		$current_thumb = (int) get_post_thumbnail_id( $post_id );
		if ( $current_thumb === $source_thumb ) return null; // already in sync

		$current_url = $current_thumb ? (string) wp_get_attachment_image_url( $current_thumb, 'thumbnail' ) : '';
		$source_url  = (string) wp_get_attachment_image_url( $source_thumb, 'thumbnail' );

		return [
			'post_id'      => $post_id,
			'title'        => $post->post_title,
			'current_id'   => $current_thumb,
			'current_url'  => $current_url,
			'source_id'    => $source_id,
			'source_thumb' => $source_thumb,
			'source_url'   => $source_url,
		];
	}

	/**
	 * Copy the source-language sibling's featured image onto a single post.
	 *
	 * Writes `_thumbnail_id` directly via update_post_meta() rather than calling
	 * WordPress's set_post_thumbnail(), which additionally requires
	 * wp_get_attachment_image() to successfully render the attachment before it
	 * will persist anything — a validity check meant for admin-supplied arbitrary
	 * attachment IDs. The ID copied here was already validated the moment it was
	 * set as the source post's own featured image, so re-validating it here would
	 * only add a redundant image-render lookup. This mirrors the direct
	 * meta_input writes the three translation-creation paths already use.
	 *
	 * @return array{ applied: bool }
	 */
	public function fix_post( int $post_id, string $target_lang ): array {
		$scan = $this->scan_post( $post_id, $target_lang );
		if ( ! $scan ) return [ 'applied' => false ];

		update_post_meta( $post_id, '_thumbnail_id', $scan['source_thumb'] );
		$applied = ( (int) get_post_thumbnail_id( $post_id ) === $scan['source_thumb'] );

		return [ 'applied' => $applied ];
	}

	// =========================================================
	// AJAX: SCAN (dry-run for a whole language)
	// =========================================================

	public function ajax_scan(): void {
		check_ajax_referer( 'lsflr_featured_image_fixer_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$lang = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );
		if ( ! $this->router->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid language' );
		}

		// Admin-only path: gated by current_user_can('edit_posts') and only fires when
		// an admin clicks "Fix Featured Images". Bounded to published, thumbnail-
		// supporting content post types — not a hot frontend path.
		$query = new \WP_Query( [
			'post_type'      => $this->fixer_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see comment above the $query assignment.
			'meta_query'     => [ [ 'key' => '_lf_lang', 'value' => $lang ] ],
		] );

		$scanned = 0;
		$results = [];
		foreach ( $query->posts as $post_id ) {
			$scanned++;
			$scan = $this->scan_post( (int) $post_id, $lang );
			if ( $scan ) {
				$results[] = $scan;
			}
		}

		wp_send_json_success( [
			'lang'    => $lang,
			'results' => $results,
			'total'   => count( $results ),
			'scanned' => $scanned,
		] );
	}

	// =========================================================
	// AJAX: FIX SINGLE POST
	// =========================================================

	public function ajax_fix_post(): void {
		check_ajax_referer( 'lsflr_featured_image_fixer_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() sanitizes to a non-negative integer.
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$lang    = sanitize_text_field( wp_unslash( $_POST['lang'] ?? '' ) );

		if ( ! $post_id || ! $this->router->is_valid_lang( $lang ) ) {
			wp_send_json_error( 'Invalid parameters' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Permission denied for this post' );
		}

		$result = $this->fix_post( $post_id, $lang );
		wp_send_json_success( $result );
	}

	// =========================================================
	// ADMIN UI: BUTTON (in the toolbar above the post list)
	// =========================================================

	/**
	 * Render the "Fix Featured Images" button next to the language filter
	 * dropdown. Only shown when a language filter is active AND the current
	 * screen's post type actually supports featured images.
	 */
	public function render_fix_button( string $post_type ): void {
		if ( ! in_array( $post_type, $this->fixer_post_types(), true ) ) {
			return;
		}
		if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
			return;
		}

		$lang = $this->active_lang_filter();
		if ( ! $lang || $lang === $this->router->context->source_language() ) {
			return;
		}

		$nonce = wp_create_nonce( 'lsflr_featured_image_fixer_nonce' );
		printf(
			'<button type="button" class="button lsflr-open-thumbfixer" data-lang="%s" data-nonce="%s">'
			. '🖼 %s (%s)'
			. '</button>',
			esc_attr( $lang ),
			esc_attr( $nonce ),
			esc_html__( 'Fix Featured Images', 'lingua-forge' ),
			esc_html( strtoupper( $lang ) )
		);
	}

	// =========================================================
	// ADMIN UI: MODAL OVERLAY
	// =========================================================

	/**
	 * Output the modal markup. Styles and JavaScript are enqueued separately
	 * via enqueue_assets() and live in
	 * language-router/assets/featured-image-fixer.{css,js}.
	 */
	public function render_modal(): void {
		global $pagenow;
		if ( $pagenow !== 'edit.php' )            return;
		if ( ! current_user_can( 'edit_posts' ) ) return;
		?>

		<!-- LSFLR Featured Image Fixer modal -->
		<div id="lsflr-thumbfixer-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="lsflr-thumbfixer-title">
			<div id="lsflr-thumbfixer-modal">

				<button id="lsflr-thumbfixer-close" type="button" title="<?php esc_attr_e('Close', 'lingua-forge'); ?>">✕</button>

				<h2 id="lsflr-thumbfixer-title">🖼 <?php esc_html_e('Featured Image Fixer', 'lingua-forge'); ?></h2>

				<p id="lsflr-thumbfixer-status"></p>

				<div id="lsflr-thumbfixer-results"></div>

				<div id="lsflr-thumbfixer-actions" style="display:none">
					<button id="lsflr-thumbfix-all" type="button" class="button button-primary">
						<?php esc_html_e('Fix All', 'lingua-forge'); ?>
					</button>
					<button id="lsflr-thumbfixer-recheck" type="button" class="button">
						🔄 <?php esc_html_e('Re-scan', 'lingua-forge'); ?>
					</button>
					<span id="lsflr-thumbfix-progress"></span>
				</div>

			</div>
		</div>

		<?php
	}

	// =========================================================
	// HELPERS
	// =========================================================

	/**
	 * Returns the post types this fixer scans: 'post', 'page', and any public
	 * CPT that both opts into the link-fixer post-type list (reusing the same
	 * filter so site owners configure both fixers in one place) and actually
	 * supports featured images. WooCommerce products are always excluded —
	 * MetaDelegate already serves `_thumbnail_id` from the source product at
	 * read time, so there is nothing here for this fixer to do.
	 *
	 * @return string[]
	 */
	private function fixer_post_types(): array {
		$wc_types = [ 'product', 'product_variation' ];

		// Standard internal-types exclusion list. See class-sync.php for the
		// intentional wp_navigation omission that exists only in that file.
		$internal = [
			'attachment', 'revision', 'nav_menu_item',
			'wp_template', 'wp_template_part', 'wp_navigation',
			'wp_block', 'wp_global_styles', 'wp_font_family', 'wp_font_face',
			'wp_navigation_fallback',
		];
		$cpts = array_values( array_diff(
			array_keys( get_post_types( [ 'public' => true ] ) ),
			array_merge( [ 'post', 'page' ], $wc_types, $internal )
		) );

		// Reuse the same opt-in list link-fixer uses, so a site only has to
		// configure "which CPTs does Lingua Forge manage" once.
		$cpts = (array) apply_filters( 'linguaforge_link_fixer_post_types', $cpts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		$types = array_merge( [ 'post', 'page' ], $cpts );

		return array_values( array_filter(
			$types,
			static fn( string $t ): bool => ! in_array( $t, $wc_types, true ) && post_type_supports( $t, 'thumbnail' )
		) );
	}

	/**
	 * Return the language currently chosen in the admin list filter,
	 * or an empty string when no filter is active.
	 */
	private function active_lang_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a list-filter URL parameter to determine the active language column filter; no data is modified.
		if ( ! empty( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only list-filter parameter; no data is modified.
			$lang = sanitize_text_field( wp_unslash( $_GET['lf_lang_filter'] ) );
			return $this->router->is_valid_lang( $lang ) ? $lang : '';
		}

		// Fall back to the persisted preference for the current user.
		$lang = (string) get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		return ( $lang && $this->router->is_valid_lang( $lang ) ) ? $lang : '';
	}
}
