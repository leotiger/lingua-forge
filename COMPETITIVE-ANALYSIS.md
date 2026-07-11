# Lingua Forge — Full Market Assessment

**Competitors:** WPML · Polylang · TranslatePress · Weglot · MultilingualPress
**Scope:** Small to medium WordPress sites (1–50 editors, block/FSE themes, 2–10 languages)
**Date:** July 2026 · Lingua Forge 2.6.4

---

> **⚠ Disclaimer — AI-generated and AI-maintained document**
>
> This document is researched, written, and updated by an AI assistant (Claude). It is intended as a high-level orientation to the WordPress multilingual plugin landscape — not as a definitive or authoritative source. Competitor feature sets, pricing, and roadmaps change frequently, and AI-produced assessments can contain errors, omissions, or outdated information even when recently reviewed. Treat every claim as a starting point for your own investigation, not a conclusion. Before making purchasing, migration, or architectural decisions, verify the details directly with each vendor's current documentation and pricing pages. The §15 Sources section lists the primary references used at the time of writing.

---

## TL;DR

The WordPress multilingual plugin market splits into three distinct architectural camps: **post-based** plugins that create one post record per language per content item (WPML, Polylang, Lingua Forge, MultilingualPress); **string-replacement** plugins that intercept page output and swap strings (TranslatePress); and **cloud-proxy SaaS** that stores translations externally and serves them via CDN (Weglot). Each architecture has real trade-offs, and the best choice depends more on site architecture than on feature lists.

Lingua Forge sits in the post-based camp alongside WPML and Polylang, but diverges from them in one important way: translations are native WordPress posts with no extra storage layer, no custom indexing tables, and no recomposition step — where WPML and Polylang add their own `icl_*` and `pll_*` table structures on top. Beyond architecture, Lingua Forge differentiates on four axes: zero licensing cost, FSE-native design, a materially deeper AI editorial toolset than any competitor ships natively, and direct AI provider access with no intermediary markup. AI use is entirely optional — manual translation costs nothing to run. When AI is used, the cost is your provider's published API rate with no markup, making the total three-year cost an order of magnitude lower than WPML or Weglot — whether you run a blog, a business site, or a WooCommerce store.

---

## 1. Pricing at a Glance

> **⚠ Prices are approximate and verified as of June 2026.** Competitor pricing changes frequently — always check the vendor's current pricing page before quoting or recommending. Renewal discounts (≈ 50 % for WPML, Polylang, TranslatePress) are not reflected in the first-year list prices.

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| **Free tier** | ✅ Full feature set, no expiry | ❌ | ✅ Limited (no FSE template translation, no hreflang) | ✅ 1 language | ✅ 1 language, < 2 000 words total | ✅ Free open-source core (Multisite-based) |
| **Entry paid plan** | — | €39/yr (Multilingual Blog) | €99/yr (Pro, 1 site) | €99/yr (Personal, 1 site) | ≈ €149/yr (Starter) | Pro add-ons / support |
| **Mid plan** | — | €99/yr (CMS, 3 sites) | €139/yr (Business Pack, Pro + WooCommerce, 1 site) | €199/yr (Business, 3 sites) | ≈ €276/yr (Business) | Scales by site count |
| **Agency / unlimited** | — | €199/yr (Agency, unlimited sites) | — | €349/yr (Developer, unlimited) | ≈ €758–€2 868/yr (Pro/Advanced) | Custom |
| **AI / auto-translation cost** | Your API key, provider rates (~€0.002–€0.01 per 1 000 tokens) | WPML Credits: 2 000 free/mo then top-up; CMS includes ~90 000 credits/yr, Agency ~180 000 | DeepL or Google subscription (separate) | Included AI words per plan (Personal 50 k · Business 200 k · Developer 500 k /yr; top-ups 100 k = €24, 200 k = €40) | Included (machine translation, then billed by word count) | DeepL / GPT-4 / Google via AutoTranslate (API key, provider rates) |
| **WooCommerce** | ✅ Full variable product translation + commerce lifecycle (2.3.0) — shared-stock delegation (price, SKU, stock, images), translatable variation descriptions, translated attribute term names in block + classic themes, product_brand delegation, REST write guard, order-language capture, locale-switched transactional emails, cross-language coupons, consolidated sales/reviews, HPOS-compatible | Add-on (WCML; bundled in Agency) | Separate add-on (Business Pack) | ✅ included | ✅ included (cloud handles dynamic content) | ✅ included (per-store) |
| **True zero-cost path** | ✅ Manual translation — no API key, no limit | ❌ Annual license required | ❌ Pro required for FSE template translation/hreflang | ✅ Manual translation free | ❌ Word count limit on free tier | ✅ Free open-source core (Multisite required) |
| **WordPress.org listing** | ❌ Self-hosted only (name rejected for the .org directory) | ❌ Not listed (commercial only) | ✅ Free tier listed | ✅ Free tier listed | ✅ Connector plugin listed | ⚠ v4 open-source on GitHub; v2 retired early 2025 |

### Three-year cost model — single site, ~200 posts, 3 languages, moderate AI use

> **Renewal note:** WPML, Polylang, and TranslatePress all renew at roughly **50 % off**
> after year 1, so their real 3-year spend is closer to *list + 2×(½ list)* ≈ 2× list,
> not 3× list. The figures below show list × 3 for the "no-discount" worst case and the
> discounted figure alongside; Weglot is a subscription with no comparable renewal cut.

| | 3-year license (list ×3) | 3-year with ~50 % renewals | AI / translation cost | Total (discounted) |
|---|---|---|---|---|
| **Lingua Forge** | €0 | €0 | ~€5–15 API usage | **< €15** |
| **WPML CMS** | €297 | ~€198 | 2 000 credits/mo free, then top-up | **~€200 +** |
| **Polylang Pro** | €297 | ~€198 | Separate DeepL subscription | **~€200 +** |
| **TranslatePress Personal** | €297 | ~€198 | Included quota (50 k AI words/yr) | **~€198** |
| **Weglot Starter** | €447 | €447 (no renewal discount) | Included (< 2 000-word free limit — likely need Business at €828) | **€447–€828** |
| **MultilingualPress** | €0 (free core) → Pro | — | Provider API keys at cost | **€0 + (Multisite hosting overhead)** |

---

## 2. Architectural Philosophy

Understanding how each plugin manages translations is more important than any feature checkbox — it determines what you can and cannot do without migrating to a different plugin later.

### Post-based (separate DB records per language)

**WPML, Polylang, Lingua Forge, MultilingualPress** all create a distinct post (or site) for each language. The original content is never overwritten. Editors work inside the standard WP admin — opening and editing translation posts just like any other post. Search, archives, and queries all work natively because WordPress is just serving a normal post.

Lingua Forge uses a UUID (TRID) shared across language posts to link them. WPML and Polylang use their own translation-group tables. MultilingualPress uses WordPress Multisite — each language gets its own WP site within a network, which completely separates databases.

**Strengths:** Native WP queries, no page-load overhead, translations survive plugin deactivation, no external dependency.
**Trade-offs:** More DB rows; adding a new language to existing content requires a deliberate migration or CLI command.

### String-replacement (front-end interception)

**TranslatePress** works differently: it intercepts the full page output (after WordPress renders it) and replaces strings with stored translations. There are no "translation posts" — a translated page is not a post, it is the same post with strings swapped at render time. The visual editor lets translators click directly on any text on the live front-end and type the replacement inline.

**Strengths:** Works with any plugin or theme output including dynamic JavaScript-rendered content; visual editor is intuitive for non-technical translators; no content duplication.
**Trade-offs:** Adds a render-time processing step (≈ 1 s overhead measured); requires a parallel string storage layer outside the WordPress content model; string-matching can behave unexpectedly when the same string appears in different contexts; translated content does not survive plugin removal.

### Cloud SaaS / proxy

**Weglot** takes a fundamentally different approach: it acts as a translation layer hosted on Weglot's infrastructure. Your content is sent to Weglot's servers, machine-translated, stored in their cloud, and served via CDN. You don't store translations in your WP database at all.

**Strengths:** Fast setup (under 5 minutes); handles JS-rendered content and WooCommerce dynamic elements natively; no plugin conflicts; no WP database burden.
**Trade-offs:** All your content lives in a third-party cloud (GDPR implications to assess); pricing scales with word count rather than time — a large site can hit €2 000 +/year at the Pro tier; removing Weglot means losing all translation data unless you export first; completely dependent on Weglot's uptime and pricing policy.

