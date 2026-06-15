<?php

namespace LinguaForge\AI\Providers;

use LinguaForge\AI\Contracts\AIProviderInterface;
use LinguaForge\AI\Core\KeyStore;
use LinguaForge\AI\Core\Log;
use LinguaForge\AI\Core\UsageRecorder;

defined('ABSPATH') || exit;

/**
 * Template-method base class shared by all AI providers.
 *
 * Concrete providers (Anthropic, OpenAI, Gemini, …) supply only the parts
 * that actually differ between APIs:
 *
 *   - key_slug()       — which KeyStore entry holds the API key
 *   - provider_label() — human-readable label used in diagnostic-log prefixes
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

    // ─────────────────────────────────────────────────────────────────────────
    // ERROR SURFACING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Human-readable description of the last failure, or '' on success.
     * Populated at every early-return point in chat() so callers (e.g. the
     * test-connection AJAX handler) can surface a specific reason to the admin
     * rather than the generic "check the error log" fallback.
     *
     * @var string
     */
    protected string $last_error = '';

    /** Return the last failure reason set by chat(). Empty string on success. */
    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * Extract the human-readable error message from a provider error-response body.
     *
     * Default covers the shape shared by Anthropic and Gemini:
     *   { "error": { "message": "..." } }
     *
     * Override in concrete providers for divergent formats (e.g. OpenAI maps
     * error.code === 'insufficient_quota' to a more specific label).
     *
     * @param array $decoded JSON-decoded error response body.
     * @return string Error message, or '' when none is found.
     */
    protected function extract_api_error(array $decoded): string {
        return (string) ($decoded['error']['message'] ?? '');
    }

    /**
     * Map an HTTP status code and optional provider message to an admin-friendly
     * one-line failure description.
     *
     * @param int    $code        HTTP status code.
     * @param string $api_message Provider-extracted message (may be '').
     */
    private function http_error_message(int $code, string $api_message): string {
        switch ($code) {
            case 401:
                return __('Invalid API key — double-check the key entered in the AI Provider settings.', 'lingua-forge');
            case 402:
                return __('Payment required — your account has no remaining credits.', 'lingua-forge');
            case 403:
                return __('Access forbidden — your account may be suspended or the key lacks the required permissions.', 'lingua-forge');
            case 429:
                // Prefer the provider-specific message: OpenAI overrides this to an
                // "insufficient_quota" label that distinguishes quota exhaustion from
                // ordinary rate limiting.
                return $api_message !== ''
                    ? $api_message
                    : __('Rate limited — too many requests sent to the provider; wait a moment and try again.', 'lingua-forge');
            case 500:
            case 502:
            case 503:
            case 504:
                return __('Provider service temporarily unavailable — try again in a moment.', 'lingua-forge');
            default:
                return $api_message !== ''
                    ? sprintf(
                        /* translators: 1: HTTP status code, 2: error message from the provider API */
                        __('Provider error (HTTP %1$d): %2$s', 'lingua-forge'),
                        $code,
                        $api_message
                    )
                    : sprintf(
                        /* translators: %d: HTTP status code */
                        __('Unexpected provider error (HTTP %d).', 'lingua-forge'),
                        $code
                    );
        }
    }

    final public function chat(array $messages): ?string {

        $this->last_error = '';

        $api_key = KeyStore::get($this->key_slug());

        if (!$api_key) {
            $this->last_error = __('No API key configured — enter your key in the AI Provider settings.', 'lingua-forge');
            $this->log_error('no API key found — check Settings → Lingua Forge or set the ' . strtoupper($this->key_slug()) . '_API_KEY environment variable');
            return null;
        }

        [$url, $headers, $body] = $this->build_request($messages, $api_key);

        $response = $this->post_with_retry($url, $headers, $body);

        if (is_wp_error($response)) {
            // Final-attempt failure already logged inside post_with_retry().
            $this->last_error = sprintf(
                /* translators: %s: technical error detail from the HTTP layer */
                __('Network error — could not reach the provider: %s', 'lingua-forge'),
                $response->get_error_message()
            );
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if ($http_code < 200 || $http_code >= 300) {
            $raw_body      = wp_remote_retrieve_body($response);
            $decoded_error = json_decode($raw_body, true);
            $api_message   = is_array($decoded_error) ? $this->extract_api_error($decoded_error) : '';
            $this->last_error = $this->http_error_message($http_code, $api_message);
            $this->log_error(sprintf(
                'unexpected HTTP %d: %s',
                $http_code,
                $raw_body
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
            $this->log_error('provider returned a successful response with empty text content — check model configuration in Settings → Lingua Forge');
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

    /** Human-readable label used in Log::debug() messages (e.g. 'Anthropic'). */
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
     *       'timeout'        => 300,          // wp_remote_post timeout in seconds
     *   ], $provider_label);
     *
     * Raise 'timeout' for very long translations or content generation on large
     * posts — the default 300 s covers most cases, but extremely large posts
     * requesting 30 000+ output tokens can take longer.
     */
    private function post_with_retry(string $url, array $headers, array $body): array|\WP_Error {

        $policy = apply_filters(
            'linguaforge_ai_retry_policy',
            [
                'attempts'       => 2,
                'delay_ms'       => 1500,
                'jitter_ms'      => 500,
                'retry_statuses' => [429, 500, 502, 503, 504],
                'timeout'        => 300,
            ],
            $this->provider_label()
        );

        $attempts        = max(1,   (int)   ($policy['attempts']       ?? 2));
        $delay_ms        = max(0,   (int)   ($policy['delay_ms']       ?? 1500));
        $jitter_ms       = max(0,   (int)   ($policy['jitter_ms']      ?? 500));
        $retry_statuses  = (array)          ($policy['retry_statuses'] ?? [429, 500, 502, 503, 504]);
        $timeout         = max(30,  (int)   ($policy['timeout']        ?? 300));

        $args = [
            'headers' => $headers,
            'body'    => wp_json_encode($body),
            'timeout' => $timeout,
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

        $line = sprintf(
            'Lingua Forge AI [%s] retry %d/%d after %s',
            $this->provider_label(),
            $attempt_number,
            $total_attempts - 1,
            $reason
        );

        Log::debug( $line );
        $this->maybe_wpcli_log( $line );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGGING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write a diagnostic line in the format the plugin FAQ already documents.
     * Subclasses can call this if they need extra context-specific logging.
     *
     * When running under WP-CLI, the same line is also emitted to the terminal
     * so errors are visible without tailing a log file.
     */
    protected function log_error(string $message): void {

        $line = sprintf(
            'Lingua Forge AI [%s] %s',
            $this->provider_label(),
            $message
        );

        Log::debug( $line );
        $this->maybe_wpcli_log( $line );
    }

    /**
     * Emit $line to the WP-CLI terminal when running as a CLI command.
     *
     * No-op on web requests. Checked with the WP_CLI constant rather than a
     * class_exists guard so the call is safe even if WP-CLI is not installed.
     */
    private function maybe_wpcli_log( string $line ): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- WP-CLI context only; output is intentional.
            \WP_CLI::log( '[LF debug] ' . $line );
        }
    }
}
