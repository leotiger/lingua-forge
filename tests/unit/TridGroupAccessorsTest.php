<?php
/**
 * Unit tests for TridGroup accessor methods.
 *
 * Covers the read/write accessors that TridGroupHooksTest does not:
 *   • get_trid() / set_trid()  — read and write the _lf_trid meta value.
 *   • get_lang() / set_lang()  — read and write the _lf_lang meta value.
 *   • get_lang() source-language fallback — returns router source language
 *     when no _lf_lang meta is stored for a post.
 *   • clear_translation_cache() — calls wp_cache_delete for the post.
 *
 * Uses WcPolyfills.php (get_post_meta / update_post_meta / wp_cache_delete)
 * and ApiPolyfills.php (do_action recording).
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/ApiPolyfills.php';
require_once __DIR__ . '/WooCommerce/WcPolyfills.php';

require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-context.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-language-router.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/translation/class-trid-group.php';

final class TridGroupAccessorsTest extends TestCase {

	private TridGroup $trid_group;

	protected function setUp(): void {
		parent::setUp();
		\LfWcMocks::reset();
		$GLOBALS['lf_test_actions'] = [];
		$this->trid_group = $this->make_trid_group( 'en' );
	}

	protected function tearDown(): void {
		Router::reset_instance();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function make_trid_group( string $source_lang ): TridGroup {

		$ctx_ref = new ReflectionClass( Context::class );
		$context = $ctx_ref->newInstanceWithoutConstructor();

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

		return new TridGroup( $router );
	}

	// =========================================================================
	// get_trid() / set_trid()
	// =========================================================================

	public function test_get_trid_returns_empty_string_when_no_meta(): void {
		$this->assertSame( '', $this->trid_group->get_trid( 42 ) );
	}

	public function test_get_trid_returns_stored_value(): void {
		\LfWcMocks::$meta[42]['_lf_trid'] = 'aaaaaaaa-1111-1111-1111-aaaaaaaaaaaa';

		$this->assertSame( 'aaaaaaaa-1111-1111-1111-aaaaaaaaaaaa', $this->trid_group->get_trid( 42 ) );
	}

	public function test_set_trid_writes_meta(): void {
		$uuid = 'bbbbbbbb-2222-2222-2222-bbbbbbbbbbbb';

		$this->trid_group->set_trid( 42, $uuid );

		$this->assertSame( $uuid, \LfWcMocks::$meta[42]['_lf_trid'] ?? null );
	}

	public function test_set_trid_fires_action_when_value_changes(): void {
		$old = 'aaaaaaaa-0000-0000-0000-000000000000';
		$new = 'bbbbbbbb-1111-1111-1111-111111111111';
		\LfWcMocks::$meta[42]['_lf_trid'] = $old;

		$this->trid_group->set_trid( 42, $new );

		$actions = $GLOBALS['lf_test_actions']['linguaforge_trid_changed'] ?? [];
		$this->assertCount( 1, $actions, 'linguaforge_trid_changed must fire once.' );
		$this->assertSame( [ 42, $new, $old ], $actions[0] );
	}

	public function test_set_trid_does_not_fire_action_when_value_unchanged(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000000';
		\LfWcMocks::$meta[42]['_lf_trid'] = $uuid;

		$this->trid_group->set_trid( 42, $uuid );

		$actions = $GLOBALS['lf_test_actions']['linguaforge_trid_changed'] ?? [];
		$this->assertCount( 0, $actions, 'linguaforge_trid_changed must not fire when value is unchanged.' );
	}

	// =========================================================================
	// get_lang() / set_lang()
	// =========================================================================

	public function test_get_lang_returns_source_language_when_no_meta(): void {
		// No _lf_lang stored — should fall back to router source language ('en').
		$this->assertSame( 'en', $this->trid_group->get_lang( 99 ) );
	}

	public function test_get_lang_returns_stored_lang(): void {
		\LfWcMocks::$meta[42]['_lf_lang'] = 'es';

		$this->assertSame( 'es', $this->trid_group->get_lang( 42 ) );
	}

	public function test_get_lang_source_fallback_uses_router_source_language(): void {
		// Build a TridGroup whose router says source is 'fr'.
		$trid_fr = $this->make_trid_group( 'fr' );

		// Post 10 has no _lf_lang — expects 'fr'.
		$this->assertSame( 'fr', $trid_fr->get_lang( 10 ) );
	}

	public function test_set_lang_writes_meta(): void {
		$this->trid_group->set_lang( 42, 'ca' );

		$this->assertSame( 'ca', \LfWcMocks::$meta[42]['_lf_lang'] ?? null );
	}

	// =========================================================================
	// clear_translation_cache()
	// =========================================================================

	public function test_clear_translation_cache_deletes_post_meta_cache(): void {
		// clear_translation_cache() bails early when get_trid() returns ''.
		// Seed a trid so the method reaches wp_cache_delete.
		$trid = 'cccccccc-3333-3333-3333-cccccccccccc';
		\LfWcMocks::$meta[42]['_lf_trid'] = $trid;

		$this->trid_group->clear_translation_cache( 42 );

		$deletes = \LfWcMocks::$cache_deletes;
		$this->assertNotEmpty( $deletes, 'wp_cache_delete must have been called.' );
		$keys = array_column( $deletes, 'key' );
		$this->assertContains( 'trid_' . $trid, $keys );
	}
}
