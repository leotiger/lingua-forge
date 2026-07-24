#!/usr/bin/env php
<?php
/**
 * translate-missing.php — AI-translate every empty string in
 * languages/lingua-forge-*.po directly, replacing the manual
 * per-string translation pass this project relied on until now.
 *
 * Mirrors Agnosis's dev/bin/translate-missing.php (`composer translate-missing`
 * there) — same flags, same batching/plural/logging design — adapted to
 * Lingua Forge's own locale list, file naming, and domain vocabulary.
 *
 * This translates the PLUGIN'S OWN ADMIN-UI STRINGS (settings labels,
 * button text, error/success messages, WP-CLI help) — not to be confused
 * with Translation, the plugin's own AI *content* translation feature
 * (posts/pages/products). This script is dev tooling that runs on a
 * contributor's machine against .po files; it never touches WordPress or
 * a live site.
 *
 * Why this exists: opening each .po file by hand in a translation editor
 * (no repo trace of what changed or why) is slow and manual. This script
 * finds every empty msgstr/msgstr[N] across every locale — ordinary
 * untranslated strings AND plural-slot gaps in one pass — and fills them
 * by calling an AI provider's API with a prompt that knows what Lingua
 * Forge actually is, so translations land in the right register instead
 * of a generic machine-translation guess.
 *
 * Supports the same three providers as the plugin itself (Settings → AI
 * Provider): Anthropic (default), OpenAI, Gemini — pick one with
 * --provider=. Request/response shapes here mirror this plugin's own
 * ai/includes/Providers/{Anthropic,OpenAI,Gemini}.php exactly (same
 * endpoints, auth style, and response fields), so behavior stays
 * consistent with how the plugin itself talks to each provider.
 *
 * This is a narrow, ON-DEMAND tool (composer translate-missing), same
 * spirit as clear-fuzzy.php — not wired into make-pot.sh/compile-pos.sh,
 * not run automatically on every version bump. Fits between them in the
 * pipeline: make-pot.sh (steps 1–3: extract, merge, clear fuzzy) leaves
 * every new/changed string blank; this script fills those blanks; then
 * run compile-pos (steps 4–5: compile to .mo + .l10n.php).
 *
 * If --provider= isn't passed and STDIN is an interactive terminal, the
 * script first asks which provider to use (Enter for Anthropic, the
 * default); non-interactive runs (CI, a pipe) skip straight to Anthropic
 * with no prompt. Requires an API key for whichever provider ends up
 * selected — either export it before running (ANTHROPIC_API_KEY /
 * OPENAI_API_KEY / GEMINI_API_KEY, same names the plugin itself checks —
 * see ai/includes/Core/KeyStore.php), or leave it unset and this script
 * prompts for it interactively too (input hidden, used for that run only,
 * never written to any file in this repo or persisted anywhere by the
 * script itself). This is a separate key from anything a site running
 * Lingua Forge stores at runtime (the plugin's own encrypted Settings →
 * AI Provider key); this script only ever runs on a developer's own
 * machine, never inside WordPress.
 *
 * Writes AI translations DIRECTLY into the .po files, same trade-off
 * Agnosis's script accepted (see its AUDIT-0.9.44.md §5d annotation for
 * the discussion) — and saves after EVERY batch, not just once a whole
 * locale finishes, so an interrupted run (Ctrl-C, a crash, or an external
 * wrapper killing the process on its own timeout) keeps whatever was
 * already translated. Every write is also appended to
 * dev/translate-missing.log for a fast post-hoc skim; nothing here claims
 * professional/native-speaker accuracy — same caveat as every AI-drafted
 * string this project ships (e.g. AI-generated meta descriptions).
 *
 * If you're running this through a tool/wrapper that enforces its own
 * process timeout (e.g. a 300-second cap), pass --time-budget=SECONDS set
 * safely under that limit; the script then stops itself cleanly before a
 * new batch starts, instead of being killed mid-batch. Because progress is
 * saved per batch either way, just re-run the same command — it picks up
 * exactly where the previous run left off.
 *
 * Usage (from dev/):
 *   php bin/translate-missing.php                       # all locales, all gaps, Anthropic
 *   php bin/translate-missing.php --dry-run              # call the API, print results, write nothing
 *   php bin/translate-missing.php --locale=de_DE         # scope to one locale
 *   php bin/translate-missing.php --limit=10             # cap items translated (testing/cost control)
 *   php bin/translate-missing.php --batch-size=40         # MAX items per API call (default 40) — a
 *                                                        # batch may still be split smaller than this
 *                                                        # if its items' combined content is long
 *                                                        # enough to need it (see MAX_CHARS_PER_CHUNK)
 *   php bin/translate-missing.php --provider=openai       # anthropic (default) | openai | gemini — skips the interactive picker
 *   php bin/translate-missing.php --model=gpt-4o-mini     # override the provider's default model
 *   php bin/translate-missing.php --time-budget=270       # stop cleanly under a 300s wrapper timeout; re-run to resume
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$devDir  = dirname(__DIR__);
$langDir = $devDir . '/../languages';
$logFile = $devDir . '/translate-missing.log';

// Light-tier default model per provider — matches this plugin's own
// Settings → AI Provider → Models defaults (Config::MODEL_DEFAULTS);
// short UI strings don't need the Quality tier's reasoning budget.
const PROVIDER_MODEL_DEFAULTS = [
	'anthropic' => 'claude-haiku-4-5-20251001',
	'openai'    => 'gpt-4o-mini',
	'gemini'    => 'gemini-2.5-flash-lite',
];

// Where to get a key + which env var this script (and the plugin itself,
// per ai/includes/Core/KeyStore.php) checks for it. 'label' matches the
// display name each ai/includes/Providers/*.php class's own
// provider_label() returns, for one consistent name across the plugin
// and this script.
const PROVIDER_KEY_INFO = [
	'anthropic' => ['env' => 'ANTHROPIC_API_KEY', 'url' => 'https://console.anthropic.com/', 'label' => 'Anthropic'],
	'openai'    => ['env' => 'OPENAI_API_KEY', 'url' => 'https://platform.openai.com/api-keys', 'label' => 'OpenAI'],
	'gemini'    => ['env' => 'GEMINI_API_KEY', 'url' => 'https://aistudio.google.com/apikey', 'label' => 'Gemini'],
];

// Max combined source-character count per batch, regardless of item count.
// Paired with lf_estimate_max_tokens() below — sized so that a chunk right
// at this budget still lands comfortably under the 8192 max_tokens/
// maxOutputTokens cap once translated/JSON-escaped, rather than exactly at
// it. Ported from Agnosis's dev/bin/translate-missing.php (2026-07-24 sync),
// which added this after a real batch of long AI-Provider-settings-style
// descriptions truncated mid-response under the old flat count-based
// formula. See lf_chunk_items_by_budget().
const MAX_CHARS_PER_CHUNK = 2600;

$onlyLocale       = null;
$dryRun           = false;
$limit            = null;
$batchSize        = 40;
$provider         = 'anthropic';
$providerExplicit = false; // true once --provider= is seen; suppresses the interactive picker below
$model            = null; // resolved from PROVIDER_MODEL_DEFAULTS below once $provider is known
$timeBudget       = null; // seconds; null = unlimited (see --time-budget below)

foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--dry-run') {
		$dryRun = true;
	} elseif (str_starts_with($arg, '--locale=')) {
		$onlyLocale = substr($arg, strlen('--locale='));
	} elseif (str_starts_with($arg, '--limit=')) {
		$limit = (int) substr($arg, strlen('--limit='));
	} elseif (str_starts_with($arg, '--batch-size=')) {
		$batchSize = max(1, (int) substr($arg, strlen('--batch-size=')));
	} elseif (str_starts_with($arg, '--provider=')) {
		$provider = substr($arg, strlen('--provider='));
		$providerExplicit = true;
	} elseif (str_starts_with($arg, '--model=')) {
		$model = substr($arg, strlen('--model='));
	} elseif (str_starts_with($arg, '--time-budget=')) {
		$timeBudget = max(1, (int) substr($arg, strlen('--time-budget=')));
	} elseif ($arg === '--help' || $arg === '-h') {
		fwrite(STDOUT, "Usage: php bin/translate-missing.php [--dry-run] [--locale=xx] [--limit=N] [--batch-size=N] [--provider=anthropic|openai|gemini] [--model=NAME] [--time-budget=SECONDS]\n");
		exit(0);
	} else {
		fwrite(STDERR, "Unknown argument: {$arg}\n");
		exit(1);
	}
}

/**
 * Interactively ask which provider to use, when --provider= wasn't passed
 * on the command line. Returns $default unchanged (never prompting) if
 * STDIN isn't an interactive TTY — e.g. CI, a pipe, or a non-interactive
 * shell — same non-hanging fallback as lf_prompt_for_api_key() below —
 * or if the reply is empty (plain Enter) or unrecognized.
 */
