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

// =============================================================================
// apply_filters — pass-through with optional test override
// =============================================================================

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Pass-through apply_filters polyfill.
	 *
	 * Returns $value unchanged unless $GLOBALS['lf_test_filters'][$hook] is
	 * set to a callable, in which case the callable receives $value plus any
	 * extra arguments and its return value is used instead.
	 *
	 * Tests reset $GLOBALS['lf_test_filters'] = [] in setUp().
	 */
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$cb = $GLOBALS['lf_test_filters'][ $hook ] ?? null;
		if ( is_callable( $cb ) ) {
			return $cb( $value, ...$args );
		}
		return $value;
	}
}

// =============================================================================
// WP_Error class stub
// =============================================================================

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- WP stub must coexist with polyfill functions.
	class WP_Error {
		private string $code;
		private string $message;
		private mixed  $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string    { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed     { return $this->data; }
	}
}

// =============================================================================
// Transient polyfills
// =============================================================================
// Tests reset $GLOBALS['lf_test_transients'] = [] in setUp().

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		return $GLOBALS['lf_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature; $expiration unused in stub.
		$GLOBALS['lf_test_transients'][ $transient ] = $value;
		return true;
	}
}

// =============================================================================
// User polyfills
// =============================================================================

if ( ! function_exists( 'get_current_user_id' ) ) {
	/** Returns $GLOBALS['lf_test_user_id'] (default 1). */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['lf_test_user_id'] ?? 1 );
	}
}

// =============================================================================
// WordPress options API polyfills
// =============================================================================
// Tests that need these functions reset $GLOBALS['lf_test_options'] in setUp().
// The store is shared across all tests in the process, so tests that write
// options must reset it themselves.

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $fallback = false ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- matches WP signature; renamed to avoid reserved keyword warning.
		return $GLOBALS['lf_test_options'][ $option ] ?? $fallback;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool|string $autoload = true ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature; $autoload unused in stub.
		$GLOBALS['lf_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['lf_test_options'][ $option ] );
		return true;
	}
}

// =============================================================================
// WordPress utility polyfills
// =============================================================================

if ( ! function_exists( 'get_available_languages' ) ) {
	/**
	 * Returns the list of installed WP language packs.
	 * Unit tests run without WP, so the list is always empty.
	 */
	function get_available_languages( string $dir = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches WP signature; $dir unused in this stub.
		return [];
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $str ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.stringFound -- matches WP signature.
		return rtrim( $str, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Minimal wp_upload_dir polyfill — returns a temp basedir so
	 * Context::i18n_overrides_dir() can compute a path without fatalling.
	 * The path won't exist, so glob() returns [] and discover_plugin_locales()
	 * returns [] — which is what LocaleDetector tests need.
	 *
	 * @return array{basedir:string,baseurl:string,path:string,url:string,subdir:string,error:bool}
	 */
	function wp_upload_dir(): array {
		$base = sys_get_temp_dir() . '/lf-unit-test-uploads';
		return [
			'basedir' => $base,
			'baseurl' => 'http://example.org/wp-content/uploads',
			'path'    => $base,
			'url'     => 'http://example.org/wp-content/uploads',
			'subdir'  => '',
			'error'   => false,
		];
	}
}

// =============================================================================
// Gutenberg block-parsing polyfills (for BlockTextExtractor::extract() tests)
// =============================================================================

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Minimal parse_blocks polyfill for unit tests.
	 *
	 * Parses Gutenberg block comment markup into the same array shape that
	 * the real WordPress parse_blocks() returns.  Handles flat and nested
	 * blocks; does not parse freeform / classic content between blocks.
	 *
	 * @param string $content Raw post_content string.
	 * @return array<int,array{blockName:string,attrs:array<string,mixed>,innerBlocks:array,innerHTML:string,innerContent:array}>
	 */
	function parse_blocks( string $content ): array {

		$blocks = [];
		$offset = 0;
		$len    = strlen( $content );

		while ( $offset < $len ) {

			// Locate the next opening block comment.
			if ( ! preg_match(
				'/<!--\s+wp:(\S+)(?:\s+(\{[^}]*\}))?\s+-->/',
				$content,
				$open,
				PREG_OFFSET_CAPTURE,
				$offset
			) ) {
				break;
			}

			$name       = $open[1][0];
			$attrs_json = isset( $open[2] ) ? $open[2][0] : '';
			$attrs      = $attrs_json !== '' ? (array) json_decode( $attrs_json, true ) : [];
			$body_start = (int) $open[0][1] + strlen( $open[0][0] );

			// Find the matching close comment.
			$close_tag = "<!-- /wp:{$name} -->";
			$close_pos = strpos( $content, $close_tag, $body_start );

			if ( $close_pos === false ) {
				break;
			}

			$inner_html   = substr( $content, $body_start, $close_pos - $body_start );
			$inner_blocks = str_contains( $inner_html, '<!-- wp:' )
				? parse_blocks( $inner_html )
				: [];

			$blocks[] = [
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => $inner_html,
				'innerContent' => [ $inner_html ],
			];

			$offset = $close_pos + strlen( $close_tag );
		}

		return $blocks;
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	/**
	 * Minimal serialize_blocks polyfill for unit tests.
	 *
	 * Serialises a parsed block array back to Gutenberg block comment markup.
	 * When a block has innerBlocks they are re-serialised recursively; otherwise
	 * the stored innerHTML is used as the block body.
	 *
	 * @param array<int,array{blockName:string,attrs:array<string,mixed>,innerBlocks:array,innerHTML:string}> $blocks
	 */
	function serialize_blocks( array $blocks ): string {

		$out = '';

		foreach ( $blocks as $block ) {

			$name  = $block['blockName'];
			$attrs = $block['attrs'] ?? [];

			$attr_str = ! empty( $attrs )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- polyfill; wp_json_encode is not required here.
				? ' ' . (string) json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: '';

			$inner = ! empty( $block['innerBlocks'] )
				? serialize_blocks( $block['innerBlocks'] )
				: ( $block['innerHTML'] ?? '' );

			$out .= "<!-- wp:{$name}{$attr_str} -->{$inner}<!-- /wp:{$name} -->";
		}

		return $out;
	}
}
