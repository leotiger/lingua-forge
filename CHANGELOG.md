# Changelog — Lingua Forge

---

## [2.6.5] — 2026-07-13

Addresses three AI Provider settings gaps found while trying to override the
Anthropic Quality-tier model to a newer Claude generation: the model
suggestion list looked permanently stale, there was no way to actually test a
typed-in override before saving it, and the newer model rejected the request
outright once it could be tested.

### Fixed
- **The Models datalist (Settings → AI Provider) reverted to the hard-coded built-in catalog on every page load, discarding the live model list fetched from the provider's own API.** `ApiKeysTab::ajax_test_provider()` already called `ModelCatalog::fetch_from_api()` on a successful "Test connection" and cached the merged catalog+live list in a 24-hour transient (`linguaforge_available_models_{provider}`) — but `GeneralTab::render_content()` never read that transient back; it only ever rendered `ModelCatalog::for_provider()`, the static compiled-in list. The live fetch was real and worked, it just never survived a page refresh, which is why newly-released models could disappear from the suggestions moments after a successful test. `GeneralTab::render_content()` now reads the cached transient first and falls back to the static catalog only when nothing has been cached yet. (`ai/includes/Admin/Settings/Tabs/GeneralTab.php`)
- **Overriding the Anthropic Quality (or any tier) model to a newer Claude generation that has deprecated the `temperature` sampling parameter failed outright with an HTTP 400 ("temperature is deprecated for this model").** `Anthropic::build_request()` unconditionally sent `temperature` on every call, including the compliance/legal/technical/creative presets that rely on it (`Config::apply_compliance()`). Removing the parameter entirely would have broken preset-driven determinism for models that still accept it, so instead `AbstractProvider::chat()` gained a bounded strip-and-retry: on a 400 whose message names a specific parameter as deprecated for the model, it retries once with that key removed from the request body, via two new provider-overridable hooks (`droppable_sampling_params()`, `is_deprecated_param_error()`). Anthropic is the only provider that currently opts in. (`ai/includes/Providers/AbstractProvider.php`, `ai/includes/Providers/Anthropic.php`)

### Added
- **"Test model" button next to every Light/Quality model field in Settings → AI Provider → Models — runs a real translation of real content, not a ping.** The existing "Test connection" button (API Keys tab) always pings the saved *light*-tier model for the provider with "reply with the word ping," which only proves a model responds at all — it can't exercise a Quality-tier override, doesn't send real content, and doesn't apply the active behaviour preset's temperature/addendum, so it couldn't have confirmed whether a Quality override actually produces usable translations. The new button tests the exact (saved or unsaved) string in its field by translating a short sample of the site's most recently published post through the tier's real production code path — full JSON-envelope translation for Quality (`Translation::prepare_full_post_inputs()` + `build_system_prompt()` + `JsonEnvelopeTranslator`), chunk translation for Light (mirroring `ChunkTranslation`) — with the currently active Settings → Behavior preset applied exactly as a live translation would, and shows a preview of the translated output plus the preset/language used. Runs via a new `linguaforge_test_model` AJAX endpoint; makes a real, billed API call (result is not cached). `Translation::prepare_full_post_inputs()`, `build_system_prompt()`, and `resolve_compliance_addendum()` were widened from `private` to `public static` so this test (and future callers) can reuse the exact production logic instead of duplicating it; `JsonEnvelopeTranslator::translate()` gained an optional `$bypass_cache` parameter so the test never leaves a stray cache entry under the sampled post. (`ai/includes/Admin/Settings/Tabs/GeneralTab.php`, `ai/includes/Admin/Settings/Tabs/ApiKeysTab.php`, `ai/includes/Admin/Settings/Tabs/AiProviderTab.php`, `ai/includes/Admin/SettingsPage.php`, `ai/includes/Features/Translation.php`, `ai/includes/Features/JsonEnvelopeTranslator.php`, `ai/assets/test-connection.js`, `ai/assets/settings.css`)

---

## [2.6.4] — 2026-07-11

Addresses AUDIT-2026-07-11 §2 through §7, the ScaffoldHandler theme-scoping
gap tracked in §9/§12 row 8, and the WpAiClient API-verification item carried
in §10/§12 row 9: the plugin's three translated-post creation paths had
drifted apart in two more ways (excerpt-at-creation, WooCommerce variation
sync), each fixed in only a subset of the three paths; the language-uninstall
locale-file matcher was both greedy (cross-language collisions) and blind
(wrong-shaped for the plugins/themes suffix convention); Sync's cross-post
writes were authorized only against the triggering post, not each post they
actually overwrite; uninstall.php's own cleanup sweep had drifted ~30 options,
several meta keys, and all cron/Action Scheduler jobs behind current source;
the FSE force-recreate path could look up an existing template/part to update
in place with no theme scoping at all; and the WordPress AI Client provider
threw an uncaught PHP TypeError on any multi-turn refinement flow.

