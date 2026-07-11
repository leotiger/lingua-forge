<?php
/**
 * Recording double for the real WP_AI_Client_Prompt_Builder /
 * WordPress\AiClient\Builders\PromptBuilder (WP 7.0+ core AI Client).
 *
 * Every method WpAiClient::chat() calls is implemented here and stores what
 * it was called with, so WpAiClientTest.php can assert on the message→builder
 * mapping afterward instead of needing a real WP 7.0 install with a
 * configured AI connector.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

if ( ! class_exists( __NAMESPACE__ . '\\FakeAiClientPromptBuilder' ) ) {
	class FakeAiClientPromptBuilder {

		public ?string $prompt_text = null;
		public ?string $system_instruction = null;

		/** @var array<int, object> */
		public array $history = [];

		public ?float $temperature = null;
		public ?int $max_tokens = null;

		public bool $json_response_called = false;
		/** @var array<string, mixed>|null */
		public ?array $json_schema = null;

		public bool $supported_for_text_generation = true;

		/** @var string|\WP_Error */
		public $generate_text_result = '';

		public function __construct( ?string $prompt_text ) {
			$this->prompt_text = $prompt_text;
		}

		public function using_system_instruction( string $instruction ): self {
			$this->system_instruction = $instruction;
			return $this;
		}

		public function with_history( ...$messages ): self {
			$this->history = $messages;
			return $this;
		}

		public function using_temperature( float $temperature ): self {
			$this->temperature = $temperature;
			return $this;
		}

		public function using_max_tokens( int $max_tokens ): self {
			$this->max_tokens = $max_tokens;
			return $this;
		}

		/** @param array<string, mixed>|null $schema */
		public function as_json_response( ?array $schema = null ): self {
			$this->json_response_called = true;
			$this->json_schema = $schema;
			return $this;
		}

		public function is_supported_for_text_generation(): bool {
			return $this->supported_for_text_generation;
		}

		/** @return string|\WP_Error */
		public function generate_text() {
			return $this->generate_text_result;
		}
	}
}
