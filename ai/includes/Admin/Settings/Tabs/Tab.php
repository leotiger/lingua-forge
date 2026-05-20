<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

/**
 * Abstract base for all SettingsPage tab classes.
 *
 * Each tab is a pure-static class.  This abstract base documents the expected
 * interface and enforces the three required members via abstract declarations.
 * The optional save() hook is a no-op by default and only needs overriding
 * on tabs that live inside the main settings form (General, API Keys, Limits,
 * Behavior).  Router, Glossary, AI Usage, and Maintenance use their own
 * admin-post actions and therefore do not implement save().
 */
abstract class Tab {

    /**
     * URL fragment / data-lf-panel attribute for this tab (e.g. 'general').
     */
    abstract public static function slug(): string;

    /**
     * Human-readable tab label shown in the tab bar.
     * Must be wrapped with __() so it is translatable.
     */
    abstract public static function label(): string;

    /**
     * Output the tab's inner HTML content.
     * Called by SettingsPage::render() inside the appropriate wrapper div.
     * Must NOT emit the outer <div class="lingua-forge-tab-panel"> wrapper.
     */
    abstract public static function render_content(): void;

    /**
     * Process this tab's slice of $_POST.
     * Called by SettingsPage::handle_save() after the nonce and capability
     * guard have already passed.  Default: no-op.
     */
    public static function save(): void {}
}
