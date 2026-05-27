# Contributing to Lingua Forge

This document covers the conventions you need to know before adding to or
modifying the Lingua Forge plugin. The most-asked question is **"which
prefix do I use for this thing?"** — that's at the top.

---

## Prefix policy

Lingua Forge uses **five** distinct prefixes. Each has a specific scope.
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
| `lsflr_` / `LSFLR_` | mixed   | Feature prefix for the Language Switcher / Link Fixer public API (wrapper functions, AJAX actions, DOM classes). Stable — do not rename existing identifiers. Do not extend this prefix for new features; use `lf_` or `linguaforge_` instead |

### `linguaforge_*` — long lowercase

Use for any identifier that should clearly belong to Lingua Forge from
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
  translation feature; receives `WorkerConfig`, `$post_id`, `$params`),
  `linguaforge_translation_memory_enabled` (disable TM per-invocation;
  receives `bool $enabled`, `int $post_id`).
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
  - **Language Router meta keys** (public data contract — readable by other
    plugins and themes): `_lf_lang`, `_lf_trid`, `_lf_lang_previous`,
    `_lf_source_updated_at`, `_lf_translation_source_updated_at`,
    `_lf_search_content`. These are stable public API; preserve the exact key
    names. *(Renamed from unprefixed `_lang`, `_trid`, etc. in DB version 1.1;
    `Db\Migrator::rename_meta_keys()` handles in-place migration on upgrade.)*
  - **Plugin-owned AI module keys** (prefixed, not part of external API):
    `_linguaforge_meta_description` (Meta Description module — stores the
    per-post translated meta description; read by the CLI
    `--with-meta-description` flag), `_linguaforge_preset` (per-page AI
    behavior preset override set in the editor metabox; read by
    `Config::active_preset()`).
  - **Internal routing key:** `_lf_auto_template` (tracks which FSE
    template was auto-assigned by the Language Router so it can be
    retracted if the language setting changes; not in the uninstall list
    because it is regenerated on the next save).
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
  `lf_debug_setting_saved`. Note: the main Settings form uses
  `linguaforge_saved` (long prefix) rather than `lf_` because it is
  dispatched from `SettingsPage` rather than the Language Router layer.

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

### `lsflr_*` / `LSFLR_*` — Language Switcher / Link Fixer feature prefix

Originates from the standalone mu-plugin
"LanguageSwitcher-ForLanguageRouter" that was folded into Lingua Forge in
v1.0. Classes are fully namespaced as `LinguaForge\Router\Switcher` and
`LinguaForge\Router\LinkFixer` and integrated into the Router singleton as
`$router->switcher` and `$router->link_fixer`. The prefix is the public
identifier convention for this feature — stable API, not deprecated:

- Template-callable functions: `linguaforge_lsflr_render_switcher()`,
  `linguaforge_lsflr_get_languages()`,
  `linguaforge_lsflr_translate_current_url()`.
- AJAX action names: `wp_ajax_lsflr_scan_links`, `wp_ajax_lsflr_fix_post`, `wp_ajax_lsflr_fix_template`.
- DOM element IDs / CSS classes inside the Link Fixer modal:
  `lsflr-fixer-*`, `lsflr-fix-*`.

**Do not extend** this prefix for new features. New Language Switcher /
Link Fixer additions should use `lf_` (filter hooks) or `linguaforge_`
(options / AJAX actions). The existing identifiers are stable and must
not be renamed without a deprecation cycle.

### Public PHP API — `linguaforge_*` wrapper functions

`language-router/language-router.php` defines a set of procedural
wrapper functions that theme code and third-party plugins should use
instead of reaching into the class instances directly. All are prefixed
`linguaforge_` and delegate to `LinguaForge\Router\Router::get_instance()`.

