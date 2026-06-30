/**
 * Bulk collection exclusions: a checklist of SureCart collections (with product
 * counts) plus a button to rebuild the cached excluded-product list.
 */
import { CheckboxControl, Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import { boot } from '../boot';

export default function CollectionExclusions({
	label,
	help,
	selected,
	onChange,
}) {
	const collections = Array.isArray(boot.collections) ? boot.collections : [];
	const [refreshing, setRefreshing] = useState(false);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);

	const toggle = (id, on) => {
		const next = on ? [...selected, id] : selected.filter((x) => x !== id);
		onChange([...new Set(next)]);
	};

	// Rebuild the cache in place via REST and toast the result — no page reload.
	const refresh = async () => {
		setRefreshing(true);
		try {
			const res = await apiFetch({
				path: boot.refreshExclusionsPath,
				method: 'POST',
			});
			const count = res?.count || 0;
			createSuccessNotice(
				sprintf(
					/* translators: %d: number of products resolved from excluded collections. */
					_n(
						'Excluded-product list refreshed: %d product in your excluded collections.',
						'Excluded-product list refreshed: %d products in your excluded collections.',
						count,
						'surecart-eu-helper'
					),
					count
				),
				{ id: 'sceu-exclusions-refresh', type: 'snackbar' }
			);
		} catch (e) {
			createErrorNotice(
				__(
					'Could not refresh the excluded-product list. Please try again.',
					'surecart-eu-helper'
				),
				{
					id: 'sceu-exclusions-refresh',
					type: 'snackbar',
					explicitDismiss: true,
				}
			);
		}
		setRefreshing(false);
	};

	return (
		<div className="sceu-field__group">
			{label && <div className="sceu-field__label">{label}</div>}

			{!collections.length ? (
				<p className="description">
					{__(
						'No product collections found (or SureCart is unavailable). Create collections in SureCart to exclude products in bulk.',
						'surecart-eu-helper'
					)}
				</p>
			) : (
				<>
					<ul className="sceu-checklist">
						{collections.map((col) => (
							<li className="sceu-checklist__item" key={col.id}>
								<CheckboxControl
									label={col.name}
									checked={selected.includes(col.id)}
									onChange={(on) => toggle(col.id, on)}
								/>
								<span className="sceu-checklist__count">
									{sprintf(
										/* translators: %d: number of products in the collection. */
										_n(
											'%d product',
											'%d products',
											col.products_count,
											'surecart-eu-helper'
										),
										col.products_count
									)}
								</span>
							</li>
						))}
					</ul>
					{boot.refreshExclusionsPath && (
						<p className="sceu-refresh-row">
							<Button
								variant="secondary"
								onClick={refresh}
								isBusy={refreshing}
								disabled={refreshing}
							>
								{refreshing
									? __('Refreshing…', 'surecart-eu-helper')
									: __(
											'Refresh excluded product list',
											'surecart-eu-helper'
									  )}
							</Button>
						</p>
					)}
					<p className="sceu-field__help">
						{__(
							'Rebuilds the cached list of products in the excluded collections. Runs automatically when you save, on a schedule, and when you add products to a collection.',
							'surecart-eu-helper'
						)}
					</p>
				</>
			)}

			{help && <p className="sceu-field__help">{help}</p>}
		</div>
	);
}
