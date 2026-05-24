<?php
/**
 * Asset manifest for the missing-translation-notice editor script.
 *
 * Lists the WordPress script handles that must be enqueued before
 * index.js loads.  Written by hand (no build step) — update whenever
 * the wp.* globals used in index.js change.
 *
 * @package LinguaForge\Router\Blocks
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-i18n',
		'wp-server-side-render',
	),
	'version' => '1.0.0',
);