**Language config**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_source_language()` | `string` | The site's primary language code |
| `linguaforge_languages()` | `string[]` | All active language codes |
| `linguaforge_is_valid_lang( $lang )` | `bool` | Whether a code is in the active list |
| `linguaforge_locale_from_lang( $lang )` | `string` | Resolve a language code to a WP locale |
| `linguaforge_language_label( $lang )` | `string` | Human-readable label for a language code |

**Current request**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_detect_lang()` | `string` | Active language for the current request |
| `linguaforge_detect_lang_safe()` | `string` | Same, falls back to source language |
| `linguaforge_set_lang_cookie( $lang )` | `void` | Write the language preference cookie |
| `linguaforge_hreflang_mode()` | `string` | Current hreflang output mode (`custom` or `off`) |
| `linguaforge_is_system_request()` | `bool` | True for cron / REST / CLI — skip frontend logic |

**Post language and TRID**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_get_lang( $id )` | `string` | Language code stored on a post |
| `linguaforge_set_lang( $id, $lang )` | `void` | Write the `_lf_lang` meta |
| `linguaforge_get_trid( $id )` | `string` | UUID linking a post to its translation group |
| `linguaforge_set_trid( $id, $trid )` | `void` | Write the `_lf_trid` meta |
| `linguaforge_get_translations( $id )` | `array` | `[ lang => post_id ]` map for all variants |
| `linguaforge_clear_translation_cache( $id )` | `void` | Bust the `wp_cache` entry for a TRID group |
| `linguaforge_get_missing_languages( $id )` | `string[]` | Language codes that have no translation yet |

**Outdated tracking**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_mark_source_updated( $id )` | `void` | Flag source post as changed (marks translations outdated) |
| `linguaforge_mark_translation_synced( $id )` | `void` | Clear the outdated flag on a translation post |
| `linguaforge_is_outdated( $id )` | `bool` | Whether a translation is behind its source |

**Language-filtered queries**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_query( $args )` | `WP_Query` | `WP_Query` scoped to `LF_LANG` |
| `linguaforge_query_fallback( $args )` | `WP_Query` | Same, falls back to source language if no translation |
| `linguaforge_get_posts( $args, $fallback )` | `WP_Post[]` | Convenience wrapper around `linguaforge_query` |

**URL and routing helpers**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_safe_query_args( $url )` | `string` | Strip language-routing internals from a URL |
| `linguaforge_lang_permalink( $url, $post )` | `string` | Language-prefixed permalink (used as `post_link` filter) |

**Developer / internal**

| Function | Returns | Purpose |
|---|---|---|
| `linguaforge_build_search_content( $id )` | `void` | Rebuild the `_lf_search_content` index for a post |
| `linguaforge_ensure_lang_index()` | `bool` | Create the `idx_lang` postmeta index if missing |
| `linguaforge_debug( $message, $context )` | `void` | Write a debug entry (no-op unless debug is on) |

---

## Namespaces and class layout

Modern code lives under three namespaces:

- `LinguaForge\Router\…` — the Language Router sub-module (file location:
  `language-router/includes/`). All three classes are namespaced as of 1.2.0;
  back-compat `class_alias` entries were removed in 1.4.0:
  - `LinguaForge\Router\Router` (canonical — use this everywhere)
  - `LinguaForge\Router\Switcher` (canonical)
  - `LinguaForge\Router\LinkFixer` (canonical)
  The old bare names `Language_Router`, `LSFLR_Switcher`, and `LSFLR_Link_Fixer`
  no longer exist. New code in this sub-module must use the fully-qualified names.
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

Architectural review and audit notes live **outside** the public
plugin tree — in a maintainer-only `lingua-forge-audit/` sibling
folder (not tracked in this repo). The current snapshot is
`AUDIT-2026-05-23.md`; older `REVIEW.md` / `AUDIT-2026-05-19.md`
documents are kept as historical record only. Contributors don't
need to read them to ship a correct change — the conventions they
codify all live in this file.

---

## Coding conventions

- **PHP 8.1+ syntax** is fine — the plugin requires PHP 8.1 (declared in
  the header). Constructor property promotion, named arguments,
  `match` expressions, `readonly` properties, enums, never return type,
  union return types are all in use.
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

