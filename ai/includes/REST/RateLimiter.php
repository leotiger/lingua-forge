<?php

namespace LinguaForge\AI\REST;

defined('ABSPATH') || exit;

/**
 * Per-user rate limit + site-wide daily quota for paid AI calls.
 *
 * Extracted from {@see FeatureController} so the FSE-translate AJAX
 * handlers in `Admin\Settings\Tabs\RouterTab` (Translate template,
 * Translate navigation, etc.) can share the same budget-protection
 * gates that already guard the REST chunk / revise / create endpoints.
 *
 * Two filters parametrise the limits:
 *
 *   apply_filters('linguaforge_ai_rate_limit', [
 *       'window_seconds' => 60,    // sliding window length
 *       'max_requests'   => 30,    // requests allowed in the window
 *   ], $endpoint);
 *
 *   apply_filters('linguaforge_ai_daily_quota',
 *       (int) get_option('linguaforge_ai_daily_quota', 0),
 *       $endpoint);
 *
 * Both methods return null when the call is allowed (and bump their
 * own counter), or a WP_Error with HTTP 429 when the limit is hit.
 *
 * REST callers (FeatureController) return the WP_Error directly to the
 * REST stack. AJAX callers (RouterTab FSE handlers) call
 * {@see self::gate_ajax_or_die()} which translates either error into
 * the standard wp_send_json_error envelope and exits.
 */
class RateLimiter {

    /**
     * Enforce a per-user-per-endpoint sliding-window rate limit.
     *
     * Returns null when the request is allowed (and records the call),
     * or a WP_Error with HTTP 429 when the user has exceeded the
     * threshold. The error payload includes a `retry_after` integer
     * (seconds until the next quota slot frees up — i.e. when the
     * oldest in-window event ages out).
     *
     * Implementation note: WordPress transients have no atomic
     * increment, so we store an array of timestamps. A race between
     * two concurrent requests may let one over-the-limit call slip
     * through; that's acceptable for a budget-protection limiter
     * (vs. a security limiter).
     *
     * @param string $endpoint Short identifier used in the transient
     *                         key so per-endpoint quotas are separate.
     */
    public static function enforce_rate_limit(string $endpoint): ?\WP_Error {

        $policy = apply_filters(
            'linguaforge_ai_rate_limit',
            [
                'window_seconds' => 60,
                'max_requests'   => 30,
            ],
            $endpoint
        );

        $window = max(1, (int) ($policy['window_seconds'] ?? 60));
        $limit  = max(1, (int) ($policy['max_requests']   ?? 30));

        $user_id = get_current_user_id();

        // Anonymous callers shouldn't reach this — the permission_callback
        // requires edit_posts (REST) or manage_options (AJAX). If a future
        // code path bypasses that, fail closed.
        if ($user_id <= 0) {
            return new \WP_Error(
                'rate_limited',
                'Rate limit unavailable for anonymous requests.',
                ['status' => 429, 'retry_after' => $window]
            );
        }

        $key = "linguaforge_rate_user_{$user_id}_{$endpoint}";

        $now    = time();
        $cutoff = $now - $window;

        $events = get_transient($key);
        $events = is_array($events)
            ? array_values(array_filter(
                $events,
                static fn($t) => is_int($t) && $t >= $cutoff
            ))
            : [];

        if (count($events) >= $limit) {

            $oldest      = min($events);
            $retry_after = max(1, ($oldest + $window) - $now);

            return new \WP_Error(
                'rate_limited',
                sprintf(
                    /* translators: %d is seconds until the next AI request is allowed. */
                    __('Too many AI requests. Please retry in %d seconds.', 'lingua-forge'),
                    $retry_after
                ),
                [
                    'status'      => 429,
                    'retry_after' => $retry_after,
                ]
            );
        }

        $events[] = $now;

        // TTL slightly larger than the window so the array survives the
        // full sliding span. Anything older than $cutoff is pruned on
        // next read.
        set_transient($key, $events, $window + 5);

        return null;
    }

