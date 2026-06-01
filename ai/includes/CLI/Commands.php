<?php

namespace LinguaForge\AI\CLI;

defined('ABSPATH') || exit;

/**
 * Translate, retranslate, and manage AI cache for multilingual posts.
 *
 * ## SUBCOMMANDS
 *
 *   translate      Translate a post into one or more target languages.
 *                  Creates missing TRID-linked target posts automatically.
 *
 *   retranslate    Force a fresh retranslation, wiping the prior cache first
 *                  and marking the translation synced afterwards.
 *
 *   fill_translations      Find and fill every missing translation for a post
 *                         across all router-active languages. Skips languages
 *                         that already have a TRID-linked post.
 *
 *   missing_translations  Scan all posts of a given type and source language,
 *                         and list every post that is missing one or more
 *                         router-language translations. Use as a work-list to
 *                         drive fill_translations in bulk.
 *
 *   cache_clear    Clear AI-result cache entries (whole table, by feature,
 *                  or by post ID).
 *
 * Run `wp linguaforge <subcommand> --help` for full options and examples.
 *
 * @package Lingua Forge
 *
 * ARCHITECTURE NOTE:
 *   WP-CLI registers `linguaforge` against this class and introspects the
 *   docblocks of the public methods below to build `wp linguaforge <subcommand>
 *   --help`. Each public method is a one-line forwarder into a dedicated
 *   command class — the actual run-loop lives in TranslateCommand,
 *   RetranslateCommand, FillTranslationsCommand, MissingTranslationsCommand,
 *   and CacheClearCommand. The three translate-driver classes share their
 *   helpers via AbstractTranslateCommand.
 *
 *   The docblocks stay on this facade so WP-CLI continues to render the same
 *   help output without any registration changes.
 */
class Commands {

    /**
     * Translate a post into one or more target languages.
     *
     * Runs the full Translation feature pipeline (cache check, JSON schema,
     * provider retry/backoff, Compliance preset, JSON envelope parsing) for
     * each target language, then either prints a dry-run summary or writes
     * the translated content into the TRID-linked target-language post via
     * wp_update_post.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Source post ID to translate from.
     *
     * --to=<langs>
     * : Comma-separated list of target language codes (e.g. fr,de,es).
     *   Each must be present in Translation::get_languages().
     *
     * [--dry-run]
     * : Generate the translation but don't write anywhere. Default behavior
     *   without --dry-run is to apply the translation to the TRID-linked
     *   target-language post (if one exists) via wp_update_post.
     *
     * [--force]
     * : Skip the cache. Forces a fresh API call even when an unchanged-input
     *   cache entry exists.
     *
     * [--draft]
     * : When a new target post must be created (no TRID-linked post exists yet),
     *   always create it as 'draft' regardless of the source post's status.
     *   Without this flag the new post inherits the source status, so a published
     *   source produces a published translation. Has no effect when a target post
     *   already exists (wp_update_post never changes post_status).
     *
     * [--temperature=<float>]
     * : Override the worker temperature (0.0–1.0). Clamped to that range.
     *   When omitted, the feature default (0.2) plus any active Compliance
     *   preset override applies.
     *
     * [--max-tokens=<int>]
     * : Override the worker max_tokens. Useful when translating very long
     *   pages that hit truncation with the default ceiling.
     *
     * [--model=<name>]
     * : Override the worker model string (e.g. 'claude-sonnet-4-6').
     *
     * [--with-meta-description]
     * : After writing each translated post, generate and save an AI meta
     *   description for that post in its target language. The description is
     *   stored under _linguaforge_meta_description, exactly as if the editor
     *   had clicked "Generate Meta Description" in the post metabox. Skipped
     *   on --dry-run (no target post exists to write to).
     *
     * [--debug]
     * : Enable translation debug logging for this run. Forces debug-file writes
     *   to wp-content/uploads/lingua-forge-debug/ regardless of the Settings
     *   toggle, and prints the source prompt and raw API response for each
     *   language directly in the terminal after the call returns. Provider errors
     *   (timeouts, HTTP failures, truncation) are also echoed inline. Useful for
     *   diagnosing a specific post without enabling debug site-wide.
     *
     * [--format=<format>]
     * : Output format for the per-language results table.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * ## EXAMPLES
     *
     *   # Translate post 123 into French and German, applying to linked posts.
     *   $ wp linguaforge translate 123 --to=fr,de
     *
     *   # Translate and force new posts to draft for editorial review first.
     *   $ wp linguaforge translate 123 --to=fr,de --draft
     *
     *   # Dry-run with a stricter temperature to inspect quality.
     *   $ wp linguaforge translate 123 --to=fr --dry-run --temperature=0.1
     *
     *   # Translate and immediately generate meta descriptions for all targets.
     *   $ wp linguaforge translate 123 --to=fr,de --with-meta-description
     *
     * @when after_wp_load
     */
    public function translate( array $args, array $assoc_args ): void {
        ( new TranslateCommand() )->execute( $args, $assoc_args );
    }

