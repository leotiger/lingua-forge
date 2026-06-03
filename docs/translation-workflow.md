# G-05 — Translation workflow

This guide covers the day-to-day translation workflow for posts, pages, custom post types, WooCommerce products, FSE templates, and navigation menus.

---

## Chapters

1. [How content is organised by language](#1-how-content-is-organised-by-language)
2. [Translating posts, pages, and custom post types](#2-translating-posts-pages-and-custom-post-types)
3. [Language-scoped search](#3-language-scoped-search)
4. [WooCommerce products](#4-woocommerce-products)
5. [FSE templates and template parts](#5-fse-templates-and-template-parts)
6. [Navigation menus](#6-navigation-menus)
7. [Block patterns](#7-block-patterns)
8. [Translation Memory and AI cache](#8-translation-memory-and-ai-cache)
9. [Glossary](#9-glossary)
10. [Keeping translations in sync](#10-keeping-translations-in-sync)

---

## 1. How content is organised by language

Every translatable post carries two meta fields:

- `_lf_lang` — the two-letter language code (`en`, `de`, `ca`, …)
- `_lf_trid` — a shared UUID that groups the source post and all its translations together

There is no separate translation database. Translated posts are ordinary WordPress posts — they appear in the standard post list, carry their own permalink, and are indexed by search engines independently.

The source language is set in **Settings → Lingua Forge → Router**. It is the language your original content is written in. All other languages are translation targets.

---

## 2. Translating posts, pages, and custom post types

All public custom post types receive the full Lingua Forge admin layer automatically — no configuration needed. This includes WooCommerce `product` posts and any third-party CPT.

**Creating a translation:**

1. Open the source-language post in the block editor.
2. Find the **Lingua Forge** metabox in the sidebar (or below the editor on classic screens).
3. Select the target language and click **Translate with AI** — or choose **Create blank** to start with an empty post.
4. The translated post opens for review. Edit as needed and publish.

**Post list indicators:**

The post list gains a **Lang** column showing the language of each post. Colour indicators flag:

- **Green** — translation is up to date
- **Amber** — source has been edited since this translation was saved
- **Red / missing** — no translation exists for one or more active languages

**Translating all missing languages at once:**

Click the **Translate missing** button in the Lang column of a source post to generate all missing translations in a single operation.

**Opting a post type out:**

```php
// Remove a CPT from the Lang column
add_filter( 'linguaforge_column_post_types', function( array $types ): array {
    return array_diff( $types, [ 'my_cpt' ] );
} );

// Remove a CPT from the AI translation metabox
add_filter( 'linguaforge_ai_metabox_post_types', function( array $types ): array {
    return array_diff( $types, [ 'my_cpt' ] );
} );
```

---

## 3. Language-scoped search

WordPress search (`/?s=query`) is automatically scoped to the active language. A search on `example.com/de/` returns only German posts; a search on `example.com/` (source language) returns only source-language posts.

In path-prefix mode the search URL carries the `?lang=` parameter (`/?s=query&lang=de`). In subdomain mode the subdomain itself is the language signal — no extra parameter is added.

FSE themes get a language-specific search results template automatically when one exists (`search-de.html`, `search-en.html`, etc.). The plugin selects the correct template at render time based on the active language.

---

## 4. WooCommerce products

WooCommerce products use a **shared-stock delegation model**. Translated products carry only content fields — title, description, excerpt, and meta description. All operational data is served transparently from the source product at runtime:

| Field | Where it lives |
|---|---|
| Title, description, excerpt | Translated product post |
| Meta description | Translated product post |
| Price, SKU, stock, weight, dimensions | Source product (delegated automatically) |
| Images, gallery | Source product (delegated automatically) |
| Category, tag, attribute term *names* | Displayed in the visitor's language via term-name meta |
| Variations (operational data) | Source product's variations (delegated automatically) |

**Creating a translated product:**

Identical to any other post — open the source product, use the Lingua Forge metabox, select the target language, and translate. The delegation layer activates immediately; no stock sync or SKU duplication is needed.

**Term name translation:**

Category, tag, and attribute term names (e.g. "Red", "Large") can be translated from the term edit screen (**Products → Categories**, **Products → Attributes**, etc.). A "Lingua Forge translations" section appears at the bottom of each term edit form.

---

## 5. FSE templates and template parts

FSE templates and template parts are localised from **Settings → Lingua Forge → Router** (the Router tab), not from the post editor.

**Workflow:**

1. Open **Settings → Lingua Forge → Router → Language Setup**.
2. The table lists all templates and template parts detected in the active theme, including WooCommerce templates.
3. For each template row, click **Scaffold** to create a language-specific copy (`single-de.html`, `page-about-de.html`, etc.).
4. Click **Translate** to fill the scaffolded template with AI-translated content.
5. Click **Fix links** to rewrite internal URLs in the template to their language-specific equivalents.
6. Click **Fix parts** to rewrite template-part references to point at the language-specific part slugs.

**CPT-specific templates:**

`single-{post_type}` and `archive-{post_type}` rows appear automatically for any public CPT whose base template is shipped by the active theme. The scaffold and translate operations work identically.

**Block patterns:**

The **Patterns** section in the Router tab lists CPT-scoped block patterns. Click **Translate** to generate language-specific copies for use in translated CPT posts.

---

## 6. Navigation menus

Navigation menus in FSE themes are `wp_navigation` posts. Each language gets its own navigation post, named by convention `navigation-{lang}` (e.g. `navigation-de`, `navigation-en`).

**Translating a navigation:**

1. In the Router tab, find the navigation post in the **Navigations** section.
2. Click **Translate** — the plugin sends only the visible link labels to the AI, preserving all URL structure.
3. After translation, click **Fix nav refs** to rewrite any internal links to their language-specific equivalents.

Menu links that point to translated posts are rewritten automatically using the TRID translation group. Links to external URLs are left unchanged.

---

## 7. Block patterns

Block patterns scoped to a CPT (e.g. a "Product highlight" pattern for `product`) are translated from the **Patterns** section of the Router tab. The translated pattern is stored and available for copy-paste into translated CPT posts. This is distinct from FSE template translation — patterns are reusable content blocks, not structural templates.

---

## 8. Translation Memory and AI cache

**Translation Memory (TM)** stores every post-level translation keyed by source text hash, language pair, provider, and model. On subsequent translation runs the TM is checked first — if a match is found the stored result is returned instantly without an API call.

**AI Response Cache** stores chunk-level translations (Quick Translate toolbar, excerpt and meta description generation). Cache hits are shown with a badge in the result panel; a **↺ Re-translate** button bypasses the cache and forces a fresh API call when needed.

Both stores are visible and can be cleared from **Settings → Lingua Forge → Maintenance → Translation Caching**.

---

## 9. Glossary

The glossary lets you pin specific translations for domain-specific terms — product names, brand names, legal terminology — so the AI always uses your preferred wording.

Manage entries at **Settings → Lingua Forge → Glossary**. Each entry has a source term, a target term, and a language pair. The glossary is included automatically in the system prompt for all translation operations: post translation, chunk translation, meta description, excerpt, FSE template translation, navigation translation, and pattern translation.

---

## 10. Keeping translations in sync

When the source post is edited and saved, Lingua Forge records the edit timestamp in `_lf_source_updated_at`. Translated posts compare this against their own `_lf_translation_source_updated_at` and display an amber outdated indicator in the post list when the source is newer.

**Retranslating an outdated post:**

Click **Retranslate** in the Lang column. The existing translated post is updated in place — permalink, TRID link, and any manually edited fields outside the translated content are preserved.

**Bulk retranslation via WP-CLI:**

```bash
wp linguaforge retranslate --post_type=post --lang=de
wp linguaforge fill-translations --post_type=product --lang=es
```

See the WP-CLI reference guide for the full command list.
