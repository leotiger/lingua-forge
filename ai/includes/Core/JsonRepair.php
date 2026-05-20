<?php

namespace LinguaForge\AI\Core;

defined('ABSPATH') || exit;

/**
 * Repair / normalise JSON envelopes returned by AI providers.
 *
 * Two-stage utility used by every code path that calls an AI worker and
 * expects a JSON response (Translation, MetaDescription, future Features):
 *
 *   1. {@see normalise_json_response()} — trims whitespace, strips
 *      Markdown code fences (`` ```json `` or `` ``` ``), extracts the
 *      first `{ … }` block if the model prepended a sentence, and
 *      triggers the byte-level repair only when a fast json_decode()
 *      attempt fails.
 *
 *   2. {@see repair_unescaped_quotes()} — byte-by-byte fixup for the
 *      common AI failure mode where direct-speech quotes inside a string
 *      value (`"He said "no" to me"`) are emitted without the required
 *      backslash escaping. Uses a peek-ahead heuristic to distinguish
 *      string terminators from content quotes.
 *
 * Both methods are pure PHP — no WordPress runtime dependency — and are
 * therefore unit-testable without spinning up the WP test framework.
 * See tests/unit/JsonRepairTest.php.
 *
 * Extracted from LinguaForge\AI\Features\Translation in v1.3.7 (audit
 * §2.1 / §5 item 6).
 */
class JsonRepair {

    /**
     * Normalise the raw text an AI provider returned into a JSON string
     * that {@see json_decode()} can parse.
     *
     * The flow is deliberately layered so the cheap fast-paths run first:
     *
     *   1. Trim leading / trailing whitespace.
     *   2. If the text starts with a backtick, strip Markdown code fences
     *      — both the opening ```` ```json ```` / ```` ``` ```` and the
     *      closing ```` ``` ````.
     *   3. If the text still doesn't start with `{` or `[`, try to extract
     *      the first `{ … }` block — catches the rare case where the
     *      model prepends a sentence before the JSON object.
     *   4. If json_decode() still returns null, the failure is most
     *      likely a malformed string value rather than a structural
     *      issue, so run {@see repair_unescaped_quotes()} as a
     *      best-effort fixup. Step 4 is skipped on the happy path
     *      (already-valid JSON) so it never adds overhead to the 99%
     *      case.
     *
     * @param  string $text  Raw text returned by the AI provider.
     * @return string        Best-effort JSON string ready for json_decode().
     */
    public static function normalise_json_response(string $text): string {

        $text = trim($text);

        // Step 2 — strip Markdown code fences.
        if (str_starts_with($text, '`')) {
            $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = (string) preg_replace('/\s*```\s*$/', '', $text);
            $text = trim($text);
        }

        // Step 3 — extract first JSON object when preamble precedes it.
        if (!str_starts_with($text, '{') && !str_starts_with($text, '[')) {
            if (preg_match('/(\{[\s\S]*\})/u', $text, $matches)) {
                $text = $matches[1];
            }
        }

        // Step 4 — repair unescaped double-quote characters inside string values.
        // Translated text may contain direct-speech quotes ("he said "no"") or
        // technical terms in quotes that the model emits as bare " without \-escaping,
        // producing structurally invalid JSON that json_decode() cannot recover from.
        // Only run the repair when the fast path already failed, to keep overhead zero
        // for the vast majority of responses that are already valid JSON.
        if (json_decode($text) === null) {
            $text = self::repair_unescaped_quotes($text);
        }

        return $text;
    }

    /**
     * Attempt to fix JSON that contains unescaped double-quote characters inside
     * string values.
     *
     * Scans the JSON byte-by-byte, tracking whether we are inside a string.
     * When a " is encountered inside a string, a peek-ahead decides its role:
     *
     *   - If the next non-whitespace character is a JSON structural token
     *     (: , } ]) the quote closes the string — treat it as a terminator.
     *   - Otherwise the quote is content and is escaped to \".
     *
     * The heuristic works correctly for quoted direct speech embedded in HTML
     * or prose (the common AI failure mode). It can mis-classify a content
     * quote that is immediately followed by , } or ] with no intervening text,
     * but that is extremely rare in practice and the worst outcome is a second
     * json_decode failure that the caller already handles gracefully.
     *
     * @param  string $json  Fence-stripped candidate JSON string.
     * @return string        Best-effort repaired JSON string.
     */
    public static function repair_unescaped_quotes(string $json): string {

        $out       = '';
        $len       = strlen($json);   // scan by byte — JSON structural chars are all ASCII
        $in_string = false;
        $escape    = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];

            if ($escape) {
                $out   .= $c;
                $escape = false;
                continue;
            }

            if ($c === '\\') {
                $out   .= $c;
                $escape = true;
                continue;
            }

            if ($c === '"') {
                if (!$in_string) {
                    // Opening a new JSON string.
                    $in_string = true;
                    $out      .= $c;
                    continue;
                }

                // We are inside a string. Decide: terminator or content quote?
                // Peek at the next non-whitespace byte.
                $j = $i + 1;
                while ($j < $len && ($json[$j] === ' ' || $json[$j] === "\t"
                        || $json[$j] === "\n" || $json[$j] === "\r")) {
                    $j++;
                }
                $next = $j < $len ? $json[$j] : '';

                if ($next === ':' || $next === ',' || $next === '}' || $next === ']' || $next === '') {
                    // Looks like a string terminator.
                    $in_string = false;
                    $out      .= $c;
                } else {
                    // Content quote — escape it.
                    $out .= '\\"';
                }
                continue;
            }

            $out .= $c;
        }

        return $out;
    }
}
