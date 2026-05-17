<?php

namespace LinguaForge\AI\Providers;

defined('ABSPATH') || exit;

/**
 * OpenAI provider via the Chat Completions API.
 *
 * API specifics handled here:
 *   - System messages stay inline in the messages array (no special handling).
 *   - Auth via `Authorization: Bearer …` header.
 *   - Truncation marker: `choices[0].finish_reason === 'length'`.
 *   - Response text path: `choices[0].message.content`.
 *
 * Everything else (HTTP retry/backoff, error logging, JSON parsing) is inherited
 * from AbstractProvider.
 */
class OpenAI extends AbstractProvider {

    protected function key_slug(): string {
        return 'openai';
    }

    protected function provider_label(): string {
        return 'OpenAI';
    }

    protected function build_request(array $messages, string $api_key): array {

        $body = [
            'model'       => $this->config->model,
            'messages'    => $messages,
            'temperature' => $this->config->temperature,
            'max_tokens'  => $this->config->max_tokens,
        ];

        // Structured-output mode: ask the API to constrain the response to a
        // JSON object conforming to the supplied schema. OpenAI enforces this
        // server-side, so a malformed or schema-violating response cannot reach
        // the caller — invalid generations are retried by OpenAI internally.
        if ($this->config->response_schema !== null) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => 'lingua_forge_response',
                    'strict' => true,
                    'schema' => $this->config->response_schema,
                ],
            ];
        }

        return [
            'https://api.openai.com/v1/chat/completions',
            [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            $body,
        ];
    }

    protected function is_truncated(array $decoded): bool {
        return ($decoded['choices'][0]['finish_reason'] ?? '') === 'length';
    }

    protected function extract_text(array $decoded): string {
        return (string) ($decoded['choices'][0]['message']['content'] ?? '');
    }

    protected function extract_usage(array $decoded): ?array {

        $usage = $decoded['usage'] ?? null;
        if (!is_array($usage)) {
            return null;
        }

        return [
            'input'  => (int) ($usage['prompt_tokens']     ?? 0),
            'output' => (int) ($usage['completion_tokens'] ?? 0),
        ];
    }
}
