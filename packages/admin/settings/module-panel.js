/**
 * A module's panel: enable toggle, disclaimer, and its fields grouped by section.
 */
import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Field from './field';

export default function ModulePanel({ module, values, enabled, onField, onEnable }) {
	const sections = module.sections || {};
	const grouped = {};
	(module.fields || []).forEach((f) => {
		const s = f.section || '_default';
		(grouped[s] = grouped[s] || []).push(f);
	});
	const order = Object.keys(sections);
	Object.keys(grouped).forEach((k) => {
		if (!order.includes(k)) {
			order.push(k);
		}
	});

	return (
		<section className="sceu-panel is-active">
			<div className="sceu-panel__head">
				<h2 className="sceu-panel__title">{module.label}</h2>
			</div>
			{module.description && (
				<p className="sceu-panel__desc">{module.description}</p>
			)}
			{module.disclaimer && (
				<p className="sceu-card__note">
					<strong>{__('Your responsibility', 'surecart-eu-helper')}:</strong>{' '}
					{module.disclaimer}
				</p>
			)}

			<div className="sceu-card sceu-card--compact">
				<ToggleControl
					label={__('Enable module', 'surecart-eu-helper')}
					checked={!!enabled}
					onChange={onEnable}
				/>
			</div>

			{order.map((skey) => {
				if (!grouped[skey]) {
					return null;
				}
				const sec = sections[skey] || {};
				return (
					<div key={skey}>
						{sec.title && (
							<div className="sceu-section__head">
								<h3 className="sceu-section__title">{sec.title}</h3>
								{sec.description && (
									<p className="sceu-section__desc">{sec.description}</p>
								)}
							</div>
						)}
						<div className="sceu-card">
							{grouped[skey].map((field) => (
								<div className="sceu-field" key={field.key}>
									<Field
										field={field}
										value={values[field.key]}
										moduleValues={values}
										onChange={onField}
									/>
								</div>
							))}
						</div>
					</div>
				);
			})}
		</section>
	);
}
