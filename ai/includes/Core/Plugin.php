<?php

namespace LinguaForge\AI\Core;

use LinguaForge\AI\Admin\AdminToolbar;
use LinguaForge\AI\Admin\MetaBox;
use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\AI\Features\Registry;
use LinguaForge\AI\REST\FeatureController;

defined('ABSPATH') || exit;

class Plugin {

    public static function init(): void {

        add_action('init', [self::class, 'boot']);
    }

    public static function boot(): void {

        Registry::init();

        MetaBox::init();
        SettingsPage::init();
        AdminToolbar::init();

        FeatureController::init();
    }
}
