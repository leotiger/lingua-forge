=== Lingua Forge ===
Contributors: ulih
Tags: multilingual, translation, ai, seo, meta-description
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 1.7.0
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
* Language Switcher block (dropdown or dropup, fully customisable)
* Admin link fixer — finds internal links pointing to the wrong language version and repairs them in bulk

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
* **WP-CLI** — `wp linguaforge translate`, `wp linguaforge retranslate`, and `wp linguaforge cache-clear` for scripted and automated workflows

API keys are stored encrypted (AES-256-GCM with provider slug as authenticated data, derived from WordPress auth salts). Model endpoints are configurable from Settings with no code changes needed when a new model version ships.

Source code and issue tracker: https://github.com/leotiger/lingua-forge

== Installation ==

1. Upload the `lingua-forge` folder to `wp-content/plugins/`.
2. Activate **Lingua Forge** from **Plugins → Installed Plugins**.
3. Go to **Settings → Permalinks** and click **Save Changes** — this registers the language URL prefixes.
4. Go to **Settings → Lingua Forge**, select an AI provider, and enter your API key.

**Migrating from mu-plugins:** if you were running Language Router, Meta Description, or WPEnhance AI as must-use plugins, deactivate or remove those files before activating Lingua Forge to avoid duplicate hooks. Existing post meta (`_lang`, `_trid`, `meta_description`) and the `my_lang_filter` user preference are migrated automatically on first activation.

== Frequently Asked Questions ==

= Can I use Lingua Forge without an AI subscription? =

Yes. The Language Router (URL-based language routing, hreflang injection, language switcher block, FSE template routing) and the Link Fixer work with no API key at all. The AI features — translation, meta description generation, and content generation — are optional enhancements. Simply leave the API key fields empty and the plugin will function as a pure language-routing and multilingual management tool.

= What happens to my content if I deactivate or uninstall the plugin? =

**Deactivating** stops routing and AI features but leaves all data intact — your posts, settings, and meta fields are untouched. Reactivating picks up where you left off.

**Uninstalling (deleting)** always removes plugin settings, API keys, transients, and the AI result cache. By default, language assignments (`_lang`), translation relationships (`_trid`), meta descriptions, the AI glossary, and Translation Memory are **kept** — so a reinstall can pick up where it left off without losing any editorial work. To also remove that data, enable **Settings → Maintenance → Delete content data on uninstall** before deleting the plugin. The translated posts themselves are ordinary WordPress posts and are never deleted regardless of this setting.

= Does this work with classic (non-block) themes? =

Most features work with any theme. Language routing, hreflang injection, the AI meta box, and meta description generation are theme-agnostic. The Language Switcher block requires a block theme or a block-ready widget area. For classic themes, use the `[linguaforge_switcher]` shortcode or call `lsflr_language_switcher()` directly in a template file.

= Does Lingua Forge require any theme preparation? =

For full multilingual operation with block (FSE) themes, each language needs its own set of templates and patterns (e.g. `page-de.html`, `single-fr.html`). Lingua Forge routes incoming requests to these templates automatically, but the templates themselves must exist in your theme first.

As of 1.6.0, Lingua Forge handles this natively: the **Settings → Router** page lets you scaffold a language copy of any template or template part, AI-translate it in one click, and fix internal links, template part references, and navigation menu references — all without editing theme files directly.

= Can I use Lingua Forge alongside WPML or Polylang? =

Not recommended — all three handle language routing at the URL and content level, and running them in parallel will produce conflicts. Lingua Forge is a replacement, not an add-on. If you are migrating, disable WPML or Polylang before activating Lingua Forge. Post relationships from those plugins are not auto-imported; use the Translation meta box in the post editor to re-link translated posts after migrating.

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

