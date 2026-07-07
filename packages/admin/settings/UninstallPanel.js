/**
 * Uninstall panel: a single toggle to remove all plugin data on deletion.
 * Rendered as the "uninstall" tab's content, beside ModulePanel.
 */
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SaveButton from './SaveButton';

export default function UninstallPanel({
	removeData,
	onChange,
	onSave,
	saving,
	dirty,
}) {
	return (
		<section className="sceu-panel is-active">
			<div className="sceu-panel__head">
				<h2 className="sceu-panel__title">
					{__('Uninstall', 'surecart-eu-helper')}
				</h2>
				<SaveButton onClick={onSave} saving={saving} dirty={dirty} />
			</div>
			<div className="sceu-card sceu-card--compact">
				<ToggleControl
					label={__('Remove Plugin Data', 'surecart-eu-helper')}
					help={__(
						'Completely remove all plugin data — settings and the withdrawal-request log — when the plugin is deleted. This cannot be undone.',
						'surecart-eu-helper'
					)}
					checked={removeData}
					onChange={onChange}
				/>
			</div>
		</section>
	);
}
