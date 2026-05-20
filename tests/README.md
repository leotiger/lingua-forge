# tests/

PHPUnit suites for Lingua Forge.

```
tests/
├── bootstrap.php              ← phpunit.xml.dist points here
├── phpstan-bootstrap.php      ← phpstan.neon.dist points here
├── unit/                      ← no WordPress; pure-PHP units
│   └── BlockTextExtractorTest.php
└── integration/               ← runs inside wp-env / WP test framework
    └── PluginBootTest.php
```

## Running

```bash
# Unit only — fast, no Docker required
composer test:unit

# Integration — requires wp-env up
npm run env:start
composer test:integration

# Both
composer test
```

The unit bootstrap defines the plugin's constants (LINGUAFORGE_PATH, etc.)
and stops. The integration bootstrap hooks the plugin onto
`muplugins_loaded` and lets the WordPress test framework take over —
canonical pattern from the WP Plugin handbook.

See `CONTRIBUTING.md` → "Local development environment" for the install
walkthrough.
