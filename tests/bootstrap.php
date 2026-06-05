<?php
/**
 * PHPUnit bootstrap for Lingua Forge.
 *
 * Two paths through this file:
 *
 *  • Unit suite ( --testsuite=unit )
 *      WP_TESTS_DIR is not present. We load the Composer autoloader plus
 *      the Yoast polyfills and stop — unit tests can `use` plugin classes
 *      that don't touch WordPress. Classes that DO touch WordPress (most
 *      of them) belong in the integration suite, not here.
 *
 *  • Integration suite ( --testsuite=integration )
 *      Run inside wp-env (or any environment that exposes the WordPress
 *      PHPUnit framework via $WP_TESTS_DIR). We load the WP test bootstrap,
 *      hook the plugin onto muplugins_loaded so it boots inside the test
 *      WordPress install, and finally include the WP includes/bootstrap.php.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

// ── Resolve WP_TESTS_DIR ─────────────────────────────────────────────────────
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
    $wp_phpunit = getenv( 'WP_PHPUNIT__DIR' );
    if ( $wp_phpunit ) {
        $wp_tests_dir = $wp_phpunit;
    }
}

// Composer autoload — required for both unit and integration paths
// (PHPUnit itself, Yoast polyfills, dev libraries).
$autoload = __DIR__ . '/../dev/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

// Yoast PHPUnit polyfills — provides PHPUnit 9-compatible assertions on
// older WordPress test suites. The Autoload class is an spl_autoload
// callback for non-Composer setups; when PHPUnit is run from lingua-forge-dev
// the Composer autoloader already loaded by PHPUnit covers the polyfill
// traits, so no manual registration is needed here.

// ── Unit suite path ──────────────────────────────────────────────────────────
// No WP test framework available, or we're being run for the unit suite
// only. Stop here — unit tests are not allowed to touch WordPress.
if ( ! $wp_tests_dir || ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {

    // Define the bare minimum constants so plugin source files that exit
    // on `! defined( 'ABSPATH' )` can still be require'd by unit tests
    // that exercise pure-function utilities.
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', dirname( __DIR__ ) . '/' );
    }

    // Plugin constants — mirror the ones lingua-forge.php would define on
    // a real WP boot. Unit tests should not depend on these, but a few
    // helper classes reference LINGUAFORGE_PATH for include resolution.
    if ( ! defined( 'LINGUAFORGE_FILE' ) ) {
        define( 'LINGUAFORGE_FILE', dirname( __DIR__ ) . '/lingua-forge.php' );
    }
    if ( ! defined( 'LINGUAFORGE_PATH' ) ) {
        define( 'LINGUAFORGE_PATH', dirname( __DIR__ ) . '/' );
    }
    if ( ! defined( 'LINGUAFORGE_URL' ) ) {
        define( 'LINGUAFORGE_URL', 'http://example.org/wp-content/plugins/lingua-forge/' );
    }
    if ( ! defined( 'LINGUAFORGE_VERSION' ) ) {
        define( 'LINGUAFORGE_VERSION', '0.0.0-test' );
    }
    if ( ! defined( 'LINGUAFORGE_AI_PATH' ) ) {
        define( 'LINGUAFORGE_AI_PATH', LINGUAFORGE_PATH . 'ai' );
    }
    if ( ! defined( 'LINGUAFORGE_AI_URL' ) ) {
        define( 'LINGUAFORGE_AI_URL', LINGUAFORGE_URL . 'ai' );
    }

    // Minimal polyfills for WordPress functions used by pure-function
    // utilities that we still want to exercise from the unit suite.
    // Each polyfill matches WordPress's own signature and observable
    // behaviour for the parameters our code passes — they're not
    // general-purpose replacements.
    if ( ! function_exists( 'wp_json_encode' ) ) {
        /**
         * Polyfill — see wp-includes/functions.php. The real
         * wp_json_encode() has filter hooks and depth-overflow
         * handling we don't need under unit-test conditions; for the
         * shapes our code passes (string + JSON_UNESCAPED_UNICODE),
         * json_encode is identical.
         */
        function wp_json_encode( $data, $options = 0, $depth = 512 ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This IS the polyfill for wp_json_encode. The sniff's "use wp_json_encode instead" recommendation is what this function provides; calling json_encode here is the implementation.
            return json_encode( $data, $options, $depth );
        }
    }

    // Unit-test classmap autoloader.
    //
    // The plugin uses the WordPress file-naming convention (class-*.php) which
    // is not PSR-4-compatible, so Composer can't resolve plugin source classes
    // without an explicit classmap. Rather than requiring `composer dump-autoload`
    // after every change, we register the map here so the unit suite is
    // self-contained and always up to date.
    //
    // Add an entry whenever a new unit test needs a plugin class that lives
    // outside the tests/ directory. Only map the class(es) the test actually
    // uses via ReflectionClass or direct reference — do NOT chain-load the
    // full require_once hierarchy from language-router.php, which would call
    // WP functions and boot the whole plugin.
    $lf_plugin_root = dirname( __DIR__ );
    $lf_classmap    = [
        'LinguaForge\\Router\\Router' =>
            $lf_plugin_root . '/language-router/includes/class-language-router.php',
    ];
    spl_autoload_register(
        static function ( string $class ) use ( $lf_classmap ): void {
            if ( isset( $lf_classmap[ $class ] ) ) {
                require_once $lf_classmap[ $class ];
            }
        }
    );

    return; // ← unit suite stops here.
}

