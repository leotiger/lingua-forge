# Changelog — Lingua Forge

---

## [2.1.4] — 2026-06-03

### Fixed

- **Switching back to the source language redirected to the previously active language** — `set_lang_cookie()` set the `lf_lang` cookie with `HttpOnly = true`. Modern browsers silently discard `document.cookie` writes that target an existing HttpOnly cookie, so the switcher's client-side `writeCookie()` call was a no-op. The stale `lf_lang=ca` cookie then reached the server on the bare `/` request; since `/` carries no URL language prefix, `detect_lang_safe()` skipped step 1 and fell through to the cookie, returning `ca` instead of `en`, causing `handle_init_redirects()` to redirect back to the Catalan front page. `lf_lang` is a non-sensitive user preference; `HttpOnly` protection is unnecessary and actively prevents the switcher from working. Changed to `false`. The inline JS cookie-write in both overlay mode and dropdown mode now also explicitly sets `domain=` to match the PHP-written cookie so both writes target the same cookie entry across browsers. (`class-context.php`, `class-lsflr-switcher.php`)
- **Language switcher overlay panel rendered as single column regardless of available width** — the panel used `position: absolute` relative to its `inline-block` wrapper, so its width was content-driven and the CSS grid collapsed to one column. Panel is now `position: fixed` at 90 vw wide, up to 40 vh tall (scrollable), positioned via a `positionPanel()` JS helper that reads `getBoundingClientRect()` on the trigger each time the panel opens. The helper handles all four placement corners — aligns the panel's leading edge with the trigger, clamps both edges to the viewport, and flips to open upward when space below is insufficient. Scroll and resize events reposition the open panel while it is visible. Grid column width uses `minmax(clamp(4.5em, 28vw, 10em), 1fr)` so mobile (~390 px) renders three columns and desktop six+. Language item text scales via `clamp(0.8rem, 3.5vw, 1rem)` for comfortable legibility at all screen sizes. (`class-lsflr-switcher.php`, `lsflr.css`)

### Added

- **Security: CodeQL `js/incomplete-multi-character-sanitization` resolved** — footnote plain-text extraction in `block-action.js` used `replace(/<[^>]*>/g, '')` which the CodeQL rule correctly identifies as defeatable by nested/malformed tags. Replaced with `DOMParser.parseFromString()` + `body.textContent` — the parsed document is never inserted into the live DOM, so no XSS risk is introduced. (`block-action.js`)
- **Security: CodeQL `js/xss-through-dom` resolved** — all eight `innerHTML` assignments that received network-sourced (AI response) or DOM-sourced (editor state) HTML in `admin.js` are now routed through a new `sanitizeHtml()` helper. The helper parses via `DOMParser` (no script execution during parsing), walks the resulting tree to remove dangerous elements (`script`, `iframe`, `style`, `form`, `embed`, `noscript`, `link`, `meta`, `template`, `object`) and attributes (event handlers, `javascript:` URLs), then re-serialises via `body.innerHTML`. Structural content markup required for diff and preview panes is preserved. (`admin.js`)
- **API Response Cache toggle** — new on/off switch in Settings → Behavior → API Response Cache, mirroring the existing Translation Memory toggle. When disabled, `CacheStore::get()` returns null and `CacheStore::set()` is skipped; all other cache operations (clear, stats, delete) continue to work normally. Defaults to enabled so existing installs are unaffected. (`CacheStore.php`, `BehaviorTab.php`, `SettingsPage.php`)
- **Contextual help panels** — WordPress-native Help tab (top-right of the Lingua Forge settings screen) with concise explanations for every settings section: Overview, Router, API Keys, Models, Translation, Glossary, and Maintenance. Sidebar links point to the relevant documentation guide. Implemented as a `SettingsHelp` class hooked on `load-toplevel_page_lingua-forge` via `WP_Screen::add_help_tab()`. (`ai/includes/Admin/SettingsHelp.php`)
- **Language switcher overlay mode** — new `overlayMode` attribute (`never` / `always` / `auto`) on the `custom/lsflr-switcher` block, `[lsflr_switcher]` shortcode, and classic widget. `always` renders a trigger button that opens a floating CSS-grid panel listing all languages; `auto` adds a `ResizeObserver` heuristic that expands the panel inline when the container is wide enough. The default is `never`, which preserves the existing dropdown behaviour exactly. Fully keyboard-navigable (Tab, Escape, focus trap), ARIA-labelled (`role="dialog"`, `aria-haspopup`, `aria-expanded`, `aria-current` on the active language), and styled with the existing `--lsflr-bg` / `--lsflr-color` FSE tokens. (`class-lsflr-switcher.php`, `lsflr.css`, `editor-switcher.js`)
- **Localisation updated** — translation strings refreshed for all 26 supplied languages (ar, ca, de_DE, el, en_US, es_ES, eu, fa_IR, fr_FR, hi_IN, hu_HU, id_ID, it_IT, ja, km, ko_KR, nl_NL, pl_PL, pt_PT, ru_RU, sv_SE, sw, th, tr_TR, ur, zh_CN). New strings introduced in 2.1.4 (overlay mode labels, help panel text, cache toggle description) are covered.

---

## [2.1.3] — 2026-06-02

### Fixed

- **Homepage always redirected to browser-language version; source-language front page at `/` unreachable after switching away** — `detect_lang_safe()` now writes the `lf_lang` cookie from both language-prefixed URLs (`/en/…` → `lf_lang=en`) and source-language URLs (`/about/` → `lf_lang=ca`), so the visitor's last explicit URL choice always wins on `/`. The switcher's inline JS also pre-writes the cookie client-side before navigating. (`class-context.php`, `class-lsflr-switcher.php`)
- **Search returning source-language results for `/?s=…&lang=de`** — `detect_lang_safe()` and `detect_lang()` used `trim(REQUEST_URI, '/')` which treated the entire query string (`?s=…&lang=de`) as the first path segment, causing step 1b to fire and return the source language before the `?lang=` GET param at step 2 was ever reached. Both methods now use `wp_parse_url(REQUEST_URI, PHP_URL_PATH)` to extract only the path component. (`class-context.php`)
- **Maintenance → Translation Memory tab showed no content** — `data-lf-tab="translation-memory"` on the nav link did not match the panel id `lf-tab-tm`, so the JS tab switcher never made the Translation Memory panel visible. Attribute corrected to `data-lf-tab="tm"`; post-clear redirect restoration updated to match. (`MaintenanceTab.php`)

### Docs

- `README.md` — added **Translations** entry to the Table of Contents (the section existed but was not linked from the index).
- `README.md` — added feature-freeze notice above the WordPress.org note: version 2.1.2/2.1.3 is considered stable and open for testing, with the feature set frozen until community feedback is gathered.

---

## [2.1.2] — 2026-06-01

### Fixed

- **Cached result in toolbar and chunk mode showed no "cached" badge and no re-translate button** — `toolbar-translate.js` and `renderChunkResult` in `admin.js` both ignored `data.cached` in the response. The toolbar now shows the "cached" badge in the result meta line and a "↺ Re-translate" button that force-refreshes the result; `renderChunkResult` in the editor meta box does the same via the existing `renderRefreshRow` helper.
- **Admin Toolbar Quick Translate (`run_chunk()`) made an API call on every request** — the chunk translation endpoint had no cache at all, so translating the same snippet twice always triggered a new API call. `CacheStore` is now used with `post_id = 0` as a synthetic key; the hash covers the chunk text, target language, provider, and model. Refinement (multi-turn improve) requests are correctly excluded. Note: Translation Memory does not update from toolbar translations — the toolbar has no source-language context, which is required to key TM entries. (`Translation.php`)
- **`CacheStore` hash did not include AI provider or model** — switching provider (Anthropic → OpenAI → Gemini) or changing the model in Settings left stale cached results until post content changed. `Config::provider()` and `Config::model($tier)` are now included in the hash inputs for all four caching features (Translation, MetaDescription, ExcerptGenerator, ContentGenerator).
- **`ExcerptGenerator` omitted `post_title` from its cache hash** — a title-only edit returned an excerpt generated from the previous title. `post_title` is now the first hash input, consistent with all other features. (`ExcerptGenerator.php`)

### Added

- **Glossary applied to FSE template, navigation, and pattern translation** — `TranslateHandler` (templates/template parts and navigations) and `PatternHandler` (CPT block patterns) did not call `Glossary::format_for_prompt()`, so user-defined terminology constraints were silently ignored for all FSE localisation operations. All three handlers now append the glossary segment to their system prompt when entries exist for the active language pair. (`TranslateHandler.php`, `PatternHandler.php`)
- **`CacheStore` hit tracking and Maintenance stats** — `wp_lingua_forge_ai_cache` now records `hit_count` and `last_hit_at` per entry (schema version 1.1, applied automatically via `dbDelta`). The Maintenance → AI Cache section shows cached entries count, cumulative hits, average hits per entry, and oldest/newest entry dates — matching the existing Translation Memory stats panel. (`CacheStore.php`, `MaintenanceTab.php`)
- **Maintenance → Translation Caching unified view** — the previously separate "AI Cache" and "Translation Memory" sections are now consolidated under a single "Translation Caching" `<h2>` with two tabs: **API Response Cache** and **Translation Memory**. Each tab carries its own description, stats table, and clear button. Stat labels are now distinct across tabs (e.g. "API calls saved by cache" vs "Block translation reuses") to prevent confusion between the two systems. The Translation Memory tab shows an "(disabled)" hint in the tab label when TM is off. (`MaintenanceTab.php`)
- **Multisite compatibility note** — `README.md` and `readme.txt` now document that per-site activation is expected to work and network-wide activation is not supported, preventing .org reviewers from flagging the absence of `is_multisite()` guards as a bug.

- **Homepage always redirected to browser-language version after visiting any other page** — `detect_lang_safe()` correctly read the active language from the URL prefix but never persisted it to the `lf_lang` cookie. On a subsequent visit to `/` (no URL prefix, no cookie), the browser `Accept-Language` header was the first signal available and overrode the visitor's last explicit URL choice. URL-detected language (step 1, path prefix) and `?lang=`-detected language (step 2) now write the `lf_lang` cookie when the existing cookie is absent or stale, so the cookie wins at step 3 on all future requests including the homepage. (`class-context.php`)

### Fixed (JS / tooling)

- **`toolbar-translate.js` ESLint errors** — unused variable `tab` (assigned but never read) removed from the re-translate click handler; inner `const reTranslateBtn` declaration removed from the `try` block to eliminate the `no-shadow` violation against the outer declaration in `fetchResult`. (`toolbar-translate.js`)
- **Maintenance Translation Memory tab showed no content** — `data-lf-tab="translation-memory"` on the nav link did not match the panel id `lf-tab-tm`, so the JS tab switcher never made the TM panel visible. Attribute corrected to `data-lf-tab="tm"`; post-clear redirect restoration updated to match. (`MaintenanceTab.php`)

---

## [2.1.1] — 2026-06-01

### Fixed

- **Hardcoded `'ca'` fallback in `Context::source_language()`** — `get_option( 'linguaforge_primary_language', 'ca' )` and the `?: 'ca'` guard were both hardcoded to Catalan. On a fresh install, any request that fired before the admin completed first-time setup (automated health checks, search-engine crawls) was silently routed as if Catalan were the source language. The fallback now derives the first two characters of the WordPress site locale via `get_locale()`, so an unconfigured install behaves consistently with the rest of the WordPress instance. (`class-context.php`)
- **`lf_lang_filter` user meta not cleared on logout or user deletion** — the admin list-screen language filter preference persisted indefinitely, potentially leaking into a new user if the ID was recycled. `Admin\Filters` now hooks `wp_logout` and `delete_user` to call `delete_user_meta`. (`class-filters.php`)
- **`glob()` in `MaintenanceTab` without readability guard** — both `glob( $dir . '*.mo' )` and `glob( $dir . '*.po' )` calls now short-circuit to `[]` when `is_readable( $dir )` returns false, preventing undefined-behaviour on restrictive server configurations. (`MaintenanceTab.php`)
- **`TemplateDefinitions::get()` WooCommerce template glob order not deterministic** — `glob()` returns files in filesystem order, which differs between Linux and macOS. The WooCommerce template directory scan result is now passed through `natsort()` before iteration, guaranteeing stable alphabetical order across environments. (`TemplateDefinitions.php`)
- **`readme.txt` server-timeout entry still cited 120-second timeout** — the FAQ entry "AI requests time out on long content" referenced the old 120 s value. Updated to 300 s and added a note about the `linguaforge_ai_retry_policy` filter. (`readme.txt`)

### Added

- **Lingua Forge interface translated into 26 languages** — the plugin now ships `.po` / `.mo` / `.l10n.php` files for Arabic (`ar`), Basque (`eu`), Catalan (`ca`), Chinese Simplified (`zh_CN`), Dutch (`nl_NL`), English US (`en_US`), French (`fr_FR`), German (`de_DE`), Greek (`el`), Hindi (`hi_IN`), Hungarian (`hu_HU`), Indonesian (`id_ID`), Italian (`it_IT`), Japanese (`ja`), Khmer (`km`), Korean (`ko_KR`), Persian (`fa_IR`), Polish (`pl_PL`), Portuguese (`pt_PT`), Russian (`ru_RU`), Spanish (`es_ES`), Swahili (`sw`), Swedish (`sv_SE`), Thai (`th`), Turkish (`tr_TR`), and Urdu (`ur`). Use the plugin in your own language right out of the box. (`languages/`)

### Dev tooling

- **`FeatureControllerCapabilityTest`** — new unit test asserting that `FeatureController::required_capability()` never returns an empty string for any registered feature slug or endpoint, and that the `edit_posts` safety net catches both an empty stored option and a filter returning `''`. (`tests/unit/FeatureControllerCapabilityTest.php`)

---

## [2.1.0] — 2026-05-30

### Refactored

- **RouterTab god class split** — `RouterTab.php` (formerly ~2,015 lines) has been decomposed into focused, single-responsibility classes:
  - `FseLocalisation\TemplateDefinitions` — template type registry (pure static data).
  - `FseLocalisation\PartDiscovery` — `part_exists()`, `collect_parts_from_blocks()`, `discover_template_parts()`.
  - `FseLocalisation\PatternExpander` — `expand_pattern_refs()` (expands `wp:pattern` pointers before AI translation).
  - `FseLocalisation\ScaffoldHandler` — `ajax_scaffold_template()`, `ajax_scaffold_template_part()`.
  - `FseLocalisation\TranslateHandler` — `ajax_translate_fse_content()`, `ajax_translate_fse_navigation()`.
  - `FseLocalisation\LinkFixer` — `ajax_fix_fse_links()`.
  - `FseLocalisation\PartRefFixer` — `replace_part_slug_in_blocks()`, `ajax_fix_fse_parts()`, `ajax_fix_fse_nav_refs()`.
  - `Settings\Tabs\Sections\TemplatesSection` — FSE templates scaffold table render.
  - `Settings\Tabs\Sections\TemplatePartsSection` — template parts scaffold table render.
  - `Settings\Tabs\Sections\NavigationsSection` — navigation translation table render.
  - `RouterTab` is now ~350 lines: tab contract (`slug`, `label`, `render_content`), language-panel dispatcher, language-pack install/list handlers, and `register_fse_hooks()`.
  - All AJAX hook registrations consolidated: `SettingsPage` calls `RouterTab::register_fse_hooks()` instead of eleven individual `add_action` calls.

### Added

- **`router-tab.js` split** — the 630-line JS monolith is decomposed into four focused files mirroring the PHP split: `fse-scaffold.js`, `fse-translate.js`, `fse-link-fixer.js`, `fse-part-fixer.js`. `router-tab.js` now handles only tab panel switching and language-pack install (~115 lines). All four files depend on `linguaforge-router-tab` so the shared `lfRouterTab` data object is always available.

- **CPT-specific FSE template scaffold slots** — `TemplateDefinitions::get()` now appends `single-{cpt}` and `archive-{cpt}` entries dynamically for each registered public CPT whose base template is actually shipped by the active theme (`get_block_templates()` check). Labels use the CPT's registered singular/plural names. The scaffold, translate, link-fix, and part-fix workflows all pick up the new slots automatically.

