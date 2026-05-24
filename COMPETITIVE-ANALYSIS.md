# Lingua Forge — Full Market Assessment

**Competitors:** WPML · Polylang · TranslatePress · Weglot · MultilingualPress
**Scope:** Small to medium WordPress sites (1–50 editors, block/FSE themes, 2–10 languages)
**Date:** May 2026 · Lingua Forge 1.7.0

---

> **⚠ Disclaimer — AI-generated and AI-maintained document**
>
> This document is researched, written, and updated by an AI assistant (Claude). It is intended as a high-level orientation to the WordPress multilingual plugin landscape — not as a definitive or authoritative source. Competitor feature sets, pricing, and roadmaps change frequently, and AI-produced assessments can contain errors, omissions, or outdated information even when recently reviewed. Treat every claim as a starting point for your own investigation, not a conclusion. Before making purchasing, migration, or architectural decisions, verify the details directly with each vendor's current documentation and pricing pages. The §15 Sources section lists the primary references used at the time of writing.

---

## TL;DR

The WordPress multilingual plugin market splits into three distinct architectural camps: **post-based** plugins that create one post record per language per content item (WPML, Polylang, Lingua Forge, MultilingualPress); **string-replacement** plugins that intercept page output and swap strings (TranslatePress); and **cloud-proxy SaaS** that stores translations externally and serves them via CDN (Weglot). Each architecture has real trade-offs, and the best choice depends more on site architecture than on feature lists.

Lingua Forge sits in the post-based camp alongside WPML and Polylang, but diverges from them in one important way: translations are native WordPress posts with no extra storage layer, no custom indexing tables, and no recomposition step — where WPML and Polylang add their own `icl_*` and `pll_*` table structures on top. Beyond architecture, Lingua Forge differentiates on four axes: zero licensing cost, FSE-native design, a materially deeper AI editorial toolset than any competitor ships natively, and direct AI provider access with no intermediary markup. AI use is entirely optional — manual translation costs nothing to run. When AI is used, the cost is your provider's published API rate with no markup, making the total three-year cost an order of magnitude lower than WPML or Weglot for content-focused sites.

---

## 1. Pricing at a Glance

> **⚠ Prices are approximate and verified as of May 2026.** Competitor pricing changes frequently — always check the vendor's current pricing page before quoting or recommending.

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| **Free tier** | ✅ Full feature set, no expiry | ❌ | ✅ Limited (no FSE template translation, no hreflang) | ✅ 1 language, 2 000 AI words/mo | ✅ 1 language, 2 000 words total | ❌ |
| **Entry paid plan** | — | €29/yr (Multilingual Blog) | €99/yr (Pro, 1 site) | ≈ €89/yr (Personal, 1 site) | ≈ €149/yr (Starter) | $99/yr |
| **Mid plan** | — | €99/yr (CMS, 1 site) | €149/yr (Business, 3 sites) | ≈ €156/yr (Business, 3 sites) | ≈ €276/yr (Business) | Scales by site count |
| **Agency / unlimited** | — | €199/yr (Agency) | — | ≈ €252/yr (Developer, unlimited) | ≈ €758–€2 868/yr (Pro/Advanced) | Custom |
| **AI / auto-translation cost** | Your API key, provider rates (~€0.002–€0.01 per 1 000 tokens) | WPML Credits: 2 000 free/mo then top-up; ~€0.90 per 1 000 words | DeepL or Google subscription (separate) | Included word quota per plan (50 k–500 k AI words/yr) | Included (machine translation, then billed by word count) | DeepL / GPT4 / Google via AutoTranslate (API key, provider rates) |
| **WooCommerce** | ❌ | Add-on (bundled in Agency) | Separate add-on | ✅ included | ✅ included (cloud handles dynamic content) | ✅ included |
| **True zero-cost path** | ✅ Manual translation — no API key, no limit | ❌ Annual license required | ❌ Pro required for FSE template translation/hreflang | ✅ Manual translation free | ❌ Word count limit on free tier | ❌ |

### Three-year cost model — single site, ~200 posts, 3 languages, moderate AI use

