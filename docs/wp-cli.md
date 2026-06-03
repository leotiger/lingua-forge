# G-06 — WP-CLI reference

All Lingua Forge commands are grouped under `wp linguaforge`. Run any subcommand with `--help` to see the full option list directly in your terminal.

```bash
wp linguaforge --help
wp linguaforge translate --help
```

---

## Subcommands

### `translate`

Translate a single post into one or more target languages. Runs the full pipeline: Translation Memory check → AI call → post create or update → cache write.

```bash
wp linguaforge translate <post_id> --to=<langs> [options]
```

| Option | Description |
|---|---|
| `--to=<langs>` | **Required.** Comma-separated target language codes, e.g. `fr,de,es` |
| `--dry-run` | Generate translation but do not write to the database |
| `--force` | Bypass the AI response cache; always makes a fresh API call |
| `--draft` | Create new translated posts as `draft` regardless of source status |
| `--with-meta-description` | Also generate and save an AI meta description for each target post |
| `--temperature=<float>` | Override AI temperature (0.0–1.0) |
| `--max-tokens=<int>` | Override max output tokens; useful for very long posts |
| `--model=<name>` | Override the model string, e.g. `claude-opus-4-6` |
| `--debug` | Print the full system prompt and raw API response to the terminal |
| `--format=<format>` | Output format: `table` (default), `json`, `csv`, `yaml` |

**Examples:**

```bash
# Translate post 123 into French and German
wp linguaforge translate 123 --to=fr,de

# Translate and hold new posts in draft for editorial review
wp linguaforge translate 123 --to=fr,de --draft

# Dry-run to inspect output quality before committing
wp linguaforge translate 123 --to=fr --dry-run --temperature=0.1

# Translate and generate meta descriptions for all targets
wp linguaforge translate 123 --to=fr,de --with-meta-description
```

---

### `retranslate`

Force a fresh retranslation of an existing post, clearing the prior cached result first. On success the outdated indicator in the post list is cleared.

```bash
wp linguaforge retranslate <post_id> --to=<langs> [options]
```

Accepts the same options as `translate`. The key difference: the cache is always cleared before the API call, and the outdated sync flag is reset afterwards.

**Examples:**

```bash
# Retranslate post 123 into French after editing the source
wp linguaforge retranslate 123 --to=fr

# Use legal-grade temperature for a terms-of-service page
wp linguaforge retranslate 123 --to=fr,de --temperature=0.1

# Dry-run to verify quality before committing
wp linguaforge retranslate 123 --to=es --dry-run
```

---

### `fill_translations`

Check all active languages for a given post and translate any that do not yet have a TRID-linked post. Languages that already have a linked post are always skipped — use `retranslate` to update existing ones.

```bash
wp linguaforge fill_translations <post_id> [options]
```

| Option | Description |
|---|---|
| `--check-only` | Report missing languages without making API calls; exits with code 1 when any are missing |
| `--dry-run` | Run API calls but do not write to the database |
| `--draft` | Create all new translation posts as `draft` |
| `--exclude=<langs>` | Comma-separated language codes to skip, e.g. `--exclude=it,fr` |
| `--with-meta-description` | Also generate and save meta descriptions for each new post |
| `--temperature`, `--max-tokens`, `--model`, `--debug`, `--format` | Same as `translate` |

**Examples:**

```bash
# Check which translations are missing for post 123
wp linguaforge fill_translations 123 --check-only

# Fill all missing translations as drafts
wp linguaforge fill_translations 123 --draft

# Fill missing, skip Italian
wp linguaforge fill_translations 123 --exclude=it --draft

# CI check — exit 1 when any translation is missing
wp linguaforge fill_translations 123 --check-only --format=json
```

---

### `missing_translations`

Scan all posts of a given post type and source language, and list every post that is missing one or more active-language translations. Use as a work-list to drive `fill_translations` in bulk.

```bash
wp linguaforge missing_translations <lang> <post_type> [options]
```

| Option | Description |
|---|---|
| `--exclude=<langs>` | Comma-separated language codes to ignore when checking |
| `--status=<status>` | Only include posts with this status (default: `publish`; accepts any WP status or `any`) |
| `--format=<format>` | Output format: `table` (default), `json`, `csv`, `yaml` |

**Examples:**

```bash
# Show all Catalan pages with missing translations
wp linguaforge missing_translations ca page

# Include drafts as well as published posts
wp linguaforge missing_translations ca post --status=any

# Machine-readable output piped to fill_translations
wp linguaforge missing_translations ca page --format=json \
    | jq -r '.[].post_id' \
    | xargs -I{} wp linguaforge fill_translations {} --draft
```

---

### `cache_clear`

Clear AI response cache entries. With no options, truncates the entire cache table — equivalent to clicking **Maintenance → AI Cache → Clear AI Cache** in the admin.

```bash
wp linguaforge cache_clear [options]
```

| Option | Description |
|---|---|
| `--feature=<name>` | Clear only this feature's entries: `translation`, `meta-description`, `excerpt`, `content_generator` |
| `--post-id=<id>` | Clear only entries for this post ID |
| `--yes` | Skip the confirmation prompt when truncating the whole table |

**Examples:**

```bash
# Clear every cached translation across the whole site
wp linguaforge cache_clear --feature=translation

# Clear all cached AI results for one post
wp linguaforge cache_clear --post-id=123

# Nuke the whole cache without a confirmation prompt
wp linguaforge cache_clear --yes
```

---

### `fix_nav_lang`

Backfill `_lf_lang` and `_lf_trid` meta on `wp_navigation` posts created before v2.1.0. Language is inferred from the post slug suffix (e.g. `navigation-de` → `de`). Navigation posts that share a base slug are linked under a shared TRID group.

```bash
wp linguaforge fix_nav_lang [--dry-run]
```

**Examples:**

```bash
# Preview what would be tagged and linked
wp linguaforge fix_nav_lang --dry-run

# Apply the fix
wp linguaforge fix_nav_lang
```

---

## Bulk workflow example

Translate all published Catalan pages that are missing a German or French translation, create them as drafts, and generate meta descriptions:

```bash
wp linguaforge missing_translations ca page --format=json \
    | jq -r '.[].post_id' \
    | xargs -I{} wp linguaforge fill_translations {} \
        --exclude=es \
        --draft \
        --with-meta-description
```