    /**
     * Force a fresh retranslation of a post, clearing any cached result first.
     *
     * Designed for the common "source post was edited, retranslate now" workflow.
     * The AI-result cache for each target language is wiped before the run, so the
     * previous translation is never returned. On success, the TRID-linked target post
     * is updated and its outdated flag is cleared.
     *
     * This is equivalent to `wp linguaforge translate <id> --to=<langs> --force`, but
     * with `--temperature` as a first-class argument and with automatic sync-flag
     * clearing — so the ⚠ outdated indicator in the post list disappears after the run.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Source post ID to retranslate from.
     *
     * --to=<langs>
     * : Comma-separated list of target language codes (e.g. fr,de,es).
     *   Each must be present in Translation::get_languages().
     *
     * [--temperature=<float>]
     * : Override the AI temperature (0.0–1.0). Clamped to that range.
     *   When omitted, the active Behavior preset temperature is used:
     *   Standard 0.4 / Technical 0.2 / Legal 0.1 / Creative 0.7.
     *
     * [--max-tokens=<int>]
     * : Override the worker max_tokens ceiling. Useful for very long posts.
     *
     * [--model=<name>]
     * : Override the worker model string (e.g. 'claude-opus-4-6').
     *
     * [--draft]
     * : When a new target post must be created (no TRID-linked post exists yet),
     *   always create it as 'draft' regardless of the source post's status.
     *   Without this flag the new post inherits the source status. Has no effect
     *   when a target post already exists.
     *
     * [--dry-run]
     * : Generate the translation but don't write to the target post.
     *   Cache is still cleared and a fresh API call is still issued.
     *
     * [--with-meta-description]
     * : After writing each translated post, generate and save an AI meta
     *   description for that post in its target language. Stored under
     *   _linguaforge_meta_description. Skipped on --dry-run.
     *
     * [--debug]
     * : Enable translation debug logging for this run. Forces debug-file writes
     *   to wp-content/uploads/lingua-forge-debug/ regardless of the Settings
     *   toggle, and prints the source prompt and raw API response for each
     *   language directly in the terminal after the call returns. Provider errors
     *   are also echoed inline.
     *
     * [--format=<format>]
     * : Output format for the per-language results table.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * ## EXAMPLES
     *
     *   # Retranslate post 123 into French after editing the source.
     *   $ wp linguaforge retranslate 123 --to=fr
     *
     *   # Retranslate with legal-grade temperature for a terms-of-service page.
     *   $ wp linguaforge retranslate 123 --to=fr,de --temperature=0.1
     *
     *   # Dry-run to verify output quality before committing.
     *   $ wp linguaforge retranslate 123 --to=es --temperature=0.2 --dry-run
     *
     *   # Retranslate and regenerate meta descriptions for all targets.
     *   $ wp linguaforge retranslate 123 --to=fr,de --with-meta-description
     *
     * @when after_wp_load
     */
    public function retranslate( array $args, array $assoc_args ): void {
        ( new RetranslateCommand() )->execute( $args, $assoc_args );
    }

