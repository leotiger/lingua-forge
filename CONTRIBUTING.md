# Contributing to Lingua Forge

This document covers the conventions you need to know before adding to or
modifying the Lingua Forge plugin. The most-asked question is **"which
prefix do I use for this thing?"** — that's at the top.

---

## Prefix policy

Lingua Forge uses **five** distinct prefixes. Each has a specific scope.
Pick the right one when introducing a new option, hook, constant, class,
or DOM element — the prefix communicates *what kind of thing this is and
who owns it* at a glance.

### Quick reference

| Prefix          | Case        | Used for                                                              |
|-----------------|-------------|-----------------------------------------------------------------------|
| `linguaforge_`  | lowercase   | Long-form identifiers: option keys, admin_post / wp_ajax actions, integration API hooks (across all sub-modules), transient prefixes |
| `lf_`           | lowercase   | Short-form identifiers: post/user-meta keys, form field names, nonce names, Language-Router filter hooks, GET-flag query args |
| `LF_`           | UPPERCASE   | One runtime constant defined at file-load time (currently only `LF_LANG`) |
| `LINGUAFORGE_`  | UPPERCASE   | Plugin-wide PHP constants — paths, URLs, version, and the wp-config-overridable behavior switches |
| `lsflr_` / `LSFLR_` | mixed   | Feature prefix for the Language Switcher / Link Fixer public API (wrapper functions, AJAX actions, DOM classes). Stable — do not rename existing identifiers. Do not extend this prefix for new features; use `lf_` or `linguaforge_` instead |

### `linguaforge_*` — long lowercase

Use for any identifier that should clearly belong to Lingua Forge from
outside the plugin namespace.

- **`wp_options` keys.** Examples: `linguaforge_provider`,
  `linguaforge_ai_daily_quota`, `linguaforge_compliance_temperature`,
  `linguaforge_block_editor_allow_lock_blocks`,
  `linguaforge_secondary_query_excluded_types` (comma-separated list of
  post type slugs excluded from secondary-query language filtering; managed
  via Settings → Router → "Excluded post types").
- **`admin_post_*` and `wp_ajax_*` action names.** Examples:
  `admin_post_linguaforge_clear_ai_cache`,
  `wp_ajax_linguaforge_test_provider`.
- **Integration API hooks** — the stable surface for third-party plugins
  (see *Writing a third-party integration* below). These span all sub-modules:
  - **Router sub-module:** `linguaforge_loaded` (fires after the router has
    fully booted; receives `string $version`), `linguaforge_trid_changed`
    (fires in `TridGroup::set_trid()` only when the TRID UUID changes;
    receives `int $post_id`, `string $new_trid`, `string $old_trid`),
    `linguaforge_switcher_output` (filter on the fully-rendered language-
    switcher HTML; receives `string $html`, `array $langs`, `array $atts`),
    `linguaforge_page_menu_excluded_page_ids` (filter on the array of page
    IDs that are hidden from every language's `core/page-list` navigation;
    receives `int[] $ids`; seeded from `_lf_page_menu_exclude` post meta.
    Has no effect on classic nav menus — those render from stored
    `nav_menu_item` posts, not from `get_pages()`),
    `linguaforge_secondary_query_excluded_post_types` (filter on the array
    of post type slugs that are excluded from the secondary-query `_lf_lang`
    meta constraint injected by `QueryFilter::handle_secondary_pre_get_posts()`;
    `wpcf7_contact_form` is built-in; additional types can be added via
    Settings → Router → "Excluded post types" or by hooking this filter
    directly; receives `string[] $types`).
  - **AI sub-module:** `linguaforge_translation_content` (filter on the AI
    translation payload before it is written to the result cache; receives
    `array $payload`, `int $post_id`, `string $target_lang`),
    `linguaforge_translation_complete` (action after a CLI / programmatic
    translation creates or updates a post; receives `int $new_id`,
    `int $source_id`, `string $target_lang`).
  - **SEO sub-module hooks** (new in 2.2.0):
    - `linguaforge_seo_og_type` — filter; override the resolved `og:type` per page. Receives `string $type` ('article'|'website'). WooCommerce integration uses this to return `'product'` on product pages.
    - `linguaforge_seo_og_extra_tags` — action; fires after the full OG + Twitter Card set. Use to append additional Open Graph properties (e.g. WC price/availability).
    - `linguaforge_seo_schema_extra_types` — action; fires after built-in JSON-LD types (Article, WebSite). Receives `string $lang`, `string $in_language` (BCP 47). Use to output additional JSON-LD types.
    - `linguaforge_seo_og_locale_map` — filter; override the language→Facebook-locale mapping (`array<string,string>`).
    - `linguaforge_seo_schema_locale_map` — filter; override the language→BCP47 mapping (`array<string,string>`).
    - `linguaforge_seo_og_image` — filter; override the resolved OG image URL (string).
    - `linguaforge_seo_og_description` — filter; override the resolved OG description (string).
    - `linguaforge_seo_schema_data` — filter; modify any schema array before JSON encoding. Receives `array $data`, `string $type` (@type value).
    - `linguaforge_seo_sitemap_slug` — filter; override the sitemap URL slug (default `'lf-sitemap.xml'`).
    - `linguaforge_seo_sitemap_xml` — filter; modify the full generated sitemap XML string before serving.
    - `linguaforge_social_share_url` — filter; override the resolved share URL for a given service. Receives `string $url`, `string $service`.
  - **AI sub-module settings knobs:** `linguaforge_ai_retry_policy`,
    `linguaforge_required_capability`, `linguaforge_debug_dir`,
    `linguaforge_ai_should_boot`, `linguaforge_ai_rate_limit`,
    `linguaforge_ai_daily_quota`, `linguaforge_translation_worker_config`
    (per-invocation model / temperature / max_tokens override; receives
    `WorkerConfig`, `$post_id`, `$params`),
    `linguaforge_translation_memory_enabled` (disable TM per-invocation;
    receives `bool $enabled`, `int $post_id`).
- **Transient name prefixes.** Examples:
  `linguaforge_rate_user_{id}_{endpoint}`,
  `linguaforge_quota_daily_used_{Ymd}`.
- **SEO batch run options.** `linguaforge_seo_batch_last_{lang}` (e.g.
  `linguaforge_seo_batch_last_de`) — written by `ajax_batch_analyze()`
  after each successful batch run; stores a JSON object with
  `{total, analyzed, skipped, avg_score, ok, warn, fail, partial, ts}`.
  Read by the batch-card JS to display last-run statistics without
  re-running the analysis. Not autoloaded (`false`); one option per
  active language.

When you add a new option or hook, default to `linguaforge_` unless the
identifier ships in form-field land (where `lf_` is more idiomatic).

### `lf_*` — short lowercase

Use for identifiers that appear in the database, the DOM, or the URL where
a short name is meaningfully more readable.

- **Post meta and user meta keys.** Examples: `lf_lang_filter`
  (user-level admin filter), every `lf_trans_{lang}` form field that maps
  back to TRID groups.
  - **Language Router meta keys** (public data contract — readable by other
    plugins and themes): `_lf_lang`, `_lf_trid`, `_lf_lang_previous`,
    `_lf_source_updated_at`, `_lf_translation_source_updated_at`,
    `_lf_search_content`, `_lf_page_menu_exclude` (boolean flag — value `'1'`
    or absent — that hides a page from every language's `core/page-list`
    navigation; set via the Language meta box or Quick Edit. **Scope:**
    affects only `core/page-list` blocks inside `core/navigation`. Classic
    nav menus (`wp_nav_menu`) render from stored `nav_menu_item` posts and
    are unaffected). These are stable
    public API; preserve the exact key names. *(Renamed from unprefixed `_lang`, `_trid`, etc. in DB version 1.1;
    `Db\Migrator::rename_meta_keys()` handles in-place migration on upgrade.)*
  - **Plugin-owned AI module keys** (prefixed, not part of external API):
    `_linguaforge_meta_description` (Meta Description module — stores the
    per-post translated meta description; read by the CLI
    `--with-meta-description` flag), `_linguaforge_preset` (per-page AI
    behavior preset override set in the editor metabox; read by
    `Config::active_preset()`), `_lf_seo_score_history` (SEO Analysis —
    stores the two most recent rule-based scores as a JSON array,
    newest-first; used by the Lang column score badge to show a Δ delta.
    Written by `SeoAnalysisPanel::save_score_history()`; read by
    `SeoAnalysisPanel::get_score_history()`; max 2 entries).
  - **Internal routing key:** `_lf_auto_template` (tracks which FSE
    template was auto-assigned by the Language Router so it can be
    retracted if the language setting changes; not in the uninstall list
    because it is regenerated on the next save).
  - **WooCommerce term name keys** (public data contract — readable by
    other plugins): `_lf_term_name_{lang}` (e.g. `_lf_term_name_es`) —
    stores the translated display name for a WooCommerce taxonomy term
    (`product_cat`, `product_tag`, `product_type`, `pa_*`). Written by
    `TermNameAdmin` from the term edit screen; read by `TermNameFilter`
    via the `term_name` filter. One termmeta row per language per term;
    no value stored means "fall back to the source name".
- **`<input name="…">` form field names.** Examples: `lf_lang`,
  `lf_trans_{lang}`, `lf_page_template`.
- **Nonce names and actions.** Examples: `lf_language_nonce` /
  `lf_language_save`, `lf_translations_nonce` / `lf_translations_save`,
  `lf_import_translation_nonce`.
