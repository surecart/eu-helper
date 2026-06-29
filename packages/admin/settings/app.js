/**
 * Root settings container. Holds the per-module value/enabled state, composes the
 * left nav + the active panel (module or uninstall), and persists everything
 * through the REST endpoint (which runs the shared SettingsSanitizer).
 */
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { boot, MODULES } from './boot';
import SettingsNav from './nav';
import ModulePanel from './module-panel';
import UninstallPanel from './uninstall-panel';

export default function SettingsApp() {
	const [values, setValues] = useState(() => {
		const v = {};
		MODULES.forEach((m) => {
			v[m.id] = { ...m.values };
		});
		return v;
	});
	const [enabled, setEnabled] = useState(() => {
		const e = {};
		MODULES.forEach((m) => {
			e[m.id] = !!m.enabled;
		});
		return e;
	});
	const [removeData, setRemoveData] = useState(!!boot.removeData);
	const [active, setActive] = useState(
		MODULES.length ? MODULES[0].id : 'uninstall'
	);
	const [dirty, setDirty] = useState(false);
	const [saving, setSaving] = useState(false);
	const [notice, setNotice] = useState(null);

	const onField = (moduleId, key, val) => {
		setValues((prev) => ({
			...prev,
			[moduleId]: { ...prev[moduleId], [key]: val },
		}));
		setDirty(true);
	};

	const save = async () => {
		setSaving(true);
		setNotice(null);
		const settings = { modules: {}, remove_data: removeData };
		MODULES.forEach((m) => {
			settings.modules[m.id] = !!enabled[m.id];
			settings[m.id] = { ...values[m.id] };
		});
		try {
			await apiFetch({
				path: boot.restPath,
				method: 'POST',
				data: { settings },
			});
			setDirty(false);
			setNotice({ status: 'success', msg: __('Settings saved.', 'surecart-eu-helper') });
		} catch (e) {
			setNotice({
				status: 'error',
				msg: __('Could not save settings. Please try again.', 'surecart-eu-helper'),
			});
		}
		setSaving(false);
	};

	const activeModule = MODULES.find((m) => m.id === active);

	return (
		<div className="sceu-app__body">
			<SettingsNav modules={MODULES} active={active} onSelect={setActive} />

			<div className="sceu-app__content">
				<div className="sceu-app__inner">
					{notice && (
						<Notice status={notice.status} onRemove={() => setNotice(null)}>
							{notice.msg}
						</Notice>
					)}

					{activeModule && (
						<ModulePanel
							module={activeModule}
							values={values[activeModule.id] || {}}
							enabled={enabled[activeModule.id]}
							onField={(key, val) => onField(activeModule.id, key, val)}
							onEnable={(on) => {
								setEnabled((prev) => ({ ...prev, [activeModule.id]: on }));
								setDirty(true);
							}}
						/>
					)}

					{'uninstall' === active && (
						<UninstallPanel
							removeData={removeData}
							onChange={(on) => {
								setRemoveData(on);
								setDirty(true);
							}}
						/>
					)}

					<div className="sceu-app__actions">
						<Button variant="primary" onClick={save} disabled={saving || !dirty}>
							{saving && <Spinner />}
							{saving ? __('Saving…', 'surecart-eu-helper') : __('Save', 'surecart-eu-helper')}
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
}
