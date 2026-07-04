<?php
/**
 * Minimal WP_CLI stub for integration tests.
 *
 * `composer test:integration` runs `vendor/bin/phpunit` directly (not
 * `wp phpunit`), so the real WP_CLI class from the wp-cli tool is never
 * loaded and the WP_CLI constant is never defined — see the "WP_CLI note" in
 * RedirectorRedirectIntegrationTest.php, which documents the same fact for
 * Context::is_system_request(). Any test exercising code that calls
 * \WP_CLI::error() / ::warning() / ::log() (the whole CLI/ directory) needs a
 * stand-in: the dev-only `php-stubs/wp-cli-stubs` package (required for
 * PHPStan) is NOT autoloaded at runtime — its composer.json declares no
 * "autoload" section, so it exists purely for static analysis and does
 * nothing to help here.
 *
 * This stub:
 *   - never touches process control (no exit()/die()). WP_CLI::error() throws
 *     WpCliTestErrorException instead, the same exception-seam pattern
 *     RedirectorRedirectIntegrationTest.php already uses for wp_redirect() —
 *     so a test can assert "this halted with an error" without ending the
 *     PHPUnit process.
 *   - records every error/warning/log/success/line call in a static array so
 *     tests can assert on message text. Call WP_CLI::reset() in setUp()/
 *     tearDown() so calls from one test don't bleed into the next.
 *   - is defined only in the true global namespace (matching the real
 *     \WP_CLI class, which unqualified `WP_CLI::error(...)` calls inside
 *     `namespace LinguaForge\AI\CLI` resolve to) and only when no WP_CLI
 *     already exists, so this is a harmless no-op if a real WP-CLI
 *     environment ever does load one ahead of this file.
 *
 * Not auto-discovered by PHPUnit — only files ending in *Test.php are (see
 * the <directory suffix="Test.php"> config in dev/phpunit.xml.dist). Test
 * files that need it must `require_once` it explicitly.
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- WpCliTestErrorException seam stub and the WP_CLI stub itself must coexist in one file; same pattern as LfTestRedirectException + RedirectorRedirectIntegrationTest.php.

if ( ! class_exists( 'WpCliTestErrorException', false ) ) {
	final class WpCliTestErrorException extends \RuntimeException {}
}

if ( ! class_exists( 'WP_CLI', false ) ) {

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName, Squiz.Classes.ValidClassName -- must match the real \WP_CLI class name exactly; it is not this project's code.
	final class WP_CLI {

		/** @var string[] */
		public static array $errors = [];

		/** @var string[] */
		public static array $warnings = [];

		/** @var string[] */
		public static array $logs = [];

		/** Clear all recorded calls. Call from setUp()/tearDown() between tests. */
		public static function reset(): void {
			self::$errors   = [];
			self::$warnings = [];
			self::$logs     = [];
		}

		/**
		 * @param mixed $message
		 * @param bool  $should_halt
		 * @throws WpCliTestErrorException When $should_halt is true (the default) —
		 *                                 mirrors the real WP_CLI::error() halting
		 *                                 script execution, but via a catchable
		 *                                 exception.
		 */
		public static function error( $message, $should_halt = true ): void {
			$message        = is_string( $message ) ? $message : (string) $message;
			self::$errors[] = $message;
			if ( $should_halt ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only seam; message is caught and compared in assertions, never rendered to output.
				throw new WpCliTestErrorException( $message );
			}
		}

		/** @param mixed $message */
		public static function warning( $message ): void {
			self::$warnings[] = is_string( $message ) ? $message : (string) $message;
		}

		/** @param mixed $message */
		public static function log( $message ): void {
			self::$logs[] = is_string( $message ) ? $message : (string) $message;
		}

		/** @param mixed $message */
		public static function success( $message ): void {
			self::$logs[] = is_string( $message ) ? $message : (string) $message;
		}

		/** @param mixed $message */
		public static function line( $message = '' ): void {
			self::$logs[] = is_string( $message ) ? $message : (string) $message;
		}

		/**
		 * @param int $return_code
		 * @throws WpCliTestErrorException Always.
		 */
		public static function halt( $return_code ): void {
			throw new WpCliTestErrorException( 'halt(' . (int) $return_code . ')' );
		}
	}
}
