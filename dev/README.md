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

# JS / CSS side
npm run lint:js
npm run lint:css
npm run format

# wp-env
npm run env:start
npm run env:stop
npm run env:cli -- option get blogname     # WP-CLI inside the dev container
```

### What composite commands include

| Command                       | Expands to                                                         | Docker needed |
| ----------------------------- | ------------------------------------------------------------------ | ------------- |
| `composer test`               | `test:unit` + `test:integration`                                   | Yes           |
| `composer qa`                 | `lint` → `analyse` → `test:unit`                                   | No            |
| `composer test:integration:wc`| WooCommerce suite only — needs WC in `.wp-env.override.json`       | Yes           |
| `composer plugin-check`       | starts wp-env CLI, runs the WP Plugin Check tool inside it         | Yes           |

Notes:
- `composer qa` runs only the **unit** suite — intentionally fast and Docker-free. Run `composer test:integration` separately when wp-env is up.
- `composer test:integration` and `composer test:integration:wc` require wp-env to be running (`npm run env:start`) and Docker Desktop to be open.
- `composer plugin-check` also requires Docker Desktop + wp-env.

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
