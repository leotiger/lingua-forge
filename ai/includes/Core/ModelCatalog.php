<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Static catalog of known AI models per provider.
 *
 * Serves two purposes:
 *   1. Populates <datalist> suggestions on the Settings → General → Models
 *      inputs so admins can discover valid model identifiers without leaving
 *      the settings page.
 *   2. Provides tier guidance and a one-line note so admins understand which
 *      model is appropriate for which task.
 *
 * The catalog is updated with each Lingua Forge release to track new models.
 * When an API key is configured and the "Test connection" button is clicked,
 * the provider's models endpoint is also queried and any newly-released
 * models are merged into the datalist suggestions automatically.
 *
 * Tier mapping:
 *   light   — fast, cost-effective; suitable for short structured tasks
 *             (meta descriptions, excerpt generation, quick translations).
 *             Light-to-mid-tier models are fully sufficient for all
 *             translation and content-generation tasks in Lingua Forge.
 *   quality — higher-capability; the default tier for full-page translation
 *             and content generation.  Mid-range "quality" models (Sonnet,
 *             GPT-4o, Gemini 2.0/2.5 Flash) deliver excellent results and
 *             are the recommended sweet-spot.
 *   max     — flagship model; higher cost with marginal gains for structured
 *             translation work.  Use only when output quality is critical and
 *             budget is not a concern.
 */
class ModelCatalog {

    /**
     * Curated model catalog, ordered light → quality → max within each provider.
     *
     * @var array<string, array<string, array{tier: string, label: string, note: string}>>
     */
    private const CATALOG = [

        'anthropic' => [
            // Light tier ─────────────────────────────────────────────────────
            'claude-haiku-4-5-20251001' => [
                'tier'  => 'light',
                'label' => 'Claude Haiku 4.5',
                'note'  => 'Fast · lowest cost (default light)',
            ],
            'claude-3-5-haiku-20241022' => [
                'tier'  => 'light',
                'label' => 'Claude 3.5 Haiku',
                'note'  => 'Fast · cost-effective (prev gen)',
            ],
            // Quality tier ───────────────────────────────────────────────────
            'claude-sonnet-4-6'         => [
                'tier'  => 'quality',
                'label' => 'Claude Sonnet 4.6',
                'note'  => 'Balanced · recommended for translation (default quality)',
            ],
            'claude-sonnet-4-5'         => [
                'tier'  => 'quality',
                'label' => 'Claude Sonnet 4.5',
                'note'  => 'Balanced (prev gen)',
            ],
            'claude-3-7-sonnet-20250219' => [
                'tier'  => 'quality',
                'label' => 'Claude 3.7 Sonnet',
                'note'  => 'Balanced (prev gen)',
            ],
            // Max tier ───────────────────────────────────────────────────────
            'claude-opus-4-6'           => [
                'tier'  => 'max',
                'label' => 'Claude Opus 4.6',
                'note'  => 'Highest capability · overkill for translation',
            ],
        ],

        'openai' => [
            // Light tier ─────────────────────────────────────────────────────
            'gpt-4.1-nano' => [
                'tier'  => 'light',
                'label' => 'GPT-4.1 nano',
                'note'  => 'Fastest · lowest cost',
            ],
            'gpt-4.1-mini' => [
                'tier'  => 'light',
                'label' => 'GPT-4.1 mini',
                'note'  => 'Fast · cost-effective (latest)',
            ],
            'gpt-4o-mini'  => [
                'tier'  => 'light',
                'label' => 'GPT-4o mini',
                'note'  => 'Fast · cost-effective (default light)',
            ],
            // Quality tier ───────────────────────────────────────────────────
            'gpt-4.1'      => [
                'tier'  => 'quality',
                'label' => 'GPT-4.1',
                'note'  => 'Balanced · latest',
            ],
            'gpt-4o'       => [
                'tier'  => 'quality',
                'label' => 'GPT-4o',
                'note'  => 'Balanced · recommended for translation (default quality)',
            ],
            'o3-mini'      => [
                'tier'  => 'quality',
                'label' => 'o3 mini',
                'note'  => 'Reasoning model · slower, higher latency',
            ],
            'o1-mini'      => [
                'tier'  => 'quality',
                'label' => 'o1 mini',
                'note'  => 'Reasoning model · slower (prev gen)',
            ],
        ],

        'gemini' => [
            // Light tier ─────────────────────────────────────────────────────
            'gemini-2.5-flash-lite' => [
                'tier'  => 'light',
                'label' => 'Gemini 2.5 Flash Lite',
                'note'  => 'Fastest · lowest cost (default light)',
            ],
            // Quality tier ───────────────────────────────────────────────────
            'gemini-2.5-flash'      => [
                'tier'  => 'quality',
                'label' => 'Gemini 2.5 Flash',
                'note'  => 'Balanced · latest recommended (default quality)',
            ],
            'gemini-1.5-pro'        => [
                'tier'  => 'quality',
                'label' => 'Gemini 1.5 Pro',
                'note'  => 'Balanced (legacy)',
            ],
            // Max tier ───────────────────────────────────────────────────────
            'gemini-2.5-pro'        => [
                'tier'  => 'max',
                'label' => 'Gemini 2.5 Pro',
                'note'  => 'Highest capability · overkill for translation',
            ],
        ],
    ];

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * All catalog entries for a given provider, ordered light → quality → max.
     *
     * @return array<string, array{tier: string, label: string, note: string}>
     */
    public static function for_provider(string $provider): array {
        return self::CATALOG[$provider] ?? [];
    }

