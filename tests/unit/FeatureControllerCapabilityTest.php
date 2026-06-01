<?php
/**
 * Unit tests for FeatureController::required_capability().
 *
 * Asserts that required_capability() never returns an empty string for any
 * known feature slug or endpoint, ensuring current_user_can() is never called
 * with '' (which resolves to "anyone can" in WordPress).
 *
 * Covers audit §3.2: exhaustiveness check on the required_capability() safety net.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/ApiPolyfills.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/ai/includes/REST/FeatureController.php';

final class FeatureControllerCapabilityTest extends TestCase {

	private ReflectionMethod $method;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['lf_test_options'] = [];
		$GLOBALS['lf_test_filters'] = [];

		$this->method = new ReflectionMethod(
			\LinguaForge\AI\REST\FeatureController::class,
			'required_capability'
		);
		$this->method->setAccessible( true );
	}

	protected function tearDown(): void {
		$GLOBALS['lf_test_options'] = [];
		$GLOBALS['lf_test_filters'] = [];
		parent::tearDown();
	}

	// =========================================================================
	// Helper
	// =========================================================================

	private function cap( string $context ): string {
		return (string) $this->method->invoke( null, $context );
	}

	// =========================================================================
	// All registered feature slugs return a non-empty capability
	// =========================================================================

	/**
	 * @dataProvider feature_slugs
	 */
	public function test_required_capability_is_never_empty_for_feature_slugs( string $slug ): void {
		$cap = $this->cap( $slug );

		$this->assertNotSame( '', $cap, "required_capability('$slug') must never return ''" );
		$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $cap, "capability '$cap' must be a valid WP capability name" );
	}

	/** @return array<string, array{string}> */
	public static function feature_slugs(): array {
		// All keys registered by Registry::init() plus the three
		// dedicated endpoint slugs passed in FeatureController::register_routes().
		return [
			'translation'      => [ 'translation' ],
			'meta-description' => [ 'meta-description' ],
			'excerpt'          => [ 'excerpt' ],
			'content-generator'=> [ 'content-generator' ],
			'translate-chunk'  => [ 'translate-chunk' ],
			'create-chunk'     => [ 'create-chunk' ],
			'revise-block'     => [ 'revise-block' ],
		];
	}

	// =========================================================================
	// Unknown / garbage slugs still return a non-empty capability
	// =========================================================================

	public function test_required_capability_is_never_empty_for_unknown_slug(): void {
		$cap = $this->cap( 'totally-unknown-feature' );

		$this->assertNotSame( '', $cap );
	}

	public function test_required_capability_is_never_empty_for_empty_slug(): void {
		$cap = $this->cap( '' );

		$this->assertNotSame( '', $cap );
	}

	// =========================================================================
	// Default value is 'edit_posts' when option is unset
	// =========================================================================

	public function test_default_is_edit_posts_when_option_unset(): void {
		$cap = $this->cap( 'translation' );

		$this->assertSame( 'edit_posts', $cap );
	}

	// =========================================================================
	// Stored option value is respected
	// =========================================================================

	public function test_stored_option_is_used(): void {
		$GLOBALS['lf_test_options']['linguaforge_required_capability'] = 'edit_others_posts';

		$cap = $this->cap( 'translation' );

		$this->assertSame( 'edit_others_posts', $cap );
	}

	// =========================================================================
	// Empty option falls back to 'edit_posts' (not '')
	// =========================================================================

	public function test_empty_option_falls_back_to_edit_posts(): void {
		$GLOBALS['lf_test_options']['linguaforge_required_capability'] = '';

		$cap = $this->cap( 'translation' );

		$this->assertSame( 'edit_posts', $cap );
		$this->assertNotSame( '', $cap );
	}

	// =========================================================================
	// Filter can tighten the capability
	// =========================================================================

	public function test_filter_override_is_respected(): void {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- intentional: filter receives $cap but overrides it unconditionally
		$GLOBALS['lf_test_filters']['linguaforge_required_capability'] = static function ( string $cap ): string {
			return 'manage_options';
		};

		$cap = $this->cap( 'translation' );

		$this->assertSame( 'manage_options', $cap );
	}

	// =========================================================================
	// Filter returning '' is caught by the safety net
	// =========================================================================

	public function test_filter_returning_empty_string_is_caught(): void {
		$GLOBALS['lf_test_filters']['linguaforge_required_capability'] = static function (): string {
			return '';
		};

		$cap = $this->cap( 'translation' );

		$this->assertNotSame( '', $cap, 'Safety net must prevent a filter from producing an empty capability' );
		$this->assertSame( 'edit_posts', $cap );
	}

	// =========================================================================
	// Context argument is forwarded to the filter
	// =========================================================================

	public function test_context_is_forwarded_to_filter(): void {
		$received_context = null;
		$GLOBALS['lf_test_filters']['linguaforge_required_capability'] = static function ( string $cap, string $context ) use ( &$received_context ): string {
			$received_context = $context;
			return $cap;
		};

		$this->cap( 'translate-chunk' );

		$this->assertSame( 'translate-chunk', $received_context );
	}
}
