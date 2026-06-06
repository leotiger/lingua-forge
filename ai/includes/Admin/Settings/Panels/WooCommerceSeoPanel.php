<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\WooCommerceSeoPanel
 *
 * Renders the WooCommerce SEO section on the SEO tab.
 *
 * Only displayed when WooCommerce is active — SeoTab guards the tab render
 * with class_exists('WooCommerce').
 *
 * Settings:
 *   linguaforge_seo_wc_og_enabled  bool  Default true.
 *     Enables og:type=product, og:price:amount, og:price:currency,
 *     og:availability, and their product: namespace equivalents on
 *     WooCommerce product pages.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

class WooCommerceSeoPanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$enabled = (bool) get_option( 'linguaforge_seo_wc_og_enabled', true );

		?>
		<!-- ── WooCommerce SEO ───────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge adds WooCommerce-specific Open Graph properties to product pages so that sharing a product URL on Facebook, Pinterest, and similar platforms surfaces the correct product type, price, and availability.',
				'lingua-forge'
			);
			?>
		</p>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the save action.
		if ( isset( $_GET['lf_seo_wc_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'WooCommerce SEO settings saved.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="linguaforge_save_seo_wc">
			<?php wp_nonce_field( 'linguaforge_save_seo_wc', 'linguaforge_seo_wc_nonce' ); ?>

			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Product Open Graph', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_wc_og_enabled"
								value="1"
								<?php checked( $enabled ); ?>
							>
							<?php esc_html_e( 'Add WooCommerce product tags to Open Graph output', 'lingua-forge' ); ?>
						</label>
						<p class="description">
							<?php
							esc_html_e(
								'When enabled, Lingua Forge outputs the following additional meta tags on product pages: og:type=product, og:price:amount, og:price:currency, og:availability, and their product: namespace equivalents used by Facebook Catalog.',
								'lingua-forge'
							);
							?>
						</p>
					</td>
				</tr>

				<?php if ( $enabled ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tags emitted', 'lingua-forge' ); ?></th>
					<td>
						<table class="widefat striped" style="max-width:480px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tag', 'lingua-forge' ); ?></th>
									<th><?php esc_html_e( 'Value source', 'lingua-forge' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>og:type</code></td>
									<td><?php esc_html_e( '"product" (replaces "article")', 'lingua-forge' ); ?></td>
								</tr>
								<tr>
									<td><code>og:price:amount</code><br><code>product:price:amount</code></td>
									<td><?php esc_html_e( 'WooCommerce product price', 'lingua-forge' ); ?></td>
								</tr>
								<tr>
									<td><code>og:price:currency</code><br><code>product:price:currency</code></td>
									<td><?php esc_html_e( 'WooCommerce store currency', 'lingua-forge' ); ?></td>
								</tr>
								<tr>
									<td><code>og:availability</code><br><code>product:availability</code></td>
									<td>
										<?php esc_html_e( 'instock / oos / pending from WC stock status', 'lingua-forge' ); ?>
									</td>
								</tr>
							</tbody>
						</table>
						<p class="description" style="margin-top:8px;">
							<?php
							esc_html_e(
								'Price and availability always reflect the source-language product (shared stock model). Tags are only output when LF\'s Open Graph module is set to "Full" or "Auto" mode with no conflicting SEO plugin detected.',
								'lingua-forge'
							);
							?>
						</p>
					</td>
				</tr>
				<?php endif; ?>

			</table>

			<?php submit_button( __( 'Save WooCommerce SEO Settings', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	// =========================================================================
	// Save handler
	// =========================================================================

	public static function handle_save(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'linguaforge_save_seo_wc', 'linguaforge_seo_wc_nonce' );

		$enabled = ! empty( $_POST['linguaforge_seo_wc_og_enabled'] );
		update_option( 'linguaforge_seo_wc_og_enabled', $enabled ? 1 : 0, false );

		wp_safe_redirect( add_query_arg(
			'lf_seo_wc_saved',
			'1',
			admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}
}
