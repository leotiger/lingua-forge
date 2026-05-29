<?php
/**
 * WcUnitTestCase — abstract base for WooCommerce integration unit tests.
 *
 * Responsibilities:
 *   • Requires the global-namespace WP stubs and function polyfills.
 *   • Loads LinguaForge\Router\Context and Router so the delegation classes
 *     can call Router::get_instance()->source_language() without booting WP.
 *   • Provides inject_router() which plants a stub Router singleton whose
 *     source_language() short-circuits to the given lang string via a pre-set
 *     $cached_source_language, bypassing all WP option / filter calls.
 *   • Resets LfWcMocks and the Router singleton between every test.
 *   • Exposes Reflection helpers for resetting / reading private static state
 *     on the integration classes.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit\WooCommerce;

use LinguaForge\Router\Router;
use LinguaForge\Router\Context;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/WcPolyfills.php';
require_once dirname( __DIR__, 3 ) . '/language-router/includes/class-context.php';
require_once dirname( __DIR__, 3 ) . '/language-router/includes/class-language-router.php';

abstract class WcUnitTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\LfWcMocks::reset();
		static::inject_router( 'en' );
	}

	protected function tearDown(): void {
		Router::reset_instance();
		parent::tearDown();
	}

	// =========================================================================
	// Post / meta registration helpers
	// =========================================================================

	/**
	 * Create a WP_Post stub and register it in the mock post store.
	 */
	protected function make_post( int $id, string $post_type = 'product' ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = $post_type;
		\LfWcMocks::$posts[ $id ] = $post;
		return $post;
	}

	/**
	 * Register a single piece of post meta in the mock meta store.
	 */
	protected function set_meta( int $post_id, string $key, mixed $value ): void {
		\LfWcMocks::$meta[ $post_id ][ $key ] = $value;
	}

	/**
	 * Register the full translation map for a given post.
	 *
	 * @param array<string,int> $map  e.g. [ 'en' => 100, 'es' => 42 ]
	 */
	protected function set_translations( int $post_id, array $map ): void {
		\LfWcMocks::$translations[ $post_id ] = $map;
	}

	// =========================================================================
	// Router injection
	// =========================================================================

	/**
	 * Plant a minimal Router singleton whose source_language() returns $source_lang
	 * without touching WordPress options or filters.
	 *
	 * Approach:
	 *   1. Build a Context via newInstanceWithoutConstructor() so no WP calls run.
	 *   2. Pre-set $cached_source_language via Reflection so source_language()
	 *      short-circuits immediately.
	 *   3. Build a Router the same way, inject the Context into $context.
	 *   4. Write the Router into the private static $instance slot.
	 */
	protected static function inject_router( string $source_lang = 'en' ): void {
		$ctx_ref  = new ReflectionClass( Context::class );
		$context  = $ctx_ref->newInstanceWithoutConstructor();

		$lang_prop = $ctx_ref->getProperty( 'cached_source_language' );
		$lang_prop->setAccessible( true );
		$lang_prop->setValue( $context, $source_lang );

		$router_ref = new ReflectionClass( Router::class );
		$router     = $router_ref->newInstanceWithoutConstructor();

		$ctx_field = $router_ref->getProperty( 'context' );
		$ctx_field->setAccessible( true );
		$ctx_field->setValue( $router, $context );

		$inst_prop = $router_ref->getProperty( 'instance' );
		$inst_prop->setAccessible( true );
		$inst_prop->setValue( null, $router );
	}

	// =========================================================================
	// Reflection helpers for integration class static state
	// =========================================================================

	/**
	 * Reset a private static array property to [] via Reflection.
	 */
	protected static function reset_static_array( string $class, string $property ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	/**
	 * Read the current value of a private static property via Reflection.
	 */
	protected static function read_static( string $class, string $property ): mixed {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		return $prop->getValue( null );
	}

	/**
	 * Set a private static property to an arbitrary value via Reflection.
	 */
	protected static function set_static( string $class, string $property, mixed $value ): void {
		$ref  = new ReflectionClass( $class );
		$prop = $ref->getProperty( $property );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}
}
