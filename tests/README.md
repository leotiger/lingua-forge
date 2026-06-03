# tests/

PHPUnit suites for Lingua Forge.

```
tests/
├── bootstrap.php                                  ← phpunit.xml.dist points here
├── phpstan-bootstrap.php                          ← phpstan.neon.dist points here
│
├── unit/                                          ← no WordPress; pure-PHP units
│   ├── ApiPolyfills.php                           ← recording stubs for do_action, get/update_post_meta
│   ├── BlockTextExtractorTest.php                 ← reinsert + strip_interblock_br
│   ├── CacheStoreHashTest.php                     ← CacheStore::hash() input coverage
│   ├── ConfigDefaultModelsTest.php                ← Config::default_model / all_model_defaults
│   ├── ConfigPresetAddendumTest.php               ← Config::default_preset_addendum
│   ├── ConfigTest.php                             ← Config provider/model/tier resolution
│   ├── DataEndpointsTest.php                      ← REST /languages + /post/{id}/translations handlers
│   ├── FeatureControllerCapabilityTest.php        ← required_capability() exhaustiveness
│   ├── JsonRepairTest.php                         ← normalise + repair_unescaped_quotes
│   ├── KeyStoreEnvelopeTest.php                   ← v1/v2 AES envelope + AAD + tamper
│   ├── KeyStorePublicApiTest.php                  ← KeyStore public API contracts
│   ├── LocaleDetectorTest.php                     ← LocaleDetector language resolution
│   ├── MetaDescriptionCleanOutputTest.php         ← MetaDescription::clean_output()
│   ├── RateLimiterTest.php                        ← RateLimiter gate + quota logic
│   ├── RegressionContractsTest.php                ← pin critical string constants + key shapes
│   ├── RouterSingletonTest.php                    ← Router::reset_instance contract
│   ├── TranslationLanguagesTest.php               ← Translation::LANGUAGES + get_languages()
│   ├── TridGroupAccessorsTest.php                 ← TridGroup get/set accessors
│   ├── TridGroupHooksTest.php                     ← linguaforge_trid_changed action firing
│   ├── WorkerConfigTest.php                       ← WorkerConfig readonly value object
│   └── WooCommerce/
│       ├── WcUnitTestCase.php                     ← base: WcPolyfills + Router stub
│       ├── WcPolyfills.php                        ← get/update_post_meta + LfWpdb stub (prepare/get_var/esc_like)
│       ├── MetaDelegateTest.php                   ← price/stock/image delegation logic (individual + bulk reads)
│       ├── StockRouterTest.php                    ← stock write routing to source
│       ├── TaxonomyDelegateTest.php               ← wp_get_object_terms delegation
│       └── VariationDelegateTest.php              ← pre_get_posts filter; own-variations bypass
│
└── integration/                                   ← runs inside wp-env / WP test framework
    ├── ConfigPresetAddendumIntegrationTest.php    ← preset_addendum + apply_compliance
    ├── ContextOptionsTest.php                     ← source_language, routing_mode, languages, detect_browser_lang
    ├── GlossaryHashForPairTest.php                ← Glossary::hash_for_pair stability
    ├── MissingTranslationNoticeBlockTest.php      ← FSE block render gating + escaping
    ├── PatternDiscoveryIntegrationTest.php        ← PatternDiscovery CPT pattern expansion
    ├── PluginBootTest.php                         ← constants + autoloader + class load
    ├── TridGroupTest.php                          ← set/get lang+trid, get_translations SQL, cache clear
    └── WooCommerce/
        ├── WcIntegrationTestCase.php              ← base: WC bootstrap + product factory helpers
        ├── BootstrapIntegrationTest.php           ← WC module wiring + hook registration (incl. VariationSync, RestWriteGuard)
        ├── HposOrderIsolationTest.php             ← shop_order never gets _lf_lang; MetaDelegate not triggered
        ├── MetaDelegateIntegrationTest.php        ← per-key delegation round-trip against real postmeta
        ├── MetaDelegateWcApiIntegrationTest.php   ← wc_get_product() API path: price/SKU/stock on translated products/variations
        ├── RestWriteGuardIntegrationTest.php      ← HTTP 422 on PUT/PATCH to translated products and variations
        ├── StockRouterIntegrationTest.php         ← stock write routing in WP runtime
        ├── TaxonomyDelegateIntegrationTest.php    ← term delegation + product_brand + linguaforge_wc_delegate_taxonomies filter
        ├── TermNameIntegrationTest.php            ← _lf_term_name_{lang} swap at render time
        ├── VariationDelegateIntegrationTest.php   ← variation query scoping; translated variations not redirected
        └── VariationSyncIntegrationTest.php       ← variation creation, TRID wiring, attribute meta, price delegation
```

