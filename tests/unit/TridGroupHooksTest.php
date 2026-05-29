<?php
/**
 * Unit tests for TridGroup::set_trid() → linguaforge_trid_changed action.
 *
 * Verifies that the action fires exactly when the TRID value changes, carries
 * the correct arguments, and is silent when the value is unchanged.
 *
 * Does NOT boot WordPress. Uses ApiPolyfills.php (recording do_action) and
 * WcPolyfills.php (get_post_meta / update_post_meta stubs).
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Router;
use LinguaForge\Router\Context;
use LinguaForge\Router\Translation\TridGroup;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// Polyfills — order matters: ApiPolyfills must come first so its do_action
// definition wins over any later no-op version.
require_once __DIR__ . '/ApiPolyfills.php';
require_once __DIR__ . '/WooCommerce/WcPolyfills.php';

// Router source files (no WP needed — constructors are bypassed via Reflection).
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-context.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-language-router.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/translation/class-trid-group.php';

class TridGroupHooksTest extends TestCase {

	private TridGroup $trid_group;

	protected function setUp(): void {
		parent::setUp();

		// Reset the global action log before every test.
		$GLOBALS['lf_test_actions'] = [];

		// Reset mock meta store.
		\LfWcMocks::reset();

		// Inject a minimal Router stub (no WP calls) and wire a TridGroup.
		$this->trid_group = new TridGroup( $this->make_router_stub() );
	}

	protected function tearDown(): void {
		Router::reset_instance();
		parent::tearDown();
	}

	// =========================================================================

	public function test_action_fires_when_trid_changes_from_empty(): void {
		$new_trid = 'aaaaaaaa-0000-0000-0000-000000000001';

		$this->trid_group->set_trid( 10, $new_trid );

		$calls = $GLOBALS['lf_test_actions']['linguaforge_trid_changed'] ?? [];
		$this->assertCount( 1, $calls, 'Action must fire exactly once.' );
		$this->assertSame( [ 10, $new_trid, '' ], $calls[0], 'Args must be (post_id, new, old).' );
	}

	public function test_action_fires_when_trid_changes_from_one_uuid_to_another(): void {
		$old_trid = 'aaaaaaaa-0000-0000-0000-000000000001';
		$new_trid = 'bbbbbbbb-0000-0000-0000-000000000002';

		\LfWcMocks::$meta[20]['_lf_trid'] = $old_trid;

		$this->trid_group->set_trid( 20, $new_trid );

		$calls = $GLOBALS['lf_test_actions']['linguaforge_trid_changed'] ?? [];
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 20, $new_trid, $old_trid ], $calls[0] );
	}

	public function test_action_does_not_fire_when_value_is_unchanged(): void {
		$trid = 'cccccccc-0000-0000-0000-000000000003';

		\LfWcMocks::$meta[30]['_lf_trid'] = $trid;

		$this->trid_group->set_trid( 30, $trid ); // same value

		$calls = $GLOBALS['lf_test_actions']['linguaforge_trid_changed'] ?? [];
		$this->assertEmpty( $calls, 'Action must not fire when value is unchanged.' );
	}

	public function test_action_does_not_fire_when_both_values_are_empty(): void {
		// Post has no TRID (meta absent → get_post_meta returns '').
		$this->trid_group->set_trid( 40, '' ); // '' → ''

		$calls = $GLOBALS['lf_test_actions']['linguaforge_trid_changed'] ?? [];
		$this->assertEmpty( $calls, 'Action must not fire for no-op empty → empty.' );
	}

	public function test_meta_is_written_regardless_of_action(): void {
		$trid = 'dddddddd-0000-0000-0000-000000000004';

		$this->trid_group->set_trid( 50, $trid );

		// Confirm update_post_meta was called (write_log entry exists).
		$writes = array_filter(
			\LfWcMocks::$write_log,
			static fn( $e ) => $e[1] === 50 && $e[2] === '_lf_trid'
		);
		$this->assertCount( 1, array_values( $writes ), 'update_post_meta must always be called.' );
		$this->assertSame( $trid, array_values( $writes )[0][3] );
	}

	// =========================================================================

	/**
	 * Build a Router stub without calling the constructor (no WP calls).
	 * Only the type is needed — TridGroup stores the reference but set_trid()
	 * never calls any Router method.
	 */
	private function make_router_stub(): Router {
		$ctx_ref = new ReflectionClass( Context::class );
		$context = $ctx_ref->newInstanceWithoutConstructor();

		$router_ref = new ReflectionClass( Router::class );
		$router     = $router_ref->newInstanceWithoutConstructor();

		$ctx_field = $router_ref->getProperty( 'context' );
		$ctx_field->setAccessible( true );
		$ctx_field->setValue( $router, $context );

		$inst_prop = $router_ref->getProperty( 'instance' );
		$inst_prop->setAccessible( true );
		$inst_prop->setValue( null, $router );

		return $router;
	}
}
