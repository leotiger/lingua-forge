# tests/

PHPUnit suites for Lingua Forge.

```
tests/
├── bootstrap.php                              ← phpunit.xml.dist points here
├── phpstan-bootstrap.php                      ← phpstan.neon.dist points here
│
├── unit/                                      ← no WordPress; pure-PHP units
│   ├── BlockTextExtractorTest.php             ← reinsert + strip_interblock_br
│   ├── ConfigPresetAddendumTest.php           ← Config::default_preset_addendum
│   ├── JsonRepairTest.php                     ← normalise + repair_unescaped_quotes
│   ├── KeyStoreEnvelopeTest.php               ← v1/v2 AES envelope + AAD + tamper
│   └── RouterSingletonTest.php                ← Router::reset_instance contract
│
└── integration/                               ← runs inside wp-env / WP test framework
    ├── ConfigPresetAddendumIntegrationTest.php  ← preset_addendum + apply_compliance
    ├── GlossaryHashForPairTest.php            ← Glossary::hash_for_pair stability
    ├── MissingTranslationNoticeBlockTest.php  ← FSE block render gating + escaping
    └── PluginBootTest.php                     ← constants + autoloader + class load
```

Counts as of this writing: 5 unit + 4 integration files, totalling
**37 unit tests / 57 unit assertions** (`composer test:unit` output).

## Running

```bash
# Unit only — fast, no Docker required
composer test:unit

# Integration — requires wp-env up (Docker Desktop running)
npm run env:start
composer test:integration

# Both
composer test
```

All commands run from `../dev/` (the dev-tooling folder).

## Unit vs integration — where does a new test go?

Decide by what the code under test depends on, not by what it does:

| Code under test depends on…       | Suite       | Example                    |
|-----------------------------------|-------------|----------------------------|
| Nothing but PHP + plugin source   | **unit**    | `BlockTextExtractor::strip_interblock_br` |
| `get_option` / `update_option`    | integration | `Config::preset_addendum` (reads `wp_options`) |
| `get_post_meta`, `wp_query`, etc. | integration | `Translation::detect_post_language` |
| `parse_blocks` / `serialize_blocks` | integration | `BlockTextExtractor::extract` (uses core block parser) |
| Block render / `do_blocks`        | integration | `MissingTranslationNoticeBlockTest` |
| `$wpdb` / a custom table          | integration | `GlossaryHashForPairTest` (hash itself is pure but the read isn't) |
| Transients (`get_transient` etc.) | integration | (none yet — `RateLimiter` is a candidate) |

If a method is *almost* pure but calls one stray WP function, the
canonical pattern is to **split that one call into a small wrapper**
and unit-test the pure inner method. `JsonRepair` was extracted from
`Translation` for exactly this reason (audit §2.1).

## How the bootstrap dual-paths

`bootstrap.php` runs in two modes:

- **Unit path** — no `WP_TESTS_DIR` env var. Defines the
  `LINGUAFORGE_*` constants by hand, registers a tiny
  `wp_json_encode` polyfill, and stops. Unit tests `require_once` the
  one or two classes they exercise directly (e.g.
  `require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/BlockTextExtractor.php';`).
- **Integration path** — `WP_TESTS_DIR` points at the WordPress test
  framework (wp-env exposes `/wordpress-phpunit` automatically). The
  bootstrap hooks the plugin onto `muplugins_loaded` and then
  delegates to the WP test bootstrap. Tests extend `WP_UnitTestCase`
  and get the full WordPress runtime (factory, transactional DB,
  `do_action`, etc.).

A **classmap autoloader** is registered in the unit path for
plugin source classes that follow the WP `class-foo.php` naming
convention (which is not PSR-4-compatible). To add a unit test for
a Language Router class, add it to the `$lf_classmap` array in
`bootstrap.php` — no `composer dump-autoload` step is required.

## Testing private statics via ReflectionMethod

Two unit tests already use this pattern:
`KeyStoreEnvelopeTest` (the AES helpers were never public API) and
the original `TranslationRepairQuotesTest` (since refactored into
`JsonRepairTest` once `repair_unescaped_quotes` became `public static`).

Keep this for genuinely-private internals. If the method is
*conceptually* part of the public surface but happens to be marked
`private`, prefer extracting + making `public static` over reflecting
into it. `JsonRepair` is the precedent.

## See also

- `../CONTRIBUTING.md` → **Local development environment** for the
  install walkthrough.
- `../dev/README.md` for the operator-facing command reference.