- **Hooks exposed by the Language Router sub-module.** These are
  the public API for theme code and other plugins integrating with
  routing/translation. Filter hook examples: `lf_primary_language`,
  `lf_languages_list`, `lf_hreflang_mode`, `lf_hreflang_x_default`,
  `lf_block_editor_restrictions`, `lf_lang_default_fallback`,
  `lf_lang_fallback_map`, `lf_lang_force_locale`, `lf_i18n_overrides_dir`.
  Action hook examples: `lf_lang_column_missing` (fires after the ⭕
  missing-language indicator in the Lang column; receives `$post_id, $missing[]`),
  `lf_lang_column_outdated` (fires after the ⚠ outdated indicator; receives
  `$post_id`), `lf_lang_column_retranslate` (fires unconditionally for every
  post in the Lang column that has at least one other TRID-linked language;
  receives `$post_id` — used for the "Retranslate" button that appears
  regardless of outdated status). All three are designed for injecting UI
  into the column from a decoupled module — the AI module uses them.
- **GET-flag query args** set by `wp_safe_redirect()` after an
  admin-post handler so the success notice renders on the next request.
  Examples: `lf_override_uploaded`, `lf_cache_cleared`, `lf_debug_cleared`,
  `lf_debug_setting_saved`. Note: the main Settings form uses
  `linguaforge_saved` (long prefix) rather than `lf_` because it is
  dispatched from `SettingsPage` rather than the Language Router layer.

Why two prefixes? `linguaforge_` reads cleanly when you're scanning
`wp_options` or grep-ing PHP source. `lf_` is short enough to type into
form-field markup and short enough to read in browser dev-tools, where
length is annoying.

### `LF_*` — uppercase runtime constant

Currently there's exactly one of these: **`LF_LANG`**, the active language
code for the current request. Defined inside
`Language_Router::define_lang_constant()` at file-load time so theme code
can read it everywhere as a global without going through a function call.

If you introduce another runtime constant of similar nature — set by the
plugin during boot, read everywhere — use `LF_` to match. If it's an
override knob that the *end-user* sets in `wp-config.php`, use
`LINGUAFORGE_` instead.

### `LINGUAFORGE_*` — uppercase PHP constants

Two flavors:

1. **Plugin-internal constants** defined by the plugin during its own
   bootstrap. These are paths, URLs, and version metadata:
   `LINGUAFORGE_PATH`, `LINGUAFORGE_URL`, `LINGUAFORGE_VERSION`,
   `LINGUAFORGE_FILE`, `LINGUAFORGE_AI_PATH`, `LINGUAFORGE_AI_URL`.
2. **wp-config.php override constants** that end-users can define to
   control plugin behavior outside the database. These bypass or take
   precedence over the corresponding settings UI:
   `LINGUAFORGE_PROVIDER`, `LINGUAFORGE_AI_DEBUG`, `LINGUAFORGE_SECRET`,
   `LINGUAFORGE_LANG_FALLBACK_ACTIVE`.

When you add a new override constant, follow the WP_DEBUG pattern: the
constant always wins over the database option, and the Settings UI
displays a "forced by constant" indicator when the constant is defined.

### `lsflr_*` / `LSFLR_*` — Language Switcher / Link Fixer feature prefix

Originates from the standalone mu-plugin
"LanguageSwitcher-ForLanguageRouter" that was folded into Lingua Forge in
v1.0. Classes are fully namespaced as `LinguaForge\Router\Switcher` and
`LinguaForge\Router\LinkFixer` and integrated into the Router singleton as
`$router->switcher` and `$router->link_fixer`. The prefix is the public
identifier convention for this feature — stable API, not deprecated:

- Template-callable functions: `linguaforge_lsflr_render_switcher()`,
  `linguaforge_lsflr_get_languages()`,
  `linguaforge_lsflr_translate_current_url()`.
- AJAX action names: `wp_ajax_lsflr_scan_links`, `wp_ajax_lsflr_fix_post`, `wp_ajax_lsflr_fix_template`.
- DOM element IDs / CSS classes inside the Link Fixer modal:
  `lsflr-fixer-*`, `lsflr-fix-*`.

**Do not extend** this prefix for new features. New Language Switcher /
Link Fixer additions should use `lf_` (filter hooks) or `linguaforge_`
(options / AJAX actions). The existing identifiers are stable and must
not be renamed without a deprecation cycle.

### Public PHP API — `linguaforge_*` wrapper functions

`language-router/language-router.php` defines a set of procedural
wrapper functions that theme code and third-party plugins should use
instead of reaching into the class instances directly. All are prefixed
`linguaforge_` and delegate to `LinguaForge\Router\Router::get_instance()`.

**Language config**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_source_language()` | `string` | The site's primary language code |
| `linguaforge_languages()` | `string[]` | All active language codes |
| `linguaforge_is_valid_lang( $lang )` | `bool` | Whether a code is in the active list |
| `linguaforge_locale_from_lang( $lang )` | `string` | Resolve a language code to a WP locale |
| `linguaforge_language_label( $lang )` | `string` | Human-readable label for a language code |

**Current request**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_detect_lang()` | `string` | Active language for the current request |
| `linguaforge_detect_lang_safe()` | `string` | Same, falls back to source language |
| `linguaforge_set_lang_cookie( $lang )` | `void` | Write the language preference cookie |
| `linguaforge_hreflang_mode()` | `string` | Current hreflang output mode (`custom` or `off`) |
| `linguaforge_is_system_request()` | `bool` | True for cron / REST / CLI — skip frontend logic |

**Post language and TRID**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_get_lang( $id )` | `string` | Language code stored on a post |
| `linguaforge_set_lang( $id, $lang )` | `void` | Write the `_lf_lang` meta |
| `linguaforge_get_trid( $id )` | `string` | UUID linking a post to its translation group |
| `linguaforge_set_trid( $id, $trid )` | `void` | Write the `_lf_trid` meta |
| `linguaforge_get_translations( $id )` | `array` | `[ lang => post_id ]` map for all variants |
| `linguaforge_clear_translation_cache( $id )` | `void` | Bust the `wp_cache` entry for a TRID group |
| `linguaforge_get_missing_languages( $id )` | `string[]` | Language codes that have no translation yet |

**Outdated tracking**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_mark_source_updated( $id )` | `void` | Flag source post as changed (marks translations outdated) |
| `linguaforge_mark_translation_synced( $id )` | `void` | Clear the outdated flag on a translation post |
| `linguaforge_is_outdated( $id )` | `bool` | Whether a translation is behind its source |

**Language-filtered queries**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_query( $args )` | `WP_Query` | `WP_Query` scoped to `LF_LANG` |
| `linguaforge_query_fallback( $args )` | `WP_Query` | Same, falls back to source language if no translation |
| `linguaforge_get_posts( $args, $fallback )` | `WP_Post[]` | Convenience wrapper around `linguaforge_query` |

**URL and routing helpers**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_safe_query_args( $url )` | `string` | Strip language-routing internals from a URL |
| `linguaforge_lang_permalink( $url, $post )` | `string` | Language-prefixed permalink (used as `post_link` filter) |

**AI integration (defined in `ai/ai.php` — requires the AI module)**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_trigger_translation( $source_id, $lang, $params = [] )` | `int\|WP_Error` | Programmatically run the full AI translation pipeline (AI call → create-or-update translated post → TRID link → cache clear → `linguaforge_translation_complete` action). Returns the new/updated post ID or a `WP_Error`. Accepted `$params` keys: `force_refresh` (bool), `force_draft` (bool), `with_meta_description` (bool). |

**Developer / internal**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_build_search_content( $id )` | `void` | Rebuild the `_lf_search_content` index for a post |
| `linguaforge_ensure_lang_index()` | `bool` | Create the `idx_lang` postmeta index if missing |
| `linguaforge_debug( $message, $context )` | `void` | Write a debug entry (no-op unless debug is on) |

---

## Namespaces and class layout

Modern code lives under three namespaces:

- `LinguaForge\Router\…` — the Language Router sub-module (file location:
  `language-router/includes/`). All three classes are namespaced as of 1.2.0;
  back-compat `class_alias` entries were removed in 1.4.0:
  - `LinguaForge\Router\Router` (canonical — use this everywhere)
  - `LinguaForge\Router\Switcher` (canonical)
  - `LinguaForge\Router\LinkFixer` (canonical)
  The old bare names `Language_Router`, `LSFLR_Switcher`, and `LSFLR_Link_Fixer`
  no longer exist. New code in this sub-module must use the fully-qualified names.
- `LinguaForge\Router\REST\…` — REST endpoints bundled with the router
  (file location: `language-router/includes/rest/`).
  - `LinguaForge\Router\REST\DataEndpoints` — public read-only REST routes
    (`GET /wp-json/lingua-forge/v1/languages` and
    `GET /wp-json/lingua-forge/v1/post/{id}/translations`). No AI dependency;
    active whenever the router is active.
- `LinguaForge\AI\Core\…`, `LinguaForge\AI\Providers\…`,
  `LinguaForge\AI\Features\…`, `LinguaForge\AI\Admin\…`,
  `LinguaForge\AI\REST\…`, `LinguaForge\AI\Contracts\…` — the AI
  sub-module (file location: `ai/includes/`). Loaded via a small PSR-4
  autoloader at `ai/includes/Core/Autoloader.php`.
- `LinguaForge\MetaDescription\…` — the Meta Description sub-module
  (`meta-description/meta-description.php`). Currently a single
  `LinguaForge\MetaDescription\Module` class; self-boots via `Module::init()`
  at the bottom of the file.

### PHP namespacing — audit before shipping

When you wrap an existing file in a namespace, **every global-class
reference becomes an unresolved class name** — PHP does not auto-fall-back
classes to the global namespace the way it does for functions and
constants. The audit script in this repo's history (see the project
memory `feedback_php_namespacing.md`) covers every reference site:

```
new ClassName(            # instantiation
: ClassName               # return type / after colon
(ClassName $var           # param type hint
instanceof ClassName      # type check
ClassName::               # static call / ::class
catch (ClassName          # catch block
throw new ClassName       # exception
```