= 1.7.0 =
* Added: Subdomain routing mode — languages can now be served from subdomains (de.example.com, fr.example.com) instead of path prefixes (example.com/de/). Select the URL structure in Settings → Lingua Forge → Router → URL structure. Requires wildcard DNS and TLS on the server side; no WordPress configuration beyond the setting. Source language always serves from the root domain. Cookie scoping, permalink generation, hreflang output, language switcher, and the link-fixer scan are all subdomain-aware. The lf_base_domain filter is available to override the auto-derived base domain when home_url() includes www.
* Added: Classic navigation menu auto-add guard — a publish_page hook (priority 11) removes any just-inserted menu item whose page is a non-source-language translation, preventing translated pages from appearing in source-language classic menus. Applies to classic nav menus (wp_nav_menu) only; FSE wp_navigation posts are unaffected.
* Fixed: Language switcher rendered empty on non-singular pages (archives, category, tag, author, blog index) — get_the_ID() returns 0 there, causing get_languages() to return an empty array even when the block was present in a shared header or footer template. A URL-rewrite fallback now builds the language list from all configured languages when no post ID is available.
* Fixed: Translate Navigation generated wrong URLs in subdomain routing mode — internal page URLs were rewritten using path-prefix logic (/de/contact/) instead of subdomain URLs (de.example.com/contact/). Now uses lang_base_url() for host-based rewriting in subdomain mode.
* Fixed: Source-language pages were redirected to the wrong language when a stale cross-language cookie was present. In path mode, a non-prefixed URL is now treated as an authoritative source-language signal and bypasses cookie detection. The homepage is unaffected.
* Fixed: Fix Navigation References now correctly handles source-language template parts and wrong-language navigation references — source-language targets were previously rejected; wrong-language nav references produced double-suffixed slugs (e.g. navigation-it-de). Base name derivation now reads _lf_lang meta from the referenced navigation post.
* Fixed: Language switcher SVG globe icon collapsed to zero size — was sized via a theme-specific CSS variable undefined on most sites. Replaced with a generic 1.2em rule using currentColor.
* Fixed: Language switcher dropdown overflowed the viewport on right-aligned placements. Inline JS now detects overflow via getBoundingClientRect on load and resize and flips the panel to open right-to-left when needed.
* Fixed: Language switcher showed a white background with invisible text on dark-themed sites. CSS variables now inherit FSE global-style color tokens (--wp--preset--color--base / --contrast) with CSS system-color fallbacks (Canvas / CanvasText) that track OS light/dark preference.
* Maintenance: build-zip.sh now normalises file permissions after rsync (0755 directories, 0644 files) before creating the ZIP.

= 1.6.5 =
* Fixed: ajax_fix_fse_links() stale-path links not updated in template parts — links that already carried the correct language prefix but whose slug had changed were never repaired. A second pass via LinkFixer::fix_post() now runs after the prefix-rewrite save, using data-id as ground truth. Covers footers, headers, sidebars, and any wp_template_part.
* Maintenance: .distignore — .github/ added (workflow directory was missing and would have been included in SVN submission); docs/ added for screenshots, banner, and icon pushed to SVN assets/ by the deploy workflow.
* Maintenance: filter_locale_for_vik_booking() renamed to filter_locale() — the locale filter hook is generic and covers any plugin that reads locale directly instead of determine_locale. Docblock added.
* Maintenance: All ->debug() call sites removed from language-router sub-classes (QueryFilter, Sync, Query, Redirector, Hreflang) — leftover from the mu-plugin era, flooding debug.log on every request. The debug() method and linguaforge_debug() wrapper are retained.
* Maintenance: Router::debug_system_init() and debug_request_context() removed along with their add_action registrations — fired on every frontend page load whenever WP_DEBUG was on.

= 1.6.4 =
* Fixed: tests/bootstrap.php autoload path corrected to dev/vendor/autoload.php — previously pointed at a non-existent vendor/ in the plugin root; silent failure masked by PHPUnit's own autoloader.
* Improved: register_meta in the Meta Description module is now gated on is_admin(), REST_REQUEST, and WP_CLI — skipped entirely on anonymous front-end requests where it was pure dead weight.
* Improved: all update_option() calls for linguaforge_flush_rewrite_rules now pass autoload = false — option is short-lived and does not belong in the autoloaded options blob.
* Documentation: README.md gains a Roles and capabilities section documenting the two-tier capability model — linguaforge_required_capability gates editor AI operations; FSE template and navigation operations always require manage_options regardless of that setting.

