=== Lingua Forge ===
Contributors: ulih
Tags: multilingual, translation, ai, seo, meta-description
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 1.2.13
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual routing, SEO meta descriptions, and AI-powered content tools — all in one plugin for block-theme sites.

== Description ==

Lingua Forge is a free, permanently open-source multilingual plugin for WordPress. There is no paid tier, no annual license fee, and no subscription. AI features are powered by the API key of your choice (Anthropic, OpenAI, or Google Gemini) — you pay the provider directly at standard API rates, with no markup and no proprietary credit system in between. Every AI feature has a fully usable manual fallback, so the plugin works without any API key at all.

Lingua Forge is for sites that publish content in more than one language and want AI assistance built into the editorial workflow — without a paid third-party subscription service or a complex multi-plugin stack.

It brings together three concerns that always end up intertwined on multilingual WordPress sites: language routing, SEO meta output, and AI-assisted editorial work. Instead of coordinating three separate plugins that each make assumptions about the others, everything ships as a single installable package with a shared foundation.

**Language Router**

Detects the active language from URL prefixes (`/de/`), query parameters, or a cookie, and keeps all routing, hreflang, and admin UX in sync.

* Language-prefixed URLs and category archives with automatic rewrite rules
* Post and page translation groups linked via a shared TRID (UUID)
* Outdated-translation tracking — flags content that was updated after its translations were last synced
* Language-specific FSE templates (`page-de`, `single-fr`, `search-en`)
* hreflang tags for singular, archive, and paginated views; suppresses duplicate output from Yoast SEO, Rank Math, AIOSEO, and SEOPress automatically
* Language Switcher block (dropdown or dropup, fully customisable)
* Admin link fixer — finds internal links pointing to the wrong language version and repairs them in bulk

**Meta Description**

Adds a meta description field to every public post type and outputs `<meta name="description">`, `<meta property="og:description">`, and `<meta name="twitter:description">` on every page.

* Editable in the Classic meta box, fully compatible with the Block Editor
* Character counter with green/amber/red guidance (120–160 ideal range)
* Fallback chain: custom field → post excerpt → site description
* Stores descriptions under `_linguaforge_meta_description` (prefixed, plugin-owned). A one-time migration on upgrade copies any existing `meta_description` rows to the new key automatically. The old key is left intact so other plugins that read it are unaffected

**AI Content Tools**

Supports Anthropic Claude, OpenAI, and Google Gemini as interchangeable backends. All generated results appear in a review panel — nothing is applied automatically.

* **Meta Description Generator** — language-aware, 140–160 character output with SEO quality indicator
* **Excerpt Generator** — concise editorial excerpt up to 240 characters
* **Content Translation** — translates full posts while preserving all Gutenberg block markup, block attribute strings (accordion summaries, image alt text, etc.), and footnotes; chunk mode for individual snippets. Max output tokens and max input characters are configurable from Settings with no code changes needed
* **Content Generator** — drafts or rewrites post content from topic hints, tone, and output-type controls; outputs native Gutenberg block markup. Max output tokens, max hints characters, and max context characters are configurable from Settings
* **Quick Translate** — available in the admin toolbar and inside the Gutenberg / FSE editor toolbar for on-the-fly translation of any text snippet
* **AI Behavior Presets** — four named presets (Standard, Technical / Scientific, Legal / Compliance, Creative / Marketing) control the AI's temperature and system instructions. Set a site-wide default from Settings or override it per post from the editor metabox (Translation and Content Generator only)
* **Translation Memory** — opt-in block-level cache shared across posts; only new or changed blocks are sent to the API on subsequent translations, reducing token usage on recurring content
* **Glossary** — user-managed terminology table per language pair. Terms are injected into every translation prompt so brand names, technical terms, and units stay consistent
* **Side-by-side diff preview** — "Apply to Editor" shows a two-column before/after modal so you can review the translation before it touches the post
* **AI Usage tracking** — every API call is logged by feature, provider, model, and date. A summary table with token counts is available in **Settings → AI Usage**
* **Language Overrides** — upload custom `.mo` files to override third-party plugin strings per locale (e.g. replace "room" with "apartment" in VikBooking). Files are stored in the uploads folder and survive plugin updates. Managed from **Settings → Lingua Forge AI → Language Overrides**
* **WP-CLI** — `wp linguaforge translate`, `wp linguaforge retranslate`, and `wp linguaforge cache-clear` for scripted and automated workflows

API keys are stored encrypted (AES-256-CBC, derived from WordPress auth salts). Model endpoints are configurable from Settings with no code changes needed when a new model version ships.

Source code and issue tracker: https://github.com/leotiger/lingua-forge

