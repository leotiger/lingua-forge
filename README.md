# Lingua Forge

**GitHub:** https://github.com/leotiger/lingua-forge

A WordPress plugin that combines multilingual routing, SEO meta tags, and AI-powered content tools in a single package. Built for FSE / block-theme sites that serve content in multiple languages and need editorial AI assistance without a heavyweight third-party subscription service.

LinguaForge merges three previously separate must-use plugins — **Language Router**, **Meta Description**, and **WPEnhance AI** — into one installable plugin with a shared path and URL foundation.

---

## Features

### Language Router

- Language detection from URL prefix (`/de/`), query param (`?lang=de`), and cookie
- Custom rewrite rules for language-prefixed URLs and category archives
- Post and page translation groups linked via a shared TRID (UUID)
- Outdated translation tracking — warnings when source content is updated after a translation was synced
- Language-specific FSE templates (`page-de`, `single-fr`, `search-en`)
- hreflang tags for singular, archive, and paginated views; compatible with Yoast SEO, Rank Math, AIOSEO, and SEOPress
- Language switcher block (LSFLR Switcher) rendered as dropdown or dropup
- Admin link fixer — scans translated pages for internal links pointing to the wrong language version and repairs them via AJAX
- Plugin translation override — custom `.mo` files placed in `wp-content/uploads/lingua-forge/i18n-overrides/` are loaded automatically, overriding third-party plugin strings for each locale (e.g. swapping "room" → "apartment" in VikBooking). Files survive plugin updates. Manage them from **Settings → LinguaForge AI → Language Overrides** or drop them in directly via FTP/SFTP.
- DB index on `wp_postmeta (meta_key, meta_value)` created on activation for fast `_lang` queries

### Meta Description

Adds a meta description field to every public post type. Outputs `<meta name="description">`, `<meta property="og:description">`, and `<meta name="twitter:description">` in `<head>` on every frontend request.

- Custom field editable in the post editor's Classic meta box, fully compatible with the Block Editor
- Character counter with green/amber/red guidance (120–160 ideal range)
- Fallback chain: custom field → post excerpt → site description
- Excerpt fallback is auto-generated from content if no manual excerpt exists
- Only custom descriptions are output verbatim; fallback descriptions are auto-truncated at 190 characters

> The `meta_description` post-meta key is intentionally neutral — themes and other plugins that already read this key will continue to work without any changes.

### AI Content Tools (LinguaForge AI)

Supports **Anthropic Claude**, **OpenAI**, and **Google Gemini** as interchangeable backends. All results appear in a review panel — nothing is applied automatically.

- **Meta Description Generator** — language-aware, 140–160 character output with SEO quality indicator
- **Excerpt Generator** — concise editorial excerpt up to 240 characters, language-aware
- **Content Translation** — full post and page translation preserving all Gutenberg block markup, block attribute strings (accordion summaries, image alt text, etc.), and footnotes. Chunk mode for translating individual snippets
- **Content Generator** — drafts or rewrites post content from hints, tone, and output-type controls. Outputs native Gutenberg block markup
- **Quick Translate** — available in the admin toolbar and inside the Gutenberg / FSE editor toolbar, for translating any text snippet on the fly without opening a specific post
- SHA-256 hash-based result caching in post meta; per-language translation cache; force-refresh control
- Configurable model endpoints per provider and tier from the Settings page — no code changes needed when a new model version ships

---

## Requirements

- WordPress 6.4 or later (block theme / FSE recommended)
- PHP 8.0 or later
- Permalink structure set to anything other than Plain
- An API key for at least one supported AI provider (Anthropic, OpenAI, or Gemini)

---

## Installation

1. Copy the `lingua-forge/` folder to `wp-content/plugins/`
2. Activate **LinguaForge** from the WordPress admin (Plugins → Installed Plugins)
3. Go to **Settings → Permalinks** and click **Save Changes** — this flushes the rewrite rules for the language URL prefixes
4. Go to **Settings → LinguaForge AI**, select a provider, and enter your API key

```
wp-content/
  plugins/
    lingua-forge/
      lingua-forge.php       ← main plugin file
      language-router/       ← Language Router module
      meta-description/      ← SEO meta description module
      ai/                    ← AI content tools module
```

