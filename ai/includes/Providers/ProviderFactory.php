<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Core\Config;
use LinguaForge\AI\Contracts\AIProviderInterface;

defined('ABSPATH') || exit;

/**
 * Creates an AI provider instance for a given WorkerConfig.
 *
 * The active provider (Anthropic / OpenAI / Gemini) is determined by
 * Config::provider().  Each feature supplies its own WorkerConfig to select
 * the model tier ('light' or 'quality') and generation parameters for that
 * specific task.  Model strings are resolved by Config::model() at runtime,
 * so they can be updated from Settings → LinguaForge AI without code changes.
 *
 * When called without a config (rare fallback path) the 'light' model for
 * the active provider is used — the same default as Meta Description and
 * Excerpt Generator.
 */
class ProviderFactory {

    public static function make(
        ?WorkerConfig $config = null
    ): AIProviderInterface {

        $provider = Config::provider();

        // Fallback when no WorkerConfig is supplied: use the 'light' model,
        // which is the cost-effective default for the active provider.
        $config ??= new WorkerConfig(
            model: Config::model('light')
        );

        return match ($provider) {
            'anthropic' => new Anthropic($config),
            'openai'    => new OpenAI($config),
            'gemini'    => new Gemini($config),
            default     => new Anthropic($config),
        };
    }
}
