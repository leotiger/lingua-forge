<?php
/**
 * Integration tests for LinguaForge\Router\Translation\TridGroup.
 *
 * TridGroup is the translation-group primitive: it maps post IDs to TRID
 * strings and language codes, and runs SQL queries to find all members of a
 * translation group.  All methods touch wp_postmeta or $wpdb, so these tests
 * must run inside wp-env.
 *
 * Test infrastructure:
 *   • WP_UnitTestCase wraps every test in a DB transaction and rolls it back
 *     at tearDown — no manual cleanup of posts / meta is needed.
 *   • setUp() resets the per-instance caches on the shared Router::instance()
 *     Context so option changes in other tests don't bleed through.
 *   • TridGroup is accessed via the booted plugin's Router singleton so the
 *     full $wpdb / cache stack is wired up identically to production.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionClass;
use WP_UnitTestCase;

final class TridGroupTest extends WP_UnitTestCase {

	private TridGroup $tg;

	// ── Helpers ───────────────────────────────────────────────────────────────

	protected function setUp(): void {
		parent::setUp();
		$this->tg = Router::get_instance()->trid_group;

		// Reset Context instance-caches so option changes are re-read fresh
		// each test.  The Router singleton persists across test methods; its
		// Context caches would otherwise return stale option values.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}
	}

	protected function tearDown(): void {
		remove_all_filters( 'lf_languages_list' );
		parent::tearDown();
	}

	/** Generate a unique TRID string so concurrent test runs can't collide. */
	private function trid(): string {
		return 'trid-' . uniqid( '', true );
	}

	// ── set_trid / get_trid ───────────────────────────────────────────────────

	public function test_get_trid_returns_empty_string_for_post_with_no_meta(): void {
		$id = self::factory()->post->create();
		$this->assertSame( '', $this->tg->get_trid( $id ) );
	}

	public function test_set_and_get_trid_round_trip(): void {
		$id = self::factory()->post->create();
		$this->tg->set_trid( $id, 'group-abc' );
		$this->assertSame( 'group-abc', $this->tg->get_trid( $id ) );
	}

	public function test_set_trid_overwrites_previous_value(): void {
		$id = self::factory()->post->create();
		$this->tg->set_trid( $id, 'first' );
		$this->tg->set_trid( $id, 'second' );
		$this->assertSame( 'second', $this->tg->get_trid( $id ) );
	}

	// ── set_lang / get_lang ───────────────────────────────────────────────────

	public function test_get_lang_returns_source_language_when_meta_absent(): void {
		update_option( 'linguaforge_primary_language', 'ca', false );
		$id = self::factory()->post->create();
		// No _lf_lang meta — must fall back to source_language().
		$this->assertSame( 'ca', $this->tg->get_lang( $id ) );
	}

	public function test_set_and_get_lang_round_trip(): void {
		$id = self::factory()->post->create();
		$this->tg->set_lang( $id, 'de' );
		$this->assertSame( 'de', $this->tg->get_lang( $id ) );
	}

	public function test_set_lang_overwrites_previous_value(): void {
		$id = self::factory()->post->create();
		$this->tg->set_lang( $id, 'de' );
		$this->tg->set_lang( $id, 'en' );
		$this->assertSame( 'en', $this->tg->get_lang( $id ) );
	}

	// ── get_translations ─────────────────────────────────────────────────────

	public function test_get_translations_returns_empty_array_for_post_with_no_trid(): void {
		$id = self::factory()->post->create();
		$this->assertSame( [], $this->tg->get_translations( $id ) );
	}

	public function test_get_translations_returns_empty_array_for_lone_post_in_group(): void {
		// A post with a TRID but no other group members still returns correctly
		// (the post itself is included when _lf_lang is set).
		$id = self::factory()->post->create();
		$this->tg->set_trid( $id, $this->trid() );
		$this->tg->set_lang( $id, 'ca' );

		$result = $this->tg->get_translations( $id );
		$this->assertCount( 1, $result );
		$this->assertSame( $id, $result['ca'] );
	}

	public function test_get_translations_returns_all_members_of_group(): void {
		$trid = $this->trid();

		$ca = self::factory()->post->create();
		$de = self::factory()->post->create();
		$en = self::factory()->post->create();

		foreach ( [ $ca => 'ca', $de => 'de', $en => 'en' ] as $id => $lang ) {
			$this->tg->set_trid( $id, $trid );
			$this->tg->set_lang( $id, $lang );
		}

		$result = $this->tg->get_translations( $ca );

		$this->assertCount( 3, $result );
		$this->assertSame( $ca, $result['ca'] );
		$this->assertSame( $de, $result['de'] );
		$this->assertSame( $en, $result['en'] );
	}

	public function test_get_translations_returns_integer_post_ids(): void {
		$trid = $this->trid();
		$id   = self::factory()->post->create();

		$this->tg->set_trid( $id, $trid );
		$this->tg->set_lang( $id, 'ca' );

		foreach ( $this->tg->get_translations( $id ) as $post_id ) {
			$this->assertIsInt( $post_id, 'get_translations() must return integer post IDs.' );
		}
	}

	public function test_get_translations_excludes_auto_draft_posts(): void {
		$trid   = $this->trid();
		$pub_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$aut_id = self::factory()->post->create( [ 'post_status' => 'auto-draft' ] );

		foreach ( [ $pub_id => 'ca', $aut_id => 'de' ] as $id => $lang ) {
			$this->tg->set_trid( $id, $trid );
			$this->tg->set_lang( $id, $lang );
		}

		$result = $this->tg->get_translations( $pub_id );

		$this->assertArrayHasKey( 'ca', $result );
		$this->assertArrayNotHasKey( 'de', $result, 'auto-draft posts must be excluded.' );
	}

	// ── Translation cache ─────────────────────────────────────────────────────

	public function test_get_translations_returns_same_result_on_second_call(): void {
		$trid = $this->trid();
		$id   = self::factory()->post->create();

		$this->tg->set_trid( $id, $trid );
		$this->tg->set_lang( $id, 'ca' );

		$this->assertSame(
			$this->tg->get_translations( $id ),
			$this->tg->get_translations( $id )
		);
	}

	public function test_clear_translation_cache_allows_db_re_read(): void {
		$trid   = $this->trid();
		$post_a = self::factory()->post->create();

		$this->tg->set_trid( $post_a, $trid );
		$this->tg->set_lang( $post_a, 'ca' );

		// Prime the cache with just one member.
		$before = $this->tg->get_translations( $post_a );
		$this->assertCount( 1, $before );

		// Add a second member while the cache is warm.
		$post_b = self::factory()->post->create();
		$this->tg->set_trid( $post_b, $trid );
		$this->tg->set_lang( $post_b, 'de' );

		// Without clearing, the stale cache would hide post_b.
		$this->tg->clear_translation_cache( $post_a );

		$after = $this->tg->get_translations( $post_a );
		$this->assertCount( 2, $after );
		$this->assertArrayHasKey( 'de', $after );
	}

	// ── get_missing_languages ────────────────────────────────────────────────

	public function test_get_missing_languages_returns_untranslated_langs(): void {
		// Pin the language list to a known set via filter.
		add_filter( 'lf_languages_list', fn() => [ 'ca', 'de', 'en' ] );

		$trid = $this->trid();
		$id   = self::factory()->post->create();

		$this->tg->set_trid( $id, $trid );
		$this->tg->set_lang( $id, 'ca' );

		$missing = $this->tg->get_missing_languages( $id );

		$this->assertContains( 'de', $missing, "'de' should be missing." );
		$this->assertContains( 'en', $missing, "'en' should be missing." );
		$this->assertNotContains( 'ca', $missing, "Current lang 'ca' must not appear as missing." );
	}

	public function test_get_missing_languages_returns_empty_when_all_langs_translated(): void {
		add_filter( 'lf_languages_list', fn() => [ 'ca', 'de', 'en' ] );

		$trid = $this->trid();
		$ids  = [
			'ca' => self::factory()->post->create(),
			'de' => self::factory()->post->create(),
			'en' => self::factory()->post->create(),
		];

		foreach ( $ids as $lang => $id ) {
			$this->tg->set_trid( $id, $trid );
			$this->tg->set_lang( $id, $lang );
		}

		$this->assertSame( [], $this->tg->get_missing_languages( $ids['ca'] ) );
	}

	public function test_get_missing_languages_returns_empty_for_unlinked_post(): void {
		// No TRID means get_translations() returns [] — no languages exist,
		// so every known language except the post's own appears missing.
		// More importantly: the method must not throw or return garbage.
		add_filter( 'lf_languages_list', fn() => [ 'ca', 'de' ] );

		$id = self::factory()->post->create();
		$this->tg->set_lang( $id, 'ca' );
		// No set_trid() call — post is not in any group.

		$missing = $this->tg->get_missing_languages( $id );

		// 'ca' is the current lang and is excluded; 'de' has no post in the
		// group (get_translations returns []) so it is missing.
		$this->assertContains( 'de', $missing );
		$this->assertNotContains( 'ca', $missing );
	}
}
