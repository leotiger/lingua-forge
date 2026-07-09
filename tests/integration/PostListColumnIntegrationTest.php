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
 *   render_sync_button()        — outputs the button on both source and
 *                                 secondary posts (unlike Retranslate, never
 *                                 suppressed for the source post); suppressed
 *                                 for wp_navigation and for a post with no
 *                                 assigned language
 *   ajax_sync()                 — fans a post's content out to every other
 *                                 configured language via a StubProvider:
 *                                 creates a missing sibling, force-refreshes
 *                                 an existing one (and clears its outdated
 *                                 flag, and reassigns its language-specific
 *                                 FSE template — a regression guard: Sync's
 *                                 batch loop unhooks handle_save_post() for
 *                                 its whole duration, unlike ajax_retranslate(),
 *                                 so update_linked_post() cannot rely on that
 *                                 hook firing and must assign the template
 *                                 explicitly), and — the key behavioural difference
 *                                 from ajax_retranslate() — can overwrite the
 *                                 primary/source post itself when triggered
 *                                 from a secondary-language post, while also
 *                                 clearing the outdated flag on the
 *                                 triggering post so it isn't left looking
 *                                 stale against the content it just produced;
 *                                 rejects wp_navigation, an invalid post ID,
 *                                 and insufficient permissions; surfaces a
 *                                 provider failure
 *   WooCommerce Sync safeguard  — a secondary-language 'product' post is
 *                                 blocked from Sync (button hidden, AJAX
 *                                 rejected) unless the
 *                                 linguaforge_wc_allow_secondary_sync option
 *                                 or the linguaforge_wc_secondary_sync_allowed
 *                                 filter allows it; syncing FROM the primary
 *                                 product is unaffected either way
 *   General Sync safeguard      — the same restriction as above, covering
 *                                 every OTHER post type (ordinary posts,
 *                                 pages, non-WC CPTs), via the independent
 *                                 linguaforge_allow_secondary_sync option /
 *                                 linguaforge_secondary_sync_allowed filter;
 *                                 also covers guard independence — enabling
 *                                 the WooCommerce toggle alone never lifts
 *                                 the general restriction (or a WC product),
 *                                 and vice versa
 *   linguaforge_sync_translations() — the public API wrapper (ai/ai.php):
 *                                 $check_caps defaults to false (bypasses
 *                                 current_user_can()), true enforces it
 *   render_template_sync_button() — outputs the "TS" button only on the
 *                                 primary/source-language post; silent for
 *                                 secondary posts, wp_navigation, and a post
 *                                 with no assigned language
 *   ajax_sync_templates()       — reassigns the language-specific FSE
 *                                 template for every EXISTING sibling in the
 *                                 TRID group, with no `linguaforge_ai_provider`
 *                                 filter registered anywhere in the test
 *                                 (proves it never reaches the translation
 *                                 feature at all); omits the source-language
 *                                 post from its own results; rejects a call
 *                                 from a secondary-language post, wp_navigation,
 *                                 an invalid post ID, and insufficient
 *                                 permissions
 *   linguaforge_sync_templates() — the public API wrapper (ai/ai.php):
 *                                 $check_caps defaults to false, true enforces it
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
		remove_all_filters( 'linguaforge_wc_secondary_sync_allowed' );
		delete_option( 'linguaforge_wc_allow_secondary_sync' );
		remove_all_filters( 'linguaforge_secondary_sync_allowed' );
		delete_option( 'linguaforge_allow_secondary_sync' );
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

	private function dispatch_sync( int $post_id ): array {
		return $this->dispatch(
			[ PostListColumn::class, 'ajax_sync' ],
			PostListColumn::NONCE_NAME_SYNC,
			[ 'post_id' => $post_id ]
		);
	}

	private function dispatch_sync_templates( int $post_id ): array {
		return $this->dispatch(
			[ PostListColumn::class, 'ajax_sync_templates' ],
			PostListColumn::NONCE_NAME_SYNC_TEMPLATES,
			[ 'post_id' => $post_id ]
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

	public function test_ajax_retranslate_reassigns_language_specific_template(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid ); $this->tg->set_lang( $target_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Re-traducido', '<p>Actualizado</p>' )
		), 10, 3 );

		$resp = $this->dispatch_retranslate( $target_id, 'en' );

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertSame(
			'single-es',
			get_post_meta( $target_id, '_wp_page_template', true ),
			'Retranslating an existing sibling must (re)assign its language-specific template.'
		);
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

	// =========================================================================
	// render_sync_button()
	// =========================================================================

	public function test_render_sync_button_shown_on_source_post(): void {
		$en_id = $this->make_post();
		$this->tg->set_trid( $en_id, $this->trid() );
		$this->tg->set_lang( $en_id, 'en' );

		ob_start();
		PostListColumn::render_sync_button( $en_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'lf-sync', $html,
			'Unlike Retranslate, Sync must be available on the source-language post too.' );
	}

	public function test_render_sync_button_suppressed_on_secondary_post_by_default(): void {
		delete_option( 'linguaforge_allow_secondary_sync' );

		$es_id = $this->make_post();
		$this->tg->set_trid( $es_id, $this->trid() );
		$this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_sync_button( $es_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html,
			'Sync from a secondary-language post must be blocked by default for every post type, not just WooCommerce products.' );
	}

	public function test_render_sync_button_shown_on_secondary_post_when_option_enabled(): void {
		update_option( 'linguaforge_allow_secondary_sync', 1, false );

		$es_id = $this->make_post();
		$this->tg->set_trid( $es_id, $this->trid() );
		$this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_sync_button( $es_id );
		$html = ob_get_clean();

		delete_option( 'linguaforge_allow_secondary_sync' );

		$this->assertStringContainsString( 'lf-sync', $html );
	}

	public function test_render_sync_button_suppressed_for_wp_navigation(): void {
		$post_id = $this->make_post( 'publish', 'wp_navigation' );
		$this->tg->set_lang( $post_id, 'en' );

		ob_start();
		PostListColumn::render_sync_button( $post_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_render_sync_button_suppressed_without_assigned_language(): void {
		$post_id = $this->make_post();
		// A normal save auto-assigns the source language via
		// Sync::handle_save_post() (wp_after_insert_post), so a freshly
		// created 'post' is never actually lang-less in practice — force the
		// "not yet assigned" state directly to exercise the guard clause.
		delete_post_meta( $post_id, '_lf_lang' );

		ob_start();
		PostListColumn::render_sync_button( $post_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	// =========================================================================
	// ajax_sync()
	// =========================================================================

	public function test_ajax_sync_from_source_creates_missing_translation(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Título', '<p>Contenido</p>' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $source_id );

		$this->assertTrue( $resp['success'] ?? false, 'AJAX must report success.' );
		$outcome = $resp['data']['results']['es'] ?? null;
		$this->assertNotNull( $outcome );
		$this->assertSame( 'created', $outcome['status'] );

		$target = get_post( (int) $outcome['id'] );
		$this->assertSame( '<p>Contenido</p>', $target->post_content );
		$this->assertSame( 'es', get_post_meta( $target->ID, '_lf_lang', true ) );
	}

	public function test_ajax_sync_from_source_refreshes_existing_translation(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid ); $this->tg->set_lang( $target_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Actualizado', '<p>Nuevo contenido</p>' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $source_id );

		$this->assertTrue( $resp['success'] ?? false );
		$outcome = $resp['data']['results']['es'] ?? null;
		$this->assertSame( 'updated', $outcome['status'] ?? null );

		$target = get_post( $target_id );
		$this->assertSame( '<p>Nuevo contenido</p>', $target->post_content );

		// The refreshed target must be marked in sync against the (unchanged)
		// source timestamp — no ⚠ outdated flag left behind.
		$this->assertSame(
			get_post_meta( $source_id, '_lf_source_updated_at', true ),
			get_post_meta( $target_id, '_lf_translation_source_updated_at', true )
		);
	}

	public function test_ajax_sync_refresh_reassigns_language_specific_template(): void {
		// Regression guard: run_sync() unhooks handle_save_post() (and therefore
		// assign_template_if_needed()) for its ENTIRE batch loop, unlike
		// ajax_retranslate() which restores the hook before calling into
		// update_linked_post(). Before this fix, update_linked_post() reset
		// _wp_page_template to 'default' and nothing ever reassigned it for a
		// sibling refreshed via Sync — silently stripping the template off an
		// already-correctly-templated existing translation.
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid ); $this->tg->set_lang( $target_id, 'es' );

		// Simulate an existing, already-correctly-templated translation.
		update_post_meta( $target_id, '_wp_page_template', 'single-es' );
		update_post_meta( $target_id, '_lf_auto_template', 'single-es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Actualizado', '<p>Nuevo contenido</p>' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $source_id );

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertSame( 'updated', $resp['data']['results']['es']['status'] ?? null );
		$this->assertSame(
			'single-es',
			get_post_meta( $target_id, '_wp_page_template', true ),
			'Sync force-refreshing an existing sibling must reassign its language-specific template, not leave it reset to default.'
		);
	}

	public function test_ajax_sync_rejects_secondary_post_by_default(): void {
		delete_option( 'linguaforge_allow_secondary_sync' );

		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$es_id     = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync( $es_id );

		$this->assertFalse( $resp['success'] ?? true,
			'Sync from a secondary-language post of an ordinary post type must be blocked by default.' );
		$this->assertStringContainsString( 'secondary-language post', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_sync_from_secondary_overwrites_primary_source_post_when_allowed(): void {
		update_option( 'linguaforge_allow_secondary_sync', 1, false );

		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$es_id     = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Back-translated title', '<p>Back-translated content</p>' )
		), 10, 3 );

		// Trigger Sync FROM the secondary (es) post — unlike Retranslate, this
		// must be allowed to overwrite the primary/source post once the general
		// secondary-sync safeguard is explicitly lifted.
		$resp = $this->dispatch_sync( $es_id );

		delete_option( 'linguaforge_allow_secondary_sync' );

		$this->assertTrue( $resp['success'] ?? false, 'AJAX must report success.' );
		$outcome = $resp['data']['results']['en'] ?? null;
		$this->assertNotNull( $outcome, 'A result entry for the primary language "en" must be present.' );
		$this->assertSame( 'updated', $outcome['status'] );

		$source = get_post( $source_id );
		$this->assertSame( '<p>Back-translated content</p>', $source->post_content,
			'Sync from a secondary language must be able to overwrite the primary/source post.' );

		// The secondary post that drove the sync must not be left showing as
		// outdated relative to the primary content it was just used to produce.
		$this->assertSame(
			get_post_meta( $source_id, '_lf_source_updated_at', true ),
			get_post_meta( $es_id, '_lf_translation_source_updated_at', true )
		);
	}

	public function test_ajax_sync_allows_secondary_post_via_general_filter_override(): void {
		// The option stays off; the filter alone lifts the restriction.
		add_filter( 'linguaforge_secondary_sync_allowed', '__return_true' );

		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$es_id     = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'X', 'Y' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $es_id );

		remove_filter( 'linguaforge_secondary_sync_allowed', '__return_true' );

		$this->assertTrue( $resp['success'] ?? false,
			'The linguaforge_secondary_sync_allowed filter must be able to lift the restriction independently of the option.' );
	}

	// =========================================================================
	// Guard independence — WooCommerce vs. general secondary-sync safeguards
	// =========================================================================

	public function test_ajax_sync_wc_guard_unaffected_by_general_option(): void {
		// Only the GENERAL option is enabled; the WooCommerce-specific one is not.
		update_option( 'linguaforge_allow_secondary_sync', 1, false );
		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish', 'product' );
		$es_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync( $es_id );

		delete_option( 'linguaforge_allow_secondary_sync' );

		$this->assertFalse( $resp['success'] ?? true,
			'Enabling the general secondary-sync setting must not lift the separate WooCommerce-specific restriction.' );
		$this->assertStringContainsString( 'WooCommerce product', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_sync_general_guard_unaffected_by_wc_option(): void {
		// Only the WooCommerce-specific option is enabled; the general one is not.
		update_option( 'linguaforge_wc_allow_secondary_sync', 1, false );
		delete_option( 'linguaforge_allow_secondary_sync' );

		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$es_id     = $this->make_post( 'publish' );
		$this->tg->set_trid( $source_id, $trid ); $this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync( $es_id );

		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$this->assertFalse( $resp['success'] ?? true,
			'Enabling the WooCommerce-specific secondary-sync setting must not lift the separate general restriction on an ordinary post.' );
		$this->assertStringContainsString( 'secondary-language post', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_sync_rejects_wp_navigation(): void {
		$post_id = $this->make_post( 'publish', 'wp_navigation' );
		$this->tg->set_lang( $post_id, 'en' );

		$resp = $this->dispatch_sync( $post_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'Router tab', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_sync_rejects_invalid_post_id(): void {
		$resp = $this->dispatch_sync( 0 );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Invalid post ID.', $resp['data']['message'] ?? null );
	}

	public function test_ajax_sync_rejects_insufficient_permissions(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		wp_set_current_user( $this->subscriber_id );
		$resp = $this->dispatch_sync( $source_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Insufficient permissions.', $resp['data']['message'] ?? null );
	}

	public function test_ajax_sync_surfaces_provider_failure(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider( null ), 10, 3 );

		$resp = $this->dispatch_sync( $source_id );

		$this->assertFalse( $resp['success'] ?? true, 'Every target language failing must report overall failure.' );
		$outcome = $resp['data']['results']['es'] ?? null;
		$this->assertSame( 'error', $outcome['status'] ?? null );
	}

	// =========================================================================
	// WooCommerce Sync safeguard
	// =========================================================================

	public function test_render_sync_button_suppressed_for_secondary_wc_product_by_default(): void {
		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish', 'product' );
		$es_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_sync_button( $es_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html,
			'Sync must be hidden on a secondary-language WooCommerce product by default.' );
	}

	public function test_render_sync_button_shown_for_primary_wc_product_regardless_of_setting(): void {
		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$en_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $this->trid() );
		$this->tg->set_lang( $en_id, 'en' );

		ob_start();
		PostListColumn::render_sync_button( $en_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'lf-sync', $html,
			'Syncing FROM the primary product must always be allowed.' );
	}

	public function test_render_sync_button_shown_for_secondary_wc_product_when_option_enabled(): void {
		update_option( 'linguaforge_wc_allow_secondary_sync', 1, false );

		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish', 'product' );
		$es_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_sync_button( $es_id );
		$html = ob_get_clean();

		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$this->assertStringContainsString( 'lf-sync', $html );
	}

	public function test_ajax_sync_rejects_secondary_wc_product_by_default(): void {
		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish', 'product' );
		$es_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync( $es_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'WooCommerce product', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_sync_allows_secondary_wc_product_when_option_enabled(): void {
		update_option( 'linguaforge_wc_allow_secondary_sync', 1, false );

		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish', 'product' );
		$es_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Back-translated', '<p>Back-translated body</p>' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $es_id );

		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$this->assertTrue( $resp['success'] ?? false );
		$outcome = $resp['data']['results']['en'] ?? null;
		$this->assertSame( 'updated', $outcome['status'] ?? null );
	}

	public function test_ajax_sync_allows_secondary_wc_product_via_filter_override(): void {
		// The option stays off; the filter alone lifts the restriction.
		add_filter( 'linguaforge_wc_secondary_sync_allowed', '__return_true' );

		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish', 'product' );
		$es_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'X', 'Y' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $es_id );

		remove_filter( 'linguaforge_wc_secondary_sync_allowed', '__return_true' );

		$this->assertTrue( $resp['success'] ?? false,
			'The linguaforge_wc_secondary_sync_allowed filter must be able to lift the restriction independently of the option.' );
	}

	public function test_ajax_sync_allows_from_source_wc_product_by_default(): void {
		delete_option( 'linguaforge_wc_allow_secondary_sync' );

		$en_id = $this->make_post( 'publish', 'product' );
		$this->tg->set_lang( $en_id, 'en' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Título', '<p>Contenido</p>' )
		), 10, 3 );

		$resp = $this->dispatch_sync( $en_id );

		$this->assertTrue( $resp['success'] ?? false,
			'Syncing FROM the primary-language product must be allowed even with the safeguard on.' );
	}

	// =========================================================================
	// linguaforge_sync_translations() public API wrapper
	// =========================================================================

	public function test_linguaforge_sync_translations_defaults_check_caps_to_false(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		add_filter( 'linguaforge_ai_provider', fn() => new StubProvider(
			$this->translation_json( 'Título', '<p>Contenido</p>' )
		), 10, 3 );

		wp_set_current_user( $this->subscriber_id ); // No edit_post capability on this post.

		$result = linguaforge_sync_translations( $source_id );

		$this->assertTrue( $result['success'] ?? false,
			'linguaforge_sync_translations() must not enforce current_user_can() by default.' );
	}

	public function test_linguaforge_sync_translations_check_caps_true_enforces_permissions(): void {
		$source_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $source_id, 'en' );

		wp_set_current_user( $this->subscriber_id );

		$result = linguaforge_sync_translations( $source_id, true );

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'Insufficient permissions.', $result['message'] ?? null );
	}

	// =========================================================================
	// render_template_sync_button()
	// =========================================================================

	public function test_render_template_sync_button_shown_on_source_post(): void {
		$trid  = $this->trid();
		$en_id = $this->make_post();
		$es_id = $this->make_post();
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_template_sync_button( $en_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'lf-sync-templates', $html );
	}

	public function test_render_template_sync_button_suppressed_on_secondary_post(): void {
		$trid  = $this->trid();
		$en_id = $this->make_post();
		$es_id = $this->make_post();
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		ob_start();
		PostListColumn::render_template_sync_button( $es_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html,
			'Template Sync must only ever be shown on the primary/source-language post — restricted from the start, unlike Sync.' );
	}

	public function test_render_template_sync_button_suppressed_for_wp_navigation(): void {
		$post_id = $this->make_post( 'publish', 'wp_navigation' );
		$this->tg->set_lang( $post_id, 'en' );

		ob_start();
		PostListColumn::render_template_sync_button( $post_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_render_template_sync_button_suppressed_without_assigned_language(): void {
		$post_id = $this->make_post();
		// A normal save auto-assigns the source language via
		// Sync::handle_save_post() (wp_after_insert_post), so a freshly
		// created 'post' is never actually lang-less in practice — force the
		// "not yet assigned" state directly to exercise the guard clause.
		delete_post_meta( $post_id, '_lf_lang' );

		ob_start();
		PostListColumn::render_template_sync_button( $post_id );
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	// =========================================================================
	// ajax_sync_templates() / run_sync_templates()
	// =========================================================================

	public function test_ajax_sync_templates_reassigns_templates_without_any_ai_provider(): void {
		// Deliberately no `linguaforge_ai_provider` filter registered anywhere
		// in this test — proves Template Sync never reaches the translation
		// feature at all (the entire point: no AI cost).
		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish' );
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync_templates( $en_id );

		$this->assertTrue( $resp['success'] ?? false, 'AJAX must report success.' );
		$outcome = $resp['data']['results']['es'] ?? null;
		$this->assertNotNull( $outcome );
		$this->assertSame( 'synced', $outcome['status'] );
		$this->assertSame( $es_id, $outcome['id'] );
		$this->assertSame( 'single-es', $outcome['template'] );
		$this->assertSame( 'single-es', get_post_meta( $es_id, '_wp_page_template', true ) );
	}

	public function test_ajax_sync_templates_results_omit_the_source_language_entry(): void {
		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish' );
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync_templates( $en_id );

		$this->assertTrue( $resp['success'] ?? false );
		$this->assertArrayNotHasKey( 'en', $resp['data']['results'] ?? [ 'en' => true ],
			'The source-language post is never a template-assignment target.' );
		$this->assertSame( '', (string) get_post_meta( $en_id, '_wp_page_template', true ),
			'The primary post must never get an explicit template assigned by Template Sync.' );
	}

	public function test_ajax_sync_templates_rejects_call_from_secondary_post(): void {
		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish' );
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		$resp = $this->dispatch_sync_templates( $es_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertStringContainsString( 'primary/source-language', $resp['data']['message'] ?? '' );
	}

	public function test_ajax_sync_templates_rejects_wp_navigation(): void {
		$post_id = $this->make_post( 'publish', 'wp_navigation' );
		$this->tg->set_lang( $post_id, 'en' );

		$resp = $this->dispatch_sync_templates( $post_id );

		$this->assertFalse( $resp['success'] ?? true );
	}

	public function test_ajax_sync_templates_rejects_invalid_post_id(): void {
		$resp = $this->dispatch_sync_templates( 0 );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Invalid post ID.', $resp['data']['message'] ?? null );
	}

	public function test_ajax_sync_templates_rejects_insufficient_permissions(): void {
		$en_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $en_id, 'en' );

		wp_set_current_user( $this->subscriber_id );
		$resp = $this->dispatch_sync_templates( $en_id );

		$this->assertFalse( $resp['success'] ?? true );
		$this->assertSame( 'Insufficient permissions.', $resp['data']['message'] ?? null );
	}

	// =========================================================================
	// linguaforge_sync_templates() public API wrapper
	// =========================================================================

	public function test_linguaforge_sync_templates_defaults_check_caps_to_false(): void {
		$trid  = $this->trid();
		$en_id = $this->make_post( 'publish' );
		$es_id = $this->make_post( 'publish' );
		$this->tg->set_trid( $en_id, $trid ); $this->tg->set_lang( $en_id, 'en' );
		$this->tg->set_trid( $es_id, $trid ); $this->tg->set_lang( $es_id, 'es' );

		wp_set_current_user( $this->subscriber_id ); // No edit_post capability on this post.

		$result = linguaforge_sync_templates( $en_id );

		$this->assertTrue( $result['success'] ?? false,
			'linguaforge_sync_templates() must not enforce current_user_can() by default.' );
		$this->assertSame( 'single-es', get_post_meta( $es_id, '_wp_page_template', true ) );
	}

	public function test_linguaforge_sync_templates_check_caps_true_enforces_permissions(): void {
		$en_id = $this->make_post( 'publish' );
		$this->tg->set_lang( $en_id, 'en' );

		wp_set_current_user( $this->subscriber_id );

		$result = linguaforge_sync_templates( $en_id, true );

		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( 'Insufficient permissions.', $result['message'] ?? null );
	}
}
