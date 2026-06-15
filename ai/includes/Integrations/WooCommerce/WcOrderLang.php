<?php
declare(strict_types=1);

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use LinguaForge\Router\Router;
use WC_Order;

/**
 * Captures the purchase language on WC orders and switches locale for
 * customer-facing transactional emails.
 *
 * Stores `_lf_order_lang` via HPOS-safe `update_meta_data()` on
 * `woocommerce_checkout_create_order` and `woocommerce_store_api_checkout_create_order`.
 * Locale switch: status hook (priority 1) stashes the lang; `woocommerce_email_setup_locale`
 * applies it and returns false to suppress WC's own user-locale switch;
 * `woocommerce_email_restore_locale` resets. Admin-triggered resends handled via
 * `woocommerce_before_resend_order_emails`. `linguaforge_email_order_lang` filter
 * lets `WcPageBridge` resolve order links to translated page versions.
 * Adds a "Language" column to both CPT and HPOS order list screens.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.3.0
 */
class WcOrderLang {

	// =========================================================================
	// State (static because hooks are registered as static callbacks)
	// =========================================================================

	/**
	 * Language stashed from the order status hook, waiting for the email's
	 * setup_locale filter to consume it.
	 *
	 * @var string
	 */
	private static string $pending_email_lang = '';

	/**
	 * Set to true while we are inside a locale-switched email render so that
	 * restore_locale knows it needs to call restore_current_locale().
	 *
	 * @var bool
	 */
	private static bool $locale_switched = false;

	/**
	 * The language currently active for an email render (non-empty only between
	 * setup_locale and restore_locale).  Exposed via the
	 * `linguaforge_email_order_lang` filter so WcPageBridge can resolve page links.
	 *
	 * @var string
	 */
	private static string $current_email_lang = '';

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {

		// --- Capture purchase language -----------------------------------------

		add_action( 'woocommerce_checkout_create_order',          [ self::class, 'capture_order_lang' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_create_order', [ self::class, 'capture_order_lang' ], 10, 2 );

		// --- Seed pending lang before email triggers ---------------------------
		// Priority 1 fires before WC email triggers (default priority 10); the
		// matching priority-99 clear fires *after* them so the stashed language
		// can never outlive the transition that set it. This matters when a
		// transition's email is admin-only (e.g. failed / cancelled) and never
		// calls woocommerce_email_setup_locale to consume the value — without the
		// clear, a stale language could be picked up by a later customer email in
		// the same request (e.g. a bulk admin status change across orders).

		$status_hooks = [
			'pending', 'processing', 'completed', 'on-hold',
			'cancelled', 'refunded', 'failed',
		];
		foreach ( $status_hooks as $status ) {
			add_action( 'woocommerce_order_status_' . $status, [ self::class, 'seed_pending_email_lang' ], 1, 2 );
			add_action( 'woocommerce_order_status_' . $status, [ self::class, 'clear_pending_email_lang' ], 99 );
		}

		// Refund emails fire on dedicated hooks, not order status transitions.
		add_action( 'woocommerce_order_refunded',           [ self::class, 'seed_pending_email_lang_by_order_id' ], 1 );
		add_action( 'woocommerce_order_refunded',           [ self::class, 'clear_pending_email_lang' ], 99 );
		add_action( 'woocommerce_order_partially_refunded', [ self::class, 'seed_pending_email_lang_by_order_id' ], 1 );
		add_action( 'woocommerce_order_partially_refunded', [ self::class, 'clear_pending_email_lang' ], 99 );

		// Admin "Resend email" button.
		add_action( 'woocommerce_before_resend_order_emails', [ self::class, 'seed_from_resend' ], 1, 1 );

		// --- Locale switch for customer emails ---------------------------------

		add_filter( 'woocommerce_email_setup_locale',   [ self::class, 'maybe_switch_email_locale' ] );
		add_action( 'woocommerce_email_restore_locale', [ self::class, 'maybe_restore_email_locale' ] );

		// --- Expose active email lang for WcPageBridge -------------------------

		add_filter( 'linguaforge_email_order_lang', [ self::class, 'get_current_email_lang' ] );

		// --- Admin order list column -------------------------------------------

		if ( is_admin() ) {
			// Legacy CPT orders list.
			add_filter( 'manage_edit-shop_order_columns',        [ self::class, 'add_lang_column' ] );
			add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'render_lang_column_post' ], 10, 2 );

			// HPOS orders list.
			add_filter( 'manage_woocommerce_page_wc-orders_columns',        [ self::class, 'add_lang_column' ] );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column',  [ self::class, 'render_lang_column_hpos' ], 10, 2 );
		}
	}

