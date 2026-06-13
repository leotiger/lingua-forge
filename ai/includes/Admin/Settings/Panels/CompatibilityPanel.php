<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Panels\CompatibilityPanel
 *
 * Read-only SEO plugin compatibility overview on the SEO tab.
 *
 * Detects active SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress) and the
 * legacy lf-social-share mu-plugin, then shows exactly what Lingua Forge does
 * in each feature area (Hreflang, Open Graph, Canonical) for each detected
 * plugin — and WHY.
 *
 * No settings live here.  All toggles are on the other SEO sub-tabs.
 *
 * @package LinguaForge\AI\Admin\Settings\Panels
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Panels;

defined( 'ABSPATH' ) || exit;

class CompatibilityPanel {

	// =========================================================================
	// Known SEO plugins
	// =========================================================================

	/**
	 * Returns the list of known SEO plugins with detection data.
	 *
	 * @return array<string, array{label:string, active:bool, hreflang_filter:string}>
	 */
	private static function known_plugins(): array {
		return [
			'yoast'    => [
				'label'            => 'Yoast SEO',
				'active'           => defined( 'WPSEO_VERSION' ),
				'hreflang_filter'  => 'wpseo_hreflang',
			],
			'rankmath' => [
				'label'            => 'Rank Math',
				'active'           => defined( 'RANK_MATH_VERSION' ),
				'hreflang_filter'  => 'rank_math/frontend/hreflang',
			],
			'aioseo'   => [
				'label'            => 'All in One SEO',
				'active'           => defined( 'AIOSEO_VERSION' ),
				'hreflang_filter'  => 'aioseo_hreflang',
			],
			'seopress' => [
				'label'            => 'SEOPress',
				'active'           => defined( 'SEOPRESS_VERSION' ),
				'hreflang_filter'  => 'seopress_hreflang',
			],
		];
	}

	// =========================================================================
	// Render
	// =========================================================================