= 1.6.3 =
* Fixed: Language-prefix regex now matches multi-character locales (zh-tw, zh-hant, pt-br, …) — three [a-z]{2} hardcodes in Redirector replaced by a dynamic lang_regex() helper built from the configured locale list.
* Fixed: Frontend AJAX — POST requests were silently sent without the lang parameter because the old jQuery ajaxSend interceptor appended it to the POST body, which detect_lang_safe() never reads. The script is rewritten without jQuery: XMLHttpRequest.prototype.open and window.fetch are patched to append ?lang=X to the URL query string of same-origin requests. jQuery is no longer a script dependency.
* Added: missing-translation-notice block now has a full editor component — sidebar controls (notice message, home-link toggle, home-link text) and a live ServerSideRender canvas preview. index.js + index.asset.php added; block.json gains editorScript.
* Maintenance: CONTRIBUTING.md — LINGUAFORGE_SECRET cross-environment guidance added to the API keys section: explains shared-salt risk across environments and includes a define() snippet.
* Maintenance: README.md — matching LINGUAFORGE_SECRET paragraph added to the API keys section.
* Maintenance: CONTRIBUTING.md — pre-1.3.x debug directory migration note added to "Things worth knowing".
* Maintenance: README.md + readme.txt — WordPress-core and FSE conformance section added.

= 1.6.2 =
* Fixed: handle_singular_redirect() now skips non-public post types (wp_global_styles, wp_navigation, etc.) — previously these satisfied is_singular() and could produce cross-domain redirects when the object cache was poisoned (e.g. shared Redis without WP_CACHE_KEY_SALT).
* Fixed: get_translations() query now excludes non-public post types and auto-drafts — wp_template and other FSE-internal posts could appear as translation group members, causing the homepage redirector to send visitors to raw template slugs like /front-page-it/.
* Fixed: lang_permalink() short-circuits for non-public post types — prevents URL rewriting on internal WordPress post types that pass through post_link / page_link.
* Fixed: set_lang_cookie() now explicitly scopes the lf_lang cookie to the site's own domain (wp_parse_url( home_url(), PHP_URL_HOST )) instead of passing an empty domain string, preventing cookie bleed between sites sharing a server.

= 1.6.1 =
* Fixed: Translation Memory cache invalidation — Translation::compute_compliance_signature() was still reading the legacy linguaforge_compliance_addendum option (replaced in 1.5.0 by three per-preset options). Editing a per-preset addendum no longer invalidated the affected TM rows, so the cache served back translations produced under the previous preset rules. The signature now reads Config::preset_addendum( $preset ) and folds in the resolved per-post preset; existing TM rows become one-time misses on first encounter and are rewritten on the next translation.
* Fixed: MetaBox per-page preset picker — the "Global default (Custom)" indicator stopped surfacing after the 1.5.0 migration because the underlying check still read the legacy global option. Now compares the resolved per-preset addendum against the built-in default.
* Fixed: FSE-translate AJAX endpoints (Settings → Router → Translate / Translate all) bypassed the rate-limit and daily-quota gates that protect every other paid-AI endpoint. Both ajax_translate_fse_content and ajax_translate_fse_navigation now apply the same per-user sliding window and site-wide UTC daily ceiling via the new RateLimiter::gate_ajax_or_die() adapter. The linguaforge_ai_rate_limit and linguaforge_ai_daily_quota filters apply to two new endpoint keys: translate-fse-content and translate-fse-navigation.
* Added: LinguaForge\AI\REST\RateLimiter class — extracted from FeatureController so the REST endpoints and the new AJAX FSE-translate handlers share one limiter implementation.

