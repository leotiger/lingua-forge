<?php
/**
 * Integration tests for MetaBox — the per-post AI panel (AUDIT §7.1
 * untested-file row: 884 lines, largest untested admin surface before this
 * file).
 *
 * Covered here:
 *   inject_instance_languages() — adds an instance-configured language absent
 *                                 from the AI map (real Locale resolution when
 *                                 available, uppercase fallback for a code
 *                                 Locale cannot resolve); never overwrites a
 *                                 language already present in the input map
 *   save_preset()                — persists the per-post preset override:
 *                                 no-ops without a nonce / with an invalid
 *                                 nonce / during DOING_AUTOSAVE / without
 *                                 edit_post capability; deletes the meta for
 *                                 ''/'global'; writes a valid preset key;
 *                                 ignores an unrecognized preset key;
 *                                 sanitizes the raw POST value first
 *   register()                   — excludes LF's internal post types before
 *                                 applying the linguaforge_ai_metabox_post_types
 *                                 filter; registers the 'lingua-forge-ai' meta
 *                                 box for 'post'
 *   render()                     — smoke-tests the main panel: preset nonce
 *                                 field + select present, a saved per-post
 *                                 preset renders as the selected <option>, the
 *                                 always-registered 'translation' feature's
 *                                 overlay trigger + dialog markup is present
 *
 * NOT covered here (left for a follow-up pass):
 *   - The enqueue_*() methods — asset registration + simple hook-name guards,
 *     no business logic worth a regression test (consistent with skipping
 *     PostListColumn::enqueue() for the same reason).
 *   - render_seo_analysis_meta_box() / enqueue_seo_analysis*() — couples to
 *     SeoAnalysisPanel's profile/score format, same rationale as skipping
 *     PostListColumn::render_seo_score_badge().
 *   - render_feature_fields() in isolation — exercised incidentally by the
 *     render() smoke test above (Translation's real get_ui_fields() renders
 *     through it), not asserted on field-by-field.
 *
 * Strategy:
 *   • inject_instance_languages() and save_preset() are called directly —
 *     both are plain public static methods with no wp_die()/exit() involved.
 *   • The DOING_AUTOSAVE test defines that constant, which cannot be undefined
 *     afterwards; it runs @runInSeparateProcess so the constant never leaks
 *     into other tests in this file (or others) in the same PHPUnit process.
 *   • The instance-language list is pinned via the lf_languages_list filter +
 *     Context cache reset, the same approach used in
 *     RedirectorRedirectIntegrationTest / PostListColumnIntegrationTest.
 *   • register()'s post-type computation is observed by hooking
 *     linguaforge_ai_metabox_post_types at a late priority and capturing the
 *     array it receives — that array has already had LF's internal-type
 *     exclusion applied, which is exactly the logic under test.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\MetaBox;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class MetaBoxIntegrationTest extends WP_UnitTestCase {

	private int $admin_id = 0;
	private int $subscriber_id = 0;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		$this->admin_id      = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		remove_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		remove_all_filters( 'linguaforge_ai_metabox_post_types' );
		// Context::cached_languages (and friends) are static properties that
		// persist for the rest of the PHPUnit process. Without resetting them
		// here, whatever pin_langs_to() last pinned (e.g. ['xx']) leaks into
		// every test that runs after this file alphabetically (e.g.
		// QueryFilterIntegrationTest, RedirectorRedirectIntegrationTest), which
		// expect the real/default language list once this file's filter is gone.
		$this->reset_context_caches();
		wp_set_current_user( 0 );
		$_POST = [];
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** @var string[] Set by pin_langs_to() before installing the filter. */
	private array $pinned_langs = [];

	/** @return string[] */
	public function pin_langs(): array {
		return $this->pinned_langs;
	}

	private function pin_langs_to( array $langs ): void {
		$this->pinned_langs = $langs;
		add_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		$this->reset_context_caches();
	}

	private function reset_context_caches(): void {
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	private function make_post( string $post_type = 'post' ): int {
		return (int) $this->factory->post->create( [ 'post_type' => $post_type, 'post_status' => 'publish' ] );
	}

	// =========================================================================
	// inject_instance_languages()
	// =========================================================================

	public function test_inject_instance_languages_never_overwrites_existing_entry(): void {
		$this->pin_langs_to( [ 'de' ] );

		$result = MetaBox::inject_instance_languages( [ 'de' => 'Custom Name' ] );

		$this->assertSame( 'Custom Name', $result['de'],
			'A language already present in the map must never be overwritten.' );
	}

	public function test_inject_instance_languages_adds_missing_entry(): void {
		$this->pin_langs_to( [ 'de' ] );

		$result = MetaBox::inject_instance_languages( [] );

		$this->assertArrayHasKey( 'de', $result );
		$this->assertNotSame( '', $result['de'] );
	}

	public function test_inject_instance_languages_resolves_real_name_via_locale(): void {
		if ( ! class_exists( 'Locale' ) ) {
			$this->markTestSkipped( 'intl extension (Locale class) not available in this PHP build.' );
		}

		$this->pin_langs_to( [ 'de' ] );

		$result = MetaBox::inject_instance_languages( [] );

		$this->assertSame( 'German', $result['de'],
			'With intl available, a real ISO code must resolve to its actual English display name, not the uppercased code.' );
	}

	public function test_inject_instance_languages_falls_back_to_uppercase_for_unresolvable_code(): void {
		// 'xx' is not a real ISO 639-1 subtag — Locale::getDisplayLanguage()
		// returns it unchanged, which the method treats as a resolution failure.
		$this->pin_langs_to( [ 'xx' ] );

		$result = MetaBox::inject_instance_languages( [] );

		$this->assertSame( [ 'xx' => 'XX' ], $result );
	}

	// =========================================================================
	// save_preset()
	// =========================================================================

	public function test_save_preset_noop_without_nonce(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_linguaforge_preset', 'legal' );

		$_POST = [ '_linguaforge_preset' => 'technical' ];
		MetaBox::save_preset( $post_id );

		$this->assertSame( 'legal', get_post_meta( $post_id, '_linguaforge_preset', true ),
			'Without the nonce field present at all, save_preset() must return immediately.' );
	}

	public function test_save_preset_noop_with_invalid_nonce(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_linguaforge_preset', 'legal' );

		$_POST = [
			'_linguaforge_preset_nonce' => 'not-a-real-nonce',
			'_linguaforge_preset'       => 'technical',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( 'legal', get_post_meta( $post_id, '_linguaforge_preset', true ) );
	}

	public function test_save_preset_noop_without_edit_permission(): void {
		$post_id = $this->make_post();

		wp_set_current_user( $this->subscriber_id );
		$_POST = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => 'technical',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_linguaforge_preset', true ),
			'A user without edit_post capability must not be able to set the preset.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * DOING_AUTOSAVE cannot be undefined once set — isolate this test's process
	 * so the constant never leaks into any other test. preserveGlobalState must
	 * be disabled (see the same pairing throughout tests/unit/QueryFilterArmTest.php
	 * and tests/unit/TranslationQueueTest.php) — otherwise PHPUnit tries to
	 * serialize $GLOBALS into the forked process, and $wp_filter almost always
	 * holds a Closure from WordPress core's own hooks by this point in a
	 * WP_UnitTestCase run, which fails with "Serialization of 'Closure' is not
	 * allowed". The child process re-runs the full bootstrap anyway, so no
	 * parent global state is actually needed here.
	 */
	public function test_save_preset_noop_during_autosave(): void {
		define( 'DOING_AUTOSAVE', true );

		$post_id = $this->make_post();
		$_POST   = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => 'technical',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_linguaforge_preset', true ),
			'An autosave request must never persist a preset change.' );
	}

	public function test_save_preset_writes_valid_preset(): void {
		$post_id = $this->make_post();

		$_POST = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => 'technical',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( 'technical', get_post_meta( $post_id, '_linguaforge_preset', true ) );
	}

	public function test_save_preset_sanitizes_value_before_validating(): void {
		$post_id = $this->make_post();

		$_POST = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => ' TECHNICAL ',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( 'technical', get_post_meta( $post_id, '_linguaforge_preset', true ),
			'sanitize_key() must trim and lowercase the raw POST value before matching against valid presets.' );
	}

	public function test_save_preset_deletes_meta_for_empty_value(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_linguaforge_preset', 'legal' );

		$_POST = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => '',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_linguaforge_preset', true ) );
	}

	public function test_save_preset_deletes_meta_for_global_value(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_linguaforge_preset', 'legal' );

		$_POST = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => 'global',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_linguaforge_preset', true ) );
	}

	public function test_save_preset_ignores_unrecognized_value(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_linguaforge_preset', 'legal' );

		$_POST = [
			'_linguaforge_preset_nonce' => wp_create_nonce( 'linguaforge_preset_save' ),
			'_linguaforge_preset'       => 'not-a-real-preset',
		];
		MetaBox::save_preset( $post_id );

		$this->assertSame( 'legal', get_post_meta( $post_id, '_linguaforge_preset', true ),
			'An unrecognized preset key must neither be saved nor clear the existing value.' );
	}

	// =========================================================================
	// register()
	// =========================================================================

	public function test_register_excludes_internal_post_types_and_includes_post(): void {
		$captured = null;
		add_filter( 'linguaforge_ai_metabox_post_types', function ( array $types ) use ( &$captured ): array {
			$captured = $types;
			return $types;
		}, 20 );

		MetaBox::register();

		$this->assertIsArray( $captured );
		$this->assertContains( 'post', $captured );
		$this->assertContains( 'page', $captured );

		foreach ( [ 'attachment', 'revision', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block' ] as $internal ) {
			$this->assertNotContains( $internal, $captured,
				"'$internal' is an LF-internal post type and must be excluded before the filter runs." );
		}
	}

	public function test_register_adds_the_meta_box_for_post(): void {
		MetaBox::register();

		global $wp_meta_boxes;
		$this->assertArrayHasKey( 'lingua-forge-ai', $wp_meta_boxes['post']['normal']['high'] ?? [],
			"register() must add the 'lingua-forge-ai' meta box for the 'post' screen." );
	}

	// =========================================================================
	// render()
	// =========================================================================

	public function test_render_outputs_preset_field_and_translation_overlay(): void {
		$post = get_post( $this->make_post() );

		ob_start();
		MetaBox::render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'lingua-forge-panel', $html );
		$this->assertStringContainsString( 'name="_linguaforge_preset_nonce"', $html );
		$this->assertStringContainsString( 'id="lf-page-preset"', $html );

		// 'translation' is always registered — its overlay trigger + dialog must render.
		$this->assertStringContainsString( 'lf-overlay-translation', $html );
		$this->assertStringContainsString( 'Translate page', $html );
	}

	public function test_render_marks_saved_preset_as_selected(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_linguaforge_preset', 'technical' );

		ob_start();
		MetaBox::render( get_post( $post_id ) );
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<option value="technical"[^>]*selected=([\'"])selected\1/',
			$html,
			"The post's saved preset ('technical') must render as the selected <option>."
		);
	}
}
