<?php
/**
 * Integration tests for FseLocalisation\RepairHandler (AUDIT §7.1 untested-file row).
 *
 * Covered here:
 *   ajax_repair_fse_metadata() — a published wp_template whose slug ends with
 *                                "-{lang}" gets its missing _lf_lang meta added and
 *                                its wp_theme term set; a template with no language
 *                                suffix is left untouched; an already-correct
 *                                template is not re-counted.
 *   extract_lang_suffix()      — slug → language-code extraction.
 *
 * Strategy:
 *   • Language list pinned to ['en','es'] (source 'en') via lf_languages_list so
 *     the secondary-language set is deterministically ['es'].
 *   • resolve_namespace() falls back to the active theme for arbitrary test slugs
 *     (no block-template ships them), so assertions use get_stylesheet() and are
 *     theme-agnostic.
 *   • The AJAX handler ends in wp_send_json*(); the JSON is captured via ob_start
 *     and the inherited WP_UnitTestCase get_wp_die_handler (no custom override —
 *     redeclaring it is signature-incompatible with WP_UnitTestCase_Base).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\FseLocalisation\RepairHandler;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

final class RepairHandlerIntegrationTest extends WP_UnitTestCase {

	private int $admin_id = 0;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', 'en',   false );
		update_option( 'linguaforge_routing_mode',     'path', false );

		add_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		$this->reset_context_caches();

		$this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', true );
	}

	protected function tearDown(): void {
		remove_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		wp_set_current_user( 0 );
		$_POST    = [];
		$_REQUEST = [];
		$this->reset_context_caches();
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** @return string[] */
	public function pin_langs(): array {
		return [ 'en', 'es' ];
	}

	private function reset_context_caches(): void {
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $name ) {
			$p = $ref->getProperty( $name );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	private function make_template( string $slug, string $post_type = 'wp_template' ): int {
		return (int) self::factory()->post->create( [
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_title'  => $slug,
		] );
	}

	/** Dispatch the repair AJAX handler and return the decoded JSON envelope. */
	private function dispatch(): array {
		$payload = [ 'nonce' => wp_create_nonce( 'linguaforge_repair_fse_metadata' ) ];

		$_POST    = $payload;
		$_REQUEST = $payload;

		add_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		$raw = '';
		ob_start(
			static function ( string $buffer ) use ( &$raw ): string {
				$raw = $buffer;
				return '';
			}
		);
		try {
			RepairHandler::ajax_repair_fse_metadata();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentional: JSON captured via ob_start; exception signals wp_die().
		}
		ob_end_clean();

		remove_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );

		$_POST    = [];
		$_REQUEST = [];

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [ 'success' => false, 'data' => null ];
	}

	// =========================================================================
	// extract_lang_suffix()
	// =========================================================================

	public function test_extract_lang_suffix(): void {
		$m = new ReflectionMethod( RepairHandler::class, 'extract_lang_suffix' );
		$m->setAccessible( true );

		$this->assertSame( 'es', $m->invoke( null, 'page-checkout-es', [ 'es', 'de' ] ) );
		$this->assertSame( 'de', $m->invoke( null, 'header-de', [ 'es', 'de' ] ) );
		$this->assertSame( '',   $m->invoke( null, 'page-checkout', [ 'es', 'de' ] ) );
		$this->assertSame( '',   $m->invoke( null, 'page-fr', [ 'es', 'de' ] ),
			'A suffix for an unconfigured language must not match.' );
	}

	// =========================================================================
	// ajax_repair_fse_metadata()
	// =========================================================================

	public function test_repairs_missing_lf_lang_and_theme_term(): void {
		$id = $this->make_template( 'page-repairme-es' );
		// No _lf_lang meta, no wp_theme term — both should be repaired.

		$resp = $this->dispatch();

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertGreaterThanOrEqual( 1, $resp['data']['repaired'] ?? 0 );

		$this->assertSame( 'es', get_post_meta( $id, '_lf_lang', true ),
			'Missing _lf_lang meta must be set to the slug language.' );

		$terms = wp_get_post_terms( $id, 'wp_theme', [ 'fields' => 'names' ] );
		$this->assertSame( [ get_stylesheet() ], $terms,
			'wp_theme term must be set to the active theme for an unscoped template.' );
	}

	public function test_template_without_language_suffix_is_untouched(): void {
		$id = $this->make_template( 'page-plain' ); // no -{lang} suffix

		$resp = $this->dispatch();

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertSame( '', (string) get_post_meta( $id, '_lf_lang', true ),
			'A non-localised template must not receive an _lf_lang tag.' );
		$this->assertSame( [], wp_get_post_terms( $id, 'wp_theme', [ 'fields' => 'names' ] ),
			'A non-localised template must not be assigned a wp_theme term.' );
	}

	public function test_already_correct_template_not_recounted(): void {
		$id = $this->make_template( 'page-done-es' );
		update_post_meta( $id, '_lf_lang', 'es' );
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );

		$resp = $this->dispatch();

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertSame( 0, $resp['data']['repaired'] ?? null,
			'A template that is already correct must not be counted as repaired.' );

		// State unchanged.
		$this->assertSame( 'es', get_post_meta( $id, '_lf_lang', true ) );
		$this->assertSame( [ get_stylesheet() ], wp_get_post_terms( $id, 'wp_theme', [ 'fields' => 'names' ] ) );
	}
}
