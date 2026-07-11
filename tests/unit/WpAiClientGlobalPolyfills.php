<?php
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- the wp_ai_client_prompt() stub, __()/is_wp_error() polyfills, and the WP_Error stub class must coexist in this single global-namespace bootstrap file; same pattern as ApiPolyfills.php.
/**
 * Global-namespace stubs/polyfills for WpAiClientTest.php.
 *
 * wp_ai_client_prompt() must live in the true global namespace (no
 * `namespace` declaration at all) because LinguaForge\AI\Providers\WpAiClient::chat()
 * resolves it via `call_user_func( 'wp_ai_client_prompt', ... )`, which looks
 * up a bare string callable in the global namespace regardless of which
 * namespace the calling code sits in.
 *
 * All declarations are guarded with function_exists()/class_exists() so this
 * file coexists safely with ApiPolyfills.php / WcPolyfills.php in the same
 * PHPUnit process if both happen to load.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Stub for WordPress 7.0+'s wp_ai_client_prompt() entry point. Returns a
	 * FakeAiClientPromptBuilder recording double instead of a real
	 * WP_AI_Client_Prompt_Builder, pre-configured from
	 * $GLOBALS['lf_test_wp_ai_client_builder_config'] (set by the test before
	 * calling WpAiClient::chat()) and stashed in
	 * $GLOBALS['lf_test_wp_ai_client_builder'] for the test to inspect after.
	 */
	function wp_ai_client_prompt( ?string $prompt = null ) {

		$builder = new \LinguaForge\Tests\Unit\FakeAiClientPromptBuilder( $prompt );

		$config = $GLOBALS['lf_test_wp_ai_client_builder_config'] ?? [];

		if ( array_key_exists( 'supported_for_text_generation', $config ) ) {
			$builder->supported_for_text_generation = $config['supported_for_text_generation'];
		}
		if ( array_key_exists( 'generate_text_result', $config ) ) {
			$builder->generate_text_result = $config['generate_text_result'];
		}

		$GLOBALS['lf_test_wp_ai_client_builder'] = $builder;

		return $builder;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP signature; $domain unused.
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
