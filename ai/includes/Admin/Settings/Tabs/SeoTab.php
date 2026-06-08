<?php
/**
 * Class LinguaForge\AI\Admin\Settings\Tabs\SeoTab
 *
 * Settings tab: SEO
 *
 * Thin orchestrator — delegates all rendering to dedicated panel classes:
 *   • HreflangPanel   — hreflang output toggle + SEO plugin suppression status
 *   • OpenGraphPanel  — Open Graph / Twitter Card toggle, mode selector, detection notices
 *
 * @package LinguaForge\AI\Admin\Settings\Tabs
 * @since   2.2.0
 */

namespace LinguaForge\AI\Admin\Settings\Tabs;

use LinguaForge\AI\Admin\Settings\Panels\CompatibilityPanel;
use LinguaForge\AI\Admin\Settings\Panels\SeoAnalysisPanel;
use LinguaForge\AI\Admin\Settings\Panels\SchemaPanel;
use LinguaForge\AI\Admin\Settings\Panels\SitemapPanel;
use LinguaForge\AI\Admin\Settings\Panels\HreflangPanel;
use LinguaForge\AI\Admin\Settings\Panels\OpenGraphPanel;
use LinguaForge\AI\Admin\Settings\Panels\SocialSharePanel;
use LinguaForge\AI\Admin\Settings\Panels\WooCommerceSeoPanel;

defined( 'ABSPATH' ) || exit;

class SeoTab extends Tab {

	public static function slug(): string {
		return 'seo';
	}

	public static function label(): string {
		return __( 'SEO', 'lingua-forge' );
	}

	public static function render_content(): void {

		?>
		<?php $wc_active = class_exists( 'WooCommerce' ); ?>

		<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Multilingual SEO', 'lingua-forge' ); ?></h2>
		<p class="description" style="margin-bottom:1.5em;max-width:680px;">
			<?php esc_html_e( 'Configure hreflang, Open Graph, Schema.org, sitemaps, and social sharing for every language on your site. Each section below handles one aspect of multilingual SEO.', 'lingua-forge' ); ?>
		</p>

		<nav class="nav-tab-wrapper lf-seo-tabs" style="margin-bottom:1.5em;">
			<a href="#lf-seo-tab-hreflang" class="nav-tab nav-tab-active" data-lf-tab="hreflang">
				<?php esc_html_e( 'Hreflang', 'lingua-forge' ); ?>
			</a>
			<a href="#lf-seo-tab-og" class="nav-tab" data-lf-tab="og">
				<?php esc_html_e( 'Open Graph &amp; Twitter Cards', 'lingua-forge' ); ?>
			</a>
			<a href="#lf-seo-tab-social-share" class="nav-tab" data-lf-tab="social-share">
				<?php esc_html_e( 'Social Share', 'lingua-forge' ); ?>
			</a>
			<?php if ( $wc_active ) : ?>
			<a href="#lf-seo-tab-woocommerce" class="nav-tab" data-lf-tab="woocommerce">
				<?php esc_html_e( 'WooCommerce', 'lingua-forge' ); ?>
			</a>
			<?php endif; ?>
			<a href="#lf-seo-tab-schema" class="nav-tab" data-lf-tab="schema">
				<?php esc_html_e( 'Schema.org', 'lingua-forge' ); ?>
			</a>
			<a href="#lf-seo-tab-sitemap" class="nav-tab" data-lf-tab="sitemap">
				<?php esc_html_e( 'Sitemap', 'lingua-forge' ); ?>
			</a>
			<a href="#lf-seo-tab-analysis" class="nav-tab" data-lf-tab="analysis">
				<?php esc_html_e( 'Analysis', 'lingua-forge' ); ?>
			</a>
			<a href="#lf-seo-tab-compatibility" class="nav-tab" data-lf-tab="compatibility">
				<?php esc_html_e( 'Compatibility', 'lingua-forge' ); ?>
			</a>
		</nav>

		<div id="lf-seo-tab-hreflang" class="lf-seo-tab-panel">
			<?php HreflangPanel::render(); ?>
		</div>

		<div id="lf-seo-tab-og" class="lf-seo-tab-panel">
			<?php OpenGraphPanel::render(); ?>
		</div>

		<div id="lf-seo-tab-social-share" class="lf-seo-tab-panel">
			<?php SocialSharePanel::render(); ?>
		</div>

		<?php if ( $wc_active ) : ?>
		<div id="lf-seo-tab-woocommerce" class="lf-seo-tab-panel">
			<?php WooCommerceSeoPanel::render(); ?>
		</div>
		<?php endif; ?>

		<div id="lf-seo-tab-schema" class="lf-seo-tab-panel">
			<?php SchemaPanel::render(); ?>
		</div>

		<div id="lf-seo-tab-sitemap" class="lf-seo-tab-panel">
			<?php SitemapPanel::render(); ?>
		</div>

		<div id="lf-seo-tab-analysis" class="lf-seo-tab-panel">
			<?php SeoAnalysisPanel::render(); ?>
		</div>

		<div id="lf-seo-tab-compatibility" class="lf-seo-tab-panel">
			<?php CompatibilityPanel::render(); ?>
		</div>

		<script>
		( function () {
			var LS_KEY = 'lf_seo_tab';
			var nav    = document.querySelector( '.lf-seo-tabs' );
			var panels = document.querySelectorAll( '.lf-seo-tab-panel' );

			if ( ! nav ) { return; }

			function activate( tabId ) {
				nav.querySelectorAll( '[data-lf-tab]' ).forEach( function ( a ) {
					a.classList.toggle( 'nav-tab-active', a.dataset.lfTab === tabId );
				} );
				panels.forEach( function ( panel ) {
					panel.style.display = ( panel.id === 'lf-seo-tab-' + tabId ) ? '' : 'none';
				} );
				try { localStorage.setItem( LS_KEY, tabId ); } catch (e) {}
			}

			// Restore persisted tab, defaulting to 'hreflang'.
			var initial = 'hreflang';
			try { initial = localStorage.getItem( LS_KEY ) || 'hreflang'; } catch (e) {}
			activate( initial );

			nav.addEventListener( 'click', function ( e ) {
				var a = e.target.closest( '[data-lf-tab]' );
				if ( ! a ) { return; }
				e.preventDefault();
				activate( a.dataset.lfTab );
			} );
		} )();
		</script>
		<?php
	}
}
