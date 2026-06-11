<?php
/**
 * LF Product-Save Debug mu-plugin — v2
 *
 * Adds microsecond timestamps and probes inside the WC save layer to answer:
 *
 *   — Does maybe_intercept_translated_save() run to completion?
 *   — Does woocommerce_process_product_meta fire? (= WC started its save)
 *   — Does it fire multiple times? (= loop)
 *   — Does wp_after_insert_post fire for product_variation posts? (= variation cascade)
 *   — Where exactly does execution stop on the integration server?
 *
 * All output: error_log() with prefix [LF-SAVE-DEBUG].
 * Grep: grep 'LF-SAVE-DEBUG' /path/to/php-error.log | head -200
 *
 * NEVER ship to production.
 */

defined( 'ABSPATH' ) || exit;

// ── Helpers ──────────────────────────────────────────────────────────────────

function lf_dbg( string $msg ): void {
	$t = number_format( microtime( true ), 4, '.', '' );
	error_log( "[LF-SAVE-DEBUG][{$t}] " . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
}

// ── save_post: hook table + intercept diagnosis (priority 0) ─────────────────

add_action( 'save_post', function ( int $post_id, WP_Post $post ) {
	static $dumped = false;
	if ( 'product' !== $post->post_type ) return;

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$has_nonce = ! empty( $_POST['woocommerce_meta_nonce'] );
	$lang      = (string) get_post_meta( $post_id, '_lf_lang', true );
	$tpl       = (string) get_post_meta( $post_id, '_wp_page_template', true );

	lf_dbg( "save_post p0 — post={$post_id} lang={$lang} nonce=" . ( $has_nonce ? 'YES' : 'NO' ) . " tpl={$tpl}" );

	if ( ! $dumped ) {
		$dumped = true;
		global $wp_filter;
		$cbs = $wp_filter['save_post']->callbacks ?? [];
		ksort( $cbs );
		lf_dbg( '--- save_post hook table ---' );
		foreach ( $cbs as $prio => $items ) {
			foreach ( $items as $cb ) {
				$fn = $cb['function'];
				if ( is_array( $fn ) ) {
					$label = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1];
				} elseif ( $fn instanceof Closure ) {
					$label = '{closure}';
				} else {
					$label = (string) $fn;
				}
				lf_dbg( "  p{$prio}: {$label}" );
			}
		}
		lf_dbg( '--- end hook table ---' );
	}
}, 0, 2 );

// ── save_post: counter — fires for EVERY post to detect cascade ───────────────

add_action( 'save_post', function ( int $post_id, WP_Post $post ) {
	static $count = 0;
	$count++;
	lf_dbg( "save_post fire #{$count} — post={$post_id} type={$post->post_type}" );
}, 2, 2 ); // priority 2 = after WC (1) and our intercept (0)

// ── Probe: did maybe_intercept_translated_save() complete? ────────────────────
// Hook at priority 0 AFTER AdminSaveGuard's callback (same priority, later registration).
// This fires only if PHP didn't crash inside maybe_intercept_translated_save.

add_action( 'save_post', function ( int $post_id, WP_Post $post ) {
	if ( 'product' !== $post->post_type ) return;
	$intercepting       = false;
	$source_saving      = false;
	$source_hooks_removed = false;
	try {
		$class = 'LinguaForge\\AI\\Integrations\\WooCommerce\\AdminSaveGuard';
		$ref1  = new ReflectionProperty( $class, 'intercepting' );
		$ref1->setAccessible( true );
		$intercepting = (bool) $ref1->getValue();
		$ref2 = new ReflectionProperty( $class, 'source_saving' );
		$ref2->setAccessible( true );
		$source_saving = (bool) $ref2->getValue();
		$ref3 = new ReflectionProperty( $class, 'source_hooks_removed' );
		$ref3->setAccessible( true );
		$source_hooks_removed = (bool) $ref3->getValue();
	} catch ( \Throwable $e ) {
		// reflection failed — class not loaded yet or property renamed
	}
	lf_dbg(
		'save_post p0-LATE — AdminSaveGuard: $intercepting=' . ( $intercepting ? 'TRUE' : 'false' )
		. ' $source_saving=' . ( $source_saving ? 'TRUE' : 'false' )
		. ' $source_hooks_removed=' . ( $source_hooks_removed ? 'TRUE' : 'false' )
	);
}, 0, 2 ); // same priority 0, registered after AdminSaveGuard

