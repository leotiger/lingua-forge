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
}