= 1.6.0 =
* Added: FSE Template Localisation — Settings → Router now includes a Language Templates section. Scaffold language-specific FSE templates (page-ca, single-de, …) from base templates in one click, AI-translate their content, fix internal links, and update template-part slug references to language-specific variants (e.g. footer → footer-ca) via the new Fix Parts action.
* Added: Language Template Parts — scaffold, AI-translate, and fix internal links for language-specific template parts (header-ca, footer-de, …). A Fix Nav action rewrites wp:navigation ref IDs inside each part to point at the corresponding language-copy navigation post, resolving mismatched menus in the Site Editor.
* Added: Language Navigations — lists all base wp_navigation posts × secondary languages. Translate button creates a language-copy navigation post ({name}-{lang}) with AI-translated link labels and language-prefixed internal URLs. Re-translate updates the existing copy.
* Added: Pattern reference expansion — wp:pattern pointer blocks are resolved to their actual markup before any translation or fixing pass, ensuring patterns within templates and parts are fully processed.
* Added: PHPUnit unit test suite — RouterSingletonTest verifies the singleton reset contract in isolation without booting WordPress.
* Fixed: Primary language setting not persisting — Context::source_language() had two hardcoded 'ca' (Catalan) fallbacks dating from initial versions (get_option default and a ?: guard). Any primary language saved in Settings → Router was silently overridden back to Catalan on the next request. Both fallbacks removed; the saved value is now honoured correctly.
* Fixed: PHPStan — unreachable-statement false positives in ajax_fix_fse_links() resolved by reordering validation guards to match control-flow analysis expectations.
* Fixed: PHPCS — MissingTranslatorsComment on _n() call in ajax_fix_fse_parts() and slow_db_query warning in class-migrator.php.

= 1.5.1 =
* Added: Quick Translate — Create tab in the Admin Toolbar popover. Generate new content from instructions and key points, with tone selector (Informative, Persuasive, Storytelling, Technical, Conversational) and optional target language.
* Added: Quick Translate — Refine. After any Translate or Create result, an inline Refine row lets you iteratively improve the output with additional instructions. The model receives the original request and prior draft as context.
* Added: /create-chunk REST endpoint for free-form text generation without requiring a post ID. Rate-limited and quota-gated on the same policy as /translate-chunk.
* Changed: Per-preset editable addenda — the single global "Custom prompt instructions" field is replaced by three separate fields in Settings → Behavior, one for each non-standard preset (Technical/Scientific, Legal/Compliance, Creative/Marketing). Leave blank to use the built-in default; a built-in default preview is shown inline. Sites with a saved custom addendum are migrated automatically on first admin load.
* Changed: /translate-chunk now supports iterative refinement via refine_hint + previous_output params (multi-turn conversation).
* Fixed: PHP Fatal — namespace declaration in class-language-router.php was preceded by the ABSPATH guard, causing a fatal error on PHP 8.1+ ("Namespace declaration statement has to be the very first statement"). Guard moved to after the namespace line.
* Fixed: Quick Translate Translate and Create tab panes were both visible simultaneously. display:flex was overriding the browser's hidden attribute; explicit [hidden]{display:none} author rule added.

For the full changelog see CHANGELOG.md in the plugin repository.


== Upgrade Notice ==

= 1.7.0 =
Adds subdomain routing mode (de.example.com style). No schema changes. Existing path-prefix setups are unaffected — subdomain mode must be explicitly enabled in Router settings. Safe to update in place.

= 1.6.5 =
Maintenance release — removes leftover debug logging from the language-router module. No schema, settings, or behaviour changes. Safe to update in place.

= 1.6.4 =
Maintenance and micro-optimisation release. No schema or settings changes — safe to update in place. register_meta no longer fires on front-end requests; flush_rewrite_rules option writes are now autoload-free; tests bootstrap path corrected.

= 1.6.3 =
Correctness release. Multi-character locales (zh-tw, zh-hant, pt-br) now route correctly. Frontend AJAX lang detection fixed for POST requests. Missing-translation-notice block gains a full Site Editor component. No schema or settings changes — safe to update in place.

= 1.6.2 =
Defensive hardening release. Closes four edge cases around non-public post types and cross-site cookie bleed, surfaced by a shared-Redis misconfiguration (missing WP_CACHE_KEY_SALT). No schema or settings changes — safe to update in place.

= 1.6.1 =
Bug-fix release. Fixes Translation Memory cache invalidation after per-preset addendum edits and closes a rate-limit gap where 1.6.0 FSE-translate AJAX endpoints bypassed per-user and site-wide quota guards. No schema or settings changes.

= 1.6.0 =
Adds full FSE template localisation: scaffold, AI-translate, fix links, fix template-part slugs, and fix navigation refs for language-specific templates, template parts, and navigation menus directly from Settings → Router.

= 1.5.0 =
Adds Quick Translate Create tab and iterative Refine. Per-preset addenda replace the single global custom addendum field — existing values migrate automatically. Includes a PHP Fatal fix for class-language-router.php; update immediately if on 1.4.x.
