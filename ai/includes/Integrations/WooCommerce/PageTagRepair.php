<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\PageTagRepair
 *
 * Ensures WooCommerce's auto-created pages (Shop, Cart, Checkout, My Account,
 * Terms) carry a `_lf_lang` meta value.
 *
 * Problem
 * -------
 * WooCommerce creates these pages via wp_insert_post() during plugin activation.
 * If LF is activated after WooCommerce, or if WC recreates a missing page while
 * LF is active but its save_post handler has not yet been registered for that
 * execution context, those pages end up without `_lf_lang`.  Without it the
 * language filter INNER JOIN introduced in CatalogQuery excludes them from
 * frontend product-page queries (wc_get_page_id falls back to these posts).
 *
 * Fix strategy
 * ------------
 * Repair lazily rather than on every request: hook into `load-edit.php` for the
 * pages list screen *only when "All languages" is selected*.  That moment is a
 * natural editorial checkpoint where untagged pages are visible but confusing,
 * and the cost of a handful of postmeta reads + rare writes is negligible.
 *
 * The source language is used as the default — WC's built-in pages are always
 * part of the primary site language.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.2.2
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class PageTagRepair {

	/**
	 * WooCommerce option keys that store built-in page IDs.
	 *
	 * @var string[]
	 */
	private const WC_PAGE_OPTIONS = [
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
	];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		add_action( 'load-edit.php', [ self::class, 'maybe_repair' ] );
	}

	// =========================================================================
	// Hook callback
	// =========================================================================

	/**
	 * Tag any untagged WooCommerce built-in pages with the source language,
	 * but only when viewing the pages list with "All languages" selected.
	 *
	 * Conditions checked before running:
	 *  • Current screen is the pages list (post_type = 'page').
	 *  • The user has the `edit_posts` capability.
	 *  • No specific language filter is active — "All languages" is selected.
	 *    (Running under a language filter would leave untagged pages invisible
	 *    on re-load, making the repair confusingly silent.)
	 */
	public static function maybe_repair(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Only act on the pages list screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL post_type param; no data is modified.
		$screen_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( 'page' !== $screen_type ) {
			return;
		}

		// Resolve the persisted filter, then allow a fresh GET param to override it
		// (same order used by Filters::persist_admin_lang_filter()).
		$lang_filter = (string) get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( isset( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang_filter = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
		}

		// Only repair when "All languages" is showing — any specific filter
		// means the user is not looking at all posts, so silence the writes.
		if ( '' !== $lang_filter ) {
			return;
		}

		self::tag_untagged_wc_pages( self::resolve_source_language() );
	}

	// =========================================================================
	// Repair logic
	// =========================================================================

	/**
	 * Iterates over WooCommerce's built-in page option keys, reads each stored
	 * page ID, and writes `_lf_lang` if the meta is absent.
	 *
	 * @param string $lang Language code to assign (the LF source language).
	 */
	private static function tag_untagged_wc_pages( string $lang ): void {
		foreach ( self::WC_PAGE_OPTIONS as $option ) {
			$page_id = (int) get_option( $option, 0 );
			if ( $page_id <= 0 ) {
				continue; // Option not set — WC page not configured.
			}

			if ( get_post_meta( $page_id, '_lf_lang', true ) ) {
				continue; // Already tagged — nothing to do.
			}

			update_post_meta( $page_id, '_lf_lang', $lang );
		}
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Returns the Lingua Forge source language.
	 *
	 * @return string Language code, e.g. "es".
	 */
	private static function resolve_source_language(): string {
		return Router::get_instance()->source_language();
	}
}
