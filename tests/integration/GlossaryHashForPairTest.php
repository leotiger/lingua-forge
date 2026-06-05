<?php
/**
 * Integration test for LinguaForge\AI\Core\Glossary::hash_for_pair.
 *
 * Lives in the integration suite because hash_for_pair() calls
 * get_for_pair(), which queries $wpdb. The hashing logic itself is pure,
 * but it can only be exercised end-to-end with a working glossary table.
 *
 * Properties under test:
 *   1. Returns the sentinel 'none' when no entries match.
 *   2. Deterministic — two calls with the same data return the same hash.
 *   3. Sensitive — adding or changing an entry changes the hash. This is
 *      the property the Translation Memory cache key relies on; if it
 *      breaks, glossary edits stop invalidating cached translations.
 *
 * @package LinguaForge\Tests
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration;

use WP_UnitTestCase;
use LinguaForge\AI\Core\Glossary;

final class GlossaryHashForPairTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        Glossary::clear_all();
    }

    public function tear_down(): void {
        Glossary::clear_all();
        parent::tear_down();
    }

    public function test_hash_for_empty_pair_returns_none_sentinel(): void {

        $this->assertSame(
            'none',
            Glossary::hash_for_pair( 'en', 'ca' ),
            'hash_for_pair must return the literal string "none" when no entries apply — TM cache-key composition depends on this sentinel.'
        );
    }

    public function test_hash_for_pair_is_deterministic(): void {

        Glossary::insert( 'Cat',  'Gat',   'en', 'ca' );
        Glossary::insert( 'Dog',  'Gos',   'en', 'ca' );

        $first  = Glossary::hash_for_pair( 'en', 'ca' );
        $second = Glossary::hash_for_pair( 'en', 'ca' );

        $this->assertSame( $first, $second );
        $this->assertNotSame(
            'none',
            $first,
            'Hash must not be "none" when entries exist for the pair.'
        );
    }

    public function test_hash_changes_when_entry_added(): void {

        Glossary::insert( 'Cat', 'Gat', 'en', 'ca' );
        $before = Glossary::hash_for_pair( 'en', 'ca' );

        Glossary::insert( 'Dog', 'Gos', 'en', 'ca' );
        $after = Glossary::hash_for_pair( 'en', 'ca' );

        $this->assertNotSame(
            $before,
            $after,
            'Adding a glossary entry must change the pair hash — without this, glossary edits never invalidate cached translations.'
        );
    }

    public function test_hash_distinct_for_distinct_pairs(): void {

        Glossary::insert( 'Cat', 'Gat',   'en', 'ca' );
        Glossary::insert( 'Cat', 'Katze', 'en', 'de' );

        $this->assertNotSame(
            Glossary::hash_for_pair( 'en', 'ca' ),
            Glossary::hash_for_pair( 'en', 'de' )
        );
    }

    public function test_hash_is_16_hex_chars_when_entries_exist(): void {

        Glossary::insert( 'Cat', 'Gat', 'en', 'ca' );

        $hash = Glossary::hash_for_pair( 'en', 'ca' );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{16}$/',
            $hash,
            'Hash must be the first 16 hex chars of a SHA-256 digest.'
        );
    }

    // =========================================================================
    // Write paths — §6.0.1 Medium (Glossary.php, 61%)
    // =========================================================================

    /**
     * insert() must persist a row and return a positive integer ID.
     * The row must be retrievable via get_for_pair() with correct field values.
     */
    public function test_insert_persists_row_and_returns_id(): void {
        $id = Glossary::insert( 'Solar panel', 'Panneau solaire', 'en', 'fr', 'Energy sector term' );

        $this->assertGreaterThan( 0, $id, 'insert() must return a positive integer ID on success.' );

        $rows = Glossary::get_for_pair( 'en', 'fr' );
        $this->assertCount( 1, $rows, 'get_for_pair() must return exactly one matching row.' );

        $row = $rows[0];
        $this->assertSame( $id,              $row['id'] );
        $this->assertSame( 'Solar panel',    $row['source_term'] );
        $this->assertSame( 'Panneau solaire', $row['target_term'] );
        $this->assertSame( 'en',             $row['source_lang'] );
        $this->assertSame( 'fr',             $row['target_lang'] );
        $this->assertSame( 'Energy sector term', $row['notes'] );
    }

    /**
     * insert() must reject a row where source_term is empty and return 0.
     * target_term validation is symmetric.
     */
    public function test_insert_returns_zero_for_empty_source_term(): void {
        $id = Glossary::insert( '', 'Panneau solaire', 'en', 'fr' );

        $this->assertSame( 0, $id, 'insert() must return 0 when source_term is empty.' );
        $this->assertSame( [], Glossary::get_for_pair( 'en', 'fr' ), 'No row must be persisted for empty source_term.' );
    }

    /**
     * delete() must remove the row by ID and return true.
     * A subsequent get_for_pair() must return an empty result set.
     */
    public function test_delete_removes_entry_and_returns_true(): void {
        $id = Glossary::insert( 'Wind turbine', 'Turbine éolienne', 'en', 'fr' );
        $this->assertGreaterThan( 0, $id, 'Pre-condition: insert must succeed.' );

        $deleted = Glossary::delete( $id );

        $this->assertTrue( $deleted, 'delete() must return true on success.' );
        $this->assertSame( [], Glossary::get_for_pair( 'en', 'fr' ), 'Entry must be gone after delete().' );
    }

    /**
     * format_for_prompt() must include inserted entries as a formatted prompt
     * section, and the "do not translate" branch must produce a preserve directive.
     */
    public function test_format_for_prompt_includes_entries(): void {
        // Standard substitution entry.
        Glossary::insert( 'kWp', 'kWc', 'en', 'fr' );
        // "Do not translate" entry: source_term === target_term.
        Glossary::insert( 'Cal Talaia', 'Cal Talaia', '', 'fr' );

        $output = Glossary::format_for_prompt( 'en', 'fr' );

        $this->assertNotEmpty( $output, 'format_for_prompt() must return a non-empty string when entries exist.' );
        $this->assertStringContainsString( 'kWp', $output, 'Substitution entry source term must appear in prompt.' );
        $this->assertStringContainsString( 'kWc', $output, 'Substitution entry target term must appear in prompt.' );
        $this->assertStringContainsString( 'Cal Talaia', $output, '"Do not translate" entry must appear in prompt.' );
        $this->assertStringContainsString( 'verbatim', $output, '"Do not translate" directive must use "verbatim" language.' );
    }
}
