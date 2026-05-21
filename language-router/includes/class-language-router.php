<?php
/**
 * Class LinguaForge\Router\Router
 *
 * Singleton orchestrator for the Language Router sub-module.
 *
 * This class is intentionally thin: it constructs the sub-object graph,
 * delegates hook registration to each sub-object, and exposes a stable public
 * proxy API so that the `language-router.php` wrapper functions and any external
 * callers (AI module, CLI, SettingsPage) continue to call
 * Router::get_instance()->method() unchanged.
 *
 * All implementation logic lives in the sub-classes under includes/:
 *   Context          – language config, detection, cookie, URL helpers
 *   LocaleDetector   – locale resolution, apply_locale, determine_locale hooks
 *   I18n\Overrides   – loads .mo overrides from uploads/lingua-forge/i18n-overrides/
 *   Rewrite\Manager  – rewrite rules, query vars, lang_permalink filter
 *   Rewrite\QueryFilter – parse_query, pre_get_posts, query helpers
 *   Routing\Redirector  – redirect handlers, fix_site_logo_link, menu translation
 *   Seo\Hreflang     – hreflang output, canonical removal, SEO plugin compat
 *   Search\Index     – build_search_content, extract_block_text
 *   Search\Query     – search template override, form fix, SQL extend/boost
 *   Translation\TridGroup – get/set lang/trid, get_translations, cache clear
 *   Translation\Sync – outdated tracking, template assignment, handle_save_post
 *   Db\Migrator      – ensure_lang_index, check_db_version
 *   Admin\MetaBoxes  – 4 meta boxes, ajax_set_language, ajax_import_translation
 *   Admin\Columns    – lang column, quick-edit box
 *   Admin\Filters    – admin list filter dropdowns, persist_admin_lang_filter
 *   Admin\Scripts    – enqueue admin + frontend scripts
 */

namespace LinguaForge\Router;

use LinguaForge\Router\I18n\Overrides      as I18nOverrides;
use LinguaForge\Router\Rewrite\Manager     as RewriteManager;
use LinguaForge\Router\Rewrite\QueryFilter as RewriteQueryFilter;
use LinguaForge\Router\Routing\Redirector;
use LinguaForge\Router\Seo\Hreflang;
use LinguaForge\Router\Search\Index        as SearchIndex;
use LinguaForge\Router\Search\Query        as SearchQuery;
use LinguaForge\Router\Translation\TridGroup;
use LinguaForge\Router\Translation\Sync    as TranslationSync;
use LinguaForge\Router\Db\Migrator;
use LinguaForge\Router\Admin\MetaBoxes;
use LinguaForge\Router\Admin\Columns;
use LinguaForge\Router\Admin\Filters;
use LinguaForge\Router\Admin\Scripts;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) exit;

class Router {

	// =========================================================
	// SINGLETON
	// =========================================================

	private static ?Router $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton instance.
	 *
	 * Production code never calls this. It exists solely so PHPUnit tests
	 * can tear down the router between test cases and avoid state bleed.
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	private function __construct() {
		$this->context       = new Context();
		$this->locale        = new LocaleDetector( $this );
		$this->i18n          = new I18nOverrides( $this );
		$this->rewrite       = new RewriteManager( $this );
		$this->query_filter  = new RewriteQueryFilter( $this );
		$this->redirector    = new Redirector( $this );
		$this->hreflang      = new Hreflang( $this );
		$this->search_index  = new SearchIndex();
		$this->search_query  = new SearchQuery( $this );
		$this->trid_group    = new TridGroup( $this );
		$this->sync          = new TranslationSync( $this );
		$this->migrator      = new Migrator();
		$this->meta_boxes    = new MetaBoxes( $this );
		$this->columns       = new Columns( $this );
		$this->filters       = new Filters( $this );
		$this->scripts       = new Scripts( $this );

		$this->define_lang_constant();
		$this->register_hooks();
	}

	// =========================================================
	// CONSTANTS / VERSION
	// =========================================================

