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
 * Two complementary interception layers handle stock writes:
 *
 *  1. WordPress metadata filters (`update_post_metadata`, `add_post_metadata`)
 *     intercept writes that go through `update_post_meta()` / `add_post_meta()`.
 *     This covers the `$product->save()` path (WC calls `update_or_delete_post_meta()`
 *     which calls `update_post_meta()`).
 *
 *  2. `woocommerce_update_product_stock_query` intercepts the *direct SQL* path
 *     used by `WC_Product_Data_Store_CPT::update_product_stock()` and
 *     `set_product_stock()`. These methods bypass the WordPress metadata API
 *     entirely (`$wpdb->query("UPDATE postmeta SET meta_value…")`), so they are
 *     invisible to layer 1. This filter rewrites the `post_id` in the SQL from
 *     the translated product ID to the source product ID, and clears the source
 *     product's postmeta object cache so that subsequent reads (including the
 *     `update_lookup_table` call that follows in WC immediately) see the fresh
 *     stock value.
 *
 * Stock keys covered by layer 1: _stock, _stock_status, _manage_stock,
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
	 * `_stock_qty` was removed from WooCommerce in 3.x (replaced by `_stock`).
	 * It is omitted here to keep the list accurate to WC 3.x+.
	 *
	 * @var string[]
	 */
	private const STOCK_WRITE_KEYS = [
		'_stock',
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

	/**
	 * Per-request map of translated product ID → routing context for stock
	 * writes that went through rewrite_stock_sql().
	 *
	 * Shape: [ $trans_id => [ 'source_id' => int, 'new_stock' => float|int|null ] ]
	 *
	 * Used by clear_source_meta_cache() to:
	 *  (a) clear the source product's postmeta object cache, and
	 *  (b) sync the source product's wc_product_meta_lookup row (WC-02).
	 *
	 * @var array<int, array{source_id: int, new_stock: float|int|null}>
	 */
	private static array $pending_cache_clear = [];

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {
		// Priority 1 — run before most other plugins, same reasoning as MetaDelegate.
		add_filter( 'update_post_metadata', [ self::class, 'route_stock_write' ], 1, 5 );
		add_filter( 'add_post_metadata',    [ self::class, 'route_stock_add'   ], 1, 4 );

		// Intercept WooCommerce's direct SQL stock writes.
		// WC_Product_Data_Store_CPT::update_product_stock() and set_product_stock()
		// bypass the WordPress metadata API with raw $wpdb->query() calls, making
		// them invisible to the update_post_metadata filter above. This filter
		// rewrites the post_id in the SQL to the source product ID.
		// Accept 3 args: $sql, $product_id, $new_stock — the 4th ($operation) is not needed.
		add_filter( 'woocommerce_update_product_stock_query', [ self::class, 'rewrite_stock_sql' ], 1, 3 );

		// After WC's direct SQL write completes, also clear the source product's
		// object-cache entry. WC only calls wp_cache_delete() for the translated
		// product ID; the source product's cache must be cleared separately so that
		// subsequent reads (including WC's own update_lookup_table call that follows
		// immediately) see the freshly written stock value.
		add_action( 'woocommerce_updated_product_stock', [ self::class, 'clear_source_meta_cache' ], 1, 1 );
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
	// Direct SQL interception (WooCommerce fast-path stock writes)
	// =========================================================================

	/**
	 * Rewrite the post_id in WooCommerce's direct SQL stock update to target
	 * the source product instead of the translated product.
	 *
	 * WooCommerce calls:
	 *   $sql = apply_filters( 'woocommerce_update_product_stock_query', $sql, $product_id, … )
	 *   $wpdb->query( $sql );
	 *   wp_cache_delete( $product_id, 'post_meta' );
	 *   $this->update_lookup_table( $product_id, 'wc_product_meta_lookup' );
	 *
	 * After this rewrite:
	 *  - The SQL's WHERE clause targets the source product → stock correctly decremented.
	 *  - $pending_cache_clear is populated so clear_source_meta_cache() can flush
	 *    the source product's postmeta object cache immediately after, ensuring that
	 *    the update_lookup_table() call that follows in WC reads the fresh stock value
	 *    for the translated product via MetaDelegate (which delegates to the source).
	 *
	 * @param  string         $sql        Raw SQL prepared by WC (post_id appears as a literal integer).
	 * @param  int            $product_id The translated (or source) product ID WC is targeting.
	 * @param  float|int|null $new_stock  The new stock quantity (passed by WC as the 3rd filter arg).
	 * @return string  Rewritten SQL, or original SQL when no routing is needed.
	 */
	public static function rewrite_stock_sql( string $sql, int $product_id, float|int|null $new_stock = null ): string {

		// ── 1. Post type guard ─────────────────────────────────────────────────
		$post = get_post( $product_id );
		if ( ! $post ) {
			return $sql;
		}

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product', 'product_variation' ] );
		if ( ! in_array( $post->post_type, $delegate_types, true ) ) {
			return $sql;
		}

		// ── 2. Language guard — only rewrite for translated (non-source) posts ─
		$lang = (string) get_post_meta( $product_id, '_lf_lang', true );
		if ( '' === $lang ) {
			return $sql;
		}

		$source_lang = Router::get_instance()->source_language();
		if ( $lang === $source_lang ) {
			return $sql;
		}

		// ── 3. Resolve source product ID ──────────────────────────────────────
		$source_id = MetaDelegate::get_source_id_for( $product_id );
		if ( ! $source_id || $source_id === $product_id ) {
			return $sql; // Fail safe — let the write proceed on the translated post.
		}

		// ── 4. Rewrite post_id in the SQL ──────────────────────────────────────
		// WC uses $wpdb->prepare() so the IDs appear as unquoted literal integers.
		// Use a precise regex to avoid corrupting other integer literals in the SQL
		// (e.g. WC_ROUNDING_PRECISION values, meta_value floats).
		$rewritten = preg_replace(
			'/\bpost_id\s*=\s*' . $product_id . '\b/',
			'post_id = ' . $source_id,
			$sql
		);

		if ( null === $rewritten || $rewritten === $sql ) {
			// preg_replace failed or found nothing to replace — fail safe.
			return $sql;
		}

		// ── 5. Schedule source cache clear ────────────────────────────────────
		// WC calls wp_cache_delete($product_id) for the translated product after
		// the SQL. We must also clear the source product's cache before WC's
		// update_lookup_table() call reads postmeta for the translated product
		// (MetaDelegate will delegate that read to the source, so the source
		// cache must be fresh at that point).
		// We clear it immediately here — the SQL has not run yet but the cache
		// entry will be stale regardless once the SQL executes.
		wp_cache_delete( $source_id, 'post_meta' );

		// Record source_id + new_stock for clear_source_meta_cache() which:
		//  (a) provides a second flush for any cache re-warmed between now and then, and
		//  (b) syncs the source product's wc_product_meta_lookup row (WC-02).
		self::$pending_cache_clear[ $product_id ] = [
			'source_id' => $source_id,
			'new_stock' => $new_stock,
		];

		return $rewritten;
	}

	/**
	 * After WC's direct SQL stock write and update_lookup_table() complete:
	 *
	 *  1. Clear the source product's postmeta object cache (redundant flush for
	 *     any cache re-warmed since rewrite_stock_sql() ran).
	 *  2. Sync the source product's wc_product_meta_lookup row (WC-02).
	 *     WC's update_lookup_table() ran against the translated product ID and
	 *     correctly updated that row via MetaDelegate. But the source product's
	 *     own lookup row is stale — this update corrects it.
	 *
	 * Hooked on `woocommerce_updated_product_stock` at priority 1 (fires after
	 * WC's write and lookup update complete).
	 *
	 * @param int $product_id  The product ID WC targeted (the translated product).
	 */
	public static function clear_source_meta_cache( int $product_id ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */

		$entry = self::$pending_cache_clear[ $product_id ] ?? null;
		if ( ! $entry ) {
			return;
		}

		$source_id = (int) $entry['source_id'];
		$new_stock = $entry['new_stock'];
		unset( self::$pending_cache_clear[ $product_id ] );

		// ── 1. Flush postmeta object cache for source product ─────────────────
		wp_cache_delete( $source_id, 'post_meta' );

		// ── 2. Sync wc_product_meta_lookup for source product (WC-02) ─────────
		// WC_Product_Data_Store_CPT::update_lookup_table() is a protected method
		// with no public API equivalent. We write the two stock columns directly.
		// $wpdb->wc_product_meta_lookup is registered by WC_Install::define_tables()
		// on every request after plugins_loaded — guaranteed available here.
		if ( empty( $wpdb->wc_product_meta_lookup ) ) {
			return;
		}

		$manage_stock = get_post_meta( $source_id, '_manage_stock', true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WC_Data_Store_WP::update_lookup_table() is protected; no public API exists for this targeted two-column sync.
			$wpdb->wc_product_meta_lookup,
			[
				'stock_quantity' => 'yes' === $manage_stock ? wc_stock_amount( $new_stock ) : null,
				'stock_status'   => (string) get_post_meta( $source_id, '_stock_status', true ),
			],
			[ 'product_id' => $source_id ],
			[ '%f',         '%s' ],
			[ '%d' ]
		);

		wp_cache_delete( 'lookup_table', 'object_' . $source_id );
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

		$delegate_types = (array) apply_filters( 'linguaforge_wc_delegate_post_types', [ 'product', 'product_variation' ] );
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
