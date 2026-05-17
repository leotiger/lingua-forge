# Contributing to LinguaForge

This document covers the conventions you need to know before adding to or
modifying the LinguaForge plugin. The most-asked question is **"which
prefix do I use for this thing?"** — that's at the top.

---

## Prefix policy

LinguaForge uses **five** distinct prefixes. Each has a specific scope.
Pick the right one when introducing a new option, hook, constant, class,
or DOM element — the prefix communicates *what kind of thing this is and
who owns it* at a glance.

### Quick reference

| Prefix          | Case        | Used for                                                              |
|-----------------|-------------|-----------------------------------------------------------------------|
| `linguaforge_`  | lowercase   | Long-form identifiers: option keys, admin_post / wp_ajax actions, AI-module filter hooks, transient prefixes |
| `lf_`           | lowercase   | Short-form identifiers: post/user-meta keys, form field names, nonce names, Language-Router filter hooks, GET-flag query args |
| `LF_`           | UPPERCASE   | One runtime constant defined at file-load time (currently only `LF_LANG`) |
| `LINGUAFORGE_`  | UPPERCASE   | Plugin-wide PHP constants — paths, URLs, version, and the wp-config-overridable behavior switches |
| `lsflr_` / `LSFLR_` | mixed   | **Legacy** — the language-switcher / link-fixer module, kept for back-compat with theme code that adopted the old mu-plugin function names. Do not introduce new identifiers under this prefix |

### `linguaforge_*` — long lowercase

Use for any identifier that should clearly belong to LinguaForge from
outside the plugin namespace.

- **`wp_options` keys.** Examples: `linguaforge_provider`,
  `linguaforge_ai_daily_quota`, `linguaforge_compliance_temperature`,
  `linguaforge_block_editor_allow_lock_blocks`.
- **`admin_post_*` and `wp_ajax_*` action names.** Examples:
  `admin_post_linguaforge_clear_ai_cache`,
  `wp_ajax_linguaforge_test_provider`.
- **Filter hooks exposed by the AI sub-module.** These are the
  "settings-on-top-of-options" knobs that integrators tune from a custom
  plugin or theme. Examples: `linguaforge_ai_retry_policy`,
  `linguaforge_required_capability`, `linguaforge_debug_dir`,
  `linguaforge_ai_should_boot`, `linguaforge_ai_rate_limit`,
  `linguaforge_ai_daily_quota`, `linguaforge_translation_worker_config`
  (per-invocation model / temperature / max_tokens override for the
  translation feature; receives `WorkerConfig`, `$post_id`, `$params`).
- **Transient name prefixes.** Examples:
  `linguaforge_rate_user_{id}_{endpoint}`,
  `linguaforge_quota_daily_used_{Ymd}`.

When you add a new option or hook, default to `linguaforge_` unless the
identifier ships in form-field land (where `lf_` is more idiomatic).

### `lf_*` — short lowercase

Use for identifiers that appear in the database, the DOM, or the URL where
a short name is meaningfully more readable.

- **Post meta and user meta keys.** Examples: `lf_lang_filter`
  (user-level admin filter), every `lf_trans_{lang}` form field that maps
  back to TRID groups.
  - **Exception:** the underscore-prefixed `_lang`, `_trid`,
    `_lang_previous`, `_source_updated_at`, `_translation_source_updated_at`,
    `_search_content` meta keys are intentionally generic so other plugins
    can read them. They predate the prefix policy and are now part of the
    plugin's data contract — preserve them.
- **`<input name="…">` form field names.** Examples: `lf_lang`,
  `lf_trans_{lang}`, `lf_page_template`.
- **Nonce names and actions.** Examples: `lf_language_nonce` /
  `lf_language_save`, `lf_translations_nonce` / `lf_translations_save`,
  `lf_import_translation_nonce`.
- **Filter hooks exposed by the Language Router sub-module.** These are
  the public API for theme code and other plugins integrating with
  routing/translation. Examples: `lf_primary_language`,
  `lf_languages_list`, `lf_hreflang_mode`, `lf_hreflang_x_default`,
  `lf_block_editor_restrictions`, `lf_lang_default_fallback`,
  `lf_lang_fallback_map`, `lf_lang_force_locale`, `lf_i18n_overrides_dir`.