== Installation ==

1. Upload the `lingua-forge` folder to `wp-content/plugins/`.
2. Activate **Lingua Forge** from **Plugins → Installed Plugins**.
3. Go to **Settings → Permalinks** and click **Save Changes** — this registers the language URL prefixes.
4. Go to **Settings → Lingua Forge AI**, select an AI provider, and enter your API key.

**Migrating from mu-plugins:** if you were running Language Router, Meta Description, or WPEnhance AI as must-use plugins, deactivate or remove those files before activating Lingua Forge to avoid duplicate hooks. Existing post meta (`_lang`, `_trid`, `meta_description`) and the `my_lang_filter` user preference are migrated automatically on first activation.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Anthropic Claude, OpenAI (GPT), and Google Gemini. You only need an API key for the provider you want to use. The active provider is selected from **Settings → Lingua Forge AI**.

= Where are API keys stored? =

Keys are encrypted with AES-256-CBC using a secret derived from your WordPress auth salts and stored in `wp_options`. Plaintext keys never touch the database. As a fallback, the plugin also reads keys from server environment variables or PHP constants in `wp-config.php`.

= Does this work with FSE / block themes? =

Yes — the Language Router was designed specifically for block-theme sites. Language-specific FSE templates, hreflang injection, and the Language Switcher block all work in the Site Editor.

= Does Lingua Forge conflict with Yoast SEO, Rank Math, or other SEO plugins? =

The hreflang output from third-party SEO plugins is suppressed automatically when Lingua Forge is handling hreflang (the default). Meta description output coexists without conflict. If you prefer to let an SEO plugin handle hreflang, set the `lf_hreflang_mode` filter to `'off'`.

= What languages can be translated? =

The AI translation feature supports 38 languages out of the box: English, Spanish, Portuguese, French, Italian, German, Dutch, Catalan, Swedish, Danish, Norwegian, Finnish, Polish, Czech, Slovak, Hungarian, Romanian, Bulgarian, Croatian, Slovenian, Greek, Ukrainian, Russian, Arabic, Hebrew, Persian, Turkish, Swahili, Hindi, Bengali, Indonesian, Malay, Vietnamese, Thai, Chinese (Simplified), Chinese (Traditional), Japanese, and Korean. The list is filterable via `linguaforge_translation_languages` — you can add, remove, or replace languages without modifying plugin files. The Language Router itself works with any language WordPress supports.

= Translation cuts off at the end of a long page. =

Go to **Settings → Lingua Forge AI → Translation Limits** and increase **Max output tokens** (default: 16 000). If you also want to cap how much content is sent to the AI, set **Max input characters** — leave it at `0` (the default) to always send the full page.

= Generated content is cut off before the article is finished. =

Go to **Settings → Lingua Forge AI → Content Generator** and increase **Max output tokens** (default: 8 192). For very long articles you may need to raise this to 12 000–16 000. You can also adjust **Max hints characters** (default: 2 000) to control how much of the Hints field is forwarded to the AI, and **Max context characters** (default: 6 000) to control how much of the existing post body is used when no hints are provided.

= How do I override a third-party plugin's strings for a specific language? =

Place a compiled `.mo` file named `{textdomain}-{locale}.mo` (e.g. `vikbooking-ca.mo`) in `wp-content/uploads/lingua-forge/i18n-overrides/`. The easiest way is to go to **Settings → Lingua Forge AI → Language Overrides** and use the upload form. The folder is created automatically on plugin activation, files survive plugin updates, and no code changes are needed when adding new overrides.

= AI requests time out or cause a white screen on long content. =

Managed hosting plans often cap PHP execution time at 30–60 seconds. Lingua Forge uses a 120-second timeout for AI API calls, but PHP kills the process first if the server limit is lower. Fix options: add `set_time_limit( 180 );` to `wp-config.php`, add `php_value max_execution_time 180` to `.htaccess`, or ask your host to raise the limit. As a workaround without server changes, use **Chunk mode** to translate individual blocks rather than the full page at once.

= The AI returns "generation failed" with no explanation. =

Check the PHP error log — Lingua Forge writes the raw provider response there whenever a call fails. The most common causes are an invalid or expired API key, hitting the provider's rate limit, or a temporary provider outage. Verify your key in **Settings → Lingua Forge AI → API Keys** and test it in the provider's own dashboard.

= The Quick Translate button doesn't appear in the editor on first load. =

This is a known issue. A single page reload (F5) makes it appear consistently. The Admin Toolbar Quick Translate is unaffected and is always available as a fallback.

= The meta description generator uses the old content after I apply a translation. =

