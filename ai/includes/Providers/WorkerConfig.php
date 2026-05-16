<?php

namespace LinguaForge\AI\Providers;

defined('ABSPATH') || exit;

/**
 * Immutable configuration for a single AI worker invocation.
 *
 * Pass a WorkerConfig to ProviderFactory::make() to select a specific
 * model and generation parameters for a feature. This allows lightweight
 * features (meta descriptions, excerpts) to use a fast/cheap model such
 * as Haiku, while heavier workloads (full-page translation) can opt in
 * to a more capable model such as Sonnet.
 */
class WorkerConfig {

    public function __construct(
        public readonly string $model,
        public readonly int    $max_tokens  = 1024,
        public readonly float  $temperature = 0.4,
    ) {}
}
