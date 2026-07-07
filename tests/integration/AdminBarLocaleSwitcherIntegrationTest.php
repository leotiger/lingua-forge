<?php
/**
 * Integration tests for the admin-bar "Preview Language" switcher
 * (MetaBoxes::add_locale_admin_bar_node() in
 * language-router/includes/admin/class-meta-boxes.php).
 *
 * Confirmed live bug: an active router language whose locale_from_lang()
 * mapping collides with another language's (e.g. an unmapped code silently
 * resolving to the same 'en_US' default as English) showed up as BOTH the
 * current language in the parent label AND double-checked in the flyout,
 * because the flyout loop independently re-derived "is this the active
 * language?" per item by comparing locale strings again, instead of reusing
 * the single $current_lang value already resolved for the parent label.
 *
 * The fix (2.5.1) makes every flyout item compare against that single
 * $current_lang value, so exactly one item can ever be marked active —
 * regardless of any locale_from_lang() collision. This suite reproduces the
 * collision synthetically via the `lf_languages_list` filter (an unmapped
 * 'xx' code, which locale_from_lang() has no choice but to default to
 * 'en_US') rather than relying on any specific real-world language, so it
 * keeps covering the defensive fix even after every currently-known mapping
 * gap (see LocaleDetectorTest for those) is closed.
 *
 * Run via: composer test:integration  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use LinguaForge\Router\Context;
use LinguaForge\Router\Router;
use ReflectionClass;
use WP_Admin_Bar;
use WP_UnitTestCase;

final class AdminBarLocaleSwitcherIntegrationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// WP_Admin_Bar is not autoloaded by the WP test bootstrap — core only
		// requires this file from _wp_admin_bar_init() (wp-includes/admin-bar.php),
		// itself hooked to 'init' and gated behind is_admin_bar_showing(), neither
		// of which runs in the WP_UnitTestCase CLI context. Load it directly.
		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}

		update_option( 'linguaforge_primary_language', 'en', false );

		// Context::languages() and ::source_language() cache their result on
		// first call, and the Router singleton persists across every test in
		// this process — without resetting these, a call from an earlier test
		// (or from WP's own bootstrap) can win, silently ignoring both the
		// option write above and force_languages()'s lf_languages_list filter
		// for the rest of this test. Same technique as
		// LanguageUninstallerIntegrationTest.
		$ref = new ReflectionClass( Context::class );
		foreach ( [ 'cached_languages', 'cached_source_language', 'cached_routing_mode', 'cached_base_domain' ] as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( Router::get_instance()->context, null );
		}

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$this->fake_admin();
	}

	protected function tearDown(): void {
		remove_all_filters( 'lf_languages_list' );
		delete_option( 'linguaforge_primary_language' );
		unset( $GLOBALS['current_screen'] );
		parent::tearDown();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/** Make is_admin() return true by seeding a minimal screen object. */
	private function fake_admin(): void {
		$GLOBALS['current_screen'] = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			public function in_admin(): bool { return true; }
		};
	}

	/**
	 * Force the active router language list, bypassing installed-language-pack
	 * discovery entirely so the test is deterministic regardless of what's
	 * actually installed in wp-env.
	 *
	 * @param string[] $langs
	 */
	private function force_languages( array $langs ): void {
		add_filter(
			'lf_languages_list',
			static fn (): array => $langs
		);
	}

	/**
	 * Render the admin-bar node into a real WP_Admin_Bar instance and return
	 * its flattened nodes, keyed by id.
	 *
	 * @return array<string,object>
	 */
	private function render_switcher(): array {
		$bar = new WP_Admin_Bar();
		$bar->initialize();

		Router::get_instance()->meta_boxes->add_locale_admin_bar_node( $bar );

		// WP_Admin_Bar::get_nodes() returns null, not [], when zero nodes have
		// ever been added — its internal check is `if ( ! $nodes ) return;`
		// against a private `$nodes = array()` property, and an empty array is
		// falsy in PHP. That's exactly what happens in the single-language
		// test below: add_locale_admin_bar_node() correctly early-returns
		// without adding even the root node, so this is a real, reachable
		// case here — not just defensive padding.
		$nodes = [];
		foreach ( $bar->get_nodes() ?? [] as $node ) {
			$nodes[ $node->id ] = $node;
		}
		return $nodes;
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * With only 'en' active, the node must not render at all — the method
	 * early-returns when fewer than 2 languages are active. Guards the other
	 * tests' premise that >=2 languages are required to reach the code path
	 * under test.
	 */
	public function test_single_active_language_renders_no_node(): void {
		$this->force_languages( [ 'en' ] );

		$nodes = $this->render_switcher();

		$this->assertArrayNotHasKey( 'lf-locale-switcher', $nodes );
	}

	/**
	 * Reproduces the confirmed live bug: 'xx' is not in locale_from_lang()'s
	 * fallback map, so it silently resolves to 'en_US' — the exact same
	 * locale English resolves to. Before the fix, the flyout loop re-derived
	 * "is this active?" independently per item by comparing locale strings
	 * again, so both 'en' and 'xx' satisfied that check and both got a
	 * checkmark. 'en' is listed first here so the (unchanged) top loop that
	 * resolves $current_lang for the parent label still picks the correct
	 * language first — this test isolates the double-checkmark half of the
	 * bug specifically. (The live report also showed the *label* itself
	 * picking the wrong language, which happens when the colliding code is
	 * iterated before the real one in $languages — a separate, pre-existing
	 * property of the top loop's first-match algorithm that this fix does not
	 * change; closing the specific real-world collision that triggered it is
	 * handled instead by the locale_from_lang() fallback-map fix.)
	 */
	public function test_locale_collision_marks_exactly_one_language_active(): void {
		$this->force_languages( [ 'en', 'xx' ] );

		$nodes = $this->render_switcher();

		$active_ids = array_values( array_filter(
			array_keys( $nodes ),
			static fn ( string $id ): bool =>
				str_starts_with( $id, 'lf-locale-' )
				&& $id !== 'lf-locale-switcher'
				&& str_contains( $nodes[ $id ]->meta['class'] ?? '', 'lf-locale-current' )
		) );

		$this->assertCount(
			1,
			$active_ids,
			'Exactly one flyout item must be marked active, even when two language codes resolve to the same locale.'
		);
	}

	/**
	 * The single active item, and the parent label, must both point at the
	 * real current language ('en' — the site's source language and the
	 * fallback current_lang when the admin's user locale is the site
	 * default) rather than the colliding 'xx' code.
	 */
	public function test_locale_collision_resolves_to_real_current_language(): void {
		$this->force_languages( [ 'en', 'xx' ] );

		$nodes = $this->render_switcher();

		$this->assertStringContainsString(
			'EN',
			$nodes['lf-locale-switcher']->title,
			'The parent label must show the real current language (EN), not a colliding unmapped code.'
		);

		$this->assertStringContainsString(
			'lf-locale-current',
			$nodes['lf-locale-en']->meta['class'] ?? '',
			'The EN flyout item must be the one marked active.'
		);
		$this->assertStringNotContainsString(
			'lf-locale-current',
			$nodes['lf-locale-xx']->meta['class'] ?? '',
			'The colliding XX flyout item must not be marked active.'
		);
	}

	/**
	 * Sanity check for the non-colliding case: two languages with distinct,
	 * correctly-mapped locales ('en' and 'de') must still produce exactly one
	 * active item, matching the source language.
	 */
	public function test_no_collision_still_marks_exactly_one_language_active(): void {
		$this->force_languages( [ 'en', 'de' ] );

		$nodes = $this->render_switcher();

		$this->assertStringContainsString( 'lf-locale-current', $nodes['lf-locale-en']->meta['class'] ?? '' );
		$this->assertStringNotContainsString( 'lf-locale-current', $nodes['lf-locale-de']->meta['class'] ?? '' );
	}
}
