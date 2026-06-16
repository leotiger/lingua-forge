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
		public string $post_name   = '';
		public int    $post_author = 0;
		public int    $menu_order  = 0;
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
		public function get_error_data( string $code = '' ): mixed { return $this->data; } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- matches WP_Error signature; $code is unused in this minimal stub.
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub — only get() / set() / is_main_query() are used by
	 * WooCommerce integration classes under test.
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

		/**
		 * Returns the value of LfWcMocks::$is_main_query so tests can simulate
		 * whether this query instance is the main WordPress query.
		 */
		public function is_main_query(): bool {
			return \LfWcMocks::$is_main_query;
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

		/**
		 * Cache-delete log — records every wp_cache_delete() call.
		 * Each entry: [ 'key' => mixed, 'group' => string ].
		 *
		 * @var list<array{key:mixed,group:string}>
		 */
		public static array $cache_deletes = [];

		/**
		 * $wpdb->update() log — records every direct lookup-table update.
		 * Each entry: [ 'table' => string, 'data' => array, 'where' => array ].
		 *
		 * @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>}>
		 */
		public static array $wpdb_updates = [];

		/**
		 * Return value for $wpdb->get_var() calls.
		 *
		 * null  = no row found (the normal "no own variations" state).
		 * '1'   = row found (simulates "translated parent has own variations").
		 *
		 * @var mixed
		 */
		public static mixed $wpdb_get_var = null;

		/**
		 * Return value for $wpdb->get_col() calls.
		 *
		 * [] = no rows (default; variation loop is skipped).
		 * Set to an array of int-string IDs to simulate variation children.
		 *
		 * @var array<int,string>
		 */
		public static array $wpdb_get_col = [];

		/** @var bool Return value for the is_admin() polyfill. Default false (frontend). */
		public static bool $is_admin = false;

		/**
		 * Return value for get_queried_object_id() polyfill.
		 * 0 = no queried object (early bootstrap / admin context).
		 * Set to a post ID in tests that exercise frontend conditional hooks.
		 *
		 * @var int
		 */
		public static int $queried_object_id = 0;

		/** @var bool Return value for WP_Query::is_main_query(). Default false (secondary query). */
		public static bool $is_main_query = false;

		/** @var bool Return value for current_user_can(). Default true (capable). */
		public static bool $current_user_can = true;

		/**
		 * User meta store for get_user_meta() polyfill.
		 * Shape: [ user_id => [ meta_key => value ] ]
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static array $user_meta = [];

		/** @var array<int,array<string,mixed>> term meta, keyed by [term_id][meta_key] */
		public static array $term_meta = [];

		/**
		 * Raw rows returned by $wpdb->get_results() for the current test.
		 * Shape: list of stdClass objects with `post_id` (string) and `lang` (string)
		 * properties — matches the columns TridGroup::get_translations() SELECTs.
		 *
		 * Reset to [] by reset(). Set this before exercising code paths that call
		 * TridGroup::get_translations() (e.g. post__in path in CatalogQuery,
		 * translate_privacy_policy_page_id in WcPageBridge).
		 *
		 * @var list<object>
		 */
		public static array $db_results = [];

		public static function reset(): void {
			self::$posts             = [];
			self::$meta              = [];
			self::$translations      = [];
			self::$object_terms      = [];
			self::$write_log         = [];
			self::$options           = [];
			self::$cache_deletes     = [];
			self::$wpdb_updates      = [];
			self::$wpdb_get_var      = null;
			self::$wpdb_get_col      = [];
			self::$is_admin            = false;
			self::$is_main_query       = false;
			self::$queried_object_id   = 0;
			\LfWpQueryStub::reset();
			self::$current_user_can  = true;
			self::$user_meta         = [];
			self::$term_meta         = [];
			self::$db_results        = [];
		}
	}
}

// =============================================================================
// $wpdb stub
// =============================================================================

