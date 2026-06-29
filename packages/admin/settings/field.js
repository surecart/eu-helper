/**
 * Render one schema field by type. The schema mirrors each module's PHP
 * settings_fields(); unknown types fall back to a plain text control.
 */
import {
	ToggleControl,
	TextControl,
	TextareaControl,
	SelectControl,
	RadioControl,
} from '@wordpress/components';
import { boot } from './boot';
import ProductExclusions from './controls/product-exclusions';
import CollectionExclusions from './controls/collection-exclusions';

export default function Field({ field, value, moduleValues, onChange }) {
	const { key, type, label, help, options = [] } = field;

	switch (type) {
		case 'toggle':
			return (
				<ToggleControl
					label={field.checkbox_label || label}
					help={help}
					checked={!!value}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'number':
			return (
				<TextControl
					type="number"
					label={label}
					help={help}
					min={field.min}
					value={value ?? ''}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'email':
			return (
				<TextControl
					type="email"
					label={label}
					help={help}
					placeholder={
						'merchant_email' === key ? boot.merchantEmailPlaceholder : undefined
					}
					value={value ?? ''}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'select':
			return (
				<SelectControl
					label={label}
					help={help}
					value={value ?? ''}
					options={options}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'radio':
			return (
				<RadioControl
					label={label}
					help={help}
					selected={value ?? ''}
					options={options}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					label={label}
					help={help}
					value={value ?? ''}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'collection_exclusions':
			return (
				<CollectionExclusions
					selected={Array.isArray(value) ? value : []}
					onChange={(v) => onChange(key, v)}
				/>
			);
		case 'product_exclusions':
			return (
				<ProductExclusions
					ids={Array.isArray(value) ? value : []}
					labels={moduleValues.excluded_product_labels || {}}
					onChange={(nextIds, nextLabels) => {
						onChange(key, nextIds);
						onChange('excluded_product_labels', nextLabels);
					}}
				/>
			);
		default:
			return (
				<TextControl
					label={label}
					help={help}
					value={value ?? ''}
					onChange={(v) => onChange(key, v)}
				/>
			);
	}
}
