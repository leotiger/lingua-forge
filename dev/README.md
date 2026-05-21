# dev/

Dev tooling for the Lingua Forge WordPress plugin. The plugin itself ships
with **zero runtime Composer dependencies** — that policy is non-negotiable
for WordPress.org submission. This `dev/` folder is where every dev-tooling
dependency lives so the plugin's root stays clean.

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
no caches pollute the folder that ships to WordPress.org.

## Day-to-day

Run every command from inside this `dev/` folder:

```bash
# PHP side
composer lint                 # PHPCS against ../
composer lint:fix             # phpcbf auto-fix
composer analyse              # PHPStan, WP stubs
composer test:unit            # PHPUnit unit suite — no Docker needed
composer test:integration     # PHPUnit integration suite — wp-env up
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

## Pre-deploy gate

```bash
composer qa && composer plugin-check && npm run lint:js && npm run lint:css
```

If green, the plugin root is ready to push to production via SFTP / rsync.
Nothing in `dev/` reaches the deploy target — `.distignore` excludes it.