---

## 3. Core Multilingual Routing

| Feature | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| URL prefixes (`/de/`, `/fr/`) | ✅ | ✅ | ✅ | ✅ | ✅ (or subdomain / separate domain) | ✅ (one domain per language, or subdirectories) |
| Subdomains (`de.site.com`) | ✅ (1.7.0, selectable in Settings → Router) | ✅ | ✅ Pro | ✅ | ✅ | ✅ (native per site) |
| Separate domain per language | ❌ | ✅ | ✅ Pro | ⚠ Business+ add-on ("Different Domain per Language") | ✅ | ✅ (native per site) |
| Translation groups (linked posts) | ✅ TRID / UUID | ✅ | ✅ | N/A (string-based) | N/A (cloud-based) | ✅ (cross-site relationships) |
| Outdated translation tracking | ✅ ⚠ indicator + one-click Retranslate button with source-language selector in the post list (1.8.2) | ✅ dashboard | ✅ Pro | ✅ (visual indicator) | ❌ | ⚠ dashboard widget (incomplete) |
| Cookie / query-param detection | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Browser auto-redirect | ✅ opt-in (Accept-Language header) | ✅ | ✅ Pro | ✅ Business | ✅ | ✅ |
| Language Switcher block (FSE) + shortcode + classic widget | ✅ | ✅ built-in (CMS/Agency plan) | ✅ Pro | ✅ | ✅ (widget/block) | ✅ |
| Language-specific FSE templates | ✅ (`page-de`, `single-fr`) with full in-plugin scaffold + AI-translate + fix workflow | ❌ (known open issues as of 2026) | ❌ Pro (translates template parts, not template entities — no `page-de`) | ❌ | ❌ | ❌ |
| FSE template part localisation (scaffold + AI-translate + fix) | ✅ (`header-de`, `footer-ca`, …); Fix Nav rewrites navigation refs per part | ❌ | ⚠ Pro (template parts only, no fix workflow) | ❌ | ❌ | ❌ |
| Navigation menu localisation (AI-translate + lang-copy) | ✅ per-language `wp_navigation` posts with AI-translated labels and URL fixing | ❌ | ✅ Pro (manual) | ✅ (string-intercept) | ✅ (cloud) | ✅ |
| Admin link fixer (repairs cross-language internal links) | ✅ | ⚠ Translate Link Targets scan (Settings); Sticky Links add-on | ❌ | ❌ | ❌ | ❌ |
| WP-CLI support | ✅ 5 commands | ⚠ import/export only | ⚠ Pro (native since 3.8) | ❌ | ❌ | ⚠ language assignment + AutoTranslate |

---

## 4. SEO

> **Note (June 2026):** WPML's SEO features are delivered by the WPML SEO component (separate install; v2.2.x). Polylang Pro automatically adds hreflang and Open Graph tags and is designed to work alongside Yoast or Rank Math. TranslatePress provides hreflang, sitemaps, and OG/Twitter via the SEO Pack add-on. Weglot auto-manages hreflang and sitemaps (subdirectory mode); it does not output `x-default` by default.

| Feature | Lingua Forge 2.6.4 | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| hreflang output | ✅ (singular, archive, paginated, x-default; BCP 47 normalised) | ✅ WPML SEO component; hreflang now also in sitemaps since WPML SEO 2.2.x | ✅ Pro (auto-added) | ✅ SEO Pack add-on | ✅ auto (no x-default by default) | ✅ |
| Self-referencing canonical aligned with hreflang | ✅ native (2.2.16); defers to detected SEO plugin | Via Yoast/Rank Math | Via SEO plugin | Via SEO Pack / SEO plugin | ⚠ cloud-managed | Via SEO plugin |
| Auto-suppresses duplicate hreflang from Yoast / Rank Math / AIOSEO / SEOPress | ✅ automatic via filter; Compatibility tab shows live status | WPML SEO handles co-existence with Yoast/Rank Math via its own integration layer | Works alongside SEO plugin; some manual filter configuration | ❌ relies on SEO plugin | ❌ | ❌ |
| Open Graph + og:locale + og:locale:alternate | ✅ native; og:locale always emitted; full OG set in auto/full mode; defers to detected SEO plugin in auto mode to avoid duplicate tags | Via Yoast/Rank Math; WPML SEO ensures translated OG title/description matches language | ✅ Pro (auto-added alongside SEO plugin) | ✅ SEO Pack (OG + Twitter Cards for translated content) | ✅ auto-translated OG from cloud | Via SEO plugin per site |
| Schema.org JSON-LD with `inLanguage` annotations | ✅ native — Article/WebPage, WebSite, Product (WC), BreadcrumbList (2.3.0); includes `inLanguage` BCP 47; defers entirely to Yoast/Rank Math to prevent conflicting JSON-LD graphs | Via Yoast/Rank Math; WPML SEO translates schema title/description fields; no `inLanguage` field | Via SEO plugin; no multilingual `inLanguage` | Via SEO plugin / SEO Pack (partial) | ❌ cloud handles rendered output but no structured data control | Via SEO plugin per site |
| Language-aware BreadcrumbList JSON-LD | ✅ native (2.3.0) — language-prefixed crumb URLs across post/page/CPT/taxonomy chains | Via SEO plugin (not language-aware) | Via SEO plugin | Via SEO plugin | ❌ | Via SEO plugin |
| XML sitemap with hreflang alternates | ✅ native `/lf-sitemap.xml` — sitemap-index + chunked sub-sitemaps (2.3.0, 50k-URL safe) with `xmlns:xhtml` + `xhtml:link` alternates per translation group; announced in `robots.txt`; robots.txt management panel | ✅ WPML SEO multilingual sitemaps with hreflang in sitemap (SEO 2.2.x); improved sitemap rendering performance | Via Yoast/Rank Math with Polylang integration | ✅ SEO Pack multilingual sitemaps | ✅ auto (subdirectory mode); multilingual sitemap managed by Weglot | Via SEO plugin per sub-site |
| IndexNow auto-submission (all language versions) | ✅ native (2.2.16) — key file + auto-submit of the post and every TRID sibling to Bing/Yandex/Seznam/Naver; manual batch submit | ❌ (rely on a separate SEO/IndexNow plugin) | ❌ (separate SEO plugin) | ❌ (separate SEO plugin) | ❌ | ❌ |
| Per-language `noindex` | ✅ native (2.2.16) — `_lf_noindex` per language version | Via SEO plugin (per post) | Via SEO plugin | Via SEO plugin | ⚠ cloud | Via SEO plugin |
| Social Share (share: URL rewriting for Social Icons block) | ✅ native — Facebook, X, LinkedIn, WhatsApp, Telegram, Email, Reddit, Pinterest, Mastodon; copy/native/auto JS actions with clipboard and Web Share API | ❌ | ❌ | ❌ | ❌ | ❌ |
| AI-powered SEO content analysis | ✅ native — rule-based score (0–100) with title length, meta description, word count, heading structure, image alt coverage, internal links; AI recommendations (summary, improvements, title/meta suggestions) in block editor `PluginDocumentSettingPanel` | ❌ relies on Yoast/Rank Math for content analysis | ❌ relies on Yoast/Rank Math | ❌ | ❌ | ❌ |
| WooCommerce product OG (`og:type=product`, price, availability) | ✅ native — `og:type=product`, `og:price:amount`, `og:price:currency`, `og:availability`, `product:*` namespace equivalents | Via Yoast/Rank Math + WooCommerce | Via SEO plugin + WooCommerce | Via SEO plugin + SEO Pack | ✅ cloud auto-translates product pages | Via SEO plugin per sub-site |
| SEO plugin co-existence management | ✅ Compatibility tab shows live detection + per-feature behaviour; no config needed | ✅ WPML SEO designed for Yoast/Rank Math co-existence; recent 2.2.5 improved RankMath integration | Works with major SEO plugins; some manual filter config needed | ✅ SEO Pack works with Yoast/Rank Math/SEOPress/AIOSEO | Works alongside site SEO plugins | Works alongside per-site SEO plugin |
| Native meta description field | ✅ all public post types; character counter with colour guidance | Via SEO plugin | Via SEO plugin | ✅ SEO Pack | ✅ auto-translated | Via SEO plugin |
| AI meta description generator | ✅ language-aware, 140–160 chars, per post per language | ❌ | ❌ | ❌ | ❌ | ❌ |
| No additional SEO plugin required | ✅ complete multilingual SEO layer (hreflang, OG, schema, sitemap, analysis) | ❌ WPML SEO component + Yoast/Rank Math recommended for content analysis | ❌ Pro + SEO plugin for full coverage | ❌ SEO Pack add-on | ⚠ basic SEO auto-handled; no content analysis | ❌ SEO plugin per sub-site |
| Translated slugs / permalinks | ✅ slug editable independently of title per language | ✅ | ✅ Pro | ✅ | ✅ | ✅ |

