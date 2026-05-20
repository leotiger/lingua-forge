<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

class Autoloader {

    public static function register(): void {

        spl_autoload_register(function ($class) {

            $prefix = 'LinguaForge\\AI\\';

            if (strpos($class, $prefix) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));

            $path = LINGUAFORGE_AI_PATH .
                '/includes/' .
                str_replace('\\', '/', $relative) .
                '.php';

            if (file_exists($path)) {
                require_once $path;
            }
        });
    }
}

Autoloader::register();