    /**
     * Catalog model IDs for a provider — convenience wrapper for datalist
     * generation where only the IDs are needed.
     *
     * @return string[]
     */
    public static function ids_for_provider(string $provider): array {
        return array_keys(self::for_provider($provider));
    }

    /**
     * Full catalog, all providers.
     *
     * @return array<string, array<string, array{tier: string, label: string, note: string}>>
     */
    public static function all(): array {
        return self::CATALOG;
    }

    /**
     * Merge a live model list from the provider API with the static catalog.
     *
     * Catalog models are listed first (preserving their curated order), then
     * any model IDs returned by the live API that are not in the catalog are
     * appended at the end.  Duplicates are removed so each ID appears once.
     *
     * @param  string   $provider  Provider slug.
     * @param  string[] $live_ids  Model IDs returned by the provider's API.
     * @return string[]
     */
    public static function merge_live(string $provider, array $live_ids): array {
        $catalog_ids = self::ids_for_provider($provider);
        $extra       = array_values(array_diff($live_ids, $catalog_ids));
        return array_merge($catalog_ids, $extra);
    }

    // ── Live fetch ────────────────────────────────────────────────────────────

    /**
     * Fetch the list of available model IDs from the provider's own API.
     *
     * Returns raw model IDs (not merged with the catalog) — call merge_live()
     * on the result when you want a combined list.
     *
     * Uses wp_remote_get with a 5-second timeout.  Returns an empty array on
     * any network or parse failure; callers must treat empty as "unavailable"
     * rather than "no models exist".
     *
     * @param  string $provider  Provider slug: 'anthropic', 'openai', 'gemini'.
     * @param  string $api_key   Valid API key for the provider.
     * @return string[]          Filtered model IDs, or [] on failure.
     */
    public static function fetch_from_api(string $provider, string $api_key): array {
        return match ($provider) {
            'anthropic' => self::fetch_anthropic($api_key),
            'openai'    => self::fetch_openai($api_key),
            'gemini'    => self::fetch_gemini($api_key),
            default     => [],
        };
    }

    // ── Provider-specific fetches ─────────────────────────────────────────────

    /**
     * @return string[]
     */
    private static function fetch_anthropic(string $api_key): array {

        $response = wp_remote_get(
            'https://api.anthropic.com/v1/models',
            [
                'timeout' => 5,
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body['data'] ?? null)) {
            return [];
        }

        $ids = array_column($body['data'], 'id');

        return array_values(array_filter($ids, 'is_string'));
    }

    /**
     * @return string[]
     */
    private static function fetch_openai(string $api_key): array {

        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            [
                'timeout' => 5,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body['data'] ?? null)) {
            return [];
        }

        $ids = array_column($body['data'], 'id');

        // Keep only chat-capable models.  Exclude embeddings, TTS, Whisper,
        // DALL-E, fine-tunes, and deprecated completions models.
        return array_values(array_filter($ids, function ($id) {
            return is_string($id) && (bool) preg_match('/^(gpt-[0-9]|o[1-9])/i', $id);
        }));
    }

    /**
     * @return string[]
     */
    private static function fetch_gemini(string $api_key): array {

        $url = add_query_arg(
            'key',
            $api_key,
            'https://generativelanguage.googleapis.com/v1beta/models'
        );

        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body['models'] ?? null)) {
            return [];
        }

        $ids = [];

        foreach ($body['models'] as $model) {

            if (!is_array($model)) {
                continue;
            }

            // Only keep models that support generateContent (chat-capable).
            $methods = (array) ($model['supportedGenerationMethods'] ?? []);
            if (!in_array('generateContent', $methods, true)) {
                continue;
            }

            // Strip "models/" prefix: "models/gemini-2.0-flash" → "gemini-2.0-flash".
            $raw_name = (string) ($model['name'] ?? '');
            $id       = preg_replace('#^models/#', '', $raw_name);

            if ($id !== '' && str_starts_with($id, 'gemini-')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
