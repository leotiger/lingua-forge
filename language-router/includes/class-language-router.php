<?php
/**
 * Class LinguaForge\Router\Router (aliased as Language_Router for back-compat).
 *
 * Core routing, translation, and admin logic.
 * Singleton — access via Language_Router::get_instance() or
 * \LinguaForge\Router\Router::get_instance().
 *
 * The unqualified `Language_Router` name remains available for one release
 * via class_alias() at the bottom of this file so theme and third-party code
 * that referenced the old global class keeps working unchanged.
 */

namespace LinguaForge\Router;

use WP_Query;
use WP_Locale_Switcher;

if ( ! defined( 'ABSPATH' ) ) exit;

class Router {

	// =========================================================
	// SINGLETON
	// =========================================================

	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->define_lang_constant();
		$this->register_hooks();
	}

	// =========================================================
	// CONSTANTS / VERSION
	// =========================================================

	const ROUTER_VERSION = '1.3.4';

	// =========================================================
	// INSTANCE CACHES  (avoids repeated filesystem + filter calls)
	// =========================================================

	private ?array  $cached_languages        = null;
	private ?string $cached_source_language  = null;

	// =========================================================
	// BOOTSTRAP
	// =========================================================

	/**
	 * Define LF_LANG constant as early as possible (file-load time via constructor).
	 */
	private function define_lang_constant(): void {
		if ( defined( 'LF_LANG' ) ) return;
		define( 'LF_LANG', $this->detect_lang_safe() );
	}

	/**
	 * Register the hooks that need to fire on every request type.
	 *
	 * Anything that runs on the frontend (locale, rewrite, permalinks, SEO,
	 * template_redirect, render_block) lives here, plus the handful of hooks
	 * that fire on REST or AJAX flows where is_admin() returns false:
	 *
	 *   - wp_after_insert_post (handle_save_post, handle_cache_clear) —
	 *     fires from REST `/wp/v2/posts/{id}` PUTs as well as admin saves.
	 *   - block_editor_settings_all — the block editor pulls its settings
	 *     via a REST request when loading on a post-edit screen.
	 *
	 * Admin-only hooks (post list columns, quick edit, meta boxes, admin JS,
	 * admin AJAX, admin menu) are split into register_admin_hooks() and
	 * registered only when is_admin() is true. That skips ~15 add_action /
	 * add_filter calls on every anonymous frontend page render.
	 */
	private function register_hooks(): void {

		// Meta
		add_action( 'init', [ $this, 'register_meta' ] );

		// Block editor settings — fires from REST in the block editor's
		// settings request, so we can't gate this on is_admin().
		add_filter( 'block_editor_settings_all', [ $this, 'restrict_block_editor_settings' ], 10, 2 );

		// Locale
		add_action( 'plugins_loaded', [ $this, 'apply_locale' ], 0 );
		add_filter( 'determine_locale', [ $this, 'filter_determine_locale' ], 0 );

		// Translation files (early, priority 1)
		add_action( 'init', [ $this, 'load_translation_files' ], 1 );

		// Init redirects
		add_action( 'init', [ $this, 'handle_init_redirects' ], 0 );

		// Rewrite rules
		add_action( 'init', [ $this, 'register_rewrite_rules' ] );

		// Query vars
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );

		// Vik Booking locale compat
		add_filter( 'locale', [ $this, 'filter_locale_for_vik_booking' ], 0 );

		// parse_query
		add_action( 'parse_query', [ $this, 'handle_parse_query' ] );

		// pre_get_posts
		add_action( 'pre_get_posts', [ $this, 'handle_pre_get_posts' ] );

		// Template redirect
		add_action( 'template_redirect', [ $this, 'handle_singular_redirect' ], 1 );
		add_action( 'template_redirect', [ $this, 'handle_homepage_redirect' ] );
		add_action( 'template_redirect', [ $this, 'normalize_duplicate_slashes' ], 0 );
		add_action( 'template_redirect', [ $this, 'redirect_search_under_lang_prefix' ] );

		// Site logo link
		add_filter( 'render_block', [ $this, 'fix_site_logo_link' ], 20, 2 );

		// Menu translation
		add_filter( 'wp_nav_menu_objects', [ $this, 'translate_menu_items' ] );

		// Permalink filters — called from both admin (e.g. building list-table
		// links) and frontend (template rendering).
		add_filter( 'post_link', [ $this, 'lang_permalink' ], 10, 2 );
		add_filter( 'page_link', [ $this, 'lang_permalink' ], 10, 2 );

		// get_pages filter — fires whenever get_pages() is called, including
		// from the parent-selector in admin AND from front-end widgets/themes.
		add_filter( 'get_pages', [ $this, 'filter_pages_by_lang' ], 10, 2 );

		// Save handler — REST saves (e.g. block editor) hit this too.
		add_action( 'wp_after_insert_post', [ $this, 'handle_save_post' ], 10, 2 );

		// Cache clear on save
		add_action( 'wp_after_insert_post', [ $this, 'handle_cache_clear' ], 20 );

		// SEO / hreflang
		add_action( 'wp_head', [ $this, 'print_hreflang_tags' ], 1 );
		add_action( 'wp',      [ $this, 'remove_core_canonical' ] );
		add_action( 'init',    [ $this, 'disable_seo_plugin_hreflang' ] );

		// Search
		add_filter( 'get_block_templates', [ $this, 'override_search_template' ], 10, 3 );
		add_filter( 'render_block',        [ $this, 'fix_search_form' ], 20, 2 );
		add_filter( 'posts_search',        [ $this, 'extend_posts_search' ], 20, 2 );
		add_filter( 'posts_clauses',       [ $this, 'boost_title_in_search' ], 20, 2 );

		// Frontend AJAX lang — appends current language to every jQuery AJAX request.
		// Hooked to wp_enqueue_scripts (fires after template_redirect so LF_LANG is defined).
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_lang_script' ], 20 );

		// DB version / index
		add_action( 'plugins_loaded', [ $this, 'check_db_version' ], 1 );

		// Debug
		add_action( 'wp',   [ $this, 'debug_request_context' ] );
		add_action( 'init', [ $this, 'debug_system_init' ] );

		// Admin-only hooks — registered lazily to skip the ~15 add_* calls
		// on every anonymous frontend page render.
		if ( is_admin() ) {
			$this->register_admin_hooks();
		}
	}

	/**
	 * Hooks that only matter inside wp-admin/* (including admin-ajax.php,
	 * for which is_admin() returns true).
	 *
	 * Each hook here meets one of:
	 *   - The action/filter is fired by WP only in admin context, so
	 *     registering it on the frontend would be functionally a no-op but
	 *     still costs an add_action() call.
	 *   - The action handles a wp_ajax_* endpoint, which only routes through
	 *     /wp-admin/admin-ajax.php.
	 *
	 * Split out per REVIEW.md §3.3.
	 */
	private function register_admin_hooks(): void {

		// Admin menu
		add_action( 'admin_menu', [ $this, 'add_navigation_menu_page' ] );

		// Persist admin lang filter
		add_action( 'load-edit.php', [ $this, 'persist_admin_lang_filter' ] );

		// Admin meta boxes
		add_action( 'add_meta_boxes', [ $this, 'add_language_meta_box' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_template_meta_box' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_translations_meta_box' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_source_footnotes_meta_box' ] );

		// AJAX
		add_action( 'wp_ajax_lf_import_translation', [ $this, 'ajax_import_translation' ] );

		// Admin JS — enqueued via wp_add_inline_script so no hardcoded <script> tags.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_lang_scripts' ] );

		// Admin columns
		add_filter( 'manage_posts_columns', [ $this, 'add_lang_column' ] );
		add_filter( 'manage_pages_columns', [ $this, 'add_lang_column' ] );
		add_action( 'manage_posts_custom_column', [ $this, 'render_lang_column' ], 10, 2 );
		add_action( 'manage_pages_custom_column', [ $this, 'render_lang_column' ], 10, 2 );

		// Quick edit
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit_box' ], 10, 2 );

		// Admin filters (language + outdated dropdowns)
		add_action( 'restrict_manage_posts', [ $this, 'render_lang_filter_dropdown' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_outdated_filter_dropdown' ] );
	}

	// =========================================================
	// CONFIG
	// =========================================================

	public function source_language(): string {
		if ( $this->cached_source_language !== null ) return $this->cached_source_language;
		$stored = sanitize_key( (string) get_option( 'linguaforge_primary_language', 'ca' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $this->cached_source_language = apply_filters( 'lf_primary_language', $stored ?: 'ca' );
	}

	public function languages(): array {
		if ( $this->cached_languages !== null ) return $this->cached_languages;

		// Start with languages WordPress core knows about.
		$locales   = get_available_languages();
		$locales[] = get_locale();

		// Also auto-discover languages from the plugin's own .mo files so that
		// adding e.g. vikbooking-it_IT.mo is sufficient — no WP core language
		// pack and no manual filter needed.
		foreach ( $this->discover_plugin_locales() as $locale ) {
			$locales[] = $locale;
		}

		$langs = [];
		foreach ( $locales as $locale ) {
			$langs[] = strtolower( substr( $locale, 0, 2 ) );
		}

		$langs[] = $this->source_language();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $this->cached_languages = apply_filters( 'lf_languages_list', array_values( array_unique( $langs ) ) );
	}

	/**
	 * Absolute path to the user-managed i18n overrides directory.
	 *
	 * Stored in the uploads folder so files survive plugin updates and are
	 * never part of the plugin codebase:
	 *   wp-content/uploads/lingua-forge/i18n-overrides/
	 *
	 * Drop {textdomain}-{locale}.mo files here to override a plugin's strings
	 * (e.g. vikbooking-ca.mo to swap "room" → "apartment" in Catalan).
	 * No code changes needed when adding new plugins or locales.
	 *
	 * Filterable via lf_i18n_overrides_dir for custom storage locations.
	 *
	 * @return string  Trailing-slash path.
	 */
	private function i18n_overrides_dir(): string {

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'lingua-forge/i18n-overrides/';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return (string) apply_filters( 'lf_i18n_overrides_dir', $dir );
	}

	/**
	 * Scan the i18n-overrides/ directory and return every unique locale code
	 * found across all third-party .mo override files.
	 *
	 * Files follow the standard WordPress naming convention:
	 *   {textdomain}-{locale}.mo
	 *
	 * The locale suffix is either a bare two-letter code ("ca", "ja") or a
	 * language_COUNTRY pair ("it_IT", "pt_PT", "de_DE"). Both forms are matched
	 * by the regex so any plugin's translation file is accepted automatically —
	 * no anchor on a specific text domain is needed.
	 *
	 * @return string[]  e.g. ['it_IT', 'pt_PT', 'de_DE', 'ca', …]
	 */
	private function discover_plugin_locales(): array {
		$files   = glob( $this->i18n_overrides_dir() . '*.mo' ) ?: [];
		$locales = [];

		foreach ( $files as $file ) {
			// Match the locale at the end: either "xx_XX" or bare "xx".
			if ( preg_match( '/-([a-z]{2}(?:_[A-Z]{2})?)\.mo$/i', $file, $m ) ) {
				$locales[] = $m[1];
			}
		}

		return array_unique( $locales );
	}

	// =========================================================
	// META REGISTRATION
	// =========================================================

	public function register_meta(): void {
		$auth = function() { return current_user_can( 'edit_posts' ); };

		register_post_meta( '', '_lang', [
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
			'auth_callback' => $auth,
		] );

		register_post_meta( '', '_trid', [
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
			'auth_callback' => $auth,
		] );

		register_post_meta( '', '_source_updated_at', [
			'type'         => 'number',
			'single'       => true,
			'show_in_rest' => true,
			'auth_callback' => $auth,
		] );

		register_post_meta( '', '_translation_source_updated_at', [
			'type'         => 'number',
			'single'       => true,
			'show_in_rest' => true,
			'auth_callback' => $auth,
		] );
	}

	// =========================================================
	// ADMIN MENU
	// =========================================================

	public function add_navigation_menu_page(): void {
		add_menu_page(
			'Navigation (List)',
			'Navigation (List)',
			'edit_posts',
			'edit.php?post_type=wp_navigation',
			'',
			'dashicons-menu',
			20
		);
	}

	// =========================================================
	// BLOCK EDITOR SETTINGS
	// =========================================================

	public function restrict_block_editor_settings( array $settings, $context ): array {

		// Defaults preserve pre-1.4 behavior: both restrictions ON unless an
		// admin explicitly opts out via Settings → Lingua Forge AI → Behavior.
		// The lf_block_editor_restrictions filter lets opinionated sites
		// override programmatically (e.g. a custom MU plugin can enable block
		// locking for a single user role).
		$restrictions = [
			// true  = WordPress default applies (feature available)
			// false = Lingua Forge restricts the feature
			'canLockBlocks'         => ! (bool) get_option( 'linguaforge_block_editor_allow_lock_blocks', false ),
			'supportsTemplateMode' => ! (bool) get_option( 'linguaforge_block_editor_allow_template_mode', false ),
		];

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$restrictions = (array) apply_filters( 'lf_block_editor_restrictions', $restrictions, $context );

		if ( ! empty( $restrictions['canLockBlocks'] ) ) {
			$settings['canLockBlocks'] = false;
		}

		if ( ! empty( $context->post ) && ! empty( $restrictions['supportsTemplateMode'] ) ) {
			$settings['supportsTemplateMode'] = false;
		}

		return $settings;
	}

	// =========================================================
	// LANGUAGE DETECTION
	// =========================================================

	public function is_valid_lang( $lang ): bool {
		return in_array( $lang, $this->languages(), true );
	}

	public function locale_from_lang( string $lang ): string {
		static $cache = [];

		// Normalise first so the cache key is always lowercase.
		$lang = strtolower( $lang );

		if ( isset( $cache[$lang] ) ) return $cache[$lang];

		// 1. Hard overrides
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$force = apply_filters( 'lf_lang_force_locale', [
			'ca' => 'ca',
		] );

		if ( isset( $force[$lang] ) ) {
			return $cache[$lang] = $force[$lang];
		}

		// 2. Installed WP language packs + plugin-bundled locales
		$known_locales = array_merge( get_available_languages(), $this->discover_plugin_locales() );
		foreach ( $known_locales as $locale ) {
			$locale_l = strtolower( $locale );
			if ( $locale_l === $lang || str_starts_with( $locale_l, $lang . '_' ) ) {
				return $cache[$lang] = $locale;
			}
		}

		// 3. Fallback map — extend via the filter for custom or regional variants.
		//    'pt' defaults to pt_PT (Portugal); override with 'pt' => 'pt_BR' if needed.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$fallback_map = apply_filters( 'lf_lang_fallback_map', [
			'ca' => 'ca',
			'en' => 'en_US',
			'es' => 'es_ES',
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'it' => 'it_IT',
			'pt' => 'pt_PT',
			'nl' => 'nl_NL',
			'pl' => 'pl_PL',
			'ru' => 'ru_RU',
			'sv' => 'sv_SE',
			'da' => 'da_DK',
			'nb' => 'nb_NO',
			'ro' => 'ro_RO',
			'hu' => 'hu_HU',
			'cs' => 'cs_CZ',
			'tr' => 'tr_TR',
			'el' => 'el',
			'ja' => 'ja',
			'zh' => 'zh_CN',
			'ko' => 'ko_KR',
			'ar' => 'ar',
			'he' => 'he_IL',
			'id' => 'id_ID',
		] );

		if ( isset( $fallback_map[$lang] ) ) {
			return $cache[$lang] = $fallback_map[$lang];
		}

		// 4. Default
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		return $cache[$lang] = apply_filters( 'lf_lang_default_fallback', 'en_US' );
	}

	public function language_label( string $lang ): string {
		$locale = $this->locale_from_lang( $lang );

		if ( function_exists( 'locale_get_display_language' ) ) {
			$label = locale_get_display_language( $locale, $locale );
			return mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' );
		}

		return strtoupper( $lang );
	}

	public function detect_lang_safe(): string {
		$langs   = $this->languages();
		$default = $this->source_language();

		// 1. URL
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = trim( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '/' );
		$seg = explode( '/', $uri );
		if ( ! empty( $seg[0] ) ) {
			$url_lang = strtolower( $seg[0] );
			if ( in_array( $url_lang, $langs, true ) ) return $url_lang;
		}

		// 2. GET
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
		if ( ! empty( $_GET['lang'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$q_lang = strtolower( sanitize_key( wp_unslash( $_GET['lang'] ) ) );
			if ( in_array( $q_lang, $langs, true ) ) return $q_lang;
		}

		// 3. Cookie
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
		if ( ! empty( $_COOKIE['lf_lang'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
			$cookie_lang = strtolower( trim( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ) ) ) );
			if ( str_contains( $cookie_lang, '-' ) ) {
				$cookie_lang = substr( $cookie_lang, 0, 2 );
			}
			if ( in_array( $cookie_lang, $langs, true ) ) return $cookie_lang;
		}

		return $default;
	}

	public function detect_lang(): string {
		$langs   = $this->languages();
		$default = $this->source_language();

		// 1. URL
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = trim( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '/' );
		$seg = explode( '/', $uri );
		if ( ! empty( $seg[0] ) ) {
			$url_lang = strtolower( $seg[0] );
			if ( in_array( $url_lang, $langs, true ) ) return $url_lang;
		}

		// 2. Cookie
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
		if ( ! empty( $_COOKIE['lf_lang'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
			$cookie_lang = strtolower( trim( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ) ) ) );
			if ( str_contains( $cookie_lang, '-' ) ) {
				$cookie_lang = substr( $cookie_lang, 0, 2 );
			}
			if ( in_array( $cookie_lang, $langs, true ) ) return $cookie_lang;
		}

		return $default;
	}

	// =========================================================
	// LOCALE
	// =========================================================

	public function apply_locale(): void {
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $wp_locale_switcher is WordPress core's own global; we are initialising it defensively, not defining a plugin variable.
		if ( ! isset( $GLOBALS['wp_locale_switcher'] ) ) {
			$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
			$GLOBALS['wp_locale_switcher']->init();
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		$locale = $this->locale_from_lang( LF_LANG );
		if ( $locale !== get_locale() ) {
			switch_to_locale( $locale );
		}
	}

	public function filter_determine_locale( string $locale ): string {
		if ( ! defined( 'LF_LANG' ) ) return $locale;

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) return $locale;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $locale;

		// 1. REQUEST (AJAX or manual)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads REQUEST parameters for routing; not form processing.
		if ( ! empty( $_REQUEST['lang'] ) && $this->is_valid_lang( sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads REQUEST parameters for routing; not form processing.
			return $this->locale_from_lang( sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) );
		}

		// 2. Frontend LF_LANG
		if ( ! empty( LF_LANG ) ) {
			return $this->locale_from_lang( LF_LANG );
		}

		// 3. Cookie fallback
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
		if ( ! empty( $_COOKIE['lf_lang'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cookie value is a language code validated immediately via is_valid_lang().
			$lang = substr( strtolower( sanitize_key( wp_unslash( $_COOKIE['lf_lang'] ) ) ), 0, 2 );
			if ( $this->is_valid_lang( $lang ) ) {
				return $this->locale_from_lang( $lang );
			}
		}

		return $locale;
	}

	public function filter_locale_for_vik_booking( string $locale ): string {
		if ( is_admin() ) return $locale;
		if ( ! defined( 'LF_LANG' ) ) return $locale;
		return $this->locale_from_lang( LF_LANG );
	}

	// =========================================================
	// TRANSLATION FILES
	// =========================================================

	public function load_translation_files(): void {
		$locale = determine_locale();

		if ( $locale === 'ca_ES' ) $locale = 'ca';

		// Auto-load any {textdomain}-{locale}.mo file from the uploads-based
		// override directory (wp-content/uploads/lingua-forge/i18n-overrides/).
		// Files here survive plugin updates and are never part of the codebase.
		// Lingua Forge's own translations live in languages/ (standard WP location).
		$dir    = $this->i18n_overrides_dir();
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

	// =========================================================
	// INIT REDIRECTS
	// =========================================================

	public function handle_init_redirects(): void {
		if ( $this->is_system_request() ) return;
		if ( is_admin() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		// Search redirect
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( isset( $_GET['s'] ) && preg_match( '#^/[a-z]{2}/#', $path ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : LF_LANG;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			$s    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			$this->debug( 'SEARCH REDIRECT: /?lang=' . $lang . '&s=' . $s );
			wp_safe_redirect( '/?lang=' . $lang . '&s=' . urlencode( $s ), 301 );
			exit;
		}

		// Homepage redirect
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( ( $path === '/' || $path === '' ) && empty( $_GET['s'] ) ) {
			$front_id = get_option( 'page_on_front' );
			if ( ! $front_id ) return;

			$translations = $this->get_translations( $front_id );

			if ( ! empty( $translations[LF_LANG] ) ) {
				$target = get_permalink( $translations[LF_LANG] );
				if ( untrailingslashit( $target ) !== untrailingslashit( home_url( '/' ) ) ) {
					$target = $this->safe_query_args( $target );
					wp_safe_redirect( $target, 302 );
					exit;
				}
			}
		}
	}

	// =========================================================
	// REWRITE RULES
	// =========================================================

	public function register_rewrite_rules(): void {
		$langs = implode( '|', $this->languages() );

		add_rewrite_tag( '%lang%', '(' . $langs . ')' );

		// Pagination
		add_rewrite_rule(
			'^(' . $langs . ')/page/([0-9]+)/?$',
			'index.php?lang=$matches[1]&paged=$matches[2]',
			'top'
		);

		// Category + pagination
		add_rewrite_rule(
			'^(' . $langs . ')/category/(.+?)/page/([0-9]+)/?$',
			'index.php?lang=$matches[1]&category_name=$matches[2]&paged=$matches[3]',
			'top'
		);

		add_rewrite_rule(
			'^(' . $langs . ')/category/(.+?)/?$',
			'index.php?lang=$matches[1]&category_name=$matches[2]',
			'top'
		);

		// Front page (/de/)
		add_rewrite_rule(
			'^(' . $langs . ')/?$',
			'index.php?lang=$matches[1]',
			'top'
		);

		// Generic fallback
		add_rewrite_rule(
			'^(' . $langs . ')/(.+)$',
			'index.php?lang=$matches[1]&pagename=$matches[2]',
			'top'
		);
	}

	// =========================================================
	// QUERY VARS
	// =========================================================

	public function add_query_vars( array $vars ): array {
		if ( $this->is_system_request() ) return $vars;
		$vars[] = 'lang';
		return $vars;
	}

	// =========================================================
	// PARSE QUERY
	// =========================================================

	public function handle_parse_query( $q ): void {
		if ( $this->is_system_request() ) return;
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;

		$q->set( 'lang', LF_LANG );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
		if ( ! empty( $_GET['s'] ) ) {
			$q->is_search = true;
			$q->is_home   = false;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			$this->debug( 'Search forced', [ 's' => sanitize_text_field( wp_unslash( $_GET['s'] ) ) ] );
		}
	}

	// =========================================================
	// PRE_GET_POSTS
	// =========================================================

	public function handle_pre_get_posts( $q ): void {
		if ( ! $q->is_main_query() ) return;

		// Frontend
		if ( ! is_admin() ) {
			if ( $q->is_front_page() ) return;

			if ( $q->is_search() ) {
				$meta_query   = $q->get( 'meta_query' ) ?: [];
				$meta_query[] = [ 'key' => '_lang', 'value' => LF_LANG ];
				$q->set( 'meta_query', $meta_query );
				$this->debug( 'Search filtered by language', [ 'lang' => LF_LANG ] );
				return;
			}

			if ( $q->is_archive() || $q->is_home() ) {
				$meta_query   = $q->get( 'meta_query' ) ?: [];
				$meta_query[] = [ 'key' => '_lang', 'value' => LF_LANG ];
				$q->set( 'meta_query', $meta_query );
			}

			return;
		}

		// Admin — reached only when is_admin() is true (frontend block above always returns).
		$meta_query = $q->get( 'meta_query' ) ?: [];
		$user_id    = get_current_user_id();
		$lang       = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( isset( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
		} else {
			$lang = get_user_meta( $user_id, 'lf_lang_filter', true );
		}

		if ( ! empty( $lang ) ) {
			$meta_query[] = [ 'key' => '_lang', 'value' => $lang ];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( ! empty( $_GET['lf_outdated_filter'] ) ) {
			$meta_query[] = [
				'key'     => '_lang',
				'value'   => $this->source_language(),
				'compare' => '!=',
			];
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => '_translation_source_updated_at', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_translation_source_updated_at', 'value' => 0, 'compare' => '=' ],
			];
		}

		if ( ! empty( $meta_query ) ) {
			$q->set( 'meta_query', $meta_query );
		}
	}

	// =========================================================
	// PERSIST ADMIN LANG FILTER
	// =========================================================

	public function persist_admin_lang_filter(): void {
		if ( ! current_user_can( 'edit_posts' ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( isset( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
			update_user_meta( get_current_user_id(), 'lf_lang_filter', $lang );
		}
	}

	// =========================================================
	// TRID SYSTEM
	// =========================================================

	public function get_trid( int $id ): string {
		return (string) get_post_meta( $id, '_trid', true );
	}

	public function set_trid( int $id, string $v ): void {
		update_post_meta( $id, '_trid', $v );
	}

	public function get_lang( int $id ): string {
		$lang = get_post_meta( $id, '_lang', true );
		return $lang ?: $this->source_language();
	}

	public function set_lang( int $id, string $v ): void {
		update_post_meta( $id, '_lang', $v );
	}

	// =========================================================
	// TRANSLATIONS
	// =========================================================

	public function get_translations( int $post_id ): array {
		global $wpdb;

		$trid = $this->get_trid( $post_id );
		if ( ! $trid ) return [];

		$cache_key = 'trid_' . $trid;
		$cached    = wp_cache_get( $cache_key, 'lf_translations' );
		if ( $cached !== false ) return $cached;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Subquery across wp_postmeta to resolve translation groups by TRID; no WP API equivalent. $wpdb->postmeta is a server-defined table name; $trid bound via %s placeholder. Result cached immediately below via wp_cache_set.
		$rows = $wpdb->get_results( $wpdb->prepare( "
			SELECT post_id, meta_value lang
			FROM $wpdb->postmeta
			WHERE meta_key='_lang'
			AND post_id IN (
				SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key='_trid' AND meta_value=%s
			)
		", $trid ) );

		$out = [];
		foreach ( $rows as $r ) {
			$out[$r->lang] = (int) $r->post_id; // wpdb returns strings; cast for type safety
		}

		wp_cache_set( $cache_key, $out, 'lf_translations', 3600 );

		return $out;
	}

	public function clear_translation_cache( int $post_id ): void {
		$trid = $this->get_trid( $post_id );
		if ( ! $trid ) return;
		wp_cache_delete( 'trid_' . $trid, 'lf_translations' );
	}

	public function handle_cache_clear( int $post_id ): void {
		$this->clear_translation_cache( $post_id );
	}

	// =========================================================
	// OUTDATED SYSTEM
	// =========================================================

	public function mark_source_updated( int $post_id ): void {
		update_post_meta( $post_id, '_source_updated_at', time() );
	}

	public function mark_translation_synced( int $post_id ): void {
		$translations = $this->get_translations( $post_id );
		$source_id    = $translations[$this->source_language()] ?? 0;
		if ( ! $source_id ) return;

		$source_time = get_post_meta( $source_id, '_source_updated_at', true );
		update_post_meta( $post_id, '_translation_source_updated_at', $source_time );
	}

	public function is_outdated( int $post_id ): bool {
		$lang = $this->get_lang( $post_id );
		if ( $lang === $this->source_language() ) return false;

		$source = get_post_meta( $post_id, '_source_updated_at', true );
		$trans  = get_post_meta( $post_id, '_translation_source_updated_at', true );

		if ( ! $source ) return false;
		if ( ! $trans  ) return true;

		return (int) $trans < (int) $source;
	}

	// =========================================================
	// MISSING TRANSLATIONS
	// =========================================================

	public function get_missing_languages( int $post_id ): array {
		$translations = $this->get_translations( $post_id );
		$existing     = array_keys( $translations );
		$current      = $this->get_lang( $post_id );
		$missing      = [];

		foreach ( $this->languages() as $lang ) {
			if ( $lang === $current ) continue;
			if ( ! in_array( $lang, $existing ) ) {
				$missing[] = $lang;
			}
		}

		return $missing;
	}

	// =========================================================
	// QUERY HELPERS
	// =========================================================

	public function query( array $args = [] ): WP_Query {
		if ( ! empty( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $mq ) {
				if ( isset( $mq['key'] ) && $mq['key'] === '_lang' ) {
					return new WP_Query( $args );
				}
			}
		}

		$args['meta_query'][] = [ 'key' => '_lang', 'value' => LF_LANG ];

		return new WP_Query( $args );
	}

	public function query_fallback( array $args = [] ): WP_Query {
		$args['meta_query'][] = [
			'relation' => 'OR',
			[ 'key' => '_lang', 'value' => LF_LANG ],
			[ 'key' => '_lang', 'value' => $this->source_language() ],
		];

		return new WP_Query( $args );
	}

	public function get_posts( array $args = [], bool $fallback = false ): array {
		$q = $fallback ? $this->query_fallback( $args ) : $this->query( $args );
		return $q->posts;
	}

	// =========================================================
	// URL HELPERS
	// =========================================================

	public function safe_query_args( string $url ): string {
		$allowed = [ 'paged', 's' ];
		$params  = [];

		foreach ( $allowed as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
			if ( isset( $_GET[$key] ) && $_GET[$key] !== '' ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WP search query parameter for language-aware search; no data is modified.
				$params[$key] = sanitize_text_field( wp_unslash( $_GET[$key] ) );
			}
		}

		return empty( $params ) ? $url : add_query_arg( $params, $url );
	}

	public function is_system_request(): bool {
		return
			( defined( 'DOING_AJAX' )    && DOING_AJAX ) ||
			( defined( 'REST_REQUEST' )  && REST_REQUEST ) ||
			( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ||
			( defined( 'WP_CLI' )        && WP_CLI );
	}

	public function set_lang_cookie( string $lang ): void {
		if ( ! $this->is_valid_lang( $lang ) ) return;

		setcookie(
			'lf_lang',
			$lang,
			time() + MONTH_IN_SECONDS,
			'/',
			'',
			is_ssl(),
			true
		);
	}

	// =========================================================
	// TEMPLATE REDIRECT HANDLERS
	// =========================================================

	public function handle_singular_redirect(): void {
		if ( $this->is_system_request() ) return;
		if ( is_admin() ) return;
		if ( is_search() ) return;
		if ( ! is_singular() ) return;
		if ( LF_LANG === $this->source_language() ) return;

		global $post;
		if ( ! $post ) return;

		$translations = $this->get_translations( $post->ID );

		if ( empty( $translations[LF_LANG] ) ) {
			if ( ! defined( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE' ) ) {
				define( 'LINGUAFORGE_LANG_FALLBACK_ACTIVE', true );
			}
			return;
		}

		$target_id = (int) $translations[LF_LANG];
		if ( $target_id === (int) $post->ID ) return;

		$target_url = get_permalink( $target_id );
		if ( ! $target_url ) return;

		$target_url = $this->safe_query_args( $target_url );
		wp_safe_redirect( $target_url, 301 );
		exit;
	}

	public function handle_homepage_redirect(): void {
		if ( $this->is_system_request() ) return;
		if ( is_admin() ) return;
		if ( ! defined( 'LF_LANG' ) ) return;
		if ( is_search() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if (
			$path !== '/' &&
			$path !== '' &&
			! preg_match( '#^/[a-z]{2}/?$#', $path )
		) {
			return;
		}

		$front_id = get_option( 'page_on_front' );
		if ( ! $front_id ) return;

		$translations = $this->get_translations( $front_id );

		if (
			LF_LANG !== $this->source_language() &&
			! empty( $translations[LF_LANG] )
		) {
			$target_id = $translations[LF_LANG];
		} else {
			$target_id = $front_id;
		}

		$target  = get_permalink( $target_id );
		if ( ! $target ) return;

		$current = home_url( trailingslashit( ltrim( $path, '/' ) ) );
		if ( untrailingslashit( $target ) === untrailingslashit( $current ) ) return;

		$target = $this->safe_query_args( $target );
		wp_safe_redirect( $target, 302 );
		exit;
	}

	public function normalize_duplicate_slashes(): void {
		if ( is_admin() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri   = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		$clean = preg_replace( '#(?<!:)//+#', '/', $uri );

		if ( $clean !== $uri ) {
			wp_safe_redirect( $clean, 301 );
			exit;
		}
	}

	public function redirect_search_under_lang_prefix(): void {
		if ( ! is_search() ) return;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );

		if ( preg_match( '#^/[a-z]{2}/#', $uri ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Language detection reads URL parameters for routing; nonces are not applicable to public URL-based language switching.
			$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : LF_LANG;
			$s    = get_query_var( 's' );
			$url  = '/?lang=' . $lang . '&s=' . urlencode( $s );
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	// =========================================================
	// RENDER BLOCK FILTERS
	// =========================================================

	public function fix_site_logo_link( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/site-logo' ) return $block_content;
		if ( ! defined( 'LF_LANG' ) ) return $block_content;

		$front_id = get_option( 'page_on_front' );
		if ( ! $front_id ) return $block_content;

		$translations = $this->get_translations( $front_id );

		if ( LF_LANG === $this->source_language() ) {
			$target_id = $front_id;
		} elseif ( ! empty( $translations[LF_LANG] ) ) {
			$target_id = $translations[LF_LANG];
		} else {
			$target_id = $front_id;
		}

		$target_url = get_permalink( $target_id );

		$block_content = preg_replace(
			'/<a\s+([^>]*?)href="[^"]*"/',
			'<a $1href="' . esc_url( $target_url ) . '"',
			$block_content,
			1
		);

		return $block_content;
	}

	// =========================================================
	// MENU TRANSLATION
	// =========================================================

	public function translate_menu_items( array $items ): array {
		foreach ( $items as &$item ) {
			if ( ! empty( $item->object_id ) ) {
				$translations = $this->get_translations( (int) $item->object_id );
				if ( ! empty( $translations[LF_LANG] ) ) {
					$item->url = get_permalink( $translations[LF_LANG] );
				}
			}
		}
		return $items;
	}

	// =========================================================
	// PERMALINK
	// =========================================================

	public function lang_permalink( string $url, $post ): string {
		if ( is_numeric( $post ) ) $post = get_post( $post );
		if ( ! $post || ! isset( $post->ID ) ) return $url;

		$lang = $this->get_lang( $post->ID );
		if ( ! $lang || $lang === $this->source_language() ) return $url;

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) return $url;

		$path        = trim( $path, '/' );
		$langs_regex = implode( '|', array_map( 'preg_quote', $this->languages() ) );
		$path        = preg_replace( '#^(' . $langs_regex . ')/#', '', $path );

		return home_url( '/' . $lang . '/' . $path . '/' );
	}

	// =========================================================
	// TEMPLATE HANDLING
	// =========================================================

	public function resolve_template_for_lang( $post, string $lang ): ?string {
		if ( ! $post || ! $lang ) return null;

		// Primary language uses WordPress's default template hierarchy
		// (page, single, etc.) — no language suffix is expected.
		if ( $lang === $this->source_language() ) return null;

		$type = $post->post_type;

		if ( $type === 'page' )      $base = 'page';
		elseif ( $type === 'post' )  $base = 'single';
		else                         return null;

		return $base . '-' . $lang;
	}

	public function template_exists( string $slug ): bool {
		$tpl = get_page_by_path( $slug, OBJECT, 'wp_template' );
		return ! empty( $tpl );
	}

	public function assign_template_if_needed( int $post_id, $post, string $lang ): void {
		if ( ! in_array( $post->post_type, [ 'post', 'page' ] ) ) return;

		$template_slug = $this->resolve_template_for_lang( $post, $lang );
		if ( ! $template_slug ) return;
		if ( ! $this->template_exists( $template_slug ) ) return;

		$current = get_post_meta( $post_id, '_wp_page_template', true );
		if ( ! empty( $current ) && $current !== 'default' ) return;

		update_post_meta( $post_id, '_wp_page_template', $template_slug );
		$this->debug( 'Template auto-assigned', [ 'post_id' => $post_id, 'template' => $template_slug ] );
	}

	// =========================================================
	// SAVE HANDLER
	// =========================================================

	public function handle_save_post( int $post_id, $post ): void {
		if ( ! in_array( $post->post_type, [ 'post', 'page', 'wp_navigation' ] ) ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( wp_is_post_autosave( $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// Dedicated metabox nonces. Verified independently of WP core's
		// edit_post nonce so a CSRF on an unrelated POST endpoint that ends
		// up triggering save_post cannot rebind language or translation groups.
		//
		// Missing-or-invalid nonce means "the field wasn't submitted by our
		// metabox" — we silently skip the corresponding block rather than
		// aborting the handler, so REST saves and wp_insert_post() callers
		// (which post no nonce) continue to work for everything else.
		$has_lang_nonce  = isset( $_POST['lf_language_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_language_nonce'] ) ), 'lf_language_save' );
		$has_trans_nonce = isset( $_POST['lf_translations_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_translations_nonce'] ) ), 'lf_translations_save' );

		// Language
		if ( $has_lang_nonce && isset( $_POST['lf_lang'] ) && $this->is_valid_lang( sanitize_key( wp_unslash( $_POST['lf_lang'] ) ) ) ) {
			$this->set_lang( $post_id, sanitize_key( wp_unslash( $_POST['lf_lang'] ) ) );
		}
		if ( ! get_post_meta( $post_id, '_lang', true ) ) {
			$this->set_lang( $post_id, $this->source_language() );
		}

		$lang = $this->get_lang( $post_id );

		// Skip template/TRID/timestamp for non-page/post types
		if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) return;

		// Template auto-assignment.
		//
		// Fires whenever the post's language has changed OR when this is the
		// first save the post has ever seen ($previous_lang empty). The
		// in-method guard inside assign_template_if_needed() leaves any
		// explicit admin template choice intact — only acts when the
		// current template is 'default' or empty — so this is safe to
		// trigger more aggressively than the original "on change only" gate.
		//
		// Without the first-save branch, posts created programmatically (REST
		// inserts, wp_insert_post calls, duplicated-from-source flows) with
		// _lang already set would never get their language-specific template
		// assigned, since there's no _lang_previous to compare against.
		$previous_lang = get_post_meta( $post_id, '_lang_previous', true );
		update_post_meta( $post_id, '_lang_previous', $lang );

		if ( ! $previous_lang || $previous_lang !== $lang ) {
			$this->assign_template_if_needed( $post_id, $post, $lang );
		}

		// TRID
		$trid = $this->get_trid( $post_id );
		if ( ! $trid ) {
			$trid = wp_generate_uuid4();
			$this->set_trid( $post_id, $trid );
		}

		// Timestamps
		if ( $lang === $this->source_language() ) {
			$this->mark_source_updated( $post_id );
			$translations = $this->get_translations( $post_id );
			foreach ( $translations as $t ) {
				update_post_meta( $t, '_translation_source_updated_at', 0 );
			}
		} else {
			$this->mark_translation_synced( $post_id );
		}

		// Group merge (collect submitted translations).
		// Only read lf_trans_* when the translations metabox nonce checks out;
		// otherwise the post still gets its language/timestamp updates but the
		// TRID translation group is untouched.
		$group_ids = [ $post_id ];

		if ( $has_trans_nonce ) {
			foreach ( $this->languages() as $l ) {
				if ( ! isset( $_POST['lf_trans_' . $l] ) ) continue;
				$target_id = absint( wp_unslash( $_POST['lf_trans_' . $l] ) );
				if ( ! $target_id || $target_id === $post_id ) continue;
				$group_ids[] = $target_id;
			}
		}

		// Expand translation group (graph completion)
		$expanded_ids = $group_ids;

		foreach ( $group_ids as $pid ) {
			$existing = $this->get_translations( $pid );
			if ( empty( $existing ) ) continue;
			foreach ( $existing as $existing_id ) {
				if ( ! in_array( $existing_id, $expanded_ids ) ) {
					$expanded_ids[] = $existing_id;
				}
			}
		}

		$group_ids = array_unique( $expanded_ids );

		// Resolve shared TRID
		$trid = null;
		foreach ( $group_ids as $pid ) {
			$existing = $this->get_trid( $pid );
			if ( $existing ) { $trid = $existing; break; }
		}
		if ( ! $trid ) $trid = wp_generate_uuid4();

		foreach ( $group_ids as $pid ) {
			$this->set_trid( $pid, $trid );

			if ( $pid === $post_id ) continue;

			// Language-binding on related posts is only safe when the
			// translations metabox nonce verified — same gate as the group
			// collection loop above.
			if ( ! $has_trans_nonce ) continue;

			foreach ( $this->languages() as $l ) {
				if ( isset( $_POST['lf_trans_' . $l] ) && absint( wp_unslash( $_POST['lf_trans_' . $l] ) ) === $pid ) {
					$this->set_lang( $pid, $l );
				}
			}
		}

		// Search index
		$this->build_search_content( $post_id );

		// Template selection (manual)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Language is set via a meta box field; the post save nonce (edit_post) is verified by WordPress core before save_post fires.
		if ( ! isset( $_POST['lf_page_template'] ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Language is set via a meta box field; the post save nonce (edit_post) is verified by WordPress core before save_post fires.
		$template = sanitize_text_field( wp_unslash( $_POST['lf_page_template'] ) );
		update_post_meta( $post_id, '_wp_page_template', $template );
	}

	// =========================================================
	// AJAX IMPORT
	// =========================================================

	public function ajax_import_translation(): void {
		check_ajax_referer( 'lf_import_translation_nonce', 'nonce' );

		$target_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$source_lang = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );

		// Validate the requested source language; fall back to the primary language
		// if the parameter is missing or not in the active language list.
		if ( ! $source_lang || ! $this->is_valid_lang( $source_lang ) ) {
			$source_lang = $this->source_language();
		}

		$translations = $this->get_translations( $target_id );
		$source_id    = $translations[ $source_lang ] ?? 0;

		if ( ! $source_id ) wp_send_json_error( 'No source found for language: ' . $source_lang );
		if ( $target_id === $source_id ) wp_send_json_error( 'Cannot update from itself' );

		$source = get_post( $source_id );
		$target = get_post( $target_id );

		if ( ! $source || ! $target ) wp_send_json_error();

		$original_lang = $this->get_lang( $target_id );
		$content       = $source->post_content;
		$blocks        = parse_blocks( $content );
		$content       = serialize_blocks( $blocks );

		// Strip all footnote markup from the imported content.
		// Gutenberg footnotes are tightly coupled to post-specific UUIDs and
		// internal block state — copying them verbatim breaks the block editor on
		// the target page. The source footnotes are displayed in a read-only
		// metabox on the target page so the translator can recreate them manually.
		//
		// 1. Remove the footnotes block comment.
		$content = preg_replace( '/<!--\s*wp:footnotes\s*\/-->\n?/', '', $content );
		// 2. Remove inline footnote markers (<sup data-fn="…">…</sup>), leaving
		//    the surrounding prose intact.
		$content = preg_replace( '/<sup[^>]+data-fn="[^"]*"[^>]*>.*?<\/sup>/s', '', $content );

		wp_update_post( [
			'ID'           => $target_id,
			'post_title'   => $source->post_title,
			'post_content' => $content,
		] );

		// Reset footnotes meta to an empty array so the block editor starts from
		// a clean state identical to a fresh page. Without this, stale UUID data
		// from the target's previous content remains in the meta, causing the
		// editor's footnotes store to initialise in an inconsistent state
		// (meta has UUIDs, content has none) which crashes the footnotes block.
		update_post_meta( $target_id, 'footnotes', '[]' );

		$this->set_lang( $target_id, $original_lang );

		$source_time = get_post_meta( $source_id, '_source_updated_at', true );
		update_post_meta( $target_id, '_translation_source_updated_at', $source_time );

		wp_send_json_success();
	}

	// =========================================================
	// ADMIN META BOXES
	// =========================================================

	public function add_language_meta_box(): void {
		add_meta_box(
			'lf_lang',
			'Language',
			[ $this, 'render_language_meta_box' ],
			null,
			'side'
		);
	}

	public function render_language_meta_box( $post ): void {
		// Dedicated nonce — verified in handle_save_post() before lf_lang is read.
		// Independent of the post-edit nonce so CSRF on third-party POST endpoints
		// can't rebind a post's language via a save_post side effect.
		wp_nonce_field( 'lf_language_save', 'lf_language_nonce' );

		$cur = $this->get_lang( $post->ID );
		echo '<select name="lf_lang" class="lf-lr-lang" id="lf_lr_lang">';
		foreach ( $this->languages() as $l ) {
			echo '<option value="' . esc_attr( $l ) . '" ' . selected( $cur, $l, false ) . '>' . esc_html( strtoupper( $l ) ) . '</option>';
		}
		echo '</select>';
	}

	public function add_template_meta_box(): void {
		add_meta_box(
			'lf_page_template',
			'Template',
			[ $this, 'render_template_meta_box' ],
			null,
			'side',
			'default'
		);
	}

	public function render_template_meta_box( $post ): void {
		if ( ! in_array( $post->post_type, [ 'page', 'post' ] ) ) return;

		$current = get_post_meta( $post->ID, '_wp_page_template', true ) ?: 'default';

		$templates = get_posts( [
			'post_type'      => 'wp_template',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		] );

		echo '<select name="lf_page_template" style="width:100%">';
		echo '<option value="default"' . selected( $current, 'default', false ) . '>Default</option>';

		foreach ( $templates as $tpl ) {
			$slug = $tpl->post_name;
			if ( ! str_starts_with( $slug, 'page-' ) && ! str_starts_with( $slug, 'single-' ) ) continue;
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $slug ) . '</option>';
		}

		echo '</select>';
		echo '<p style="margin-top:8px;color:#666;">Current: <code>' . esc_html( $current ) . '</code></p>';
	}

	public function add_translations_meta_box(): void {
		add_meta_box(
			'lf_trans',
			'Translations',
			[ $this, 'render_translations_meta_box' ],
			null,
			'side'
		);
	}

	public function render_translations_meta_box( $post ): void {
		// Dedicated nonce — verified in handle_save_post() before any
		// lf_trans_* post-id input is consumed. Prevents a forged save from
		// rewriting a post's TRID translation group via cross-post side effects.
		wp_nonce_field( 'lf_translations_save', 'lf_translations_nonce' );

		$current_lang = $this->get_lang( $post->ID );
		$translations = $this->get_translations( $post->ID );

		echo '<p><strong>Current language:</strong> ' . esc_html( strtoupper( $current_lang ) ) . '</p>';

		foreach ( $this->languages() as $l ) {
			if ( $l === $current_lang ) continue;

			$id = $translations[$l] ?? '';

			echo '<p><strong>' . esc_html( strtoupper( $l ) ) . '</strong>';
			if ( $id && $this->is_outdated( (int) $id ) ) echo ' ⚠';
			echo '<br>';

			$args = [
				'name'             => 'lf_trans_' . $l,
				'show_option_none' => '—',
				'meta_key'         => '_lang',
				'meta_value'       => $l,
			];

			if ( $id ) {
				$args['include']  = [ $id ];
				$args['selected'] = $id;
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() is a WordPress core function that escapes all its own output.
			wp_dropdown_pages( $args );

			echo '<br>';
			if ( ! empty( $id ) ) {
				echo '<button type="button" class="button lf-import" data-lang="' . esc_attr( $l ) . '">Override</button>';
			}

			echo '</p>';
		}
	}

	// =========================================================
	// SOURCE FOOTNOTES META BOX
	// =========================================================

	public function add_source_footnotes_meta_box(): void {
		add_meta_box(
			'lf_source_footnotes',
			'Source Footnotes',
			[ $this, 'render_source_footnotes_meta_box' ],
			null,
			'normal',
			'default'
		);
	}

	/**
	 * Show the source page's footnotes as a read-only reference on translation pages.
	 *
	 * Footnotes are stripped from imported content (Gutenberg's UUID-based system
	 * makes cross-page copying fragile). This metabox lets translators see what
	 * footnotes the source has so they can recreate them manually via the block editor.
	 */
	public function render_source_footnotes_meta_box( $post ): void {
		$lang = $this->get_lang( $post->ID );

		// Only relevant on non-source translation pages.
		if ( $lang === $this->source_language() ) {
			echo '<p style="color:#888;">' . esc_html__('This is the source page. Footnotes are edited directly in the block editor.', 'lingua-forge') . '</p>';
			return;
		}

		$translations = $this->get_translations( $post->ID );
		$source_id    = $translations[ $this->source_language() ] ?? 0;

		if ( ! $source_id ) {
			echo '<p style="color:#888;">' . esc_html__('No source page linked yet.', 'lingua-forge') . '</p>';
			return;
		}

		$raw = get_post_meta( $source_id, 'footnotes', true );

		if ( empty( $raw ) || $raw === '[]' ) {
			echo '<p style="color:#888;">' . esc_html__('The source page has no footnotes.', 'lingua-forge') . '</p>';
			return;
		}

		$footnotes = json_decode( $raw, true );

		if ( ! is_array( $footnotes ) || empty( $footnotes ) ) {
			echo '<p style="color:#888;">' . esc_html__('The source page has no footnotes.', 'lingua-forge') . '</p>';
			return;
		}

		echo '<p style="color:#888;font-style:italic;">'
			. esc_html__('These footnotes come from the source page and are shown here for reference only. Add them to this page using the block editor.', 'lingua-forge')
			. '</p>';
		echo '<ol style="margin-left:1.5em;">';
		foreach ( $footnotes as $fn ) {
			echo '<li style="margin-bottom:.5em;">' . wp_kses_post( $fn['content'] ?? '' ) . '</li>';
		}
		echo '</ol>';
	}

	// =========================================================
	// ADMIN COLUMN
	// =========================================================

	public function add_lang_column( array $cols ): array {
		$cols['lang'] = 'Lang';
		return $cols;
	}

	public function render_lang_column( string $col, $id ): void {
		$id = (int) $id;
		if ( $col !== 'lang' ) return;

		$lang = $this->get_lang( $id );
		echo '<strong data-lang="' . esc_attr( $lang ) . '">' . esc_html( strtoupper( $lang ) ) . '</strong>';

		if ( $this->is_outdated( $id ) ) echo ' ⚠';

		$missing = $this->get_missing_languages( $id );
		if ( ! empty( $missing ) ) {
			echo ' ⭕ ' . esc_html( implode( ',', array_map( 'strtoupper', $missing ) ) );
		}
	}

	// =========================================================
	// QUICK EDIT
	// =========================================================

	public function render_quick_edit_box( string $column_name, string $post_type ): void {
		if ( $column_name !== 'lang' ) return;
		if ( ! in_array( $post_type, [ 'post', 'page', 'wp_navigation' ] ) ) return;
		?>
		<fieldset class="inline-edit-col">
			<label>
				<span class="title">Language</span>
				<select name="lf_lang">
					<?php foreach ( $this->languages() as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>"><?php echo esc_html( strtoupper( $l ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</fieldset>
		<?php
	}

	// =========================================================
	// ADMIN FILTER DROPDOWNS
	// =========================================================

	public function filter_pages_by_lang( array $pages, array $args ): array {
		if ( ! is_admin() ) return $pages;

		global $pagenow;
		if ( $pagenow !== 'edit.php' ) return $pages;

		$lang = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		if ( ! empty( $_GET['lf_lang_filter'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			$lang = sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) );
		} else {
			$lang = get_user_meta( get_current_user_id(), 'lf_lang_filter', true );
		}

		if ( ! $lang ) return $pages;

		$filtered = [];
		foreach ( $pages as $page ) {
			if ( $this->get_lang( $page->ID ) === $lang ) {
				$filtered[] = $page;
			}
		}

		return $filtered;
	}

	public function render_lang_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, [ 'post', 'page' ] ) ) return;

		$user_id = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
		$current = ! empty( $_GET['lf_lang_filter'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading admin list-filter URL parameter; no data is modified.
			? sanitize_key( wp_unslash( $_GET['lf_lang_filter'] ) )
			: ( get_user_meta( $user_id, 'lf_lang_filter', true ) ?: '' );

		echo '<select name="lf_lang_filter">';
		echo '<option value="">All languages</option>';
		foreach ( $this->languages() as $lang ) {
			echo '<option value="' . esc_attr( $lang ) . '" ' . selected( $current, $lang, false ) . '>' . esc_html( strtoupper( $lang ) ) . '</option>';
		}
		echo '</select>';
	}

	public function render_outdated_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, [ 'post', 'page' ] ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading admin list-filter URL parameter; no data is modified.
		$current = isset( $_GET['lf_outdated_filter'] ) ? sanitize_key( wp_unslash( $_GET['lf_outdated_filter'] ) ) : '';

		echo '<select name="lf_outdated_filter">';
		echo '<option value="">All statuses</option>';
		echo '<option value="1" ' . selected( $current, '1', false ) . '>Outdated only</option>';
		echo '</select>';
	}

	// =========================================================
	// SEO / HREFLANG
	// =========================================================

	public function hreflang_mode(): string {
		static $mode = null;
		if ( $mode !== null ) return $mode;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
		$mode = apply_filters( 'lf_hreflang_mode', 'custom' );
		return $mode;
	}

	public function print_hreflang_tags(): void {
		if ( $this->hreflang_mode() !== 'custom' ) return;

		if ( is_singular() ) {
			global $post;
			if ( ! $post ) return;

			$translations = $this->get_translations( $post->ID );
			if ( empty( $translations ) ) return;

			foreach ( $translations as $lang => $id ) {
				echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( get_permalink( $id ) ) . '" />' . "\n";
			}

			// x-default — Google's spec says this should point at a page intended
			// for users whose language preference matches none of the variants.
			// We default to the source-language URL (preserves pre-1.4 behavior),
			// but expose lf_hreflang_x_default so sites can swap in a dedicated
			// /global/ landing page or the English version when available.
			if ( ! empty( $translations[$this->source_language()] ) ) {
				$x_default_url = get_permalink( $translations[$this->source_language()] );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- lf_ is this plugin's registered short prefix; hook is public API.
				$x_default_url = (string) apply_filters( 'lf_hreflang_x_default', $x_default_url, $post->ID, $translations );

				if ( $x_default_url !== '' ) {
					echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default_url ) . '" />' . "\n";
				}
			}
			return;
		}

		if ( is_paged() ) {
			$paged = get_query_var( 'paged' );
			foreach ( $this->languages() as $lang ) {
				$base = ( $lang === $this->source_language() ) ? home_url( '/' ) : home_url( '/' . $lang . '/' );
				$url  = ( $paged > 1 ) ? $base . 'page/' . $paged . '/' : $base;
				echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
			return;
		}

		if ( is_archive() || is_home() ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
			$path = trim( wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );

			$langs_regex = implode( '|', array_map( 'preg_quote', $this->languages() ) );
			$path        = preg_replace( '#^(' . $langs_regex . ')/#', '', $path );
			$path        = preg_replace( '#/+#', '/', $path );

			foreach ( $this->languages() as $lang ) {
				if ( $lang === $this->source_language() ) {
					$url = empty( $path ) ? home_url( '/' ) : home_url( '/' . trailingslashit( $path ) );
				} else {
					$url = empty( $path )
						? home_url( '/' . trailingslashit( $lang ) )
						: home_url( '/' . trailingslashit( $lang . '/' . $path ) );
				}
				echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
			}
		}
	}

	public function remove_core_canonical(): void {
		if ( is_admin() ) return;
		if ( $this->hreflang_mode() !== 'custom' ) return;
		remove_action( 'wp_head', 'rel_canonical' );
	}

	public function disable_seo_plugin_hreflang(): void {
		if ( $this->hreflang_mode() !== 'custom' ) return;

		$this->debug( 'Disabling plugin hreflang' );

		if ( defined( 'WPSEO_VERSION' ) )     add_filter( 'wpseo_hreflang', '__return_false' );
		if ( defined( 'RANK_MATH_VERSION' ) )  add_filter( 'rank_math/frontend/hreflang', '__return_false' );
		if ( defined( 'AIOSEO_VERSION' ) )     add_filter( 'aioseo_hreflang', '__return_false' );
		if ( defined( 'SEOPRESS_VERSION' ) )   add_filter( 'seopress_hreflang', '__return_false' );
	}

	// =========================================================
	// SEARCH
	// =========================================================

	public function override_search_template( array $templates, $query, string $template_type ): array {
		if ( $template_type !== 'wp_template' ) return $templates;
		if ( ! defined( 'LF_LANG' ) ) return $templates;
		if ( ! is_search() ) return $templates;

		$lang_slug = 'search-' . LF_LANG;
		$tpl       = get_page_by_path( $lang_slug, OBJECT, 'wp_template' );

		if ( $tpl ) {
			$this->debug( 'Search template override SUCCESS', [ 'template' => $lang_slug ] );
			return [ _build_block_template_result_from_post( $tpl ) ];
		}

		return $templates;
	}

	public function fix_search_form( string $block_content, array $block ): string {
		if ( $block['blockName'] !== 'core/search' ) return $block_content;
		if ( ! defined( 'LF_LANG' ) ) return $block_content;

		$block_content = preg_replace( '/<form[^>]*action="[^"]*"/', '<form action="/"', $block_content );

		if ( ! str_contains( $block_content, 'name="lang"' ) ) {
			$hidden        = '<input type="hidden" name="lang" value="' . esc_attr( LF_LANG ) . '">';
			$block_content = preg_replace( '/<\/form>/', $hidden . '</form>', $block_content, 1 );
		}

		$this->debug( 'Search form fixed (root + lang)', [ 'lang' => LF_LANG ] );

		return $block_content;
	}

	public function boost_title_in_search( array $clauses, $query ): array {
		global $wpdb;

		if ( ! is_search() ) return $clauses;

		$term = $query->get( 's' );
		if ( ! $term ) return $clauses;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$clauses['orderby'] = $wpdb->prepare( "
			(CASE
				WHEN {$wpdb->posts}.post_title LIKE %s THEN 1
				ELSE 2
			END),
			{$wpdb->posts}.post_date DESC
		", $like );

		return $clauses;
	}

	public function extend_posts_search( string $search, $wp_query ): string {
		global $wpdb;

		if ( ! is_search() ) return $search;

		$term = $wp_query->get( 's' );
		if ( ! $term ) return $search;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->prepare( "
			AND (
				{$wpdb->posts}.post_title LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta}
					WHERE post_id = {$wpdb->posts}.ID
					AND meta_key = '_search_content'
					AND meta_value LIKE %s
				)
			)
		", $like, $like );
	}

	public function build_search_content( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) return;

		$blocks = parse_blocks( $post->post_content );
		$text   = '';

		foreach ( $blocks as $block ) {
			$text .= $this->extract_block_text( $block ) . ' ';
		}

		update_post_meta( $post_id, '_search_content', trim( $text ) );
	}

	public function extract_block_text( array $block ): string {
		$name    = $block['blockName'] ?? '';
		$inner   = $block['innerBlocks'] ?? [];
		$content = '';

		if ( $name === 'core/details' ) {
			if ( ! empty( $block['attrs']['summary'] ) ) {
				$content .= ' ' . $block['attrs']['summary'];
			}
			foreach ( $inner as $child ) {
				$text = $this->extract_block_text( $child );
				if ( ! empty( trim( $text ) ) ) {
					$content .= ' ' . $text;
					if ( strlen( $content ) > 1000 ) break;
				}
			}
			return trim( $content );
		}

		if ( in_array( $name, [ 'core/paragraph', 'core/heading', 'core/list' ], true ) ) {
			return wp_strip_all_tags( implode( ' ', $block['innerContent'] ) );
		}

		if ( in_array( $name, [ 'core/gallery', 'core/image', 'core/cover', 'core/columns', 'core/group', 'core/spacer' ], true ) ) {
			return '';
		}

		foreach ( $inner as $child ) {
			$content .= $this->extract_block_text( $child ) . ' ';
		}

		return $content;
	}


	// =========================================================
	// PERFORMANCE
	// =========================================================

	public function ensure_lang_index(): bool {
		global $wpdb;

		$table      = $wpdb->postmeta;
		$index_name = 'idx_lang';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- INFORMATION_SCHEMA query to detect the idx_lang index before creating it; no WP API equivalent. $table and $index_name are bound via %s placeholders in prepare().
		$exists = $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(1)
			FROM INFORMATION_SCHEMA.STATISTICS
			WHERE table_schema = DATABASE()
			AND table_name = %s
			AND index_name = %s
		", $table, $index_name ) );

		if ( $exists ) return true;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL CREATE INDEX on wp_postmeta; no WP API equivalent. Identifiers cannot use %s placeholders; escaped with esc_sql() and wrapped in backticks.
		$result = $wpdb->query(
			'CREATE INDEX `' . esc_sql( $index_name ) . '` ON `' . esc_sql( $table ) . '` (meta_key, meta_value(10))'
		);

		return $result !== false;
	}

	public function check_db_version(): void {
		$stored = get_option( 'lf_lang_router_version' );

		if ( $stored === self::ROUTER_VERSION ) return;

		$ok = $this->ensure_lang_index();

		if ( $ok !== false ) {
			update_option( 'lf_lang_router_version', self::ROUTER_VERSION, false );
		}
	}

	// =========================================================
	// ADMIN JS — enqueued via wp_add_inline_script (no hardcoded <script> tags)
	// =========================================================

	/**
	 * Enqueue admin JavaScript for the language metabox and quick-edit row.
	 *
	 * Uses wp_register_script( $handle, false ) + wp_add_inline_script() so that
	 * the scripts go through the proper enqueue system without requiring an
	 * external file.  Dynamic values (nonce) are passed as a localised data
	 * object prepended before the main script body.
	 */
	public function enqueue_admin_lang_scripts( string $hook_suffix ): void {

		// Import-translation button + language-change select: post edit screens only.
		if ( in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			$nonce   = wp_create_nonce( 'lf_import_translation_nonce' );
			$version = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;

			wp_register_script( 'lf-admin-metabox', false, [ 'jquery' ], $version, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version supplied via $version.
			wp_enqueue_script( 'lf-admin-metabox' );

			// Pass nonce as a JS data object so no PHP is embedded in the script body.
			wp_add_inline_script(
				'lf-admin-metabox',
				'var lfAdminMetabox = ' . wp_json_encode( [ 'importNonce' => $nonce ] ) . ';',
				'before'
			);
			wp_add_inline_script( 'lf-admin-metabox', $this->admin_metabox_js() );
		}

		// Quick-edit row: list screens only.
		if ( 'edit.php' === $hook_suffix ) {
			$version = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;
			wp_register_script( 'lf-quick-edit', false, [ 'jquery' ], $version, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version supplied via $version.
			wp_enqueue_script( 'lf-quick-edit' );
			wp_add_inline_script( 'lf-quick-edit', $this->quick_edit_js() );
		}
	}

	/**
	 * JavaScript for the language metabox: import-translation click handler and
	 * language/translation-group change handlers.  Nonce is read from the
	 * lfAdminMetabox JS object set by enqueue_admin_lang_scripts().
	 */
	private function admin_metabox_js(): string {
		return <<<'JS'
document.addEventListener('click', function(e){
	if(!e.target.classList.contains('lf-import')) return;
	if(!confirm('Override content from desired language?')) return;

	var post_id = document.getElementById('post_ID').value;
	var lang = e.target.dataset.lang;

	fetch(ajaxurl,{
		method:'POST',
		headers:{'Content-Type':'application/x-www-form-urlencoded'},
		body:new URLSearchParams({
			action:'lf_import_translation',
			post_id:post_id,
			lang:lang,
			nonce:lfAdminMetabox.importNonce
		})
	})
	.then(function(r){ return r.json(); })
	.then(function(data){
		if(!data.success){ alert('Import failed: ' + (data.data || 'unknown error')); return; }
		location.reload();
	})
	.catch(function(err){ alert('Import request failed: ' + err); });
});

document.addEventListener('change', function(e){
	var isLangSelect = e.target.classList.contains('lf-lr-lang');
	var isInsideTrans = e.target.closest && e.target.closest('#lf_trans');
	if (!isLangSelect && !isInsideTrans) return;
	if(typeof wp === 'undefined' || !wp.data) { location.reload(); return; }
	var editor = wp.data.dispatch('core/editor');
	var select  = wp.data.select('core/editor');
	if (!confirm('Change language or relationship? The page will reload.')) return;
	editor.savePost();
	var check = setInterval(function(){
		if (!select.isSavingPost() && !select.isAutosavingPost()) {
			clearInterval(check); location.reload();
		}
	}, 300);
});

(function(){
	if(typeof wp === 'undefined' || !wp.data) return;
	var lastLang = null;
	document.addEventListener('change', function(e){
		if(e.target.name !== 'lf_lang') return;
		var newLang = e.target.value;
		if(newLang === lastLang) return;
		lastLang = newLang;
		var isNew = wp.data.select('core/editor').isEditedPostNew();
		if (isNew) {
			wp.data.dispatch('core/notices').createNotice(
				'info',
				'Language change has to be applied after saving new posts and pages first.\nPlease do a full reload after changing page language.',
				{ type: 'snackbar', isDismissible: true }
			);
			return;
		}
		var permalink = document.querySelector('.editor-post-permalink');
		if(permalink){ permalink.style.opacity = '0.99'; setTimeout(function(){ permalink.style.opacity = ''; }, 50); }
	});
})();
JS;
	}

	/**
	 * JavaScript for the quick-edit row: populates the language select when an
	 * editor expands the inline edit panel.
	 */
	private function quick_edit_js(): string {
		return <<<'JS'
jQuery(function($){
	$(document).on('click', '.editinline', function(){
		var postId = $(this).closest('tr').attr('id').replace('post-','');
		setTimeout(function(){
			var row     = $('#post-' + postId);
			var editRow = $('#edit-' + postId);
			if(!editRow.length) return;
			var lang = row.find('td.column-lang strong').data('lang');
			if(lang){ editRow.find('select[name="lf_lang"]').val(lang); }
		}, 200);
	});
});
JS;
	}

	// =========================================================
	// FRONTEND AJAX LANG — enqueued via wp_add_inline_script
	// =========================================================

	/**
	 * Enqueue the inline script that appends the current language code to every
	 * jQuery AJAX request sent from the frontend.
	 *
	 * Hooked to wp_enqueue_scripts (priority 20) so that LF_LANG is already
	 * defined by the template_redirect language-detection pass.
	 */
	public function enqueue_frontend_lang_script(): void {
		if ( ! defined( 'LF_LANG' ) ) {
			return;
		}

		$version = defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false;
		wp_register_script( 'lf-frontend-lang', false, [ 'jquery' ], $version, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Version supplied via $version.
		wp_enqueue_script( 'lf-frontend-lang' );

		// Embed the current language as a JS constant so the script body stays
		// free of PHP — avoids caching issues with opcode or full-page caches.
		wp_add_inline_script(
			'lf-frontend-lang',
			'var lfFrontendLang = ' . wp_json_encode( [ 'lang' => LF_LANG ] ) . ';',
			'before'
		);
		wp_add_inline_script( 'lf-frontend-lang', $this->frontend_lang_js() );
	}

	/**
	 * JavaScript that appends the current language to every jQuery AJAX request.
	 * The language value is read from the lfFrontendLang JS object to keep all
	 * PHP interpolation out of the script body.
	 */
	private function frontend_lang_js(): string {
		return <<<'JS'
jQuery(function($){
	var lang = (typeof lfFrontendLang !== 'undefined') ? lfFrontendLang.lang : '';
	if (!lang) return;

	$(document).ajaxSend(function(event, xhr, settings){
		if (typeof settings.data === 'string' && settings.data.includes('lang=')) return;
		if (settings.data instanceof FormData) { settings.data.append('lang', lang); return; }
		if (typeof settings.data === 'string' && settings.data.length) {
			settings.data += '&lang=' + lang; return;
		}
		if (!settings.data) { settings.data = 'lang=' + lang; }
	});
});
JS;
	}

	// =========================================================
	// DEBUG
	// =========================================================

	public function debug( string $message, array $context = [] ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) return;

		$prefix = '[LANG ROUTER] ';
		if ( ! empty( $context ) ) $message .= ' | ' . json_encode( $context );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log.
		error_log( $prefix . $message );
	}

	public function debug_system_init(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		if ( is_admin() ) return;
		$this->debug( '=========================================' );
		$this->debug( 'SYSTEM INIT', [
			'mode' => $this->hreflang_mode(),
			'lang' => defined( 'LF_LANG' ) ? LF_LANG : null,
		] );
	}

	public function debug_request_context(): void {
		if ( is_admin() ) return;
		if ( ! is_singular() && ! is_archive() && ! is_home() && ! is_search() ) return;
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set URL string; wp_unslash() applied and value is used only for URL path parsing/routing.
		$this->debug( 'Request context', [
			'url'  => wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ),
			'lang' => defined( 'LF_LANG' ) ? LF_LANG : null,
			'type' => [
				'singular' => is_singular(),
				'archive'  => is_archive(),
				'home'     => is_home(),
				'search'   => is_search(),
			],
		] );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}
}

// =========================================================
// BACK-COMPAT ALIAS
//
// The class lived in the global namespace as `Language_Router` before the
// 1.4 refactor. Themes and third-party plugins continue to reference that
// name — keep the alias for one release so the refactor is invisible to
// callers. Will be removed in 1.5.
// =========================================================
\class_alias( \LinguaForge\Router\Router::class, 'Language_Router' );
