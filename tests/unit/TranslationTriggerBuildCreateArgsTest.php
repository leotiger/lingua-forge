<?php
/**
 * Unit tests for LinguaForge\AI\Features\TranslationTrigger::build_create_args().
 *
 * build_create_args() is the shared helper extracted per AUDIT-2026-07-11 §2:
 * it builds the common wp_insert_post() args (title, content, status, type,
 * author, excerpt) every translated-post creation path needs, so a future
 * common-field fix (like the excerpt fix this one addresses) lands in all
 * three creation paths — TranslationTrigger::create_translated_post(),
 * PostListColumn::create_linked_post(), and
 * AbstractTranslateCommand::create_trid_linked_post() — by construction
 * instead of requiring a three-way spot-fix.
 *
 * Pure logic, no WP function calls beyond native PHP (in_array, strtoupper),
 * so it's fully unit-testable with only the WP_Post stub.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Features\TranslationTrigger;
use WP_Post;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once __DIR__ . '/ApiPolyfills.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/TranslationTrigger.php';

final class TranslationTriggerBuildCreateArgsTest extends TestCase {

	private function make_source( string $status = 'publish', string $type = 'post' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_title  = 'Hello World';
		$post->post_status = $status;
		$post->post_type   = $type;
		$post->post_author = 7;
		return $post;
	}

	public function test_carries_translated_title_and_content(): void {
		$args = TranslationTrigger::build_create_args(
			$this->make_source(),
			'es',
			[ 'output' => '<p>Contenido</p>', 'translated_title' => 'Título' ]
		);

		$this->assertSame( 'Título', $args['post_title'] );
		$this->assertSame( '<p>Contenido</p>', $args['post_content'] );
	}

	public function test_synthesises_a_title_when_none_translated(): void {
		$args = TranslationTrigger::build_create_args( $this->make_source(), 'es', [ 'output' => 'x' ] );

		$this->assertSame( 'Hello World [ES]', $args['post_title'] );
	}

	public function test_writes_translated_excerpt_when_present(): void {
		// The bug this helper exists to prevent (AUDIT-2026-07-11 §2): a
		// creation path forgetting to carry translated_excerpt at birth.
		$args = TranslationTrigger::build_create_args(
			$this->make_source(),
			'es',
			[ 'output' => 'x', 'translated_excerpt' => 'Resumen' ]
		);

		$this->assertArrayHasKey( 'post_excerpt', $args );
		$this->assertSame( 'Resumen', $args['post_excerpt'] );
	}

	public function test_omits_excerpt_key_entirely_when_absent_from_result(): void {
		// isset(), not empty()/array_key_exists() with a fallback — an AI
		// result with no excerpt field must not write an empty post_excerpt
		// that could stomp on an update-path value elsewhere.
		$args = TranslationTrigger::build_create_args( $this->make_source(), 'es', [ 'output' => 'x' ] );

		$this->assertArrayNotHasKey( 'post_excerpt', $args );
	}

	public function test_inherits_publish_status_from_source(): void {
		$args = TranslationTrigger::build_create_args( $this->make_source( 'publish' ), 'es', [ 'output' => 'x' ] );

		$this->assertSame( 'publish', $args['post_status'] );
	}

	public function test_disallowed_source_status_falls_back_to_draft(): void {
		$args = TranslationTrigger::build_create_args( $this->make_source( 'trash' ), 'es', [ 'output' => 'x' ] );

		$this->assertSame( 'draft', $args['post_status'] );
	}

	public function test_force_draft_overrides_a_published_source(): void {
		$args = TranslationTrigger::build_create_args(
			$this->make_source( 'publish' ),
			'es',
			[ 'output' => 'x' ],
			true
		);

		$this->assertSame( 'draft', $args['post_status'] );
	}

	public function test_carries_post_type_and_author(): void {
		$args = TranslationTrigger::build_create_args( $this->make_source( 'publish', 'page' ), 'es', [ 'output' => 'x' ] );

		$this->assertSame( 'page', $args['post_type'] );
		$this->assertSame( 7, $args['post_author'] );
	}
}
