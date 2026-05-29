<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\StockRouter
 *
 * Routes WooCommerce stock WRITES to the source-language product.
 *
 * With the shared-stock delegation model, MetaDelegate transparently redirects
 * all operational meta READS (including _stock, _stock_status, etc.) to the
 * source product. However, WooCommerce stock WRITES — triggered by purchases,
 * manual stock adjustments, and WC's stock management UI — target the product
 * ID they were given. If a customer purchases a translated product, the order
 * item carries the translated product ID, and WooCommerce will attempt to write
 * the new stock level to that translated product post.
 *
 * This class intercepts `update_post_metadata` for stock keys on translated
 * product posts and redirects the write to the source product instead. Returning
 * `true` from the filter short-circuits WordPress's own DB write, ensuring the
 * translated post remains meta-free for the stock keys, and the source product
 * is the single source of truth.
 *
 * Stock keys covered: _stock, _stock_qty, _stock_status, _manage_stock,
 * _backorders, _sold_individually.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class StockRouter {

	/**
	 * Meta keys whose writes must be routed to the source product.
	 *
	 * Intentionally narrower than MetaDelegate::OPERATIONAL_KEYS: we only
	 * intercept writes that WooCommerce performs during order processing and
	 * stock management. Price updates, dimension changes, etc. should be done
	 * by the admin on the source product directly — intercepting those writes
	 * would create confusing behaviour in the product editor.
	 *
	 * @var string[]
	 */
	private const STOCK_WRITE_KEYS = [
		'_stock',
		'_stock_qty',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_sold_individually',
	];

	/**
	 * Reentrancy guard: prevents recursion when we call update_post_meta()
	 * for the source product from inside the update_post_metadata filter.
	 *
	 * @var array<string,true>
	 */
	private static array $routing = [];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 1 — run before most other plugins, same reasoning as MetaDelegate.
		add_filter( 'update_post_metadata', [ self::class, 'route_stock_write' ], 1, 5 );
		add_filter( 'add_post_metadata',    [ self::class, 'route_stock_add'   ], 1, 4 );
	}

	// =========================================================================
	// Filter callbacks
	// =========================================================================

	/**
	 * Intercept update_post_meta() for stock keys on translated products and
	 * redirect the write to the source product.
	 *
	 * WordPress calls `apply_filters("update_{$type}_metadata", null, $object_id, $meta_key, $meta_value, $prev_value)`.
	 * Returning null means "not filtered". Returning a bool short-circuits the
	 * DB write: true = "pretend it succeeded", false = "pretend it failed".
	 *
	 * @param  mixed  $check      Current filter value (null = not yet filtered).
	 * @param  int    $object_id  Post ID receiving the update.
	 * @param  string $meta_key   Meta key being written.
	 * @param  mixed  $meta_value New value.
	 * @param  mixed  $prev_value Previous value (used by WordPress duplicate-value guard).
	 * @return mixed  null to pass through, or true when routed to source product.
	 */
	public static function route_stock_write( mixed $check, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $prev_value is required by the update_{type}_metadata filter signature (5 args) but not needed for routing logic.
		return self::maybe_route( $check, $object_id, $meta_key, $meta_value, false );
	}

	/**
	 * Intercept add_post_meta() for stock keys on translated products and
	 * redirect the write to the source product.
	 *
	 * Signature: (null|bool $check, int $object_id, string $meta_key, mixed $meta_value)
	 *
	 * @param  mixed  $check      Current filter value (null = not yet filtered).
	 * @param  int    $object_id  Post ID receiving the add.
	 * @param  string $meta_key   Meta key being written.
	 * @param  mixed  $meta_value Value to add.
	 * @return mixed  null to pass through, or true when routed to source product.
	 */
	public static function route_stock_add( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		return self::maybe_route( $check, $object_id, $meta_key, $meta_value, true );
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Core routing logic shared by both update and add callbacks.
	 *
	 * @param  mixed  $check      Current filter value.
	 * @param  int    $object_id  Post ID receiving the write.
	 * @param  string $meta_key   Meta key.
	 * @param  mixed  $meta_value Value to write.
	 * @param  bool   $is_add     True for add_post_meta, false for update_post_meta.
	 * @return mixed
	 */
	private static function maybe_route( mixed $check, int $object_id, string $meta_key, mixed $meta_value, bool $is_add ): mixed {

		// ── 1. Key guard ───────────────────────────────────────────────────────
		if ( ! in_array( $meta_key, self::STOCK_WRITE_KEYS, true ) ) {
			return $check;
		}

		// ── 2. Reentrancy guard ────────────────────────────────────────────────
		$guard = $object_id . ':' . $meta_key;
		if ( isset( self::$routing[ $guard ] ) ) {
			return $check;
		}

		// ── 3. Post type guard ─────────────────────────────────────────────────
		$post = get_post( $object_id );
		if ( ! $post ) {
			return $check;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return $check;
		}

		// ── 4. Language guard — only route for translated (non-source) posts ───
		$lang = (string) get_post_meta( $object_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $check;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $check; // Already writing to the source — no routing needed.
		}

		// ── 5. Resolve source product ID ──────────────────────────────────────
		$source_id = MetaDelegate::get_source_id_for( $object_id );
		if ( ! $source_id || $source_id === $object_id ) {
			return $check; // Fail safe — let the write proceed on the translated post.
		}

		// ── 6. Route write to source product ──────────────────────────────────
		self::$routing[ $guard ] = true;

		if ( $is_add ) {
			add_post_meta( $source_id, $meta_key, $meta_value );
		} else {
			update_post_meta( $source_id, $meta_key, $meta_value );
		}

		unset( self::$routing[ $guard ] );

		// Return true: "pretend the write succeeded on the translated post".
		// The actual write went to the source. The translated post remains
		// meta-free for this key, so MetaDelegate continues to serve the
		// source product's value on all subsequent reads.
		return true;
	}
}
