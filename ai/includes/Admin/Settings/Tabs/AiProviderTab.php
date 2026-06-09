<?php

namespace LinguaForge\AI\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

/**
 * Settings tab: AI Provider
 *
 * Combines provider/model configuration (formerly the "General" tab) with
 * API key management (formerly the "API Keys" tab) into a single tab.
 *
 * Render order:
 *   1. Active Provider + Models  (GeneralTab::render_content)
 *   2. API Keys + Test connection (ApiKeysTab::render_content)
 */
class AiProviderTab extends Tab {

    public static function slug(): string {
        return 'ai-provider';
    }

    public static function label(): string {
        return __( 'AI Provider', 'lingua-forge' );
    }

    public static function render_content(): void {
        GeneralTab::render_content();
        ApiKeysTab::render_content();
    }

    /**
     * Proxy for the test-connection AJAX handler.
     *
     * Wired to wp_ajax_linguaforge_test_provider in SettingsPage so that
     * the AJAX action is owned by this tab rather than the now-retired
     * ApiKeysTab.  Behaviour is identical.
     */
    public static function ajax_test_provider(): void {
        ApiKeysTab::ajax_test_provider();
    }
}
