# Lingua Forge — Hooks Reference

This is the single canonical list of every WordPress filter and action Lingua
Forge fires for third-party code to hook into. If you're building an
integration, this is the file to search — not README.md or CONTRIBUTING.md,
which only cross-reference it.

For a narrative walkthrough (safe attach points, bootstrap class structure,
`linguaforge_trigger_translation()`, the WooCommerce integration as a
reference implementation), see **"Writing a third-party integration"** in
[CONTRIBUTING.md](CONTRIBUTING.md).

**Stability contract:** every hook below is part of the plugin's stable
public API. Signatures don't change across minor/patch releases without a
CHANGELOG entry calling it out. New hooks are additive; see
[CHANGELOG.md](CHANGELOG.md) for when each one shipped if that matters to
your integration (most are noted inline below where known).

**Conventions:** filter hooks always return a value; action hooks don't.
Post-type/taxonomy "excluded" filters all follow the same shape — receive
the current array, return the array with your additions. Almost every hook
follows the `linguaforge_` prefix; a handful of Language Router hooks use
the short `lf_` prefix instead (see CONTRIBUTING.md's Prefix Policy for why).

---

## Contents

- [Quick start — commonly used integration hooks](#quick-start--commonly-used-integration-hooks)
- [Router & language detection](#router--language-detection)
- [Content & rewrite exclusion filters](#content--rewrite-exclusion-filters)
- [AI translation](#ai-translation)
- [SEO](#seo)
- [WooCommerce integration](#woocommerce-integration)
- [Lifecycle & integration events (actions)](#lifecycle--integration-events-actions)

---

## Quick start — commonly used integration hooks

The handful most integrations reach for first:

| Hook | Type | Purpose |
|---|---|---|
| `linguaforge_loaded` | action | Safe attach point — fires after the router has fully booted |
| `linguaforge_translation_content` | filter | Modify translated content before cache/return |
| `linguaforge_translation_extra_instruction` | filter | Inject an extra instruction into the AI system prompt |
| `linguaforge_translated_post_meta` | filter | Declare post meta a programmatically-created translation is born with |
| `linguaforge_translation_complete` | action | React to a translated post being created/updated |
| `linguaforge_template_for_lang` | filter | Override the FSE template assigned to a translated post |
| `linguaforge_ai_provider` | filter | Swap in a custom AI provider or test stub |
| `linguaforge_switcher_output` | filter | Customize the language-switcher HTML |
| `lf_languages_list` | filter | Override the active language list |

Full signatures for these are in their category sections below.

---

## Router & language detection

Core language/locale resolution — `language-router/includes/class-context.php`,
`class-locale-detector.php`, `class-language-router.php`, `seo/class-hreflang.php`.

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `lf_primary_language` | filter | `(string $lang)` | Override the source/default language code. Takes priority over Settings → Router. |
| `lf_languages_list` | filter | `(string[] $codes)` | Override the full active language list. Takes priority over Settings → Router. |
| `lf_lang_force_locale` | filter | `(array $map)`, default `['ca' => 'ca']` | Hard locale overrides for lang codes WordPress can't resolve on its own (e.g. for VikBooking). |
| `lf_lang_fallback_map` | filter | `(array $map)`, default `['en' => 'en_US', …]` | Locale fallbacks used when no installed locale matches a lang code. |
| `lf_lang_default_fallback` | filter | `(string $locale)`, default `'en_US'` | Last-resort locale when nothing else matches. |
| `lf_lang_iso_639_1_map` | filter | `(array $map)` | Corrects LF lang codes that aren't real ISO 639-1 codes to one that is, for contexts needing strict ISO 639-1 (e.g. `arg`→`an`, `bel`→`be`). Deliberately excludes codes with no safe 1:1 mapping — see the docblock on `Context::iso_639_1_from_lang()`. |
| `lf_base_domain` | filter | `(string $domain)`, default from `home_url()` | Override the bare domain used for subdomain URL construction — useful when `home_url()` includes `www` or a non-apex hostname. |
| `lf_hreflang_mode` | filter | `(string $mode)`, default `'custom'` | `'custom'` outputs LF's own hreflang tags and suppresses SEO-plugin duplicates. Any other value (e.g. `'off'`) disables built-in output and hands control to the SEO plugin. |
| `lf_hreflang_x_default` | filter | `(string $url, int $post_id, array $translations)` | Override the URL used for the `x-default` hreflang tag (defaults to the source-language URL). |
| `lf_i18n_overrides_dir` | filter | `(string $dir)`, default `uploads/lingua-forge/i18n-overrides/` | Override the storage path for third-party `.mo` override files. |
| `lf_block_editor_restrictions` | filter | `(array{canLockBlocks: bool, supportsTemplateMode: bool} $restrictions, $context)` | Override LF's default block-editor restrictions (block locking, template mode) — e.g. to enable block locking for a specific user role via an MU plugin. Defaults preserve pre-1.4 behavior (both restricted) unless changed in Settings → Behavior. |
| `linguaforge_translation_languages` | filter | `(array<string,string> $languages)` | Override the AI translation target-language list (`code => English name`). Used both by the AI translation dropdown and as the intersection source for which languages the Quick Translate / meta-box UI offers. |
| `linguaforge_switcher_output` | filter | `(string $html, array $langs, array $atts)` | Customize the fully-rendered language-switcher HTML. |
| `linguaforge_required_capability` | filter | `(string $cap, string $context)`, default from the `linguaforge_required_capability` option or `'edit_posts'` | Override the WP capability required to use an AI endpoint or feature. `$context` is the feature key (e.g. `'translation'`) or endpoint slug (`'translate-chunk'`, `'revise-block'`). |

---

## Content & rewrite exclusion filters

All of these follow the same shape: receive an array of post type (or
taxonomy) slugs, return the array with your additions or removals. They
gate which post types participate in a specific LF behavior.

| Hook | Type | Signature | Default | Gates |
|---|---|---|---|---|
| `linguaforge_column_post_types` | filter | `(string[] $types)` | every public CPT except internal WP types | Which post types get the admin Lang column. |
| `linguaforge_metabox_excluded_post_types` | filter | `(string[] $types)` | seeded from the "Excluded post types" System-panel setting | Post types whose edit screens get **no** LF meta boxes at all (Language, Template, Translations, Source Footnotes). |
| `linguaforge_ai_metabox_post_types` | filter | `(string[] $types)` | every public CPT (incl. `post`, `page`) | Which post types get the AI translation meta box. |
| `linguaforge_link_fixer_post_types` | filter | `(string[] $types)` | every public CPT except WC + internal types | Which post types the link-fixer scan manages. |
| `linguaforge_secondary_query_excluded_post_types` | filter | `(string[] $types)` | `['wpcf7_contact_form']` | Post types excluded from the secondary-query `_lf_lang` meta constraint (`QueryFilter::handle_secondary_pre_get_posts()`). |
| `linguaforge_cpt_archive_excluded_post_types` | filter | `(string[] $types)` | `['product', 'product_variation']` | CPTs excluded from LF's own archive-page rewrite rules. |
| `linguaforge_cpt_single_excluded_post_types` | filter | `(string[] $types)` | `['post', 'page', 'attachment', 'product', 'product_variation']` | CPTs excluded from LF's single-post rewrite rules (hierarchical CPTs are always skipped regardless). |
| `linguaforge_permalink_excluded_post_types` | filter | `(string[] $types)` | `['product', 'product_variation']` | Post types excluded from language-prefixed permalink rewriting entirely. |
| `lf_public_taxonomy_archives_excluded` | filter | `(string[] $taxonomies)` | `[]` (on top of hardcoded WP + WC built-ins) | Additional public taxonomies excluded from LF's taxonomy-archive language handling. |
| `linguaforge_source_footnotes_excluded_post_types` | filter | `(string[] $types)` | `['product']` | Post types that don't get the Source Footnotes meta box (a Gutenberg-only, UUID-based feature). |
| `linguaforge_page_menu_excluded_page_ids` | filter | `(int[] $ids)` | seeded from `_lf_page_menu_exclude` post meta | Pages hidden from every language's `core/page-list` navigation block. No effect on classic nav menus. |
| `linguaforge_trash_cascade_post_ids` | filter | `(int[] $ids, int $post_id)` | the full translation group | The set of post IDs trashed together by the "Trash + Siblings" row action. |
| `linguaforge_backfill_post_types` | filter | `(string[] $types)` | every public post type minus WC products/variations | Post types the hourly Automatic Translation Backfill scan checks for missing-translation gaps. |
| `linguaforge_cpt_create_allowed` | filter | `(bool $allowed, string $post_type)` | `true` | Per-post-type gate on translated-post creation — return `false` to block creation for a type until its delegation layer is confirmed ready (checked by both the Backfill scan and `PostListColumn::ajax_fill_missing()`). |
| `linguaforge_seo_analysis_classic_post_types` | filter | `(string[] $types)` | `['product']` | Post types that use the classic-editor SEO Analysis meta box instead of the block-editor sidebar panel. |

---

## AI translation

`ai/includes/Features/`, `ai/includes/Providers/`, `ai/includes/REST/`,
`ai/includes/Core/`.

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `linguaforge_translation_content` | filter | `(array $payload, int $post_id, string $target_lang)` | Modify the AI translation payload before it's written to the result cache. |
| `linguaforge_translation_extra_instruction` | filter | `(string $instruction, int $post_id)`, default `''` | Insert an extra sentence into the AI system prompt, ahead of the CRITICAL JSON RULE — e.g. to leave Latin phrases untranslated. Runs for full-post translation (TM and JSON-envelope paths, since 2.6.6) and chunk translation (`ChunkTranslation::run()`, including the post-independent Admin Toolbar popover where `$post_id` is `0`; since 2.6.7). |
| `linguaforge_translated_post_meta` | filter | `(array $meta, int $source_id, string $lang, string $source_post_type)`, default `[]` | Declare post meta a programmatically-created translated post is born with (written via `meta_input` inside `create_translated_post()`). `_lf_trid`/`_lf_lang` are stripped — LF writes them authoritatively. ⚠️ WooCommerce: operational product keys (`_thumbnail_id`, `_price`, …) written on a translated *product* are shadowed by MetaDelegate; scope by `$source_post_type`. *(Since 2.4.0.)* |
| `linguaforge_template_for_lang` | filter | `(string $resolved, \WP_Post $post, string $lang)` | Override the language-specific FSE template slug LF is about to assign. Fires for every assignment path (editor save, WP-CLI, Sync button, programmatic creation). Never fires for the source-language post. Return `''`/`null` to suppress assignment entirely. *(Since 2.6.1.)* |
| `linguaforge_translation_worker_config` | filter | `(WorkerConfig $cfg, int $post_id, array $params)` | Override the AI model, temperature, or max_tokens for a specific translation call. |
| `linguaforge_ai_provider` | filter | `(AIProviderInterface $provider, int $post_id, WorkerConfig $cfg)` | Swap the AI provider instance entirely — inject a custom provider or a test stub. |
| `linguaforge_ai_should_boot` | filter | `(bool $should_boot)` | Override whether the AI module initializes for the current request at all (defaults to on for admin/REST/WP-CLI contexts, off for plain frontend requests). |
| `linguaforge_ai_retry_policy` | filter | `(array{attempts:int, delay_ms:int, jitter_ms:int, retry_statuses:int[], timeout:int} $policy, string $provider_label)`, default `attempts:2, delay_ms:1500, jitter_ms:500, retry_statuses:[429,500,502,503,504], timeout:300` | Tune the retry/backoff behavior for AI provider HTTP calls. Add/override `'timeout'` here if a translation needs longer than 300s (minimum 30s). |
| `linguaforge_ai_rate_limit` | filter | `(array{window_seconds:int, max_requests:int} $limit, string $endpoint)`, default `window_seconds:60, max_requests:30` | Per-user sliding-window rate limit for paid AI endpoints. |
| `linguaforge_ai_daily_quota` | filter | `(int $quota, string $endpoint)`, default from the `linguaforge_ai_daily_quota` option (`0` = unlimited) | Site-wide rolling daily ceiling on AI calls, protecting against the per-user limit being multiplied across many users. |
| `linguaforge_translation_memory_enabled` | filter | `(bool $enabled, int $post_id)` | Disable Translation Memory per-invocation. |
| `linguaforge_debug_dir` | filter | `(string $dir)`, default `WP_CONTENT_DIR . '/lingua-forge-debug'` | Override where AI debug files are written. Empty/non-string return falls back to the default. |
| `linguaforge_fse_template_definitions` | filter | `(array<string, array{label:string, title:string}> $defs)` | Add, remove, or rename entries in the full set of scaffold-able FSE template definitions. |
| `linguaforge_secondary_sync_allowed` | filter | `(bool $allowed)`, default from the `linguaforge_allow_secondary_sync` option (off) | Override whether "Sync" may be triggered from a secondary-language post — which would overwrite the primary post via back-translation. Applies to every post type *other than* WooCommerce products/variations (see `linguaforge_wc_secondary_sync_allowed` below). Has no effect on syncing *from* the primary post, which is always allowed. |

---

## Comment translation

`language-router/includes/comments/` (data model, mirroring, cascade — no AI
dependency), `ai/includes/Features/CommentTranslation.php`,
`ai/includes/Features/CommentTranslationQueue.php`,
`ai/includes/Admin/CommentBulkActions.php`. Off by default — see Settings →
Behavior → Comment Translation. Full design rationale:
`lingua-forge-audit/PROPOSAL-comment-translation-2026-07-29.md` (maintainer-only,
not shipped). New in 2.7.0.

Options (Settings → Behavior → Comment Translation):

| Option | Default | Purpose |
|---|---|---|
| `linguaforge_comment_translation_enabled` | `false` | Master toggle. Off by default — this feature makes AI provider requests automatically once a comment is eligible. |
| `linguaforge_comment_translation_mode` | `'manual'` | `'manual'`: translation only via the Comments-screen "Translate"/"Translate missing" actions. `'auto'`: queues a translation the moment an eligible comment is approved (or arrives already-approved). |
| `linguaforge_comment_translation_max_backfill_depth` | `2` | How many levels of nested replies "Translate missing" walks in one pass. The original top-level comment is level 0. |

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `linguaforge_comment_translation_excluded_types` | filter | `(string[] $excluded)`, default `[]` | Post types excluded from comment translation, in addition to the hard-excluded `product`/`product_variation` (WooCommerce reviews stay on `ProductReviewRouter`'s separate shared-pool model). |
| `linguaforge_comment_translation_eligible_types` | filter | `(string[] $types)`, default `['comment']` | `comment_type` values eligible for translation. `'review'` is always refused regardless of what this filter returns. |
| `linguaforge_comment_translation_complete` | action | `(int $comment_id, array{translated: string[], failed: array<string,string>} $result)` | Fires after a comment-translation attempt, whether or not every target language succeeded. |

Public API (`language-router.php`):

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_get_comment_translations( $comment_id )` | `array<string,int>` | `[ lang => comment_id ]` map for every row in the comment's mirror group — the comment-level analog of `linguaforge_get_translations()`. Works whether `$comment_id` is the canonical comment or one of its mirrors. `(Since 2.7.0.)` |

Comment meta (public data contract, same stability convention as `_lf_lang`/`_lf_trid`):

| Meta key | Scope | Purpose |
|---|---|---|
| `_lf_comment_lang` | comment meta | The comment's own written/target language. Always set on a mirror; set on a canonical comment once it's been translated at least once (or detected). |
| `_lf_comment_group_id` | comment meta | Shared by a canonical comment and every mirror of it — set once, at insertion, to the canonical comment's own ID. Deliberately **not** `_lf_trid`: that groups *posts*, and one post family hosts many independent comments, each needing its own group. |
| `_lf_comment_translation_failures` | comment meta | Per-target-language failure state (attempts/last_attempt/last_error), same shape as `_lf_translation_failures` but scoped to the comment. |

---

## SEO

`language-router/includes/seo/`. New in 2.2.0 unless noted.

| Hook | Type | Signature | Purpose |
|---|---|---|---|
| `linguaforge_seo_og_type` | filter | `(string $type)`, `'article'`\|`'website'` | Override the resolved `og:type` per page. WooCommerce integration returns `'product'` on product pages. |
| `linguaforge_seo_og_extra_tags` | action | — | Fires after the full OG + Twitter Card tag set. Use to append additional Open Graph properties (e.g. WC price/availability). |
| `linguaforge_seo_schema_extra_types` | action | `(string $lang, string $in_language)` | Fires after the built-in JSON-LD types (Article, WebSite). `$in_language` is BCP 47. Use to output additional JSON-LD types. |
| `linguaforge_seo_og_locale_map` | filter | `(array<string,string> $map)` | Override the language→Facebook-locale mapping used for `og:locale`/`og:locale:alternate`. |
| `linguaforge_seo_schema_locale_map` | filter | `(array<string,string> $map)` | Override the language→BCP47 mapping used in JSON-LD `inLanguage`. |
| `linguaforge_seo_og_image` | filter | `(string $url)` | Override the resolved OG image URL. |
| `linguaforge_seo_og_description` | filter | `(string $description)` | Override the resolved OG description. |
| `linguaforge_seo_schema_data` | filter | `(array $data, string $type)` | Modify any Schema.org JSON-LD array before encoding. `$type` is the `@type` value (Article/WebPage/WebSite/Product/…). |
| `linguaforge_seo_sitemap_slug` | filter | `(string $slug)`, default `'lf-sitemap.xml'` | Override the sitemap URL slug. |
| `linguaforge_seo_sitemap_xml` | filter | `(string $xml)` | Modify the full generated sitemap XML string before serving. |
| `linguaforge_sitemap_extra_urls` | filter | `(array $groups)`, default `[]` | Register additional URL groups the sitemap's own `_lf_trid`/`_lf_lang` query can't discover — e.g. a companion plugin's per-user/per-artist subdomains. Return an array keyed by an arbitrary group id; each value an array of rows (array or object) with `url` (required), `lang` (required), `post_modified_gmt` (optional, defaults to now). Rows in one group are emitted as hreflang alternates of each other, same as a native TRID group. A row missing `url` or `lang` is dropped rather than breaking generation for every other group. Group keys are re-namespaced internally so they can never collide with a real TRID. See `class-sitemap-manager.php`'s class docblock for the full contract. `(Since 2.7.1.)` |
| `linguaforge_seo_sslverify` | filter | `(bool $verify)`, default `true` | Disable SSL verification when the SEO Analysis panel fetches the live frontend page for heading-count analysis — useful on local/staging environments with self-signed certs. |
| `linguaforge_social_share_url` | filter | `(string $url, string $service)` | Override the resolved Social Icons share URL for a given service. |

---

## WooCommerce integration

`ai/includes/Integrations/WooCommerce/`. The reference implementation for
*any* third-party integration — see "Writing a third-party integration" in
CONTRIBUTING.md, and "Extending the delegation layer" there for how to add a
new meta key, post type, or taxonomy to delegation.

| Hook | Type | Signature | Default | Purpose |
|---|---|---|---|---|
| `linguaforge_wc_delegate_post_types` | filter | `(string[] $types)` | `['product', 'product_variation']` | Which post types participate in meta/taxonomy delegation and stock routing (translated products delegate operational data to the source product). |
| `linguaforge_wc_delegate_taxonomies` | filter | `(string[] $taxonomies)` | `['product_cat', 'product_tag', 'product_type', 'product_brand']` | Which WC taxonomy slugs are delegated (`pa_*` attribute taxonomies are handled separately by prefix match). |
| `linguaforge_wc_product_archive_taxonomies` | filter | `(string[] $taxonomies)` | `['product_cat', 'product_tag', 'product_brand']` | Which WC public taxonomies get LF's language-aware archive rewrite rules, term-link prefixing, and archive language injection. A third-party brand/attribute plugin that registers its own public taxonomy adds it here. Don't add `pa_*` or internal taxonomies (`product_type`, `product_visibility`) — they have no browsable archive. |
| `linguaforge_wc_order_item_source_mapping` | filter | `(bool $normalize, int $product_id, int $source_id, \WC_Order_Item_Product $item)` | site setting (`WcOrderLang`-based) | Enable/disable per-order-item product-ID normalization from a translated product ID back to its source. |
| `linguaforge_wc_secondary_sync_allowed` | filter | `(bool $allowed)` | from the `linguaforge_wc_allow_secondary_sync` option (off) | Same restriction as `linguaforge_secondary_sync_allowed` above, but specifically for WooCommerce `product`/`product_variation` posts. The two filters are independent — a post routes to exactly one of them by post type. |
| `linguaforge_email_order_lang` | filter | `(string $lang)`, default `''` | — | Fallback language for email/cron contexts where `LF_LANG` isn't defined, so order-received/my-account/checkout links in transactional emails resolve to the correct translated page. |

---

## Lifecycle & integration events (actions)

| Hook | Signature | Purpose |
|---|---|---|
| `linguaforge_loaded` | `(string $version)` | Fires after the router has fully booted (end of the `plugins_loaded` priority-10 boot sequence) — `LF_LANG` is defined and every `linguaforge_*`/`lf_*` wrapper function is available. **The safe attach point for third-party integrations** — see CONTRIBUTING.md. |
| `linguaforge_wc_integration_active` | — | WooCommerce integration booted successfully. |
| `linguaforge_trid_changed` | `(int $post_id, string $new_trid, string $old_trid)` | A post joined or left a translation group (fires only when the TRID UUID actually changes). |
| `linguaforge_translation_complete` | `(int $new_id, int $source_id, string $target_lang)` | A translated post was created or updated via the CLI or programmatic path (`linguaforge_trigger_translation()`, Sync, Translate-missing, WP-CLI `translate`/`fill_translations`). |
| `linguaforge_trash_cascade_complete` | `(int[] $trashed, int[] $skipped, int $post_id)` | Fires after a "Trash + Siblings" cascade run. |
| `lf_lang_column_outdated` | `(int $id)` | Fires when the admin Lang column renders an "outdated" indicator for a post. |
| `lf_lang_column_missing` | `(int $id, array $missing)` | Fires when the admin Lang column renders a "missing translations" indicator. |
| `lf_lang_column_retranslate` | `(int $id)` | Fires when the admin Lang column renders a "retranslate" action. |
| `linguaforge_seo_og_extra_tags` | — | See [SEO](#seo) above. |
| `linguaforge_seo_schema_extra_types` | `(string $lang, string $in_language)` | See [SEO](#seo) above. |

---

*Maintenance note (for contributors): when you add a new `apply_filters()`/
`do_action()` call, add a row here in the appropriate category — see
CONTRIBUTING.md's "When you add something new" checklist, item 4. This file
was reconstructed from a full source audit on 2026-07-22 after several hooks
(most with proper inline docblocks) were found to have never been added to
any reference doc — the checklist previously stopped at "document it in a
docblock," which doesn't make a hook discoverable to an integrator scanning
the docs rather than the source.*