- **CPT-scoped block pattern translation** — `FseLocalisation\PatternDiscovery` discovers all registered block patterns whose `postTypes` intersect with public custom post types. `FseLocalisation\PatternHandler` adds `wp_ajax_linguaforge_translate_pattern`: AI translates the pattern content (same system-prompt rules as FSE templates) and persists the result in the `linguaforge_pattern_translations` option, keyed by pattern name and target language. `Settings\Tabs\Sections\PatternsSection` renders a per-language table in the Language Setup section with Translate / Re-translate / View toggle. `fse-patterns.js` handles the button interactions. The section is silently skipped when no CPT-scoped patterns are registered or AI is not configured.

- **FSE Page List block — language-scoped** — `core/page-list` now shows only the current language's pages, both on the public frontend and in the Site Editor / block editor. Previously, all languages' pages appeared in every navigation. Root cause: `core/page-list` calls `get_pages()` directly with no filterable query-args hook (confirmed WP 6.4–6.9), and `pre_render_block` is never triggered for `core/page-list` because the navigation block's PHP callback creates it dynamically and calls `$inner_block->render()` directly (confirmed `class-wp-block.php`), bypassing the hook. Fix: `QueryFilter` uses two complementary strategies. (1) Frontend — a permanent `get_pages` filter keyed on `LF_LANG` that is always live: covers both navigation posts with a `ref` and auto-add navigations (no `wp_navigation` post). (2) Admin / REST — `pre_render_block` tracks `core/navigation` top-level blocks (which do fire), captures the language of the referenced navigation post via `_lf_lang` meta, falls back to slug-suffix detection (e.g. `navigation-de` → `de`) for posts created before this fix, and finally defaults to the source language for untagged navigations like "Navigation (default)". The stored language arms a one-shot `get_pages` result filter that is already live when the navigation PHP callback calls `get_pages()` during render; it disarms after first use. Both strategies treat untagged pages as source-language content. Skipped in WP-CLI. `TranslateHandler::ajax_translate_fse_navigation()` now tags newly created and updated navigation posts with `_lf_lang` so future admin renders use the meta path directly. `wp linguaforge fix_nav_lang [--dry-run]` backfills `_lf_lang` on existing navigation posts created before this fix.

- **WooCommerce FSE templates in scaffold table** — `TemplateDefinitions::get()` now discovers and exposes WooCommerce block templates (Cart, Checkout, Order Confirmation, Product Search Results, taxonomy templates, etc.) so they appear as columns in the scaffold table and can be localised via the existing scaffold → translate → fix-links → fix-parts pipeline. Discovery uses two complementary strategies: (1) `get_block_templates()` partitioned by `$tpl->theme` — plugin-owned templates (those with a theme value other than the active theme and source other than `custom`/`user`) are collected alongside theme-owned ones; (2) a filesystem scan of `WC_ABSPATH/templates/templates/*.html` as a reliable fallback, since `get_block_templates()` returns nothing for WooCommerce templates in CLI and some admin hook contexts. CPT-specific slots (`single-product`, `archive-product`) continue to be handled by the existing CPT loop. `ScaffoldHandler::ajax_scaffold_template()` now falls back to plugin-owned templates when fetching source content for WooCommerce slugs the active theme does not own. A new `linguaforge_fse_template_definitions` filter allows third-party code to add, remove, or rename entries.

- **Loco Translate — copy to safe storage** — When Loco Translate is active, **Settings → Maintenance → Language Overrides** now shows a subsection listing all custom `.mo`/`.po` files from `wp-content/languages/loco/plugins/` and `…/themes/`. A "Copy to safe storage" button per row copies the files into the Lingua Forge i18n-overrides directory (`wp-content/uploads/lingua-forge/i18n-overrides/`), which survives WP core updates, plugin reinstalls, and Loco Translate removal. Already-copied files are flagged "✓ In safe storage". The section is hidden when Loco Translate is not installed.

- **Site Editor navigation language filtering (canvas + sidebar)** — the Site Editor now shows only the current language's pages in both the navigation canvas and the sidebar page picker. Covers two navigation types by design: "Pages"-type (auto-add) navigations have their `core/page-list` output scoped to the navigation's language; explicit (edited) navigations contain admin-chosen links that are left untouched. Three complementary strategies ensure the correct language is known before the first `/wp/v2/pages` REST request fires:
  1. **PHP synchronous init** — `Scripts::enqueue_admin_lang_scripts()` resolves the navigation language at page load from four `$_GET` URL formats used by the Site Editor (`?p=/wp_navigation/{id}`, `?postType=wp_navigation&postId={id}`, `?p=/wp_template/{theme}//{slug}`, `?p=/wp_template_part/{theme}//{slug}`) and injects it as `lfNavLang.lang` via `wp_add_inline_script('before')`. For template/part URLs, the language is resolved from `_lf_lang` post meta first, falling back to the slug suffix (e.g. `order-confirmation-ca` → `ca`).
  2. **JS SPA navigation** — `nav-lang-filter.js` re-resolves the language on every `pushState`/`replaceState`/`popstate` event, but only when the navigation post ID or template slug actually changes (guarded by `lastNavId` / `lastTplSlug` comparison). Navigation post URLs trigger an async REST fetch of nav meta; template/part URLs use synchronous slug-suffix extraction (no round-trip); all other Site Editor URLs (panel opens, block selections, canvas transitions) are suppressed.
  3. **Block selection watcher** — `wp.data.subscribe` watches block editor selection. When the user selects a `core/navigation` block (or any child of one) inside a template or template part, the navigation's language is fetched immediately via REST before the pages sidebar panel opens. Covers the residual case where a template's own `_lf_lang` differs from the language of an embedded navigation block.
  `QueryFilter::register_rest_nav_lang_meta()` exposes `_lf_lang` as `meta.lf_lang` on authenticated `wp_navigation` REST responses and registers `lf_lang` as a valid `/wp/v2/pages` collection parameter. `nav-lang-filter.js` middleware appends `?lf_lang=<code>` to every matching pages request and invalidates the `core` store's `getEntityRecords` resolution cache on language change so the sidebar re-fetches automatically.

- **Editor Preview Language Switcher** — a globe icon button (`dashicons-admin-site`) is injected into `.interface-pinned-items` in both the block post editor and the Site Editor (the same slot used by Quick Translate). Clicking it opens a compact dropdown listing all configured languages; the active one is marked ✓. Selecting a language switches the current admin/editor user's WordPress locale (`wp_update_user locale`) and reloads the editor so the canvas, meta boxes, and plugin `.mo` translations (including WooCommerce order-confirmation blocks) all render in the chosen language. Uses the same DOM-injection + MutationObserver pattern as `editor-translate.js` — `registerPlugin` / SlotFill is intentionally avoided as it has no reliable slot for the top toolbar across WP versions. Implemented in `admin-locale-switcher.js` + `ajax_set_user_locale` AJAX handler; enqueued on `post.php`, `post-new.php`, and `site-editor.php`.

- **Template picker shows all templates, filtered by post language** — the Template meta box in the block editor sidebar previously limited its list to templates whose slugs started with `page-` or `single-` and whose registered `post_type` matched the current post. WooCommerce templates (Order Confirmation, Cart, Checkout, etc.) and any other plugin-registered templates were invisible. The meta box now calls `get_block_templates()` with no `post_type` filter, shows human-readable template titles instead of raw slugs, and scopes the list to templates whose language suffix matches the current post's language (e.g. a CA page sees "Order Confirmation CA" but not "Order Confirmation DE"). Generic templates with no language suffix are always included. Implemented in `MetaBoxes::render_template_meta_box()`.

- **WooCommerce product AI translation — `post_excerpt` (Short Description) propagated** — the AI translation pipeline now translates and applies the WooCommerce Short Description (`post_excerpt`) end-to-end. PHP side: `Translation::run()` extracts `post_excerpt` from the source post, includes it in the AI prompt, and stores `translated_excerpt` in the result; `TranslationTrigger` and `PostListColumn::ajax_fill_missing()` write it to the translated post on create/update; `AbstractTranslateCommand` handles it in the CLI path; `ajax_import_translation` writes it on manual import. JS side: `admin.js` reads `translated_excerpt` from the REST response, passes it through `renderContentResult()` → `openApplyDiffModal()` → `performApplyFromModal()`, and applies it to both Gutenberg (`editPost({ excerpt })`) and the classic TinyMCE `excerpt` editor instance.

- **Source Footnotes meta box hidden on WooCommerce products** — `MetaBoxes::add_source_footnotes_meta_box()` now iterates public post types and skips those in the exclusion list (default: `['product']`). The list is filterable via `linguaforge_source_footnotes_excluded_post_types`. The Source Footnotes box is a Gutenberg-only feature; WooCommerce products use the classic editor and do not support it.

### Fixed

