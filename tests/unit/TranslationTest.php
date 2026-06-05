<?php
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- polyfill functions and the test class must coexist in this file; same pattern as ApiPolyfills.php.
/**
 * Unit tests for LinguaForge\AI\Features\Translation.
 *
 * Covers four private pure-logic helpers (all fully testable without WP stubs):
 *
 *   • build_translation_schema()   — static schema builder.
 *   • parse_full_post_envelope()   — JSON decode + field extraction.
 *   • build_system_prompt()        — pure prompt construction (compliance addendum
 *                                    and glossary text passed in as plain strings).
 *   • prepare_full_post_inputs()   — pure content preparation (post fields,
 *                                    source_lang, prompt template, and max_input
 *                                    all passed in as plain scalars).
 *
 * Private methods are accessed via \ReflectionMethod::setAccessible().
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use LinguaForge\AI\Features\Translation;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

// WcPolyfills must load before ApiPolyfills so that is_admin(), get_post_meta(),
// and get_locale() come from WcPolyfills (controlled via LfWcMocks). ApiPolyfills
// function_exists() guards then skip re-defining those three, and only add the
// non-WC-overlapping stubs (WP_Screen, get_current_screen, is_singular, etc.).
require_once __DIR__ . '/WooCommerce/WcPolyfills.php';
require_once __DIR__ . '/ApiPolyfills.php';

// Real source classes the methods under test depend on.
require_once LINGUAFORGE_AI_PATH . '/includes/Core/JsonRepair.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Core/BlockTextExtractor.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/WorkerConfig.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/Contracts/FeatureInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/TranslationMemoryTranslator.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/JsonEnvelopeTranslator.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Features/Translation.php';

// ---------------------------------------------------------------------------

use LinguaForge\AI\Features\JsonEnvelopeTranslator;

/**
 * @covers \LinguaForge\AI\Features\Translation
 * @covers \LinguaForge\AI\Features\JsonEnvelopeTranslator
 */
final class TranslationTest extends TestCase {

	// =========================================================================
	// Helpers
	// =========================================================================

	private function callPrivate( object|string $subject, string $method, array $args = [] ): mixed {
		$rm = new ReflectionMethod( $subject, $method );
		$rm->setAccessible( true );
		return $rm->invokeArgs( is_object( $subject ) ? $subject : null, $args );
	}

	private function makeTranslation(): Translation {
		return new Translation();
	}

	/**
	 * Minimal prompt template — covers all five placeholders used by
	 * prepare_full_post_inputs() without dragging in the real .txt file.
	 */
	private function makePromptTemplate(): string {
		return "Language: {{language}}\nTitle: {{title}}\nContent: {{content}}{{extra_output}}{{extra_output_doc}}";
	}

	// =========================================================================
	// build_translation_schema()
	// =========================================================================

