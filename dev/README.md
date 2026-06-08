# dev/

Dev tooling for the Lingua Forge WordPress plugin. The plugin itself ships
with **zero runtime Composer dependencies** — this is a hard rule: no
Composer autoloader, no third-party libraries in the shipped plugin root.
This `dev/` folder is where every dev-tooling dependency lives so the
plugin root stays clean and deployable.

## Layout

```
lingua-forge/
├── ai/
├── language-router/
├── meta-description/
├── tests/                ← test files live with the code they exercise
├── lingua-forge.php
├── readme.txt
├── ...
└── dev/                  ← (you are here) — never shipped anywhere
    ├── composer.json         ← require-dev: PHPUnit, PHPCS, WPCS, PHPStan
    ├── package.json          ← @wordpress/env, @wordpress/scripts
    ├── phpcs.xml.dist        ← all <file> paths -> ../
    ├── phpunit.xml.dist      ← bootstrap + suites -> ../
    ├── phpstan.neon.dist     ← paths -> ../
    ├── .wp-env.json          ← maps .. into wp-content/plugins/
    ├── .eslintrc.json
    ├── .prettierrc.json
    ├── .stylelintrc.json
    ├── vendor/               ← Composer installs here (~200 MB, gitignored)
    └── node_modules/         ← npm installs here (~700 MB, gitignored)
```

## One-time install

```bash
cd dev/
composer install              # PHPUnit, PHPCS, PHPStan, WP stubs
npm install                   # @wordpress/env, @wordpress/scripts
```

After this, the plugin root is untouched — no `vendor/`, no `node_modules/`,
no caches pollute the folder that ships to users.

## Day-to-day

Run every command from inside this `dev/` folder:

```bash
# PHP side
composer lint                 # PHPCS against ../
composer lint:fix             # phpcbf auto-fix  ⚠️  see caution below
composer analyse              # PHPStan, WP stubs
composer test:unit            # PHPUnit unit suite — no Docker needed
composer test:integration     # PHPUnit integration suite — wp-env up
composer test:integration:wc  # WooCommerce integration suite only (needs WC in .wp-env.override.json)
composer test                 # both suites
composer qa                   # lint + analyse + unit tests
composer plugin-check         # the official .org checker (inside wp-env)
composer coverage:setup       # one-time: install pcov in the wp-env tests-cli container
composer coverage:run         # unit + integration suites with Clover + HTML output
composer coverage:merge       # merge unit + integration Clovers → combined/
composer coverage             # full pipeline: coverage:run → coverage:merge

# JS / CSS side
npm run lint:js
npm run lint:css
npm run format

# i18n
composer make-pot              # regenerate languages/lingua-forge.pot
                               # downloads wp-cli.phar to dev/ on first run (curl + php required)
                               # no Docker or global WP-CLI needed

# wp-env
npm run env:start
npm run env:seed      # one-time: permalink structure, router options, language packs, sample pages, AI key prompt
npm run env:stop
npm run env:cli -- option get blogname     # WP-CLI inside the dev container

# E2E tests (requires env:start + env:seed)
npm run e2e:install   # one-time: install Chromium browser
npm run test:e2e      # run all E2E specs (headless)
npm run test:e2e:ui   # Playwright interactive UI mode
```

### E2E suite — what runs and when

`npm run test:e2e` targets the **already-running** wp-env container on port 8888.
**Do not stop the environment before running tests** — Playwright just makes HTTP
requests to it; stopping wp-env first will cause every test to fail.

Spec files and what they cover:

