<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Tabs\AiUsageTab
 *
 * Settings tab: AI Usage & Cache
 *
 * Thin orchestrator — delegates all rendering to dedicated panel classes:
 *   • UsageStatsPanel — token consumption table
 *   • CacheStatsPanel — API response cache + Translation Memory stats + clear actions
 *
 * @package LinguaForge\AI\Admin\Settings\Tabs
 * @since   1.0.0
 */

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\Settings\Panels\CacheStatsPanel;
use LinguaForge\AI\Admin\Settings\Panels\UsageStatsPanel;

defined( 'ABSPATH' ) || exit;

class AiUsageTab extends Tab {

    public static function slug(): string {
        return 'ai-usage';
    }

    public static function label(): string {
        return __( 'AI Usage & Cache', 'lingua-forge' );
    }

    public static function render_content(): void {
        UsageStatsPanel::render();
        CacheStatsPanel::render();
    }
}
