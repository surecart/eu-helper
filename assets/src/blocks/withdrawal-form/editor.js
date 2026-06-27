/**
 * Withdrawal Request Form — block editor registration.
 *
 * Server-rendered (dynamic): save() returns null; edit() shows a static preview
 * plus the text-override controls. The live form renders the same markup
 * server-side (render.php).
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AttrField from '../shared/field';

const DEFAULTS = {
	heading: __( 'Withdraw from a purchase', 'surecart-eu-helper' ),
	intro: __(
		'Enter the email you ordered with and your order number to start a withdrawal request.',
		'surecart-eu-helper'
	),
	submit_label: __( 'Find my order', 'surecart-eu-helper' ),
	confirm_label: __( 'Confirm withdrawal', 'surecart-eu-helper' ),
	success_message: __(
		'Thank you. Your withdrawal request has been received…',
		'surecart-eu-helper'
	),
};

// One SureCart-style form-control field (label + input wrapper) for the static
// preview; the live form renders the same markup server-side.
function PreviewField( { label, type } ) {
	return (
		<div className="sceu-form-control sceu-form-control--has-label">
			<label className="sceu-form-control__label">{ label }</label>
			<div className="sceu-form-control__input">
				<input type={ type } className="sceu-wf__input" disabled />
			</div>
		</div>
	);
}

registerBlockType( 'surecart-eu-helper/withdrawal-form', {
	edit( { attributes: a, setAttributes: set } ) {
		const blockProps = useBlockProps( {
			className: 'sceu-wf sceu-wf-editor-preview',
		} );

		const field = ( attr, label, textarea ) => (
			<AttrField
				attr={ attr }
				label={ label }
				attributes={ a }
				setAttributes={ set }
				defaults={ DEFAULTS }
				textarea={ textarea }
			/>
		);

		return (
			<Fragment>
				<InspectorControls>
					<PanelBody
						title={ __( 'Form text', 'surecart-eu-helper' ) }
						initialOpen={ true }
					>
						{ field( 'heading', __( 'Heading', 'surecart-eu-helper' ) ) }
						{ field( 'intro', __( 'Intro text', 'surecart-eu-helper' ), true ) }
						{ field( 'submit_label', __( 'Lookup button label', 'surecart-eu-helper' ) ) }
						{ field( 'confirm_label', __( 'Confirm button label', 'surecart-eu-helper' ) ) }
						{ field( 'success_message', __( 'Success message', 'surecart-eu-helper' ), true ) }
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<h3 className="sceu-wf__heading">
						{ a.heading || DEFAULTS.heading }
					</h3>
					<p className="sceu-wf__intro">{ a.intro || DEFAULTS.intro }</p>
					<PreviewField
						label={ __( 'Email address', 'surecart-eu-helper' ) }
						type="email"
					/>
					<PreviewField
						label={ __( 'Order number', 'surecart-eu-helper' ) }
						type="text"
					/>
					<button type="button" className="sceu-wf__submit" disabled>
						{ a.submit_label || __( 'Find my order', 'surecart-eu-helper' ) }
					</button>
					<p className="sceu-wf__preview-note">
						{ __(
							'Preview — the live form looks up the order and lets the customer withdraw.',
							'surecart-eu-helper'
						) }
					</p>
				</div>
			</Fragment>
		);
	},
	save: () => null,
} );