The Settings page (`Settings → Lingua Forge`) uses an eight-tab layout
(General / API Keys / Limits / Behavior / Router / Glossary / AI Usage /
Maintenance). The first four tabs (General, API Keys, Limits, Behavior)
live inside a single `<form>` so one Save Settings click persists every
value. The remaining four tabs (Router, Glossary, AI Usage, Maintenance)
are outside that form — each uses its own dedicated admin-post actions.

When adding a new setting, decide which tab it belongs in:

- **General** — provider, models. Things the admin configures once.
- **API Keys** — API keys and the Test Connection AJAX flow.
- **Limits** — quotas, rate limits, capability gate, per-feature token /
  character caps.
- **Behavior** — toggles that change *how* the AI features act (block
  editor restrictions, AI behavior preset — Standard / Technical / Legal / Creative).
- **Router** — Language Router settings (active languages, browser
  redirect, slug handling). Has its own admin-post save action
  (`linguaforge_save_router_settings`) and a Flush Permalinks action.
- **Glossary** — per-language-pair terminology table. Has its own
  admin-post actions (`linguaforge_glossary_add`,
  `linguaforge_glossary_delete`).
- **AI Usage** — read-only usage log (requests, input/output tokens by
  feature, provider, model, and date). No save action.
- **Maintenance** — operational forms (cache, debug files, language
  overrides, translation memory). Each entry has its own admin-post
  action.

Tab state is preserved across the save-redirect cycle via
`sessionStorage`. Each tab is deep-linkable via URL hash (`#behavior`,
`#limits`, etc.).

---

## WP-CLI

The plugin registers a `linguaforge` WP-CLI command namespace when
`WP_CLI` is defined. Registration happens eagerly at AI sub-module load
time (`ai/ai.php`); the facade class (`LinguaForge\AI\CLI\Commands`)
is autoloaded on the first method dispatch.

The CLI namespace under `ai/includes/CLI/` is split into:

- `Commands.php` — thin facade. Holds one public method per subcommand
  plus the WP-CLI docblocks (`## OPTIONS` / `## EXAMPLES` / `@when`).
  Each method is a one-line forwarder into a dedicated command class.
  Why the docblocks live here: WP-CLI introspects the class registered
  via `WP_CLI::add_command()` and reads its method docblocks to build
  `wp linguaforge <sub> --help`. Keeping the docblocks on the facade
  means the help output stays byte-stable regardless of how the
  implementation is structured underneath.
- `AbstractTranslateCommand.php` — shared base for the three commands
  that drive `Translation::run()` (translate, retranslate,
  fill_translations). Provides the validators (`validate_post_id`,
  `validate_target_langs`), the worker-overrides filter installer, the
  debug-mode helper, the `apply_translation` / `create_trid_linked_post`
  / `generate_and_save_meta_description` pipeline, and the
  `dump_debug_files` echo helper. Subclasses only implement
  `execute(array $args, array $assoc_args): void`.
- `TranslateCommand.php`, `RetranslateCommand.php`,
  `FillTranslationsCommand.php` — each extends `AbstractTranslateCommand`.
- `MissingTranslationsCommand.php`, `CacheClearCommand.php` — standalone
  classes (no translation pipeline involvement), each with an
  `execute(array $args, array $assoc_args): void`.

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

- **`wp linguaforge retranslate <post_id> --to=fr,de[,…]`** — same pipeline
  as `translate` but ignores the AI result cache (`--force` implied), so
  it always calls the provider. Use when content has changed and you want
  a fresh translation without clearing the cache globally.

- **`wp linguaforge fill_translations [--post-type=post] [--lang=fr]`** —
  iterates over all posts of the given type (default: `post`, `page`) and
  translates into any target languages where a TRID-linked post exists but
  has no content yet. Safe to re-run; posts with existing content are
  skipped unless `--force` is passed.

- **`wp linguaforge missing_translations [--post-type=post]`** — reports
  which posts are missing one or more language variants. No writes; pure
  audit. Pairs with `fill_translations` as a detect-then-fill pipeline.

