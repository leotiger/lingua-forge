<?php
/**
 * Class LinguaForge\Router\Routing\FrontPageQuery
 *
 * Substitutes the active-language front-page / blog-home block template at
 * runtime.
 *
 * When a block theme ships a `front-page.html` template, WordPress applies it
 * automatically whenever the site's front page is rendered — whether that front
 * page is a static Page or the latest-posts listing. This class mirrors the
 * pattern used in Search\Query::override_search_template(): it hooks into
 * `get_block_templates` on the frontend and, when a language-specific variant
 * exists, substitutes it for the base template so the correct localised layout
 * is used.
 *
 * Two base slugs are handled, matching WordPress's own template hierarchy:
 *   - `front-page` → `front-page-{lang}`, whenever `is_front_page()` is true.
 *   - `home`       → `home-{lang}`, whenever `is_home()` is true. This covers
 *     both "Your latest posts" as the site's front page (is_front_page() is
 *     ALSO true in that case) and a dedicated "Posts page" alongside a static
 *     front page (is_front_page() is false).
 *
 * When both is_front_page() and is_home() are true (posts-as-front, no separate
 * posts page configured), `front-page-{lang}` takes priority over `home-{lang}`
 * if it exists — mirroring WordPress's own front-page-before-home precedence —
 * and `home-{lang}` is used only as a fallback for themes that ship `home.html`
 * but no `front-page.html`.
 *
 * Each candidate is only attempted when the active theme actually ships the
 * corresponding BASE template (`front-page.html` / `home.html`). Without this
 * guard, a `front-page-{lang}` row that was scaffolded (and never customised)
 * on a theme with no `front-page.html` at all would still win the priority
 * race for every language that has one — even though WordPress itself would
 * never select `front-page.html` at the source language, since it doesn't
 * exist there. `TemplateDefinitions::get()` also excludes such a slot from
 * being offered for scaffolding in the first place; this guard covers rows
 * that already exist from before that exclusion was added, or that a
 * developer created directly.
 *
 * The hook is a no-op when:
 *   - LF_LANG is not defined (frontend without a language prefix, or admin/REST).
 *   - Neither is_front_page() nor is_home() is true.
 *   - The active theme has neither a matching base template nor its `-{lang}`
 *     variant (DB or theme file).
 * In all those cases the original `$templates` array is returned unchanged.
 */

namespace LinguaForge\Router\Routing;

defined( 'ABSPATH' ) || exit;

class FrontPageQuery {

	/**
	 * Reentrancy guard — prevents infinite recursion when we call get_block_templates() inside the filter.
	 *
	 * @var bool
	 */
	private bool $in_override = false;

	/**
	 * Per-request cache of base_template_exists() lookups, keyed by slug.
	 * Avoids repeating the get_block_template() call for every page render —
	 * this filter can fire more than once per request (header/footer parts
	 * each trigger their own get_block_templates() resolution).
	 *
	 * @var array<string,bool>
	 */
	private array $base_exists_cache = [];

	public function register_hooks(): void {
		add_filter( 'get_block_templates', [ $this, 'override_front_page_template' ], 10, 3 );
	}

	/**
	 * Swap `front-page`/`home` for their `-{lang}` variants on the frontend.
	 *
	 * Only acts when:
	 *   - We are resolving wp_template objects (not wp_template_part).
	 *   - LF_LANG is defined and non-empty (i.e. a language prefix is active).
	 *   - is_front_page() or is_home() is true.
	 *   - The active theme actually ships the matching BASE template.
	 *   - A matching `-{lang}` template actually exists (DB or theme file).
	 *
	 * @param  \WP_Block_Template[] $templates     Current template candidates.
	 * @param  array                $query         Original query args passed to get_block_templates().
	 * @param  string               $template_type 'wp_template' or 'wp_template_part'.
	 * @return \WP_Block_Template[]
	 */
	public function override_front_page_template( array $templates, $query, string $template_type ): array {
		if ( $template_type !== 'wp_template' ) return $templates;
		if ( ! defined( 'LF_LANG' ) || LF_LANG === '' ) return $templates;
		if ( $this->in_override ) return $templates;

		$is_front = is_front_page();
		$is_home  = is_home();
		if ( ! $is_front && ! $is_home ) return $templates;

		// Base slugs to try, in priority order. `front-page` is only attempted
		// when is_front_page() is true AND the theme actually ships front-page.html;
		// `home` is attempted whenever is_home() is true AND the theme ships
		// home.html. This mirrors WordPress's own template hierarchy, where
		// front-page.html takes precedence over home.html only when both exist —
		// a theme lacking front-page.html entirely never has it "win" at the
		// source language either, so a lang-specific variant must not win here.
		$candidates = [];
		if ( $is_front && $this->base_template_exists( 'front-page' ) ) $candidates[] = 'front-page-' . LF_LANG;
		if ( $is_home  && $this->base_template_exists( 'home' ) )       $candidates[] = 'home-' . LF_LANG;

		$this->in_override = true;
		$found              = [];
		foreach ( $candidates as $lang_slug ) {
			$found = get_block_templates( [ 'slug__in' => [ $lang_slug ] ] );
			if ( ! empty( $found ) ) break;
		}
		$this->in_override = false;

		return ! empty( $found ) ? $found : $templates;
	}

	/**
	 * Whether the active theme ships a base (non-language-suffixed) template
	 * for the given slug — as a theme file, or a Site-Editor-customised DB row.
	 *
	 * @param  string $slug  Base template slug, e.g. 'front-page' or 'home'.
	 * @return bool
	 */
	private function base_template_exists( string $slug ): bool {
		if ( isset( $this->base_exists_cache[ $slug ] ) ) {
			return $this->base_exists_cache[ $slug ];
		}
		$exists = null !== get_block_template( get_stylesheet() . '//' . $slug );
		return $this->base_exists_cache[ $slug ] = $exists;
	}
}
