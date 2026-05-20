/**
 * Lingua Forge — LSFLR Switcher block editor registration.
 *
 * Registers `custom/lsflr-switcher` as a server-side rendered Gutenberg block
 * so editors can insert the language switcher from the block inserter under
 * the Widgets category. Inspector controls cover:
 *   - Direction (dropdown / dropup)
 *   - Toggle display (current language / custom label / icon / icon + label)
 *   - Custom label (when display = custom)
 *   - Icon HTML (when display includes icon)
 *
 * The actual rendering is done server-side by Switcher::render_switcher() via
 * the block's render_callback, so save() returns null. The editor preview is
 * a dashed-bordered placeholder labelled "LSFLR Switcher".
 *
 * Loaded via wp_enqueue_script() against the lsflr-switcher-editor handle,
 * which is then referenced as the block's `editor_script` in
 * register_block_type().
 */

( function ( wp ) {
	const { registerBlockType }       = wp.blocks;
	const { createElement: el }       = wp.element;
	const { InspectorControls }       = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl } = wp.components;

	registerBlockType( 'custom/lsflr-switcher', {
		apiVersion: 3,
		title:      'LSFLR Switcher',
		icon:       'translation',
		category:   'widgets',

		attributes: {
			direction:   { type: 'string', default: 'down' },
			show:        { type: 'string', default: 'label' },
			customLabel: { type: 'string', default: 'Language' },
			iconHtml:    { type: 'string', default: '🌐' }
		},

		edit: function ( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = wp.blockEditor.useBlockProps( {
				style: {
					padding:    '10px',
					border:     '1px dashed #ccc',
					background: '#f9f9f9',
					cursor:     'pointer'
				}
			} );

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: 'Settings' },
						el( SelectControl, {
							label:   'Direction',
							value:   attributes.direction,
							options: [
								{ label: 'Dropdown', value: 'down' },
								{ label: 'Dropup',   value: 'up' }
							],
							onChange: function ( v ) { setAttributes( { direction: v } ); }
						} ),
						el( SelectControl, {
							label:   'Toggle Display',
							value:   attributes.show,
							options: [
								{ label: 'Current language', value: 'label' },
								{ label: 'Custom label',     value: 'custom' },
								{ label: 'Icon only',        value: 'icon' },
								{ label: 'Icon + language',  value: 'icon-label' }
							],
							onChange: function ( v ) { setAttributes( { show: v } ); }
						} ),
						attributes.show === 'custom' &&
						el( TextControl, {
							label:    'Custom label',
							value:    attributes.customLabel,
							onChange: function ( v ) { setAttributes( { customLabel: v } ); }
						} ),
						( attributes.show === 'icon' || attributes.show === 'icon-label' ) &&
						el( TextControl, {
							label:    'Icon (emoji or SVG)',
							value:    attributes.iconHtml,
							onChange: function ( v ) { setAttributes( { iconHtml: v } ); }
						} )
					)
				),
				el( 'div', {}, 'LSFLR Switcher' )
			);
		},

		save: function () { return null; }
	} );

} )( window.wp );