- **`wp linguaforge cache_clear`** — wipes AI-result cache entries.
  Bare command truncates the whole table; `--feature=translation` scopes
  to feature-key prefix; `--post-id=N` scopes to a single post; both
  combine. Bare-truncate prompts unless `--yes` is passed.

When adding a new command:

1. Create a new class `LinguaForge\AI\CLI\FooBarCommand` in
   `ai/includes/CLI/FooBarCommand.php`. If it runs the translation
   pipeline, extend `AbstractTranslateCommand`; otherwise it stands
   alone. Implement `public function execute(array $args, array
   $assoc_args): void`.
2. Add a public method `foo_bar` to `LinguaForge\AI\CLI\Commands` whose
   docblock contains the WP-CLI-flavored `## OPTIONS` / `## EXAMPLES`
   (these render as `wp linguaforge foo-bar --help`). The method body
   is a one-liner: `( new FooBarCommand() )->execute( $args, $assoc_args );`.
3. Mention the new subcommand in the class-level `## SUBCOMMANDS`
   docblock at the top of `Commands.php` so `wp linguaforge --help`
   lists it.

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
  `clear_all(): int` plus the `hash($inputs): string` helper.
- **API keys** are AES-256-GCM-encrypted in `wp_options` rows named
  `linguaforge_key_{provider}`. The provider slug is bound as Additional
  Authenticated Data (AAD) so cross-provider ciphertext swaps fail the
  tag check. Encryption secret derives from `wp_salt('auth')` unless
  `LINGUAFORGE_SECRET` is defined. Legacy v1 (AES-256-CBC) values are
  decrypted transparently and re-encrypted as v2 on the first successful
  read (lazy migration).

  **When to define `LINGUAFORGE_SECRET`:** `wp_salt('auth')` is written
  once into `wp-config.php` and stays identical across every copy of that
  file — development, staging, and production share the same secret when
  bootstrapped from the same config. A key ciphertext extracted from one
  environment would therefore decrypt successfully in another. Define a
  unique constant in each environment's `wp-config.php` to give each its
  own independent encryption key:

  ```php
  // Different random string on each environment.
  define( 'LINGUAFORGE_SECRET', 'your-64-char-random-string-here' );
  ```

  Use a cryptographically random value (e.g. `openssl rand -base64 48`).
  Changing this constant on an existing install invalidates all stored
  ciphertexts — re-enter API keys after rotating it.
- **Translation cache key** is `translation_{lang}` (e.g.
  `translation_fr`) so a single post can hold many language caches
  without collision.

---

## Things worth knowing

- **Pre-1.3.x debug files may be orphaned** under
  `wp-content/uploads/lingua-forge-debug/`. That path was the hardcoded
  default before the `linguaforge_debug_dir` filter was added. Either
  point the filter at the old path so the plugin manages cleanup there, or
  delete the directory manually — it is never touched again on 1.3.x+
  installs unless redirected.
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

## Local development environment

The plugin itself ships with **zero runtime dependencies** — that policy
is non-negotiable for WordPress.org submission. Every piece of dev
tooling (PHPUnit, PHPCS / WPCS, PHPStan, wp-env, ESLint, Prettier,
Plugin Check) lives in the **`dev/` subdirectory** of this repo:

```
lingua-forge/
├── ai/
├── language-router/
├── meta-description/
├── tests/                ← plugin source (not shipped to .org)
├── lingua-forge.php
└── dev/                  ← tooling only — excluded from .org build via .distignore
    ├── composer.json
    ├── package.json
    ├── phpcs.xml.dist
    ├── phpunit.xml.dist
    ├── phpstan.neon.dist
    ├── .wp-env.json
    ├── vendor/           ← Composer installs here (gitignored, ~200 MB)
    └── node_modules/     ← npm installs here (gitignored, ~700 MB)
```