| Spec file               | Coverage                                                              | API calls? |
| ----------------------- | --------------------------------------------------------------------- | ---------- |
| `e2e/routing.spec.js`   | EN/DE/CA language-prefixed URLs; root and cross-lang slugs without fatals; language switcher block renders on frontend (`/en/home`); hreflang tags present, include `x-default`, cover all configured languages | No |
| `e2e/admin.spec.js`     | Settings page loads (no PHP/JS errors), all 10 tabs present (including SEO and System), Router/Maintenance/API Keys tab content, post edit meta box registered | No |
| `e2e/lang-column.spec.js` | Lang column header + filter dropdown, EN/DE/CA filter returns correct cells, WC products (auto-skipped when WC not active) | No |
| `e2e/ai-translation.spec.js` | Meta box "Translate" button (REST `/feature/translation/{id}`), "Translate missing" in the Lang column (AJAX `lf_fill_missing`), AI Usage tab shows token rows | **Yes — costs tokens** |
| `e2e/ai-modals.spec.js` | Quick Translate REST (`/translate-chunk`), Content-gen REST (`/create-chunk`); diff modal open/cancel/apply UI (mock payload, no API key); content-gen modal open/cancel/apply-button UI (mock payload, no API key) | **Yes for REST tests** |
| `e2e/fse-localisation.spec.js` | Router tab smoke (no errors, DE/CA scaffold table rows); full DE pipeline: Scaffold → Translate → Fix links → Fix parts (auto-skipped if FSE theme not active) | **Yes — costs tokens** |
| `e2e/admin-metabox.spec.js` | `window.LfAdmin` namespace present; `admin-diff-modal.js` / `admin-content-gen-modal.js` exports; meta box in DOM; AJAX dispatch on button click | No |
| `e2e/woocommerce-integration.spec.js` | Variable product admin list (EN/DE/CA); product type + attribute delegation; TermNameFilter (Rot/Blau on DE, Vermell/Blau on CA); price delegation; brand; REST write guard (HTTP 422 on translated product and variation); auto-skipped when WC not active | No |

**Reset for a clean scaffold run:**
```bash
npm run env:destroy && npm run env:start && npm run env:seed
```
This wipes all wp-env data (posts, templates, options) and re-seeds from scratch.
Only needed when you want to re-test scaffold from an empty state.

### What composite commands include

| Command                       | Expands to                                                         | Docker needed |
| ----------------------------- | ------------------------------------------------------------------ | ------------- |
| `npm run env:seed`            | Sets permalinks, router options, installs DE/CA/ES language packs, creates sample pages (including the language switcher block appended to EN Home for E2E tests), simple WC product group, and a **variable WC product** (Test Shirt EN/DE/CA with `pa_color` attribute, Red/Blue variations with `_variation_description`, translated term names Rot/Blau/Vermell, and product_brand "Acme"). Prompts for AI provider + API key. Safe to re-run — all creation steps are idempotent. | Yes |
| `npm run test:e2e`            | Playwright E2E suite: routing + hreflang + switcher, Settings page, lang column, WC product list, AI translation, modal UI, FSE localisation pipeline. Requires `env:start` (keep running) + `env:seed`. | Yes |
| `composer test`               | `test:unit` + `test:integration`                                   | Yes           |
| `composer qa`                 | `lint` → `analyse` → `test:unit`                                   | No            |
| `composer test:integration:wc`| WooCommerce suite only — needs WC in `.wp-env.override.json`       | Yes           |
| `composer plugin-check`       | starts wp-env CLI, runs the WP Plugin Check tool inside it         | Yes           |
| `composer coverage:setup`     | optionally installs pcov in the tests-cli container (faster than xdebug) — once per `env:start` | Yes |
| `composer coverage:run`       | unit + integration suites with Clover + HTML; copies integration report out of container | Yes |
| `composer coverage:merge`     | merges `coverage/unit/clover.xml` + `coverage/integration/clover.xml` → `coverage/combined/` | No |
| `composer coverage`           | `coverage:run` → `coverage:merge`; summary.txt + combined clover land in `coverage/combined/` | Yes |

