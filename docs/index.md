# Lingua Forge — Documentation

Lingua Forge is a multilingual plugin for WordPress with full support for the Block Editor (FSE) and classic themes. These guides cover installation, server configuration, everyday translation workflows, and advanced topics.

Begin with **[G-01 Getting started](getting-started.md)** — it walks through the first install, initial configuration, and automatic updates. Come back to the server and performance guides once you are ready to choose a routing mode or optimise your hosting stack.

---

## Guides

### Setup and configuration

| # | Guide | What it covers |
|---|-------|----------------|
| G-01 | [Getting started](getting-started.md) | First installation from GitHub, initial configuration, adding languages, choosing a routing mode, connecting an AI provider, automatic updates, and uninstalling |
| G-02 | [Server setup for subdomain routing](server-subdomain-routing.md) | DNS wildcard records, SSL/TLS wildcard certificates, nginx and Apache configuration — everything the server needs before you switch to subdomain mode |
| G-03 | [WordPress caching — hints and strategies](wordpress-caching-hints.md) | OPcache, Redis object cache, Nginx page cache, Varnish, multilingual cache key separation, cookie analysis, bypass rules, and cache efficiency verification |

### Translation workflow

| # | Guide | What it covers |
|---|-------|----------------|
| G-04 | [Language switcher placement](language-switcher.md) | FSE block, `[lsflr_switcher]` shortcode, and classic widget; display options (label, icon, custom); viewport-aware positioning; CSS theming |
| G-05 | [Translation workflow](translation-workflow.md) | Translating posts, pages, custom post types, and WooCommerce products; FSE template and template-part localisation; navigation menu translation; block patterns; language-scoped search; Translation Memory and AI cache; glossary; keeping translations in sync |

### Advanced

| # | Guide | What it covers |
|---|-------|----------------|
| G-06 | [WP-CLI reference](wp-cli.md) | All `wp linguaforge` subcommands with flags and examples |
| G-07 | [Third-party integration API](integration-api.md) | Actions, filters, REST endpoints, and `linguaforge_trigger_translation()` for building integrations |

---

## What Lingua Forge handles

| Capability | Notes |
|---|---|
| Path-prefix routing (`/de/`, `/es/`) | Default; no server config required |
| Subdomain routing (`de.example.com`) | Requires wildcard DNS + SSL — see G-02 |
| Posts, pages, custom post types | All public CPTs supported automatically |
| WooCommerce products | Shared-stock delegation; translated content, delegated operational data — see G-05 |
| FSE templates and template parts | Scaffold + AI translate from the Router tab — see G-05 |
| Navigation menus | Per-language `wp_navigation` posts; label translation + link rewriting |
| Block patterns | CPT-scoped pattern translation from the Router tab |
| Language-scoped search | Results and search template scoped to active language automatically |
| Hreflang SEO tags | Injected in `<head>` for all active languages including `x-default` |
| Language switcher | FSE block, shortcode, classic widget — see G-04 |
| Translation Memory | Post-level cache keyed by content hash, language pair, provider, model |
| AI Response Cache | Chunk-level cache for Quick Translate, meta description, excerpt |
| Glossary | Term pinning applied to all AI translation operations |
| Outdated-translation indicators | Amber flag when source is newer than translation |
| AI providers | Anthropic, OpenAI, Gemini — configurable per tier |

---

## Routing modes at a glance

| Mode | Example URL | Server requirement |
|---|---|---|
| Path prefix *(default)* | `example.com/es/about/` | None — WordPress handles everything |
| Subdomain | `es.example.com/about/` | Wildcard DNS + wildcard SSL — see [G-01](server-subdomain-routing.md) |

Switch between modes at any time in **Settings → Lingua Forge → Router → URL structure**.

---

## Getting help

- **Plugin settings** — most settings include an inline description explaining what the option does and when to change it.
- **GitHub issues** — bug reports, feature requests, and questions at https://github.com/leotiger/lingua-forge/issues.
- **Plugin site** — https://lingua-forge.com