### Fixed
- **A first-time translated post created via "Translate missing"/Sync (`PostListColumn::create_linked_post()`) or the WP-CLI `translate`/`fill_translations` commands (`AbstractTranslateCommand::create_trid_linked_post()`) was born with no excerpt.** The 2.4.0 fix ("A first-time translated post now keeps its translated excerpt") only reached `TranslationTrigger::create_translated_post()` — the path third-party integrations use via `linguaforge_trigger_translation()`/`linguaforge_queue_translation()` — leaving the admin-list and CLI creation paths writing an empty `post_excerpt` on create (their *update* branches already wrote it correctly). Effect: `SeoManager::get_og_description()` fell back to a trimmed slice of post_content instead of the real excerpt, and any excerpt-driven archive/theme output was empty, for any translation created through those two paths. (`ai/includes/Admin/PostListColumn.php`, `ai/includes/CLI/AbstractTranslateCommand.php`)
- **A translated WooCommerce variable product created via the programmatic API (`linguaforge_trigger_translation()`/`linguaforge_queue_translation()`, including the Automatic Translation Backfill scan) or the WP-CLI create path was born with no translated variation children and no WC structural taxonomies (`product_type`, `pa_*`, `product_brand`).** `VariationSync::maybe_sync_on_save()`'s `wp_after_insert_post` priority-30 hook always saw an empty `_lf_lang` during creation (both paths write that meta *after* `wp_insert_post()` returns) and silently bailed; nothing called it afterwards. Only `PostListColumn::create_linked_post()` already compensated with an explicit call. Effect: frontend variation matching failed on the new product until something else re-saved it. (`ai/includes/Features/TranslationTrigger.php`, `ai/includes/CLI/AbstractTranslateCommand.php`)
- **`LanguageUninstaller::collect_locale_files()`'s prefix matching could delete the wrong plugin/theme locale files, or miss the right ones.** At `WP_LANG_DIR` root, the old check matched any filename *starting with* the target locale, so uninstalling `de` could also delete an unrelated `den_DK.mo`, and uninstalling `ar`, `ce`, `az`, or `ka` could delete real dialect files (`ary`/`arq` Moroccan/Algerian Arabic, `ceb` Cebuano, `azb` South Azerbaijani, `kab` Kabyle) that merely share a 2-letter prefix with the target. Separately, the `plugins/`/`themes/` subdirectories use a `{textdomain}-{locale}.mo` *suffix* convention that the same prefix-shaped check could never match, so real per-plugin/theme locale files there were silently left behind on uninstall. New `locale_root_matches()` (anchored, no false-prefix collisions) and `locale_suffix_matches()` (matches the actual `-{locale}.mo` suffix) helpers replace the ambiguous shared regex; `collect_override_files()` (i18n-overrides / Loco "Copy to Safe Storage") now reuses `locale_suffix_matches()` instead of duplicating its own copy. (`ai/includes/Admin/Language/LanguageUninstaller.php`)
- **Sync and Template Sync could overwrite a sibling post the current user has no permission to edit.** `run_sync()` and `run_sync_templates()` each checked `current_user_can( 'edit_post', ... )` only on the triggering post, then wrote to every other post in the TRID group — including, once the secondary-language safeguards are relaxed, the primary post itself — with no per-target check. On the common admin/editor setup (`edit_others_posts`) this was moot, but an Author-role workflow could overwrite a sibling (or, indirectly, the primary) they don't have edit rights on. Both methods now check `current_user_can( 'edit_post', $target_id )` against each *existing* sibling before overwriting it, skipping and reporting (`'status' => 'skipped'`) any target the caller can't edit instead of writing to it regardless — matching the skip-and-report pattern `TrashCascade::trash_group()` already used. A post about to be *created* (not yet existing) has nothing to authorize against and is unaffected. `linguaforge_sync_translations()` / `linguaforge_sync_templates()`'s existing `$check_caps = false` default for trusted programmatic callers bypasses this new check the same way it already bypassed the triggering-post one. (`ai/includes/Admin/PostListColumn.php`)
- **`uninstall.php`'s cleanup sweep had drifted ~30 options, several post meta keys, and every cron/Action Scheduler job behind current source, despite its own docblock's blanket "all linguaforge_*/lf_* options" claim.** Missing entirely: the whole 2.2.0 SEO layer (~15 options), the sitemap cache bookkeeping trio, `linguaforge_indexnow_key`, `linguaforge_primary_language`, `linguaforge_routing_mode`, both secondary-sync toggles, `linguaforge_api_cache_enabled`, the per-taxonomy `linguaforge_attr_labels_{taxonomy}` set, `linguaforge_fse_translated_slugs`, and `lf_browser_redirect`; the sitemap/updater/theme-switch-notice transients; the `_lf_noindex`, `_lf_page_menu_exclude`, `_lf_seo_score_history`, `_lf_translation_failures`, and `_lf_auto_template` post meta keys; and any cleanup at all for the backfill cron, the IndexNow submit cron, or queued-translation jobs (WP-Cron fallback or Action Scheduler). The named-options array plus five narrow LIKE prefixes are replaced with one `linguaforge\_%` options sweep and one `_transient_linguaforge_%`/`_transient_timeout_linguaforge_%` transient sweep — both self-updating for any option/transient shipped in the future, rather than requiring its own uninstall.php entry. The five newly-identified post meta keys were added to the appropriate list (regenerable bookkeeping vs. editorial content, matching the file's existing default-keep philosophy), and `wp_clear_scheduled_hook()`/`as_unschedule_all_actions()` calls now clear all three cron hooks and the `lingua-forge` Action Scheduler group. `CONTRIBUTING.md`'s "When you add something new" checklist updated to match the new approach and gained a dedicated cron/Action Scheduler item. (`uninstall.php`, `CONTRIBUTING.md`)
- **The FSE "Re-create" force path (Templates and Template Parts) looked up the existing DB post to update in place with no theme scoping at all**, so a force re-create could silently grab and overwrite an unrelated same-slug row belonging to a different theme (or, for template parts, a bare `post_name` collision with any other `wp_template_part` on the install). WordPress's own `wp_unique_post_slug()` deliberately scopes `wp_template`/`wp_template_part` uniqueness by the `wp_theme` taxonomy term rather than renaming, so two different themes legitimately can and do have DB rows sharing an identical slug. Both `ScaffoldHandler::ajax_scaffold_template()` and `ajax_scaffold_template_part()` now look up the existing post via the same theme/namespace-scoped `get_block_templates(['slug__in' => ..., 'theme' => ...])` call the rest of the class already uses, instead of a bare `get_posts()` by `name` alone. The update-vs-insert decision itself was extracted into a new pure static helper, `ScaffoldHandler::resolve_existing_post_id()`, specifically so it could be unit-tested without a WordPress boot (the AJAX handlers around it still need one). (`ai/includes/Admin/FseLocalisation/ScaffoldHandler.php`)
- **The WordPress AI Client provider (`WpAiClient`, WP 7.0+) threw an uncaught PHP `TypeError` on any multi-turn refinement request** — reachable any time the "Refine" step of AI Content Generation or Chunk Translation ran against this provider specifically (the other three providers were unaffected). `WpAiClient` was written against a March-2026 preview of WordPress's built-in AI Client API; verifying it against the API as actually shipped on 2026-05-20 found that `with_history()` requires real `WordPress\AiClient\Messages\DTO\Message` objects, not the plain `['role' => ..., 'content' => ...]` arrays this codebase's other providers pass around — and because a `TypeError` isn't an `Exception`, the builder wrapper's own error handling couldn't catch it either, so the request fataled outright instead of failing gracefully. New `WpAiClient::build_history_messages()` converts each history turn into a real `Message` object (`user`→`UserMessage`, `assistant`→`ModelMessage`) before calling `with_history()`. (`ai/includes/Providers/WpAiClient.php`)

### Changed
- **Extracted `TranslationTrigger::build_create_args()`** — a shared static helper that builds the common `wp_insert_post()` args (title, content, status, type, author, and the translated excerpt when present) every translated-post creation path needs. All three creation paths now call it instead of each independently constructing the same fields, so the next fix to a common field (this is the second time an excerpt-shaped bug has landed in only a subset of the three paths — see also the 2.6.1 template-assignment fix) lands everywhere by construction. Purely a refactor of existing, already-tested logic — no behaviour change to the path that was already correct. (`ai/includes/Features/TranslationTrigger.php`)
- **Extracted `TranslationTrigger::sync_variation_children_if_product()`** — a second shared helper, this one for the WooCommerce variation-sync fix above. `PostListColumn::create_linked_post()` was refactored to call it too, replacing its own inline duplicate of the same guard, so all three creation paths now share one implementation instead of three independent (and now two formerly missing) copies. (`ai/includes/Features/TranslationTrigger.php`, `ai/includes/Admin/PostListColumn.php`)

### Docs
- **`COMPETITIVE-ANALYSIS.md` refreshed** — was pinned at "June 2026 · 2.3.1" and undersold six 2.4–2.6 differentiators (Sync/Template Sync, Trash + Siblings, the async translation queue + self-healing backfill, latest-posts front-page support, featured-image copy + fixer, and the 2.6.2 Re-create/Recreate-All-Languages tooling), all now covered. WP-CLI corrected from "5 commands" to the actual 6 (`fix_nav_lang` was missing everywhere it was counted). Two copy-paste-duplicated bullets in the "When to Choose Each Plugin" section (TranslatePress, Weglot) collapsed to one each.
- **`SECURITY.md`'s supported-versions table** no longer pins a specific version number (was stuck at "2.3.x") — now reads "latest minor release," which won't go stale again next cycle.
- **`CONTRIBUTING.md`** — `OrderItemNormalizer`'s documented hook corrected from the stale `woocommerce_new_order_item` to the actual `woocommerce_checkout_create_order_line_item`; the Settings-page-layout Router section gained the 2.6.x FSE scaffold/sync DOM class inventory (`.lf-recreate-*`, `.lf-parts-group`, `#lf-global-recreate-*`, `.lf-global-run-btn`, and related) that had never been documented.
- **Removed a dead JS click handler** — `.lf-scaffold-all-parts-btn` in `fse-scaffold.js` had no matching button anywhere in the Template Parts panel and could never fire; removed along with its header docblock mention rather than adding an unrequested new button. (`ai/includes/Admin/FseLocalisation` templates were already free of the class; only the JS handler and doc line needed removing.)
- **Trimmed a self-contradicting docblock** in `FeaturedImageFixer` that opened by claiming the three translation-creation paths don't copy the featured image, then corrected itself a paragraph later — now leads with the current (since 2.5.0) fact directly.

## [2.6.3] — 2026-07-11

Addresses the one High finding of `AUDIT-2026-07-11.md` (§1): Automatic Translation
Backfill (2.5.3) spent the site owner's AI-provider money automatically and in the
background, with no setting to stop it, while `readme.txt` claimed the opposite.

### Changed
- **Automatic Translation Backfill is now off by default, behind a new Settings → Behavior → Automatic Translation Backfill toggle.** Since 2.5.3 the hourly scan ran unconditionally for every site with the AI module active — installing/updating the plugin (with an API key configured) was enough to start autonomous, unattended AI translation of every content gap it found, across every public post type including WooCommerce products, with no admin-visible indication it was running and no way to turn it off short of a developer-only filter. The recurring event is now scheduled only while the new option is enabled, and `maybe_schedule()`/`run()` both re-check it — turning the setting off unschedules the event and stops the worker on its very next tick, not just on future activations. (`ai/includes/Features/TranslationBackfill.php`, `ai/includes/Admin/Settings/Tabs/BehaviorTab.php`, `ai/includes/Admin/SettingsPage.php`)
- **WooCommerce products/variations are excluded from the backfill scan by default.** The scan's only creation path (`TranslationTrigger::create_translated_post()`) doesn't run `VariationSync`, so an autonomously-created translated variable product was born with no variation children (§3 of the same audit). Still reachable via the existing `linguaforge_backfill_post_types` filter for integrations that want it. (`ai/includes/Features/TranslationBackfill.php`)
- **`readme.txt`'s External Services and AI-data-and-privacy sections now disclose the background AI sends Automatic Translation Backfill makes while enabled**, correcting prior wording that unconditionally promised no data is ever sent automatically or in the background.

### Fixed
- **The backfill scan now applies the `linguaforge_cpt_create_allowed` filter per post type**, matching the same creation gate "Translate missing" and Sync already honour. Previously the backfill was the one creation path that bypassed it entirely, so it would create exactly the translations an integration's delegation layer (e.g. Agnosis) uses that filter to prevent. (`ai/includes/Features/TranslationBackfill.php`)
- **The backfill scan no longer queues jobs when no usable AI provider/API key is configured.** Previously, on a site with the AI module active but no key set (or a revoked one), every queued job failed and re-entered the 24-hour failure cooldown forever — an hourly `posts_per_page => -1` scan with no possibility of ever succeeding. `run()` now checks provider/key usability up front and returns early instead. (`ai/includes/Features/TranslationBackfill.php`)

## [2.6.2] — 2026-07-10

### Added
- **"Re-create all" and per-template "Re-create" — Settings → Router → Templates.** "Create missing" and the per-card "Create" button only ever operate on templates that don't exist yet — there was no way to force-refresh a template that already exists from within the same panel (e.g. after a theme update changed the base template, or to discard a Site Editor customisation that broke something). Re-create bypasses the existing "already exists" bail in `ScaffoldHandler::ajax_scaffold_template()` via a new `force` POST param, re-resolves the source content the same way normal creation does (active theme → other template owner → theme index → empty), and applies it in place: if a DB-stored `wp_template` post already exists for the slug it's `wp_update_post()`-ed (same post ID preserved), otherwise it's inserted fresh exactly like a normal "Create" — which also covers the file-only case (a theme `.html` template with no DB row yet) by creating the DB override. New `.lf-recreate-all-btn` (toolbar, always visible) and `.lf-recreate-one-btn` (per card, shown alongside Translate/Fix Links/Fix Parts once a template exists) in `TemplatesSection.php`, wired in `fse-scaffold.js` by extending the existing `scaffoldOne()` helper with a `force` argument. Both actions are destructive (they discard any Site Editor customisations on the overwritten template) and are guarded by a `confirm()` dialog before the AJAX call fires, matching the existing "Sync" button's confirmation pattern. (`ai/includes/Admin/FseLocalisation/ScaffoldHandler.php`, `ai/includes/Admin/Settings/Tabs/Sections/TemplatesSection.php`, `ai/assets/fse-scaffold.js`, `ai/includes/Admin/SettingsPage.php`)
- **Same "Re-create all" / "Re-create" pair for Template Parts — Settings → Router → Template Parts (headers, footers, and any other theme part).** Identical design applied to `ScaffoldHandler::ajax_scaffold_template_part()`: the same `force` bypass, the same DB-post-lookup-and-update-in-place-or-insert logic (now against `wp_template_part`), and the same destructive `confirm()` guard. The Template Parts panel previously had no per-language wrapper element to anchor a bulk action against (each part was its own standalone table row) — added a `.lf-parts-group` container (with a `.lf-template-bulk-actions` toolbar inside it, matching the Templates panel's layout) wrapping the existing per-language table so `.lf-recreate-all-parts-btn` has a scope to iterate every part row in. New `.lf-recreate-all-parts-btn` and `.lf-recreate-part-btn` in `TemplatePartsSection.php`, wired in `fse-scaffold.js` by extending `scaffoldPart()` with the same `force` argument. (`ai/includes/Admin/FseLocalisation/ScaffoldHandler.php`, `ai/includes/Admin/Settings/Tabs/Sections/TemplatePartsSection.php`, `ai/assets/fse-scaffold.js`, `ai/includes/Admin/SettingsPage.php`)
- **"Recreate All Languages" — a single button above the language tabs (Settings → Router → Language Setup) that runs the full per-language bulk workflow across every active language in one click.** With many installed languages, running Re-create all templates → Re-create all parts → Translate all → Fix all parts → Fix all links separately on every language tab was repetitive and easy to lose track of. The new global button runs that exact five-step sequence for every language, one language at a time (never in parallel — many languages' worth of concurrent AJAX calls at once, especially for Translate all's AI calls, risks overwhelming the server or the AI provider). All language panels are already present in the DOM (RouterTab renders every one up front and only hides inactive tabs via CSS), so the run needs no tab-switching or extra page load — purely a client-side orchestrator (`fse-global-actions.js`) that calls the same underlying row-level functions each per-language button already uses, now shared via `window.lfFseActions` (exposed by `fse-scaffold.js`, `fse-translate.js`, `fse-part-fixer.js`, and `fse-link-fixer.js`, each refactored to extract its row-level logic out of its click handler so both the button and the global orchestrator call the same function). One upfront `confirm()` covers the whole run (explicitly warning about AI cost from Translate all) rather than a confirm per language per step. A Cancel button appears once the run starts and is checked between languages — the language currently in progress always finishes, then the run stops rather than starting the next one. A failure in any step for a language is logged and the run continues to the next language rather than aborting, with a final summary listing exactly which language/step combinations had failures. No new AJAX endpoints or PHP business logic — purely JS-side sequencing over existing, already-working endpoints. (`ai/assets/fse-global-actions.js` NEW, `ai/assets/fse-scaffold.js`, `ai/assets/fse-translate.js`, `ai/assets/fse-part-fixer.js`, `ai/assets/fse-link-fixer.js`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`, `ai/includes/Admin/SettingsPage.php`, `ai/assets/settings.css`)
- **"Recreate All Languages (Template Parts Only)" — a second global button next to the first, for when only headers/footers/etc. need touching up across every language.** Same language-by-language sequencing, Cancel behaviour, and failure-summary reporting as the full run above, but with only the Re-create all parts step — no template rebuilding, no re-translation, no link/part-ref fixing. `fse-global-actions.js`'s step list was refactored from one fixed array into named step definitions (`STEP_RECREATE_TEMPLATES`, `STEP_RECREATE_PARTS`, etc.) composed into `ALL_STEPS` and `PARTS_ONLY_STEPS`, with a shared `startGlobalRun(steps, confirmTpl)` entry point so both buttons reuse the same sequencing/progress/cancel/summary logic and the same `running` guard (both buttons share the `.lf-global-run-btn` class and are disabled together for the duration of either run, so the two variants can never overlap). (`ai/assets/fse-global-actions.js`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`, `ai/includes/Admin/SettingsPage.php`)
- **"Recreate All Languages (Templates Only)" — a third global button, the mirror image of "Template Parts Only".** Runs every step that touches the Templates panel — Re-create all templates, Translate all, Fix all parts, Fix all links — for every active language in sequence, while leaving template parts untouched. New `TEMPLATES_ONLY_STEPS` constant in `fse-global-actions.js`, deliberately defined as a clean partition alongside `PARTS_ONLY_STEPS`: every step in `ALL_STEPS` appears in exactly one of the two "only" variants, so the three buttons together cover every combination an admin would reasonably want without introducing a fourth, fifth, etc. custom mix. Same `startGlobalRun()` entry point, same shared `.lf-global-run-btn` mutual-exclusion guard as the other two buttons. (`ai/assets/fse-global-actions.js`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`, `ai/includes/Admin/SettingsPage.php`)
- **"Translate all", "Fix all links", and "Fix all navs" bulk buttons added to the per-language Template Parts toolbar — Settings → Router → Template Parts — bringing it to parity with the Templates panel's toolbar, which already had these (plus "Fix all parts", which has no parts-side equivalent since it fixes template→part references, not part→part ones).** Previously the Parts toolbar only had "Re-create all"; translating or fixing links/nav-refs across every part still had to be done one part at a time. `translateAllInRow()` (`fse-translate.js`) and `fixAllLinksInRow()` (`fse-link-fixer.js`) were generalised to accept either a Templates `.lf-tpl-row` or a Template Parts `.lf-parts-group` — both already took an explicit scope element, so this only required branching the toolbar message-span lookup (a `.lf-parts-group` contains one `.lf-scaffold-row-msg` per part row plus the toolbar's own, so the toolbar's is found explicitly via `.lf-template-bulk-actions` rather than a bare `.find()`, exactly as `recreateAllPartsInGroup()` already did). "Fix all navs" had no prior extracted function to reuse — its logic existed only inside `.lf-fix-nav-refs-row-btn`, a dead click handler with no matching button anywhere (flagged in an earlier session as a pre-existing gap from a layout change): properly extracted into `fixAllNavRefsInGroup()` and wired to a new `.lf-fix-nav-refs-all-btn`, replacing the dead handler rather than leaving it alongside working code. New `.lf-translate-all-parts-btn` / `.lf-fix-links-all-parts-btn` / `.lf-fix-nav-refs-all-btn` in `TemplatePartsSection.php` ("Translate all" gated on `$ai_active`, matching the Templates toolbar's own gate). Not wired into the "Recreate All Languages" global buttons in this pass — those still only cover Re-create for parts. (`ai/assets/fse-translate.js`, `ai/assets/fse-link-fixer.js`, `ai/assets/fse-part-fixer.js`, `ai/includes/Admin/Settings/Tabs/Sections/TemplatePartsSection.php`)

---

## [2.6.1] — 2026-07-09

### Added
- **`linguaforge_template_for_lang` filter** — lets an integration override the language-specific FSE template slug Lingua Forge is about to assign to a translated post. Applied inside `Sync::resolve_template_for_lang()`, the single method every template-assignment path already resolves through (normal editor save, WP-CLI `translate`/`retranslate`, the post-list "Sync" button, and programmatic creation via `linguaforge_trigger_translation()`/`linguaforge_queue_translation()`), so one hook covers all of them consistently rather than only the creation path. Never fires for the source-language post — forcing an explicit template onto the primary post is out of scope, since it would also change `assign_template_if_needed()`'s "revert to default when language changes back to source" behaviour, which assumes a null resolution means "nothing to do here." Returning an empty string or `null` from the filter suppresses assignment entirely, handled identically to the existing "no template" case (reverts a previously auto-assigned template, never touches an explicit user choice). No live naming mismatch prompted this — LF's own `single-{post_type}-{lang}` convention already matches every scaffolded template on this project's own site — added as forward-looking API surface for integrations whose CPT template naming diverges from that convention. (`language-router/includes/translation/class-sync.php`)
- **"Template Sync" (TS) — a new button next to Sync in the post list Lang column, plus `linguaforge_sync_templates()` for programmatic use.** Sync's AI call is overkill when the only thing wrong is a stale or missing `_wp_page_template` — e.g. after the bug fixed above, a template rename, or a theme change. Template Sync reassigns the correct language-specific template for every *existing* sibling in a post's translation group without calling the AI translation feature at all and without touching `post_content`/`post_title`/`post_excerpt` — free to run and safe to run repeatedly. It cannot create a missing sibling, since that requires translated content (i.e. an AI call); use Sync or "Translate missing" for that. Shown and runnable only from the primary/source-language post — restricted from the start, since this is meant as the one entry point that fixes every sibling's template in a single pass, not a per-post action — so, unlike Sync, it needs no secondary-language safeguard: it never touches content, so the back-translation content-overwrite risk those guards exist for doesn't apply. New `PostListColumn::run_sync_templates()` (engine) / `ajax_sync_templates()` (AJAX wrapper) / `render_template_sync_button()` (button), and `linguaforge_sync_templates( $post_id, $check_caps = false )` (`ai/ai.php`), matching `linguaforge_sync_translations()`'s `$check_caps` convention. (`ai/includes/Admin/PostListColumn.php`, `ai/ai.php`, `ai/assets/post-list.js`, `ai/assets/admin.css`)

### Fixed
- **Programmatically-created translations (`linguaforge_trigger_translation()` / `linguaforge_queue_translation()`) never received a language-specific FSE template.** This is the entry point every third-party integration uses to create a translated post — Agnosis's `Compat/LinguaForge.php` drives all of its translation creation through it — and it is exactly the one code path that didn't assign a template. `TranslationTrigger::create_translated_post()` unhooks `Sync::handle_save_post()` around `wp_insert_post()` (deliberately, so trid/lang aren't re-derived mid-insert by the normal save flow), but unlike its three siblings — a normal editor save (`handle_save_post()` itself calls `assign_template_if_needed()` whenever no Language-metabox nonce is present, i.e. every programmatic/REST save), the WP-CLI `translate`/`retranslate` commands (`AbstractTranslateCommand::apply_translation()`), and the post-list "Sync" button (`PostListColumn::run_sync()`) — it never called the compensating `assign_template_if_needed()` afterward. The translated post was left with no `_wp_page_template` at all, so WordPress silently fell back to its normal (untranslated) template hierarchy even when a matching `single-{post_type}-{lang}` FSE template already existed. Fixed by adding the same call, placed after the `_lf_trid`/`_lf_lang` meta writes (not immediately after the insert) so `resolve_template_for_lang()`'s front-page-translation detection — which compares the new post's trid against the site's front page trid — reads the correct, already-committed value rather than an empty just-inserted post. (`ai/includes/Features/TranslationTrigger.php`)
- **The "Sync" button and the `ajax_fill_missing` handler could silently strip the language-specific template off an *already-templated, existing* translation when force-refreshing it.** `PostListColumn::update_linked_post()` resets `_wp_page_template` to `'default'` before its `wp_update_post()` call (needed so WP 6.7+'s page-template validation doesn't choke on an FSE slug it doesn't recognise as a registered template), then relied on `handle_save_post()` firing on `wp_after_insert_post` to reassign the correct slug afterward — true for `ajax_retranslate()`, which restores that hook before calling in, but both `PostListColumn::run_sync()` (Sync) and `ajax_fill_missing()` deliberately unhook it for their *entire* batch loop and only restore it after every language in the batch has been processed. An existing sibling refreshed through either of those two paths was therefore left with no template at all — a regression on a previously-correct assignment, not merely a missing one. `update_linked_post()` now takes the target language explicitly and calls `Sync::assign_template_if_needed()` itself after the update succeeds, independent of whichever hook state its three callers (`ajax_retranslate()`, `run_sync()`, `ajax_fill_missing()`) happen to be in. (`ai/includes/Admin/PostListColumn.php`)

### Tests
- **`TranslationTriggerTemplateAssignmentIntegrationTest`** (new) covers the fix: a language-specific template (`single-{lang}`) is assigned and tracked via `_lf_auto_template` on creation; the assigned slug matches `Sync::resolve_template_for_lang()`'s own resolution; no template is written when the target language equals the source language (degenerate case, pinning `resolve_template_for_lang()`'s null-return contract); and — the regression guard for the ordering fix specifically — `_lf_trid` is already persisted and matches the source post's TRID by the time template assignment runs, with the correct template slug still resolved despite depending on it. (`tests/integration/TranslationTriggerTemplateAssignmentIntegrationTest.php` NEW)
- **`PostListColumnIntegrationTest`** gained two regression tests for the `update_linked_post()` fix: Sync force-refreshing an existing, pre-templated sibling reassigns `single-es` rather than leaving it reset to `default` (the exact bug scenario — hooks disabled for Sync's whole batch loop), and `ajax_retranslate()`'s existing-target update path (already safe, since it restores hooks before calling in) still ends up with the correct template after the explicit call.
- **`PostListColumnIntegrationTest`** gained coverage for Template Sync: the button renders only on the primary post (silent for a secondary post, `wp_navigation`, and a post with no assigned language); `ajax_sync_templates()`/`run_sync_templates()` reassign the correct template for an existing sibling with **no `linguaforge_ai_provider` filter registered anywhere in the test** (proves it never reaches the translation feature); the source-language post is never included in its own results and never gets a template assigned; rejects a call made from a secondary-language post, `wp_navigation`, an invalid post ID, and insufficient permissions; and `linguaforge_sync_templates()`'s `$check_caps` default/enforcement, matching `linguaforge_sync_translations()`'s existing test pattern.

---

## [2.6.0] — 2026-07-08

### Added
- **"Sync" — retranslate a post into every other configured language in one click, including the primary/source language.** Previously the post list Lang column offered two directional actions: "Translate missing" (source post → every language that doesn't have a translation yet, never touches existing ones) and "Retranslate" (pick one sibling language, pull it into the *current* post, always refuses to touch the source post — "the source is authoritative, edit it directly"). Neither covers "I edited this German translation and want it pushed out everywhere else, overwriting what's already there" — including back onto the primary post, when that's genuinely what the editor wants (e.g. the German version is now the most current text and should become the new source of truth). The new **Sync** button, rendered on *every* language version of a post via the same `lf_lang_column_retranslate` hook `PostListColumn::render_retranslate_button()` and `render_seo_score_badge()` already use, fans the clicked post's content out to every other active language: a missing sibling is created exactly as "Translate missing" would, an existing sibling is force-refreshed exactly as "Retranslate" would, and — the deliberate behavioural difference from "Retranslate" — the primary/source post is not exempt when it's one of the targets, so a secondary-language Sync click can overwrite it via back-translation. The source-language target (when present) is always processed first so its `_lf_source_updated_at` timestamp is fresh before every other target's outdated-flag comparison runs against it, and the post that triggered the sync is itself marked in-sync afterward so it isn't left showing a spurious outdated indicator against content it was just used to produce. Because one click can now overwrite several posts — including the source — the client shows a `confirm()` dialog before the AJAX call fires; none of the plugin's other one-click list actions do this, but none of them could previously touch the source post either. (`ai/includes/Admin/PostListColumn.php`, `ai/assets/post-list.js`, `ai/assets/admin.css`)
- **WooCommerce safeguard for Sync, plus a settings toggle to lift it.** The primary-language product is WooCommerce's operational source of truth — price, SKU, and stock are always served from it regardless of language (`MetaDelegate`). Syncing a normal post is fine either direction, but syncing a *secondary-language* `product`/`product_variation` would back-translate onto that authoritative primary product, a materially different risk than for an ordinary post. Sync from a secondary-language product is now blocked by default — the button doesn't render (`PostListColumn::render_sync_button()`) and the underlying action rejects the request (`run_sync()`) — while syncing FROM the primary product is unaffected either way. Two escape hatches, checked in order: the new **Settings → Behavior → WooCommerce → "Allow 'Sync' on a translated product to overwrite the primary product"** checkbox (only shown when WooCommerce is active), backed by the `linguaforge_wc_allow_secondary_sync` option, and the `linguaforge_wc_secondary_sync_allowed` filter for a per-request override that doesn't need a persistent site-wide setting. (`ai/includes/Admin/PostListColumn.php`, `ai/includes/Admin/Settings/Tabs/BehaviorTab.php`, `ai/includes/Admin/SettingsPage.php`)
- **General secondary-language safeguard for Sync — every post type the WooCommerce guard above doesn't cover.** The WooCommerce restriction only ever applied to `product`/`product_variation`; every other post type (posts, pages, any other CPT) had no restriction at all — Sync from a secondary-language post of any of those could silently overwrite the primary post the moment the feature shipped. Closed the same day: syncing FROM a secondary-language post of any non-WooCommerce post type is now blocked by default too, via a second, fully independent guard — `PostListColumn::general_secondary_sync_blocked()`, checked separately from `wc_secondary_sync_blocked()` so enabling one setting never implicitly enables the other. New **Settings → Behavior → Sync → "Allow 'Sync' on a translated post to overwrite the primary post"** checkbox (always shown, not gated on WooCommerce), backed by the `linguaforge_allow_secondary_sync` option, and the `linguaforge_secondary_sync_allowed` filter for a per-request override. Syncing FROM the primary post is unaffected either way, for every post type. (`ai/includes/Admin/PostListColumn.php`, `ai/includes/Admin/Settings/Tabs/BehaviorTab.php`, `ai/includes/Admin/SettingsPage.php`)
- **`linguaforge_sync_translations( $post_id, $check_caps = false )` — public API for Sync**, for integrations that want the same "retranslate this post into every other language" behaviour from their own code, not through wp-admin. Refactored the AJAX handler's body into a reusable `PostListColumn::run_sync()` so both the button and this wrapper function share one implementation (including both safeguards above). `$check_caps` defaults to **false**, matching `linguaforge_trigger_translation()` / `linguaforge_trash_translation_group()`'s existing convention of not enforcing `current_user_can()` itself, since a programmatic caller very often has no logged-in WP user at all. (`ai/ai.php`, `ai/includes/Admin/PostListColumn.php`)

### Tests
- **`PostListColumnIntegrationTest`** gained coverage for the new surface: `render_sync_button()` renders on both the source-language post and a secondary post (the key difference from `render_retranslate_button()`, which is silent on the source post), and stays silent for `wp_navigation` posts and for a post with no assigned language. `ajax_sync()` covers creating a missing sibling, force-refreshing an existing one (and clearing its outdated flag), overwriting the primary/source post when triggered from a secondary post while also clearing the outdated flag on the *triggering* post, and the standard `wp_navigation` / invalid-post-ID / insufficient-permissions / provider-failure rejections already exercised for the other two AJAX handlers in this file. Further coverage for both secondary-language safeguards: each is blocked by default for its respective post types, allowed once its own option or filter is enabled, always allowed from the primary-language post regardless of either setting, and — critically — independent of each other (enabling the WooCommerce toggle alone does not lift the general restriction on a WooCommerce product, and vice versa). `linguaforge_sync_translations()` is covered for both `$check_caps` defaults. (`tests/integration/PostListColumnIntegrationTest.php`)

### Docs
- **`docs/translation-workflow.md` §10** now documents Sync — including both secondary-language safeguards and the programmatic `linguaforge_sync_translations()` entry point — alongside the existing Retranslate / WP-CLI bulk-retranslation entries. **`CONTRIBUTING.md`** documents the new options, filters, and Public PHP API table row. (`docs/translation-workflow.md`, `CONTRIBUTING.md`)

---

## [2.5.5] — 2026-07-08

### Fixed
- **Language Switcher — Grid Overlay panel could open off the right edge of the viewport on RTL-language pages.** `positionPanel()` always anchored the fixed `.lsflr-panel` to the trigger's *left* edge and grew rightward, with no awareness of text direction — unlike the classic (non-overlay) dropdown, whose `[dir="rtl"] .lsflr-submenu` CSS rule already anchors to the right and opens leftward for RTL languages (Arabic, Farsi, Urdu, etc.). Because the overlay panel's position is set entirely via inline styles computed in JS, that CSS rule never had a chance to apply to it. The panel now resolves the actual rendered direction via `getComputedStyle(wrap).direction` — which correctly picks up an inherited `dir="rtl"` from any ancestor rather than assuming it's only set on `<html>` — and when RTL, anchors to the trigger's right edge and grows leftward instead, using the same viewport-clamping math already used for the LTR case. (`language-router/includes/class-lsflr-switcher.php`)

---

## [2.5.4] — 2026-07-07

### Added
- **"Trash + Siblings" — cascading trash for translation groups.** Trashing one language version of a translated post previously left every other language version behind with no warning, since posts in a TRID group are independent `WP_Post` rows with no delete-time link between them. New `Translation\TrashCascade` adds two additive entry points to the Posts/Pages/CPT admin list tables, both scoped to Trash only (not restore, not permanent delete — see `lingua-forge-audit/PROPOSAL-trash-cascade-2026-07-07.md` for the full design rationale): a **"Trash + Siblings" row action**, inserted immediately after the stock "Trash" link (`Edit | Quick Edit | Trash | Trash + Siblings | View`) and only shown when the post actually has TRID siblings, and a **"Move to Trash (incl. translations)" bulk action**, registered per public post type since `bulk_actions-edit-{$post_type}` is genuinely per-type (unlike row-action filters). Both call the same `TrashCascade::trash_group()` engine — the bulk path de-duplicates across the whole selection first, so picking both a source post and its own translation in one batch reports 2 trashed, not 4. Neither shows a `confirm()` prompt, matching WP core's own single-post "Trash" link, which also acts immediately since Trash is reversible; WP core's existing bulk-action nonce covers the bulk path, and the row action goes through its own nonce'd `admin-post.php` handler. The engine skips (and reports as skipped, not silently) posts the current user can't delete and the site's static front page / posts page, which core refuses to trash anyway. Two new integration hooks: `linguaforge_trash_cascade_post_ids` (filter the ID set before it's trashed) and `linguaforge_trash_cascade_complete` (action, fires after, receives the trashed/skipped ID lists). (`language-router/includes/translation/class-trash-cascade.php` NEW, `language-router/includes/class-language-router.php`, `language-router/language-router.php`)
- **`linguaforge_trash_translation_group( $post_id, $check_caps = false )` — public API for the cascade above**, for integrations that need the same "trash a whole translation group" behavior from their own code, not through wp-admin. `TrashCascade::trash_group()` gained a `$check_caps` parameter (default `true`, preserving the existing wp-admin row/bulk-action behavior exactly); the public wrapper function defaults it to `false` instead, matching `linguaforge_trigger_translation()` / `linguaforge_queue_translation()`'s existing convention of not enforcing `current_user_can()` themselves — a REST endpoint or CLI command calling in very often has no logged-in WP user at all (an anonymous, token-authenticated request being the common case), so the calling integration is expected to have already authorized the action itself. (`language-router/includes/translation/class-trash-cascade.php`, `language-router/includes/class-language-router.php`, `language-router/language-router.php`)

### Fixed
- **`TrashCascadeIntegrationTest::test_bulk_action_hooks_registered_for_post_and_page` failed on real wp-env** (`has_filter('bulk_actions-edit-post', …)` asserted non-`false`, got `false`) — not a bug in `TrashCascade` itself, but in the test: `register_bulk_action_hooks()` is only ever invoked automatically via `Router::register_admin_hooks()`, gated behind `is_admin()`, which is evaluated once inside the `Router` constructor at `muplugins_loaded` — well before `is_admin()` can ever be true in the WP-CLI/PHPUnit test runner (no real `wp-admin` request is made), so the automatic wiring never happens in that environment, though it does on every real admin page load. The test now calls `register_bulk_action_hooks()` directly, exactly as `register_admin_hooks()` would on a real request, and cleans up the filters it adds afterward so they don't leak into other integration test files sharing the same PHP process. (`tests/integration/TrashCascadeIntegrationTest.php`)

### Tests
- **Closed a pre-existing gap from 2.5.3**: `TranslationBackfill` shipped with zero test coverage. New `TranslationBackfillIntegrationTest` (17 tests) covers the recurring scan (`run()` queues a genuine gap, skips an already-linked translation, never queues the source language against itself), the per-`(post, lang)` failure-cooldown bookkeeping (`record_failure()`/`clear_failure()`, and `run()` skipping a pair mid-cooldown vs. retrying one whose cooldown has elapsed), the `MAX_JOBS_PER_RUN` cap (26 gaps in one run queues exactly 25), the `linguaforge_backfill_post_types` filter, and the schedule/unschedule pair (`maybe_schedule()` is idempotent, `unschedule()` cancels the event). No AI calls or network access in any of it — `run()` only ever calls `TranslationQueue::queue()`, which itself only schedules a `WP-Cron` event or hands off to Action Scheduler; nothing here ever reaches `run_queued()`. Its `is_queued()` test helper checks Action Scheduler first (`as_has_scheduled_action()`) before falling back to `wp_next_scheduled()`, since this project's own wp-env installs WooCommerce (`.wp-env.override.json`) and `TranslationQueue::queue()` prefers Action Scheduler whenever it's present. (`tests/integration/TranslationBackfillIntegrationTest.php` NEW)
- **CONTRIBUTING.md updated for 2.5.3's undocumented hooks/meta**: `linguaforge_backfill_post_types`, the `linguaforge_backfill_missing_translations` cron hook, and the `_lf_translation_failures` post-meta key were all added in 2.5.3 but never added to the project's own documented-hooks list, despite CONTRIBUTING.md requiring it for "any option, hook, constant, class, or DOM element." (`CONTRIBUTING.md`)
- **`TrashCascadeIntegrationTest`** gained 4 more tests (23 total) covering the new `$check_caps` parameter and `linguaforge_trash_translation_group()`: `trash_group()`'s default (`true`) still blocks an anonymous/no-capability caller exactly as before; `check_caps=false` bypasses that block; the public wrapper function defaults to `false`; and passing `true` through the wrapper still enforces it.

---

## [2.5.3] — 2026-07-07

### Added
- **Automatic missing-translation backfill.** Previously, if a queued translation (`TranslationQueue::run_queued()`, dispatched via Action Scheduler or WP-Cron) timed out, errored, or was otherwise lost — a cron tick that never fired, an Action Scheduler action that expired, a provider outage mid-request — the resulting gap was silent: nothing ever revisited it, and an admin only discovered it by noticing a missing language-switcher entry, or by running the `wp linguaforge missing_translations` / `wp linguaforge fill_translations` CLI commands by hand. A new `TranslationBackfill` class runs an hourly cron scan that re-derives the same TRID-group gap those CLI commands compute across every managed post type ('post', 'page', and public CPTs, minus WordPress' own internal types), and re-queues just the missing (post, language) pairs through the existing async pipeline — the same create/update + TRID-link + cache-clear flow a normal save uses. Capped at 25 jobs per run so a large backlog can't flood Action Scheduler or blow a single tick's time budget; any remainder is picked up on the next tick. `TranslationQueue::run_queued()` now records each queued job's outcome (attempt count, timestamp, last error) on the source post's `_lf_translation_failures` meta, success or failure; the scan reads that state so a (post, language) pair that fails 5 times in a row is left alone for 24 hours before one more automatic attempt, instead of being hammered every tick by a structurally broken case (e.g. a revoked API key) — long enough for a fixed key or an ended provider outage to recover on its own. The recurring schedule itself is checked on every `init`, not just plugin activation, mirroring the existing self-heal pattern used for the i18n-overrides directory bootstrap: an activation hook never fires on an SFTP/rsync deploy, and a scheduled event can also be dropped by a wp_cron table clear or a host's cron manager, so `wp_next_scheduled()` is re-checked cheaply on every request instead of being a one-time setup step. The scheduled event is cancelled on plugin deactivation. The manual `missing_translations` / `fill_translations` WP-CLI commands are unchanged and remain available for an immediate, on-demand check rather than waiting for the next hourly tick. (`ai/includes/Features/TranslationBackfill.php` NEW, `ai/includes/Features/TranslationQueue.php`, `ai/ai.php`, `lingua-forge.php`)

---

## [2.5.2] — 2026-07-07

### Fixed
- **Language Switcher could render nothing at all on a "Your latest posts" front page, even with every language correctly configured and other pages switching fine.** Confirmed live on agnosis.art: the homepage (Settings → Reading → "Your latest posts") uses a block theme's `home.html`, which renders a custom block (`agnosis/gallery-overview`) instead of a Query Loop — but WordPress still runs the ordinary `post_type=post` main query behind the scenes for that request, and `WP::register_globals()` unconditionally points the global `$post` at the first row of that query's results *before any template renders*, regardless of whether the active template ever loops over it. `Switcher::get_languages()` computed `$post_id = get_the_ID() ?: null` unconditionally — with no `is_singular()` guard on the general case (only the WooCommerce shop-page override checked it) — so a non-singular archive/front-page request could still resolve a truthy `$post_id` from that stray main-query post. On agnosis.art the only `post`-type row left in the database was WordPress's own default "Hello world!" sample post; it has no translation group (`_lf_trid` empty), so `Router::get_translations()` returned `[]` and `get_languages()` returned `[]` per its existing "singular post with no translations → hide the switcher" rule — except this wasn't a singular post at all, and the site's real content (entirely `agnosis_artwork`/`agnosis_biography`/`agnosis_event` custom post types) was fully translated the whole time. This is **not** WooCommerce-specific and **not** specific to any particular post type — it reproduces with any untranslated `post`-type (or custom-post-type) row that simply happens to be the newest one, on any site using "Your latest posts" as its front page. Fix: `$post_id` is now only taken from `get_the_ID()` when `is_singular()` is actually true (`$post_id = is_singular() ? ( get_the_ID() ?: null ) : null;`), ahead of the existing WooCommerce shop-page override, which is unchanged and still layers on top of it for its own non-singular special case. A non-singular front page now always falls through to the existing, always-populated `array_fill_keys( $this->router->languages(), null )` fallback, exactly as it already does for every other archive/search/category page. (`language-router/includes/class-lsflr-switcher.php`)

### Tests
- **Switcher on a "Your latest posts" front page** — new `SwitcherLatestPostsFrontIntegrationTest` (2 integration tests) reproduces the exact production scenario via `go_to( home_url( '/' ) )` (which exercises the real `WP::register_globals()` mechanism, not a mock): one test with an untranslated `post`-type row present, one with an untranslated arbitrary custom post type, both asserting `Switcher::get_languages()` still returns one entry per active language rather than `[]`. Confirmed green via `composer test:integration`. (`tests/integration/SwitcherLatestPostsFrontIntegrationTest.php` NEW)

---

## [2.5.1] — 2026-07-06

### Fixed
- **The Danger Zone "Uninstall {LANG}" action on Settings → Router appeared to silently do nothing.** The deletion itself ran correctly — `LanguageUninstaller::uninstall()` removed the content and locale files — but `RouterTab::handle_uninstall_language()` redirected afterward to `admin_url('options-general.php') . '?page=' . SettingsPage::PAGE_SLUG`. The Lingua Forge settings page is registered as a **top-level** admin menu page (`add_menu_page()`, served at `wp-admin/admin.php?page=lingua-forge`), not a submenu under Settings, so `options-general.php` doesn't recognise that `page` value and WordPress falls back to rendering the default Settings → General screen — dropping the `lf_lang_uninstalled` flag and every count in it along the way. From the admin's point of view the button did nothing: no success notice, no post/file counts, and the page you land on doesn't even mention Lingua Forge. The same wrong base URL was also used by `handle_save_router_settings()` (Router tab's own Save button) and `handle_flush_permalinks()` (the Flush Permalinks button on the same tab) — both share the identical failure mode, just less noticeable since neither presents a distinct before/after state the way a destructive delete does. All three now redirect via `admin_url('admin.php?page=' . SettingsPage::PAGE_SLUG)`, matching every other settings panel in the codebase (`UninstallSettingsPanel`, `CacheStatsPanel`, `SitemapPanel`, etc.) and restoring the confirmation notices. (`ai/includes/Admin/Settings/Tabs/RouterTab.php`)
- **Uninstalling a language (Settings → Router → Danger Zone) could leave it only partially removed, for three independent reasons.** CPT Block Pattern translations survived indefinitely — unlike templates, template parts, and navigation menus (all regular posts tagged with `_lf_lang` postmeta, and already covered by the uninstall's postmeta query), they're stored entirely inside the `linguaforge_pattern_translations` option, so they were invisible to it. Custom translation files copied into the Maintenance tab's "Loco Translate — Copy to Safe Storage" location were never scanned for removal, even though that directory is designed to hold exactly the kind of file that can make a language "active." And for languages where WordPress's own locale slug isn't the 2-letter code this plugin assumed everywhere (e.g. Yoruba's real slug is the bare 3-letter `yor`, not `yo`), uninstall could report success while the language stayed active forever, because nothing could resolve the assumed 2-letter code back to the real installed locale to remove it. All three are fixed: a new `PatternDiscovery::delete_language()` purges pattern translations and reports the count in the success notice; a new `LanguageUninstaller::collect_override_files()` also scans the Loco safe-storage directory; and a new `Context::lang_from_locale()` replaces the lossy 2-character truncation everywhere it appeared, so any of WordPress's roughly two dozen bare 3-letter-only locale codes (Yoruba's `yor`, Sakha's `sah`, and others) resolves correctly instead of silently colliding with — or becoming — a different language's code. Internal routing, URLs, and postmeta still use WordPress's own locale slug, so no site's existing URLs or stored data change. (`ai/includes/Admin/FseLocalisation/PatternDiscovery.php`, `ai/includes/Admin/Language/LanguageUninstaller.php`, `ai/includes/Admin/Language/UninstallResult.php`, `language-router/includes/class-context.php`, `ai/includes/Admin/Settings/Panels/SystemPanel.php`, `ai/includes/Admin/Settings/Tabs/RouterTab.php`, `ai/includes/Features/Translation.php`)
- **The admin-bar "Preview Language" switcher could show two languages checked at once, and could label the wrong one as current.** Confirmed live with Yoruba added as an active router language: `LocaleDetector::locale_from_lang()`'s fallback map had no entry for it (nor for five other languages this plugin ships its own UI translation for — `hi`, `ur`, `th`, `sw`, `km`, `eu` — an audit turned up while investigating), so an unmapped code silently fell through to the `en_US` default, colliding with English's own locale. `MetaBoxes::add_locale_admin_bar_node()`'s flyout then independently re-derived "is this language active?" per item by comparing locale strings again, so both the colliding language and English satisfied that check and both got a checkmark. Two fixes: the fallback map now has entries for all six previously-missing bundled languages; and the flyout now compares every item against the single `$current_lang` value already resolved for the parent label, so exactly one item can ever be marked active regardless of any future mapping gap. (`language-router/includes/class-locale-detector.php`, `language-router/includes/admin/class-meta-boxes.php`)
- **hreflang tags, og:locale, and browser-language auto-detection didn't understand WordPress's bare 3-letter locale slugs.** `SchemaManager::lang_to_bcp47()` and `SeoManager::lang_to_locale()` could emit a malformed tag such as `yor-YOR` (a 3-letter region subtag isn't valid BCP 47); the "Preview Language" switcher's label could show an unlabelled raw locale code instead of a language name, since PHP's intl/ICU doesn't recognise slugs like `yor` as a locale identifier; and a visitor whose browser correctly sends `Accept-Language: yo` wouldn't auto-match a router language of `yor`. A new `Context::iso_639_1_from_lang()` — a small map, each entry individually verified against the official ISO 639 code list, covering only languages with an unambiguous ISO 639-1 equivalent (`yor`→`yo`, `bel`→`be`, `dzo`→`dz`, `kir`→`ky`, `oci`→`oc`, `snd`→`sd`, `tah`→`ty`, `arg`→`an`) — normalises just for these outbound-facing uses; internal routing, URLs, and postmeta are untouched. (`language-router/includes/class-context.php`, `language-router/includes/class-locale-detector.php`, `language-router/includes/seo/class-schema-manager.php`, `language-router/includes/seo/class-seo-manager.php`)

---

## [2.5.0] — 2026-07-05

### Added
- **Support for "Your latest posts" as the site's front page** (Settings → Reading). Previously only a static front page was supported; translated homepages now also work when the front page shows the latest posts, living at `/es/`, `/fr/`, etc., alongside every other language-prefixed route. Five pieces, all additive to the existing static-front-page path:
  - **Language-scoped post listing.** `QueryFilter::handle_pre_get_posts()` previously returned before applying the `_lf_lang` meta_query whenever `is_front_page()` was true — correct for a static front page (a singular Page lookup with nothing to filter), but wrong for the latest-posts front, where `is_front_page()` is *also* true and the query is a normal posts listing. Every language's posts appeared mixed together on `/{lang}/` until now; the guard is now conditioned on `show_on_front === 'page'`, so a latest-posts front falls through to the same `is_archive()`/`is_home()` scoping as any other archive. (`language-router/includes/rewrite/class-query-filter.php`)
  - **"Blog Home" template scaffolding.** `TemplateDefinitions::get()` gains a hardcoded `home` slot (WordPress's own `home.html` — the template used for the latest-posts listing, whether as the front page or a dedicated "Posts page"), so it can be scaffolded, translated, and link/part-fixed from Settings → Router → FSE scaffold exactly like Page, Single, Search, Archive, and Front Page already were. (`ai/includes/Admin/FseLocalisation/TemplateDefinitions.php`)
  - **Runtime `home-{lang}` template selection.** `Routing\FrontPageQuery` (renamed in spirit, not in class name, since it now covers both cases) swaps in `home-{lang}` whenever `is_home()` is true, mirroring the existing `front-page-{lang}` swap for `is_front_page()`. When both are true (posts-as-front, no separate posts page), `front-page-{lang}` takes priority if it exists — matching WordPress's own front-page-before-home template hierarchy — with `home-{lang}` as the fallback for themes that ship `home.html` but no `front-page.html`. (`language-router/includes/routing/class-front-page-query.php`)
  - **Homepage redirect parity.** `Redirector::handle_init_redirects()` previously no-op'd entirely on the bare homepage (`/`) whenever `page_on_front` was unset. A returning visitor whose language was detected from the `lf_lang` cookie or `Accept-Language` header (rather than the URL, since bare `/` carries no language prefix) is now redirected to the language-prefixed root (`/es/`) when that detected language isn't the site's source language — matching the existing static-front-page redirect behaviour. Skipped in subdomain routing mode, where the request already lands on the correct language subdomain via DNS. (`language-router/includes/routing/class-redirector.php`)
  - **Sitemap homepage entries.** `SitemapManager` built its URL list entirely from posts carrying `_lf_trid`/`_lf_lang` meta — a latest-posts homepage isn't a post, so `/es/`, `/fr/`, … never appeared in the sitemap. A synthetic entry (with hreflang alternates, respecting path vs. subdomain routing mode) is now added per active language whenever `show_on_front === 'posts'`. (`language-router/includes/seo/class-sitemap-manager.php`)
- **Translated posts, pages, and CPTs now get their featured image automatically.** None of the 3 built-in translation-creation paths ever copied `_thumbnail_id` from the source post, so every translation was born with no featured image unless an external integration explicitly supplied one via the `linguaforge_translated_post_meta` filter. All three now copy the source post's `_thumbnail_id` at creation time (skipped when the filter above already supplied one, when the post type doesn't support thumbnails, or for WooCommerce products/variations — `MetaDelegate` already serves `_thumbnail_id` live from the source product, so writing a copy there would be silently shadowed). Gallery images are out of scope: they're embedded directly in post content, which is already translated. (`ai/includes/Features/TranslationTrigger.php`, `ai/includes/CLI/AbstractTranslateCommand.php`, `ai/includes/Admin/PostListColumn.php`)
- **New "Fix Featured Images" bulk-fix tool** for translations created before the above, or whose source's featured image changed afterward and was never re-synced. Appears in the Posts/Pages/CPT admin list toolbar next to the language filter and the existing "Fix Links" button (not inside Lingua Forge's own settings) whenever a secondary-language filter is active. Scans the active language's posts, compares each against its source-language sibling, and lets you copy the source's featured image across individually or all at once — mirrors the "Fix Links" tool's scan/fix/modal architecture. (`language-router/includes/class-lsflr-featured-image-fixer.php`, `language-router/assets/featured-image-fixer.js`, `language-router/assets/featured-image-fixer.css`)
- **Language lists in translate-action UIs are now sorted alphabetically by language code**, instead of following whatever order the database query or language-discovery logic happened to return. Affects the Retranslate "from" dropdown, the AI Translate-to dropdown (block editor sidebar), the Quick Translate popover (admin bar), and the Translations meta box's linked/unlinked language lists. (`ai/includes/Admin/PostListColumn.php`, `ai/includes/Admin/MetaBox.php`, `ai/includes/Admin/AdminToolbar.php`, `language-router/includes/admin/class-meta-boxes.php`)

### Fixed
- **A theme with `home.html` but no `front-page.html` could render the theme's generic index fallback ("Hello world!") instead of real content on every secondary-language homepage** — confirmed live on an Agnosis-family site. `TemplateDefinitions::get()` offered "Front Page" for scaffolding unconditionally, regardless of whether the active theme actually ships a base `front-page.html`; `ScaffoldHandler`'s documented fallback-to-`index` seed then produced a `front-page-{lang}` row with unrelated content once scaffolded (and left un-customised). At runtime, `FrontPageQuery` unconditionally preferred `front-page-{lang}` over `home-{lang}` whenever it existed — mirroring WordPress's real front-page-before-home precedence, which only holds when the theme has a base `front-page.html` in the first place. On a theme without one, WordPress itself never selects `front-page.html` at the source language either, so a lang-specific variant of it should never win. Three coordinated changes: `TemplateDefinitions::get()` now excludes both `front-page` and `home` from the offered scaffold list unless the active theme (or a plugin) actually ships the matching base template; `FrontPageQuery` now checks base-template existence before adding `front-page-{lang}`/`home-{lang}` to its candidate list; `Sync::resolve_template_for_lang()` carries the matching guard for the static-front-page case, falling back to `page-{lang}` when no base `front-page.html` exists. No manual cleanup of already-scaffolded, unused `front-page-{lang}` rows is required — they're simply no longer considered at runtime. (`ai/includes/Admin/FseLocalisation/TemplateDefinitions.php`, `language-router/includes/routing/class-front-page-query.php`, `language-router/includes/translation/class-sync.php`)
- **A translated post of a custom post type with its own rewrite slug (e.g. an "art" CPT at `/art/some-artwork/`) 404'd once language-prefixed** — confirmed live on an Agnosis-family site (`agnosis_artwork`, rewrite slug `art`): `Manager::lang_permalink()` correctly built `/es/art/some-artwork/` for the translation, but `register_rewrite_rules()` only added a language-prefixed inbound rule for that CPT's *archive*, never its single-post permalink. The URL fell through to the generic `pagename` fallback, which treats `art/some-artwork` as a single hierarchical page path — no such page exists, so the request 404'd, even though the un-prefixed source URL (`/art/some-artwork/`) worked fine. A new `add_cpt_single_rewrite_rules()` registers the missing rule for every public, non-hierarchical CPT with a custom rewrite slug (mirroring the existing CPT-archive rule generator); hierarchical CPTs, built-in `post`/`page`, and WooCommerce `product`/`product_variation` are excluded — WooCommerce's own product-permalink handling has not been audited against this change. Existing installs need to **re-save Settings → Permalinks once** (or otherwise trigger a rewrite-rules flush) to pick up the new rule. (`language-router/includes/rewrite/class-manager.php`)
- **The CPT-single fix above could still silently fail to register at all, regardless of any number of permalink flushes** — `register_rewrite_rules()` (and its `add_cpt_archive_rewrite_rules()` / `add_cpt_single_rewrite_rules()` helpers, both of which enumerate CPTs via `get_post_types()`) ran on `init` at the default priority (10), the same priority almost every plugin/theme uses to register its own custom post types. Whether a given CPT was visible to `get_post_types()` at that point depended entirely on unpredictable same-priority `init` callback ordering across plugins — a race Lingua Forge does not control. Confirmed live on the same Agnosis-family site: the "art" CPT's registration callback ran *after* Lingua Forge's, so `get_post_types()` never saw it and no rewrite rule was ever queued for it in that request — no rewrite-rules flush can fix a rule that was never added to begin with. `register_rewrite_rules()` now hooks at `init` priority 20, matching the same fix already applied elsewhere in this codebase (`Columns::register_cpt_column_hooks()`) for the identical class of problem. (`language-router/includes/rewrite/class-manager.php`)
- **The Language Switcher (and anything else calling `get_permalink()`) rendered every custom-post-type link without its language prefix** — confirmed live on the same Agnosis-family site: `get_permalink()` for a CUSTOM post type (e.g. the "art" CPT) never applies the `post_link`/`page_link` filters at all; WordPress's own `get_post_permalink()` applies `post_type_link` instead (`post_link` is documented core-side as firing only for the built-in `post` type, `page_link` only for `page`). `Manager::register_hooks()` only hooked `post_link`/`page_link`, so `lang_permalink()` silently never ran for any CPT post, and the un-prefixed permalink it returned is exactly what the Language Switcher uses to build each language's link. `post_type_link` is now hooked to the same `lang_permalink()` callback. WooCommerce products/variations are excluded from this new hook (filterable via `linguaforge_permalink_excluded_post_types`) — they intentionally stay on a single, language-neutral permalink for every translation, and this fix must not change that established behaviour. (`language-router/includes/rewrite/class-manager.php`)
- **Language Switcher block produced a double-slash URL (`.../fr//`) on any non-singular page whose path is empty once the language prefix is stripped** — in practice, the site's own homepage. `Switcher::build_translated_url()`'s non-singular branches (source-language switch, and path-prefix mode) always appended a literal `/` after `$new_path` regardless of whether `$new_path` was empty; the subdomain-mode branch already guarded this correctly. This was previously unreachable in practice, since a static front page is always `is_singular()` (using the permalink branch instead) — it becomes reachable the moment a site uses "Your latest posts" as the front page (added in this same release), where the homepage is `is_home()`/`is_front_page()` but not singular. The generated link still worked (WordPress's own duplicate-slash redirect resolves it), but at the cost of an extra 301 hop and an unpolished URL in the switcher's `href`. Both affected branches now build the sub-path suffix conditionally, matching the pattern the subdomain branch already used. (`language-router/includes/class-lsflr-switcher.php`)
- **The hreflang alternates and canonical tag both duplicated the language prefix (`/fr/fr/`) on a bare language-root homepage request** (e.g. `/fr/`, no further path segments) — confirmed live on an Agnosis-family site's homepage, in both path and subdomain routing modes. `print_hreflang_tags()` and `print_canonical()` each derive the language-neutral remainder of `REQUEST_URI` by first `trim()`-ing surrounding slashes, then stripping a leading `{lang}/` via regex — but when the entire trimmed path is just the bare lang code, there's no trailing slash left for that regex to anchor on, so the strip silently failed and the lang code was re-prepended downstream, doubling it. The duplicate strip logic (previously copy-pasted identically in both methods) is now a single shared `strip_lang_prefix_from_path()` helper whose regex also matches when the lang code is the entire remaining path. (`language-router/includes/seo/class-hreflang.php`)

## [2.4.2] — 2026-07-04

### Added
- **Language Switcher: "Icon color" block setting.** New `iconColor` attribute, editable via a theme-palette-aware colour picker in the block Inspector (shown only when display mode is "Icon only" or "Icon + language"). Applied as an inline `color` style on the icon wrapper, which the existing `fill: currentColor` CSS already resolves through. Exists for sections whose background is set locally (e.g. a manually dark-styled header) rather than via the theme's global style — the switcher's automatic contrast colour (`--lsflr-color`, sourced from the theme's global `--wp--preset--color--contrast`) tracks the theme's *global* colour pairing, so it can end up matching a locally-dark background instead of standing out against it; this setting lets that be overridden per instance. Value is sanitised server-side (`Switcher::sanitize_icon_color()`) to hex, `rgb()/rgba()`, `hsl()/hsla()`, or `var(--...)` only. (`language-router/includes/class-lsflr-switcher.php`, `language-router/assets/editor-switcher.js`)

### Fixed
- **Language Switcher: Grid Overlay's "Auto" list style could silently override an "Icon only" display, showing the current language as a plain text link instead of the configured icon.** `render_switcher()`'s `auto` overlay mode adds a class that hides the icon trigger and reveals the language panel inline once a `ResizeObserver` decides the container is wide enough (`neededWidth = langCount * 7em`). On any page where secondary languages are configured site-wide but this particular post has no translated siblings yet, `Switcher::get_languages()` correctly returns only one entry (itself), so `langCount` was `1` and `neededWidth` collapsed to ~7em — trivially satisfied at almost any real container width. The icon trigger was hidden nearly every time, replaced by a single, non-functional self-referential text link (e.g. "English"), silently overriding the "Icon only" setting. Confirmed live on an Agnosis-family site: reproduced only with List style = "Grid overlay — auto" (never with "Grid overlay — always", which skips this heuristic entirely and has no such issue). The `ResizeObserver` now only runs when there's at least one other language to switch to. (`language-router/includes/class-lsflr-switcher.php`)

### Changed
- **Grid Overlay's language panel no longer lists the current language.** Previously the panel-grid rendered every entry in `$langs`, including the current language (shown de-emphasised via `.lsflr-panel-current`); the classic dropdown's submenu already excluded it via `$others`. The panel now renders `$others` too, so both list styles show only the languages you can actually switch to. The now-unreachable `.lsflr-panel-current` CSS rule was removed. The auto-expand width heuristic above was updated to size against this same `$others` count. (`language-router/includes/class-lsflr-switcher.php`, `language-router/assets/lsflr.css`)

---

## [2.4.1] — 2026-07-03

### Fixed
- **IndexNow key-file submissions could fail with 403 even though the key file loaded fine in a browser** — the key-file URL (`/<key>.txt`) never matches a real post/page/rewrite rule, so WordPress's own request parsing already calls `status_header(404)` before `template_redirect` fires, unlike `robots.txt`, which has a dedicated `is_robots()` fast-path that bypasses 404 determination entirely. `IndexNowManager::maybe_serve_key_file()` served the correct key body but never overrode that inherited status, so the response went out under an HTTP 404 even though the body was correct. Browsers render 404 bodies fine, so a manual visit to the key-file URL looked correct, but `key_file_reachable()`'s own self-check and real IndexNow crawlers both require an actual 200 and reject a 404 regardless of body content, causing submissions to fail with 403 and the Sitemap panel's "Submit all URLs" button to stay disabled. `send_key_file_headers()` now calls `status_header(200)` — alongside the existing `nocache_headers()` call, which guards against a separate risk: a full-page cache/CDN freezing a stale hit for this URL. Confirmed live against cal-talaia.cat: `curl` with WordPress's own User-Agent returned the key body under an HTTP/2 404 before this fix, HTTP/2 200 after. (`language-router/includes/seo/class-indexnow-manager.php`)
- **Sitemap chunk files (`/lf-sitemap-{N}.xml`) could go undiscovered by Google despite loading fine in a browser** — the same root cause as the IndexNow fix above: a chunk URL never matches a real post/page/rewrite rule, so WordPress already queues a 404 status before `template_redirect` fires. `SitemapManager::send_xml_headers()` (called from `serve_xml()`) served the correct XML body under that inherited 404 status. Google Search Console and any other status-code-aware sitemap consumer reject a 404 regardless of body content, so a chunk's URLs — reached via the `<loc>` entries in the `/lf-sitemap.xml` index — could go unindexed even though a logged-in admin's browser visit looked fine. `send_xml_headers()` now calls `status_header(200)` alongside the existing `nocache_headers()` call (which guards against a full-page cache/CDN freezing a stale response for a chunk URL). Confirmed live against cal-talaia.cat: a chunk request returned an HTTP/2 404 with a correct, PHP-generated body before this fix, HTTP/2 200 after; the sitemap index (`/lf-sitemap.xml`) was unaffected, confirmed returning 200 both before and after. The 24h internal transient cache that avoids the expensive DB regeneration is unaffected; only the HTTP status/caching of the outer response changed. (`language-router/includes/seo/class-sitemap-manager.php`)

---

## [2.4.0] — 2026-06-30

_Programmatic-publisher integration API from the Agnosis compatibility audit
(`lingua-forge-audit/AUDIT-COMPAT-AGNOSIS-2026-06-30.md`). All additive; no change
to existing behaviour._

### Fixed
- **Translated posts are now born with their translated excerpt** — `TranslationTrigger::create_translated_post()` wrote `post_content` but not `post_excerpt`, even though the AI payload already carries `translated_excerpt` (the update path used it; the create path discarded it). A first-time translation therefore had an empty excerpt, so `SeoManager::get_og_description()` fell back from the excerpt to a trimmed slice of `post_content`. The create path now writes `post_excerpt` from `translated_excerpt`, restoring symmetry with the update path. Benefits every integration that relies on the excerpt for the description (e.g. Agnosis artwork). (`ai/includes/Features/TranslationTrigger.php`) — Agnosis audit §2c.

### Added
- **`linguaforge_queue_translation()`** — non-blocking counterpart to
  `linguaforge_trigger_translation()`. Schedules a translation to run off-request
  via Action Scheduler when available, falling back to a single WP-Cron event,
  then runs the same pipeline (and fires `linguaforge_translation_complete`).
  Lets a programmatic publisher translating into N languages avoid making N
  blocking AI calls in one intake request. Duplicate pending jobs for the same
  post + language + params are debounced; fire-and-forget, so failures are logged
  (WP_DEBUG-gated) rather than returned. New worker class
  `LinguaForge\AI\Features\TranslationQueue` (hook `linguaforge_run_queued_translation`,
  registered unconditionally so it fires in bare cron requests). (`ai/ai.php`,
  `ai/includes/Features/TranslationQueue.php`) — Agnosis audit §3a.
- **`linguaforge_translated_post_meta` filter** — lets an integration declare the
  post meta a programmatically-created translated post is born with. Fires inside
  `TranslationTrigger::create_translated_post()` before insertion and is written
  via `wp_insert_post()`'s `meta_input`, so the translated post is complete the
  moment it exists (no window where a reader sees it without its featured image,
  gallery, or other custom meta) — replacing the after-the-fact
  `linguaforge_translation_complete` patch-up pattern. Receives
  `($meta, $source_id, $lang, $source_post_type)`; LF's own `_lf_trid` / `_lf_lang`
  are stripped so the filter cannot clobber group membership. WooCommerce
  operational keys written on a translated product remain delegated by MetaDelegate
  (the write is shadowed). (`ai/includes/Features/TranslationTrigger.php`) —
  Agnosis audit §3b.

---

## [2.3.3] — 2026-06-21

### Fixed
- **LF meta boxes no longer appear on excluded CPTs** — `MetaBoxes::add_language_meta_box()`, `add_template_meta_box()`, and `add_translations_meta_box()` previously passed `null` as the `$screen` argument to `add_meta_box()`, which registers on every post-type edit screen regardless of whether the type is excluded from Lingua Forge routing. All three methods now accept the `string $post_type` argument that `add_meta_boxes` passes, guard against the exclusion list, and pass `$post_type` explicitly to `add_meta_box()`. `add_source_footnotes_meta_box()` — which already looped post types individually — gains the same check inside its loop. (`language-router/includes/admin/class-meta-boxes.php`)

### Added
- **`linguaforge_metabox_excluded_post_types` filter** — a new filterable hook in `MetaBoxes::is_post_type_excluded()` lets third-party plugins extend or override the metabox exclusion list without touching the System panel option (`linguaforge_secondary_query_excluded_types`). The filter receives the array already built from the option, so third-party exclusions layer on top of user intent. Removing a type the user excluded is possible but intentional. Follows the same naming convention as `linguaforge_source_footnotes_excluded_post_types` and `linguaforge_cpt_archive_excluded_post_types`. (`language-router/includes/admin/class-meta-boxes.php`)

### Changed
- **i18n pipeline is now a two-step composer workflow** — `composer make-pot` (previously only generated the POT file) now also runs `msgmerge --update` against all 26 active locale `.po` files, merging new and changed strings while preserving existing translations and flagging modified source strings as fuzzy for review. A new `composer compile-pos` command (step 4–5) compiles translated `.po` files into binary `.mo` files via `msgfmt` and generates `.l10n.php` PHP caches via `wp i18n make-php`. Both scripts live in `dev/bin/` alongside the existing `make-pot.sh`; `gettext` tools (`msgmerge`, `msgfmt`) must be available on PATH (`brew install gettext` on macOS, `apt-get install gettext` on Ubuntu). (`dev/bin/make-pot.sh`, `dev/bin/compile-pos.sh` NEW, `dev/composer.json`)

### Tests
- **MetaBoxes (CPT exclusion)** — new `MetaBoxesIntegrationTest` (4 integration tests) covering the metabox suppression behaviour: a non-excluded public CPT shows all four LF boxes (`lf_lang`, `lf_page_template`, `lf_trans`, `lf_source_footnotes`); a CPT in the `linguaforge_secondary_query_excluded_types` option shows none; a CPT added to the `linguaforge_metabox_excluded_post_types` filter (not in the option) shows none; a CPT removed from the exclusion list by the filter (despite being in the option) shows all boxes. (`tests/integration/MetaBoxesIntegrationTest.php`)

---

## [2.3.2] — 2026-06-15

### Changed
- **AI-subsystem logging is now `WP_DEBUG`-gated** — the operational diagnostics across the AI providers (`AbstractProvider`, `WpAiClient`), `KeyStore`, and the translation features (`Translation`, `JsonEnvelopeTranslator`, `TranslationMemoryTranslator`) previously called `error_log()` unconditionally, so failed/retried AI requests, cryptographic feature gaps, and malformed translation envelopes accumulated in production `debug.log` (and tripped Plugin Check at ~13 separate sites). They now route through a new shared `LinguaForge\AI\Core\Log::debug()` helper that writes only when `WP_DEBUG` and `WP_DEBUG_LOG` are both enabled — the same resolution the language-router's own `debug()` helper already uses. Log lines are byte-identical (each already carried its own `Lingua Forge AI [Component]` prefix); the single Plugin Check `error_log` exception now lives at one call site. (`ai/includes/Core/Log.php` NEW; `AbstractProvider.php`, `WpAiClient.php`, `KeyStore.php`, `Translation.php`, `JsonEnvelopeTranslator.php`, `TranslationMemoryTranslator.php`)
- **IndexNow submission is now asynchronous** — `IndexNowManager::on_post_saved()` (on `wp_after_insert_post`) previously ran a blocking `wp_remote_post()` to `api.indexnow.org` (up to a 15-second timeout) synchronously inside the save, so publishing or updating any translated post stalled the editor save / REST response. The save handler now performs no network I/O: after its existing guards it calls a new private `schedule_submit()`, which queues a single core **WP-Cron** event (`linguaforge_indexnow_submit`) carrying only the `post_id`. The blocking POST now runs in a new public `run_scheduled_submit()` cron callback in a separate request. A `wp_next_scheduled()` guard plus WP's duplicate-event window debounce rapid re-saves of the same post into one submission, and the URL set is re-collected at run time (not stored in the cron arg) so a burst of sibling creation — e.g. "Translate missing" — submits the final translation group once. A 60-second `SUBMIT_DELAY` (`MINUTE_IN_SECONDS`) lets such bursts settle. Action Scheduler was deliberately not used — core WP-Cron keeps the plugin's no-runtime-dependencies guarantee. Manual "Submit all URLs" from the Sitemap panel remains synchronous (explicit admin action). (`language-router/includes/seo/class-indexnow-manager.php`)

### Fixed
- **Canonical and hreflang now carry the pagination suffix on paged singulars** — on page 2+ of a multipage post (`<!--nextpage-->`) or a comment-paginated singular (`/comment-page-N/`), `Hreflang::print_canonical()` and `print_hreflang_tags()` emitted the base permalink, pointing the canonical at page 1 — the page/comment suffix that WP core's `rel_canonical()` used to add was lost when LF took over canonical output. A new `append_singular_pagination()` helper now appends the current request's `page` / `cpage` suffix (comment pagination taking precedence, matching core's `wp_get_canonical_url()`), applied to both the self-canonical and every hreflang alternate so the cluster stays reciprocal. Only affects sites that use `<!--nextpage-->` or paged comments. (`language-router/includes/seo/class-hreflang.php`)
- **Order email language can no longer leak across orders in one request** — `WcOrderLang` stashes the purchase language in a static (`$pending_email_lang`) on each order-status transition (priority 1) for the email locale switch to consume. When a transition's email is admin-only (e.g. failed / cancelled) WooCommerce never calls `woocommerce_email_setup_locale`, so the value was never consumed and could be picked up by a later customer email in the same request — a realistic risk during bulk admin status changes across orders in different languages. A matching `clear_pending_email_lang()` is now hooked at priority 99 on the same status and refund hooks (after WC's priority-10 email triggers), so a seeded language can never outlive the transition that set it. (`ai/includes/Integrations/WooCommerce/WcOrderLang.php`)
- **Sitemap chunk self-heals after independent cache eviction** — `SitemapManager::get_sitemap_chunk_xml()` keyed regeneration only on the index transient, so under a persistent object cache (Redis / Memcached) a chunk transient evicted independently of the index would serve a valid-but-empty `<urlset>` — hiding every URL in that chunk from crawlers until the 24-hour TTL lapsed or a save flushed the cache. It now regenerates the full set once when an in-range chunk transient is missing and re-reads it; an in-range check against the stored chunk count keeps an out-of-range or probe request from triggering a needless rebuild on every hit. (`language-router/includes/seo/class-sitemap-manager.php`)
- **IndexNow key is no longer generated on a front-end request** — `IndexNowManager::maybe_serve_key_file()` runs on every front-end `template_redirect` and previously called `get_key()`, which lazily generates and `update_option()`s the verification key on first read. That meant an anonymous GET could trigger an option write (and two simultaneous cold requests could race to generate competing keys). The serving path now uses a new read-only `read_key()` accessor that never writes; the key is generated only in write-appropriate contexts (the admin Sitemap panel render, the cron/manual submission path, and `key_file_url()`). When no key exists yet there is nothing for a search engine to verify anyway, so the serving path returns early. (`language-router/includes/seo/class-indexnow-manager.php`)

### Tests
- **PHP 8.1 floor compliance** — `tests/unit/WooCommerce/WcPolyfills.php` declared `wp_cache_get(): false`, a PHP-8.2-only standalone literal return type that fataled the unit suite at collection on the project's own declared minimum (PHP 8.1). Changed to `: bool` (the `@return false` docblock keeps the precise static-analysis type). A `php -l` sweep on PHP 8.1 confirms the whole codebase — shipped and tests — is now free of 8.2-only syntax; the full unit suite parses and runs on 8.1. (`tests/unit/WooCommerce/WcPolyfills.php`)
- **IndexNowManager** — new `IndexNowManagerIntegrationTest` (20 integration tests) closing the standing untested-file row for `seo/IndexNowManager.php`: key generation/persistence/rotation and key-file URL; the read-only `read_key()` accessor and the guarantee that a front-end request never generates the key (no write-on-read); `collect_post_urls` (all published TRID siblings; draft sibling excluded); `collect_all_urls` (non-LF posts and excluded post types absent); `submit_urls` payload shape (host / key / keyLocation / urlList) with 200 & 202 → `ok`, non-2xx → `error`, empty list → `error` with no HTTP; `submit_all` empty/populated; the async path — `on_post_saved` schedules a single cron event with no HTTP, debounces duplicate saves, and skips drafts and non-TRID posts; `run_scheduled_submit` collects siblings and submits; and the `maybe_serve_key_file` no-exit guard. Outbound HTTP is stubbed via the `pre_http_request` filter. (`tests/integration/IndexNowManagerIntegrationTest.php`)
- **ModelCatalog** — new `ModelCatalogTest` (8 unit tests) + `ModelCatalogIntegrationTest` (7 integration tests) closing the untested-file row for `Core/ModelCatalog.php`: the curated-list invariants (every entry has tier/label/note; tier ∈ light|quality|max; each provider offers a light and a quality model), `for_provider`/`ids_for_provider`/`all` shape, and `merge_live` (catalog IDs first, live extras appended, catalog-overlap deduped, empty live → curated fallback); and `fetch_from_api` per provider via `pre_http_request` stubs — happy-path filtering (OpenAI chat-only, Gemini generateContent + prefix strip, Anthropic all string IDs), and `[]` on transport error / malformed body / wrong shape / unknown provider. (`tests/unit/ModelCatalogTest.php`, `tests/integration/ModelCatalogIntegrationTest.php`)
- **RepairHandler** — new `RepairHandlerIntegrationTest` (4 integration tests) closing the untested-file row for `Admin/FseLocalisation/RepairHandler.php`: `ajax_repair_fse_metadata()` adds the missing `_lf_lang` meta and `wp_theme` term to a `-{lang}`-suffixed `wp_template`, leaves a template with no language suffix untouched, and does not re-count an already-correct template; plus `extract_lang_suffix()` slug parsing. (`tests/integration/RepairHandlerIntegrationTest.php`)
- **AttributeLabelAdmin** — new `AttributeLabelAdminIntegrationTest` (5 integration tests) closing the untested-file row for `WooCommerce/AttributeLabelAdmin.php`: `save()` persists a per-language label option, removes a translation when its field is emptied, and ignores an empty attribute name; `ajax_translate_all_attr_labels()` batch-translates untranslated labels via a `StubProvider` (writing one option per taxonomy per language) and skips already-translated labels without overwriting them. AJAX captured via the `ob_start` + `WPDieException` pattern; AI stubbed through the `linguaforge_ai_provider` filter. (`tests/integration/WooCommerce/AttributeLabelAdminIntegrationTest.php`)
- **Hreflang (§1.7)** — five integration tests added to `HreflangIntegrationTest` covering `append_singular_pagination()` (in-post `page` under pretty permalinks, `cpage` precedence, plain-permalink query args, unpaginated pass-through) and an end-to-end check that a paged singular's canonical carries the page suffix. (`tests/integration/HreflangIntegrationTest.php`)
- **WcOrderLang (§1.6)** — two integration tests added to `WcOrderLangIntegrationTest`: `clear_pending_email_lang()` discards a seeded-but-unconsumed language; and the priority-1 seed + priority-99 clear are both registered on the status and refund hooks. (`tests/integration/WooCommerce/WcOrderLangIntegrationTest.php`)
- **SitemapManager (chunk eviction)** — two integration tests added to `SitemapManagerIntegrationTest`: an in-range chunk transient evicted while the index survives is regenerated and serves the real URLs (not an empty `<urlset>`) and is repopulated for later requests; an out-of-range chunk index returns a valid empty `<urlset>`. (`tests/integration/SitemapManagerIntegrationTest.php`)
- **Updater (supply-chain hardening)** — new `UpdaterIntegrationTest` (16 integration tests) closing the untested-file row for `includes/class-updater.php`, covering the 2.3.0 download host-pinning and SHA-256 verification so they cannot silently regress: `is_allowed_download_host` accepts exact and subdomain matches and rejects suffix-spoof attempts (`github.com.evil.com`), unrelated hosts, and the empty string; `verify_and_download` passes through a prior filter result, returns `false` for a non-LF package, returns a `linguaforge_updater_host_blocked` `WP_Error` for a disallowed host, returns a `linguaforge_updater_checksum_mismatch` `WP_Error` on a bad hash, skips verification when `sha256` is empty, returns the temp-file path on a matching hash, and propagates a download `WP_Error`; `check_for_update` injects a response entry only when the manifest is newer (no_update otherwise) and bails before WP populates `$checked`; `build_update_object` / `build_no_update_object` field mapping and defaults. The manifest is primed into its transient cache and downloads are stubbed via `pre_http_request`. (`tests/integration/UpdaterIntegrationTest.php`)

---

## [2.3.1] — 2026-06-14

### Fixed
- **GDPR right-to-erasure gap in AI usage statistics** — the `lingua_forge_ai_usage` table stores rows keyed by WordPress `user_id` but had no `wp_privacy_personal_data_erasers` registration, so WP's Tools → Erase Personal Data flow did not reach LF data. `PrivacyIntegration` now registers both an **exporter** (returns all usage rows for the user's email as a `linguaforge-ai-usage` data group: date, feature, provider, model, token counts, request count) and an **eraser** (anonymises rather than deletes — merges the user's rows into the `user_id = 0` anonymous bucket via `UPDATE…JOIN` for existing anon rows and `INSERT IGNORE` for new ones, then deletes the user-identified originals). Aggregate billing data is preserved; the personal link (WP user ID) is removed. `_lf_order_lang` order meta rides WooCommerce's own order anonymiser — no separate eraser needed. (`ai/includes/Core/PrivacyIntegration.php`, `ai/ai.php`)
- **WooCommerce catalogue block pagination broken** — `isInteractivityRequest()` in `frontend-lang.js` only checked URL parameters to detect Interactivity Router fetches, but the Interactivity Router identifies itself via the `X-WP-Interactivity-Router-Nonce` request header, not URL parameters. The URL-only check was wrong: it caused `?lang=<source>` to be injected on catalogue pagination `fetch()` calls on the source-language root (`/`), which prevented the Product Collection block's server render callback from running on page 2+. Fixed by also inspecting `init.headers` (both `Headers` objects and plain key-value objects) for this header, and skipping `?lang=` injection when it is present. XHR path is unchanged — the Interactivity Router uses `fetch` exclusively. (`language-router/assets/frontend-lang.js`)
- **WooCommerce variation stock not routing to source product** — `StockRouter::maybe_route()` and `rewrite_stock_sql()` defaulted to `['product']` while `MetaDelegate` already defaulted to `['product', 'product_variation']`. When WooCommerce reduced `_stock` on a translated `product_variation` (e.g. on purchase), StockRouter passed through without routing, leaving the source variation stock unchanged. Both default arrays aligned to `['product', 'product_variation']`. Exposed by the new `PurchaseFlowIntegrationTest` variation scenario. (`ai/includes/Integrations/WooCommerce/StockRouter.php`)

### Tests
- **PrivacyIntegration** — `PrivacyIntegrationTest` (6 integration): unknown-email early-return (eraser + exporter), known user with no rows, no-collision anonymise (two rows → two user_id=0 rows, originals deleted), collision merge (user row + pre-existing anon row → counts summed into single anon row), export correct WP personal-data format (group_id, item_id, seven data fields). (`tests/integration/PrivacyIntegrationTest.php`)
- **LocalAttributeTranslator** — `LocalAttributeTranslatorTest` (17 unit) + `LocalAttributeTranslatorIntegrationTest` (3 integration, guard conditions). (`tests/unit/WooCommerce/LocalAttributeTranslatorTest.php`, `tests/integration/WooCommerce/LocalAttributeTranslatorIntegrationTest.php`)
- **AdminSaveGuard** — `AdminSaveGuardTest` (12 unit) + `AdminSaveGuardIntegrationTest` (5 integration, SQL conflict logic via Reflection). (`tests/unit/WooCommerce/AdminSaveGuardTest.php`, `tests/integration/WooCommerce/AdminSaveGuardIntegrationTest.php`)
- **FrontPageQuery** — `FrontPageQueryIntegrationTest` (4 integration): page-on-front routing, posts-on-front pass-through, missing-translation fallback, paged front page. (`tests/integration/FrontPageQueryIntegrationTest.php`)
- **PurchaseFlowIntegrationTest** — 4 integration tests: source stock reduced on purchase, translated post has no own stock row, refund restores source stock, variation stock routes to source variation. (`tests/integration/WooCommerce/PurchaseFlowIntegrationTest.php`)

---

## [2.3.0] — 2026-06-13

### Added
- **WordPress 7.0 AI Client provider** — new `WpAiClient` class implements `AIProviderInterface` and delegates to core's `wp_ai_client_prompt()` builder. Credentials are managed through WordPress Settings → Connectors; Lingua Forge stores no keys for this provider. Works alongside the existing Anthropic, OpenAI, and Gemini providers. `GeneralTab` shows a connector-requirement notice and a dedicated Connectors link when this provider is active on WP 7.0+. (`ai/includes/Providers/WpAiClient.php`, `GeneralTab.php`)
- **Sitemap index + chunking** — `SitemapManager` now generates a sitemap-index document at `/lf-sitemap.xml` that splits URLs across 2,000-URL sub-sitemaps (`/lf-sitemap-1.xml`, `/lf-sitemap-2.xml`, …). Handles the 50,000-URL protocol limit automatically for large multilingual sites. Flush Cache and Search Console registration URL are unchanged. (`class-sitemap-manager.php`)
- **BreadcrumbList JSON-LD** — `SchemaManager::build_breadcrumb_schema()` outputs `BreadcrumbList` structured data for singular posts, pages, non-hierarchical CPTs (primary taxonomy chain), and taxonomy archive pages. All URLs are automatically language-prefixed via LF's existing rewrite filters. Controlled by the new `linguaforge_seo_schema_breadcrumb` option (default: on); filterable via `linguaforge_seo_schema_data` with `@type = 'BreadcrumbList'`. (`class-schema-manager.php`)
- **WooCommerce order language capture + transactional email locale** — `WcOrderLang` stores the customer's language code as `_lf_order_lang` order meta at checkout and switches the WooCommerce email locale for order-confirmation, processing, completed, refunded, and customer-note emails so transactional messages arrive in the customer's language. (`ai/includes/Integrations/WooCommerce/WcOrderLang.php`, `WcPageBridge.php`)
- **WooCommerce coupon product-restriction mapping** — `CouponTridMap` hooks `woocommerce_coupon_is_valid_for_product` and `woocommerce_coupon_is_valid_for_cart_item` to remap translated product IDs to their source-language equivalents, so coupon "Products" and "Exclude Products" restrictions honour all language versions of a product. (`ai/includes/Integrations/WooCommerce/CouponTridMap.php`)
- **WooCommerce order line item normalisation** — `OrderItemNormalizer` rewrites `product_id` on checkout line items to the source-language product at `woocommerce_checkout_create_order_line_item` (priority 10). This ensures `wc_update_total_sales_counts()` increments `total_sales` on the source product and that WC Analytics reports one revenue row per product instead of one per language version. Default on; toggleable via Settings → Router → WooCommerce Integration. New `linguaforge_wc_order_item_source_mapping` filter `(bool $normalize, int $product_id, int $source_id, WC_Order_Item_Product $item)` for per-item control. (`ai/includes/Integrations/WooCommerce/OrderItemNormalizer.php`, `RouterTab.php`)
- **WooCommerce shared product review pool** — `ProductReviewRouter` hooks `preprocess_comment` to redirect review submissions to the source-language product and `comments_array` to serve the source-language review pool on translated product pages, keeping review counts and star ratings consistent across language versions. (`ai/includes/Integrations/WooCommerce/ProductReviewRouter.php`)

### Fixed
- **Provider error surfacing** — `AIProviderInterface` gains a `get_last_error(): string` method. `JsonEnvelopeTranslator::translate()` and `ChunkTranslation::run()` now call `$provider->get_last_error()` when `chat()` returns null and display the specific reason (e.g. "No text-generation model is available. Configure an AI provider in Settings → Connectors.") in the toolbar notification instead of the generic "Translation failed. Please try again." (`AIProviderInterface.php`, `JsonEnvelopeTranslator.php`, `ChunkTranslation.php`, `tests/unit/ChunkTranslationTest.php`)
- **`LF_LANG` guard in public query helpers** — `QueryFilter::query()` and `query_fallback()` now open with `$lang = defined( 'LF_LANG' ) ? LF_LANG : $this->router->context->source_language()` to prevent a PHP 8 fatal `Undefined constant "LF_LANG"` when `linguaforge_get_posts()` / `linguaforge_query_fallback()` are called from WP-CLI, cron, or admin contexts where the router's `parse_request` hook never fires. (`language-router/includes/rewrite/class-query-filter.php`)
- **`handle_parse_query()` main-query guard** — missing `if ( ! $q->is_main_query() ) return;` caused the `is_search = true` / `is_home = false` mutation to apply to every `WP_Query` on search pages, breaking secondary widget and block queries that test `$query->is_search()` inside `posts_*` filters. (`language-router/includes/rewrite/class-query-filter.php`)
- **Sitemap and hreflang BCP 47 normalisation** — `hreflang` attribute values in the sitemap `xhtml:link` alternates and in `wp_head` hreflang tags now route through `SchemaManager::lang_to_bcp47()`, ensuring correct BCP 47 casing and regional-code normalisation consistent with the Schema.org JSON-LD output. (`class-sitemap-manager.php`, `class-hreflang.php`)
- **Bundled translations never loaded** — `load_plugin_textdomain( 'lingua-forge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' )` registered on `init` (priority 1); `Domain Path: /languages` header added. Performant-translation `.l10n.php` files now load automatically on WP 6.5+; on earlier versions the `.mo` files are used. On self-hosted installs where files were previously hand-copied to `WP_LANG_DIR/plugins/`, both paths continue to work. (`lingua-forge.php`)
- **WP 7.0 `contentOnly` editing — `missing-translation-notice` block** — `messageText` and `homeLinkText` attributes in `block.json` now carry `"role": "content"`. Without this, WP 7.0's default `contentOnly` mode for unsynced patterns and template parts made the block's text fields unselectable and hidden from List View. (`blocks/missing-translation-notice/block.json`)
- **Self-hosted updater — SHA-256 manifest field** — the update manifest now includes a `sha256` field for the release ZIP; the updater verifies the digest before handing off to WP's upgrader. Host-pinning applied to the manifest endpoint. (`docs/lf-update-manifest.php`, `language-router/includes/class-updater.php`)

### Tests
- `dev/e2e/wc-checkout.spec.js` added — 4 E2E scenarios: DE add-to-cart, DE cart page (WcPageBridge redirect), DE cart contents, DE checkout page (WcPageBridge redirect). Scenarios 5–6 (COD order placement and order-received page) are deferred: WC Blocks Store API returns an empty payment-methods list for virtual-only carts even when COD is enabled, making automated order placement impossible without a physical product + shipping zone.
- `test_missing_api_key_returns_null_with_error` removed from `ProviderChatIntegrationTest`: wp-env injects API keys as PHP constants, which cannot be unset at runtime, causing the test to skip for every provider with a key defined. The missing-key path is covered by the unit suite (KeyStoreTest + per-provider unit tests) and by the E2E Settings → AI ping test. (`tests/integration/ProviderChatIntegrationTest.php`)

---

## [2.2.16] — 2026-06-13

### Added
- **Per-language noindex** — new `_lf_noindex` boolean post meta with a "Noindex" checkbox in the Language meta box. When set, `Hreflang::print_robots()` emits `<meta name="robots" content="noindex,follow">` in `wp_head` (priority 1), keeping that language version out of search results while leaving other language versions unaffected. (`class-hreflang.php`, `class-language-router.php`, `class-meta-boxes.php`, `class-sync.php`)
- **IndexNow protocol** — new `IndexNowManager` class implements the IndexNow protocol (Bing, Yandex, Seznam, and others via the shared `api.indexnow.org` endpoint), replacing the defunct Bing/Yandex sitemap ping endpoints (gone since 2021/22). Generates and persists a 32-char hex verification key; serves `/<key>.txt` via `template_redirect`; auto-submits the updated post's URL plus all TRID translation siblings on `wp_after_insert_post`. The Sitemap panel now shows key status (live reachability check), key-file URL, and a manual "Submit all URLs" action with result notices. (`class-indexnow-manager.php`, `class-language-router.php`, `SitemapPanel.php`, `SettingsPage.php`)

### Fixed
- **WooCommerce Catalogue Block pagination on source language** — `frontend-lang.js` appended `?lang=<source>` to every same-origin fetch, including WC's Interactivity API navigation requests (identified by the `?cst` parameter). On the source language front page (`/`) this extra parameter prevented the WooCommerce Product Collection block's render callback from running, producing an empty server response for page 2+ and requiring a browser reload to see products. Translated-language catalogue blocks were unaffected because their URL prefix (`/es/`) establishes the language before `?lang=` is consulted. A new `isInteractivityRequest()` guard skips `?lang=` injection for any URL that carries `?cst` or a `query-N-page` parameter. (`language-router/assets/frontend-lang.js`)
- **Self-referencing canonical tag** — `Hreflang::remove_core_canonical()` was removing WP's `rel_canonical` with no replacement, leaving pages without any canonical tag. Google's hreflang guidance requires a self-referencing canonical on every language version. New `print_canonical()` method (hooked at `wp_head` priority 1) emits the correct self URL for singular, archive, home, and paginated pages. Output is skipped when a third-party SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) is active. CompatibilityPanel and HreflangPanel copy updated. (`class-hreflang.php`, `CompatibilityPanel.php`, `HreflangPanel.php`)
- **hreflang alternates wrong on paginated archives** — a request for `/es/category/noticias/page/2/` is both `is_paged()` and `is_archive()`. The previous `is_paged()` branch ran first and emitted blog-home-style alternates (`/page/2/`, `/es/page/2/`, …) instead of the category's pagination. The `is_paged()` branch has been removed; `is_archive() || is_home()` is now checked before any paged test. The REQUEST_URI path-rebuild used by the archive branch naturally includes the `/page/N/` segment, so paged archives, paged home, and unpaged archives all resolve correctly through the same branch. Same fix applied to the new `print_canonical()`. (`class-hreflang.php`)
- **Admin handler redirects used wrong page slug** — all 25 form-submit redirects across 13 admin files used `options-general.php?page=lingua-forge`, but the settings page is registered as a top-level `add_menu_page()` entry (correct slug: `admin.php?page=lingua-forge`). On WP 6.7+ / PHP 8.1 this caused `strip_tags(): Passing null to parameter #1` deprecation notices on every settings save because `$title` was never populated for the wrong parent. All occurrences corrected. (13 files under `ai/includes/Admin/`)
- **WooCommerce HPOS and Cart Checkout Blocks compatibility not declared** — WooCommerce listed Lingua Forge as "Incompatible" on the High-Performance Order Storage feature screen (`WooCommerce → Settings → Advanced → Features`) on every HPOS store (default since WC 8.2). LF never touches order storage. `FeaturesUtil::declare_compatibility()` is now called for `custom_order_tables` and `cart_checkout_blocks` on `before_woocommerce_init` (registered at file scope in `ai/ai.php` so it fires before WooCommerce boots at `plugins_loaded` priority 10). (`ai/ai.php`)

---

## [2.2.15] — 2026-06-12

### Fixed
- **WP locale mismatch alert — correct detection using `_lf_lang` content check** — the Router and System settings tabs now display a `notice-error` banner when the WordPress site language maps to a language code with no associated content (no posts tagged `_lf_lang` with that code) and it is not the primary language. Language pack presence is explicitly not used as a proxy — packs can be installed for admin UI use without any content in that language. `Context::languages()` continues to include `get_locale()` unconditionally so that the WP site language is always routable when it happens to be a real secondary content language (e.g. WP=en_US on a site that has English content alongside CA and ES). The alert fires only when there are genuinely zero `_lf_lang` posts for the WP locale code, which is the actual failure condition that causes WooCommerce to return zero products. (`language-router/includes/class-context.php`, `RouterTab.php`, `SystemPanel.php`)
- **Plugin Check `UnescapedDBParameter` false positive in `RepairHandler`** — `PluginCheck.Security.DirectDB.UnescapedDBParameter` was triggered on `$where_sql` in `get_lf_template_posts()`. The variable contains only literal `%s` placeholders built by `array_fill()`; all user values are bound via the spread array in `$wpdb->prepare()`. The existing `phpcs:ignore` comment was extended to include the Plugin Check rule with a safety explanation. (`ai/includes/Admin/FseLocalisation/RepairHandler.php`)
- **Theme template parts (header, footer, …) load source-language version on archive pages** — Theme-provided templates (WooCommerce shop archive, product_cat/tag/brand archives, 404, etc.) hardcode the base template-part slug (`{"slug":"footer"}`) in their block markup. LF-assigned per-post templates already reference `footer-{lang}` directly and were unaffected. A new `get_block_template` filter in `Redirector::redirect_template_part_to_lang` intercepts every template-part lookup: when `LF_LANG` is set and a `{slug}-{lang}` variant exists (DB or theme file), it is returned instead of the base part. The slug-suffix guard (`footer-es` already ends with `-es`) prevents infinite recursion on the recursive lookup. (`class-redirector.php`)
- **WooCommerce taxonomy archive title shows source-language taxonomy noun on translated pages** — `woocommerce_page_title()` builds the archive title as `sprintf(__('Products by %s', 'woocommerce'), $tax->labels->singular_name)`. The format string uses the switched locale correctly (fires at render time), but `$tax->labels->singular_name` is frozen in `$wp_taxonomies` from taxonomy registration time. If WooCommerce's `plugins_loaded` callback fires before LF's `switch_to_locale()` call, the singular noun (e.g. "categoria") stays in the source locale. A new `woocommerce_page_title` filter in `WcPageBridge::fix_taxonomy_archive_title` re-derives the noun with a fresh gettext call in the switched locale — matching the exact string WooCommerce uses at registration — so the complete title appears in the target language. Built-in WooCommerce taxonomies (`product_cat`, `product_tag`, `product_brand`, etc.) are handled explicitly; `pa_*` attribute taxonomies use the AttributeLabelAdmin per-language option when available. (`WcPageBridge.php`)
- **WooCommerce catalogue blocks on normal pages show no products on translated pages** — `CatalogQuery::apply_language_filter_to_secondary_query` was injecting only `_lf_lang` into secondary product queries. When a block (Product Collection, HandpickedProducts, etc.) also carries a `tax_query` for category/tag filtering, the SQL JOIN on `wp_term_relationships` returns zero rows for translated products (they have no entries there — `TaxonomyDelegate` virtualises taxonomy at the PHP layer only). The secondary-query handler now applies the same three-phase trid-lookup used by `WcPageBridge::inject_taxonomy_archive_lang` for archive pages: (1) fetch source-language product IDs that satisfy the original `tax_query` using `suppress_filters=true` + explicit `_lf_lang=$source_lang` to prevent recursion; (2) collect their `_lf_trid` values; (3) replace the `tax_query` with `_lf_trid IN ($trids)` + `_lf_lang` in `meta_query`. A Phase 3a pre-check verifies at least one translated product with those trids exists before committing; if none do, the query falls back to a plain `_lf_lang` filter rather than returning zero results. (`CatalogQuery.php`)
- **WooCommerce "Handpicked Products" and hand-picked product-collection blocks show no products on translated pages** — Both the `woocommerce/handpicked-products` block and the `woocommerce/product-collection` "hand-picked" variation store source-language post IDs in `post__in`. `CatalogQuery` now maps each source ID to its translated sibling via `Router::get_translations()` + `Router::get_lang()` before applying the language filter; IDs with no translated sibling resolve to `[-1]` so the collection returns empty rather than leaking source-language products. (`CatalogQuery.php`)
- **Admin Pages list does not show "— Front Page" / "— Posts Page" labels on translated pages** — WordPress core adds these state labels only to the pages configured in Settings → Reading. A new `display_post_states` hook at priority 20 in `Columns::add_translated_core_page_states()` calls `get_post_states()` on the source page (cached per request) and copies the matching label to every translated sibling, so editors can identify the front page and posts page in every language. Labels are read from WordPress core, not hardcoded, so they honour the admin locale. (`class-columns.php`)

### Improved
- **WP locale mismatch alert in Router tab and System tab** — when the WordPress site language (e.g. `en_US`) is not among the active Lingua Forge content languages, a prominent `notice-error` banner appears at the top of Settings → Router and Settings → System. The alert names the mismatched WP locale and all active content languages and links directly to Settings → General → Site Language. The System tab environment table also marks the "WP instance language" row with a ✗ / "not a content language — see notice above" label in place of the normal ✓. (`ai/includes/Admin/Settings/Tabs/RouterTab.php`, `ai/includes/Admin/Settings/Panels/SystemPanel.php`)
- **Contextual help tabs comprehensively updated** — Settings screen help (`SettingsHelp.php`) fully revised to match the current tab layout: (1) API Keys and Models merged into a single **AI Provider** tab with a Model Tiers subsection; (2) **Translation** tab renamed to **Behavior**; (3) **Router** section expanded with a "WP site language requirement" subsection explaining the constraint, supported/unsupported examples, and impact on WooCommerce; (4) new **AI Usage** tab documenting the token-usage table, date-range filter, daily quota, API Response Cache, and Translation Memory; (5) new **System** tab documenting all eight SystemPanel sections and inviting users to open GitHub issues; (6) **Maintenance** tab corrected — cache and Translation Memory paragraphs removed (they moved to AI Usage), Uninstall Behaviour entry added; (7) all tab entries reordered to match the settings tab bar: Overview → AI Provider → Behavior → Router → Glossary → SEO → SEO Scores → AI Usage → Maintenance → System. (`ai/includes/Admin/SettingsHelp.php`)
- **README: WP site language documentation corrected** — the "WP site language vs. primary content language" section in README.md previously implied that running WP with `en_US` and content in Catalan + Spanish was a valid configuration. The section now documents the requirement (WP site language must be one of the active content languages), lists seven concrete failure modes, and provides a supported / unsupported configuration table. A matching "Known Issues" entry in the Troubleshooting section names the root cause and states that no workaround exists short of changing the site language. (`README.md`)

---

## [2.2.14] — 2026-06-11

### Added
- **WooCommerce local attribute translation** — `LocalAttributeTranslator` hooks `linguaforge_translation_complete` (priority 20, after VariationSync) to translate local (non-taxonomy, `is_taxonomy=0`) product attributes in two passes. Component A translates the `_product_attributes` postmeta array: attribute `name` labels and all pipe-separated `value` options are batched into a single AI call per target language and written back as the translated product's own `_product_attributes`, bypassing `MetaDelegate` delegation for those fields. Component B rewrites `attribute_{key}` meta on every translated variation child so that WooCommerce's `find_matching_product_variation()` can match on the translated value string. Empty ("Any") variation slots are preserved. (`ai/includes/Integrations/WooCommerce/LocalAttributeTranslator.php`, `Bootstrap.php`)
- **WooCommerce attribute label translations** — per-language label fields added to the Product Attributes edit and add forms. Translations are stored in `wp_options` under `linguaforge_attr_labels_{taxonomy}` and applied on the frontend via the `woocommerce_attribute_label` filter, so labels like "Color" and "Size" appear translated without any companion plugin. (`ai/includes/Integrations/WooCommerce/AttributeLabelAdmin.php`, `TermNameFilter.php`)
- **Batch AI translate for attribute labels** — "Translate all labels (AI)" button on the Product Attributes page translates all untranslated attribute labels in a single AI call per target language. Skips labels that already have a manual translation, mirroring the skip-existing semantics of the term-name batch button. (`AttributeLabelAdmin.php`)
- **Batch AI translate for WC taxonomy term names** — "Translate all terms (AI)" button on the term admin screen (Products → Categories, Tags, Attributes, and any `pa_*` taxonomy) translates all untranslated term names in a single AI call per language. Already-translated terms are skipped by default; a "Force retranslate" link overrides the skip. (`ai/includes/Integrations/WooCommerce/TermNameAdmin.php`)
- **Front-page language routing** — new `FrontPageQuery` class handles language-prefixed front-page URLs (`/{lang}/front-page/`) and auto-assigns `front-page-{lang}` FSE templates when a post carries the front-page flag for a given language. The Translation meta box now includes `front-page` in its base-template list for front-page posts. (`includes/Routing/FrontPageQuery.php`, `class-language-router.php`, `ai/includes/Admin/TranslationMetaBox.php`)

### Fixed
- **WC SKU duplicate error on translated product saves** — WooCommerce raised a "Duplicate SKU" notice on translated products that intentionally share the source SKU. `AdminSaveGuard` now suppresses the error when the post has `_lf_source` meta (i.e. is a Lingua Forge translation), while leaving real SKU conflicts on non-translated products untouched. (`ai/includes/Integrations/WooCommerce/AdminSaveGuard.php`)
- **`invalid_page_template` on WC product retranslation** — WordPress rejected the Lingua Forge FSE template slug as invalid on retranslation because template validation ran before WooCommerce set the post type. Template assignment is now deferred to fire after the post type is fully registered.
- **`linguaforge_translation_complete` not firing from PostListColumn** — the action was only fired from the post-editor save path. The Lang-column "Retranslate" and "Translate missing" buttons in the admin post list now also fire it, so `TermNameTranslator` and `LocalAttributeTranslator` run for those paths too. (`ai/includes/Admin/PostListColumn.php`)
- **WC add-to-cart AJAX notice in wrong language** — WooCommerce's AJAX add-to-cart handler served notices in the source language regardless of the visitor's active language. Lingua Forge now switches locale for the duration of that handler.

### Improved
- **TermNameAdmin batch: force retranslate + skipped count** — the batch-translate button reports how many terms were skipped, with a "Force retranslate" link to override them. A `force` POST flag triggers a full retranslation pass that overwrites existing entries. Status messages updated: "No terms found", "N already translated — force retranslate?", and "X translated, Y skipped". (`TermNameAdmin.php`)
- **TermNameTranslator scalable token budget** — `translate_term_names()` accepts an optional `$max_tokens` parameter (default 256). The batch-translate handler passes `max(512, count($pending) * 20)` so large taxonomies (e.g. 50+ product tags) no longer produce truncated JSON and silent translation failures. (`TermNameTranslator.php`)
- **AdminSaveGuard PHPCS/PluginCheck compliance** — `defined('ABSPATH') || exit;` moved before line 50 (Plugin Check requirement); `phpcs:disable`/`phpcs:enable` blocks wrap the multi-line `$wpdb->prepare()` call; `phpcs:ignore WordPress.WP.I18n.TextDomainMismatch` added where the `woocommerce` text domain is used intentionally for error-string comparison.

---

## [2.2.13] — 2026-06-10

### Fixed
- **WooCommerce shop pagination (and translated page pagination)** — language-prefixed paginated URLs such as `/es/tienda/page/2/` were matched by the generic fallback rewrite rule as `pagename=tienda/page/2`. That compound slug failed the slug-equality check in `WcPageBridge::inject_shop_post_type()`, so the query was never converted to a product archive and the page rendered empty. A new `{lang}/([^/]+)/page/([0-9]+)` rewrite rule, inserted before the generic fallback, splits the URL correctly into `pagename=tienda&paged=2`. **Flush permalinks after upgrading.** (`class-manager.php`)
- **"Save Settings" button missing on AI Provider tab** — `settings-tabs.js` was checking for the stale tab slugs `general` and `api-keys` (removed in 2.2.11) when deciding whether to show the submit button, so the button was always hidden on the AI Provider tab. Updated `FORM_TABS` to `['ai-provider', 'limits', 'behavior']` and corrected the default-tab fallback from `'general'` to `'ai-provider'`. (`settings-tabs.js`)

### Updated
- **Gemini default models** — default Light tier model changed from the retired `gemini-2.0-flash` to `gemini-2.5-flash-lite`; default Quality tier changed from the retired `gemini-1.5-pro` to `gemini-2.5-flash`. The models `gemini-2.0-flash-lite` and `gemini-2.0-flash` have been removed from the model catalog. (`Config.php`, `ModelCatalog.php`)

---

## [2.2.12] — 2026-06-10

### Improved
- **Precise provider error messages on test connection** — the "Test connection" button now surfaces the specific failure reason instead of the generic "check the error log" message. Failures are mapped to actionable labels: invalid API key (HTTP 401), no credits remaining (HTTP 402 or OpenAI `insufficient_quota`), access forbidden (403), rate limited (429), service unavailable (5xx), or a network-layer error with the underlying detail. (`AbstractProvider.php`, `OpenAI.php`, `ApiKeysTab.php`)
- **Provider console links** — both the AI Provider tab and the AI Usage tab now include direct links (open in new tab) to Anthropic Console, OpenAI Platform, and Google AI Studio for quick access to account, billing, and usage dashboards. (`GeneralTab.php`, `UsageStatsPanel.php`)
- **Batch analysis results persisted across page loads** — each language card in Settings → SEO → Batch Analysis now shows the last run's avg score, analyzed/total count, and ok/warn/fail tally on every page load, not only immediately after running a fresh batch. (`SeoAnalysisPanel.php`)
- **Updated plugin language translations** — `.pot` file and all bundled `.po`/`.mo` translation files updated to cover strings introduced in 2.2.11 and 2.2.12.

---

## [2.2.11] — 2026-06-10

### Added
- **Model catalog with autocomplete** — Settings → General → Models inputs now offer browser autocomplete suggestions populated from a curated `ModelCatalog` class covering Anthropic, OpenAI, and Gemini. Light and mid-tier Quality models are recommended for all translation and content-generation tasks; a collapsible reference table below the Models table lists every catalogued model with its tier and a one-line note. (`ai/includes/Core/ModelCatalog.php`, `GeneralTab.php`)
- **Live model list on test connection** — the "Test connection" button in Settings → API Keys now also queries the provider's models endpoint after a successful ping (`/v1/models` for Anthropic and OpenAI, `/v1beta/models` for Gemini). The merged result (catalog + newly-released models) is cached as a 24-hour transient and returned in the AJAX response; `test-connection.js` updates the datalist for that provider immediately so new models appear as autocomplete suggestions without a page reload. (`ApiKeysTab.php`, `test-connection.js`)
- **"General" and "API Keys" tabs merged into "AI Provider"** — provider selection, model overrides, API key management, and test-connection are now in one place. New `AiProviderTab` delegates to the existing tab classes; `GeneralTab` and `ApiKeysTab` are retained as implementation detail. (`AiProviderTab.php`, `SettingsPage.php`)

---

## [2.2.10] — 2026-06-10

### Improved
- **Apply logic for full-page translation and content generation** — after staging content via `editPost()`, the editor canvas is now also updated explicitly via `resetBlocks(wp.blocks.parse(content))`, ensuring the visual editor reflects the new content immediately across all three Apply paths: translation diff modal, content-gen modal, and inline overlay. (`admin-diff-modal.js`, `admin-content-gen-modal.js`, `admin.js`)

---

## [2.2.9] — 2026-06-09

### Improved
- System tab → AI Configuration now lists all configured providers (Anthropic, OpenAI, Gemini) with individual key status; active provider marked with an "(active)" badge.
- System tab → _lf_lang Coverage separates routable post types (need attention) from routing-excluded post types (shown muted). Repair action carries a danger warning and skips excluded types.
- System tab → Environment now shows "WP instance language" (WordPress locale) as a distinct row above "Primary content language".

### Added
- Per-row "Exclude from routing" button in _lf_lang Coverage — adds the post type to the Router exclusion option without leaving the System tab. Reversible via Settings → Router → Query Filter Exclusions.

### Fixed
- System tab → PHP `max_execution_time = 0` was misleadingly labelled "Unlimited"; now shown as "No PHP limit (server/FPM timeout still applies)".

---

## [2.2.8] — 2026-06-09

### Improved

- **SEO heading analysis accepts H3 as valid subheading structure** — when no H2 is found but H3 headings are present (e.g. from Accordion or Details blocks, which default to H3), the heading metric now scores as `ok` instead of `warn`. The message notes the H3 count explicitly.
- **SEO heading analysis: short content exempt from subheading warning** — pages with 3 paragraphs or fewer are never penalised for missing H2/H3 structure. Short content does not benefit from forced heading hierarchy; the metric scores `ok` and the message states the paragraph count.

### Fixed

- **SEO title length threshold** — the minimum-length check was `> 10` characters (strictly greater than); corrected to `≥ 10` (at least 10). A 10-character title now scores as passing.

### Added

- **WP Admin contextual help tab: SEO Scores** — new "SEO Scores" entry in the Settings → Lingua Forge help panel. Covers: score computation (ok/warn/fail point mapping, 10-point reading-time base, colour thresholds), all three content profiles with exact metric weights and thresholds, heading structure rules (H3 acceptance, short-content exemption, H2-as-H1 option), and score history badges.

---

## [2.2.7] — 2026-06-09

### Fixed

- **Contact Form 7 forms (and similar third-party shortcodes) broken on the frontend** — CF7 resolves non-numeric shortcode IDs (e.g. `id='b657a7a'`) via `get_posts()` against `post_type=wpcf7_contact_form`. `QueryFilter::handle_secondary_pre_get_posts()` was injecting `_lf_lang = LF_LANG` on that query; CF7 form posts carry no `_lf_lang` meta, so the query returned zero results and the form rendered empty. `wpcf7_contact_form` is now excluded from secondary-query language filtering automatically. Admins can exclude additional post types via Settings → Router → **Excluded post types** (comma/newline-separated slugs); the list is stored in `linguaforge_secondary_query_excluded_types` and applied unconditionally for all visitors.

---

## [2.2.6] — 2026-06-09

### Fixed

- **Block editor link control limited to source-language pages** — When inserting a link inside a non-source-language template part (e.g. `footer-it`), the link popup search returned only source-language pages. Root cause: `QueryFilter::handle_secondary_pre_get_posts()` injected `_lf_lang = LF_LANG` on the REST API search query (`wp/v2/search`) fired by the link control. REST requests carry no language prefix, so `LF_LANG` resolved to the source language (the site default) and silently excluded all other-language pages from results. Fix: `handle_secondary_pre_get_posts()` now early-returns for all REST API requests (`defined('REST_REQUEST') && REST_REQUEST`). The method's scope is public-frontend secondary queries only; REST endpoints that need explicit language scoping use the `lf_lang` query param registered via `filter_pages_by_lf_lang_rest()`.

---

## [2.2.5] — 2026-06-08

### Added

- **SEO score history badge in the Lang column** — running analysis in Settings → SEO → Analysis now persists the rule-based score to `_lf_seo_score_history` post meta (newest-first, max 2 entries). The Lang column reads this meta and renders a compact colour-coded `SEO N` badge for any post that has been analysed (green ≥80, amber ≥50, red <50). When two score entries are stored a delta indicator (↑N / ↓N) is shown alongside, making before/after improvements visible at a glance without opening the Analysis panel. `SeoAnalysisPanel::save_score_history()` / `get_score_history()` added as public static helpers. Resolves §10.2 point 3.

- **SEO Analysis: Batch Analysis grid** — new section above the per-post analysis in Settings → SEO → Analysis. A responsive card grid (one card per active language) shows the post count, last run time, and — after running — the average score (colour-coded), posts analysed, and an ok/warn/fail distribution. An "Analyse" button on each card triggers a batch run for that language; "Analyse all languages" runs them sequentially. Batch mode uses content-only heading extraction (no per-post HTTP request) to keep processing time predictable. Results are persisted via `save_score_history()` and immediately visible as Lang column badges. Resolves §10.2 point 2.

- **Settings → System tab** — new read-only diagnostic tab (10th tab, slug `system`) with seven sections: Environment (LF/WP/PHP versions, active theme + FSE flag, routing mode, active languages); Permalink structure compatibility check with a green/red indicator and a link to Settings → Permalinks on failure; Active SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress detection, same as CompatibilityPanel); WooCommerce (WC version, `WcPageBridge` active status, per-language translation coverage for shop/cart/checkout/my-account pages); `_lf_lang` coverage (per-post-type count of published posts missing `_lf_lang`, with a one-click repair button that assigns the source language to all affected posts via AJAX); Rewrite rules (collapsible dump of all LF-owned entries in `$wp_rewrite->extra_rules_top`); Debug copy (formatted textarea + copy-to-clipboard button for pasting into bug reports). `SystemTab` + `SystemPanel` + `ajax_repair_lf_lang` AJAX handler. Resolves §9.2 and §9.3.

- **`_lf_page_menu_exclude` post meta — hide pages from every language's navigation** — New boolean meta flag (`_lf_page_menu_exclude = '1'`) that removes a page from every language's `core/page-list` block inside `core/navigation`, regardless of that page's own `_lf_lang` value. Exposed as a checkbox in the Language meta box (post editor) and in Quick Edit. `filter_page_list_frontend()` checks this flag for every page in the `get_pages()` result and excludes matching IDs before the language filter runs. Affects only `core/page-list` blocks inside `core/navigation`; classic menus (`wp_nav_menu`) render from stored `nav_menu_item` posts and are unaffected. The auto-add mechanism (which populates navigations for new translations) returns early before filtering when this meta is set. Filterable via `linguaforge_page_menu_excluded_page_ids` for programmatic extension (e.g. always hiding the privacy-policy page).

### Changed

- **SEO Analysis: Batch results replaced with a "Multilingual SEO overview" parity view** — The previous "Posts needing attention" table (score < 70 only, single flat list) is replaced by a tabbed overview that shows every analyzed post grouped by language. Each tab renders a sortable table with: SEO score (colour-coded green ≥80 / amber ≥50 / red <50), the post title as a direct edit link where the user has edit permission, a Source title column showing the source-language equivalent for cross-language comparison, post type, and SEO profile. A parity hint below the section heading explains that a low score reflects structural limits and is a signal rather than a mandate. Per-tab scrolling kicks in above 12 rows. WooCommerce system pages (Shop, Cart, Checkout, My Account, Terms) and all their language translations are silently excluded from batch analysis; an inline note in the section description explains the exclusion when WooCommerce is active. The batch card stats show a "(N skipped)" note when WC system pages are excluded. Running a single language card shows only that language's tab; "Analyse all languages" populates all tabs. Resolves §10.2 point 1.

- **SEO Analysis: single-post result shows an informational notice for WooCommerce system pages** — Analysing the Shop, Cart, Checkout, My Account, or Terms page now renders a blue info banner in the result panel explaining that the page's content is WooCommerce-managed and that structural signals only are scored.

- **Router Tab Templates section replaced with a responsive card grid** — The Templates table previously rendered one column per template type, overflowing horizontally beyond the admin viewport when a theme registers 10+ templates. Replaced with a `CSS grid` (`auto-fill / minmax(155px, 1fr)`) of template cards, each containing the template name, status indicator, and action buttons. The card layout wraps naturally at any template count and fills available horizontal space. Bulk action buttons (Create missing / Translate all / Fix all parts / Fix all links) moved to a toolbar above the grid. `fse-scaffold.js`, `fse-translate.js`, `fse-link-fixer.js`, and `fse-part-fixer.js` updated to traverse the new `div.lf-tpl-row` wrapper instead of `tr.lf-tpl-row`.

### Fixed

- **Template scaffold action buttons not appearing without a page reload** — After clicking "Create" for an FSE template or template part, the JS success callback replaced the cell with a bare ✓ indicator. The Translate / Fix Links / Fix Parts (template) and Translate / Fix Links / Fix Nav (part) buttons were only rendered server-side at page load, so they were absent until a manual reload. Fix: `ScaffoldHandler::ajax_scaffold_template()` and `ajax_scaffold_template_part()` now include a `buttons_html` field in the success response (PHP-rendered fragment matching what the Router Tab renders at page load for existing items). `fse-scaffold.js` injects this fragment into the cell on success; falls back to the bare ✓ if the field is absent for any reason.

- **Navigation language filter returning empty menus on WooCommerce product pages** — On language-neutral product URLs (e.g. `/product/camisa/`) `LF_LANG` is always the source language because there is no language prefix in the path. WordPress 6.3+ changed `get_pages()` to use `WP_Query` internally, which means `QueryFilter::handle_secondary_pre_get_posts()` fires for `get_pages()` calls made during navigation block rendering and injects `_lf_lang = LF_LANG = 'en'`. This filtered translated pages out at SQL level before `filter_page_list_frontend()` could see them, so the language filter found zero translated pages and fell back to source-language pages — even when translated nav pages exist. Fix: `handle_secondary_pre_get_posts()` now skips `page` post-type queries when `pending_page_list_lang !== null`, i.e. when the navigation arm has already fired. `filter_page_list_frontend()` handles language scoping for those `get_pages()` calls via its existing `pending_page_list_lang` mechanism. A source-language fallback was also added to `filter_page_list_frontend()` so navigation is never left empty when translated nav pages genuinely do not exist yet.

- **Home link and logo/site-title link not localised on WooCommerce product pages** — `Redirector::lang_home_url()`, which drives `fix_site_logo_link()`, `fix_site_title_link()`, and the WooCommerce breadcrumb home URL, used `LF_LANG` directly. On language-neutral product URLs `LF_LANG` is always the source language, so all three links resolved to the source-language home (e.g. `/` instead of `/es/`) even when the product being viewed was a Spanish translation. Fix: `lang_home_url()` now detects the translated-product context by checking `is_singular()` and reading `_lf_lang` from the queried post when `LF_LANG === source`; it uses the post's language for the home URL lookup in that case.

- **`core/home-link` navigation item coverage extended** — The `core/home-link` block (a direct `home_url()` link, commonly used as a standalone nav item in FSE themes) had no Lingua Forge handler. On normal prefixed URLs this was not an issue — `LF_LANG` is set correctly and `lang_home_url()` would resolve to the right URL — but on language-neutral URLs (e.g. WooCommerce product pages) the block would render the source-language root. Added `Redirector::fix_home_link()` (hooked on `render_block`, priority 20) using the same `lang_home_url()` helper as `fix_site_logo_link` and `fix_site_title_link`, giving `core/home-link` the same full coverage those blocks already had, including the translated-product-page context introduced in this release.

---

## [2.2.4] — 2026-06-07

### Fixed

- **WooCommerce My Account sub-endpoint URLs returning 404** — Visiting `/es/mi-cuenta/orders/` (or any WC endpoint under a language-prefixed My Account URL) produced a 404. Root cause: WC's endpoint rewrite rule (`(.?.+?)/orders(/(.*))?/?$`) sits in the main rules array and intercepts the URL before LF's generic fallback, causing WP to call `get_page_by_path('es/mi-cuenta')`, fail, clear all query vars, and set `error=404` — all before the `request` filter fires. Fix: `WcPageBridge::fix_myaccount_endpoint_request()` (hooked on `request`) parses `$_SERVER['REQUEST_URI']` directly, verifies the page slug belongs to the My Account page (source or any LF translation via `_lf_trid`), maps the endpoint slug to its WC query var via `WC()->query->get_query_vars()`, and rebuilds query vars from scratch.

- **WooCommerce Terms & Conditions page ID not translated on checkout** — The "I agree to Terms & Conditions" link on checkout always pointed to the source-language T&C page. WC resolves this via two separate paths: `wc_get_page_id('terms')` → `woocommerce_get_terms_page_id` filter, and `wc_get_terms_and_conditions_page_id()` → `woocommerce_terms_and_conditions_page_id` filter. Both are now hooked in `WcPageBridge` and delegate to the shared `translate_wc_page_id('woocommerce_terms_page_id')` cache entry.

- **WooCommerce Privacy Policy link on checkout pointing to source-language page** — WC resolves the privacy policy via `wc_privacy_policy_page_id()` → `woocommerce_privacy_policy_page_id` filter → `get_permalink()` directly, bypassing WordPress's `get_privacy_policy_url()`. Added `WcPageBridge::translate_privacy_policy_page_id()` on the WC filter, resolving the translation via `TridGroup::get_translations()` (the privacy policy page is a WP core option, not a WC option). The `privacy_policy_url` filter in `Redirector::translate_privacy_policy_url()` covers non-WC contexts (WP login footer, FSE blocks).

- **WooCommerce Brands and custom product taxonomy archives falling back to source-language pages** — Brand archives under a language prefix (e.g. `/es/brand/nike/`) were silently routed to the source-language archive instead of the translated one. The three taxonomy-archive hooks in `WcPageBridge` were hardcoded to `product_cat` / `product_tag`. Replaced the hardcoded lists with a `get_product_archive_taxonomies()` helper (default: `product_cat`, `product_tag`, `product_brand`) filterable via `lf_wc_product_archive_taxonomies`. All three hooks (`register_taxonomy_archive_rewrite_rules`, `translate_wc_term_link`, `inject_taxonomy_archive_lang`) now consume this list; rewrite slug and query var are read from the taxonomy registry via `get_taxonomy()`.

- **Site Title block `href` not localised** — `fix_site_logo_link()` already rewrote the `core/site-logo` anchor to the language-appropriate home URL, but the `core/site-title` block's wrapping link was left pointing to the source-language home. Added `fix_site_title_link()` (hooked on `render_block`, priority 20) mirroring the same pattern and delegating to the shared `lang_home_url()` helper, which handles both static front pages (translated permalink via `_lf_trid`) and latest-posts fronts (`home_url('/{lang}/')`).

- **Custom taxonomy archives returning 404 under a language prefix** — Any public custom taxonomy with a rewrite slug (e.g. `event_type` with slug `event-type`) produced a 404 when accessed via a language-prefixed URL such as `/es/event-type/conference/`. Root cause: LF's generic fallback rewrite rule matched the URL as `pagename=event-type/conference`, WP found no page with that slug, and WordPress's own taxonomy archive rule (`^event-type/(.+?)/?$`) never fired. Fix: `Manager::add_general_taxonomy_archive_rewrite_rules()` (called from `register_rewrite_rules()`) now registers explicit `top`-priority rules for all public taxonomies with a rewrite slug, excluding WP built-ins and WC taxonomies already handled by `WcPageBridge`. `Manager::translate_general_term_link()` (hooked on `term_link`) prefixes `get_term_link()` output with the active language path. `QueryFilter::handle_pre_get_posts()` already injected `_lf_lang` for all `is_archive()` main queries, so no additional query hook was needed. The exclusion list is filterable via `lf_public_taxonomy_archives_excluded`. **Flush required on upgrade:** Settings → Permalinks → Save.

- **WooCommerce Product structured data duplicated** — When no third-party SEO plugin is active, a product page carried two `Product` JSON-LD blocks: one from WC's own `WC_Structured_Data` (wp_footer) and a second from `SeoSupport::output_product_schema()` (wp_head). `SchemaManager` also emitted a `WebPage` block for product singulars, which is redundant when WC already covers structured data for that page type. Fix: `SeoSupport` now hooks `woocommerce_structured_data_product` (priority 10) and injects `inLanguage` (BCP 47) directly into WC's own Product markup instead of outputting a parallel block. `SchemaManager::print_schema()` skips the Article/WebPage block for product singulars when WC is active. Result: one `Product` block per page, with `inLanguage` set, owned by WC.

- **Secondary (non-main) CPT queries returning mixed-language results** — Sidebar widgets, `get_posts()` calls in templates, "Latest Posts" / "Latest Events" core blocks, and any code that creates a secondary `WP_Query` directly received no `_lf_lang` constraint, so results from all languages were mixed together. Root cause: `QueryFilter::handle_pre_get_posts()` guards with `is_main_query()` and exits immediately for non-main queries. Fix: new `QueryFilter::handle_secondary_pre_get_posts()` (also hooked on `pre_get_posts`) injects `_lf_lang = LF_LANG` on all secondary frontend queries, skipping admin, WC post types (already handled by `CatalogQuery`), `post_type = 'any'`, ID-only lookups (`fields = 'ids'` or `'id=>parent'`), WordPress system post types, and queries that already carry a `_lf_lang` constraint. Additional post types can be excluded via the `linguaforge_secondary_query_excluded_post_types` filter.

- **Navigation block injecting unexpected new items** — `handle_secondary_pre_get_posts()` did not exclude WordPress system/infrastructure post types. Any secondary `WP_Query` for `wp_navigation` posts — including `WP_Navigation_Fallback::get_fallback()` called by the `core/navigation` block when its referenced post cannot be resolved, and `QueryFilter::arm_page_list_lang_filter()`'s own internal fallback lookup — received an injected `_lf_lang = LF_LANG` constraint. Since `wp_navigation` posts do not carry `_lf_lang` meta by default the fallback query returned zero results, causing WordPress to silently create a new navigation post from the latest classic menu. Fix: `handle_secondary_pre_get_posts()` now skips a `$system_types` list (`wp_navigation`, `wp_navigation_fallback`, `nav_menu_item`, `wp_template`, `wp_template_part`, `wp_block`, `wp_global_styles`, `wp_font_family`, `wp_font_face`) that mirrors the internal-type exclusions already present throughout the codebase.

### Changed

- **Translation Memory and API Response Cache toggles moved to AI Usage & Cache tab** — The enable/disable checkboxes for both caching layers were previously in Settings → Behavior. They now live in the AI Usage & Cache tab under their respective inner tabs (API Response Cache / Translation Memory), directly alongside the stats and clear-cache controls. Each toggle saves independently via its own `admin_post_` handler; the main settings form is unaffected.

---

## [2.2.3] — 2026-06-07

### Fixed

- **WooCommerce Cart, Checkout, and My Account pages always linking to source-language URLs** — Mini-cart, the checkout flow, and My Account navigation always resolved to the source-language equivalents regardless of the active frontend language. Root cause: `wc_get_page_id('cart'|'checkout'|'myaccount')` reads from WP options which always store source-language IDs. Added `woocommerce_get_cart_page_id`, `woocommerce_get_checkout_page_id`, and `woocommerce_get_myaccount_page_id` filters in `WcPageBridge`, reusing the shared `translate_wc_page_id()` private method (per-request cache keyed by option name) already serving the Shop page.

### Developer

- Removed stale `[LF-DEBUG filter_related]` `error_log` call from `WcPageBridge::filter_related_products_by_lang` left over from related-products debugging.
- **Tests — 16 new unit test methods.** `WcPageBridgeTest` (tests 13–26): `translate_cart_page_id`, `translate_checkout_page_id`, `translate_myaccount_page_id` (happy-path, no-translation passthrough, cache-key independence across page types), and full `filter_related_products_by_lang` coverage (empty input, source-lang passthrough ×2, source peers mapped to translations via `_lf_trid`, self-exclusion after trid mapping, already-translated peer kept, foreign-lang peer dropped, missing trid skipped, no translation in target language). `CatalogQueryTest` (tests 17–18): `is_singular('product')` effective-lang branch — product's own `_lf_lang` used when set; falls back to `LF_LANG` when absent. `WcPolyfills` extended with `get_queried_object()` and `is_singular()` stubs, and `'compare' => 'IN'` support added to the `get_posts` stub.

---

## [2.2.2] — 2026-06-06

### Fixed

- **WooCommerce product blocks showing cross-language products** — New Arrivals (`product-new`), Top Rated, Best Sellers, On Sale, Handpicked Products, Featured Product, and Product Collection all use secondary `WP_Query` instances. `woocommerce_product_query` is guarded by `is_main_query()` inside `WC_Query::pre_get_posts()` and never fires for these blocks. Added `pre_get_posts` hook (`apply_language_filter_to_secondary_query`) to inject the `_lf_lang` meta constraint on all secondary product queries.

- **Cross-language transient cache contamination in legacy product grid blocks** — `BlocksWpQuery::get_cached_posts()` computes its MD5 hash before `pre_get_posts` fires, so `_lf_lang` was absent from the cache key. The first language to prime the transient contaminated all other languages. Now disabled via the `woocommerce_blocks_product_grid_is_cacheable` filter (`disable_product_grid_cache`) when `LF_LANG` is set, forcing live SQL execution on every request.

- **WooCommerce built-in pages missing `_lf_lang`** — Shop, Cart, Checkout, My Account, and Terms pages created before LF is active have no `_lf_lang` postmeta, causing them to appear as "untagged" in the language filter. New `PageTagRepair` class repairs them lazily when the admin pages list is viewed with All Languages selected.

---

## [2.2.1] — 2026-06-06

### Added

- **SEO Analysis scoring profiles** — three built-in profiles (Blog/Editorial, Product/eCommerce, Landing/Short-form) each with tailored thresholds and metric weights. Profile selector appears per-row in Settings → SEO → Analysis and auto-triggers analysis on change (no separate Analyze button). Block editor sidebar panel includes a `SelectControl` profile picker that auto-loads the score on mount and on every profile change.

- **H2-as-H1 global option** — checkbox in Settings → SEO → Analysis. When enabled and the theme renders the post title as H2 (no H1 present), the first H2 is credited as the H1 equivalent. Fallback when the rendered page cannot be fetched returns `ok` status with an explanatory note rather than penalising the score.

- **Fetch-based heading detection** — `SeoAnalysisPanel::extract_headings_from_url()` fetches the rendered frontend page via `wp_remote_get()` so theme-output heading tags (post title as `<h1>`) are counted accurately. Falls back to content-only parsing on fetch failure. `linguaforge_seo_sslverify` filter for self-signed-cert environments.

- **WooCommerce classic editor SEO Analysis meta box** — `add_meta_box()` registered for `product` post type (filterable via `linguaforge_seo_analysis_classic_post_types`). Profile selector with a disabled "Analyse…" placeholder triggers inline analysis on change; full metrics table and AI recommendations rendered directly in the meta box. `seo-analysis-meta.js` is enqueued only on product edit screens via `admin_enqueue_scripts`.

- **AI recommendation caching** — results stored in the existing `CacheStore` table under `seo-ai-{profile}` feature keys. Hash covers post content, title, meta description, language, and profile — any content change invalidates the cache automatically. Cache hits are served instantly; a `from_cache: true` flag in the response causes JS to render a "↺ Refresh AI Analysis" button. `force_refresh: 1` POST parameter bypasses cache and writes a fresh entry.

- **Profile-aware AI prompts** — `ajax_ai_analyze()` builds a tailored prompt context per profile. Product: excludes heading and internal-link metrics (WooCommerce theme template outputs the product title as H1 automatically; internal links are not a relevant ranking signal for product pages). Landing: excludes internal-link advice. Blog: full context including H1/H2 counts and internal link count.

### Changed

- **Product profile weights** — `links` weight reduced from 10 to 0; `meta_description` raised from 25 to 30; `word_count` raised from 10 to 15; `images` raised from 15 to 20; `headings` lowered from 10 to 5. Weights still sum to 90 (max score 100 including the fixed 10-point reading-time base).

- **Landing profile weights** — `links` weight reduced from 10 to 0; `meta_description` raised from 25 to 30; `word_count` raised from 10 to 15.

- **`rate_links()` — non-required profiles** — returns `info` status (0 score contribution) instead of `warn` when `links_required` is false and no links are found.

- **Block editor AI panel** — replaced static "Run AI Analysis" button with a persistent "↺ Refresh AI Analysis" button rendered below results; `runAi()` accepts `forceRefresh` parameter passed through to the AJAX action.

### Fixed

- `seo-analysis.js` — `forEach` callback parameter `s` shadowed the outer `s` (strings) variable; renamed to `sel`. ESLint `no-shadow` errors on lines 160 and 194.

- `seo-analysis-editor.js` — `useEffect` dependency array missing `setScore`; added. ESLint `react-hooks/exhaustive-deps` warning on line 83.

---

## [2.2.0] — 2026-06-06

### Added

- **SEO tab** — new Settings → SEO tab with seven inner panels: Hreflang, Open Graph & Twitter Cards, Social Share, WooCommerce (WC-only), Schema.org, Sitemap, Analysis, and Compatibility.

- **Hreflang settings UI** — first admin surface for the always-on hreflang engine. Enable/disable toggle, status display, and live list of suppressed SEO-plugin hreflang outputs (Yoast, Rank Math, AIOSEO, SEOPress).

- **Open Graph & Twitter Cards** (`SeoManager`) — outputs `og:locale` and `og:locale:alternate` on every page so social platforms serve the correct language when a translated URL is shared. In `auto` mode detects the legacy lf-social-share mu-plugin and major SEO plugins; falls back to the full OG + Twitter Card set when none are present. Configurable mode selector: auto / locale-only / full / disabled. Default OG image field with fallback chain: featured image → site logo → site icon → admin-configured default → mu-plugin legacy asset.

- **Social Share** — built-in extension for the WordPress Core Social Icons block. Set any icon's link URL to `share:facebook`, `share:x`, `share:linkedin`, `share:whatsapp`, `share:telegram`, `share:email`, `share:reddit`, `share:pinterest`, `share:mastodon`, `share:copy`, `share:native`, or `share:auto`; LF rewrites them to the correct share URL or JS action at render time. Includes `share:copy` (clipboard), `share:native` (Web Share API), and `share:auto` (native with clipboard fallback) JS actions with toast feedback.

- **WooCommerce Open Graph** (`SeoSupport`) — on product pages, replaces `og:type` with `product`, adds `og:price:amount`, `og:price:currency`, `og:availability`, and their `product:` namespace equivalents used by Facebook Catalog. Only active when WooCommerce is installed.

- **Schema.org JSON-LD** (`SchemaManager`) — outputs `Article` / `WebPage` on singular posts and pages, and `WebSite` on the front page/blog index. Each type includes `inLanguage` (BCP 47 format). Fires `linguaforge_seo_schema_extra_types` action for extensions. Fully skips output when Yoast, Rank Math, AIOSEO, or SEOPress is active to prevent conflicting JSON-LD graphs. **WooCommerce Product schema** — `Product` type with `name`, `description`, `inLanguage`, `url`, `image`, and `offers` (price, currency, Schema.org availability URL).

- **XML Sitemap** (`SitemapManager`) — dedicated multilingual sitemap at `/lf-sitemap.xml` with `xmlns:xhtml` namespace and `<xhtml:link rel="alternate" hreflang>` entries for every translation group. Announced automatically in `robots.txt` via filter. 24-hour transient cache flushed on `save_post` for LF-managed posts. Admin panel shows URL, entry count, cache age, flush button, and Bing/Yandex ping buttons. Google row explains robots.txt discovery (deprecated ping). robots.txt section detects physical file and offers to append the Sitemap directive via `WP_Filesystem`.

- **SEO Analysis tab** — rule-based content audit for any post/language combination. Filter content by language and post type, browse the list, click Analyze. Checks: title length, meta description presence/quality (uses `_linguaforge_meta_description` when available), word count, reading time, heading structure (H1/H2/H3), image alt coverage, internal/external link count. Returns weighted 0–100 score with per-metric status (ok/warn/fail/info).

- **SEO Analysis — block editor sidebar panel** (`seo-analysis-editor.js`) — `PluginDocumentSettingPanel` in the Document sidebar shows the current post's rule-based score. Clicking Analyze opens a `wp.components.Modal` with the full metrics table. An "AI Recommendations" section calls the configured AI provider (quality tier) for natural-language improvements: overall assessment, up to 5 specific recommendations, optional title and meta description suggestions.

- **Compatibility tab** — read-only panel listing detected SEO plugins with per-feature behaviour table explaining what LF does for Hreflang (takes over, suppresses plugin output), Open Graph (adds locale tags, avoids duplicates), Schema.org (defers entirely to prevent conflicts), XML Sitemap (independent, submit both to Search Console), and Canonical (removes WP core canonical). Legacy lf-social-share mu-plugin migration notice.

- **Admin help tab** — new "SEO" entry explains complete multilingual SEO coverage, no additional SEO plugin required, sitemap discovery via robots.txt, and SEO plugin conflict avoidance strategy.

### Developer

- `SeoManager`, `SchemaManager`, `SocialShare`, `SitemapManager` — new classes under `language-router/includes/seo/`. All registered via Router and optioned-gated.
- `WooCommerce/SeoSupport` — new class hooking `linguaforge_seo_og_type` filter and `linguaforge_seo_og_extra_tags` / `linguaforge_seo_schema_extra_types` actions. Product schema output delegates to `SchemaManager::output_schema()` (now `public static`).
- `SeoManager::lang_to_locale()`, `SchemaManager::lang_to_bcp47()`, `SchemaManager::output_schema()` — promoted to `public static` for testability.
- All `SeoAnalysisPanel` private helpers promoted to `public static`; `analyze_links()` accepts optional `$home` parameter.
- `linguaforge_seo_og_type` filter — override `og:type` per page type (used by WC to return `'product'`).
- `linguaforge_seo_og_extra_tags` action — append additional OG properties after the base set.
- `linguaforge_seo_schema_extra_types` action — append additional JSON-LD types after built-ins.
- `linguaforge_seo_og_locale_map` filter — override the language→Facebook-locale mapping.
- `linguaforge_seo_schema_locale_map` filter — override the language→BCP47 mapping.
- `linguaforge_seo_og_image` filter — override the resolved OG image URL.
- `linguaforge_seo_og_description` filter — override the resolved OG description.
- `linguaforge_seo_schema_data` filter — modify any schema array before JSON encoding.
- `linguaforge_seo_sitemap_slug` filter — override the sitemap URL slug (default `lf-sitemap.xml`).
- `linguaforge_seo_sitemap_xml` filter — modify the full sitemap XML string before output.
- `linguaforge_social_share_url` filter — override the resolved share URL per service.
- `ApiPolyfills.php` — added `home_url`, `is_ssl`, `esc_url`, `esc_attr`, `wp_parse_url`, `wp_get_document_title`, `do_blocks`, `wp_trim_words`, `_n`, `number_format_i18n`, PHP 8.0 `str_starts_with`/`str_contains`/`str_ends_with` shims for unit test environments.

### Tests

- **`SeoHelpersTest.php`** (unit, ~30 tests) — `SeoManager::lang_to_locale()`: known language codes, unknown fallback, filter override. `SchemaManager::lang_to_bcp47()`: BCP 47 format, hyphen separator, fallback. `SchemaManager::output_schema()`: JSON encoding, `</script>` injection escaping, empty array guard, Unicode preservation. `SocialShare::rewrite_share_url()`: all external services, JS actions (copy/native/auto), no-op cases, legacy `share:twitter` alias.
- **`SeoAnalysisHelpersTest.php`** (unit, ~28 tests) — `count_words`, `extract_headings` (case-insensitive, attribute-tolerant), `analyze_images` (alt coverage), `analyze_links` (internal/external/anchor/mailto classification), all `rate_*` functions (boundary conditions), `compute_score` (all-ok = 100, all-fail = 10, mixed, cap).
- **`SeoAnalysisPanelIntegrationTest.php`** (integration, 5 tests) — `ajax_analyze()`: permission denied as subscriber, invalid post ID, valid post metric key structure, score in 0–100 range, word-count rating correctness.

---

## [2.1.10] — 2026-06-05

### Developer

- **Full coverage pass — 32 new PHPUnit tests; total ~865 (unit + integration + WC).** Combined coverage rises from 23.63 % (end of 2.1.9) to **26.94 %** (2,787 / 10,347 statements). Testable business-logic coverage ~65–70 %. All §6.0.1 High, Medium, and Low priority gaps closed. No functional changes, no schema changes.

  **High:**
  - `SyncIntegrationTest.php` (4) — `handle_save_post()`: new post → `_lf_lang` + `_lf_trid`; existing lang preserved on update; `wp_navigation` gets lang but not TRID. `class-sync.php` 8 % → ~53 %.
  - `TermNameIntegrationTest.php` (+4) — `get_term` filter and `wp_get_object_terms` filter (Store API path): translate and skip correctly for WC and non-WC taxonomies. `TermNameFilter.php` 49 % → 68 %.
  - `LanguageUninstallerIntegrationTest.php` (3) — `uninstall()` end-to-end: posts deleted, count correct; protected lang noop; `file_mod_allowed=false` → skipped-file list surfaced. `LanguageUninstaller.php` 48 % → 94 %.

  **Medium:**
  - `MetaDescriptionIntegrationTest.php` (3) — success path via StubProvider; empty response → failure; cache hit on second call. `Features/MetaDescription.php` 74 % → 90 %.
  - `GlossaryHashForPairTest.php` (+4) — `insert()` round-trip; empty-term guard (returns 0); `delete()` removes entry; `format_for_prompt()` substitution + "do not translate" directive. `Glossary.php` 61 % → 79 %.
  - `TaxonomyDelegateIntegrationTest.php` (+3) — `clear_translated_product_term_cache_on_post()`: clears cache for translated product; skips source; skips non-product type. `TaxonomyDelegate.php` 53 % → 77 %.
  - `ContextOptionsTest.php` (+3) — `lang_base_url()` subdomain URL; source-lang returns `home_url('/')`; `detect_lang()` reads HTTP_HOST in subdomain mode. `class-context.php` 52 % → 57 %.
  - `FeatureControllerRestTest.php` (+2) — `/feature/meta-description/{id}` returns 200 + `success=true` via StubProvider; unknown slug returns 404. `FeatureController.php` 45 % → 47 %.

  **Low:**
  - `KeyStoreEnvelopeTest.php` (+3 unit) — `decrypt_v2()` invalid base64 → null; at exact IV+TAG boundary → null; `decrypt('')` via v1 path → null. `KeyStore.php` 79 % → 81 %.
  - `VariationSyncIntegrationTest.php` (+2) — `sync_wc_taxonomies_from_source()` physically writes `product_type` and `pa_*` terms (post-condition verified by temporarily removing `TaxonomyDelegate` filter). `VariationSync.php` 79 % → 80 %.
  - `ManagerIntegrationTest.php` (2) — source-language post returns URL unchanged; non-existent post ID returns URL unchanged. `class-manager.php` 38 %.
  - `AbstractProviderIntegrationTest.php` (5) — WP_Error, HTTP 401, invalid JSON, `stop_reason=max_tokens`, success path — all via `pre_http_request` filter + Anthropic provider, no API quota consumed. `AbstractProvider.php` 2 % → 80 %.

  **Discovered during run:**
  - `MetaDescriptionModuleIntegrationTest.php` (5) — `Module::get()` with/without meta; `Module::save()` stores and delete-on-empty; `Module::output_tags()` three `<meta>` tags via bloginfo fallback. `meta-description/meta-description.php` 2 % → 27 %.

---

## [2.1.9] — 2026-06-05

### Fixed

- **`Translation::run()` / `run_json_envelope()` — integration tests added** — `linguaforge_ai_provider` filter added to `run_json_envelope()`, `try_translate_with_tm()`, and `MetaDescription::run()` as a provider injection seam (also a real extension point for custom providers). `StubProvider` added in `tests/integration/Stubs/` with response-queue support for multi-call scenarios. 2 unit tests for `run()` early exits (invalid language, post not found). 9 integration tests cover cache hit, JSON-envelope path, cache-write-on-success, empty provider response, `linguaforge_translation_content` filter, TM path with pre-cached blocks, TM-disabled fallback, TM partial-hit → JSON-envelope fallback, `chain_meta_description()` chaining. `composer test:integration` / `test:integration:wc` / `coverage:run` include a `wp eval ensure_table()` step for the four custom plugin tables. (§3.1 / §6.1)
- **`Manager` / `Switcher` — pure helpers extracted + unit tests** — `Manager::rewrite_lang_permalink()` and `Switcher::build_translated_url()` extracted as `public static` helpers. Both `lang_permalink()` and `translate_current_url()` now delegate to them. `RouterPureHelpersTest.php` adds 12 unit tests covering path-prefix mode, subdomain mode, search, singular, and source-language branches. `RedirectorSwitcherTest.php` adds 7 integration tests for `allow_lang_subdomains()`, `fix_site_logo_link()`, and `translate_menu_items()`. (§6.5)
- **`FeatureController` REST endpoints — integration tests added** — `FeatureControllerRestTest.php` (10 tests via `rest_do_request()`): 401 on unauthenticated requests (all four endpoint groups), 403 on insufficient capability, 400 on invalid language / empty hints / empty chunk_text / unknown feature (404), 429 on per-user rate limit (seeded transient + filter), 429 on daily quota exceeded. No live API key required — all tests rejected before the AI call. (§6.3)
- **`LinkFixer::scan_post()` — integration tests added** — `LinkFixerScanTest.php` (6 tests): non-existent post, no links, wrong-language link → `fixes[]`, no translation → `flagged[no_translation]`, unresolvable data-id → `flagged[unresolved]`, correct-language link not in fixes, result shape completeness. Uses `Router::get_instance()->link_fixer` with TridGroup TRID scaffolding and Context cache reset. (§3.3)
- **`MetaBox::inject_instance_languages()` — unit tests added** — `MetaBoxTest.php` (5 tests): existing code not overwritten, empty instance list, Locale display name resolution (branches on intl availability), unresolvable code `xx` → `strtoupper` fallback, mix of present/missing codes, multiple missing codes. (§3.5)
- **`LinkFixer` — pure helpers made public static + unit tests** — `alt_scheme()`, `extract_internal_links()`, `fix_data_id_attr()` changed from `private` to `public static`. `extract_internal_links()` now accepts `$home` as a parameter (was `home_url()` inline) making it fully pure. `LinkFixerTest.php` adds 18 unit tests covering all three helpers. (§3.3)
- **`MaintenanceTab` — panel architecture applied** — 871-line tab reduced to a 39-line orchestrator. Three panel classes extracted: `LanguageOverridesPanel` (456 lines — .mo upload/delete, Loco Translate copy-to-safe-storage, 3 handlers; `overrides_dir`, `loco_is_active`, `loco_custom_files` are `public static` for testability), `DebugFilesPanel` (199 lines — debug viewer + toggle, 2 handlers), `UninstallSettingsPanel` (106 lines — uninstall toggle, 1 handler). `LanguageOverridesPanelTest.php` (10 unit tests) covers all filesystem helpers using temp directories. `size_format()` polyfill added to `ApiPolyfills.php`. (§3.2)
- **`UsageRecorder` — DB layer integration tests added** — `UsageRecorderTest.php` (11 tests): `record()` no-op without context, round-trip row shape, ON DUPLICATE KEY UPDATE accumulation, negative token clamping, `query()` date filter, `query()` GROUP BY aggregation, `row_count()` empty/after-seed, `clear_all()` count/empty. Seeding uses the real `push_context()`/`record()`/`pop_context()` pipeline. (§6.7)
- **`CacheStore` / `TranslationMemory` — integration tests added** — `CacheStoreTest.php` (6 tests): empty-table shape, row count, date strings, hit_count via DB update, `clear_all()` count, leaves empty. `TranslationMemoryTest.php` (8 tests): empty shape, row count, `bytes_estimate`, idempotent `store()` (INSERT IGNORE), date strings, `clear_all()` count, leaves empty. (§3.2)
- **`CacheStatsPanel` / `UsageStatsPanel` — panel architecture for AI Usage & Cache tab** — Translation Caching section (API cache + TM stats + clear handlers) extracted from `MaintenanceTab` into `ai/includes/Admin/Settings/Panels/CacheStatsPanel.php`. Token usage table extracted to `UsageStatsPanel.php`. `AiUsageTab` reduced to a 36-line orchestrator. SettingsPage routes both clear actions to `CacheStatsPanel`. `MaintenanceTab` drops from 1,184 → 871 lines. (§3.2)
- **`JsonEnvelopeTranslator` — JSON-envelope path extracted from `Translation.php`** — `run_json_envelope()`, `parse_full_post_envelope()`, and `build_translation_schema()` moved to a new `JsonEnvelopeTranslator` class. All three are `public static` (no reflection needed in tests). `Translation::run()` instantiates `JsonEnvelopeTranslator` and handles `chain_meta_description` for both TM and JSON-envelope paths consistently. `Translation.php` drops from 912 → 648 lines. (§3.1)
- **`TranslationMemoryTranslator` — TM path extracted from `Translation.php`** — `try_translate_with_tm()` and its six pure helpers moved to a new `TranslationMemoryTranslator` class. `Translation.php` drops from 1,376 → 912 lines. All six helpers are now `public static` (no reflection needed in tests). `Translation::run()` instantiates `TranslationMemoryTranslator` with the pre-built `WorkerConfig` and system prompt. `compute_compliance_signature()` is now public static on `TranslationMemoryTranslator` — integration tests call it directly. (§3.1)
- **`Translation::try_translate_with_tm()` — six pure helpers extracted** — `build_tm_source_markups`, `build_tm_queue`, `build_tm_schema`, `build_tm_user_message`, `parse_tm_envelope`, `reassemble_tm_blocks` extracted as private static methods. `try_translate_with_tm()` now delegates to each. 26 unit tests added in `TranslationTmHelpersTest.php`. `wp_strip_all_tags` and `serialize_block` polyfills added to `ApiPolyfills.php`. (§3.1)
- **`Translation::detect_post_language()` — unit tests added** — 9 tests covering all five logical paths: admin post-screen with `_lf_lang` meta, admin post-screen locale fallback, admin post-screen unknown locale → null, admin non-post screen → null, null screen → null, no `WP_Post` global → null, frontend singular with meta, frontend singular with zero object ID → null, frontend non-singular → null. New polyfills added to `ApiPolyfills.php`: `WP_Post` stub, `WP_Screen` stub, `is_admin`, `get_current_screen`, `is_singular`, `get_queried_object_id`, `get_post_meta`, `get_locale`. (§3.1)
- **`CatalogQuery` — unit test coverage added** — `apply_language_filter()` was only tested for hook registration. Added `CatalogQueryTest` (5 unit tests): clause appended to empty meta_query, clause appended alongside existing clauses, double-application guard for flat meta_query, double-application guard for relation-wrapped meta_query, admin skip. `is_admin()` polyfill added to `WcPolyfills` with a controllable `LfWcMocks::$is_admin` flag. (§5.7)
- **`QueryFilter` — `shop_booking` added to WC skip lists** — `shop_booking` (WC Bookings) was absent from the frontend `$wc_types` and admin `$wc_non_content` skip lists in `QueryFilter::handle_pre_get_posts()`. A main query for that post type would have had a `_lf_lang` meta condition appended, silently returning zero results. Added alongside the existing `shop_order`, `shop_coupon`, `shop_subscription` entries. `HposOrderIsolationTest` data provider extended to pin the contract. (§5.6)
- **`LanguageUninstaller::collect_post_ids()` — Plugin Check compliance** — table name now passed via `%i` identifier placeholder instead of string interpolation; local `$wpdb` alias satisfies PHPCS `NotPrepared` sniff; `phpcs:ignore` consolidated onto the `get_col()` line covering `DirectQuery`, `NoCaching`, and `UnescapedDBParameter`.
- **`RouterTab` uninstall notice — Plugin Check compliance** — `$_GET['lf_uninstall_posts|files|skipped']` now passed through `wp_unslash()` + `sanitize_text_field()` before `(int)` cast; `phpcs:disable/enable` block covers all three assignments.

---

## [2.1.8] — 2026-06-04

### Performance

- **`MetaDelegate::maybe_delegate_bulk()` — bulk source read** — the overlay loop previously called `get_post_meta($source_id, $key, false)` individually for each of the 33 `OPERATIONAL_KEYS`, producing ~33 filter traversals per translated product load (each re-entering `maybe_delegate()` and bailing at the language guard). Replaced with a single `get_post_meta($source_id)` bulk read; keys are extracted from the returned `array<string, array>`. Reduces filter traversals from O(n_keys) to O(1) per product load — measurable on catalog pages with many translated products. (§7.1)
- **`TaxonomyDelegate::get_taxonomies_to_clear()`** — new private static helper that computes the merged WC taxonomy list (`WC_TAXONOMY_DEFAULTS + ['product_type'] + pa_*` attribute taxonomies) once per request via `static $taxonomies` and returns the cached result on subsequent calls. Both `clear_translated_product_term_cache()` and `clear_translated_product_term_cache_on_post()` now call this helper, eliminating repeated `get_object_taxonomies() + array_merge + str_starts_with` passes on every `the_post` loop iteration. (§7.2)

### Fixed

- **`TaxonomyDelegate::clear_translated_product_term_cache_on_post()` — corrected docblock** — the previous comment incorrectly attributed the term cache re-priming to `setup_postdata()`. The actual source is `WP_Query::get_posts()` calling `update_object_term_cache()` once for the whole query before the loop starts. The `the_post` hook is needed to clear caches immediately before WC reads each product in the loop iteration, not because `setup_postdata()` re-primes them. (§7.2)

### Added

- **Language uninstall** (`LanguageUninstaller`) — new class at `ai/includes/Admin/Language/LanguageUninstaller.php`. Each secondary language panel in the Router tab now has a collapsible "Danger Zone" section with a confirmation-gated Uninstall button. Deletes all posts of any type carrying `_lf_lang = $lang` (templates, template parts, patterns, navigations, posts, pages, CPTs, products, product variations) via `wp_delete_post($id, true)`. Also removes WordPress locale pack files (`WP_LANG_DIR/*.mo|po`, `plugins/`, `themes/`) so the language is fully removed from the router. If `DISALLOW_FILE_MODS` is set, a warning notice lists the file paths for manual deletion. Two languages are permanently protected: the primary content language and the WP instance locale — blocked in the UI and enforced server-side. Introduces `UninstallResult` readonly value object carrying `posts_deleted`, `files_deleted`, `files_skipped`, and `mods_allowed`. (§9.4 / §10.1)

### Tests

- **`LanguageUninstallerTest`** (19 unit tests) — covers `is_protected()`: source-language guard, WP-locale guard (including case-insensitive locale handling), unprotected secondary language, and edge case where source lang and WP locale coincide. `collect_post_ids()`: empty result, integer casting from string rows, postmeta table name in SQL, `_lf_lang` key in query, language value in query. `collect_locale_files()`: empty dir, root `.mo` prefix matching, `.po` files, `plugins/` subdir, `themes/` subdir, non-matching files excluded, aggregation across all three dirs.

### Dev tooling

- **`bin/seed-dev-env.sh` — robust page creation** — `wp post list --search` silently returns all posts when WooCommerce is active, causing `create_page_if_missing` to skip creation of every page that matched any existing post with `_lf_lang`. Replaced `--search="$title"` with `--name="$slug"` (direct `post_name` lookup); added explicit `--post_name` to all `wp post create` calls. All 9 seeded pages (EN/DE/CA Home, About, Contact) now receive correct slugs and are reliably created on a fresh environment. Function signature gains a required 4th `slug` parameter.
- **`bin/seed-dev-env.sh` — second rewrite flush** — added a final `wp rewrite flush` at the end of the script, after LF options (`linguaforge_primary_language`, `linguaforge_routing_mode`) and language packs are all in place. The early flush at line 23 runs before options exist, so language-prefix rewrite rules (`/en/`, `/de/`, `/ca/`) were never registered on a fresh install.
- **`bin/seed-dev-env.sh` — welcome guide + meta box preferences** — dismisses the Gutenberg block editor welcome guide via `wp_persisted_preferences` (`core/edit-post.welcomeGuide = false`) and clears `closedpostboxes_page` / `metaboxhidden_page` user meta so meta boxes are open by default in E2E runs.
- **`bin/seed-dev-env.sh` — switcher block target fix** — the language-switcher block injection now uses `get_posts(['name'=>'home','meta_key'=>'_lf_lang','meta_value'=>'en'])` instead of `get_page_by_path('home')`, preventing misdirection to a temporary test page that shares the same slug.

### E2E

- **`e2e/admin-metabox.spec.js` — meta box interaction hardening** — `goToFirstPageEdit` now closes the WordPress editor welcome guide if present (2 s grace window; guide dismissal uses role selectors rather than CSS). The "clicking a feature action button" test now expands the Gutenberg "Meta Boxes" panel before interacting: reads `aria-expanded` to avoid toggling an already-open panel, JS-dispatches the click to bypass the resize-handle separator that intercepts pointer events. Removed "avoid AI costs" comment — AI provider is available in E2E.

### UI

- **Maintenance Tab — scrollable file lists** — the Language Overrides and Loco Translate file list tables now cap at `50vh` with `overflow-y: auto`. Long file lists no longer push the rest of the tab off-screen. Implemented via the new `.lf-scrollable-table` CSS utility class.

---

## [2.1.7] — 2026-06-04

### Security

- **`TermNameAdmin::save_fields()`** — capability check (`manage_categories`) now runs before nonce verification, matching WordPress coding standards ordering. (§2.1)

### Fixed — WooCommerce

- **`VariationSync::sync_variations_for()` — attribute update on existing variations** — step 2 previously skipped (via `continue`) when a translated variation already existed, leaving `attribute_pa_*` meta stale if attributes were added or changed on the source variation after initial creation. The idempotency check now runs a full `attribute_pa_*` re-sync pass from the source before continuing, so new attributes propagate immediately on the next `sync_variations_for()` call. (§5.4)
- **`TermNameFilter::is_wc_taxonomy()`** — now also checks the `linguaforge_wc_delegate_taxonomies` filter after the static list. Custom taxonomies added via the filter (e.g. `pwb-brand`) now receive translated term names in both the classic and Store API/block paths. (§5.3)
- **`translate_single_term_name()` and `translate_term_objects()`** — extended from `pa_*` only to all taxonomies covered by `is_wc_taxonomy()` (`product_cat`, `product_tag`, `product_brand`, and custom delegated taxonomies). `product_cat` category names on block product pages and in Store API JSON now display in the visitor's language. (§5.5)

### Changed

- **`Translation::run_chunk()` delegated to `ChunkTranslation`** — all quick-translate logic extracted to `ai/includes/Features/ChunkTranslation.php`. Constructor-injected `AIProviderInterface` makes the class fully testable without a WordPress runtime. `Translation::run_chunk()` is now a 4-line delegator that creates the provider via `ProviderFactory` and hands off. `FeatureController` is unchanged. (§10.6 / §6.2)

### Tests

- **`ChunkTranslationTest`** (18 tests) — unit tests for `ChunkTranslation::run()` and pure helpers: empty input guard, whitespace-only guard, provider null/empty failure, success payload shape, output trimming, 2-message non-refinement array, 4-message refinement array, refinement message content, missing-hint/output edge cases, input capping via `quick_translate_max_input_chars`, `resolve_language_code()` (known/unknown/case-sensitive), and `build_messages()` both paths.
- **`UsageRecorderContextTest`** (13 tests) — unit tests for `UsageRecorder` context stack: push/pop/current stack mechanics, nested push returns innermost key, pop restores previous context, pop on empty stack is safe, `tracked()` sets context during callback, restores after, restores outer context after nested call, pops on exception (`try/finally` guarantee), propagates exception, returns callback value, `record()` no-op when no context active. (§6.7)

### Developer

- **PHPStan WC stubs** — `php-stubs/woocommerce-stubs ^9.0` added to `dev/composer.json`; `woocommerce-stubs.php` added to `phpstan.neon.dist` `scanFiles`. Three `@phpstan-ignore-next-line` suppressions in `RestWriteGuard.php` (×2) and `VariationSync.php` removed. Run `composer update` in `dev/` to install. (§8.1)

---

## [2.1.6] — 2026-06-04

### Added — WooCommerce integration (complete variable product translation)

- **`VariationSync`** (new class) — creates `product_variation` children on translated parent products, TRID-linked to source variations. Copies `_variation_description` (WC's description meta key — not `post_content`, which WC always leaves empty), `attribute_pa_*` meta (WC prefix, no leading underscore; required by `find_matching_product_variation()`), and clears the `wc_product_children_{id}` transient so WC sees new variations immediately. Editors retranslate descriptions via the standard Retranslate button. Idempotent. Closes audit §5.2.
- **`VariationSync::sync_wc_taxonomies_from_source()`** (new method) — copies `product_type`, `pa_*` attribute term assignments, and `product_brand` directly from source to translated product in the DB at creation time. Without this, `WC_Product_Factory::get_product_type()` read an empty term cache and defaulted to `'simple'`, preventing variation form rendering. These are structural, not content — every translated version of a variable product must be variable in the DB.
- **`VariationSync::propagate_wc_taxonomies_to_translations()`** (new method) — fires when the SOURCE product saves (`wp_after_insert_post` priority 30). Re-syncs `product_type`, `pa_*`, and `product_brand` to all translated products. A `variable → simple` type change on the source propagates immediately to all translations.
- **`RestWriteGuard`** (new class) — hooks `woocommerce_rest_pre_insert_product_object` and `woocommerce_rest_pre_insert_product_variation_object` (priority 10). PUT/PATCH to translated products or variations returns HTTP 422 with error code `linguaforge_rest_write_to_translated_product` and `source_id` in the response body. CREATE requests are permitted. Closes audit §5.6.
- **`product_brand` delegation** — native WooCommerce 10.x taxonomy added to `TaxonomyDelegate::WC_TAXONOMY_DEFAULTS`. New `linguaforge_wc_delegate_taxonomies` filter allows third-party brand taxonomies (`pwb-brand`, YITH, etc.) to be added without patching the plugin. Closes audit §5.7.

### Fixed — WooCommerce delegation layer

- **`MetaDelegate` bulk-read bypass** — WC's `read_product_data()` calls `get_post_meta($id)` with no key; the filter fired with `$meta_key = ''` and was not intercepted, so `wc_get_product($translated_id)->get_price()` / `->get_sku()` / `->get_stock_quantity()` returned empty. New `maybe_delegate_bulk()` intercepts the bulk read, fetches translated meta (reentrancy-guarded), overlays every `OPERATIONAL_KEY` with the source value, and returns the merged array. Covers both translated products and translated variations.
- **`MetaDelegate` extended to `product_variation`** — `linguaforge_wc_delegate_post_types` default extended to `['product', 'product_variation']`. `_variation_description` is NOT in `OPERATIONAL_KEYS` and reads from the translated variation so descriptions can differ per language.
- **`VariationDelegate` own-variation check** — before redirecting `product_variation` queries to the source parent, a direct SQL check confirms whether the translated parent has its own variation children. If yes, WC queries them directly; backwards-compatible fallback to source when no translated variations exist.
- **`TaxonomyDelegate` — `object_id` rewrite** — WC's `_prime_post_caches()` fires a combined multi-taxonomy `wp_get_object_terms()` call; `update_object_term_cache()` distributes results by `$term->object_id`. Delegated source terms carried `object_id = source_id`, causing all delegated terms to land in the source product's cache bucket. The translated product's caches were stored as empty arrays — `get_the_terms(183, 'product_type')` returned the cached `[]` without calling `wp_get_object_terms()`, and WC defaulted to `'simple'`. Fixed by rewriting `object_id` on every returned term to the translated post ID.
- **`TaxonomyDelegate` — `wp` / `the_post` cache clearing** — `WP_Query` primes term relationship caches from DB before plugin filters run; `setup_postdata()` (fired by `the_post`) re-primes them. Two hooks (`wp` priority 5, `the_post` priority 10) clear WC taxonomy caches so the next `get_the_terms()` call goes through `wp_get_object_terms()` → TaxonomyDelegate.
- **`TermNameFilter` — Store API / block theme path** — WC 10.x block templates are served at `/product/{slug}/` (no language URL prefix), causing `detect_lang()` to return the source language for all product pages. Two new hooks translate `pa_*` attribute term names using `_lf_lang` from the queried product's postmeta: `get_term` filter (covers `WC_Product_Attribute::get_terms()` → Store API JSON / React block path, used by all store visitors) and `wp_get_object_terms` priority 15 (covers classic template path). Result: DE product page JSON contains "Rot"/"Blau"; EN retains "Red"/"Blue".

### Tests

- **Integration** — `VariationSyncIntegrationTest` (14 tests), `MetaDelegateWcApiIntegrationTest` (11 tests, exercises `wc_get_product()` API path), `VariationDelegateIntegrationTest` +2 cases, `TaxonomyDelegateIntegrationTest` +2 cases, `BootstrapIntegrationTest` +3 hook registration assertions, `RestWriteGuardIntegrationTest` (11 tests).
- **Unit** — `VariationDelegateTest` +1 case (own-variations branch); `WcPolyfills` extended with `LfWpdb::prepare()`, `get_var()`, `esc_like()`, `$posts`, `$postmeta` properties.
- **E2E** — `woocommerce-integration.spec.js` (new, 17 scenarios): admin list, frontend rendering, TermNameFilter (Rot/Blau on DE, Vermell/Blau on CA), product_brand, price delegation, and REST write guard on both product and variation endpoints. Suite: 7 spec files, 55 scenarios total.

---

## [2.1.5] — 2026-06-03

### Changed

- **`Translation::run()` refactored** — the 427-line mega-method is now a ~65-line orchestrator that delegates to four focused private helpers. `build_system_prompt()` de-duplicates the shared system-prompt construction used by both the TM path and the main translation path. `prepare_full_post_inputs()` handles attribute extraction, prompt loading, footnote/excerpt detection, and input-cap enforcement (~75 lines). `run_json_envelope()` owns the WorkerConfig/provider setup, API call, result caching, and meta-description chain. `parse_full_post_envelope()` covers JSON decode, field validation, attribute reinsertion, and payload assembly. No behaviour change; existing integration-test coverage passes unchanged. (`ai/includes/Features/Translation.php`)
- **`admin.js` split: diff modal and content-gen modal extracted** — `admin.js` reduced from 2,064 to 1,493 lines (−571). Diff/apply modal (`ensureDiffModal`, `wireDiffModalEvents`, `openApplyDiffModal`, `closeDiffModal`, `performApplyFromModal`) moved to `admin-diff-modal.js` (297 lines). Standalone content-generation modal (`ensureContentGenOverlay`, `openContentGenOverlay`, `wireContentGenOverlay`, `closeContentGenOverlay`, `applyContentGenToEditor`, `runContentGenRefinement`) moved to `admin-content-gen-modal.js` (351 lines). Both files are loaded via `wp_enqueue_script` with `lingua-forge-admin` as a dependency and read shared utilities from a new `window.LfAdmin` namespace set by `admin.js`. Inline overlay variants (`showTranslationDiffInOverlay`, `showContentGenInOverlay`) remain in `admin.js` as they are tightly coupled to overlay state. (`ai/assets/admin.js`, `ai/assets/admin-diff-modal.js`, `ai/assets/admin-content-gen-modal.js`, `ai/includes/Admin/MetaBox.php`)
- **`build_system_prompt()` and `prepare_full_post_inputs()` are now pure functions** — all WP-dependent resolution (`Config::apply_compliance_to_system`, `Glossary::format_for_prompt`, `get_post_meta`, `file_get_contents`) moved out of these helpers and into the `run()` caller. A new thin private wrapper `resolve_compliance_addendum(int $post_id): string` is the only WP call at the helper boundary. `run_json_envelope()` and `try_translate_with_tm()` now pass resolved plain strings. No behaviour change. (`ai/includes/Features/Translation.php`)
- **Unit tests for `Translation` helpers expanded to 34 pure tests** — `TranslationTest.php` rewritten with no WP stubs required: `build_translation_schema()` (6 cases: baseline, each optional flag, combined, footnote item shape), `parse_full_post_envelope()` (11 cases: invalid JSON, truncation hint, empty content, happy path, missing title, footnotes in/out, attrs reinsert, excerpt, `<br>` strip, Markdown-fenced input), `build_system_prompt()` (7 pure cases — passes compliance addendum and glossary as plain strings), `prepare_full_post_inputs()` (10 cases: excerpt/footnote detection, attr extraction with placeholder substitution, `max_input` cap, source-lang propagation). Also adds `wp_json_encode` polyfill to `ApiPolyfills.php`. (`tests/unit/TranslationTest.php`, `tests/unit/ApiPolyfills.php`)
- **Pure-function extraction and unit tests for `ContentGenerator`, `ExcerptGenerator`, and `TranslationMemory`** — following the same pattern as `Translation.php`, four pure static helpers extracted from `ContentGenerator::run()`: `build_seed_section()` (hints vs. existing-content seed selection with truncation), `build_prompt()` (template placeholder substitution), `is_refinement()` (multi-turn refinement detection), `build_messages()` (provider message array assembly). `ExcerptGenerator::locale_to_lang_code()` extracted from `run()`. `TranslationMemory::compute_hash()` was already public static; no source change required. Three new test files — `ContentGeneratorTest.php` (17 tests), `ExcerptGeneratorTest.php` (4 tests), `TranslationMemoryHashTest.php` (8 tests) — bring the unit suite to 421 tests, 596 total (421 unit + 175 integration). (`ai/includes/Features/ContentGenerator.php`, `ai/includes/Features/ExcerptGenerator.php`, `tests/unit/ContentGeneratorTest.php`, `tests/unit/ExcerptGeneratorTest.php`, `tests/unit/TranslationMemoryHashTest.php`)
- **E2E: `admin-metabox.spec.js` added** — 8 new scenarios verify the 2.1.5 JS split: `window.LfAdmin` is defined after `admin.js` loads, 12 core utility functions are present on the namespace, `openApplyDiffModal` (from `admin-diff-modal.js`) and `openContentGenOverlay` (from `admin-content-gen-modal.js`) are exported correctly, the meta box is attached to the DOM, at least one feature action button is present, and clicking a feature button dispatches a real `admin-ajax.php` request. E2E suite now 6 spec files, 38 scenarios. (`dev/e2e/admin-metabox.spec.js`)

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