	/**
	 * Schema version for the Language Router's DB state.
	 * Bump when the index schema or meta-key layout changes — never on a plugin-version bump.
	 * Stored in option 'lf_lang_router_version'; Migrator::check_db_version() runs
	 * pending migrations in order when the stored value is behind.
	 *
	 * 1.0 — idx_lang composite index on wp_postmeta (meta_key, meta_value(10)).
	 * 1.1 — rename unprefixed meta keys (_lang, _trid, …) to _lf_ equivalents.
	 */
	const DB_VERSION = '1.1';

	// =========================================================
	// SUB-OBJECTS (public so sub-objects can reach each other via $this->router->*)
	// =========================================================

	public Context          $context;
	public LocaleDetector   $locale;
	public I18nOverrides    $i18n;
	public RewriteManager   $rewrite;
	public RewriteQueryFilter $query_filter;
	public Redirector       $redirector;
	public Hreflang         $hreflang;
	public SearchIndex      $search_index;
	public SearchQuery      $search_query;
	public TridGroup        $trid_group;
	public TranslationSync  $sync;
	public Migrator         $migrator;
	public MetaBoxes        $meta_boxes;
	public Columns          $columns;
	public Filters          $filters;
	public Scripts          $scripts;

	// =========================================================
	// BOOTSTRAP
	// =========================================================

	/**
	 * Define LF_LANG constant as early as possible (file-load time via constructor).
	 */
	private function define_lang_constant(): void {
		if ( defined( 'LF_LANG' ) ) return;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- `LF_LANG` is documented in CONTRIBUTING.md as one of the canonical project prefixes (`LF_` for short identifiers, alongside `LINGUAFORGE_*`). WPCS rejects it because the `LF` stem is below its 3-char minimum, but the codebase ships with `LF_LANG` as a documented public API since v1.0.
		define( 'LF_LANG', $this->context->detect_lang_safe() );
	}

	/**
	 * Register the hooks that need to fire on every request type.
	 *
	 * Anything that runs on the frontend (locale, rewrite, permalinks, SEO,
	 * template_redirect, render_block) lives here, plus the handful of hooks
	 * that fire on REST or AJAX flows where is_admin() returns false:
	 *
	 *   - wp_after_insert_post — fires from REST `/wp/v2/posts/{id}` PUTs as well as admin saves.
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

		// Locale + translation files
		$this->locale->register_hooks();
		$this->i18n->register_hooks();

		// Redirects and init-time routing
		$this->redirector->register_hooks();

		// Rewrite rules + query filters
		$this->rewrite->register_hooks();
		$this->query_filter->register_hooks();

		// SEO
		$this->hreflang->register_hooks();

		// Search
		$this->search_query->register_hooks();

		// Save handler + cache clear
		$this->sync->register_hooks();
		$this->trid_group->register_hooks();

		// DB migration
		$this->migrator->register_hooks();

		// Frontend scripts
		$this->scripts->register_hooks();

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
	 * Split out per REVIEW.md §3.3 and §2.2.
	 */
	private function register_admin_hooks(): void {
		$this->meta_boxes->register_hooks();
		$this->columns->register_hooks();
		$this->filters->register_hooks();
		$this->scripts->register_admin_hooks();
	}

	// =========================================================
	// META REGISTRATION
	// =========================================================

	public function register_meta(): void {
		$auth = function() { return current_user_can( 'edit_posts' ); };

		register_post_meta( '', '_lf_lang', [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => $auth,
		] );

		register_post_meta( '', '_lf_trid', [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => $auth,
		] );

		register_post_meta( '', '_lf_source_updated_at', [
			'type'          => 'number',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => $auth,
		] );

		register_post_meta( '', '_lf_translation_source_updated_at', [
			'type'          => 'number',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => $auth,
		] );
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
			'canLockBlocks'        => ! (bool) get_option( 'linguaforge_block_editor_allow_lock_blocks', false ),
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
	// DEBUG
	// =========================================================

	public function debug( string $message, array $context = [] ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) return;

		$prefix = '[LANG ROUTER] ';
		if ( ! empty( $context ) ) $message .= ' | ' . wp_json_encode( $context );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log.
		error_log( $prefix . $message );
	}

