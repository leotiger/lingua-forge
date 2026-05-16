<?php
/**
 * Class LSFLR_Switcher
 *
 * Language Switcher block and renderer.
 * Depends on Language_Router for data resolution.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSFLR_Switcher {

	private Language_Router $router;

	public function __construct( Language_Router $router ) {
		$this->router = $router;
		$this->register_hooks();
	}

	private function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'init', [ $this, 'register_block' ] );
	}

	// =========================================================
	// ASSETS
	// =========================================================

	public function enqueue_styles(): void {
		wp_enqueue_style(
			'lsflr',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/lsflr.css',
			[],
			'1.1.9'
		);
	}

	// =========================================================
	// DATA
	// =========================================================

	public function get_languages(): array {
		$post_id = get_the_ID();
		if ( ! $post_id ) return [];

		$translations = $this->router->get_translations( $post_id );
		if ( empty( $translations ) ) return [];

		$langs = [];

		foreach ( $translations as $lang => $id ) {
			// Only published posts
			if ( get_post_status( $id ) !== 'publish' ) continue;

			$langs[] = [
				'code'    => $lang,
				'url'     => $this->translate_current_url( $lang, $id ),
				'label'   => $this->router->language_label( $lang ),
				'current' => ( $lang === LF_LANG ),
			];
		}

		return $langs;
	}

	public function translate_current_url( string $target_lang, ?int $post_id = null ): string {
		$current_url = home_url( $_SERVER['REQUEST_URI'] );
		$langs       = $this->router->languages();
		$source      = $this->router->source_language();

		$parsed  = parse_url( $current_url );
		$path    = trim( $parsed['path'] ?? '', '/' );
		$query   = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

		$segments = explode( '/', $path );
		if ( ! empty( $segments[0] ) && in_array( $segments[0], $langs ) ) {
			array_shift( $segments );
		}

		$new_path = implode( '/', $segments );

		// Search
		if ( is_search() ) {
			$s = get_query_var( 's' );
			return home_url( '/?lang=' . $target_lang . '&s=' . urlencode( $s ) );
		}

		// Singular
		if ( is_singular() && $post_id ) {
			$url = get_permalink( $post_id );
			return $url . $query;
		}

		// Non-singular
		if ( $target_lang === $source ) {
			return home_url( '/' . trim( $new_path, '/' ) . '/' ) . $query;
		}

		return home_url( '/' . $target_lang . '/' . $new_path . '/' ) . $query;
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
			$toggle = esc_html( $atts['customLabel'] );
		} elseif ( $atts['show'] === 'icon' ) {
			$toggle = $get_icon( $atts['iconHtml'] );
		} elseif ( $atts['show'] === 'icon-label' ) {
			$toggle =
				'<span class="lsflr-icon">' . $get_icon( $atts['iconHtml'] ) . '</span>' .
				'<span class="lsflr-label">' . esc_html( $current['label'] ) . '</span>';
		} else {
			$toggle = esc_html( $current['label'] );
		}

		$dir = ( $atts['direction'] === 'up' ) ? 'lsflr-dropup' : 'lsflr-dropdown';

		ob_start(); ?>

		<ul class="lsflr-switcher <?php echo esc_attr( $dir ); ?>">
			<li class="lsflr-toggle" tabindex="0">

				<div class="lsflr-current"><?php echo $toggle; ?></div>

				<?php if ( $others ) : ?>
				<ul class="lsflr-submenu">
					<?php foreach ( $others as $lang ) : ?>
						<li>
							<a href="<?php echo esc_url( $lang['url'] ); ?>">
								<?php echo esc_html( $lang['label'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>

			</li>
		</ul>

		<?php
		return ob_get_clean();
	}

	// =========================================================
	// BLOCK REGISTRATION
	// =========================================================

	public function register_block(): void {
		wp_register_script(
			'lsflr-switcher-editor',
			'',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ],
			null,
			true
		);

		wp_add_inline_script( 'lsflr-switcher-editor', "
			(function(wp){
				const { registerBlockType } = wp.blocks;
				const { createElement: el } = wp.element;
				const { InspectorControls } = wp.blockEditor;
				const { PanelBody, SelectControl, TextControl } = wp.components;

				registerBlockType('custom/lsflr-switcher', {
					apiVersion: 3,
					title: 'LSFLR Switcher',
					icon: 'translation',
					category: 'widgets',

					attributes: {
						direction:   { type: 'string', default: 'down' },
						show:        { type: 'string', default: 'label' },
						customLabel: { type: 'string', default: 'Language' },
						iconHtml:    { type: 'string', default: '🌐' }
					},

					edit: function(props) {
						const { attributes, setAttributes } = props;
						const blockProps = wp.blockEditor.useBlockProps({
							style:{
								padding:'10px',
								border:'1px dashed #ccc',
								background:'#f9f9f9',
								cursor:'pointer'
							}
						});

						return el('div', blockProps,
							el(InspectorControls, {},
								el(PanelBody, { title: 'Settings' },
									el(SelectControl, {
										label: 'Direction',
										value: attributes.direction,
										options: [
											{ label: 'Dropdown', value: 'down' },
											{ label: 'Dropup',   value: 'up' }
										],
										onChange: function(v){ setAttributes({ direction: v }); }
									}),
									el(SelectControl, {
										label: 'Toggle Display',
										value: attributes.show,
										options: [
											{ label: 'Current language', value: 'label' },
											{ label: 'Custom label',     value: 'custom' },
											{ label: 'Icon only',        value: 'icon' },
											{ label: 'Icon + language',  value: 'icon-label' }
										],
										onChange: function(v){ setAttributes({ show: v }); }
									}),
									attributes.show === 'custom' &&
									el(TextControl, {
										label: 'Custom label',
										value: attributes.customLabel,
										onChange: function(v){ setAttributes({ customLabel: v }); }
									}),
									(attributes.show === 'icon' || attributes.show === 'icon-label') &&
									el(TextControl, {
										label: 'Icon (emoji or SVG)',
										value: attributes.iconHtml,
										onChange: function(v){ setAttributes({ iconHtml: v }); }
									})
								)
							),
							el('div', {}, 'LSFLR Switcher')
						);
					},

					save: function(){ return null; }
				});

			})(window.wp);
		" );

		register_block_type( 'custom/lsflr-switcher', [
			'editor_script'   => 'lsflr-switcher-editor',
			'render_callback' => [ $this, 'render_switcher' ],
		] );
	}
}
