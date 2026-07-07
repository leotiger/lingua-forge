<?php
/**
 * Integration tests for LinguaForge\Router\Translation\TrashCascade.
 *
 * Covers the "Trash + Siblings" row action added to Posts/Pages/CPT list
 * tables: the add_row_action() filter callback, and the trash_group()
 * cascade engine it (and the admin-post handler) both call.
 *
 * See lingua-forge-audit/PROPOSAL-trash-cascade-2026-07-07.md for the
 * design rationale.
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

final class TrashCascadeIntegrationTest extends WP_UnitTestCase {

	private const SOURCE_LANG = 'en';
	private const TRANS_LANG  = 'de';

	protected function setUp(): void {
		parent::setUp();

		update_option( 'linguaforge_primary_language', self::SOURCE_LANG, false );
		update_option( 'linguaforge_secondary_query_excluded_types', '', false );

		// Reset per-request Context caches — the Router singleton persists
		// across tests in the same process.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'linguaforge_trash_cascade_post_ids' );
		remove_all_filters( 'linguaforge_trash_cascade_complete' );
		delete_option( 'page_on_front' );
		delete_option( 'page_for_posts' );
		delete_option( 'show_on_front' );
		parent::tearDown();
	}

	/**
	 * Builds a TRID pair: a source-language post and a translated sibling
	 * sharing the same _lf_trid, via the same TridGroup accessors the plugin
	 * itself uses.
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

	// =========================================================================
	// add_row_action()
	// =========================================================================

	public function test_row_action_not_added_without_siblings(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$post    = get_post( $post_id );

		$actions = Router::get_instance()->trash_cascade->add_row_action( [ 'trash' => '<a>Trash</a>' ], $post );

		$this->assertArrayNotHasKey(
			'lf_trash_siblings',
			$actions,
			'A post with no TRID siblings must not get the "Trash + Siblings" row action.'
		);
	}

	public function test_row_action_added_and_placed_after_trash(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$post = get_post( $source_id );

		$actions = Router::get_instance()->trash_cascade->add_row_action(
			[ 'edit' => '<a>Edit</a>', 'trash' => '<a>Trash</a>', 'view' => '<a>View</a>' ],
			$post
		);

		$this->assertArrayHasKey( 'lf_trash_siblings', $actions );
		$this->assertSame(
			[ 'edit', 'trash', 'lf_trash_siblings', 'view' ],
			array_keys( $actions ),
			'"Trash + Siblings" must be inserted immediately after "trash", before "view".'
		);
		$this->assertStringContainsString( 'action=linguaforge_trash_with_siblings', $actions['lf_trash_siblings'] );
		$this->assertStringContainsString( 'post=' . $source_id, $actions['lf_trash_siblings'] );
	}

	public function test_row_action_not_added_on_trash_screen(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		wp_update_post( [ 'ID' => $source_id, 'post_status' => 'trash' ] );
		$post = get_post( $source_id );

		$actions = Router::get_instance()->trash_cascade->add_row_action( [ 'untrash' => '<a>Restore</a>' ], $post );

		$this->assertArrayNotHasKey(
			'lf_trash_siblings',
			$actions,
			'Out of scope for v1: the row action must not appear once the post is already in the Trash.'
		);
	}

	public function test_row_action_not_added_for_excluded_post_type(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		update_option( 'linguaforge_secondary_query_excluded_types', 'post', false );
		$post = get_post( $source_id );

		$actions = Router::get_instance()->trash_cascade->add_row_action( [ 'trash' => '<a>Trash</a>' ], $post );

		$this->assertArrayNotHasKey(
			'lf_trash_siblings',
			$actions,
			'Post types excluded via linguaforge_secondary_query_excluded_types must not get the row action.'
		);
	}

	// =========================================================================
	// trash_group()
	// =========================================================================

	public function test_trash_group_trashes_post_and_sibling(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();

		$result = Router::get_instance()->trash_cascade->trash_group( $source_id );

		$this->assertSame( [ 'trashed' => 2, 'skipped' => 0 ], $result );
		$this->assertSame( 'trash', get_post_status( $source_id ) );
		$this->assertSame( 'trash', get_post_status( $trans_id ) );
	}

	public function test_trash_group_triggered_from_translation_also_trashes_source(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();

		// Cascade direction is symmetric — triggering from either post trashes the whole group.
		$result = Router::get_instance()->trash_cascade->trash_group( $trans_id );

		$this->assertSame( 2, $result['trashed'] );
		$this->assertSame( 'trash', get_post_status( $source_id ) );
		$this->assertSame( 'trash', get_post_status( $trans_id ) );
	}

	public function test_trash_group_default_check_caps_skips_without_permission(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		wp_set_current_user( 0 ); // Anonymous — no delete_post capability on anything.

		$result = Router::get_instance()->trash_cascade->trash_group( $source_id );

		$this->assertSame(
			[ 'trashed' => 0, 'skipped' => 2 ],
			$result,
			'$check_caps defaults to true, matching the wp-admin row/bulk action call sites, which always have a real logged-in user.'
		);
		$this->assertSame( 'publish', get_post_status( $source_id ) );
		$this->assertSame( 'publish', get_post_status( $trans_id ) );
	}

	public function test_trash_group_check_caps_false_bypasses_permission_check(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		wp_set_current_user( 0 ); // Anonymous — same as a token-authenticated REST request with no WP session.

		$result = Router::get_instance()->trash_cascade->trash_group( $source_id, false );

		$this->assertSame(
			[ 'trashed' => 2, 'skipped' => 0 ],
			$result,
			'A trusted programmatic caller passing check_caps=false must not be blocked by current_user_can() when there is no meaningful current-WP-user context.'
		);
		$this->assertSame( 'trash', get_post_status( $source_id ) );
		$this->assertSame( 'trash', get_post_status( $trans_id ) );
	}

	// =========================================================================
	// linguaforge_trash_translation_group() — public wrapper function
	// =========================================================================

	public function test_public_wrapper_defaults_check_caps_to_false(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		wp_set_current_user( 0 );

		$result = linguaforge_trash_translation_group( $source_id );

		$this->assertSame(
			[ 'trashed' => 2, 'skipped' => 0 ],
			$result,
			'Unlike TrashCascade::trash_group() itself, the public wrapper function defaults check_caps to false — it is meant for programmatic callers (REST endpoints, CLI) that often have no logged-in WP user at all.'
		);
	}

	public function test_public_wrapper_forwards_explicit_check_caps_true(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		wp_set_current_user( 0 );

		$result = linguaforge_trash_translation_group( $source_id, true );

		$this->assertSame(
			[ 'trashed' => 0, 'skipped' => 2 ],
			$result,
			'Passing check_caps=true explicitly must still enforce current_user_can() through the wrapper.'
		);
	}

	public function test_trash_group_skips_static_front_page(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		update_option( 'show_on_front', 'page', false );
		update_option( 'page_on_front', $trans_id, false );

		$result = Router::get_instance()->trash_cascade->trash_group( $source_id );

		$this->assertSame( 1, $result['trashed'], 'Only the non-front-page post should be trashed.' );
		$this->assertSame( 1, $result['skipped'], 'The static front page must be skipped, not force-trashed.' );
		$this->assertSame( 'publish', get_post_status( $trans_id ), 'The front page itself must remain untouched.' );
		$this->assertSame( 'trash', get_post_status( $source_id ) );
	}

	public function test_trash_group_ignores_already_trashed_sibling(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		wp_trash_post( $trans_id );

		$result = Router::get_instance()->trash_cascade->trash_group( $source_id );

		// Already-trashed siblings are neither re-counted as trashed nor
		// reported as skipped — see the inline comment in trash_group().
		$this->assertSame( [ 'trashed' => 1, 'skipped' => 0 ], $result );
	}

	public function test_trash_group_post_ids_filter_can_shrink_the_group(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();
		$extra_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		add_filter( 'linguaforge_trash_cascade_post_ids', function ( array $ids ) use ( $extra_id ) {
			$ids[] = $extra_id;
			return $ids;
		} );

		$result = Router::get_instance()->trash_cascade->trash_group( $source_id );

		$this->assertSame( 3, $result['trashed'], 'linguaforge_trash_cascade_post_ids must be able to extend the group.' );
		$this->assertSame( 'trash', get_post_status( $extra_id ) );
	}

	public function test_trash_group_fires_complete_action_with_id_lists(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();

		$captured = null;
		add_action( 'linguaforge_trash_cascade_complete', function ( $trashed, $skipped, $triggered_from ) use ( &$captured ) {
			$captured = [ $trashed, $skipped, $triggered_from ];
		}, 10, 3 );

		Router::get_instance()->trash_cascade->trash_group( $source_id );

		$this->assertNotNull( $captured, 'linguaforge_trash_cascade_complete must fire.' );
		$this->assertEqualsCanonicalizing( [ $source_id, $trans_id ], $captured[0] );
		$this->assertSame( [], $captured[1] );
		$this->assertSame( $source_id, $captured[2] );
	}

	// =========================================================================
	// Bulk action registration
	// =========================================================================

	public function test_bulk_action_hooks_registered_for_post_and_page(): void {
		// register_bulk_action_hooks() is only ever invoked automatically via
		// Router::register_admin_hooks(), which is gated behind is_admin() —
		// evaluated once inside the Router constructor at muplugins_loaded,
		// well before any test's setUp() runs. In the WP-CLI/PHPUnit test
		// runner is_admin() is false at that moment (no real wp-admin request
		// is ever made), so the automatic wiring never happens here — that's
		// an artifact of the test environment, not something wrong with
		// production behaviour (confirmed: WP core itself never sets
		// is_admin() true for a bare CLI/phpunit process). Call the method
		// under test directly, exactly as Router::register_admin_hooks()
		// would on a real wp-admin page load, so this test actually exercises
		// register_bulk_action_hooks()'s own logic (bulk_action_post_types()
		// + the per-type add_filter() calls) rather than the unrelated
		// is_admin() bootstrap gate.
		$cascade = Router::get_instance()->trash_cascade;
		$cascade->register_bulk_action_hooks();

		$this->assertNotFalse(
			has_filter( 'bulk_actions-edit-post', [ $cascade, 'add_bulk_action' ] ),
			'bulk_actions-edit-post must be registered.'
		);
		$this->assertNotFalse(
			has_filter( 'handle_bulk_actions-edit-post', [ $cascade, 'handle_bulk_action' ] ),
			'handle_bulk_actions-edit-post must be registered.'
		);
		$this->assertNotFalse(
			has_filter( 'bulk_actions-edit-page', [ $cascade, 'add_bulk_action' ] ),
			'bulk_actions-edit-page must be registered — unlike admin columns, bulk action hook names are uniform across post types.'
		);

		// register_bulk_action_hooks() was called directly above rather than
		// through the normal is_admin()-gated boot path, so nothing else
		// would ever remove these — clean up explicitly so they don't leak
		// into other integration test files sharing this PHP process. A bare
		// WP test install only has 'post'/'page' as eligible public types
		// ('attachment' is excluded by bulk_action_post_types() itself), so
		// those are the only two that were actually registered above.
		foreach ( [ 'post', 'page' ] as $post_type ) {
			remove_filter( "bulk_actions-edit-{$post_type}", [ $cascade, 'add_bulk_action' ] );
			remove_filter( "handle_bulk_actions-edit-{$post_type}", [ $cascade, 'handle_bulk_action' ] );
		}
	}

	// =========================================================================
	// add_bulk_action()
	// =========================================================================

	public function test_add_bulk_action_inserts_after_trash(): void {
		$actions = Router::get_instance()->trash_cascade->add_bulk_action( [
			'edit'  => 'Edit',
			'trash' => 'Move to Trash',
		] );

		$this->assertSame(
			[ 'edit', 'trash', 'lf_trash_with_siblings' ],
			array_keys( $actions ),
			'"Move to Trash (incl. translations)" must be inserted immediately after "trash".'
		);
	}

	public function test_add_bulk_action_skipped_on_trash_screen(): void {
		// The Trash screen's own $actions array has 'untrash'/'delete', not
		// 'trash' — out of scope for v1 (see the proposal doc).
		$actions = Router::get_instance()->trash_cascade->add_bulk_action( [
			'untrash' => 'Restore',
			'delete'  => 'Delete Permanently',
		] );

		$this->assertArrayNotHasKey( 'lf_trash_with_siblings', $actions );
	}

	// =========================================================================
	// handle_bulk_action()
	// =========================================================================

	public function test_handle_bulk_action_ignores_other_actions(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();

		$redirect = Router::get_instance()->trash_cascade->handle_bulk_action(
			'https://example.org/wp-admin/edit.php',
			'trash', // stock action, not ours
			[ $source_id ]
		);

		$this->assertSame( 'https://example.org/wp-admin/edit.php', $redirect );
		$this->assertSame( 'publish', get_post_status( $source_id ), 'Must not touch posts when $doaction is not lf_trash_with_siblings.' );
	}

	public function test_handle_bulk_action_trashes_selected_groups(): void {
		[ $source_id, $trans_id ]   = $this->make_trid_pair();
		[ $source_id_2, $trans_id_2 ] = $this->make_trid_pair();

		$redirect = Router::get_instance()->trash_cascade->handle_bulk_action(
			'https://example.org/wp-admin/edit.php',
			'lf_trash_with_siblings',
			[ $source_id, $source_id_2 ]
		);

		foreach ( [ $source_id, $trans_id, $source_id_2, $trans_id_2 ] as $id ) {
			$this->assertSame( 'trash', get_post_status( $id ) );
		}

		$query = wp_parse_url( $redirect, PHP_URL_QUERY );
		parse_str( (string) $query, $args );
		$this->assertSame( '4', $args['lf_trashed'] );
		$this->assertSame( '0', $args['lf_skipped'] );
	}

	public function test_handle_bulk_action_deduplicates_selected_siblings(): void {
		[ $source_id, $trans_id ] = $this->make_trid_pair();

		// Both the source AND its own translation are selected in the same
		// batch — must not be processed (and counted) twice.
		$redirect = Router::get_instance()->trash_cascade->handle_bulk_action(
			'https://example.org/wp-admin/edit.php',
			'lf_trash_with_siblings',
			[ $source_id, $trans_id ]
		);

		$query = wp_parse_url( $redirect, PHP_URL_QUERY );
		parse_str( (string) $query, $args );
		$this->assertSame( '2', $args['lf_trashed'], 'Selecting both members of a TRID pair must still report 2 trashed, not 4.' );
	}
}
