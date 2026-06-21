# tests/

PHPUnit suites for Lingua Forge.

```
tests/
├── bootstrap.php                                  ← phpunit.xml.dist points here
├── phpstan-bootstrap.php                          ← phpstan.neon.dist points here
│
├── unit/                                          ← no WordPress; pure-PHP units
│   ├── ApiPolyfills.php                           ← WP function stubs: WP_Post, WP_Screen, is_admin, get_current_screen,
│   │                                               ← is_singular, get_queried_object_id, get_post_meta, get_locale,
│   │                                               ← parse_blocks, serialize_blocks, serialize_block, wp_strip_all_tags,
│   │                                               ← size_format, untrailingslashit, sanitize_* and many more
│   ├── BlockTextExtractorTest.php                 ← reinsert + strip_interblock_br
│   ├── CacheStoreHashTest.php                     ← CacheStore::hash() input coverage
│   ├── ChunkTranslationTest.php                   ← ChunkTranslation pure helpers (split/merge/clean)
│   ├── ConfigDefaultModelsTest.php                ← Config::default_model / all_model_defaults
│   ├── ConfigPresetAddendumTest.php               ← Config::default_preset_addendum
│   ├── ConfigTest.php                             ← Config provider/model/tier resolution
│   ├── ContentGeneratorTest.php                   ← ContentGenerator prompt-building + cache-skip logic
│   ├── DataEndpointsTest.php                      ← REST /languages + /post/{id}/translations handlers
│   ├── ExcerptGeneratorTest.php                   ← ExcerptGenerator prompt-building
│   ├── FeatureControllerCapabilityTest.php        ← required_capability() exhaustiveness
│   ├── JsonRepairTest.php                         ← normalise + repair_unescaped_quotes
│   ├── KeyStoreEnvelopeTest.php                   ← v1/v2 AES envelope + AAD + tamper
│   ├── KeyStorePublicApiTest.php                  ← KeyStore public API contracts
│   ├── LanguageOverridesPanelTest.php             ← loco_custom_files(), overrides_dir(), loco_is_active() with temp dirs
│   ├── LanguageUninstallerTest.php                ← is_protected, collect_post_ids, collect_locale_files
│   ├── LinkFixerTest.php                          ← alt_scheme(), extract_internal_links(), fix_data_id_attr()
│   ├── LocaleDetectorTest.php                     ← LocaleDetector language resolution
│   ├── MetaBoxTest.php                            ← inject_instance_languages() Locale branch + fallback
│   ├── MetaDescriptionCleanOutputTest.php         ← MetaDescription::clean_output()
│   ├── QueryFilterArmTest.php                     ← QueryFilter frontend arm: nav-block pending flag, noop guards (singular, lang, block type)
│   ├── QueryFilterPageMenuExcludeTest.php         ← QueryFilter page-menu exclusion: excluded pages hidden regardless of language
│   ├── RateLimiterTest.php                        ← RateLimiter gate + quota logic
│   ├── RegressionContractsTest.php                ← pin critical string constants + key shapes
│   ├── RouterPureHelpersTest.php                  ← Manager::rewrite_lang_permalink(), Switcher::build_translated_url()
│   ├── RouterSingletonTest.php                    ← Router::reset_instance contract
│   ├── SeoAnalysisHelpersTest.php                 ← SEO analysis scoring helpers: keyword density, meta length, heading checks (42 tests)
│   ├── SeoHelpersTest.php                         ← SeoManager::lang_to_locale, SchemaManager::lang_to_bcp47/output_schema, SocialShare::rewrite_share_url (23 tests)
│   ├── TranslationLanguagesTest.php               ← Translation::LANGUAGES + get_languages()
│   ├── TranslationMemoryHashTest.php              ← TranslationMemory hash stability
│   ├── TranslationTest.php                        ← Translation pure helpers + detect_post_language() + run() early exits
│   ├── TranslationTmHelpersTest.php               ← TranslationMemoryTranslator 6 public static helpers (26 tests)
│   ├── TridGroupAccessorsTest.php                 ← TridGroup get/set accessors
│   ├── TridGroupHooksTest.php                     ← linguaforge_trid_changed action firing
│   ├── UsageRecorderContextTest.php               ← UsageRecorder context + provider recording
│   ├── WorkerConfigTest.php                       ← WorkerConfig readonly value object
│   └── WooCommerce/
│       ├── WcUnitTestCase.php                     ← base: WcPolyfills + Router stub
│       ├── WcPolyfills.php                        ← WP_Query/WP_Post stubs; get/update_post_meta; is_admin; LfWpdb stub
│       ├── Stubs/
│       │   └── TermNameTranslatorStub.php         ← stub for TermNameFilter unit tests
│       ├── AdminSaveGuardTest.php                 ← AdminSaveGuard SKU-conflict resolution: trid-linked conflicts pass, unrelated fail (12 tests)
│       ├── CatalogQueryTest.php                   ← apply_language_filter: append, double-application guard, admin skip
│       ├── LocalAttributeTranslatorTest.php       ← LocalAttributeTranslator attribute-copy logic: taxonomy skip, custom attribute copy, empty guards (17 tests)
│       ├── MetaDelegateTest.php                   ← price/stock/image delegation logic (individual + bulk reads)
│       ├── PageTagRepairTest.php                  ← PageTagRepair lazy-repair + is_protected guard (9 tests)
│       ├── StockRouterTest.php                    ← stock write routing to source
│       ├── TaxonomyDelegateTest.php               ← wp_get_object_terms delegation
│       ├── VariationDelegateTest.php              ← pre_get_posts filter; own-variations bypass
│       └── WcPageBridgeTest.php                   ← WcPageBridge pure helper coverage (32 tests)
│
└── integration/                                   ← runs inside wp-env / WP test framework
    ├── Stubs/
    │   └── StubProvider.php                       ← AIProviderInterface stub with response-queue support; no live API key needed
    ├── AbstractProviderIntegrationTest.php        ← AbstractProvider::chat() via pre_http_request: WP_Error/401/bad JSON/truncation/success
    ├── CacheStoreTest.php                         ← stats() shape, row count, date strings, hit_count, clear_all()
    ├── ConfigPresetAddendumIntegrationTest.php    ← preset_addendum + apply_compliance
    ├── ContextOptionsTest.php                     ← source_language, routing_mode, languages, detect_browser_lang, subdomain paths
    ├── CptArchiveIntegrationTest.php              ← CPT archive routing: language prefix + rewrite rules (8 tests)
    ├── FeatureControllerRestTest.php              ← REST HTTP layer: 401/403/400/404/429 + /feature/{feature}/{id} success dispatch
    ├── FrontPageQueryIntegrationTest.php          ← FrontPageQuery get_block_templates hook: wrong type noop, non-front-page noop, idempotency guard (4 tests)
    ├── GeneralTaxonomyArchiveIntegrationTest.php  ← general (non-WC) taxonomy archive routing under language prefix (7 tests)
    ├── GlossaryHashForPairTest.php                ← hash_for_pair stability + insert/delete/format_for_prompt write paths
    ├── HreflangIntegrationTest.php                ← print_hreflang_tags(), print_canonical(), print_robots(): 3-lang trid group, paged archive, noindex tag (9 tests)
    ├── LanguageUninstallerIntegrationTest.php     ← uninstall() end-to-end: posts deleted; protected lang noop; mods-disallowed path
    ├── LinkFixerScanTest.php                      ← scan_post(): wrong-language/no-translation/unresolved/correct-lang/shape
    ├── ManagerIntegrationTest.php                 ← lang_permalink() early exits: source-lang post, non-existent post ID
    ├── MetaBoxesIntegrationTest.php               ← CPT metabox exclusion: option-excluded + filter-excluded suppress all LF boxes; filter un-exclude restores them (4 tests)
    ├── MetaDescriptionIntegrationTest.php         ← MetaDescription::run() via StubProvider: success, empty response, cache hit
    ├── MetaDescriptionModuleIntegrationTest.php   ← meta-description Module: get(), save(), output_tags() bloginfo fallback
    ├── MissingTranslationNoticeBlockTest.php      ← FSE block render gating + escaping
    ├── PatternDiscoveryIntegrationTest.php        ← PatternDiscovery CPT pattern expansion
    ├── PluginBootTest.php                         ← constants + autoloader + class load
    ├── PrivacyIntegrationTest.php                 ← AI usage GDPR exporter + anonymising eraser: export shape, erasure, unknown email guards (6 tests)
    ├── ProviderChatIntegrationTest.php            ← AI provider chat() round-trips via pre_http_request (OpenAI, Gemini, Anthropic)
    ├── QueryFilterIntegrationTest.php             ← QueryFilter query-cycle: handle_parse_query, handle_pre_get_posts admin branch, query(), query_fallback() (11 tests)
    ├── RedirectorRedirectIntegrationTest.php      ← Redirector redirect firing + suppression: duplicate-slash, search-prefix, homepage, singular guards; wp_redirect seam (18 tests)
    ├── RedirectorSwitcherTest.php                 ← allow_lang_subdomains(), fix_site_logo_link(), translate_menu_items()
    ├── SchemaManagerIntegrationTest.php           ← output_schema() JSON-LD wrapping, print_schema() Article/WebPage/WebSite + inLanguage, hook suppression (7 tests)
    ├── SecondaryQueryFilterIntegrationTest.php    ← secondary query _lf_lang injection + fields=ids skip (21 tests)
    ├── SeoAnalysisPanelIntegrationTest.php        ← AJAX handler stack: nonce, capability, score output (5 tests)
    ├── SeoManagerIntegrationTest.php              ← print_og_tags() og:locale/alternate, full/locale-only/disabled modes, get_og_description() priority (10 tests)
    ├── SitemapManagerIntegrationTest.php          ← get_sitemap_chunk_xml() alternates + x-default, get_sitemap_xml() index, flush_on_save(), append_robots_txt() (10 tests)
    ├── SyncIntegrationTest.php                    ← handle_save_post(): new post gets _lf_lang + _lf_trid; lang preserved; wp_navigation
    ├── SystemPanelIntegrationTest.php             ← ajax_exclude_post_type + ajax_repair_lf_lang: option writes, exclusions, permission gates (10 tests)
    ├── TranslationIntegrationTest.php             ← Translation::run() via StubProvider: cache hit, JSON-envelope, TM path, etc.
    ├── TranslationMemoryTest.php                  ← stats() shape, bytes_estimate, idempotent store(), clear_all()
    ├── TridGroupTest.php                          ← set/get lang+trid, get_translations SQL, cache clear
    ├── UsageRecorderTest.php                      ← record()+query() round-trip, ON DUPLICATE KEY, quota, row_count(), clear_all()
    └── WooCommerce/
        ├── WcIntegrationTestCase.php              ← base: WC bootstrap + product factory helpers
        ├── AdminSaveGuardIntegrationTest.php      ← SKU-conflict resolution against real postmeta: trid-linked pass, unrelated fail (5 tests)
        ├── BootstrapIntegrationTest.php           ← WC module wiring + hook registration (incl. VariationSync, RestWriteGuard)
        ├── CouponTridMapIntegrationTest.php       ← expand_ids(): source↔translated expansion, deduplication, 3-lang group, cache cross-population (9 tests)
        ├── HposOrderIsolationTest.php             ← shop_order/shop_booking never get _lf_lang; MetaDelegate not triggered
        ├── LocalAttributeTranslatorIntegrationTest.php ← attribute copy early returns: non-product, no attributes meta, taxonomy-only (3 tests)
        ├── MetaDelegateIntegrationTest.php        ← per-key delegation round-trip against real postmeta
        ├── MetaDelegateWcApiIntegrationTest.php   ← wc_get_product() API path: price/SKU/stock on translated products/variations
        ├── OrderItemNormalizerIntegrationTest.php ← normalize_product_id(): translated→source rewrite, setting disabled, per-item filter, zero-id guard (11 tests)
        ├── ProductReviewRouterIntegrationTest.php ← redirect_submission() + serve_source_reviews(): translated→source routing, type guards, failsafe (11 tests)
        ├── PurchaseFlowIntegrationTest.php        ← stock reduced/restored on source for simple + variation on simulated purchase/refund (4 tests)
        ├── RestWriteGuardIntegrationTest.php      ← HTTP 422 on PUT/PATCH to translated products and variations
        ├── SeoSupportIntegrationTest.php          ← og:type=product, price, currency, availability; Product JSON-LD schema
        ├── StockRouterIntegrationTest.php         ← stock write routing in WP runtime
        ├── TaxonomyDelegateIntegrationTest.php    ← term delegation + cache clearing (clear_translated_product_term_cache_on_post)
        ├── TermNameIntegrationTest.php            ← _lf_term_name_{lang} swap; get_term + wp_get_object_terms Store API paths
        ├── VariationDelegateIntegrationTest.php   ← variation query scoping; translated variations not redirected
        ├── VariationSyncIntegrationTest.php       ← variation creation, TRID wiring, attribute meta, sync_wc_taxonomies_from_source
        ├── WcOrderLangIntegrationTest.php         ← capture_order_lang(), seed_pending_email_lang(), maybe_switch_email_locale(): lang stored + applied per-order (15 tests)
        ├── WcPageBridgeArchiveIntegrationTest.php ← archive routing for product taxonomies incl. Brands (14 tests)
        └── WcPageBridgeEndpointIntegrationTest.php ← My Account sub-endpoint URL building and 404 prevention (7 tests)
```

