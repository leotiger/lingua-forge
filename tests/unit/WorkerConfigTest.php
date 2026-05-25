<?php
/**
 * Unit tests for LinguaForge\AI\Providers\WorkerConfig.
 *
 * WorkerConfig is a pure PHP 8.1 readonly-property value object with no
 * WordPress dependencies, so it belongs entirely in the unit suite.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Providers\WorkerConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once dirname( __DIR__, 2 ) . '/ai/includes/Providers/WorkerConfig.php';

final class WorkerConfigTest extends TestCase {

	// ── Constructor defaults ──────────────────────────────────────────────────

	public function test_constructor_sets_model(): void {
		$cfg = new WorkerConfig( 'claude-sonnet-4-6' );
		$this->assertSame( 'claude-sonnet-4-6', $cfg->model );
	}

	public function test_constructor_defaults_max_tokens_to_1024(): void {
		$cfg = new WorkerConfig( 'gpt-4o' );
		$this->assertSame( 1024, $cfg->max_tokens );
	}

	public function test_constructor_defaults_temperature_to_0_4(): void {
		$cfg = new WorkerConfig( 'gemini-2.0-flash' );
		$this->assertEqualsWithDelta( 0.4, $cfg->temperature, 0.0001 );
	}

	public function test_constructor_defaults_response_schema_to_null(): void {
		$cfg = new WorkerConfig( 'claude-haiku-4-5-20251001' );
		$this->assertNull( $cfg->response_schema );
	}

	// ── Constructor explicit values ───────────────────────────────────────────

	public function test_constructor_accepts_custom_max_tokens(): void {
		$cfg = new WorkerConfig( 'claude-sonnet-4-6', max_tokens: 4096 );
		$this->assertSame( 4096, $cfg->max_tokens );
	}

	public function test_constructor_accepts_custom_temperature(): void {
		$cfg = new WorkerConfig( 'claude-sonnet-4-6', temperature: 0.1 );
		$this->assertEqualsWithDelta( 0.1, $cfg->temperature, 0.0001 );
	}

	public function test_constructor_accepts_zero_temperature(): void {
		$cfg = new WorkerConfig( 'claude-sonnet-4-6', temperature: 0.0 );
		$this->assertEqualsWithDelta( 0.0, $cfg->temperature, 0.0001 );
	}

	public function test_constructor_accepts_response_schema(): void {
		$schema = [
			'type'       => 'object',
			'properties' => [
				'translation' => [ 'type' => 'string' ],
			],
		];

		$cfg = new WorkerConfig( 'gpt-4o', response_schema: $schema );
		$this->assertSame( $schema, $cfg->response_schema );
	}

	public function test_constructor_accepts_all_parameters_at_once(): void {
		$schema = [ 'type' => 'string' ];
		$cfg    = new WorkerConfig(
			model:           'gemini-1.5-pro',
			max_tokens:      8192,
			temperature:     0.7,
			response_schema: $schema,
		);

		$this->assertSame( 'gemini-1.5-pro', $cfg->model );
		$this->assertSame( 8192,             $cfg->max_tokens );
		$this->assertEqualsWithDelta( 0.7,   $cfg->temperature, 0.0001 );
		$this->assertSame( $schema,          $cfg->response_schema );
	}

	// ── Readonly enforcement ─────────────────────────────────────────────────

	/** Every public property must be declared readonly. */
	public function test_all_public_properties_are_readonly(): void {
		foreach ( [ 'model', 'max_tokens', 'temperature', 'response_schema' ] as $prop ) {
			$ref = new ReflectionProperty( WorkerConfig::class, $prop );
			$this->assertTrue(
				$ref->isReadOnly(),
				"WorkerConfig::\${$prop} must be readonly."
			);
		}
	}

	/** Attempting to overwrite a readonly property after construction must throw. */
	public function test_readonly_property_throws_on_write(): void {
		$cfg = new WorkerConfig( 'gpt-4o' );

		$this->expectException( \Error::class );
		// @phpstan-ignore-line — intentional illegal write to verify readonly
		$cfg->model = 'something-else'; // @phpcs:ignore
	}
}