if ( ! class_exists( 'LfWpdb' ) ) {
	/**
	 * Minimal $wpdb stub covering the methods used by WooCommerce integration
	 * classes in unit tests.
	 *
	 * • update()  — StockRouter lookup-table sync. Logs to LfWcMocks::$wpdb_updates.
	 * • prepare() — Returns the SQL template unchanged; SQL is never executed in
	 *               unit tests so the placeholder substitution is irrelevant.
	 * • get_var() — Returns LfWcMocks::$wpdb_get_var (null by default = no row
	 *               found). Used by VariationDelegate's own-variations existence
	 *               check. Tests that need a non-null result can set this value.
	 * • esc_like() — Returns the string unchanged; no real DB escaping needed.
	 *
	 * Table-name properties ($posts, $postmeta, $wc_product_meta_lookup) are
	 * exposed so SQL strings that interpolate {$wpdb->posts} do not produce
	 * undefined-property notices.
	 */
	class LfWpdb {
		public string $prefix                 = 'wp_';
		public string $posts                  = 'wp_posts';
		public string $postmeta               = 'wp_postmeta';
		public string $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';

		/**
		 * @param string               $table  Table name.
		 * @param array<string,mixed>  $data   Column → value map.
		 * @param array<string,mixed>  $where  WHERE column → value map.
		 * @param string[]|null        $format Data format specifiers.
		 * @param string[]|null        $where_format WHERE format specifiers.
		 * @return int|false  Mocked rows-affected (always 1).
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- format args unused in stub
			LfWcMocks::$wpdb_updates[] = [
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			];
			return 1;
		}

		/**
		 * Simulates $wpdb->prepare() — returns the query template as-is.
		 * Unit tests never execute the SQL; placeholder substitution is irrelevant.
		 *
		 * @param string $query   SQL template with %s / %d placeholders.
		 * @param mixed  ...$args Replacement values (ignored in stub).
		 * @return string  The template string unchanged.
		 */
		public function prepare( string $query, mixed ...$args ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- args intentionally ignored; stub never executes SQL.
			return $query;
		}

		/**
		 * Simulates $wpdb->get_col() — returns LfWcMocks::$wpdb_get_col.
		 *
		 * Default is [] (no variation children). Tests that need variation children
		 * can set LfWcMocks::$wpdb_get_col = ['101', '102'] before the call.
		 *
		 * @param mixed $query Ignored in the stub.
		 * @return array<int,string>  LfWcMocks::$wpdb_get_col.
		 */
		public function get_col( mixed $query = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- query intentionally ignored; stub returns mock value.
			return LfWcMocks::$wpdb_get_col;
		}

		/**
		 * Simulates $wpdb->get_var() — returns LfWcMocks::$wpdb_get_var.
		 *
		 * Default is null (no row found), which makes VariationDelegate's
		 * "has own variations?" check return false and proceed to the normal
		 * source-parent delegation path — keeping all existing delegation
		 * tests passing unchanged.
		 *
		 * Tests that need to simulate "own variations exist" can set
		 * LfWcMocks::$wpdb_get_var = '1' before calling the method under test.
		 *
		 * @param mixed $query Ignored in the stub.
		 * @return mixed  LfWcMocks::$wpdb_get_var (null by default).
		 */
		public function get_var( mixed $query = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- query intentionally ignored; stub returns mock value.
			return LfWcMocks::$wpdb_get_var;
		}

		/**
		 * Simulates $wpdb->esc_like() — returns the string unchanged.
		 * No real DB escaping is needed in unit tests.
		 *
		 * @param string $text  Input string.
		 * @return string  Unchanged input.
		 */
		public function esc_like( string $text ): string {
			return $text;
		}

		/**
		 * Simulates $wpdb->get_results() — returns LfWcMocks::$db_results.
		 *
		 * Default is [] (no rows). Tests that exercise code paths calling
		 * TridGroup::get_translations() should pre-set LfWcMocks::$db_results to
		 * an array of stdClass objects with `post_id` (string) and `lang` (string)
		 * properties before the method under test is called.
		 *
		 * Previously always returned []; updated to be controllable per-test so
		 * TridGroup can be injected into the stub Router without fataling.
		 *
		 * @param mixed $query  Ignored in stub.
		 * @param mixed $output Ignored in stub.
		 * @return array<int, mixed>  LfWcMocks::$db_results.
		 */
		public function get_results( mixed $query = null, mixed $output = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- stub; args intentionally ignored.
			return LfWcMocks::$db_results;
		}
	}
}

