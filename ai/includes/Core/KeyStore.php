<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Encrypted storage for AI provider API keys.
 *
 * Envelope versions (cohabitating, see read-path dispatcher):
 *
 *   v2 — AES-256-GCM, authenticated, with provider slug as AAD.
 *        Storage: "v2|" + base64( IV(12) || ciphertext || tag(16) )
 *        Default for every write since 2026-05-20 (§3.5). The AAD
 *        binds the ciphertext to the wp_options row that holds it —
 *        an attacker with DB write access can no longer swap an
 *        Anthropic blob into the OpenAI slot (the GCM tag check fails).
 *
 *   v1 — AES-256-CBC, no MAC. Storage: base64( IV(16) || ciphertext ).
 *        Legacy. Kept readable indefinitely so the plugin survives
 *        DB restores from pre-upgrade backups. Any successful v1 read
 *        is opportunistically re-encrypted as v2 in-place (lazy
 *        migration). New writes never produce v1 again.
 *
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

    /** v1 envelope cipher — kept readable for legacy values. */
    private const CIPHER_V1 = 'aes-256-cbc';

    /** v2 envelope cipher — used for every write. */
    private const CIPHER_V2 = 'aes-256-gcm';

    /** Magic prefix that identifies a v2 envelope in the stored option value. */
    private const V2_PREFIX = 'v2|';

    /** v2 IV length, bytes. GCM standard is 12. */
    private const V2_IV_LEN = 12;

    /** v2 tag length, bytes. */
    private const V2_TAG_LEN = 16;

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
            $decrypted = self::decrypt((string) $raw, $provider);
            if ($decrypted !== null && $decrypted !== '') {

                // Lazy migration: if this was a successful legacy (v1) read,
                // re-encrypt with v2 so future reads use the authenticated
                // envelope. Best-effort — failures here must not block the
                // caller from receiving the decrypted key.
                if (!self::is_v2((string) $raw)) {
                    $re_encrypted = self::encrypt_v2($decrypted, $provider);
                    if ($re_encrypted !== '') {
                        update_option(
                            self::OPTION_PREFIX . $provider,
                            $re_encrypted,
                            false
                        );
                    }
                }

                return $decrypted;
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic log for a silent decryption failure, most likely caused by wp_salt('auth') changing after the key was stored. The fix is to re-save the API key in Settings → Lingua Forge.
            error_log(sprintf(
                'Lingua Forge AI [KeyStore] decryption failed for provider "%s" — the stored key could not be decrypted (wp_salt may have changed). Re-save the API key in Settings → Lingua Forge.',
                $provider
            ));
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
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- API keys come from the server environment, not user input. sanitize_text_field() would strip valid key characters (+, /, =) and wp_unslash() would corrupt keys containing backslashes. No request data flows here.
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

        $encrypted = self::encrypt_v2($key, $provider);

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

        if ($raw !== '' && self::decrypt((string) $raw, $provider) !== null) {
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

    /**
     * Quick check whether OpenSSL on this host supports AES-256-GCM.
     * Result is cached for the request — openssl_get_cipher_methods()
     * scans a long list every call.
     */
    private static function gcm_supported(): bool {

        static $supported = null;

        if ($supported === null) {
            $supported = in_array(
                self::CIPHER_V2,
                openssl_get_cipher_methods(),
                true
            );
        }

        return $supported;
    }

    /**
     * True when the given stored value is a v2 envelope (vs. legacy v1).
     */
    private static function is_v2(string $stored): bool {
        return str_starts_with($stored, self::V2_PREFIX);
    }

    /**
     * Build a v2 envelope: "v2|" + base64( IV(12) || ciphertext || tag(16) ).
     * Provider slug is used as AAD so the tag check binds the ciphertext to
     * the wp_options row that holds it.
     *
     * Returns an empty string on any failure (cipher unsupported, RNG
     * exhausted, openssl returns false). Caller treats '' as fatal.
     */
    private static function encrypt_v2(string $plaintext, string $provider): string {

        if (!self::gcm_supported()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic log for an unrecoverable cryptographic feature gap on the host.
            error_log('Lingua Forge AI [KeyStore] AES-256-GCM is not available on this PHP/OpenSSL build; cannot store API keys.');
            return '';
        }

        $key = self::secret();

        try {
            $iv = random_bytes(self::V2_IV_LEN);
        } catch (\Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional diagnostic log for a cryptographic failure that would silently prevent API key storage.
            error_log('Lingua Forge AI [KeyStore] could not generate IV: ' . $e->getMessage());
            return '';
        }

        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER_V2,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $provider, // AAD
            self::V2_TAG_LEN
        );

        if ($cipher === false) {
            return '';
        }

        return self::V2_PREFIX . base64_encode($iv . $cipher . $tag);
    }

    /**
     * Decrypt a stored value, handling both envelope versions.
     *
     * The dispatcher checks for the "v2|" prefix. Anything else is treated
     * as a legacy v1 (CBC) blob. Returns null on any failure — callers
     * treat null as "could not recover plaintext".
     *
     * The provider slug is required for v2 (AAD); ignored for v1.
     */
    private static function decrypt(string $stored, string $provider): ?string {

        if (self::is_v2($stored)) {
            return self::decrypt_v2($stored, $provider);
        }

        return self::decrypt_v1($stored);
    }

    /**
     * v2: AES-256-GCM with provider slug as AAD.
     */
    private static function decrypt_v2(string $stored, string $provider): ?string {

        if (!self::gcm_supported()) {
            return null;
        }

        $b64 = substr($stored, strlen(self::V2_PREFIX));
        $raw = base64_decode($b64, true);

        if ($raw === false) {
            return null;
        }

        $iv_len  = self::V2_IV_LEN;
        $tag_len = self::V2_TAG_LEN;

        if (strlen($raw) <= $iv_len + $tag_len) {
            return null;
        }

        $iv         = substr($raw, 0, $iv_len);
        $tag        = substr($raw, -$tag_len);
        $ciphertext = substr($raw, $iv_len, -$tag_len);

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER_V2,
            self::secret(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $provider // AAD
        );

        return $plain !== false ? $plain : null;
    }

    /**
     * v1: legacy AES-256-CBC envelope. Kept for backward read-compat.
     */
    private static function decrypt_v1(string $stored): ?string {

        $raw = base64_decode($stored, true);

        if ($raw === false) {
            return null;
        }

        $iv_len = openssl_cipher_iv_length(self::CIPHER_V1);

        if ($iv_len === false || strlen($raw) <= $iv_len) {
            return null;
        }

        $iv     = substr($raw, 0, $iv_len);
        $cipher = substr($raw, $iv_len);
        $plain  = openssl_decrypt(
            $cipher,
            self::CIPHER_V1,
            self::secret(),
            OPENSSL_RAW_DATA,
            $iv
        );

        return $plain !== false ? $plain : null;
    }
}