- **GET-flag query args** set by `wp_safe_redirect()` after an
  admin-post handler so the success notice renders on the next request.
  Examples: `lf_override_uploaded`, `lf_cache_cleared`, `lf_debug_cleared`,
  `lf_debug_setting_saved`.

Why two prefixes? `linguaforge_` reads cleanly when you're scanning
`wp_options` or grep-ing PHP source. `lf_` is short enough to type into
form-field markup and short enough to read in browser dev-tools, where
length is annoying.

### `LF_*` — uppercase runtime constant

Currently there's exactly one of these: **`LF_LANG`**, the active language
code for the current request. Defined inside
`Language_Router::define_lang_constant()` at file-load time so theme code
can read it everywhere as a global without going through a function call.

If you introduce another runtime constant of similar nature — set by the
plugin during boot, read everywhere — use `LF_` to match. If it's an
override knob that the *end-user* sets in `wp-config.php`, use
`LINGUAFORGE_` instead.

### `LINGUAFORGE_*` — uppercase PHP constants

Two flavors:

1. **Plugin-internal constants** defined by the plugin during its own
   bootstrap. These are paths, URLs, and version metadata:
   `LINGUAFORGE_PATH`, `LINGUAFORGE_URL`, `LINGUAFORGE_VERSION`,
   `LINGUAFORGE_FILE`, `LINGUAFORGE_AI_PATH`, `LINGUAFORGE_AI_URL`.
2. **wp-config.php override constants** that end-users can define to
   control plugin behavior outside the database. These bypass or take
   precedence over the corresponding settings UI:
   `LINGUAFORGE_PROVIDER`, `LINGUAFORGE_AI_DEBUG`, `LINGUAFORGE_SECRET`,
   `LINGUAFORGE_LANG_FALLBACK_ACTIVE`.

When you add a new override constant, follow the WP_DEBUG pattern: the
constant always wins over the database option, and the Settings UI
displays a "forced by constant" indicator when the constant is defined.

### `lsflr_*` / `LSFLR_*` — legacy

Originates from the standalone mu-plugin
"LanguageSwitcher-ForLanguageRouter" that was folded into LinguaForge in
v1.0. As of 1.2.0 the classes are namespaced (`LinguaForge\Router\Switcher`
/ `LinguaForge\Router\LinkFixer`) with `class_alias` back-compat; the prefix
survives in the public-facing identifiers below:

- Back-compat aliases: `LSFLR_Switcher`, `LSFLR_Link_Fixer` (removal target: 1.5).
- Template-callable functions: `linguaforge_lsflr_render_switcher()`,
  `linguaforge_lsflr_get_languages()`,
  `linguaforge_lsflr_translate_current_url()`.
- AJAX action names: `wp_ajax_lsflr_scan_links`, `wp_ajax_lsflr_fix_post`.
- DOM element IDs / CSS classes inside the Link Fixer modal:
  `lsflr-fixer-*`, `lsflr-fix-*`.

**Do not extend** this prefix in new code. New language-switcher /
link-fixer features should land under `lf_` (filter hooks),
`linguaforge_` (options / ajax actions), or namespaced class names
inside `LinguaForge\Router\…`. The legacy identifiers stay only
because theme code in the wild already calls them.

---

## Namespaces and class layout

Modern code lives under three namespaces:

- `LinguaForge\Router\…` — the Language Router sub-module (file location:
  `language-router/includes/`). All three classes are namespaced as of 1.2.0:
  - `LinguaForge\Router\Router` (aliased `Language_Router`)
  - `LinguaForge\Router\Switcher` (aliased `LSFLR_Switcher`)
  - `LinguaForge\Router\LinkFixer` (aliased `LSFLR_Link_Fixer`)
  Back-compat aliases are slated for removal in 1.5. New code in this
  sub-module should go straight into the namespace using the canonical names.
- `LinguaForge\AI\Core\…`, `LinguaForge\AI\Providers\…`,
  `LinguaForge\AI\Features\…`, `LinguaForge\AI\Admin\…`,
  `LinguaForge\AI\REST\…`, `LinguaForge\AI\Contracts\…` — the AI
  sub-module (file location: `ai/includes/`). Loaded via a small PSR-4
  autoloader at `ai/includes/Core/Autoloader.php`.