Clicking "Apply to Editor" now triggers an automatic save. If the save succeeds (button shows "Saved ✓") the meta description generator will read the translated content. If the auto-save fails, save the post manually before generating the meta description.

= Do I need a permalink structure other than Plain? =

Yes. Language URL prefixes (`/de/`, `/fr/`, etc.) require WordPress to use pretty permalinks. Go to **Settings → Permalinks** and choose any option except **Plain**.

== Screenshots ==

1. The Lingua Forge AI meta box in the block editor — Meta Description, Excerpt, Translation, and Content Generator features.
2. Quick Translate popover in the admin toolbar.
3. Quick Translate popover in the Gutenberg editor toolbar.
4. Block-level Translate / Revise popover on a selected block.
5. Settings → Lingua Forge AI — provider selection, API key management, and model overrides.
6. Language column and filters in the post list.
7. Translation meta box in the post editor — linked translations per language with Override control.
8. Admin Link Fixer — dry-run table with per-row Fix and Fix All actions.

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

== Changelog ==

= 1.2.13 =
* Added: Content Generator now opens in its own dedicated overlay instead of the side-by-side diff modal. After initial generation an inline **Refine** section lets you submit additional instructions (tone, structure, expansion, etc.) as follow-up turns in the same API conversation — the model receives its previous draft and rewrites from there. Refinements can be repeated any number of times; each iteration is labelled `· Refinement #N` in the overlay header. Apply to Editor inserts the current draft directly without a diff step.
* Added: `ContentGenerator::run()` multi-turn backend — detects `refine_hint` + `previous_output` params and assembles a four-message conversation array so all three providers (Anthropic, OpenAI, Gemini) handle refinement consistently. Refinements bypass the cache on both read and write, preventing iterative drafts from overwriting the cached initial generation.

= 1.2.12 =
* Added: `--with-meta-description` flag on `translate`, `retranslate`, and `fill-translations` — generates and saves `_linguaforge_meta_description` for each translated post in its target language immediately after writing its content. Skipped on `--dry-run`. Result visible in the `detail` column as `+ meta` or `+ meta (error: …)`.

= 1.2.11 =
* Added: WP-CLI `wp linguaforge missing-translations <lang> <post_type>` — scans all posts of a given type and source language, lists every post missing one or more router-language translations, sorted by missing count. Pairs with `fill-translations` for bulk translation workflows.
* Fixed: Custom prompt instructions were silently ignored when the Standard AI preset was active. They now always apply regardless of preset selection.
* Changed: Settings → Behavior now shows a live read-only preview of each preset's built-in instructions when the dropdown changes; "Custom system-prompt addendum" renamed to "Custom prompt instructions" with a realistic placeholder example; Standard preset shows its temperature range in the dropdown.

= 1.2.10 =
* Added: WP-CLI `wp linguaforge fill-translations <post_id>` command — identifies all active router languages without a translation for the given post and creates them in one pass. Supports `--check-only` (report only, no API calls), `--exclude` (skip specific locales), `--draft`, `--dry-run`, `--format`, and all provider/model/token override flags.

= 1.2.9 =
* Changed: Glossary language dropdowns now show only languages the Language Router actively knows about, not the full 100+ language AI translation list.
* Added: "Any target language" option in the Glossary "Add entry" form — select it to apply a term (brand name, abbreviation, do-not-translate rule) to all target languages at once. Displayed as *any* in the entries table.
* Fixed: `Glossary::get_for_pair()` now includes any-target-language entries (target_lang = '') in the translation prompt.

= 1.2.8 =
* Added: New **Router** tab in Settings with dedicated Language Router configuration. Includes a Primary Language selector (moved from Behavior tab), a one-click Flush Permalinks button, an Active Languages chip list, and an Install Language section that downloads WordPress core language packs from wordpress.org directly from the settings page.

= 1.2.7 =
* Added: Primary Language selector in Settings → Behavior → Language Router. The language served without a URL prefix is now configurable from the admin UI (stored in `linguaforge_primary_language`). Previously it was hardcoded to `ca`. Changing it requires flushing permalinks.
* Fixed: Link Fixer template checker no longer flags primary-language posts for missing a language-suffixed template (e.g. `page-ca`). Primary-language posts use WordPress's default templates and are now correctly excluded from the template check.