- **Navigation blocks inside templates showing pages from all languages in the Site Editor canvas** — three bugs combined: (1) `filter_page_list_frontend()` had an unconditional `is_admin()` early return that bailed before the `pending_page_list_lang` consume-once path could run — the canvas is `is_admin()` context, so the language armed by `arm_page_list_lang_filter()` was silently discarded. Fixed by checking `pending_page_list_lang !== null` first, before the admin/REST bailouts. (2) `Scripts::enqueue_admin_lang_scripts()` was not parsing the `?p=/wp_template/{theme}//{slug}` URL format that the Site Editor actually uses when opening a template — only the `?postType=wp_template&postId=N` variant (which the Site Editor does not emit) was handled. Added a `preg_match` for the `?p=` format, resolving `lfNavLang.lang` from `_lf_lang` meta (preferred) or slug suffix (fallback) before the first page request fires. (3) `nav-lang-filter.js`'s `maybeInitAsync()` function did not extract the language from the template slug in the URL — when the URL had no navigation post ID, `currentLang` was cleared even on template URLs. Added `getTemplateSlug()` helper and a synchronous slug-suffix branch so language is resolved without a REST round-trip on direct template access.
- **`add_post_type_support('wp_navigation','custom-fields')` firing on unauthenticated REST requests** — the call is now gated on `current_user_can('edit_posts')` inside `QueryFilter::register_rest_nav_lang_meta()`. Previously it fired on every `rest_api_init` including public GET requests, causing WP REST to expose all `show_in_rest` postmeta in navigation REST responses for unauthenticated visitors. `register_post_meta` continues to run unconditionally.
- **`extractLangFromSlug` regex extracting the wrong token from multi-word slugs** — the regex `/-([a-z]{2,}(?:-[a-z]{2,})?)$/` was too greedy: applied to `order-confirmation-ca` it captured `confirmation-ca` (because `confirmation` satisfies `[a-z]{2,}`) rather than the intended `ca`. The first group is now capped at three characters (`[a-z]{2,3}`) to match ISO 639-1 (2-char) and ISO 639-2 (3-char) codes while rejecting English words; the optional region suffix uses `[a-z]{2,4}` to cover `zh-tw`, `pt-br`, and similar variants. The same cap applied to both equivalent `preg_match` calls in `Scripts::enqueue_admin_lang_scripts()` (PHP). The REST param validation pattern `^[a-z]{2,}(-[a-z]{2,})?$` is unchanged — it validates user-supplied values, not slugs.
- **Search template override — infinite recursion and 512 MB memory exhaustion** — `Search\Query::override_search_template()` was hooked on `get_block_templates` but called `get_block_templates()` internally, causing unbounded recursion on every request with the search template override active. A `$in_override_search_template` reentrancy guard now short-circuits re-entrant calls immediately.
- **WooCommerce stock writes bypass on translated products** — `StockRouter` now hooks `woocommerce_update_product_stock_query` (fired by WooCommerce's direct-SQL stock update path for `wc_update_product_stock()`) in addition to the `update/add_post_metadata` filters. Previously, stock decrements triggered by order processing went through WooCommerce's direct-SQL path and bypassed `StockRouter` entirely, leaving translated product stock stale. The hook routes the query to the source product ID and clears the lookup-table cache.
- **`wc_product_meta_lookup` not refreshed after stock route** — `StockRouter::clear_source_meta_cache()` now issues a targeted `$wpdb->update()` on `wc_product_meta_lookup` to sync `stock_quantity` and `stock_status` for the source product after a stock write, keeping WooCommerce's denormalised lookup table consistent.
- **Dead `_stock_qty` key in `StockRouter::STOCK_WRITE_KEYS`** — `_stock_qty` was removed from WooCommerce in 3.x; the entry in the intercept key list was dead and has been removed.
- **`Glossary::ensure_table()` trusting stale DB version option** — if the `linguaforge_ai_glossary_db_version` option was present but the glossary table had been dropped (e.g. DB restore, server migration), `ensure_table()` would skip `dbDelta` and all glossary queries would silently fail. A `SHOW TABLES LIKE` verification step is now performed before trusting the option; a missing table falls through to `dbDelta` to recreate it.
- **`pending_page_list_lang` state bleed on interrupted block rendering** — `QueryFilter::filter_page_list_frontend()` now reads and nulls `$pending_page_list_lang` atomically (consume-once pattern) so the property cannot leak into a subsequent unrelated `get_pages()` call if `clear_nav_lang_after_render()` never fires.
- **`TemplateDefinitions::get()` calling `get_block_templates()` on every scaffold table render** — result is now cached with `static $cache`; the `linguaforge_fse_template_definitions` filter is applied once on cache fill. Eliminates repeated full-template-set DB queries on admin pages that render the FSE scaffold table.
- **Scaffolded templates and template parts not tagged with `_lf_lang`** — `ScaffoldHandler::ajax_scaffold_template()` and `ajax_scaffold_template_part()` now call `update_post_meta($post_id, '_lf_lang', $lang)` after `wp_insert_post`. Without this tag, the theme-switch notice that counts orphaned localised templates always showed zero, and no language badge appeared in the admin.
- **`$_GET` reads in `QueryFilter::current_navigation_post_id()` missing `wp_unslash()`** — three `$_GET` reads (`postId`, `post_id`, `id`) now pass through `sanitize_text_field( wp_unslash(...) )` before the integer cast.
- **Lang column rendered twice on product and CPT list screens** — `manage_posts_custom_column` is a catch-all that fires for every post type including CPTs; combined with the type-specific `manage_{$pt}_posts_custom_column` hook registered in `register_cpt_column_hooks()`, `render_lang_column` was called twice per row, doubling the language badge and Retranslate button. Replaced `manage_posts_custom_column` with the specific `manage_post_posts_custom_column` (covers only the `post` post type); `manage_posts_columns` column-header filter replaced with `manage_post_posts_columns` for the same reason.
- **Language filter dropdown not filtering products in the admin product list** — the WooCommerce post-type skip in `QueryFilter::handle_pre_get_posts()` was unconditional, so selecting a language from the Lang filter on `Products → All Products` had no effect. The skip now applies only when no `lf_lang_filter` is active; orders, coupons, and subscriptions continue to be skipped unconditionally as they are not translatable content.
- **`product_variation` missing from admin `$wc_types` skip list** — `QueryFilter`'s admin post-list branch skipped WooCommerce post types to avoid conflicting with WC's own query pipeline, but `product_variation` was absent from the list. Added alongside `product`, `shop_order`, `shop_coupon`, and `shop_subscription`.
- **WooCommerce product AI translation — classic editor apply path** — `admin.js` `isGutenbergActive()` now detects the Gutenberg editor by calling `getCurrentPostId()` on the `core/editor` store rather than checking for `wp.data` presence (which is truthy on all admin pages, including the WooCommerce classic product editor). A new `applyToClassicEditor()` helper consolidates all TinyMCE/textarea apply logic (content, title, excerpt, meta description). The diff preview modal is bypassed for non-Gutenberg screens, applying directly via TinyMCE `setContent()`.
- **`QueryFilter` empty `if` body — PHPCS `EmptyStatement`** — the `if ($pending_page_list_lang !== null) { // fall-through }` guard in `filter_page_list_frontend()` had an empty body that PHPCS flagged. Condition inverted: the `is_admin()` and `REST_REQUEST` early-returns now sit inside `if ($pending_page_list_lang === null)`, eliminating the empty branch.
- **`tests/bootstrap.php` `error_reporting()` PHPCS warnings** — the `error_reporting()` calls around the WP test bootstrap include (needed to suppress a harmless `E_WARNING` on `WP_MEMORY_LIMIT` redefinition in `@runInSeparateProcess` child processes) are now wrapped with scoped `phpcs:disable/enable` pragmas for `WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting` and `WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting`.
- **PHPStan: `add_locale_admin_bar_node()` `href` argument type** — `WP_Admin_Bar::add_node()` expects a `string` for `href`; passing `false` was a PHPStan type error. Changed `'href' => false` to `'href' => ''` in `class-meta-boxes.php`.
- **ESLint `no-undef` for PHP-injected `lfNavLang` global** — `nav-lang-filter.js` lacked a `/* global lfNavLang */` declaration, causing ESLint to report `lfNavLang` as undefined. Declaration added at the top of the file.
- **PHPCS `OutputNotEscaped` / `MissingTranslatorsComment` in `MetaBox.php`** — the `sprintf( esc_html__(…), $linguaforge_preset_label )` call was flagged because PHPCS cannot statically trace that `$linguaforge_preset_label` is already `esc_html`'d at the assignment site. Added an inline `phpcs:ignore` on the same line as the `echo` (so the `translators:` comment immediately above remains adjacent to `esc_html__()` as required by the `MissingTranslatorsComment` rule).

### Dev tooling

- **Combined code coverage pipeline** — unit and integration coverage can now be merged into a single report. `composer coverage:setup` installs pcov in the wp-env `tests-cli` container (run once after `npm run env:start`). `composer coverage:run` runs both suites with Clover XML output to `coverage/unit/` and `coverage/integration/`. `composer coverage:merge` normalises the two Clover files (which have different absolute paths — local vs container) and writes a combined report to `coverage/combined/clover.xml` + `coverage/combined/summary.txt`. `composer coverage` runs the full pipeline end-to-end.

- **E2E `ai-translation.spec.js` — self-contained fixture and timeout fixes** — Test 1 (REST translation endpoint) now sets `test.setTimeout(200_000)` and passes `timeout: 180_000` to `page.request.post()` to accommodate large-content AI calls that exceed Playwright's 30 s default. Test 2 ("Translate missing" button) creates a fixture `page` post via `POST /wp/v2/pages` with `_lf_lang: 'en'` and a unique `_lf_trid` at test start, targets the `lf-fill-missing` button by `data-post-id` of that specific post, and deletes the post in a `finally` block — eliminating the dependency on `npm run env:seed`. `SETTINGS_URL` corrected to `admin.php?page=lingua-forge`.

---

## [2.0.1] — 2026-05-29

### Fixed

- **Translate / Review panel closes on block focus change** — the panel now closes automatically when the user focuses a different block in the editor. Previously the panel stayed open after switching blocks, requiring a manual dismiss. Implemented via `wp.data.subscribe()` watching `getSelectedBlockClientId()` against the panel's `activeClientId`.

---

## [2.0.0] — 2026-05-29

### Added

- **Custom Post Type support (Phase 0)** — all public CPTs (e.g. WooCommerce `product`, any third-party CPT) now receive the full Lingua Forge admin layer: Lang column with outdated/missing indicators and Retranslate/Translate-missing buttons, language and outdated-status filter dropdowns, quick-edit language control, AI translation metabox, FSE template selector metabox, Translation Memory cache eligibility, and link-fixer scan with Fix Links button. The eight hard-coded `['post', 'page']` guards across five files have been replaced with dynamic public-CPT detection. Three new opt-out filters are available: `linguaforge_column_post_types`, `linguaforge_ai_metabox_post_types`, and `linguaforge_link_fixer_post_types`.
- **FSE template auto-assignment for CPTs** — `Sync::resolve_template_for_lang()` now derives `single-{post_type}-{lang}` for CPTs (e.g. `single-product-de`), extending language-specific template auto-assignment and back-compat detection beyond `post` and `page`.
- **WooCommerce integration — Phase 1 (shared-stock delegation model)** — WooCommerce `product` posts are now fully supported. Translated products carry only content fields (title, description, excerpt, meta description); all operational data (price, SKU, stock, dimensions, images, variations, taxonomy assignments, upsells) is read transparently from the source-language product at runtime via a `get_post_metadata` delegation filter. No meta copying, no SKU uniqueness issues, no stock sync complexity. Five new integration classes under `ai/includes/Integrations/WooCommerce/`: `MetaDelegate` (`get_post_metadata` delegation with `_product_attributes` own-value exception), `StockRouter` (`update/add_post_metadata` routing for stock writes), `VariationDelegate` (`pre_get_posts` delegation of `product_variation` children), `TaxonomyDelegate` (`wp_get_object_terms` delegation for `product_cat`, `product_tag`, `product_type`, `pa_*`), `CatalogQuery` (`woocommerce_product_query` language filter for secondary WC product queries).
- **`linguaforge_cpt_create_allowed` filter** — allows integrations to block translated-post creation for a given post type until their delegation layer is active. Used in `PostListColumn::ajax_fill_missing()`. Defaults to `true`.
- **`linguaforge_wc_delegate_post_types` filter** — controls which post types participate in operational-meta delegation and stock-write routing. Defaults to `['product']`.
- **`linguaforge_wc_integration_active` action** — fires after the WooCommerce integration initialises for the current request.
- **Classic theme language switcher — `[lsflr_switcher]` shortcode + `Lsflr_Switcher_Widget`** — the language switcher is now available on all WordPress themes without a block widget area. The shortcode `[lsflr_switcher]` accepts `direction`, `show`, and `customLabel` attributes and can be placed in any theme that supports shortcodes. `Lsflr_Switcher_Widget` (Appearance → Widgets) exposes the same options via the classic widget form. Both delegate to the existing `Switcher::render_switcher()` method and produce identical output to the block.
- **WooCommerce integration — Phase 1b (translated term names)** — WooCommerce category, tag, product-type, and attribute-value term names now display in the visitor's language. Translated names are stored as termmeta under `_lf_term_name_{lang}` (e.g. `_lf_term_name_es`) and are entered via new fields on the term add/edit screens for every WC taxonomy (`product_cat`, `product_tag`, `product_type`, `pa_*`). `TermNameFilter` hooks into WordPress's `term_name` filter at priority 10; it resolves the current request language via `Router::detect_lang()` and swaps the name when a translated entry exists. `TermNameAdmin` registers the term-edit screen fields after WooCommerce has registered its `pa_*` taxonomies (`init` priority 15) and is skipped on non-admin requests. Implementation notes: `TermNameFilter` is registered with `$accepted_args = 4` to handle both WordPress `term_name` call signatures — `WP_Term` object from `edit-tags.php` and integer `$term_id` from `sanitize_term_field()` (Trac #45085); a `$context` guard restricts translation to the `'display'` context only, leaving `'edit'`, `'db'`, and `'raw'` untouched to prevent stored value corruption. `TermNameAdmin` save hooks use `$accepted_args = 1` (`$term_id` only); nonce field sanitized via `sanitize_text_field( wp_unslash(...) )`.
- **Third-party integration API** — Lingua Forge now provides a stable, documented surface for external plugins to build on. Five new hooks: `linguaforge_loaded` action (fires after all public wrapper functions are defined — the safe attach point for integrations); `linguaforge_translation_content` filter (intercept or modify the AI translation payload before it is written to cache, receives `$payload`, `$post_id`, `$target_language`); `linguaforge_translation_complete` action (fires after a CLI translation run creates or updates a translated post, receives `$new_post_id`, `$source_post_id`, `$target_lang`); `linguaforge_trid_changed` action (fires in `TridGroup::set_trid()` only when the TRID UUID actually changes, receives `$post_id`, `$new_trid`, `$old_trid`); `linguaforge_switcher_output` filter (wrap or replace the HTML produced by the language switcher block, shortcode, and widget, receives `$html`, `$langs`, `$atts`). Two public read-only REST endpoints registered under the `lingua-forge/v1` namespace: `GET /languages` (returns code + label for each configured language) and `GET /post/{id}/translations` (returns a language → permalink map for published translations of a post, with visibility gating via `current_user_can`). New public PHP function `linguaforge_trigger_translation( int $source_post_id, string $target_lang, array $params = [] ): int|WP_Error` — programmatically runs the full AI translation pipeline (AI call → create-or-update translated post → TRID link → cache clear → `linguaforge_translation_complete` action). New "Writing a third-party integration" section added to `CONTRIBUTING.md` covering the Bootstrap class pattern, full hook reference, and `linguaforge_trigger_translation()` usage.

### Fixed

- **`register_block_type()` — deprecated `editor_script` string key** — `class-lsflr-switcher.php` was using the pre-6.0 `editor_script` string key, which emits a `_deprecated_argument` notice in debug mode since WP 6.3. Changed to `editor_script_handles` (array) as required since WP 6.0.
- **Template metabox — filesystem-bundled templates missing from dropdown** — `class-meta-boxes.php` was using `get_posts(['post_type' => 'wp_template'])` which only returns DB-stored (customised) templates; theme-bundled templates are invisible until edited in the Site Editor. Replaced with `get_block_templates(['post_type' => ...])` which returns both DB and filesystem templates. Template slug now read from `WP_Block_Template->slug` instead of `WP_Post->post_name`.
- **Dead fallback for WP < 5.8 using deprecated `get_page_by_path()`** — `class-sync.php::template_exists()` had a dead `get_page_by_path()` fallback branch behind a `function_exists('get_block_templates')` guard. Minimum WP is 6.4; `get_block_templates()` is always available. Removed the guard and the deprecated fallback.
- **Search template override — private internal function and deprecated API** — `class-query.php::override_search_template()` was calling `get_page_by_path()` (deprecated WP 6.3) and `_build_block_template_result_from_post()` (WordPress-private, leading-underscore). Both replaced with a single `get_block_templates(['slug__in' => [$lang_slug]])` call; `get_block_templates()` already returns proper `WP_Block_Template` objects covering both DB-stored and filesystem templates.
- **Outdated-translations filter — slow `NOT EXISTS` meta_query subquery** — `class-query-filter.php` was using an `OR [NOT EXISTS, = 0]` pattern for `_lf_translation_source_updated_at`. Since this is a timestamp field, any `= 0` row is semantically equivalent to absent. Simplified to a single `NOT EXISTS` clause, which uses the more efficient internal path added in WP 6.1.

### Improved

- **`readme.txt` — added `== Compatibility ==` section** — clarifies that WordPress 6.4+ is required for all core features, while the WooCommerce integration requires WP 6.9+ and WooCommerce 9.0+ (it is safely inactive on earlier versions). Plugin header `Requires at least` remains 6.4.
- **Dev tooling — memory limits** — PHPStan set to 2G in `dev/composer.json`; PHPUnit reduced from 2G to 512M in `dev/phpunit.xml.dist`.

---

## [1.8.4] — 2026-05-28

### Improved

- **"Retranslate" button now available on all TRID-linked posts** — previously the "Retranslate" selector and button only appeared next to the ⚠ outdated indicator, meaning posts whose translations existed but were not flagged as outdated offered no way to force a fresh translation from the list screen. A new `lf_lang_column_retranslate` action hook now fires unconditionally for every post in the Lang column, so the button is always present whenever a post has at least one other language in its TRID group — regardless of sync status. The `lf_lang_column_outdated` hook is retained and continues to fire for the ⚠ indicator so any third-party code hooking it is unaffected.

---

## [1.8.3] — 2026-05-27

### Fixed

- **Double-update badge after WordPress upgrade** — `check_for_update()` now reads the installed version from the plugin file header on disk via `get_file_data()` instead of from the `LINGUAFORGE_VERSION` constant. WordPress re-runs the `pre_set_site_transient_update_plugins` filter immediately after an upgrade while the old constant is still in memory for the current request, causing the update entry to be re-injected even though the file on disk already has the new version. Reading from disk closes this race and eliminates the need to click Update a second time.

### Documentation

- **Update checker attribution corrected** — self-hosted automatic update checker is now correctly listed under 1.7.2 in all docs (`CHANGELOG.md`, `readme.txt`, `README.md`, `docs/lf-update-manifest.php`). Was incorrectly attributed to 1.7.0.
- **Feature freeze notice** — added a short collaboration and testing callout at the top of `README.md`.

---

## [1.8.2] — 2026-05-27

### Added

- **"Retranslate" button with language selector in the Posts/Pages list** — outdated target-language posts (those showing the ⚠ indicator) now display a compact "From [lang]" dropdown listing all other language versions in the TRID group, followed by a "Retranslate" button. The editor selects which language version to translate from, clicks the button, and the translation is refreshed via the same AI pipeline as the CLI `retranslate` command — stale cache cleared, `Translation::run()` called with `force_refresh: true`, outdated flag reset, meta description regenerated. Same-language retranslation is rejected both client-side and server-side. The button is injected via the new `lf_lang_column_outdated` action hook, keeping the AI module decoupled from the language-router module.

### Improved

- **Lang column inline layout** — "Translate missing" and "Retranslate" buttons now render on the same line as the language indicator instead of on a new line below it. The retranslate selector + button use `inline-flex` for compact, wrapping-free presentation.

---

## [1.8.1] — 2026-05-27

### Added

- **"Translate missing" button in the Posts/Pages list** — a one-click button now appears in the existing "Lang" column next to the ⭕ missing-language indicator for any source-language post that lacks one or more target-language translations. Clicking it fires all missing AI translations in a single AJAX request, creates TRID-linked posts (inheriting source status), assigns FSE templates where applicable, and replaces the indicator with "✓ Done" on success — without leaving the list screen. The button is injected via the new `lf_lang_column_missing` action hook so the AI module stays decoupled from the language-router module.

---

## [1.8.0] — 2026-05-27

### Fixed

- **Translations metabox — spurious "Override" button after language switch** — after `ajax_set_language()` updated `_lf_lang` post meta and returned, the TRID object-cache entry (stored under key `trid_{uuid}` in the `lf_translations` group) was not cleared. The normal cache-clear hook (`handle_cache_clear`) fires on `wp_after_insert_post`, which is not triggered by `update_post_meta()` alone. On the immediate page reload the stale cached group still showed the post under its old language, causing the Translations metabox to render an Override button for a language that was in fact the same post. Fix: explicit `TridGroup::clear_translation_cache()` call in `ajax_set_language()` after `set_lang()`.
- **PHPCS `MissingTranslatorsComment` in Translations metabox** — the `/* translators: */` comment preceding `_n()` in `render_translations_meta_box()` was separated from the function call by a blank line, breaking PHPCS's adjacency check. Moved inside the `sprintf()` call directly above `_n()`.
- **PHPCS `EscapeOutput.OutputNotEscaped` on multi-line `wp_dropdown_pages()` calls** — single-line `phpcs:ignore` only suppresses the first line of a multi-line expression. Converted both occurrences in `class-meta-boxes.php` to `phpcs:disable` / `phpcs:enable` block pairs.
- **PHPCS `SlowDBQuery` on `wp_dropdown_pages()` `meta_key`/`meta_value` args** — `_lf_lang` is an indexed, intentional meta key used for per-language page filtering. Extended the existing `phpcs:disable` blocks to also suppress `slow_db_query_meta_key` and `slow_db_query_meta_value` with an explanatory comment.

### Improved

- **Router tab — "Add Language" flow** — after a language pack is successfully downloaded, the server now calls `flush_rewrite_rules()` immediately so the new language's URL prefix is registered before the response is sent. The client shows a "Reloading page…" notice and reloads after 1.5 seconds, so the Active Languages chips and template/parts/navigations tables update automatically without a manual refresh.
- **Router tab — per-language tabbed UI for Templates, Parts, and Navigations** — the previous layout rendered all secondary languages side-by-side in three large cross-language tables, which became unmanageable with many languages. The three section methods (`render_templates_section`, `render_parts_section`, `render_navigations_section`) have been replaced with a tabbed panel layout: one tab per secondary language, each panel containing that language's Templates, Parts, and Navigations tables. The active tab is preserved across page loads via `sessionStorage`. All existing delegated JS event handlers work unchanged — every `data-lang`, `data-slug`, `data-base`, and `data-post-type` attribute is preserved in the new layout.

---

## [1.7.2] — 2026-05-27

### Added

- **Self-hosted update checker** — `Linguaforge_Updater` class hooks into WordPress's plugin-update machinery so **Plugins → Installed Plugins** surfaces update badges and one-click updates directly from lingua-forge.com, without the plugin being listed on WordPress.org. The update manifest is fetched from a REST endpoint on lingua-forge.com and cached for 12 hours (error results cached 1 hour via a sentinel object to avoid hammering the server on transient failures).

### Improved

- **"View details" link in the plugin row** — clicking it opens the standard WordPress plugin-information modal (thickbox) populated with the description, changelog, and installation sections from the manifest. The method deduplicates: if WordPress has already added its own thickbox link no second link is added.
- **"Visit plugin site" link guaranteed in the plugin row** — the GitHub repository link (`https://github.com/leotiger/lingua-forge`) is now explicitly restored when WordPress drops it for self-hosted plugins not tracked in the .org update transient.

### Fixed

- **Plugin info modal graceful fallback** — when the remote manifest is temporarily unreachable, `plugins_api` now returns a minimal info object (name, current installed version, author, homepage, short description) instead of `false`. This prevents WordPress from falling through to the .org API and showing "Plugin not found."
- **PHPStan level 5 — `$transient` typed as `\stdClass`** — `check_for_update()` previously declared `object $transient`, which PHPStan does not allow dynamic property access on. Changed to `\stdClass` so `$transient->response` and `$transient->no_update` resolve cleanly.
- **PHPStan — `includes/` missing from analysis paths** — `dev/phpstan.neon.dist` now includes `../includes` in `paths`, so PHPStan resolves `Linguaforge_Updater` when analysing `lingua-forge.php`.

### Maintenance

- **Plugin Check — update-checker codes suppressed** — `plugin_updater_detected` and `update_modification_detected` added to `--ignore-codes` in `dev/composer.json`. These are WordPress.org-specific guards not applicable to self-hosted plugins.

---

## [1.7.1] — 2026-05-26

### Fixed

- **MetaBox — Target Language dropdown shows only instance languages** — `Translation::get_ui_fields()` was passing `self::get_languages()` (the full hardcoded AI language list) as dropdown options instead of filtering to languages active on the server instance. Now uses `array_intersect_key( self::get_languages(), array_flip( linguaforge_languages() ) )`, identical to the Quick Translate modal source. Additionally, `MetaBox::inject_instance_languages()` is registered on `linguaforge_translation_languages` at priority 5 to automatically add any instance-configured language absent from the built-in map — English names derived via `Locale::getDisplayLanguage()`. Languages such as Basque (`eu`) that are installed on the server but not in the default list now appear in all dropdowns and are correctly pre-selected from `_lf_lang` post meta.

- **Maintenance tab — uninstall warning text** — internal meta key names (`_lang`, `_trid`) removed from the user-facing warning; replaced with plain-language equivalents ("language assignments", "translation relationships").

### Maintenance

- **`SECURITY.md` excluded from distribution** — added to `.distignore` and `.gitattributes export-ignore` so the file stays in the GitHub repository but is excluded from the SVN deploy ZIP and GitHub's auto-generated source archives.

---

## [1.7.0] — 2026-05-24

### Added

- **Subdomain routing mode** — languages can now be served from subdomains (`de.example.com`, `fr.example.com`) as an alternative to path prefixes (`example.com/de/`). Select the URL structure in **Settings → Router → URL structure**. The source language always serves from the root domain. Language detection, permalink generation, redirects, hreflang output, the language switcher, and the link fixer are all subdomain-aware. Switching modes requires a permalink flush.

  **Server prerequisite:** wildcard DNS and a wildcard TLS certificate covering all language subdomains must be in place before enabling this mode. The `lf_base_domain` filter overrides the auto-detected apex domain when `home_url()` includes `www`.

- **Classic navigation menu auto-add guard** — publishing a translated page no longer inserts it into classic nav menus that have "automatically add new top-level pages" enabled. A `publish_page` hook removes any just-inserted item whose page has a non-source-language `_lf_lang` value. Classic menus only; FSE `wp_navigation` posts are unaffected.

### Fixed

- **Language switcher rendered empty on non-singular pages** — `get_languages()` returned an empty array on archive, category, tag, and author pages because `get_the_ID()` returns `0` there, causing the block to render nothing even when placed in a shared template part. The method now falls back to a URL-rewrite map covering all configured languages when no post ID is available.

- **Translate Navigation — wrong URLs in subdomain mode** — the "Translate Navigation" button rewrote internal URLs using path-prefix logic regardless of routing mode. In subdomain mode URLs are now correctly rewritten using `lang_base_url()`.

- **Source-language URL redirected to wrong language** — in path mode, a stale `lf_lang` cookie could cause a source-language URL (no `/lang/` prefix) to redirect to a translated version. A non-prefixed URL path is now treated as an authoritative source-language signal, bypassing cookie detection. The homepage is unaffected.

- **Fix Navigation References — two corrections** — source-language template parts were incorrectly rejected as invalid targets; a wrong-language nav reference (e.g. `navigation-it` in the DE template) produced a double-suffixed slug (`navigation-it-de`). Base name derivation now reads `_lf_lang` meta from the referenced nav post.

- **Language switcher — icon not rendering** — icon mode sized the SVG via a theme-specific CSS variable undefined on most sites, collapsing it to zero. Replaced with a generic `1.2em` rule.

- **Language switcher — dropdown overflows viewport** — the submenu panel now detects right-edge overflow on load and resize and flips to open right-to-left when needed.

- **Language switcher — dropdown unreadable on dark themes** — hardcoded `#fff` background replaced with FSE colour tokens (`--wp--preset--color--base` / `--contrast`) with `Canvas`/`CanvasText` system-colour fallbacks.

### Maintenance

- **`build-zip.sh` — permission normalisation** — directories set to 0755, files to 0644 after rsync and before the ZIP is created.

---

## [1.6.5] — 2026-05-24

### Fixed

- **`ajax_fix_fse_links()` — stale-path links not updated in template parts** — the handler was saving prefix-rewritten content and returning early when `$count === 0`, so links that already carried the correct language prefix but whose slug had changed (page moved or renamed after the template part was last saved) were never repaired. A second pass via `LinkFixer::fix_post()` now runs unconditionally after the prefix-rewrite save, using `data-id` as ground truth to detect and rewrite stale paths. Covers footers, headers, sidebars, and any other `wp_template_part` post type.

### Maintenance

- **`.distignore` — `.github/` added; `docs/` added for repo documentation and WordPress.org assets** — the GitHub Actions workflow directory was missing from the exclusion list and would have been included in the SVN submission. `docs/` covers the new `docs/assets/` folder (screenshots, banner, icon) which the deploy workflow pushes to SVN `assets/` separately and must not appear inside the plugin ZIP. Comments tightened throughout.

- **`LocaleDetector::filter_locale_for_vik_booking()` renamed to `filter_locale()`** — the hook enforces the active frontend locale on the `locale` filter for any plugin that reads it directly instead of `determine_locale` (booking plugins, e-commerce plugins, etc.). The old name was an implementation note baked into the method signature; the new name is generic and accurate. Docblock added explaining the pattern.

- **Language router — debug call sites removed** — all `->debug()` calls scattered across language-router sub-classes (`QueryFilter`, `Sync`, `Query`, `Redirector`, `Hreflang`) date from when the router was a mu-plugin and verbose tracing was needed during early development. All eight call sites are removed. The `debug()` method itself (gated on `WP_DEBUG && WP_DEBUG_LOG`) and the `linguaforge_debug()` public wrapper are retained for targeted use when needed.
- **`Router::debug_system_init()` and `debug_request_context()` removed** — both methods and their `add_action( 'wp' / 'init' )` registrations are removed. They were firing on every frontend request and flooding `debug.log` unconditionally whenever `WP_DEBUG` was on.

---

## [1.6.4] — 2026-05-24

### Fixed

- **`tests/bootstrap.php` — autoload path corrected** — pointed at `../vendor/autoload.php` (non-existent since Composer moved to `dev/` in 1.4.0); corrected to `../dev/vendor/autoload.php`. Silent failure was masked by PHPUnit's own autoloader when invoked from `dev/`.

### Improved

- **`MetaDescription\Module` — `register_meta` gated on admin/REST/CLI context** — `register_post_meta()` was called on every request including anonymous front-end views where the metabox is never rendered. Now skipped unless `is_admin()`, `REST_REQUEST`, or `WP_CLI` is true.
- **`linguaforge_flush_rewrite_rules` — `autoload = false` on all write sites** — three `update_option()` call sites (activation hook in `lingua-forge.php`, upload and delete handlers in `MaintenanceTab`) now pass the `false` autoload flag. The option is consumed within one request cycle and does not belong in the autoloaded options blob.

### Documentation

- **`README.md` — Roles and capabilities section** — documents the two-tier capability model: `linguaforge_required_capability` (default `edit_posts`) gates editor-level AI operations (chunk translation, block revision, meta/excerpt generation); FSE template scaffold, AI-translate, fix-links, fix-parts, fix-nav, and language navigation operations always require `manage_options` regardless of that filter.

---

## [1.6.3] — 2026-05-24

### Fixed

- **Language-prefix regex — multi-character locales (zh-tw, zh-hant, …) not matched** — three `[a-z]{2}` hardcoded patterns in `Redirector` (search redirect, homepage redirect, search-under-lang-prefix) rejected any locale whose slug is longer than two characters. Replaced with a new private `lang_regex()` helper that builds the alternation dynamically from the configured locale list via `preg_quote`, matching the approach already used in `Rewrite\Manager`. zh-tw, zh-hant, pt-br, and any future multi-character slugs now route correctly.
- **Frontend AJAX — POST requests silently sent without `lang` parameter** — the previous jQuery `ajaxSend` interceptor appended `lang=X` to the POST body, but `detect_lang_safe()` reads only `$_GET`. POST requests from the frontend therefore always fell through to cookie/Accept-Language detection regardless of the active language. The script is rewritten without jQuery: `XMLHttpRequest.prototype.open` and `window.fetch` are monkey-patched to append `?lang=X` to the URL query string of same-origin requests, landing the value reliably in `$_GET` for all HTTP methods. The jQuery handle is removed from the script's dependency array.

### Added

- **`missing-translation-notice` block — editor component** — the block previously had no `editorScript`, so the Site Editor showed a blank slot with no controls. `index.js` registers the `edit` function using `wp.*` globals (no build step): sidebar `InspectorControls` with a `TextControl` for the notice message, a `ToggleControl` for the home link, and a conditional `TextControl` for the link text. A `ServerSideRender` in the canvas gives an accurate live preview including all block-supports (colour, spacing, typography). `index.asset.php` declares the six `wp-*` script dependencies. `block.json` gains `"editorScript": "file:./index.js"`.

### Maintenance

- **`CONTRIBUTING.md` — `LINGUAFORGE_SECRET` cross-environment guidance** — the "API keys" storage-shapes entry now explains that `wp_salt('auth')` is shared across environments that share a `wp-config.php` copy (dev/staging/prod), and that defining a unique `LINGUAFORGE_SECRET` constant per environment gives each its own independent encryption key. Includes a `define()` snippet and a note that rotating the constant invalidates stored ciphertexts.
- **`README.md` — `LINGUAFORGE_SECRET` cross-environment guidance** — matching paragraph added to the "API keys" section aimed at deployers.
- **`CONTRIBUTING.md` — pre-1.3.x debug directory migration note** — "Things worth knowing" now includes a bullet noting that installs upgrading from before 1.3.x may have orphaned debug files under `wp-content/uploads/lingua-forge-debug/`. Options: point the `linguaforge_debug_dir` filter at that path for managed cleanup, or delete the directory manually.
- **`README.md` + `readme.txt` — WordPress-core and FSE conformance section** — documents that the plugin ships with no runtime dependencies, uses Block API v3 with server-side rendering, carries no jQuery frontend dependency, registers REST routes at `rest_api_init`, manages FSE post types with correct taxonomy bindings, and follows standard i18n and security conventions throughout.

---

## [1.6.2] — 2026-05-24

### Fixed

- **`handle_singular_redirect()` — non-public post types processed as translatable content** — `wp_global_styles`, `wp_navigation`, and other internal WordPress post types satisfy `is_singular()` and were therefore processed by the redirect logic. When the object cache was poisoned (e.g. shared Redis with no `WP_CACHE_KEY_SALT`), this produced live 302 redirects to internal FSE URLs on the wrong domain (e.g. `other-site.com/wp-global-styles-theme/`). The handler now checks `get_post_type_object()->public` and returns immediately for non-public post types.
- **`get_translations()` — non-public post types returned as translation group members** — the TRID query joined only `wp_postmeta`, so `wp_template`, `wp_template_part`, `wp_navigation`, `wp_global_styles`, `wp_block`, `nav_menu_item`, `revision`, and `attachment` posts could appear as translations of public pages if they shared a `_lf_trid` value. The query now inner-joins `wp_posts` and excludes all non-public post types and `auto-draft` status. Fixes the `front-page-it` redirect caused by FSE template posts leaking into homepage translation lookups.
- **`lang_permalink()` — URL rewriting attempted on non-public post types** — `post_link` and `page_link` fire for any post, including internal types. The filter now short-circuits immediately for non-public post types, preventing URL mangling on `wp_template` and similar posts.
- **`set_lang_cookie()` — empty domain allowed cross-site cookie bleed** — the `setcookie()` call passed an empty string as the domain parameter, leaving scope resolution to the browser. On servers hosting multiple WordPress sites this could allow the `lf_lang` cookie set by one site to be read by another. The domain is now explicitly set to `wp_parse_url( home_url(), PHP_URL_HOST )`.

---

## [1.6.1] — 2026-05-23

### Fixed

- **Translation Memory cache invalidation — stale legacy option read** — `Translation::compute_compliance_signature()` was reading the pre-1.5.0 `linguaforge_compliance_addendum` option as part of the TM cache-key signature. Since 1.5.0 replaced that global option with per-preset options (`linguaforge_preset_addendum_{technical,legal,creative}`), the signature went silently constant: editing a per-preset addendum no longer invalidated affected TM rows, and the cache served back translations produced under the previous preset rules. The signature now reads `Config::preset_addendum( $preset )` and folds in the resolved per-post preset via a new `$post_id` parameter, so per-page preset overrides also participate in cache keying. TM rows written before this fix become one-time permanent misses on first encounter and are overwritten on the next translation — an acceptable one-shot reset since those rows were keyed on a stale addendum value anyway.
- **MetaBox per-page preset picker — "(Custom)" indicator never surfacing** — the `$has_custom_addendum` check in `MetaBox::render()` also read the legacy `linguaforge_compliance_addendum` option, so the "Global default (Custom)" label in the per-page preset dropdown stopped appearing after the 1.5.0 migration even on sites that had saved a custom per-preset addendum. The check now compares the global preset's resolved addendum against its built-in default — "(Custom)" surfaces whenever an admin has saved an override.
- **FSE-translate AJAX endpoints bypassed rate-limit + daily-quota gates** — `RouterTab::ajax_translate_fse_content` and `ajax_translate_fse_navigation` (added in 1.6.0) called the AI provider directly without going through the per-user 30/min sliding window or the site-wide UTC daily ceiling that guard every other paid-AI endpoint. An admin clicking "Translate all" in the Language Templates row could dispatch many parallel quality-tier calls with no upper bound. Both handlers now gate on the same defences as `/translate-chunk` / `/create-chunk` / `/revise-block`, via the new `RateLimiter::gate_ajax_or_die()` adapter.

### Added

- **`LinguaForge\AI\REST\RateLimiter` class** — extracted from `FeatureController` to make the per-user-per-endpoint sliding-window rate limit and the site-wide UTC daily quota reusable across the REST endpoints and the AJAX FSE-translate handlers. Public surface: `enforce_rate_limit($endpoint)` and `enforce_daily_quota($endpoint)` return `null` on success / `WP_Error` 429 on limit hit (used by REST callers, which return the `WP_Error` directly), and `gate_ajax_or_die($endpoint)` runs both gates and exits with `wp_send_json_error` on the first failure (used by the AJAX FSE-translate handlers). Both filter hooks (`linguaforge_ai_rate_limit`, `linguaforge_ai_daily_quota`) keep their existing signatures and now apply to the two new endpoint keys `translate-fse-content` and `translate-fse-navigation` as well.

### Maintenance

- **`CONTRIBUTING.md` — verifying-changes-without-PHP section rewritten** with a primary path that installs PHP 8.1 user-space from the apt cache via `apt-get download` + `dpkg-deb -x` (works in restricted sandboxes that have neither root nor `composer install` capability). The previous Python-tokenizer + global-class regex audit guidance is kept as a fallback for environments where even `apt-get download` is blocked.
- **`CONTRIBUTING.md` — new "When you add something new" item 9** — explicit "Don't touch `CHANGELOG.md`, `Stable tag`, or version headers" rule. Iterating on a fix produces meaningless version history if every attempt bumps the version; releases are cut at clean checkpoints.
- **`CONTRIBUTING.md` — REVIEW.md cross-reference** updated to clarify that architectural notes live in the maintainer-only `lingua-forge-audit/` sibling folder; older `REVIEW.md` is historical.
- **`tests/README.md` — full refresh.** Was 36 lines listing 2 of 9 actual test files. Now 100 lines covering every test file by name with a one-line scope, a "where does a new test go?" decision table (by what the code-under-test depends on), the bootstrap dual-path mechanism, and the ReflectionMethod-on-private-statics pattern.

---

## [1.6.0] — 2026-05-23

### Added

- **FSE Template Localisation — Language Templates** — Settings → Router gains a new Language Templates section. Each base FSE template (page, single, archive, …) is shown in a table with one column per secondary language. Per-cell actions: **Create** (scaffold a language copy from the base template), **Translate** / **Retranslate** (AI-translate the full block content), **Fix Links** (rewrite internal URLs to the correct language prefix), and **Fix Parts** (update `wp:template-part` slug attributes to point at language-specific variants — e.g. `footer` → `footer-ca` — when those variants exist). Per-row actions run the same operations across all languages in one click.
- **FSE Template Localisation — Language Template Parts** — a parallel section for template parts (header, footer, navigation, …). Per-cell: **Create**, **Translate** / **Retranslate**, **Fix Links**, and **Fix Nav** (rewrites `wp:navigation {"ref":N}` block attributes so each language-specific part points at the correct language-copy navigation post, resolving mismatched menus in the Site Editor). Per-row: **Translate all**, **Fix all links**, **Fix all nav refs**.
- **FSE Template Localisation — Language Navigations** — lists every base `wp_navigation` post with one column per secondary language. **Translate** / **Re-translate** creates or updates a `{post_name}-{lang}` navigation post with AI-translated link labels and language-prefixed internal URLs. The translated post can then be wired into language-specific template parts via the Fix Nav action above.
- **`expand_pattern_refs()`** — private helper that resolves `wp:pattern` pointer blocks to their actual registered markup before any translation or fixing pass. Resolution order: PHP-registered / theme-directory patterns first, synced `wp_block` posts second. Unresolvable slugs are left untouched so block structure remains valid.
- **PHPUnit unit test suite** — `RouterSingletonTest` verifies the Router singleton's `reset_instance()` contract in isolation (no WordPress boot). Uses `ReflectionClass::newInstanceWithoutConstructor()` to satisfy the typed `?Router` property constraint without calling the WP-dependent constructor. Bootstrap registers a classmap autoloader for plugin source classes so no `composer dump-autoload` is required.

### Fixed

- **Primary language setting not persisting — hardcoded `'ca'` fallback** — `Context::source_language()` read `linguaforge_primary_language` from the database but applied two `'ca'` overrides inherited from initial versions: `get_option( …, 'ca' )` as the default and `$stored ?: 'ca'` as a second fallback. Any primary language the user saved in Settings → Router was silently overridden back to Catalan on the next request. Both fallbacks are removed; the option now defaults to an empty string and resolves to the first language in the Router's active language list when unset. Sites always running Catalan as primary are unaffected; sites that had intended a different primary language but were silently ignored will now honour the saved value.

- **PHPStan — unreachable statement in `ajax_fix_fse_links()`** — PHPStan treated the post-type guard as always-terminating, making the subsequent `strrpos`/`$lang` computation unreachable. Reordered: language inference now runs before the post-type check so the control-flow graph is linear.
- **PHPStan — dead `return` after `wp_send_json_success()`** — `wp_send_json_success()` is typed `@return never` in WP stubs; the trailing `return;` was genuinely unreachable and removed.
- **PHPCS — `MissingTranslatorsComment` on `_n()` call** — the `/* translators: */` comment in `ajax_fix_fse_parts()` was placed above `sprintf()` rather than immediately above the `_n()` call; moved inside the `sprintf` argument list.
- **PHPCS — `SlowDBQuery` warning in `class-migrator.php`** — the `meta_key`-based `$wpdb->update()` in `rename_meta_keys()` is a one-time idempotent migration with no WP API equivalent; added `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` to the existing inline `phpcs:ignore` comment.

---

## [1.5.1] — 2026-05-22

### Fixed

- **RTL language support — Persian locale** — `fa` (Persian/Farsi) was missing from the `lf_lang_fallback_map` filter array in `LocaleDetector`, causing `switch_to_locale()` to fall through to `en_US` on Persian pages. Added `'fa' => 'fa_IR'` to the fallback map.
- **Language switcher accessibility — missing `lang` attribute** — LSFLR switcher links had no `lang` attribute, preventing screen readers and browser heuristics from identifying each link's language. Each `<a>` in the submenu now carries `lang="{code}"`.
- **Language switcher CSS — RTL submenu position** — the submenu used `left: 0` unconditionally, causing it to open from the wrong side on RTL pages. Added `[dir="rtl"]` overrides that flip to `right: 0` and correct `transform-origin` for both dropdown and dropup variants.
- **AI result panels — RTL text direction** — translation results for Arabic, Hebrew, Persian, and Urdu were rendered LTR in all output textareas (admin metabox, diff modal, Quick Translate popover). Added an `RTL_LANGS` set and `isRtlLang()` helper to both `admin.js` and `toolbar-translate.js`; result textareas and the diff modal's new-content/new-title panes now receive `dir="rtl"` when the target language is RTL.

---

## [1.5.0] — 2026-05-21

### Added

- **Quick Translate — Create tab** — both the Admin Toolbar popover and the Editor toolbar Quick Translate popover gain a second tab for generating new content from scratch. Enter instructions and key points, choose a writing tone (Informative, Persuasive, Storytelling, Technical, Conversational), and optionally select a target language. Content is generated via the new `/lingua-forge/v1/create-chunk` REST endpoint using the quality model tier.
- **Quick Translate — Refine** — after any Translate or Create result, an inline Refine row appears below the output in both popovers. Type additional instructions (e.g. "make it shorter", "use a more formal tone") and click ↺ Refine; the model receives the original request plus the prior draft as context and returns an improved version. Refinement count is shown in the result meta line. Refinements are never cached.
- **`/create-chunk` REST endpoint** — new endpoint under `lingua-forge/v1`; accepts `hints`, `tone`, `target_language`, and optionally `refine_hint` + `previous_output` for iterative multi-turn refinement. Rate-limited and daily-quota-gated on the same policy as `/translate-chunk`.

### Changed

- **Per-preset editable addenda** — the single global "Custom prompt instructions" field in Settings → Behavior is replaced by three separate fields, one per non-standard preset (Technical/Scientific, Legal/Compliance, Creative/Marketing). Each field accepts plain-text override instructions; leaving it blank reverts to the built-in default. A `<details>` widget on each field shows the built-in default text for reference. `Config::preset_addendum()` and `Config::default_preset_addendum()` handle resolution; `apply_compliance_to_system()` is simplified accordingly. Sites that had a custom addendum saved are migrated automatically on first admin load (`linguaforge_preset_addendum_migrated_v1` guard).
- **`/translate-chunk` now supports refinement** — when `refine_hint` and `previous_output` are sent, `Translation::run_chunk()` builds a multi-turn conversation (original prompt → assistant draft → refinement instruction) instead of a fresh single-turn call.
- **All popovers widened to 450 px** — Admin Toolbar Quick Translate (`toolbar-translate.css`), Block Action toolbar (`block-action.css`), and Editor toolbar Quick Translate (`editor-translate.css`) all unified at 450 px (previously 400 px, 380 px, and 360 px respectively). Responsive breakpoint updated to 470 px across all three; below that width each popover falls back to `calc(100vw - 16px)` flush to the viewport edges.

### Fixed

- **PHP Fatal — namespace declaration order** — `class-language-router.php` had `defined( 'ABSPATH' ) || exit;` placed before the `namespace` declaration, triggering a fatal on PHP 8.1+ (`Namespace declaration statement has to be the very first statement`). The guard is now placed immediately after the `namespace` line, which is the correct pattern for namespaced files.
- **Quick Translate — tab panes both visible** — `display: flex` on `.lingua-forge-tp__tab-pane` was overriding the browser's built-in `[hidden] { display: none }` rule, causing both the Translate and Create panels to be visible simultaneously. Added an explicit `[hidden] { display: none }` author-level rule, consistent with the existing fix already applied to the result panel.
- **Language dropdowns show only instance languages** — all three overlay popovers (Admin Toolbar Quick Translate, Editor toolbar Quick Translate, Block Action toolbar) were populating the target-language `<select>` with the full 38-language list regardless of the languages actually installed on the WordPress instance. The `wp_localize_script` data is now filtered to the intersection of AI-supported languages and the codes returned by `linguaforge_languages()` — the Language Router's authoritative list of languages active on this install (derived from installed WP locale packs, the site locale, plugin translation files, and the configured source language). Use the language installer in Settings → Maintenance to add more languages to the instance as needed.

---

## [1.4.4] — 2026-05-21

### Changed

- **Switcher and LinkFixer absorbed into the Router singleton** — `LinguaForge\Router\Switcher` and `LinguaForge\Router\LinkFixer` are now sub-objects of the Router, accessible as `$router->switcher` and `$router->link_fixer`, consistent with all other sub-classes. The boot file is reduced to a single `Router::get_instance()` call; no plugin-level globals remain. The three `linguaforge_lsflr_*` template wrapper functions are unchanged.
- **Settings link and menu label** — a "Settings" action link is now shown next to "Deactivate" on `wp-admin/plugins.php`; the Settings submenu entry and page title have been corrected from "Lingua Forge AI" to "Lingua Forge".

---

## [1.4.3] — 2026-05-21

### Changed

- **Post meta keys renamed to `_lf_` prefix** — all six Language Router post meta keys now carry the plugin prefix, eliminating collision risk with any other plugin that stores language or search data under the same generic names.

  | Old key | New key |
  |---|---|
  | `_lang` | `_lf_lang` |
  | `_trid` | `_lf_trid` |
  | `_lang_previous` | `_lf_lang_previous` |
  | `_source_updated_at` | `_lf_source_updated_at` |
  | `_translation_source_updated_at` | `_lf_translation_source_updated_at` |
  | `_search_content` | `_lf_search_content` |

  `Db\Migrator::rename_meta_keys()` performs the in-place migration automatically on first load after upgrade. `DB_VERSION` bumps from `1.0` to `1.1` to gate the one-time operation. Migration is idempotent and scoped — only rows belonging to Lingua Forge posts are touched (identified via `_lf_trid` presence). No data is lost.

  **Compatibility note:** theme or plugin code that reads these meta keys directly must update to the new names. Code using the public `linguaforge_*` wrapper functions (`linguaforge_get_lang()`, `linguaforge_get_trid()`, etc.) requires no changes.

---

## [1.4.2] — 2026-05-21

### Fixed

- **`.mo` upload — MIME validation restored** — `MaintenanceTab::handle_upload_override()` was calling `wp_handle_upload()` with `test_type: false`, bypassing WordPress's MIME-magic check. A scoped `upload_mimes` filter now maps `mo → application/octet-stream` around the upload call, and `test_type: false` is removed so the MIME-magic check runs normally. The filter is added and removed in the same request; no global side-effect.
- **Router singleton — testability** — `Router::reset_instance()` added as a test-only static method that nulls the singleton so PHPUnit test cases can boot a clean instance without state bleeding between tests. Production code is unaffected. `RouterSingletonTest` covers null-after-reset and idempotency.

### Changed

- **Language Router sub-module docblock `Version:` line removed** — the line served no purpose (nothing reads it; `LINGUAFORGE_VERSION` is the canonical version string) and would have required manual maintenance on every release.

---

## [1.4.1] — 2026-05-20

### Changed

- **Tested up to WordPress 7.0.**
- **Uninstall behaviour — safe default** — language assignments (`_lang`), translation relationships (`_trid`), meta descriptions, the AI glossary, and Translation Memory are now **kept** when the plugin is deleted. Only settings, API keys, transients, and the AI result cache are removed automatically. A new toggle in **Settings → Maintenance → Uninstall Behaviour** lets administrators opt in to full data removal before uninstalling, preventing accidental loss of editorial content structure.

---

## [1.4.0] — 2026-05-20

### Fixed

- **Block Action popover — Footnotes tab hidden in block context** — the Footnotes tab in the block toolbar popover is no longer shown when the popover is opened from a regular block in the main editor. It now appears only when the AI button is clicked from inside the WordPress footnote editing popover, which is the context where it was always intended to live. Previously the tab was shown whenever a block happened to contain footnote references, creating an out-of-context UI element alongside Translate and Revision.
- **Block Action popover — Footnote selector removed** — the "Footnote" label and dropdown selector inside the Footnotes panel have been removed. The selector only ever contained a single entry (the current footnote being edited), added no selection value, and occupied space before the Translate / Revision sub-tabs. The underlying `<select>` element is retained hidden for the multi-footnote code path (block with more than one footnote reference) where a selector would be meaningful.
- **Quick Translate editor toolbar — intermittent duplicate icon** — `editor-translate.js` could inject the translate button into two different Gutenberg header containers when a lower-priority fallback container (e.g. `.editor-header__settings`) was matched first at load time and then a higher-priority container (`.interface-pinned-items`) appeared later as React finished rendering. Each `tryInject` call now removes any stale buttons from non-winning containers before inserting into the target, ensuring at most one icon is visible at any time regardless of how the editor header assembles itself.

### Changed

- **CSS lint — stylelint now passes cleanly across all AI module assets** — `.stylelintrc.json` in the dev tooling folder updated with four rule overrides on top of `@wordpress/stylelint-config`: BEM-aware `selector-class-pattern` (allows `block__element--modifier`), `currentColor` permitted via `camelCaseSvgKeywords`, `rule-empty-line-before` and `comment-empty-line-before` both nulled (project style does not require blank lines between every rule or before inline comments). Five CSS files corrected to pass cleanly: non-standard `.--bad` / `.--warn` / `.--good` modifier classes in `admin.css` renamed to proper BEM (`lingua-forge-info-quality--bad` etc.) with matching fixes in `admin.js`; selector specificity ordering corrected in `admin.css` and `settings.css`; font family quote removed from `SFMono-Regular` in `block-action.css`; duplicate `.lsflr-switcher` rule blocks merged and `.lsflr-icon svg` moved to the correct specificity position in `lsflr.css`.

---

## [1.3.6] — 2026-05-19

### Changed

- **`Language_Router::ROUTER_VERSION` renamed to `DB_VERSION` (value reset to `'1.0'`)** — the constant is a schema-version marker for `ensure_lang_index()`, not a plugin-release tag. The old name (`'1.3.4'`) mirrored the plugin version at the time it was written and would inevitably get bumped in sync with plugin releases, falsely triggering a no-op index rebuild on every upgrade. On first load after this change, existing installs will find the stored `lf_lang_router_version` option (`'1.3.4'`) no longer matches `DB_VERSION` (`'1.0'`), so `ensure_lang_index()` runs once and resets the stored value to `'1.0'` — the operation is idempotent and the index is unchanged.

### Fixed

- **Duplicate Quick Translate icon — main editor toolbar** — a global sentinel (`window.linguaForgeEditorTranslateInit`) now short-circuits the script IIFE on any second execution, preventing the toolbar button from being inserted twice when the script is enqueued via multiple hooks.
- **Duplicate AI action icon — block toolbar** — `registerFormatType` / `BlockFormatControls` was producing a second toolbar button alongside the one already added by `addFilter` / `BlockControls`. The `registerFormatType` path has been removed entirely; footnote editing continues via the dedicated Footnotes tab in the `addFilter` popover.
- **CLI translation failure for posts with footnotes** — translated text containing direct-speech quotation marks or terms wrapped in `"…"` (e.g. Portuguese `"como"`) was emitted by the AI as bare `"` bytes inside JSON string values, rendering the response structurally invalid and causing `json_decode` to return `null`. A new `repair_unescaped_quotes()` step inside `normalise_json_response()` scans the fence-stripped response byte-by-byte and escapes stray `"` characters using a peek-ahead heuristic before decoding. Both the TM-flow and main-flow system prompts were also hardened with an explicit `CRITICAL JSON RULE` instruction covering the same failure mode at the source.
- **MetaBox.php i18n PHPCS errors** — `esc_html__()` calls with `%s` placeholders now use the `echo sprintf(...)` single-line pattern so each `translators:` comment sits on the line immediately above the i18n function call, satisfying `WordPress.WP.I18n.MissingTranslatorsComment`.

---

## [1.3.5] — 2026-05-19

### Added

- **Block Revision — Instructions textarea** — the Revision tab in the block toolbar popover now includes an optional free-form "Instructions" textarea below the Revision Type select. Any text entered there is appended to the server-side revision prompt as "Additional instructions from the editor: …", allowing per-use tone, style, or audience guidance on top of the preset revision type. The field is cleared on every new popover open so guidance from one block never silently carries over to the next. The same textarea is available in the Footnotes → Revision sub-panel.

### Changed

- **Translation — "Also generate meta description" checked by default** — the checkbox was previously unchecked on first use; it now defaults to checked so the meta description is generated alongside every translation without requiring an extra click.

### Fixed

- **Meta description applied transparently on translate / content-generate** — clicking "Apply translation" or "Apply to Editor" now writes the generated meta description to both the Gutenberg editor store (`editPost({ meta: { _linguaforge_meta_description } })`) and the Classic metabox textarea (`lf_meta_description_field`) in one step. The editor can see the value immediately, edit it manually, and it persists on the normal "Update" save without any additional action. Previously the textarea was never updated (wrong element selector) and the store dispatch used the wrong meta key, so the value was silently discarded on save.
- **"Apply to Meta Description" standalone button** — was dispatching to the wrong Gutenberg store key (`meta_description`) instead of the REST-registered key (`_linguaforge_meta_description`), causing the value to be overwritten by the stale DB value on the next save. Fixed to use the correct key.
- **Cross-frame meta description field lookup** — added `findInIframes()` helper so the field lookup falls back to scanning accessible iframes when the code runs in the main-window context (e.g. Content Generator overlay) rather than inside the classic-metabox iframe.

---

## [1.3.4] — 2026-05-19

### Changed

- **Language change in block editor no longer triggers save + reload** — changing the language select in the Language metabox now stages the correct FSE template directly in the Gutenberg editor state (`editPost({ template })`) instead of immediately calling `savePost()` and forcing a full page reload. The template slug is computed from the available `{page|single}-{lang}` templates passed to the script at enqueue time. The user's normal "Update" click commits both the language and the template in one save. No confirm dialog is shown for language changes (translation-group changes still confirm + reload, as those affect linked posts). Reverting to the source language clears any auto-assigned language template from the editor state.

### Fixed

- **`lfAdminMetabox` now carries `availableTemplates` and `sourceLanguage`** — these were missing from the localised script data object, which meant the template-staging logic had no data to work with. The PHP enqueue function now queries all published `wp_template` posts matching the `(page|single)-[a-z]{2}` pattern and passes them as an array.

---

## [1.3.3] — 2026-05-19

### Fixed

- **FSE template auto-assignment on language change** — `assign_template_if_needed()` used a guard (`_wp_page_template` non-empty and non-`default` → skip) that was too conservative: once a language-specific template had been auto-assigned (e.g. `page-de`), a subsequent language change to another non-source language (e.g. `fr`) would leave the old template in place instead of assigning `page-fr`. The fix tracks which template was last auto-assigned in a new `_lf_auto_template` post-meta key and allows overwriting only that template — user-chosen templates are still protected. Back-compat pattern-matching handles posts saved before 1.3.3 that don't yet have the tracking key (any template matching `{page|single}-{lang}` for an active language is treated as auto-assigned). Changing the language back to the source language now also reverts `_wp_page_template` to `'default'` when the current template was auto-assigned, clearing the stale language-specific template.

---

## [1.3.2] — 2026-05-19

### Fixed

- **Slug not updated on retranslation** — when a WP-CLI `translate` / `retranslate` / `fill_translations` command updates an existing translated post, `wp_update_post()` does not automatically regenerate `post_name` from `post_title`. The CLI now explicitly adds `post_name => sanitize_title($translated_title)` to the update arguments whenever a translated title is present, so the URL slug stays in sync with the translated title across all CLI translation commands.
- **Admin apply path slug** — the Gutenberg `editPost()` dispatch in the "Apply translation" modal now includes a `slug` field derived from the translated title via the new `lfSlugify()` helper. WordPress sanitizes this further via `sanitize_title()` + `wp_unique_post_slug()` on save. The classic-editor fallback does not touch the slug (no client-side field exists there; the server-side fix covers that path via the CLI workflow).

---

## [1.3.1] — 2026-05-18

### Added

- **Browser language redirect** — opt-in setting in **Settings → Router** that redirects first-time visitors to their preferred language based on the browser's `Accept-Language` header. The redirect fires only when no language prefix is present in the URL, no `?lang=` query param is set, and no `lf_lang` cookie exists — i.e. a genuine first visit with no prior preference recorded. The `Accept-Language` header is parsed in quality order; both exact two-char codes (`de`) and regional tags (`de-DE`, `de-AT`) are matched against the router's active language list. When the visitor later switches language via the language switcher, `set_lang_cookie()` fires and the cookie wins on all future visits — the browser header is never consulted again. No new redirect handler was needed: the existing `handle_homepage_redirect()` and `handle_singular_redirect()` already act on `LF_LANG`, which is now set from the browser header when the option is enabled.

---

## [1.3.0] — 2026-05-18

Version milestone. No breaking changes; no database migrations required. Consolidates the full 1.2.x series into a named stable release.

### Summary of what shipped across 1.2.x

- **Content Generator overlay** — dedicated single-column overlay with iterative multi-turn refinement (chat with the model to improve its own draft) and automatic meta description generation chained server-side after every generation and every refinement iteration.
- **Translation meta description chaining** — optional "Also generate meta description" checkbox in the Translation metabox generates a meta description in the same server-side request using the already-translated content, with no second API round-trip.
- **`MetaDescription::run()` direct-content override** — accepts `content`, `title`, and `lang` params so any feature can chain a meta description from in-memory content without re-reading the post from the database.
- **WP-CLI `--debug` flag** — available on `translate`, `retranslate`, and `fill_translations`. Forces debug-file writes for that run and echoes the source prompt and raw API response inline in the terminal. Provider errors surface in WP-CLI output regardless of `--debug`.
- **`Translation::force_debug(bool)`** — runtime debug activation without touching the database option or wp-config.php; used by the CLI flag, also available for custom scripts.
- **HTTP timeout raised to 300 s** — was hardcoded at 120 s; now 300 s by default and configurable via the `linguaforge_ai_retry_policy` filter (`timeout` key) for very large posts.
- **WP-CLI `fill_translations` and `missing_translations` commands** — bulk-fill missing router-language translations for a post in one pass; scan all posts of a given type for missing translations.
- **WP-CLI `--with-meta-description`** — available on `translate`, `retranslate`, and `fill_translations`; generates and saves a meta description for each translated post immediately after writing its content.
- **Settings → Behavior** — Global AI Preset live preview, renamed Custom prompt instructions field with realistic placeholder, Standard preset temperature hint in dropdown.
- **Settings → Router tab** — Primary Language selector, Flush Permalinks button, Active Languages chip list, Install Language pack section.
- **Glossary "any target language" entries** — apply a term to all target languages at once.
- **Custom prompt instructions honoured on Standard preset** — previously silently discarded; now always applied when saved.

---

## [1.2.17] — 2026-05-18

### Added

- **WP-CLI `--debug` flag on `translate`, `retranslate`, and `fill_translations`** — forces translation debug-file writes for that single run (no need to enable debug site-wide or touch `wp-config.php`), and immediately echoes the source prompt and raw API response for each language to the terminal inline after the call returns. Provider errors — timeouts, HTTP failures, truncation, bad JSON — are also printed inline via the same channel. This makes it possible to inspect exactly what was sent and what came back for a specific failing post without tailing any log file:
  ```
  wp linguaforge translate 42 --to=fr --debug
  wp linguaforge retranslate 42 --to=fr --debug
  wp linguaforge fill_translations 42 --debug
  ```
- **Provider errors now surface in WP-CLI terminal** — `AbstractProvider::log_error()` and `log_retry()` now also call `WP_CLI::log()` when running under WP-CLI, so HTTP failures, truncation warnings, and retry events are visible without checking the PHP error log or WordPress debug.log.
- **`Translation::force_debug(bool)`** — new public static method that activates debug-file writes for the current process without touching the database option or requiring a `LINGUAFORGE_AI_DEBUG` constant. Used by the CLI `--debug` flag; also available for custom scripts and mu-plugins.

---

## [1.2.16] — 2026-05-18

### Fixed

- **HTTP timeout raised from 120 s to 300 s** — the `wp_remote_post` timeout used for all AI provider calls was hardcoded at 120 seconds. Very large posts requesting 16 000–32 000 output tokens can take longer than that to generate, causing the request to time out and the translation (or content generation) to report failure even though the provider would have succeeded. The default is now 300 seconds. The timeout is now also part of the `linguaforge_ai_retry_policy` filter (`'timeout'` key) so it can be raised further in `wp-config.php` or a must-use plugin for exceptionally large posts without a code change:
  ```php
  add_filter( 'linguaforge_ai_retry_policy', function ( $policy ) {
      $policy['timeout'] = 600;
      return $policy;
  } );
  ```

---

## [1.2.15] — 2026-05-18

### Added

- **Content Generator — automatic meta description** — every content generation (initial and every refinement iteration) now chains a `MetaDescription::run()` call server-side immediately after the content is produced, using the just-generated text directly without a second API round-trip for the full post body. The generated description appears in a blue-tinted panel inside the Content Generator overlay. Clicking Apply to Editor writes both the generated content and the meta description to the editor in one step. The meta description is never stored in the content cache — it reflects the draft content, not the saved post — matching the cache-isolation approach introduced for translation chaining in 1.2.14.

---

## [1.2.14] — 2026-05-18

### Added

- **Translation → "Also generate meta description" checkbox** — when checked, a meta description is generated in the same server-side request immediately after the translated content is produced. The description is derived from the already-translated content already in memory — the full post body is not re-sent to the API a second time. The result appears in a dedicated blue-tinted section inside the diff modal. Clicking Apply writes the translated content **and** the meta description to the editor in one step. Implemented on both the main translation path and the Translation Memory path.
- **`MetaDescription::run()` — direct content override** — the method now accepts `content`, `title`, and `lang` params. When `content` is provided it is used instead of reading `post_content` from the database, and the result is not written to the translation cache (the content is ephemeral until the post is saved). This enables the translation chaining above and makes the feature composable for future server-side orchestration.

---

## [1.2.13] — 2026-05-18

### Added

- **Content Generator — dedicated overlay with iterative refinement** — the Generate Content feature now opens in its own single-column overlay instead of the side-by-side diff modal used for translation. After an initial generation the overlay exposes a **Refine** section: write additional instructions (change tone, expand a section, add examples, etc.) and click Refine to submit them as a follow-up turn in the same API conversation. The model receives its previous draft as an assistant turn and rewrites it from there rather than starting from scratch. Refinements can be repeated any number of times; each iteration appends `· Refinement #N` to the meta badge. Apply to Editor inserts the current draft directly (no diff step) and closes the overlay.
- **Content Generator — server-side multi-turn support** — `ContentGenerator::run()` detects `refine_hint` + `previous_output` in the request params and builds a four-message conversation array (`system → user → assistant → user`), routing it through the normal `provider->chat()` path so all three supported providers (Anthropic, OpenAI, Gemini) handle refinement transparently. Refinements bypass the cache on both read and write so iterative drafts never overwrite the cached initial generation.

---

## [1.2.12] — 2026-05-18

### Added

- **`--with-meta-description` flag on `translate`, `retranslate`, and `fill_translations`** — when passed, each command generates and saves an AI meta description for every translated post immediately after writing its content, storing it under `_linguaforge_meta_description` (the same key the admin metabox writes). The description is generated in the target language using the post's `_lang` meta via `MetaDescription::run()`. Skipped on `--dry-run` (no target post exists to write to) and on `--check-only` in `fill_translations`. The `detail` column in the results table appends `+ meta` on success or `+ meta (error: …)` on failure so every operation is visible in the same output row. This makes a full multilingual content pass possible in one command: `wp linguaforge fill_translations 42 --draft --with-meta-description`.

---

## [1.2.11] — 2026-05-18

### Added

- **WP-CLI `missing_translations` command** — `wp linguaforge missing_translations <lang> <post_type>` scans every post of the given type whose `_lang` matches the source language and reports which posts are missing one or more router-language translations. Output columns: `post_id`, `title`, `post_status`, `missing` (comma-separated language codes), `count`. Sorted by missing count descending so the most incomplete posts surface first. Supports `--exclude`, `--status` (default `publish`, accepts `any`), and `--format`. Pairs directly with `fill_translations`: the final warning line shows the exact command to run on each incomplete post, and `--format=json | jq -r '.[].post_id' | xargs` pipelines work out of the box.

### Fixed

- **Custom prompt instructions ignored on Standard preset** — `Config::apply_compliance_to_system()` returned early for the Standard preset even when an explicit custom addendum had been saved, silently discarding it. The custom addendum now always wins regardless of which preset is active; Standard without a saved custom addendum continues to leave the system prompt untouched.

### Changed

- **Settings → Behavior — Global AI Preset preview** — selecting a preset in the dropdown now instantly shows its built-in system-prompt instructions in a read-only panel below the dropdown, so editors can see exactly what each preset does and use the format as a template for the Custom prompt instructions field.
- **Settings → Behavior — Custom prompt instructions** — renamed from "Custom system-prompt addendum"; now shows a realistic placeholder example (renewable-energy abbreviations, formal register, flag-unknowns rule) and an **Active** notice when custom instructions are saved.
- **Settings → Behavior — Standard preset temperature hint** — the dropdown now shows `(T=0.2–0.6, per feature)` next to Standard so it is comparable to the explicit temperatures on the other presets.

---

## [1.2.10] — 2026-05-18

### Added

- **WP-CLI `fill_translations` command** — `wp linguaforge fill_translations <post_id>` checks which router languages are missing a translation for a post and creates them in one go. Use `--check-only` to report missing languages without touching the database. Respects `--exclude`, `--draft`, `--dry-run`, `--format`, and all provider/model/token override flags. Uses only the active router languages (not the full AI-supported language list), so it's safe to run against any post without generating unwanted locales.

---

## [1.2.9] — 2026-05-18

### Changed

- **Glossary — language dropdowns now show only active router languages** — the Source language and Target language selectors in both the filter form and the "Add entry" form previously listed every language in the AI translation map (100+ entries). They now show only the languages the Language Router actually knows about (installed WordPress locale packs + the configured primary language), matching what the site uses in practice.
- **Glossary — "Any target language" support** — the Target language field in the "Add entry" form now includes a "— Any target language —" option (value = empty string). Entries saved with no target language are injected into the translation prompt for every target, making it trivial to add brand names, abbreviations, or do-not-translate terms once and have them enforced across all language pairs. Existing entries stored with a specific target language are unaffected. The entries table shows *any* (italic) for these rows, matching the existing display for source-language wildcards.

### Fixed

- `Glossary::get_for_pair()` SQL updated to `(target_lang = %s OR target_lang = '')` so any-target-language entries are picked up correctly when building the translation prompt.
- `Glossary::insert()` no longer rejects rows with an empty `target_lang`.

---

## [1.2.8] — 2026-05-18

### Added

- **Dedicated Router tab in Settings** — Language Router configuration is now in its own **Router** tab rather than buried in the Behavior tab. The tab has three sections:
  - *Primary Language* — the language selector (previously in Behavior), now saved via its own form.
  - *Flush Permalinks* — a one-click button that calls `flush_rewrite_rules()` directly from the settings page, with a success notice on completion. No more navigating to Settings → Permalinks.
  - *Active Languages* — a read-only chip list of all router-known language codes and a count of installed locale packs.
  - *Install a Language* — a "Load available languages" button fetches the full list from WordPress.org translate API (cached in a 12-hour transient), populates a searchable dropdown, and an Install button downloads and installs the selected language pack via `wp_download_language_pack()`. If `DISALLOW_FILE_MODS` is set, a warning is shown instead with a WP-CLI fallback command.

---

## [1.2.7] — 2026-05-18

### Added

- **Primary Language selector in Settings → Behavior → Language Router** — the primary language (the one served without a URL prefix and using default FSE templates) is now configurable from the admin UI rather than hardcoded to `ca`. The setting is stored in the `linguaforge_primary_language` option. Changing it requires a permalink flush (Settings → Permalinks → Save Changes).

### Fixed

- **Link Fixer template checker false-positive on primary language** — `resolve_template_for_lang()` now returns `null` for posts whose language matches the primary language, so the Link Fixer no longer flags Catalan pages (or whichever language is primary) for missing a `page-ca` / `single-ca` template. Primary-language posts correctly use WordPress's default templates (`page`, `single`, etc.) and are no longer reported as having a template issue.

---

## [1.2.6] — 2026-05-18

### Fixed

- **WordPress.org Plugin Check — `wp_enqueue` compliance**: All inline `<script>` and `<style>` output that previously used raw `admin_footer` / `wp_footer` print callbacks has been replaced with the canonical `wp_register_script` / `wp_add_inline_script` and `wp_register_style` / `wp_add_inline_style` pattern. The three affected output points are the Language Router admin meta-box JS, the quick-edit JS, and the AI Settings page CSS. The raw `<style>` block that was appended inline at the bottom of the Settings page render method has been removed — styles are now output through `wp_head` like any other enqueued asset.
- **WordPress.org Plugin Check — sanitization**: `(int) $_POST[…]` casts that skipped `wp_unslash()` have been corrected to `absint(wp_unslash(…))` in the Language Router (`lf_trans_*` and `post_id` POST fields). A raw `$_GET` comparison in the AI Settings page has been corrected with `sanitize_key(wp_unslash(…))`.
- **WordPress.org Plugin Check — nonce data in inline JS**: The admin meta-box inline script no longer embeds a PHP-interpolated nonce directly. The nonce is now passed through a `wp_add_inline_script(…, 'before')` data object (`lfAdminMetabox.importNonce`), keeping all PHP and JS cleanly separated.

---

## [1.2.5] — 2026-05-18

### Added

- **Stale-path detection in the Admin Link Fixer** — the scan now catches same-language links that point to a correct-language URL that has become outdated after a page was moved in the hierarchy (e.g. a Catalan page reparented from root to a sub-page, changing `/ca/aprop/` to `/ca/cal-talaia/aprop/`). Gutenberg's `data-id` attribute is used as ground truth: if `get_permalink(data-id)` no longer matches the stored `href`, the link is flagged as a stale path and auto-correctable. Stale fixes appear in the modal with an amber "📍 Stale path (page moved)" label, showing the outdated URL and the correct current URL. The existing "Fix" per-row button and "Fix All" handle stale-path corrections together with cross-language link fixes — all are resolved in a single `fix_post()` pass. No new AJAX endpoint required.

---

## [1.2.4] — 2026-05-18

### Added

- **Template checker in the Admin Link Fixer** — the "Fix Links" scan now also checks each translated post's FSE block template against the expected language-specific slug (e.g. `page-de` for a German page, `single-de` for a German post). Posts with a wrong or missing template appear in the results table with a "📄 Wrong template" badge showing the expected slug and the current value. A per-row **Fix Template** button writes the correct `_wp_page_template` meta immediately; "Fix All" applies both link and template corrections in a single pass. When the expected template does not yet exist in the Site Editor a warning directs the editor to create it first. All new strings are translatable.

---

## [1.2.3] — 2026-05-18

### Fixed

- German (and other verbose-language) translations failing silently with "unparseable response": Claude 4 with system-prompt JSON enforcement can return `stop_reason: "end_turn"` even when the generated JSON is truncated mid-string, bypassing the `is_truncated()` guard in `AbstractProvider`. Both the main translation path and the Translation Memory path now apply a heuristic — if the response starts with `{` but does not end with `}`, it is flagged as a likely truncation. The error returned to the user now reads "Translation output truncated — raise Max output tokens in Settings → Lingua Forge → Translation Limits or pass --max-tokens=20000 on the CLI" instead of the generic "unparseable response" message. The PHP error log entry also notes the truncation suspicion.

---

## [1.2.2] — 2026-05-18

### Fixed

- WP-CLI `wp linguaforge translate` and `wp linguaforge retranslate`: when no TRID-linked post existed for a target language the command was silently skipping that language (status `skipped`) rather than creating the missing post. Both commands now call `create_trid_linked_post()` which creates a new draft of the same post type, links it into the source's TRID group (`_trid` + `_lang` meta), populates it with the translated content and title, and assigns a language-specific FSE template where one exists. If the source post has no TRID yet, a fresh UUID is generated and assigned to both the source and the new post. The new post is always created as `draft` so it never auto-publishes without editor review.

---

## [1.2.1] — 2026-05-18

### Fixed

- Fatal 500 on Admin Link Fixer scan: `WP_Query` inside the namespaced `LinguaForge\Router\LinkFixer` class was not prefixed with `\`, causing PHP to look for `LinguaForge\Router\WP_Query` and fail. Every scan request from the Pages list had been returning a 500 since the 1.2.0 namespace migration.

---

## [1.2.0] — 2026-05-17

### Added

- **AI Behavior Presets** — four named presets replace the binary compliance toggle: Standard (temperature 0.4), Technical / Scientific (0.2, precise terminology directives), Legal / Compliance (0.1, strict preservation of regulatory citations and units), Creative / Marketing (0.7, encourages vivid language). Each preset ships with a tuned system-prompt addendum. A custom addendum field overrides the preset default when non-empty. Managed from **Settings → Lingua Forge → Behavior**.
- **Per-page preset override** — Translation and Content Generator now respect a per-post preset chosen from the Lingua Forge metabox (new select at the top of the panel). When set to anything other than "Global default", the page-level preset takes priority over the site-wide setting. Useful for legal pages that need strict mode while the rest of the site uses Standard. (Meta Description, Excerpt Generator, and Quick Translate intentionally use the global preset only.)
- **Footnotes tab in the Block Action popover** — editors can translate or revise individual footnotes directly from the AI panel without switching to chunk mode. The tab shows all footnotes attached to the current block as a select list; picking one loads its text into sub-panels for Translate and Revise. The Apply button writes the result back into the post's `footnotes` meta via `dispatch('core/editor').editPost`.
- **Translate button in the format / footnote editing toolbar** — registers as a native WordPress rich-text format type (`lingua-forge/translate`) via `wp.richText.registerFormatType` so the inline globe icon appears in both the block selection toolbar and the footnote editing popover. Clicking it opens the Block Action popover pre-loaded with the selected text. Uses an inline SVG icon compatible with the block editor environment.
- **Side-by-side diff preview before applying translations** — "Apply to Editor" now opens a two-column modal overlay showing the current editor content (left) vs the translated content (right) before anything is written. Apply fires only when the editor explicitly clicks "Apply translation" inside the modal; all cancel paths (overlay click, ✕, Cancel, Escape) dismiss without changes. Content panes render HTML so block markup reads close to the final post appearance. Footnotes are shown as a collapsible reference below. Layout stacks to a single column below 800 px viewport.
- **Translation Memory** — opt-in block-level cache shared across posts (`{$wpdb->prefix}lingua_forge_ai_tm`). When enabled, a full-post translation request parses the content into individual blocks, batch-looks up cached translations, and issues a single API call only for the uncached portion. Cache key includes a SHA-256 of block markup, language pair, glossary hash, and compliance preset signature, so glossary edits and preset changes automatically invalidate affected entries. Status, block count, hit rate, and a Clear button are visible in **Settings → Maintenance**.
- **Glossary** — user-managed terminology table per language pair (`{$wpdb->prefix}lingua_forge_ai_glossary`). Source language `''` (wildcard) covers brand names and language-agnostic abbreviations. A new **Glossary** tab in Settings shows the table with filter dropdown and an add-new form. Glossary terms are injected into every Translation and Quick Translate system prompt; the glossary hash is folded into the TM cache key.
- **WP-CLI commands** — `wp linguaforge translate <post_id> --to=fr,de[,…]` (with `--temperature`, `--max-tokens`, `--model`, `--force`, `--dry-run` overrides) and `wp linguaforge cache_clear` (with `--feature` and `--post-id` scope flags, requires `--yes` for full truncate).
- **Per-user rate limiting and per-site daily quota** on all REST endpoints — sliding 60-second window (default 30 req/min per user), UTC-keyed daily ceiling in site settings, both filterable. Managed from **Settings → Limits**.
- **Test Connection button** per provider — fires a lightweight 16-token ping against the selected provider. Result shows inline in the API Keys tab.
- **Provider retry / backoff** — all providers retry up to twice on `WP_Error`, HTTP 429, or 5xx responses, with ~1.5 s + jitter between attempts. Policy filterable via `linguaforge_ai_retry_policy`.
- **Debug files section** in **Settings → Maintenance** — toggle `linguaforge_ai_debug_enabled` option (overridden by `LINGUAFORGE_AI_DEBUG` constant), shows resolved debug path and file count, and provides a Clear Debug Files button.
- **AbstractProvider** — shared template-method base class for all AI providers. Concrete Anthropic / OpenAI / Gemini implementations now contain only provider-specific methods (`build_request`, `extract_text`, `extract_usage`, `is_truncated`). All providers report token usage per call, persisted in **Settings → AI Usage** (new tab between Behavior and Maintenance).
- **Structured JSON output for Translation** — Translation responses now use provider-native JSON schema enforcement (OpenAI `response_format`, Gemini `responseSchema`, Anthropic assistant-message prefill). Sentinel-marker (`===TITLE===`, `===FOOTNOTES===`) parsing is gone; output is parsed from a typed JSON envelope.
- **`lf_hreflang_x_default` filter** — controls which URL is emitted as the `x-default` hreflang entry. Default behavior unchanged (source-language URL). Useful for sites that redirect `x-default` to a landing page.
- **`Plugin::should_boot()` short-circuit** — the AI module skips its full boot sequence on anonymous frontend requests where none of the AI features are needed (no admin, no AJAX, no REST, no WP-CLI, no logged-in user with `edit_posts`). Filterable via `linguaforge_ai_should_boot`.

### Changed

- **All three Language Router classes now fully namespaced** under `LinguaForge\Router`. `LSFLR_Switcher` → `LinguaForge\Router\Switcher`; `LSFLR_Link_Fixer` → `LinguaForge\Router\LinkFixer`. Back-compat aliases (`LSFLR_Switcher`, `LSFLR_Link_Fixer`) remain via `class_alias` for one release (target removal: 1.5). The boot file (`language-router.php`) and all theme wrapper functions continue to work unmodified.
- **Meta Description sub-module refactored to a namespaced class** — `LinguaForge\MetaDescription\Module`. Constants `META_KEY = '_linguaforge_meta_description'`, `LEGACY_KEY = 'meta_description'`. A one-time bulk migration (guarded by `lf_meta_key_migrated_v1` option flag) copies existing `meta_description` rows to the prefixed key on the first admin request after upgrade. The `get()` method falls back to the legacy key transparently so no content is lost. On save, the new key is written and the legacy key is deleted.
- **Settings page Behavior tab** — compliance toggle + temperature field replaced by a single preset selector with `(T=X.X)` notation per option and a shared custom addendum textarea below.
- **AI feature result cache moved to a custom table** — `{$wpdb->prefix}lingua_forge_ai_cache` (composite primary key on `post_id, feature_key`). Lazy migration in `CacheStore::get()` reads pre-1.4 post-meta entries, copies them forward, and deletes the old rows. Public `CacheStore` API unchanged. A **Clear AI Cache** button is available in **Settings → Maintenance**.
- **`Language_Router::register_hooks()` split** — 16 admin-only hooks moved into `register_admin_hooks()`, called only when `is_admin()` is true. Reduces `add_action`/`add_filter` overhead on every anonymous frontend request.
- All new user-facing strings wrapped in `__()` / `esc_html__()` with `/* translators: */` comments where the string contains a placeholder; `text-domain` is `lingua-forge` throughout.

### Fixed

- **`uninstall.php` cleanup completeness** — added `_linguaforge_meta_description`, `_linguaforge_preset`, `lf_meta_key_migrated_v1`, and `linguaforge_active_preset` to the wipe list. The generic `meta_description` key is intentionally **not** deleted — other plugins may own rows under this key.
- **Language Router `detect_post_language()` admin branch** — reads the global `$post` (set by `wp-admin/post.php` / `post-new.php`) instead of `$_GET['post']` / `$_POST['post_ID']`, removing the phpcs violations without behavioral change. FSE / site-editor paths correctly resolve to `null`.
- **Block editor restriction options** — `linguaforge_block_editor_allow_lock_blocks` and `linguaforge_block_editor_allow_template_mode` options now filter `block_editor_settings_all` and are controllable from **Settings → Behavior → Block Editor** without code changes.

---

## [1.1.0] — 2026-05-17

### Changed

- **Public template functions renamed** — all `lf_*` global functions in `language-router.php` are now `linguaforge_*` (e.g. `linguaforge_get_lang()`, `linguaforge_languages()`, `linguaforge_lsflr_render_switcher()`). Required for WordPress.org naming-convention compliance. Update any direct calls in custom themes or mu-plugins.
- **Plugin URI updated** to https://github.com/leotiger/lingua-forge.
- **WordPress.org Plugin Check compliance** — full pass across all files: escaping at output points (`esc_html()`, `wp_kses_post()`, `absint()`), `wp_unslash()` on superglobals, `phpcs:ignore` comments with rationale for justified exceptions, i18n `/* translators: */` comments placed directly above `esc_html__()` calls, `wp_safe_redirect()` used throughout, `wp_parse_url()` replacing `parse_url()`.
- **`wp_handle_upload()` replaces `move_uploaded_file()`** in the Language Override upload handler — required by Plugin Check; custom directory and exact filename preserved via `upload_dir` filter and `unique_filename_callback`.
- **`linguaforge_*` template function wrappers** replace inline delegation to keep the public API surface clean.

### Fixed

- **Uninstall index name mismatch** — `uninstall.php` was attempting to drop a DB index named `lf_lang_meta` while `ensure_lang_index()` actually creates it as `idx_lang`. The DROP now targets the correct name, so the index is properly removed on plugin deletion.
- **Double-escaping in Language Switcher** — when using a custom toggle label, `esc_html()` was applied before `wp_kses_post()`, causing `&` to render literally as `&amp;` in the browser. The custom label is now passed raw to `wp_kses_post()` which handles entity normalisation correctly.
- **`wp_unslash()` removed from `$_ENV` API key reads** in `KeyStore` — environment variable values are not magic-quoted; applying `wp_unslash()` could silently corrupt API keys containing backslashes.

---

## [1.0.1] — 2026-05-17

### Added

- **Language Overrides UI** — new section in **Settings → Lingua Forge** to upload, list, and
  delete `.mo` override files for third-party plugins (e.g. VikBooking terminology customisation).
  Each row shows both `.mo` and `.po` presence; Delete removes both files together.
- **Language overrides in uploads** — override `.mo` files are now stored in
  `wp-content/uploads/lingua-forge/i18n-overrides/` instead of inside the plugin directory.
  Files survive plugin updates. The folder is created automatically on activation.
- **`lf_i18n_overrides_dir` filter** — allows custom storage path for override files.
- **"Apply to Meta Description" button** — AI-generated meta descriptions now have a dedicated
  button that writes the result directly into the Meta Description meta box field and into the
  Gutenberg editor store.
- **"Save the post to persist changes" hint** — shown for 6 seconds after applying a translation
  or meta description to the editor, since programmatic auto-save is not reliable with meta boxes.
- **Content Generator limits** — max output tokens, max hints characters, and max context
  characters are now configurable from Settings → Lingua Forge → Content Generator.
- **Quick Translation limits** — model tier (Light/Quality), max output tokens, and max input
  characters are now configurable from Settings → Lingua Forge → Quick Translation.
- **`linguaforge_translation_languages` filter** — the 38-language translation target list is now
  filterable; add, remove, or replace languages without modifying plugin files.
- **38 languages** supported out of the box for AI translation (up from 13), grouped by region.
- **`uninstall.php`** — cleans up all plugin options, post meta, user meta, and the
  `lf_lang_meta` DB index on plugin deletion.
- **Known Issues & Troubleshooting** section added to both README.md and readme.txt covering
  PHP timeouts, empty AI results, translation cut-off, and the meta description workflow.

### Fixed

- **Infinite recursion crash** — `Translation::get_languages()` was passing `self::get_languages()`
  as the default to `apply_filters()`, causing a fatal `Allowed memory size exhausted` error on
  every page load. Fixed to pass `self::LANGUAGES` (the constant array).
- **"Apply to Meta Description" button invisible** — the button was being clipped in the
  flex result bar because `.lingua-forge-feature-group .button { width: 100% }` overrode the
  `flex: 0 0 auto` rule. Moved to its own full-width row below the textarea.
- **Quick Translate double icon** — the editor toolbar inject loop continued past the first
  matching container, injecting the button into multiple elements. Fixed with `break` after
  first successful injection.
- **Translation truncation** — a hardcoded `mb_substr($content, 0, 20000)` cap was silently
  cutting input before sending to the AI, causing incomplete translations. Removed; input limit
  is now configurable (default: no limit).
- **Max-tokens truncation detection** — Anthropic, OpenAI, and Gemini providers now detect
  `stop_reason: max_tokens` / `finish_reason: length` / `finishReason: MAX_TOKENS` and return
  `null` with an error log entry instead of silently returning truncated output.
- **Autoload flags** — all plugin-specific `update_option()` calls now pass `false` as the
  autoload argument so options are not loaded on every page request.

### Changed

- **`BlockTextExtractor`** — removed the `tag()`, `reconstruct()`, and `strip_all_lfids()`
  methods and all related private helpers. The `_lfid` tagging system was compensating for input
  truncation (now fixed at the source) and is no longer needed.
- **Translation max tokens** — raised default from 8 192 to 16 000 to accommodate full-page
  translations without cut-off.
- **Language Router i18n overrides** path moved from `language-router/languages/` to the
  uploads-based `i18n-overrides/` directory (see Added above).
- **`readme.txt`** — added External Services section (required for WordPress.org), Language
  Overrides feature, FAQ entries for timeout and AI errors, and full 38-language list.

---

## [1.0.0] — 2026-05-16

First release of **Lingua Forge** — a combined WordPress plugin merging the previously separate
**Language Router** (v1.3.4), **Meta Description** (v1.1.0), and **WPEnhance AI** (v1.1.6)
must-use plugins into a single installable plugin.

### Added

- **Meta Description sub-module** — SEO meta box with `<meta name="description">`,
  `og:description`, and `twitter:description` output; character counter with green/amber/red
  guidance; fallback chain: custom field → excerpt → site description

### Changed

- Both modules are now loaded from a shared root (`lingua-forge.php`) via `LINGUAFORGE_PATH`
  and `LINGUAFORGE_URL` constants, replacing the hardcoded `WPMU_PLUGIN_DIR` and
  `content_url('mu-plugins/…')` references in each module
- Plugin header moved to `lingua-forge.php`; sub-module entry files are now internal loaders,
  not standalone plugin files
- Activation hook triggers a deferred `flush_rewrite_rules()` so language URL prefixes register
  correctly on first activation without requiring a manual Permalinks save
- Deactivation hook flushes rewrite rules to clean up on removal

### Renamed (breaking for mu-plugin adopters migrating to this release)

All internal identifiers have been unified under the `lingua-forge` / `lf_` / `linguaforge_`
namespace. Sites running the original mu-plugin versions will need to update any theme or
`wp-config.php` references:

| Old name | New name | Context |
|---|---|---|
| `MY_LANG` | `LF_LANG` | PHP constant set by Language Router at boot |
| `my_*()` (31 functions) | `lf_*()` | Language Router theme wrapper functions |
| `my_primary_language` | `lf_primary_language` | WordPress filter hook |
| `my_languages_list` | `lf_languages_list` | WordPress filter hook |
| `my_lang_force_locale` | `lf_lang_force_locale` | WordPress filter hook |
| `my_lang_fallback_map` | `lf_lang_fallback_map` | WordPress filter hook |
| `my_lang_default_fallback` | `lf_lang_default_fallback` | WordPress filter hook |
| `my_hreflang_mode` | `lf_hreflang_mode` | WordPress filter hook |
| `my_lang` (cookie) | `lf_lang` | Browser cookie name |
| `my_lang_filter` | `lf_lang_filter` | GET param / user meta key |
| `my_lang_router_version` | `lf_lang_router_version` | `wp_options` key |
| `WPEnhance\AI\*` | `LinguaForge\AI\*` | PHP namespace |
| `wpenhance_ai_*` options | `linguaforge_*` options | `wp_options` keys |
| `_wpenhance_cache_*` | `_linguaforge_cache_*` | Post meta cache keys |
| `wpenhance-ai/v1` | `lingua-forge/v1` | REST API namespace |
| `WPENHANCE_AI_PROVIDER` | `LINGUAFORGE_PROVIDER` | `wp-config.php` constant |
| `WPENHANCE_AI_SECRET` | `LINGUAFORGE_SECRET` | `wp-config.php` constant |
| `wp_ajax_my_import_translation` | `wp_ajax_lf_import_translation` | AJAX action |

---

## Component history

The entries below preserve the full release history of each module prior to the Lingua Forge
merge. New entries from this point forward will appear in the section above.

---

## Language Router

### [1.3.4] — 2026-05-16

#### Fixed

- **Substring collision in `fix_post`** — `str_replace` on a short URL silently corrupted longer
  sibling URLs sharing the same prefix. Replaced with `preg_replace_callback` using an exact
  `href=(["\'])URL\1` pattern so only the precise href value is touched
- **Root-relative href not matched during fix** — `fix_post` now builds both the absolute and
  root-relative forms of each search URL to cover content saved with root-relative hrefs
- **JS false-positive "Fixed" status** — the `doFix` callback now receives `(ok, applied)` and
  shows a distinct warning when the server reports zero replacements
- **Null pointer in `fix_post`** — added early-return guard when `scan_post` returns `[]` for a
  deleted or invalid post ID
- **Stale TRID translation cache masking valid translations** — `clear_translation_cache()` is
  now called alongside `clean_post_cache()` at the start of every scan and Re-scan
- **False-positive links from breadcrumbs and navigation anchors** — switched to `data-id`-only
  detection, eliminating structural links from scan results entirely

#### Added

- **Re-scan button** — 🔄 Re-scan in the modal action bar lets editors verify fixes without
  closing and reopening the modal
- **Flagged bucket** — links that are wrong but cannot be auto-fixed are now surfaced with an
  amber warning row and a reason code: `unresolved`, `no_translation`, or `permalink_error`
- **Scanned count in AJAX response** — distinguishes "0 posts found" from "X posts scanned,
  all links correct"

#### Changed

- **Post ID resolution switched to `data-id` only** — all previous fallback strategies
  (`get_page_by_path`, leaf-slug lookup, `url_to_postid`) removed; structural links without
  `data-id` are silently skipped

---

### [1.3.3] — 2026-05-16

#### Added

- **Internal Link Fixer (`LSFLR_Link_Fixer`)** — admin-only class that scans translated posts
  and pages for internal links pointing to the source-language version of a page and offers
  AJAX-powered repair via a modal overlay in the posts list

#### Changed

- Minimum PHP version raised from 7.4 to 8.0; `str_starts_with()` and `str_contains()` used
  throughout in place of `strpos()` checks

---

### [1.3.2] — 2026-05-15

#### Fixed

- **Cannot add footnotes to imported pages** — fixed by writing `'[]'` to `footnotes` postmeta
  immediately after `wp_update_post` on import, giving the imported page the same clean state
  as a freshly created page

---

### [1.3.1] — 2026-05-15

#### Changed

- **Footnotes stripped from imported content** — all footnote import code removed after
  repeated failed attempts; the import strips `<!-- wp:footnotes /-->` and inline
  `<sup data-fn="…">` markers from source content before saving to the target

#### Added

- **Source Footnotes metabox** — read-only metabox on non-source translation pages showing
  the source page's footnotes as a numbered reference list

---

### [1.3.0] — 2026-05-15

#### Fixed

- Block Logic JS fix not loading — switched from raw `<script>` tag in action to
  `wp_add_inline_script( 'wp-edit-post', $script )`
- `data-fn` attribute stripped by `wp_kses_post` — added `wp_kses_allowed_html` filter to
  explicitly allow `data-fn` on `<sup>` tags

---

### [1.2.x] — 2026-05-15

Multiple footnote import iterations (1.2.1 through 1.2.9), culminating in the clean-slate
reset in 1.2.7 and the final decision to strip footnotes on import in 1.3.1. See the
individual component CHANGELOG at `language-router/CHANGELOG.md` for the full entry-by-entry
record.

---

### [1.2.0] — 2026-05-14

Full conversion from procedural / closure-based code to an OOP class structure.
`Language_Router` implemented as a singleton; `LSFLR_Switcher` extracted into its own class
with dependency injection; `MY_LANG` constant still defined at file-load time; all theme
wrapper functions preserved.

---

## WPEnhance AI

### [1.1.6] — 2026-05-16

#### Added

- **Meta description result UI** — proper result bar with Copy button, character count, and
  SEO quality tooltip (green 140–160 chars, amber borderline, red outside range)

#### Fixed

- **Model output artifacts stripped on server** — `MetaDescription::clean_output()` removes
  surrounding quotes, "Meta description:" prefixes, markdown bold wrappers, and excess whitespace
  before caching

---

### [1.1.5] — 2026-05-16

#### Changed

- Meta description character limit raised to 140–160 characters; `max_tokens` raised to 384

---

### [1.1.4] — 2026-05-16

#### Fixed

- Accordion block repair algorithm updated to handle both duplication (more top-level blocks
  than original) and escape-without-duplication failure modes in `BlockTextExtractor::repair_structure()`

---

### [1.1.3] — 2026-05-16

#### Fixed

- Accordion blocks break after translation — added prompt rule and PHP structural repair via
  `BlockTextExtractor::repair_structure()` / `reattach_escaped_blocks()`

---

### [1.1.2] — 2026-05-16

#### Fixed

- "Apply to Editor" reported success even when content was not applied — handler is now `async`,
  dispatches are `await`ed, and the result is verified against the Gutenberg store before
  reporting success; failures restore the button state and show an inline error

---

### [1.1.1] — 2026-05-16

#### Fixed

- Meta Description and Excerpt Generator falling back to English for non-English locales —
  locale string now resolved to a human-readable language name before prompt construction
- Quick Translate "Clear" buttons not loading in admin toolbar — asset version bumped to force
  cache flush
- Quick Translate action-button rows unstyled — missing flex rules added to both stylesheets

---

### [1.1.0] — 2026-05-15

#### Added

- **Admin Toolbar Quick Translate** — ⇌ icon in the WP admin bar opens a popover for
  translating any text snippet on the fly, backed by the new `/translate-chunk` REST endpoint
- **Editor Toolbar Quick Translate** — same popover injected into the Gutenberg / FSE editor's
  pinned-items bar via `MutationObserver`
- **`POST /wpenhance-ai/v1/translate-chunk`** REST endpoint
- **Quick Translate Clear buttons** — "Clear" (input only) and "Clear All" (input + output)
  buttons in both popovers

---

### [1.0.6] — 2026-05-15

#### Added

- Configurable model endpoints per provider and tier from **Settings → WPEnhance AI → Models**;
  two-tier model abstraction (`light` / `quality`) with `Config::model()` as single source of truth

---

### [1.0.5] — 2026-05-15

#### Added

- **Chunk translation mode** — Mode selector in the Translation panel; chunk textarea with
  Copy-only result (no Apply); generic `data-condition-field` / `data-condition-value`
  conditional visibility system

#### Changed

- Metabox context moved from `'side'` to `'normal'`; feature groups rendered as cards with
  `flex-wrap`

---

### [1.0.4] — 2026-05-15

#### Fixed

- Unsaved footnotes ignored during translation — changed `meta._footnotes` → `meta.footnotes`
  in `collectParams()`
- Translated footnotes overwritten on post save — footnotes now dispatched through
  `editPost({meta: {footnotes: …}})` instead of a separate REST call

---

### [1.0.3] — 2026-05-14

#### Fixed

- Footnotes not translated — introduced `{{extra_output}}` placeholder in `translation.txt`
  so footnote and block-attribute instructions are injected inside the template rather than
  appended after a conflicting constraint

---

### [1.0.2] — 2026-05-14

#### Fixed

- Root cause of `<br>` corruption — `escapeHtml()` in `admin.js` was using the
  `div.innerText / div.innerHTML` DOM trick, which converts newlines to `<br>` on `innerHTML`
  readback; replaced with a plain string-replacement escape

---

### [1.0.1] — 2026-05-14

#### Fixed

- `<br>` tags re-introduced by `wpautop` in REST responses — added `rest_pre_echo_response`
  hook at priority 999 to strip `<br>` from the `output` field after all other filters

---

### [1.0.0] — 2026-05-14

#### Fixed

- Apply to Editor had no effect in Gutenberg — changed to `window.parent.wp.data.dispatch`
  to cross the legacy metabox iframe boundary
- Post title was not translated — title now returned via `===TITLE===` separator and applied
  alongside content in the same Apply click
- `<br>` tags injected into block markup — prompt rule + `preg_replace` safety net in
  `Translation::run()` and `ContentGenerator::run()`
- All features shared a single result panel — each feature group now has its own result container

---

### [0.9.0] — 2026-05-14

Block attribute translation: `BlockTextExtractor` class extracts translatable attribute strings
(`summary`, `alt`, `caption`, etc.) from block comment JSON, replaces them with `__WPAI_N__`
placeholders, translates in the same API call, and reinserts with proper JSON escaping.

---

### [0.8.0] — 2026-05-14

Content Generator: added **Hints** textarea as a seed for generation; hints take priority
over existing post body as context.

---

### [0.7.0] — 2026-05-14

Content Generator feature: drafts or rewrites post content with selectable Tone and Output
type; output uses native Gutenberg block markup.

---

### [0.6.0] — 2026-05-14

Force-refresh control (↺ Refresh) below any cached result; shared `runFeature()` JS function
eliminates duplication between the action-button and refresh handlers.

---

### [0.5.0] — 2026-05-14

Result caching across all features using SHA-256 hash of inputs; per-language translation
cache; `CacheStore` class; "cached" badge in the UI.

---

### [0.4.0] — 2026-05-14

Footnote translation support (same API call as content); fatal error on Linux fixed (filename
case mismatch `autoloader.php` → `Autoloader.php`).

---

### [0.3.0] — 2026-05-14

Content Translation feature; Google Gemini provider; `WorkerConfig` value object;
`KeyStore` with AES-256-CBC encrypted storage; Settings page with provider selector,
API key fields, and source badges.

---

### Earlier

See `wpenhance-ai/CHANGELOG.md` for the full pre-0.3.0 entry-by-entry record.