function lf_prompt_for_provider(string $default): string {
	if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
		return $default;
	}

	fwrite(STDOUT, "Which AI provider? [1] Anthropic  [2] OpenAI  [3] Gemini (Enter for Anthropic): ");
	$answer = strtolower(trim((string) fgets(STDIN)));

	return match ($answer) {
		'', '1', 'anthropic' => 'anthropic',
		'2', 'openai'        => 'openai',
		'3', 'gemini'        => 'gemini',
		default              => $default,
	};
}

if (!$providerExplicit) {
	$provider = lf_prompt_for_provider($provider);
}

if (!isset(PROVIDER_MODEL_DEFAULTS[$provider])) {
	fwrite(STDERR, "Unknown --provider: {$provider}. Supported: " . implode(', ', array_keys(PROVIDER_MODEL_DEFAULTS)) . "\n");
	exit(1);
}
$providerLabel = PROVIDER_KEY_INFO[$provider]['label'];
if ($model === null) {
	$model = PROVIDER_MODEL_DEFAULTS[$provider];
	fwrite(STDERR, "No --model given — defaulting to {$model} for {$providerLabel}. Provider model names move fast; verify this is still current, or pin one with --model=NAME.\n");
}

if (!function_exists('curl_init')) {
	fwrite(STDERR, "PHP's curl extension is required but not available.\n");
	exit(1);
}

/**
 * Prompt for an API key on STDIN with input hidden, when the provider's
 * env var isn't set. Returns '' (never prompting) if STDIN isn't an
 * interactive TTY — e.g. CI, a pipe, or a non-interactive shell — so the
 * caller can fall through to the existing "not set" error instead of
 * hanging on a read.
 */
function lf_prompt_for_api_key(string $providerLabel, string $envVar): string {
	if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
		return '';
	}

	fwrite(STDOUT, "{$envVar} is not set in the environment.\nEnter your {$providerLabel} API key now (input hidden; used for this run only, never saved anywhere): ");

	$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
	if (!$isWindows) {
		shell_exec('stty -echo 2>/dev/null');
	}
	$key = trim((string) fgets(STDIN));
	if (!$isWindows) {
		shell_exec('stty echo 2>/dev/null');
	}
	fwrite(STDOUT, "\n");

	return $key;
}

$keyInfo = PROVIDER_KEY_INFO[$provider];
$apiKey  = getenv($keyInfo['env']);
if ($apiKey === false || $apiKey === '') {
	$apiKey = lf_prompt_for_api_key($providerLabel, $keyInfo['env']);
}
if ($apiKey === '') {
	fwrite(STDERR, "No API key provided for {$providerLabel}. Either export it before running:\n  export {$keyInfo['env']}=...\nor re-run interactively and enter it at the prompt. Get a key at {$keyInfo['url']}\n");
	exit(1);
}

