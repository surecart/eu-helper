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
			var blockProps = useBlockProps( { className: "sceu-wf sceu-wf-editor-preview" } );

			// One SureCart-style form-control field (label + input wrapper) for the
			// static preview; the live form renders the same markup server-side.
			function previewField( labelText, type ) {
				return el(
					"div",
					{ className: "sceu-form-control sceu-form-control--has-label" },
					el( "label", { className: "sceu-form-control__label" }, labelText ),
					el(
						"div",
						{ className: "sceu-form-control__input" },
						el( "input", { type: type, className: "sceu-wf__input", disabled: true } )
					)
				);
			}

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
					el( "h3", { className: "sceu-wf__heading" }, a.heading || DEFAULTS.heading ),
					el( "p", { className: "sceu-wf__intro" }, a.intro || DEFAULTS.intro ),
					previewField( __( "Email address", "surecart-eu-helper" ), "email" ),
					previewField( __( "Order number", "surecart-eu-helper" ), "text" ),
					el( "button", { type: "button", className: "sceu-wf__submit", disabled: true }, a.submit_label || __( "Find my order", "surecart-eu-helper" ) ),
					el( "p", { className: "sceu-wf__preview-note" }, __( "Preview — the live form looks up the order and lets the customer withdraw.", "surecart-eu-helper" ) )
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp );
