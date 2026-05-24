/**
 * Lingua Forge — Missing Translation Notice block: editor component.
 *
 * Registers the `edit` function for the lingua-forge/missing-translation-notice
 * block so editors can change the three block attributes from the sidebar:
 *
 *   • messageText    — the notice paragraph text
 *   • showHomeLink   — toggle for the home-link paragraph
 *   • homeLinkText   — the home-link anchor text (visible only when toggle is on)
 *
 * The editor canvas shows a live server-side-rendered preview via
 * wp.serverSideRender so the block's appearance (colour, spacing, typography
 * block-supports values) is always accurate.  The render.php callback already
 * emits the block unconditionally when REST_REQUEST is true and the current
 * user can edit_posts, so no extra REST-context flag is needed.
 *
 * No build step — uses wp.* globals declared in index.asset.php.
 */

( function () {
	'use strict';

	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var __                = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var TextControl       = wp.components.TextControl;
	var ToggleControl     = wp.components.ToggleControl;
	var ServerSideRender  = wp.serverSideRender;

	registerBlockType( 'lingua-forge/missing-translation-notice', {

		/**
		 * Edit component — renders the sidebar controls and a live preview.
		 *
		 * @param {Object} props            Standard block props.
		 * @param {Object} props.attributes Block attribute values.
		 * @param {Function} props.setAttributes Attribute setter.
		 */
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps    = useBlockProps();

			return el(
				Fragment,
				null,

				// ── Sidebar inspector controls ────────────────────────────
				el( InspectorControls, null,
					el( PanelBody,
						{
							title: __( 'Notice settings', 'lingua-forge' ),
							initialOpen: true,
						},

						el( TextControl,
							{
								label:    __( 'Notice message', 'lingua-forge' ),
								help:     __( 'Shown when no translation exists for the visitor\'s language.', 'lingua-forge' ),
								value:    attributes.messageText,
								onChange: function ( val ) {
									setAttributes( { messageText: val } );
								},
							}
						),

						el( ToggleControl,
							{
								label:    __( 'Show home link', 'lingua-forge' ),
								help:     __( 'Adds a link to the home page so visitors can browse in their language.', 'lingua-forge' ),
								checked:  attributes.showHomeLink,
								onChange: function ( val ) {
									setAttributes( { showHomeLink: val } );
								},
							}
						),

						// Home-link text field — only visible when the toggle is on.
						attributes.showHomeLink && el( TextControl,
							{
								label:    __( 'Home link text', 'lingua-forge' ),
								value:    attributes.homeLinkText,
								onChange: function ( val ) {
									setAttributes( { homeLinkText: val } );
								},
							}
						)
					)
				),

				// ── Editor canvas preview (server-side rendered) ──────────
				el( 'div', blockProps,
					el( ServerSideRender,
						{
							block:      'lingua-forge/missing-translation-notice',
							attributes: attributes,
						}
					)
				)
			);
		},

		// Server-rendered — save is a no-op; PHP render.php owns the markup.
		save: function () {
			return null;
		},
	} );
} )();
