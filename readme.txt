=== Lingua Forge ===
Contributors: ulih
Tags: multilingual, translation, ai, seo, meta-description
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 2.1.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual routing, SEO meta descriptions, and AI-powered content tools — all in one plugin for block-theme sites.

== Description ==

Lingua Forge is a free, permanently open-source multilingual plugin for WordPress. There is no paid tier, no annual license fee, and no subscription. AI features are powered by the API key of your choice (Anthropic, OpenAI, or Google Gemini) — you pay the provider directly at standard API rates, with no markup and no proprietary credit system in between. Every AI feature has a fully usable manual fallback, so the plugin works without any API key at all.

Lingua Forge is for sites that publish content in more than one language and want AI assistance built into the editorial workflow — without a paid third-party subscription service or a complex multi-plugin stack.

It brings together three concerns that always end up intertwined on multilingual WordPress sites: language routing, SEO meta output, and AI-assisted editorial work. Instead of coordinating three separate plugins that each make assumptions about the others, everything ships as a single installable package with a shared foundation.

**Built on WordPress, not around it**

Lingua Forge stays as close to WordPress core and Full Site Editing conventions as possible. Translations are native WordPress posts. FSE templates, template parts, and navigations are native `wp_template`, `wp_template_part`, and `wp_navigation` posts — not string-swapped versions of a shared entity. No runtime dependencies ship with the plugin, no parallel data layer, no render-time interception. Block API v3 throughout, no jQuery on the frontend, REST routes at `rest_api_init`, standard WordPress i18n and security conventions without exception.

**Language Router**

Detects the active language from URL prefixes (`/de/`), query parameters, or a cookie, and keeps all routing, hreflang, and admin UX in sync.

* Language-prefixed URLs and category archives with automatic rewrite rules
* Post and page translation groups linked via a shared TRID (UUID)
* Outdated-translation tracking — flags content that was updated after its translations were last synced
* Full FSE template localisation — language-specific templates (`page-de`, `single-fr`, `search-en`) auto-assigned when a post's language is set. Settings → Router provides a complete scaffold → AI-translate → fix links → fix parts → fix nav workflow: create a language copy of any template or template part, AI-translate it, fix internal links, fix template-part slug references, and fix wp:navigation ref IDs — all without CLI or manual database work
* Language-specific template parts — scaffold, AI-translate, fix links, and fix navigation references for `header-{lang}`, `footer-{lang}`, and any template part. Each is an independent native `wp_template_part` post
* Language navigation menus — create per-language `wp_navigation` copies with AI-translated labels and language-prefixed URLs
* hreflang tags for singular, archive, and paginated views; suppresses duplicate output from Yoast SEO, Rank Math, AIOSEO, and SEOPress automatically
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

= Can I use Lingua Forge without an AI subscription? =

Yes. The Language Router (URL-based language routing, hreflang injection, language switcher block, FSE template routing) and the Link Fixer work with no API key at all. The AI features — translation, meta description generation, and content generation — are optional enhancements. Simply leave the API key fields empty and the plugin will function as a pure language-routing and multilingual management tool.

= What happens to my content if I deactivate or uninstall the plugin? =

**Deactivating** stops routing and AI features but leaves all data intact — your posts, settings, and meta fields are untouched. Reactivating picks up where you left off.

**Uninstalling (deleting)** always removes plugin settings, API keys, transients, and the AI result cache. By default, language assignments (`_lang`), translation relationships (`_trid`), meta descriptions, the AI glossary, and Translation Memory are **kept** — so a reinstall can pick up where it left off without losing any editorial work. To also remove that data, enable **Settings → Maintenance → Delete content data on uninstall** before deleting the plugin. The translated posts themselves are ordinary WordPress posts and are never deleted regardless of this setting.

= Does this work with classic (non-block) themes? =

Most features work with any theme. Language routing, hreflang injection, the AI meta box, and meta description generation are theme-agnostic. For classic themes the language switcher is available as the `[lsflr_switcher]` shortcode (paste into any post, page, or widget area that supports shortcodes) or as the **Language Switcher** classic widget under Appearance → Widgets. Both produce the same output as the Gutenberg block.

