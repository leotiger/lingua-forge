# Changelog — LinguaForge

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

- **Language Overrides UI** — new section in **Settings → LinguaForge AI** to upload, list, and
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
  characters are now configurable from Settings → LinguaForge AI → Content Generator.
- **Quick Translation limits** — model tier (Light/Quality), max output tokens, and max input
  characters are now configurable from Settings → LinguaForge AI → Quick Translation.
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

First release of **LinguaForge** — a combined WordPress plugin merging the previously separate
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

The entries below preserve the full release history of each module prior to the LinguaForge
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
