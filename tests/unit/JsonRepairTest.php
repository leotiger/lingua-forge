<?php
/**
 * Unit tests for LinguaForge\AI\Core\JsonRepair.
 *
 * Both methods are pure PHP (no WordPress, no I/O) so the test runs in
 * the fast unit suite without wp-env / Docker.
 *
 * Covers:
 *   • repair_unescaped_quotes() — the byte-level fixup for unescaped
 *     direct-speech quotes inside JSON string values (the audit §2.4
 *     helper that bit production at 1.3.6).
 *   • normalise_json_response() — the orchestrator that strips fences,
 *     extracts the first { … } block, and only invokes the repair as a
 *     fallback when json_decode() fails.
 *
 * Extracted from tests/unit/TranslationRepairQuotesTest.php in v1.3.7
 * (audit §2.1 / §5 item 6) — the original test invoked the method via
 * ReflectionMethod because it was `private static`. After the split,
 * the method is `public static` in JsonRepair, so the ReflectionMethod
 * dance is gone.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use LinguaForge\AI\Core\JsonRepair;

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/JsonRepair.php';

final class JsonRepairTest extends TestCase {

    // ── repair_unescaped_quotes ──────────────────────────────────────────────

    public function test_well_formed_json_is_returned_unchanged(): void {

        $json = '{"title":"Hello world","body":"Some text"}';

        $this->assertSame(
            $json,
            JsonRepair::repair_unescaped_quotes( $json ),
            'Properly-quoted JSON must pass through untouched.'
        );
    }

    public function test_already_escaped_quote_is_preserved(): void {

        // \" inside a JSON string is already valid — the byte sequence
        // \\\" in PHP source = \" in the actual string.
        $json = '{"q":"He said \"hi\""}';

        $this->assertSame(
            $json,
            JsonRepair::repair_unescaped_quotes( $json ),
            'Existing backslash-escaped quotes must not be re-escaped.'
        );
    }

    public function test_unescaped_inner_quote_is_escaped(): void {

        // Raw bytes:  {"q":"He said "hi" to me"}
        // After repair the inner quotes should become \" so json_decode
        // succeeds.
        $broken = '{"q":"He said "hi" to me"}';

        $repaired = JsonRepair::repair_unescaped_quotes( $broken );

        $this->assertJson(
            $repaired,
            'Repaired JSON must be parseable. Got: ' . $repaired
        );

        $decoded = json_decode( $repaired, true );
        $this->assertSame( 'He said "hi" to me', $decoded['q'] );
    }

    public function test_repaired_output_round_trips_through_json_decode(): void {

        // Direct-speech in HTML — the common AI failure mode the helper
        // was built for.
        $broken = '{"html":"<p>She replied, "Yes."</p>"}';

        $repaired = JsonRepair::repair_unescaped_quotes( $broken );
        $decoded  = json_decode( $repaired, true );

        $this->assertIsArray( $decoded, 'json_decode must succeed on repaired output.' );
        $this->assertSame( '<p>She replied, "Yes."</p>', $decoded['html'] );
    }

    public function test_quote_followed_by_structural_token_is_treated_as_terminator(): void {

        // A quote followed by `,` or `}` or `]` (after optional whitespace)
        // closes the string — that's the heuristic. Whitespace between the
        // quote and the comma must not confuse it.
        $json = "{\"a\":\"x\" , \"b\":\"y\"}";

        $repaired = JsonRepair::repair_unescaped_quotes( $json );
        $decoded  = json_decode( $repaired, true );

        $this->assertSame( [ 'a' => 'x', 'b' => 'y' ], $decoded );
    }

    public function test_empty_string_is_handled(): void {

        $this->assertSame( '', JsonRepair::repair_unescaped_quotes( '' ) );
    }

    // ── normalise_json_response ──────────────────────────────────────────────

    public function test_normalise_passes_through_clean_json(): void {

        $json   = '{"ok":true}';
        $result = JsonRepair::normalise_json_response( $json );

        $this->assertSame( $json, $result );
    }

    public function test_normalise_strips_json_code_fence(): void {

        $fenced   = "```json\n{\"ok\":true}\n```";
        $result   = JsonRepair::normalise_json_response( $fenced );

        $this->assertSame( '{"ok":true}', $result );
    }

    public function test_normalise_strips_bare_code_fence(): void {

        $fenced = "```\n{\"ok\":true}\n```";
        $result = JsonRepair::normalise_json_response( $fenced );

        $this->assertSame( '{"ok":true}', $result );
    }

    public function test_normalise_extracts_first_object_when_preceded_by_prose(): void {

        $raw    = "Here is your response:\n{\"ok\":true}";
        $result = JsonRepair::normalise_json_response( $raw );

        $this->assertSame( '{"ok":true}', $result );
    }

    public function test_normalise_invokes_repair_only_on_decode_failure(): void {

        // Broken JSON — inner quote not escaped.
        $broken = '{"q":"He said "no""}';
        $result = JsonRepair::normalise_json_response( $broken );

        // The output must be a parseable JSON string after repair.
        $this->assertJson( $result );
        $decoded = json_decode( $result, true );
        $this->assertSame( 'He said "no"', $decoded['q'] );
    }

    public function test_normalise_trims_surrounding_whitespace(): void {

        $result = JsonRepair::normalise_json_response( "   {\"ok\":true}\n\n" );

        $this->assertSame( '{"ok":true}', $result );
    }

    public function test_normalise_extracts_object_when_prose_precedes_code_fence(): void {

        // Common AI pattern for some languages: introductory sentence, then a
        // ```json block, then optionally a trailing remark.
        $raw = "Hier ist die Übersetzung:\n\n```json\n{\"title\":\"Titel\",\"content\":\"Inhalt\"}\n```";

        $result = JsonRepair::normalise_json_response( $raw );

        $this->assertJson( $result, 'Extracted content must be parseable JSON.' );
        $decoded = json_decode( $result, true );
        $this->assertSame( 'Titel',  $decoded['title'] );
        $this->assertSame( 'Inhalt', $decoded['content'] );
    }

    public function test_normalise_stops_at_matching_brace_not_last_brace(): void {

        // Greedy regex would have matched all the way to the } inside {formell},
        // producing invalid JSON.  The balanced extractor must stop at the JSON
        // closing brace and discard the trailing note.
        $raw = "Here is your translation:\n{\"title\":\"T\",\"content\":\"C\"}\n\nNote: used {formal} register.";

        $result = JsonRepair::normalise_json_response( $raw );

        $this->assertJson( $result, 'Result must be parseable JSON — trailing {} must not be included.' );
        $decoded = json_decode( $result, true );
        $this->assertSame( 'T', $decoded['title'] );
        $this->assertSame( 'C', $decoded['content'] );
    }

    public function test_normalise_handles_braces_inside_string_values(): void {

        // JSON object whose content value itself contains {} — the balanced
        // scanner must not close at the inner braces.
        $json = "{\"content\":\"CSS: .foo { color: red; }\"}";

        $result = JsonRepair::normalise_json_response( $json );

        $this->assertSame( $json, $result, 'Clean JSON with inner {} must pass through unchanged.' );
    }

    public function test_repair_german_ascii_closing_quote_before_comma(): void {

        // Real-world German AI response pattern: AI uses typographic „ (U+201E)
        // as opening quote but falls back to ASCII " (U+0022) as closing quote.
        // The closing " is immediately followed by a German sentence comma,
        // so the old single-level peek-ahead incorrectly treated it as a JSON
        // string terminator rather than a content quote to be escaped.
        //
        // Input bytes around the problem: …hinzufügen", indem…
        // The " after hinzufügen is 0x22 followed by 0x2C (',').
        $broken = '{"title":"T","content":"Updates \xc3\xbcber \xe2\x80\x9ePlugin hinzuf\xc3\xbcgen", indem weiter."}';
        // Use actual UTF-8 bytes:
        $broken = '{"title":"T","content":"Updates über „Plugin hinzufügen", indem weiter."}';

        $result = JsonRepair::normalise_json_response( $broken );

        $this->assertJson( $result, 'Repaired JSON must be parseable. Got: ' . $result );
        $decoded = json_decode( $result, true );
        $this->assertStringContainsString( 'hinzufügen', $decoded['content'] );
        $this->assertStringContainsString( 'indem weiter', $decoded['content'] );
    }
}
