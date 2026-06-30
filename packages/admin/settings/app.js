/**
 * Root settings container. Holds the per-module value/enabled state, composes the
 * left nav + the active panel (module or uninstall), and persists everything
 * through the REST endpoint (which runs the shared SettingsSanitizer).
 */
import { useState, useEffect } from '@wordpress/element';
import { NoticeList, SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { boot, MODULES } from './boot';
import SaveButton from './SaveButton';
import SettingsNav from './Nav';
import ModulePanel from './ModulePanel';
import UninstallPanel from './UninstallPanel';

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
	// Mirror the active tab in the URL hash so a reload restores it.
	const [active, setActive] = useState(() => {
		const hash = window.location.hash.replace(/^#/, '');
		const ids = [...MODULES.map((m) => m.id), 'uninstall'];
		if (ids.includes(hash)) {
			return hash;
		}
		return MODULES.length ? MODULES[0].id : 'uninstall';
	});
	const selectTab = (id) => {
		setActive(id);
		if (window.location.hash !== `#${id}`) {
			window.history.replaceState(null, '', `#${id}`);
		}
	};
	const [dirty, setDirty] = useState(false);
	const [saving, setSaving] = useState(false);

	// Notices use WP's core/notices store (stack, dedupe by id, dismiss).
	const { createSuccessNotice, createErrorNotice, removeNotice } =
		useDispatch(noticesStore);
	const notices = useSelect(
		(select) => select(noticesStore).getNotices(),
		[]
	);
	// Snackbars float as a toast near the action; everything else renders inline.
	const snackbarNotices = notices.filter((n) => 'snackbar' === n.type);
	const inlineNotices = notices.filter((n) => 'snackbar' !== n.type);

	// Warn before leaving with unsaved edits (mirrors the old settings shell).
	useEffect(() => {
		if (!dirty) {
			return undefined;
		}
		const onBeforeUnload = (e) => {
			e.preventDefault();
			e.returnValue = '';
		};
		window.addEventListener('beforeunload', onBeforeUnload);
		return () => window.removeEventListener('beforeunload', onBeforeUnload);
	}, [dirty]);

	const onField = (moduleId, key, val) => {
		setValues((prev) => ({
			...prev,
			[moduleId]: { ...prev[moduleId], [key]: val },
		}));
		setDirty(true);
	};

	const save = async () => {
		setSaving(true);
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
			createSuccessNotice(__('Settings saved.', 'surecart-eu-helper'), {
				id: 'sceu-save-result',
				type: 'snackbar',
			});
		} catch (e) {
			createErrorNotice(
				__(
					'Could not save settings. Please try again.',
					'surecart-eu-helper'
				),
				{
					id: 'sceu-save-result',
					type: 'snackbar',
					explicitDismiss: true,
				}
			);
		}
		setSaving(false);
	};

	const activeModule = MODULES.find((m) => m.id === active);

	return (
		<div className="sceu-app__body">
			<SettingsNav
				modules={MODULES}
				active={active}
				onSelect={selectTab}
			/>

			<div className="sceu-app__content">
				<div className="sceu-app__inner">
					{!!inlineNotices.length && (
						<NoticeList
							notices={inlineNotices}
							onRemove={removeNotice}
							className="sceu-app__notices"
						/>
					)}

					{activeModule && (
						<ModulePanel
							module={activeModule}
							values={values[activeModule.id] || {}}
							enabled={enabled[activeModule.id]}
							onSave={save}
							saving={saving}
							dirty={dirty}
							onField={(key, val) =>
								onField(activeModule.id, key, val)
							}
							onEnable={(on) => {
								setEnabled((prev) => ({
									...prev,
									[activeModule.id]: on,
								}));
								setDirty(true);
							}}
						/>
					)}

					{'uninstall' === active && (
						<UninstallPanel
							removeData={removeData}
							onSave={save}
							saving={saving}
							dirty={dirty}
							onChange={(on) => {
								setRemoveData(on);
								setDirty(true);
							}}
						/>
					)}

					<div className="sceu-app__actions">
						<SaveButton
							onClick={save}
							saving={saving}
							dirty={dirty}
						/>
					</div>
				</div>
			</div>

			<SnackbarList
				notices={snackbarNotices}
				onRemove={removeNotice}
				className="sceu-app__snackbars"
			/>
		</div>
	);
}
