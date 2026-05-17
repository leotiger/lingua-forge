<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\UsageRecorder;

defined('ABSPATH') || exit;

/**
 * Template-method base class shared by all AI providers.
 *
 * Concrete providers (Anthropic, OpenAI, Gemini, …) supply only the parts
 * that actually differ between APIs:
 *
 *   - key_slug()       — which KeyStore entry holds the API key
 *   - provider_label() — human-readable label used in error_log prefixes
 *   - build_request()  — URL, headers, body for wp_remote_post
 *   - is_truncated()   — provider-specific "hit max_tokens" marker
 *   - extract_text()   — pull the assistant message text out of the response
 *
 * Everything else — key lookup, retry/backoff on transient failures,
 * HTTP error handling, truncation logging, JSON decoding, empty-string
 * normalization — lives here exactly once.
 *
 * The chat() method is `final` so subclasses can't accidentally bypass the
 * retry loop or error logging by overriding it.
 */
abstract class AbstractProvider implements AIProviderInterface {

    public function __construct(
        protected readonly WorkerConfig $config
    ) {}

    final public function chat(array $messages): ?string {

        $api_key = KeyStore::get($this->key_slug());

        if (!$api_key) {
            $this->log_error('no API key found — check Settings → LinguaForge AI or set the ' . strtoupper($this->key_slug()) . '_API_KEY environment variable');
            return null;
        }

        [$url, $headers, $body] = $this->build_request($messages, $api_key);

        $response = $this->post_with_retry($url, $headers, $body);

        if (is_wp_error($response)) {
            // Final-attempt failure already logged inside post_with_retry().
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if ($http_code < 200 || $http_code >= 300) {
            $this->log_error(sprintf(
                'unexpected HTTP %d: %s',
                $http_code,
                wp_remote_retrieve_body($response)
            ));
            return null;
        }

        $decoded = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        if (!is_array($decoded)) {
            $this->log_error('response body is not valid JSON');
            return null;
        }

        if ($this->is_truncated($decoded)) {
            $this->log_error('response truncated; raise max_tokens in WorkerConfig');
            return null;
        }

        $text = trim($this->extract_text($decoded));

        if ($text === '') {
            $this->log_error('provider returned a successful response with empty text content — check model configuration in Settings → LinguaForge AI');
            return null;
        }

        // Usage telemetry — recorded only when a feature has pushed a
        // tracking context via UsageRecorder::tracked(). Test-Connection
        // pings (and any future bare callers) leave the stack empty and
        // therefore don't pollute the usage table.
        $usage = $this->extract_usage($decoded);
        if (is_array($usage)) {
            UsageRecorder::record(
                $this->provider_label(),
                $this->config->model,
                (int) ($usage['input']  ?? 0),
                (int) ($usage['output'] ?? 0)
            );
        }

        return $text;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEMPLATE HOOKS — implemented by each concrete provider
    // ─────────────────────────────────────────────────────────────────────────

    /** KeyStore slug for the API key (e.g. 'anthropic'). */
    abstract protected function key_slug(): string;

    /** Human-readable label used in error_log() messages (e.g. 'Anthropic'). */
    abstract protected function provider_label(): string;

    /**
     * Return [$url, $headers, $body] for wp_remote_post.
     * $body is encoded by post_with_retry() — return the raw associative array.
     */
    abstract protected function build_request(array $messages, string $api_key): array;

    /** True when the decoded response indicates a max-tokens truncation. */
    abstract protected function is_truncated(array $decoded): bool;

    /** Return the assistant message text from the decoded response. */
    abstract protected function extract_text(array $decoded): string;

    /**
     * Return the token usage for this call, or null when the provider didn't
     * supply usage data on this particular response.
     *
     * Normalized shape:
     *
     *   [
     *     'input'  => int,  // prompt / input tokens (incl. cached if reported separately)
     *     'output' => int,  // completion / output tokens
     *   ]
     *
     * Returning null is fine — UsageRecorder treats it as "skip this call".
     */
    abstract protected function extract_usage(array $decoded): ?array;

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP — POST with retry/backoff on transient failures
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * wp_remote_post with one retry on transient failure.
     *
     * Retries when wp_remote_post returns a WP_Error (timeout, TCP reset, DNS
     * hiccup) or when the response carries an HTTP status in `retry_statuses`
     * (default 429/500/502/503/504). Never retries on 400/401/403/404 — those
     * are deterministic failures (bad request, bad key, forbidden, missing).
     *
     * Tunable via:
     *
     *   apply_filters('linguaforge_ai_retry_policy', [
     *       'attempts'       => 2,            // total attempts including the first
     *       'delay_ms'       => 1500,         // base delay before each retry
     *       'jitter_ms'      => 500,          // random 0..jitter added per retry
     *       'retry_statuses' => [429, 500, 502, 503, 504],
     *   ], $provider_label);
     */
    private function post_with_retry(string $url, array $headers, array $body): array|\WP_Error {

        $policy = apply_filters(
            'linguaforge_ai_retry_policy',
            [
                'attempts'       => 2,
                'delay_ms'       => 1500,
                'jitter_ms'      => 500,
                'retry_statuses' => [429, 500, 502, 503, 504],
            ],
            $this->provider_label()
        );

        $attempts        = max(1, (int) ($policy['attempts']       ?? 2));
        $delay_ms        = max(0, (int) ($policy['delay_ms']       ?? 1500));
        $jitter_ms       = max(0, (int) ($policy['jitter_ms']      ?? 500));
        $retry_statuses  = (array)        ($policy['retry_statuses'] ?? [429, 500, 502, 503, 504]);

        $args = [
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => 120,
        ];

        $response = null;

        for ($i = 0; $i < $attempts; $i++) {

            if ($i > 0) {
                $sleep_ms = $delay_ms + ($jitter_ms > 0 ? random_int(0, $jitter_ms) : 0);
                usleep($sleep_ms * 1000);
            }

            $response = wp_remote_post($url, $args);

            if (!$this->is_retryable($response, $retry_statuses)) {
                break;
            }

            // If another attempt remains, log the retry decision.
            if ($i < $attempts - 1) {
                $this->log_retry($response, $i + 1, $attempts);
            }
        }

        // Preserve the original behavior: a wp_error on the final attempt is
        // logged through the same channel callers were already grepping for.
        if (is_wp_error($response)) {
            $this->log_error('request failed: ' . $response->get_error_message());
        }

        return $response;
    }

    /**
     * True when $response is transient — worth a retry.
     */
    private function is_retryable($response, array $retry_statuses): bool {
        if (is_wp_error($response)) {
            return true;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return in_array($code, $retry_statuses, true);
    }

    private function log_retry($response, int $attempt_number, int $total_attempts): void {

        $reason = is_wp_error($response)
            ? $response->get_error_message()
            : 'HTTP ' . (int) wp_remote_retrieve_response_code($response);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic log for AI request retries; same channel as request failures.
        error_log(sprintf(
            'LinguaForge AI [%s] retry %d/%d after %s',
            $this->provider_label(),
            $attempt_number,
            $total_attempts - 1,
            $reason
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGGING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write a diagnostic line in the format the plugin FAQ already documents.
     * Subclasses can call this if they need extra context-specific logging.
     */
    protected function log_error(string $message): void {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log; the plugin FAQ directs users here when AI requests fail.
        error_log(sprintf(
            'LinguaForge AI [%s] %s',
            $this->provider_label(),
            $message
        ));
    }
}
