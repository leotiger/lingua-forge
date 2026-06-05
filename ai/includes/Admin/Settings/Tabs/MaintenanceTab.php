<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Tabs\MaintenanceTab
 *
 * Settings tab: Maintenance
 *
 * Thin orchestrator — delegates all rendering to dedicated panel classes:
 *   • LanguageOverridesPanel — .mo override files + Loco Translate copy
 *   • DebugFilesPanel        — AI debug log viewer + toggle
 *   • UninstallSettingsPanel — content-deletion on uninstall toggle
 *
 * @package LinguaForge\AI\Admin\Settings\Tabs
 * @since   1.0.0
 */

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\Settings\Panels\DebugFilesPanel;
use LinguaForge\AI\Admin\Settings\Panels\LanguageOverridesPanel;
use LinguaForge\AI\Admin\Settings\Panels\UninstallSettingsPanel;

defined( 'ABSPATH' ) || exit;

class MaintenanceTab extends Tab {

    public static function slug(): string {
        return 'maintenance';
    }

    public static function label(): string {
        return __( 'Maintenance', 'lingua-forge' );
    }

    public static function render_content(): void {
        LanguageOverridesPanel::render();
        DebugFilesPanel::render();
        UninstallSettingsPanel::render();
    }
}