= 1.2.6 =
* Fixed: All inline `<script>` and `<style>` output replaced with the canonical `wp_register_script` / `wp_add_inline_script` and `wp_register_style` / `wp_add_inline_style` pattern — no more raw print callbacks on `admin_footer`, `wp_footer`, or appended style blocks in template output. Addresses WordPress.org Plugin Check `wp_enqueue` requirement.
* Fixed: `(int) $_POST[…]` casts without `wp_unslash()` corrected to `absint(wp_unslash(…))` in the Language Router. `sanitize_key(wp_unslash(…))` applied to a `$_GET` read in the AI Settings page. Addresses WordPress.org Plugin Check sanitization requirement.
* Fixed: Admin meta-box inline JS no longer embeds a PHP-interpolated nonce as a literal string; nonce is now passed via a `wp_add_inline_script` data object.

= 1.2.5 =
* Added: Admin Link Fixer now detects stale same-language links caused by pages being moved in the hierarchy. When a page's URL changes (e.g. after reparenting), any other page that linked to it will show a "📍 Stale path (page moved)" entry with the old and new URLs. Fixed automatically by the existing Fix / Fix All buttons — no new workflow required.

= 1.2.4 =
* Added: Admin Link Fixer now also checks each translated post's FSE block template. Posts using `default` or a mismatched template (e.g. a German page not using `page-de`) appear in the scan results with the expected and current values. A per-row "Fix Template" button corrects it immediately; "Fix All" handles both links and templates together. When the target template doesn't exist yet, a message directs the editor to create it in the Site Editor first.

= 1.2.3 =
* Fixed: German (and other verbose-language) translations failing with a generic "unparseable response" error when Claude 4 returns a truncated JSON envelope without signalling `max_tokens`. Both the main translation path and the Translation Memory path now detect likely truncation (response starts with `{` but does not end with `}`) and return a specific message pointing to the Max output tokens setting or the `--max-tokens` CLI flag.

= 1.2.2 =
* Fixed: WP-CLI `wp linguaforge translate` and `wp linguaforge retranslate` were silently skipping target languages that had no TRID-linked post instead of creating one. Both commands now create a new draft of the correct post type, link it into the translation group, populate it with the translated content and title, and assign a language-specific FSE template if one exists.

