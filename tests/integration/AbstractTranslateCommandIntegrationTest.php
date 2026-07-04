<?php
/**
 * Integration tests for AbstractTranslateCommand — the shared base for the
 * `translate` / `retranslate` / `fill_translations` WP-CLI commands.
 *
 * This is 0%-covered code today (see AUDIT-2026-06-13.md §7.1 and the
 * coverage-gap review that prompted this file): every method here either
 * calls a static \WP_CLI:: method or is reached only from execute(), and the
 * real \WP_CLI class is never loaded under `composer test:integration` (see
 * the "WP_CLI note" in RedirectorRedirectIntegrationTest.php — that script
 * runs `vendor/bin/phpunit` directly, not `wp phpunit`). support-wp-cli-stub.php
 * supplies a minimal stand-in so this class's real logic — argument
 * validation, worker-config overrides, and the create/update translation-apply
 * path — can finally be exercised.
 *
 * Covered here:
 *   validate_post_id()             — valid ID, missing/zero ID, nonexistent ID
 *   validate_target_langs()        — valid CSV, empty, unknown code, trims/
 *                                    lowercases via sanitize_key()
 *   register_worker_overrides_filter() / collect_worker_config_overrides()
 *                                   — model/max-tokens/temperature overrides
 *                                    applied via the linguaforge_translation_
 *                                    worker_config filter; temperature clamped
 *                                    to [0,1]; invalid values warned + ignored;
 *                                    no filter registered when nothing supplied
 *   apply_translation()             — create path (no TRID-linked post yet):
 *                                    new post created, linked into TRID group,
 *                                    status inherited from source unless
 *                                    force_draft, disallowed source statuses
 *                                    fall back to draft, linguaforge_translation_
 *                                    complete fires with the right args;
 *                                    update path (TRID-linked post exists):
 *                                    content/title/excerpt written, footnotes
 *                                    meta set, page_template reset to 'default'
 *   dump_debug_files()              — prints the newest matching debug files,
 *                                    skips files older than 60s (not from this run)
 *
 * NOT covered here (left for a follow-up pass):
 *   - generate_and_save_meta_description() — calls the real MetaDescription
 *     AI feature; needs the same pre_http_request provider stub used in
 *     ProviderChatIntegrationTest, kept out of this pass to stay focused.
 *   - resolve_translation_feature() — trivial Registry passthrough.
 *   - The concrete execute() methods on TranslateCommand / RetranslateCommand /
 *     FillTranslationsCommand — they call Translation::run() (the real AI
 *     pipeline) and \WP_CLI\Utils\get_flag_value() / format_items(), a
 *     larger dependency surface than this pass covers. TranslateCommand is
 *     used below purely as a concrete instance to reflect into the abstract
 *     parent's protected/private methods.
 *
 * Strategy:
 *   • AbstractTranslateCommand is abstract; TranslateCommand (its simplest
 *     concrete subclass) is instantiated and reflection invokes the parent's
 *     protected/private methods directly, bypassing execute() entirely.
 *   • \WP_CLI::error() halts real WP-CLI execution; the stub throws
 *     WpCliTestErrorException instead (see support-wp-cli-stub.php), the same
 *     exception-seam idiom RedirectorRedirectIntegrationTest.php uses for
 *     wp_redirect(). WP_CLI::reset() runs in setUp()/tearDown() so recorded
 *     log/warning/error calls never bleed between tests.
 *   • Posts are created via the factory; TRID linkage uses TridGroup directly
 *     (set_trid/set_lang) where a test needs an existing linked post, and is
 *     left entirely unset where a test wants to exercise the
 *     "no TRID yet" first-translation path.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\CLI\AbstractTranslateCommand;
use LinguaForge\AI\CLI\TranslateCommand;
use LinguaForge\AI\Core\TranslationDebug;
use LinguaForge\AI\Providers\WorkerConfig;
use LinguaForge\Router\Router;
use LinguaForge\Router\Translation\TridGroup;
use ReflectionMethod;
use WP_UnitTestCase;

require_once __DIR__ . '/support-wp-cli-stub.php';

final class AbstractTranslateCommandIntegrationTest extends WP_UnitTestCase {

	private TranslateCommand $cmd;
	private TridGroup $tg;

	/** @var string|null  Temp debug dir used by the dump_debug_files() tests; cleaned in tearDown(). */
	private ?string $debug_tmp_dir = null;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	protected function setUp(): void {
		parent::setUp();

		\WP_CLI::reset();

		$this->cmd = new TranslateCommand();
		$this->tg  = Router::get_instance()->trid_group;
	}

	protected function tearDown(): void {
		\WP_CLI::reset();
		remove_all_filters( 'linguaforge_translation_worker_config' );
		remove_all_filters( 'linguaforge_debug_dir' );
		remove_all_actions( 'linguaforge_translation_complete' );

		if ( null !== $this->debug_tmp_dir && is_dir( $this->debug_tmp_dir ) ) {
			// glob('*') does not match dotfiles, and debug_write() drops a
			// .htaccess + index.html placeholder on first write in the dir — use
			// scandir() so those don't leak an unremovable temp directory.
			foreach ( scandir( $this->debug_tmp_dir ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a sys_get_temp_dir() fixture path created by this same test; WP_Filesystem would require request_filesystem_credentials() for no benefit here.
				unlink( $this->debug_tmp_dir . '/' . $entry );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Same rationale as the unlink() above: test-only temp directory cleanup.
			rmdir( $this->debug_tmp_dir );
			$this->debug_tmp_dir = null;
		}

		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Invoke a protected/private AbstractTranslateCommand method via reflection.
	 *
	 * @param  string $method
	 * @param  array  $args
	 * @return mixed
	 */
	private function invoke( string $method, array $args = [] ) {
		$ref = new ReflectionMethod( AbstractTranslateCommand::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->cmd, $args );
	}

	private function make_post( string $status = 'publish', string $post_type = 'post' ): int {
		return (int) $this->factory->post->create( [
			'post_type'   => $post_type,
			'post_status' => $status,
		] );
	}

	private function trid(): string {
		return 'atc-trid-' . uniqid( '', true );
	}

	// =========================================================================
	// validate_post_id()
	// =========================================================================

	public function test_validate_post_id_returns_wp_post_for_valid_id(): void {
		$id = $this->make_post();

		$post = $this->invoke( 'validate_post_id', [ [ (string) $id ] ] );

		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( $id, $post->ID );
	}

	public function test_validate_post_id_errors_on_missing_arg(): void {
		try {
			$this->invoke( 'validate_post_id', [ [] ] );
			$this->fail( 'Expected WP_CLI::error() for a missing post-id argument.' );
		} catch ( \WpCliTestErrorException $e ) {
			$this->assertStringContainsString( 'positive post ID', $e->getMessage() );
		}
	}

	public function test_validate_post_id_errors_on_zero(): void {
		try {
			$this->invoke( 'validate_post_id', [ [ '0' ] ] );
			$this->fail( 'Expected WP_CLI::error() for post-id 0.' );
		} catch ( \WpCliTestErrorException $e ) {
			$this->assertStringContainsString( 'positive post ID', $e->getMessage() );
		}
	}

	public function test_validate_post_id_errors_on_nonexistent_post(): void {
		$missing_id = 999999999;

		try {
			$this->invoke( 'validate_post_id', [ [ (string) $missing_id ] ] );
			$this->fail( 'Expected WP_CLI::error() for a nonexistent post.' );
		} catch ( \WpCliTestErrorException $e ) {
			$this->assertStringContainsString( (string) $missing_id, $e->getMessage() );
			$this->assertStringContainsString( 'not found', $e->getMessage() );
		}
	}

	// =========================================================================
	// validate_target_langs()
	// =========================================================================

	public function test_validate_target_langs_parses_valid_csv(): void {
		$langs = $this->invoke( 'validate_target_langs', [ 'es,fr' ] );

		$this->assertSame( [ 'es', 'fr' ], $langs );
	}

	public function test_validate_target_langs_trims_and_lowercases(): void {
		$langs = $this->invoke( 'validate_target_langs', [ ' ES , Fr ' ] );

		$this->assertSame( [ 'es', 'fr' ], $langs, 'sanitize_key() must lowercase and trim each code.' );
	}

	public function test_validate_target_langs_errors_on_empty_string(): void {
		try {
			$this->invoke( 'validate_target_langs', [ '' ] );
			$this->fail( 'Expected WP_CLI::error() for an empty --to value.' );
		} catch ( \WpCliTestErrorException $e ) {
			$this->assertStringContainsString( '--to=', $e->getMessage() );
		}
	}

	public function test_validate_target_langs_errors_on_unknown_code(): void {
		try {
			$this->invoke( 'validate_target_langs', [ 'xx' ] );
			$this->fail( 'Expected WP_CLI::error() for an unknown language code.' );
		} catch ( \WpCliTestErrorException $e ) {
			$this->assertStringContainsString( "'xx'", $e->getMessage() );
		}
	}

	// =========================================================================
	// register_worker_overrides_filter() / collect_worker_config_overrides()
	// =========================================================================

	public function test_worker_overrides_applies_model_max_tokens_temperature(): void {
		$this->invoke( 'register_worker_overrides_filter', [ [
			'model'       => 'claude-haiku-4-5',
			'max-tokens'  => '2048',
			'temperature' => '0.9',
		] ] );

		$base   = new WorkerConfig( model: 'claude-sonnet-5', max_tokens: 1024, temperature: 0.4 );
		$result = apply_filters( 'linguaforge_translation_worker_config', $base );

		$this->assertSame( 'claude-haiku-4-5', $result->model );
		$this->assertSame( 2048, $result->max_tokens );
		$this->assertSame( 0.9, $result->temperature );
	}

	public function test_worker_overrides_clamps_temperature_to_unit_interval(): void {
		$this->invoke( 'register_worker_overrides_filter', [ [ 'temperature' => '5' ] ] );

		$result = apply_filters( 'linguaforge_translation_worker_config', new WorkerConfig( model: 'x' ) );

		$this->assertSame( 1.0, $result->temperature, 'temperature must be clamped to a maximum of 1.0.' );
	}

	public function test_worker_overrides_warns_and_ignores_non_numeric_temperature(): void {
		$this->invoke( 'register_worker_overrides_filter', [ [ 'temperature' => 'hot' ] ] );

		$result = apply_filters( 'linguaforge_translation_worker_config', new WorkerConfig( model: 'x', temperature: 0.4 ) );

		$this->assertSame( 0.4, $result->temperature, 'Non-numeric temperature must be ignored, leaving the base config value.' );
		$this->assertNotEmpty( \WP_CLI::$warnings, 'A non-numeric --temperature must trigger a WP_CLI::warning().' );
	}

	public function test_worker_overrides_registers_no_filter_when_nothing_supplied(): void {
		$this->invoke( 'register_worker_overrides_filter', [ [] ] );

		$this->assertFalse(
			(bool) has_filter( 'linguaforge_translation_worker_config' ),
			'No overrides supplied — the filter must never be registered.'
		);
	}

	// =========================================================================
	// apply_translation() — create path (no TRID-linked post yet)
	// =========================================================================

	public function test_apply_translation_creates_linked_post_when_none_exists(): void {
		$source_id = $this->make_post( 'publish' );

		$result = $this->invoke( 'apply_translation', [
			$source_id,
			'es',
			[ 'output' => '<p>Contenido</p>', 'translated_title' => 'Título' ],
			false,
			false,
		] );

		$this->assertSame( 'created', $result['status'] );
		$target_id = $result['target_id'];
		$this->assertGreaterThan( 0, $target_id );

		$target = get_post( $target_id );
		$this->assertInstanceOf( \WP_Post::class, $target );
		$this->assertSame( '<p>Contenido</p>', $target->post_content );
		$this->assertSame( 'Título', $target->post_title );
		$this->assertSame( 'post', $target->post_type );
		$this->assertSame( 'publish', $target->post_status, 'Published source must yield a published translation.' );

		$this->assertSame( 'es', get_post_meta( $target_id, '_lf_lang', true ) );
		$source_trid = get_post_meta( $source_id, '_lf_trid', true );
		$this->assertNotSame( '', $source_trid, 'The source must be assigned a fresh TRID when it had none.' );
		$this->assertSame( $source_trid, get_post_meta( $target_id, '_lf_trid', true ) );
	}

	public function test_apply_translation_force_draft_overrides_published_source(): void {
		$source_id = $this->make_post( 'publish' );

		$result = $this->invoke( 'apply_translation', [
			$source_id, 'es', [ 'output' => 'x' ], true, false,
		] );

		$target = get_post( $result['target_id'] );
		$this->assertSame( 'draft', $target->post_status,
			'force_draft=true must always create a draft, even for a published source.' );
	}

	public function test_apply_translation_falls_back_to_draft_for_disallowed_source_status(): void {
		$source_id = $this->make_post( 'pending' );

		$result = $this->invoke( 'apply_translation', [
			$source_id, 'es', [ 'output' => 'x' ], false, false,
		] );

		$target = get_post( $result['target_id'] );
		$this->assertSame( 'draft', $target->post_status,
			"'pending' is not in the inheritable status list, so the translation must fall back to draft." );
	}

	public function test_apply_translation_reuses_existing_source_trid(): void {
		$source_id = $this->make_post( 'publish' );
		$trid      = $this->trid();
		$this->tg->set_trid( $source_id, $trid );
		$this->tg->set_lang( $source_id, 'en' );

		$result = $this->invoke( 'apply_translation', [
			$source_id, 'es', [ 'output' => 'x' ], false, false,
		] );

		$this->assertSame( $trid, get_post_meta( $result['target_id'], '_lf_trid', true ),
			'An existing source TRID must be reused, not regenerated.' );
		$this->assertSame( $trid, get_post_meta( $source_id, '_lf_trid', true ),
			'The source TRID itself must be left unchanged.' );
	}

	public function test_apply_translation_fires_translation_complete_action(): void {
		$source_id = $this->make_post( 'publish' );

		$captured = null;
		add_action( 'linguaforge_translation_complete', function ( $new_id, $src_id, $lang ) use ( &$captured ) {
			$captured = [ $new_id, $src_id, $lang ];
		}, 10, 3 );

		$result = $this->invoke( 'apply_translation', [
			$source_id, 'de', [ 'output' => 'x' ], false, false,
		] );

		$this->assertNotNull( $captured, 'linguaforge_translation_complete must fire on a successful create.' );
		$this->assertSame( [ $result['target_id'], $source_id, 'de' ], $captured );
	}

	// =========================================================================
	// apply_translation() — update path (TRID-linked post already exists)
	// =========================================================================

	public function test_apply_translation_updates_existing_linked_post(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );

		$this->tg->set_trid( $source_id, $trid );
		$this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid );
		$this->tg->set_lang( $target_id, 'es' );

		// Pre-set a stale FSE page_template so we can verify the reset-to-default guard.
		update_post_meta( $target_id, '_wp_page_template', 'single-product-es' );

		// WP core's own 'footnotes' meta key is globally sanitized on every
		// update_post_meta() call (wp-includes/blocks.php:_wp_filter_post_meta_footnotes(),
		// hooked to sanitize_post_meta_footnotes via _wp_footnotes_kses_init() on
		// 'init'/'set_current_user' whenever the current user lacks
		// unfiltered_html — true for the default logged-out user 0 in this test,
		// and for WP-CLI's default user context in real usage too). That
		// sanitizer json_decode()s the value and returns '' outright if it is
		// not a JSON array of {id, content} objects — a plain string like
		// 'Nota al pie' is silently wiped to ''. Real production values are
		// never a plain string: JsonEnvelopeTranslator::parse_full_post_envelope()
		// / TranslationMemoryTranslator always wp_json_encode() the translated
		// footnotes array before putting it in $result['footnotes']. Mirror that
		// real shape here instead of a bare string.
		$footnotes_json = (string) wp_json_encode( [ [ 'id' => 'fn1', 'content' => 'Nota al pie' ] ] );

		$result = $this->invoke( 'apply_translation', [
			$source_id,
			'es',
			[
				'output'             => '<p>Actualizado</p>',
				'translated_title'   => 'Actualizado título',
				'translated_excerpt' => 'Resumen',
				'footnotes'          => $footnotes_json,
			],
			false,
			false,
		] );

		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( $target_id, $result['target_id'] );

		$target = get_post( $target_id );
		$this->assertSame( '<p>Actualizado</p>', $target->post_content );
		$this->assertSame( 'Actualizado título', $target->post_title );
		$this->assertSame( 'Resumen', $target->post_excerpt );

		// Compare decoded structures rather than raw strings so this does not
		// depend on wp_json_encode()'s key ordering / escaping matching
		// _wp_filter_post_meta_footnotes()'s own re-encoding byte-for-byte.
		$stored = json_decode( (string) get_post_meta( $target_id, 'footnotes', true ), true );
		$this->assertSame( [ [ 'id' => 'fn1', 'content' => 'Nota al pie' ] ], $stored );
		// wp_update_post()'s 'page_template' => 'default' in $update_args resets the
		// stale 'single-product-es' value first (the invalid_page_template WP 6.7+
		// guard — see the comment on $update_args above), but that is not the final
		// state: assign_template_if_needed() runs immediately afterward and, seeing
		// _wp_page_template now at 'default', reassigns the language-specific FSE
		// slug for this post_type ('post') + lang ('es') pair — 'single-es'. That
		// re-assignment has no template_exists() guard (documented in
		// Sync::assign_template_if_needed()), so it always happens regardless of
		// whether a matching theme template file actually exists.
		$this->assertSame( 'single-es', get_post_meta( $target_id, '_wp_page_template', true ),
			'page_template must be reset off the stale WC-style slug, then reassigned to the language-specific template by assign_template_if_needed().' );
	}

	public function test_apply_translation_update_does_not_touch_trid_or_lang(): void {
		$trid      = $this->trid();
		$source_id = $this->make_post( 'publish' );
		$target_id = $this->make_post( 'publish' );

		$this->tg->set_trid( $source_id, $trid );
		$this->tg->set_lang( $source_id, 'en' );
		$this->tg->set_trid( $target_id, $trid );
		$this->tg->set_lang( $target_id, 'es' );

		$this->invoke( 'apply_translation', [ $source_id, 'es', [ 'output' => 'x' ], false, false ] );

		$this->assertSame( $trid, get_post_meta( $target_id, '_lf_trid', true ) );
		$this->assertSame( 'es', get_post_meta( $target_id, '_lf_lang', true ) );
	}

	// =========================================================================
	// dump_debug_files()
	// =========================================================================

	public function test_dump_debug_files_prints_newest_matching_files(): void {
		$this->use_temp_debug_dir();

		TranslationDebug::debug_write( 42, 'es', 'source', 'PROMPT-BODY' );
		TranslationDebug::debug_write( 42, 'es', 'response', 'RESPONSE-BODY' );

		$this->invoke( 'dump_debug_files', [ 42, 'es' ] );

		$dumped = implode( "\n", \WP_CLI::$logs );
		$this->assertStringContainsString( 'PROMPT-BODY', $dumped );
		$this->assertStringContainsString( 'RESPONSE-BODY', $dumped );
	}

	public function test_dump_debug_files_skips_files_older_than_60_seconds(): void {
		$this->use_temp_debug_dir();

		TranslationDebug::debug_write( 7, 'fr', 'source', 'STALE-BODY' );

		// Back-date the file we just wrote past the 60s "this run" freshness window.
		$files = glob( $this->debug_tmp_dir . '/7-fr-*-source.txt' );
		$this->assertNotEmpty( $files, 'Fixture debug file must exist before back-dating it.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test-only: back-dating a fixture file's mtime in a sys_get_temp_dir() path to exercise the 60s freshness guard.
		touch( $files[0], time() - 120 );

		$this->invoke( 'dump_debug_files', [ 7, 'fr' ] );

		$this->assertStringNotContainsString( 'STALE-BODY', implode( "\n", \WP_CLI::$logs ),
			'A debug file older than 60s must be treated as belonging to a previous run and skipped.' );
	}

	private function use_temp_debug_dir(): void {
		$this->debug_tmp_dir = rtrim( sys_get_temp_dir(), '/' ) . '/lf-test-debug-' . uniqid( '', true );
		add_filter( 'linguaforge_debug_dir', fn(): string => $this->debug_tmp_dir );
	}
}
