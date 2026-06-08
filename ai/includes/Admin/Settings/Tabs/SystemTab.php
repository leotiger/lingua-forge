<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Tabs\SystemTab
 *
 * Settings tab: System
 *
 * Thin orchestrator — delegates all rendering to SystemPanel.
 * Read-only diagnostic view; no settings form or admin-post actions.
 *
 * Sections rendered by SystemPanel:
 *   • Environment          — LF / WP / PHP versions, theme, routing mode
 *   • Permalink structure  — compatibility check with a fix link on failure
 *   • Active SEO plugins   — conflict detection (Yoast, Rank Math, AIOSEO, SEOPress)
 *   • WooCommerce          — WC version + translated WC page coverage (when active)
 *   • _lf_lang coverage    — per-post-type count of posts missing _lf_lang (§9.3)
 *   • Rewrite rules        — collapsible dump of LF-owned entries in extra_rules_top
 *   • Debug copy           — one-click plain-text system info for bug reports
 *
 * @package LinguaForge\AI\Admin\Settings\Tabs
 * @since   2.2.5
 */

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\Settings\Panels\SystemPanel;

defined( 'ABSPATH' ) || exit;

class SystemTab extends Tab {

    public static function slug(): string {
        return 'system';
    }

    public static function label(): string {
        return __( 'System', 'lingua-forge' );
    }

    public static function render_content(): void {
        SystemPanel::render();
    }
}