	// =========================================================================
	// Capture: write _lf_order_lang on order creation
	// =========================================================================

	/**
	 * Writes `_lf_order_lang` to the order immediately after WooCommerce creates
	 * it, covering both the classic checkout and the Store-API/blocks checkout.
	 *
	 * `LF_LANG` is defined at this point:
	 *  - Classic checkout runs on a frontend page URL that always has a language
	 *    prefix (translated) or falls back to the source language by default.
	 *  - Store API checkout runs via REST; `detect_lang_safe()` resolves the lang
	 *    from `?lang=X` (appended by frontend-lang.js) or from the `lf_lang`
	 *    cookie set when the customer added items to the cart.
	 *
	 * Do NOT call `$order->save()` here — WooCommerce will save the order after
	 * this hook returns and will persist the staged meta automatically.
	 *
	 * @param WC_Order $order The order being created.
	 * @param mixed    $_data Checkout data array (classic) or WP_REST_Request (Store API).
	 */
	public static function capture_order_lang( WC_Order $order, mixed $_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_data required by woocommerce_checkout_create_order / woocommerce_store_api_checkout_create_order filter signature.
		$lang = defined( 'LF_LANG' ) && '' !== LF_LANG
			? LF_LANG
			: Router::get_instance()->context->source_language();

		$order->update_meta_data( '_lf_order_lang', $lang );
	}

	// =========================================================================
	// Seed: stash pending lang before email triggers fire
	// =========================================================================

