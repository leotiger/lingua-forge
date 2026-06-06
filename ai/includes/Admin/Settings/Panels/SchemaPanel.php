<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\SchemaPanel
 *
 * Renders the Schema.org section on the SEO tab.
 *
 * Options:
 *   linguaforge_seo_schema_enabled  bool  Master switch (default true).
 *   linguaforge_seo_schema_article  bool  Article / WebPage output (default true).
 *   linguaforge_seo_schema_website  bool  WebSite on front page (default true).
 *   linguaforge_seo_schema_product  bool  Product on WC product pages (default true).
 *
 * When a major SEO plugin (Yoast, Rank Math, etc.) is active the Schema module
 * skips all output to avoid conflicting JSON-LD graphs.  The panel surfaces this
 * detection and explains the deference strategy.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class SchemaPanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$enabled         = (bool) get_option( 'linguaforge_seo_schema_enabled',  true );
		$article_enabled = (bool) get_option( 'linguaforge_seo_schema_article',  true );
		$website_enabled = (bool) get_option( 'linguaforge_seo_schema_website',  true );
		$product_enabled = (bool) get_option( 'linguaforge_seo_schema_product',  true );
		$wc_active       = class_exists( 'WooCommerce' );

		$schema_manager    = Router::get_instance()->schema_manager;
		$plugin_active     = $schema_manager->is_schema_plugin_active();
		$detected_plugins  = $schema_manager->detected_schema_plugins();

		?>
		<!-- ── Schema.org ───────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge outputs Schema.org JSON-LD structured data so search engines understand your content type, language, and metadata. This is the primary way to communicate inLanguage annotations for multilingual content to Google and other crawlers.',
				'lingua-forge'
			);
			?>
		</p>

		<?php if ( $plugin_active ) : ?>
		<div class="notice notice-warning inline" style="margin-bottom:1.5em;">
			<p>
				<strong><?php esc_html_e( 'Schema output deferred.', 'lingua-forge' ); ?></strong>
				<?php
				echo ' ' . esc_html( sprintf(
					/* translators: %s: comma-separated plugin names */
					__( '%s is active and already outputs Schema.org JSON-LD. Lingua Forge has disabled its own schema output to prevent conflicting graphs. Unlike Open Graph — where LF adds og:locale on top of another plugin\'s output — schema cannot be cleanly supplemented: duplicate or overlapping JSON-LD graphs produce validation errors. Disable schema output in the detected plugin if you want LF to handle structured data.', 'lingua-forge' ),
					implode( ', ', $detected_plugins )
				) );
				?>
			</p>
		</div>
		<?php endif; ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the save action.
		if ( isset( $_GET['lf_seo_schema_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Schema.org settings saved.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="linguaforge_save_seo_schema">
			<?php wp_nonce_field( 'linguaforge_save_seo_schema', 'linguaforge_seo_schema_nonce' ); ?>

			<table class="form-table" role="presentation">

				<!-- Master switch -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Schema.org output', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_schema_enabled"
								value="1"
								<?php checked( $enabled ); ?>
								<?php disabled( $plugin_active ); ?>
							>
							<?php esc_html_e( 'Output Schema.org JSON-LD in wp_head', 'lingua-forge' ); ?>
						</label>
						<?php if ( $plugin_active ) : ?>
							<p class="description">
								<?php esc_html_e( 'Locked — a conflicting SEO plugin is active. See the notice above.', 'lingua-forge' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Article / WebPage -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Article / WebPage', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_schema_article"
								value="1"
								<?php checked( $article_enabled ); ?>
							>
							<?php esc_html_e( 'Output Article or WebPage schema on singular posts and pages', 'lingua-forge' ); ?>
						</label>
						<p class="description">
							<?php
							esc_html_e(
								'Blog posts use Article; all other post types and pages use WebPage. Includes headline, description, inLanguage, url, datePublished, dateModified, image, and publisher.',
								'lingua-forge'
							);
							?>
						</p>
					</td>
				</tr>

				<!-- WebSite -->
				<tr>
					<th scope="row"><?php esc_html_e( 'WebSite', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_schema_website"
								value="1"
								<?php checked( $website_enabled ); ?>
							>
							<?php esc_html_e( 'Output WebSite schema on the front page and blog index', 'lingua-forge' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Includes site name, url, and inLanguage.', 'lingua-forge' ); ?>
						</p>
					</td>
				</tr>

				<?php if ( $wc_active ) : ?>
				<!-- Product (WooCommerce) -->
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Product', 'lingua-forge' ); ?>
						<span style="display:block;font-weight:400;color:#646970;font-size:12px;">WooCommerce</span>
					</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_schema_product"
								value="1"
								<?php checked( $product_enabled ); ?>
							>
							<?php esc_html_e( 'Output Product schema on WooCommerce product pages', 'lingua-forge' ); ?>
						</label>
						<p class="description">
							<?php
							esc_html_e(
								'Includes name, description, inLanguage, url, image, and an Offer with price, priceCurrency, and availability. Price and availability come from the source-language product (shared stock model).',
								'lingua-forge'
							);
							?>
						</p>
					</td>
				</tr>
				<?php endif; ?>

				<?php if ( $enabled && ! $plugin_active ) : ?>
				<!-- Current output status -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Currently emitting', 'lingua-forge' ); ?></th>
					<td>
						<ul style="margin:.4em 0 0 1.2em;list-style:disc;">
							<?php if ( $article_enabled ) : ?>
								<li><?php esc_html_e( 'Article / WebPage — all singular posts and pages', 'lingua-forge' ); ?></li>
							<?php endif; ?>
							<?php if ( $website_enabled ) : ?>
								<li><?php esc_html_e( 'WebSite — front page / blog index', 'lingua-forge' ); ?></li>
							<?php endif; ?>
							<?php if ( $wc_active && $product_enabled ) : ?>
								<li><?php esc_html_e( 'Product — WooCommerce product pages', 'lingua-forge' ); ?></li>
							<?php endif; ?>
							<?php if ( ! $article_enabled && ! $website_enabled && ( ! $wc_active || ! $product_enabled ) ) : ?>
								<li style="color:#d63638;"><?php esc_html_e( 'Nothing — all types disabled', 'lingua-forge' ); ?></li>
							<?php endif; ?>
						</ul>
					</td>
				</tr>
				<?php endif; ?>

			</table>

			<?php submit_button( __( 'Save Schema.org Settings', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
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

		check_admin_referer( 'linguaforge_save_seo_schema', 'linguaforge_seo_schema_nonce' );

		update_option( 'linguaforge_seo_schema_enabled', ! empty( $_POST['linguaforge_seo_schema_enabled'] ) ? 1 : 0, false );
		update_option( 'linguaforge_seo_schema_article', ! empty( $_POST['linguaforge_seo_schema_article'] ) ? 1 : 0, false );
		update_option( 'linguaforge_seo_schema_website', ! empty( $_POST['linguaforge_seo_schema_website'] ) ? 1 : 0, false );
		update_option( 'linguaforge_seo_schema_product', ! empty( $_POST['linguaforge_seo_schema_product'] ) ? 1 : 0, false );

		wp_safe_redirect( add_query_arg(
			'lf_seo_schema_saved',
			'1',
			admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}
}