For each hit that isn't a same-namespace class, either add
`use ClassName;` at the top or prefix the reference with `\`. A clean
brace/paren tokenizer pass does **not** catch this — the file parses
correctly; the failure is at runtime when PHP tries to resolve the
missing class.

---

## File and feature locations

```
lingua-forge.php              Plugin entry point; defines constants, loads sub-modules
uninstall.php                 Wipe-on-delete handler (named options + LIKE prefixes + tables)
includes/
  class-updater.php           Self-hosted update checker (Linguaforge_Updater)

language-router/              Routing, locale, translations, hreflang, SEO output, admin meta boxes
  language-router.php         Sub-module bootstrap + procedural template wrappers
  includes/                   Class files (Router, Switcher, LinkFixer, widget, …)
    seo/                      SEO output classes (all registered as Router sub-objects):
                              class-hreflang.php     — hreflang tags, canonical removal, SEO plugin compat
                              class-seo-manager.php  — Open Graph / og:locale / Twitter Cards (priority 2)
                              class-schema-manager.php — Schema.org JSON-LD Article/WebPage/WebSite (priority 3)
                              class-social-share.php — Social Icons block share: URL rewriting + JS
                              class-sitemap-manager.php — /lf-sitemap.xml generation + robots.txt
    rest/                     REST endpoint classes (DataEndpoints)
  assets/                     CSS / JS enqueued into admin and frontend
                              social-share.js — clipboard / native share JS for share:copy/native/auto

ai/                           AI features (translation, meta-description, excerpt, content gen, revise)
  ai.php                      Sub-module bootstrap
  includes/                   PSR-4 class files under LinguaForge\AI\…
    Admin/                    Admin UI: MetaBox, AdminToolbar, PostListColumn, SettingsPage
    Admin/Settings/Tabs/      One class per settings tab (Tab base + 9 concrete tabs)
                              SeoTab.php — SEO tab orchestrator (inner tabs: Hreflang, Open Graph,
                              Social Share, WooCommerce, Schema.org, Sitemap, Analysis, Compatibility)
    Admin/Settings/Tabs/Sections/
                              Per-section renderers for the Router tab's FSE panel:
                              TemplatesSection, TemplatePartsSection, NavigationsSection,
                              PatternsSection (CPT-scoped block pattern translation)
    Admin/Settings/Panels/    Per-panel classes for the SEO tab and other multi-panel tabs:
                              HreflangPanel, OpenGraphPanel, SocialSharePanel,
                              WooCommerceSeoPanel, SchemaPanel, SitemapPanel,
                              SeoAnalysisPanel, CompatibilityPanel, SystemPanel
    Admin/FseLocalisation/    FSE localisation layer — pure-static classes, no instance state:
                              TemplateDefinitions (CPT-slot template list),
                              PartDiscovery (template-part registry queries),
                              PatternExpander (nested wp:pattern inline expansion),
                              PatternDiscovery (CPT-scoped pattern registry + translation store),
                              PatternHandler (AJAX handler for pattern translation),
                              ScaffoldHandler (AJAX: scaffold missing FSE templates),
                              TranslateHandler (AJAX: translate FSE templates/parts),
                              LinkFixer (AJAX: fix cross-language navigation links),
                              PartRefFixer (AJAX: rewrite part slugs for target language)
    CLI/                      WP-CLI commands (Commands facade + one class per subcommand)
    Contracts/                Interface definitions (AIProviderInterface)
    Core/                     Bootstrap, config, caching, TM, glossary, key store, utilities
    Features/                 Feature implementations (Translation, MetaDescription,
                              TranslationTrigger, …)
    Integrations/WooCommerce/ WooCommerce delegation layer:
                              Bootstrap — wires all WC integration hooks
                              MetaDelegate — transparent price/stock/image reads from source
                              StockRouter — routes stock writes to source product
                              VariationDelegate — scopes variation queries to correct language
                              VariationSync — creates/syncs translated product_variation children
                              TaxonomyDelegate — delegates term assignments to source; object_id rewrite
                              CatalogQuery — language filter for WC product queries
                              RestWriteGuard — HTTP 422 for writes to translated products
                              WcPageBridge — filters cart/checkout/my-account page IDs to translated page
                              WcOrderLang — captures language to _lf_order_lang; switches email locale
                              CouponTridMap — expands coupon product/category restrictions across TRID siblings
                              ProductReviewRouter — routes review submissions + reads to source product
                              OrderItemNormalizer — rewrites translated product ID on new order items to source
                              LocalAttributeTranslator — copies custom (non-taxonomy) attribute meta to translated product
                              AdminSaveGuard — suppresses duplicate-SKU notices when the conflict is a TRID sibling
                              PageTagRepair — repairs product post-tag assignments wiped by WC type normalization
                              TermNameFilter — translates pa_* term names in all rendering paths (blocks, Store API)
                              TermNameAdmin — term edit/add screen fields; saves/deletes termmeta
                              SeoSupport — WC-specific OG/schema (og:type=product, price, JSON-LD)
    Providers/                AI provider adapters (Anthropic, OpenAI, Gemini) + factory
    REST/                     REST controller + rate limiter
  assets/                     CSS / JS for the meta box, editor toolbar, Settings page, post list
                              seo-analysis.js — rule-based analysis results rendering (settings page)
                              seo-analysis-editor.js — Gutenberg PluginDocumentSettingPanel + AI modal
  templates/prompts/          AI prompt templates (translation.txt, block-revision.txt, …)

meta-description/             Meta Description module — LinguaForge\MetaDescription\Module class
```

Architectural review and audit notes live **outside** the public
plugin tree — in a maintainer-only `lingua-forge-audit/` sibling
folder (not tracked in this repo). The current snapshot is
`AUDIT-2026-06-13.md`; older documents are kept as historical record only.
Contributors don't need to read them to ship a correct change — the
conventions they codify all live in this file.

---

## Coding conventions

- **PHP 8.1+ syntax** is fine — the plugin requires PHP 8.1 (declared in
  the header). Constructor property promotion, named arguments,
  `match` expressions, `readonly` properties, enums, never return type,
  union return types are all in use.
- **WordPress Coding Standards** are tracked via `phpcs`; the codebase
  is clean against the WordPress.org standard with documented
  `phpcs:ignore` for every justified exception.
- **Tabs in PHP**, 4-space indent in the AI module's class files (this is
  a historical split — match the existing indentation of the file you're
  editing).
- **Capability checks** at every entry point. The plugin defaults to
  `current_user_can('edit_posts')` for content features, gated through
  `FeatureController::required_capability()` which is filterable via
  `linguaforge_required_capability`. Cap-elevation goes through the
  Settings UI's "Minimum role" dropdown, not by hand-editing the cap
  string anywhere.
- **Nonces on every state-changing request.** Admin-post handlers use
  `check_admin_referer`, AJAX endpoints use `check_ajax_referer`. Add a
  dedicated nonce per metabox / form rather than relying on WP's
  edit_post nonce.
- **Sanitize on input, escape on output.** Standard WP idioms:
  `sanitize_key`, `sanitize_text_field`, `wp_unslash`, `absint` on the
  way in; `esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` on the way
  out.

---

## Settings page layout

The Settings page (`Settings → Lingua Forge`) uses a nine-tab layout
(AI Provider / Limits / Behavior / Router / Glossary / SEO /
AI Usage / Maintenance / System). The first three tabs (AI Provider,
Limits, Behavior) live inside a single `<form>` so one Save Settings
click persists every value. The remaining six tabs are outside that form —
each uses its own dedicated admin-post actions.

When adding a new setting, decide which tab it belongs in:

- **AI Provider** — provider selection, model overrides (formerly "General"),
  API keys, and the Test Connection AJAX flow (formerly "API Keys").
  Everything needed to configure an AI provider lives here.
- **Limits** — quotas, rate limits, capability gate, per-feature token /
  character caps.
- **Behavior** — toggles that change *how* the AI features act (block
  editor restrictions, AI behavior preset — Standard / Technical / Legal /
  Creative). Note: Translation Memory and API Response Cache enable/disable
  toggles live in the **AI Usage** tab, not here.
- **Router** — Language Router settings (active languages, browser
  redirect, slug handling). Has its own admin-post save action
  (`linguaforge_save_router_settings`) and a Flush Permalinks action.
- **Glossary** — per-language-pair terminology table. Has its own
  admin-post actions (`linguaforge_glossary_add`,
  `linguaforge_glossary_delete`).
- **SEO** — SEO output settings. Structured as inner tabs using the same
  `.lf-seo-tab` / `.lf-seo-tab-panel` pattern as the Cache stats panel.
  Inner tabs: **Hreflang**, **Open Graph & Twitter Cards**, **Social Share**,
  **WooCommerce** (visible only when WC is active), **Schema.org**, **Sitemap**,
  **Analysis**, **Compatibility**. Each inner panel has its own admin-post
  action (`linguaforge_save_seo_hreflang`, `linguaforge_save_seo_og`,
  `linguaforge_save_seo_social_share`, `linguaforge_save_seo_wc`,
  `linguaforge_save_seo_schema`, `linguaforge_save_seo_sitemap`). The
  Analysis panel also registers three AJAX actions:
  `wp_ajax_linguaforge_seo_analyze` (single-post rule-based),
  `wp_ajax_linguaforge_seo_ai_analyze` (single-post AI-powered), and
  `wp_ajax_linguaforge_seo_batch_analyze` (per-language batch run in fast
  mode — skips `wp_remote_get`, excludes WooCommerce system pages, returns
  a parity overview grouped by language). The batch UI uses the CSS class
  namespaces `.lf-batch-card__*` (per-language result cards) and
  `.lf-parity-tab-btn` / `.lf-parity-panel` / `.lf-parity-source-col`
  (the Multilingual SEO overview tabbed view). The JS strings passed via
  `wp_localize_script` for the SEO Analysis panel include:
  `parityHeading`, `parityHint`, `sourceTitle`, `wcSystemPageNotice`,
  `justNow`, `score`, `title`, `type`, `profile` (added/extended in
  2.2.5; for the full list see `SettingsPage::localize_seo_analysis()`).
  The Sitemap panel
  registers `linguaforge_flush_sitemap_cache`, `linguaforge_ping_sitemap`,
  and `linguaforge_update_robots_txt`.
- **AI Usage** — usage log (requests, input/output tokens by feature,
  provider, model, and date) plus a **Translation Caching** section with
  inner tabs for API Response Cache and Translation Memory. The caching
  inner tabs each contain an enable/disable toggle with a dedicated
  admin-post save action (`linguaforge_save_api_cache_enabled`,
  `linguaforge_save_tm_enabled`) and a clear-cache form
  (`linguaforge_clear_ai_cache`, `linguaforge_clear_translation_memory`).
- **Maintenance** — operational forms (cache, debug files, language
  overrides, translation memory). Each entry has its own admin-post
  action.
- **System** — read-only environment panel: PHP/WP/plugin version info,
  permalink compatibility check, active SEO plugin detection, WooCommerce
  page translation coverage table, `_lf_lang` repair tool, rewrite-rule
  dump, and a debug-copy button. No admin-post actions; all output is
  informational.

Tab state is preserved across the save-redirect cycle via
`sessionStorage`. Each tab is deep-linkable via URL hash (`#behavior`,
`#limits`, etc.).