// ── WC layer probes ───────────────────────────────────────────────────────────

// Fires inside WC_Admin_Meta_Boxes::save_meta_boxes() — confirms WC started.
add_action( 'woocommerce_process_product_meta', function ( int $post_id ) {
	static $wc_count = 0;
	$wc_count++;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$sku_in_post = isset( $_POST['_sku'] ) ? ( '"' . sanitize_text_field( wp_unslash( $_POST['_sku'] ) ) . '"' ) : 'NOT SET';
	lf_dbg( "woocommerce_process_product_meta #{$wc_count} — post={$post_id} \$_POST[_sku]={$sku_in_post}" );
}, 0 );

// Probe wc_product_has_unique_sku — fires during $product->set_sku() inside WC.
// Logs both the incoming value (before our filter) and exit value (after all filters).
add_filter( 'wc_product_has_unique_sku', function ( bool $is_unique, int $product_id, string $sku ): bool {
	lf_dbg( "wc_product_has_unique_sku ENTRY — product={$product_id} sku={$sku} is_unique=" . ( $is_unique ? 'YES' : 'NO (duplicate found)' ) );
	return $is_unique;
}, 5, 3 );

add_filter( 'wc_product_has_unique_sku', function ( bool $is_unique, int $product_id, string $sku ): bool {
	lf_dbg( "wc_product_has_unique_sku EXIT — product={$product_id} sku={$sku} final=" . ( $is_unique ? 'UNIQUE ✓' : 'DUPLICATE ✗ — WC will throw WC_Data_Exception!' ) );
	return $is_unique;
}, 99, 3 );

// Fires after WC finishes updating the product object (end of WC data store update).
add_action( 'woocommerce_update_product', function ( int $post_id ) {
	lf_dbg( "woocommerce_update_product — post={$post_id} (WC data-store update done)" );
}, 0 );

// Fires after WC product object is fully saved.
add_action( 'woocommerce_after_product_object_save', function ( $product ) {
	if ( ! is_object( $product ) ) return;
	lf_dbg( 'woocommerce_after_product_object_save — id=' . $product->get_id() );
}, 0 );

// ── Meta write log ────────────────────────────────────────────────────────────

add_filter( 'update_post_metadata', function ( $check, int $oid, string $key ) {
	// Only log writes for products and their variations to avoid noise.
	$pt = get_post_type( $oid );
	if ( ! in_array( $pt, [ 'product', 'product_variation' ], true ) ) return $check;
	$status = ( $check === true ) ? 'BLOCKED' : 'ALLOWED';
	lf_dbg( "update_meta post={$oid}({$pt}) key={$key} → {$status}" );
	return $check;
}, 1, 3 );

add_filter( 'add_post_metadata', function ( $check, int $oid, string $key ) {
	$pt = get_post_type( $oid );
	if ( ! in_array( $pt, [ 'product', 'product_variation' ], true ) ) return $check;
	$status = ( $check === true ) ? 'BLOCKED' : 'ALLOWED';
	lf_dbg( "add_meta post={$oid}({$pt}) key={$key} → {$status}" );
	return $check;
}, 1, 3 );

// ── wp_after_insert_post: ALL product-related types ──────────────────────────

add_action( 'wp_after_insert_post', function ( int $post_id, WP_Post $post ) {
	if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) return;
	$tpl = get_post_meta( $post_id, '_wp_page_template', true );
	lf_dbg( "wp_after_insert_post p5 — post={$post_id} type={$post->post_type} tpl={$tpl}" );
}, 5, 2 );

add_action( 'wp_after_insert_post', function ( int $post_id, WP_Post $post ) {
	if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) return;
	$tpl = get_post_meta( $post_id, '_wp_page_template', true );
	lf_dbg( "wp_after_insert_post p15 — post={$post_id} type={$post->post_type} tpl={$tpl}" );
}, 15, 2 );

add_action( 'wp_after_insert_post', function ( int $post_id, WP_Post $post ) {
	if ( ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) return;
	$tpl = get_post_meta( $post_id, '_wp_page_template', true );
	lf_dbg( "wp_after_insert_post p99 — post={$post_id} type={$post->post_type} tpl={$tpl}" );
}, 99, 2 );

// ── redirect_post_location (save completed) ───────────────────────────────────

add_filter( 'redirect_post_location', function ( string $loc ): string {
	lf_dbg( 'redirect_post_location — save chain COMPLETE' );
	return $loc;
}, 0 );
