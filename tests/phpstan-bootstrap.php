<?php
/**
 * PHPStan bootstrap.
 *
 * Defines the plugin-level constants that PHPStan would otherwise mark as
 * undefined when scanning files that reference them at module-load time.
 * Loaded via phpstan.neon.dist → bootstrapFiles.
 *
 * Real values aren't important — PHPStan only needs the names defined and
 * (where the type matters) a representative value.
 */

defined( 'ABSPATH' )            || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'LINGUAFORGE_FILE' )   || define( 'LINGUAFORGE_FILE', __DIR__ . '/../lingua-forge.php' );
defined( 'LINGUAFORGE_PATH' )   || define( 'LINGUAFORGE_PATH', __DIR__ . '/../' );
defined( 'LINGUAFORGE_URL' )    || define( 'LINGUAFORGE_URL', 'http://example.org/wp-content/plugins/lingua-forge/' );
defined( 'LINGUAFORGE_VERSION' )|| define( 'LINGUAFORGE_VERSION', '0.0.0-static' );
defined( 'LINGUAFORGE_AI_PATH' )|| define( 'LINGUAFORGE_AI_PATH', LINGUAFORGE_PATH . 'ai' );
defined( 'LINGUAFORGE_AI_URL' ) || define( 'LINGUAFORGE_AI_URL', LINGUAFORGE_URL . 'ai' );
defined( 'LF_LANG' )            || define( 'LF_LANG', 'en' );

defined( 'WP_CLI' )             || define( 'WP_CLI', false );
defined( 'WPINC' )              || define( 'WPINC', 'wp-includes' );