| | 3-year license | AI / translation cost | Total |
|---|---|---|---|
| **Lingua Forge** | €0 | ~€5–15 API usage | **< €15** |
| **WPML CMS** | €297 | Credit top-ups if >2 000 words/mo | **€300 +** |
| **Polylang Pro** | €297 | Separate DeepL subscription | **€300 +** |
| **TranslatePress Personal** | €267 | Included quota (50 k AI words/yr) | **€267** |
| **Weglot Starter** | €447 | Included (2 000-word limit — likely need Business at €828) | **€447–€828** |
| **MultilingualPress** | €297 | Provider API keys at cost | **~€310** |

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
| Outdated translation tracking | ✅ ⚠ indicator | ✅ dashboard | ✅ Pro | ✅ (visual indicator) | ❌ | ⚠ dashboard widget (incomplete) |
| Cookie / query-param detection | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Browser auto-redirect | ✅ opt-in (Accept-Language header) | ✅ | ✅ Pro | ✅ Business | ✅ | ✅ |
| Language Switcher block (FSE) | ✅ | ✅ built-in (CMS/Agency plan) | ✅ Pro | ✅ | ✅ (widget/block) | ✅ |
| Language-specific FSE templates | ✅ (`page-de`, `single-fr`) with full in-plugin scaffold + AI-translate + fix workflow | ❌ (known open issues as of 2026) | ❌ Pro (translates template parts, not template entities — no `page-de`) | ❌ | ❌ | ❌ |
| FSE template part localisation (scaffold + AI-translate + fix) | ✅ (`header-de`, `footer-ca`, …); Fix Nav rewrites navigation refs per part | ❌ | ⚠ Pro (template parts only, no fix workflow) | ❌ | ❌ | ❌ |
| Navigation menu localisation (AI-translate + lang-copy) | ✅ per-language `wp_navigation` posts with AI-translated labels and URL fixing | ❌ | ✅ Pro (manual) | ✅ (string-intercept) | ✅ (cloud) | ✅ |
| Admin link fixer (repairs cross-language internal links) | ✅ | ⚠ Translate Link Targets scan (Settings); Sticky Links add-on | ❌ | ❌ | ❌ | ❌ |
| WP-CLI support | ✅ 5 commands | ⚠ import/export only | ⚠ Pro (native since 3.8) | ❌ | ❌ | ⚠ language assignment + AutoTranslate |

---

## 4. SEO

| Feature | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| hreflang output | ✅ (singular, archive, paginated) | ✅ | ✅ Pro | ✅ | ✅ | ✅ |
| Auto-suppresses duplicate hreflang from Yoast / Rank Math / AIOSEO | ✅ automatic | Manual filter | Manual filter | ❌ (relies on SEO plugin) | ❌ | ❌ |
| Native meta description field | ✅ (all public post types) | Via SEO plugin | Via SEO plugin | ✅ via SEO Pack add-on | ✅ (auto-translated) | Via SEO plugin |
| `<meta name="description">` + OG + Twitter | ✅ native | Via SEO plugin | Via SEO plugin | ✅ SEO Pack | ✅ | Via SEO plugin |
| Character counter with colour guidance | ✅ | Via SEO plugin | Via SEO plugin | Via SEO plugin | ❌ | Via SEO plugin |
| AI meta description generator | ✅ language-aware | ❌ | ❌ | ❌ | ❌ | ❌ |
| Translated slugs / permalinks | ✅ full support across all translation paths; editor free to optimise slug independently of title (SEO advantage) | ✅ | ✅ Pro | ✅ | ✅ | ✅ |

---

## 5. Translation Approach and AI / Auto-translation

| Feature | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| AI provider(s) | Claude, OpenAI, Gemini (your key) | DeepL via WPML Credits | DeepL / Google (separate subscription) | DeepL, Google Translate, GPT, Gemini (combined NMT + LLM engine) | DeepL, Google, Microsoft (cloud-managed) | DeepL, GPT-4, Google (your API key) |
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
| Inline editing in diff preview | 🗓 Next major release | ❌ | ❌ | ❌ | ❌ | ❌ |
| Block flagging (needs review / needs editing) from diff view | 🗓 Next major release | ❌ | ❌ | ❌ | ❌ | ❌ |
| AI Behavior Presets (temperature + system prompt tuning) | ✅ 4 presets | ❌ | ❌ | ❌ | ❌ | ❌ |
| AI Usage tracking (tokens / feature / date) | ✅ | ❌ | ❌ | Word quota shown in dashboard | Weglot dashboard | ❌ |
| API key encryption | ✅ AES-256-GCM with versioned envelope | N/A (WPML manages) | N/A | N/A (site credentials) | N/A (SaaS) | Site credentials |
| Translator role | 🗓 Medium-term | ✅ | ✅ Pro | ✅ Business | ✅ Pro | ❌ |
| Agency / CAT tool integration (XLIFF) | ⬇ Low priority | ✅ | ✅ Pro | ❌ | ✅ (export) | ❌ |

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