---

## 5. Translation Approach and AI / Auto-translation

| Feature | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| AI provider(s) | Claude, OpenAI, Gemini (your key) + **WordPress 7.0 core AI Client** (2.3.0) | DeepL via WPML Credits | DeepL / Google (separate subscription) | DeepL, Google Translate, GPT, Gemini (combined NMT + LLM engine) | DeepL, Google, Microsoft (cloud-managed) | DeepL, GPT-4, Google (your API key) |
| WordPress 7.0 core AI Client provider (keys via Settings → Connectors) | ✅ early adopter (2.3.0) | ❌ | ❌ | ❌ | ❌ | ❌ |
| Manual translation with zero AI cost | ✅ | ✅ | ✅ | ✅ | ✅ (free tier limited) | ✅ |
| Block markup preservation during translation | ✅ | ✅ | ✅ Pro | ✅ | ✅ (cloud-based) | ✅ |
| Block attribute translation (alt text, accordions, labels) | ✅ | ✅ | Partial | ✅ (string-intercept catches most) | ✅ | Partial |
| Translation on complete post object and block level | ✅ both granularities | ✅ post-level | ✅ post-level | ✅ string-level | ✅ string-level | ✅ post-level |
| Block-level refinement and rewrite | ✅ granular, cost-aware — target exactly what needs work | ❌ | ❌ | ❌ | ❌ | ❌ |
| Full post content generation | ✅ dedicated overlay with iterative refinement | ❌ | ❌ | ❌ | ❌ | ❌ |
| Translation Memory | ✅ block-level | ✅ segment-level | ❌ | ✅ | ✅ Business+ | ✅ |
| Terminology Glossary | ✅ per language-pair | ✅ | ❌ | ❌ | ✅ (translation rules) | ❌ |
| Front-end block-level translation overlay | ✅ native blocks — no string indexing or recomposition layer required | ❌ | ❌ | ✅ string-interception (parallel storage + reassembly layer) | ✅ cloud proxy | ❌ |
| Side-by-side diff preview (before / after) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Block flagging (needs review / needs editing) from diff view | 🔜 Coming soon | ❌ | ❌ | ❌ | ❌ | ❌ |
| AI Behavior Presets (temperature + system prompt tuning) | ✅ 4 presets | ❌ | ❌ | ❌ | ❌ | ❌ |
| AI Usage tracking (tokens / feature / date) | ✅ | ❌ | ❌ | Word quota shown in dashboard | Weglot dashboard | ❌ |
| API key encryption | ✅ AES-256-GCM with versioned envelope | N/A (WPML manages) | N/A | N/A (site credentials) | N/A (SaaS) | Site credentials |
| Translator role | 🔜 Coming soon | ✅ | ✅ Pro | ✅ Business | ✅ Pro | ❌ |
| Agency / CAT tool integration (XLIFF) | 📣 On request | ✅ | ✅ Pro | ❌ | ✅ (export) | ❌ |

---

## 6. AI Editorial Tools (beyond translation)

This is where Lingua Forge is uniquely differentiated. No competitor ships these capabilities natively.

| Feature | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| Content Generator (full post — draft from hints + tone, iterative refinement) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Meta description generator (language-aware, 140–160 chars) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Excerpt generator | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Quick Translate (admin toolbar + editor toolbar popover) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Block-level translate / revise / rewrite with footnote support | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Chunk translate (paste any snippet, translate in isolation) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Translate missing — post-list one-click (no editor required) | ✅ source posts with missing language versions show a "Translate missing" button in the Lang column — fires all missing AI translations and resolves ⭕ indicators inline (1.8.1) | ❌ | ❌ | ❌ | ❌ | ❌ |
| Retranslate outdated — post-list with source-language selector | ✅ target posts with ⚠ outdated indicator show a "From [lang]" dropdown + Retranslate button in the Lang column — clears cache, reruns AI translation, resets outdated flag, regenerates meta description (1.8.2) | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 7. FSE / Block Theme Compatibility

This was a hard gap in most plugins as recently as 2024. The landscape has improved but unevenly.

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| Site Editor (FSE) support | ✅ designed for it | ⚠ retrofit; open errata: custom FSE templates not applied to translated pages in some cases | ✅ Pro (template parts + patterns; full template entities not supported); language switcher blocks free since 3.8 | ✅ string-intercept works on FSE output; language switcher has known FSE integration issues | ✅ (cloud catches rendered output) | ✅ (each language is a separate site; FSE works natively per site) |
| Language-specific FSE templates (`page-de`) | ✅ auto-assigned; scaffold + AI-translate + fix-links + fix-parts in Settings → Router | ❌ | ❌ free / ❌ Pro (translates template part content in-place; no separate `header-de` entity approach) | ❌ | ❌ | N/A (each language is its own WP site) |
| Language-specific template parts (`header-ca`) | ✅ scaffold + AI-translate + fix-links + fix-nav-refs; Fix Nav rewrites `wp:navigation` ref IDs | ❌ | ✅ Pro (translate in Site Editor; no nav ref fix workflow) | ❌ | ❌ | N/A (each language is its own WP site) |
| Language navigation menus (wp_navigation) | ✅ AI-translate labels, fix internal URLs, create `{name}-{lang}` copies | ❌ (errata: not possible to add language switcher to Navigation Block) | ✅ Pro (manual) | ✅ (string-intercept) | ✅ (cloud) | ✅ (per-site navigation) |
| Language Switcher as a block | ✅ | ✅ built-in (CMS/Agency plan); known issue: cannot be inserted into core Navigation Block | ✅ free since 3.8 (language switcher blocks moved from Pro to free) | ⚠ block exists; known FSE issues — renders as dropdown in Navigation Block, not inline links | ✅ (floating/CSS switcher; works on all themes) | ✅ (per-site) |
| Block attribute translation (JSON inside block comments) | ✅ custom extractor | ✅ | Partial (Pro) | ✅ (string-intercept catches rendered output) | ✅ (cloud catches all output) | Partial (manual per-site editing) |

---

## 8. Setup and Theme Integration Experience

Feature tables show what a plugin can do — not what it costs in actual effort to get there. For block themes especially, the gap between "feature listed" and "what you actually have to do" is significant and varies widely across plugins.

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| **Install → routing live** | Install + Settings → Languages + Settings → Router (2 screens) | Install + setup wizard + String Translation add-on install (CMS plan minimum for FSE) | Install + Languages screen (clean; FSE requires Pro) | Install + Settings → TranslatePress → add language | Install + enter API key — automatic translation + switcher live immediately | WordPress Multisite prerequisite first; then network-activate, create per-language sites, configure relationships |
| **Language switcher in header/nav** | Add the Language Switcher block anywhere in Site Editor — standard block insertion | WPML Switcher block available; known errata: cannot be inserted directly into the Navigation Block; if no Navigation Block is present, styles may break requiring custom PHP | Switcher block available (free since 3.8); requires inserting a regular block into the Navigation Block first before the switcher becomes insertable (WordPress quirk) | Floating switcher auto-injected on activation; can also be placed as a block, widget, shortcode, or menu item — most flexible placement of any plugin | Floating switcher auto-injected; appearance configurable in Weglot's visual Switcher Editor with no code | Language Switcher widget or Gutenberg Menu Block; must be configured on each sub-site independently (or copied via "Copy Navigation Menu" feature) |
| **Navigation menus per language** | Settings → Router: scaffold a language copy of any `wp_navigation`, AI-translate labels in one click, fix internal URLs to correct language — unified workflow | Translation Dashboard → create job → assign to self → complete in translation editor; adding language switcher to Navigation Block is unsupported (open errata) | Pro only; translate labels manually in the menu editor; no AI assist, no automated URL fix | Labels translated automatically via string interception; no separate copy step required | Labels auto-translated as part of all site content; no manual step | Copy menu from source site using "Copy Navigation Menu"; translate labels per sub-site individually |
| **FSE template / template part localisation** | Scaffold language variant, AI-translate, fix internal links, fix `wp:navigation` ref IDs — all from Settings → Router in one workflow | String Translation add-on required; WPML Translation Dashboard job workflow; open errata: custom FSE templates may not be applied correctly to translated pages in some configurations | Pro only; translate content manually in Site Editor; no scaffold, no AI assist, no nav-ref fix workflow; full template entities not supported | N/A — string interception operates on rendered output; no per-template differentiation | N/A — cloud proxy catches all rendered output | N/A — each language is its own WP site; templates are configured independently per site |
| **Add-ons / plan required for full FSE** | None — full workflow in the free core plugin | String Translation add-on + CMS plan (€99/yr minimum) | Pro plan (€99/yr) | None (free tier works; string-intercept is theme-agnostic) | None (cloud model is theme-agnostic; free tier limited to 1 language / 2 000 words) | None beyond base license — but Multisite is required regardless |
| **Estimated time to first working multilingual front-end** | FSE full workflow (templates + nav + switcher): 1–2 h · Classic theme: routing + translation live in ~30 min, but switcher placement requires a block widget area or custom code | Classic theme: 1–2 h · FSE: several hours + likely troubleshooting | Classic (Pro): ~30 min · FSE (Pro): 1–2 h | ~15–30 min (install + visual editor pass on key pages) | ~5 min (auto-translation + auto-switcher; content control comes later) | 2–4 h minimum, assuming Multisite is already in place |
| **Classic theme support** | ✅ Full support — routing, translation, hreflang, AI tools, WP-CLI, language switcher via block (FSE widget areas), `[lsflr_switcher]` shortcode, or classic `WP_Widget` (Appearance → Widgets) | ✅ Full support; menu-based switcher works in Appearance → Menus | ✅ Full support (free + Pro) | ✅ Full support (string-intercept is theme-agnostic; switcher via widget/shortcode/menu) | ✅ Full support (cloud catches all output; floating switcher works on any theme) | ✅ Full support per sub-site |