---

## WP-CLI

The plugin registers a `linguaforge` WP-CLI command namespace when
`WP_CLI` is defined. Registration happens eagerly at AI sub-module load
time (`ai/ai.php`); the facade class (`LinguaForge\AI\CLI\Commands`)
is autoloaded on the first method dispatch.

The CLI namespace under `ai/includes/CLI/` is split into:

- `Commands.php` — thin facade. Holds one public method per subcommand
  plus the WP-CLI docblocks (`## OPTIONS` / `## EXAMPLES` / `@when`).
  Each method is a one-line forwarder into a dedicated command class.
  Why the docblocks live here: WP-CLI introspects the class registered
  via `WP_CLI::add_command()` and reads its method docblocks to build
  `wp linguaforge <sub> --help`. Keeping the docblocks on the facade
  means the help output stays byte-stable regardless of how the
  implementation is structured underneath.
- `AbstractTranslateCommand.php` — shared base for the three commands
  that drive `Translation::run()` (translate, retranslate,
  fill_translations). Provides the validators (`validate_post_id`,
  `validate_target_langs`), the worker-overrides filter installer, the
  debug-mode helper, the `apply_translation` / `create_trid_linked_post`
  / `generate_and_save_meta_description` pipeline, and the
  `dump_debug_files` echo helper. Subclasses only implement
  `execute(array $args, array $assoc_args): void`.
- `TranslateCommand.php`, `RetranslateCommand.php`,
  `FillTranslationsCommand.php` — each extends `AbstractTranslateCommand`.
- `MissingTranslationsCommand.php`, `CacheClearCommand.php` — standalone
  classes (no translation pipeline involvement), each with an
  `execute(array $args, array $assoc_args): void`.

Currently shipped:

