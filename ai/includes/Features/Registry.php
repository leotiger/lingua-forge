<?php

namespace LinguaForge\AI\Features;

use LinguaForge\AI\Features\Contracts\FeatureInterface;

defined('ABSPATH') || exit;

class Registry {

    private static array $features = [];

    public static function init(): void {

        self::register(new MetaDescription());
        self::register(new ExcerptGenerator());
        self::register(new Translation());
        self::register(new ContentGenerator());
    }

    public static function register(
        FeatureInterface $feature
    ): void {

        self::$features[$feature->get_key()] = $feature;
    }

    public static function all(): array {

        return self::$features;
    }

    public static function get(string $key): ?FeatureInterface {

        return self::$features[$key] ?? null;
    }
}
