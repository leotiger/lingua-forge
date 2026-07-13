=== Lingua Forge ===
Contributors: ulih
Tags: multilingual, translation, ai, seo, meta-description
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 2.6.5
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual routing, complete multilingual SEO (hreflang, Open Graph, Schema.org, sitemap), and AI tools — free, no license required.

== Description ==

Lingua Forge is a free, permanently open-source multilingual plugin for WordPress. There is no paid tier, no annual license fee, and no subscription. AI features are powered by the API key of your choice (Anthropic, OpenAI, or Google Gemini) — you pay the provider directly at standard API rates, with no markup and no proprietary credit system in between. Every AI feature has a fully usable manual fallback, so the plugin works without any API key at all.

Lingua Forge is for sites that publish content in more than one language and want AI assistance built into the editorial workflow — without a paid third-party subscription service or a complex multi-plugin stack.

It brings together three concerns that always end up intertwined on multilingual WordPress sites: language routing, SEO meta output, and AI-assisted editorial work. Instead of coordinating three separate plugins that each make assumptions about the others, everything ships as a single installable package with a shared foundation.

**Built on WordPress, not around it**

Lingua Forge stays as close to WordPress core and Full Site Editing conventions as possible. Translations are native WordPress posts. FSE templates, template parts, and navigations are native `wp_template`, `wp_template_part`, and `wp_navigation` posts — not string-swapped versions of a shared entity. No runtime dependencies ship with the plugin, no parallel data layer, no render-time interception. Block API v3 throughout, no jQuery on the frontend, REST routes at `rest_api_init`, standard WordPress i18n and security conventions without exception.

**Language Router**

Detects the active language from URL prefixes (`/de/`), subdomains (`de.example.com`), query parameters, or a cookie, and keeps all routing, hreflang, and admin UX in sync.

* Language-prefixed URLs and category archives with automatic rewrite rules
* Post and page translation groups linked via a shared TRID (UUID)
* Outdated-translation tracking — flags content that was updated after its translations were last synced
* Full FSE template localisation — language-specific templates (`page-de`, `single-fr`, `search-en`) auto-assigned when a post's language is set. Settings → Router provides a complete scaffold → AI-translate → fix links → fix parts → fix nav workflow: create a language copy of any template or template part, AI-translate it, fix internal links, fix template-part slug references, and fix wp:navigation ref IDs — all without CLI or manual database work
* Language-specific template parts — scaffold, AI-translate, fix links, and fix navigation references for `header-{lang}`, `footer-{lang}`, and any template part. Each is an independent native `wp_template_part` post
* Language navigation menus — create per-language `wp_navigation` copies with AI-translated labels and language-prefixed URLs
* hreflang tags for singular, archive, and paginated views; suppresses duplicate output from Yoast SEO, Rank Math, AIOSEO, and SEOPress automatically
* **Complete multilingual SEO** — Open Graph with og:locale and og:locale:alternate, Twitter Cards, Schema.org JSON-LD (Article/WebPage/WebSite with inLanguage annotations, Product schema for WooCommerce), and a dedicated XML sitemap at `/lf-sitemap.xml` with xhtml:link alternate entries. No companion SEO plugin required. Settings → SEO provides a Compatibility tab explaining exactly what LF does alongside any detected SEO plugin
* **Social Share** — set any WordPress Social Icons block link URL to `share:facebook`, `share:x`, `share:linkedin`, `share:whatsapp`, `share:telegram`, `share:reddit`, `share:copy`, or `share:auto`; Lingua Forge rewrites it at render time with no custom code required
* **SEO Content Analysis** — rule-based 0–100 score accessible from three entry points: single-post analysis in Settings → SEO → Analysis; the block editor Document sidebar with AI Recommendations panel; and a **Batch Analysis** card grid that runs an entire language in one pass and presents results as a **Multilingual SEO overview** with per-language tabs, direct edit links, source-language titles for parity comparison, and colour-coded scores. WooCommerce system pages are excluded from batch scoring automatically
* Language Switcher — available as a Gutenberg block (LSFLR Switcher), a `[lsflr_switcher]` shortcode, and a classic widget (Appearance → Widgets). All three produce identical output and support `direction`, `show`, and `customLabel` options. The `linguaforge_switcher_output` filter wraps all three
* Admin link fixer — finds internal links pointing to the wrong language version and repairs them in bulk
* **Full Custom Post Type support** — every public CPT (WooCommerce `product`, any third-party CPT) automatically receives the full admin layer: Lang column with outdated/missing indicators and Retranslate/Translate-missing buttons, language filter dropdowns, quick-edit language control, AI translation metabox, FSE template selector, Translation Memory eligibility, and link-fixer scan. No configuration required
* **WooCommerce integration** — translated products carry only content fields (title, description, meta description); price, SKU, stock, dimensions, images, variations, and taxonomy assignments are served transparently from the source-language product at runtime. Category and attribute term names display in the visitor's language via translated names stored on the term edit screen. Requires WooCommerce 9.0+ and WordPress 6.9+

**Meta Description**

Adds a meta description field to every public post type and outputs `<meta name="description">`, `<meta property="og:description">`, and `<meta name="twitter:description">` on every page.

* Editable in the Classic meta box, fully compatible with the Block Editor
* Character counter with green/amber/red guidance (120–160 ideal range)
* Fallback chain: custom field → post excerpt → site description
* Stores descriptions under `_linguaforge_meta_description` (prefixed, plugin-owned)

**AI Content Tools**

Supports Anthropic Claude, OpenAI, and Google Gemini as interchangeable backends. All generated results appear in a review panel — nothing is applied automatically.

* **Meta Description Generator** — language-aware, 140–160 character output with SEO quality indicator
* **Excerpt Generator** — concise editorial excerpt up to 240 characters
* **Content Translation** — translates full posts while preserving all Gutenberg block markup, block attribute strings (accordion summaries, image alt text, etc.), and footnotes; chunk mode for individual snippets. Max output tokens and max input characters are configurable from Settings with no code changes needed
* **Content Generator** — drafts or rewrites post content from topic hints, tone, and output-type controls; outputs native Gutenberg block markup. Max output tokens, max hints characters, and max context characters are configurable from Settings
* **Quick Translate** — admin toolbar popover with three modes: Translate any text snippet, Create new content from hints and tone controls, and Refine any result iteratively. Also available in the Gutenberg / FSE editor toolbar (Translate mode)
* **AI Behavior Presets** — four named presets (Standard, Technical / Scientific, Legal / Compliance, Creative / Marketing) control the AI's temperature and system instructions. Set a site-wide default from Settings or override it per post from the editor metabox (Translation and Content Generator only)
* **Translation Memory** — opt-in block-level cache shared across posts; only new or changed blocks are sent to the API on subsequent translations, reducing token usage on recurring content
* **Glossary** — user-managed terminology table per language pair. Terms are injected into every translation prompt so brand names, technical terms, and units stay consistent
* **Side-by-side diff preview** — "Apply to Editor" shows a two-column before/after modal so you can review the translation before it touches the post
* **AI Usage tracking** — every API call is logged by feature, provider, model, and date. A summary table with token counts is available in **Settings → AI Usage**
* **Language Overrides** — upload custom `.mo` files to override third-party plugin strings per locale (e.g. replace "room" with "apartment" in VikBooking). Files are stored in the uploads folder and survive plugin updates. Managed from **Settings → Lingua Forge → Language Overrides**
* **WP-CLI** — five commands for scripted and automated workflows: `wp linguaforge translate`, `retranslate`, `fill_translations`, `missing_translations`, and `cache_clear`

