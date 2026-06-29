/**
 * Bulk collection exclusions: a checklist of all SureCart collections.
 */
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { boot } from '../boot';

export default function CollectionExclusions( { selected, onChange } ) {
	const collections = Array.isArray( boot.collections ) ? boot.collections : [];
	if ( ! collections.length ) {
		return (
			<p className="description">
				{ __(
					'No product collections found (or SureCart is unavailable).',
					'surecart-eu-helper'
				) }
			</p>
		);
	}
	const toggle = ( id, on ) => {
		const next = on
			? [ ...selected, id ]
			: selected.filter( ( x ) => x !== id );
		onChange( [ ...new Set( next ) ] );
	};
	return (
		<ul className="sceu-checklist">
			{ collections.map( ( col ) => (
				<li className="sceu-checklist__item" key={ col.id }>
					<CheckboxControl
						label={ col.name }
						checked={ selected.includes( col.id ) }
						onChange={ ( on ) => toggle( col.id, on ) }
					/>
				</li>
			) ) }
		</ul>
	);
}
