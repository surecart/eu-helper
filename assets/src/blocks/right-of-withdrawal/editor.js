/**
 * Right of Withdrawal — block editor registration.
 *
 * Server-rendered (dynamic) block: save() returns null and render.php draws the
 * front end. edit() shows a static preview plus the text-override controls in
 * the inspector. All visible defaults are translatable so a translation plugin
 * can localize per country.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AttrField from '../shared/field';

const DEFAULTS = {
	heading: __( 'Right of Withdrawal', 'surecart-eu-helper' ),
	intro: __(
		'You have the right to withdraw from your purchase within 14 days of receiving your order.',
		'surecart-eu-helper'
	),
	buttonLabel: __( 'Withdraw from contract', 'surecart-eu-helper' ),
	modalTitle: __( 'Request a withdrawal', 'surecart-eu-helper' ),
	confirmButtonLabel: __( 'Confirm withdrawal', 'surecart-eu-helper' ),
	confirmationMessage: __(
		'Thank you. Your withdrawal request has been received and a confirmation has been emailed to you.',
		'surecart-eu-helper'
	),
};

// Outline-style shield icon to match the WordPress inserter icon set (line
// drawing, currentColor), rather than a solid Dashicon.
const icon = (
	<svg
		viewBox="0 0 24 24"
		width={ 24 }
		height={ 24 }
		xmlns="http://www.w3.org/2000/svg"
	>
		<path
			fill="none"
			stroke="currentColor"
			strokeWidth={ 1.6 }
			strokeLinejoin="round"
			strokeLinecap="round"
			d="M12 3.25l6.75 2.4v5.05c0 4.3-2.88 7.42-6.75 8.8-3.87-1.38-6.75-4.5-6.75-8.8V5.65L12 3.25z"
		/>
	</svg>
);

registerBlockType( 'surecart-eu-helper/right-of-withdrawal', {
	icon,
	category: 'surecart-customer-dashboard',
	edit( { attributes: a, setAttributes: set } ) {
		const scheme = a.colorScheme || 'auto';
		const container = a.container === 'none' ? 'plain' : 'card';
		const blockProps = useBlockProps( {
			className: `sceu-row sceu-row--editor sceu-row--scheme-${ scheme } sceu-row--${ container }`,
		} );
		const HeadingTag = a.headingLevel || 'h3';

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
						title={ __( 'Withdrawal text', 'surecart-eu-helper' ) }
						initialOpen={ true }
					>
						{ field( 'heading', __( 'Heading', 'surecart-eu-helper' ) ) }
						<SelectControl
							label={ __( 'Heading style', 'surecart-eu-helper' ) }
							value={ a.headingLevel || 'h3' }
							options={ [
								{ value: 'h2', label: __( 'Heading 2 (large)', 'surecart-eu-helper' ) },
								{ value: 'h3', label: __( 'Heading 3', 'surecart-eu-helper' ) },
								{ value: 'h4', label: __( 'Heading 4', 'surecart-eu-helper' ) },
								{ value: 'h5', label: __( 'Heading 5', 'surecart-eu-helper' ) },
								{ value: 'h6', label: __( 'Heading 6', 'surecart-eu-helper' ) },
								{ value: 'p', label: __( 'Normal text', 'surecart-eu-helper' ) },
							] }
							onChange={ ( v ) => set( { headingLevel: v } ) }
						/>
						{ field( 'intro', __( 'Explanation', 'surecart-eu-helper' ), true ) }
						{ field( 'buttonLabel', __( 'Button label', 'surecart-eu-helper' ) ) }
						{ field( 'modalTitle', __( 'Form title', 'surecart-eu-helper' ) ) }
						{ field( 'confirmButtonLabel', __( 'Confirm button label', 'surecart-eu-helper' ) ) }
						{ field( 'confirmationMessage', __( 'Confirmation message', 'surecart-eu-helper' ), true ) }
					</PanelBody>
					<PanelBody
						title={ __( 'Appearance', 'surecart-eu-helper' ) }
						initialOpen={ false }
					>
						<SelectControl
							label={ __( 'Color scheme', 'surecart-eu-helper' ) }
							value={ a.colorScheme || 'auto' }
							options={ [
								{ value: 'auto', label: __( 'Auto (match theme)', 'surecart-eu-helper' ) },
								{ value: 'light', label: __( 'Light', 'surecart-eu-helper' ) },
								{ value: 'dark', label: __( 'Dark', 'surecart-eu-helper' ) },
							] }
							onChange={ ( v ) => set( { colorScheme: v } ) }
						/>
						<SelectControl
							label={ __( 'Container', 'surecart-eu-helper' ) }
							value={ a.container || 'card' }
							options={ [
								{ value: 'card', label: __( 'Card (bordered)', 'surecart-eu-helper' ) },
								{ value: 'none', label: __( 'Borderless', 'surecart-eu-helper' ) },
							] }
							onChange={ ( v ) => set( { container: v } ) }
						/>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<div className="sceu-row__notice">
						<div className="sceu-row__text">
							<HeadingTag className="sceu-row__heading">
								{ a.heading || DEFAULTS.heading }
							</HeadingTag>
							<p className="sceu-row__intro">
								{ a.intro || DEFAULTS.intro }
							</p>
						</div>
						<div className="sceu-row__actions">
							<button
								type="button"
								className="sceu-row__trigger sceu-btn sceu-btn--primary wp-element-button"
								disabled
							>
								{ a.buttonLabel || DEFAULTS.buttonLabel }
							</button>
						</div>
					</div>
					<p className="sceu-row__editor-note">
						{ __(
							'This block only appears for eligible EU consumers with recent orders. Visitors who do not qualify will not see it.',
							'surecart-eu-helper'
						) }
					</p>
				</div>
			</Fragment>
		);
	},
	save: () => null,
} );
