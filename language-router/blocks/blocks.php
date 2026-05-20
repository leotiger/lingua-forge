<?php
/**
 * Lingua Forge — Language Router blocks bootstrap.
 *
 * Registers every block in this folder by walking the subdirectories and
 * passing each one to `register_block_type()`. WordPress's
 * block.json-aware registrar handles the rest (attributes, supports,
 * server-side render callback).
 *
 * Hooked at `init` priority 10 because:
 *   • `register_block_type()` must fire after `init` so the WordPress
 *     block API is fully bootstrapped.
 *   • The plugin's `register_meta()` calls are at the default `init`
 *     priority; blocks need to register no earlier than that.
 *
 * To add a new block, create a sibling folder containing a `block.json`
 * (with `render: "file:./render.php"` or `editor`/`view` script paths
 * as needed) and the registrar will pick it up automatically — no
 * change needed here.
 *
 * @package LinguaForge\Router\Blocks
 */

defined( 'ABSPATH' )            || exit;
defined( 'LINGUAFORGE_PATH' )   || exit;

add_action( 'init', static function (): void {

    $blocks_dir = __DIR__;

    foreach ( glob( $blocks_dir . '/*', GLOB_ONLYDIR ) as $block_dir ) {

        if ( file_exists( $block_dir . '/block.json' ) ) {
            register_block_type( $block_dir );
        }
    }
} );
