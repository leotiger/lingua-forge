<?php

namespace LinguaForge\AI\Core;

use LinguaForge\AI\Admin\AdminToolbar;
use LinguaForge\AI\Admin\MetaBox;
use LinguaForge\AI\Admin\PostListColumn;
use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\REST\FeatureController;

defined('ABSPATH') || exit;

class Plugin {

    public static function init(): void {

        add_action('init', [self::class, 'boot']);
    }

    /**
     * Boot the AI sub-module's admin / REST surface.
     *
     * Short-circuits on plain frontend page requests so the
     * MetaBox / SettingsPage / AdminToolbar / FeatureController autoloads
     * (plus the dozens of WP hooks they register) are skipped for visitors
     * who can never trigger an AI feature anyway. Saves real time on every
     * cold-PHP frontend hit.
     *
     * The boot still runs for:
     *   - Admin requests (wp-admin/*)
     *   - Logged-in admin-bar requests on the frontend (so the toolbar
     *     translate popover is wired up)
     *   - admin-ajax.php requests
     *   - REST requests (REST_REQUEST constant isn't defined yet at the
     *     `init` priority we run on, so we sniff the request URI / query
     *     string instead)
     *   - WP-CLI invocations
     */
    public static function boot(): void {

        if (!self::should_boot()) {
            return;
        }

        Registry::init();

        MetaBox::init();
        PostListColumn::init();
        SettingsPage::init();
        AdminToolbar::init();

        FeatureController::init();
    }

    /**
     * Decide whether the current request needs the AI module's admin/REST surface.
     *
     * Filterable via linguaforge_ai_should_boot so a custom integration can
     * force the boot on otherwise-skipped requests (e.g. a public widget that
     * calls a REST route via a non-standard URL pattern).
     */
    private static function should_boot(): bool {

        // 1. Admin screens (wp-admin/*) and admin-ajax.php both make is_admin() true.
        if (is_admin()) {
            return self::filter_decision(true);
        }

        // 2. Logged-in users still see the admin bar on the frontend; the toolbar
        //    translate popover and any user-facing admin-bar AI affordances need
        //    their hooks registered. Cheap check; better than autoloading the
        //    whole AdminToolbar class only to bail inside.
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return self::filter_decision(true);
        }

        // 3. REST requests. REST_REQUEST is set at parse_request time, which
        //    runs AFTER `init` — so by the time we get here on a REST request
        //    the constant is sometimes defined and sometimes not depending on
        //    other plugins. Sniff the URI as a defensive fallback.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return self::filter_decision(true);
        }

        $request_uri = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only for REST-URL substring detection via strpos, never output or stored.

        // Pretty REST URL: /wp-json/lingua-forge/v1/...
        // Custom prefixes (rest_url_prefix filter) still start with the prefix
        // returned by rest_get_url_prefix(); fall back to "wp-json" since at
        // `init` time the filter may not yet be applied.
        $rest_prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';

        if ($request_uri !== '' && strpos($request_uri, '/' . trim($rest_prefix, '/') . '/') !== false) {
            return self::filter_decision(true);
        }

        // Plain-permalinks REST URL: ?rest_route=/lingua-forge/v1/...
        if ($request_uri !== '' && strpos($request_uri, 'rest_route=') !== false) {
            return self::filter_decision(true);
        }

        // 4. WP-CLI invocations. The whole point of this short-circuit is the
        //    frontend page-render path; CLI deserves the same treatment as REST.
        if (defined('WP_CLI') && WP_CLI) {
            return self::filter_decision(true);
        }

        // Plain frontend visitor — skip the admin/REST init.
        return self::filter_decision(false);
    }

    /**
     * Apply the linguaforge_ai_should_boot filter on a decision value.
     * Kept as a helper so every return point in should_boot() runs through it.
     */
    private static function filter_decision(bool $decision): bool {

        return (bool) apply_filters('linguaforge_ai_should_boot', $decision);
    }
}
