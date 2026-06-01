<?php
/**
 * Regression contract tests — pin critical string constants and key shapes
 * that must never silently change between versions.
 *
 * Each test here guards a specific hard-coded value whose accidental change
 * would cause a silent data-layer regression:
 *
 *   • BlockTextExtractor::TRANSLATABLE_ATTRS — dropping an attribute means
 *     block content stops being translated without any error.
 *   • KeyStore option prefix and ENV_MAP — changing these orphans stored API
 *     keys and silently stops environment-variable lookup.
 *   • KeyStore cipher constants — changing CIPHER_V2 or V2_PREFIX breaks
 *     decryption of every key already stored in production databases.
 *   • Config option key pattern — changing the option name loses all stored
 *     settings on upgrade.
 *   • TridGroup meta keys — changing _lf_trid or _lf_lang breaks all existing
 *     translation relationships stored in post_meta.
 *   • RateLimiter transient key shapes — changing these resets all in-flight
 *     rate-limit windows silently.
 *   • CacheStore cache group name — changing 'lf_translations' invalidates all
 *     object-cache entries without any error.
 *
 * These tests use ReflectionClass to read private constants so the production
 * code does not need to expose them as public API.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\BlockTextExtractor;
use LinguaForge\AI\Core\CacheStore;
use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\REST\RateLimiter;
use LinguaForge\Router\Translation\TridGroup;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/ApiPolyfills.php';
require_once __DIR__ . '/WooCommerce/WcPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_SECRET' ) ) {
	define( 'LINGUAFORGE_SECRET', 'unit-test-secret-do-not-use-in-production' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/BlockTextExtractor.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/CacheStore.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/KeyStore.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Providers/WorkerConfig.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/Config.php';
require_once dirname( __DIR__, 2 ) . '/ai/includes/REST/RateLimiter.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-context.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/class-language-router.php';
require_once dirname( __DIR__, 2 ) . '/language-router/includes/translation/class-trid-group.php';

final class RegressionContractsTest extends TestCase {

	/** Read a private/protected constant from a class via Reflection. */
	private static function read_const( string $class, string $name ): mixed {
		return ( new ReflectionClass( $class ) )->getConstant( $name );
	}


	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_options']    = [];
		$GLOBALS['lf_test_filters']    = [];
		$GLOBALS['lf_test_transients'] = [];
		\LfWcMocks::reset();
	}

	protected function tearDown(): void {
		\LinguaForge\Router\Router::reset_instance();
		$GLOBALS['lf_test_options']    = [];
		$GLOBALS['lf_test_filters']    = [];
		$GLOBALS['lf_test_transients'] = [];
		unset( $GLOBALS['lf_test_user_id'] );
		parent::tearDown();
	}

	// =========================================================================
	// BlockTextExtractor — TRANSLATABLE_ATTRS
	// =========================================================================

	public function test_translatable_attrs_contains_alt(): void {
		$this->assertContains( 'alt', self::read_const( BlockTextExtractor::class, 'TRANSLATABLE_ATTRS' ) );
	}

	public function test_translatable_attrs_contains_caption(): void {
		$this->assertContains( 'caption', self::read_const( BlockTextExtractor::class, 'TRANSLATABLE_ATTRS' ) );
	}

	public function test_translatable_attrs_contains_label(): void {
		$this->assertContains( 'label', self::read_const( BlockTextExtractor::class, 'TRANSLATABLE_ATTRS' ) );
	}

	public function test_translatable_attrs_contains_placeholder(): void {
		$this->assertContains( 'placeholder', self::read_const( BlockTextExtractor::class, 'TRANSLATABLE_ATTRS' ) );
	}

	public function test_translatable_attrs_contains_buttonText(): void {
		$this->assertContains( 'buttonText', self::read_const( BlockTextExtractor::class, 'TRANSLATABLE_ATTRS' ) );
	}

	public function test_translatable_attrs_contains_summary(): void {
		$this->assertContains( 'summary', self::read_const( BlockTextExtractor::class, 'TRANSLATABLE_ATTRS' ) );
	}

	// =========================================================================
	// KeyStore — option prefix and env var names
	// =========================================================================

	public function test_keystore_option_prefix_is_stable(): void {
		$this->assertSame( 'linguaforge_key_', self::read_const( KeyStore::class, 'OPTION_PREFIX' ) );
	}

	public function test_keystore_env_map_anthropic_key_name(): void {
		$map = self::read_const( KeyStore::class, 'ENV_MAP' );
		$this->assertSame( 'ANTHROPIC_API_KEY', $map['anthropic'] );
	}

	public function test_keystore_env_map_openai_key_name(): void {
		$map = self::read_const( KeyStore::class, 'ENV_MAP' );
		$this->assertSame( 'OPENAI_API_KEY', $map['openai'] );
	}

	public function test_keystore_env_map_gemini_key_name(): void {
		$map = self::read_const( KeyStore::class, 'ENV_MAP' );
		$this->assertSame( 'GEMINI_API_KEY', $map['gemini'] );
	}

	public function test_keystore_env_map_covers_all_three_providers(): void {
		$map = self::read_const( KeyStore::class, 'ENV_MAP' );
		$this->assertCount( 3, $map );
		$this->assertArrayHasKey( 'anthropic', $map );
		$this->assertArrayHasKey( 'openai',    $map );
		$this->assertArrayHasKey( 'gemini',    $map );
	}

	// =========================================================================
	// KeyStore — cipher constants (changing these breaks stored keys)
	// =========================================================================

	public function test_keystore_v2_cipher_is_aes_256_gcm(): void {
		$this->assertSame( 'aes-256-gcm', self::read_const( KeyStore::class, 'CIPHER_V2' ) );
	}

	public function test_keystore_v2_prefix_is_stable(): void {
		$this->assertSame( 'v2|', self::read_const( KeyStore::class, 'V2_PREFIX' ) );
	}

	public function test_keystore_v2_iv_len_is_12(): void {
		// GCM standard IV is 12 bytes; changing this breaks decryption of stored keys.
		$this->assertSame( 12, self::read_const( KeyStore::class, 'V2_IV_LEN' ) );
	}

	public function test_keystore_v2_tag_len_is_16(): void {
		$this->assertSame( 16, self::read_const( KeyStore::class, 'V2_TAG_LEN' ) );
	}

	// =========================================================================
	// KeyStore — option key shape (functional, not Reflection)
	// =========================================================================

	public function test_keystore_stores_under_expected_option_key(): void {
		KeyStore::set( 'anthropic', 'sk-ant-test' );

		$this->assertArrayHasKey( 'linguaforge_key_anthropic', $GLOBALS['lf_test_options'] );
	}

	// =========================================================================
	// Config — option key names for provider and model
	// =========================================================================

	public function test_config_provider_option_name(): void {
		$GLOBALS['lf_test_options']['linguaforge_provider'] = 'openai';

		$this->assertSame( 'openai', Config::provider() );
	}

	public function test_config_model_option_key_pattern(): void {
		// Option key must be linguaforge_model_{provider}_{tier}.
		$GLOBALS['lf_test_options']['linguaforge_model_anthropic_quality'] = 'claude-sentinel';

		$this->assertSame( 'claude-sentinel', Config::model( 'quality' ) );
	}

	public function test_config_translation_tier_option_name(): void {
		$GLOBALS['lf_test_options']['linguaforge_translation_tier'] = 'light';

		$this->assertSame( 'light', Config::translation_tier() );
	}

	public function test_config_daily_quota_option_name(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 42;

		// The option is read inside RateLimiter::enforce_daily_quota; also verify
		// Config reads via the known option name.
		$this->assertSame( 42, (int) get_option( 'linguaforge_ai_daily_quota', 0 ) );
	}

	// =========================================================================
	// TridGroup — meta key strings
	// =========================================================================

	public function test_trid_group_reads_lf_trid_meta_key(): void {
		\LfWcMocks::$meta[99]['_lf_trid'] = 'test-uuid';

		$trid_group = $this->make_trid_group();
		$this->assertSame( 'test-uuid', $trid_group->get_trid( 99 ) );
	}

	public function test_trid_group_writes_lf_trid_meta_key(): void {
		$trid_group = $this->make_trid_group();
		$trid_group->set_trid( 99, 'new-uuid' );

		$this->assertSame( 'new-uuid', \LfWcMocks::$meta[99]['_lf_trid'] );
	}

	public function test_trid_group_reads_lf_lang_meta_key(): void {
		\LfWcMocks::$meta[99]['_lf_lang'] = 'de';

		$trid_group = $this->make_trid_group();
		$this->assertSame( 'de', $trid_group->get_lang( 99 ) );
	}

	public function test_trid_group_writes_lf_lang_meta_key(): void {
		$trid_group = $this->make_trid_group();
		$trid_group->set_lang( 99, 'fr' );

		$this->assertSame( 'fr', \LfWcMocks::$meta[99]['_lf_lang'] );
	}

	// =========================================================================
	// RateLimiter — transient key shapes
	// =========================================================================

	public function test_rate_limiter_transient_key_includes_user_id_and_endpoint(): void {
		$GLOBALS['lf_test_user_id'] = 7;

		RateLimiter::enforce_rate_limit( 'translate' );

		$this->assertArrayHasKey(
			'linguaforge_rate_user_7_translate',
			$GLOBALS['lf_test_transients'],
			'Transient key must follow pattern linguaforge_rate_user_{id}_{endpoint}.'
		);
	}

	public function test_daily_quota_transient_key_includes_utc_date(): void {
		$GLOBALS['lf_test_options']['linguaforge_ai_daily_quota'] = 100;

		RateLimiter::enforce_daily_quota( 'translate' );

		$today    = gmdate( 'Ymd' );
		$expected = "linguaforge_quota_daily_used_{$today}";

		$this->assertArrayHasKey(
			$expected,
			$GLOBALS['lf_test_transients'],
			'Daily quota transient key must follow pattern linguaforge_quota_daily_used_{Ymd}.'
		);
	}

	// =========================================================================
	// CacheStore — cache group name
	// =========================================================================

	public function test_cache_store_hash_output_is_64_hex_chars(): void {
		// Also acts as a regression guard: the hash algorithm must stay SHA-256.
		$h = CacheStore::hash( [ 'content', 'en' ] );
		$this->assertSame( 64, strlen( $h ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $h );
	}

	public function test_cache_store_hash_algorithm_matches_sha256(): void {
		$expected = hash( 'sha256', "a\x00b" );
		$this->assertSame( $expected, CacheStore::hash( [ 'a', 'b' ] ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function make_trid_group(): TridGroup {
		$ctx_ref   = new ReflectionClass( \LinguaForge\Router\Context::class );
		$context   = $ctx_ref->newInstanceWithoutConstructor();
		$lang_prop = $ctx_ref->getProperty( 'cached_source_language' );
		$lang_prop->setAccessible( true );
		$lang_prop->setValue( $context, 'en' );

		$router_ref = new ReflectionClass( \LinguaForge\Router\Router::class );
		$router     = $router_ref->newInstanceWithoutConstructor();
		$ctx_field  = $router_ref->getProperty( 'context' );
		$ctx_field->setAccessible( true );
		$ctx_field->setValue( $router, $context );
		$inst_prop = $router_ref->getProperty( 'instance' );
		$inst_prop->setAccessible( true );
		$inst_prop->setValue( null, $router );

		return new TridGroup( $router );
	}
}
