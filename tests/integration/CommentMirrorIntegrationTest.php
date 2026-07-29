<?php
/**
 * Integration tests for LinguaForge\Router\Comments\CommentMirror.
 *
 * Covers the data model (group-ID assignment, canonical/mirror
 * distinction), the mirror-creation engine (including nested-reply parent
 * mapping and idempotent re-creation), the status cascade, and the
 * backfill-candidate scan's depth cap — everything CommentMirror owns
 * without an AI dependency. AI-calling orchestration
 * (CommentTranslation/CommentTranslationQueue) needs a stubbed provider and
 * is not covered here — see
 * lingua-forge-audit/PROPOSAL-comment-translation-2026-07-29.md.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Comments\CommentMirror;
use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_UnitTestCase;

final class CommentMirrorIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';
	private const TRANS_LANG  = 'de';

	private CommentMirror $mirror;

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );
		update_option( 'linguaforge_comment_translation_enabled', 1, false );
		update_option( 'linguaforge_comment_translation_mode', 'manual', false );
		update_option( 'linguaforge_comment_translation_max_backfill_depth', 2, false );

		// Reset per-request Context caches — the Router singleton persists
		// across tests in the same process (same precedent as
		// TrashCascadeIntegrationTest).
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}

		$this->mirror = Router::get_instance()->comment_mirror;
	}

	protected function tearDown(): void {
		delete_option( 'linguaforge_comment_translation_enabled' );
		delete_option( 'linguaforge_comment_translation_mode' );
		delete_option( 'linguaforge_comment_translation_max_backfill_depth' );
		remove_all_filters( 'linguaforge_comment_translation_excluded_types' );
		remove_all_filters( 'linguaforge_comment_translation_eligible_types' );
		parent::tearDown();
	}

	/**
	 * Builds a TRID pair: a source-language post and a translated sibling
	 * sharing the same _lf_trid — same helper shape as
	 * TrashCascadeIntegrationTest::make_trid_pair().
	 *
	 * @return array{0:int,1:int} [source_id, translation_id]
	 */
	private function make_trid_pair(): array {
		$trid_group = Router::get_instance()->trid_group;

		$source_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$trans_id  = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$trid = wp_generate_uuid4();
		$trid_group->set_trid( $source_id, $trid );
		$trid_group->set_trid( $trans_id, $trid );
		$trid_group->set_lang( $source_id, self::SOURCE_LANG );
		$trid_group->set_lang( $trans_id, self::TRANS_LANG );
		$trid_group->clear_translation_cache( $source_id );

		return [ $source_id, $trans_id ];
	}

	private function make_comment( int $post_id, array $overrides = [] ): int {
		return self::factory()->comment->create( array_merge( [
			'comment_post_ID'  => $post_id,
			'comment_approved' => 1,
			'comment_content'  => 'Hello, this is a test comment.',
		], $overrides ) );
	}

	// =========================================================================
	// Group-ID assignment
	// =========================================================================

	public function test_group_id_assigned_on_insert_when_eligible(): void {
		[ $source_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );

		$this->assertSame( (string) $comment_id, $this->mirror->get_group_id( $comment_id ) );
		$this->assertTrue( $this->mirror->is_canonical( $comment_id ) );
	}

	public function test_group_id_not_assigned_when_feature_disabled(): void {
		update_option( 'linguaforge_comment_translation_enabled', 0, false );

		[ $source_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );

		$this->assertSame( '', $this->mirror->get_group_id( $comment_id ) );
		$this->assertFalse( $this->mirror->is_canonical( $comment_id ) );
	}

	public function test_group_id_not_assigned_for_excluded_post_type(): void {
		if ( ! post_type_exists( 'product' ) ) {
			$this->markTestSkipped( 'WooCommerce not active — product post type unavailable.' );
		}

		$product_id = self::factory()->post->create( [ 'post_type' => 'product', 'post_status' => 'publish' ] );
		$comment_id = $this->make_comment( $product_id );

		$this->assertSame( '', $this->mirror->get_group_id( $comment_id ) );
	}

	public function test_review_comment_type_never_eligible_even_if_filter_allows_it(): void {
		add_filter( 'linguaforge_comment_translation_eligible_types', function ( array $types ): array {
			$types[] = 'review';
			return $types;
		} );

		[ $source_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id, [ 'comment_type' => 'review' ] );

		$this->assertFalse( $this->mirror->is_comment_type_eligible( 'review' ) );
		$this->assertSame( '', $this->mirror->get_group_id( $comment_id ) );
	}

	// =========================================================================
	// Mirror creation
	// =========================================================================

	public function test_create_or_update_mirror_creates_approved_translated_comment(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );
		$canonical  = get_comment( $comment_id );

		$mirror_id = $this->mirror->create_or_update_mirror( $canonical, self::TRANS_LANG, $trans_id, 'Hallo, das ist ein Testkommentar.' );

		$this->assertGreaterThan( 0, $mirror_id );

		$mirror_comment = get_comment( $mirror_id );
		$this->assertSame( $trans_id, (int) $mirror_comment->comment_post_ID );
		$this->assertSame( 'Hallo, das ist ein Testkommentar.', $mirror_comment->comment_content );
		$this->assertSame( '1', (string) $mirror_comment->comment_approved );
		$this->assertSame( (string) $comment_id, $this->mirror->get_group_id( $mirror_id ) );
		$this->assertSame( self::TRANS_LANG, $this->mirror->get_source_lang( $mirror_id ) );

		// A mirror is never itself canonical, even though it's approved and
		// eligible-typed — its group ID points at the canonical comment, not itself.
		$this->assertFalse( $this->mirror->is_canonical( $mirror_id ) );
	}

	public function test_create_or_update_mirror_is_idempotent(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );
		$canonical  = get_comment( $comment_id );

		$first  = $this->mirror->create_or_update_mirror( $canonical, self::TRANS_LANG, $trans_id, 'First translation.' );
		$second = $this->mirror->create_or_update_mirror( $canonical, self::TRANS_LANG, $trans_id, 'Updated translation.' );

		$this->assertSame( $first, $second );
		$this->assertSame( 'Updated translation.', get_comment( $second )->comment_content );

		$siblings = get_comments( [ 'post_id' => $trans_id, 'status' => 'any' ] );
		$this->assertCount( 1, $siblings, 'Re-running create_or_update_mirror() must not create a duplicate row.' );
	}

	public function test_nested_reply_mirror_skipped_until_parent_has_mirror(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();

		$parent_id = $this->make_comment( $source_id );
		$reply_id  = $this->make_comment( $source_id, [ 'comment_parent' => $parent_id ] );
		$reply     = get_comment( $reply_id );

		// Parent has no mirror on $trans_id yet — the nested reply must be skipped.
		$skipped = $this->mirror->create_or_update_mirror( $reply, self::TRANS_LANG, $trans_id, 'Translated reply.' );
		$this->assertSame( 0, $skipped );

		// Mirror the parent first…
		$parent_comment = get_comment( $parent_id );
		$parent_mirror_id = $this->mirror->create_or_update_mirror( $parent_comment, self::TRANS_LANG, $trans_id, 'Translated parent.' );
		$this->assertGreaterThan( 0, $parent_mirror_id );

		// …now the reply's own mirror succeeds and is parented to it correctly.
		$reply_mirror_id = $this->mirror->create_or_update_mirror( $reply, self::TRANS_LANG, $trans_id, 'Translated reply.' );
		$this->assertGreaterThan( 0, $reply_mirror_id );
		$this->assertSame( $parent_mirror_id, (int) get_comment( $reply_mirror_id )->comment_parent );
	}

	// =========================================================================
	// Status cascade
	// =========================================================================

	public function test_status_cascade_trashes_every_mirror(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );
		$canonical  = get_comment( $comment_id );

		$mirror_id = $this->mirror->create_or_update_mirror( $canonical, self::TRANS_LANG, $trans_id, 'Translated.' );
		$this->assertGreaterThan( 0, $mirror_id );

		wp_trash_comment( $comment_id );

		$this->assertSame( 'trash', wp_get_comment_status( $mirror_id ) );
	}

	public function test_status_cascade_does_not_infinite_loop(): void {
		// Regression guard for the reentrancy guard in
		// CommentMirror::handle_status_transition() — approving the canonical
		// comment when a mirror already exists must complete, not hang/recurse.
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id, [ 'comment_approved' => 0 ] );
		$canonical  = get_comment( $comment_id );

		$mirror_id = $this->mirror->create_or_update_mirror( $canonical, self::TRANS_LANG, $trans_id, 'Translated.' );
		$this->assertGreaterThan( 0, $mirror_id );

		wp_set_comment_status( $comment_id, 'approve' );

		$this->assertSame( 'approved', wp_get_comment_status( $comment_id ) );
		$this->assertSame( 'approved', wp_get_comment_status( $mirror_id ) );
	}

	// =========================================================================
	// Backfill discovery
	// =========================================================================

	public function test_find_backfill_candidates_finds_untranslated_canonical_comment(): void {
		[ $source_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );

		$candidates = $this->mirror->find_backfill_candidates();

		$ids = array_map( static fn ( array $c ): int => (int) $c['comment']->comment_ID, $candidates );
		$this->assertContains( $comment_id, $ids );

		foreach ( $candidates as $c ) {
			if ( (int) $c['comment']->comment_ID === $comment_id ) {
				$this->assertSame( [ self::TRANS_LANG ], $c['missing_langs'] );
			}
		}
	}

	public function test_find_backfill_candidates_excludes_already_mirrored_language(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$comment_id = $this->make_comment( $source_id );
		$canonical  = get_comment( $comment_id );

		$this->mirror->create_or_update_mirror( $canonical, self::TRANS_LANG, $trans_id, 'Translated.' );

		$candidates = $this->mirror->find_backfill_candidates();
		$ids = array_map( static fn ( array $c ): int => (int) $c['comment']->comment_ID, $candidates );

		$this->assertNotContains( $comment_id, $ids, 'A comment with no missing languages must not appear as a backfill candidate.' );
	}

	public function test_find_backfill_candidates_respects_max_depth(): void {
		update_option( 'linguaforge_comment_translation_max_backfill_depth', 0, false );

		[ $source_id ] = $this->make_trid_pair();
		$top_level = $this->make_comment( $source_id );
		$reply     = $this->make_comment( $source_id, [ 'comment_parent' => $top_level ] );

		$candidates = $this->mirror->find_backfill_candidates();
		$ids = array_map( static fn ( array $c ): int => (int) $c['comment']->comment_ID, $candidates );

		$this->assertContains( $top_level, $ids, 'Level-0 comment must still be considered when max depth is 0.' );
		$this->assertNotContains( $reply, $ids, 'Level-1 reply must be excluded when max depth is 0.' );
	}
}
