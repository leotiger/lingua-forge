<?php
/**
 * Integration tests for Translation::run() and run_json_envelope().
 *
 * These tests exercise the full orchestration path inside a real WordPress
 * runtime (wp-env) with a StubProvider injected via the
 * `linguaforge_ai_provider` filter — no live AI API key required.
 *
 * Coverage targets:
 *   • run() cache hit — CacheStore pre-seeded; run() short-circuits with cached:true
 *   • run() JSON-envelope path — stub returns valid JSON; full translate+cache cycle
 *   • run() cache written on success — second call returns cached:true
 *   • run() empty provider response — stub returns null; run() returns success:false
 *   • run() linguaforge_translation_content filter — filter can mutate output
 *   • run() TM path, all blocks cached — stub translates title only; content from TM
 *   • run() TM fallback — TM disabled; falls through to JSON-envelope
 *
 * StubProvider is injected fresh per-test so each test controls its own response.
 *
 * Note on custom tables: the plugin creates its DB tables in admin_init (never
 * fires in CLI) or the activation hook (runs against a DB that PHPUnit resets).
 * setUp() calls ensure_table() for each table the tests need; the static guards
 * inside make this a no-op after the first call in a process.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\TranslationMemory;
use LinguaForge\AI\Features\Translation;
use LinguaForge\AI\Features\TranslationMemoryTranslator;
use LinguaForge\Tests\Integration\Stubs\StubProvider;
use WP_UnitTestCase;

require_once __DIR__ . '/Stubs/StubProvider.php';

final class TranslationIntegrationTest extends WP_UnitTestCase {

	// =========================================================================
	// Fixtures
	// =========================================================================

	private Translation $translation;
	private int         $post_id;

	/** The StubProvider instance registered for the current test. */
	private StubProvider $stub;

	/** Block content used across most tests. */
	private const BLOCK_CONTENT = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';

	protected function setUp(): void {
		parent::setUp();

		// Custom tables (Glossary, CacheStore, TranslationMemory) are created by
		// the `wp eval` step in composer test:integration before phpunit starts.
		// The activation hook does NOT create them; admin_init never fires in CLI.

		$this->translation = new Translation();

		$this->post_id = (int) self::factory()->post->create( [
			'post_title'   => 'Hello World',
			'post_content' => self::BLOCK_CONTENT,
			'post_status'  => 'publish',
			'post_excerpt' => '',
		] );

		// Disable cache and TM by default; individual tests opt in.
		update_option( 'linguaforge_api_cache_enabled', false );
		update_option( 'linguaforge_translation_memory_enabled', false );

		// Register stub provider — each test sets $this->stub->response before calling run().
		$this->stub = new StubProvider();
		add_filter( 'linguaforge_ai_provider', [ $this, 'inject_stub' ], 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'linguaforge_ai_provider', [ $this, 'inject_stub' ] );
		parent::tearDown();
	}

	public function inject_stub(): StubProvider {
		return $this->stub;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Build the JSON response the stub should return for a standard
	 * German translation of a simple paragraph post.
	 */
	private function de_json( string $title = 'Hallo Welt', string $content = '<!-- wp:paragraph --><p>Testinhalt</p><!-- /wp:paragraph -->' ): string {
		return (string) wp_json_encode( [ 'title' => $title, 'content' => $content ] );
	}

	/**
	 * Return the compliance signature exactly as TranslationMemoryTranslator::translate()
	 * computes it, so TM pre-seeding uses the same key at runtime.
	 * Now public static — no reflection needed.
	 */
	private function compliance_sig( int $post_id = 0 ): string {
		return TranslationMemoryTranslator::compute_compliance_signature( $post_id );
	}

	// =========================================================================
	// Cache hit
	// =========================================================================

	public function test_run_returns_cached_payload_on_cache_hit(): void {
		update_option( 'linguaforge_api_cache_enabled', true );

		$post            = get_post( $this->post_id );
		$target_language = 'de';
		$cache_key       = 'translation_' . $target_language;
		$hash            = CacheStore::hash( [
			$post->post_title,
			$post->post_content,
			'', // footnotes_raw
			$target_language,
			\LinguaForge\AI\Core\Config::provider(),
			\LinguaForge\AI\Core\Config::model( \LinguaForge\AI\Core\Config::translation_tier() ),
		] );

		$cached_payload = [
			'output'   => '<p>Gecachter Inhalt</p>',
			'type'     => 'content',
			'language' => 'German',
		];
		CacheStore::set( $this->post_id, $cache_key, $hash, $cached_payload );

		$result = $this->translation->run( $this->post_id, [ 'target_language' => $target_language ] );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['cached'] );
		$this->assertSame( '<p>Gecachter Inhalt</p>', $result['output'] );
		// Stub should not have been called — provider was not needed.
		$this->assertCount( 0, $this->stub->calls );
	}

	// =========================================================================
	// JSON-envelope path
	// =========================================================================

	public function test_run_translates_post_via_json_envelope(): void {
		$this->stub = new StubProvider( $this->de_json() );

		$result = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'content', $result['type'] );
		$this->assertSame( 'German',  $result['language'] );
		$this->assertStringContainsString( 'Testinhalt', $result['output'] );
		$this->assertSame( 'Hallo Welt', $result['translated_title'] );
	}

	public function test_run_writes_cache_after_successful_translation(): void {
		update_option( 'linguaforge_api_cache_enabled', true );
		$this->stub = new StubProvider( $this->de_json() );

		// First call — hits the provider.
		$first = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );
		$this->assertTrue( $first['success'] );
		$this->assertArrayNotHasKey( 'cached', $first );

		// Second call — same inputs, should hit the cache.
		$second = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['cached'] );
		$this->assertCount( 1, $this->stub->calls ); // provider called only once
	}

	public function test_run_returns_failure_when_provider_returns_empty(): void {
		$this->stub = new StubProvider( null ); // null → empty response

		$result = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Translation failed', $result['error'] );
	}

	public function test_run_applies_linguaforge_translation_content_filter(): void {
		$this->stub = new StubProvider( $this->de_json() );

		$filter_called = false;
		add_filter( 'linguaforge_translation_content', static function ( array $payload ) use ( &$filter_called ): array {
			$filter_called    = true;
			$payload['output'] = '<!-- wp:paragraph --><p>Filtered</p><!-- /wp:paragraph -->';
			return $payload;
		} );

		$result = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );

		$this->assertTrue( $filter_called );
		$this->assertStringContainsString( 'Filtered', $result['output'] );
	}

	// =========================================================================
	// TM path
	// =========================================================================

	public function test_run_tm_partial_hit_falls_back_to_json_envelope(): void {
		update_option( 'linguaforge_translation_memory_enabled', true );
		update_post_meta( $this->post_id, '_lf_lang', 'en' );

		// TM is enabled but no blocks are pre-seeded → all blocks go into the queue.
		// First call: TM path — stub returns wrong block count (0 instead of 1)
		//   → parse_tm_envelope() mismatch → try_translate_with_tm() returns null.
		// Second call: JSON-envelope fallback — stub returns valid translation JSON.
		$this->stub = new StubProvider( [
			(string) wp_json_encode( [ 'title' => 'T', 'blocks' => [] ] ), // bad: 0 blocks, expected 1
			$this->de_json(),                                                // good: JSON-envelope path
		] );

		$result = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Testinhalt', $result['output'] );
		// Provider called twice: once for TM (bad response), once for JSON-envelope.
		$this->assertCount( 2, $this->stub->calls );
	}

	public function test_run_chains_meta_description_when_flag_set(): void {
		update_option( 'linguaforge_translation_memory_enabled', false );

		// First call: Translation (JSON-envelope path).
		// Second call: MetaDescription (chained after translation).
		$meta_json = (string) wp_json_encode( [ 'output' => 'Eine deutsche Metabeschreibung.' ] );
		$this->stub = new StubProvider( [ $this->de_json(), $meta_json ] );

		$result = $this->translation->run( $this->post_id, [
			'target_language'      => 'de',
			'with_meta_description' => true,
		] );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Testinhalt', $result['output'] );
		$this->assertArrayHasKey( 'meta_description', $result );
		$this->assertStringContainsString( 'Metabeschreibung', $result['meta_description'] );
		// Two provider calls: one for translation, one for meta description.
		$this->assertCount( 2, $this->stub->calls );
	}

	public function test_run_uses_tm_cache_for_all_blocks_skips_api_for_content(): void {
		update_option( 'linguaforge_translation_memory_enabled', true );
		update_post_meta( $this->post_id, '_lf_lang', 'en' );

		// Pre-seed TM with the translated block using the exact same key components
		// that try_translate_with_tm() will compute at runtime.
		$source_markup    = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
		$translated_block = '<!-- wp:paragraph --><p>Hallo Welt</p><!-- /wp:paragraph -->';
		$glossary_hash    = \LinguaForge\AI\Core\Glossary::hash_for_pair( 'en', 'de' );
		$compliance_sig   = $this->compliance_sig( $this->post_id );

		TranslationMemory::store(
			$source_markup,
			$translated_block,
			'en',
			'de',
			$glossary_hash,
			$compliance_sig
		);

		// Stub returns a title-only response (no blocks needed — all cached).
		$this->stub = new StubProvider(
			(string) wp_json_encode( [ 'title' => 'Hallo Welt' ] )
		);

		$result = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Hallo Welt', $result['output'] );
		$this->assertSame( 'Hallo Welt', $result['translated_title'] );
		// Provider was called once — for title (blocks were all TM-cached).
		$this->assertCount( 1, $this->stub->calls );
	}

	public function test_run_falls_through_to_json_envelope_when_tm_disabled(): void {
		update_option( 'linguaforge_translation_memory_enabled', false );
		update_post_meta( $this->post_id, '_lf_lang', 'en' );

		$this->stub = new StubProvider( $this->de_json() );

		$result = $this->translation->run( $this->post_id, [ 'target_language' => 'de' ] );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Testinhalt', $result['output'] );
		// Provider called via JSON-envelope path, not TM.
		$this->assertCount( 1, $this->stub->calls );
		// System message should contain the full-post prompt (not the TM blocks prompt).
		$system_msg = $this->stub->calls[0][0]['content'] ?? '';
		$this->assertStringContainsString( 'professional translator', $system_msg );
	}
}