## 8. WooCommerce Multilingual

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| Product / variation / category translation | ❌ | ✅ (add-on; requires CMS plan or higher) | ✅ paid add-on | ✅ included | ✅ (cloud, including JS-rendered cart/checkout) | ✅ included |
| Dynamic cart / checkout translation | ❌ | ✅ | ✅ | ✅ | ✅ (strongest — cloud catches JS output) | ✅ |
| Multi-currency | ❌ | ✅ (WPML Multi-Currency add-on) | Via WooCommerce / 3rd-party | ❌ | ❌ | ✅ (separate store per language) |

**Honest assessment:** Lingua Forge is a content-site plugin and WooCommerce multilingual support is not on its roadmap. Ongoing WooCommerce compatibility requires sustained engineering effort against WooCommerce's release cycle — effort that is only viable with a revenue model or a team. If your site sells products in multiple languages, TranslatePress or Weglot handle WooCommerce most transparently; WPML and MultilingualPress cover it most completely. Those plugins exist for good reason and serve this segment well.

---

## 9. Performance and Architecture

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| Translation storage | WP post table + postmeta | WP post table + `icl_*` tables | WP post table + `pll_*` tables | `trp_*` string tables | Weglot cloud (external) | Separate WP site per language |
| Extra storage / indexing layer | None — translations are native WP posts | Moderate (`icl_*` metadata joins) | Low (`pll_*` tables) | Yes — parallel string tables + indexing + recomposition on render | Yes — Weglot cloud | None per site |
| Page load overhead (typical) | Minimal — routing is URL-based (zero routing queries); hreflang TRID lookup is a single direct SQL query cached in WP object cache for 1 h; archive/home queries use a meta_query JOIN on the existing WP_Query, not a standalone extra query | Low–moderate (extra queries per page via `icl_*` joins) | Low (optimised `pll_*` table queries) | Moderate — render-interception adds measurable overhead; impact varies significantly by page complexity and hosting environment | Minimal per cached page (content served from CDN); proxy layer adds latency on first translation or cache miss | Minimal per site (separate DB, zero shared queries) |
| DB query overhead | Low — 0 extra queries on routing; 0–1 on singular pages (TRID lookup, object-cache backed after first hit); 0 standalone queries on archives | Moderate (`icl_*` metadata joins on every request) | Low (`pll_*` table joins) | Moderate (string lookup and replacement per render) | None server-side (cloud) | None per site |
| Content survives plugin removal | ✅ (posts remain) | ✅ (posts remain, tables orphaned) | ✅ (posts remain) | ⚠ Strings deleted with plugin | ❌ Data in Weglot cloud | ✅ (each site is independent) |
| Offline / no-internet capable | ✅ | ✅ | ✅ | ✅ | ❌ (cloud-dependent) | ✅ |

---

## 10. Developer and Operator Experience

| | Lingua Forge | WPML | Polylang | TranslatePress | Weglot | MultilingualPress |
|---|---|---|---|---|---|---|
| WP-CLI commands | ✅ 5 commands (translate, retranslate, fill-translations, missing-translations, cache-clear) — shipped natively; more underway | ⚠ `wp wpml import process` (export/import only; no language management) | ⚠ Pro: native `wp pll language` + `wp pll setting` since 3.8 (Feb 2026); free tier: unofficial community package only | ❌ | ❌ | ⚠ language assignment to subsites + AutoTranslate trigger; multisite-scoped |
| Public PHP API | ✅ (`linguaforge_*` wrapper functions) | ✅ (`wpml_*` filters/functions) | ✅ (`pll_*` functions) | Limited (hooks only) | Limited (REST API) | ✅ (MLP API) |
| WordPress Multisite required | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (prerequisite) |
| Third-party plugin ecosystem dependency | None | Large (WPML-specific APIs widespread) | Moderate | Low | Low | Low |
| Language Overrides (.mo upload, survives updates) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| API key in wp-config.php constant | ✅ | N/A | N/A | N/A | N/A | ✅ (provider keys) |
| Safe uninstall default | ✅ language assignments and TM kept by default; opt-in full removal toggle | ✅ | ✅ | ⚠ string tables deleted | ❌ data in cloud | ✅ |
| Lock-in risk if you switch away | Low (standard posts, TRID in postmeta) | Medium (many plugins use WPML APIs) | Low | Medium (string tables) | High (content in Weglot cloud) | Low (standard WP sites) |