// Clock starts here, not at script entry — so time spent waiting on a human
// typing an interactive API key doesn't count against --time-budget.
$scriptStart = microtime(true);

// Locale list + English names — must match dev/bin/make-pot.sh's LOCALES
// array (the 26 locales this plugin ships .po files for) and, where a
// language also appears in the AI content-translation feature's own list
// (Translation::LANGUAGES), the same English name — e.g. 'Persian', not
// 'Persian (Farsi)' — for one consistent vocabulary across both prompts.
// Region-specific locale codes (de_DE, es_ES, …) get the regional name
// (e.g. 'Spanish (Spain)') since that's more precise for a translator model
// than the bare language name.
const LOCALE_NAMES = [
	'ar'    => 'Arabic',
	'ca'    => 'Catalan',
	'de_DE' => 'German',
	'el'    => 'Greek',
	'en_US' => 'English (US) — the source strings are already English; only Americanize any British spelling that slipped in (colour→color, organise→organize, etc.), otherwise return the source unchanged',
	'es_ES' => 'Spanish (Spain)',
	'eu'    => 'Basque',
	'fa_IR' => 'Persian',
	'fr_FR' => 'French (France)',
	'hi_IN' => 'Hindi',
	'hu_HU' => 'Hungarian',
	'id_ID' => 'Indonesian',
	'it_IT' => 'Italian',
	'ja'    => 'Japanese',
	'km'    => 'Khmer',
	'ko_KR' => 'Korean',
	'nl_NL' => 'Dutch',
	'pl_PL' => 'Polish',
	'pt_PT' => 'Portuguese (Portugal)',
	'ru_RU' => 'Russian',
	'sv_SE' => 'Swedish',
	'sw'    => 'Swahili',
	'th'    => 'Thai',
	'tr_TR' => 'Turkish',
	'ur'    => 'Urdu',
	'zh_CN' => 'Chinese (Simplified)',
];

const DOMAIN_PROMPT = <<<'PROMPT'
You are translating admin-dashboard UI strings for Lingua Forge, a free
WordPress plugin providing multilingual URL routing, hreflang/Open Graph/
Schema.org SEO, a language switcher, and AI-powered translation tools — all
in one plugin, no companion SEO or translation plugin required. Key
vocabulary you will see in these strings and should translate consistently:
- "Language Router" — the core URL-routing module (path-prefix like /de/,
  or subdomain like de.example.com)
- "primary language" — the site's source/default content language
- "TRID" — Translation ID, the identifier linking a group of sibling posts
  (one per language) as translations of each other; usually left
  untranslated as a technical term, like an acronym
- "hreflang" — the SEO tag search engines use to serve the right language
  variant; a technical/SEO term, do not translate
- "Language Switcher" — the front-end block/shortcode letting visitors
  change language
- "Translate" / "Translation" (capitalized, referring to the AI feature) —
  the AI-powered content translation tool (as opposed to "translation" the
  general concept)
- "Quick Translate" / "Translate chunk" — the AI feature for translating a
  short pasted snippet rather than a whole post
- "Translation Memory" — the block-level cache that reuses prior AI
  translations instead of re-paying for identical content
- "Sync" — the action that refreshes a translated post's language-specific
  template/structural data from its source post
- "glossary" — the mandatory terminology list (source term → target term)
  the AI translation prompt enforces
- "FSE template" — a Full Site Editing (block theme) template or template
  part; "Template Sync"/"Re-create" are actions on these
- "meta description" — the AI-generated SEO meta description for a
  translated post
- "preset" (Settings → Behavior) — a named bundle of AI translation
  instructions (Standard, Technical/Scientific, Legal/Compliance,
  Creative/Marketing)
- these strings are WordPress admin-dashboard and WP-CLI UI text — keep the
  register plain, direct, and functional, matching ordinary WordPress
  plugin admin UI copy, not marketing language

Translation rules, no exceptions:
1. Preserve every placeholder EXACTLY as given: %s, %d, %1$s, %2$d, etc. Never translate, drop, or reorder them unless the target language's grammar requires reordering — if so, convert to explicit positional form (%1$s, %2$s, ...) consistently.
2. Preserve HTML tags exactly (e.g. <a href="...">...</a>, <strong>, <code>) — translate only the text between tags, never the tags or attributes.
3. Preserve inline code/technical identifiers exactly as given (option names, filter/hook names, CLI command names, class names) — these are not prose, do not transliterate or translate them.
4. Prefer gender-neutral phrasing where the target language allows it naturally, rather than defaulting to a masculine or feminine form.
5. Where a "reference" (already-translated sibling text in the same language) is given for a plural entry, match its exact register, terminology choices, and grammatical pattern — extend it correctly to the requested plural category, using standard grammatical agreement for that language and count range.
6. Where no reference is given, translate naturally for a native speaker of the target locale; this is a first pass, not a final professional translation, so prioritize correctness and natural phrasing over cleverness.
7. Some items include an "existing_translation" and "missing_placeholders" — these are NOT empty strings, they're translations that already read naturally but are missing a required placeholder (most often because the target language's plural/dual form doesn't grammatically need to state the number). FIX the existing translation by inserting the listed missing placeholder(s) — every gettext-compiled build still needs the placeholder literally present so a runtime substitution has somewhere to go. Keep the existing phrasing/register; don't retranslate from scratch unless the existing text is otherwise wrong.

You will be given a JSON array of items to translate, each with a stable "id". Respond with ONLY a single raw JSON object mapping each id to its translated string — no markdown fences, no commentary, no extra keys, no omitted ids.
PROMPT;