	/**
	 * Stash the order's purchase language so the email setup_locale filter can
	 * read it.  Fires on `woocommerce_order_status_{status}` at priority 1,
	 * which is before WC registers its email triggers (priority 10).
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public static function seed_pending_email_lang( int $order_id, WC_Order $order ): void {
		$lang = $order->get_meta( '_lf_order_lang' );
		if ( $lang ) {
			self::$pending_email_lang = (string) $lang;
		}
	}

	/**
	 * Variant for hooks that pass only `$order_id` as the first argument
	 * (e.g. `woocommerce_order_refunded`, `woocommerce_order_partially_refunded`).
	 *
	 * @param int $order_id
	 */
	public static function seed_pending_email_lang_by_order_id( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}
		$lang = $order->get_meta( '_lf_order_lang' );
		if ( $lang ) {
			self::$pending_email_lang = (string) $lang;
		}
	}

	/**
	 * Seed from the admin "Resend email" action.
	 *
	 * @param WC_Order $order
	 */
	public static function seed_from_resend( WC_Order $order ): void {
		$lang = $order->get_meta( '_lf_order_lang' );
		if ( $lang ) {
			self::$pending_email_lang = (string) $lang;
		}
	}

	/**
	 * Discard any pending language that was seeded for this transition but not
	 * consumed by an email's `setup_locale`.
	 *
	 * Hooked at priority 99 on the same status / refund hooks that seed at
	 * priority 1, so it runs *after* WC's priority-10 email triggers. For a
	 * customer email the value is already consumed (and cleared) by
	 * {@see maybe_switch_email_locale()} before we get here, making this a no-op;
	 * for an admin-only email (failed / cancelled, etc.) nothing consumed it, and
	 * this prevents the stale value from leaking into a later customer email in
	 * the same request (e.g. a bulk admin status change across orders).
	 */
	public static function clear_pending_email_lang(): void {
		self::$pending_email_lang = '';
	}

	// =========================================================================
	// Locale switch / restore
	// =========================================================================

	/**
	 * Hooked on `woocommerce_email_setup_locale` (filter on bool, WC 3+).
	 *
	 * WC only calls `setup_locale()` — and therefore reaches this filter — for
	 * `is_customer_email()` emails, so admin notifications are never touched.
	 *
	 * If a pending purchase language is available, we:
	 *  1. Switch the locale to that language's WP locale string.
	 *  2. Record the active lang in `$current_email_lang` (exposed via the
	 *     `linguaforge_email_order_lang` filter to WcPageBridge).
	 *  3. Return false so WC does NOT additionally switch to the customer's
	 *     saved user locale (which would override ours).
	 *
	 * @param  bool $setup WC's default: true (proceed with WC's own switch).
	 * @return bool        false when we handled the switch; $setup otherwise.
	 */
	public static function maybe_switch_email_locale( bool $setup ): bool {
		if ( '' === self::$pending_email_lang ) {
			return $setup;
		}

		$lang   = self::$pending_email_lang;
		$locale = Router::get_instance()->locale_from_lang( $lang );

		switch_to_locale( $locale );

		self::$locale_switched      = true;
		self::$current_email_lang   = $lang;
		self::$pending_email_lang   = '';

		// Prevent WC from switching again (to the user's WP profile locale).
		return false;
	}

	/**
	 * Hooked on `woocommerce_email_restore_locale` (action, WC 3+).
	 *
	 * WC fires this only for `is_customer_email()` emails — matching the setup
	 * filter — so the restore always pairs with our switch.
	 */
	public static function maybe_restore_email_locale(): void {
		if ( ! self::$locale_switched ) {
			return;
		}

		restore_current_locale();

		self::$locale_switched    = false;
		self::$current_email_lang = '';
	}

	// =========================================================================
	// Filter: expose active email lang to WcPageBridge
	// =========================================================================

	/**
	 * Returns the language code currently active for an email render, or the
	 * passed-through default when no email is being rendered.
	 *
	 * WcPageBridge calls `apply_filters('linguaforge_email_order_lang', '')`
	 * as a fallback when `LF_LANG` is not defined (email/cron context) so that
	 * order-received, my-account, and checkout links resolve to the translated
	 * page versions rather than source-language pages.
	 *
	 * @param  string $lang Default value passed by the caller (empty string).
	 * @return string
	 */
	public static function get_current_email_lang( string $lang ): string {
		return '' !== self::$current_email_lang ? self::$current_email_lang : $lang;
	}

	// =========================================================================
	// Admin: order list language column
	// =========================================================================

	/**
	 * Inserts a "Language" column before the "Actions" column in the orders list.
	 *
	 * Shared by both the legacy CPT filter and the HPOS filter.
	 *
	 * @param  string[] $columns Existing columns.
	 * @return string[]
	 */
	public static function add_lang_column( array $columns ): array {
		$reordered = [];
		foreach ( $columns as $key => $label ) {
			if ( 'wc_actions' === $key ) {
				$reordered['lf_order_lang'] = __( 'Language', 'lingua-forge' );
			}
			$reordered[ $key ] = $label;
		}
		// Fallback: append if wc_actions column was not found.
		if ( ! array_key_exists( 'lf_order_lang', $reordered ) ) {
			$reordered['lf_order_lang'] = __( 'Language', 'lingua-forge' );
		}
		return $reordered;
	}

	/**
	 * Renders the language column cell for the legacy CPT order list.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order post ID.
	 */
	public static function render_lang_column_post( string $column, int $post_id ): void {
		if ( 'lf_order_lang' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		self::render_lang_badge( $order );
	}

	/**
	 * Renders the language column cell for the HPOS order list.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order object (HPOS passes the object directly).
	 */
	public static function render_lang_column_hpos( string $column, WC_Order $order ): void {
		if ( 'lf_order_lang' !== $column ) {
			return;
		}
		self::render_lang_badge( $order );
	}

	/**
	 * Outputs the language badge HTML for an order.
	 *
	 * @param WC_Order $order
	 */
	private static function render_lang_badge( WC_Order $order ): void {
		$lang = $order->get_meta( '_lf_order_lang' );

		if ( ! $lang ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		printf(
			'<span class="lf-lang-badge" title="%s">%s</span>',
			esc_attr( sprintf(
				/* translators: %s: two-character language code */
				__( 'Order placed in language: %s', 'lingua-forge' ),
				strtoupper( $lang )
			) ),
			esc_html( strtoupper( (string) $lang ) )
		);
	}
}