	public function test_schema_baseline_has_title_and_content_only(): void {

		$schema = JsonEnvelopeTranslator::build_translation_schema( false, false, false );

		$this->assertSame( [ 'title', 'content' ], $schema['required'] );
		$this->assertArrayHasKey( 'title',   $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayNotHasKey( 'footnotes', $schema['properties'] );
		$this->assertArrayNotHasKey( 'attrs',     $schema['properties'] );
		$this->assertArrayNotHasKey( 'excerpt',   $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_schema_includes_footnotes_when_flag_set(): void {

		$schema = JsonEnvelopeTranslator::build_translation_schema( true, false, false );

		$this->assertContains( 'footnotes', $schema['required'] );
		$this->assertArrayHasKey( 'footnotes', $schema['properties'] );
		$this->assertSame( 'array', $schema['properties']['footnotes']['type'] );
	}

	public function test_schema_includes_attrs_when_flag_set(): void {

		$schema = JsonEnvelopeTranslator::build_translation_schema( false, true, false );

		$this->assertContains( 'attrs', $schema['required'] );
		$this->assertArrayHasKey( 'attrs', $schema['properties'] );
		$this->assertSame( 'object', $schema['properties']['attrs']['type'] );
	}

	public function test_schema_includes_excerpt_when_flag_set(): void {

		$schema = JsonEnvelopeTranslator::build_translation_schema( false, false, true );

		$this->assertContains( 'excerpt', $schema['required'] );
		$this->assertArrayHasKey( 'excerpt', $schema['properties'] );
	}

	public function test_schema_all_flags_produces_five_properties(): void {

		$schema = JsonEnvelopeTranslator::build_translation_schema( true, true, true );

		// title + content + excerpt + footnotes + attrs = 5 required fields.
		$this->assertCount( 5, $schema['required'] );
		$this->assertSame(
			[ 'title', 'content', 'excerpt', 'footnotes', 'attrs' ],
			array_keys( $schema['properties'] )
		);
	}

	public function test_schema_footnotes_item_requires_id_and_content(): void {

		$schema = JsonEnvelopeTranslator::build_translation_schema( true, false );
		$items  = $schema['properties']['footnotes']['items'];

		$this->assertContains( 'id',      $items['required'] );
		$this->assertContains( 'content', $items['required'] );
		$this->assertFalse( $items['additionalProperties'] );
	}

	// =========================================================================
	// parse_full_post_envelope()
	// =========================================================================

	/** @param array<string,mixed> $overrides */
	private function makeCtx( array $overrides = [] ): array {
		return array_merge(
			[
				'has_footnotes' => false,
				'has_attrs'     => false,
				'has_excerpt'   => false,
				'language_name' => 'German',
				'attr_map'      => [],
			],
			$overrides
		);
	}

	public function test_parse_returns_error_for_invalid_json(): void {

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( 'this is not json', 1, $this->makeCtx() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'unparseable', $result['error'] );
	}

	public function test_parse_detects_truncated_response(): void {

		// Starts with '{' but does not end with '}' — triggers truncation hint.
		$truncated = '{"title":"Hallo","content":"Inh';

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $truncated, 1, $this->makeCtx() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'truncated',        $result['error'] );
		$this->assertStringContainsString( 'Max output tokens', $result['error'] );
	}

	public function test_parse_returns_error_for_empty_content(): void {

		$json = wp_json_encode( [ 'title' => 'Hallo', 'content' => '   ' ] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'empty', $result['error'] );
	}

	public function test_parse_happy_path_title_and_content(): void {

		$json = wp_json_encode( [
			'title'   => 'Hallo Welt',
			'content' => '<!-- wp:paragraph --><p>Inhalt</p><!-- /wp:paragraph -->',
		] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx() );

		$this->assertSame( '<!-- wp:paragraph --><p>Inhalt</p><!-- /wp:paragraph -->', $result['output'] );
		$this->assertSame( 'content', $result['type'] );
		$this->assertSame( 'German',  $result['language'] );
		$this->assertSame( 'Hallo Welt', $result['translated_title'] );
		$this->assertArrayNotHasKey( 'footnotes',          $result );
		$this->assertArrayNotHasKey( 'translated_excerpt', $result );
	}

	public function test_parse_omits_translated_title_when_missing_from_envelope(): void {

		$json = wp_json_encode( [ 'content' => '<p>Text</p>' ] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx() );

		$this->assertSame( '<p>Text</p>', $result['output'] );
		$this->assertArrayNotHasKey( 'translated_title', $result );
	}

	public function test_parse_includes_footnotes_when_flag_and_envelope_match(): void {

		$footnotes = [ [ 'id' => 'fn-1', 'content' => 'Fussnote eins' ] ];
		$json      = wp_json_encode( [
			'title'     => 'T',
			'content'   => '<p>Body</p>',
			'footnotes' => $footnotes,
		] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx( [ 'has_footnotes' => true ] ) );

		$this->assertArrayHasKey( 'footnotes', $result );
		$decoded = json_decode( $result['footnotes'], true );
		$this->assertSame( 'fn-1',          $decoded[0]['id'] );
		$this->assertSame( 'Fussnote eins', $decoded[0]['content'] );
	}

	public function test_parse_skips_footnotes_when_flag_false_even_if_present_in_envelope(): void {

		$json = wp_json_encode( [
			'title'     => 'T',
			'content'   => '<p>Body</p>',
			'footnotes' => [ [ 'id' => 'fn-1', 'content' => 'ignored' ] ],
		] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx( [ 'has_footnotes' => false ] ) );

		$this->assertArrayNotHasKey( 'footnotes', $result );
	}

	public function test_parse_reinserts_attrs_when_flag_set(): void {

		$content_with_placeholder = '<!-- wp:image {"alt":"__WPAI_0__"} --><figure></figure><!-- /wp:image -->';
		$json = wp_json_encode( [
			'title'   => 'T',
			'content' => $content_with_placeholder,
			'attrs'   => [ '__WPAI_0__' => 'Eine Katze' ],
		] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx( [ 'has_attrs' => true ] ) );

		$this->assertStringContainsString(    'Eine Katze',  $result['output'] );
		$this->assertStringNotContainsString( '__WPAI_0__', $result['output'] );
	}

	public function test_parse_includes_excerpt_when_flag_set_and_present(): void {

		$json = wp_json_encode( [
			'title'   => 'T',
			'content' => '<p>Body</p>',
			'excerpt' => 'Kurzbeschreibung',
		] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx( [ 'has_excerpt' => true ] ) );

		$this->assertArrayHasKey( 'translated_excerpt', $result );
		$this->assertSame( 'Kurzbeschreibung', $result['translated_excerpt'] );
	}

	public function test_parse_strips_interblock_br_from_content(): void {

		$json = wp_json_encode( [
			'title'   => 'T',
			'content' => "<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->\n<br>\n<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->",
		] );

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $json, 1, $this->makeCtx() );

		$this->assertStringNotContainsString( '<br>',     $result['output'] );
		$this->assertStringContainsString(    '<p>A</p>', $result['output'] );
		$this->assertStringContainsString(    '<p>B</p>', $result['output'] );
	}

	/** JsonRepair strips Markdown code fences before decode. */
	public function test_parse_handles_markdown_fenced_json(): void {

		$fenced = "```json\n{\"title\":\"T\",\"content\":\"<p>C</p>\"}\n```";

		$result = JsonEnvelopeTranslator::parse_full_post_envelope( $fenced, 1, $this->makeCtx() );

		$this->assertSame( '<p>C</p>', $result['output'] );
		$this->assertSame( 'T',       $result['translated_title'] );
	}

	// =========================================================================
	// build_system_prompt()  — pure since 2.1.6: caller resolves WP values
	// =========================================================================
	//
	// Signature: build_system_prompt(
	//     string $compliance_addendum,
	//     string $glossary_text,
	//     string $extra_instruction = ''
	// ): string

	public function test_build_system_prompt_contains_core_instructions(): void {

		$prompt = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ '', '', '' ]
		);