/**
 * Unescape / escape a PO string literal's inner text.
 */
function lf_po_unescape(string $inner): string {
	return str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $inner);
}
function lf_po_escape(string $raw): string {
	return str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\\"', '\\n', '\\t'], $raw);
}

function lf_parse_nplurals(string $poText): ?int {
	if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;/', $poText, $m)) {
		return (int) $m[1];
	}
	return null;
}
function lf_parse_plural_formula(string $poText): string {
	if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*\d+;\s*plural\s*=\s*([^;]*);/', $poText, $m)) {
		return trim($m[1]);
	}
	return '';
}

/**
 * Grab the nearest "#. translators:" comment directly above a msgid
 * start line, if present — useful context for the model, e.g. "%d:
 * number of posts affected".
 */
function lf_translator_comment(array $lines, int $msgidLine): ?string {
	for ($back = 1; $back <= 6; $back++) {
		$idx = $msgidLine - $back;
		if ($idx < 0) {
			break;
		}
		if (preg_match('/^#\.\s*translators:\s*(.+)$/', $lines[$idx], $m)) {
			return trim($m[1]);
		}
		if ($lines[$idx] === '' || preg_match('/^msgid/', $lines[$idx])) {
			break;
		}
	}
	return null;
}

/**
 * Grab the nearest "#, ..." flags comment directly above a msgid start
 * line, if present — split on commas, e.g. "#, fuzzy, php-format" becomes
 * ['fuzzy', 'php-format']. Ported from Agnosis (2026-07-24 sync); used to
 * scope the format-placeholder check below to entries gettext itself
 * considers format strings, rather than flagging any translation that
 * happens to contain a literal "%s"/"%d".
 */
function lf_entry_flags(array $lines, int $msgidLine): array {
	for ($back = 1; $back <= 6; $back++) {
		$idx = $msgidLine - $back;
		if ($idx < 0) {
			break;
		}
		if (preg_match('/^#,\s*(.+)$/', $lines[$idx], $m)) {
			return array_map('trim', explode(',', $m[1]));
		}
		if ($lines[$idx] === '' || preg_match('/^msgid/', $lines[$idx])) {
			break;
		}
	}
	return [];
}

/**
 * Extract every printf-style placeholder from a string: %s, %d, %1$s,
 * %2$d, etc. Returns a plain (non-unique) list — duplicates matter, since
 * a translation genuinely needs to repeat a placeholder as many times as
 * the source does. Ported from Agnosis (2026-07-24 sync).
 */
function lf_extract_placeholders(string $s): array {
	preg_match_all('/%(?:\d+\$)?[sdfeEgGxXou]/', $s, $m);
	return $m[0];
}

/**
 * Compare a translation's placeholders against the source's expected set
 * (already-sorted list). Returns the sorted list of placeholders the
 * translation is missing (multiset difference — one occurrence removed per
 * match found), or [] if nothing is missing. Doesn't flag *extra*
 * placeholders — mirrors Agnosis's I-2 audit finding, where the observed
 * failure mode was always a dropped placeholder, never an added one.
 * Ported from Agnosis (2026-07-24 sync).
 */
function lf_missing_placeholders(array $expectedSorted, string $translated): array {
	$missing = $expectedSorted;
	foreach (lf_extract_placeholders($translated) as $found) {
		$pos = array_search($found, $missing, true);
		if ($pos !== false) {
			unset($missing[$pos]);
		}
	}
	return array_values($missing);
}

/**
 * Walk a .po file and yield BOTH singular and plural entries as a
 * unified list, each tagged 'type' => 'single'|'plural'. Multi-line
 * (wrapped) msgid/msgid_plural text is concatenated correctly; entries
 * whose msgstr/msgstr[N] slot(s) are themselves wrapped across multiple
 * lines are flagged 'multiline_warning' and never written to.
 */
