<?php
/**
 * Integration tests for AttributeLabelAdmin (AUDIT §7.1 untested-file row).
 *
 * Covered here:
 *   save()                          — persists per-language label translations to
 *                                     wp_options; removes a translation when its
 *                                     field is empty; ignores an empty attribute
 *                                     name (no `pa_` option written).
 *   ajax_translate_all_attr_labels  — batch-translates untranslated labels via a
 *                                     StubProvider, writing one option per
 *                                     attribute taxonomy per language; already-
 *                                     translated labels are skipped, never
 *                                     overwritten.
 *
 * Strategy:
 *   • The language list is pinned to ['ca','es'] (source = 'ca') via the
 *     lf_languages_list filter, so non_source_languages() is deterministically
 *     ['es'].
 *   • The AI call inside TermNameTranslator::translate_term_names() is replaced
 *     with a StubProvider via the linguaforge_ai_provider filter — no network.
 *   • The AJAX handler ends in wp_send_json*(), which calls wp_die(); we capture
 *     the JSON via an ob_start callback and catch WPDieException (same pattern as
 *     SystemPanelIntegrationTest).
 *
 * Run via: composer test:integration:wc  (requires wp-env + WooCommerce).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\AttributeLabelAdmin;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Tests\Integration\Stubs\StubProvider;
use ReflectionClass;

final class AttributeLabelAdminIntegrationTest extends WcIntegrationTestCase {

	private const OPT = 'linguaforge_attr_labels_';

	private int $admin_id = 0;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		add_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		$this->reset_languages_cache();

		$this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );

		defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', true );
	}

	protected function tearDown(): void {
		remove_all_filters( 'linguaforge_ai_provider' );
		wp_set_current_user( 0 );
		$_POST    = [];
		$_REQUEST = [];
		parent::tearDown(); // removes lf_languages_list, flushes, rolls back DB
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** @return string[] */
	public function pin_langs(): array {
		return [ 'ca', 'es' ];
	}

	private function reset_languages_cache(): void {
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language' ] as $name ) {
			$p = $ref->getProperty( $name );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	/** Create a WooCommerce global attribute and refresh the taxonomy cache. */
	private function make_attribute( string $name, string $slug ): void {
		wc_create_attribute( [
			'name'         => $name,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		] );
		delete_transient( 'wc_attribute_taxonomies' );
	}

	/**
	 * Invoke the AJAX handler and return the decoded JSON envelope.
	 *
	 * @return array{success:bool, data:mixed}
	 */
	private function dispatch_translate_all(): array {
		$payload = [ 'nonce' => wp_create_nonce( 'lf_translate_all_attr_labels' ) ];

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
			AttributeLabelAdmin::ajax_translate_all_attr_labels();
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
	// save()
	// =========================================================================

	public function test_save_writes_per_language_translation(): void {
		$_POST['lf_attr_label_es'] = 'Color ES';

		AttributeLabelAdmin::save( 0, [ 'attribute_name' => 'color' ] );

		$this->assertSame(
			[ 'es' => 'Color ES' ],
			get_option( self::OPT . 'pa_color' ),
			'save() must persist the per-language label under linguaforge_attr_labels_pa_color.'
		);
	}

	public function test_save_removes_translation_when_field_empty(): void {
		update_option( self::OPT . 'pa_color', [ 'es' => 'Old' ], false );

		$_POST['lf_attr_label_es'] = '';
		AttributeLabelAdmin::save( 0, [ 'attribute_name' => 'color' ] );

		$this->assertSame(
			[],
			(array) get_option( self::OPT . 'pa_color', [] ),
			'An emptied field must remove the translation (option deleted when no translations remain).'
		);
	}

	public function test_save_ignores_empty_attribute_name(): void {
		$_POST['lf_attr_label_es'] = 'Whatever';

		AttributeLabelAdmin::save( 0, [ 'attribute_name' => '' ] );

		$this->assertFalse(
			get_option( self::OPT . 'pa_', false ),
			'An empty attribute name must not write any pa_ option.'
		);
	}

	// =========================================================================
	// ajax_translate_all_attr_labels()
	// =========================================================================

	public function test_ajax_translate_all_writes_options_per_language(): void {
		$this->make_attribute( 'Color', 'color' );
		$this->make_attribute( 'Size', 'size' );

		// StubProvider returns the source-label → translation map the handler expects.
		$json = wp_json_encode( [ 'Color' => 'Color ES', 'Size' => 'Size ES' ] );
		add_filter( 'linguaforge_ai_provider', static fn() => new StubProvider( $json ), 10, 3 );

		$resp = $this->dispatch_translate_all();

		$this->assertTrue( $resp['success'] ?? false, 'AJAX must return success.' );
		$this->assertSame( 2, $resp['data']['translated'] ?? null );
		$this->assertSame( 0, $resp['data']['skipped'] ?? null );

		$this->assertSame( [ 'es' => 'Color ES' ], get_option( self::OPT . 'pa_color' ) );
		$this->assertSame( [ 'es' => 'Size ES' ],  get_option( self::OPT . 'pa_size' ) );
	}

	public function test_ajax_translate_all_skips_already_translated(): void {
		$this->make_attribute( 'Color', 'color' );

		// Pre-existing manual translation must be left untouched.
		update_option( self::OPT . 'pa_color', [ 'es' => 'Existing' ], false );

		add_filter(
			'linguaforge_ai_provider',
			static fn() => new StubProvider( wp_json_encode( [ 'Color' => 'Should Not Apply' ] ) ),
			10,
			3
		);

		$resp = $this->dispatch_translate_all();

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertSame( 0, $resp['data']['translated'] ?? null, 'Nothing new to translate.' );
		$this->assertSame( 1, $resp['data']['skipped'] ?? null, 'The already-translated label must be skipped.' );

		$this->assertSame(
			[ 'es' => 'Existing' ],
			get_option( self::OPT . 'pa_color' ),
			'An existing translation must never be overwritten by the batch translate.'
		);
	}
}