---

## 11. Lingua Forge Strengths

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

From a query overhead perspective: routing is URL-based with zero DB queries; the one non-trivial operation — resolving the TRID translation group for hreflang — is a single direct SQL query backed by WP object cache (1-hour TTL, invalidated on post save). Archive and home queries filter by language via a `meta_query` JOIN on the existing `WP_Query`, not a standalone extra query. The result is 0–1 additional DB queries per page load on a cold cache, and zero on a warm one. No published head-to-head benchmark has been run, so a "fastest" claim would be unsupported — but the architectural reasoning is sound and verifiable in the code.

### FSE / block-theme native from day one

Polylang's free tier has no FSE template translation support — block themes work, but translating template parts (header, footer, patterns) requires Pro. WPML added Site Editor support but requires the String Translation add-on for default template strings and has known open issues with FSE template application to translated pages; language-specific template entities are not supported. TranslatePress handles FSE via string interception — it catches rendered output automatically, but because translations are keyed to literal string content rather than context, the same string appearing in two different template parts (e.g. "Read more" in a blog card and in a CTA block) shares a single translation with no way to differentiate. Weglot's cloud approach catches rendered output regardless of source but with the same context-collapse limitation.

Lingua Forge takes the WordPress-native path: every template part — header, footer, navigation, reusable sections — has a language-specific equivalent built in the Site Editor. Each is a real WordPress entity, not a string-swapped version of a shared one. There is no ambiguity, no context collapse, and no shared-string problem. The Quick Translate tool makes building language variants of template parts fast. The result is a fully independent, editorially correct template structure per language. Templates, template parts, posts, pages, blocks — everything is a native WordPress object. Nothing sits outside WordPress's own data model.

The complete FSE localisation workflow shipped natively in 1.6.0: scaffold a language variant of any template or template part, AI-translate it in one click, fix internal links to point at the correct language equivalents, fix template-part slug references, fix `wp:navigation` ref IDs so each header and footer loads the correct language navigation, and create language-specific `wp_navigation` copies with AI-translated labels — all from Settings → Router with no CLI or manual database work required. WP-CLI commands for templates and template parts remain on the roadmap as a future automation path, extending the same five existing commands that already cover posts and pages to the FSE layer.

### SEO — native output, no plugin dependency

Lingua Forge handles the full multilingual SEO surface natively, without a companion plugin or add-on:

- **hreflang** — output for singular, archive, and paginated contexts; duplicate output from Yoast, Rank Math, and AIOSEO suppressed automatically
- **Meta description** — native field on all public post types; `<meta name="description">`, OG, and Twitter tags all emitted natively
- **Character counter** — real-time length guidance with colour coding directly in the editor
- **AI meta description generator** — language-aware, 140–160 character output per language
- **Slug SEO freedom** — title and slug can carry independent keyword sets per language; the translated slug is set automatically but the editor retains full control to optimise it separately

Every competitor either delegates SEO entirely to a third-party plugin (WPML, Polylang, MultilingualPress) or requires a paid add-on (TranslatePress SEO Pack). Weglot auto-translates meta descriptions but without editorial control or character guidance.

### WP-CLI for automation at scale

Lingua Forge ships five native WP-CLI commands covering the full editorial automation loop: `translate`, `retranslate`, `fill-translations`, `missing-translations`, and `cache-clear`. The `missing-translations` + `fill-translations` pipeline can identify and resolve translation gaps across an entire post type in a single shell session, and all commands integrate cleanly with CI/CD pipelines and cron jobs. More commands are underway.

Polylang Pro added native CLI in version 3.8 (February 2026) — `wp pll language` for language management and `wp pll setting` for options — but these are limited to site configuration; no content translation commands exist. The free Polylang tier still relies on the unofficial community-maintained `polylang-cli` package. WPML exposes `wp wpml import process` for its export/import add-on but has no language management or translation commands. MultilingualPress provides CLI for language assignment to subsites and an AutoTranslate trigger, both scoped to its multisite architecture. TranslatePress and Weglot have no CLI support. Lingua Forge remains the only plugin in this space with native CLI for content translation and automation at scale.