Notes:
- `composer qa` runs only the **unit** suite — intentionally fast and Docker-free. Run `composer test:integration` separately when wp-env is up.
- `composer test:integration` and `composer test:integration:wc` require wp-env to be running (`npm run env:start`) and Docker Desktop to be open. Both commands include a `wp eval` step that creates the plugin's custom DB tables (Glossary, CacheStore, TranslationMemory, UsageRecorder) before phpunit runs — the activation hook does not create these tables, and `admin_init` never fires in a CLI context.
- **Integration tests that touch AI endpoints** need `Registry::init()` and `FeatureController::init()` called explicitly in `setUp()` — `Plugin::boot()` is guarded by `should_boot()` which returns false in CLI context (not admin, REST, or WP-CLI). See `FeatureControllerRestTest` for the pattern.
- **Integration tests for the REST layer** use `rest_do_request()` with a fresh `WP_REST_Server` instance created in `setUp()` and reset to `null` in `tearDown()` — the standard WP REST test pattern.
- **Tests that use AI providers** inject a `StubProvider` via the `linguaforge_ai_provider` filter rather than needing a live API key. The stub supports a response queue for multi-call scenarios. See `tests/integration/Stubs/StubProvider.php`.
- **SEO unit tests** (`SeoHelpersTest.php`, `SeoAnalysisHelpersTest.php`) test pure static helpers on `SeoManager`, `SchemaManager`, `SocialShare`, and `SeoAnalysisPanel`. These require no WP runtime. When a helper method is extraction-worthy (pure function, no WP dependency), promote it to `public static` so unit tests can call it without reflection — this is the established pattern (`Manager::rewrite_lang_permalink`, `Switcher::build_translated_url`, `LinkFixer::extract_internal_links`, and now the full `SeoAnalysisPanel` helper suite). The `analyze_links()` helper accepts an optional `string $home = ''` parameter so tests can pass a fixed URL; at runtime it falls back to `home_url()`.
- **SEO integration tests** (`SeoAnalysisPanelIntegrationTest.php`) test the `ajax_analyze()` AJAX handler end-to-end. The handler calls `check_ajax_referer()` (throws `WPDieException` on failure) and `wp_send_json_success()` (also throws). Use `ob_start()` around the call and catch `WPDieException` for error paths; parse the JSON output for success paths. See `FeatureControllerRestTest` for the REST equivalent pattern.
- `composer plugin-check` also requires Docker Desktop + wp-env.
- `composer coverage:run` activates both `lingua-forge` and `woocommerce` before the integration suite so the full WC delegation layer is instrumented. If the integration run fails inside the container, the old `coverage/integration/clover.xml` is silently reused — check `coverage/combined/summary.txt`'s timestamp to detect a stale merge.
- `composer coverage:merge` can be re-run standalone after adding new tests without re-running the full suites.

## ⚠️ `composer lint:fix` caution

`phpcbf` rewrites files in place. Always run `git diff` (or stage with
`git add -p`) after using it and read every hunk before committing.
Things that need human review:

- **`phpcs:ignore` / `phpcs:disable` pragmas** — `phpcbf` can silently
  remove them when it fixes the surrounding code. Verify that any
  intentional suppression (Direct-SQL, render-template globals) is still
  present.
- **Namespace and `use` import changes** — reordered or added imports
  can introduce an unqualified global-class reference. Run
  `composer analyse` after every `lint:fix` run to catch these.
- **Mixed-indent files** — some files in this codebase are
  space-indented for historical reasons. `phpcbf` normalises to tabs,
  producing a large cosmetic diff. Reject those hunks unless you intend
  to re-indent the file.
- **Multi-line string reformatting** — SQL and HTML strings may be
  reflowed in ways that are correct but noisy. Accept selectively.

Safe fixes (whitespace, blank lines, brace alignment) are fine to accept
in bulk. When in doubt, accept nothing and fix manually.

## Pre-deploy gate

```bash
composer qa && composer plugin-check && npm run lint:js && npm run lint:css
```

`composer qa` runs lint + `composer analyse` (PHPStan) + unit tests in one shot.
If green, the plugin root is ready to push to production via SFTP / rsync.
Nothing in `dev/` reaches the deploy target — `.distignore` excludes it.

### Expected plugin-check noise — do not fix

`composer plugin-check` will report **1 error and 2 warnings** related to
the self-hosted auto-update path (`docs/lf-update-manifest.php` + the
`Updater` class). These fire because Plugin Check expects updates to come
from WordPress.org; Lingua Forge uses its own update channel instead.
All three — including the error flagged against the manifest file — are
permanent known false positives. Ignore them; do not suppress or work
around them.

Additionally, Plugin Check flags **hidden files** (`.htaccess`, `.gitignore`,
`.distignore`, etc.) and **Markdown files** (`CHANGELOG.md`, `CONTRIBUTING.md`,
`README.md`, etc.) as warnings. These files are excluded from release zips
and SFTP deploys via `.distignore` — the warnings are safe to ignore.
