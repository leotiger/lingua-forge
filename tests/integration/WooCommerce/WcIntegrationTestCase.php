<?php
/**
 * WcIntegrationTestCase — abstract base for WooCommerce integration tests.
 *
 * Extends WP_UnitTestCase (every test runs inside a DB transaction that is
 * rolled back on tearDown, so no manual post/meta cleanup is needed).
 *
 * Responsibilities:
 *   • Skips the whole suite when WooCommerce is not active in wp-env.
 *   • Sets the source language to 'ca' via the linguaforge_primary_language
 *     option and resets the Router/Context per-request caches so each test
 *     starts from a clean language state.
 *   • Resets the static PHP caches on MetaDelegate and StockRouter between
 *     tests — WP_UnitTestCase rolls back DB state but not PHP static variables.
 *   • Provides make_product() and make_product_pair() to create 'product' posts
 *     with proper TRID/language metadata wired through TridGroup.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;
use LinguaForge\AI\Integrations\WooCommerce\StockRouter;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use WP_UnitTestCase;

abstract class WcIntegrationTestCase extends WP_UnitTestCase {

	/** Source language used across all WC integration tests. */
	protected const SOURCE_LANG = 'ca';

	/** Default translated language. */
	protected const TRANS_LANG = 'es';

	protected TridGroup $tg;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		// Flush the WP object cache and run the cyclic GC *before* the test
		// framework sets up state.  WP_UnitTestCase::set_up() also calls
		// flush_cache() via clean_up_global_scope(), but any circularly-referenced
		// WooCommerce objects that survived tearDown sit in PHP's gc root buffer
		// and haven't been reclaimed yet.  Running gc_collect_cycles() here (before
		// new allocations start) lets PHP reclaim that memory first.
		self::flush_cache();
		gc_collect_cycles();

		parent::setUp();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped(
				'WooCommerce must be active in wp-env for these tests. ' .
				'Add "woocommerce" to dev/.wp-env.json plugins and run npm run env:start.'
			);
		}

		// Fix source language so Router::source_language() is deterministic.
		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );

		// Reset all per-request Context caches — the Router singleton persists
		// across tests; without this, a stale cached_source_language bleeds through.
		$ctx_ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ctx_ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}

		$this->tg = Router::get_instance()->trid_group;

		// Reset static PHP state on the delegation classes.
		// WP_UnitTestCase rolls back the DB, but PHP statics persist.
		$this->reset_static_array( MetaDelegate::class, 'source_cache' );
		$this->reset_static_array( MetaDelegate::class, 'delegating' );
		$this->reset_static_array( StockRouter::class, 'routing' );
	}

	protected function tearDown(): void {
		// Clean up delegation statics after each test to be safe.
		$this->reset_static_array( MetaDelegate::class, 'source_cache' );
		$this->reset_static_array( MetaDelegate::class, 'delegating' );
		$this->reset_static_array( StockRouter::class, 'routing' );

		remove_all_filters( 'lf_languages_list' );

		parent::tearDown();

		// WP_UnitTestCase::tear_down() does NOT flush the object cache — that only
		// happens in set_up() of the *next* test via clean_up_global_scope(). While
		// the cache holds live references, PHP's cyclic GC cannot collect WooCommerce
		// product objects (WC_Product → WC_Data → property array back to WC_Product).
		// Flush the cache first so those references drop, then run the cyclic
		// collector. Without both steps, each WC test leaks 50–120 MB and the 67-test
		// suite exhausts the 2 GB memory limit around test 17.
		self::flush_cache();
		gc_collect_cycles();
	}

	// =========================================================================
	// Factory helpers
	// =========================================================================

	/**
	 * Create a published 'product' post, assign a language and TRID via TridGroup.
	 *
	 * @param  string $lang  Language code ('ca', 'es', …).
	 * @param  string $trid  TRID string shared by all posts in the group.
	 * @return int  New post ID.
	 */
	protected function make_product( string $lang, string $trid ): int {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'product',
			'post_status' => 'publish',
		] );
		$this->tg->set_lang( $post_id, $lang );
		$this->tg->set_trid( $post_id, $trid );
		return $post_id;
	}

	/**
	 * Create a source–translated product pair sharing the same TRID.
	 *
	 * @param  string $trans_lang  Language code for the translated product.
	 * @return int[]  [ $source_id, $translated_id ]
	 */
	protected function make_product_pair( string $trans_lang = self::TRANS_LANG ): array {
		$trid          = $this->trid();
		$source_id     = $this->make_product( self::SOURCE_LANG, $trid );
		$translated_id = $this->make_product( $trans_lang, $trid );
		return [ $source_id, $translated_id ];
	}

	/**
	 * Generate a unique TRID string to avoid cross-test collisions.
	 */
	protected function trid(): string {
		return 'wc-trid-' . uniqid( '', true );
	}

	// =========================================================================
	// Reflection helpers
	// =========================================================================

	/**
	 * Reset a private static array property on an integration class to [].
	 */
	protected function reset_static_array( string $class, string $property ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}
}