- `LinguaForge\MetaDescription\…` — the Meta Description sub-module
  (`meta-description/meta-description.php`). Currently a single
  `LinguaForge\MetaDescription\Module` class; self-boots via `Module::init()`
  at the bottom of the file.

### PHP namespacing — audit before shipping

When you wrap an existing file in a namespace, **every global-class
reference becomes an unresolved class name** — PHP does not auto-fall-back
classes to the global namespace the way it does for functions and
constants. The audit script in this repo's history (see the project
memory `feedback_php_namespacing.md`) covers every reference site:

```
new ClassName(            # instantiation
: ClassName               # return type / after colon
(ClassName $var           # param type hint
instanceof ClassName      # type check
ClassName::               # static call / ::class
catch (ClassName          # catch block
throw new ClassName       # exception
```

For each hit that isn't a same-namespace class, either add
`use ClassName;` at the top or prefix the reference with `\`. A clean
brace/paren tokenizer pass does **not** catch this — the file parses
correctly; the failure is at runtime when PHP tries to resolve the
missing class.

---

## File and feature locations

```
lingua-forge.php              Plugin entry point; defines constants, loads sub-modules
uninstall.php                 Wipe-on-delete handler (named options + LIKE prefixes + tables)

language-router/              Routing, locale, translations, hreflang, admin meta boxes
  language-router.php         Sub-module bootstrap + procedural template wrappers
  includes/                   Class files
  assets/                     CSS / JS enqueued into admin

ai/                           AI features (translation, meta-description, excerpt, content gen, revise)
  ai.php                      Sub-module bootstrap
  includes/                   PSR-4 class files under LinguaForge\AI\…
  assets/                     CSS / JS for the editor toolbar + Settings page
  templates/prompts/          AI prompt templates (translation.txt, block-revision.txt, …)

meta-description/             Meta Description module — LinguaForge\MetaDescription\Module class
```

The `REVIEW.md` at the repo root documents the architectural review
findings and the prioritized refactor roadmap. Several roadmap items
remain open (see that document); be aware of the in-flight conventions
when adding new code.

---

## Coding conventions

- **PHP 8.0+ syntax** is fine — the plugin requires PHP 8.0 (declared in
  the header). Constructor property promotion, named arguments,
  `match` expressions, `readonly` properties, union return types are all
  in use.
- **WordPress Coding Standards** are tracked via `phpcs`; the codebase
  is clean against the WordPress.org standard with documented
  `phpcs:ignore` for every justified exception.
- **Tabs in PHP**, 4-space indent in the AI module's class files (this is
  a historical split — match the existing indentation of the file you're
  editing).
- **Capability checks** at every entry point. The plugin defaults to
  `current_user_can('edit_posts')` for content features, gated through
  `FeatureController::required_capability()` which is filterable via
  `linguaforge_required_capability`. Cap-elevation goes through the
  Settings UI's "Minimum role" dropdown, not by hand-editing the cap
  string anywhere.
- **Nonces on every state-changing request.** Admin-post handlers use
  `check_admin_referer`, AJAX endpoints use `check_ajax_referer`. Add a
  dedicated nonce per metabox / form rather than relying on WP's
  edit_post nonce.
- **Sanitize on input, escape on output.** Standard WP idioms:
  `sanitize_key`, `sanitize_text_field`, `wp_unslash`, `absint` on the
  way in; `esc_html`, `esc_attr`, `esc_url`, `wp_json_encode` on the way
  out.

---

## Settings page layout

The Settings page (`Settings → LinguaForge AI`) uses a five-tab layout
(General / API Keys / Limits / Behavior / Maintenance). All four
"settings" tabs live inside a single `<form>` so one Save Settings click
persists every value. The Maintenance tab is outside the main form
because its entries are operational forms (override upload, cache clear,
debug-files clear) each with their own admin-post action.

When adding a new setting, decide which tab it belongs in:

- **General** — provider, models. Things the admin configures once.
- **API Keys** — API keys and the Test Connection AJAX flow.
- **Limits** — quotas, rate limits, capability gate, per-feature token /
  character caps.
- **Behavior** — toggles that change *how* the AI features act (block
  editor restrictions, AI behavior preset — Standard / Technical / Legal / Creative).
- **Maintenance** — operational forms (cache, debug files, language
  overrides).

Tab state is preserved across the save-redirect cycle via
`sessionStorage`. Each tab is deep-linkable via URL hash (`#behavior`,
`#limits`, etc.).