### Notes

**Weglot** has the lowest setup barrier by a wide margin — install, connect an API key, done. The trade-off is structural: no control over templates or navigation, all content lives in Weglot's cloud, and pricing compounds steeply at scale. For a team that needs multilingual quickly with no FSE complexity, it is the fastest path; for a team that needs editorial control, it is not.

**TranslatePress** is the second-fastest initial setup and the most accessible for non-technical translators, who can click any text on the live front-end and type a translation inline. Because string interception operates on rendered output, no template or navigation work is required. The cost is the string-interception model: render-time overhead, parallel string storage with no per-template context, and no structural differentiation between languages.

**Polylang free** is clean for classic themes but essentially unusable for FSE — template parts, hreflang output, and the language switcher block all require Pro. With Pro, the experience is reasonable for teams comfortable with manual work: no scaffold, no AI assist, no automated link or nav-ref fixing. The Pro language switcher works in FSE headers once you navigate a WordPress quirk in the Navigation Block.

**WPML** has a structured onboarding wizard and is predictable on classic themes. On FSE themes it accumulates complexity quickly: the String Translation add-on is required for template strings, the language switcher has a documented errata preventing direct insertion into the Navigation Block, and FSE template application has open known issues that can require multi-step workarounds. Independent reviewer analysis from 2025 consistently describes WPML + FSE as a pairing that demands patience and troubleshooting time.

**Lingua Forge** is designed for FSE/block themes and that is where its setup workflow shines — templates, template parts, navigation menus, internal links, and `wp:navigation` ref IDs all handled from a single Settings screen, with no add-ons, no CLI, and no manual database work. On classic themes, routing, post/page translation, hreflang, AI tools, and WP-CLI all work without restriction. The language switcher is available in three forms: as a Gutenberg block (placeable in any block-based widget area), as a `[lsflr_switcher]` shortcode (usable in any theme that supports shortcodes — virtually all classic themes), and as a native `WP_Widget` subclass (Appearance → Widgets). All three delegate to the same `render_switcher()` method and produce identical output.

**MultilingualPress** carries the highest upfront cost of all: WordPress Multisite is a non-negotiable prerequisite. For teams already operating a Multisite network the experience normalises after initial configuration. For a team not on Multisite, the migration and per-site configuration overhead is substantial.

---

## 9. WooCommerce Multilingual

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| Product / variation / category translation | ✅ Full — shared-stock model (title, description, price, stock, images, variations with translatable descriptions, categories, attribute term names in block + classic themes, product_brand) | ✅ (add-on; requires CMS plan or higher) | ✅ paid add-on | ✅ included | ✅ (cloud, including JS-rendered cart/checkout) | ✅ included |
| WooCommerce UI string translation (cart labels, notices, button text) | ✅ WooCommerce language packs auto-downloaded by WordPress when a language is added; Loco Translate only needed for custom string overrides | ✅ | ✅ | ✅ (string-intercept) | ✅ (cloud) | ✅ |
| Order language captured at checkout | ✅ (2.3.0) `_lf_order_lang` | ✅ WCML | ✅ Polylang-for-WC | ⚠ string-level | ⚠ cloud | ✅ per-site |
| Transactional emails in customer's language | ✅ (2.3.0) locale-switched (confirmation, processing, completed, refunded, customer-note) | ✅ WCML (+ String Translation) | ✅ Polylang-for-WC | ⚠ | ⚠ | ✅ per-site |
| Coupon product/category restrictions across languages | ✅ (2.3.0) TRID-mapped | ✅ WCML | ⚠ | ⚠ | ⚠ | ⚠ |
| Best-Sellers / Analytics consolidated per product | ✅ (2.3.0) order-item normalisation | ✅ WCML (shared product) | ❌ | ❌ | ❌ | ❌ |
| Shared review pool across languages | ✅ (2.3.0) | ✅ WCML | ❌ | ❌ | ❌ | ❌ |
| HPOS compatibility declared | ✅ (2.2.16) | ✅ WCML | ⚠ varies | ⚠ varies | N/A (cloud) | ⚠ varies |
| Multi-currency | Via WooCommerce / 3rd-party | ✅ (WPML Multi-Currency add-on — flagship) | Via WooCommerce / 3rd-party | ❌ | ❌ | ✅ (separate store per language) |

**Assessment (v2.3.1):** With the 2.3.0 commerce-lifecycle work, Lingua Forge now covers
the multilingual *shop* lifecycle for a single-currency store end-to-end — not just the
catalogue. Order language is captured at checkout and customer-facing transactional emails
are rendered in that language; coupons honour all language versions of a restricted product;
sales counts and product reviews are consolidated per product rather than fragmenting per
language; and the plugin declares HPOS + Cart/Checkout-Blocks compatibility so it no longer
appears as "Incompatible" on WooCommerce's feature screen. **In fairness, order-language
emails, cross-language coupons, and shared reviews are *parity* with WCML and
Polylang-for-WooCommerce, not a Lingua Forge invention** — these have existed in the paid
stacks for years (WCML with a documented history of email-language bugs). What is distinct is
that LF delivers them at zero licence cost on top of the shared-stock delegation model. **WCML
remains ahead on multi-currency (its flagship) and localized payment gateways, and
MultilingualPress on multi-store** — both deliberate non-goals for Lingua Forge.

Underneath the lifecycle layer, Lingua Forge supports the full WooCommerce variable product stack:

- **Translated product variations** — `product_variation` children are created automatically on translated parent products, TRID-linked to source variations. `_variation_description` (the per-variation description field) is translatable via the standard Retranslate button. Attribute assignments (`attribute_pa_color = 'red'`) are copied so WooCommerce's variation matching (`find_matching_product_variation()`) works correctly.
- **Operational data delegation** — price, SKU, stock, dimensions, and images are served transparently from source variations at runtime via `get_post_metadata` interception (both individual and bulk reads, covering WC's `read_product_data()` path). No meta copying, no SKU uniqueness issues, no stock sync complexity.
- **Structural taxonomy inheritance** — translated products have `product_type = variable`, `pa_*` attribute term assignments, and `product_brand` written directly in the DB at creation time and re-synced when the source is saved. Type changes (simple ↔ variable) propagate instantly to all translations.
- **Attribute term name translation** — `pa_*` attribute term names (Red/Blue → Rot/Blau on DE pages, Vermell/Blau on CA pages) are translated in all rendering paths: WC block themes (Store API JSON / React), classic PHP templates, and the admin. Language is detected from the queried product's `_lf_lang` postmeta since WC product pages carry no URL language prefix.
- **Product brand** — native WC 10.x `product_brand` taxonomy delegated by default; third-party brands (`pwb-brand`, YITH, etc.) registerable via the `linguaforge_wc_delegate_taxonomies` filter.
- **REST write guard** — PUT/PATCH to translated products or variations returns HTTP 422 with the source product ID, preventing external integrations from corrupting translated posts.

WooCommerce ships official language packs for all major languages; WordPress downloads them automatically when a language is added to the site — no companion plugin required. The block-based cart, mini cart, and checkout use `@wordpress/i18n` and load those translations via `wp_set_script_translations()`, so all UI strings localise correctly out of the box. Custom string overrides can be managed with Loco Translate if needed. Product data in the cart (prices, stock, variation names, attribute term names) is served through the Store API with LF's language context already applied. Multi-currency is not a translation-plugin concern.

---

## 10. Performance and Architecture

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| Translation storage | WP post table + postmeta | WP post table + `icl_*` tables | WP post table + `pll_*` tables | `trp_*` string tables | Weglot cloud (external) | Separate WP site per language |
| Extra storage / indexing layer | None — translations are native WP posts | Moderate (`icl_*` metadata joins) | Low (`pll_*` tables) | Yes — parallel string tables + indexing + recomposition on render | Yes — Weglot cloud | None per site |
| Page load overhead (typical) | Minimal — routing is URL-based (zero routing queries); hreflang TRID lookup is a single direct SQL query cached in WP object cache for 1 h; archive/home queries use a meta_query JOIN on the existing WP_Query, not a standalone extra query | Low–moderate (extra queries per page via `icl_*` joins) | Low (optimised `pll_*` table queries) | Moderate — render-interception adds measurable overhead; impact varies significantly by page complexity and hosting environment | Minimal per cached page (content served from CDN); proxy layer adds latency on first translation or cache miss | Minimal per site (separate DB, zero shared queries) |
| DB query overhead | Low — 0 extra queries on routing; 0–1 on singular pages (TRID lookup, object-cache backed after first hit); 0 standalone queries on archives | Moderate (`icl_*` metadata joins on every request) | Low (`pll_*` table joins) | Moderate (string lookup and replacement per render) | None server-side (cloud) | None per site |
| Content survives plugin removal | ✅ (posts remain) | ✅ (posts remain, tables orphaned) | ✅ (posts remain) | ⚠ Strings deleted with plugin | ❌ Data in Weglot cloud | ✅ (each site is independent) |
| Offline / no-internet capable | ✅ | ✅ | ✅ | ✅ | ❌ (cloud-dependent) | ✅ |

---

## 11. Developer and Operator Experience

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| WP-CLI commands | ✅ 6 commands (translate, retranslate, fill_translations, missing_translations, cache_clear, fix_nav_lang) — shipped natively; more underway | ⚠ `wp wpml import process` (export/import only; no language management) | ⚠ Pro: native `wp pll language` + `wp pll setting` since 3.8 (Feb 2026); free tier: unofficial community package only | ❌ | ❌ | ⚠ language assignment to subsites + AutoTranslate trigger; multisite-scoped |
| Public PHP API | ✅ (`linguaforge_*` wrapper functions) | ✅ (`wpml_*` filters/functions) | ✅ (`pll_*` functions) | Limited (hooks only) | Limited (REST API) | ✅ (MLP API) |
| WordPress Multisite required | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (prerequisite) |
| Third-party plugin ecosystem dependency | None | Large (WPML-specific APIs widespread) | Moderate | Low | Low | Low |
| Language Overrides (.mo upload, survives updates) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| API key in wp-config.php constant | ✅ | N/A | N/A | N/A | N/A | ✅ (provider keys) |
| Safe uninstall default | ✅ language assignments and TM kept by default; opt-in full removal toggle | ✅ | ✅ | ⚠ string tables deleted | ❌ data in cloud | ✅ |
| Lock-in risk if you switch away | Low (standard posts, TRID in postmeta) | Medium (many plugins use WPML APIs) | Low | Medium (string tables) | High (content in Weglot cloud) | Low (standard WP sites) |

---

## 12. Lingua Forge Strengths

### No subscription, no paywalls, no surprise bills

Every competitor in this list charges an annual fee. WPML requires an active license for updates and support. Polylang's free tier lacks hreflang and FSE template translation. TranslatePress free limits you to one language with a small word quota. Weglot's pricing climbs steeply as word count grows — a mid-sized site with 20 000+ words across three languages can easily exceed €500/year. MultilingualPress starts at $99/year with no free tier at all. Lingua Forge ships every feature in a single GPL package with no tiers, no feature walls, and no expiry.

### Native WordPress content model — no storage or indexing overhead

Translations are native WordPress posts. Blocks are blocks. There is no parallel string storage layer, no content indexing step, and no recomposition pass at render time. String-interception tools (TranslatePress) maintain separate `trp_*` string tables that require indexing and reassembly on every page load. Cloud tools (Weglot) store your content externally. Lingua Forge has none of that overhead: translation operates directly on WordPress blocks using the same data structures WordPress itself uses, with no extra persistence logic.

### Translation and generation at post level, refinement and rewrite at block level

Lingua Forge translates the complete post object in one pass, and generates full post content through a dedicated overlay with iterative multi-turn refinement. At block level, individual blocks can be re-translated, refined, or rewritten independently — targeting exactly what needs work without re-processing the whole post. This granularity is deliberate: block-level refinement is cost-aware, precise, and faster. No competitor offers this combination.

### AI is optional, not metered through an intermediary

WPML routes auto-translation through WPML Credits, a proprietary token system layered on top of DeepL. Once the 2 000 free credits per month are exhausted, top-ups are required. Weglot stores translations in their cloud and bills based on total word count — pricing that compounds as your site grows. Lingua Forge connects directly to Anthropic, OpenAI, or Google Gemini using your own account at the provider's published API rate (typically a fraction of a cent per post for standard content), with no markup and no intermediary. If you prefer not to use AI at all, every AI button simply stays unused.

### Iterative AI content refinement — a workflow no competitor offers

The Content Generator is not just a "generate and paste" tool. It opens a dedicated overlay with a live Refine section: you can submit follow-up instructions ("make this more formal", "expand the second section", "add a practical example") and the model rewrites from its previous draft using a multi-turn conversation. This iterative loop can run any number of times before you apply the result to the editor. No other plugin in this market does this.

### Designed for WordPress, not bolted onto it

Lingua Forge follows WordPress's intrinsic conceptual design throughout: translations are posts, blocks are blocks, routing uses standard WP_Query, and SEO output hooks into the head in the same place WordPress and SEO plugins already expect it. There is no overhead, no parallel data storage, no indexing layer, no recomposition step. The plugin does its work inside WordPress's own structures — not around them.

This stands in direct contrast to the alternatives: TranslatePress maintains its own string tables and rebuilds page output at render time; Weglot routes content through an external cloud; WPML and Polylang add custom database tables and metadata joins on every request. Lingua Forge adds none of that.

From a query overhead perspective: routing is URL-based with zero DB queries; the one non-trivial operation — resolving the TRID translation group for hreflang — is a single direct SQL query backed by WP object cache (1-hour TTL, invalidated on post save). Archive and home queries filter by language via a `meta_query` JOIN on the existing `WP_Query`, not a standalone extra query. The result — verifiable in the code — is 0–1 additional DB queries per page load on a cold cache, zero on a warm one, and no parallel string store, indexing pass, or cloud round-trip to pay for on any request. We don't claim a benchmark-backed "fastest" title without a published head-to-head, but architecturally there is simply less work per request to do than string-interception or cloud-proxy designs require.

### FSE / block-theme native from day one

Polylang's free tier has no FSE template translation support — block themes work, but translating template parts (header, footer, patterns) requires Pro. WPML added Site Editor support but requires the String Translation add-on for default template strings and has known open issues with FSE template application to translated pages; language-specific template entities are not supported. TranslatePress handles FSE via string interception — it catches rendered output automatically, but because translations are keyed to literal string content rather than context, the same string appearing in two different template parts (e.g. "Read more" in a blog card and in a CTA block) shares a single translation with no way to differentiate. Weglot's cloud approach catches rendered output regardless of source but with the same context-collapse limitation.

Lingua Forge takes the WordPress-native path: every template part — header, footer, navigation, reusable sections — has a language-specific equivalent built in the Site Editor. Each is a real WordPress entity, not a string-swapped version of a shared one. There is no ambiguity, no context collapse, and no shared-string problem. The Quick Translate tool makes building language variants of template parts fast. The result is a fully independent, editorially correct template structure per language. Templates, template parts, posts, pages, blocks — everything is a native WordPress object. Nothing sits outside WordPress's own data model.

The complete FSE localisation workflow shipped natively in 1.6.0: scaffold a language variant of any template or template part, AI-translate it in one click, fix internal links to point at the correct language equivalents, fix template-part slug references, fix `wp:navigation` ref IDs so each header and footer loads the correct language navigation, and create language-specific `wp_navigation` copies with AI-translated labels — all from Settings → Router with no CLI or manual database work required. WP-CLI commands for templates and template parts remain on the roadmap as a future automation path, extending the same six existing commands that already cover posts and pages to the FSE layer.

2.6.2 added force-overwrite tooling on top of that workflow — "Re-create" (per template/part) and "Re-create all" force a fresh copy from the active theme in place, discarding any Site Editor customisation, for the case where a theme update changes the base template or a customisation needs discarding. "Recreate All Languages" (and its templates-only / parts-only variants) runs the entire per-language sequence — re-create, AI-translate, fix links, fix parts, fix navs — across every active language in one click instead of tab by tab.

### SEO — complete multilingual SEO layer, no companion plugin required

As of 2.6.4, Lingua Forge handles the full multilingual SEO surface natively. No third-party SEO plugin is required:

- **hreflang** — output for singular, archive, and paginated contexts; `x-default` pointing to the source language; BCP 47-normalised codes; duplicate output from Yoast, Rank Math, AIOSEO, and SEOPress suppressed automatically via filter
- **Self-referencing canonical** — emitted per language version (2.2.16), aligned with Google's hreflang guidance (hreflang clusters require a self-referencing canonical); defers to a detected SEO plugin so the two never conflict
- **Open Graph + Twitter Cards** — `og:locale` and `og:locale:alternate` always emitted; full OG set (title, description, URL, image, type, Twitter Cards) in auto/full mode; defers to detected SEO plugin in auto mode to avoid duplicate tags. WooCommerce product pages: `og:type=product`, `og:price:amount`, `og:price:currency`, `og:availability`, and `product:` namespace equivalents
- **Schema.org JSON-LD** — `Article`/`WebPage` on singular posts/pages, `WebSite` on the front page, `Product` on WooCommerce product pages, and a language-aware `BreadcrumbList` (2.3.0) with language-prefixed crumb URLs; every type includes the `inLanguage` BCP 47 annotation that general SEO plugins cannot provide. Defers entirely to Yoast/Rank Math when detected to prevent conflicting JSON-LD graphs
- **XML sitemap** — `/lf-sitemap.xml` is a sitemap-index splitting into chunked sub-sitemaps (2.3.0) so it stays within the 50,000-URL protocol limit on large multilingual sites; `xmlns:xhtml` namespace and `<xhtml:link rel="alternate" hreflang>` entries for every translation group; announced automatically in `robots.txt`; robots.txt detection and management panel. WPML SEO (v2.2.x) now also puts hreflang in sitemaps — Lingua Forge's dedicated sitemap was always more precise for multilingual alternate links
- **IndexNow** — native (2.2.16): generates a verification key, serves `/<key>.txt`, and on publish submits the post **plus every language version in the translation group** to the shared IndexNow endpoint (Bing, Yandex, Seznam, Naver). This replaced the Bing/Yandex sitemap-ping buttons, whose endpoints were retired (410 Gone) in 2021–22. IndexNow itself is offered by SEO plugins like Rank Math; **no other multilingual plugin ships it natively**, and LF's version is translation-group-aware
- **Per-language noindex** — `_lf_noindex` (2.2.16) keeps a single language version out of search results while leaving the others indexable
- **Social Share** — native Social Icons block enhancement: editors set any icon URL to `share:facebook`, `share:x`, `share:linkedin`, `share:whatsapp`, `share:telegram`, `share:email`, `share:reddit`, `share:pinterest`, `share:mastodon`, or `share:copy`/`share:native`/`share:auto` (JS clipboard + Web Share API); LF rewrites them at render time. No other multilingual plugin ships this
- **AI SEO content analysis** — a rule-based 0–100 SEO score (title length, meta description quality, word count, heading structure, image alt coverage, internal links) available in both the Settings → SEO → Analysis tab (browse by language, batch audit) and as a `PluginDocumentSettingPanel` in the block editor Document sidebar. The editor panel adds an AI Recommendations section: one click calls the configured AI provider for natural-language improvements, title suggestions, and meta description rewrites — the only multilingual plugin to offer this
- **Meta description** — native field on all public post types; character counter with colour guidance; AI generator producing language-aware 140–160 character output per language
- **Slug SEO freedom** — title and slug carry independent keyword sets per language with full editor control
- **Compatibility management** — Compatibility tab shows live detection of installed SEO plugins with per-feature explanation of what LF is doing, why, and what the result is for each feature area. No manual configuration needed

WPML delegates most SEO to Yoast/Rank Math with its WPML SEO add-on handling co-existence; it has no native content analysis. Polylang Pro auto-adds hreflang and OG but also relies on a paired SEO plugin for content analysis and most meta. TranslatePress requires the SEO Pack add-on. Weglot auto-handles basic SEO via its cloud but has no structured data control, no content analysis, and no `x-default` tag. MultilingualPress leaves all SEO to a per-site SEO plugin.

### WP-CLI for automation at scale

Lingua Forge ships six native WP-CLI commands covering the full editorial automation loop: `translate`, `retranslate`, `fill_translations`, `missing_translations`, `cache_clear`, and `fix_nav_lang` (backfills language/TRID metadata on `wp_navigation` posts created before v2.1.0). The `missing_translations` + `fill_translations` pipeline can identify and resolve translation gaps across an entire post type in a single shell session, and all commands integrate cleanly with CI/CD pipelines and cron jobs. More commands are underway.

Polylang Pro added native CLI in version 3.8 (February 2026) — `wp pll language` for language management and `wp pll setting` for options — but these are limited to site configuration; no content translation commands exist. The free Polylang tier still relies on the unofficial community-maintained `polylang-cli` package. WPML exposes `wp wpml import process` for its export/import add-on but has no language management or translation commands. MultilingualPress provides CLI for language assignment to subsites and an AutoTranslate trigger, both scoped to its multisite architecture. TranslatePress and Weglot have no CLI support. Lingua Forge remains the only plugin in this space with native CLI for content translation and automation at scale.

### Post-list AI workflow — translate and retranslate without opening the editor

Two post-list actions, added in 1.8.1 and 1.8.2, close the loop on batch editorial workflows without requiring the editor to be opened for each post.

**Translate missing (1.8.1):** Source-language posts with untranslated language versions show a "Translate missing" button directly in the Lang column of the Posts / Pages list view. One click fires all missing AI translations for that post — the ⭕ missing-language indicators resolve inline, without leaving the overview screen.

**Retranslate outdated (1.8.2):** Target-language posts flagged with the ⚠ outdated indicator show a "From [lang]" dropdown and a Retranslate button inline in the Lang column. The selector lists every available source-language version in the translation group — useful when the Spanish version is more current than the English original and an editor wants to translate from Spanish rather than English. On click: the stale cache is cleared, AI translation reruns with force-refresh, the outdated flag is reset, and the meta description is regenerated — all without entering the editor.

No competitor exposes AI translation actions directly in the post-list column. WPML and Polylang require entering the translation editor or using the WPML Translation Dashboard. TranslatePress and Weglot operate on page output, not on post-list controls. MultilingualPress requires working within each sub-site individually.

### Sync, Trash + Siblings, and other 2.4–2.6 additions

Six more editorial and integration-facing features shipped across the 2.4–2.6 line, on top of the workflows described above:

- **Sync (2.6.0) and Template Sync (2.6.1)** — one click retranslates FROM any post in a translation group INTO every other configured language: missing siblings are created, existing ones are force-refreshed in place, and (deliberately, behind a confirmation dialog and an opt-in safeguard) a secondary-language post can even become the new source via back-translation. Template Sync does the lighter-weight equivalent for FSE template assignment alone, with no AI cost. No competitor offers a single-click "make every other language version match this one" action.
- **Trash + Siblings (2.5.4)** — a "Trash + Siblings" row action and bulk action trash an entire translation group together in one step, rather than requiring an editor to locate and trash each language version individually.
- **Asynchronous translation queue + self-healing backfill (2.4.0 / 2.5.3, gated behind an explicit Settings toggle since 2.6.3)** — `linguaforge_queue_translation()` lets a programmatic integration translate into many languages off-request (Action Scheduler / WP-Cron) instead of blocking on inline AI calls; an optional hourly scan can re-queue any (post, language) pair a lost or failed job left behind, with a 24-hour backoff after repeated failures so a mis-configured API key can't retry forever.
- **"Your latest posts" front-page support (2.5.0)** — translated homepages at `/es/`, `/fr/`, etc. for sites not using a static front page, including a language-scoped post listing and a redirect from `/` to a returning visitor's detected language.
- **Featured image copy + fixer (2.5.0)** — new translations automatically inherit the source post's featured image; a "Fix Featured Images" bulk action retroactively repairs existing translations that predate this.

### Single plugin, shared foundation

WPML's complete feature set typically requires multiple plugins (WPML Multilingual CMS plus add-ons such as String Translation and Media Translation as needed), with an additional plugin for WooCommerce. Each has its own update cycle and can introduce conflicts. Lingua Forge ships language routing, meta description, and AI tools as a single package with a shared constants layer and unified settings page.

### No lock-in

Translation content lives in standard WordPress posts and postmeta — exactly what was there before. Deactivate Lingua Forge and all your content is still in the database, readable by any other plugin or export tool. This is not true of Weglot (data in their cloud), and only partly true of TranslatePress (string tables are non-standard and deleted on uninstall).

---

## 13. Honest Gaps

Two areas where established competitors have a genuine advantage:

### Community and ecosystem maturity

WPML (2008) and Polylang (2012) have large user bases, extensive third-party documentation, verified compatibility lists covering hundreds of themes and plugins, and wide community support. Lingua Forge is newer, so an unusual plugin stack is more likely to be first-encountered territory — fewer ready-made answers when something niche breaks.

### Professional translation management

WPML integrates with translation agencies and CAT tools via XLIFF export. Polylang Pro supports similar workflows. TranslatePress and Weglot both support translator role assignment. A dedicated translator role is coming soon — a scoped WordPress role that allows contributors to translate without access to source content or settings. XLIFF agency integration will be implemented if there is sufficient user demand — report your need via the support forum or GitHub issues to help prioritise it.

### Distribution — self-hosted, not on WordPress.org

Polylang, TranslatePress, and Weglot's connector are all listed in the WordPress.org plugin directory; Lingua Forge is **not** — the name was rejected for the directory, so distribution is self-hosted via the built-in update checker. For technical operators this is a non-issue (download, install, auto-update). For less technical users who discover and trust plugins through the in-dashboard directory — searching, installing in one click, and reading the review counts and active-install figures — the absence of a .org listing is a real discovery and reach disadvantage that the feature set does not offset. This is an honest gap, not a technical limitation.

---

### Notes on architectural scope

The following items are out of current scope by design, not competitive gaps:

**Separate-domain routing** (`site.de`, `site.fr`) — Path-prefix and subdomain routing (v1.7.0+) cover the vast majority of multilingual site architectures on a single WordPress install. Fully independent domains per language require either WordPress Multisite (MultilingualPress) or a reverse-proxy layer, both of which introduce infrastructure overhead that is outside Lingua Forge's single-site scope.

**String translation UI** — Theme strings, widget text, and plugin strings that live outside WordPress post content are handled by [Loco Translate](https://wordpress.org/plugins/loco-translate/), a well-maintained free GPL companion that integrates cleanly alongside Lingua Forge with no conflicts. Lingua Forge's Language Overrides feature covers the `.mo`-file override use case directly. A native string-translation UI is on the roadmap; Loco Translate is the recommended solution today.

---

## 14. When to Choose Each Plugin

### Choose Lingua Forge when:
- The site runs a **block / FSE theme** and needs language-specific templates, template parts, and navigation menus — scaffold, AI-translate, fix links, fix nav refs, and create language navigation copies, all from Settings → Router with no CLI or manual database work.
- **Zero licensing cost** is a hard requirement — no annual fee is acceptable.
- **AI content assistance** embedded in the editorial workflow matters (content generation with iterative refinement, meta descriptions, excerpts, quick translate, behavior presets).
- You want to **own your AI costs** directly and switch providers without a credit intermediary.
- **WP-CLI automation** is needed — bulk translation, retranslation cron jobs, CI/CD pipeline.
- **Terminology consistency** across languages is important (Glossary + Translation Memory).
- **Vendor lock-in** is a concern — all translation data stays in standard WP posts.
- **Browser language redirect** is needed — first-time visitors are routed to their preferred language based on the Accept-Language header, with cookie taking over on subsequent visits.

### Choose WPML when:
- Your site depends on plugins that have already integrated with **WPML's public API** (`icl_object_id`, `wpml_get_language_information`, etc.).
- You need **agency or CAT-tool workflows** with XLIFF round-trips and OTGS marketplace access.
- **WooCommerce multilingual** at scale is required and the budget supports the Agency tier.
- You need **separate-domain routing** (`site.de`, `site.fr`) per language — subdomains are now supported by Lingua Forge 1.7.0, but fully independent domains per language are not.

### Choose Polylang when:
- You want a **post-based plugin with a lighter footprint** than WPML and the site does not depend on complex block-template logic.
- You are comfortable with the **DeepL or Google subscription** add-on for auto-translation.
- **€99/year** is acceptable and WooCommerce is not in scope.

### Choose TranslatePress when:
- **Non-technical translators** will do most of the translation and a visual click-to-translate front-end interface is the priority.
- The site relies heavily on **hardcoded theme strings that bypass WordPress i18n** (`__()` / `_e()`) — the narrow category that Language Overrides and language-specific templates do not cover.
- You want **AI translation included in the license price** (word quotas per plan) with a predictable annual bill rather than per-call API costs.

### Choose Weglot when:
- **Setup speed** is paramount — Weglot is live in under 5 minutes with zero WordPress expertise.
- The site is not running on WordPress alone (Shopify, Webflow, Squarespace) and you want one translation layer across all platforms.
- You are comfortable with translations living in **Weglot's cloud** and with pricing that scales with word count — and the budget supports it.

### Choose MultilingualPress when:
- You are already on **WordPress Multisite** or are willing to migrate to it.
- **Complete language isolation** is required — separate databases, separate sites, separate admin environments (typical for large enterprise networks or media groups).
- **WooCommerce multilingual with multi-store** (separate WooCommerce store per language) is needed.
- **Maximum performance** at scale is the primary concern — no per-request string processing, no shared database queries.
- You are comfortable with the **operational overhead** of managing a WordPress Multisite network.

---

## 15. Market Positioning Summary

| Plugin | Best fit | Core strength | Core limitation |
|---|---|---|---|
| **Lingua Forge** | Content-focused block-theme sites, developers, cost-sensitive projects, single-currency WooCommerce stores | Zero cost + complete native SEO layer (hreflang, self-referencing canonical, OG/schema/BreadcrumbList/sitemap, IndexNow, Social Share, AI content analysis) + AI editorial depth + early WP 7.0 AI Client adoption + WP-CLI + FSE-native + full variable-product translation **and commerce lifecycle** (order-language emails, coupons, consolidated sales/reviews) + post-list AI translate/retranslate | Self-hosted only (not on WordPress.org); no multi-currency, multi-store, or separate-domain routing |
| **WPML** | Plugin-ecosystem-dependent sites, agencies, WooCommerce at scale | Market leader, widest compatibility, agency/CAT workflows | High cost, plugin bloat, metered AI credits |
| **Polylang** | Minimal post-based sites that need basic routing only — no AI, no native SEO layer, no FSE tooling | Lightweight, clean, widely understood, large install base | Free tier severely limited; Pro still needs DeepL separately |
| **TranslatePress** | Teams where visual front-end editing is priority | Front-end editor UX, transparent WooCommerce, predictable pricing | Render-time overhead + parallel string storage layer, no FSE template support |
| **Weglot** | Non-technical teams, multi-platform, speed of setup | Fastest setup, cloud handles all content types including JS | Highest cost at scale, data sovereignty concerns, strong lock-in |
| **MultilingualPress** | Enterprise, high-traffic, multisite-native, WooCommerce multi-store | Zero per-request overhead, complete isolation, performance, free open-source core | Requires Multisite, operational complexity, per-site configuration overhead |

For a small to medium WordPress site on a block theme — a business site, a magazine, a portfolio, a non-profit — Lingua Forge 2.6.4 already covers the full multilingual workflow that every competitor charges €99–€349+/year to provide. It does so at zero licensing cost, with an AI editorial toolset deeper than anything in this market, designed for the FSE architecture from the ground up, and with no extra storage layer, no string indexing, and no content locked in a third-party cloud.

The differentiation is simple: **Lingua Forge gives a modern WordPress site the complete multilingual workflow — routing, a full native SEO layer, AI translation and content tools, and built-in WooCommerce — permanently free, FSE-native, with a developer experience (WP-CLI, AES-256 key encryption, PHP API, no lock-in) that no competitor matches and a native-block architecture that carries none of the string-interception or cloud-proxy overhead.**

As of 2.6.4, Lingua Forge covers the complete multilingual SEO surface natively — hreflang, self-referencing canonical, Open Graph with locale tags, Schema.org JSON-LD with `inLanguage` annotations (including a language-aware BreadcrumbList), a chunked multilingual sitemap, native IndexNow submission of every language version, per-language noindex, WooCommerce product schema and OG tags, Social Icons block share: rewriting, and AI-powered SEO content analysis — with no companion SEO plugin required. It also covers the WooCommerce variable-product stack *and* the commerce lifecycle for a single-currency store: translated variable products display correct prices, stock, images, and variations; variation descriptions are translatable; attribute term names display in the visitor's language in both block themes and classic templates; product brands are delegated automatically; a REST write guard protects translated posts from external integration accidents; order language is captured at checkout and transactional emails are sent in it; coupons honour all language versions; and sales counts and reviews consolidate per product. Order-language emails, cross-language coupons, and shared reviews reach parity with WCML and Polylang-for-WooCommerce rather than leading them; multi-currency and multi-store remain out of scope by design. For everything else in the competitive surface, Lingua Forge is fully covered at zero licensing cost.

---

## 16. Sources and References

> All sources verified June 2026. Pricing pages change frequently — the ⚠ caution in §1 applies throughout.

### Pricing

- [WPML — Pricing](https://wpml.org/purchase/)
- [WPML — Automatic Translation Pricing](https://wpml.org/documentation/automatic-translation/automatic-translation-pricing/)
- [Polylang Pro — Pricing](https://polylang.pro/pricing/polylang-pro/)
- [Polylang Business Pack — Pricing](https://polylang.pro/pricing/polylang-business-pack/)
- [TranslatePress — Pricing](https://translatepress.com/pricing/)
- [Weglot — Pricing](https://www.weglot.com/pricing)
- [MultilingualPress — Site](https://multilingualpress.org/) · [GitHub (free open-source core)](https://github.com/inpsyde/multilingual-press)

### WooCommerce, IndexNow, and WordPress 7.0 (June 2026 verification)

- [WPML — WooCommerce Multilingual (WCML): order emails in customer language](https://wpml.org/documentation/related-projects/woocommerce-multilingual/)
- [WCML on WordPress.org](https://wordpress.org/plugins/woocommerce-multilingual/)
- [WPML errata — WC emails sent in default language on admin status change](https://wpml.org/errata/woocommerce-e-mails-are-always-sent-in-default-language-for-any-admin-order-status-change/)
- [Rank Math — IndexNow / Instant Indexing (IndexNow is an SEO-plugin feature, not native to multilingual plugins)](https://wordpress.org/plugins/seo-by-rank-math/)
- [Rank Math — Multilingual SEO with WPML](https://rankmath.com/kb/multilingual-seo-wpml/)
- [WordPress 7.0 — Introducing the AI Client (make.wordpress.org/core)](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)

### Architecture and Feature Documentation

- [Polylang — Features](https://polylang.pro/features/)
- [Polylang Pro — Using the Site Editor](https://polylang.pro/documentation/support/guides/site-editor/)
- [Polylang — Language Switcher guide](https://polylang.pro/documentation/support/guides/the-language-switcher/)
- [Polylang 3.2 — FSE support announcement](https://polylang.pro/its-official-polylang-3-2-is-available/)
- [WPML 4.5.3 — FSE / WordPress 5.9 compatibility](https://wpml.org/changelog/2022/01/wpml-4-5-3-compatibility-with-wordpress-5-9-and-full-site-editing/)
- [WPML errata — FSE template not applied to translated pages](https://wpml.org/errata/template-is-not-applied-to-translated-page/)
- [WPML forum — FSE template handling approach](https://wpml.org/forums/topic/fse-full-site-editing-handling-of-fse-templates-has-wrong-approach/)

### WordPress.org Support Threads (User Reports)

- [Polylang — Free version with block themes (user review)](https://wordpress.org/support/topic/free-version-is-useless-with-block-themes/)
- [Polylang — Language Switcher block not registering in Site Editor](https://wordpress.org/support/topic/polylang-language-switcher-block-not-available-registering-in-site-editor-fse/)
- [Polylang — Translate header/footer with FSE not possible (free)](https://wordpress.org/support/topic/polylang-translate-header-footer-with-fse-block-theme-not-possible/)

### Performance

- [TranslatePress — Translation plugins compared by page load time](https://translatepress.com/top-wordpress-translation-plugins-compared-based-on-page-load-time/) *(vendor benchmark; methodology and environment may differ)*
- [WP Rocket — Fastest WordPress translation plugin (independent test)](https://wp-rocket.me/blog/fastest-wordpress-translation-plugin/)

### WP-CLI

- [diggy/polylang-cli — GitHub (unofficial community package)](https://github.com/diggy/polylang-cli)
- [Polylang CLI — official docs (Pro, since 3.8)](https://polylang.pro/documentation/support/developers/polylang-cli/)
- [WPML Export and Import with WP-CLI](https://wpml.org/documentation/related-projects/wpml-export-and-import/how-to-run-wpml-export-and-import-with-wp-cli/)
- [MultilingualPress — assign language via WP-CLI](https://multilingualpress.org/docs/how-to-assign-a-language-via-wp-cli/)

### Setup and Theme Integration

- [WPML errata — Language Switcher Block display issue with no Navigation Block](https://wpml.org/errata/full-site-editing-fse-wpml-language-switcher-block-display-issue-if-no-navigation-block-is-used/)
- [WPML errata — Not possible to add language switcher to Navigation Block](https://wpml.org/errata/not-possible-to-add-a-language-switcher-to-the-navigation-block/)
- [Why WPML and WordPress Full Site Editing Are a Bad Match — BHI Localization (independent review, 2025)](https://www.bhi-localization.com/why-wpml-and-wordpress-full-site-editing-are-a-bad-match-for-now/)
- [WPML Review — Elegant Themes (2025)](https://www.elegantthemes.com/blog/wordpress/wpml-review)
- [Polylang — Language Switcher guide](https://polylang.pro/documentation/support/guides/the-language-switcher/)
- [Polylang — Language Switcher block not registering in Site Editor (support thread)](https://wordpress.org/support/topic/polylang-language-switcher-block-not-available-registering-in-site-editor-fse/)
- [TranslatePress — Language Switcher documentation](https://translatepress.com/docs/settings/language-switcher/)
- [Weglot — How to Add a WordPress Language Switcher](https://www.weglot.com/guides/wordpress-language-switcher)
- [MultilingualPress — Getting started guide](https://multilingualpress.org/docs/getting-started-with-multilingualpress/)
- [MultilingualPress — Language Switcher setup](https://multilingualpress.org/docs/language-switcher-multilingual-wordpress-website/)

### SEO (June 2026 research)

- [WPML SEO — Documentation](https://wpml.org/documentation/related-projects/wpml-seo/)
- [WPML SEO 2.2.0 — Release notes](https://wpml.org/compatibility/2025/06/wpml-seo-2-2-0/)
- [WPML SEO 2.2.5 — RankMath/Yoast integration](https://wpml.org/compatibility/2026/03/wpml-seo-2-2-5/)
- [Polylang — Features (hreflang + Open Graph)](https://polylang.pro/features/)
- [TranslatePress SEO Pack — Documentation](https://translatepress.com/docs/addons/seo-pack/)
- [Weglot — How Weglot manages WordPress SEO](https://support.weglot.com/article/99-wordpress-how-weglot-manage-seo)
- [Weglot — Multilingual sitemap guide](https://www.weglot.com/blog/multilingual-sitemap)
- [Rank Math vs Yoast 2026 — Independent comparison](https://oddjar.com/wordpress-seo-plugins-2026-comparison/)
- [Multilingual sitemap comparison — Slim SEO](https://wpslimseo.com/wordpress-multilingual-sitemap/)