- **`wp linguaforge translate <post_id> --to=fr,de[,…]`** — runs the
  Translation feature for each target language, then writes the result
  into the TRID-linked target-language post via `wp_update_post`. The
  `wp_after_insert_post` handlers are temporarily detached during the
  write so the content-only update doesn't touch the TRID group,
  language metadata, or outdated flag. Languages without a TRID-linked
  post are skipped with a warning rather than auto-created (that's the
  future `translate-missing` command's territory). Per-invocation
  overrides: `--temperature=<float>`, `--max-tokens=<int>`,
  `--model=<name>`, `--force` (skip cache), `--dry-run` (generate but
  don't write).

- **`wp linguaforge retranslate <post_id> --to=fr,de[,…]`** — same pipeline
  as `translate` but ignores the AI result cache (`--force` implied), so
  it always calls the provider. Use when content has changed and you want
  a fresh translation without clearing the cache globally.

- **`wp linguaforge fill_translations [--post-type=post] [--lang=fr]`** —
  iterates over all posts of the given type (default: `post`, `page`) and
  translates into any target languages where a TRID-linked post exists but
  has no content yet. Safe to re-run; posts with existing content are
  skipped unless `--force` is passed.

- **`wp linguaforge missing_translations [--post-type=post]`** — reports
  which posts are missing one or more language variants. No writes; pure
  audit. Pairs with `fill_translations` as a detect-then-fill pipeline.

- **`wp linguaforge cache_clear`** — wipes AI-result cache entries.
  Bare command truncates the whole table; `--feature=translation` scopes
  to feature-key prefix; `--post-id=N` scopes to a single post; both
  combine. Bare-truncate prompts unless `--yes` is passed.

When adding a new command:

1. Create a new class `LinguaForge\AI\CLI\FooBarCommand` in
   `ai/includes/CLI/FooBarCommand.php`. If it runs the translation
   pipeline, extend `AbstractTranslateCommand`; otherwise it stands
   alone. Implement `public function execute(array $args, array
   $assoc_args): void`.
2. Add a public method `foo_bar` to `LinguaForge\AI\CLI\Commands` whose
   docblock contains the WP-CLI-flavored `## OPTIONS` / `## EXAMPLES`
   (these render as `wp linguaforge foo-bar --help`). The method body
   is a one-liner: `( new FooBarCommand() )->execute( $args, $assoc_args );`.
3. Mention the new subcommand in the class-level `## SUBCOMMANDS`
   docblock at the top of `Commands.php` so `wp linguaforge --help`
   lists it.

WP-CLI's snake_case-to-hyphen mapping turns `public function foo_bar()`
into `wp linguaforge foo-bar`.

For commands that need to override the worker model / temperature /
max_tokens, use the `linguaforge_translation_worker_config` filter (see
the filters list below) rather than reaching into the feature's worker
config directly. The CLI's `translate` command is the reference
implementation — registers a closure on the filter, runs the feature,
removes the closure.

---

## WooCommerce integration

The WooCommerce integration lives entirely under
`ai/includes/Integrations/WooCommerce/` and is bootstrapped by
`Bootstrap::init()` on `plugins_loaded` priority 20 (after WooCommerce
itself loads at priority 10). It is silently skipped when
`class_exists('WooCommerce')` is false.

### Shared-stock delegation model

Translated `product` posts carry only content fields (title, description,
excerpt, meta description). All operational data is served transparently
from the source-language product at runtime:

| Class | Hook | Responsibility |
|---|---|---|
| `MetaDelegate` | `get_post_metadata` priority 1 + bulk `get_post_metadata` (`$meta_key=''`) | Price, SKU, stock, images, etc. transparently read from source for both individual and bulk `get_post_meta()` reads |
| `StockRouter` | `update/add_post_metadata` priority 1 | Stock writes routed to source; translated post stays clean |
| `VariationDelegate` | `pre_get_posts` priority 5 | `product_variation` children — serves translated variations if present, falls back to source |
| `VariationSync` | `wp_after_insert_post` priority 30 | Creates `product_variation` children on translated products; copies `_variation_description`, `attribute_pa_*` meta, structural WC taxonomies, and propagates type changes from source to all translations |
| `TaxonomyDelegate` | `wp_get_object_terms` priority 10 + `wp`/`the_post` cache clearing | Term assignments delegated to source; `object_id` rewritten on returned terms so `update_object_term_cache()` primes the correct bucket |
| `CatalogQuery` | `woocommerce_product_query` | Language filter for secondary WC product queries |
| `RestWriteGuard` | `woocommerce_rest_pre_insert_product_object` + `…_variation_object` | Returns HTTP 422 for PUT/PATCH to translated products/variations; includes source_id in response |
| `WcPageBridge` | `option_woocommerce_cart_page_id`, `option_woocommerce_checkout_page_id`, `option_woocommerce_myaccount_page_id` + endpoint URL filters | Returns the TRID-linked translated page ID for the active language so WC cart/checkout/my-account URLs resolve to the correct language version |
| `WcOrderLang` | `woocommerce_checkout_order_created`, `woocommerce_order_status_changed`, `woocommerce_email_before_order_table`, `woocommerce_email_footer` | Captures `LF_LANG` to `_lf_order_lang` on checkout; switches email locale to the saved order language for all transactional emails |
| `CouponTridMap` | `woocommerce_coupon_is_valid_for_product` | Expands product and product-category IDs in coupon restrictions to include all TRID siblings so coupons apply correctly regardless of which language variant is in the cart |
| `ProductReviewRouter` | `comment_post` priority 1, `comments_pre_query` priority 10 | Redirects review submissions targeting a translated product to the source product; serves source reviews on translated product pages |
| `OrderItemNormalizer` | `woocommerce_new_order_item` priority 10 | Rewrites the translated product ID on new order line items to the source product ID so sales/stock statistics accumulate on the source |
| `LocalAttributeTranslator` | `wp_after_insert_post` priority 35 | Copies custom (non-taxonomy) `_product_attributes` meta from source to translated product at save time so attribute labels are available without content delegation |
| `AdminSaveGuard` | `woocommerce_product_duplicate_before_save` (pre-filter hook) | Resolves "duplicate SKU" conflicts by checking whether the conflicting product is a TRID sibling of the product being saved; suppresses the notice when the conflict is within the same translation group |
| `PageTagRepair` | `wp_after_insert_post` priority 40 | Repairs `product` post-tag assignments on translated products when WC's type-normalization routine wipes them |
| `SeoSupport` | `linguaforge_seo_og_type` (filter), `linguaforge_seo_og_extra_tags` (action), `linguaforge_seo_schema_extra_types` (action) | WC-specific SEO: `og:type=product`, `og:price:amount`, `og:price:currency`, `og:availability`, `product:*` namespace tags, and `Product` JSON-LD schema. Option-gated: `linguaforge_seo_wc_og_enabled` (OG) + `linguaforge_seo_schema_product` (schema). |

### WC structural taxonomy inheritance

Translated variable products must have `product_type = variable`, `pa_*` attribute terms,
and `product_brand` assigned **directly in the DB** — not only delegated at runtime. WC's
`_prime_post_caches()` fires a combined multi-taxonomy `wp_get_object_terms()` call that
distributes results by `$term->object_id` BEFORE our filter can correct the empty caches.
Without the DB assignments, WC defaults to `'simple'` product type.

`VariationSync::sync_wc_taxonomies_from_source($source_id, $translated_id)` handles this at
creation time. `propagate_wc_taxonomies_to_translations($source_id)` re-syncs when the source
product saves — so a `variable → simple` change on the source propagates immediately.

### Translated term names (Phase 1b)

Category, tag, and attribute term names display in the visitor's language:

| Class | Hooks | Responsibility |
|---|---|---|
| `TermNameFilter` | `term_name` priority 10; `get_term` priority 10; `wp_get_object_terms` priority 15; `woocommerce_variation_option_name` priority 10 | Translates `pa_*` term names in all rendering paths: classic templates, WC blocks, and Store API JSON. Language resolved from `_lf_lang` on the queried product post (WC product pages have no URL language prefix). |
| `TermNameAdmin` | `init` priority 15 + taxonomy hooks | Term edit/add screen fields; saves/deletes termmeta |

`TermNameAdmin::init()` is a no-op on non-admin requests. It registers
form hooks after WooCommerce has registered its `pa_*` attribute
taxonomies (WC registers them at `init` priority 5; we run at priority 15).

**WC block / Store API path:** `WC_Product_Attribute::get_terms()` calls individual
`get_term($id, $taxonomy)` for each term. The `get_term` filter fires even on cache
hits. Language is detected from `get_post_meta(get_queried_object_id(), '_lf_lang', true)`
rather than the URL prefix, since WC product permalinks (`/product/{slug}/`) carry no
language prefix.

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `linguaforge_wc_delegate_post_types` | `['product', 'product_variation']` | Which post types participate in meta/taxonomy delegation and stock routing |
| `linguaforge_wc_delegate_taxonomies` | `['product_cat', 'product_tag', 'product_type', 'product_brand']` | Which WC taxonomy slugs are delegated by `TaxonomyDelegate` (pa_* handled separately by prefix match) |
| `linguaforge_cpt_create_allowed` | `true` | Gates translated-post creation in `PostListColumn::ajax_fill_missing()` — return `false` for a post type until its delegation layer is confirmed active |

### Extending the delegation layer

To delegate a new **meta key**, add it to `MetaDelegate::OPERATIONAL_KEYS`.

To add delegation for a **new post type**, use the `linguaforge_wc_delegate_post_types`
filter — all delegation classes read from this filter.

To add a **new taxonomy** (e.g. a third-party brand taxonomy) to the delegation layer:
```php
add_filter( 'linguaforge_wc_delegate_taxonomies', function( array $taxonomies ): array {
    $taxonomies[] = 'pwb-brand';
    return $taxonomies;
} );
```
Term-name translation is automatic for any taxonomy that passes
`TermNameFilter::is_wc_taxonomy()` (pa_* prefix) or is in the
`linguaforge_wc_delegate_taxonomies` list.

### REST API write guard

External integrations that write product data **must always target the source-language
product ID**. Translated product IDs are read-only for external systems. To resolve
the source ID from a translated ID:
```
GET /wp-json/lingua-forge/v1/post/{translated_id}/translations
```
The LF REST endpoint returns the full translation group including the source post ID.
A PUT/PATCH to a translated product returns HTTP 422 with `linguaforge_rest_write_to_translated_product`
and `data.source_id` in the response body.

### Integration tests

The WooCommerce integration suite lives in `tests/integration/WooCommerce/`
and requires Docker + wp-env with WooCommerce active:

```bash
cd dev/
npm run env:start               # boots wp-env (only needed if stopped)
composer test:integration:wc    # WC-only suite (~218 test methods, ~277 PHPUnit runs)
composer test:integration       # full suite (376 non-WC + 218 WC = 594 methods; PHPUnit reports 623 runs)
```

A full stop/destroy/start is only needed when `.wp-env.json` changes
(adding plugins, changing WP/PHP version). New PHP files are picked up
immediately from the mounted plugin directory without any restart.

---

## Writing a third-party integration

This section describes how an external plugin should integrate with Lingua
Forge. The WooCommerce integration in `ai/includes/Integrations/WooCommerce/`
is the canonical reference implementation.

### Safe attach point

Always hook into `linguaforge_loaded` rather than `plugins_loaded` directly.
`linguaforge_loaded` fires synchronously at the end of the router boot
sequence (which itself runs during `plugins_loaded` at priority 10), after
`LF_LANG` is defined and all `linguaforge_*` / `lf_*` wrapper functions are
available. If you must hook `plugins_loaded` for other reasons, use priority
20 or higher — priority ≤ 10 risks running before the router has initialised.

```php
add_action( 'linguaforge_loaded', function ( string $version ) {
    if ( version_compare( $version, '2.0.0', '<' ) ) {
        return; // version gate — bail if LF is too old
    }
    MyPlugin\LinguaForgeIntegration::init();
} );
```

### Bootstrap class structure

Follow the WC integration pattern:

```php
namespace MyPlugin\Integrations\LinguaForge;

class Bootstrap {

    public static function init(): void {
        // Guard: only boot if Lingua Forge is active and language list is non-empty.
        if ( ! function_exists( 'linguaforge_languages' ) ) {
            return;
        }
        if ( empty( linguaforge_languages() ) ) {
            return;
        }

        // Register your hooks here.
        add_filter( 'linguaforge_translation_content', [ self::class, 'apply_glossary' ], 10, 3 );

        // Announce that your integration is active (allows downstream code to gate on it).
        do_action( 'myplugin_linguaforge_integration_active' );
    }
}
```

Then call from your main file:

```php
add_action( 'linguaforge_loaded', function () {
    \MyPlugin\Integrations\LinguaForge\Bootstrap::init();
} );
```

### Available hooks for integrations

**Filters — modify behaviour:**

| Hook | Signature | Purpose |
|---|---|---|
| `linguaforge_translation_content` | `(array $payload, int $post_id, string $lang)` | Modify translated content before cache/return |
| `linguaforge_translation_worker_config` | `(WorkerConfig $cfg, int $post_id, array $params)` | Override AI model / temperature / max_tokens |
| `linguaforge_ai_provider` | `(AIProviderInterface $provider, int $post_id, WorkerConfig $cfg)` | Swap the AI provider instance — inject a custom provider or a test stub |
| `linguaforge_wc_delegate_post_types` | `(string[] $types)` | Add post types to WC shared-stock delegation |
| `linguaforge_cpt_create_allowed` | `(bool $allowed, string $post_type)` | Prevent translation creation for a post type |
| `linguaforge_switcher_output` | `(string $html, array $langs, array $atts)` | Customise language-switcher HTML |
| `lf_languages_list` | `(string[] $codes)` | Override the active language list |
| `lf_hreflang_x_default` | `(string $url, int $post_id, array $translations)` | Override x-default hreflang URL |

**Actions — react to events:**

| Hook | Signature | Purpose |
|---|---|---|
| `linguaforge_loaded` | `(string $version)` | Router fully booted; all wrapper functions available |
| `linguaforge_translation_complete` | `(int $new_id, int $source_id, string $lang)` | Translated post saved (CLI / programmatic path) |
| `linguaforge_trid_changed` | `(int $post_id, string $new_trid, string $old_trid)` | Post joined or left a translation group |
| `linguaforge_wc_integration_active` | — | WooCommerce integration booted successfully |

### Programmatic translation

To trigger a translation from PHP (bulk import, migration script, custom
CLI command):

```php
$post_id = linguaforge_trigger_translation( $source_id, 'es' );

if ( is_wp_error( $post_id ) ) {
    // handle error
} else {
    // $post_id is the ID of the new or updated translated post
}
```

`linguaforge_trigger_translation()` is defined in `ai/ai.php` and requires
the AI module to be active. It fires `linguaforge_translation_complete` on
success, so any hooks registered on that action will run automatically.

### REST read endpoints

Two unauthenticated GET endpoints are available for headless setups and
block-theme data consumers:

```
GET /wp-json/lingua-forge/v1/languages
→ [ { "code": "ca", "label": "Català" }, … ]

GET /wp-json/lingua-forge/v1/post/{id}/translations
→ { "ca": "https://example.com/post-slug/", "es": "https://example.com/es/post-slug/" }
```

Private or password-protected posts require `read_post` capability.

---

## Direct SQL and phpcs:ignore conventions

The plugin owns several custom tables (`lingua_forge_ai_cache`,
`lingua_forge_ai_usage`, `lingua_forge_ai_glossary`,
`lingua_forge_ai_tm`). WordPress Coding Standards' `WordPress.DB.*`
ruleset is conservative: it flags every direct `$wpdb->…` call, every
variable passed to `prepare()`, and every interpolation into a SQL
string — even when the SQL is safe by construction.

When the SQL must be dynamic (variable WHERE clauses built from a
whitelisted criteria array, `IN (...)` lists sized to a query-time
array, etc.) inlining a literal isn't an option. The canonical pattern
in this codebase:

```php
// Build WHERE / IN from a whitelisted list of %s/%d placeholder fragments.
$where = [];
$values = [];
if (isset($criteria['user_id']) && (int) $criteria['user_id'] > 0) {
    $where[]  = 'user_id = %d';
    $values[] = (int) $criteria['user_id'];
}
// …

$sql = "SELECT * FROM {$table}";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read on the plugin's own table; WHERE built from a whitelisted format-string list above; $values bound through %d/%s placeholders.
$rows = $values
    ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A)
    : $wpdb->get_results($sql, ARRAY_A);
```

Up to five rules can fire on the same line; the ignore directive needs
to list every one that actually fires:

- **`WordPress.DB.DirectDatabaseQuery.DirectQuery`** — fires on any
  direct `$wpdb->…` call. Justified because the four custom tables
  have no WP API equivalent.
- **`WordPress.DB.DirectDatabaseQuery.NoCaching`** — fires because the
  read isn't wrapped in `wp_cache_*`. Justified because these tables
  are themselves caches / telemetry — wrapping with object-cache
  layers would be redundant or actively wrong.
- **`WordPress.DB.PreparedSQL.InterpolatedNotPrepared`** — fires on
  `"{$table} WHERE …"` style interpolation in the SQL string template.
  Justified when the interpolated value is `$wpdb->prefix + hardcoded
  suffix` or a member of a whitelisted format-string list (`%s`/`%d`),
  never caller data.
- **`WordPress.DB.PreparedSQL.NotPrepared`** — fires when `prepare()`
  receives a variable as its first argument (rather than a literal
  string). Justified for the same reason as the previous rule: the
  variable is composed from whitelisted fragments above.
- **`PluginCheck.Security.DirectDB.UnescapedDBParameter`** — fires
  whenever the WordPress.org Plugin Check tool sees a variable passed
  to `$wpdb->…()` without obvious escaping. It's a parallel sniffer to
  the WPCS rules above with its own ruleset; ignoring it requires its
  own line in the directive. Same justification applies — caller data
  is bound through `%s`/`%d`, SQL fragments come from a whitelisted
  list. Often missed in initial directive lists because the regular
  WPCS sweep doesn't load it; runs separately under `plugin check`.

The justification text after the `--` should make explicit:
1. *What table this targets* (so "plugin's own table" is verifiable).
2. *Where the dynamic fragments come from* (the whitelisted list above).
3. *That caller-supplied data is bound, not interpolated* (via `%s`/`%d`
   placeholders).

Reviewers should be able to verify the justification by reading the
preceding 5–10 lines and matching them against the comment.

**When you DON'T need the ignore directive**

If the SQL is fully literal (no variables) the rule doesn't fire:

```php
// No ignore needed — no variables in the SQL.
$count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM " . self::table_name()
);
```

Note: `self::table_name()` returns `$wpdb->prefix . 'lingua_forge_…'`
where the prefix is a server-known constant and the suffix is hardcoded.
PHPCS treats the concatenation as "literal enough" and doesn't fire the
NotPrepared rule. Routing safe dynamic table names through a
class-private accessor like that keeps the call sites clean.

**Why not just inline every query**

A common reflex is "just write seven separate methods instead of one
`query()` with criteria." That works for small APIs but the criteria
permutations multiply quickly (`{post_id?, feature?, post_id+feature}`
already wants three methods) and the validation logic ends up
duplicated. The dynamic-WHERE pattern is the standard way to keep that
under control; the ignore directive is what makes WPCS tolerate it.

---

## Storage shapes

- **AI feature result cache** lives in the custom table
  `{$wpdb->prefix}lingua_forge_ai_cache` (composite primary key on
  `post_id` + `feature_key`, `payload LONGTEXT` JSON-encoded). Managed by
  `LinguaForge\AI\Core\CacheStore`; the public API is `get($post_id,
  $feature, $hash)` / `set(…)` / `delete($post_id, $feature)` /
  `clear_all(): int` plus the `hash($inputs): string` helper.
- **API keys** are AES-256-GCM-encrypted in `wp_options` rows named
  `linguaforge_key_{provider}`. The provider slug is bound as Additional
  Authenticated Data (AAD) so cross-provider ciphertext swaps fail the
  tag check. Encryption secret derives from `wp_salt('auth')` unless
  `LINGUAFORGE_SECRET` is defined. Legacy v1 (AES-256-CBC) values are
  decrypted transparently and re-encrypted as v2 on the first successful
  read (lazy migration).

  **When to define `LINGUAFORGE_SECRET`:** `wp_salt('auth')` is written
  once into `wp-config.php` and stays identical across every copy of that
  file — development, staging, and production share the same secret when
  bootstrapped from the same config. A key ciphertext extracted from one
  environment would therefore decrypt successfully in another. Define a
  unique constant in each environment's `wp-config.php` to give each its
  own independent encryption key:

  ```php
  // Different random string on each environment.
  define( 'LINGUAFORGE_SECRET', 'your-64-char-random-string-here' );
  ```

  Use a cryptographically random value (e.g. `openssl rand -base64 48`).
  Changing this constant on an existing install invalidates all stored
  ciphertexts — re-enter API keys after rotating it.
- **Translation cache key** is `translation_{lang}` (e.g.
  `translation_fr`) so a single post can hold many language caches
  without collision.

---

## Things worth knowing

- **Pre-1.3.x debug files may be orphaned** under
  `wp-content/uploads/lingua-forge-debug/`. That path was the hardcoded
  default before the `linguaforge_debug_dir` filter was added. Either
  point the filter at the old path so the plugin manages cleanup there, or
  delete the directory manually — it is never touched again on 1.3.x+
  installs unless redirected.
- **`is_admin()` returns true** for `wp-admin/*` AND for admin-ajax.php.
  It returns **false** for REST requests — sniff `REST_REQUEST` or the
  URL pattern when you need to detect REST. See
  `Plugin::should_boot()` for the canonical detection.
- **`save_post` and `wp_after_insert_post` fire for REST writes too**, so
  any save-handler must be REST-safe. Don't gate it on `is_admin()`.
- **Rewrite rules need a flush** whenever the locale list changes. The
  pattern is `update_option('linguaforge_flush_rewrite_rules', true)`;
  the `init` hook at priority 99 in `lingua-forge.php` consumes it and
  calls `flush_rewrite_rules()` on the next request.
- **AI cache table is created lazily** — on the first
  `CacheStore::get()` / `set()` / `clear_all()` call after install.
  `CacheStore::ensure_table()` compares
  `linguaforge_ai_cache_db_version` against the `DB_VERSION` constant
  and only runs `dbDelta` on mismatch.

---

## Deployment

The plugin is pushed to production manually via SFTP / rsync (or zip
upload + unzip on the server) — there's no build step, no Composer, no
asset pipeline. Three gotchas come up often enough to be worth flagging:

**File permissions.** New files often land at `0600` on the server (the
web server can't read them — assets 403, PHP includes fatal). Two
common causes: SFTP uploads on hardened hosts where the client's umask
is `077`, and zip archives extracted under a session whose umask is
`077` (Plesk's web-SSH default). The brute-force fix after any deploy:

```bash
cd wp-content/plugins/lingua-forge/
find . -type d -exec chmod 0755 {} \;
find . -type f -exec chmod 0644 {} \;
```

**Building a release zip locally** — normalize permissions on the
source tree before zipping so the archive's stored Unix modes are
correct:

```bash
find lingua-forge -type d -exec chmod 0755 {} \;
find lingua-forge -type f -exec chmod 0644 {} \;
zip -r -X lingua-forge.zip lingua-forge/ -x "*.DS_Store" -x "__MACOSX/*"
```

`-X` strips macOS extended-attribute metadata for a smaller, cleaner
archive. The two `find` lines come first because `zip` captures each
file's mode bits at archive time.

**Unzipping on the server.** Stored permissions aren't enough by
themselves — `unzip` ANDs them with the current shell's umask. On Plesk
web-SSH that umask is `077`, which extracts `0644`-stored files as
`0600`. Two ways to get the right result:

```bash
# Option 1 — set the umask before each extract
umask 022 && unzip -o lingua-forge.zip
```

```bash
# Option 2 — re-normalize after extracting (matches the brute-force fix above)
unzip -o lingua-forge.zip
find lingua-forge -type d -exec chmod 0755 {} \;
find lingua-forge -type f -exec chmod 0644 {} \;
```

To make `umask 022` permanent for the SSH account, append it to
`~/.bashrc` (`echo 'umask 022' >> ~/.bashrc && source ~/.bashrc`). For
other upload paths: rsync takes `--chmod=D755,F644`; FileZilla can
default-set numeric `644` on file permissions in its transfer settings.

Quick sanity check after any extract:

```bash
stat -c '%a %n' ai/assets/settings-tabs.js
# Expected: 644 ai/assets/settings-tabs.js — if it prints 600, umask bit you.
```

**OPcache.** Aggressive OPcache configurations (typical on managed
hosts: `validate_timestamps=0` or long `revalidate_freq`) keep the
previous PHP code resident after a file replace. Symptoms: tabs that
don't render, JS that 404s because the new enqueue method "doesn't
exist," fatals that grep says are impossible. After a deploy:

```bash
# Either reset OPcache from a one-off PHP request:
php -r 'opcache_reset();'

# Or reload PHP-FPM:
service php-fpm reload
```

If you're not sure whether OPcache is the culprit, the quick test is:
add a marker line to a known-loaded file (e.g. `error_log('alive');` at
the top of `lingua-forge.php`) and hit a page. If the marker doesn't
appear in the error log, OPcache is serving the old file.

---

## Local development environment

The plugin itself ships with **zero runtime dependencies** — that policy
is non-negotiable for WordPress.org submission. Every piece of dev
tooling (PHPUnit, PHPCS / WPCS, PHPStan, wp-env, ESLint, Prettier,
Plugin Check) lives in the **`dev/` subdirectory** of this repo:

```
lingua-forge/
├── ai/
├── language-router/
├── meta-description/
├── tests/                ← plugin source (not shipped to .org)
├── lingua-forge.php
└── dev/                  ← tooling only — excluded from .org build via .distignore
    ├── composer.json
    ├── package.json
    ├── phpcs.xml.dist
    ├── phpunit.xml.dist
    ├── phpstan.neon.dist
    ├── .wp-env.json
    ├── vendor/           ← Composer installs here (gitignored, ~200 MB)
    └── node_modules/     ← npm installs here (gitignored, ~700 MB)
```

`dev/` is excluded from every `.org` build via `.distignore`, so the
~1 GB of tooling dependencies never reaches the deploy ZIP.

See [`dev/README.md`](dev/README.md) for the full layout and command
reference.

### Prerequisites

- PHP 8.1+ (PHP 8.2 recommended, matches Plugin Check's target).
- Composer 2.x.
- Node 20+ and npm 10+.
- Docker — required by `@wordpress/env` for integration tests and the
  Plugin Check wrapper.

### One-time install

```bash
cd dev/
composer install
npm install
```

After this, the plugin root is untouched — no `vendor/`, no
`node_modules/`, no caches in the folder that ships to .org.

### Day-to-day commands

Run every command from the `dev/` directory:

| Goal                               | Command                       |
| ---------------------------------- | ----------------------------- |
| PHPCS — full lint                  | `composer lint`               |
| PHPCS — auto-fix what `phpcbf` can | `composer lint:fix` ⚠️        |
| PHPStan — static analysis          | `composer analyse`            |
| PHPUnit — full suite               | `composer test`               |
| PHPUnit — unit only (fast, no WP)  | `composer test:unit`          |
| PHPUnit — integration only         | `composer test:integration`   |
| PHPUnit — WooCommerce integration  | `composer test:integration:wc` |
| All of the above                   | `composer qa`                 |
| Start wp-env (Docker WP install)   | `npm run env:start`           |
| Stop wp-env                        | `npm run env:stop`            |
| Run WP-CLI inside wp-env           | `npm run env:cli -- <args>`   |
| Plugin Check (official WP.org)     | `composer plugin-check`       |
| ESLint                             | `npm run lint:js`             |
| Stylelint                          | `npm run lint:css`            |
| Prettier — format everything       | `npm run format`              |

> ⚠️ **`composer lint:fix` — review every change before committing**
>
> `phpcbf` rewrites files in place. Most of what it touches is safe
> (whitespace, blank lines, brace placement), but a few classes of fix
> require human review before committing:
>
> - **`phpcs:ignore` / `phpcs:disable` pragmas** — `phpcbf` will
>   sometimes remove them when it "fixes" the surrounding code. Check
>   that any pragma you added intentionally (Direct-SQL suppression,
>   render-template globals, etc.) is still present after the run.
> - **Namespace and `use` import reordering** — if `phpcbf` adds or
>   reorders `use` statements it can introduce a global-class reference
>   that PHPStan will catch but that may not be obvious on a quick
>   visual scan. Always run `composer analyse` after a `lint:fix` run.
> - **String concatenation and alignment changes** — the
>   `WordPress.WhiteSpace.OperatorSpacing` fixer sometimes reformats
>   multi-line SQL or HTML strings in ways that are technically correct
>   but harder to read. Prefer accepting those hunks selectively with
>   `git add -p` rather than staging the whole file.
> - **Files with mixed indent history** — some files in this codebase
>   use tabs, others spaces. `phpcbf` will normalise to the ruleset
>   default (tabs). That is a cosmetic-only change on tab files but
>   can produce a large noisy diff on any file that was historically
>   space-indented. Check the diff before committing.
>
> **Safe rule of thumb:** run `git diff` (or stage with `git add -p`)
> after every `lint:fix` invocation and read every hunk. Reject any
> fix that changes logic, removes a suppression pragma, or produces a
> diff larger than the surrounding problem warrants.

### Code coverage

Coverage is measured with **pcov** (faster than Xdebug) and reported as
Clover XML. Because unit tests run locally and integration tests run inside
the wp-env Docker container, the two suites produce separate Clover files
with different absolute paths. A custom PHP script (`dev/scripts/merge-coverage.php`)
normalises the paths and merges them into a single combined report.

pcov is installed automatically when `composer coverage:run` first runs inside
the container — no separate setup step is needed.

**Running coverage:**

| Goal                                | Command                      |
| ----------------------------------- | ---------------------------- |
| Full combined coverage              | `composer coverage`          |
| Re-run both suites (no merge)       | `composer coverage:run`      |
| Re-merge existing Clover XML files  | `composer coverage:merge`    |

**Output directories** (all inside `dev/`; gitignored):

```
coverage/
├── unit/
│   ├── clover.xml        ← PHPUnit Clover (local paths)
│   ├── coverage.txt      ← human-readable text summary
│   └── html/             ← HTML report
├── integration/
│   ├── clover.xml        ← copied out of the Docker container
│   └── coverage.txt
└── combined/
    ├── clover.xml        ← merged, local absolute paths
    └── summary.txt       ← per-file ✅/🔶/❌ table + totals
```

**Interpreting the numbers:** the raw headline understates real coverage — the
denominator includes several thousand lines of Admin HTML render methods, WP-CLI
command boilerplate, and other structurally untestable code. The meaningful signal
is the per-file column in `dev/coverage/combined/summary.txt`: core business-logic
classes (`BlockTextExtractor`, `Config`, `JsonRepair`, `TaxonomyDelegate`,
`MetaDelegate`, `StockRouter`, `TridGroup`, `AbstractProvider`,
`LanguageUninstaller`, etc.) should stay green (≥ 80 %).

**Docker must be running** for `composer coverage` (the integration step calls
`wp-env run tests-cli`). If Docker isn't in your `$PATH`, prefix the command:

```bash
PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" composer coverage
```

### What ruleset each tool uses

- **PHPCS (`dev/phpcs.xml.dist`)** loads `WordPress` + `WordPress-Extra`
  + `WordPress-Docs` + `PHPCompatibilityWP`, targets WP 6.7+ and PHP 8.1,
  and pre-configures the prefix list (`linguaforge_`, `LINGUAFORGE_`,
  `Linguaforge`, `LinguaForge`, `lf_`, `LF_`) so the `PrefixAllGlobals`
  sniff knows about them. The file-name sniff (`WordPress.Files.FileName`)
  is disabled — this codebase intentionally mixes PSR-4 PascalCase
  (`ai/includes/Core/Plugin.php`) with the WP `class-foo.php` convention
  (`language-router/includes/class-context.php`). All `<file>` paths in
  the config use `../` (parent plugin root).
- **PHPStan (`dev/phpstan.neon.dist`)** runs at level 5 with
  `szepeviktor/phpstan-wordpress` as the WP stub source. Level 6+ starts
  flagging type imprecision in WP core itself — not worth the noise.
  `paths:` references `../` (parent plugin root).
- **PHPUnit (`dev/phpunit.xml.dist`)** defines two suites. The **unit**
  suite is the fast path: no WordPress, only pure-function utilities.
  The **integration** suite expects `WP_TESTS_DIR` to point at the WP
  PHPUnit framework (wp-env exposes this automatically). The test files
  themselves live in `tests/` — they're plugin source, just
  `.distignore`'d out of the .org build.

  **Integration test conventions:**
  - Extend `WP_UnitTestCase` (not `WP_UnitTestCase_Base` or `TestCase`).
  - Use Yoast snake_case lifecycle hooks (`set_up` / `tear_down`) instead
    of PHPUnit's camelCase `setUp` / `tearDown`. These **must** be declared
    `public` — the Yoast polyfill base declares them public, and PHP will
    fatal if a subclass narrows visibility to `protected`.
  - `composer test:integration` runs PHPUnit inside the wp-env
    `tests-cli` container. The working directory inside the container is
    `wp-content/plugins/lingua-forge/dev/` — this must match the `cd`
    path in the `test:integration` script in `dev/composer.json` so that
    the Composer-generated classmap's `$baseDir` resolves to the plugin
    root. If you see `Failed to open stream` errors for AI class files,
    the `cd` path is wrong.
- **wp-env (`dev/.wp-env.json`)** boots WordPress 6.9 on PHP 8.1 with
  `..` (plugin root) mounted at `wp-content/plugins/lingua-forge`.
  `WP_DEBUG` is on in both the development and test environments.
  A `.wp-env.override.json` (gitignored) activates WooCommerce for the
  WC integration suite — see `dev/README.md` for the override pattern.
- **Plugin Check** runs inside the wp-env CLI container via
  `composer plugin-check`. This is the same checker WordPress.org uses
  on submission, so passing it locally is a strong signal that a
  release is .org-ready. It also catches the 5th DirectDB rule
  documented below (`PluginCheck.Security.DirectDB.UnescapedDBParameter`)
  that the WPCS-only run misses.
- **ESLint / Prettier / Stylelint** use the
  `@wordpress/eslint-plugin/recommended`,
  `@wordpress/stylelint-config/scss`, and `@wordpress/prettier-config`
  presets as the base. Stylelint has four rule overrides in
  `dev/.stylelintrc.json`: a BEM-aware `selector-class-pattern` (allows
  `block__element--modifier` with all-lowercase-hyphenated segments),
  `camelCaseSvgKeywords: true` to permit the conventional `currentColor`
  casing, and both `rule-empty-line-before` and `comment-empty-line-before`
  nulled (the project style does not require blank lines between every
  rule or before inline comments). New CSS must use proper BEM modifier
  names (`.block--modifier`, not `.--modifier`). The npm scripts in
  `dev/package.json` glob over `../**/*.{js,css}`.

### Recommended pre-deploy sequence

```bash
cd dev/
composer qa                  # lint + analyse + test
composer plugin-check        # the .org checker
npm run lint:js && npm run lint:css
```

If all four are green, the plugin is ready to push via SFTP / rsync.
`dev/` is excluded from every deploy by `.distignore` — nothing in it
ever reaches the server or a .org build ZIP.

### When the tooling disagrees with itself

PHPCS and Plugin Check overlap on direct-DB queries — that's the "five
linter rules per ignore directive" pattern documented below in *Direct
SQL and phpcs:ignore conventions*. Run **both** before you ship; PHPCS
gives you four of the five rules, Plugin Check gives you the fifth.

---

## Verifying changes when PHP isn't installed locally

A common scenario: editing in an environment without `php` on PATH
(remote shell, restricted sandbox, hosted AI session).

### First choice — install PHP user-space from the apt cache

In most restricted environments `sudo` and `apt-get install` are
blocked, but `apt-get download` (pulls a .deb without installing it)
and `dpkg-deb -x` (extracts a .deb into any directory) both work
without root. The recipe — confirmed working in the Cowork sandbox
this project is developed in — takes ~3 minutes and gives you the
full `composer qa` toolchain:

```bash
# 1) Pull the .deb files (no sudo).
cd /tmp
apt-get download \
    php8.1-cli php8.1-common php8.1-opcache \
    php8.1-xml php8.1-mbstring php8.1-curl php8.1-zip \
    php-common libssl3 libxml2 libzip4 libonig5 libargon2-1 \
    libgmp10 libgmpxx4ldbl libtidy5deb1 libsodium23 libffi8 \
    libsqlite3-0 libreadline8 zlib1g

# 2) Extract into ~/.local/php-root.
mkdir -p ~/.local/php-root && cd ~/.local/php-root
for d in /tmp/*.deb; do dpkg-deb -x "$d" .; done

# 3) Override extension_dir so the extension .so files resolve against
#    the user-space install path (the .ini files ship with bare names).
cat > ~/.local/php-root/etc/php/8.1/cli/conf.d/00-extension-dir.ini <<EOF
extension_dir = "$HOME/.local/php-root/usr/lib/php/20210902"
EOF

# 4) Copy the extension .ini files into the cli conf.d so they load.
for ini in ~/.local/php-root/usr/share/php8.1-*/*/*.ini; do
    cp "$ini" "$HOME/.local/php-root/etc/php/8.1/cli/conf.d/20-$(basename $ini)"
done

# 5) Drop a wrapper on PATH that bakes in the LD path + scan dir.
mkdir -p ~/.local/bin
cat > ~/.local/bin/php <<'EOF'
#!/usr/bin/env bash
export LD_LIBRARY_PATH="$HOME/.local/php-root/usr/lib/aarch64-linux-gnu:$HOME/.local/php-root/lib/aarch64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export PHP_INI_SCAN_DIR="$HOME/.local/php-root/etc/php/8.1/cli/conf.d"
exec "$HOME/.local/php-root/usr/bin/php8.1" "$@"
EOF
chmod +x ~/.local/bin/php
export PATH="$HOME/.local/bin:$PATH"

# 6) Verify — should print PHP 8.1.x and a module list including
#    tokenizer, xml, simplexml, dom, mbstring, …
php -v && php -m
```

The `aarch64-linux-gnu` path in step 5 assumes ARM64 hosts. On x86_64
hosts, swap that segment for `x86_64-linux-gnu` and request `_amd64`
.deb variants in step 1.

After this, `phpcs` / `phpstan` / `phpunit` from `dev/vendor/bin/`
run exactly as they do on a developer's machine. The full
walkthrough (with explanations of why the obvious paths fail and a
verified `composer qa` reproduction) is in
`lingua-forge-audit/AUDIT-2026-05-23.md` §9.5.

### Fallback — when even `apt-get download` is blocked

Two regex-tier tools cover most of the failure surface:

- **Brace / paren / `<?php`-aware tokenizer in Python.** Walks the file
  tracking PHP / HTML mode, single + double quoted strings, heredocs and
  nowdocs, block + line comments, and counts `{}` / `()` only inside
  PHP regions. Catches unclosed blocks, runaway strings, and `<?php …`
  blocks left open after a refactor. ~50 lines of Python; small enough
  to inline into a prompt.

- **Global-class reference audit.** A regex scan over
  `new \w+(`, `: ?\w+`, `(\w+ \$var`, `instanceof \w+`, `\w+::`,
  `catch (\w+`, `throw new \w+` inside namespaced files. Each hit needs
  to be a same-namespace class, an imported `use`, or `\`-prefixed —
  otherwise it's a runtime fatal waiting to happen. The brace tokenizer
  does **not** catch this; the file parses fine. PHP doesn't auto-fall
  back classes to the global namespace the way it does functions and
  constants.

Together they're not a full parser substitute, but they catch the most
common edit damage. Anything they pass is worth pushing to staging for a
live PHP execution test before going to production.

---

## Internationalization — all user-facing strings must be localizable

Every string that a site administrator or editor might read — labels,
descriptions, error messages, Settings page text, metabox content,
admin notices, REST error messages — **must** pass through a
localization function. The plugin text domain is `lingua-forge`.

### Which function to use

| Context | Function |
|---|---|
| Plain string (no HTML, echo) | `esc_html_e( 'String', 'lingua-forge' )` |
| Plain string (return / assign) | `esc_html__( 'String', 'lingua-forge' )` |
| String with safe HTML tags (echo) | `wp_kses_post( __( 'String with <strong>HTML</strong>', 'lingua-forge' ) )` |
| String used in `printf` / `sprintf` | `__( 'Found %d items', 'lingua-forge' )` |
| JavaScript strings in `wp_localize_script` | Wrap in `__()` / `esc_html__()` on the PHP side; no JS-side i18n needed |

### Translators comments

When a translated string contains a placeholder, add a `/* translators: */`
comment **on the line immediately above** the `__()` call — WordPress's
i18n extractor (`wp i18n make-pot`) reads only the line directly
before the call:

```php
// Good — extractor sees the comment
/* translators: %s: temperature value, e.g. 0.4 */
printf( esc_html__( 'T=%s', 'lingua-forge' ), esc_html( (string) $meta['temperature'] ) );

// Bad — comment is too far away
/* translators: %s: temperature value */

echo '';
printf( esc_html__( 'T=%s', 'lingua-forge' ), $meta['temperature'] );
```

### Common mistakes to avoid

- **Pre-escaping before `__()` breaks entity normalisation.** Pass the
  raw string to `__()` then escape the result:
  ```php
  // Wrong — double-encodes &amp;
  echo esc_html( __( 'Save &amp; Continue', 'lingua-forge' ) );

  // Correct
  echo esc_html__( 'Save & Continue', 'lingua-forge' );
  ```
- **Dynamic values don't belong inside `__()`.** Use `printf` / `sprintf`
  with `%s` / `%d` placeholders; bind values outside the translated string.
- **JavaScript strings in inline `wp_add_inline_script` blocks** are PHP
  strings at definition time — wrap them in `esc_html__()` and
  `wp_localize_script()` data arrays, not bare JS string literals.
- **REST API error messages** shown in the editor UI must also be
  localized. Use `__()` in `WP_Error` constructors for the human-readable
  message argument.

---

## When you add something new

A short checklist:

1. **Pick the right prefix** (see the table above).
2. **If it's an option**, add it to the `linguaforge_named_options`
   array in `uninstall.php` so it gets cleaned up on plugin delete.
3. **If it's a new table or schema bump**, version it with a dedicated
   `_db_version` option and run `dbDelta` from a lazy
   `ensure_table()`-style method. Add a `DROP TABLE IF EXISTS` line to
   `uninstall.php`.
4. **If it's a new filter or action hook**, document its signature in a
   docblock on the function that calls `apply_filters()` / `do_action()`.
   Use the appropriate prefix.
5. **If it's a Settings UI input**, decide which of the eight tabs it
   belongs in (see the Settings page layout section above), render through
   a `form-table`, save in `handle_save()`, and validate against a
   whitelist (especially for selects and capability strings).
6. **If it's any user-facing string**, wrap it in `esc_html__()` /
   `__()` with the `lingua-forge` text domain. Add a `/* translators: */`
   comment immediately above any call whose string contains a `%s` / `%d`
   placeholder. See the Internationalization section above.
7. **If you're moving a class into a namespace**, run the
   global-class-reference audit (grep for the old bare class name across
   all PHP files) and update every call site before the PR lands.
8. **If it's a new post-meta key**, check whether it should be in the
   uninstall list and whether the generic unprefixed variant (if any)
   is safe to delete — keys like `meta_description` may be shared with
   other plugins and must not be wiped on uninstall.
9. **Changelog entries in `docs/lf-update-manifest.php` and `readme.txt`
   contain the current release only** — never accumulate history there.
   Full history belongs in `CHANGELOG.md`. Replace the previous entry
   on every release; do not prepend to a growing list.
10. **Don't touch `CHANGELOG.md`, `readme.txt`'s `Stable tag`, or the
   `Version:` headers in `lingua-forge.php` / the constants in
   `lingua-forge.php`.** The maintainer cuts releases manually via
   SFTP/rsync and bumps version strings + writes changelog entries at
   release time. Iterating on a fix produces meaningless version
   history if every attempt gets its own bump. Same applies to AI-
   assisted PRs — fix the code, leave the version metadata alone, and
   the maintainer batches changelog entries when the release ships.
