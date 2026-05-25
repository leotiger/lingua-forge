# Lingua Forge — Documentation

Lingua Forge is a multilingual plugin for WordPress with full support for the Block Editor (FSE) and classic themes. These guides cover server configuration, everyday translation workflows, and advanced topics.

If you are just getting started, begin with **[Getting Started](getting-started.md)** and come back to the server guides once you are ready to choose a routing mode.

---

## Guides

### Setup and configuration

| # | Guide | What it covers |
|---|-------|----------------|
| G-01 | [Server setup for subdomain routing](server-subdomain-routing.md) | DNS wildcard records, SSL/TLS wildcard certificates, nginx and Apache configuration — everything the server needs before you switch Lingua Forge to subdomain mode |
| G-02 | [Getting started](getting-started.md) | Install, activate, add your first language, choose path-prefix or subdomain routing |

### Day-to-day use

| # | Guide | What it covers |
|---|-------|----------------|
| G-03 | [Language switcher placement](language-switcher.md) | Placing the switcher in an FSE header, navigation block, or widget area; classic theme workarounds |
| G-04 | [Translation workflow](translation-workflow.md) | Translating posts, pages, menus, and theme strings; managing translation groups |

### Advanced

| # | Guide | What it covers |
|---|-------|----------------|
| G-05 | [AI translation tools](ai-tools.md) | Connecting OpenAI or DeepL, bulk-translating content, reviewing AI drafts |
| G-06 | [WP-CLI reference](wp-cli.md) | All available commands with flags and examples |

---

## Routing modes at a glance

Lingua Forge supports two URL structures. The choice affects how you configure your server.

| Mode | Example URL | Server requirement |
|------|-------------|-------------------|
| Path prefix *(default)* | `example.com/es/about/` | None — WordPress handles everything |
| Subdomain | `es.example.com/about/` | Wildcard DNS record + wildcard SSL certificate — see [G-01](server-subdomain-routing.md) |

You can switch between modes at any time in **Lingua Forge → Router → URL structure**.

---

## Getting help

- **Plugin settings** — every option has inline help text; look for the ⓘ icon.
- **WordPress.org support forum** — post questions and bug reports at wordpress.org/support/plugin/lingua-forge.
- **GitHub issues** — for confirmed bugs and feature requests.
