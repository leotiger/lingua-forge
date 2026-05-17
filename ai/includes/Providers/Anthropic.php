<?php

namespace LinguaForge\AI\Providers;

defined('ABSPATH') || exit;

/**
 * Anthropic Claude provider via the Messages API.
 *
 * API specifics handled here:
 *   - System messages are sent as a top-level `system` string (not in messages).
 *   - Auth via `x-api-key` header and `anthropic-version` header.
 *   - Truncation marker: `stop_reason === 'max_tokens'`.
 *   - Response text path: `content[0].text`.
 *   - Structured output: enforced via system-prompt directive + schema only.
 *     Claude 4+ models reject assistant-turn prefill (HTTP 400); the system
 *     directive alone is sufficient for JSON-only responses.
 *
 * Everything else (HTTP retry/backoff, error logging, JSON parsing) is inherited
 * from AbstractProvider.
 */
class Anthropic extends AbstractProvider {

    protected function key_slug(): string {
        return 'anthropic';
    }

    protected function provider_label(): string {
        return 'Anthropic';
    }

    protected function build_request(array $messages, string $api_key): array {

        $system             = '';
        $formatted_messages = [];

        foreach ($messages as $message) {

            if (($message['role'] ?? '') === 'system') {
                $system = $message['content'] ?? '';
                continue;
            }

            $formatted_messages[] = [
                'role'    => $message['role'],
                'content' => $message['content'],
            ];
        }

        // Structured-output mode.
        //
        // The Messages API has no first-class JSON-schema parameter. We enforce
        // JSON-only output via a strong system-message directive that names the
        // schema and forbids any surrounding text or Markdown code fences.
        //
        // NOTE: Claude 4+ models (claude-sonnet-4-6, claude-opus-4-6, etc.)
        // reject assistant-turn prefill with HTTP 400. The previous Claude 3
        // recipe of appending {role:'assistant', content:'{'} has been removed.
        // The system-prompt directive alone is sufficient for Claude 4.
        if ($this->config->response_schema !== null) {

            $schema_json = (string) wp_json_encode(
                $this->config->response_schema,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            $system = trim(
                $system
                . "\n\nYou MUST respond with a single JSON object that conforms exactly to this schema. "
                . "Do not emit any text outside the JSON object — no preamble, no explanation, no Markdown code fences. "
                . "Schema:\n" . $schema_json
            );
        }

        return [
            'https://api.anthropic.com/v1/messages',
            [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            [
                'model'       => $this->config->model,
                'max_tokens'  => $this->config->max_tokens,
                'temperature' => $this->config->temperature,
                'system'      => $system,
                'messages'    => $formatted_messages,
            ],
        ];
    }

    protected function is_truncated(array $decoded): bool {
        return ($decoded['stop_reason'] ?? '') === 'max_tokens';
    }

    protected function extract_text(array $decoded): string {

        return (string) ($decoded['content'][0]['text'] ?? '');
    }

    protected function extract_usage(array $decoded): ?array {

        $usage = $decoded['usage'] ?? null;
        if (!is_array($usage)) {
            return null;
        }

        // Anthropic returns input_tokens / output_tokens. When prompt caching
        // is in play it also reports cache_creation_input_tokens and
        // cache_read_input_tokens — sum them into the input figure so admins
        // see the full prompt token consumption.
        $input  = (int) ($usage['input_tokens']                ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens']     ?? 0);
        $output = (int) ($usage['output_tokens']               ?? 0);

        return [
            'input'  => $input,
            'output' => $output,
        ];
    }
}
