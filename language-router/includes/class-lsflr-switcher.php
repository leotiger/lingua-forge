<?php
/**
 * Class LinguaForge\Router\Switcher
 *
 * Language Switcher block and renderer.
 * Depends on LinguaForge\Router\Router for data resolution.
 */

namespace LinguaForge\Router;

if ( ! defined( 'ABSPATH' ) ) exit;

class Switcher {

	private Router $router;

	public function __construct( Router $router ) {
		$this->router = $router;
		$this->register_hooks();
	}

	private function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'init',          [ $this, 'register_block' ] );
		add_action( 'init',          [ $this, 'register_shortcode' ] );
		add_action( 'widgets_init',  [ $this, 'register_widget' ] );
	}

	// =========================================================
	// ASSETS
	// =========================================================

	public function enqueue_styles(): void {
		wp_enqueue_style(
			'lsflr',
			plugin_dir_url( __DIR__ ) . 'assets/lsflr.css',
			[],
			defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false
		);
	}

	// =========================================================
	// DATA
	// =========================================================

	public function get_languages(): array {
		$post_id        = get_the_ID() ?: null;
		$force_permalink = false;

		// Translated WC shop pages (/es/tienda/, /ca/botiga/): inject_shop_post_type()
		// fires at pre_get_posts p9 and converts the page query to a product archive.
		// By the time the Switcher renders the main loop may have started, so
		// get_the_ID() returns the first product ID rather than the shop page ID,
		// and is_singular() is false.  inject_shop_post_type() saves the original
		// page ID in the 'lf_shop_page_id' query var so we can recover it here.
		if ( ! is_singular() ) {
			$shop_page_id = (int) get_query_var( 'lf_shop_page_id' );
			if ( $shop_page_id > 0 ) {
				$post_id        = $shop_page_id;
				$force_permalink = true; // permalink must be used; is_singular() is false
			}
		}

		// Singular pages: build from the post's translation group so each
		// language link points at the correct translated post permalink.
		// Non-singular pages (archives, category, tag, author, date, search,
		// homepage without a static page): there is no post to translate, so
		// generate one URL per language by rewriting the current URL only.
		// Without this fallback the switcher is invisible on all archive pages,
		// which is a hard blocker for subdomain routing where every URL on
		// de.example.com should have a working language switcher.
		if ( $post_id ) {
			$translation_map = $this->router->get_translations( $post_id );
			if ( empty( $translation_map ) ) return [];
		} else {
			// Keyed by lang code, value null signals "URL-rewrite only".
			$translation_map = array_fill_keys( $this->router->languages(), null );
		}

		// On language-neutral product URLs (e.g. /product/camisa/) LF_LANG is always
		// the source language even when the queried product is a translation.  Read the
		// product's own _lf_lang so the switcher marks the actual content language as
		// current rather than always marking the source language.
		$current_lang = LF_LANG;
		if ( is_singular() && $post_id ) {
			$product_lang = (string) get_post_meta( $post_id, '_lf_lang', true );
			if ( $product_lang ) {
				$current_lang = $product_lang;
			}
		}

		$langs = [];

		foreach ( $translation_map as $lang => $id ) {
			// For translation-group entries, skip unpublished posts.
			// For URL-rewrite entries ($id === null) there is nothing to check.
			if ( null !== $id && get_post_status( $id ) !== 'publish' ) continue;

			$langs[] = [
				'code'    => $lang,
				'url'     => $this->translate_current_url( $lang, $id, $force_permalink ),
				'label'   => $this->router->language_label( $lang ),
				'current' => ( $lang === $current_lang ),
			];
		}

		return $langs;
	}

	public function translate_current_url( string $target_lang, ?int $post_id = null, bool $force_permalink = false ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is a server-set value used only for URL path parsing; home_url() encodes the result.
		$current_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );

		return self::build_translated_url(
			$current_url,
			$target_lang,
			$this->router->source_language(),
			$this->router->languages(),
			$this->router->context->routing_mode(),
			is_search(),
			(string) get_query_var( 's' ),
			// Use permalink when on a genuine singular page, OR when the caller
			// explicitly set $force_permalink (e.g. translated shop pages whose
			// query was converted to a product archive by inject_shop_post_type(),
			// making is_singular() false even though $post_id is known and correct).
			( is_singular() || $force_permalink ) && (bool) $post_id,
			$post_id ? (string) get_permalink( $post_id ) : '',
			$this->router->context->lang_base_url( $target_lang ),
			home_url()
		);
	}

	/**
	 * Build the translated URL for a given language — pure, WP-free.
	 *
	 * Public static so unit tests can call it directly with controlled inputs.
	 * Called by translate_current_url() after all WP-dependent values are resolved.
	 *
	 * @param  string   $current_url   Full current URL (home_url + REQUEST_URI).
	 * @param  string   $target_lang   Language to translate to.
	 * @param  string   $source_lang   Site source language.
	 * @param  string[] $langs         All active language codes.
	 * @param  string   $routing_mode  'path' or 'subdomain'.
	 * @param  bool     $is_search     Whether the current page is a search results page.
	 * @param  string   $search_term   Search query (get_query_var('s')).
	 * @param  bool     $is_singular   True when current page is singular AND $permalink is known.
	 * @param  string   $permalink     get_permalink($post_id), or '' when not applicable.
	 * @param  string   $lang_base_url Subdomain base URL for $target_lang.
	 * @param  string   $home_url      Site home URL.
	 * @return string
	 */
	public static function build_translated_url(
		string $current_url,
		string $target_lang,
		string $source_lang,
		array  $langs,
		string $routing_mode,
		bool   $is_search,
		string $search_term,
		bool   $is_singular,
		string $permalink,
		string $lang_base_url,
		string $home_url
	): string {
		$parsed   = \parse_url( $current_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure static helper; wp_parse_url() is not available without WP runtime.
		$path     = trim( $parsed['path'] ?? '', '/' );
		$query    = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$home_url = \untrailingslashit( $home_url );

		$segments = explode( '/', $path );
		if ( ! empty( $segments[0] ) && in_array( $segments[0], $langs, true ) ) {
			array_shift( $segments );
		}
		$new_path = implode( '/', $segments );

		// Search results page.
		if ( $is_search ) {
			if ( $routing_mode === 'subdomain' ) {
				return $lang_base_url . '?s=' . rawurlencode( $search_term );
			}
			return $home_url . '/?lang=' . $target_lang . '&s=' . rawurlencode( $search_term );
		}

		// Singular — use the pre-resolved permalink.
		if ( $is_singular && $permalink !== '' ) {
			return $permalink . $query;
		}

		// Non-singular: switching to source language → strip prefix only.
		if ( $target_lang === $source_lang ) {
			return $home_url . '/' . trim( $new_path, '/' ) . '/' . $query;
		}

		// Non-singular: subdomain mode.
		if ( $routing_mode === 'subdomain' ) {
			return $lang_base_url . ( $new_path ? \trailingslashit( $new_path ) : '' ) . $query;
		}

		// Non-singular: path-prefix mode.
		return $home_url . '/' . $target_lang . '/' . $new_path . '/' . $query;
	}

	// =========================================================
	// RENDER
	// =========================================================

	public function render_switcher( array $atts = [] ): string {
		$atts = wp_parse_args( $atts, [
			'direction'   => 'down',
			'show'        => 'label',
			'customLabel' => 'Language',
			'iconHtml'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M351.9 280l-190.9 0c2.9 64.5 17.2 123.9 37.5 167.4 11.4 24.5 23.7 41.8 35.1 52.4 11.2 10.5 18.9 12.2 22.9 12.2s11.7-1.7 22.9-12.2c11.4-10.6 23.7-28 35.1-52.4 20.3-43.5 34.6-102.9 37.5-167.4zM160.9 232l190.9 0C349 167.5 334.7 108.1 314.4 64.6 303 40.2 290.7 22.8 279.3 12.2 268.1 1.7 260.4 0 256.4 0s-11.7 1.7-22.9 12.2c-11.4 10.6-23.7 28-35.1 52.4-20.3 43.5-34.6 102.9-37.5 167.4zm-48 0C116.4 146.4 138.5 66.9 170.8 14.7 78.7 47.3 10.9 131.2 1.5 232l111.4 0zM1.5 280c9.4 100.8 77.2 184.7 169.3 217.3-32.3-52.2-54.4-131.7-57.9-217.3L1.5 280zm398.4 0c-3.5 85.6-25.6 165.1-57.9 217.3 92.1-32.7 159.9-116.5 169.3-217.3l-111.4 0zm111.4-48C501.9 131.2 434.1 47.3 342 14.7 374.3 66.9 396.4 146.4 399.9 232l111.4 0z"/></svg>',
			'overlayMode' => 'never',
		] );

		$langs = $this->get_languages();
		if ( ! $langs ) return '';

		$current = null;
		$others  = [];

		foreach ( $langs as $lang ) {
			if ( $lang['current'] ) $current = $lang;
			else $others[] = $lang;
		}

		if ( ! $current ) $current = $langs[0];

		$get_icon = function( string $html ): string {
			$html = preg_replace( '/(width|height)="[^"]*"/i', '', $html );
			return wp_kses( $html, [
				'svg'  => [ 'xmlns' => true, 'viewbox' => true ],
				'path' => [ 'd' => true, 'fill' => true ],
			] );
		};

		if ( $atts['show'] === 'custom' ) {
			// Store raw value — wp_kses_post() at echo point handles entity normalisation.
			// Pre-escaping with esc_html() here would double-encode entities (e.g. & → &amp;amp;).
			$toggle = $atts['customLabel'];
		} elseif ( $atts['show'] === 'icon' ) {
			$toggle = '<span class="lsflr-icon">' . $get_icon( $atts['iconHtml'] ) . '</span>';
		} elseif ( $atts['show'] === 'icon-label' ) {
			$toggle =
				'<span class="lsflr-icon">' . $get_icon( $atts['iconHtml'] ) . '</span>' .
				'<span class="lsflr-label">' . esc_html( $current['label'] ) . '</span>';
		} else {
			$toggle = esc_html( $current['label'] );
		}

		$switcher_id  = 'lsflr-' . wp_unique_id();
		$overlay_mode = in_array( $atts['overlayMode'], [ 'always', 'auto' ], true )
			? $atts['overlayMode']
			: 'never';

		ob_start();

		if ( $overlay_mode !== 'never' ) :
			$panel_id = $switcher_id . '-panel';
			$toggle_kses = [
				'svg'  => [ 'xmlns' => true, 'viewbox' => true ],
				'path' => [ 'd' => true, 'fill' => true ],
				'span' => [ 'class' => true ],
			];
			?>

		<div id="<?php echo esc_attr( $switcher_id ); ?>"
			class="lsflr-switcher lsflr-overlay-wrap"
			data-overlay-mode="<?php echo esc_attr( $overlay_mode ); ?>"
		>
			<button
				class="lsflr-trigger"
				aria-haspopup="dialog"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $panel_id ); ?>"
			><span class="lsflr-current"><?php echo wp_kses( $toggle, $toggle_kses ); ?></span></button>

			<div
				id="<?php echo esc_attr( $panel_id ); ?>"
				class="lsflr-panel"
				role="dialog"
				aria-modal="true"
				aria-label="<?php esc_attr_e( 'Select language', 'lingua-forge' ); ?>"
				hidden
			>
				<button
					class="lsflr-panel-close"
					aria-label="<?php esc_attr_e( 'Close language panel', 'lingua-forge' ); ?>"
				>&times;</button>

				<div class="lsflr-panel-grid">
					<?php foreach ( $others as $lang ) : ?>
						<a
							href="<?php echo esc_url( $lang['url'] ); ?>"
							lang="<?php echo esc_attr( $lang['code'] ); ?>"
							class="lsflr-lang-item"
						><?php echo esc_html( $lang['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<script>
		(function(){
			var wrap  = document.getElementById('<?php echo esc_js( $switcher_id ); ?>');
			var btn   = wrap ? wrap.querySelector('.lsflr-trigger') : null;
			var panel = wrap ? wrap.querySelector('.lsflr-panel')  : null;
			if (!wrap || !btn || !panel) return;

			var secure    = <?php echo is_ssl() ? 'true' : 'false'; ?>;
			var maxAge    = <?php echo (int) MONTH_IN_SECONDS; ?>;
			/* Match the domain PHP uses in set_lang_cookie() so both writes target
			   the same cookie entry and JS can overwrite a server-written cookie. */
			var cookieDomain = '<?php echo esc_js( $this->router->context->routing_mode() === 'subdomain'
				? '.' . $this->router->context->base_domain()
				: (string) wp_parse_url( home_url(), PHP_URL_HOST )
			); ?>';

			function writeCookie(code) {
				document.cookie = 'lf_lang=' + encodeURIComponent(code) +
					'; path=/' +
					'; max-age=' + maxAge +
					'; domain=' + cookieDomain +
					(secure ? '; secure' : '') +
					'; samesite=lax';
			}

			function positionPanel() {
				var r   = btn.getBoundingClientRect();
				var vw  = window.innerWidth  || document.documentElement.clientWidth;
				var vh  = window.innerHeight || document.documentElement.clientHeight;
				var gap = 6;
				var pw  = Math.round(vw * 0.9);
				var maxH = Math.round(vh * 0.40);

				/* Horizontal: align with trigger leading edge, clamped so panel stays
				   inside the viewport with 8px margin on each side. */
				var leftEdge  = Math.min(r.left, vw - pw - 8);
				leftEdge = Math.max(leftEdge, 8);

				/* Vertical: prefer below trigger; fall back to above when insufficient
				   space below (less than 80px). */
				var spaceBelow = vh - r.bottom - gap;
				var spaceAbove = r.top  - gap;
				var openAbove  = (spaceBelow < 80) ? (spaceAbove > spaceBelow) : false;

				panel.style.width    = pw + 'px';
				panel.style.left     = leftEdge + 'px';
				panel.style.right    = '';

				if (openAbove) {
					panel.style.bottom   = (vh - r.top + gap) + 'px';
					panel.style.top      = '';
					panel.style.maxHeight = Math.max(spaceAbove - 8, 60) + 'px';
				} else {
					panel.style.top      = (r.bottom + gap) + 'px';
					panel.style.bottom   = '';
					panel.style.maxHeight = Math.max(Math.min(spaceBelow - 8, maxH), 60) + 'px';
				}
			}

			function openPanel() {
				panel.hidden = false;
				positionPanel();
				btn.setAttribute('aria-expanded', 'true');
				var first = panel.querySelector('a');
				if (first) first.focus();
			}

			function closePanel() {
				panel.hidden = true;
				btn.setAttribute('aria-expanded', 'false');
				btn.focus();
			}

			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				panel.hidden ? openPanel() : closePanel();
			});

			var closeBtn = panel.querySelector('.lsflr-panel-close');
			if (closeBtn) {
				closeBtn.addEventListener('click', function(e) {
					e.stopPropagation();
					closePanel();
				});
			}

			/* Close on outside click */
			document.addEventListener('click', function(e) {
				if (!panel.hidden) { if (!wrap.contains(e.target)) closePanel(); }
			});

			/* Close on Escape; trap Tab inside open panel */
			document.addEventListener('keydown', function(e) {
				if (panel.hidden) return;
				if (e.key === 'Escape') {
					e.preventDefault();
					closePanel();
					return;
				}
				if (e.key === 'Tab') {
					var focusable = Array.prototype.slice.call(
						panel.querySelectorAll('a, button:not([hidden])')
					);
					if (!focusable.length) return;
					var first = focusable[0];
					var last  = focusable[focusable.length - 1];
					if (e.shiftKey) {
						if (document.activeElement === first) {
							e.preventDefault();
							last.focus();
						}
					} else {
						if (document.activeElement === last) {
							e.preventDefault();
							first.focus();
						}
					}
				}
			});

			/* Reposition on scroll / resize so panel tracks the trigger */
			window.addEventListener('resize', function() {
				if (!panel.hidden) positionPanel();
			});
			window.addEventListener('scroll', function() {
				if (!panel.hidden) positionPanel();
			}, { passive: true });

			/* Write lf_lang cookie before navigation */
			panel.addEventListener('click', function(e) {
				var a = e.target.closest('a[lang]');
				if (!a) return;
				writeCookie(a.getAttribute('lang'));
			});

			<?php if ( $overlay_mode === 'auto' ) : ?>
			/* auto mode: switch to dropdown-style when container is wide enough.
			   The panel-grid now lists $others only (current language excluded, to
			   match the classic dropdown's submenu), so the width heuristic sizes
			   against that same count. Skipped entirely when there are zero other
			   languages (e.g. secondary languages are configured site-wide but this
			   post has no translated siblings yet) — otherwise the trigger would
			   hide in favour of an empty panel with nothing to switch to. */
			if ('ResizeObserver' in window && <?php echo count( $others ); ?> > 0) {
				var ro = new ResizeObserver(function(entries) {
					var containerWidth = entries[0].contentRect.width;
					var langCount = <?php echo count( $others ); ?>;
					/* Heuristic: ~7em per language label at current font size */
					var neededWidth = langCount * 7 * parseFloat(getComputedStyle(document.documentElement).fontSize);
					wrap.classList.toggle('lsflr-overlay-auto-expanded', containerWidth >= neededWidth);
				});
				ro.observe(wrap.parentElement || wrap);
			}
			<?php endif; ?>
		})();
		</script>

		<?php else :
			$dir = ( $atts['direction'] === 'up' ) ? 'lsflr-dropup' : 'lsflr-dropdown';
			?>

		<ul id="<?php echo esc_attr( $switcher_id ); ?>" class="lsflr-switcher <?php echo esc_attr( $dir ); ?>" title="<?php esc_attr_e( 'Language Switcher', 'lingua-forge' ); ?>">
			<li class="lsflr-toggle" tabindex="0">

				<div class="lsflr-current"><?php echo wp_kses( $toggle, [
						'svg'  => [ 'xmlns' => true, 'viewbox' => true ],
						'path' => [ 'd' => true, 'fill' => true ],
						'span' => [ 'class' => true ],
					] ); ?></div>

				<?php if ( $others ) : ?>
				<ul class="lsflr-submenu">
					<?php foreach ( $others as $lang ) : ?>
						<li>
							<a href="<?php echo esc_url( $lang['url'] ); ?>" lang="<?php echo esc_attr( $lang['code'] ); ?>">
								<?php echo esc_html( $lang['label'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>

			</li>
		</ul>

		<script>
		(function(){
			var el = document.getElementById('<?php echo esc_js( $switcher_id ); ?>');
			if (!el) return;

			// Write lf_lang cookie before navigating so that detect_lang_safe() sees
			// the correct language on the very first request to the target URL.
			// This is critical when switching back to the source language whose front
			// page lives at / — without the cookie, a stale non-source cookie would
			// cause handle_init_redirects() to redirect the visitor back immediately.
			el.addEventListener('click', function(e){
				var a = e.target.closest('a[lang]');
				if (!a) return;
				var code         = a.getAttribute('lang');
				var secure       = <?php echo is_ssl() ? 'true' : 'false'; ?>;
				var maxAge       = <?php echo (int) MONTH_IN_SECONDS; ?>;
				var cookieDomain = '<?php echo esc_js( $this->router->context->routing_mode() === 'subdomain'
					? '.' . $this->router->context->base_domain()
					: (string) wp_parse_url( home_url(), PHP_URL_HOST )
				); ?>';
				document.cookie = 'lf_lang=' + encodeURIComponent(code) +
					'; path=/' +
					'; max-age=' + maxAge +
					'; domain=' + cookieDomain +
					( secure ? '; secure' : '' ) +
					'; samesite=lax';
			});

			function reposition(){
				var sub = el.querySelector('.lsflr-submenu');
				if (!sub) return;
				/* Reset first so we measure the natural position */
				el.classList.remove('lsflr-auto-right');
				var r = sub.getBoundingClientRect();
				if (r.right > (window.innerWidth || document.documentElement.clientWidth) - 8) {
					el.classList.add('lsflr-auto-right');
				}
			}
			reposition();
			window.addEventListener('resize', reposition, { passive: true });
		})();
		</script>

		<?php endif;

		$html = ob_get_clean();

		/**
		 * Filters the rendered language-switcher HTML.
		 *
		 * Allows themes and third-party plugins to wrap, modify, or replace the
		 * switcher output without overriding the block render callback.
		 * Applies to all three entry points: block, shortcode, and classic widget.
		 *
		 * @param string $html    The fully-rendered switcher HTML.
		 * @param array  $langs   Language entries: each has keys `code`, `url`, `label`, `current`.
		 * @param array  $atts    Resolved attributes: `direction`, `show`, `customLabel`, `iconHtml`, `overlayMode`.
		 */
		return (string) apply_filters( 'linguaforge_switcher_output', $html, $langs, $atts );
	}

	// =========================================================
	// BLOCK REGISTRATION
	// =========================================================

	public function register_block(): void {
		wp_register_script(
			'lsflr-switcher-editor',
			LINGUAFORGE_URL . 'language-router/assets/editor-switcher.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ],
			defined( 'LINGUAFORGE_VERSION' ) ? LINGUAFORGE_VERSION : false,
			true
		);

		register_block_type( 'custom/lsflr-switcher', [
			'editor_script_handles' => [ 'lsflr-switcher-editor' ],
			'render_callback'       => [ $this, 'render_switcher' ],
		] );
	}

	// =========================================================
	// SHORTCODE
	// =========================================================

	public function register_shortcode(): void {
		add_shortcode( 'lsflr_switcher', [ $this, 'render_switcher' ] );
	}

	// =========================================================
	// CLASSIC WIDGET
	// =========================================================

	public function register_widget(): void {
		register_widget( 'Lsflr_Switcher_Widget' );
	}
}


