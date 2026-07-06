<?php
/**
 * Integration tests for LinguaForge\AI\Admin\FseLocalisation\TemplateDefinitions.
 *
 * Covered here:
 *   get() offers the hardcoded 'home' ("Blog Home") slot alongside 'page',
 *   'single', 'search', 'archive', and 'front-page' — added to support
 *   scaffolding/translating the template WordPress uses for the latest-posts
 *   listing (Settings → Reading → "Your latest posts", or a dedicated "Posts
 *   page"). Runtime rendering of home-{lang} is covered separately in
 *   FrontPageQueryIntegrationTest.
 *
 *   'front-page' and 'home' are excluded from the offered set entirely when
 *   the active theme ships neither base template — confirmed live on an
 *   Agnosis-family site: the theme has home.html but no front-page.html, yet
 *   "Front Page" was still offered (and scaffolded) for secondary languages.
 *   ScaffoldHandler's documented fallback-to-'index' seed then produced a
 *   'front-page-{lang}' row with the theme's generic index content — and
 *   because WordPress's own front-page-before-home precedence meant
 *   FrontPageQuery preferred that row over the correctly authored
 *   'home-{lang}' one, secondary-language homepages rendered the theme's
 *   generic fallback instead of real content. Excluding the slot here stops
 *   it being offered in the first place; FrontPageQuery and Sync carry a
 *   matching runtime guard for rows that already exist from before this fix.
 *
 * Note: TemplateDefinitions::get() caches its result in a static local
 * variable for the lifetime of the PHP process, so these tests rely on
 * get() not having been called earlier in the same process with different
 * theme/template state. Plain wp-env (no block theme active) is the
 * deterministic starting condition exercised here.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\AI\Admin\FseLocalisation\TemplateDefinitions;
use WP_UnitTestCase;

final class TemplateDefinitionsIntegrationTest extends WP_UnitTestCase {

	/**
	 * Plain wp-env has no block theme active, so the theme ships neither
	 * front-page.html nor home.html — both must be excluded from the offered
	 * set rather than falling back to a misleading 'index'-seeded scaffold.
	 */
	public function test_definitions_exclude_front_page_and_home_when_theme_has_neither(): void {
		$defs = TemplateDefinitions::get();

		$this->assertArrayNotHasKey( 'front-page', $defs,
			'"front-page" must not be offered when the active theme has no base front-page.html.' );
		$this->assertArrayNotHasKey( 'home', $defs,
			'"home" must not be offered when the active theme has no base home.html.' );
	}

	/**
	 * The pre-existing hardcoded slots that don't carry the new existence
	 * guard ('page', 'single', 'search', 'archive') must remain present and
	 * unaffected by the 'front-page'/'home' exclusion.
	 */
	public function test_definitions_still_include_unguarded_preexisting_slots(): void {
		$defs = TemplateDefinitions::get();

		foreach ( [ 'page', 'single', 'search', 'archive' ] as $slug ) {
			$this->assertArrayHasKey( $slug, $defs,
				"Pre-existing scaffold slug '{$slug}' must still be present." );
		}
	}
}
