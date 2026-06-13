<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\HreflangPanel
 *
 * Renders the Hreflang section on the SEO tab.
 *
 * Surfaces the existing hreflang behaviour (always-on since v1.0) with a
 * proper settings UI for the first time:
 *   • Enable/disable toggle (linguaforge_seo_hreflang_enabled, default true)
 *   • Read-only notice listing which SEO plugins' hreflang output is being
 *     suppressed by LF (Yoast, Rank Math, AIOSEO, SEOPress)
 *
 * Disabling hreflang also stops LF from removing WP's core canonical tag
 * and from suppressing SEO-plugin hreflang output — i.e. the Hreflang class
 * registers zero hooks when the option is false.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

class HreflangPanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$enabled = (bool) get_option( 'linguaforge_seo_hreflang_enabled', true );

		// Detect which SEO plugins' hreflang LF suppresses.
		$suppressed = [];
		if ( defined( 'WPSEO_VERSION' ) )    $suppressed[] = 'Yoast SEO';
		if ( defined( 'RANK_MATH_VERSION' ) ) $suppressed[] = 'Rank Math';
		if ( defined( 'AIOSEO_VERSION' ) )    $suppressed[] = 'All in One SEO';
		if ( defined( 'SEOPRESS_VERSION' ) )  $suppressed[] = 'SEOPress';

		?>
		<!-- ── Hreflang ──────────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge outputs <link rel="alternate" hreflang> tags for every configured language on every page, including x-default pointing to the source language. This is the primary multilingual SEO signal for Google and other search engines — no additional SEO plugin is required.',
				'lingua-forge'
			);
			?>
		</p>
		<p>
			<?php
			esc_html_e(
				'When Yoast SEO, Rank Math, All in One SEO, or SEOPress is active, Lingua Forge automatically suppresses their hreflang output via filter. General SEO plugins produce hreflang based on a single-language URL structure and cannot account for your multilingual routing configuration; LF\'s output is always correct.',
				'lingua-forge'
			);
			?>
		</p>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the save action.
		if ( isset( $_GET['lf_seo_hreflang_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Hreflang settings saved.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="linguaforge_save_seo_hreflang">
			<?php wp_nonce_field( 'linguaforge_save_seo_hreflang', 'linguaforge_seo_hreflang_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Hreflang output', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_hreflang_enabled"
								value="1"
								<?php checked( $enabled ); ?>
							>
							<?php esc_html_e( 'Output hreflang tags in wp_head', 'lingua-forge' ); ?>
						</label>
						<p class="description">
							<?php
							esc_html_e(
								'Disable only for headless sites or fully custom hreflang implementations. When disabled, LF no longer removes the WordPress core canonical tag and no longer suppresses SEO-plugin hreflang output.',
								'lingua-forge'
							);
							?>
						</p>
					</td>
				</tr>

				<?php if ( $enabled ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Current status', 'lingua-forge' ); ?></th>
					<td>
						<p style="color:#00a32a;font-weight:600;">
							<?php esc_html_e( '✓ Hreflang active', 'lingua-forge' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'LF is outputting hreflang tags and is replacing the WordPress core canonical with a self-referencing canonical for each language version.', 'lingua-forge' ); ?>
						</p>

						<?php if ( ! empty( $suppressed ) ) : ?>
						<p class="description" style="margin-top:8px;">
							<?php
							echo esc_html( sprintf(
								/* translators: %s: comma-separated list of SEO plugin names */
								__( 'LF is suppressing hreflang output from: %s', 'lingua-forge' ),
								implode( ', ', $suppressed )
							) );
							?>
						</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<?php submit_button( __( 'Save Hreflang Settings', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
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

		check_admin_referer( 'linguaforge_save_seo_hreflang', 'linguaforge_seo_hreflang_nonce' );

		$enabled = ! empty( $_POST['linguaforge_seo_hreflang_enabled'] );
		update_option( 'linguaforge_seo_hreflang_enabled', $enabled ? 1 : 0, false );

		wp_safe_redirect( add_query_arg(
			'lf_seo_hreflang_saved',
			'1',
			admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}
}
