<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\AdminSaveGuard
 *
 * Controls translated WooCommerce product saves from the admin editor.
 *
 * Intercepts at save_post priority 0 to: (1) allow post-row writes (title,
 * content, excerpt), (2) silently block every non-whitelisted meta write so
 * WC never persists operational meta (SKU, price, stock, …), and (3) disable
 * VariationSync for the save duration.  Torn down at redirect_post_location
 * after the full save chain (including inner wp_update_post calls) completes.
 *
 * Only activates when woocommerce_meta_nonce is present in $_POST (classic
 * admin editor).  REST API and programmatic saves pass straight through.
 *
 * Whitelisted meta keys (allowed through during an intercepted save):
 *   _lf_*              — LF-internal state (lang, trid, …).
 *   _wp_page_template  — handle_save_post() must correct the FSE template slug.
 *   _edit_lock / _edit_last — WP concurrent-edit tracking (harmless).
 *
 * SKU: MetaDelegate serves source SKU at runtime via get_post_metadata, but
 * wc_product_has_unique_sku() (the WC helper function) queries the
 * wc_product_meta_lookup table directly and can see a genuine duplicate row
 * (e.g. once WC's own lookup-table sync has run for a translated product/
 * variation). Two separate filters resolve this — do not conflate them:
 *
 *   `wc_product_pre_has_unique_sku` (WC 9.0+) — a genuine short-circuit filter.
 *   Returning a non-null bool here IS the final "is this SKU unique?" answer
 *   (true = unique). pre_has_unique_sku() below hooks this to force `true`
 *   for translated LF products/variations unconditionally, before WC's own
 *   DB check even runs.
 *
 *   `wc_product_has_unique_sku` — an internal-implementation filter with the
 *   OPPOSITE polarity despite the shared name: WC passes it $sku_found (true
 *   = a duplicate WAS found), and a truthy return from the filter chain makes
 *   the outer function return false (not unique). flag_source_sku_conflict()
 *   below hooks this ONLY to observe genuine conflicts on SOURCE products (to
 *   drive the shutdown notice-suppression pass) — it must NOT be used to try
 *   to "allow" a save by returning `true`, since that reads as "duplicate
 *   confirmed" to WC, not "allowed". (This class used to make exactly that
 *   mistake for translated products: see git history pre-2.4.1 — the
 *   `allow_source_sku_on_translated()` override returned `true` from this
 *   filter for translated products, which WC read as "yes, duplicate", so
 *   translated saves were blocked whenever a real duplicate row existed —
 *   the delegation model's whole point. Verified failing with a standalone
 *   WC-filter-chain replica before this fix; see AUDIT-2026-06-06.md follow-up.)
 *
 * whitelist_meta_write() also blocks the subsequent _sku write during
 * intercepted classic-editor saves of a translated PRODUCT post. It does NOT
 * cover the WC_AJAX variation-save path (save_variations), which is a
 * separate request with no woocommerce_meta_nonce POST — this is why a
 * translated product's own Variations tab can still physically write `_sku`
 * onto a translated variation if someone types a value there directly. That
 * stray row is the "genuine duplicate" the two filters above exist to work
 * around; it does not, on its own, indicate data corruption.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.2.14
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class AdminSaveGuard {

	// =========================================================================
	// State
	// =========================================================================

	/**
	 * True while an admin save of a translated product is in progress.
	 *
	 * Set by maybe_intercept_translated_save(); cleared by teardown_intercept().
	 * Controls whether whitelist_meta_write() blocks meta writes.
	 *
	 * Stays true for the entire save chain (including secondary wp_update_post
	 * calls from WC's price-recalculation hooks) and is reset at
	 * redirect_post_location once edit_post() has fully returned.
	 *
	 * @var bool
	 */
	private static bool $intercepting = false;

	/**
	 * Product ID whose spurious SKU-duplicate error should be suppressed before
	 * WC writes it to the persistent option.  Set by flag_source_sku_conflict()
	 * when a source product's conflict is identified as a false positive (all
	 * conflicting rows belong to the same LF translation group).  Null means no
	 * suppression is pending.
	 *
	 * @var int|null
	 */
	private static ?int $pending_sku_suppress_product = null;

	/**
	 * SKU value associated with $pending_sku_suppress_product.
	 *
	 * @var string
	 */
	private static string $pending_sku_suppress_sku = '';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// ── SKU duplicate guard (translated products) ─────────────────────────
		// Genuine short-circuit (WC 9.0+): true/false here IS the final answer,
		// bypassing WC's own DB check entirely for translated LF products.
		add_filter( 'wc_product_pre_has_unique_sku', [ self::class, 'pre_has_unique_sku' ], 10, 3 );

		// Raw/internal filter — observation-only, for source-side notice suppression.
		// Priority 10 (default) — fires after WC's own filter callbacks (none).
		add_filter( 'wc_product_has_unique_sku', [ self::class, 'flag_source_sku_conflict' ], 10, 3 );

		// ── SKU false-positive notice suppression (source products) ───────────
		// WC stores product errors in a WP option at shutdown priority 10 via
		// WC_Admin_Meta_Boxes::append_to_error_store().  We hook at priority 5 —
		// before WC writes — and remove the spurious SKU-duplicate error from the
		// static $meta_box_errors array so it is never persisted.  This is
		// page-context-independent: the option never contains the false positive,
		// so it cannot appear on any subsequent admin page (Categories, etc.).
		add_action( 'shutdown', [ self::class, 'suppress_sku_error_before_store' ], 5 );

		// ── Translated-product text-only save ─────────────────────────────────
		// Intercept on save_post at priority 0 — fires before WC's save_post
		// priority 1 callback (WC_Admin_Meta_Boxes::save_meta_boxes).
		// save_post_product fires AFTER save_post, so it is too late to block WC.
		// Meta whitelist filters are always registered but are no-ops until
		// $intercepting = true.
		add_action( 'save_post',            [ self::class, 'maybe_intercept_translated_save' ], 0, 2 );
		add_filter( 'update_post_metadata', [ self::class, 'whitelist_meta_write' ], 0, 3 );
		add_filter( 'add_post_metadata',    [ self::class, 'whitelist_meta_write' ], 0, 3 );
	}

	// =========================================================================
	// Translated-product admin-save interception
	// =========================================================================

	/**
	 * Detect an admin-form save of a translated product and configure the
	 * request for text-only persistence.
	 *
	 * Fires on save_post at priority 0 — before WooCommerce's meta-box save at
	 * priority 1 (WC_Admin_Meta_Boxes::save_meta_boxes).  save_post fires before
	 * save_post_{post_type}, so hooking save_post_product would be too late.
	 *
	 * If the post is a 'product', is a translated (non-source) product, and the
	 * WooCommerce meta nonce is present in $_POST (i.e. this is a direct
	 * admin-editor save, not a programmatic call), the method:
	 *
	 *   • Sets $intercepting = true so whitelist_meta_write() activates.
	 *   • Disables VariationSync for this request.
	 *   • Registers teardown_intercept() on redirect_post_location (priority 99)
	 *     so state is restored after edit_post() has fully returned.
	 *
	 * @param int      $post_id Product post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function maybe_intercept_translated_save( int $post_id, \WP_Post $post ): void {
		// save_post fires for every post type; bail early for non-products.
		if ( 'product' !== $post->post_type ) {
			return;
		}

		if ( self::$intercepting ) {
			return; // Re-entrant call from WC's $variable_product->save() — already set.
		}

		// Only intercept direct admin-editor saves (WC nonce present).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- existence check only; verification is WC's responsibility.
		if ( empty( $_POST['woocommerce_meta_nonce'] ) ) {
			return;
		}

		$lang = (string) get_post_meta( $post_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return; // Not LF-managed.
		}

		if ( $lang === Router::get_instance()->source_language() ) {
			return; // Source product — normal WC save; SKU notice handled by filter_sku_error_on_display().
		}

		self::$intercepting = true;

		// Remove identity keys from $_POST before WC's save_meta_boxes (priority 1) runs.
		//
		// WC_Product::set_sku() and set_global_unique_id() both unconditionally call
		// a direct SQL uniqueness query (wc_product_has_unique_sku /
		// wc_product_has_unique_global_unique_id).  Those queries find the source
		// product's row (same identifier) and return $is_unique = false.  WC then
		// calls $this->error() which throws WC_Data_Exception.  On some hosts this
		// exception is not caught by WC_Admin_Meta_Boxes::save_meta_boxes(),
		// propagates through the save_post dispatcher, and terminates the request
		// (blank page / PHP timeout).
		//
		// Both keys are owned by MetaDelegate (delegated from the source product at
		// runtime) so there is nothing to validate or write during a text-only admin
		// save.  Unsetting them causes WC_Meta_Box_Product_Data::save() to skip the
		// isset() branches entirely — no setter call, no SQL, no exception.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		unset( $_POST['_sku'], $_POST['_global_unique_id'] );

		// Variation sync is not needed on admin saves: retranslation builds the
		// full structure; running it here would cascade into WC's price hooks.
		// Stays disabled until teardown_intercept() fires at redirect_post_location.
		VariationSync::disable_for_request();

		// Tear down only AFTER edit_post() has fully returned (all inner and outer
		// wp_after_insert_post calls complete).  redirect_post_location is the
		// earliest reliable hook that fires after the entire save chain, and it
		// only fires in the classic admin path — the same path that presents the
		// woocommerce_meta_nonce we checked above.
		add_filter( 'redirect_post_location', [ self::class, 'teardown_intercept' ], 99 );
	}

	/**
	 * Allow only whitelisted meta keys to be written during an intercepted
	 * translated-product admin save.  All other writes return true (fake success)
	 * so WooCommerce sees "write OK" and does not retry or cascade.
	 *
	 * Hooked on both update_post_metadata and add_post_metadata at priority 0.
	 * The $check parameter is null when no earlier filter has acted; returning
	 * null means "proceed normally", any other value short-circuits the write.
	 *
	 * @param  mixed  $check     null = proceed; anything else = short-circuit result.
	 * @param  int    $object_id Post ID receiving the meta write.
	 * @param  string $meta_key  Meta key being written.
	 * @return mixed  null to allow; true to fake success without writing.
	 */
	public static function whitelist_meta_write( mixed $check, int $object_id, string $meta_key ): mixed {
		if ( ! self::$intercepting ) {
			return $check;
		}

		// LF-internal meta — always allow.
		if ( str_starts_with( $meta_key, '_lf_' ) ) {
			return $check;
		}

		// Page template — handle_save_post() corrects the FSE slug here.
		if ( '_wp_page_template' === $meta_key ) {
			return $check;
		}

		// WP housekeeping meta — harmless to allow.
		if ( '_edit_lock' === $meta_key || '_edit_last' === $meta_key ) {
			return $check;
		}

		// Everything else: fake success.  WooCommerce treats the return value of
		// update_post_meta() / add_post_meta() as truthy = "write succeeded" and
		// does not retry, validate, or cascade.
		return true;
	}

	/**
	 * Restore normal save behaviour after the translated-product admin save chain
	 * completes.
	 *
	 * Hooked on redirect_post_location at priority 99 — fires after edit_post()
	 * has fully returned (all inner and outer wp_after_insert_post done).
	 *
	 * @param  string $location  The redirect URL (passed through unchanged).
	 * @return string
	 */
	public static function teardown_intercept( string $location ): string {
		self::$intercepting = false;
		VariationSync::enable_for_request();
		remove_filter( 'redirect_post_location', [ self::class, 'teardown_intercept' ], 99 );
		return $location;
	}

	// =========================================================================
	// SKU duplicate guard
	// =========================================================================

	/**
	 * Force translated LF products/variations to be treated as SKU-unique,
	 * bypassing WC's own uniqueness check entirely.
	 *
	 * Hooked on `wc_product_pre_has_unique_sku` (WC 9.0+), a genuine short-circuit
	 * filter: a non-null bool returned here IS the final "is unique?" answer
	 * (true = unique), so returning `true` reliably allows the save — unlike the
	 * legacy `wc_product_has_unique_sku` filter (see flag_source_sku_conflict()),
	 * whose polarity is inverted and cannot be used to "allow" a save this way.
	 *
	 * For a translated product/variation, any "duplicate" WC could find is always
	 * the source's own _sku row (or a stray physical row left on the translated
	 * post — see class docblock) — never a genuinely unrelated product. Source
	 * products/variations are left to WC's normal check (return null).
	 *
	 * @param  bool|null $short_circuit  null = no earlier filter decided; proceed normally.
	 * @param  int       $product_id     WooCommerce product/variation ID being validated.
	 * @param  string    $sku            SKU value being checked.
	 * @return bool|null
	 */
	public static function pre_has_unique_sku( ?bool $short_circuit, int $product_id, string $sku ): ?bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $sku accepted for filter signature compatibility; not needed here.
		if ( null !== $short_circuit ) {
			return $short_circuit; // An earlier filter already decided — don't interfere.
		}

		$post = get_post( $product_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			return null;
		}

		$lang = (string) get_post_meta( $product_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return null; // Not managed by LF.
		}

		if ( $lang === Router::get_instance()->source_language() ) {
			return null; // Source product/variation — let WC validate normally.
		}

		return true; // Translated — always unique from LF's perspective.
	}

	/**
	 * Observe genuine SKU conflicts on SOURCE products/variations and flag them
	 * for possible notice suppression at shutdown.
	 *
	 * Hooked on the legacy `wc_product_has_unique_sku` filter. WARNING: this
	 * filter's polarity is the OPPOSITE of "is_unique" despite the shared name —
	 * WC passes $sku_found (true = a duplicate WAS found via direct SQL against
	 * wc_product_meta_lookup), and a truthy value returned from the filter chain
	 * makes the outer wc_product_has_unique_sku() function return false (not
	 * unique). This method never changes that outcome for source products: WC's
	 * own validation is left to stand (the actual save is genuinely blocked when
	 * a real conflict exists), it only records the candidate for the shutdown
	 * notice-suppression pass in suppress_sku_error_before_store().
	 *
	 * Translated products/variations are already resolved upstream by
	 * pre_has_unique_sku() (WC never reaches this filter for them once that
	 * short-circuits), so this method passes them through unchanged as a
	 * defensive fallback only.
	 *
	 * @param  bool   $sku_found  true = WC's direct SQL check found a conflicting row.
	 * @param  int    $product_id WooCommerce product ID being validated.
	 * @param  string $sku        SKU value being checked.
	 * @return bool
	 */
	public static function flag_source_sku_conflict( bool $sku_found, int $product_id, string $sku ): bool {
		if ( ! $sku_found ) {
			return $sku_found; // No conflict — nothing to flag.
		}

		$post = get_post( $product_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			return $sku_found;
		}

		$lang = (string) get_post_meta( $product_id, '_lf_lang', true );
		if ( '' === $lang || $lang !== Router::get_instance()->source_language() ) {
			return $sku_found; // Not LF-managed, or a translated post (already resolved upstream).
		}

		// Record the candidate for suppression; the actual conflict-group check
		// happens at shutdown priority 5 in suppress_sku_error_before_store() so
		// we don't add SQL inside a hot filter chain.
		self::$pending_sku_suppress_product = $product_id;
		self::$pending_sku_suppress_sku     = $sku;
		return $sku_found;
	}

	// =========================================================================
	// Source-product SKU notice suppression
	// =========================================================================

	/**
	 * Remove the spurious "Invalid or duplicated SKU" error from WC's static
	 * error array before WC writes it to the persistent option.
	 *
	 * Runs on `shutdown` at priority 5 — before WC_Admin_Meta_Boxes::append_to_error_store()
	 * at priority 10.  If $pending_sku_suppress_product was set by
	 * flag_source_sku_conflict() during this request, and the SQL check
	 * confirms the conflict is entirely within the same LF translation group, the
	 * SKU error is removed from WC_Admin_Meta_Boxes::$meta_box_errors.
	 *
	 * Because suppression happens before the option is written, the error never
	 * reaches the persistent store, so it cannot appear on any subsequent admin
	 * page (Categories, product list, etc.) regardless of which page the user
	 * visits after saving.
	 */
	public static function suppress_sku_error_before_store(): void {
		if ( null === self::$pending_sku_suppress_product ) {
			return;
		}

		// Full conflict-group check using direct SQL (safe: shutdown context,
		// not inside a hot filter chain).
		if ( ! self::sku_conflict_is_lf_translation(
			self::$pending_sku_suppress_product,
			self::$pending_sku_suppress_sku
		) ) {
			return;
		}

		if ( ! class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			return;
		}

		$sku_error = __( 'Invalid or duplicated SKU.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional: must match the exact string WooCommerce writes into WC_Admin_Meta_Boxes::$meta_box_errors.

		\WC_Admin_Meta_Boxes::$meta_box_errors = array_values( array_filter(
			(array) \WC_Admin_Meta_Boxes::$meta_box_errors,
			fn( string $e ) => $e !== $sku_error
		) );
	}

	/**
	 * Check whether every product that shares $sku in the DB belongs to the same
	 * LF translation group as $product_id.
	 *
	 * Returns true  → all conflicts are LF translations of this product (false positive).
	 * Returns false → at least one genuinely unrelated product has the same SKU, or
	 *                 the product has no TRID (cannot verify group membership).
	 *
	 * Uses direct SQL throughout to avoid triggering MetaDelegate's get_post_metadata
	 * filter inside a context where WC may already be iterating meta reads.
	 *
	 * @param  int    $product_id  Source product post ID.
	 * @param  string $sku         SKU being tested.
	 * @return bool
	 */
	private static function sku_conflict_is_lf_translation( int $product_id, string $sku ): bool {
		global $wpdb;

		$trid = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta}
				 WHERE post_id = %d AND meta_key = '_lf_trid' LIMIT 1",
				$product_id
			)
		);
		if ( '' === $trid ) {
			return false;
		}

		$conflicting_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type IN ('product','product_variation')
				   AND p.post_status != 'trash'
				   AND pm.meta_key = '_sku'
				   AND pm.meta_value = %s
				   AND p.ID != %d",
				$sku,
				$product_id
			)
		);
		if ( empty( $conflicting_ids ) ) {
			return true; // No conflicts in DB at query time — nothing to suppress.
		}

		$placeholders   = implode( ',', array_fill( 0, count( $conflicting_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders contains only %d tokens; $wpdb->postmeta is a trusted table name constant; all IDs come from a prior prepare'd query.
		$conflict_trids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN ({$placeholders}) AND meta_key = '_lf_trid'",
				...$conflicting_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $conflict_trids as $ct ) {
			if ( (string) $ct !== $trid ) {
				return false; // Genuine duplicate from an unrelated product.
			}
		}

		return true;
	}

}
