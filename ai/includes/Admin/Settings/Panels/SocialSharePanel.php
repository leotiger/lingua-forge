<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\SocialSharePanel
 *
 * Renders the Social Share section on the SEO tab.
 *
 * Exposes the built-in Social Share feature that extends the WordPress Core
 * Social Icons block with dynamic "share:" URLs and JavaScript-powered actions.
 *
 * When enabled, editors can set any Social Icon's link URL to a "share:" value
 * (e.g. share:facebook) and Lingua Forge rewrites it to the correct share URL
 * at render time — no plugin or shortcode required.
 *
 * Option:
 *   linguaforge_seo_social_share_enabled  bool  Default false (opt-in).
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class SocialSharePanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$enabled     = (bool) get_option( 'linguaforge_seo_social_share_enabled', false );
		$mu_active   = Router::get_instance()->social_share->is_mu_plugin_active();

		?>
		<!-- ── Social Share ──────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge extends the WordPress Core Social Icons block with dynamic share URLs. Set any icon\'s link to a "share:" value and LF rewrites it to the correct share URL or JavaScript action at render time — no custom code or third-party plugin required.',
				'lingua-forge'
			);
			?>
		</p>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the save action.
		if ( isset( $_GET['lf_seo_social_share_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Social Share settings saved.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $mu_active ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					esc_html_e(
						'The lf-social-share mu-plugin is active. Lingua Forge\'s built-in Social Share is inactive — the mu-plugin handles share URL rewriting and the JS actions.',
						'lingua-forge'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="linguaforge_save_seo_social_share">
			<?php wp_nonce_field( 'linguaforge_save_seo_social_share', 'linguaforge_seo_social_share_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Social Share', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_social_share_enabled"
								value="1"
								<?php checked( $enabled ); ?>
								<?php disabled( $mu_active ); ?>
							>
							<?php esc_html_e( 'Enable Social Share URL rewriting for Social Icons blocks', 'lingua-forge' ); ?>
						</label>
						<?php if ( $mu_active ) : ?>
							<p class="description">
								<?php esc_html_e( 'Disabled because the lf-social-share mu-plugin is already providing this feature.', 'lingua-forge' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( ! $mu_active ) : ?>
				<?php submit_button( __( 'Save Social Share Settings', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
			<?php endif; ?>
		</form>

		<!-- ── Usage guide ─────────────────────────────────────── -->
		<hr style="margin-top:2em;">
		<h3><?php esc_html_e( 'How to use', 'lingua-forge' ); ?></h3>
		<p>
			<?php
			esc_html_e(
				'In the block editor, add a Social Icons block. For each icon, open the link settings and enter one of the "share:" values below as the URL. On the frontend, LF replaces it with the correct share URL for the current page.',
				'lingua-forge'
			);
			?>
		</p>

		<h4 style="margin-bottom:8px;"><?php esc_html_e( 'External share services', 'lingua-forge' ); ?></h4>
		<table class="widefat striped" style="max-width:560px;margin-bottom:1.5em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL to enter', 'lingua-forge' ); ?></th>
					<th><?php esc_html_e( 'Action', 'lingua-forge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>share:facebook</code></td><td><?php esc_html_e( 'Share on Facebook', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:x</code></td><td><?php esc_html_e( 'Post on X / Twitter', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:linkedin</code></td><td><?php esc_html_e( 'Share on LinkedIn', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:whatsapp</code></td><td><?php esc_html_e( 'Send via WhatsApp', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:telegram</code></td><td><?php esc_html_e( 'Send via Telegram', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:reddit</code></td><td><?php esc_html_e( 'Submit to Reddit', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:pinterest</code></td><td><?php esc_html_e( 'Pin on Pinterest', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:mastodon</code></td><td><?php esc_html_e( 'Share on Mastodon', 'lingua-forge' ); ?></td></tr>
				<tr><td><code>share:email</code></td><td><?php esc_html_e( 'Share via email (mailto:)', 'lingua-forge' ); ?></td></tr>
			</tbody>
		</table>

		<h4 style="margin-bottom:8px;"><?php esc_html_e( 'JavaScript-powered actions', 'lingua-forge' ); ?></h4>
		<table class="widefat striped" style="max-width:560px;margin-bottom:1.5em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL to enter', 'lingua-forge' ); ?></th>
					<th><?php esc_html_e( 'Action', 'lingua-forge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>share:copy</code></td>
					<td><?php esc_html_e( 'Copy the current URL to the clipboard. Shows a "Link copied" toast on success.', 'lingua-forge' ); ?></td>
				</tr>
				<tr>
					<td><code>share:native</code></td>
					<td><?php esc_html_e( 'Open the browser\'s native Web Share sheet (iOS, Android, some desktops). Falls back to clipboard copy if not supported.', 'lingua-forge' ); ?></td>
				</tr>
				<tr>
					<td><code>share:auto</code></td>
					<td><?php esc_html_e( 'Use native share when available; fall back to clipboard copy. Recommended default for "Share" buttons.', 'lingua-forge' ); ?></td>
				</tr>
			</tbody>
		</table>

		<p class="description">
			<?php
			esc_html_e(
				'The share URL always reflects the current page — no manual URL entry needed. The linguaforge_social_share_url filter lets you override the resolved URL for any service.',
				'lingua-forge'
			);
			?>
		</p>
		<?php
	}

	// =========================================================================
	// Save handler
	// =========================================================================

	public static function handle_save(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'linguaforge_save_seo_social_share', 'linguaforge_seo_social_share_nonce' );

		$enabled = ! empty( $_POST['linguaforge_seo_social_share_enabled'] );
		update_option( 'linguaforge_seo_social_share_enabled', $enabled ? 1 : 0, false );

		wp_safe_redirect( add_query_arg(
			'lf_seo_social_share_saved',
			'1',
			admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}
}
