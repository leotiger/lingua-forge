<?php
/**
 * Unit tests for LinguaForge\Router\Router singleton mechanics.
 *
 * These tests do NOT boot WordPress or instantiate the full Router
 * (which requires WP hooks, options, and sub-classes). They verify the
 * singleton contract in isolation via ReflectionClass — specifically
 * that reset_instance() nulls the static property so the next
 * get_instance() call constructs a fresh object.
 *
 * Why this matters: any PHPUnit test that touches the router must be
 * able to start from a clean slate. Without reset_instance(), singleton
 * state bleeds across test cases and makes tests order-dependent.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RouterSingletonTest extends TestCase {

	/**
	 * Read the private static $instance property directly via reflection.
	 *
	 * @return mixed
	 */
	private function read_static_instance(): mixed {
		$ref  = new ReflectionClass( \LinguaForge\Router\Router::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		return $prop->getValue( null );
	}

	public function test_reset_instance_nulls_the_static_property(): void {
		// Inject a bare Router shell (no constructor, no WP) so the property is
		// non-null while satisfying the ?Router type constraint.
		$ref  = new ReflectionClass( \LinguaForge\Router\Router::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, $ref->newInstanceWithoutConstructor() );

		$this->assertNotNull( $this->read_static_instance(), 'Pre-condition: instance should be non-null.' );

		\LinguaForge\Router\Router::reset_instance();

		$this->assertNull( $this->read_static_instance(), 'reset_instance() must null the static property.' );
	}

	public function test_reset_instance_is_idempotent_when_already_null(): void {
		// Ensure null first.
		\LinguaForge\Router\Router::reset_instance();

		// Calling again must not throw.
		\LinguaForge\Router\Router::reset_instance();

		$this->assertNull( $this->read_static_instance() );
	}

	protected function tearDown(): void {
		// Leave the property null so no state leaks to other test files.
		\LinguaForge\Router\Router::reset_instance();
	}
}
