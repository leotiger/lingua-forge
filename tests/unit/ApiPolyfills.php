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
// WordPress DB output-type constants (used by $wpdb->get_results() etc.)
// =============================================================================

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
	define( 'OBJECT_K', 'OBJECT_K' );
}

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
	/**
	 * Checks both backing stores so this polyfill is compatible with WC tests
	 * regardless of which polyfill file PHPUnit loads first:
	 *  - WcPolyfills loaded first → WcPolyfills defines this instead; n/a here.
	 *  - ApiPolyfills loaded first → this version runs; honours LfWcMocks when
	 *    the class is already loaded (WC test suite loaded it after this file),
	 *    and falls back to $GLOBALS['lf_api_current_user_can'] otherwise.
	 *
	 * @param mixed ...$args Capability string + optional extra args (signature compat).
	 */
	function current_user_can( mixed ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches WP signature; args unused in stub.
		if ( class_exists( 'LfWcMocks' ) && ! \LfWcMocks::$current_user_can ) {
			return false;
		}
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

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * Polyfill for WP's size_format() — converts bytes to a human-readable string.
	 * Used by LanguageOverridesPanel::loco_custom_files() for display purposes.
	 */
	function size_format( int $bytes, int $decimals = 0 ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature; $decimals unused in this simplified stub.
		if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 1 ) . ' MB';
		if ( $bytes >= 1024 )    return round( $bytes / 1024,    1 ) . ' KB';
		return $bytes . ' B';
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $str ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.stringFound -- matches WP signature.
		return rtrim( $str, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( string $str ): string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.stringFound -- matches WP signature.
		return rtrim( $str, '/\\' );
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * WordPress wp_json_encode polyfill — wraps json_encode.
	 * Used by Glossary::format_for_prompt() and Translation::parse_full_post_envelope().
	 */
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- polyfill
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Polyfill for wp_strip_all_tags() — strips HTML/PHP tags and optionally
	 * line breaks.  The unit-test version only strips tags; the $remove_breaks
	 * flag is accepted for signature compatibility but ignored.
	 */
	function wp_strip_all_tags( string $str, bool $remove_breaks = false ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature; $remove_breaks unused in this stub.
		return strip_tags( $str ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- polyfill; wp_strip_all_tags() is what this function provides.
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	/**
	 * Polyfill for WP's serialize_block() — serialises a single parsed block
	 * back to Gutenberg block-comment markup.  Delegates to serialize_blocks()
	 * which is defined below.
	 *
	 * @param array{blockName:string,attrs:array<string,mixed>,innerBlocks:array,innerHTML:string} $block
	 */
	function serialize_block( array $block ): string {
		return serialize_blocks( [ $block ] );
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

// =============================================================================
// String-sanitisation polyfills (used by ChunkTranslation and other classes
// that run in unit tests without a WordPress runtime)
// =============================================================================

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return implode( "\n", array_map( 'strip_tags', explode( "\n", $str ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- polyfill; wp_strip_all_tags() is not available without WP.
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- polyfill; wp_strip_all_tags() is not available without WP.
	}
}

// =============================================================================
// WP_Post stub (shared; also defined in WcPolyfills — guarded to avoid
// "Cannot redeclare" when both files are loaded in the same PHPUnit process)
// =============================================================================

if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- WP stub must coexist with polyfill functions in this bootstrap file.
	class WP_Post {
		public int    $ID          = 0;
		public string $post_type   = 'post';
		public string $post_status = 'publish';
		public string $post_title  = '';
		public string $post_content = '';
		public string $post_excerpt = '';
		public int    $post_author = 0;
	}
}

// =============================================================================
// WP_Screen stub — minimal object for get_current_screen() polyfill
// =============================================================================

if ( ! class_exists( 'WP_Screen' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Screen {
		public string $base = '';
	}
}

// =============================================================================
// Admin-context polyfills (detect_post_language and other admin-aware helpers)
//
// is_admin() is intentionally NOT defined here — it is provided by WcPolyfills
// (controlled via LfWcMocks::$is_admin) which TranslationTest loads first.
// The function_exists() guard would skip this definition anyway when both
// files are loaded, but the comment makes the intent explicit.
// =============================================================================

if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * Returns a WP_Screen stub whose base equals $GLOBALS['lf_test_screen_base'],
	 * or null when that global is absent — matching WP's own null return on
	 * non-admin and REST requests.
	 */
	function get_current_screen(): ?WP_Screen {
		if ( ! isset( $GLOBALS['lf_test_screen_base'] ) ) {
			return null;
		}
		$screen       = new WP_Screen();
		$screen->base = (string) $GLOBALS['lf_test_screen_base'];
		return $screen;
	}
}

// =============================================================================
// Frontend context polyfills (singular page, queried object)
// =============================================================================

if ( ! function_exists( 'is_singular' ) ) {
	/** Returns $GLOBALS['lf_test_is_singular'] (default false). */
	function is_singular( mixed $post_types = '' ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches WP signature; $post_types unused in stub.
		return (bool) ( $GLOBALS['lf_test_is_singular'] ?? false );
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	/** Returns $GLOBALS['lf_test_queried_object_id'] (default 0). */
	function get_queried_object_id(): int {
		return (int) ( $GLOBALS['lf_test_queried_object_id'] ?? 0 );
	}
}

// get_post_meta() and get_locale() are intentionally NOT defined here — both
// are provided by WcPolyfills (LfWcMocks::$meta and $GLOBALS['lf_test_locale']
// respectively). Tests that need them should load WcPolyfills first.

// =============================================================================
// PHP 8.0+ built-in polyfills (for PHP 7.4 local lint/test environment)
// =============================================================================

if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( string $haystack, string $needle ): bool {
		return '' === $needle || str_starts_with_compat( $haystack, $needle );
	}
	function str_starts_with_compat( string $haystack, string $needle ): bool {
		return substr( $haystack, 0, strlen( $needle ) ) === $needle;
	}
}
// Re-define after the above guards to avoid issues when str_starts_with already exists.
if ( ! function_exists( 'str_starts_with_compat' ) ) {
	function str_starts_with_compat( string $haystack, string $needle ): bool {
		return substr( $haystack, 0, strlen( $needle ) ) === $needle;
	}
}

if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( string $haystack, string $needle ): bool {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( string $haystack, string $needle ): bool {
		return '' === $needle || substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}

// =============================================================================
// WordPress URL / HTTP polyfills (for SEO helper tests)
// =============================================================================

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Returns $GLOBALS['lf_test_home_url'] (default 'https://example.org').
	 * Accepts an optional path appended to the base URL.
	 */
	function home_url( string $path = '' ): string {
		$base = rtrim( (string) ( $GLOBALS['lf_test_home_url'] ?? 'https://example.org' ), '/' );
		return '' === $path ? $base : $base . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	/** Returns $GLOBALS['lf_test_is_ssl'] (default false). */
	function is_ssl(): bool {
		return (bool) ( $GLOBALS['lf_test_is_ssl'] ?? false );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/** Minimal esc_url polyfill — strips dangerous protocols, otherwise returns as-is. */
	function esc_url( string $url, array $protocols = [], string $_context = 'display' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature.
		// Reject javascript: and data: URLs; pass everything else through.
		if ( preg_match( '/^\s*(javascript|data|vbscript)\s*:/i', $url ) ) {
			return '';
		}
		return $url;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/** Minimal esc_attr polyfill. */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/** Wraps PHP parse_url(). */
	function wp_parse_url( string $url, int $component = -1 ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- polyfill
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_get_document_title' ) ) {
	/** Returns $GLOBALS['lf_test_document_title'] (default 'Test Page'). */
	function wp_get_document_title(): string {
		return (string) ( $GLOBALS['lf_test_document_title'] ?? 'Test Page' );
	}
}

if ( ! function_exists( 'do_blocks' ) ) {
	/** Minimal do_blocks polyfill — strips Gutenberg block comments. */
	function do_blocks( string $content ): string {
		return (string) preg_replace( '/<!--\s*\/?wp:[^>]*-->/i', '', $content );
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	/** Minimal wp_trim_words polyfill. */
	function wp_trim_words( string $text, int $num_words = 55, string $more = '' ): string {
		$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) || count( $words ) <= $num_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
	}
}

if ( ! function_exists( '_n' ) ) {
	/** Minimal _n polyfill — returns singular or plural based on count. */
	function _n( string $singular, string $plural, int $number, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP signature.
		return 1 === $number ? $singular : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( float $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals );
	}
}
