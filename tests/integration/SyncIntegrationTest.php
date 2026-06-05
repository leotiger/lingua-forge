<?php
/**
 * Integration tests for LinguaForge\Router\Translation\Sync::handle_save_post().
 *
 * handle_save_post() is hooked onto wp_after_insert_post (registered in
 * Sync::register_hooks(), called from Router::__construct()). These tests call
 * wp_insert_post() / wp_update_post() and assert the postmeta that Sync writes.
 *
 * Coverage — §6.0.1 High (class-sync.php, 8%):
 *   1. New 'post' → _lf_lang set to source language.
 *   2. New 'post' → _lf_trid set to a UUID v4.
 *   3. Post with existing _lf_lang = 'de' → lang preserved on subsequent save.
 *   4. wp_navigation post type → _lf_lang assigned; _lf_trid skipped (non-public guard).
 *
 * Design notes:
 *   • An administrator user is set in setUp() because handle_save_post() bails
 *     at current_user_can('edit_post') when no user is logged in.
 *   • WP_UnitTestCase wraps every test in a DB transaction rolled back on
 *     tearDown — no manual post/meta cleanup is needed.
 *   • Context caches are reset in setUp() so option changes don't bleed across
 *     tests (the Router singleton persists between test methods).
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class SyncIntegrationTest extends WP_UnitTestCase {

	/** Source language — matches linguaforge_primary_language option. */
	private const SOURCE_LANG = 'en';

	/** A secondary language used for the preservation test. */
	private const TRANS_LANG = 'de';

	protected function setUp(): void {
		parent::setUp();

		// Fix source language so Router::source_language() is deterministic.
		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );

		// Reset all per-request Context caches — the Router singleton persists
		// across tests; without this, a stale cached_source_language bleeds through.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}

		// handle_save_post() bails at current_user_can('edit_post') when no user is
		// set. Set an administrator so the capability check passes.
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
	}

	protected function tearDown(): void {
		// Restore anonymous (no) user so other test classes are not affected.
		wp_set_current_user( 0 );
		remove_all_filters( 'lf_languages_list' );
		parent::tearDown();
	}

	// =========================================================================
	// 1. New post → _lf_lang = source language
	// =========================================================================

	/**
	 * When a new public post is created without any _lf_lang meta, handle_save_post()
	 * must assign the source language as the default.
	 */
	public function test_new_post_gets_source_language_assigned(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$lang = get_post_meta( $post_id, '_lf_lang', true );

		$this->assertSame(
			self::SOURCE_LANG,
			$lang,
			'handle_save_post() must assign the source language when _lf_lang is absent.'
		);
	}

	// =========================================================================
	// 2. New post → _lf_trid = UUID v4
	// =========================================================================

	/**
	 * When a new public post is created without any _lf_trid meta, handle_save_post()
	 * must generate a UUID v4 and store it.
	 */
	public function test_new_post_gets_trid_assigned(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$trid = get_post_meta( $post_id, '_lf_trid', true );

		$this->assertNotEmpty( $trid, 'handle_save_post() must assign a TRID on first save.' );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			(string) $trid,
			'_lf_trid must be a valid UUID v4.'
		);
	}

	// =========================================================================
	// 3. Existing _lf_lang preserved on subsequent save
	// =========================================================================

	/**
	 * Once _lf_lang is set (e.g. to 'de' for a translated post), a subsequent
	 * wp_update_post() must not reset it to the source language.
	 *
	 * handle_save_post() only writes the default source-lang fallback when
	 * get_post_meta($post_id, '_lf_lang', true) returns falsy — so any
	 * already-stored value is left intact.
	 */
	public function test_existing_language_preserved_on_update(): void {
		// Create — Sync writes source lang on first save.
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		// Switch to the translation language via TridGroup.
		Router::get_instance()->trid_group->set_lang( $post_id, self::TRANS_LANG );

		$this->assertSame(
			self::TRANS_LANG,
			get_post_meta( $post_id, '_lf_lang', true ),
			'Pre-condition: _lf_lang must be de before update.'
		);

		// Trigger another wp_after_insert_post cycle — Sync must leave 'de' in place.
		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Updated title' ] );

		$this->assertSame(
			self::TRANS_LANG,
			get_post_meta( $post_id, '_lf_lang', true ),
			'handle_save_post() must not overwrite an already-set _lf_lang.'
		);
	}

	// =========================================================================
	// 4. wp_navigation → _lf_lang assigned, _lf_trid not assigned
	// =========================================================================

	/**
	 * wp_navigation is a non-public post type that Sync explicitly allows through
	 * the first guard (so FSE navigation posts receive _lf_lang). The second guard
	 * inside handle_save_post() — the !$pto->public check before the TRID block —
	 * runs before TRID assignment and causes an early return, so _lf_trid is never
	 * written for navigation posts.
	 */
	public function test_wp_navigation_gets_lang_but_no_trid(): void {
		$post_id = self::factory()->post->create( [
			'post_type'   => 'wp_navigation',
			'post_status' => 'publish',
		] );

		$lang = get_post_meta( $post_id, '_lf_lang', true );
		$trid = get_post_meta( $post_id, '_lf_trid', true );

		$this->assertSame(
			self::SOURCE_LANG,
			$lang,
			'wp_navigation must receive _lf_lang from handle_save_post().'
		);
		$this->assertEmpty(
			$trid,
			'wp_navigation must NOT receive _lf_trid — the non-public type guard returns early before TRID assignment.'
		);
	}
}
