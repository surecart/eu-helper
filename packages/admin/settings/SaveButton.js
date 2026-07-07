/**
 * Shared Save button (panel headers + bottom bar). Disabled until there are
 * unsaved edits; shows a spinner while saving.
 */
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SaveButton({ onClick, saving, dirty }) {
	return (
		<Button variant="primary" onClick={onClick} disabled={saving || !dirty}>
			{saving && <Spinner />}
			{saving
				? __('Saving…', 'surecart-eu-helper')
				: __('Save', 'surecart-eu-helper')}
		</Button>
	);
}
