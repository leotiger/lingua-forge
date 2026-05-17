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
 *
 * When $response_schema is non-null, concrete providers ask the model to
 * emit a JSON object conforming to the schema. Each provider implements
 * this in its API-native way:
 *
 *   - OpenAI : response_format: {type: "json_schema", json_schema: …}
 *   - Gemini : generationConfig.responseSchema + responseMimeType
 *   - Anthropic: assistant-message prefill with "{" + system directive
 *                (the Messages API has no first-class schema mode)
 *
 * Callers receive the JSON string back from chat() as if it were ordinary
 * text — it's the caller's responsibility to json_decode() and validate.
 */
class WorkerConfig {

    public function __construct(
        public readonly string $model,
        public readonly int    $max_tokens  = 1024,
        public readonly float  $temperature = 0.4,
        /**
         * JSON schema (associative-array form) the response must conform to,
         * or null for free-form text output. The schema is provider-agnostic
         * — concrete providers translate it into their native request shape.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $response_schema = null,
    ) {}
}
