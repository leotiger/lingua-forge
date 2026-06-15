<?php
/**
 * Unit tests for ModelCatalog's pure accessors and curated-list shape
 * (AUDIT §7.1 untested-file row).
 *
 * Covered here:
 *   for_provider() / ids_for_provider() / all() — shape + unknown-provider []
 *   curated-list invariants — every entry has tier/label/note; tier is one of
 *                             light|quality|max
 *   merge_live() — catalog IDs first, live extras appended once, dedup; empty
 *                  live list → just the catalog (the curated fallback)
 *
 * The live-fetch methods (wp_remote_get) are exercised separately in
 * ModelCatalogIntegrationTest.
 *
 * @package LinguaForge\Tests\Unit
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Unit;

use LinguaForge\AI\Core\ModelCatalog;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/ai/includes/Core/ModelCatalog.php';

final class ModelCatalogTest extends TestCase {

	private const TIERS     = [ 'light', 'quality', 'max' ];
	private const PROVIDERS = [ 'anthropic', 'openai', 'gemini' ];

	public function test_all_contains_the_three_providers(): void {
		$all = ModelCatalog::all();
		foreach ( self::PROVIDERS as $provider ) {
			$this->assertArrayHasKey( $provider, $all );
			$this->assertNotEmpty( $all[ $provider ] );
		}
	}

	public function test_every_entry_has_valid_shape(): void {
		foreach ( ModelCatalog::all() as $provider => $models ) {
			foreach ( $models as $id => $entry ) {
				$this->assertIsString( $id, "Model id under {$provider} must be a string." );
				$this->assertNotSame( '', $id );

				foreach ( [ 'tier', 'label', 'note' ] as $key ) {
					$this->assertArrayHasKey( $key, $entry, "{$provider}/{$id} missing '{$key}'." );
					$this->assertIsString( $entry[ $key ] );
					$this->assertNotSame( '', $entry[ $key ], "{$provider}/{$id} '{$key}' must not be empty." );
				}

				$this->assertContains(
					$entry['tier'],
					self::TIERS,
					"{$provider}/{$id} has an invalid tier '{$entry['tier']}'."
				);
			}
		}
	}

	public function test_every_provider_has_at_least_one_light_and_quality_tier(): void {
		foreach ( self::PROVIDERS as $provider ) {
			$tiers = array_column( ModelCatalog::for_provider( $provider ), 'tier' );
			$this->assertContains( 'light', $tiers, "{$provider} must offer a light-tier model." );
			$this->assertContains( 'quality', $tiers, "{$provider} must offer a quality-tier model." );
		}
	}

	public function test_for_provider_unknown_returns_empty(): void {
		$this->assertSame( [], ModelCatalog::for_provider( 'does-not-exist' ) );
	}

	public function test_ids_for_provider_matches_keys(): void {
		$ids = ModelCatalog::ids_for_provider( 'anthropic' );
		$this->assertSame( array_keys( ModelCatalog::for_provider( 'anthropic' ) ), $ids );
		$this->assertContains( 'claude-sonnet-4-6', $ids );
	}

	public function test_merge_live_appends_extras_and_dedupes_against_catalog(): void {
		$catalog = ModelCatalog::ids_for_provider( 'anthropic' );
		$known   = $catalog[0];

		// Live list repeats a catalog model and adds a novel one.
		$merged = ModelCatalog::merge_live( 'anthropic', [ $known, 'claude-future-1' ] );

		// Catalog IDs come first, in order.
		$this->assertSame( $catalog, array_slice( $merged, 0, count( $catalog ) ) );
		// The novel id is appended.
		$this->assertContains( 'claude-future-1', $merged );
		// A live id that is already in the catalog is not duplicated.
		$this->assertSame( 1, count( array_keys( $merged, $known, true ) ) );
	}

	public function test_merge_live_empty_returns_catalog_only(): void {
		// A failed live fetch returns [] → merge falls back to the curated list.
		$this->assertSame(
			ModelCatalog::ids_for_provider( 'openai' ),
			ModelCatalog::merge_live( 'openai', [] )
		);
	}

	public function test_merge_live_unknown_provider_returns_live_ids(): void {
		$this->assertSame(
			[ 'x', 'y' ],
			ModelCatalog::merge_live( 'nope', [ 'x', 'y' ] )
		);
	}
}