API keys are stored encrypted (AES-256-GCM with provider slug as authenticated data, derived from WordPress auth salts). Model endpoints are configurable from Settings with no code changes needed when a new model version ships.

Source code and issue tracker: https://github.com/leotiger/lingua-forge

== Compatibility ==

**WordPress:** Lingua Forge requires WordPress 6.4 or later. All core features — language routing, hreflang, SEO meta, AI tools, CPT support, subdomain mode — work on 6.4+.

**WooCommerce integration:** The built-in WooCommerce integration (shared-stock delegation, translated category and attribute names) requires WordPress 6.9 or later and WooCommerce 9.0 or later. The integration is inactive and causes no errors on earlier versions; it is simply not loaded.

**PHP:** 8.1 or later is required regardless of which features are used.

**Multisite:** Lingua Forge is not tested on WordPress Multisite. Per-site activation (each site manages its own languages and settings independently) is expected to work. Network-wide activation is not supported and may produce unexpected behaviour.

== Installation ==

**Lingua Forge is not listed in the WordPress.org Plugin Directory.** Install it manually from GitHub:

1. Go to the [Releases page](https://github.com/leotiger/lingua-forge/releases) and download the latest `lingua-forge-{version}.zip`.
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and click **Install Now**.
3. Activate **Lingua Forge** from **Plugins → Installed Plugins**.
4. Go to **Settings → Permalinks** and click **Save Changes** — this registers the language URL prefixes.
5. Go to **Settings → Lingua Forge**, select an AI provider, and enter your API key.

**After the first manual install, updates are automatic.** Once the plugin is active, WordPress checks for new versions automatically (every 12 hours) and displays the standard update badge in **Plugins → Installed Plugins** when a new release is available. You can then update with one click — no manual download needed for subsequent updates.

**Migrating from mu-plugins:** if you were running Language Router, Meta Description, or WPEnhance AI as must-use plugins, deactivate or remove those files before activating Lingua Forge to avoid duplicate hooks. Existing post meta (`_lang`, `_trid`, `meta_description`) and the `my_lang_filter` user preference are migrated automatically on first activation.

== Frequently Asked Questions ==

= Do I need a separate SEO plugin, sitemap generator, or hreflang plugin? =

No. Lingua Forge ships a complete multilingual SEO stack out of the box — no companion plugin required:

* **hreflang** — injected automatically on every singular, archive, and paginated view. Duplicate output from Yoast SEO, Rank Math, AIOSEO, and SEOPress is suppressed automatically if any of those plugins are active.
* **XML sitemap** — a dedicated sitemap at `/lf-sitemap.xml` includes `<xhtml:link rel="alternate" hreflang>` entries for every translation group. The WordPress built-in sitemap (`/wp-sitemap.xml`) is disabled so there is no duplication. The sitemap URL is announced automatically via a `Sitemap:` directive in `robots.txt`.
* **Open Graph & Twitter Cards** — `og:locale`, `og:locale:alternate`, and Twitter Card tags output on every page with no configuration needed.
* **Schema.org JSON-LD** — Article, WebPage, and WebSite markup with `inLanguage` annotations; Product schema for WooCommerce product pages.
* **Meta descriptions** — a dedicated meta description field on every public post type; output as `<meta name="description">`, `og:description`, and `twitter:description`.
* **SEO Content Analysis** — a rule-based 0–100 score accessible from Settings → SEO → Analysis (single-post and batch), the block editor Document sidebar (AI recommendations), and a Multilingual SEO overview with per-language tabs for cross-language parity review.

If you already run Yoast SEO, Rank Math, or a similar plugin for non-multilingual features (redirects, breadcrumbs, etc.), Lingua Forge coexists cleanly — see the Settings → SEO → Compatibility tab for a full breakdown of what each plugin contributes when both are active.

= Can I use Lingua Forge without an AI subscription? =

Yes. The Language Router (URL-based language routing, hreflang injection, language switcher block, FSE template routing) and the Link Fixer work with no API key at all. The AI features — translation, meta description generation, and content generation — are optional enhancements. Simply leave the API key fields empty and the plugin will function as a pure language-routing and multilingual management tool.

= What happens to my content if I deactivate or uninstall the plugin? =

**Deactivating** stops routing and AI features but leaves all data intact — your posts, settings, and meta fields are untouched. Reactivating picks up where you left off.

**Uninstalling (deleting)** always removes plugin settings, API keys, transients, and the AI result cache. By default, language assignments (`_lang`), translation relationships (`_trid`), meta descriptions, the AI glossary, and Translation Memory are **kept** — so a reinstall can pick up where it left off without losing any editorial work. To also remove that data, enable **Settings → Maintenance → Delete content data on uninstall** before deleting the plugin. The translated posts themselves are ordinary WordPress posts and are never deleted regardless of this setting.

= Does this work with classic (non-block) themes? =

Most features work with any theme. Language routing, hreflang injection, the AI meta box, and meta description generation are theme-agnostic. For classic themes the language switcher is available as the `[lsflr_switcher]` shortcode (paste into any post, page, or widget area that supports shortcodes) or as the **Language Switcher** classic widget under Appearance → Widgets. Both produce the same output as the Gutenberg block.

= Does Lingua Forge require any theme preparation? =

Lingua Forge handles this natively: the **Settings → Router** page lets you scaffold a language copy of any template or template part, AI-translate it in one click, and fix internal links, template part references, and navigation menu references — all without editing theme files directly. Language-specific templates (e.g. `page-de`, `single-fr`) are routed automatically once they exist.

While Lingua Forge covers the most common templates and template parts out of the box, complex themes and plugins that dynamically inject patterns, template parts, or custom block types may require additional adaptation. In those cases the scaffolding and AI-translate tools in Settings → Router give you a starting point, but some manual review of injected content may be needed.

= Can I use Lingua Forge alongside WPML or Polylang? =

Not recommended — all three handle language routing at the URL and content level, and running them in parallel will produce conflicts. Lingua Forge is a replacement, not an add-on. If you are migrating, disable WPML or Polylang before activating Lingua Forge. Post relationships from those plugins are not auto-imported; use the Translation meta box in the post editor to re-link translated posts after migrating.

= Does Lingua Forge work with WooCommerce? =

Yes — WooCommerce product posts are fully supported via the shared-stock delegation model. Translated products carry only content fields (title, description, excerpt); all operational data (price, SKU, stock, dimensions, images, variations, taxonomy assignments) is served from the source-language product at runtime with no copying or sync required. Category, tag, and product attribute term names display in the visitor's language — enter translated names directly on the term edit screen (Products → Categories, Products → Tags, or the attribute edit screen under Products → Attributes).

**Minimum versions for WooCommerce support:** WooCommerce 9.0 or later and WordPress 6.9 or later. The core plugin (language routing, hreflang, AI tools) works on WordPress 6.4 without WooCommerce — the integration layer is silently skipped when WooCommerce is not active.

= Which AI providers are supported? =

Anthropic Claude, OpenAI (GPT), and Google Gemini. You only need an API key for the provider you want to use. The active provider is selected from **Settings → Lingua Forge**.

= Where are API keys stored? =

Keys are encrypted with AES-256-GCM (authenticated encryption with provider slug as additional data) using a secret derived from your WordPress auth salts and stored in `wp_options`. Plaintext keys never touch the database. As a fallback, the plugin also reads keys from server environment variables or PHP constants in `wp-config.php`.

= Does this work with FSE / block themes? =

Yes — the Language Router was designed specifically for block-theme sites. Language-specific FSE templates, hreflang injection, and the Language Switcher block all work in the Site Editor.

= Does Lingua Forge conflict with Yoast SEO, Rank Math, or other SEO plugins? =

The hreflang output from third-party SEO plugins is suppressed automatically when Lingua Forge is handling hreflang (the default). Meta description output coexists without conflict. If you prefer to let an SEO plugin handle hreflang, set the `lf_hreflang_mode` filter to `'off'`.

Plugins that read the WordPress `locale` filter directly instead of `determine_locale` — common in booking and e-commerce plugins — receive the correct frontend locale automatically. No configuration is required.

= What languages can be translated? =

The AI translation feature supports 38 languages out of the box: English, Spanish, Portuguese, French, Italian, German, Dutch, Catalan, Swedish, Danish, Norwegian, Finnish, Polish, Czech, Slovak, Hungarian, Romanian, Bulgarian, Croatian, Slovenian, Greek, Ukrainian, Russian, Arabic, Hebrew, Persian, Turkish, Swahili, Hindi, Bengali, Indonesian, Malay, Vietnamese, Thai, Chinese (Simplified), Chinese (Traditional), Japanese, and Korean. The list is filterable via `linguaforge_translation_languages` — you can add, remove, or replace languages without modifying plugin files. The Language Router itself works with any language WordPress supports.

= Translation cuts off at the end of a long page. =

Go to **Settings → Lingua Forge → Translation Limits** and increase **Max output tokens** (default: 16 000). If you also want to cap how much content is sent to the AI, set **Max input characters** — leave it at `0` (the default) to always send the full page.

= Generated content is cut off before the article is finished. =

Go to **Settings → Lingua Forge → Content Generator** and increase **Max output tokens** (default: 8 192). For very long articles you may need to raise this to 12 000–16 000. You can also adjust **Max hints characters** (default: 2 000) to control how much of the Hints field is forwarded to the AI, and **Max context characters** (default: 6 000) to control how much of the existing post body is used when no hints are provided.

= How do I override a third-party plugin's strings for a specific language? =

Place a compiled `.mo` file named `{textdomain}-{locale}.mo` (e.g. `vikbooking-ca.mo`) in `wp-content/uploads/lingua-forge/i18n-overrides/`. The easiest way is to go to **Settings → Lingua Forge → Language Overrides** and use the upload form. The folder is created automatically on plugin activation, files survive plugin updates, and no code changes are needed when adding new overrides.

= AI requests time out or cause a white screen on long content. =

Managed hosting plans often cap PHP execution time at 30–60 seconds. Lingua Forge uses a 300-second HTTP timeout for AI API calls (configurable via the `linguaforge_ai_retry_policy` filter), but PHP kills the process first if the server limit is lower. Fix options: add `set_time_limit( 300 );` to `wp-config.php`, add `php_value max_execution_time 300` to `.htaccess`, or ask your host to raise the limit. As a workaround without server changes, use **Chunk mode** to translate individual blocks rather than the full page at once.

= The AI returns "generation failed" with no explanation. =

Check the PHP error log — Lingua Forge writes the raw provider response there whenever a call fails. The most common causes are an invalid or expired API key, hitting the provider's rate limit, or a temporary provider outage. Verify your key in **Settings → Lingua Forge → API Keys** and test it in the provider's own dashboard.

= The meta description generator uses the old content after I apply a translation. =

Clicking "Apply to Editor" now triggers an automatic save. If the save succeeds (button shows "Saved ✓") the meta description generator will read the translated content. If the auto-save fails, save the post manually before generating the meta description.

= Do I need a permalink structure other than Plain? =

Yes. Language URL prefixes (`/de/`, `/fr/`, etc.) require WordPress to use pretty permalinks. Go to **Settings → Permalinks** and choose any option except **Plain**.

= My language navigation shows pages from all languages, not just the current one. =

As of 2.1.0 this is handled automatically for both navigation types:

* **Page List (auto-add) navigations** — Lingua Forge filters the page list to the navigation's language in both the frontend canvas and the Site Editor sidebar picker. No manual action needed.
* **Explicit (edited) navigations** — once you have manually built a navigation with specific links, those choices are yours and are left untouched. If any links point to the wrong language version, go to **Settings → Router → Fix Links** to repair them in bulk.

== Screenshots ==

1. AI translation review — side-by-side comparison of the current source content and the AI-generated translation before applying it to the editor. The generated meta description is shown below the content diff.
2. Quick Translate popover in the admin toolbar — Translate, Create, and Refine tabs.
3. Quick Translate popover in the Gutenberg editor toolbar.
4. Block-level Translate / Revise popover on a selected block.
5. Settings → Lingua Forge — provider selection, API key management, and model overrides.
6. Language column and filters in the post list.
7. Translation meta box in the post editor — linked translations per language with Override control.
8. Admin Link Fixer — dry-run table with per-row Fix and Fix All actions.

== Privacy Policy ==

= Cookie =

The Language Router sets a functional cookie named `lf_lang` to remember the visitor's chosen language (e.g. `ca`, `es`, `de`). The cookie is HttpOnly, lasts 30 days, and contains only a language code. Its value is never stored in the database, never logged, and never sent to any external service. It is read on each request solely to route the visitor to the correct language version of the site.

This is a strictly functional cookie. It does not track behaviour, identify individuals, or serve any analytics purpose.

= AI features and content data =

When an administrator uses the AI translation, generation, or revision features, the relevant post content is sent to the configured third-party AI provider. See the External Services section below for details on which providers are used and what data is transmitted.

An optional **Automatic Translation Backfill** (Settings → Behavior), off by default, periodically scans for posts missing a translation in an active language and sends their content to the configured AI provider without a per-request administrator action. Turning it on is itself the explicit administrator action that authorises this background sending. It never runs without a configured API key, and never includes WooCommerce products/variations. With this feature left off (the default), no content is ever sent automatically or without administrator action.

= Data stored on your server =

The plugin stores the following data in your WordPress database:

* Encrypted API keys in `wp_options`.
* AI cache entries (post content hashes and translated output) in a custom table. Cleared via Settings → Maintenance → Clear AI Cache or on plugin uninstall.
* Translation Memory entries (block-level translated content) in a custom table. Cleared via Settings → Maintenance or on uninstall.
* AI usage statistics (token counts per date, user, feature, provider) in a custom table. No personally identifiable information beyond the WordPress user ID. Dropped on uninstall. Rows for a given user are removed when that user's data is erased via **Tools → Erase Personal Data** (WordPress privacy tools).
* Language metadata (`_lang`, `_trid`, `_lf_trans_*`) stored as post meta on multilingual posts.
* Order language (`_lf_order_lang`) stored as WooCommerce order meta when WooCommerce is active. This meta is covered by WooCommerce's own order anonymisation and erasure flows.
* Translation-backfill failure state (`_lf_translation_failures`) as post meta on a source post, only while Automatic Translation Backfill is enabled and only for (post, language) pairs that failed. Cleared automatically on a successful retry.

All custom tables and plugin-specific options are removed on uninstall.

== External Services ==

This plugin connects to third-party AI APIs to generate and translate content. Connections require an administrator to have configured an API key.

By default, connections are also gated on a user explicitly triggering an AI feature (Generate, Translate, Quick Translate, Sync, etc.) — no data is sent automatically or in the background.

If an administrator opts into **Automatic Translation Backfill** (Settings → Behavior → Automatic Translation Backfill, off by default), the plugin additionally sends post content to the configured AI provider on an hourly schedule, without further per-request action, for any published post found missing a translation in an active language. This background sending only occurs while that setting is turned on, and never for WooCommerce products/variations.

= Anthropic (Claude) =
Used when the active provider is set to Anthropic.
* API endpoint: https://api.anthropic.com/v1/messages
* Data sent: post title, post content, and any configured prompt instructions.
* Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy

= OpenAI (GPT) =
Used when the active provider is set to OpenAI.
* API endpoint: https://api.openai.com/v1/chat/completions
* Data sent: post title, post content, and any configured prompt instructions.
* Terms of Service: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy

= Google Gemini =
Used when the active provider is set to Google Gemini.
* API endpoint: https://generativelanguage.googleapis.com/v1beta/models/
* Data sent: post title, post content, and any configured prompt instructions.
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

= AI model list fetches =
When Settings → Lingua Forge is opened, the plugin fetches the current list of available models from each configured provider. These requests use the same API key already stored for content generation and transmit only the key (via the Authorization header).
* Anthropic endpoint: https://api.anthropic.com/v1/models
* OpenAI endpoint: https://api.openai.com/v1/models
* Google Gemini endpoint: https://generativelanguage.googleapis.com/v1beta/models
* Data sent: API key (Authorization header only). No post content is transmitted.
* Terms of Service / Privacy Policy: see the respective provider sections above.

= IndexNow (search engine notification) =
When a post or page is saved, the plugin notifies the IndexNow network so search engines can recrawl the updated URL promptly. This is triggered only on public post saves and only when IndexNow is enabled in Settings → SEO → Sitemap.
* Endpoint: https://api.indexnow.org/indexnow
* Data sent: the updated URL, your site's host, and the IndexNow key stored in your uploads folder. No personal data is transmitted.
* IndexNow protocol: https://www.indexnow.org/documentation
* Privacy Policy: https://www.indexnow.org/privacy

= Update checks (self-hosted updater) =
The plugin periodically checks for updates by fetching a small JSON manifest from lingua-forge.com. The request includes the plugin version, WordPress version, and your site's home URL (sent as part of the User-Agent string). No other data is transmitted. This request is made from the WordPress admin area only and is never triggered on the frontend.
* Endpoint: https://lingua-forge.com/wp-json/lingua-forge/v1/update
* Data sent: plugin version, WordPress version, site home URL (User-Agent only).
* Privacy Policy: https://lingua-forge.com/privacy-policy

== Developers ==

The plugin is developed against WordPress Coding Standards (PHPCS + WPCS 3.1), passes PHPStan level 5 with WordPress stubs, and is verified clean by the official WordPress Plugin Check tool. JavaScript and CSS are linted via ESLint and Stylelint (@wordpress/scripts). A PHPUnit test suite (unit + integration) ships alongside the source. Source code and contributing guide at https://github.com/leotiger/lingua-forge.

== Changelog ==

= 2.6.5 =
* Fixed: The Models datalist (Settings → AI Provider) reverted to the hard-coded built-in catalog on every page load, discarding the live model list fetched from the provider's own API when "Test connection" last succeeded — the fetch and its 24-hour cache were both working, but the settings page never read the cached list back when rendering the field suggestions. It now does. (`ai/includes/Admin/Settings/Tabs/GeneralTab.php`)
* Fixed: Overriding a model to a newer Claude generation that has deprecated the `temperature` parameter failed outright with an HTTP 400 from Anthropic. The request now retries once with the parameter dropped when the provider reports it as deprecated for that model, keeping temperature control intact (still used by the compliance presets) for models that accept it. (`ai/includes/Providers/AbstractProvider.php`, `ai/includes/Providers/Anthropic.php`)
* Added: "Test model" button next to every Light/Quality model field in Settings → AI Provider → Models — translates a short sample of your most recent published post with the exact (saved or unsaved) model in that field, using the tier's real translation code path and the currently active Behavior preset, and previews the translated output. Replaces a bare connectivity ping, which couldn't confirm a Quality-tier override actually produced usable translations. Makes a real, billed API call. (`ai/includes/Admin/Settings/Tabs/GeneralTab.php`, `ai/includes/Admin/Settings/Tabs/ApiKeysTab.php`, `ai/includes/Features/Translation.php`, `ai/includes/Features/JsonEnvelopeTranslator.php`, `ai/assets/test-connection.js`)

= 2.6.4 =
* Fixed: A first-time translated post created via "Translate missing"/Sync (`PostListColumn::create_linked_post()`) or the WP-CLI `translate`/`fill_translations` commands (`AbstractTranslateCommand::create_trid_linked_post()`) was born with no excerpt — only `TranslationTrigger::create_translated_post()` (the path third-party integrations use) carried it, a gap left by the 2.4.0 excerpt fix. All three creation paths now build their common `wp_insert_post()` args (title, content, status, type, author, excerpt) through one new shared helper, `TranslationTrigger::build_create_args()`, so a future fix to a common field lands in all three by construction instead of requiring a repeat spot-fix. (`ai/includes/Features/TranslationTrigger.php`, `ai/includes/Admin/PostListColumn.php`, `ai/includes/CLI/AbstractTranslateCommand.php`)
* Fixed: A translated WooCommerce **variable product** created via the programmatic API (`linguaforge_trigger_translation()`/`linguaforge_queue_translation()`, including the Automatic Translation Backfill scan) or the WP-CLI create path was born with no translated variation children and no WC structural taxonomies (`product_type`, `pa_*`, `product_brand`) — the `wp_after_insert_post` hook that normally syncs these always saw an empty `_lf_lang` during creation and silently did nothing. Only "Translate missing"/Sync already compensated for this. All three creation paths now call one shared helper, `TranslationTrigger::sync_variation_children_if_product()`, explicitly after the TRID/lang meta is written. (`ai/includes/Features/TranslationTrigger.php`, `ai/includes/Admin/PostListColumn.php`, `ai/includes/CLI/AbstractTranslateCommand.php`)
* Fixed: Uninstalling a language (Settings → Router → Danger Zone) could delete the wrong locale files, or miss the right ones, due to unanchored prefix matching in `collect_locale_files()`. At `WP_LANG_DIR` root, uninstalling `de` could also delete an unrelated `den_DK.mo`; uninstalling `ar`, `ce`, `az`, or `ka` could delete real dialect files (`ary`/`arq` Arabic, `ceb` Cebuano, `azb` South Azerbaijani, `kab` Kabyle) that merely share the 2-letter prefix. In the `plugins/`/`themes/` subdirectories, files use a `{textdomain}-{locale}.mo` suffix convention that the old prefix check couldn't match at all, so real files there were silently skipped. Two new helpers replace the old ambiguous regex: `locale_root_matches()` anchors the root-level match so a code can't be a false prefix of a longer one, and `locale_suffix_matches()` checks the actual `-{locale}.mo` suffix convention; `collect_override_files()` (i18n-overrides / Loco "Copy to Safe Storage") now shares the same suffix matcher instead of duplicating its own. (`ai/includes/Admin/Language/LanguageUninstaller.php`)
* Fixed: **Sync** and **Template Sync** could overwrite a sibling translation the current user has no permission to edit — both checked `current_user_can()` only on the post you clicked, then wrote to every other post in the translation group regardless. Matters for Author-role setups: an Author who can trigger Sync on their own post could still have it overwrite a sibling authored by someone else. Both now check permissions per target too, skipping (and reporting) any post the current user can't edit instead of writing to it. Programmatic callers via `linguaforge_sync_translations()`/`linguaforge_sync_templates()` are unaffected, same as before. (`ai/includes/Admin/PostListColumn.php`)
* Fixed: The uninstall cleanup (Delete on Plugins → Installed Plugins) had drifted ~30 options, several post meta keys, and every scheduled cron/Action Scheduler job behind current source — the whole SEO layer, sitemap bookkeeping, IndexNow's key, and several other settings had no delete path despite the plugin's own claim to remove "all linguaforge_*/lf_* options". Replaced with a single self-updating options sweep (covers any future option automatically), added the missing post meta keys, and now clears pending cron/Action Scheduler jobs too, so nothing keeps running after the plugin itself is gone. (`uninstall.php`)
* Fixed: The FSE "Re-create" force path (Settings → Router → Templates and Template Parts) looked up the existing template/part to update in place with no theme scoping, so it could silently overwrite an unrelated same-slug row belonging to a different theme — WordPress itself allows two themes to share a template slug. The lookup is now scoped to the active theme/namespace, matching the pattern already used elsewhere in the same class. (`ai/includes/Admin/FseLocalisation/ScaffoldHandler.php`)
* Fixed: The **WordPress AI Client** provider (WP 7.0+, Settings → Connectors) crashed with an uncaught PHP error on any multi-turn "Refine" request — reachable via AI Content Generation's or Chunk Translation's Refine step when this provider is selected. Verifying the provider against WordPress's shipped AI Client API (it was originally written against an earlier preview) found `with_history()` needs different input than was being sent; fixed by converting each conversation turn to the object type it actually expects. The other three AI providers (Anthropic, OpenAI, Gemini) were never affected. (`ai/includes/Providers/WpAiClient.php`)

= 2.6.3 =
* Changed: Automatic Translation Backfill (2.5.3) is now off by default and controlled by a new **Settings → Behavior → Automatic Translation Backfill** toggle — previously it ran unconditionally, hourly, for every site with the AI module active, with no setting to stop it. Enabling it is what now authorises the background AI sends the External Services section discloses. (`ai/includes/Features/TranslationBackfill.php`, `ai/includes/Admin/Settings/Tabs/BehaviorTab.php`, `ai/includes/Admin/SettingsPage.php`)
* Fixed: The backfill scan no longer queues jobs when no AI provider/API key is configured — previously every queued job failed, and kept retrying on a 24-hour cooldown forever, on a site with no key set at all. (`ai/includes/Features/TranslationBackfill.php`)
* Fixed: The backfill scan now respects the `linguaforge_cpt_create_allowed` filter per post type, matching "Translate missing" and Sync — previously it was the one creation path that bypassed this integration gate entirely. (`ai/includes/Features/TranslationBackfill.php`)
* Changed: WooCommerce products and variations are now excluded from the backfill scan by default, since the scan's only creation path doesn't run variation sync (see #3, `AUDIT-2026-07-11.md`). Still reachable via the existing `linguaforge_backfill_post_types` filter if an integration wants them included. (`ai/includes/Features/TranslationBackfill.php`)
* Changed: `readme.txt`'s External Services / AI-data-and-privacy sections now disclose the background AI sends Automatic Translation Backfill makes when enabled, correcting prior wording that said no data is ever sent automatically.

= 2.6.2 =
* Added: "Re-create all" and per-template "Re-create" buttons in Settings → Router → Templates — force-overwrite an existing FSE template with a fresh copy from the active theme, discarding any Site Editor customisations. Unlike "Create missing"/"Create", which refuse to touch a template that already exists, Re-create replaces the content in place (keeping the same post ID when a DB-stored copy already exists) and is guarded by a confirmation dialog since it can't be undone. New `force` POST param on the existing `linguaforge_scaffold_template` AJAX action. (`ai/includes/Admin/FseLocalisation/ScaffoldHandler.php`, `ai/includes/Admin/Settings/Tabs/Sections/TemplatesSection.php`, `ai/assets/fse-scaffold.js`)
* Added: The same "Re-create all" / "Re-create" pair for Settings → Router → Template Parts (headers, footers, and any other theme part) — same `force`-bypass logic against `wp_template_part`, same confirmation guard. New `.lf-parts-group` wrapper gives the parts panel a bulk-action scope it didn't have before. (`ai/includes/Admin/FseLocalisation/ScaffoldHandler.php`, `ai/includes/Admin/Settings/Tabs/Sections/TemplatePartsSection.php`, `ai/assets/fse-scaffold.js`)
* Added: "Recreate All Languages" button above the language tabs in Settings → Router → Language Setup — runs Re-create all templates, Re-create all parts, Translate all, Fix all parts, and Fix all links for every active language in sequence, one language at a time, with a Cancel button and a final per-language failure summary. Purely a client-side orchestrator over the existing per-language buttons; no new AJAX endpoints. (`ai/assets/fse-global-actions.js`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`)
* Added: "Recreate All Languages (Template Parts Only)" — a second global button for when only headers/footers/etc. need refreshing across every language, without also rebuilding templates, re-translating, or running the link/part-ref fixers. Same sequencing, Cancel behaviour, and failure summary as the full run. (`ai/assets/fse-global-actions.js`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`)
* Added: "Recreate All Languages (Templates Only)" — a third global button, the mirror of Template Parts Only: runs Re-create all templates, Translate all, Fix all parts, and Fix all links across every language, leaving template parts untouched. (`ai/assets/fse-global-actions.js`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`)
* Added: "Translate all", "Fix all links", and "Fix all navs" bulk buttons to the per-language Template Parts toolbar, bringing it to parity with the Templates panel (which already had these). Previously only "Re-create all" was available there; translating or fixing links/nav-refs across every part required doing it one part at a time. (`ai/assets/fse-translate.js`, `ai/assets/fse-link-fixer.js`, `ai/assets/fse-part-fixer.js`, `ai/includes/Admin/Settings/Tabs/Sections/TemplatePartsSection.php`)

= 2.6.1 =
* Added: `linguaforge_template_for_lang` filter — lets an integration override the language-specific FSE template slug Lingua Forge is about to assign to a translated post. Applies across every assignment path (editor save, WP-CLI, Sync button, and programmatic creation), never fires for the source-language post, and returning an empty value suppresses assignment entirely. (`language-router/includes/translation/class-sync.php`)
* Fixed: A translated post created via `linguaforge_trigger_translation()` / `linguaforge_queue_translation()` (the path every third-party integration uses, e.g. Agnosis) never received its language-specific FSE template (`single-{post_type}-{lang}`), even when one existed — it was left on the default/untranslated template. `TranslationTrigger::create_translated_post()` now calls `Sync::assign_template_if_needed()` after insertion, matching the normal editor save, WP-CLI, and Sync-button paths, which already did this. (`ai/includes/Features/TranslationTrigger.php`)
* Fixed: The "Sync" button and the "Translate missing" bulk action could silently strip the language-specific template off an already-templated, existing translation when force-refreshing it in place. Both disable the normal save hook for their entire batch, so the compensating template reassignment never ran the way it does for the single-post "Retranslate" action. Templates are now reassigned explicitly, independent of hook state. (`ai/includes/Admin/PostListColumn.php`)
* Added: "Template Sync" (TS) — a new button next to Sync in the post list Lang column that reassigns the correct language-specific template for every existing translation of a post, with no AI call and no content changes. Only shown on the primary/source-language post. Also adds `linguaforge_sync_templates( $post_id, $check_caps = false )` for programmatic use. (`ai/includes/Admin/PostListColumn.php`, `ai/ai.php`)

= 2.6.0 =
* Added: "Sync" — a new button in the post list Lang column, shown on every language version of a translated post, including the primary/source post. One click retranslates FROM that post's language INTO every other configured language: any missing language is created, any existing one is force-refreshed in place. Unlike the existing "Retranslate" button, Sync is never blocked on the source-language post — triggering it from a secondary-language post can overwrite the primary post via back-translation, which is the intended behaviour (the point of Sync is "make every other version match this one"), guarded by a confirmation dialog before it runs since it can touch several posts, including the source, in a single click. (`ai/includes/Admin/PostListColumn.php`, `ai/assets/post-list.js`, `ai/assets/admin.css`)
* Added: WooCommerce safeguard for Sync — syncing a secondary-language product/variation is blocked by default, since it would back-translate onto the primary product (WooCommerce's operational source of truth for price, SKU, and stock). Syncing FROM the primary product is unaffected. Lift the restriction via **Settings → Behavior → WooCommerce**, or the new `linguaforge_wc_secondary_sync_allowed` filter. (`ai/includes/Admin/PostListColumn.php`, `ai/includes/Admin/Settings/Tabs/BehaviorTab.php`)
* Added: General secondary-language safeguard for Sync — the same restriction as the WooCommerce one above, now covering every other post type (posts, pages, any other CPT), which previously had none. Independent setting: **Settings → Behavior → Sync**, or the `linguaforge_secondary_sync_allowed` filter. Enabling one safeguard's setting does not affect the other. (`ai/includes/Admin/PostListColumn.php`, `ai/includes/Admin/Settings/Tabs/BehaviorTab.php`)
* Added: `linguaforge_sync_translations( $post_id, $check_caps = false )` — public API for Sync, for integrations that want the same behaviour from their own code. (`ai/ai.php`)

= 2.5.5 =
* Fixed: Language Switcher — Grid Overlay panel could open anchored to the wrong side of the trigger on an RTL-language page (e.g. Arabic, Farsi, Urdu). The panel's position was always calculated from the trigger's left edge regardless of text direction, which could run language options off the right edge of the viewport instead of opening from the trigger's leading edge the way the classic dropdown's RTL styling already does. The panel now detects the resolved text direction and anchors from the right edge in RTL, matching the dropdown's existing `[dir="rtl"]` behaviour. (`class-lsflr-switcher.php`)

= 2.5.4 =
* Added: "Trash + Siblings" — a new row action on the Posts/Pages/CPT admin list tables (next to Edit | Quick Edit | Trash | View) that trashes a post together with every other language version in its translation group, and a matching "Move to Trash (incl. translations)" bulk action. Both only appear when a post actually has translated siblings, act immediately (no confirmation prompt, matching the stock "Trash" link's own reversible behaviour), and report a "Trashed N posts (including translations)" notice afterward. Skips the static front page / posts page and any post the current user can't delete, reporting them as skipped rather than failing silently. Two new hooks for integrations: `linguaforge_trash_cascade_post_ids` (filter the group before it's trashed) and `linguaforge_trash_cascade_complete` (fires after, with the trashed/skipped ID lists). (`language-router/includes/translation/class-trash-cascade.php` NEW)
* Added: `linguaforge_trash_translation_group( $post_id, $check_caps = false )` — public function for integrations that want the same cascading-trash behaviour from their own code, not through wp-admin. Defaults to not requiring `current_user_can()`, matching `linguaforge_trigger_translation()`'s existing convention, since a REST endpoint or CLI command calling in often has no logged-in WP user at all. Pass `true` to require it instead. (`language-router/language-router.php`)

= 2.5.3 =
* Added: Automatic missing-translation backfill. Previously, if a queued translation (Action Scheduler / WP-Cron job) timed out, errored, or was otherwise lost, the resulting gap was silent — nothing ever revisited it, and an admin only found out by noticing a missing language switcher entry or by running the `missing_translations` / `fill_translations` WP-CLI commands by hand. A new hourly scan re-derives the same "which posts are missing which active language" check those CLI commands compute and re-queues just the missing (post, language) pairs through the normal async pipeline, up to 25 jobs per run. Each queued job's outcome (success or failure) is now recorded on the source post, so a pair that fails 5 times in a row is left alone for 24 hours before one more automatic retry — enough for a fixed API key or an ended provider outage to recover on its own without hammering a structurally broken case every tick. The schedule itself is checked on every admin request, not just on activation, so it self-heals if the cron event is ever dropped (SFTP/rsync deploy, host cron reset, etc.). The manual CLI commands are unchanged and still work for an immediate, on-demand check. (`ai/includes/Features/TranslationBackfill.php` NEW, `ai/includes/Features/TranslationQueue.php`, `ai/ai.php`, `lingua-forge.php`)

= 2.5.2 =
* Fixed: The Language Switcher could render nothing at all on a "Your latest posts" front page, even with every language correctly configured. A stray, untranslated post (WordPress's own default "Hello world!" sample, or any other leftover post — not specific to WooCommerce or any post type) could get silently picked up as "the current post" via `get_the_ID()` on a non-singular request, and since it had no translation group the switcher hid itself entirely, even though the site's real content was fully translated. `get_the_ID()` is now only trusted when `is_singular()` is actually true; a non-singular front page falls through to the existing per-language URL fallback instead. (`class-lsflr-switcher.php`)

= 2.5.1 =
* Fixed: The Danger Zone "Uninstall {LANG}" action on Settings → Router appeared to do nothing — the deletion actually ran, but the redirect afterward pointed at `wp-admin/options-general.php`, a URL the plugin's settings page (a top-level admin menu page) doesn't live under, so WordPress silently fell back to the default Settings → General screen with no success notice. The same wrong-redirect bug also affected saving Router settings and flushing permalinks from the same tab. All three now redirect to the actual settings page and show their confirmation notice. (`RouterTab.php`)
* Fixed: Uninstalling a language could leave it only partially removed, for three independent reasons — CPT Block Pattern translations lived in a single option rather than as posts and were invisible to the uninstall's postmeta query; custom translation files copied into the Maintenance tab's "Loco Translate — Copy to Safe Storage" location were never scanned for removal; and for languages where WordPress's own locale slug isn't the 2-letter code this plugin assumed everywhere (e.g. Yoruba's real slug is the 3-letter `yor`, not `yo`), uninstall could report success while the language stayed active forever. All three fixed: pattern translations are now purged and counted in the success notice, the Loco safe-storage directory is now scanned too, and a new `Context::lang_from_locale()` replaces the lossy 2-character truncation everywhere it appeared, so any of WordPress's roughly two dozen bare 3-letter-only locale codes resolves correctly. Internal routing/URLs/postmeta are unaffected — no site's existing URLs or stored data change. (`PatternDiscovery.php`, `LanguageUninstaller.php`, `class-context.php`, `SystemPanel.php`, `RouterTab.php`, `Translation.php`)
* Fixed: The admin-bar "Preview Language" switcher could show two languages checked at once (and label the wrong one as current) — confirmed with Yoruba added as an active language, which had no locale mapping and silently collided with English's own locale. Added the missing mapping (plus five others found via audit: hi, ur, th, sw, km, eu) and made the switcher compare against a single resolved "current language" value instead of re-deriving it per item, so this class of bug can't recur even for a future unmapped language. (`class-locale-detector.php`, `class-meta-boxes.php`)
* Fixed: hreflang tags, og:locale, the "Preview Language" label, and browser-language auto-detection didn't understand WordPress's bare 3-letter locale slugs. A new `Context::iso_639_1_from_lang()` normalises the handful of affected languages (Yoruba, Belarusian, Dzongkha, Kyrgyz, Occitan, Sindhi, Tahitian, Aragonese) to their real ISO 639-1 code for these outbound-facing uses only, without touching internal routing/URLs/postmeta. (`class-context.php`, `class-locale-detector.php`, `class-schema-manager.php`, `class-seo-manager.php`)

= 2.5.0 =
* Added: Support for "Your latest posts" as the site's front page (Settings → Reading) — translated homepages now live at `/es/`, `/fr/`, etc., alongside the existing static-front-page support. Includes: language-scoped post listing on the latest-posts front page (previously all languages appeared mixed); a "Blog Home" entry in the FSE template scaffold list so the latest-posts template can be translated per language; automatic `home-{lang}` template selection at runtime; a redirect from `/` to the language-prefixed root for returning visitors whose detected language differs from the site's source language; and a synthetic per-language homepage entry (with hreflang alternates) in the XML sitemap. (`class-query-filter.php`, `class-front-page-query.php`, `class-redirector.php`, `class-sitemap-manager.php`, `TemplateDefinitions.php`)
* Added: Translated posts, pages, and CPTs now get their featured image copied from the source post automatically when the translation is created — none of the 3 built-in translation paths did this before. Skipped for WooCommerce products (already served live from the source via `MetaDelegate`) and when an integration's `linguaforge_translated_post_meta` filter already supplied one. A new "Fix Featured Images" bulk-fix button (next to "Fix Links" in the Posts/Pages/CPT admin list toolbar) retroactively fixes existing translations missing or out of sync with their source's featured image. Gallery images are unaffected — they live in post content, which is already translated. (`TranslationTrigger.php`, `AbstractTranslateCommand.php`, `PostListColumn.php`, `class-lsflr-featured-image-fixer.php`)
* Added: Language lists in translate-action UIs (Retranslate dropdown, AI Translate-to dropdown, Quick Translate popover, Translations meta box) are now sorted alphabetically by language code instead of following arbitrary database/discovery order. (`PostListColumn.php`, `MetaBox.php`, `AdminToolbar.php`, `class-meta-boxes.php`)
* Fixed: A translated post of a custom post type with its own rewrite slug (e.g. `/art/some-artwork/`) 404'd once language-prefixed (`/es/art/some-artwork/`) — only the CPT's archive had a language-prefixed inbound rewrite rule, not its single-post permalink. A new rule closes this for every public, non-hierarchical CPT with a custom rewrite slug. **Re-save Settings → Permalinks once after updating** to pick up the new rule. (`class-manager.php`)
* Fixed: The fix above could silently never register at all, no matter how many times permalinks were re-saved — the rule-building code ran on `init` at the default priority (10), the same priority almost every plugin/theme uses to register its own custom post types, so whether a given CPT was visible yet was a matter of unpredictable same-priority ordering. Now runs at priority 20, guaranteeing CPTs registered at the default priority are already visible. (`class-manager.php`)
* Fixed: The Language Switcher (and anything calling `get_permalink()`) rendered custom-post-type links without their language prefix — `get_permalink()` for a CPT applies the `post_type_link` filter, not `post_link`/`page_link`, which were the only ones hooked. Now also hooked, excluding WooCommerce products which intentionally keep a single language-neutral URL. (`class-manager.php`)
* Fixed: A theme with `home.html` but no `front-page.html` could render the theme's generic fallback content instead of real content on secondary-language homepages — "Front Page" is no longer offered for scaffolding (and no longer preferred at runtime) unless the active theme actually ships a base `front-page.html`. (`TemplateDefinitions.php`, `class-front-page-query.php`, `class-sync.php`)
* Fixed: Language Switcher block could produce a double-slash URL (e.g. `/fr//`) when linking to the homepage of a site using "Your latest posts" — the link still worked (an extra redirect resolved it) but wasn't clean. (`class-lsflr-switcher.php`)
* Fixed: hreflang alternates and the canonical tag both duplicated the language prefix (e.g. `/fr/fr/`) on a bare language-root homepage request — the lang-stripping logic required a trailing slash that wasn't there once the path reduced to just the lang code. (`class-hreflang.php`)

= 2.4.2 =
* Added: Language Switcher — new "Icon color" block setting (Inspector, when display mode is "Icon only" or "Icon + language"), using a theme-palette-aware colour picker. Lets you override the icon's colour for sections whose background is set locally rather than via the theme's global style, where the switcher's automatic contrast colour can otherwise end up matching the background. (`class-lsflr-switcher.php`, `editor-switcher.js`)
* Fixed: Language Switcher — Grid Overlay's "Auto" list style could silently override an "Icon only" display and show the current language as a plain text link instead. On any page where secondary languages are configured but have no translated content yet, only one language is available to switch to; the width heuristic that decides when to auto-expand used that count directly, so it was almost always satisfied and hid the icon trigger in favour of the (now pointless) self-referential text link. The heuristic no longer runs when there's nothing to switch to. (`class-lsflr-switcher.php`)
* Changed: Grid Overlay's language panel no longer lists the current language alongside the other languages — it now shows only the languages you can switch to, matching the classic dropdown's existing behaviour. (`class-lsflr-switcher.php`, `lsflr.css`)

= 2.4.1 =
* Fixed: IndexNow key-file submissions could fail with 403 even though the file loaded fine in a browser. The key-file URL never matches a real post/page/rewrite rule, so WordPress had already set an HTTP 404 status before the plugin's handler ran — the correct key was served, but under a 404 status line that browsers render fine but that `key_file_reachable()` and real IndexNow crawlers correctly reject. The response now explicitly sends status 200. (`class-indexnow-manager.php`)
* Fixed: Sitemap chunk files (`/lf-sitemap-{N}.xml`) could go undiscovered by Google for the same reason — served with a correct XML body under an inherited HTTP 404 status, which Search Console rejects regardless of body content. The response now explicitly sends status 200. The sitemap index (`/lf-sitemap.xml`) was not affected. (`class-sitemap-manager.php`)

= 2.4.0 =
* Added: `linguaforge_queue_translation()` — a non-blocking companion to `linguaforge_trigger_translation()` that runs a translation off-request via Action Scheduler (when available) or WP-Cron. Lets programmatic publishers translate into many languages without making blocking AI calls inline. (`ai/ai.php`, `ai/includes/Features/TranslationQueue.php`)
* Added: `linguaforge_translated_post_meta` filter — lets an integration declare the post meta a programmatically-created translated post is born with (featured image, gallery, custom fields), written via `meta_input` so the translation is complete the moment it exists. WooCommerce operational keys remain delegated by MetaDelegate. (`ai/includes/Features/TranslationTrigger.php`)
* Fixed: A first-time translated post now keeps its translated excerpt — it was discarded on creation, so the meta description fell back to a trimmed slice of the content. The create path now writes `post_excerpt` from the AI's `translated_excerpt`, matching the update path. (`ai/includes/Features/TranslationTrigger.php`)

= 2.3.3 =
* Fixed: Language, Template, and Translations meta boxes are no longer displayed for post types that have been excluded from Lingua Forge routing via Settings → System. (`language-router/includes/admin/class-meta-boxes.php`)

= 2.3.2 =
* Changed: IndexNow submission is now asynchronous — publishing or updating a translated post no longer blocks on the outbound IndexNow request. The save handler schedules a single WP-Cron event and the HTTP POST runs in the background; rapid re-saves of the same post are debounced. Manual "Submit all URLs" from the Sitemap panel stays synchronous. (`class-indexnow-manager.php`)
* Changed: AI-module diagnostic logging is now gated behind WP_DEBUG (via a new shared logger), so production sites no longer accumulate AI request/translation diagnostics in debug.log. (`ai/includes/Core/Log.php`)
* Fixed: The IndexNow verification key is no longer generated during a front-end request. Key-file serving now reads the key without writing it; the key is created only in admin / submission contexts. (`class-indexnow-manager.php`)
* Fixed: On sites with a persistent object cache, a sitemap chunk evicted independently of the sitemap index is now regenerated on demand instead of serving an empty list. (`class-sitemap-manager.php`)
* Fixed: WooCommerce order email language is now cleared after each status transition, so the language of one order's confirmation email can no longer leak to another order during bulk admin status changes. (`WcOrderLang.php`)
* Fixed: On paginated singular content (multipage posts using <!--nextpage--> or paginated comments), the canonical and hreflang tags now point at the actual page being viewed instead of page 1. (`class-hreflang.php`)

For the full changelog see https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 2.6.5 =
Fixes a stale AI model suggestion list and a deprecated-parameter error on newer Claude models, and adds a "Test model" button to verify a model override before saving it. No database changes. No flush required.

= 2.6.4 =
Fixes missing excerpts/WC variation children on first translations, uninstall gaps (locale files, options/cron sweep), missing per-target Sync permission checks, FSE Re-create's theme-scoping, and a WP AI Client refine-request crash. No database changes. No flush required.

= 2.6.3 =
Automatic Translation Backfill (hourly AI translation of missing content) is now off by default with a new Settings → Behavior toggle — it previously ran unconditionally. No database changes. No flush required.

= 2.6.2 =
Adds Re-create (single + bulk, plus a cross-language "Recreate All Languages" run) for FSE templates and template parts. No database changes. No flush required.

= 2.6.1 =
Fixes translated posts not getting their language-specific template on creation and when refreshed via Sync/Translate missing, adds a filter to override it, and adds a no-AI-cost "Template Sync" button. No database changes. No flush required.

= 2.6.0 =
Adds a "Sync" button (with two off-by-default safeguards, one for WooCommerce products) in the post list Lang column that retranslates every other language from the post you click it on. No database changes. No flush required.

= 2.5.5 =
Fixes the Language Switcher's Grid Overlay panel opening off-screen to the right on RTL-language pages. No database changes. No flush required.

= 2.5.4 =
Adds a "Trash + Siblings" row action and bulk action to trash a post and all its translated versions together from the Posts/Pages list. No database changes. No flush required.

= 2.5.3 =
Adds automatic hourly backfill for missing translations — a queued translation that timed out or failed is now retried automatically instead of sitting silently missing. No database changes. No flush required.

= 2.5.2 =
Fixes the Language Switcher going blank on a "Your latest posts" homepage when an untranslated stray post exists. No database changes. No flush required.

= 2.5.1 =
Fixes Danger Zone redirect failures, language uninstall bugs (including a WordPress locale-code mismatch that made some languages permanently unremovable), and the admin-bar switcher double-checking languages. No database changes. No flush required.

= 2.5.0 =
Adds "Your latest posts" homepage support and automatic featured-image copying for translations. Also fixes translated custom-post-type permalinks 404ing. Re-save Settings > Permalinks once after updating.

= 2.4.2 =
Fixes Grid Overlay "Auto" mode showing the current language as text instead of the configured icon when secondary languages are untranslated, and excludes the current language from the Grid Overlay panel to match the dropdown. No database changes. No flush required.