`dev/` is excluded from every `.org` build via `.distignore`, so the
~1 GB of tooling dependencies never reaches the deploy ZIP.

See [`dev/README.md`](dev/README.md) for the full layout and command
reference.

### Prerequisites

- PHP 8.1+ (PHP 8.2 recommended, matches Plugin Check's target).
- Composer 2.x.
- Node 20+ and npm 10+.
- Docker — required by `@wordpress/env` for integration tests and the
  Plugin Check wrapper.

### One-time install

```bash
cd dev/
composer install
npm install
```

After this, the plugin root is untouched — no `vendor/`, no
`node_modules/`, no caches in the folder that ships to .org.

### Day-to-day commands

Run every command from the `dev/` directory:

| Goal                               | Command                       |
| ---------------------------------- | ----------------------------- |
| PHPCS — full lint                  | `composer lint`               |
| PHPCS — auto-fix what `phpcbf` can | `composer lint:fix` ⚠️        |
| PHPStan — static analysis          | `composer analyse`            |
| PHPUnit — full suite               | `composer test`               |
| PHPUnit — unit only (fast, no WP)  | `composer test:unit`          |
| PHPUnit — integration only         | `composer test:integration`   |
| All of the above                   | `composer qa`                 |
| Start wp-env (Docker WP install)   | `npm run env:start`           |
| Stop wp-env                        | `npm run env:stop`            |
| Run WP-CLI inside wp-env           | `npm run env:cli -- <args>`   |
| Plugin Check (official WP.org)     | `composer plugin-check`       |
| ESLint                             | `npm run lint:js`             |
| Stylelint                          | `npm run lint:css`            |
| Prettier — format everything       | `npm run format`              |

> ⚠️ **`composer lint:fix` — review every change before committing**
>
> `phpcbf` rewrites files in place. Most of what it touches is safe
> (whitespace, blank lines, brace placement), but a few classes of fix
> require human review before committing:
>
> - **`phpcs:ignore` / `phpcs:disable` pragmas** — `phpcbf` will
>   sometimes remove them when it "fixes" the surrounding code. Check
>   that any pragma you added intentionally (Direct-SQL suppression,
>   render-template globals, etc.) is still present after the run.
> - **Namespace and `use` import reordering** — if `phpcbf` adds or
>   reorders `use` statements it can introduce a global-class reference
>   that PHPStan will catch but that may not be obvious on a quick
>   visual scan. Always run `composer analyse` after a `lint:fix` run.
> - **String concatenation and alignment changes** — the
>   `WordPress.WhiteSpace.OperatorSpacing` fixer sometimes reformats
>   multi-line SQL or HTML strings in ways that are technically correct
>   but harder to read. Prefer accepting those hunks selectively with
>   `git add -p` rather than staging the whole file.
> - **Files with mixed indent history** — some files in this codebase
>   use tabs, others spaces. `phpcbf` will normalise to the ruleset
>   default (tabs). That is a cosmetic-only change on tab files but
>   can produce a large noisy diff on any file that was historically
>   space-indented. Check the diff before committing.
>
> **Safe rule of thumb:** run `git diff` (or stage with `git add -p`)
> after every `lint:fix` invocation and read every hunk. Reject any
> fix that changes logic, removes a suppression pragma, or produces a
> diff larger than the surrounding problem warrants.

### What ruleset each tool uses

- **PHPCS (`dev/phpcs.xml.dist`)** loads `WordPress` + `WordPress-Extra`
  + `WordPress-Docs` + `PHPCompatibilityWP`, targets WP 6.4 and PHP 8.1,
  and pre-configures the prefix list (`linguaforge_`, `LINGUAFORGE_`,
  `Linguaforge`, `LinguaForge`, `lf_`, `LF_`) so the `PrefixAllGlobals`
  sniff knows about them. The file-name sniff (`WordPress.Files.FileName`)
  is disabled — this codebase intentionally mixes PSR-4 PascalCase
  (`ai/includes/Core/Plugin.php`) with the WP `class-foo.php` convention
  (`language-router/includes/class-context.php`). All `<file>` paths in
  the config use `../` (parent plugin root).
- **PHPStan (`dev/phpstan.neon.dist`)** runs at level 5 with
  `szepeviktor/phpstan-wordpress` as the WP stub source. Level 6+ starts
  flagging type imprecision in WP core itself — not worth the noise.
  `paths:` references `../` (parent plugin root).
- **PHPUnit (`dev/phpunit.xml.dist`)** defines two suites. The **unit**
  suite is the fast path: no WordPress, only pure-function utilities.
  The **integration** suite expects `WP_TESTS_DIR` to point at the WP
  PHPUnit framework (wp-env exposes this automatically). The test files
  themselves live in `tests/` — they're plugin source, just
  `.distignore`'d out of the .org build.
- **wp-env (`dev/.wp-env.json`)** boots WordPress 6.4 on PHP 8.1 with
  `..` (plugin root) mounted at `wp-content/plugins/lingua-forge`.
  `WP_DEBUG` is on in both the development and test environments.
- **Plugin Check** runs inside the wp-env CLI container via
  `composer plugin-check`. This is the same checker WordPress.org uses
  on submission, so passing it locally is a strong signal that a
  release is .org-ready. It also catches the 5th DirectDB rule
  documented below (`PluginCheck.Security.DirectDB.UnescapedDBParameter`)
  that the WPCS-only run misses.
- **ESLint / Prettier / Stylelint** use the
  `@wordpress/eslint-plugin/recommended`,
  `@wordpress/stylelint-config/scss`, and `@wordpress/prettier-config`
  presets as the base. Stylelint has four rule overrides in
  `dev/.stylelintrc.json`: a BEM-aware `selector-class-pattern` (allows
  `block__element--modifier` with all-lowercase-hyphenated segments),
  `camelCaseSvgKeywords: true` to permit the conventional `currentColor`
  casing, and both `rule-empty-line-before` and `comment-empty-line-before`
  nulled (the project style does not require blank lines between every
  rule or before inline comments). New CSS must use proper BEM modifier
  names (`.block--modifier`, not `.--modifier`). The npm scripts in
  `dev/package.json` glob over `../**/*.{js,css}`.

### Recommended pre-deploy sequence

```bash
cd dev/
composer qa                  # lint + analyse + test
composer plugin-check        # the .org checker
npm run lint:js && npm run lint:css
```

If all four are green, the plugin is ready to push via SFTP / rsync.
`dev/` is excluded from every deploy by `.distignore` — nothing in it
ever reaches the server or a .org build ZIP.

### When the tooling disagrees with itself

PHPCS and Plugin Check overlap on direct-DB queries — that's the "five
linter rules per ignore directive" pattern documented below in *Direct
SQL and phpcs:ignore conventions*. Run **both** before you ship; PHPCS
gives you four of the five rules, Plugin Check gives you the fifth.

---

## Verifying changes when PHP isn't installed locally

A common scenario: editing in an environment without `php` on PATH
(remote shell, restricted sandbox, hosted AI session).

### First choice — install PHP user-space from the apt cache

In most restricted environments `sudo` and `apt-get install` are
blocked, but `apt-get download` (pulls a .deb without installing it)
and `dpkg-deb -x` (extracts a .deb into any directory) both work
without root. The recipe — confirmed working in the Cowork sandbox
this project is developed in — takes ~3 minutes and gives you the
full `composer qa` toolchain:

```bash
# 1) Pull the .deb files (no sudo).
cd /tmp
apt-get download \
    php8.1-cli php8.1-common php8.1-opcache \
    php8.1-xml php8.1-mbstring php8.1-curl php8.1-zip \
    php-common libssl3 libxml2 libzip4 libonig5 libargon2-1 \
    libgmp10 libgmpxx4ldbl libtidy5deb1 libsodium23 libffi8 \
    libsqlite3-0 libreadline8 zlib1g

# 2) Extract into ~/.local/php-root.
mkdir -p ~/.local/php-root && cd ~/.local/php-root
for d in /tmp/*.deb; do dpkg-deb -x "$d" .; done

# 3) Override extension_dir so the extension .so files resolve against
#    the user-space install path (the .ini files ship with bare names).
cat > ~/.local/php-root/etc/php/8.1/cli/conf.d/00-extension-dir.ini <<EOF
extension_dir = "$HOME/.local/php-root/usr/lib/php/20210902"
EOF

# 4) Copy the extension .ini files into the cli conf.d so they load.
for ini in ~/.local/php-root/usr/share/php8.1-*/*/*.ini; do
    cp "$ini" "$HOME/.local/php-root/etc/php/8.1/cli/conf.d/20-$(basename $ini)"
done

# 5) Drop a wrapper on PATH that bakes in the LD path + scan dir.
mkdir -p ~/.local/bin
cat > ~/.local/bin/php <<'EOF'
#!/usr/bin/env bash
export LD_LIBRARY_PATH="$HOME/.local/php-root/usr/lib/aarch64-linux-gnu:$HOME/.local/php-root/lib/aarch64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export PHP_INI_SCAN_DIR="$HOME/.local/php-root/etc/php/8.1/cli/conf.d"
exec "$HOME/.local/php-root/usr/bin/php8.1" "$@"
EOF
chmod +x ~/.local/bin/php
export PATH="$HOME/.local/bin:$PATH"

# 6) Verify — should print PHP 8.1.x and a module list including
#    tokenizer, xml, simplexml, dom, mbstring, …
php -v && php -m
```

The `aarch64-linux-gnu` path in step 5 assumes ARM64 hosts. On x86_64
hosts, swap that segment for `x86_64-linux-gnu` and request `_amd64`
.deb variants in step 1.

After this, `phpcs` / `phpstan` / `phpunit` from `dev/vendor/bin/`
run exactly as they do on a developer's machine. The full
walkthrough (with explanations of why the obvious paths fail and a
verified `composer qa` reproduction) is in
`lingua-forge-audit/AUDIT-2026-05-23.md` §9.5.

### Fallback — when even `apt-get download` is blocked

Two regex-tier tools cover most of the failure surface:

- **Brace / paren / `<?php`-aware tokenizer in Python.** Walks the file
  tracking PHP / HTML mode, single + double quoted strings, heredocs and
  nowdocs, block + line comments, and counts `{}` / `()` only inside
  PHP regions. Catches unclosed blocks, runaway strings, and `<?php …`
  blocks left open after a refactor. ~50 lines of Python; small enough
  to inline into a prompt.

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
5. **If it's a Settings UI input**, decide which of the eight tabs it
   belongs in (see the Settings page layout section above), render through
   a `form-table`, save in `handle_save()`, and validate against a
   whitelist (especially for selects and capability strings).
6. **If it's any user-facing string**, wrap it in `esc_html__()` /
   `__()` with the `lingua-forge` text domain. Add a `/* translators: */`
   comment immediately above any call whose string contains a `%s` / `%d`
   placeholder. See the Internationalization section above.
7. **If you're moving a class into a namespace**, run the
   global-class-reference audit (grep for the old bare class name across
   all PHP files) and update every call site before the PR lands.
8. **If it's a new post-meta key**, check whether it should be in the
   uninstall list and whether the generic unprefixed variant (if any)
   is safe to delete — keys like `meta_description` may be shared with
   other plugins and must not be wiped on uninstall.
9. **Don't touch `CHANGELOG.md`, `readme.txt`'s `Stable tag`, or the
   `Version:` headers in `lingua-forge.php` / the constants in
   `lingua-forge.php`.** The maintainer cuts releases manually via
   SFTP/rsync and bumps version strings + writes changelog entries at
   release time. Iterating on a fix produces meaningless version
   history if every attempt gets its own bump. Same applies to AI-
   assisted PRs — fix the code, leave the version metadata alone, and
   the maintainer batches changelog entries when the release ships.
