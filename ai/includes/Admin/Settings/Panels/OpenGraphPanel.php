<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\OpenGraphPanel
 *
 * Renders the Open Graph section on the SEO tab.
 *
 * Options:
 *   linguaforge_seo_og_enabled  bool    Master switch (default true).
 *   linguaforge_seo_og_mode     string  'auto'|'locale-only'|'full'|'disabled' (default 'auto').
 *
 * In 'auto' mode SeoManager detects the lf-social-share mu-plugin and major
 * SEO plugins at runtime and emits only og:locale + og:locale:alternate when
 * either is present.  This panel reflects that detection so the admin can
 * see exactly what LF will output.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class OpenGraphPanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$enabled       = (bool)   get_option( 'linguaforge_seo_og_enabled', true );
		$mode          = (string) get_option( 'linguaforge_seo_og_mode', 'auto' );
		$default_image = (string) get_option( 'linguaforge_seo_og_default_image', '' );

		// Detection — delegate to SeoManager helpers via the Router singleton.
		$seo_manager         = Router::get_instance()->seo_manager;
		$social_share_active = $seo_manager->is_social_share_active();
		$seo_plugins_found   = $seo_manager->detected_seo_plugins();
		$effective_mode      = ( 'auto' === $mode ) ? $seo_manager->resolve_og_mode() : $mode;

		?>
		<!-- ── Open Graph ────────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge outputs Open Graph and Twitter Card meta tags so shared URLs display the correct language, title, description, and image on social platforms.',
				'lingua-forge'
			);
			?>
		</p>
		<p>
			<?php
			esc_html_e(
				'Unlike hreflang — where LF always overrides other plugins because they cannot know your multilingual configuration — Open Graph is different: Yoast, Rank Math, and similar plugins already output correct OG tags for your content. Duplicating them adds no value. In auto mode, LF detects active SEO plugins and adds only what they cannot provide: og:locale (current language) and og:locale:alternate (all other configured languages).',
				'lingua-forge'
			);
			?>
		</p>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag set by wp_safe_redirect() after the save action.
		if ( isset( $_GET['lf_seo_og_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Open Graph settings saved.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="linguaforge_save_seo_og">
			<?php wp_nonce_field( 'linguaforge_save_seo_og', 'linguaforge_seo_og_nonce' ); ?>

			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Open Graph output', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_og_enabled"
								value="1"
								<?php checked( $enabled ); ?>
							>
							<?php esc_html_e( 'Output Open Graph tags in wp_head', 'lingua-forge' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Output mode', 'lingua-forge' ); ?></th>
					<td>
						<fieldset>
							<label style="display:block;margin-bottom:6px;">
								<input type="radio" name="linguaforge_seo_og_mode" value="auto" <?php checked( $mode, 'auto' ); ?>>
								<strong><?php esc_html_e( 'Auto (recommended)', 'lingua-forge' ); ?></strong>
								&mdash;
								<?php esc_html_e( 'Detect active SEO plugins (Yoast, Rank Math, etc.) at runtime. Emit only og:locale + og:locale:alternate when another plugin already outputs the full OG set; emit the complete set when none is detected.', 'lingua-forge' ); ?>
							</label>
							<label style="display:block;margin-bottom:6px;">
								<input type="radio" name="linguaforge_seo_og_mode" value="locale-only" <?php checked( $mode, 'locale-only' ); ?>>
								<strong><?php esc_html_e( 'Locale only', 'lingua-forge' ); ?></strong>
								&mdash;
								<?php esc_html_e( 'Always emit only og:locale and og:locale:alternate. Use when another plugin or theme handles the full OG set.', 'lingua-forge' ); ?>
							</label>
							<label style="display:block;margin-bottom:6px;">
								<input type="radio" name="linguaforge_seo_og_mode" value="full" <?php checked( $mode, 'full' ); ?>>
								<strong><?php esc_html_e( 'Full', 'lingua-forge' ); ?></strong>
								&mdash;
								<?php esc_html_e( 'Always emit the complete OG + Twitter Card set regardless of other plugins. Use only if no other plugin outputs OG tags.', 'lingua-forge' ); ?>
							</label>
							<label style="display:block;">
								<input type="radio" name="linguaforge_seo_og_mode" value="disabled" <?php checked( $mode, 'disabled' ); ?>>
								<strong><?php esc_html_e( 'Disabled', 'lingua-forge' ); ?></strong>
								&mdash;
								<?php esc_html_e( 'Do not output any OG tags.', 'lingua-forge' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Default OG image', 'lingua-forge' ); ?></th>
					<td>
						<input
							type="url"
							name="linguaforge_seo_og_default_image"
							value="<?php echo esc_attr( $default_image ); ?>"
							class="regular-text"
							placeholder="https://example.com/fallback.jpg"
						>
						<p class="description">
							<?php
							esc_html_e(
								'Fallback image URL used when a page has no featured image, site logo, or site icon. Shown in social previews for archives, the blog index, and any singular page without a thumbnail.',
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
						<?php if ( 'disabled' === $effective_mode ) : ?>
							<p style="color:#d63638;font-weight:600;"><?php esc_html_e( '— Disabled', 'lingua-forge' ); ?></p>
						<?php elseif ( 'locale-only' === $effective_mode ) : ?>
							<p style="color:#00a32a;font-weight:600;"><?php esc_html_e( '✓ Active — locale tags only (og:locale, og:locale:alternate)', 'lingua-forge' ); ?></p>
						<?php else : ?>
							<p style="color:#00a32a;font-weight:600;"><?php esc_html_e( '✓ Active — full OG + Twitter Cards', 'lingua-forge' ); ?></p>
						<?php endif; ?>

						<?php if ( $social_share_active ) : ?>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Legacy lf-social-share mu-plugin detected. LF is deferring the base OG set to the mu-plugin and outputting og:locale + og:locale:alternate only. You can deactivate the mu-plugin — LF\'s built-in Social Share (SEO → Social Share tab) and Open Graph cover the same functionality.', 'lingua-forge' ); ?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $seo_plugins_found ) ) : ?>
							<p class="description" style="margin-top:6px;">
								<?php
								echo esc_html( sprintf(
									/* translators: %s: comma-separated list of SEO plugin names */
									__( 'SEO plugin(s) detected: %s. LF is outputting og:locale and og:locale:alternate only; the detected plugin handles the full OG set.', 'lingua-forge' ),
									implode( ', ', $seo_plugins_found )
								) );
								?>
							</p>
						<?php endif; ?>

						<?php if ( ! $social_share_active && empty( $seo_plugins_found ) && 'auto' === $mode ) : ?>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'No external OG plugin detected. LF is outputting the full OG + Twitter Card set.', 'lingua-forge' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>

			</table>

			<?php submit_button( __( 'Save Open Graph Settings', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
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

		check_admin_referer( 'linguaforge_save_seo_og', 'linguaforge_seo_og_nonce' );

		$enabled = ! empty( $_POST['linguaforge_seo_og_enabled'] );
		update_option( 'linguaforge_seo_og_enabled', $enabled ? 1 : 0, false );

		$allowed_modes = [ 'auto', 'locale-only', 'full', 'disabled' ];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key normalises slashes.
		$mode = sanitize_key( $_POST['linguaforge_seo_og_mode'] ?? 'auto' );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			$mode = 'auto';
		}
		update_option( 'linguaforge_seo_og_mode', $mode, false );

		$default_image = isset( $_POST['linguaforge_seo_og_default_image'] )
			? esc_url_raw( wp_unslash( $_POST['linguaforge_seo_og_default_image'] ) )
			: '';
		update_option( 'linguaforge_seo_og_default_image', $default_image, false );

		wp_safe_redirect( add_query_arg(
			'lf_seo_og_saved',
			'1',
			admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}
}