    /**
     * Enforce a site-wide daily ceiling on AI calls.
     *
     * Counter lives in a transient keyed by UTC date (YYYYMMDD) with a
     * TTL that lasts until UTC midnight. WordPress will auto-expire the
     * entry the day after, so there's no cleanup to do.
     *
     * Returns null when the call is allowed (and increments the
     * counter), or a WP_Error with HTTP 429 when the daily ceiling is
     * reached. The `retry_after` field on the error payload is the
     * seconds remaining until UTC midnight (when the counter resets).
     *
     * Quota source (lowest priority first):
     *   wp_options['linguaforge_ai_daily_quota']
     *   apply_filters('linguaforge_ai_daily_quota', $option, $endpoint)
     *
     * A value of 0 means "unlimited" and short-circuits the helper.
     */
    public static function enforce_daily_quota(string $endpoint): ?\WP_Error {

        $quota = (int) apply_filters(
            'linguaforge_ai_daily_quota',
            (int) get_option('linguaforge_ai_daily_quota', 0),
            $endpoint
        );

        if ($quota <= 0) {
            return null; // unlimited
        }

        // UTC-keyed counter — same key across all users so the limit
        // is site-wide.
        $today = gmdate('Ymd');
        $key   = "linguaforge_quota_daily_used_{$today}";

        $used = (int) get_transient($key);

        if ($used >= $quota) {

            // Seconds until UTC midnight tomorrow.
            $retry_after = max(1, (strtotime('tomorrow UTC') ?: (time() + 86400)) - time());

            return new \WP_Error(
                'daily_quota_exceeded',
                sprintf(
                    /* translators: 1: quota number, 2: reset time. */
                    __('Daily AI quota reached (%1$d requests). Resets at %2$s UTC.', 'lingua-forge'),
                    $quota,
                    gmdate('H:i', strtotime('tomorrow UTC') ?: time() + 86400)
                ),
                [
                    'status'      => 429,
                    'retry_after' => $retry_after,
                    'quota'       => $quota,
                ]
            );
        }

        // TTL to UTC midnight + small grace, so the transient evaporates
        // with the day.
        $ttl = max(60, (strtotime('tomorrow UTC') ?: (time() + 86400)) - time() + 30);
        set_transient($key, $used + 1, $ttl);

        return null;
    }

    /**
     * AJAX-shaped wrapper that runs both gates and exits with
     * wp_send_json_error on the first failure.
     *
     * The FSE-translate AJAX handlers in RouterTab call this after
     * input validation (so structurally-bad requests don't burn quota)
     * but before the paid AI provider call. On success the method
     * returns; on either limit hit it sends a 429 JSON envelope and
     * terminates the request — same dispatch shape as wp_send_json_error
     * elsewhere in those handlers, so the JS callers need no changes.
     *
     * The error payload echoes the WP_Error message and includes the
     * structured `retry_after` / `quota` metadata under `data` so a
     * future JS client can render a richer "try again in N seconds"
     * message without parsing the human-readable string.
     */
    public static function gate_ajax_or_die(string $endpoint): void {

        $rate = self::enforce_rate_limit($endpoint);
        if ($rate instanceof \WP_Error) {
            self::send_ajax_error($rate);
        }

        $quota = self::enforce_daily_quota($endpoint);
        if ($quota instanceof \WP_Error) {
            self::send_ajax_error($quota);
        }
    }

    /**
     * Translate a WP_Error from one of the gates into a wp_send_json_error
     * payload + HTTP status code, then exit. Never returns.
     */
    private static function send_ajax_error(\WP_Error $err): void {

        $data = $err->get_error_data();
        $data = is_array($data) ? $data : [];

        $status = isset($data['status']) ? (int) $data['status'] : 429;

        wp_send_json_error(
            [
                'code'    => $err->get_error_code(),
                'message' => $err->get_error_message(),
                'meta'    => $data,
            ],
            $status
        );
    }
}
