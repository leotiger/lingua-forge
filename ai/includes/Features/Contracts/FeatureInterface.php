<?php

namespace LinguaForge\AI\Features\Contracts;

defined('ABSPATH') || exit;

use LinguaForge\AI\Providers\WorkerConfig;

interface FeatureInterface {

    /** Unique slug used in REST routes and the registry. */
    public function get_key(): string;

    /** Human-readable label shown on the meta-box button. */
    public function get_label(): string;

    /** Return true when the current user may run this feature on the post. */
    public function supports(int $post_id): bool;

    /**
     * Worker configuration for this feature.
     *
     * Determines the AI model, token budget, and temperature.
     * Lightweight tasks (meta description, excerpt) should use a fast
     * model such as Haiku; heavier tasks (full-page translation) can
     * declare Sonnet or Opus here.
     */
    public function get_worker_config(): WorkerConfig;

    /**
     * Optional extra UI fields to render in the meta box above the button.
     *
     * Each element is an associative array with at least:
     *   'name'  => string   (sent as a key in $params)
     *   'type'  => string   ('select' supported)
     *   'label' => string
     *   'options' => array<string, string>  (for type='select')
     *
     * Return [] when no extra fields are needed.
     */
    public function get_ui_fields(): array;

    /**
     * Post-specific default values for UI fields declared in get_ui_fields().
     *
     * Keyed by field name, e.g. ['target_language' => 'fr'].
     * The MetaBox uses these to pre-select options for the current post.
     * Return [] when no post-specific defaults are needed.
     */
    public function get_field_defaults(int $post_id): array;

    /**
     * Execute the feature and return a response payload.
     *
     * @param  int                  $post_id  Target post / page ID.
     * @param  array<string, mixed> $params   Extra parameters from the UI
     *                                        (e.g. 'target_language').
     * @return array{success: bool, output?: string, type?: string, ...}
     */
    public function run(int $post_id, array $params = []): array;
}
