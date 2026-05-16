<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\KeyStore;

defined('ABSPATH') || exit;

class Anthropic implements AIProviderInterface {

    public function __construct(
        private readonly WorkerConfig $config
    ) {}

    public function chat(array $messages): ?string {

        $api_key = KeyStore::get('anthropic');

        if (!$api_key) {
            return null;
        }

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

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'       => $this->config->model,
                    'max_tokens'  => $this->config->max_tokens,
                    'temperature' => $this->config->temperature,
                    'system'      => $system,
                    'messages'    => $formatted_messages,
                ]),
                'timeout' => 120,
            ]
        );

        if (is_wp_error($response)) {
            error_log('LinguaForge AI [Anthropic] request failed: ' . $response->get_error_message());
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if ($http_code < 200 || $http_code >= 300) {
            error_log(sprintf(
                'LinguaForge AI [Anthropic] unexpected HTTP %d: %s',
                $http_code,
                wp_remote_retrieve_body($response)
            ));
            return null;
        }

        $body = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        // Detect output truncation: 'max_tokens' means the model hit the token
        // ceiling before finishing — the result would be a partial translation.
        if (($body['stop_reason'] ?? '') === 'max_tokens') {
            error_log('LinguaForge AI [Anthropic] response truncated: stop_reason=max_tokens. Raise max_tokens in WorkerConfig.');
            return null;
        }

        $text = trim($body['content'][0]['text'] ?? '');

        return $text !== '' ? $text : null;
    }
}