function lf_parse_entries(array $lines): array {
	$entries = [];
	$i = 0;
	$n = count($lines);

	while ($i < $n) {
		$line = $lines[$i];
		$isMsgidLine = preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m) && $m[1] !== '' || preg_match('/^msgid\s+""\s*$/', $line);

		if ($isMsgidLine) {
			$msgidStart = $i;
			$msgidParts = [];
			if (preg_match('/^msgid\s+"(.*)"\s*$/', $line, $m)) {
				$msgidParts[] = $m[1];
			}
			$j = $i + 1;
			while ($j < $n && preg_match('/^"(.*)"\s*$/', $lines[$j], $cm)) {
				$msgidParts[] = $cm[1];
				$j++;
			}
			$msgid = lf_po_unescape(implode('', $msgidParts));

			// Plural?
			if ($j < $n && preg_match('/^msgid_plural\s+"(.*)"\s*$/', $lines[$j], $pm)) {
				$pluralParts = [$pm[1]];
				$k = $j + 1;
				while ($k < $n && preg_match('/^"(.*)"\s*$/', $lines[$k], $cm)) {
					$pluralParts[] = $cm[1];
					$k++;
				}
				$msgidPlural = lf_po_unescape(implode('', $pluralParts));

				$slots = [];
				$m2 = $k;
				$anySlotMultilineWarning = false;
				while ($m2 < $n && preg_match('/^msgstr\[(\d+)\]\s+"(.*)"\s*$/', $lines[$m2], $sm)) {
					$idx           = (int) $sm[1];
					$slotStartLine = $m2;
					$rawParts      = [$sm[2]];
					$m2++;

					// Ported from Agnosis (2026-07-24 sync, ritual-row fix): the
					// old version stopped this whole while() the instant it hit a
					// line not starting with "msgstr[" — which is exactly what a
					// wrapped slot's OWN continuation line looks like. So a
					// wrapped msgstr[0] (e.g. a long source form) didn't just get
					// flagged; it silently truncated parsing of every slot after
					// it, meaning higher-index slots were never even discovered,
					// let alone considered for translation. Now every
					// continuation line belonging to THIS slot is consumed (and
					// its content concatenated, same as msgid/msgid_plural above)
					// before looking for the next msgstr[N] line, so scanning
					// always reaches every slot regardless of which ones happen
					// to be wrapped.
					$slotMultilineWarning = false;
					while ($m2 < $n
						&& preg_match('/^"(.*)"\s*$/', $lines[$m2], $cm)
						&& !preg_match('/^(msgstr\[|msgid|msgctxt|#)/', $lines[$m2])
					) {
						$rawParts[] = $cm[1];
						$slotMultilineWarning = true;
						$m2++;
					}
					if ($slotMultilineWarning) {
						$anySlotMultilineWarning = true;
					}

					$rawInner = implode('', $rawParts);
					$slots[$idx] = [
						'line'              => $slotStartLine,
						'text'              => lf_po_unescape($rawInner),
						'empty'             => $rawInner === '',
						// A wrapped slot is still never selected for translation
						// (see the per-slot check in the work-list loop below) —
						// this flag is what enforces that — but its 'text'/
						// 'empty' are now reconstructed correctly too, so a
						// sibling slot's reference-translation context isn't
						// wrongly built from a truncated "" instead of the
						// slot's real content.
						'multiline_warning' => $slotMultilineWarning,
					];
				}

				$entries[] = [
					'type'              => 'plural',
					'msgid'             => $msgid,
					'msgid_plural'      => $msgidPlural,
					'slots'             => $slots,
					// Aggregate (any slot wrapped), kept for anything that
					// inspects entries generically — the work-list loop below
					// checks each slot's OWN flag instead of this one.
					'multiline_warning' => $anySlotMultilineWarning,
					'start_line'        => $msgidStart,
					'comment'           => lf_translator_comment($lines, $msgidStart),
					'flags'             => lf_entry_flags($lines, $msgidStart),
				];
				$i = $m2;
				continue;
			}

			// Singular: msgstr "..." right after the (possibly multi-line) msgid.
			if ($j < $n && preg_match('/^msgstr\s+"(.*)"\s*$/', $lines[$j], $sm)) {
				$rawInner = $sm[1];
				$multilineWarning = ($j + 1 < $n
					&& preg_match('/^"(.*)"\s*$/', $lines[$j + 1])
					&& !preg_match('/^(msgid|msgctxt|#)/', $lines[$j + 1]));

				// Skip the file's own header entry: empty msgid + non-empty msgstr
				// containing the Content-Type/Plural-Forms block.
				$isHeader = ($msgid === '' && $rawInner !== '');

				if (!$isHeader) {
					$entries[] = [
						'type'              => 'single',
						'msgid'             => $msgid,
						'line'              => $j,
						'text'              => lf_po_unescape($rawInner),
						'empty'             => $rawInner === '',
						'multiline_warning' => $multilineWarning,
						'start_line'        => $msgidStart,
						'comment'           => lf_translator_comment($lines, $msgidStart),
						'flags'             => lf_entry_flags($lines, $msgidStart),
					];
				}
				$i = $j + ($multilineWarning ? 2 : 1);
				continue;
			}
		}

		$i++;
	}

	return $entries;
}

/**
 * Build [url, headers, jsonBody] for one provider's translate-batch
 * request. Mirrors ai/includes/Providers/{Anthropic,OpenAI,Gemini}.php's
 * build_request() in the plugin itself — same endpoints, auth style, and
 * request shapes. No structured-output schema is requested (batch item
 * ids vary per call); this script parses/repairs the JSON reply the same
 * defensive way for all three providers in lf_call_ai() below.
 */