Latest counts (test methods, from source):
**736 unit**, **322 non-WC integration**, **211 WC integration** — **1269 total test methods**.
PHPUnit run counts (after data-provider expansion): **746 unit + 562 integration = 1308 total**.
E2E: **9 spec files, 72 scenarios** (Playwright, `npm run test:e2e`).
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

## Coverage

Code coverage is split into three stages. All commands run from `../dev/`.

```bash
# Run unit + integration suites with Clover XML + HTML output.
# Requires wp-env running (npm run env:start).
# pcov is installed automatically by coverage:run; no separate setup step needed.
# Activates lingua-forge + woocommerce before the integration run
# so the full WC delegation layer is exercised.
composer coverage:run

# Merge unit/integration Clovers into a single combined report.
# Can be re-run without re-running the suites (useful after adding tests).
composer coverage:merge

# Full pipeline: coverage:run → coverage:merge
composer coverage
```

Reports land in `../dev/coverage/`:

```
dev/coverage/
├── unit/
│   ├── clover.xml        ← machine-readable; consumed by coverage:merge
│   ├── coverage.txt      ← human-readable per-file summary
│   └── html/             ← browse index.html for line-level detail
├── integration/
│   ├── clover.xml        ← copied from wp-env tests-cli container
│   ├── coverage.txt
│   └── html/
└── combined/
    ├── clover.xml        ← union of unit + integration (path-normalised)
    └── summary.txt       ← ✅/🔶/❌ per-file table + overall percentage
```

