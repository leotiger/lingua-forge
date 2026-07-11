<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- three tiny, tightly related stub classes must coexist in this single bootstrap file; same pattern as ApiPolyfills.php.
/**
 * Minimal stand-in classes for the WordPress 7.0+ core AI Client SDK's
 * message DTOs (WordPress\AiClient\Messages\DTO\*).
 *
 * These real classes ship inside WordPress core (wp-includes/ai-client/) on
 * WP 7.0+ only — this sandbox has no WordPress at all, and they're not in the
 * wordpress-stubs package this plugin's PHPStan run depends on either. This
 * file declares lightweight stand-ins under the *same* fully-qualified class
 * names so that WpAiClient::build_history_messages()'s class_exists() checks
 * and `new $class(...)` calls exercise real object construction in the unit
 * suite, instead of always short-circuiting to the "SDK unavailable" empty
 * array branch. Used by WpAiClientTest.php.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace WordPress\AiClient\Messages\DTO;

if ( ! class_exists( __NAMESPACE__ . '\\MessagePart' ) ) {
	class MessagePart {
		public string $text;
		public function __construct( string $text ) {
			$this->text = $text;
		}
	}
}

if ( ! class_exists( __NAMESPACE__ . '\\UserMessage' ) ) {
	class UserMessage {
		public string $role = 'user';
		/** @var MessagePart[] */
		public array $parts;
		/** @param MessagePart[] $parts */
		public function __construct( array $parts ) {
			$this->parts = $parts;
		}
	}
}

if ( ! class_exists( __NAMESPACE__ . '\\ModelMessage' ) ) {
	class ModelMessage {
		public string $role = 'model';
		/** @var MessagePart[] */
		public array $parts;
		/** @param MessagePart[] $parts */
		public function __construct( array $parts ) {
			$this->parts = $parts;
		}
	}
}
