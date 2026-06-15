<?php

namespace LinguaForge\AI\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WP_DEBUG-gated diagnostic logger for the AI module.
 *
 * The AI subsystem emits operational diagnostics (provider request failures and
 * retries, cryptographic feature gaps in KeyStore, malformed translation
 * envelopes) that are useful while actively debugging but should never reach a
 * production debug.log. This is the single, WP_DEBUG-gated sink they route
 * through — mirroring the language-router's own gated `Language_Router::debug()`
 * helper, and keeping the one Plugin Check `error_log` exception at a single
 * call site instead of scattered across the subsystem.
 *
 * Callers pass a fully composed message that already carries its own
 * "Lingua Forge AI [Component]" prefix; this class only gates and forwards it,
 * so log output is byte-identical to the previous direct `error_log()` calls
 * (minus the production noise).
 *
 * @package LinguaForge\AI\Core
 * @since   2.3.2
 */
class Log {

	/**
	 * Write a diagnostic line to the PHP error log — only when WP_DEBUG and
	 * WP_DEBUG_LOG are both enabled (the same resolution WordPress core uses for
	 * its own internal logging). No-ops entirely otherwise.
	 *
	 * @param string $message Fully composed log line (already prefixed by the caller).
	 */
	public static function debug( string $message ): void {

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The single WP_DEBUG-gated diagnostic sink for the AI module; callers route here instead of calling error_log() directly.
		error_log( $message );
	}
}
