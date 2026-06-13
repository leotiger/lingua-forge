<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\SitemapPanel
 *
 * Renders the XML Sitemap section on the SEO tab.
 *
 * Lingua Forge generates a dedicated sitemap at /lf-sitemap.xml that
 * complements the WordPress core sitemap and any SEO plugin sitemap.
 * It adds the <xhtml:link rel="alternate" hreflang> entries that tell
 * search engines how your translated URLs relate to each other.
 *
 * Options:
 *   linguaforge_seo_sitemap_enabled  bool  Master switch (default true).
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

use LinguaForge\AI\Admin\SettingsPage;
use LinguaForge\Router\Router;

defined( 'ABSPATH' ) || exit;

class SitemapPanel {

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$enabled        = (bool) get_option( 'linguaforge_seo_sitemap_enabled', true );
		$sitemap        = Router::get_instance()->sitemap_manager;
		$sitemap_url    = $sitemap->get_sitemap_url();
		$entry_count    = $enabled ? $sitemap->get_cached_entry_count() : null; // null = not yet generated
		$cache_age      = $sitemap->cache_age();
		$plugin_active  = $sitemap->is_seo_sitemap_plugin_active();
		$plugins_found  = $sitemap->detected_sitemap_plugins();

		?>
		<!-- ── XML Sitemap ──────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge generates a dedicated XML sitemap at /lf-sitemap.xml that adds language alternate links for all your translated content. This sitemap complements the WordPress core sitemap and any SEO plugin sitemap — it does not replace them.',
				'lingua-forge'
			);
			?>
		</p>
		<p>
			<?php
			esc_html_e(
				'The WordPress core sitemap lists all your content URLs. The LF sitemap tells search engines how language versions relate to each other via xhtml:link hreflang alternates. Submit both to Google Search Console for full coverage.',
				'lingua-forge'
			);
			?>
		</p>

		<?php if ( $plugin_active ) : ?>
		<div class="notice notice-info inline" style="margin-bottom:1.5em;">
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: %s: comma-separated plugin names */
					__( '%s is active and generates its own sitemap. That sitemap is separate from LF\'s. Both should be submitted to Google Search Console: the SEO plugin sitemap lists your content; the LF sitemap adds the multilingual alternate links. LF\'s sitemap is announced in robots.txt automatically.', 'lingua-forge' ),
					implode( ', ', $plugins_found )
				) );
				?>
			</p>
		</div>
		<?php endif; ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag.
		if ( isset( $_GET['lf_seo_sitemap_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Sitemap settings saved.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag.
		if ( isset( $_GET['lf_sitemap_flushed'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Sitemap cache flushed. It will be regenerated on the next visit to the sitemap URL.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="linguaforge_save_seo_sitemap">
			<?php wp_nonce_field( 'linguaforge_save_seo_sitemap', 'linguaforge_seo_sitemap_nonce' ); ?>

			<table class="form-table" role="presentation">

				<tr>
					<th scope="row"><?php esc_html_e( 'Sitemap output', 'lingua-forge' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="linguaforge_seo_sitemap_enabled"
								value="1"
								<?php checked( $enabled ); ?>
							>
							<?php esc_html_e( 'Generate the multilingual sitemap at /lf-sitemap.xml', 'lingua-forge' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, the sitemap URL is announced in robots.txt automatically and the cache is refreshed when translated content is saved.', 'lingua-forge' ); ?>
						</p>
					</td>
				</tr>

				<?php if ( $enabled ) : ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Sitemap URL', 'lingua-forge' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $sitemap_url ); ?>
						</a>
						<p class="description">
							<?php esc_html_e( 'Submit this URL to Google Search Console alongside your main sitemap.', 'lingua-forge' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Current status', 'lingua-forge' ); ?></th>
					<td>
						<?php if ( null === $entry_count ) : ?>
							<p style="color:#646970;">
								<?php esc_html_e( 'Not yet generated. The sitemap is built on the first visit to the sitemap URL and cached for 24 hours.', 'lingua-forge' ); ?>
							</p>
						<?php elseif ( $entry_count > 0 ) : ?>
							<p style="color:#00a32a;font-weight:600;">
								<?php
								echo esc_html( sprintf(
									/* translators: %d: number of URL entries */
									_n(
										'✓ Active — %d URL entry with language alternates',
										'✓ Active — %d URL entries with language alternates',
										$entry_count,
										'lingua-forge'
									),
									$entry_count
								) );
								?>
							</p>
						<?php else : ?>
							<p style="color:#646970;">
								<?php esc_html_e( 'No translated content found. The sitemap will populate once posts have been translated.', 'lingua-forge' ); ?>
							</p>
						<?php endif; ?>

						<?php if ( $cache_age ) : ?>
							<p class="description">
								<?php
								echo esc_html( sprintf(
									/* translators: %s: ISO 8601 timestamp */
									__( 'Last generated: %s', 'lingua-forge' ),
									$cache_age
								) );
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>

				<?php endif; ?>

			</table>

			<?php submit_button( __( 'Save Sitemap Settings', 'lingua-forge' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $enabled ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="linguaforge_flush_sitemap_cache">
			<?php wp_nonce_field( 'linguaforge_flush_sitemap_cache', 'linguaforge_sitemap_flush_nonce' ); ?>
			<?php submit_button( __( 'Flush Sitemap Cache', 'lingua-forge' ), 'secondary small', 'submit', false ); ?>
			<p class="description" style="margin-top:4px;">
				<?php esc_html_e( 'Forces the sitemap to regenerate on the next request. Use after bulk-importing or manually editing translation relationships.', 'lingua-forge' ); ?>
			</p>
		</form>

		<!-- ── IndexNow ─────────────────────────────────────────── -->
		<hr style="margin-top:2em;">
		<h3><?php esc_html_e( 'IndexNow', 'lingua-forge' ); ?></h3>

		<p>
			<?php
			esc_html_e(
				'IndexNow is an open protocol supported by Bing, Yandex, and other search engines. When a translated post is published or updated, Lingua Forge automatically notifies all participating engines in a single push — no polling, no waiting for the next robots.txt crawl.',
				'lingua-forge'
			);
			?>
		</p>
		<p>
			<?php
			esc_html_e(
				'IndexNow replaces the deprecated Bing and Yandex sitemap-ping endpoints. Google discovers sitemaps via the robots.txt Sitemap: directive as before.',
				'lingua-forge'
			);
			?>
		</p>

		<?php
		$indexnow = \LinguaForge\Router\Router::get_instance()->indexnow_manager;
		$in_key   = $indexnow->get_key();

		// Result notice from manual submit.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag.
		$in_result = isset( $_GET['lf_indexnow_result'] ) ? sanitize_key( $_GET['lf_indexnow_result'] ) : '';

		if ( 'ok' === $in_result ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( '✓ All URLs submitted to IndexNow successfully.', 'lingua-forge' ); ?></p>
			</div>
		<?php elseif ( 'empty' === $in_result ) : ?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'No translated URLs found to submit. Publish some translated posts first.', 'lingua-forge' ); ?></p>
			</div>
		<?php elseif ( 'error' === $in_result ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( '✗ IndexNow submission failed. Check that the key file is publicly reachable (see below) and try again.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation" style="max-width:700px;">
			<tr>
				<th scope="row"><?php esc_html_e( 'Verification key', 'lingua-forge' ); ?></th>
				<td>
					<?php if ( '' !== $in_key ) : ?>
						<code><?php echo esc_html( $in_key ); ?></code>
						<p class="description" style="margin-top:4px;">
							<?php
							echo esc_html( sprintf(
								/* translators: %s: key file URL */
								__( 'Key file served automatically at: %s', 'lingua-forge' ),
								$indexnow->key_file_url()
							) );
							?>
						</p>
					<?php else : ?>
						<p style="color:#d63638;"><?php esc_html_e( 'Key could not be generated. Check that the site has write access to WordPress options.', 'lingua-forge' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Key file status', 'lingua-forge' ); ?></th>
				<td>
					<?php
					$reachable = '' !== $in_key && $indexnow->key_file_reachable();
					if ( $reachable ) : ?>
						<p style="color:#00a32a;font-weight:600;"><?php esc_html_e( '✓ Key file is publicly reachable.', 'lingua-forge' ); ?></p>
					<?php else : ?>
						<p style="color:#d63638;font-weight:600;"><?php esc_html_e( '✗ Key file is not reachable.', 'lingua-forge' ); ?></p>
						<p class="description">
							<?php esc_html_e( 'IndexNow engines verify the key file before accepting submissions. If the file is not reachable, submissions will be rejected with a 403 error.', 'lingua-forge' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Common causes: full-page caching (clear it), a security plugin blocking .txt files, or a custom Nginx/Apache rule. The file is served by LF on every request — no physical file is written to disk.', 'lingua-forge' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Submit all URLs', 'lingua-forge' ); ?></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="linguaforge_indexnow_submit">
						<?php wp_nonce_field( 'linguaforge_indexnow_submit', 'linguaforge_indexnow_nonce' ); ?>
						<button type="submit" class="button button-secondary"<?php echo $reachable ? '' : ' disabled'; ?>>
							<?php esc_html_e( 'Submit all URLs via IndexNow', 'lingua-forge' ); ?>
						</button>
					</form>
					<p class="description" style="margin-top:4px;">
						<?php esc_html_e( 'Pushes every published, LF-managed URL to IndexNow in one batch. Automatic submission already runs on every publish/update — use this only after a bulk import or when setting up IndexNow for the first time.', 'lingua-forge' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Google</th>
				<td>
					<p class="description">
						<?php
						esc_html_e(
							'Google does not participate in IndexNow. It discovers sitemaps via the robots.txt Sitemap: directive — which LF has already configured. Use Google Search Console to monitor indexing coverage.',
							'lingua-forge'
						);
						?>
					</p>
					<p class="description" style="margin-top:4px;">
						<a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Open Search Console →', 'lingua-forge' ); ?>
						</a>
					</p>
				</td>
			</tr>
		</table>

		<?php endif; ?>

		<!-- ── robots.txt ───────────────────────────────────── -->
		<hr style="margin-top:2em;">
		<h3><?php esc_html_e( 'robots.txt', 'lingua-forge' ); ?></h3>

		<?php self::render_robots_section( $sitemap_url, $enabled ); ?>

		<?php
	}

	// =========================================================================
	// robots.txt section
	// =========================================================================

	private static function render_robots_section( string $sitemap_url, bool $sitemap_enabled ): void {

		$robots_path   = ABSPATH . 'robots.txt';
		$has_physical  = file_exists( $robots_path );
		$can_write     = ! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS;
		$sitemap_line  = 'Sitemap: ' . $sitemap_url;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET flag.
		if ( isset( $_GET['lf_robots_updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'robots.txt updated with Sitemap directive.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif;

		if ( $has_physical ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local filesystem file; WP_Filesystem not needed for read-only admin display.
			$content     = (string) file_get_contents( $robots_path );
			$has_sitemap = str_contains( $content, $sitemap_url );

			if ( $has_sitemap ) : ?>
				<div class="notice notice-success inline" style="margin-bottom:1em;">
					<p>
						<?php esc_html_e( '✓ Physical robots.txt found. LF\'s Sitemap directive is present.', 'lingua-forge' ); ?>
					</p>
				</div>
			<?php else : ?>
				<div class="notice notice-warning inline" style="margin-bottom:1em;">
					<p>
						<?php
						esc_html_e(
							'A physical robots.txt file exists at the site root. WordPress\'s virtual robots.txt — and LF\'s Sitemap: filter — are bypassed. The LF sitemap is not announced to search engines.',
							'lingua-forge'
						);
						?>
					</p>
				</div>
				<?php if ( $sitemap_enabled ) : ?>
					<?php if ( $can_write ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="linguaforge_update_robots_txt">
						<?php wp_nonce_field( 'linguaforge_update_robots_txt', 'linguaforge_robots_nonce' ); ?>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Add Sitemap directive to robots.txt', 'lingua-forge' ); ?>
						</button>
						<p class="description" style="margin-top:6px;">
							<?php
							echo esc_html( sprintf(
								/* translators: %s: Sitemap directive line */
								__( 'Appends the following line to your existing robots.txt: %s', 'lingua-forge' ),
								$sitemap_line
							) );
							?>
						</p>
					</form>
					<?php else : ?>
					<p class="description">
						<strong><?php esc_html_e( 'DISALLOW_FILE_MODS is set.', 'lingua-forge' ); ?></strong>
						<?php esc_html_e( 'Add the following line to your robots.txt manually:', 'lingua-forge' ); ?>
						<br>
						<code><?php echo esc_html( $sitemap_line ); ?></code>
					</p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<details style="margin-top:1em;">
				<summary style="cursor:pointer;font-weight:600;">
					<?php esc_html_e( 'View current robots.txt', 'lingua-forge' ); ?>
				</summary>
				<textarea
					readonly
					class="large-text"
					rows="12"
					style="margin-top:8px;font-family:monospace;font-size:12px;"
				><?php echo esc_textarea( $content ); ?></textarea>
			</details>

		<?php } else { ?>

			<div class="notice notice-success inline" style="margin-bottom:1em;">
				<p>
					<?php
					esc_html_e(
						'No physical robots.txt file found. WordPress is generating it virtually and LF\'s Sitemap directive is included automatically.',
						'lingua-forge'
					);
					?>
				</p>
			</div>

			<?php if ( $sitemap_enabled ) : ?>
			<p class="description">
				<?php esc_html_e( 'The following directive is active in your virtual robots.txt:', 'lingua-forge' ); ?>
				<br>
				<code><?php echo esc_html( $sitemap_line ); ?></code>
			</p>
			<?php endif; ?>

		<?php }
	}

	// =========================================================================
	// Save handlers
	// =========================================================================

	public static function handle_save(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'linguaforge_save_seo_sitemap', 'linguaforge_seo_sitemap_nonce' );

		$enabled = ! empty( $_POST['linguaforge_seo_sitemap_enabled'] );
		update_option( 'linguaforge_seo_sitemap_enabled', $enabled ? 1 : 0, false );

		// Flush cache when toggling so the next visit regenerates correctly.
		Router::get_instance()->sitemap_manager->flush_cache();

		wp_safe_redirect( add_query_arg(
			'lf_seo_sitemap_saved',
			'1',
			admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}

	public static function handle_indexnow_submit(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'linguaforge_indexnow_submit', 'linguaforge_indexnow_nonce' );

		$result = Router::get_instance()->indexnow_manager->submit_all();

		wp_safe_redirect( add_query_arg(
			'lf_indexnow_result',
			$result,
			admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}

	public static function handle_flush_cache(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'linguaforge_flush_sitemap_cache', 'linguaforge_sitemap_flush_nonce' );

		Router::get_instance()->sitemap_manager->flush_cache();

		wp_safe_redirect( add_query_arg(
			'lf_sitemap_flushed',
			'1',
			admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}

	public static function handle_update_robots(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lingua-forge' ), 403 );
		}

		check_admin_referer( 'linguaforge_update_robots_txt', 'linguaforge_robots_nonce' );

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			wp_die( esc_html__( 'File modifications are disabled (DISALLOW_FILE_MODS).', 'lingua-forge' ), 403 );
		}

		$robots_path  = ABSPATH . 'robots.txt';
		$sitemap_url  = Router::get_instance()->sitemap_manager->get_sitemap_url();
		$sitemap_line = 'Sitemap: ' . $sitemap_url;

		// Read existing content or start fresh.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local file for modification.
		$content = file_exists( $robots_path ) ? (string) file_get_contents( $robots_path ) : '';

		// Only append if not already present.
		if ( str_contains( $content, $sitemap_url ) ) {
			wp_safe_redirect( add_query_arg(
				'lf_robots_updated', '1',
				admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
			) . '#seo' );
			exit;
		}

		// Use WP_Filesystem for Plugin Check compliance.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;

		$new_content = rtrim( $content ) . "\n\n" . $sitemap_line . "\n";

		$wrote = $wp_filesystem->put_contents( $robots_path, $new_content, FS_CHMOD_FILE );

		if ( ! $wrote ) {
			wp_die( esc_html__( 'Could not write to robots.txt. Check file permissions.', 'lingua-forge' ) );
		}

		wp_safe_redirect( add_query_arg(
			'lf_robots_updated', '1',
			admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
		) . '#seo' );
		exit;
	}
}