    /**
     * Find and fill missing translations for a post across all active languages.
     *
     * Checks every language the Language Router knows about and translates any
     * that do not yet have a TRID-linked post. Languages that already have a
     * linked post are always skipped — use `retranslate` to update existing ones.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : Source post ID to check and fill.
     *
     * [--check-only]
     * : Report which languages are missing or already have a translation, without
     *   making any API calls or creating any posts. Exits with code 1 when at
     *   least one language is missing — useful in CI pipelines.
     *
     * [--dry-run]
     * : Run the translation API calls for each missing language but do not write
     *   to the database. Posts are neither created nor updated.
     *
     * [--draft]
     * : Create all new translation posts as 'draft' regardless of the source
     *   post's status. Without this flag the new post inherits the source status
     *   (a published source yields a published translation). Has no effect when
     *   the target post already exists.
     *
     * [--exclude=<langs>]
     * : Comma-separated language codes to skip entirely (e.g. --exclude=it,fr).
     *
     * [--temperature=<float>]
     * : Override the AI temperature (0.0–1.0). Clamped to that range.
     *
     * [--max-tokens=<int>]
     * : Override the worker max_tokens ceiling. Useful for very long posts.
     *
     * [--model=<name>]
     * : Override the worker model string (e.g. 'claude-opus-4-6').
     *
     * [--format=<format>]
     * : Output format for the results table.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * [--with-meta-description]
     * : After writing each translated post, generate and save an AI meta
     *   description for that post in its target language. Stored under
     *   _linguaforge_meta_description. Skipped on --dry-run and --check-only.
     *
     * [--debug]
     * : Enable translation debug logging for this run. Forces debug-file writes
     *   to wp-content/uploads/lingua-forge-debug/ regardless of the Settings
     *   toggle, and prints the source prompt and raw API response for each
     *   language directly in the terminal. Provider errors are also echoed inline.
     *   Ignored when --check-only is set (no API call is made).
     *
     * ## EXAMPLES
     *
     *   # See which translations are missing for post 123.
     *   $ wp linguaforge fill_translations 123 --check-only
     *
     *   # Fill all missing translations, creating new posts as draft.
     *   $ wp linguaforge fill_translations 123 --draft
     *
     *   # Fill missing, skip Italian, create as draft.
     *   $ wp linguaforge fill_translations 123 --exclude=it --draft
     *
     *   # Dry-run — translate but don't write; inspect output length per language.
     *   $ wp linguaforge fill_translations 123 --dry-run
     *
     *   # Fill missing translations and immediately generate meta descriptions.
     *   $ wp linguaforge fill_translations 123 --draft --with-meta-description
     *
     *   # CI check: exit 1 when any translation is missing.
     *   $ wp linguaforge fill_translations 123 --check-only --format=json
     *
     * @when after_wp_load
     */
    public function fill_translations( array $args, array $assoc_args ): void {
        ( new FillTranslationsCommand() )->execute( $args, $assoc_args );
    }