	public function debug_system_init(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return;
		if ( is_admin() ) return;
		$this->debug( '=========================================' );
		$this->debug( 'SYSTEM INIT', [
			'mode' => $this->hreflang->hreflang_mode(),
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

	// =========================================================
	// PUBLIC PROXY API
	//
	// These methods delegate to sub-objects so that all existing callers
	// (wrapper functions in language-router.php, the AI module, CLI, etc.)
	// continue to call Router::get_instance()->method() unchanged.
	// =========================================================

	// -- Context --
	public function source_language(): string                        { return $this->context->source_language(); }
	public function languages(): array                               { return $this->context->languages(); }
	public function is_valid_lang( $lang ): bool                     { return $this->context->is_valid_lang( $lang ); }
	public function is_system_request(): bool                        { return $this->context->is_system_request(); }
	public function set_lang_cookie( string $lang ): void            { $this->context->set_lang_cookie( $lang ); }
	public function safe_query_args( string $url ): string           { return $this->context->safe_query_args( $url ); }
	public function detect_lang_safe(): string                       { return $this->context->detect_lang_safe(); }
	public function detect_lang(): string                            { return $this->context->detect_lang(); }

	// -- LocaleDetector --
	public function locale_from_lang( string $lang ): string         { return $this->locale->locale_from_lang( $lang ); }
	public function language_label( string $lang ): string           { return $this->locale->language_label( $lang ); }

	// -- TridGroup --
	public function get_trid( int $id ): string                      { return $this->trid_group->get_trid( $id ); }
	public function set_trid( int $id, string $v ): void             { $this->trid_group->set_trid( $id, $v ); }
	public function get_lang( int $id ): string                      { return $this->trid_group->get_lang( $id ); }
	public function set_lang( int $id, string $v ): void             { $this->trid_group->set_lang( $id, $v ); }
	public function get_translations( int $post_id ): array          { return $this->trid_group->get_translations( $post_id ); }
	public function clear_translation_cache( int $post_id ): void    { $this->trid_group->clear_translation_cache( $post_id ); }
	public function get_missing_languages( int $post_id ): array     { return $this->trid_group->get_missing_languages( $post_id ); }

	// -- Sync --
	public function mark_source_updated( int $post_id ): void        { $this->sync->mark_source_updated( $post_id ); }
	public function mark_translation_synced( int $post_id ): void    { $this->sync->mark_translation_synced( $post_id ); }
	public function is_outdated( int $post_id ): bool                 { return $this->sync->is_outdated( $post_id ); }
	public function resolve_template_for_lang( $post, string $lang ): ?string { return $this->sync->resolve_template_for_lang( $post, $lang ); }
	public function template_exists( string $slug ): bool            { return $this->sync->template_exists( $slug ); }

	// -- Rewrite\QueryFilter --
	public function query( array $args = [] ): WP_Query              { return $this->query_filter->query( $args ); }
	public function query_fallback( array $args = [] ): WP_Query     { return $this->query_filter->query_fallback( $args ); }
	public function get_posts( array $args = [], bool $fallback = false ): array { return $this->query_filter->get_posts( $args, $fallback ); }

	// -- Rewrite\Manager --
	public function lang_permalink( string $url, $post ): string     { return $this->rewrite->lang_permalink( $url, $post ); }

	// -- Seo\Hreflang --
	public function hreflang_mode(): string                          { return $this->hreflang->hreflang_mode(); }

	// -- Search\Index --
	public function build_search_content( int $post_id ): void       { $this->search_index->build_search_content( $post_id ); }
	public function extract_block_text( array $block ): string       { return $this->search_index->extract_block_text( $block ); }

	// -- Db\Migrator --
	public function ensure_lang_index(): bool                        { return $this->migrator->ensure_lang_index(); }
}
