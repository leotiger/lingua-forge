<?php

namespace LinguaForge\AI\Providers;

defined('ABSPATH') || exit;

/**
 * Google Gemini provider via the Generative Language REST API.
 *
 * Message format differences from Anthropic / OpenAI:
 *   - System messages are sent as a top-level `system_instruction` object.
 *   - The assistant role is called "model" in Gemini's schema.
 *   - Generation parameters live inside a `generationConfig` key.
 *   - The API key is passed as a query parameter, not a header.
 *   - Truncation marker: `candidates[0].finishReason === 'MAX_TOKENS'`.
 *
 * Everything else (HTTP retry/backoff, error logging, JSON parsing) is inherited
 * from AbstractProvider.
 */
class Gemini extends AbstractProvider {

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    protected function key_slug(): string {
        return 'gemini';
    }

    protected function provider_label(): string {
        return 'Gemini';
    }

    protected function build_request(array $messages, string $api_key): array {

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

        // Structured-output mode: Gemini constrains output to the schema and
        // returns a string of valid JSON. Setting responseMimeType is required
        // alongside responseSchema; without it the schema is ignored.
        if ($this->config->response_schema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema']   = $this->config->response_schema;
        }

        $url = self::BASE_URL
            . rawurlencode($this->config->model)
            . ':generateContent?key=' . rawurlencode($api_key);

        return [
            $url,
            [
                'Content-Type' => 'application/json',
            ],
            $body,
        ];
    }

    protected function is_truncated(array $decoded): bool {
        return ($decoded['candidates'][0]['finishReason'] ?? '') === 'MAX_TOKENS';
    }

    protected function extract_text(array $decoded): string {
        return (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    protected function extract_usage(array $decoded): ?array {

        $usage = $decoded['usageMetadata'] ?? null;
        if (!is_array($usage)) {
            return null;
        }

        return [
            'input'  => (int) ($usage['promptTokenCount']     ?? 0),
            'output' => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }
}
