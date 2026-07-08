<?php
/**
 * Class LinguaForge\AI\Admin\PostListColumn
 *
 * Injects action buttons into the existing "Lang" column (rendered by
 * LinguaForge\Router\Admin\Columns) for two scenarios:
 *
 *   • "Translate missing" — shown on source-language posts that are missing
 *     one or more target-language translations.  Hooks `lf_lang_column_missing`.
 *
 *   • "Retranslate" — shown on every TRID-linked post regardless of outdated
 *     status, so editors can force a fresh translation at any time.
 *     Hooks `lf_lang_column_retranslate`.
 *
 *   • "Sync" — shown on every TRID-linked post, including the source-language
 *     post. Retranslates FROM the current post's language INTO every other
 *     configured language in one operation: missing languages are created,
 *     existing ones are force-refreshed. Unlike "Retranslate", Sync is not
 *     blocked on the source post by language alone — a source-language post
 *     can always push its content out to every translation, and (subject to
 *     the safeguards below) a secondary-language post can just as
 *     deliberately overwrite the source via back-translation, since the
 *     whole point of Sync is "make every other version match this one."
 *     Also hooked onto `lf_lang_column_retranslate` (fired unconditionally
 *     for every post).
 *
 *     Secondary-language safeguards (off by default, on a per-post-type
 *     basis) — triggering Sync FROM a secondary-language post is blocked
 *     unless explicitly allowed, via two independent guards:
 *       - WooCommerce products/variations: `linguaforge_wc_allow_secondary_sync`
 *         option (Settings → Behavior → WooCommerce) or the
 *         `linguaforge_wc_secondary_sync_allowed` filter.
 *         See wc_secondary_sync_blocked().
 *       - Every other post type: `linguaforge_allow_secondary_sync` option
 *         (Settings → Behavior → Sync) or the `linguaforge_secondary_sync_allowed`
 *         filter. See general_secondary_sync_blocked().
 *     The two are independent — enabling one has no effect on the other.
 *     Syncing FROM the primary-language post is always allowed regardless of
 *     post type or either setting; the restriction only applies to the
 *     primary-overwriting direction.
 *
 * All three hooks are fired by Columns::render_lang_column() in the
 * language-router module, keeping the AI module fully decoupled.
 *
 * The core Sync logic lives in run_sync(), a reusable method (not just an
 * AJAX handler) so third-party code can trigger the same operation
 * programmatically via the `linguaforge_sync_translations()` wrapper function
 * defined in `ai/ai.php`.
 *
 * @package LinguaForge\AI\Admin
 * @since   1.8.1
 */

namespace LinguaForge\AI\Admin;

