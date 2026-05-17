<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\KeyStore;

defined('ABSPATH') || exit;

/**
 * Google Gemini provider via the Generative Language REST API.
 *
 * Message format differences from Anthropic / OpenAI:
 *   - System messages are sent as a top-level `system_instruction` object.
 *   - The assistant role is called "model" in Gemini's schema.
 *   - Generation parameters live inside a `generationConfig` key.
 *   - The API key is passed as a query parameter, not a header.
 */
class Gemini implements AIProviderInterface {

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly WorkerConfig $config
    ) {}

    public function chat(array $messages): ?string {

        $api_key = KeyStore::get('gemini');

        if (!$api_key) {
            return null;
        }

        $system_instruction = null;
        $contents           = [];

        foreach ($messages as $message) {

            $role    = $message['role']    ?? '';
            $content = $message['content'] ?? '';

            if ($role === 'system') {
                // Gemini expects system instructions as a separate top-level key.
                $system_instruction = [
                    'parts' => [['text' => $content]],
                ];
                continue;
            }

            // Gemini uses "model" where OpenAI / Anthropic use "assistant".
            $contents[] = [
                'role'  => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $content]],
            ];
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => $this->config->temperature,
                'maxOutputTokens' => $this->config->max_tokens,
            ],
        ];

        if ($system_instruction !== null) {
            $body['system_instruction'] = $system_instruction;
        }

        $url = self::BASE_URL .
               rawurlencode($this->config->model) .
               ':generateContent?key=' . rawurlencode($api_key);

        $response = wp_remote_post(
            $url,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 120,
            ]
        );

        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log; the plugin FAQ directs users here when AI requests fail.
            error_log('LinguaForge AI [Gemini] request failed: ' . $response->get_error_message());
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if ($http_code < 200 || $http_code >= 300) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log for unexpected HTTP responses from the Gemini API.
            error_log(sprintf(
                'LinguaForge AI [Gemini] unexpected HTTP %d: %s',
                $http_code,
                wp_remote_retrieve_body($response)
            ));
            return null;
        }

        $decoded = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        // Detect output truncation: 'MAX_TOKENS' means the model hit the token
        // ceiling before finishing — the result would be a partial translation.
        if (($decoded['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log for truncated AI responses; users are directed here by the plugin FAQ.
            error_log('LinguaForge AI [Gemini] response truncated: finishReason=MAX_TOKENS. Raise max_tokens in WorkerConfig.');
            return null;
        }

        $text = trim(
            $decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''
        );

        return $text !== '' ? $text : null;
    }
}