### Single plugin, shared foundation

WPML's complete feature set typically requires multiple plugins (WPML Multilingual CMS plus add-ons such as String Translation and Media Translation as needed), with an additional plugin for WooCommerce. Each has its own update cycle and can introduce conflicts. Lingua Forge ships language routing, meta description, and AI tools as a single package with a shared constants layer and unified settings page.

### No lock-in

Translation content lives in standard WordPress posts and postmeta — exactly what was there before. Deactivate Lingua Forge and all your content is still in the database, readable by any other plugin or export tool. This is not true of Weglot (data in their cloud), and only partly true of TranslatePress (string tables are non-standard and deleted on uninstall).

---

## 12. Honest Gaps

### WooCommerce

Lingua Forge does not currently support WooCommerce. The architectural foundation is sound — products and variations are native WordPress post types, and the translation infrastructure applies to them in principle. What WooCommerce multilingual requires beyond that is sustained compatibility work across WooCommerce's own release cycle, coverage of dynamic cart and checkout surfaces, and the contributor capacity to maintain it. That is a function of a growing user base and community, not a technical barrier. It is on the horizon. For ecommerce sites today, TranslatePress, Weglot, WPML, or MultilingualPress are the right tools.

### Translated URL slugs — not a gap

Slug translation is fully supported across all paths. Full-page Translation sets the translated title and dispatches it to the editor via the Gutenberg Apply modal, from which WordPress derives the slug automatically; CLI commands set `post_name` from `sanitize_title(translated_title)` on every run. Each translation lives under its own language-prefixed URL — `/es/pagina-en-castellano`, `/fr/equivalent-content-en-francais` — as independent WordPress posts.

After translation, the editor retains full freedom to adjust the slug independently of the title. This is a deliberate SEO advantage: title and slug can carry different keyword sets — the title paraphrasing for engagement, the slug optimised for search. Fully automated slug systems do not allow this. The permalink filter and TRID groups ensure the language switcher resolves to the correct slug per language regardless of how it was customised.

### Subdomain and separate-domain routing

Lingua Forge 1.7.0 adds subdomain routing — select **Settings → Router → URL structure → Subdomain** to serve languages from `de.example.com`, `fr.example.com`, etc. on a single WordPress installation (no Multisite required). The source language remains at the root domain. Cookie scoping, permalink generation, hreflang output, and the language switcher are all subdomain-aware. Server prerequisites: wildcard DNS and a wildcard TLS certificate covering all language subdomains.

Separate-domain routing (`site.de`, `site.fr`) is not yet supported. Sites that require fully independent domains per language should consider MultilingualPress (Multisite approach) or a reverse-proxy setup.

### Professional translation management

WPML integrates with translation agencies and CAT tools via XLIFF export. Polylang Pro supports similar workflows. TranslatePress and Weglot both support translator role assignment. A dedicated translator role is on Lingua Forge's medium-term roadmap — a scoped WordPress role that allows contributors to translate without access to source content or settings. XLIFF agency integration is lower priority: the maintenance overhead against evolving block formats and the professional-agency target audience are a poor fit for a solo-maintained free plugin at this stage.

### String translation UI

WPML String Translation provides a searchable UI for translating theme strings, widget text, and plugin strings. TranslatePress and Weglot catch these automatically via string interception or cloud rendering. Lingua Forge's Language Overrides feature covers the `.mo`-file use case (replacing specific plugin strings per locale) but is not a general-purpose string translation manager.

