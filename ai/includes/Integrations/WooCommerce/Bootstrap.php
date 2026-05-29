<?php
/**
 * Class LinguaForge\AI\Integrations\WooCommerce\Bootstrap
 *
 * Entry point for Lingua Forge's WooCommerce integration.
 *
 * Registers all delegation and routing handlers required for the shared-stock
 * model: operational product meta is read from the source-language product at
 * runtime; only content fields (title, description, excerpt, custom attribute
 * values) live on the translated product post.
 *
 * Boot path: ai/ai.php registers add_action('plugins_loaded', Bootstrap::init, 20)
 * so all handlers are in place before WP runs its init hooks.  WooCommerce itself
 * loads at plugins_loaded priority 10, so class_exists('WooCommerce') is reliable
 * at priority 20.
 *
 * @package LinguaForge\AI\Integrations\WooCommerce
 * @since   2.0.0
 */

namespace LinguaForge\AI\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

class Bootstrap {

	/**
	 * Whether this integration successfully initialised in the current request.
	 * Used by external code (e.g. PostListColumn) to confirm the delegation
	 * layer is active before creating translated product posts.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	// =========================================================================
	// Boot
	// =========================================================================

	public static function init(): void {

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		MetaDelegate::init();
		StockRouter::init();
		VariationDelegate::init();
		TaxonomyDelegate::init();
		CatalogQuery::init();
		TermNameFilter::init();
		TermNameAdmin::init();

		self::$active = true;

		/**
		 * Fires after the Lingua Forge WooCommerce integration has fully
		 * initialised for the current request.
		 *
		 * Useful for extensions that need to confirm delegation is active
		 * before registering their own hooks, or for the `linguaforge_cpt_create_allowed`
		 * filter to allow translated product creation.
		 *
		 * @since 2.0.0
		 */
		do_action( 'linguaforge_wc_integration_active' );
	}

	// =========================================================================
	// Status
	// =========================================================================

	/**
	 * Returns true when WooCommerce is installed and all delegation handlers
	 * have been registered for this request.
	 */
	public static function is_active(): bool {
		return self::$active;
	}
}
