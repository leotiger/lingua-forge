# G-07 — Third-party integration API

Lingua Forge exposes a set of WordPress actions, filters, REST endpoints, and a public PHP helper that let external plugins, themes, and build tools interact with the translation pipeline without modifying plugin files.

---

## Chapters

1. [Actions](#1-actions)
2. [Filters](#2-filters)
3. [REST endpoints](#3-rest-endpoints)
4. [Public PHP helper](#4-public-php-helper)
5. [Worked examples](#5-worked-examples)

---

## 1. Actions

### `linguaforge_loaded`

Fired once, on `init` priority 20, after the plugin has bootstrapped all components and registered its post-meta keys. This is the correct hook for third-party code that needs to register its own meta, CPT translations, or taxonomy delegation before the first request is processed.

```php
add_action( 'linguaforge_loaded', function ( array $context ) {
    // $context['version']      — plugin version string
    // $context['primary_lang'] — configured source language code
    // $context['languages']    — array of active language codes
} );
```

---

### `linguaforge_translation_complete`

Fired after a translated post has been created or updated by any translation path (WP-CLI, Quick Translate, REST trigger). Use it to trigger downstream jobs — pushing to a CDN, notifying an editorial queue, clearing an external cache.

```php
add_action(
    'linguaforge_translation_complete',
    function ( int $translated_post_id, int $source_post_id, string $target_lang ) {
        // $translated_post_id — post ID of the newly created or updated translation
        // $source_post_id     — post ID of the original source post
        // $target_lang        — BCP-47 language code, e.g. 'de', 'es', 'ca'
    },
    10,
    3
);
```

---

### `linguaforge_trid_changed`

Fired whenever a post's translation group (TRID) is assigned or reassigned. Useful for keeping an external index of translation relationships in sync.

```php
add_action(
    'linguaforge_trid_changed',
    function ( int $post_id, string $new_trid, string $old_trid ) {
        // $old_trid is an empty string when the group is first assigned
    },
    10,
    3
);
```

---

### `linguaforge_wc_integration_active`

Fired once, after the WooCommerce integration layer has finished loading, on `woocommerce_loaded`. Only fires when WooCommerce is active and the Lingua Forge WC bridge has initialised successfully.

```php
add_action( 'linguaforge_wc_integration_active', function () {
    // Safe to register WC-aware hooks here
} );
```

---

## 2. Filters

### `lf_primary_language`

Override the primary (source) language code stored in the plugin settings. Useful for multi-tenant setups where the source language is determined at runtime.

```php
add_filter( 'lf_primary_language', function ( string $lang ): string {
    return 'ca'; // force Catalan as the primary language
} );
```

---

### `lf_languages_list`

Override or extend the active-languages list returned to the routing and translation subsystems. Each entry must be a valid BCP-47 code that is also registered as an active language in the plugin settings, or routing will not function correctly.

```php
add_filter( 'lf_languages_list', function ( array $langs ): array {
    // $langs — indexed array of language code strings, e.g. ['ca', 'es', 'de']
    return array_diff( $langs, ['de'] ); // temporarily exclude German
} );
```

---

### `linguaforge_translation_languages`

Filter the list of target languages for a specific translation job before the AI call is dispatched. Receives the full language list and the source post ID, allowing per-post or per-type overrides.

```php
add_filter(
    'linguaforge_translation_languages',
    function ( array $target_langs, int $post_id ): array {
        if ( get_post_type( $post_id ) === 'press_release' ) {
            return ['en', 'de']; // press releases only need English and German
        }
        return $target_langs;
    },
    10,
    2
);
```

---

### `linguaforge_translation_content`

Modify the content payload handed to the AI provider just before the API call. The payload is an associative array with at minimum a `content` key. You can inject glossary context, reformat HTML, or add custom instructions here.

```php
add_filter(
    'linguaforge_translation_content',
    function ( array $payload, int $post_id, string $target_lang ): array {
        // Append a note for the AI about brand names in this post
        $payload['system_note'] = 'Keep the brand name "Acme" untranslated.';
        return $payload;
    },
    10,
    3
);
```

---

### `linguaforge_translation_worker_config`

Override translation worker configuration — model, temperature, max tokens, and provider — for a specific post and target language. Values set here take effect for this job only and do not alter the saved plugin settings.

```php
add_filter(
    'linguaforge_translation_worker_config',
    function ( array $config, int $post_id, string $target_lang ): array {
        // $config keys: 'model', 'temperature', 'max_tokens', 'provider'
        if ( get_post_type( $post_id ) === 'legal_doc' ) {
            $config['temperature'] = 0.1;
        }
        return $config;
    },
    10,
    3
);
```

---

### `linguaforge_ai_retry_policy`

Override the retry behaviour for AI API calls. Useful for raising the retry count on high-value jobs, or reducing it to fail fast in a CI context.

```php
add_filter( 'linguaforge_ai_retry_policy', function ( array $policy ): array {
    // Default: [ 'max_retries' => 3, 'base_delay_ms' => 1000, 'backoff' => 2.0 ]
    $policy['max_retries'] = 5;
    return $policy;
} );
```

---

### `linguaforge_switcher_output`

Filter the fully-rendered HTML of the language switcher before it is returned to the caller. Useful for injecting wrapper markup or applying custom attributes.

```php
add_filter(
    'linguaforge_switcher_output',
    function ( string $html, array $languages, array $atts ): string {
        return '<div class="my-wrapper">' . $html . '</div>';
    },
    10,
    3
);
```

---

### `linguaforge_cpt_create_allowed`

Control whether a translated post of a given post type should be created. Return `false` to block Lingua Forge from creating a translation for specific types. This runs inside `Sync::handle_save_post()` and has no effect on post types that are already excluded by the `$pto->public === false` gate.

```php
add_filter(
    'linguaforge_cpt_create_allowed',
    function ( bool $allowed, string $post_type, int $post_id ): bool {
        if ( $post_type === 'my_internal_cpt' ) {
            return false;
        }
        return $allowed;
    },
    10,
    3
);
```

---

### `linguaforge_wc_delegate_post_types`

Add post types to the WooCommerce delegation list. Post types in this list have their meta and taxonomy queries proxied to the source-language product, matching the behaviour of WooCommerce's built-in types.

```php
add_filter(
    'linguaforge_wc_delegate_post_types',
    function ( array $types ): array {
        $types[] = 'my_product_bundle';
        return $types;
    }
);
```

---

## 3. REST endpoints

The plugin registers two read-only REST endpoints under the `lingua-forge/v1` namespace. Both require the `read` capability — they are accessible to logged-in users and, by default, to unauthenticated requests (following the same visibility rules as the standard WordPress posts endpoint).

### `GET /wp-json/lingua-forge/v1/languages`

Returns the active languages configured in the plugin.

**Response:**

```json
[
    { "code": "ca", "name": "Català",  "url_prefix": "ca" },
    { "code": "es", "name": "Español", "url_prefix": "es" },
    { "code": "de", "name": "Deutsch", "url_prefix": "de" }
]
```

---

### `GET /wp-json/lingua-forge/v1/post/{id}/translations`

Returns the translation group for a given post ID, indexed by language code.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `id` | integer | Post ID of any member of the translation group (source or translated) |

**Response:**

```json
{
    "trid": "a1b2c3d4-...",
    "source_lang": "ca",
    "translations": {
        "ca": { "post_id": 42,  "status": "publish", "url": "https://example.com/ca/..." },
        "es": { "post_id": 87,  "status": "publish", "url": "https://es.example.com/..."  },
        "de": { "post_id": 103, "status": "draft",   "url": "https://de.example.com/..."  }
    }
}
```

Returns `404` if the post ID does not exist, and `400` if it belongs to a non-public post type.

---

## 4. Public PHP helper

### `linguaforge_trigger_translation()`

Programmatically trigger a translation job from PHP. This is the same pipeline used by Quick Translate and WP-CLI — it runs the Translation Memory check, makes the AI call if needed, creates or updates the translated post, and writes the AI response to the cache.

```php
$result = linguaforge_trigger_translation(
    int    $source_post_id,
    string $target_lang,
    array  $params = []
);
```

**`$params` keys** (all optional):

| Key | Type | Default | Description |
|---|---|---|---|
| `force` | bool | `false` | Bypass the AI cache; always makes a fresh API call |
| `draft` | bool | `false` | Create the translated post as `draft` regardless of source status |
| `temperature` | float | plugin setting | AI temperature override (0.0–1.0) |
| `max_tokens` | int | plugin setting | Max output tokens override |
| `model` | string | plugin setting | AI model override, e.g. `claude-opus-4-6` |
| `with_meta_description` | bool | `false` | Also generate and save a meta description for the translated post |

**Return value:**

- `int` — post ID of the created or updated translated post on success.
- `WP_Error` — on failure. Check `$result->get_error_code()` for the reason.

**Example:**

```php
$post_id = linguaforge_trigger_translation( 123, 'de', [
    'draft'       => true,
    'temperature' => 0.2,
] );

if ( is_wp_error( $post_id ) ) {
    error_log( 'Translation failed: ' . $post_id->get_error_message() );
} else {
    // $post_id is the ID of the German post
}
```

The function is available after `linguaforge_loaded` fires. Calling it earlier will return a `WP_Error` with code `linguaforge_not_ready`.

---

## 5. Worked examples

### Trigger translations on post publish

Automatically translate every newly published post into all active languages, holding the translations in draft for editorial review:

```php
add_action( 'transition_post_status', function ( $new, $old, $post ) {
    if ( $new !== 'publish' || $old === 'publish' ) {
        return;
    }
    if ( $post->post_type !== 'post' ) {
        return;
    }

    $languages = apply_filters( 'lf_languages_list', [] );
    $primary   = apply_filters( 'lf_primary_language', '' );

    foreach ( $languages as $lang ) {
        if ( $lang === $primary ) {
            continue;
        }
        linguaforge_trigger_translation( $post->ID, $lang, [ 'draft' => true ] );
    }
}, 10, 3 );
```

---

### Notify an external service after each translation

```php
add_action(
    'linguaforge_translation_complete',
    function ( int $translated_id, int $source_id, string $lang ) {
        wp_remote_post( 'https://my-cms-hub.example.com/hook/translation', [
            'body' => wp_json_encode( [
                'source_id'      => $source_id,
                'translated_id'  => $translated_id,
                'target_lang'    => $lang,
                'translated_url' => get_permalink( $translated_id ),
            ] ),
            'headers' => [ 'Content-Type' => 'application/json' ],
        ] );
    },
    10,
    3
);
```

---

### Machine-readable translation inventory via REST

```bash
# Fetch all active languages
curl -s https://example.com/wp-json/lingua-forge/v1/languages | jq '.[].code'

# Check translation status for post 42
curl -s https://example.com/wp-json/lingua-forge/v1/post/42/translations \
    | jq '.translations | to_entries[] | select(.value.status != "publish") | .key'
# → "de"  (German translation is still a draft)
```

---

*Back to [Documentation index](index.md)*