= 1.2.1 =
* Fixed: Fatal 500 on Admin Link Fixer scan — `WP_Query` inside the namespaced `LinguaForge\Router\LinkFixer` class was missing the global-namespace prefix `\`, causing every scan request from the Pages list to fail with a 500 Internal Server Error since the 1.2.0 namespace migration.

= 1.2.0 =
* Added: `wp linguaforge retranslate` WP-CLI command — force-retranslates a post into one or more target languages, wipes the prior translation cache, and marks the translation synced. Accepts `--to`, `--temperature`, `--max-tokens`, `--model`, `--dry-run`, and `--format` flags.
* Added: AI Behavior Presets — four named presets (Standard, Technical / Scientific, Legal / Compliance, Creative / Marketing) with tuned temperature and system-prompt addenda. Set a site-wide default from **Settings → Behavior** or override per post from the Lingua Forge AI metabox.
* Added: AI Usage tracking — every API call is logged by feature, provider, model, and date. Summary table available in **Settings → AI Usage**.
* Added: Translation Memory — opt-in block-level cache shared across posts; only new or changed blocks are sent to the API on subsequent translations. Enable from **Settings → Behavior**.
* Added: Glossary — user-managed terminology table per language pair. Terms are injected into every translation prompt. Manage from **Settings → Glossary**.
* Added: Side-by-side diff preview — "Apply to Editor" shows a two-column before/after modal before writing to the post.
* Changed: Language Router classes moved into the `LinguaForge\Router` namespace (`Router`, `Switcher`, `LinkFixer`). Back-compat aliases (`Language_Router`, `LSFLR_Switcher`, `LSFLR_Link_Fixer`) remain active; targeted for removal in 1.5.
* Changed: Meta descriptions stored under the prefixed key `_linguaforge_meta_description`. A one-time bulk migration copies existing `meta_description` rows to the new key automatically on the first admin request after upgrade. The old key is left intact.
* Fixed: `phpcs` / Plugin Check compliance — all direct `$wpdb` queries against the plugin's own tables now carry the correct five-rule ignore directives (`DirectQuery`, `NoCaching`, `SchemaChange`, `InterpolatedNotPrepared`, `NotPrepared`, `PluginCheck.Security.DirectDB.UnescapedDBParameter`).
* Fixed: Nested `phpcs:disable/enable` blocks in `uninstall.php` were re-enabling `DirectQuery` and `NoCaching` mid-file, leaving later DROP and DELETE statements without the required ignore coverage.
* Fixed: `stable_tag` mismatch between plugin header and `readme.txt` flagged by Plugin Check.

= 1.1.0 =
* Changed: Public template functions renamed from `lf_*` to `linguaforge_*` for WordPress.org naming compliance (e.g. `linguaforge_get_lang()`, `linguaforge_languages()`).
* Fixed: Index name mismatch in `uninstall.php` — was attempting to drop `lf_lang_meta` instead of the actual index `idx_lang`, leaving an orphaned index after plugin deletion.
* Fixed: Double-escaping bug in the Language Switcher when using a custom toggle label containing special characters (e.g. `&`).
* Changed: WordPress.org Plugin Check compliance pass across all files — escaping, sanitization, nonce suppression comments, and i18n translators comments.

= 1.0.1 =
* Added: Language Overrides UI — upload and delete `.mo` files from **Settings → Lingua Forge AI → Language Overrides** without FTP access.
* Added: Override files are now stored in `wp-content/uploads/lingua-forge/i18n-overrides/` so they survive plugin updates. Deleting a `.mo` also removes the matching `.po` if present.
* Added: `lf_i18n_overrides_dir` filter to redirect the override storage path to a custom location.
* Added: Activation hook creates the uploads-based override directory automatically.
* Fixed: Fatal PHP error (memory exhausted) in `Translation.php` caused by infinite recursion — `apply_filters()` was passing `self::get_languages()` as a default, which called itself. Fixed by passing the `self::LANGUAGES` constant instead.
* Fixed: "Apply to Meta Description" button was invisible due to flex-box overflow inside the 280 px feature group. Button moved to its own full-width row beneath the result bar.
* Fixed: "Apply to Editor" no longer attempts a programmatic Gutenberg save (unreliable when meta boxes are present). A 6-second hint — "Save the post to persist changes." — now appears instead.
* Changed: `LINGUAFORGE_VERSION` bumped to `1.0.1` to bust cached JS/CSS assets.

= 1.0.0 =
* Initial release. Merges Language Router, Meta Description, and WPEnhance AI into a single plugin with shared constants, a unified settings page, and a common migration path from mu-plugin installations.

== Upgrade Notice ==

= 1.2.13 =
Content Generator redesign: dedicated overlay with iterative multi-turn refinement. No database changes; no migration required.

= 1.2.12 =
Adds `--with-meta-description` to all translation CLI commands. No database changes; no migration required.

= 1.2.11 =
Adds `wp linguaforge missing-translations` scan command; fixes custom prompt instructions being ignored on Standard preset; Settings Behavior tab improvements. No database changes; no migration required.

= 1.2.10 =
Adds `wp linguaforge fill-translations` WP-CLI command. No database changes; no migration required.

= 1.2.9 =
Glossary language dropdowns now only list active router languages. "Any target language" option added — one entry covers all translations. No database migration required; existing entries are unaffected.

= 1.2.8 =
Adds a dedicated Router tab in Settings with permalink flush, active language list, and an in-admin language pack installer. No database changes; no migration required.

= 1.2.7 =
Primary language is now configurable in Settings → Behavior → Language Router. No database migration required — existing sites default to `ca`. Link Fixer no longer false-positives on primary-language template checks.

= 1.2.6 =
WordPress.org Plugin Check compliance pass: all inline scripts and styles now go through `wp_enqueue` APIs, and all POST/GET reads are properly unslashed and sanitized. No database changes; no migration required.

= 1.2.5 =
Adds stale-path detection to the Link Fixer: pages moved in the hierarchy now surface broken same-language links and auto-fix them alongside cross-language link corrections.

= 1.2.4 =
Admin Link Fixer now also detects and corrects wrong or missing FSE block templates on translated posts. No database changes; no migration required.

= 1.2.2 =
WP-CLI translate and retranslate commands now auto-create missing target posts as drafts and link them into the TRID translation group, rather than skipping languages with no linked post.

= 1.2.1 =
Fixes a fatal 500 error on the Admin Link Fixer scan introduced by the 1.2.0 namespace migration. Update immediately if you use the Link Fixer in the Pages or Posts list.

= 1.2.0 =
Language Router classes are now namespaced; back-compat aliases remain active. Meta descriptions migrate automatically to the new `_linguaforge_meta_description` key — no manual steps required. Adds WP-CLI retranslate command, AI Usage tracking, Behavior Presets, Translation Memory, and Glossary.

= 1.1.0 =
Template functions renamed from lf_* to linguaforge_* for WordPress.org compliance. Update any direct calls in custom themes. Fixes an uninstall bug and a character-escaping issue in the Language Switcher.

= 1.0.1 =
Fixes a fatal PHP memory error and the invisible Apply to Meta Description button. Language override .mo files are now stored in wp-content/uploads/lingua-forge/i18n-overrides/ — move any existing files there after upgrading.

= 1.0.0 =
Initial release.