// Register the global $wpdb stub once per process.
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test bootstrap; intentionally seeds the $wpdb stub so unit tests can run without a real database.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new LfWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

// =============================================================================
// WordPress locale polyfill (used by LanguageUninstaller::is_protected())
// =============================================================================

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Returns LfWcMocks::$is_admin so tests can simulate admin vs. frontend
	 * context without a WordPress runtime.  Defaults to false (frontend).
	 */
	function is_admin(): bool {
		return LfWcMocks::$is_admin;
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	/**
	 * Returns LfWcMocks::$queried_object_id so tests can simulate the current
	 * frontend page without a WordPress runtime.  Defaults to 0 (no object).
	 */
	function get_queried_object_id(): int {
		return LfWcMocks::$queried_object_id;
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/**
	 * Returns the WP_Post stored under LfWcMocks::$queried_object_id, or null when
	 * no queried object is configured.  Tests must (a) set
	 * LfWcMocks::$queried_object_id and (b) register a WP_Post via
	 * WcUnitTestCase::make_post() so both return the same object.
	 */
	function get_queried_object(): ?\WP_Post {
		$id = LfWcMocks::$queried_object_id;
		if ( $id <= 0 ) {
			return null;
		}
		return LfWcMocks::$posts[ $id ] ?? null;
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * Returns $GLOBALS['lf_test_is_singular'] (default false).
	 *
	 * The $post_types argument is accepted for WP API compatibility but ignored —
	 * the stub simulates any singular context without type discrimination.
	 * WcUnitTestCase::setUp() resets $GLOBALS['lf_test_is_singular'] to false
	 * between every test so state never leaks across test methods.
	 *
	 * @param mixed $post_types  Accepted for WP API compatibility; ignored in stub.
	 */
	function is_singular( mixed $post_types = '' ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $post_types ignored; accepted for WP API signature compatibility only.
		return (bool) ( $GLOBALS['lf_test_is_singular'] ?? false );
	}
}

// Seed a minimal $wp_query stub in the global namespace for tests that read
// query_vars (e.g. page_id) without a real WordPress runtime.
// Tests that need a specific page_id call LfWpQueryStub::set_page_id().
if ( ! class_exists( 'LfWpQueryStub' ) ) {
	/**
	 * Minimal $wp_query stub for unit tests that read query_vars.
	 */
	class LfWpQueryStub {
		private static int $page_id = 0;

		public static function set_page_id( int $id ): void {
			self::$page_id = $id;
		}

		public static function reset(): void {
			self::$page_id = 0;
		}

		/** Mirror of WP_Query::get() for the keys WcPageBridge reads. */
		public function get( string $query_var, mixed $fallback = '' ): mixed {
			if ( 'page_id' === $query_var ) {
				return self::$page_id;
			}
			return $fallback;
		}
	}
}

// Register the global $wp_query stub once; tests call LfWpQueryStub::set_page_id().
if ( ! isset( $GLOBALS['wp_query'] ) ) {
	$GLOBALS['wp_query'] = new LfWpQueryStub(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Returns $GLOBALS['lf_test_locale'] so tests can control the WP instance
	 * locale without a WordPress runtime.  Defaults to 'en_US'.
	 */
	function get_locale(): string {
		return $GLOBALS['lf_test_locale'] ?? 'en_US';
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
	 * apply_filters polyfill — honours $GLOBALS['lf_test_filters'] overrides so
	 * non-WC tests (Config, RateLimiter, …) can register filter callbacks
	 * regardless of which polyfill file PHPUnit loaded first.
	 * Falls through to returning $value unchanged when no override is registered,
	 * which is the correct behaviour for all WC integration test calls.
	 */
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$cb = $GLOBALS['lf_test_filters'][ $hook ] ?? null;
		if ( is_callable( $cb ) ) {
			return $cb( $value, ...$args );
		}
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
	/**
	 * get_option polyfill — checks LfWcMocks::$options first (for WC integration
	 * tests), then falls back to $GLOBALS['lf_test_options'] (for non-WC unit
	 * tests like Config, KeyStore, RateLimiter).  Uses array_key_exists so that
	 * an explicitly stored false/null in either store is returned correctly.
	 */
	function get_option( string $option, mixed $fallback = false ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- matches WP signature; renamed to $fallback.
		if ( array_key_exists( $option, LfWcMocks::$options ) ) {
			return LfWcMocks::$options[ $option ];
		}
		if ( isset( $GLOBALS['lf_test_options'] ) && array_key_exists( $option, $GLOBALS['lf_test_options'] ) ) {
			return $GLOBALS['lf_test_options'][ $option ];
		}
		return $fallback;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( mixed $key, string $group = '' ): bool {
		LfWcMocks::$cache_deletes[] = [ 'key' => $key, 'group' => $group ];
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Returns LfWcMocks::$current_user_can regardless of the requested
	 * capability — sufficient for the single edit_posts check in PageTagRepair.
	 *
	 * @param mixed ...$args Ignored.
	 */
	function current_user_can( mixed ...$args ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- capability args intentionally ignored; stub returns global flag.
		return \LfWcMocks::$current_user_can;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Returns $GLOBALS['lf_test_user_id'] (default 1) — same backing store as
	 * ApiPolyfills so the two polyfill files are interchangeable regardless of
	 * which one PHPUnit loads first.
	 */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['lf_test_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Returns LfWcMocks::$user_meta[$user_id][$key] when $single is true, or
	 * an empty string/array when absent.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_user_meta( int $user_id, string $key = '', bool $single = false ): mixed {
		$val = \LfWcMocks::$user_meta[ $user_id ][ $key ] ?? null;
		if ( $single ) {
			return $val !== null ? $val : '';
		}
		return $val !== null ? (array) $val : [];
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * wp_unslash polyfill — passes through the value unchanged.
	 * Unit tests never deal with magic-quoted input, so stripslashes is a no-op.
	 *
	 * @param mixed $value Input value.
	 * @return mixed Unchanged value.
	 */
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( mixed $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		return true;
	}
}

if ( ! function_exists( 'wc_stock_amount' ) ) {
	/**
	 * Minimal wc_stock_amount polyfill — returns the value cast to float,
	 * matching WC's behaviour for the common (non-decimal-configured) case.
	 */
	function wc_stock_amount( mixed $qty ): float {
		return (float) $qty;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * i18n polyfill — returns the source string unchanged.
	 * Sufficient for unit tests where no translation runtime is needed.
	 *
	 * @param string $text   The text to translate.
	 * @param string $domain Text domain (ignored in stub).
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $domain intentionally ignored; stub returns input unchanged.
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Strips HTML tags and trims the result, matching WordPress's observable
	 * behaviour for the strings our unit tests pass (plain integer IDs from
	 * $_GET parameters — strip_tags + trim is sufficient here).
	 *
	 * @param  string $str  Input string.
	 * @return string       Sanitized string.
	 */
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- polyfill; wp_strip_all_tags() is not available here.
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Minimal get_posts stub — evaluates `meta_query` conditions with AND
	 * relation against LfWcMocks::$meta and returns matching post IDs or
	 * WP_Post stubs.
	 *
	 * Supports:
	 *  - `meta_query` with a 'relation' string key + array condition entries.
	 *  - `fields => 'ids'` to return plain integer IDs.
	 *  - `posts_per_page` to cap results.
	 *
	 * @param array<string,mixed> $args WP_Query-style argument array.
	 * @return array<int,int|WP_Post>
	 */
	function get_posts( array $args = [] ): array {
		$meta_query = $args['meta_query'] ?? [];
		$fields     = $args['fields']     ?? '';
		$limit      = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;

		// Extract array conditions, discarding the 'relation' string key.
		$conditions = array_values( array_filter( $meta_query, 'is_array' ) );

		$results = [];
		foreach ( LfWcMocks::$meta as $post_id => $meta ) {
			$match = true;
			foreach ( $conditions as $condition ) {
				$key   = $condition['key']   ?? '';
				$value = $condition['value'] ?? '';
				$compare = strtoupper( $condition['compare'] ?? '=' );
				$raw_val = $meta[ $key ] ?? null;
				if ( 'IN' === $compare ) {
					if ( ! in_array( $raw_val, (array) $value, true ) ) {
						$match = false;
						break;
					}
				} elseif ( ( $raw_val ?? '' ) !== $value ) {
					$match = false;
					break;
				}
			}
			if ( ! $match ) {
				continue;
			}
			$results[] = ( 'ids' === $fields )
				? $post_id
				: ( LfWcMocks::$posts[ $post_id ] ?? $post_id );
			if ( $limit > 0 && count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * wp_cache_get polyfill — always returns false (cache miss).
	 *
	 * TridGroup::get_translations() calls wp_cache_get() first and skips the
	 * database query when it returns anything other than false.  Returning false
	 * unconditionally forces the method to go through $wpdb->get_results(), which
	 * our LfWpdb stub can control via LfWcMocks::$db_results.
	 *
	 * @param mixed  $key    Cache key.  Ignored.
	 * @param string $group  Cache group.  Ignored.
	 * @param bool   $force  Force fetch.  Ignored.
	 * @param mixed  $found  Set to false to indicate a cache miss.
	 * @return false Always false (cache miss).
	 */
	function wp_cache_get( mixed $key, string $group = '', bool $force = false, mixed &$found = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- matches WP API signature; $key/$group/$force intentionally ignored. Return type is `bool` (not the 8.2-only standalone `false`) to keep the file parseable on the PHP 8.1 floor; `@return false` above keeps the precise type for static analysis.
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	/**
	 * get_term_meta polyfill — returns values from LfWcMocks::$term_meta.
	 *
	 * Covers TermNameFilter::translate_variation_option_name() and similar
	 * callers that need to look up translated term names stored as term meta.
	 * Store test data via:
	 *   LfWcMocks::$term_meta[$term_id][$meta_key] = 'value';
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $key      Meta key.
	 * @param bool   $single   Return scalar or array.
	 * @return mixed  Scalar value (or '') when $single is true; array otherwise.
	 */
	function get_term_meta( int $term_id, string $key = '', bool $single = false ): mixed {
		$val = \LfWcMocks::$term_meta[ $term_id ][ $key ] ?? null;
		if ( $single ) {
			return $val !== null ? $val : '';
		}
		return $val !== null ? (array) $val : [];
	}
}

// =============================================================================
// LfTestTridGroup — TridGroup stub for unit tests
// =============================================================================

if ( ! class_exists( 'LfTestTridGroup' ) ) {
	/**
	 * Concrete TridGroup substitute for unit tests.
	 *
	 * Overrides get_trid(), get_lang(), and get_translations() to read directly
	 * from LfWcMocks instead of going through get_post_meta() and $wpdb.  This
	 * sidesteps any uncertainty about how global-namespace function lookups resolve
	 * from within the LinguaForge\Router\Translation namespace and gives tests
	 * deterministic, reset-safe control over mock data.
	 *
	 * Injected into the Router stub by WcUnitTestCase::inject_router_with_trid_group().
	 * The typed property `Router::$trid_group` expects a TridGroup instance; this
	 * class satisfies that constraint by extending TridGroup.
	 */
	class LfTestTridGroup extends \LinguaForge\Router\Translation\TridGroup {

		public function get_trid( int $id ): string {
			return (string) ( LfWcMocks::$meta[ $id ]['_lf_trid'] ?? '' );
		}

		public function get_lang( int $id ): string {
			return (string) ( LfWcMocks::$meta[ $id ]['_lf_lang'] ?? '' );
		}

		/**
		 * Returns a lang→post_id map built from LfWcMocks::$db_results.
		 *
		 * The real TridGroup queries the DB by trid; here the test controls which
		 * rows are "in the DB" by setting LfWcMocks::$db_results before the call.
		 * If the post has no _lf_trid, we return [] to match TridGroup behaviour.
		 */
		public function get_translations( int $post_id ): array {
			if ( '' === $this->get_trid( $post_id ) ) {
				return [];
			}
			$out = [];
			foreach ( LfWcMocks::$db_results as $r ) {
				$out[ $r->lang ] = (int) $r->post_id;
			}
			return $out;
		}
	}
}
