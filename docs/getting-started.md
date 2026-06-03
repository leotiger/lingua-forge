# G-01 — Getting started

This guide covers the first manual installation, initial configuration, and the automatic update mechanism. After completing these steps your site will be routing visitors to language-specific URLs and you will be ready to start translating content.

---

## Chapters

1. [Requirements](#1-requirements)
2. [First installation](#2-first-installation)
3. [Initial configuration](#3-initial-configuration)
4. [Adding languages](#4-adding-languages)
5. [Choosing a routing mode](#5-choosing-a-routing-mode)
6. [Connecting an AI provider](#6-connecting-an-ai-provider)
7. [Automatic updates](#7-automatic-updates)
8. [Uninstalling](#8-uninstalling)

---

## 1. Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.4 |
| PHP | 8.1 |
| MySQL / MariaDB | WordPress minimum |
| WooCommerce *(optional)* | 9.0 (requires WordPress 6.9) |

No additional PHP extensions, Node.js, or build tools are required at runtime. All dev tooling lives in `dev/` and is excluded from the distribution.

---

## 2. First installation

Lingua Forge is not listed in the WordPress.org Plugin Directory. Install it directly from GitHub.

**Step 1 — Download the latest release ZIP**

Go to https://github.com/leotiger/lingua-forge/releases and download the most recent `lingua-forge-x.x.x.zip` file.

**Step 2 — Upload via WordPress admin**

1. In WordPress admin go to **Plugins → Add New → Upload Plugin**.
2. Choose the downloaded ZIP and click **Install Now**.
3. Click **Activate Plugin**.

**Step 3 — Flush rewrite rules**

Go to **Settings → Permalinks** and click **Save Changes**. This registers the language URL prefixes with WordPress. You do not need to change any permalink setting — just save.

---

## 3. Initial configuration

After activation, go to **Settings → Lingua Forge**.

**Router tab — required:**

1. Set your **Primary content language** — the language your existing content is written in. This is the source language; all translations are created from it.
2. Set your **URL structure** — path prefix (`example.com/de/`) or subdomain (`de.example.com`). Path prefix requires no server changes. Subdomain requires DNS and SSL setup — see [G-02 — Server setup for subdomain routing](server-subdomain-routing.md).
3. Click **Save**.

After saving, go back to **Settings → Permalinks** and click **Save Changes** again to register the new language prefix rules.

---

## 4. Adding languages

In the **Router tab**, find the **Active languages** section and add each language you want to support. Each language gets:

- A two-letter ISO 639-1 code (`en`, `de`, `es`, `ca`, …)
- A URL prefix in path mode (`/de/`) or a subdomain in subdomain mode (`de.example.com`)
- An hreflang tag injected automatically in `<head>`

There is no limit on the number of languages.

---

## 5. Choosing a routing mode

| Mode | URL shape | Server requirement |
|---|---|---|
| **Path prefix** | `example.com/de/about/` | None |
| **Subdomain** | `de.example.com/about/` | Wildcard DNS + wildcard SSL — see [G-02 — Server setup for subdomain routing](server-subdomain-routing.md) |

Path prefix is the default and works on any hosting. Choose subdomain if your SEO or branding strategy requires fully separate hostnames per language.

You can switch between modes at any time in **Settings → Lingua Forge → Router → URL structure**. Switching generates new permalink rules — visit **Settings → Permalinks → Save Changes** after every mode switch.

---

## 6. Connecting an AI provider

AI features (translation, content generation, meta description, excerpt) require an API key from at least one of the three supported providers.

Go to **Settings → Lingua Forge → API Keys** and enter your key:

| Provider | Where to get a key |
|---|---|
| Anthropic | https://console.anthropic.com |
| OpenAI | https://platform.openai.com/api-keys |
| Gemini | https://aistudio.google.com/app/apikey |

Select your active provider in **Settings → Lingua Forge → General**. Only one provider is active at a time; the same provider handles all AI features.

**API keys are encrypted** at rest using AES-256-GCM via a site-specific secret (`LINGUAFORGE_SECRET`). Do not share database exports without rotating the secret first. See `CONTRIBUTING.md` for cross-environment key management.

AI features are optional. Language routing, hreflang, the language switcher, and the translation group system all work without an AI provider configured.

---

## 7. Automatic updates

After the first manual install, Lingua Forge registers itself with WordPress's built-in update mechanism. WordPress checks for new releases every 12 hours and displays the standard update badge in **Plugins → Installed Plugins** when one is available.

Updates are applied in exactly the same way as any WordPress plugin — click **Update now** in the plugin list, or use WP-CLI:

```bash
wp plugin update lingua-forge
```

No re-upload of a ZIP is ever needed after the first install.

**How it works:** the plugin queries `https://lingua-forge.com/wp-json/lingua-forge/v1/update` for the current version manifest. The manifest points to the release ZIP on GitHub. WordPress downloads, verifies, and applies the update in place.

---

## 8. Uninstalling

Deactivating the plugin stops all routing, hreflang injection, and AI features immediately. Your translated posts and all plugin options remain in the database — reactivating restores everything.

**Full removal:**

1. Deactivate the plugin in **Plugins → Installed Plugins**.
2. Click **Delete**.

Plugin options (`linguaforge_*`) and post meta (`_lf_lang`, `_lf_trid`, etc.) are **not** removed automatically on deletion. This is intentional — accidental deactivation should not destroy your content. To clean up the database after a permanent uninstall, use the WP-CLI command:

```bash
wp linguaforge uninstall --yes
```

This removes all plugin options, post meta, and custom database tables. It cannot be undone.
