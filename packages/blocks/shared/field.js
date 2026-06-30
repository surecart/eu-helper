/**
 * Inspector text field bound to a block attribute.
 *
 * Shared by both blocks' editors: shows the live default as the placeholder so
 * an empty attribute renders the same copy the server falls back to. `textarea`
 * switches to a multi-line control.
 */
import { TextControl, TextareaControl } from '@wordpress/components';

export default function AttrField({
	attr,
	label,
	attributes,
	setAttributes,
	defaults,
	textarea = false,
}) {
	const Control = textarea ? TextareaControl : TextControl;

	return (
		<Control
			label={label}
			value={attributes[attr] || ''}
			placeholder={defaults[attr]}
			onChange={(value) => setAttributes({ [attr]: value })}
		/>
	);
}
