<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\KeyStore;

defined('ABSPATH') || exit;

class OpenAI implements AIProviderInterface {

    public function __construct(
        private readonly WorkerConfig $config
    ) {}

    public function chat(array $messages): ?string {

        $api_key = KeyStore::get('openai');

        if (!$api_key) {
            return null;
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'       => $this->config->model,
                    'messages'    => $messages,
                    'temperature' => $this->config->temperature,
                    'max_tokens'  => $this->config->max_tokens,
                ]),
                'timeout' => 120,
            ]
        );

        if (is_wp_error($response)) {
            error_log('LinguaForge AI [OpenAI] request failed: ' . $response->get_error_message());
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if ($http_code < 200 || $http_code >= 300) {
            error_log(sprintf(
                'LinguaForge AI [OpenAI] unexpected HTTP %d: %s',
                $http_code,
                wp_remote_retrieve_body($response)
            ));
            return null;
        }

        $body = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        // Detect output truncation: 'length' means the model hit max_tokens
        // before finishing — the result would be a partial translation.
        if (($body['choices'][0]['finish_reason'] ?? '') === 'length') {
            error_log('LinguaForge AI [OpenAI] response truncated: finish_reason=length. Raise max_tokens in WorkerConfig.');
            return null;
        }

        $text = trim($body['choices'][0]['message']['content'] ?? '');

        return $text !== '' ? $text : null;
    }
}
