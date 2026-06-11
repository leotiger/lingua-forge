<?php
/**
 * Class LinguaForge\Router\Routing\FrontPageQuery
 *
 * Substitutes the active-language front-page block template at runtime.
 *
 * When a block theme ships a `front-page.html` template, WordPress applies it
 * automatically whenever the site's static front page is rendered. This class
 * mirrors the pattern used in Search\Query::override_search_template(): it hooks
 * into `get_block_templates` on the frontend and, when `is_front_page()` is true
 * and a language-specific variant exists (e.g. `front-page-es`), substitutes it
 * for the base `front-page` template so the correct localised layout is used.
 *
 * The hook is a no-op when:
 *   - LF_LANG is not defined (frontend without a language prefix, or admin/REST).
 *   - `is_front_page()` returns false.
 *   - No `front-page-{lang}` template exists in the DB or theme files.
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

	public function register_hooks(): void {
		add_filter( 'get_block_templates', [ $this, 'override_front_page_template' ], 10, 3 );
	}

	/**
	 * Swap `front-page` template for `front-page-{lang}` on the frontend.
	 *
	 * Only acts when:
	 *   - We are resolving wp_template objects (not wp_template_part).
	 *   - LF_LANG is defined and non-empty (i.e. a language prefix is active).
	 *   - is_front_page() is true (static front page or blog index as front page).
	 *   - A `front-page-{lang}` template actually exists (DB or theme file).
	 *
	 * @param  \WP_Block_Template[] $templates     Current template candidates.
	 * @param  array                $query         Original query args passed to get_block_templates().
	 * @param  string               $template_type 'wp_template' or 'wp_template_part'.
	 * @return \WP_Block_Template[]
	 */
	public function override_front_page_template( array $templates, $query, string $template_type ): array {
		if ( $template_type !== 'wp_template' ) return $templates;
		if ( ! defined( 'LF_LANG' ) || LF_LANG === '' ) return $templates;
		if ( ! is_front_page() ) return $templates;
		if ( $this->in_override ) return $templates;

		$this->in_override = true;
		$lang_slug         = 'front-page-' . LF_LANG;
		$found             = get_block_templates( [ 'slug__in' => [ $lang_slug ] ] );
		$this->in_override = false;

		return ! empty( $found ) ? $found : $templates;
	}
}