    /**
     * List posts that are missing translations in one or more router languages.
     *
     * Scans every post of a given post type whose _lang meta matches the
     * supplied source language, checks each post's TRID translation group,
     * and reports any router language for which no linked translation post
     * exists yet. Use the output as a work-list to drive fill_translations.
     *
     * ## OPTIONS
     *
     * <lang>
     * : Source language code to scan (e.g. ca, en, de).
     *   Only posts whose _lang post meta equals this value are included.
     *
     * <post_type>
     * : WordPress post type to query (e.g. post, page, or a custom type).
     *
     * [--exclude=<langs>]
     * : Comma-separated language codes to ignore when checking for missing
     *   translations (e.g. --exclude=it,fr).
     *
     * [--status=<status>]
     * : Only include posts with this post_status. Accepts any single WP
     *   status or 'any'.
     *   ---
     *   default: publish
     *   ---
     *
     * [--format=<format>]
     * : Output format.
     *   ---
     *   default: table
     *   options:
     *     - table
     *     - json
     *     - csv
     *     - yaml
     *   ---
     *
     * ## EXAMPLES
     *
     *   # Show all Catalan pages with missing translations.
     *   $ wp linguaforge missing_translations ca page
     *
     *   # Same, but ignore Italian (not yet required).
     *   $ wp linguaforge missing_translations ca page --exclude=it
     *
     *   # Include drafts as well as published posts.
     *   $ wp linguaforge missing_translations ca post --status=any
     *
     *   # Machine-readable output to feed into a shell loop.
     *   $ wp linguaforge missing_translations ca page --format=json
     *
     *   # Pipe directly to fill_translations for each found post.
     *   $ wp linguaforge missing_translations ca page --format=json \
     *       | jq -r '.[].post_id' \
     *       | xargs -I{} wp linguaforge fill_translations {} --draft
     *
     * @when after_wp_load
     */
    public function missing_translations( array $args, array $assoc_args ): void {
        ( new MissingTranslationsCommand() )->execute( $args, $assoc_args );
    }

    /**
     * Clear AI-result cache entries.
     *
     * With no options, truncates the entire wp_lingua_forge_ai_cache table
     * (matching the Maintenance → AI Cache → "Clear AI Cache" button). Use
     * the options to scope deletion to a single feature, a single post, or
     * both.
     *
     * ## OPTIONS
     *
     * [--feature=<name>]
     * : Feature-key prefix to match. 'translation' clears every per-language
     *   translation cache (translation_fr, translation_de, …). Other valid
     *   prefixes: 'meta-description', 'excerpt', 'content_generator'.
     *
     * [--post-id=<id>]
     * : Only clear entries for this post ID.
     *
     * [--yes]
     * : Skip the confirmation prompt when truncating the whole table.
     *
     * ## EXAMPLES
     *
     *   # Clear every cached translation across the whole site.
     *   $ wp linguaforge cache_clear --feature=translation
     *
     *   # Clear all cached AI results for one post.
     *   $ wp linguaforge cache_clear --post-id=123
     *
     *   # Nuke the whole cache, no prompt.
     *   $ wp linguaforge cache_clear --yes
     *
     * @when after_wp_load
     */
    public function cache_clear( array $args, array $assoc_args ): void {
        ( new CacheClearCommand() )->execute( $args, $assoc_args );
    }

    /**
     * Backfill _lf_lang and _lf_trid on wp_navigation posts created by Lingua Forge.
     *
     * Navigation posts created before v2.1.0 are missing the _lf_lang post-meta
     * that QueryFilter uses to scope the Page List block to the correct language,
     * and the _lf_trid that links sibling navigations as translation pairs in the
     * admin Translation column.
     *
     * Language tagging: infers the language from the post slug suffix (e.g.
     * "primary-navigation-de" → "de"). Source-language navigations (whose slug
     * is the base for sibling translations) are tagged with the site's source
     * language code.
     *
     * TRID grouping: after tagging, navigations that share a base slug (e.g.
     * "navigation", "navigation-de", "navigation-ca") are linked under a shared
     * TRID UUID so the admin column shows them as a translation group.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Print what would be changed without writing any meta.
     *
     * ## EXAMPLES
     *
     *   # Preview what would be tagged and linked.
     *   $ wp linguaforge fix_nav_lang --dry-run
     *
     *   # Apply the fix.
     *   $ wp linguaforge fix_nav_lang
     *
     * @when after_wp_load
     */
    public function fix_nav_lang( array $args, array $assoc_args ): void {
        ( new FixNavLangCommand() )->execute( $args, $assoc_args );
    }
}
