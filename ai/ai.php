<?php
/**
 * LinguaForge AI — sub-module of LinguaForge.
 * Loaded by lingua-forge.php; not a standalone plugin.
 */

defined( 'ABSPATH' )          || exit;
defined( 'LINGUAFORGE_PATH' ) || exit; // Must be loaded via lingua-forge.php

define( 'LINGUAFORGE_AI_PATH', __DIR__ );
define( 'LINGUAFORGE_AI_URL',  LINGUAFORGE_URL . 'ai' );

require_once LINGUAFORGE_AI_PATH . '/includes/Core/Autoloader.php';

\LinguaForge\AI\Core\Plugin::init();

// ── WP-CLI commands ───────────────────────────────────────────────────────
// Registered eagerly so they're available the first time `wp linguaforge …`
// dispatches. The Commands class itself is autoloaded lazily on the first
// method invocation — registration is a hash insert into WP_CLI's command
// table, not a class instantiation.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command(
        'linguaforge',
        \LinguaForge\AI\CLI\Commands::class
    );
}