> If you are migrating from the mu-plugin versions of these tools, deactivate or remove `wp-content/mu-plugins/language-router/`, `wp-content/mu-plugins/meta-description/`, and `wp-content/mu-plugins/wpenhance-ai/` (or `wpai/`) before activating LinguaForge to avoid duplicate hooks.

---

## Configuration

### Language Router

Set the source language via filter (default is `'ca'`):

```php
add_filter( 'lf_primary_language', fn() => 'ca' );
```

Override the active language list:

```php
add_filter( 'lf_languages_list', fn() => ['ca', 'es', 'en', 'de', 'fr'] );
```

#### Filters reference

| Filter | Default | Description |
|---|---|---|
| `lf_primary_language` | `'ca'` | Source / default language code |
| `lf_languages_list` | Auto from WP locales | Full list of active language codes |
| `lf_lang_force_locale` | `['ca' => 'ca']` | Hard locale overrides (e.g. for VikBooking) |
| `lf_lang_fallback_map` | `['en'=>'en_US', …]` | Locale fallbacks when no installed locale matches |
| `lf_lang_default_fallback` | `'en_US'` | Last-resort locale |
| `lf_hreflang_mode` | `'custom'` | Set to `'off'` to disable built-in hreflang output |
| `lf_i18n_overrides_dir` | `uploads/lingua-forge/i18n-overrides/` | Override the storage path for third-party `.mo` override files |
| `linguaforge_translation_languages` | Built-in list | Override the AI translation target language list — see Content Translation section |

#### WordPress language setup

Before the router can serve a language, WordPress must have that language installed. Go to **Settings → General → Site Language**, install each language you need, and verify it appears under **Dashboard → Updates → Translation files**.

> **Newly added language returns 404?** After adding a new language (by dropping a `.mo` file into `language-router/languages/` or installing a WP language pack), go to **Settings → Permalinks** and click **Save Changes**. This flushes the rewrite rule cache to include the new prefix. This is a one-time step each time a language is added or removed.

#### WP site language vs. primary content language

These are two independent settings and it is intentional that they can differ.

**WordPress site language** (`Settings → General → Site Language`) controls the admin interface and the locale WordPress uses internally. This is typically set to a well-supported locale such as `en_US` or `de_DE`.

**Primary content language** (`lf_primary_language` filter, default `'ca'`) is the language your actual content is written in — the language that maps to the root URL path (no prefix) and acts as the source for all translations.

A practical example: the site admin works in `en_US`, but the primary content is Catalan (`ca`). The WordPress site language is left at `en_US` so the admin backend stays in English. The plugin's source language is set to `ca` so Catalan content lives at `/your-page/` and other languages are served at `/es/your-page/`, `/de/your-page/`, etc.

---

### AI Content Tools

#### Choosing a provider

Navigate to **Settings → LinguaForge AI** and select the active provider from the dropdown, or define the constant in `wp-config.php`:

```php
define('LINGUAFORGE_PROVIDER', 'anthropic'); // 'anthropic' | 'openai' | 'gemini'
```

#### API keys

Enter keys directly from **Settings → LinguaForge AI**. Keys are stored encrypted in `wp_options` using AES-256-CBC derived from WordPress's own auth salts — plaintext keys never touch the database.