= Does Lingua Forge require any theme preparation? =

For full multilingual operation with block (FSE) themes, each language needs its own set of templates and patterns (e.g. `page-de.html`, `single-fr.html`). Lingua Forge routes incoming requests to these templates automatically, but the templates themselves must exist in your theme first.

As of 1.6.0, Lingua Forge handles this natively: the **Settings → Router** page lets you scaffold a language copy of any template or template part, AI-translate it in one click, and fix internal links, template part references, and navigation menu references — all without editing theme files directly.

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

Managed hosting plans often cap PHP execution time at 30–60 seconds. Lingua Forge uses a 120-second timeout for AI API calls, but PHP kills the process first if the server limit is lower. Fix options: add `set_time_limit( 180 );` to `wp-config.php`, add `php_value max_execution_time 180` to `.htaccess`, or ask your host to raise the limit. As a workaround without server changes, use **Chunk mode** to translate individual blocks rather than the full page at once.

= The AI returns "generation failed" with no explanation. =

Check the PHP error log — Lingua Forge writes the raw provider response there whenever a call fails. The most common causes are an invalid or expired API key, hitting the provider's rate limit, or a temporary provider outage. Verify your key in **Settings → Lingua Forge → API Keys** and test it in the provider's own dashboard.

= The Quick Translate button appears twice in the editor toolbar. =

This intermittent duplication was fixed in 1.4.0. The button injection logic now removes any stale buttons from lower-priority containers before inserting into the winning container, so at most one icon is ever shown. If you see duplication on an older version, a single page reload (F5) clears it. The Admin Toolbar Quick Translate is separate and unaffected.

= The meta description generator uses the old content after I apply a translation. =

Clicking "Apply to Editor" now triggers an automatic save. If the save succeeds (button shows "Saved ✓") the meta description generator will read the translated content. If the auto-save fails, save the post manually before generating the meta description.

= Do I need a permalink structure other than Plain? =

Yes. Language URL prefixes (`/de/`, `/fr/`, etc.) require WordPress to use pretty permalinks. Go to **Settings → Permalinks** and choose any option except **Plain**.

= My language navigation shows pages from all languages, not just the current one. =

This happens when the navigation uses the **Page List** block — WordPress's default before the user manually edits the menu. The Page List block calls `get_pages()` directly with no filterable query arguments, so language-based filtering is not currently possible. This is a WordPress core limitation.

**Workaround:** Open the navigation in the Site Editor (Appearance → Editor → Navigation), select the affected language navigation, and click **Edit** to convert the Page List to individual static links. Then go to **Settings → Router → Fix Links** to ensure each link points to the correct language version. Static-link navigations are fully language-aware.

A proper fix is planned for a future release.

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

When an administrator uses the AI translation, generation, or revision features, the relevant post content is sent to the configured third-party AI provider. See the External Services section below for details on which providers are used and what data is transmitted. No content is sent automatically or without administrator action.

= Data stored on your server =

The plugin stores the following data in your WordPress database:

* Encrypted API keys in `wp_options`.
* AI cache entries (post content hashes and translated output) in a custom table. Cleared via Settings → Maintenance → Clear AI Cache or on plugin uninstall.
* Translation Memory entries (block-level translated content) in a custom table. Cleared via Settings → Maintenance or on uninstall.
* AI usage statistics (token counts per date, user, feature, provider) in a custom table. No personally identifiable information beyond the WordPress user ID. Dropped on uninstall.
* Language metadata (`_lang`, `_trid`, `_lf_trans_*`) stored as post meta on multilingual posts.

All custom tables and plugin-specific options are removed on uninstall.

== External Services ==

This plugin connects to third-party AI APIs to generate and translate content. Connections are only made when an administrator has configured an API key and a user explicitly triggers an AI feature (Generate, Translate, etc.). No data is sent automatically or in the background.

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

== Developers ==

The plugin is developed against WordPress Coding Standards (PHPCS + WPCS 3.1), passes PHPStan level 5 with WordPress stubs, and is verified clean by the official WordPress Plugin Check tool. JavaScript and CSS are linted via ESLint and Stylelint (@wordpress/scripts). A PHPUnit test suite (unit + integration) ships alongside the source. Source code and contributing guide at https://github.com/leotiger/lingua-forge.

