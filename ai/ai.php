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