		$this->assertStringContainsString( 'professional translator', $prompt );
		$this->assertStringContainsString( 'CRITICAL JSON RULE',      $prompt );
		$this->assertStringContainsString( 'block comments',          $prompt );
	}

	public function test_build_system_prompt_appends_extra_instruction_before_json_rule(): void {

		$extra  = 'Return only the requested fields.';
		$prompt = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ '', '', $extra ]
		);

		$this->assertStringContainsString( $extra, $prompt );
		$this->assertLessThan(
			strpos( $prompt, 'CRITICAL JSON RULE' ),
			strpos( $prompt, $extra ),
			'Extra instruction must precede the CRITICAL JSON RULE.'
		);
	}

	/** Without an extra instruction the prompt must still contain the CRITICAL JSON RULE. */
	public function test_build_system_prompt_without_extra_still_has_json_rule(): void {

		$prompt = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ '', '' ]
		);

		$this->assertStringContainsString( 'CRITICAL JSON RULE', $prompt );
		$this->assertStringNotContainsString( 'Return only the requested fields', $prompt );
	}

	public function test_build_system_prompt_appends_glossary_when_non_empty(): void {

		$glossary = "Mandatory terminology — apply these substitutions exactly:\n- invoice → Rechnung\n- receipt → Quittung";
		$prompt   = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ '', $glossary, '' ]
		);

		$this->assertStringContainsString( 'Mandatory terminology', $prompt );
		$this->assertStringContainsString( 'Rechnung', $prompt );
		$this->assertStringContainsString( 'Quittung', $prompt );
		// Glossary must appear AFTER the base instructions.
		$this->assertGreaterThan(
			strpos( $prompt, 'CRITICAL JSON RULE' ),
			strpos( $prompt, 'Mandatory terminology' )
		);
	}

	public function test_build_system_prompt_no_glossary_section_when_empty_string(): void {

		$prompt = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ '', '', '' ]
		);

		$this->assertStringNotContainsString( 'Mandatory terminology', $prompt );
	}

	public function test_build_system_prompt_appends_compliance_addendum(): void {

		$addendum = 'Always use formal address (Sie) when translating to German.';
		$prompt   = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ $addendum, '', '' ]
		);

		$this->assertStringContainsString( $addendum, $prompt );
		// Addendum must appear AFTER the base instruction block.
		$this->assertGreaterThan(
			strpos( $prompt, 'CRITICAL JSON RULE' ),
			strpos( $prompt, $addendum )
		);
	}

	public function test_build_system_prompt_no_compliance_addendum_when_empty_string(): void {

		$addendum = '';
		$prompt   = $this->callPrivate(
			$this->makeTranslation(),
			'build_system_prompt',
			[ $addendum, '', '' ]
		);

		// With an empty addendum the prompt must still be a single coherent block.
		$this->assertStringContainsString( 'CRITICAL JSON RULE', $prompt );
		// No double newlines at the seam where the addendum would be appended.
		$this->assertStringNotContainsString( "\n\n\n", $prompt );
	}

	// =========================================================================
	// prepare_full_post_inputs()  — pure since 2.1.6: caller passes resolved scalars
	// =========================================================================
	//
	// Signature: prepare_full_post_inputs(
	//     string $title, string $content, string $excerpt, string $source_lang,
	//     string $footnotes_raw, string $language_name,
	//     string $prompt_template, int $max_input, int $post_id = 0
	// ): array

	/** Call prepare_full_post_inputs with sensible defaults, allowing overrides. */
	private function callPrepare( array $overrides = [] ): array {
		$defaults = [
			'title'           => 'Hello World',
			'content'         => '<p>Simple content</p>',
			'excerpt'         => '',
			'source_lang'     => 'en',
			'footnotes_raw'   => '',
			'language_name'   => 'German',
			'prompt_template' => $this->makePromptTemplate(),
			'max_input'       => 0,
			'post_id'         => 1,
		];
		$p = array_merge( $defaults, $overrides );
		return $this->callPrivate(
			$this->makeTranslation(),
			'prepare_full_post_inputs',
			[
				$p['title'], $p['content'], $p['excerpt'], $p['source_lang'],
				$p['footnotes_raw'], $p['language_name'],
				$p['prompt_template'], $p['max_input'], $p['post_id'],
			]
		);
	}

	public function test_prepare_returns_success_for_simple_post(): void {

		$ctx = $this->callPrepare();

		$this->assertTrue( $ctx['_success'] );
		$this->assertSame( 'en', $ctx['source_lang'] );
		$this->assertFalse( $ctx['has_footnotes'] );
		$this->assertFalse( $ctx['has_attrs'] );
		$this->assertFalse( $ctx['has_excerpt'] );
	}

	public function test_prepare_prompt_contains_language_title_and_content(): void {

		$ctx = $this->callPrepare( [
			'title'   => 'My Post',
			'content' => '<p>Body text</p>',
		] );

		$this->assertStringContainsString( 'German',    $ctx['prompt'] );
		$this->assertStringContainsString( 'My Post',   $ctx['prompt'] );
		$this->assertStringContainsString( 'Body text', $ctx['prompt'] );
	}

	public function test_prepare_detects_non_empty_excerpt(): void {

		$ctx = $this->callPrepare( [ 'excerpt' => 'Short description here.' ] );

		$this->assertTrue( $ctx['has_excerpt'] );
		$this->assertStringContainsString( 'Short description here.', $ctx['prompt'] );
	}

	public function test_prepare_excerpt_empty_string_not_detected(): void {

		$ctx = $this->callPrepare( [ 'excerpt' => '   ' ] );

		$this->assertFalse( $ctx['has_excerpt'] );
	}

	public function test_prepare_detects_footnotes_from_valid_json_array(): void {

		$footnotes = wp_json_encode( [ [ 'id' => 'fn-1', 'content' => 'A note.' ] ] );
		$ctx       = $this->callPrepare( [ 'footnotes_raw' => $footnotes ] );

		$this->assertTrue( $ctx['has_footnotes'] );
		$this->assertStringContainsString( 'fn-1', $ctx['prompt'] );
	}

	public function test_prepare_ignores_empty_footnotes_json_array(): void {

		$ctx = $this->callPrepare( [ 'footnotes_raw' => '[]' ] );

		$this->assertFalse( $ctx['has_footnotes'] );
	}

	public function test_prepare_detects_block_attrs_and_replaces_with_placeholders(): void {

		$content = '<!-- wp:image {"alt":"A cat"} --><figure></figure><!-- /wp:image -->';
		$ctx     = $this->callPrepare( [ 'content' => $content ] );

		$this->assertTrue( $ctx['has_attrs'] );
		$this->assertNotEmpty( $ctx['attr_map'] );
		// Placeholder must appear in the content sent to the AI, not the original text.
		$this->assertStringContainsString( '__WPAI_', $ctx['content_to_translate'] );
		$this->assertStringNotContainsString( 'A cat', $ctx['content_to_translate'] );
	}

	public function test_prepare_applies_max_input_cap(): void {

		$long_content = str_repeat( 'x', 200 );
		$ctx          = $this->callPrepare( [ 'content' => $long_content, 'max_input' => 100 ] );

		$this->assertSame( 100, mb_strlen( $ctx['content_to_translate'] ) );
	}

	public function test_prepare_no_cap_when_max_input_zero(): void {

		$long_content = str_repeat( 'x', 200 );
		$ctx          = $this->callPrepare( [ 'content' => $long_content, 'max_input' => 0 ] );

		$this->assertSame( 200, mb_strlen( $ctx['content_to_translate'] ) );
	}

	public function test_prepare_source_lang_propagated_to_context(): void {

		$ctx = $this->callPrepare( [ 'source_lang' => 'ca' ] );

		$this->assertSame( 'ca', $ctx['source_lang'] );
	}

	// =========================================================================
	// run() — early-exit branches (unit-testable without WP runtime)
	// =========================================================================

	public function test_run_returns_error_for_invalid_target_language(): void {

		$result = $this->makeTranslation()->run( 1, [ 'target_language' => 'xx' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Invalid target language', $result['error'] );
	}

	public function test_run_returns_error_when_post_not_found(): void {

		// LfWcMocks::$posts is empty after setUp → get_post() returns null.
		$result = $this->makeTranslation()->run( 999, [ 'target_language' => 'de' ] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Post not found', $result['error'] );
	}

	// =========================================================================
	// detect_post_language()
	// =========================================================================
	//
	// Five logical paths:
	//   A. Admin, screen base = 'post', valid _lf_lang meta   → returns lang
	//   B. Admin, screen base = 'post', Polylang function     → returns lang
	//   C. Admin, screen base = 'post', locale fallback       → returns 2-char code
	//   D. Admin, screen base = 'post', no lang resolvable    → returns null
	//   E. Admin, screen base ≠ 'post'                        → returns null
	//   F. Not admin, is_singular, valid _lf_lang meta        → returns lang
	//   G. Not admin, not singular                            → returns null
	//   H. Not admin, is_singular, get_queried_object_id = 0  → returns null

	protected function setUp(): void {
		parent::setUp();
		// Reset all LfWcMocks state (covers is_admin, get_post_meta, get_locale)
		// and the globals used by ApiPolyfills stubs (get_current_screen,
		// is_singular, get_queried_object_id).
		\LfWcMocks::reset();
		unset( $GLOBALS['lf_test_screen_base'] );
		$GLOBALS['lf_test_is_singular']       = false;
		$GLOBALS['lf_test_queried_object_id'] = 0;
		$GLOBALS['lf_test_locale']            = 'en_US';
		unset( $GLOBALS['post'] );
	}

	public function test_detect_admin_post_screen_returns_lf_lang_meta(): void {

		$post     = new \WP_Post();
		$post->ID = 42;
		$GLOBALS['post']               = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test bootstrap; $post must be set to simulate an admin post-edit screen without a real WP runtime.
		\LfWcMocks::$is_admin          = true;
		$GLOBALS['lf_test_screen_base'] = 'post';
		\LfWcMocks::$meta[42]['_lf_lang'] = 'de';

		$this->assertSame( 'de', Translation::detect_post_language() );
	}

	public function test_detect_admin_post_screen_falls_back_to_locale(): void {

		$post     = new \WP_Post();
		$post->ID = 7;
		$GLOBALS['post']                = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test bootstrap; see above.
		\LfWcMocks::$is_admin           = true;
		$GLOBALS['lf_test_screen_base'] = 'post';
		// No _lf_lang meta; no pll_get_post_language defined.
		$GLOBALS['lf_test_locale']      = 'de_DE';

		$this->assertSame( 'de', Translation::detect_post_language() );
	}

	public function test_detect_admin_post_screen_returns_null_for_unknown_locale(): void {

		$post     = new \WP_Post();
		$post->ID = 7;
		$GLOBALS['post']                = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test bootstrap; see above.
		\LfWcMocks::$is_admin           = true;
		$GLOBALS['lf_test_screen_base'] = 'post';
		$GLOBALS['lf_test_locale']      = 'xx_XX'; // not in LANGUAGES

		$this->assertNull( Translation::detect_post_language() );
	}

	public function test_detect_admin_non_post_screen_returns_null(): void {

		\LfWcMocks::$is_admin           = true;
		$GLOBALS['lf_test_screen_base'] = 'edit'; // list table — no single $post

		$this->assertNull( Translation::detect_post_language() );
	}

	public function test_detect_admin_null_screen_returns_null(): void {

		\LfWcMocks::$is_admin = true;
		// lf_test_screen_base already unset in setUp → get_current_screen() returns null

		$this->assertNull( Translation::detect_post_language() );
	}

	public function test_detect_admin_post_screen_no_wp_post_global_returns_null(): void {

		\LfWcMocks::$is_admin           = true;
		$GLOBALS['lf_test_screen_base'] = 'post';
		$GLOBALS['post']                = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test bootstrap; $post must be null to simulate missing global post.

		$this->assertNull( Translation::detect_post_language() );
	}

	public function test_detect_frontend_singular_returns_lf_lang_meta(): void {

		$GLOBALS['lf_test_is_singular']       = true;
		$GLOBALS['lf_test_queried_object_id'] = 55;
		\LfWcMocks::$meta[55]['_lf_lang']     = 'fr';

		$this->assertSame( 'fr', Translation::detect_post_language() );
	}

	public function test_detect_frontend_singular_zero_object_id_returns_null(): void {

		$GLOBALS['lf_test_is_singular']       = true;
		$GLOBALS['lf_test_queried_object_id'] = 0;

		$this->assertNull( Translation::detect_post_language() );
	}

	public function test_detect_frontend_non_singular_returns_null(): void {

		// lf_test_is_singular already false in setUp
		$this->assertNull( Translation::detect_post_language() );
	}
}