== Changelog ==

= 2.1.0 =
* Refactored: RouterTab god class (2,015 lines) split into focused classes — seven FseLocalisation\* handlers (TemplateDefinitions, PartDiscovery, PatternExpander, ScaffoldHandler, TranslateHandler, LinkFixer, PartRefFixer) and three Sections\* render classes (TemplatesSection, TemplatePartsSection, NavigationsSection). RouterTab is now ~350 lines of tab plumbing and language-pack UI only.
* Added: CPT-specific FSE template scaffold slots — single-{cpt} and archive-{cpt} rows appear automatically in the Language Setup table for any public CPT whose base template is shipped by the active theme.
* Added: CPT-scoped block pattern translation — new Patterns section in the Router tab AI-translates patterns scoped to public CPTs and stores the results for copy-paste into CPT posts.
* Added: Loco Translate integration — Settings > Maintenance > Language Overrides now lists Loco Translate custom files and provides one-click copy into the Lingua Forge durable i18n-overrides directory.

= 2.0.1 =
* Fixed: Translate / Review panel now closes automatically when the user focuses a different block. Previously the panel stayed open after switching blocks, requiring a manual dismiss.

= 2.0.0 =
* Added: Custom Post Type support (Phase 0) — all public CPTs now receive the full Lingua Forge admin layer: Lang column, language and outdated-status filter dropdowns, quick-edit language control, AI translation metabox, FSE template selector, Translation Memory eligibility, and link-fixer scan. New opt-out filters: linguaforge_column_post_types, linguaforge_ai_metabox_post_types, linguaforge_link_fixer_post_types.
* Added: FSE template auto-assignment for CPTs using single-{post_type}-{lang} naming (e.g. single-product-de).
* Added: WooCommerce integration Phase 1 (shared-stock delegation model) — translated products carry only content fields; all operational data (price, SKU, stock, dimensions, images, variations, taxonomy assignments) is read transparently from the source-language product at runtime. Five new classes: MetaDelegate, StockRouter, VariationDelegate, TaxonomyDelegate, CatalogQuery.
* Added: WooCommerce integration Phase 1b (translated term names) — category, tag, product-type, and attribute term names display in the visitor's language via _lf_term_name_{lang} termmeta. Editable from the term add/edit screens (Products → Categories, Tags, Attributes). New classes: TermNameFilter, TermNameAdmin.
* Added: linguaforge_cpt_create_allowed filter — allows integrations to block translated-post creation until their delegation layer is active. Defaults to true.
* Added: linguaforge_wc_delegate_post_types filter — controls which post types participate in operational-meta delegation and stock-write routing.
* Added: linguaforge_wc_integration_active action — fires after the WooCommerce integration initialises for the current request.
* Added: Third-party integration API — five new hooks: linguaforge_loaded (fires after router boot; use instead of plugins_loaded for integrations), linguaforge_translation_content filter (modify AI payload before caching), linguaforge_translation_complete action (CLI/programmatic translation saved), linguaforge_trid_changed action (post joined or left a translation group), linguaforge_switcher_output filter (wrap or replace switcher HTML). Two public REST endpoints: GET /wp-json/lingua-forge/v1/languages and GET /wp-json/lingua-forge/v1/post/{id}/translations. New public PHP function linguaforge_trigger_translation() for programmatic translation. Full documentation in CONTRIBUTING.md.
* Added: Classic theme language switcher — [lsflr_switcher] shortcode and Lsflr_Switcher_Widget (Appearance → Widgets) make the language switcher available on any theme, no block widget area required.

For the full changelog see https://github.com/leotiger/lingua-forge/blob/main/CHANGELOG.md


== Upgrade Notice ==

= 2.1.0 =
Internal refactor only — no user-facing changes, no schema changes. Safe to update in place.

= 2.0.1 =
UX fix: Translate / Review panel now closes automatically on block focus change.

= 2.0.0 =
Custom Post Type support, WooCommerce Phase 1 + 1b, classic theme language switcher (shortcode + widget), and third-party integration API. No schema changes — safe to update in place.