**Closing this gap:** [Loco Translate](https://wordpress.org/plugins/loco-translate/) is a well-maintained free GPL plugin that provides exactly this — in-admin `.po`/`.mo` editing, automatic sync with installed language packs, and developer extraction tools. It integrates cleanly alongside Lingua Forge with no conflicts. For sites that need to translate theme or plugin strings today, Loco Translate is the recommended companion. A native string translation UI is on Lingua Forge's roadmap; verified Loco Translate compatibility and a settings-level recommendation are the planned first steps before any native feature work.

### Community and ecosystem maturity

WPML (2008) and Polylang (2012) have large user bases, extensive third-party documentation, verified compatibility lists covering hundreds of themes and plugins, and wide community support. Lingua Forge is younger. Sites running unusual plugin stacks may encounter untested edge cases, and fewer tutorial resources exist.

---

## 13. When to Choose Each Plugin

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
- **WooCommerce** support is needed without a separate add-on.

### Choose Weglot when:
- **Setup speed** is paramount — Weglot is live in under 5 minutes with zero WordPress expertise.
- The site is not running on WordPress alone (Shopify, Webflow, Squarespace) and you want one translation layer across all platforms.
- **WooCommerce dynamic content** (live cart, checkout JavaScript) needs to be translated and the cloud-proxy approach is the cleanest solution.
- You are comfortable with translations living in **Weglot's cloud** and with pricing that scales with word count — and the budget supports it.

### Choose MultilingualPress when:
- You are already on **WordPress Multisite** or are willing to migrate to it.
- **Complete language isolation** is required — separate databases, separate sites, separate admin environments (typical for large enterprise networks or media groups).
- **WooCommerce multilingual with multi-store** (separate WooCommerce store per language) is needed.
- **Maximum performance** at scale is the primary concern — no per-request string processing, no shared database queries.
- You are comfortable with the **operational overhead** of managing a WordPress Multisite network.

---

## 14. Market Positioning Summary

| Plugin | Best fit | Core strength | Core limitation |
|---|---|---|---|
| **Lingua Forge** | Content-focused block-theme sites, developers, cost-sensitive projects | Zero cost + AI editorial depth + WP-CLI + FSE-native + native block model (no storage overhead) + subdomain routing (1.7.0) | WooCommerce not current priority; separate-domain routing not yet supported |
| **WPML** | Plugin-ecosystem-dependent sites, agencies, WooCommerce at scale | Market leader, widest compatibility, agency/CAT workflows | High cost, plugin bloat, metered AI credits |
| **Polylang** | Budget post-based sites where Lingua Forge is overkill | Lightweight, clean, widely understood | Free tier severely limited; Pro still needs DeepL separately |
| **TranslatePress** | Teams where visual front-end editing is priority | Front-end editor UX, transparent WooCommerce, predictable pricing | Render-time overhead + parallel string storage layer, no FSE template support |
| **Weglot** | Non-technical teams, multi-platform, speed of setup | Fastest setup, cloud handles all content types including JS | Highest cost at scale, data sovereignty concerns, strong lock-in |
| **MultilingualPress** | Enterprise, high-traffic, multisite-native, WooCommerce multi-store | Zero per-request overhead, complete isolation, performance | Requires Multisite, operational complexity, no free tier |

For a small to medium WordPress site on a block theme — a business site, a magazine, a portfolio, a non-profit — Lingua Forge 1.6.0 already covers the full multilingual workflow that every competitor charges €99–€200+/year to provide. It does so at zero licensing cost, with an AI editorial toolset deeper than anything in this market, designed for the FSE architecture from the ground up, and with no extra storage layer, no string indexing, and no content locked in a third-party cloud.

The honest differentiation is not "Lingua Forge does everything every competitor does." It is: **Lingua Forge does everything a content-focused, block-theme site actually needs from a multilingual plugin — permanently free — with AI assistance built in, a developer experience (WP-CLI, encryption, PHP API, no lock-in) that no competitor matches, and a native-block architecture that carries none of the overhead that string-interception or cloud-proxy tools require.**

Lingua Forge is a content-site plugin. Ecommerce sites with WooCommerce multilingual needs have well-supported dedicated options — and those competitors exist for good reason. For content-focused sites, everything in the competitive surface — path-prefix and subdomain routing, hreflang, FSE templates and template parts, navigation localisation, AI translation and generation, SEO meta output, WP-CLI, block-level granularity with refinement and rewrite — is fully covered at 1.7.0.

---

## 15. Sources and References

> All sources verified May 2026. Pricing pages change frequently — the ⚠ caution in §1 applies throughout.

### Pricing

- [WPML — Pricing](https://wpml.org/purchase/)
- [WPML — Automatic Translation Pricing](https://wpml.org/documentation/automatic-translation/automatic-translation-pricing/)
- [Polylang Pro — Pricing](https://polylang.pro/pricing/polylang-pro/)
- [Polylang Business Pack — Pricing](https://polylang.pro/pricing/polylang-business-pack/)
- [TranslatePress — Pricing](https://translatepress.com/pricing/)
- [Weglot — Pricing](https://www.weglot.com/pricing)
- [MultilingualPress — Pricing](https://multilingualpress.org/)

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
