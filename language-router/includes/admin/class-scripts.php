<?php
/**
 * Class LinguaForge\Router\Admin\Scripts
 *
 * Enqueues all Language Router JavaScript: the admin metabox script (post edit
 * screens), the quick-edit script (list screens), and the frontend AJAX
 * language interceptor.
 *
 * The three scripts live as real files under language-router/assets/ and are
 * enqueued through the standard wp_enqueue_script() pipeline. Dynamic values
 * (nonces, source language, active language) are passed as localised JS
 * objects via wp_add_inline_script(…, 'before').
 */

namespace LinguaForge\Router\Admin;

use LinguaForge\Router\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Scripts {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
	}

	// =========================================================
	// HOOK REGISTRATION
	// =========================================================

	public function register_hooks(): void {
		// Frontend AJAX lang — appends ?lang=X to every same-origin XHR/fetch
		// request URL. Hooked to wp_enqueue_scripts (priority 20) so that
		// LF_LANG is already defined by the template_redirect
		// language-detection pass.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_lang_script' ], 20 );
	}

	public function register_admin_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_lang_scripts' ] );
	}

	// =========================================================
	// ADMIN SCRIPTS
	// =========================================================

	/**
	 * Enqueue admin JavaScript for the language metabox and quick-edit row.
	 *
	 * Each script is a real file under language-router/assets/; dynamic values
	 * (nonces, source language) are prepended as a localised data object via
	 * wp_add_inline_script(…, 'before') — the canonical Plugin Check-friendly
	 * pattern.
	 */
	public function enqueue_admin_lang_scripts( string $hook_suffix ): void {

		$base_url = LINGUAFORGE_URL . 'language-router/assets/';
		$version  = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;

		// Import-translation button + language-change select: post edit screens only.
		if ( in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			wp_enqueue_style(
				'lf-admin-metabox',
				$base_url . 'admin-metabox.css',
				[],
				$version
			);

			wp_enqueue_script(
				'lf-admin-metabox',
				$base_url . 'admin-metabox.js',
				[ 'jquery' ],
				$version,
				true
			);

			// Pass nonces + source language. Template staging in JS uses the
			// source language to decide whether to clear or set the template slug.
			// No availableTemplates list — the slug is computed from language +
			// post type and staged unconditionally; PHP handles the meta write.
			wp_add_inline_script(
				'lf-admin-metabox',
				'var lfAdminMetabox = ' . wp_json_encode( [
					'importNonce'    => wp_create_nonce( 'lf_import_translation_nonce' ),
					'langNonce'      => wp_create_nonce( 'lf_set_language_nonce' ),
					'sourceLanguage' => $this->router->context->source_language(),
				] ) . ';',
				'before'
			);
		}

		// Quick-edit row: list screens only.
		if ( 'edit.php' === $hook_suffix ) {
			wp_enqueue_script(
				'lf-quick-edit',
				$base_url . 'quick-edit.js',
				[ 'jquery' ],
				$version,
				true
			);
		}
	}

	// =========================================================
	// FRONTEND SCRIPT
	// =========================================================

	/**
	 * Enqueue the script that appends ?lang=X to every same-origin XHR and
	 * fetch() request so PHP's detect_lang_safe() can read it from $_GET.
	 *
	 * No jQuery dependency — the script patches XMLHttpRequest.prototype.open
	 * and window.fetch directly, which covers jQuery.ajax, Backbone.sync,
	 * and native fetch() callers alike. Appending to the URL query string
	 * (rather than the POST body as the old jQuery ajaxSend approach did)
	 * ensures detect_lang_safe()'s $_GET['lang'] step picks it up for every
	 * HTTP method.
	 *
	 * Third-party endpoints (Stripe, reCAPTCHA, etc.) are excluded by the
	 * scoping rule inside frontend-lang.js — see that file's header comment
	 * for the rationale.
	 */
	public function enqueue_frontend_lang_script(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			return;
		}

		$version = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;

		wp_enqueue_script(
			'lf-frontend-lang',
			LINGUAFORGE_URL . 'language-router/assets/frontend-lang.js',
			[],
			$version,
			true
		);

		// Embed the current language as a JS constant so the script body
		// stays free of PHP — avoids caching issues with opcode or full-page
		// caches.
		wp_add_inline_script(
			'lf-frontend-lang',
			'var lfFrontendLang = ' . wp_json_encode( [ 'lang' => LF_LANG ] ) . ';',
			'before'
		);
	}
}