Latest counts (test methods; data-provider variants add a few more test cases each):
**~422 unit**, **~116 non-WC integration**, **~113 WC integration** — approximately **651 total**.
E2E: **7 spec files, 55 scenarios** (Playwright, `npm run test:e2e`).
Run `composer test` for the exact PHPUnit count.

## Running

```bash
# Unit only — fast, no Docker required
composer test:unit

# Integration — requires wp-env up (Docker Desktop running)
npm run env:start
composer test:integration

# WooCommerce integration suite only
composer test:integration:wc

# Both unit + integration
composer test
```

All commands run from `../dev/` (the dev-tooling folder).

## Unit vs integration — where does a new test go?

Decide by what the code under test depends on, not by what it does:

| Code under test depends on…       | Suite       | Example                    |
|-----------------------------------|-------------|----------------------------|
| Nothing but PHP + plugin source   | **unit**    | `BlockTextExtractor::strip_interblock_br` |
| `get_option` / `update_option`    | integration | `Config::preset_addendum` (reads `wp_options`) |
| `get_post_meta`, `wp_query`, etc. | integration | `TridGroupTest` |
| `parse_blocks` / `serialize_blocks` | integration | `BlockTextExtractor::extract` (uses core block parser) |
| Block render / `do_blocks`        | integration | `MissingTranslationNoticeBlockTest` |
| `$wpdb` / a custom table          | integration | `GlossaryHashForPairTest` |
| WooCommerce runtime               | integration | `WooCommerce/` suite |
| Transients (`get_transient` etc.) | integration | (none yet — `RateLimiter` is a candidate) |

If a method is *almost* pure but calls one stray WP function, the
canonical pattern is to **split that one call into a small wrapper**
and unit-test the pure inner method. `JsonRepair` was extracted from
`Translation` for exactly this reason.

## How the bootstrap dual-paths

`bootstrap.php` runs in two modes:

- **Unit path** — no `WP_TESTS_DIR` env var. Defines the
  `LINGUAFORGE_*` constants by hand, registers a tiny
  `wp_json_encode` polyfill, and stops. Unit tests `require_once` the
  one or two classes they exercise directly.
- **Integration path** — `WP_TESTS_DIR` points at the WordPress test
  framework (wp-env exposes `/wordpress-phpunit` automatically). The
  bootstrap hooks the plugin onto `muplugins_loaded` and then
  delegates to the WP test bootstrap. Tests extend `WP_UnitTestCase`
  and get the full WordPress runtime (factory, transactional DB,
  `do_action`, etc.).

A **classmap autoloader** is registered in the unit path for plugin
source classes that follow the WP `class-foo.php` naming convention
(not PSR-4-compatible). To add a unit test for a Language Router class,
add it to the `$lf_classmap` array in `bootstrap.php` — no
`composer dump-autoload` step required.

## WooCommerce unit tests

The `WooCommerce/` unit sub-suite uses `WcPolyfills.php` to stub
`get_post_meta`, `update_post_meta`, and WooCommerce-specific functions
so the delegation classes can be exercised without a WP runtime. Key
bugs caught here: `TaxonomyDelegate` was reading `$taxonomies` as a
SQL-quoted string (fix: use `$args['taxonomy']`); `MetaDelegate` had
an infinite recursion via `metadata_exists()` (fix: reentrancy guard
before the call).

## Testing private statics via ReflectionMethod

`KeyStoreEnvelopeTest` uses this pattern for AES helpers that are
intentionally private API. Keep this for genuinely-private internals;
prefer extracting + making `public static` over reflecting into a
conceptually-public method. `JsonRepair` is the precedent.

## See also

- `../CONTRIBUTING.md` → **Local development environment** for the
  install walkthrough.
- `../dev/README.md` for the operator-facing command reference.
