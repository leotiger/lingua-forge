<?php
/**
 * Integration tests for PostListColumn — the "Translate missing" / "Retranslate"
 * bulk actions surfaced in the post-list Lang column (AUDIT §7.1 untested-file
 * row: 683 lines, 0% coverage before this file).
 *
 * Covered here:
 *   render_fill_button()        — outputs the button; suppressed for wp_navigation
 *   render_retranslate_button() — outputs a language <select> + button when the
 *                                 post is a non-source-language TRID member with
 *                                 at least one sibling; silent for the source
 *                                 post and for a post with no other siblings
 *   ajax_fill_missing()         — creates every missing target-language
 *                                 translation via a StubProvider, links it into
 *                                 the TRID group, reports "already exist" when
 *                                 nothing is missing, rejects wp_navigation
 *                                 posts, rejects insufficient permissions,
 *                                 rejects an invalid post ID, surfaces an error
 *                                 when the provider fails
 *   ajax_retranslate()          — updates the target post from a chosen sibling
 *                                 language via a StubProvider, rejects
 *                                 retranslating the source-language post,
 *                                 rejects target==from, rejects a missing
 *                                 from-language sibling, rejects insufficient
 *                                 permissions
 *
 * NOT covered here (left for a follow-up pass):
 *   - render_seo_score_badge() — couples to SeoAnalysisPanel's stored score
 *     history shape; a lower-value, presentation-only surface.
 *   - enqueue() — asset registration only, no branching logic worth a
 *     regression test beyond "only runs on edit.php".
 *   - generate_meta_description()'s actual output — exercised incidentally
 *     (the AJAX flow calls it) but its result is not asserted on, since
 *     MetaDescription::run() is plain-text and the StubProvider response here
 *     is shaped for the *translation* call, not a meta-description call.
 *
 * Strategy:
 *   • Both AJAX handlers end in wp_send_json_*(), which calls wp_die() only when
 *     wp_doing_ajax() is true (WP core: wp_send_json() gates its wp_die() call on
 *     it, and several handler branches here have no explicit `return` after
 *     wp_send_json_error() — they rely entirely on that wp_die() to halt
 *     execution). wp_doing_ajax() itself is `apply_filters('wp_doing_ajax',
 *     defined('DOING_AJAX') && DOING_AJAX)` — a real PHP constant is NOT the only
 *     way to make it return true. dispatch() hooks the `wp_doing_ajax` filter
 *     directly (__return_true) for the duration of the call and removes it
 *     immediately after, so nothing leaks into any other test in this file or
 *     any alphabetically-later file (e.g. QueryFilterIntegrationTest /
 *     RedirectorRedirectIntegrationTest, both of which gate on
 *     Context::is_system_request() -> wp_doing_ajax()) — no DOING_AJAX constant,
 *     no @runInSeparateProcess needed. (An earlier version of this file defined
 *     the DOING_AJAX constant and isolated every AJAX test in its own forked
 *     process; that leaked nothing but broke the StubProvider-backed tests, so
 *     it was replaced with this filter-only approach.) The WPDieException filter
 *     is installed, output is captured via an ob_start callback, and the JSON is
 *     decoded — same pattern as SystemPanelIntegrationTest / AttributeLabelAdminIntegrationTest.
 *   • The language list is pinned to ['en','es'] (source 'en') via the
 *     lf_languages_list filter + linguaforge_primary_language option, with
 *     Context's cached properties reset via reflection so the change takes
 *     effect immediately (same approach as RedirectorRedirectIntegrationTest).
 *   • The AI call inside Translation::run() is replaced with a StubProvider via
 *     the linguaforge_ai_provider filter — no network. The stub JSON matches
 *     JsonEnvelopeTranslator's minimal schema ({"title":...,"content":...}) for
 *     a plain post with no excerpt/footnotes/attrs.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\PostListColumn;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use LinguaForge\Tests\Integration\Stubs\StubProvider;
use ReflectionClass;
use WP_UnitTestCase;

final class PostListColumnIntegrationTest extends WP_UnitTestCase {

	private int $admin_id  = 0;
	private int $subscriber_id = 0;
	private TridGroup $tg;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', 'en', false );
		add_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		$this->reset_context_caches();

		$this->tg = Router::get_instance()->trid_group;

		$this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		remove_filter( 'lf_languages_list', [ $this, 'pin_langs' ] );
		remove_all_filters( 'linguaforge_ai_provider' );
		// Context::cached_languages (and friends) are static properties that
		// persist for the rest of the PHPUnit process. Without resetting them
		// here, the ['en','es'] pin above leaks into every test that runs after
		// this file alphabetically (e.g. QueryFilterIntegrationTest,
		// RedirectorRedirectIntegrationTest), which expect the real/default
		// language list once this file's filter is gone.
		$this->reset_context_caches();
		wp_set_current_user( 0 );
		$_POST    = [];
		$_REQUEST = [];
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
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	private function make_post( string $status = 'publish', string $post_type = 'post' ): int {
		return (int) $this->factory->post->create( [
			'post_type'   => $post_type,
			'post_status' => $status,
		] );
	}

	private function trid(): string {
		return 'plc-trid-' . uniqid( '', true );
	}

	/** A minimal AI response matching JsonEnvelopeTranslator's schema for a plain post (no excerpt/footnotes/attrs). */
	private function translation_json( string $title, string $content ): string {
		return (string) wp_json_encode( [ 'title' => $title, 'content' => $content ] );
	}

	/**
	 * Dispatch an AJAX handler and return the decoded JSON envelope.
	 *
	 * @param  callable            $handler
	 * @param  string              $nonce_action
	 * @param  array<string,mixed> $params
	 * @return array{success:bool, data:mixed}
	 */
	private function dispatch( callable $handler, string $nonce_action, array $params ): array {

		// Makes wp_doing_ajax() return true for the duration of this call, without
		// touching the real DOING_AJAX constant (which cannot be undefined once
		// set and would otherwise leak into every later test in the process —
		// see the class docblock). Removed immediately below, so this never
		// outlives a single dispatch() call.
		add_filter( 'wp_doing_ajax', '__return_true', 999 );

		$payload = array_merge( [ 'nonce' => wp_create_nonce( $nonce_action ) ], $params );

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
			$handler();
		} catch ( \WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- intentional: JSON captured via ob_start; exception signals wp_die().
		}
		ob_end_clean();

		remove_filter( 'wp_die_handler',      [ $this, 'get_wp_die_handler' ], 1 );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ], 1 );
		remove_filter( 'wp_doing_ajax', '__return_true', 999 );

		$_POST    = [];
		$_REQUEST = [];

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : [ 'success' => false, 'data' => null ];
	}

	private function dispatch_fill_missing( int $post_id ): array {
		return $this->dispatch(
			[ PostListColumn::class, 'ajax_fill_missing' ],
			PostListColumn::NONCE_NAME,
			[ 'post_id' => $post_id ]
		);
	}

	private function dispatch_retranslate( int $post_id, string $from_lang = '' ): array {
		$params = [ 'post_id' => $post_id ];
		if ( '' !== $from_lang ) {
			$params['from_lang'] = $from_lang;
		}
		return $this->dispatch(
			[ PostListColumn::class, 'ajax_retranslate' ],
			PostListColumn::NONCE_NAME_RETRANSLATE,
			$params
		);
	}

	// =========================================================================
	// render_fill_button()
	// =========================================================================

	public function test_render_fill_button_outputs_button(): void {
		$post_id = $this->make_post();

		ob_start();
		PostListColumn::render_fill_button( $post_id, [ 'es' ] );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'lf-fill-missing', $html );
		$this->assertStringContainsString( (string) $post_id, $html );
	}

	public function test_render_fill_button_suppressed_for_wp_navigation(): void {
		$post_id = $this->make_post( 'publish', 'wp_navigation' );

		ob_start();
		PostListColumn::render_fill_button( $post_id, [ 'es' ] );
		$html = ob_get_clean();

		$this->assertSame( '', $html, 'wp_navigation posts must never show the generic "Translate missing" button.' );
	}

	// =========================================================================
	// render_retranslate_button()
	// =========================================================================

	public function test_render_retranslate_button_lists_sibling_languages(): void {
		$trid   = $this->trid();
		$en_id  = $this->make_post();
		$es_id  = $this->make_post();
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_retranslate_button( $es_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'lf-retranslate', $html );
		$this->assertStringContainsString( 'value="en"', $html, 'The sibling (en) must appear as a from-language option.' );
		$this->assertStringNotContainsString( 'value="es"', $html, "The post's own language must never appear as a from-language option." );
	}

	public function test_render_retranslate_button_lists_siblings_sorted_by_language_code(): void {
		$trid  = $this->trid();
		$fr_id = $this->make_post();
		$de_id = $this->make_post();
		$en_id = $this->make_post();
		$es_id = $this->make_post();
		// Inserted out of alphabetical order (fr, de, en) so the assertion below
		// actually exercises the sort rather than coincidentally matching DB order.
		$this->tg->set_trid( $fr_id, $trid ); $this->tg->set_lang( $fr_id, 'fr' );
		$this->tg->set_trid( $de_id, $trid ); $this->tg->set_lang( $de_id, 'de' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_retranslate_button( $es_id );
		$html = ob_get_clean();

		$de_pos = strpos( $html, 'value="de"' );
		$en_pos = strpos( $html, 'value="en"' );
		$fr_pos = strpos( $html, 'value="fr"' );

		$this->assertNotFalse( $de_pos );
		$this->assertNotFalse( $en_pos );
		$this->assertNotFalse( $fr_pos );
		$this->assertTrue( $de_pos < $en_pos && $en_pos < $fr_pos,
			'From-language options must be sorted alphabetically by language code (de, en, fr), regardless of insertion/DB order.' );
	}

	public function test_render_retranslate_button_silent_for_source_language_post(): void {
		$trid  = $this->trid();
		$en_id = $this->make_post();
		$es_id = $this->make_post();
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_retranslate_button( $en_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html, 'The source-language post must never show a Retranslate control.' );
	}

	public function test_render_retranslate_button_silent_without_siblings(): void {
		$es_id = $this->make_post();
		$this->tg->set_trid( $es_id, $this->trid() );
		$this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_retranslate_button( $es_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html, 'A post with no other TRID siblings has nothing to retranslate from.' );
	}

	// =========================================================================
	// ajax_fill_missing()
	// =========================================================================

	public function test_ajax_fill_missing_creates_missing_translation(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Título', '<p>Contenido</p>' )
		), 10, 3 );

		$resp = $this->dispatch_fill_missing( $source_id );

		$this->assertTrue( $resp['success'] ?? false, 'AJAX must report success.' );
		$outcome = $resp['data']['results']['es'] ?? null;
		$this->assertNotNull( $outcome, 'A result entry for "es" must be present.' );
		$this->assertSame( 'created', $outcome['status'] );

		$target = get_post( (int) $outcome['id'] );
		$this->assertInstanceOf( \WP_Post::class, $target );
		$this->assertSame( '<p>Contenido</p>', $target->post_content );
		$this->assertSame( 'Título', $target->post_title );
		$this->assertSame( 'publish', $target->post_status );
		$this->assertSame( 'es', get_post_meta( $target->ID, '_lf_lang', true ) );
		$this->assertSame(
			get_post_meta( $source_id, '_lf_trid', true ),
			get_post_meta( $target->ID, '_lf_trid', true )
		);
	}

	public function test_ajax_fill_missing_copies_source_thumbnail_to_new_translation(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		$attachment_id = (int) $this->factory->attachment->create( [ 'post_parent' => $source_id ] );
		// A raw meta write, not set_post_thumbnail(): the latter additionally
		// requires wp_get_attachment_image() to render the attachment, which a
		// bare factory attachment (no real file/generated sizes) fails, so
		// set_post_thumbnail() would silently no-op. create_linked_post() only
		// reads the _thumbnail_id meta value via get_post_thumbnail_id().
		update_post_meta( $source_id, '_thumbnail_id', $attachment_id );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Título', '<p>Contenido</p>' )
		), 10, 3 );

		$resp    = $this->dispatch_fill_missing( $source_id );
		$outcome = $resp['data']['results']['es'] ?? null;

		$this->assertNotNull( $outcome );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( (int) $outcome['id'] ),
			'The bulk "Translate missing" action must copy the source post\'s featured image onto the new translation.' );
	}

	public function test_ajax_fill_missing_reports_nothing_to_do_when_all_exist(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid ); $this->tg->set_lang( $target_id, 'es' );

		$resp = $this->dispatch_fill_missing( $source_id );

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertSame( 'All translations already exist.', $resp['data']['message'] ?? null );
	}

	public function test_ajax_fill_missing_rejects_wp_navigation(): void {
		$post_id = $this->make_post( 'publish', 'wp_navigation' );

		$resp = $this->dispatch_fill_missing( $post_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'Router tab', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_fill_missing_rejects_invalid_post_id(): void {
		$resp = $this->dispatch_fill_missing( 0 );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Invalid post ID.', $resp['data']['message'] ?? null );
	}

	public function test_ajax_fill_missing_rejects_insufficient_permissions(): void {
		$source_id = $this->make_post( 'publish' );

		wp_set_current_user( $this->subscriber_id );
		$resp = $this->dispatch_fill_missing( $source_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Insufficient permissions.', $resp['data']['message'] ?? null );
	}

	public function test_ajax_fill_missing_surfaces_provider_failure(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		// A null chat() response makes Translation::run() report failure.
		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider( null ), 10, 3 );

		$resp = $this->dispatch_fill_missing( $source_id );

		$this->assertFalse( $resp['success'] ?? true, 'All target languages failing must report overall failure.' );
		$outcome = $resp['data']['results']['es'] ?? null;
		$this->assertSame( 'error', $outcome['status'] ?? null );
	}

	// =========================================================================
	// ajax_retranslate()
	// =========================================================================

	public function test_ajax_retranslate_updates_target_from_chosen_sibling(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid ); $this->tg->set_lang( $target_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Re-traducido', '<p>Actualizado</p>' )
		), 10, 3 );

		$resp = $this->dispatch_retranslate( $target_id, 'en' );

		$this->assertTrue( $resp['success'] ?? false, 'AJAX must report success.' );
		$this->assertSame( 'updated', $resp['data']['status'] ?? null );

		$target = get_post( $target_id );
		$this->assertSame( '<p>Actualizado</p>', $target->post_content );
		$this->assertSame( 'Re-traducido', $target->post_title );
	}

	public function test_ajax_retranslate_defaults_from_lang_to_source_when_omitted(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid ); $this->tg->set_lang( $target_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'X', 'Y' )
		), 10, 3 );

		// No 'from_lang' param — must fall back to the configured source language (en).
		$resp = $this->dispatch_retranslate( $target_id );

		$this->assertTrue( $resp['success'] ?? false );
	}

	public function test_ajax_retranslate_rejects_same_from_and_target_language(): void {
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $es_id, $this->trid() );
		$this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_retranslate( $es_id, 'es' );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'same language', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_retranslate_rejects_source_language_post(): void {
		$en_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $en_id, $this->trid() );
		$this->tg->set_lang( $en_id, 'en' );

		$resp = $this->dispatch_retranslate( $en_id, 'es' );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'source-language post cannot be retranslated', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_retranslate_rejects_missing_from_language_sibling(): void {
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $es_id, $this->trid() );
		$this->tg->set_lang( $es_id, 'es' );
		// No 'en' sibling exists in this TRID group.

		$resp = $this->dispatch_retranslate( $es_id, 'en' );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'No EN version found', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_retranslate_rejects_insufficient_permissions(): void {
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $es_id, $this->trid() );
		$this->tg->set_lang( $es_id, 'es' );

		wp_set_current_user( $this->subscriber_id );
		$resp = $this->dispatch_retranslate( $es_id, 'en' );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Insufficient permissions.', $resp['data']['message'] ?? null );
	}
}