function lf_build_request(string $provider, string $model, string $apiKey, string $system, string $userPayload, int $maxTokens): array {

	if ($provider === 'openai') {
		return [
			'https://api.openai.com/v1/chat/completions',
			[
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			],
			(string) json_encode([
				'model'      => $model,
				'messages'   => [
					['role' => 'system', 'content' => $system],
					['role' => 'user', 'content' => "Translate this batch:\n\n" . $userPayload],
				],
				'max_tokens' => $maxTokens,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	if ($provider === 'gemini') {
		return [
			'https://generativelanguage.googleapis.com/v1beta/models/'
				. rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey),
			[
				'Content-Type: application/json',
			],
			(string) json_encode([
				'contents'           => [
					['role' => 'user', 'parts' => [['text' => "Translate this batch:\n\n" . $userPayload]]],
				],
				'system_instruction' => ['parts' => [['text' => $system]]],
				'generationConfig'   => [
					'maxOutputTokens'  => $maxTokens,
					'responseMimeType' => 'application/json',
				],
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	// Default / 'anthropic'.
	return [
		'https://api.anthropic.com/v1/messages',
		[
			'content-type: application/json',
			'x-api-key: ' . $apiKey,
			'anthropic-version: 2023-06-01',
		],
		(string) json_encode([
			'model'      => $model,
			'max_tokens' => $maxTokens,
			'system'     => $system,
			'messages'   => [
				['role' => 'user', 'content' => "Translate this batch:\n\n" . $userPayload],
			],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
	];
}

/**
 * Extract the raw text reply + normalized ['input'=>,'output'=>] token
 * usage from a decoded API response, per provider. Mirrors
 * extract_text()/extract_usage() in the plugin's own Provider classes —
 * field names and nesting differ per provider's API, normalized here to
 * one shape so the calling code stays provider-agnostic.
 */
function lf_extract_response(string $provider, array $decoded): array {

	if ($provider === 'openai') {
		$text  = (string) ($decoded['choices'][0]['message']['content'] ?? '');
		$usage = $decoded['usage'] ?? null;
		$normalized = is_array($usage) ? [
			'input'  => (int) ($usage['prompt_tokens'] ?? 0),
			'output' => (int) ($usage['completion_tokens'] ?? 0),
		] : null;
		return [$text, $normalized];
	}

	if ($provider === 'gemini') {
		$text  = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
		$usage = $decoded['usageMetadata'] ?? null;
		$normalized = is_array($usage) ? [
			'input'  => (int) ($usage['promptTokenCount'] ?? 0),
			'output' => (int) ($usage['candidatesTokenCount'] ?? 0),
		] : null;
		return [$text, $normalized];
	}

	// Default / 'anthropic'.
	$text  = (string) ($decoded['content'][0]['text'] ?? '');
	$usage = $decoded['usage'] ?? null;
	$normalized = is_array($usage) ? [
		'input'  => (int) ($usage['input_tokens'] ?? 0),
		'output' => (int) ($usage['output_tokens'] ?? 0),
	] : null;
	return [$text, $normalized];
}

/**
 * Recursively sum the character length of every string value in a payload
 * (handles nested arrays like reference_translations_other_slots, the
 * sibling-plural-slot map on plural entries). Ported from Agnosis
 * (2026-07-24 sync).
 */
function lf_payload_char_length($value): int {
	if (is_string($value)) {
		return mb_strlen($value);
	}
	if (is_array($value)) {
		$sum = 0;
		foreach ($value as $v) {
			$sum += lf_payload_char_length($v);
		}
		return $sum;
	}
	return 0;
}

/**
 * Estimate a safe max_tokens/maxOutputTokens budget for one batch, based on
 * actual source content length rather than a flat per-item count.
 *
 * The original formula, min(8192, max(2048, count($items) * 150)), assumed
 * every item is a short UI string. It isn't: some Settings descriptions run
 * 300-500+ characters, and a batch of just a handful of such items could
 * total ~2000 source characters — once translated into a language that
 * expands on English (German) or wrapped in escaped JSON, the actual output
 * token need blows past the 2048-token floor this formula assigned, so the
 * model truncates mid-response and the whole batch fails to parse. This is
 * what broke a batch in Agnosis's own run of the sibling script (de_DE/
 * zh_CN), fixed there 2026-07-24 and ported here as a preventive measure —
 * summing real content length and applying a generous per-character
 * multiplier (covering translation expansion, JSON-escaping overhead, and
 * denser tokenization in some scripts) avoids needing per-language tuning.
 *
 * Floor 2048 / cap 8192 unchanged from the original formula.
 */
function lf_estimate_max_tokens(array $items): int {
	$totalChars = 0;
	foreach ($items as $item) {
		$totalChars += lf_payload_char_length($item['payload'] ?? $item);
	}
	return min(8192, max(2048, (int) ceil($totalChars * 3) + 200));
}

/**
 * Split $items into chunks respecting BOTH a max item count ($maxCount, the
 * --batch-size value) AND a max combined source-content budget per chunk
 * ($maxCharsPerChunk) — a small item count can already contain enough long
 * strings to blow the token budget on its own, so count-only chunking
 * (array_chunk()) isn't enough. Greedy bin-packing: keep adding items to the
 * current chunk until either limit would be exceeded, then start a new one.
 * A single item that is already over budget on its own still gets its own
 * solo chunk — it can't be split across two API calls. Ported from Agnosis
 * (2026-07-24 sync).
 */
function lf_chunk_items_by_budget(array $items, int $maxCount, int $maxCharsPerChunk): array {
	$chunks       = [];
	$current      = [];
	$currentChars = 0;

	foreach ($items as $item) {
		$itemChars = lf_payload_char_length($item['payload'] ?? $item);

		if ($current !== []
			&& (count($current) >= $maxCount || $currentChars + $itemChars > $maxCharsPerChunk)
		) {
			$chunks[]     = $current;
			$current      = [];
			$currentChars = 0;
		}

		$current[]     = $item;
		$currentChars += $itemChars;
	}

	if ($current !== []) {
		$chunks[] = $current;
	}

	return $chunks;
}

/**
 * One call to the active provider's API. Returns
 * [assocArrayOfIdToText, ['input'=>,'output'=>]|null].
 */
function lf_call_ai(string $provider, string $apiKey, string $model, string $system, array $items): array {
	$userPayload = (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$maxTokens   = lf_estimate_max_tokens($items);

	[$url, $headers, $body] = lf_build_request($provider, $model, $apiKey, $system, $userPayload, $maxTokens);

	for ($attempt = 1; $attempt <= 2; $attempt++) {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_TIMEOUT        => 120,
		]);
		$response = curl_exec($ch);
		$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			fwrite(STDERR, "  curl error: {$curlErr}" . ($attempt < 2 ? " — retrying...\n" : "\n"));
			sleep(2);
			continue;
		}
		if ($status >= 500 && $attempt < 2) {
			fwrite(STDERR, "  API {$status} — retrying...\n");
			sleep(2);
			continue;
		}
		if ($status !== 200) {
			fwrite(STDERR, "  API error {$status}: " . substr((string) $response, 0, 500) . "\n");
			return [[], null];
		}

		$decoded = json_decode((string) $response, true);
		if (!is_array($decoded)) {
			fwrite(STDERR, "  Could not parse API response as JSON.\n");
			return [[], null];
		}
		[$text, $usage] = lf_extract_response($provider, $decoded);

		// Strip markdown fences defensively even though the prompt forbids them.
		$text = trim($text);
		$text = (string) preg_replace('/^```(?:json)?\s*/', '', $text);
		$text = (string) preg_replace('/\s*```$/', '', $text);

		$parsed = json_decode($text, true);
		if (!is_array($parsed)) {
			fwrite(STDERR, "  Could not parse model response as JSON: " . substr($text, 0, 300) . "\n");
			return [[], $usage];
		}
		return [$parsed, $usage];
	}
	return [[], null];
}

// ---------------------------------------------------------------------
// Build the work list: every empty single/plural slot, per locale.
// ---------------------------------------------------------------------
$poFiles = glob($langDir . '/lingua-forge-*.po');
sort($poFiles);
if ($poFiles === [] || $poFiles === false) {
	fwrite(STDERR, "No languages/lingua-forge-*.po files found.\n");
	exit(1);
}

$work = []; // locale => ['path'=>, 'lines'=>, 'items'=>[ [id, prompt-payload, apply-info] ]]

foreach ($poFiles as $poPath) {
	$locale = preg_replace('/^lingua-forge-|\.po$/', '', basename($poPath));
	if ($onlyLocale !== null && $locale !== $onlyLocale) {
		continue;
	}
	if (!isset(LOCALE_NAMES[$locale])) {
		fwrite(STDERR, "Skipping unknown locale (no name mapping): {$locale}\n");
		continue;
	}

	$poText   = (string) file_get_contents($poPath);
	$nplurals = lf_parse_nplurals($poText) ?? 2;
	$formula  = lf_parse_plural_formula($poText);
	$lines    = explode("\n", $poText);
	$entries  = lf_parse_entries($lines);

	$items = [];
	foreach ($entries as $entry) {
		if ($entry['multiline_warning']) {
			continue; // never touched — same policy as clear-fuzzy.php
		}

		$isPhpFormat = in_array('php-format', $entry['flags'], true);

		if ($entry['type'] === 'single') {
			// I-2 style (ported from Agnosis, 2026-07-24 sync): an
			// already-translated php-format entry can still be broken — a
			// translator dropped a required %d/%s — and that's just as much
			// "needs this tool's attention" as an empty msgstr, even though
			// it isn't empty. Re-include it, but carry the existing (broken)
			// translation + exactly which placeholder(s) are missing, so the
			// model FIXES it in place instead of discarding a translation
			// that's otherwise fine.
			$expected = $isPhpFormat ? lf_extract_placeholders($entry['msgid']) : [];
			sort($expected);
			$missing = [];
			if ($entry['empty']) {
				// normal from-scratch case, nothing further to compute
			} elseif ($expected !== [] && ($missing = lf_missing_placeholders($expected, $entry['text'])) !== []) {
				// format-mismatch fix case, handled below
			} else {
				continue; // already translated and format-clean (or not a format string)
			}

			$id = 's' . count($items);
			$payload = [
				'type'    => 'single',
				'source'  => $entry['msgid'],
				'comment' => $entry['comment'],
			];
			if (!$entry['empty']) {
				$payload['existing_translation'] = $entry['text'];
				$payload['missing_placeholders'] = $missing;
			}
			$items[] = [
				'id'                    => $id,
				'payload'               => array_filter($payload),
				'apply'                 => ['line' => $entry['line']],
				'expected_placeholders' => $expected,
			];
		} else {
			$expected = $isPhpFormat ? lf_extract_placeholders($entry['msgid']) : [];
			sort($expected);

			foreach ($entry['slots'] as $idx => $slot) {
				$missing = [];
				if ($slot['empty']) {
					// normal from-scratch case
				} elseif ($expected !== [] && ($missing = lf_missing_placeholders($expected, $slot['text'])) !== []) {
					// format-mismatch fix case
				} else {
					continue;
				}

				$siblings = [];
				foreach ($entry['slots'] as $sIdx => $sSlot) {
					if ($sIdx !== $idx && !$sSlot['empty']) {
						$siblings[(string) $sIdx] = $sSlot['text'];
					}
				}
				$id = 'p' . count($items);
				$payload = [
					'type'              => 'plural',
					'source_singular'   => $entry['msgid'],
					'source_plural'     => $entry['msgid_plural'],
					'nplurals'          => $nplurals,
					'plural_formula'    => $formula,
					'target_slot_index' => $idx,
					'reference_translations_other_slots' => $siblings ?: null,
					'comment'           => $entry['comment'],
				];
				if (!$slot['empty']) {
					$payload['existing_translation'] = $slot['text'];
					$payload['missing_placeholders'] = $missing;
				}
				$items[] = [
					'id'                    => $id,
					'payload'               => array_filter($payload),
					'apply'                 => ['line' => $slot['line'], 'slot' => $idx],
					'expected_placeholders' => $expected,
				];
			}
		}
	}

	if ($items !== []) {
		$work[$locale] = ['path' => $poPath, 'lines' => $lines, 'items' => $items];
	}
}

$totalItems = array_sum(array_map(fn($w) => count($w['items']), $work));
if ($totalItems === 0) {
	echo "Nothing to translate — every locale is fully filled and format-clean.\n";
	exit(0);
}
$totalFixes = array_sum(array_map(
	fn($w) => count(array_filter($w['items'], fn($it) => isset($it['payload']['existing_translation']))),
	$work
));
$fixNote = $totalFixes > 0 ? " ({$totalFixes} of those are existing translations missing a required placeholder — I-2 style — not truly empty)" : "";
fwrite(STDERR, "{$totalItems} missing/broken string(s){$fixNote} across " . count($work) . " locale(s), via {$providerLabel} ({$model})" . ($dryRun ? " (dry run)" : "") . ".\n");

$logLines = [];
$totalIn  = 0;
$totalOut = 0;
$totalWritten = 0;
$processed = 0;

foreach ($work as $locale => $w) {
	$langName = LOCALE_NAMES[$locale];
	$items    = $w['items'];
	$lines    = $w['lines'];
	$path     = $w['path'];

	$chunks           = lf_chunk_items_by_budget($items, $batchSize, MAX_CHARS_PER_CHUNK);
	$totalChunks      = count($chunks);
	$writtenForLocale = 0;

	foreach ($chunks as $chunkIndex => $chunk) {
		// Stop cleanly BEFORE starting a new batch if the caller's time budget
		// is spent — better than being SIGKILL'd mid-curl_exec() by whatever
		// external wrapper enforces its own process timeout (e.g. a 300s cap),
		// which would waste that batch's API cost with nothing saved for it.
		// Progress is already saved after every completed batch, so re-running
		// the same command picks up exactly where this run stopped.
		if ($timeBudget !== null && (microtime(true) - $scriptStart) >= $timeBudget) {
			fwrite(STDERR, "Time budget of {$timeBudget}s reached — stopping cleanly after {$processed}/{$totalItems} item(s). Progress is saved batch-by-batch; re-run the same command to continue.\n");
			break 2;
		}
		if ($limit !== null && $processed >= $limit) {
			break 2;
		}
		if ($limit !== null) {
			$chunk = array_slice($chunk, 0, max(0, $limit - $processed));
			if ($chunk === []) {
				break 2;
			}
		}

		$system = DOMAIN_PROMPT . "\n\nTarget language for this batch: {$langName} (locale code: {$locale}).";
		$payloadItems = array_map(fn($it) => ['id' => $it['id']] + $it['payload'], $chunk);

		// Batch counter (within this locale) + running total (across every
		// locale) so a locale needing several batches doesn't read as stuck
		// repeating itself — each line is visibly a different, advancing step.
		fwrite(STDERR, "[{$locale}] translating " . count($chunk) . " item(s) via {$providerLabel} (batch " . ($chunkIndex + 1) . "/{$totalChunks} · {$processed}/{$totalItems} overall)...\n");
		[$result, $usage] = lf_call_ai($provider, $apiKey, $model, $system, $payloadItems);
		if ($usage !== null) {
			$totalIn  += $usage['input']  ?? 0;
			$totalOut += $usage['output'] ?? 0;
		}

		$batchWritten = 0;
		foreach ($chunk as $it) {
			$id = $it['id'];
			if (!isset($result[$id]) || !is_string($result[$id]) || $result[$id] === '') {
				fwrite(STDERR, "  [{$locale}] no translation returned for {$id} — skipped.\n");
				continue;
			}
			$translated = $result[$id];

			// Defense in depth (ported from Agnosis, 2026-07-24 sync): a
			// real translated UI string will essentially never itself be
			// valid, structured JSON. Observed in production there: for a
			// single-item batch, a provider's model echoed the item's own
			// request payload back as its "translation" instead of
			// answering — is_string()/non-empty above both pass (it's a
			// long non-empty string), so that garbage would get written
			// straight into the .po file without this check. Reject
			// anything that decodes as a JSON array/object rather than
			// trusting it's prose.
			$looksLikeEchoedJson = is_array(json_decode($translated, true));
			if ($looksLikeEchoedJson) {
				fwrite(STDERR, "  [{$locale}] response for {$id} looks like echoed JSON, not a translation — skipped: " . mb_substr($translated, 0, 120) . "\n");
				continue;
			}

			// Second defense in depth, same incident family (ported from
			// Agnosis): a single-item batch can also come back with
			// MULTIPLE plural forms joined into one answer instead of the
			// one distinct string each "id" was asked for. A real
			// single-slot translation never legitimately needs MORE
			// occurrences of a placeholder than the source itself has, so
			// reject anything that does rather than trust it.
			$expectedPlaceholders = $it['expected_placeholders'] ?? [];
			if ($expectedPlaceholders !== [] && count(lf_extract_placeholders($translated)) > count($expectedPlaceholders)) {
				fwrite(STDERR, "  [{$locale}] response for {$id} has more placeholders than expected — looks like multiple forms joined into one, not a single translation — skipped: " . mb_substr($translated, 0, 120) . "\n");
				continue;
			}

			$apply = $it['apply'];

			if (isset($apply['slot'])) {
				$newLine = "msgstr[{$apply['slot']}] \"" . lf_po_escape($translated) . '"';
				$logLines[] = "[{$locale}] plural slot {$apply['slot']}: " . json_encode($it['payload']['source_singular']) . " -> " . json_encode($translated);
			} else {
				$newLine = 'msgstr "' . lf_po_escape($translated) . '"';
				$logLines[] = "[{$locale}] " . json_encode($it['payload']['source']) . " -> " . json_encode($translated);
			}

			if (!$dryRun) {
				$lines[$apply['line']] = $newLine;
				$totalWritten++;
				$batchWritten++;
			} else {
				echo "  {$newLine}\n";
			}
		}
		$processed += count($chunk);

		// Persist after EVERY batch, not just once the whole locale is done —
		// so Ctrl-C (or a crash) mid-locale keeps whatever that locale already
		// translated instead of discarding a partially-finished locale and
		// re-paying for those same strings on the next run.
		if (!$dryRun && $batchWritten > 0) {
			file_put_contents($path, implode("\n", $lines));
			$writtenForLocale += $batchWritten;
			fwrite(STDERR, "  ↳ saved (batch " . ($chunkIndex + 1) . "/{$totalChunks}, {$writtenForLocale}/" . count($items) . " strings so far for {$locale})\n");
		}
	}

	if (!$dryRun && $writtenForLocale > 0) {
		echo "✓ wrote " . basename($path) . " ({$writtenForLocale} string(s))\n";
	}
}

if ($logLines !== []) {
	file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND);
}

$costNote = ($totalIn + $totalOut > 0)
	? "input_tokens={$totalIn} output_tokens={$totalOut} on {$providerLabel} ({$model}) — see that provider's current per-model pricing for the cost this represents"
	: "no usage data returned";
echo ($dryRun ? "Dry run complete" : "Applied {$totalWritten} translation(s)") . ". {$costNote}.\n";
echo "Log: {$logFile}\n";