The `combined/summary.txt` is the primary human-readable output — it lists every
tracked file with its merged coverage percentage. `coverage:merge` prints the
last few lines (total %) directly to the terminal as a quick sanity check.

**Notes:**
- The integration suite runs inside Docker; its clover is written to the container
  and then copied out by `scripts/copy-integration-coverage.sh`. If the container
  run fails, the old clover remains and a stale merged report is produced silently.
- `processUncoveredFiles` is not set — files never loaded during a suite simply
  won't appear in that suite's clover (but will appear if the Composer classmap
  triggers them). The combined report shows all files across both suites.

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

## Integration test patterns

- **`Plugin::boot()` guard** — `should_boot()` returns false in CLI context (not admin, REST, or WP-CLI). Call `Registry::init()` and `FeatureController::init()` explicitly in `setUp()` for tests that need the full AI feature stack. See `FeatureControllerRestTest` for the pattern.
- **REST layer** — use `rest_do_request()` with a fresh `WP_REST_Server` instance created in `setUp()` and reset to `null` in `tearDown()`.
- **AI providers** — inject a `StubProvider` via the `linguaforge_ai_provider` filter. The stub supports a response queue for multi-call scenarios. See `tests/integration/Stubs/StubProvider.php`.
- **AJAX handlers** — use `ob_start()` around the call and catch `WPDieException` for both error and success paths; parse the JSON output from the buffer.
- **wp_redirect seam** — add a `PHP_INT_MAX`-priority `wp_redirect` filter that throws a local exception to assert redirect location + status without process exit. See `RedirectorRedirectIntegrationTest`.
- **Custom DB tables** — call `ensure_table()` in `setUp()` (the activation hook does not run in CLI context and `admin_init` never fires). The `composer test:integration` script also runs a `wp eval` step that creates Glossary, CacheStore, TranslationMemory, and UsageRecorder tables before phpunit runs.
- **SEO unit tests** — pure static helpers on `SeoManager`, `SchemaManager`, `SocialShare`, and `SeoAnalysisPanel` require no WP runtime. When a helper is extraction-worthy, promote it to `public static` so unit tests can call it without reflection — this is the established pattern. The `analyze_links()` helper accepts an optional `string $home = ''` parameter so tests can pass a fixed URL; at runtime it falls back to `home_url()`.

## See also

- `../CONTRIBUTING.md` → **Local development environment** for the
  install walkthrough.
- `../dev/README.md` for the operator-facing command reference.
