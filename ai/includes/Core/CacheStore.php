<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Post-meta based cache for AI-generated feature results.
 *
 * Each cache entry stores the result payload alongside a SHA-256 hash of
 * the inputs used to produce it. On retrieval the hash is recomputed and
 * compared — a mismatch (i.e. the post content or locale changed) causes
 * a cache miss and a fresh API call is made.
 *
 * This means the cache is invalidated precisely when the content changes,
 * with no TTL drift and no manual flush required under normal editing.
 *
 * Meta key format:  _linguaforge_cache_{feature_key}
 * Example:          _linguaforge_cache_meta-description
 *                   _linguaforge_cache_translation_fr
 *                   _linguaforge_cache_excerpt
 *
 * The stored value is a PHP array serialised by WordPress:
 *   [
 *     'hash'      => string,   // SHA-256 of inputs
 *     'payload'   => array,    // the feature result (without success:true)
 *     'cached_at' => int,      // Unix timestamp
 *   ]
 */
class CacheStore {

    private const META_PREFIX = '_linguaforge_cache_';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the cached payload if the stored hash matches, or null on miss.
     *
     * @return array<string, mixed>|null
     */
    public static function get(
        int    $post_id,
        string $feature,
        string $hash
    ): ?array {

        $stored = get_post_meta(
            $post_id,
            self::meta_key($feature),
            true
        );

        if (!is_array($stored)) {
            return null;
        }

        if (($stored['hash'] ?? '') !== $hash) {
            return null; // inputs changed — stale cache
        }

        return $stored['payload'] ?? null;
    }

    /**
     * Persist a feature result payload alongside the input hash.
     *
     * @param array<string, mixed> $payload
     */
    public static function set(
        int    $post_id,
        string $feature,
        string $hash,
        array  $payload
    ): void {

        update_post_meta(
            $post_id,
            self::meta_key($feature),
            [
                'hash'      => $hash,
                'payload'   => $payload,
                'cached_at' => time(),
            ]
        );
    }

    /**
     * Remove the cache entry for a specific feature on a post.
     */
    public static function delete(
        int    $post_id,
        string $feature
    ): void {

        delete_post_meta($post_id, self::meta_key($feature));
    }

    /**
     * Compute a deterministic SHA-256 hash from an ordered list of inputs.
     * All values are cast to string before hashing.
     *
     * @param list<string> $inputs
     */
    public static function hash(array $inputs): string {

        return hash(
            'sha256',
            implode("\x00", array_map('strval', $inputs))
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function meta_key(string $feature): string {

        return self::META_PREFIX . sanitize_key($feature);
    }
}