use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Features\MetaDescription;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\Features\Translation;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class PostListColumn {

	const AJAX_ACTION            = 'lf_fill_missing';
	const NONCE_NAME             = 'lf_fill_missing_nonce';
	const AJAX_ACTION_RETRANSLATE = 'lf_retranslate';
	const NONCE_NAME_RETRANSLATE  = 'lf_retranslate_nonce';
	const AJAX_ACTION_SYNC         = 'lf_sync';
	const NONCE_NAME_SYNC          = 'lf_sync_nonce';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// "Translate missing" — fires on source posts with missing translations.
		add_action( 'lf_lang_column_missing', [ self::class, 'render_fill_button' ], 10, 2 );

		// "Retranslate" — fires on every post regardless of outdated status.
		add_action( 'lf_lang_column_retranslate', [ self::class, 'render_retranslate_button' ], 10, 1 );

		// "Sync" — fires on every post (including the source-language post).
		add_action( 'lf_lang_column_retranslate', [ self::class, 'render_sync_button' ], 15, 1 );

		// SEO score badge — fires on every post; shows stored score + delta if available.
		add_action( 'lf_lang_column_retranslate', [ self::class, 'render_seo_score_badge' ], 20, 1 );

		// Enqueue JS + CSS on the post list screen.
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );

		// AJAX handlers — admin-only (logged-in user required by WordPress).
		add_action( 'wp_ajax_' . self::AJAX_ACTION,            [ self::class, 'ajax_fill_missing' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_RETRANSLATE, [ self::class, 'ajax_retranslate'  ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION_SYNC,         [ self::class, 'ajax_sync'         ] );
	}

	// =========================================================================
	// Button output
	// =========================================================================

	/**
	 * Output the "Translate missing" button inside the Lang column cell.
	 *
	 * Called via the `lf_lang_column_missing` action fired by
	 * Columns::render_lang_column() when a post has at least one missing
	 * target-language translation.
	 *
	 * @param int      $post_id  Current post ID.
	 * @param string[] $missing  Missing language codes (unused here — AJAX
	 *                           recalculates server-side to stay authoritative).
	 */
	public static function render_fill_button( int $post_id, array $missing ): void {
		// wp_navigation posts are translated via the FSE navigation pipeline
		// (Router tab → ajax_translate_fse_navigation), not the standard AI
		// translate-missing flow. Skip the button so the wrong handler is never
		// triggered from the Navigation List admin screen.
		$post = get_post( $post_id );
		if ( $post && $post->post_type === 'wp_navigation' ) {
			return;
		}

		printf(
			' <button type="button" class="button button-small lf-fill-missing" data-post-id="%d">%s</button>',
			esc_attr( (string) $post_id ),
			esc_html__( 'Translate missing', 'lingua-forge' )
		);
	}

	/**
	 * Output the "Retranslate" selector + button in the Lang column.
	 *
	 * Renders a <select> listing every language in the TRID group except the
	 * current post's own language, so the editor can choose which version to
	 * translate from.  Excluding the current language prevents a meaningless
	 * "retranslate German from German" operation.
	 *
	 * Called via the `lf_lang_column_retranslate` action fired unconditionally
	 * by Columns::render_lang_column() for every post, whether or not the ⚠
	 * outdated indicator is present.  Returns early if the post has no TRID
	 * siblings to translate from.
	 *
	 * @param int $post_id  Current post ID.
	 */
	public static function render_retranslate_button( int $post_id ): void {
		$current_lang = (string) get_post_meta( $post_id, '_lf_lang', true );

		// Never show "Retranslate" on source-language posts.  The source is the
		// authoritative content; overwriting it via back-translation would be
		// destructive.  Editors update source content directly.
		$source_lang = Router::get_instance()->source_language();
		if ( '' === $current_lang || $current_lang === $source_lang ) {
			return;
		}

		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $post_id )
			: [];

		// Remove own language — retranslating from the same language makes no sense.
		unset( $translations[ $current_lang ] );

		if ( empty( $translations ) ) {
			return; // No other language available to translate from.
		}

		// Sort by language code so the "From" list reads in a predictable order
		// rather than whatever order the DB happened to return TRID siblings in.
		ksort( $translations );

		echo ' <span class="lf-retranslate-wrap">';

		// Language selector.
		echo '<select class="lf-retranslate-from">';
		foreach ( $translations as $lang => $_ ) {
			printf(
				'<option value="%s">%s %s</option>',
				esc_attr( $lang ),
				/* translators: label prefix for the retranslate language picker */
				esc_html__( 'From', 'lingua-forge' ),
				esc_html( strtoupper( $lang ) )
			);
		}
		echo '</select>';

		// Button.
		printf(
			'<button type="button" class="button button-small lf-retranslate" data-post-id="%d">%s</button>',
			esc_attr( (string) $post_id ),
			esc_html__( 'Retranslate', 'lingua-forge' )
		);

		echo '</span>';
	}

	/**
	 * Output the "Sync" button in the Lang column.
	 *
	 * Shown on every TRID-linked post, including the source-language post —
	 * unlike "Retranslate" above, Sync is never blocked on language. Clicking
	 * it retranslates FROM the current post's language INTO every other
	 * configured language: missing siblings are created, existing ones are
	 * force-refreshed. This intentionally allows a secondary-language post to
	 * overwrite the source post via back-translation; that is the entire
	 * point of a "make everything else match this one" action, and the
	 * confirmation dialog on the client side is what stands in for the
	 * destructive-action guard "Retranslate" gets for free by simply refusing
	 * to touch the source.
	 *
	 * Called via the `lf_lang_column_retranslate` action, fired unconditionally
	 * by Columns::render_lang_column() for every post. Returns early when the
	 * post has no language assigned yet, or when fewer than two languages are
	 * configured (nothing to sync to).
	 *
	 * @param int $post_id  Current post ID.
	 */
	public static function render_sync_button( int $post_id ): void {
		// wp_navigation posts are translated via the FSE navigation pipeline, not this one.
		$post = get_post( $post_id );
		if ( $post && $post->post_type === 'wp_navigation' ) {
			return;
		}

		$router = Router::get_instance();
		if ( count( $router->languages() ) < 2 ) {
			return; // Nothing to sync to.
		}

		$current_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $current_lang ) {
			return;
		}

		if ( self::wc_secondary_sync_blocked( $post, $current_lang, $router )
			|| self::general_secondary_sync_blocked( $post, $current_lang, $router )
		) {
			return;
		}

		printf(
			' <button type="button" class="button button-small lf-sync" data-post-id="%d" title="%s">%s</button>',
			esc_attr( (string) $post_id ),
			esc_attr__( 'Retranslate every other language version from this post. Creates any that are missing and overwrites any that already exist — including the primary language, if this post is a translation.', 'lingua-forge' ),
			esc_html__( 'Sync', 'lingua-forge' )
		);
	}

	// =========================================================================
	// Secondary-language Sync safeguards
	//
	// Two independent guards, each with its own setting, checked separately
	// at every Sync call site (render_sync_button(), run_sync()):
	//
	//   • wc_secondary_sync_blocked()      — WooCommerce products/variations
	//     only. Unchanged since 2.6.0; do not fold into the general guard
	//     below — WooCommerce keeps its own dedicated toggle regardless of
	//     the general one, since the primary product is WC's operational
	//     source of truth (price, SKU, stock) and warrants a separate,
	//     more visible opt-in.
	//   • general_secondary_sync_blocked() — every OTHER post type. Added in
	//     the same release to close the same hole for ordinary posts, pages,
	//     and non-WC CPTs, which had no restriction at all before this.
	//
	// The two are mutually exclusive by post type (is_wc_product_post_type()
	// routes a post to exactly one of them), so exactly one of the two ever
	// applies to a given post — enabling one setting never implicitly
	// enables the other.
	// =========================================================================

	/**
	 * Whether $post's post type is a WooCommerce product post (regular or
	 * variation). A plain post_type check, independent of whether the
	 * WooCommerce plugin is currently active — a 'product' row in the
	 * database is still a WooCommerce product even if WC has since been
	 * deactivated.
	 *
	 * @param string $post_type
	 * @return bool
	 */
	private static function is_wc_product_post_type( string $post_type ): bool {
		return in_array( $post_type, [ 'product', 'product_variation' ], true );
	}

	/**
	 * Whether a secondary-language WooCommerce product/variation is allowed
	 * to trigger Sync — i.e. to overwrite the primary product via
	 * back-translation. Off by default.
	 *
	 * Two escape hatches, checked in order: the
	 * `linguaforge_wc_allow_secondary_sync` option (Settings → Behavior →
	 * WooCommerce), then the `linguaforge_wc_secondary_sync_allowed` filter
	 * for programmatic/per-request overrides that don't need a persistent
	 * site-wide setting.
	 *
	 * @return bool
	 */
	private static function wc_secondary_sync_allowed(): bool {
		/**
		 * Filters whether Sync may be triggered from a secondary-language
		 * WooCommerce product or variation, allowing it to overwrite the
		 * primary/source product via back-translation.
		 *
		 * Off by default: the primary product is WooCommerce's operational
		 * source of truth (price, SKU, stock — see
		 * Integrations\WooCommerce\MetaDelegate), so an accidental
		 * back-translation overwrite there is a materially different risk
		 * than for an ordinary post. Syncing FROM the primary product to
		 * every translation is unaffected by this filter — it is always
		 * allowed.
		 *
		 * @param bool $allowed Defaults to the `linguaforge_wc_allow_secondary_sync` option (false).
		 *
		 * @since 2.6.0
		 */
		return (bool) apply_filters(
			'linguaforge_wc_secondary_sync_allowed', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			(bool) get_option( 'linguaforge_wc_allow_secondary_sync', false )
		);
	}

	/**
	 * Whether Sync must be blocked for this post: a secondary-language
	 * WooCommerce product/variation, with the safeguard not lifted.
	 *
	 * Syncing FROM the primary language is always allowed regardless of post
	 * type — this only restricts the primary-overwriting direction.
	 *
	 * @param \WP_Post|null $post          The post Sync would run from.
	 * @param string        $current_lang  That post's language code.
	 * @param Router        $router        Router instance (avoids re-resolving the singleton).
	 * @return bool
	 */
	private static function wc_secondary_sync_blocked( ?\WP_Post $post, string $current_lang, Router $router ): bool {
		if ( ! $post || $current_lang === $router->source_language() ) {
			return false;
		}
		if ( ! self::is_wc_product_post_type( $post->post_type ) ) {
			return false;
		}
		return ! self::wc_secondary_sync_allowed();
	}

	/**
	 * Whether a secondary-language post of any OTHER (non-WooCommerce) post
	 * type is allowed to trigger Sync — i.e. to overwrite the primary post
	 * via back-translation. Off by default, same posture as the WooCommerce
	 * guard above but independently controlled.
	 *
	 * Two escape hatches, checked in order: the
	 * `linguaforge_allow_secondary_sync` option (Settings → Behavior → Sync),
	 * then the `linguaforge_secondary_sync_allowed` filter for
	 * programmatic/per-request overrides that don't need a persistent
	 * site-wide setting.
	 *
	 * @return bool
	 */
	private static function secondary_sync_allowed(): bool {
		/**
		 * Filters whether Sync may be triggered from a secondary-language post
		 * of any post type OTHER than a WooCommerce product/variation
		 * (see `linguaforge_wc_secondary_sync_allowed` for that case),
		 * allowing it to overwrite the primary/source post via
		 * back-translation.
		 *
		 * Off by default: the primary post is the authoritative content
		 * every translation derives from, so an accidental back-translation
		 * overwrite is a deliberate opt-in, not a default. Syncing FROM the
		 * primary post to every translation is unaffected by this filter —
		 * it is always allowed.
		 *
		 * @param bool $allowed Defaults to the `linguaforge_allow_secondary_sync` option (false).
		 *
		 * @since 2.6.0
		 */
		return (bool) apply_filters(
			'linguaforge_secondary_sync_allowed', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.
			(bool) get_option( 'linguaforge_allow_secondary_sync', false )
		);
	}

	/**
	 * Whether Sync must be blocked for this post: a secondary-language post
	 * of any post type OTHER than a WooCommerce product/variation (which has
	 * its own dedicated guard above), with the safeguard not lifted.
	 *
	 * Syncing FROM the primary language is always allowed regardless of post
	 * type — this only restricts the primary-overwriting direction.
	 *
	 * @param \WP_Post|null $post          The post Sync would run from.
	 * @param string        $current_lang  That post's language code.
	 * @param Router        $router        Router instance (avoids re-resolving the singleton).
	 * @return bool
	 */
	private static function general_secondary_sync_blocked( ?\WP_Post $post, string $current_lang, Router $router ): bool {
		if ( ! $post || $current_lang === $router->source_language() ) {
			return false;
		}
		if ( self::is_wc_product_post_type( $post->post_type ) ) {
			return false; // WooCommerce has its own dedicated guard — see wc_secondary_sync_blocked().
		}
		return ! self::secondary_sync_allowed();
	}

	// =========================================================================
	// SEO score badge
	// =========================================================================

	/**
	 * Render a compact SEO score badge in the Lang column.
	 *
	 * Reads `_lf_seo_score_history` written by SeoAnalysisPanel::ajax_analyze().
	 * Shows the latest score colour-coded by threshold (≥80 green, ≥50 amber,
	 * <50 red) plus a ↑/↓ delta when a previous score is stored.
	 *
	 * @param int $post_id Current post ID.
	 */
	public static function render_seo_score_badge( int $post_id ): void {
		if ( ! class_exists( \LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel::class ) ) {
			return;
		}

		$history = \LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel::get_score_history( $post_id );
		if ( empty( $history ) ) {
			return;
		}

		$current = (int) $history[0]['score'];
		$color   = $current >= 80 ? '#00a32a' : ( $current >= 50 ? '#dba617' : '#d63638' );

		$delta_html = '';
		if ( isset( $history[1] ) ) {
			$previous = (int) $history[1]['score'];
			if ( $previous !== $current ) {
				$diff        = $current - $previous;
				$delta_color = $diff > 0 ? '#00a32a' : '#d63638';
				$arrow       = $diff > 0 ? '↑' : '↓';
				$delta_html  = sprintf(
					'<span class="lf-seo-badge__delta" style="color:%s;">%s%d</span>',
					esc_attr( $delta_color ),
					$arrow,
					abs( $diff )
				);
			}
		}

		printf(
			' <span class="lf-seo-badge" style="color:%s;" title="%s">SEO&nbsp;%d%s</span>',
			esc_attr( $color ),
			esc_attr__( 'Last rule-based SEO score. Run analysis in Settings → SEO → Analysis to update.', 'lingua-forge' ),
			absint( $current ),
			$delta_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built entirely from esc_attr()-escaped values and a hard-coded arrow character; no unescaped user input.
		);
	}

	// =========================================================================
	// Asset enqueue
	// =========================================================================

	/**
	 * Enqueue the post-list script + stylesheet on the post list (edit.php) screen.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		// Shared stylesheet (also loaded on post.php by MetaBox::enqueue()).
		wp_enqueue_style(
			'lingua-forge-admin',
			LINGUAFORGE_AI_URL . '/assets/admin.css',
			[],
			LINGUAFORGE_VERSION
		);

		wp_enqueue_script(
			'lf-post-list',
			LINGUAFORGE_AI_URL . '/assets/post-list.js',
			[ 'jquery' ],
			LINGUAFORGE_VERSION,
			true
		);

		wp_localize_script(
			'lf-post-list',
			'lfPostList',
			[
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'action'              => self::AJAX_ACTION,
				'nonce'               => wp_create_nonce( self::NONCE_NAME ),
				'actionRetranslate'   => self::AJAX_ACTION_RETRANSLATE,
				'nonceRetranslate'    => wp_create_nonce( self::NONCE_NAME_RETRANSLATE ),
				'actionSync'          => self::AJAX_ACTION_SYNC,
				'nonceSync'           => wp_create_nonce( self::NONCE_NAME_SYNC ),
				'l10n'                => [
					'translating'  => __( 'Translating…', 'lingua-forge' ),
					'retranslating' => __( 'Retranslating…', 'lingua-forge' ),
					'syncing'      => __( 'Syncing…', 'lingua-forge' ),
					'syncConfirm'  => __( 'This retranslates every other language version of this post from this one — creating any that are missing and overwriting any that already exist (including the primary language, if this post is a translation). This cannot be undone. Continue?', 'lingua-forge' ),
					'done'         => __( '✓ Done', 'lingua-forge' ),
					'error'        => __( 'Error', 'lingua-forge' ),
				],
			]
		);
	}

	// =========================================================================
	// AJAX handler
	// =========================================================================

	/**
	 * Fill all missing target-language translations for a given source post.
	 *
	 * Replicates FillTranslationsCommand logic without the WP-CLI scaffolding.
	 * For each missing language: calls Translation::run(), then creates a new
	 * TRID-linked post or updates the existing one.
	 */
	public static function ajax_fill_missing(): void {
		// ── Auth + nonce ──────────────────────────────────────────────────────
		check_ajax_referer( self::NONCE_NAME, 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_ajax_referer above.
		if ( $post_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => 'Post not found.' ] );
		}

		// wp_navigation posts must be translated via the Router tab's FSE
		// navigation pipeline (ajax_translate_fse_navigation), which applies
		// label-only translation rules and URL rewriting. Reject here so a stale
		// "Translate missing" button from before the TRID group was populated
		// cannot trigger the generic AI pipeline on a navigation post.
		if ( $post->post_type === 'wp_navigation' ) {
			wp_send_json_error( [
				'message' => __( 'Navigation posts are translated via the Router tab, not this button.', 'lingua-forge' ),
			] );
		}

		// ── Determine which languages are missing ─────────────────────────────
		$router      = Router::get_instance();
		$source_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $source_lang ) {
			$source_lang = $router->source_language();
		}

		$all_langs    = $router->languages();
		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $post_id )
			: [];

		$to_fill = [];
		foreach ( $all_langs as $lang ) {
			if ( $lang !== $source_lang && empty( $translations[ $lang ] ) ) {
				$to_fill[] = $lang;
			}
		}

		if ( empty( $to_fill ) ) {
			wp_send_json_success( [
				'message' => 'All translations already exist.',
				'results' => [],
			] );
		}

		// ── Creation gate ─────────────────────────────────────────────────────
		// Allow external integrations (e.g. WooCommerce) to block translated-post
		// creation until their own delegation layer is active.  Returning false
		// from this filter prevents creating a broken product post that lacks
		// price, stock, and other operational meta.
		if ( ! apply_filters( 'linguaforge_cpt_create_allowed', true, $post->post_type ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: post type slug */
					__( 'Creating translations for post type "%s" requires an active integration. Please ensure the required integration is installed and active.', 'lingua-forge' ),
					$post->post_type
				),
			] );
		}

		// ── Translation feature ───────────────────────────────────────────────
		$translation = Registry::get( 'translation' );
		if ( ! $translation instanceof Translation ) {
			wp_send_json_error( [ 'message' => 'Translation feature not available. Is an AI provider configured?' ] );
		}

		// ── Bypass save hooks for the duration of the batch ───────────────────
		// Same pattern as AbstractTranslateCommand: detach our own save-post /
		// cache-clear handlers so wp_insert_post / wp_update_post calls don't
		// fire them mid-batch, then restore afterwards.
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$results = [];
		$errors  = 0;

		foreach ( $to_fill as $lang ) {
			$result = $translation->run( $post_id, [
				'target_language' => $lang,
				'translate_mode'  => 'full',
				'force_refresh'   => false,
			] );

			if ( empty( $result['success'] ) ) {
				$results[ $lang ] = [
					'status'  => 'error',
					'message' => (string) ( $result['error'] ?? 'unknown error' ),
				];
				++$errors;
				continue;
			}

			// Re-fetch after run() in case a previous iteration created a post
			// and the TRID group has changed.
			$translations = function_exists( 'linguaforge_get_translations' )
				? linguaforge_get_translations( $post_id )
				: [];

			if ( empty( $translations[ $lang ] ) ) {
				$outcome = self::create_linked_post( $post, $lang, $result );
			} else {
				$outcome = self::update_linked_post( (int) $translations[ $lang ], $result );
			}

			if ( 'error' === $outcome['status'] ) {
				++$errors;
			} else {
				// Notify listeners (e.g. TermNameTranslator) that a translation was
				// created or updated.  Fired before meta-description generation so
				// that any post-translation side-effects are in place first.
				do_action( 'linguaforge_translation_complete', (int) $outcome['id'], $post_id, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

				// Generate and persist a meta description for the new/updated post,
				// reusing the same MetaDescription::run() stack as the CLI's
				// --with-meta-description flag.
				self::generate_meta_description( (int) $outcome['id'] );
			}

			$results[ $lang ] = $outcome;
		}

		// ── Restore save hooks ────────────────────────────────────────────────
		add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		if ( $errors > 0 && $errors === count( $to_fill ) ) {
			wp_send_json_error( [ 'message' => 'All translations failed.', 'results' => $results ] );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * Retranslate an outdated target-language post.
	 *
	 * Replicates RetranslateCommand logic without WP-CLI scaffolding:
	 *   1. Resolves the source post via the TRID group.
	 *   2. Wipes the per-language AI cache entry so no stale result is returned.
	 *   3. Calls Translation::run() with force_refresh = true.
	 *   4. Updates the target post content / title.
	 *   5. Calls linguaforge_mark_translation_synced() to clear the ⚠ flag.
	 *   6. Regenerates the meta description.
	 */
	public static function ajax_retranslate(): void {
		// ── Auth + nonce ──────────────────────────────────────────────────────
		check_ajax_referer( self::NONCE_NAME_RETRANSLATE, 'nonce' );

		$target_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_ajax_referer above.
		if ( $target_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
		}

		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		$target_post = get_post( $target_id );
		if ( ! $target_post instanceof \WP_Post ) {
			wp_send_json_error( [ 'message' => 'Post not found.' ] );
		}

		// ── Resolve target language, from-language, and source post ─────────
		$router      = Router::get_instance();
		$target_lang = (string) get_post_meta( $target_id, '_lf_lang', true );
		if ( '' === $target_lang ) {
			wp_send_json_error( [ 'message' => 'Target post has no language assigned.' ] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$from_lang = sanitize_key( wp_unslash( $_POST['from_lang'] ?? '' ) );
		if ( '' === $from_lang ) {
			$from_lang = $router->source_language(); // Fallback to configured source.
		}

		if ( $from_lang === $target_lang ) {
			wp_send_json_error( [ 'message' => 'Cannot retranslate a post into the same language it is already in.' ] );
		}

		// Block retranslation of source-language posts.  The source is authoritative;
		// overwriting it via back-translation from a translated version would corrupt
		// the content that all other translations derive from.
		if ( $target_lang === $router->source_language() ) {
			wp_send_json_error( [ 'message' => 'The source-language post cannot be retranslated. Edit it directly.' ] );
		}

		$translations = function_exists( 'linguaforge_get_translations' )
			? linguaforge_get_translations( $target_id )
			: [];

		$source_id = (int) ( $translations[ $from_lang ] ?? 0 );

		if ( $source_id <= 0 ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: language code */
					__( 'No %s version found in this translation group.', 'lingua-forge' ),
					strtoupper( $from_lang )
				),
			] );
		}

		// ── Translation feature ───────────────────────────────────────────────
		$translation = Registry::get( 'translation' );
		if ( ! $translation instanceof Translation ) {
			wp_send_json_error( [ 'message' => 'Translation feature not available. Is an AI provider configured?' ] );
		}

		// ── Wipe stale cache entry ────────────────────────────────────────────
		CacheStore::delete( $source_id, 'translation_' . $target_lang );

		// ── Bypass save hooks ─────────────────────────────────────────────────
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$result = $translation->run( $source_id, [
			'target_language' => $target_lang,
			'translate_mode'  => 'full',
			'force_refresh'   => true,
		] );

		// ── Restore save hooks ────────────────────────────────────────────────
		add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [
				'message' => (string) ( $result['error'] ?? 'Translation failed.' ),
			] );
		}

		// ── Update target post ────────────────────────────────────────────────
		$outcome = self::update_linked_post( $target_id, $result );

		if ( 'error' === $outcome['status'] ) {
			wp_send_json_error( [ 'message' => $outcome['message'] ?? 'Could not update post.' ] );
		}

		// ── Clear outdated flag ───────────────────────────────────────────────
		if ( function_exists( 'linguaforge_mark_translation_synced' ) ) {
			linguaforge_mark_translation_synced( $target_id );
		}

		// ── Notify listeners (e.g. TermNameTranslator) ───────────────────────
		do_action( 'linguaforge_translation_complete', $target_id, $source_id, $target_lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

		// ── Regenerate meta description ───────────────────────────────────────
		self::generate_meta_description( $target_id );

		wp_send_json_success( $outcome );
	}

	/**
	 * Sync: retranslate every other configured language from the given post.
	 *
	 * Thin AJAX wrapper around run_sync() — verifies the nonce, dispatches,
	 * and translates the result array into a wp_send_json_*() response.
	 */
	public static function ajax_sync(): void {
		check_ajax_referer( self::NONCE_NAME_SYNC, 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_ajax_referer above.
		$result  = self::run_sync( $post_id, true );
		$data    = array_diff_key( $result, [ 'success' => true ] );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $data );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Core Sync engine: retranslate every other configured language from the
	 * given post. Not AJAX-specific — this is what `ajax_sync()` and the
	 * public `linguaforge_sync_translations()` wrapper function (`ai/ai.php`)
	 * both call.
	 *
	 * Unlike ajax_retranslate() (one target, chosen "from" language, never the
	 * source post), this fans out from ONE source post to EVERY other language
	 * in the TRID group:
	 *   - A language with no post yet is created (same as ajax_fill_missing()).
	 *   - A language that already has a post is force-refreshed in place (same
	 *     as ajax_retranslate()).
	 *   - The primary/source language is not exempt: if this post is itself a
	 *     secondary language, its Sync call will overwrite the source post via
	 *     back-translation. That is the intended behaviour — Sync means "make
	 *     every other version match this one" — and is why the client shows a
	 *     confirmation dialog before calling this endpoint.
	 *   - Exception: a secondary-language post is blocked from doing this by
	 *     default, regardless of post type — see wc_secondary_sync_blocked()
	 *     (WooCommerce products/variations) and general_secondary_sync_blocked()
	 *     (everything else). Two independent settings, checked separately.
	 *
	 * The source-language target (when present in the target set) is always
	 * processed first, so its `_lf_source_updated_at` timestamp is fresh
	 * before every other target below it is marked synced against it.
	 *
	 * @param  int  $post_id     Post to sync FROM.
	 * @param  bool $check_caps  Whether to require current_user_can('edit_post', $post_id).
	 *                           Default true (matches the AJAX/admin-UI behaviour). Programmatic
	 *                           callers via linguaforge_sync_translations() default this to false —
	 *                           see that function's docblock for why.
	 * @return array{success:bool,message?:string,results?:array,from_lang?:string}
	 */
	public static function run_sync( int $post_id, bool $check_caps = true ): array {
		if ( $post_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Invalid post ID.' ];
		}

		if ( $check_caps && ! current_user_can( 'edit_post', $post_id ) ) {
			return [ 'success' => false, 'message' => 'Insufficient permissions.' ];
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [ 'success' => false, 'message' => 'Post not found.' ];
		}

		if ( $post->post_type === 'wp_navigation' ) {
			return [
				'success' => false,
				'message' => __( 'Navigation posts are translated via the Router tab, not this button.', 'lingua-forge' ),
			];
		}

		// ── Resolve from-language + target set ────────────────────────────────
		$router    = Router::get_instance();
		$from_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $from_lang ) {
			$from_lang = $router->source_language();
		}

		// ── WooCommerce safeguard ──────────────────────────────────────────────
		if ( self::wc_secondary_sync_blocked( $post, $from_lang, $router ) ) {
			return [
				'success' => false,
				'message' => __( 'Sync from a secondary-language WooCommerce product is disabled by default, since it would overwrite the primary product. Enable it in Settings → Behavior → WooCommerce if you want a translation to be able to become the new source content.', 'lingua-forge' ),
			];
		}

		// ── General secondary-language safeguard (every other post type) ──────
		if ( self::general_secondary_sync_blocked( $post, $from_lang, $router ) ) {
			return [
				'success' => false,
				'message' => __( 'Sync from a secondary-language post is disabled by default, since it would overwrite the primary post. Enable it in Settings → Behavior → Sync if you want a translation to be able to become the new source content.', 'lingua-forge' ),
			];
		}

		$source_lang = $router->source_language();
		$targets     = array_values( array_diff( $router->languages(), [ $from_lang ] ) );

		if ( empty( $targets ) ) {
			return [ 'success' => false, 'message' => 'No other languages are configured to sync to.' ];
		}

		// Source language first (see method docblock).
		usort( $targets, static function ( string $a, string $b ) use ( $source_lang ): int {
			if ( $a === $source_lang ) return -1;
			if ( $b === $source_lang ) return 1;
			return 0;
		} );

		// ── Translation feature ───────────────────────────────────────────────
		$translation = Registry::get( 'translation' );
		if ( ! $translation instanceof Translation ) {
			return [ 'success' => false, 'message' => 'Translation feature not available. Is an AI provider configured?' ];
		}

		// ── Bypass save hooks for the duration of the batch ───────────────────
		remove_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10 );
		remove_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		$results = [];
		$errors  = 0;

		foreach ( $targets as $lang ) {

			// Re-fetch every iteration: a prior pass may have just created or
			// updated a sibling and cleared the TRID translation cache.
			$translations = function_exists( 'linguaforge_get_translations' )
				? linguaforge_get_translations( $post_id )
				: [];

			// Wipe any stale AI cache entry so a previous translation can never be returned.
			CacheStore::delete( $post_id, 'translation_' . $lang );

			$result = $translation->run( $post_id, [
				'target_language' => $lang,
				'translate_mode'  => 'full',
				'force_refresh'   => true,
			] );

			if ( empty( $result['success'] ) ) {
				$results[ $lang ] = [
					'status'  => 'error',
					'message' => (string) ( $result['error'] ?? 'unknown error' ),
				];
				++$errors;
				continue;
			}

			if ( empty( $translations[ $lang ] ) ) {
				// Creation gate — same guard as ajax_fill_missing().
				if ( ! apply_filters( 'linguaforge_cpt_create_allowed', true, $post->post_type ) ) {
					$results[ $lang ] = [
						'status'  => 'error',
						'message' => sprintf(
							/* translators: %s: post type slug */
							__( 'Creating translations for post type "%s" requires an active integration.', 'lingua-forge' ),
							$post->post_type
						),
					];
					++$errors;
					continue;
				}
				$outcome = self::create_linked_post( $post, $lang, $result );
			} else {
				$outcome = self::update_linked_post( (int) $translations[ $lang ], $result );
			}

			if ( 'error' === $outcome['status'] ) {
				++$errors;
			} else {
				$target_id = (int) $outcome['id'];

				if ( $lang === $source_lang ) {
					// The primary post was just overwritten via back-translation.
					// Bump its own "source updated" timestamp so every other
					// sibling processed below (and any future save) measures
					// freshness against this content, not the pre-sync version.
					$router->sync->mark_source_updated( $target_id );
				} elseif ( function_exists( 'linguaforge_mark_translation_synced' ) ) {
					linguaforge_mark_translation_synced( $target_id );
				}

				do_action( 'linguaforge_translation_complete', $target_id, $post_id, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- linguaforge_ is the registered plugin prefix.

				self::generate_meta_description( $target_id );
			}

			$results[ $lang ] = $outcome;
		}

		// If this post is itself a secondary language and the primary was one
		// of the sync targets, the primary's timestamp was just bumped above —
		// mark THIS post synced against it too, so it doesn't turn up as
		// "outdated" relative to the very content it was just used to produce.
		if ( $from_lang !== $source_lang && function_exists( 'linguaforge_mark_translation_synced' ) ) {
			linguaforge_mark_translation_synced( $post_id );
		}

		// ── Restore save hooks ────────────────────────────────────────────────
		add_action( 'wp_after_insert_post', [ $router->sync,       'handle_save_post'   ], 10, 2 );
		add_action( 'wp_after_insert_post', [ $router->trid_group, 'handle_cache_clear' ], 20 );

		if ( $errors > 0 && $errors === count( $targets ) ) {
			return [ 'success' => false, 'message' => 'All translations failed.', 'results' => $results ];
		}

		return [ 'success' => true, 'results' => $results, 'from_lang' => $from_lang ];
	}

	// =========================================================================
	// Private helpers — post create / update
	// =========================================================================

	/**
	 * Create a new post linked into the source's TRID group, populated with
	 * the translated content.
	 *
	 * Mirrors AbstractTranslateCommand::create_trid_linked_post() but returns
	 * an associative result array instead of calling WP_CLI::warning().
	 *
	 * @param  \WP_Post $source  Source post.
	 * @param  string   $lang    Target language code.
	 * @param  array    $result  Translation::run() result.
	 * @return array{status:string,id?:int,edit_url?:string,message?:string}
	 */
	private static function create_linked_post( \WP_Post $source, string $lang, array $result ): array {
		$post_id = $source->ID;

		// ── Ensure the source has a TRID UUID ─────────────────────────────────
		$trid = (string) get_post_meta( $post_id, '_lf_trid', true );
		if ( '' === $trid ) {
			$trid = wp_generate_uuid4();
			update_post_meta( $post_id, '_lf_trid', $trid );
		}

		// ── Derive post title ─────────────────────────────────────────────────
		$title = ! empty( $result['translated_title'] )
			? (string) $result['translated_title']
			: $source->post_title . ' [' . strtoupper( $lang ) . ']';

		// ── Inherit source status (publish / private / draft only) ────────────
		$allowed_statuses = [ 'publish', 'private', 'draft' ];
		$target_status    = in_array( $source->post_status, $allowed_statuses, true )
			? $source->post_status
			: 'draft';

		$insert = [
			'post_title'   => $title,
			'post_content' => (string) ( $result['output'] ?? '' ),
			'post_status'  => $target_status,
			'post_type'    => $source->post_type,
			'post_author'  => (int) $source->post_author,
		];

		// ── Featured image — copy from source ─────────────────────────────────
		// Without this the translation is born with no featured image at all.
		// Skipped for WooCommerce products/variations: MetaDelegate already
		// serves `_thumbnail_id` from the source product at read time, so a
		// copy here would just be silently shadowed.
		if ( post_type_supports( $source->post_type, 'thumbnail' )
			&& ! in_array( $source->post_type, [ 'product', 'product_variation' ], true )
		) {
			$source_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
			if ( $source_thumbnail_id ) {
				$insert['meta_input'] = [ '_thumbnail_id' => $source_thumbnail_id ];
			}
		}

		$new_id = wp_insert_post( $insert, true );

		if ( is_wp_error( $new_id ) ) {
			return [
				'status'  => 'error',
				'message' => $new_id->get_error_message(),
			];
		}

		// ── Link into TRID group ──────────────────────────────────────────────
		update_post_meta( $new_id, '_lf_trid', $trid );
		update_post_meta( $new_id, '_lf_lang', $lang );

		if ( ! empty( $result['footnotes'] ) ) {
			update_post_meta( $new_id, 'footnotes', (string) $result['footnotes'] );
		}

		// Flush TRID cache now that group membership is complete.
		Router::get_instance()->trid_group->clear_translation_cache( $new_id );

		// Assign a language-specific FSE template if one exists.
		$new_post = get_post( $new_id );
		if ( $new_post instanceof \WP_Post ) {
			Router::get_instance()->sync->assign_template_if_needed( $new_id, $new_post, $lang );
		}

		// Sync translated variation children for WooCommerce variable products.
		// This path bypasses the wp_after_insert_post hook because ajax_fill_missing
		// removes that hook for the duration of the batch; call explicitly here
		// after TRID/lang meta is written so MetaDelegate::get_source_id_for() works.
		if ( 'product' === $source->post_type
			&& class_exists( 'WooCommerce' )
			&& class_exists( \LinguaForge\AI\Integrations\WooCommerce\VariationSync::class )
		) {
			\LinguaForge\AI\Integrations\WooCommerce\VariationSync::sync_variations_for( $new_id );
			\LinguaForge\AI\Integrations\WooCommerce\VariationSync::sync_wc_taxonomies_from_source( $source->ID, $new_id );
		}

		return [
			'status'   => 'created',
			'id'       => $new_id,
			'edit_url' => (string) ( get_edit_post_link( $new_id ) ?: '' ),
		];
	}

	/**
	 * Generate and persist a meta description for a freshly translated post.
	 *
	 * Mirrors AbstractTranslateCommand::generate_and_save_meta_description().
	 * Failures are silently swallowed — a missing meta description is not a
	 * reason to surface an error to the user for a list-screen batch action.
	 *
	 * @param int $target_id  Post ID to generate a meta description for.
	 */
	private static function generate_meta_description( int $target_id ): void {
		$feature = new MetaDescription();
		$result  = $feature->run( $target_id );

		if ( ! empty( $result['success'] ) && ! empty( $result['output'] ) ) {
			update_post_meta( $target_id, '_linguaforge_meta_description', (string) $result['output'] );
		}
	}

	/**
	 * Update an existing TRID-linked target post with freshly translated content.
	 *
	 * @param  int   $target_id  Target post ID.
	 * @param  array $result     Translation::run() result.
	 * @return array{status:string,id?:int,edit_url?:string,message?:string}
	 */
	private static function update_linked_post( int $target_id, array $result ): array {
		$update = [
			'ID'            => $target_id,
			'post_content'  => (string) ( $result['output'] ?? '' ),
			// Reset page_template to 'default' so wp_insert_post()'s validation never
			// sees the FSE slug already stored in _wp_page_template (e.g. 'single-product-es').
			// WP 6.7+ reads _wp_page_template via WP_Post::to_array() for any post type that
			// supports 'page-attributes' (WooCommerce adds that support to 'product'), so the
			// stored FSE slug ends up in the merged $postarr.  FSE slugs are absent from
			// get_page_templates() → invalid_page_template WP_Error when $wp_error = true.
			// assign_template_if_needed() running on wp_after_insert_post writes the correct
			// language-specific template back immediately after the save completes.
			'page_template' => 'default',
		];

		if ( ! empty( $result['translated_title'] ) ) {
			$update['post_title'] = (string) $result['translated_title'];
			$update['post_name']  = sanitize_title( (string) $result['translated_title'] );
		}

		if ( isset( $result['translated_excerpt'] ) ) {
			$update['post_excerpt'] = (string) $result['translated_excerpt'];
		}

		$updated = wp_update_post( $update, true );

		if ( is_wp_error( $updated ) ) {
			return [
				'status'  => 'error',
				'message' => $updated->get_error_message(),
			];
		}

		Router::get_instance()->trid_group->clear_translation_cache( $target_id );

		if ( ! empty( $result['footnotes'] ) ) {
			update_post_meta( $target_id, 'footnotes', (string) $result['footnotes'] );
		}

		return [
			'status'   => 'updated',
			'id'       => $target_id,
			'edit_url' => (string) ( get_edit_post_link( $target_id ) ?: '' ),
		];
	}
}