---

## WP-CLI

The plugin registers a `linguaforge` WP-CLI command namespace when
`WP_CLI` is defined. Registration happens eagerly at AI sub-module load
time (`ai/ai.php`); the command class itself (`LinguaForge\AI\CLI\Commands`)
is autoloaded on the first method dispatch.

Currently shipped:

- **`wp linguaforge translate <post_id> --to=fr,de[,…]`** — runs the
  Translation feature for each target language, then writes the result
  into the TRID-linked target-language post via `wp_update_post`. The
  `wp_after_insert_post` handlers are temporarily detached during the
  write so the content-only update doesn't touch the TRID group,
  language metadata, or outdated flag. Languages without a TRID-linked
  post are skipped with a warning rather than auto-created (that's the
  future `translate-missing` command's territory). Per-invocation
  overrides: `--temperature=<float>`, `--max-tokens=<int>`,
  `--model=<name>`, `--force` (skip cache), `--dry-run` (generate but
  don't write).

- **`wp linguaforge cache-clear`** — wipes AI-result cache entries.
  Bare command truncates the whole table; `--feature=translation` scopes
  to feature-key prefix; `--post-id=N` scopes to a single post; both
  combine. Bare-truncate prompts unless `--yes` is passed.

When adding a new command, add a public method to
`LinguaForge\AI\CLI\Commands` with a WP-CLI-flavored docblock (the
`## OPTIONS` / `## EXAMPLES` blocks render as the `wp help` output).
WP-CLI's snake_case-to-hyphen mapping turns `public function foo_bar()`
into `wp linguaforge foo-bar`.

For commands that need to override the worker model / temperature /
max_tokens, use the `linguaforge_translation_worker_config` filter (see
the filters list below) rather than reaching into the feature's worker
config directly. The CLI's `translate` command is the reference
implementation — registers a closure on the filter, runs the feature,
removes the closure.

---

## Direct SQL and phpcs:ignore conventions

The plugin owns several custom tables (`lingua_forge_ai_cache`,
`lingua_forge_ai_usage`, `lingua_forge_ai_glossary`,
`lingua_forge_ai_tm`). WordPress Coding Standards' `WordPress.DB.*`
ruleset is conservative: it flags every direct `$wpdb->…` call, every
variable passed to `prepare()`, and every interpolation into a SQL
string — even when the SQL is safe by construction.

When the SQL must be dynamic (variable WHERE clauses built from a
whitelisted criteria array, `IN (...)` lists sized to a query-time
array, etc.) inlining a literal isn't an option. The canonical pattern
in this codebase:

```php
// Build WHERE / IN from a whitelisted list of %s/%d placeholder fragments.
$where = [];
$values = [];
if (isset($criteria['user_id']) && (int) $criteria['user_id'] > 0) {
    $where[]  = 'user_id = %d';
    $values[] = (int) $criteria['user_id'];
}
// …

$sql = "SELECT * FROM {$table}";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read on the plugin's own table; WHERE built from a whitelisted format-string list above; $values bound through %d/%s placeholders.
$rows = $values
    ? $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A)
    : $wpdb->get_results($sql, ARRAY_A);
```

Up to five rules can fire on the same line; the ignore directive needs
to list every one that actually fires:

- **`WordPress.DB.DirectDatabaseQuery.DirectQuery`** — fires on any
  direct `$wpdb->…` call. Justified because the four custom tables
  have no WP API equivalent.
- **`WordPress.DB.DirectDatabaseQuery.NoCaching`** — fires because the
  read isn't wrapped in `wp_cache_*`. Justified because these tables
  are themselves caches / telemetry — wrapping with object-cache
  layers would be redundant or actively wrong.
- **`WordPress.DB.PreparedSQL.InterpolatedNotPrepared`** — fires on
  `"{$table} WHERE …"` style interpolation in the SQL string template.
  Justified when the interpolated value is `$wpdb->prefix + hardcoded
  suffix` or a member of a whitelisted format-string list (`%s`/`%d`),
  never caller data.
- **`WordPress.DB.PreparedSQL.NotPrepared`** — fires when `prepare()`
  receives a variable as its first argument (rather than a literal
  string). Justified for the same reason as the previous rule: the
  variable is composed from whitelisted fragments above.
- **`PluginCheck.Security.DirectDB.UnescapedDBParameter`** — fires
  whenever the WordPress.org Plugin Check tool sees a variable passed
  to `$wpdb->…()` without obvious escaping. It's a parallel sniffer to
  the WPCS rules above with its own ruleset; ignoring it requires its
  own line in the directive. Same justification applies — caller data
  is bound through `%s`/`%d`, SQL fragments come from a whitelisted
  list. Often missed in initial directive lists because the regular
  WPCS sweep doesn't load it; runs separately under `plugin check`.

The justification text after the `--` should make explicit:
1. *What table this targets* (so "plugin's own table" is verifiable).
2. *Where the dynamic fragments come from* (the whitelisted list above).
3. *That caller-supplied data is bound, not interpolated* (via `%s`/`%d`
   placeholders).

Reviewers should be able to verify the justification by reading the
preceding 5–10 lines and matching them against the comment.

**When you DON'T need the ignore directive**

If the SQL is fully literal (no variables) the rule doesn't fire:

```php
// No ignore needed — no variables in the SQL.
$count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM " . self::table_name()
);
```

Note: `self::table_name()` returns `$wpdb->prefix . 'lingua_forge_…'`
where the prefix is a server-known constant and the suffix is hardcoded.
PHPCS treats the concatenation as "literal enough" and doesn't fire the
NotPrepared rule. Routing safe dynamic table names through a
class-private accessor like that keeps the call sites clean.

**Why not just inline every query**

A common reflex is "just write seven separate methods instead of one
`query()` with criteria." That works for small APIs but the criteria
permutations multiply quickly (`{post_id?, feature?, post_id+feature}`
already wants three methods) and the validation logic ends up
duplicated. The dynamic-WHERE pattern is the standard way to keep that
under control; the ignore directive is what makes WPCS tolerate it.

---

## Storage shapes

- **AI feature result cache** lives in the custom table
  `{$wpdb->prefix}lingua_forge_ai_cache` (composite primary key on
  `post_id` + `feature_key`, `payload LONGTEXT` JSON-encoded). Managed by
  `LinguaForge\AI\Core\CacheStore`; the public API is `get($post_id,
  $feature, $hash)` / `set(…)` / `delete($post_id, $feature)` /
  `clear_all(): int` plus the `hash($inputs): string` helper. Lazy
  migration in `get()` reads pre-1.4 entries from `wp_postmeta` and
  copies them forward.
- **API keys** are AES-256-CBC-encrypted in `wp_options` rows named
  `linguaforge_key_{provider}`. Encryption secret derives from
  `wp_salt('auth')` unless `LINGUAFORGE_SECRET` is defined.
- **Translation cache key** is `translation_{lang}` (e.g.
  `translation_fr`) so a single post can hold many language caches
  without collision.

---

## Things worth knowing

- **`is_admin()` returns true** for `wp-admin/*` AND for admin-ajax.php.
  It returns **false** for REST requests — sniff `REST_REQUEST` or the
  URL pattern when you need to detect REST. See
  `Plugin::should_boot()` for the canonical detection.
- **`save_post` and `wp_after_insert_post` fire for REST writes too**, so
  any save-handler must be REST-safe. Don't gate it on `is_admin()`.
- **Rewrite rules need a flush** whenever the locale list changes. The
  pattern is `update_option('linguaforge_flush_rewrite_rules', true)`;
  the `init` hook at priority 99 in `lingua-forge.php` consumes it and
  calls `flush_rewrite_rules()` on the next request.
- **AI cache table is created lazily** — on the first
  `CacheStore::get()` / `set()` / `clear_all()` call after install.
  `CacheStore::ensure_table()` compares
  `linguaforge_ai_cache_db_version` against the `DB_VERSION` constant
  and only runs `dbDelta` on mismatch.

---

## Deployment

The plugin is pushed to production manually via SFTP / rsync (or zip
upload + unzip on the server) — there's no build step, no Composer, no
asset pipeline. Three gotchas come up often enough to be worth flagging:

**File permissions.** New files often land at `0600` on the server (the
web server can't read them — assets 403, PHP includes fatal). Two
common causes: SFTP uploads on hardened hosts where the client's umask
is `077`, and zip archives extracted under a session whose umask is
`077` (Plesk's web-SSH default). The brute-force fix after any deploy:

```bash
cd wp-content/plugins/lingua-forge/
find . -type d -exec chmod 0755 {} \;
find . -type f -exec chmod 0644 {} \;
```

**Building a release zip locally** — normalize permissions on the
source tree before zipping so the archive's stored Unix modes are
correct:

```bash
find lingua-forge -type d -exec chmod 0755 {} \;
find lingua-forge -type f -exec chmod 0644 {} \;
zip -r -X lingua-forge.zip lingua-forge/ -x "*.DS_Store" -x "__MACOSX/*"
```

`-X` strips macOS extended-attribute metadata for a smaller, cleaner
archive. The two `find` lines come first because `zip` captures each
file's mode bits at archive time.

**Unzipping on the server.** Stored permissions aren't enough by
themselves — `unzip` ANDs them with the current shell's umask. On Plesk
web-SSH that umask is `077`, which extracts `0644`-stored files as
`0600`. Two ways to get the right result:

```bash
# Option 1 — set the umask before each extract
umask 022 && unzip -o lingua-forge.zip
```

```bash
# Option 2 — re-normalize after extracting (matches the brute-force fix above)
unzip -o lingua-forge.zip
find lingua-forge -type d -exec chmod 0755 {} \;
find lingua-forge -type f -exec chmod 0644 {} \;
```

To make `umask 022` permanent for the SSH account, append it to
`~/.bashrc` (`echo 'umask 022' >> ~/.bashrc && source ~/.bashrc`). For
other upload paths: rsync takes `--chmod=D755,F644`; FileZilla can
default-set numeric `644` on file permissions in its transfer settings.

Quick sanity check after any extract:

```bash
stat -c '%a %n' ai/assets/settings-tabs.js
# Expected: 644 ai/assets/settings-tabs.js — if it prints 600, umask bit you.
```

**OPcache.** Aggressive OPcache configurations (typical on managed
hosts: `validate_timestamps=0` or long `revalidate_freq`) keep the
previous PHP code resident after a file replace. Symptoms: tabs that
don't render, JS that 404s because the new enqueue method "doesn't
exist," fatals that grep says are impossible. After a deploy:

```bash
# Either reset OPcache from a one-off PHP request:
php -r 'opcache_reset();'

# Or reload PHP-FPM:
service php-fpm reload
```

If you're not sure whether OPcache is the culprit, the quick test is:
add a marker line to a known-loaded file (e.g. `error_log('alive');` at
the top of `lingua-forge.php`) and hit a page. If the marker doesn't
appear in the error log, OPcache is serving the old file.

---

## Verifying changes when PHP isn't installed locally

A common scenario: editing in an environment without `php` on PATH
(remote shell, restricted sandbox). `php -l` isn't an option but two
tools cover most of the failure surface:

- **Brace / paren / `<?php`-aware tokenizer in Python.** Walks the file
  tracking PHP / HTML mode, single + double quoted strings, heredocs and
  nowdocs, block + line comments, and counts `{}` / `()` only inside
  PHP regions. Catches unclosed blocks, runaway strings, and `<?php …`
  blocks left open after a refactor. The full implementation lives in
  this project's session history and is small enough to inline into a
  prompt — under 50 lines of Python.

- **Global-class reference audit.** A regex scan over
  `new \w+(`, `: ?\w+`, `(\w+ \$var`, `instanceof \w+`, `\w+::`,
  `catch (\w+`, `throw new \w+` inside namespaced files. Each hit needs
  to be a same-namespace class, an imported `use`, or `\`-prefixed —
  otherwise it's a runtime fatal waiting to happen. The brace tokenizer
  does **not** catch this; the file parses fine. PHP doesn't auto-fall
  back classes to the global namespace the way it does functions and
  constants.

Together they're not a full parser substitute, but they catch the most
common edit damage. Anything they pass is worth pushing to staging for a
live PHP execution test before going to production.

---

## `class_alias` — the back-compat pattern

When a class is moved into a namespace for the first time, add a
`\class_alias()` call **at the bottom of the file** (after the closing
`}` of the class) so existing code that refers to the old global name
continues to work without changes:

```php
namespace LinguaForge\Router;

class Switcher {
    // …
}

\class_alias( \LinguaForge\Router\Switcher::class, 'LSFLR_Switcher' );
```

The leading `\` on `class_alias` is required because the call is inside
a namespace declaration; without it PHP looks for `LinguaForge\Router\class_alias`
and fatals.

`class_alias` makes the alias a fully-equivalent name: `instanceof`,
`new`, `static`, and `::class` all resolve correctly. The boot file
(`language-router.php`) can continue to use the old names without any
edits.

When the alias is ready for removal (after one release cycle), remove:
1. The `class_alias` line at the bottom of the class file.
2. Any surviving references to the old name in the boot file / wrappers.

---

## Internationalization — all user-facing strings must be localizable

Every string that a site administrator or editor might read — labels,
descriptions, error messages, Settings page text, metabox content,
admin notices, REST error messages — **must** pass through a
localization function. The plugin text domain is `lingua-forge`.

### Which function to use

| Context | Function |
|---|---|
| Plain string (no HTML, echo) | `esc_html_e( 'String', 'lingua-forge' )` |
| Plain string (return / assign) | `esc_html__( 'String', 'lingua-forge' )` |
| String with safe HTML tags (echo) | `wp_kses_post( __( 'String with <strong>HTML</strong>', 'lingua-forge' ) )` |
| String used in `printf` / `sprintf` | `__( 'Found %d items', 'lingua-forge' )` |
| JavaScript strings in `wp_localize_script` | Wrap in `__()` / `esc_html__()` on the PHP side; no JS-side i18n needed |

### Translators comments

When a translated string contains a placeholder, add a `/* translators: */`
comment **on the line immediately above** the `__()` call — WordPress's
i18n extractor (`wp i18n make-pot`) reads only the line directly
before the call:

```php
// Good — extractor sees the comment
/* translators: %s: temperature value, e.g. 0.4 */
printf( esc_html__( 'T=%s', 'lingua-forge' ), esc_html( (string) $meta['temperature'] ) );

// Bad — comment is too far away
/* translators: %s: temperature value */

echo '';
printf( esc_html__( 'T=%s', 'lingua-forge' ), $meta['temperature'] );
```

### Common mistakes to avoid

- **Pre-escaping before `__()` breaks entity normalisation.** Pass the
  raw string to `__()` then escape the result:
  ```php
  // Wrong — double-encodes &amp;
  echo esc_html( __( 'Save &amp; Continue', 'lingua-forge' ) );

  // Correct
  echo esc_html__( 'Save & Continue', 'lingua-forge' );
  ```
- **Dynamic values don't belong inside `__()`.** Use `printf` / `sprintf`
  with `%s` / `%d` placeholders; bind values outside the translated string.
- **JavaScript strings in inline `wp_add_inline_script` blocks** are PHP
  strings at definition time — wrap them in `esc_html__()` and
  `wp_localize_script()` data arrays, not bare JS string literals.
- **REST API error messages** shown in the editor UI must also be
  localized. Use `__()` in `WP_Error` constructors for the human-readable
  message argument.

---

## When you add something new

A short checklist:

1. **Pick the right prefix** (see the table above).
2. **If it's an option**, add it to the `linguaforge_named_options`
   array in `uninstall.php` so it gets cleaned up on plugin delete.
3. **If it's a new table or schema bump**, version it with a dedicated
   `_db_version` option and run `dbDelta` from a lazy
   `ensure_table()`-style method. Add a `DROP TABLE IF EXISTS` line to
   `uninstall.php`.
4. **If it's a new filter or action hook**, document its signature in a
   docblock on the function that calls `apply_filters()` / `do_action()`.
   Use the appropriate prefix.
5. **If it's a Settings UI input**, decide which of the five tabs it
   belongs in, render through a `form-table`, save in `handle_save()`,
   and validate against a whitelist (especially for selects and
   capability strings).
6. **If it's any user-facing string**, wrap it in `esc_html__()` /
   `__()` with the `lingua-forge` text domain. Add a `/* translators: */`
   comment immediately above any call whose string contains a `%s` / `%d`
   placeholder. See the Internationalization section above.
7. **If you're moving a class into a namespace**, add a `\class_alias()`
   at the bottom of the file, run the global-class-reference audit, and
   note the alias's planned removal version in the docblock. See the
   `class_alias` section above.
8. **If it's a new post-meta key**, check whether it should be in the
   uninstall list and whether the generic unprefixed variant (if any)
   is safe to delete — keys like `meta_description` may be shared with
   other plugins and must not be wiped on uninstall.
