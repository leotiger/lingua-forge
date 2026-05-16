<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Encrypted storage for AI provider API keys.
 *
 * Keys are encrypted with AES-256-CBC before being written to wp_options.
 * The encryption secret is derived from WordPress's own auth salts
 * (wp-config.php), so the plaintext key is never stored in the database.
 *
 * Fallback resolution order for get():
 *   1. Encrypted value in wp_options  (set via the Settings page)
 *   2. Server environment variable    (e.g. ANTHROPIC_API_KEY)
 *   3. PHP constant                   (e.g. define('ANTHROPIC_API_KEY', '…'))
 *
 * This means existing setups using env vars or wp-config.php constants
 * continue to work without any changes.
 *
 * Optionally define LINGUAFORGE_SECRET in wp-config.php to use your own
 * encryption secret instead of the derived wp_salt value.
 */
class KeyStore {

    private const OPTION_PREFIX = 'linguaforge_key_';

    private const CIPHER = 'aes-256-cbc';

    /** @var array<string, string> Env-var / constant name per provider slug. */
    private const ENV_MAP = [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'openai'    => 'OPENAI_API_KEY',
        'gemini'    => 'GEMINI_API_KEY',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Retrieve the API key for a provider.
     * Returns null if no key is found via any source.
     */
    public static function get(string $provider): ?string {

        // 1. Database (encrypted)
        $raw = get_option(self::OPTION_PREFIX . $provider, '');

        if ($raw !== '') {
            $decrypted = self::decrypt((string) $raw);
            if ($decrypted !== null && $decrypted !== '') {
                return $decrypted;
            }
        }

        // 2. Environment variable
        $env_name = self::ENV_MAP[$provider] ?? null;

        if ($env_name !== null) {
            $env_val = getenv($env_name);
            if ($env_val !== false && $env_val !== '') {
                return $env_val;
            }

            // Also check the superglobal (some server setups only populate this).
            if (!empty($_ENV[$env_name])) {
                return (string) $_ENV[$env_name];
            }
        }

        // 3. PHP constant
        if ($env_name !== null && defined($env_name)) {
            $const_val = constant($env_name);
            if ($const_val !== '' && $const_val !== null) {
                return (string) $const_val;
            }
        }

        return null;
    }

    /**
     * Encrypt and persist an API key in wp_options.
     * autoload is intentionally set to false.
     */
    public static function set(string $provider, string $key): bool {

        $encrypted = self::encrypt($key);

        if ($encrypted === '') {
            return false;
        }

        return (bool) update_option(
            self::OPTION_PREFIX . $provider,
            $encrypted,
            false
        );
    }

    /**
     * Remove the stored (database) key for a provider.
     * Falls back to env / constant after removal.
     */
    public static function delete(string $provider): bool {

        return delete_option(self::OPTION_PREFIX . $provider);
    }

    /**
     * Return where the active key for a provider comes from.
     *
     * @return 'database'|'environment'|'constant'|null
     */
    public static function source(string $provider): ?string {

        $raw = get_option(self::OPTION_PREFIX . $provider, '');

        if ($raw !== '' && self::decrypt((string) $raw) !== null) {
            return 'database';
        }

        $env_name = self::ENV_MAP[$provider] ?? null;

        if ($env_name !== null) {

            $env_val = getenv($env_name);

            if (($env_val !== false && $env_val !== '') ||
                !empty($_ENV[$env_name])) {
                return 'environment';
            }

            if (defined($env_name) && constant($env_name) !== '') {
                return 'constant';
            }
        }

        return null;
    }

    // ── Encryption helpers ────────────────────────────────────────────────────

    /**
     * 32-byte key derived from wp_salt('auth') or LINGUAFORGE_SECRET.
     * Never stored anywhere — must be recomputed on every request.
     */
    private static function secret(): string {

        $seed = defined('LINGUAFORGE_SECRET')
            ? (string) LINGUAFORGE_SECRET
            : wp_salt('auth');

        return hash('sha256', $seed, true); // raw 32 bytes
    }

    private static function encrypt(string $plaintext): string {

        $key    = self::secret();
        $iv_len = openssl_cipher_iv_length(self::CIPHER);

        try {
            $iv = random_bytes($iv_len);
        } catch (\Exception $e) {
            error_log('LinguaForge AI [KeyStore] could not generate IV: ' . $e->getMessage());
            return '';
        }

        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return base64_encode($iv . $cipher);
    }

    private static function decrypt(string $encoded): ?string {

        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            return null;
        }

        $iv_len = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($raw) <= $iv_len) {
            return null;
        }

        $iv     = substr($raw, 0, $iv_len);
        $cipher = substr($raw, $iv_len);
        $plain  = openssl_decrypt(
            $cipher,
            self::CIPHER,
            self::secret(),
            OPENSSL_RAW_DATA,
            $iv
        );

        return $plain !== false ? $plain : null;
    }
}