// ── Integration suite path ───────────────────────────────────────────────────

// Integration test classmap autoloader.
//
// PHPUnit 9 processes <directory> elements before <file> elements regardless
// of XML order, so we cannot rely on a <file> entry to pre-load abstract base
// classes before the concrete subclasses are discovered. Register an
// spl_autoload_register here instead — it fires on demand whenever PHP needs
// a class, regardless of discovery order.
//
// Add an entry whenever a new integration test base class is introduced that
// lives outside the Composer classmap.
$lf_integration_classmap = [
    'LinguaForge\Tests\Integration\WooCommerce\WcIntegrationTestCase' =>
        __DIR__ . '/integration/WooCommerce/WcIntegrationTestCase.php',
    'LinguaForge\Tests\Integration\Stubs\StubProvider' =>
        __DIR__ . '/integration/Stubs/StubProvider.php',
];
spl_autoload_register(
    static function ( string $class ) use ( $lf_integration_classmap ): void {
        if ( isset( $lf_integration_classmap[ $class ] ) ) {
            require_once $lf_integration_classmap[ $class ];
        }
    }
);

// Load WP test framework function helpers (tests_add_filter, …).
require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Boot the plugin inside the test WordPress install.
 *
 * tests_add_filter hooks onto muplugins_loaded which runs before the
 * WordPress test bootstrap installs the test DB — that's the canonical
 * point to require the plugin's main file.
 */
tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        // Use WP_PLUGIN_DIR rather than dirname(__DIR__) so this path
        // resolves correctly whether tests/ is mounted directly under the
        // WordPress root (wp-env container) or sits as a sibling of the
        // plugin root (local checkout). WP_PLUGIN_DIR is defined by
        // wp-settings.php before muplugins_loaded fires.
        require WP_PLUGIN_DIR . '/lingua-forge/lingua-forge.php';

        // Load WooCommerce if installed — the WP test framework resets
        // active_plugins on every run so wp plugin activate has no effect.
        // Loading here (before plugins_loaded fires) is safe: WooCommerce
        // registers its init hooks on plugins_loaded, which still fires
        // after muplugins_loaded completes. When the file is absent (base
        // env without the override) WC integration tests skip gracefully.
        $wc_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
        if ( file_exists( $wc_file ) ) {
            require $wc_file;
        }
    }
);

// Start the WordPress test framework. After this point, WP_UnitTestCase,
// the factory APIs, and the full WordPress runtime are available.
//
// Suppress E_WARNING for constant redefinition: when a test runs with
// @runInSeparateProcess, PHP spawns a child that re-runs this bootstrap
// with the parent's environment (including WP_MEMORY_LIMIT already set).
// WordPress's own bootstrap.php redefines WP_MEMORY_LIMIT unconditionally,
// generating a warning that PHPUnit promotes to an error. The warning is
// harmless — the value is identical — so we narrow error_reporting around
// the include and restore it immediately after.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Intentional: narrowing error_reporting around WP bootstrap to suppress a harmless WP_MEMORY_LIMIT redefinition warning in child processes spawned by @runInSeparateProcess.
$lf_prev_error_reporting = error_reporting( error_reporting() & ~E_WARNING );
require $wp_tests_dir . '/includes/bootstrap.php';
error_reporting( $lf_prev_error_reporting );
// phpcs:enable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
