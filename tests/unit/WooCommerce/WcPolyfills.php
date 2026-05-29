<?php
/**
 * Global-namespace class stubs and WP function polyfills for WooCommerce
 * integration unit tests.
 *
 * This file deliberately has NO namespace declaration so that every definition
 * lands in the global namespace — exactly where WordPress's own classes and
 * functions live in a real install.
 *
 * All stubs are guarded with class_exists() / function_exists() so this file
 * can be require_once'd by multiple test files in the same PHPUnit run without
 * "Cannot redeclare" fatals.
 *
 * The LfWcMocks registry is the single source of truth for all mock data. Tests
 * configure it via the WcUnitTestCase helper methods; polyfills read from it.
 *
 * @package LinguaForge\Tests\Unit\WooCommerce
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- WP stub classes (WP_Post, WP_Error, WP_Query, LfWcMocks) and polyfill functions must coexist in this single bootstrap file; splitting them across files would break the self-contained test setup pattern.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- same reason as above.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- polyfill and no-op functions intentionally declare the full WordPress API parameter list for signature compatibility; unused trailing parameters are expected.

// =============================================================================
// Class stubs
// =============================================================================

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub — only the properties used by the WC integration
	 * classes are declared.
	 */
	class WP_Post {
		public int    $ID          = 0;
		public string $post_type   = 'post';
		public string $post_status = 'publish';
		public string $post_title  = '';
		public int    $post_author = 0;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 * Accepts the standard (code, message, data) constructor signature so
	 * callers that pass arguments (e.g. DataEndpoints) don't get a PHP fatal.
	 */
	class WP_Error {
		public string $code    = '';
		public string $message = '';
		public mixed  $data    = null;

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_message( string $code = '' ): string { return $this->message; } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches WP_Error signature; $code is unused in this minimal stub.
		public function get_error_code(): string { return $this->code; }
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub — only get() / set() are used by VariationDelegate.
	 */
	class WP_Query {
		/** @var array<string,mixed> */
		private array $vars = [];

		public function get( string $query_var, mixed $fallback = '' ): mixed {
			return $this->vars[ $query_var ] ?? $fallback;
		}

		public function set( string $query_var, mixed $value ): void {
			$this->vars[ $query_var ] = $value;
		}
	}
}

// =============================================================================
// LfWcMocks — per-test mock registry
// =============================================================================

if ( ! class_exists( 'LfWcMocks' ) ) {
	/**
	 * Static mock registry that all polyfills read from and write to.
	 *
	 * Tests configure this via WcUnitTestCase helpers; WcUnitTestCase::setUp()
	 * calls LfWcMocks::reset() between every test.
	 */
	class LfWcMocks {
		/** @var array<int,WP_Post> post registry, keyed by post ID */
		public static array $posts = [];

		/** @var array<int,array<string,mixed>> post meta, keyed by [post_id][meta_key] */
		public static array $meta = [];

		/** @var array<int,array<string,int>> translation maps, keyed by post_id → [lang => post_id] */
		public static array $translations = [];

		/** @var array<int,mixed> wp_get_object_terms results, keyed by post_id */
		public static array $object_terms = [];

		/**
		 * Write log — records every update_post_meta / add_post_meta call made
		 * by the routing layer.  Each entry: [action, post_id, meta_key, value].
		 *
		 * @var list<array{0:string,1:int,2:string,3:mixed}>
		 */
		public static array $write_log = [];

		/** @var array<string,mixed> option store for get_option() polyfill */
		public static array $options = [];

		public static function reset(): void {
			self::$posts        = [];
			self::$meta         = [];
			self::$translations = [];
			self::$object_terms = [];
			self::$write_log    = [];
			self::$options      = [];
		}
	}
}

// =============================================================================
// WP function polyfills
// =============================================================================

if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $post = null ): ?WP_Post {
		return LfWcMocks::$posts[ (int) $post ] ?? null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Returns the stored value for ($post_id, $key):
	 *   $single = true  → raw value, or '' when absent.
	 *   $single = false → (array)value, or [] when absent.
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$val = LfWcMocks::$meta[ $post_id ][ $key ] ?? null;
		if ( $single ) {
			return $val !== null ? $val : '';
		}
		return $val !== null ? (array) $val : [];
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value ): bool {
		LfWcMocks::$meta[ $post_id ][ $meta_key ] = $meta_value;
		LfWcMocks::$write_log[]                   = [ 'update', $post_id, $meta_key, $meta_value ];
		return true;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( int $post_id, string $meta_key, mixed $meta_value, bool $unique = false ): int|false {
		LfWcMocks::$meta[ $post_id ][ $meta_key ] = $meta_value;
		LfWcMocks::$write_log[]                   = [ 'add', $post_id, $meta_key, $meta_value ];
		return 1;
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	/**
	 * Returns true when the key exists in the mock meta store for the given post.
	 * Uses array_key_exists (not isset) to match WP behaviour for null values.
	 */
	function metadata_exists( string $type, int $object_id, string $meta_key ): bool {
		return isset( LfWcMocks::$meta[ $object_id ] )
			&& array_key_exists( $meta_key, LfWcMocks::$meta[ $object_id ] );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Minimal apply_filters — returns $value unchanged (no registered callbacks).
	 * This is correct for all apply_filters() calls in the WC integration classes
	 * because the default $value is always the desired return for tests.
	 */
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	// No-op: tests call integration methods directly rather than through hooks.
	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	// No-op: TaxonomyDelegate calls this around its source-query but in tests
	// there is no live filter loop, so the call is harmless.
	function remove_filter( string $hook, mixed $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'linguaforge_get_translations' ) ) {
	/**
	 * Returns the translation map for the given post as registered in
	 * LfWcMocks::$translations.  Shape: [ 'en' => source_id, 'es' => translated_id, … ]
	 */
	function linguaforge_get_translations( int $post_id ): array {
		return LfWcMocks::$translations[ $post_id ] ?? [];
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/**
	 * Returns the terms registered in LfWcMocks::$object_terms for the given
	 * object ID, or [] when absent.
	 *
	 * Return type is mixed (not array) so tests can inject a WP_Error instance
	 * to verify the TaxonomyDelegate's error-fallback path.
	 */
	function wp_get_object_terms( mixed $object_ids, mixed $taxonomies, array $args = [] ): mixed {
		$id = is_array( $object_ids ) ? (int) $object_ids[0] : (int) $object_ids;
		return LfWcMocks::$object_terms[ $id ] ?? [];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		$sanitized = strtolower( $key );
		$sanitized = preg_replace( '/[^a-z0-9_\-]/', '', $sanitized );
		return $sanitized ?? '';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $fallback = false ): mixed {
		return LfWcMocks::$options[ $option ] ?? $fallback;
	}
}
