/**
 * Async product-exclusion picker. Tokens display product names; the canonical
 * value is the list of ids, with an id→label map persisted alongside it.
 */
import { useState, useRef } from '@wordpress/element';
import { FormTokenField } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { boot } from '../boot';

export default function ProductExclusions( { ids, labels, onChange } ) {
	const [ suggestions, setSuggestions ] = useState( [] );
	const nameToId = useRef( {} );

	const search = async ( text ) => {
		if ( ! text || text.length < 2 ) {
			setSuggestions( [] );
			return;
		}
		try {
			const results = await apiFetch( {
				path: `${ boot.productSearchPath }?q=${ encodeURIComponent( text ) }`,
			} );
			const names = [];
			( results || [] ).forEach( ( p ) => {
				nameToId.current[ p.name ] = p.id;
				names.push( p.name );
			} );
			setSuggestions( names );
		} catch ( e ) {
			setSuggestions( [] );
		}
	};

	const tokens = ids.map( ( id ) => labels[ id ] || id );

	const onTokensChange = ( nextTokens ) => {
		const nextIds = [];
		const nextLabels = {};
		nextTokens.forEach( ( token ) => {
			// Resolve a token (a display name) back to an id: a freshly searched
			// result, or one already selected.
			const id =
				nameToId.current[ token ] ||
				ids.find( ( existing ) => ( labels[ existing ] || existing ) === token );
			if ( id ) {
				nextIds.push( id );
				nextLabels[ id ] = token;
			}
		} );
		onChange( nextIds, nextLabels );
	};

	return (
		<FormTokenField
			label={ __( 'Excluded products', 'surecart-eu-helper' ) }
			value={ tokens }
			suggestions={ suggestions }
			onInputChange={ search }
			onChange={ onTokensChange }
			__experimentalShowHowTo={ false }
		/>
	);
}
