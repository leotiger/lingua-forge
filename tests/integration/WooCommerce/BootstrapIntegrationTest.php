<?php
/**
 * Integration tests — confirms WooCommerce Bootstrap wires all delegation
 * filters and actions when WooCommerce is active.
 *
 * These tests verify the boot path rather than delegation behaviour.
 * They run first in the suite (alphabetically) so any filter-registration
 * failure surfaces before the delegation tests begin.
 *
 * Run via: composer test:integration:wc  (requires wp-env running).
 *
 * @package LinguaForge\Tests\Integration\WooCommerce
 */

declare(strict_types=1);

namespace LinguaForge\Tests\Integration\WooCommerce;

use LinguaForge\AI\Integrations\WooCommerce\Bootstrap;
use LinguaForge\AI\Integrations\WooCommerce\CatalogQuery;
use LinguaForge\AI\Integrations\WooCommerce\MetaDelegate;
use LinguaForge\AI\Integrations\WooCommerce\StockRouter;
use LinguaForge\AI\Integrations\WooCommerce\TaxonomyDelegate;
use LinguaForge\AI\Integrations\WooCommerce\TermNameFilter;
use LinguaForge\AI\Integrations\WooCommerce\VariationDelegate;

final class BootstrapIntegrationTest extends WcIntegrationTestCase {

	// =========================================================================
	// Bootstrap state
	// =========================================================================

	public function test_bootstrap_is_active_when_woocommerce_is_installed(): void {
		$this->assertTrue(
			Bootstrap::is_active(),
			'Bootstrap::is_active() must be true when WooCommerce is installed and plugins_loaded has fired.'
		);
	}

	public function test_woocommerce_class_exists(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce class must exist in the test environment.' );
	}

	// =========================================================================
	// Filter / action registration
	// =========================================================================

	public function test_meta_delegate_filter_is_registered(): void {
		$priority = has_filter( 'get_post_metadata', [ MetaDelegate::class, 'maybe_delegate' ] );
		$this->assertSame( 1, $priority, 'MetaDelegate must be registered on get_post_metadata at priority 1.' );
	}

	public function test_stock_router_update_filter_is_registered(): void {
		$priority = has_filter( 'update_post_metadata', [ StockRouter::class, 'route_stock_write' ] );
		$this->assertSame( 1, $priority, 'StockRouter must be registered on update_post_metadata at priority 1.' );
	}

	public function test_stock_router_add_filter_is_registered(): void {
		$priority = has_filter( 'add_post_metadata', [ StockRouter::class, 'route_stock_add' ] );
		$this->assertSame( 1, $priority, 'StockRouter must be registered on add_post_metadata at priority 1.' );
	}

	public function test_taxonomy_delegate_filter_is_registered(): void {
		$priority = has_filter( 'wp_get_object_terms', [ TaxonomyDelegate::class, 'maybe_delegate_terms' ] );
		$this->assertSame( 10, $priority, 'TaxonomyDelegate must be registered on wp_get_object_terms at priority 10.' );
	}

	public function test_variation_delegate_action_is_registered(): void {
		$priority = has_action( 'pre_get_posts', [ VariationDelegate::class, 'maybe_delegate_variation_query' ] );
		$this->assertSame( 5, $priority, 'VariationDelegate must be registered on pre_get_posts at priority 5.' );
	}

	public function test_catalog_query_action_is_registered(): void {
		$priority = has_action( 'woocommerce_product_query', [ CatalogQuery::class, 'apply_language_filter' ] );
		$this->assertGreaterThan( 0, $priority, 'CatalogQuery must be registered on woocommerce_product_query.' );
	}

	public function test_term_name_filter_is_registered(): void {
		$priority = has_filter( 'term_name', [ TermNameFilter::class, 'translate_term_name' ] );
		$this->assertSame( 10, $priority, 'TermNameFilter must be registered on term_name at priority 10.' );
	}

	// =========================================================================
	// linguaforge_wc_integration_active action fired
	// =========================================================================

	public function test_wc_integration_active_action_was_fired(): void {
		// If the action has already fired (on plugins_loaded), did_action() > 0.
		$this->assertGreaterThan(
			0,
			did_action( 'linguaforge_wc_integration_active' ),
			'linguaforge_wc_integration_active must have fired during boot.'
		);
	}
}
