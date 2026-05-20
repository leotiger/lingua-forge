<?php
/**
 * Class LinguaForge\Router\I18n\Overrides
 *
 * Loads user-managed .mo translation overrides from the uploads-based
 * i18n-overrides directory at translation-file load time.
 */

namespace LinguaForge\Router\I18n;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Overrides {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Priority 1 — must fire before most plugins that call __() on 'init'.
		add_action( 'init', [ $this, 'load_translation_files' ], 1 );
	}

	// =========================================================
	// TRANSLATION FILE LOADING
	// =========================================================

	public function load_translation_files(): void {
		$locale = determine_locale();

		if ( $locale === 'ca_ES' ) $locale = 'ca';

		// Auto-load any {textdomain}-{locale}.mo file from the uploads-based
		// override directory (wp-content/uploads/lingua-forge/i18n-overrides/).
		// Files here survive plugin updates and are never part of the codebase.
		// Lingua Forge's own translations live in languages/ (standard WP location).
		$dir    = $this->router->context->i18n_overrides_dir();
		$suffix = '-' . $locale . '.mo';

		foreach ( glob( $dir . '*' . $suffix ) ?: [] as $mofile ) {
			// Strip '-{locale}' from the end of the basename to get the text domain.
			// e.g. "vikbooking-it_IT" with locale "it_IT" → "vikbooking"
			//      "complianz-gdpr-ca" with locale "ca"    → "complianz-gdpr"
			$textdomain = substr( basename( $mofile, '.mo' ), 0, -(strlen( $locale ) + 1) );
			if ( $textdomain !== '' ) {
				load_textdomain( $textdomain, $mofile );
			}
		}
	}
}