	public static function render(): void {

		$plugins        = self::known_plugins();
		$active_plugins = array_filter( $plugins, fn( $p ) => $p['active'] );
		$mu_active      = function_exists( 'lf_social_share_get_current_url' );
		$hreflang_on    = (bool) get_option( 'linguaforge_seo_hreflang_enabled', true );
		$og_enabled     = (bool) get_option( 'linguaforge_seo_og_enabled', true );
		$og_mode        = (string) get_option( 'linguaforge_seo_og_mode', 'auto' );
		$schema_enabled  = (bool) get_option( 'linguaforge_seo_schema_enabled',  true );
		$sitemap_enabled = (bool) get_option( 'linguaforge_seo_sitemap_enabled', true );

		?>
		<!-- ── Compatibility ────────────────────────────────── -->
		<p>
			<?php
			esc_html_e(
				'Lingua Forge is a complete multilingual SEO solution. No additional SEO plugin is required.',
				'lingua-forge'
			);
			?>
		</p>
		<p>
			<?php
			esc_html_e(
				'If you have a general SEO plugin installed, this panel shows how LF adapts to avoid duplicate output. The goal is collision avoidance — not cooperation. LF always provides the multilingual SEO layer (hreflang, og:locale, inLanguage schema) that requires knowledge of LF\'s language routing configuration to produce correctly.',
				'lingua-forge'
			);
			?>
		</p>

		<!-- ── Plugin detection ─────────────────────────────── -->
		<h3 style="margin-top:1.5em;"><?php esc_html_e( 'Installed SEO plugins', 'lingua-forge' ); ?></h3>

		<table class="widefat striped" style="max-width:480px;margin-bottom:1.5em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin', 'lingua-forge' ); ?></th>
					<th><?php esc_html_e( 'Status', 'lingua-forge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $plugins as $plugin ) : ?>
				<tr>
					<td><?php echo esc_html( $plugin['label'] ); ?></td>
					<td>
						<?php if ( $plugin['active'] ) : ?>
							<span style="color:#00a32a;font-weight:600;"><?php esc_html_e( '✓ Active', 'lingua-forge' ); ?></span>
						<?php else : ?>
							<span style="color:#646970;"><?php esc_html_e( '— Not detected', 'lingua-forge' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( empty( $active_plugins ) && ! $mu_active ) : ?>
			<div class="notice notice-success inline" style="margin-bottom:1.5em;">
				<p><?php esc_html_e( 'No conflicting SEO plugins detected. Lingua Forge is handling all SEO output independently.', 'lingua-forge' ); ?></p>
			</div>
		<?php endif; ?>

		<!-- ── Per-feature behaviour ────────────────────────── -->
		<h3 style="margin-top:1.5em;"><?php esc_html_e( 'What LF provides — and collision avoidance', 'lingua-forge' ); ?></h3>

		<table class="widefat" style="max-width:860px;margin-bottom:1.5em;">
			<thead>
				<tr>
					<th style="width:160px;"><?php esc_html_e( 'Feature', 'lingua-forge' ); ?></th>
					<th style="width:220px;"><?php esc_html_e( 'LF provides', 'lingua-forge' ); ?></th>
					<th><?php esc_html_e( 'When another SEO plugin is installed', 'lingua-forge' ); ?></th>
				</tr>
			</thead>
			<tbody>

				<!-- Hreflang -->
				<tr>
					<td><strong><?php esc_html_e( 'Hreflang', 'lingua-forge' ); ?></strong></td>
					<td>
						<?php if ( ! $hreflang_on ) : ?>
							<span style="color:#646970;"><?php esc_html_e( 'Disabled (by setting)', 'lingua-forge' ); ?></span>
						<?php elseif ( ! empty( $active_plugins ) ) : ?>
							<span style="color:#00a32a;font-weight:600;">
								<?php esc_html_e( '✓ LF outputs — plugin hreflang suppressed', 'lingua-forge' ); ?>
							</span>
						<?php else : ?>
							<span style="color:#00a32a;font-weight:600;">
								<?php esc_html_e( '✓ LF outputs', 'lingua-forge' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td class="description">
						<?php
						esc_html_e(
							'LF outputs accurate hreflang for every configured language, including x-default. General SEO plugins have no access to your LF routing configuration and would produce hreflang based on incorrect URL assumptions. LF suppresses their output via filter to prevent collisions.',
							'lingua-forge'
						);
						if ( ! empty( $active_plugins ) && $hreflang_on ) :
							echo ' ';
							echo esc_html( sprintf(
								/* translators: %s: comma-separated list of suppressed plugin names */
								__( 'Suppressed: %s.', 'lingua-forge' ),
								implode( ', ', array_map(
									fn( $p ) => $p['label'] . ' (' . $p['hreflang_filter'] . ')',
									$active_plugins
								) )
							) );
						endif;
						?>
					</td>
				</tr>

				<!-- Open Graph -->
				<tr>
					<td><strong><?php esc_html_e( 'Open Graph', 'lingua-forge' ); ?></strong></td>
					<td>
						<?php if ( ! $og_enabled ) : ?>
							<span style="color:#646970;"><?php esc_html_e( 'Disabled (by setting)', 'lingua-forge' ); ?></span>
						<?php elseif ( 'disabled' === $og_mode ) : ?>
							<span style="color:#646970;"><?php esc_html_e( 'Disabled (mode setting)', 'lingua-forge' ); ?></span>
						<?php elseif ( ! empty( $active_plugins ) && 'auto' === $og_mode ) : ?>
							<span style="color:#2271b1;font-weight:600;">
								<?php esc_html_e( '→ Locale tags only — deferring base OG to plugin', 'lingua-forge' ); ?>
							</span>
						<?php elseif ( 'locale-only' === $og_mode ) : ?>
							<span style="color:#2271b1;font-weight:600;">
								<?php esc_html_e( '→ Locale tags only (manual setting)', 'lingua-forge' ); ?>
							</span>
						<?php else : ?>
							<span style="color:#00a32a;font-weight:600;">
								<?php esc_html_e( '✓ LF outputs full OG + Twitter Cards', 'lingua-forge' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td class="description">
						<?php
						esc_html_e(
							'LF always adds og:locale and og:locale:alternate — the multilingual OG signals no general SEO plugin can produce correctly. For the base OG set (og:title, og:description, og:image), LF outputs it when no other plugin is present. When a SEO plugin is installed, LF skips the base set to avoid duplicate tags — not because the plugin is "better", but because duplication produces no value.',
							'lingua-forge'
						);
						?>
					</td>
				</tr>

				<!-- Schema.org -->
				<tr>
					<td><strong><?php esc_html_e( 'Schema.org JSON-LD', 'lingua-forge' ); ?></strong></td>
					<td>
						<?php if ( ! $schema_enabled ) : ?>
							<span style="color:#646970;"><?php esc_html_e( 'Disabled (by setting)', 'lingua-forge' ); ?></span>
						<?php elseif ( ! empty( $active_plugins ) ) : ?>
							<span style="color:#646970;">
								<?php esc_html_e( '— Deferred to plugin', 'lingua-forge' ); ?>
							</span>
						<?php else : ?>
							<span style="color:#00a32a;font-weight:600;">
								<?php esc_html_e( '✓ LF outputs Article / WebPage / WebSite', 'lingua-forge' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td class="description">
						<?php
						esc_html_e(
							'LF outputs multilingual JSON-LD with inLanguage annotations tied to its language routing configuration. When a SEO plugin is installed, LF disables its own schema entirely: duplicate JSON-LD graphs produce validation errors and there is no clean way to supplement an existing graph.',
							'lingua-forge'
						);
						?>
					</td>
				</tr>

			<!-- Sitemap -->
				<tr>
					<td><strong><?php esc_html_e( 'XML Sitemap', 'lingua-forge' ); ?></strong></td>
					<td>
						<?php if ( ! $sitemap_enabled ) : ?>
							<span style="color:#646970;"><?php esc_html_e( 'Disabled (by setting)', 'lingua-forge' ); ?></span>
						<?php else : ?>
							<span style="color:#00a32a;font-weight:600;">
								<?php esc_html_e( '✓ Active at /lf-sitemap.xml', 'lingua-forge' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td class="description">
						<?php
						esc_html_e(
							'LF generates its own sitemap with xhtml:link alternates for all translation groups. When a SEO plugin is installed, it generates a separate sitemap — both coexist independently. Submit both to Google Search Console: the SEO plugin sitemap for content discovery, the LF sitemap for language relationship signals.',
							'lingua-forge'
						);
						?>
					</td>
				</tr>

			<!-- Canonical -->
				<tr>
					<td><strong><?php esc_html_e( 'Canonical tag', 'lingua-forge' ); ?></strong></td>
					<td>
						<?php if ( $hreflang_on ) : ?>
							<span style="color:#00a32a;font-weight:600;">
								<?php esc_html_e( '✓ WP canonical replaced (self-referencing)', 'lingua-forge' ); ?>
							</span>
						<?php else : ?>
							<span style="color:#646970;"><?php esc_html_e( 'WP core canonical active (hreflang disabled)', 'lingua-forge' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="description">
						<?php
						esc_html_e(
							'LF removes the WordPress core canonical tag and replaces it with a self-referencing canonical pointing to the correct language-prefixed URL. When a third-party SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) is active, LF defers canonical management to that plugin entirely.',
							'lingua-forge'
						);
						?>
					</td>
				</tr>

			</tbody>
		</table>

		<?php if ( $mu_active ) : ?>
		<!-- ── lf-social-share mu-plugin notice ─────────────── -->
		<h3 style="margin-top:1.5em;"><?php esc_html_e( 'Legacy lf-social-share mu-plugin', 'lingua-forge' ); ?></h3>

		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'The lf-social-share mu-plugin is active.', 'lingua-forge' ); ?></strong>
				<?php
				esc_html_e(
					'Lingua Forge\'s built-in Social Share and Open Graph modules cover all functionality the mu-plugin provides, plus multilingual og:locale support. You can safely deactivate the mu-plugin.',
					'lingua-forge'
				);
				?>
			</p>
			<ul style="margin:.5em 0 .5em 1.5em;list-style:disc;">
				<li><?php esc_html_e( 'OG/Twitter Card tags: handled by LF\'s Open Graph module (SEO → Open Graph & Twitter Cards)', 'lingua-forge' ); ?></li>
				<li><?php esc_html_e( 'Social Icons block share: URLs: handled by LF\'s built-in Social Share (SEO → Social Share)', 'lingua-forge' ); ?></li>
				<li><?php esc_html_e( 'copy/native/auto JS actions: handled by LF\'s social-share.js', 'lingua-forge' ); ?></li>
			</ul>
			<p>
				<?php
				esc_html_e(
					'While active, the mu-plugin handles the base OG set and LF emits locale tags only (auto mode). Removing it switches LF to full OG mode automatically.',
					'lingua-forge'
				);
				?>
			</p>
		</div>
		<?php endif; ?>
		<?php
	}
}
