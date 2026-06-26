/**
 * Withdrawal Request Form — block editor registration.
 *
 * No build step (global `wp` packages). Server-rendered (dynamic), so save()
 * returns null; edit() shows a static preview plus text-override controls.
 */
( function ( wp ) {
	"use strict";

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var c = wp.components;

	var DEFAULTS = {
		heading: __( "Withdraw from a purchase", "surecart-eu-helper" ),
		intro: __( "Enter the email you ordered with and your order number to start a withdrawal request.", "surecart-eu-helper" )
	};

	registerBlockType( "surecart-eu-helper/withdrawal-form", {
		edit: function ( props ) {
			var a = props.attributes;
			var setA = props.setAttributes;
			var blockProps = useBlockProps( { className: "sceu-wf-editor-preview" } );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( "Form text", "surecart-eu-helper" ), initialOpen: true },
						el( c.TextControl, {
							label: __( "Heading", "surecart-eu-helper" ),
							value: a.heading,
							placeholder: DEFAULTS.heading,
							onChange: function ( v ) { setA( { heading: v } ); }
						} ),
						el( c.TextareaControl, {
							label: __( "Intro text", "surecart-eu-helper" ),
							value: a.intro,
							placeholder: DEFAULTS.intro,
							onChange: function ( v ) { setA( { intro: v } ); }
						} ),
						el( c.TextControl, {
							label: __( "Lookup button label", "surecart-eu-helper" ),
							value: a.submit_label,
							placeholder: __( "Find my order", "surecart-eu-helper" ),
							onChange: function ( v ) { setA( { submit_label: v } ); }
						} ),
						el( c.TextControl, {
							label: __( "Confirm button label", "surecart-eu-helper" ),
							value: a.confirm_label,
							placeholder: __( "Confirm withdrawal", "surecart-eu-helper" ),
							onChange: function ( v ) { setA( { confirm_label: v } ); }
						} ),
						el( c.TextareaControl, {
							label: __( "Success message", "surecart-eu-helper" ),
							value: a.success_message,
							placeholder: __( "Thank you. Your withdrawal request has been received…", "surecart-eu-helper" ),
							onChange: function ( v ) { setA( { success_message: v } ); }
						} )
					)
				),
				el(
					"div",
					blockProps,
					el( "h3", { style: { margin: "0 0 0.4em" } }, a.heading || DEFAULTS.heading ),
					el( "p", { style: { margin: "0 0 1em" } }, a.intro || DEFAULTS.intro ),
					el( "p", null, el( "strong", null, __( "Email address", "surecart-eu-helper" ) ) ),
					el( "input", { type: "email", disabled: true, style: { width: "100%", marginBottom: "0.6em", padding: "0.5em" } } ),
					el( "p", null, el( "strong", null, __( "Order number", "surecart-eu-helper" ) ) ),
					el( "input", { type: "text", disabled: true, style: { width: "100%", marginBottom: "0.8em", padding: "0.5em" } } ),
					el( "button", { type: "button", disabled: true }, a.submit_label || __( "Find my order", "surecart-eu-helper" ) ),
					el( "p", { style: { fontSize: "0.85em", opacity: 0.7, marginTop: "0.8em" } }, __( "Preview — the live form looks up the order and lets the customer withdraw.", "surecart-eu-helper" ) )
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
