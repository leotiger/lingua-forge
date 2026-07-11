<?php
/**
 * Unit tests for LinguaForge\AI\Admin\FseLocalisation\ScaffoldHandler.
 *
 * Covers only `resolve_existing_post_id()` — the pure decision logic
 * extracted from `ajax_scaffold_template()` / `ajax_scaffold_template_part()`
 * specifically so it could be unit-tested (AUDIT-2026-07-11 §9: "the
 * create/update decision logic could be extracted and unit-tested"). Both
 * AJAX handlers themselves remain integration-only — they call
 * `get_block_templates()`, `wp_insert_post()`, `wp_update_post()`, and
 * `wp_set_post_terms()` directly, none of which are available in the unit
 * suite's WordPress-free bootstrap.
 *
 * The theme-scoping fix this method supports (AUDIT-2026-07-11 §9's
 * "theme-scoping edge": a bare post_name lookup with no theme filter could
 * match an unrelated existing row belonging to a different theme or plugin)
 * lives in the `get_block_templates(['theme' => ...])` call each AJAX
 * handler now makes — that part requires a real WordPress boot to verify
 * end-to-end and is covered by ScaffoldHandlerThemeScopingIntegrationTest
 * instead (wp-env only, not run in this sandbox).
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Admin\FseLocalisation\ScaffoldHandler;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Bootstrap constants
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Admin/FseLocalisation/ScaffoldHandler.php';

final class ScaffoldHandlerTest extends TestCase {

	public function test_resolve_existing_post_id_returns_zero_for_empty_array(): void {
		$this->assertSame( 0, ScaffoldHandler::resolve_existing_post_id( [] ) );
	}

	/**
	 * A file-only template/part (a theme .html file with no DB override yet)
	 * comes back from get_block_templates() as an object with no wp_id
	 * property at all — must be treated as "insert fresh", not "update".
	 */
	public function test_resolve_existing_post_id_returns_zero_when_candidate_has_no_wp_id_property(): void {
		$candidate = (object) [ 'slug' => 'page-de', 'theme' => 'my-theme' ];

		$this->assertSame( 0, ScaffoldHandler::resolve_existing_post_id( [ $candidate ] ) );
	}

	/**
	 * Some callers may explicitly set wp_id to 0 rather than omitting it —
	 * must be treated the same as "not set" (falsy check, not isset-only).
	 */
	public function test_resolve_existing_post_id_returns_zero_when_wp_id_is_explicitly_zero(): void {
		$candidate = (object) [ 'slug' => 'page-de', 'wp_id' => 0 ];

		$this->assertSame( 0, ScaffoldHandler::resolve_existing_post_id( [ $candidate ] ) );
	}

	/**
	 * A real DB-stored template/part carries a non-zero wp_id — this is the
	 * "update in place, keep the same post ID" case.
	 */
	public function test_resolve_existing_post_id_returns_the_post_id_for_a_db_stored_candidate(): void {
		$candidate = (object) [ 'slug' => 'page-de', 'wp_id' => 42 ];

		$this->assertSame( 42, ScaffoldHandler::resolve_existing_post_id( [ $candidate ] ) );
	}

	/**
	 * Regression guard: only the FIRST candidate is ever considered, matching
	 * the single-slug `slug__in` lookup both AJAX handlers make (which should
	 * only ever return one theme/namespace-scoped match, but the decision
	 * logic itself must not silently scan past index 0 if it somehow ever
	 * receives more).
	 */
	public function test_resolve_existing_post_id_only_considers_the_first_candidate(): void {
		$file_only = (object) [ 'slug' => 'page-de' ];
		$db_stored = (object) [ 'slug' => 'page-de', 'wp_id' => 7 ];

		$this->assertSame(
			0,
			ScaffoldHandler::resolve_existing_post_id( [ $file_only, $db_stored ] ),
			'A DB-stored candidate later in the array must not be picked up if the first candidate is file-only.'
		);
	}

	public function test_resolve_existing_post_id_return_type_is_always_int(): void {
		$candidate = (object) [ 'wp_id' => '99' ]; // string, as could come from a loosely-typed source.

		$result = ScaffoldHandler::resolve_existing_post_id( [ $candidate ] );

		$this->assertIsInt( $result );
		$this->assertSame( 99, $result );
	}
}
