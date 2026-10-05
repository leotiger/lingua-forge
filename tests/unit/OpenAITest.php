<?php
/**
 * Unit tests for LinguaForge\AI\Providers\OpenAI.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Providers\OpenAI;
use LinguaForge\AI\Providers\WorkerConfig;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
	define( 'LINGUAFORGE_AI_PATH', dirname( __DIR__, 2 ) . '/ai' );
}

require_once LINGUAFORGE_AI_PATH . '/includes/Contracts/AIProviderInterface.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/WorkerConfig.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/AbstractProvider.php';
require_once LINGUAFORGE_AI_PATH . '/includes/Providers/OpenAI.php';

final class OpenAITest extends TestCase {

	private const MESSAGES = [
		[ 'role' => 'user', 'content' => 'Translate: hello.' ],
	];

	private function build_body_for_model( string $model ): array {
		$provider = new OpenAI( new WorkerConfig(
			model:       $model,
			max_tokens:  64,
			temperature: 0.2,
		) );

		$method = new \ReflectionMethod( OpenAI::class, 'build_request' );
		$method->setAccessible( true );

		[, , $body] = $method->invoke( $provider, self::MESSAGES, 'sk-test' );

		$this->assertIsArray( $body );

		return $body;
	}

	public function test_omits_temperature_for_openai_models_that_require_default_sampling(): void {
		$body = $this->build_body_for_model( 'gpt-5-mini' );

		$this->assertArrayNotHasKey( 'temperature', $body );
	}

	public function test_omits_temperature_for_o_series_models(): void {
		$body = $this->build_body_for_model( 'o3-mini' );

		$this->assertArrayNotHasKey( 'temperature', $body );
	}

	public function test_keeps_temperature_for_standard_chat_models(): void {
		$body = $this->build_body_for_model( 'gpt-4o-mini' );

		$this->assertArrayHasKey( 'temperature', $body );
		$this->assertSame( 0.2, $body['temperature'] );
	}
}