**Fallback resolution order** (highest to lowest priority):
1. Encrypted value in `wp_options` (set via the Settings page)
2. Server environment variable (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`)
3. PHP constant of the same name defined in `wp-config.php`

#### Models

Navigate to **Settings → LinguaForge AI → Models** to override the model string for any provider and tier:

| Tier | Default (Anthropic) | Used by |
|---|---|---|
| **Light** | `claude-haiku-4-5-20251001` | Meta Description, Excerpt Generator |
| **Quality** | `claude-sonnet-4-6` | Translation, Content Generator |

Leave a field blank to use the built-in default. To update to a new model version when one ships, enter the new identifier in Settings — no code change or deployment needed.

Token budgets and input limits for Translation are configured separately under **Translation Limits** — see the Content Translation section below.

#### Provider timeouts

All provider API calls use a 120-second timeout. If your host caps `max_execution_time` below this (common on managed hosts at 30–60 s), long translations may fail at the PHP level before the HTTP request completes.

---

## Architecture

```
lingua-forge/
  lingua-forge.php                   ← Plugin entry point, constants, activation hooks
  language-router/
    language-router.php              ← Module entry: boots classes, defines LF_LANG, lf_* wrapper functions
    includes/
      class-language-router.php      ← Core singleton: routing, translation, admin, SEO, search
      class-lsflr-switcher.php       ← Language switcher block (injected dependency)
      class-lsflr-link-fixer.php     ← Admin link fixer: scan & repair internal links per language
    assets/
      lsflr.css                      ← Switcher styles
    languages/                       ← LinguaForge own translation files (.pot / .po / .mo)
  meta-description/
    meta-description.php             ← SEO meta box + <head> output (description / og / twitter)
  ai/
    ai.php                           ← Module entry: constants, autoloader, plugin boot
    includes/
      Core/
        Autoloader.php               ← PSR-4 class autoloader (namespace: LinguaForge\AI)
        Plugin.php                   ← Bootstrap: registers hooks, initialises features
        Config.php                   ← Provider + model resolution (options → constant → default)
        KeyStore.php                 ← AES-256-CBC encrypted API key storage
        CacheStore.php               ← SHA-256 hash-based result cache in post meta
        BlockTextExtractor.php       ← Extracts / reinserts translatable block attribute strings
      Contracts/
        AIProviderInterface.php      ← Contract all providers must satisfy
      Features/
        Contracts/
          FeatureInterface.php       ← Contract all features must satisfy
        Registry.php                 ← Registers active features with the REST controller
        MetaDescription.php
        ExcerptGenerator.php
        Translation.php
        ContentGenerator.php
      Providers/
        ProviderFactory.php
        WorkerConfig.php             ← Immutable DTO: model, max_tokens, temperature
        Anthropic.php
        OpenAI.php
        Gemini.php
      Admin/
        MetaBox.php                  ← Post editor metabox: AI panel in main column
        AdminToolbar.php             ← Admin bar Quick Translate node
        SettingsPage.php             ← Settings → LinguaForge AI
      REST/
        FeatureController.php        ← POST /lingua-forge/v1/feature/{key}/{post_id}
                                        POST /lingua-forge/v1/translate-chunk
    assets/
      admin.js / admin.css           ← Meta box UI
      toolbar-translate.js / .css    ← Admin bar Quick Translate popover
      editor-translate.js / .css     ← Editor toolbar Quick Translate
      block-action.js / .css         ← Block-level action buttons
    templates/prompts/               ← AI prompt templates (plain text, editable)
```

### Constants

Defined in `lingua-forge.php` and available to all sub-modules:

| Constant | Value |
|---|---|
| `LINGUAFORGE_FILE` | Absolute path to `lingua-forge.php` |
| `LINGUAFORGE_PATH` | `plugin_dir_path()` of the plugin root (trailing slash) |
| `LINGUAFORGE_URL` | `plugin_dir_url()` of the plugin root (trailing slash) |
| `LINGUAFORGE_VERSION` | Plugin version string |

### Boot order

Language Router boots first because its constructor defines the `LF_LANG` constant at file-load time — before any `init` hooks fire. Meta Description boots second (no dependencies). The AI module boots third and may depend on `LF_LANG` for language-aware features.

---

## Language Router — detailed reference

### How language detection works

Detection runs in priority order:

1. **URL segment** — `/de/` at the start of the path is the strongest signal
2. **`?lang=` query param** — used for search requests (`/?lang=de&s=query`)
3. **Cookie** — `lf_lang` persists the last detected language across requests
4. **Fallback** — the configured source language

`detect_lang()` uses URL + cookie. `detect_lang_safe()` additionally checks `$_GET['lang']` (safe to call before WP is fully loaded). The result is stored in the `LF_LANG` constant.

### Translation model

Every translatable post carries four post-meta fields, all registered with `show_in_rest: true`:

| Meta key | Type | Description |
|---|---|---|
| `_lang` | `string` | Two-letter language code |
| `_trid` | `string` | Shared translation group ID (UUID) |
| `_source_updated_at` | `number` | Unix timestamp of the last source-language save |
| `_translation_source_updated_at` | `number` | Source timestamp at the time the translation was last synced |

Translation groups are resolved with a graph-expansion algorithm: linking posts A↔B when B↔C already exists results in all three sharing the same TRID automatically.

Translation lookups are cached in the WordPress object cache with a 1-hour TTL and invalidated on save.

### Public API

#### `Language_Router`

```php
$router = Language_Router::get_instance();

// Config
$router->source_language(): string
$router->languages(): array
$router->is_valid_lang( $lang ): bool
$router->locale_from_lang( $lang ): string
$router->language_label( $lang ): string

// Detection
$router->detect_lang(): string
$router->detect_lang_safe(): string

// TRID / meta
$router->get_trid( $post_id ): string
$router->set_trid( $post_id, $trid ): void
$router->get_lang( $post_id ): string
$router->set_lang( $post_id, $lang ): void
$router->get_translations( $post_id ): array   // ['de' => 42, 'fr' => 55, …]
$router->clear_translation_cache( $post_id ): void

// Outdated system
$router->mark_source_updated( $post_id ): void
$router->mark_translation_synced( $post_id ): void
$router->is_outdated( $post_id ): bool
$router->get_missing_languages( $post_id ): array

// Query helpers
$router->query( $args ): WP_Query            // auto-filters by LF_LANG
$router->query_fallback( $args ): WP_Query   // LF_LANG OR source language
$router->get_posts( $args, $fallback ): array

// Utilities
$router->safe_query_args( $url ): string
$router->is_system_request(): bool
$router->set_lang_cookie( $lang ): void
$router->hreflang_mode(): string
$router->build_search_content( $post_id ): void
$router->ensure_lang_index(): bool
$router->debug( $message, $context ): void
```

#### Theme wrapper functions

All procedural wrappers delegate to `Language_Router::get_instance()`. Use these in theme `functions.php` or template files to avoid depending on the singleton directly:

```php
lf_source_language()                          lf_get_lang( $post_id )
lf_languages()                                lf_set_lang( $post_id, $v )
lf_is_valid_lang( $lang )                     lf_get_translations( $post_id )
lf_locale_from_lang( $lang )                  lf_clear_translation_cache( $post_id )
lf_language_label( $lang )                    lf_mark_source_updated( $post_id )
lf_detect_lang()                              lf_mark_translation_synced( $post_id )
lf_detect_lang_safe()                         lf_is_outdated( $post_id )
lf_get_trid( $post_id )                       lf_get_missing_languages( $post_id )
lf_set_trid( $post_id, $v )                   lf_query( $args )
lf_query_fallback( $args )                    lf_get_posts( $args, $fallback )
lf_safe_query_args( $url )                    lf_is_system_request()
lf_set_lang_cookie( $lang )                   lf_hreflang_mode()
lf_build_search_content( $post_id )           lf_ensure_lang_index()
lf_debug( $message, $context )                lf_lang_permalink( $url, $post )
lf_lsflr_render_switcher( $atts )             lf_lsflr_get_languages()
lf_lsflr_translate_current_url( $target_lang, $post_id )
```

### Language Switcher (LSFLR)

**From PHP / shortcode:**
```php
echo lf_lsflr_render_switcher([
    'direction'   => 'down',       // 'down' | 'up'
    'show'        => 'label',      // 'label' | 'custom' | 'icon' | 'icon-label'
    'customLabel' => 'Language',
    'iconHtml'    => '<svg …/>',
]);
```

**Gutenberg block:** search for **LSFLR Switcher** in the block inserter (category: Widgets). All options are in the Inspector sidebar.

### FSE templates per language

The router can load a language-specific FSE template instead of the default one:

| Content type | Slug pattern | Example |
|---|---|---|
| Page | `page-{lang}` | `page-de`, `page-fr`, `page-en` |
| Post (single) | `single-{lang}` | `single-de`, `single-fr` |
| Search results | `search-{lang}` | `search-de`, `search-fr`, `search-en` |

Create language templates in the Site Editor (**Appearance → Editor → Templates**) by duplicating an existing template and saving it under the slug convention above. If a language-specific template does not exist, WordPress falls back to the default template.

**Auto-assignment on language change:** when an editor changes the `_lang` meta of a post or page, the router checks whether a matching template slug exists and assigns it automatically — but only if no custom template has already been set on that post.

### Admin UX

The **Lang** column in the post list shows the two-letter code, a **⚠** warning if the translation is outdated, and **⭕ DE, FR** for any languages missing a translation entirely.

A language filter dropdown and an "Outdated only" filter are added to the post list toolbar. The active language filter persists per user via user meta.

The **Translations** sidebar meta box shows each language's linked post and an **Override** button that pulls the source content into the translation via AJAX.

Quick Edit includes a language selector for posts, pages, and navigation items.

#### Link Fixer

When the post list is filtered by language, a **Fix Links (XX)** button appears in the toolbar. Clicking it opens a modal overlay that:

1. Scans all published posts and pages in that language for internal links that still point to a different language version of the same page
2. Shows a dry-run table with auto-fixable (red → green) and flagged (amber) links, each with a reason code
3. Provides per-row **Fix** and a **Fix All** action, plus a **🔄 Re-scan** button to verify results immediately

Only links with a Gutenberg `data-id` attribute are inspected. Structural links (breadcrumbs, manually typed hrefs) are deliberately skipped to avoid false positives.

### Overriding plugin translation strings

Any `.mo` file placed in `language-router/languages/` is loaded automatically at `init` priority 1, before plugins load their own translations. Files must follow the WordPress naming convention: `{textdomain}-{locale}.mo`. No code changes are needed when adding a new plugin or locale.

---

## AI Content Tools — detailed reference

### Meta Description Generator

Generates a ready-to-use SEO meta description from the post title and content. Language-aware via the `_lang` post meta field. Output is 140–160 characters with a character-count tooltip showing SEO quality (green/amber/red).

Uses the **Light** model tier (default: `claude-haiku-4-5-20251001`, 384 token budget, temperature 0.4).

### Excerpt Generator

Produces a concise editorial excerpt of up to 240 characters, language-aware.

Uses the **Light** model tier (default: `claude-haiku-4-5-20251001`, 512 token budget, temperature 0.4).

### Content Translation

Translates full post or page content while preserving all WordPress block comments, HTML structure, shortcodes, and element attributes. Only visible text is translated.

**Block attribute translation** — blocks like `wp:details` store visible text as JSON attribute values inside the block comment. The plugin extracts those strings (replacing them with `__WPAI_N__` placeholders), translates them in the same API call, and reinserts them with proper JSON escaping. Covered attributes: `summary`, `alt`, `caption`, `label`, `placeholder`, `buttonText`, `title`, `description`.

**Chunk mode** — a **Mode** selector offers *Full post* (translate title + content + block attributes in one call) and *Translate chunk* (paste any snippet — a footnote, a heading, a sentence — and translate just that). Chunk mode is the recommended workaround for footnotes or any content where the full-post path is unreliable.

**Footnote limitation** — WordPress footnotes are tightly coupled to post-specific UUIDs shared between `post_content` and the `footnotes` post meta. Full-post translation attempts to translate footnotes in the same API call, but this is fragile on long posts. The recommended workflow is chunk mode for footnotes: copy each footnote from the block editor's footnote panel, switch to *Translate chunk*, translate, and paste back.

**Translation Limits** — configurable from **Settings → LinguaForge AI → Translation Limits**:

| Setting | Default | Description |
|---|---|---|
| **Max output tokens** | 16 000 | Maximum tokens the AI may produce per translation response. Increase if very large pages are cut off at the end. |
| **Max input characters** | 0 (no limit) | Maximum characters of post content forwarded to the AI. `0` means the full content is always sent, which is the recommended setting. Set a non-zero value only when a provider has a tight context window — a PHP error log warning is written whenever content is trimmed. |

Uses the **Quality** model tier (default: `claude-sonnet-4-6`, 16 000 token budget, temperature 0.2).

Supported target languages (38 out of the box, grouped by region):

| Region | Languages |
|---|---|
| European — West | English, Spanish, Portuguese, French, Italian, German, Dutch, Catalan, Swedish, Danish, Norwegian, Finnish |
| European — East & South | Polish, Czech, Slovak, Hungarian, Romanian, Bulgarian, Croatian, Slovenian, Greek, Ukrainian, Russian |
| Middle East & Africa | Arabic, Hebrew, Persian, Turkish, Swahili |
| South & South-East Asia | Hindi, Bengali, Indonesian, Malay, Vietnamese, Thai |
| East Asia | Chinese (Simplified), Chinese (Traditional), Japanese, Korean |

The language list is filterable. Use the `linguaforge_translation_languages` filter to add, remove, or replace languages without modifying plugin files:

```php
// Add Swahili and remove Russian
add_filter( 'linguaforge_translation_languages', function ( array $languages ): array {
    $languages['sw'] = 'Swahili';
    unset( $languages['ru'] );
    return $languages;
} );

// Replace the entire list
add_filter( 'linguaforge_translation_languages', fn() => [
    'en' => 'English',
    'es' => 'Spanish',
    'ca' => 'Catalan',
] );
```

The filter applies everywhere the language list is used: the target language dropdown, validation, language detection, and the language name passed to the AI prompt. Language names must be in English — the AI uses them verbatim in its translation instructions.

### Content Generator

Drafts or rewrites post content from three controls: **Hints** (key points or rough structure), **Tone** (Informative, Persuasive, Storytelling, Technical, Conversational), and **Output type** (Full Article, Introduction only, Structured Outline). Generated output uses native Gutenberg block markup and slots directly into the block editor.

Uses the **Quality** model tier (default: `claude-sonnet-4-6`, temperature 0.6).

**Content Generator limits** — configurable from **Settings → LinguaForge AI → Content Generator**:

| Setting | Default | Description |
|---|---|---|
| **Max output tokens** | 8 192 | Maximum tokens the AI may produce per generation response. Raise to 12 000–16 000 if long articles are cut off at the end. |
| **Max hints characters** | 2 000 | Maximum characters accepted from the Hints field before the text is truncated. Increase only if you need to supply very large seed outlines. |
| **Max context characters** | 6 000 | Maximum characters of existing post body forwarded to the AI when no hints are provided, so the model can rewrite or extend the current content. |

### Quick Translate

Available in two places:

- **Admin Toolbar** — the ⇌ icon in the WordPress admin bar opens a popover with a language selector, textarea, Translate, and Copy buttons. Works on any admin page, no post required.
- **Editor Toolbar** — the same popover is injected into the Gutenberg / FSE editor's pinned-items bar. Always available in canvas-edit mode where the admin bar is hidden.

**Quick Translation limits** — configurable from **Settings → LinguaForge AI → Quick Translation**:

| Setting | Default | Description |
|---|---|---|
| **Model tier** | Light | Model tier used for chunk translations. Light (Haiku/Flash) is fast and cost-effective for short snippets; switch to Quality for higher accuracy. |
| **Max output tokens** | 2 000 | Maximum tokens per quick-translation response. Short snippets rarely exceed a few hundred tokens, but raise this for longer selections. |
| **Max input characters** | 8 000 | Maximum characters accepted in the Quick Translate textarea before the text is truncated. |

### Result Caching

Every feature caches its output in post meta using a SHA-256 hash of the inputs. The cache is invalidated automatically when any input changes — there is no TTL. A **cached** badge appears in the UI when a stored result is returned. A **↺ Refresh** link forces a new API call. Translation caches are keyed per language so multiple language versions can be cached independently.

---

## Known Issues and Troubleshooting

### AI request times out or returns a white screen on long content

**Symptom:** Generating a translation or content for a large post fails silently, returns a white screen, or produces a PHP fatal error in the log along the lines of `Maximum execution time of 30 seconds exceeded`.

**Root cause:** Managed hosting plans commonly cap `max_execution_time` at 30–60 seconds. LinguaForge uses a 120-second HTTP timeout for AI API calls, but PHP will kill the process first if the server limit is lower.

**Fix options (in order of preference):**
1. Raise the limit for the request in `wp-config.php` or a must-use plugin:
   ```php
   // Only applies to the current process — safe on most hosts
   set_time_limit( 180 );
   ```
2. Add to `.htaccess` (Apache):
   ```apache
   php_value max_execution_time 180
   ```
3. Ask your host to raise the limit, or switch to a plan that allows longer execution times (common on VPS and dedicated servers).
4. As a workaround without changing server config: translate the post in sections using **Chunk mode** (translate individual blocks rather than the full page).

### AI returns an empty result or "generation failed" with no error detail

**Symptom:** Clicking Generate or Translate shows the error message "Generation failed. Please try again." with no further explanation.

**Root cause:** The most common causes are an invalid or expired API key, the provider's rate limit being hit, or the provider's API being temporarily unavailable.

**Fix:** Check the PHP error log — LinguaForge logs the raw HTTP response code and body whenever a provider call fails. Also verify the API key in **Settings → LinguaForge AI → API Keys** and test it directly in the provider's dashboard.

### Translation is cut off at the end of a long page

**Symptom:** The translated content ends abruptly mid-sentence or mid-block. The AI result cache stores the truncated version.

**Root cause:** The AI provider hit its output token limit before finishing the response.

**Fix:** Go to **Settings → LinguaForge AI → Translation Limits** and increase **Max output tokens** (default: 16 000). Use **↺ Refresh** in the result panel to re-run without the cached truncated result.

### Editor toolbar Quick Translate button does not appear on first load

**Symptom:** The ⇌ button is missing from the Gutenberg top toolbar on first page load. A single reload (F5) makes it appear consistently from then on.

**Root cause:** The button is injected via `MutationObserver` rather than the `@wordpress/plugins` registration API. React's post-mount reconciliation can remove the injected element before the per-container observer is attached. The Admin Toolbar Quick Translate is unaffected and is always available as a fallback.

**Status:** Under investigation.

### Meta description generator uses old content after applying a translation

**Symptom:** After applying a translation via "Apply to Editor", clicking Generate Meta Description produces a description based on the original (pre-translation) content.

**Root cause:** The meta description generator reads `post_content` from the database. If the post hasn't been saved yet, the DB still holds the pre-translation content.

**Fix:** This is handled automatically — clicking "Apply to Editor" now triggers an auto-save before the button shows "Saved ✓". If the auto-save fails (shown as "Applied ✓ (auto-save failed)"), save the post manually before generating the meta description.

### Footnotes are not imported between translation pages

**Symptom:** Footnotes from the source page are not carried over when importing content into a translation page.

**Root cause:** Gutenberg footnotes are tightly coupled to post-specific UUIDs shared between `post_content` and the `footnotes` post meta. Footnote markup is stripped from imported content to avoid UUID collisions; the source footnotes are shown as a read-only reference in the **Source Footnotes** meta box on the translation page's edit screen.

**Fix:** Add footnotes manually to the translation using **Chunk mode** — copy each footnote text, switch to Translate chunk, translate it, and paste the result into the footnote panel.

---

## Language Overrides

Third-party plugins sometimes use terminology that doesn't fit your site — for example, VikBooking uses "room" but an apartment rental site needs "apartment". LinguaForge loads custom `.mo` files from an uploads-based directory so you can ship corrected translations without patching the third-party plugin.

**Storage location:** `wp-content/uploads/lingua-forge/i18n-overrides/`

The folder is created automatically on plugin activation. Files placed here survive plugin updates because they live outside the plugin codebase.

**File naming** follows the standard WordPress convention: `{textdomain}-{locale}.mo` (e.g. `vikbooking-ca.mo`, `vikbooking-es_ES.mo`). No code changes are needed when adding a new plugin or locale — the router discovers and loads all matching files automatically on every request.

**Managing files** — go to **Settings → LinguaForge AI → Language Overrides**:

- The table lists every `.mo` and `.po` file currently in the directory, with file size.
- Use the **Upload Override** form to upload a compiled `.mo` file directly from the browser.
- Each row has a **Delete** button that removes both the `.mo` and its `.po` source file together.

You can also manage files directly via FTP/SFTP/file manager — the UI and the filesystem are always in sync.

**Custom storage path** — use the `lf_i18n_overrides_dir` filter if you need to store override files somewhere other than the default uploads subfolder:

```php
add_filter( 'lf_i18n_overrides_dir', function ( string $dir ): string {
    return '/var/www/shared/lingua-forge-overrides/';
} );
```

The filter applies everywhere the directory is read — both the file loader and the Settings UI reflect it.

---

## Third-party compatibility

SEO plugin hreflang output is suppressed automatically when `lf_hreflang_mode` is `'custom'`. Confirmed compatible with: **Yoast SEO**, **Rank Math**, **AIOSEO**, **SEOPress**.

VikBooking locale compatibility is handled via the `lf_lang_force_locale` filter and `filter_locale_for_vik_booking`.

---

## Performance

On activation and version bump, LinguaForge creates a composite index on `wp_postmeta (meta_key, meta_value(10))` to speed up `_lang` queries across large sites. Translation lookups are wrapped in WordPress object cache and invalidated on post save. AI result caches are stored in post meta with `autoload = false`.

---

## Author

Uli Hake — [@leotiger](https://github.com/leotiger) on GitHub · [@ulih](https://profiles.wordpress.org/ulihake/) on WordPress.org

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

**Current release — 1.1.0**

- Public template functions renamed from `lf_*` to `linguaforge_*` for WordPress.org naming compliance
- Fixes an uninstall bug that left an orphaned DB index after plugin deletion
- Fixes a character-escaping bug in the Language Switcher custom label mode
- Full WordPress.org Plugin Check compliance pass across all files

## License

GPL-2.0-or-later — see [LICENSE](LICENSE)
