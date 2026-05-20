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
}
