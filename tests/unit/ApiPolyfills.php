<?php
/**
 * Shared polyfills for third-party API unit tests (TridGroupHooksTest,
 * DataEndpointsTest).
 *
 * Loaded via require_once from each test file. All definitions are guarded
 * with class_exists / function_exists so this file can safely coexist with
 * WcPolyfills in the same PHPUnit process.
 *
 * @package LinguaForge\Tests\Unit
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- stub classes and polyfill functions must coexist in this single bootstrap file.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- same reason.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- polyfill functions match WP signatures; unused trailing parameters are expected.

// =============================================================================
// do_action — recording version
// =============================================================================

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Records every do_action() call to $GLOBALS['lf_test_actions'].
	 * Shape: [ hook => [ [ arg, … ], … ] ]
	 *
	 * Tests reset $GLOBALS['lf_test_actions'] = [] in setUp().
	 */
	function do_action( string $hook, mixed ...$args ): void {
		if ( ! isset( $GLOBALS['lf_test_actions'] ) ) {
			$GLOBALS['lf_test_actions'] = [];
		}
		$GLOBALS['lf_test_actions'][ $hook ][] = $args;
	}
}

// =============================================================================
// REST stubs
// =============================================================================

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub — ArrayAccess over a plain array.
	 */
	class WP_REST_Request implements ArrayAccess {
		/** @var array<string,mixed> */
		private array $data;

		public function __construct( array $data = [] ) {
			$this->data = $data;
		}

		public function offsetGet( mixed $key ): mixed        { return $this->data[ $key ] ?? null; }
		public function offsetExists( mixed $key ): bool      { return isset( $this->data[ $key ] ); }
		public function offsetSet( mixed $offset, mixed $value ): void  { $this->data[ $offset ] = $value; }
		public function offsetUnset( mixed $offset ): void   { unset( $this->data[ $offset ] ); }
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub — stores data and satisfies the return-type
	 * declarations on DataEndpoints handler methods.
	 */
	class WP_REST_Response {
		private mixed $data;

		public function __construct( mixed $data = null ) {
			$this->data = $data;
		}

		public function get_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Wraps data in WP_REST_Response (satisfies PHP 8.2 return-type enforcement).
	 */
	function rest_ensure_response( mixed $data ): \WP_REST_Response {
		return $data instanceof \WP_REST_Response ? $data : new \WP_REST_Response( $data );
	}
}

// =============================================================================
// linguaforge_* wrapper polyfills
// Read from simple $GLOBALS keys set by each test.
// =============================================================================

if ( ! function_exists( 'linguaforge_languages' ) ) {
	/** @return string[] */
	function linguaforge_languages(): array {
		return $GLOBALS['lf_api_languages'] ?? [];
	}
}

if ( ! function_exists( 'linguaforge_language_label' ) ) {
	function linguaforge_language_label( string $lang ): string {
		return ( $GLOBALS['lf_api_language_labels'] ?? [] )[ $lang ] ?? $lang;
	}
}

if ( ! function_exists( 'linguaforge_is_valid_lang' ) ) {
	function linguaforge_is_valid_lang( mixed $lang ): bool {
		return in_array( $lang, $GLOBALS['lf_api_languages'] ?? [], true );
	}
}

// =============================================================================
// WordPress function polyfills for DataEndpoints
// =============================================================================

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int|\WP_Post $post = 0 ): string|false {
		$id = ( $post instanceof \WP_Post ) ? $post->ID : (int) $post;
		return ( $GLOBALS['lf_api_permalinks'] ?? [] )[ $id ] ?? false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability, mixed ...$args ): bool {
		return (bool) ( $GLOBALS['lf_api_current_user_can'] ?? true );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ), random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
		);
	}
}
