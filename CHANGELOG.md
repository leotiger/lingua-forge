# Changelog — Lingua Forge

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

- **Slug not updated on retranslation** — when a WP-CLI `translate` / `retranslate` / `fill-translations` command updates an existing translated post, `wp_update_post()` does not automatically regenerate `post_name` from `post_title`. The CLI now explicitly adds `post_name => sanitize_title($translated_title)` to the update arguments whenever a translated title is present, so the URL slug stays in sync with the translated title across all CLI translation commands.
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
- **WP-CLI `--debug` flag** — available on `translate`, `retranslate`, and `fill-translations`. Forces debug-file writes for that run and echoes the source prompt and raw API response inline in the terminal. Provider errors surface in WP-CLI output regardless of `--debug`.
- **`Translation::force_debug(bool)`** — runtime debug activation without touching the database option or wp-config.php; used by the CLI flag, also available for custom scripts.
- **HTTP timeout raised to 300 s** — was hardcoded at 120 s; now 300 s by default and configurable via the `linguaforge_ai_retry_policy` filter (`timeout` key) for very large posts.
- **WP-CLI `fill-translations` and `missing-translations` commands** — bulk-fill missing router-language translations for a post in one pass; scan all posts of a given type for missing translations.
- **WP-CLI `--with-meta-description`** — available on `translate`, `retranslate`, and `fill-translations`; generates and saves a meta description for each translated post immediately after writing its content.
- **Settings → Behavior** — Global AI Preset live preview, renamed Custom prompt instructions field with realistic placeholder, Standard preset temperature hint in dropdown.
- **Settings → Router tab** — Primary Language selector, Flush Permalinks button, Active Languages chip list, Install Language pack section.
- **Glossary "any target language" entries** — apply a term to all target languages at once.
- **Custom prompt instructions honoured on Standard preset** — previously silently discarded; now always applied when saved.

---

## [1.2.17] — 2026-05-18

### Added

- **WP-CLI `--debug` flag on `translate`, `retranslate`, and `fill-translations`** — forces translation debug-file writes for that single run (no need to enable debug site-wide or touch `wp-config.php`), and immediately echoes the source prompt and raw API response for each language to the terminal inline after the call returns. Provider errors — timeouts, HTTP failures, truncation, bad JSON — are also printed inline via the same channel. This makes it possible to inspect exactly what was sent and what came back for a specific failing post without tailing any log file:
  ```
  wp linguaforge translate 42 --to=fr --debug
  wp linguaforge retranslate 42 --to=fr --debug
  wp linguaforge fill-translations 42 --debug
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

- **`--with-meta-description` flag on `translate`, `retranslate`, and `fill-translations`** — when passed, each command generates and saves an AI meta description for every translated post immediately after writing its content, storing it under `_linguaforge_meta_description` (the same key the admin metabox writes). The description is generated in the target language using the post's `_lang` meta via `MetaDescription::run()`. Skipped on `--dry-run` (no target post exists to write to) and on `--check-only` in `fill-translations`. The `detail` column in the results table appends `+ meta` on success or `+ meta (error: …)` on failure so every operation is visible in the same output row. This makes a full multilingual content pass possible in one command: `wp linguaforge fill-translations 42 --draft --with-meta-description`.

---

## [1.2.11] — 2026-05-18

### Added

- **WP-CLI `missing-translations` command** — `wp linguaforge missing-translations <lang> <post_type>` scans every post of the given type whose `_lang` matches the source language and reports which posts are missing one or more router-language translations. Output columns: `post_id`, `title`, `post_status`, `missing` (comma-separated language codes), `count`. Sorted by missing count descending so the most incomplete posts surface first. Supports `--exclude`, `--status` (default `publish`, accepts `any`), and `--format`. Pairs directly with `fill-translations`: the final warning line shows the exact command to run on each incomplete post, and `--format=json | jq -r '.[].post_id' | xargs` pipelines work out of the box.

### Fixed

- **Custom prompt instructions ignored on Standard preset** — `Config::apply_compliance_to_system()` returned early for the Standard preset even when an explicit custom addendum had been saved, silently discarding it. The custom addendum now always wins regardless of which preset is active; Standard without a saved custom addendum continues to leave the system prompt untouched.

### Changed

- **Settings → Behavior — Global AI Preset preview** — selecting a preset in the dropdown now instantly shows its built-in system-prompt instructions in a read-only panel below the dropdown, so editors can see exactly what each preset does and use the format as a template for the Custom prompt instructions field.
- **Settings → Behavior — Custom prompt instructions** — renamed from "Custom system-prompt addendum"; now shows a realistic placeholder example (renewable-energy abbreviations, formal register, flag-unknowns rule) and an **Active** notice when custom instructions are saved.
- **Settings → Behavior — Standard preset temperature hint** — the dropdown now shows `(T=0.2–0.6, per feature)` next to Standard so it is comparable to the explicit temperatures on the other presets.

---

## [1.2.10] — 2026-05-18

### Added

- **WP-CLI `fill-translations` command** — `wp linguaforge fill-translations <post_id>` checks which router languages are missing a translation for a post and creates them in one go. Use `--check-only` to report missing languages without touching the database. Respects `--exclude`, `--draft`, `--dry-run`, `--format`, and all provider/model/token override flags. Uses only the active router languages (not the full AI-supported language list), so it's safe to run against any post without generating unwanted locales.

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
- **WP-CLI commands** — `wp linguaforge translate <post_id> --to=fr,de[,…]` (with `--temperature`, `--max-tokens`, `--model`, `--force`, `--dry-run` overrides) and `wp linguaforge cache-clear` (with `--feature` and `--post-id` scope flags, requires `--yes` for full truncate).
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
