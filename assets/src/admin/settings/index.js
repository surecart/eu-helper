/**
 * EU Helper — React settings app.
 *
 * Replaces the server-rendered Settings API form. Renders generically from the
 * module + field schema localized into `window.sceuSettingsApp` (the same
 * settings_fields() the PHP renderer used), and saves via the REST endpoint,
 * which runs the shared SettingsSanitizer. UI is @wordpress/components on top of
 * the plugin's existing .sceu-app shell styles + brand tokens.
 */
import { createRoot, useState, useRef } from '@wordpress/element';
import {
	ToggleControl,
	TextControl,
	TextareaControl,
	SelectControl,
	RadioControl,
	CheckboxControl,
	FormTokenField,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const boot = window.sceuSettingsApp || {};
const MODULES = Array.isArray( boot.modules ) ? boot.modules : [];

/**
 * Async product-exclusion picker. Tokens display product names; the canonical
 * value is the list of ids, with an id→label map persisted alongside it.
 */
function ProductExclusions( { ids, labels, onChange } ) {
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

/** Bulk collection exclusions: a checklist of all SureCart collections. */
function CollectionExclusions( { selected, onChange } ) {
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

/** Render one schema field by type. */
function Field( { field, value, moduleValues, onChange } ) {
	const { key, type, label, help, options = [] } = field;

	switch ( type ) {
		case 'toggle':
			return (
				<ToggleControl
					label={ field.checkbox_label || label }
					help={ help }
					checked={ !! value }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'number':
			return (
				<TextControl
					type="number"
					label={ label }
					help={ help }
					min={ field.min }
					value={ value ?? '' }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'email':
			return (
				<TextControl
					type="email"
					label={ label }
					help={ help }
					placeholder={
						'merchant_email' === key ? boot.merchantEmailPlaceholder : undefined
					}
					value={ value ?? '' }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'select':
			return (
				<SelectControl
					label={ label }
					help={ help }
					value={ value ?? '' }
					options={ options }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'radio':
			return (
				<RadioControl
					label={ label }
					help={ help }
					selected={ value ?? '' }
					options={ options }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'textarea':
			return (
				<TextareaControl
					label={ label }
					help={ help }
					value={ value ?? '' }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'collection_exclusions':
			return (
				<CollectionExclusions
					selected={ Array.isArray( value ) ? value : [] }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
		case 'product_exclusions':
			return (
				<ProductExclusions
					ids={ Array.isArray( value ) ? value : [] }
					labels={ moduleValues.excluded_product_labels || {} }
					onChange={ ( nextIds, nextLabels ) => {
						onChange( key, nextIds );
						onChange( 'excluded_product_labels', nextLabels );
					} }
				/>
			);
		default:
			return (
				<TextControl
					label={ label }
					help={ help }
					value={ value ?? '' }
					onChange={ ( v ) => onChange( key, v ) }
				/>
			);
	}
}

/** A module's panel: enable toggle, disclaimer, and its fields grouped by section. */
function ModulePanel( { module, values, enabled, onField, onEnable } ) {
	const sections = module.sections || {};
	const grouped = {};
	( module.fields || [] ).forEach( ( f ) => {
		const s = f.section || '_default';
		( grouped[ s ] = grouped[ s ] || [] ).push( f );
	} );
	const order = Object.keys( sections );
	Object.keys( grouped ).forEach( ( k ) => {
		if ( ! order.includes( k ) ) {
			order.push( k );
		}
	} );

	return (
		<section className="sceu-panel is-active">
			<div className="sceu-panel__head">
				<h2 className="sceu-panel__title">{ module.label }</h2>
			</div>
			{ module.description && (
				<p className="sceu-panel__desc">{ module.description }</p>
			) }
			{ module.disclaimer && (
				<p className="sceu-card__note">
					<strong>{ __( 'Your responsibility', 'surecart-eu-helper' ) }:</strong>{ ' ' }
					{ module.disclaimer }
				</p>
			) }

			<div className="sceu-card sceu-card--compact">
				<ToggleControl
					label={ __( 'Enable module', 'surecart-eu-helper' ) }
					checked={ !! enabled }
					onChange={ onEnable }
				/>
			</div>

			{ order.map( ( skey ) => {
				if ( ! grouped[ skey ] ) {
					return null;
				}
				const sec = sections[ skey ] || {};
				return (
					<div key={ skey }>
						{ sec.title && (
							<div className="sceu-section__head">
								<h3 className="sceu-section__title">{ sec.title }</h3>
								{ sec.description && (
									<p className="sceu-section__desc">{ sec.description }</p>
								) }
							</div>
						) }
						<div className="sceu-card">
							{ grouped[ skey ].map( ( field ) => (
								<div className="sceu-field" key={ field.key }>
									<Field
										field={ field }
										value={ values[ field.key ] }
										moduleValues={ values }
										onChange={ onField }
									/>
								</div>
							) ) }
						</div>
					</div>
				);
			} ) }
		</section>
	);
}

function SettingsApp() {
	const [ values, setValues ] = useState( () => {
		const v = {};
		MODULES.forEach( ( m ) => {
			v[ m.id ] = { ...m.values };
		} );
		return v;
	} );
	const [ enabled, setEnabled ] = useState( () => {
		const e = {};
		MODULES.forEach( ( m ) => {
			e[ m.id ] = !! m.enabled;
		} );
		return e;
	} );
	const [ removeData, setRemoveData ] = useState( !! boot.removeData );
	const [ active, setActive ] = useState(
		MODULES.length ? MODULES[ 0 ].id : 'uninstall'
	);
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const onField = ( moduleId, key, val ) => {
		setValues( ( prev ) => ( {
			...prev,
			[ moduleId ]: { ...prev[ moduleId ], [ key ]: val },
		} ) );
		setDirty( true );
	};

	const save = async () => {
		setSaving( true );
		setNotice( null );
		const settings = { modules: {}, remove_data: removeData };
		MODULES.forEach( ( m ) => {
			settings.modules[ m.id ] = !! enabled[ m.id ];
			settings[ m.id ] = { ...values[ m.id ] };
		} );
		try {
			await apiFetch( {
				path: boot.restPath,
				method: 'POST',
				data: { settings },
			} );
			setDirty( false );
			setNotice( { status: 'success', msg: __( 'Settings saved.', 'surecart-eu-helper' ) } );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				msg: __( 'Could not save settings. Please try again.', 'surecart-eu-helper' ),
			} );
		}
		setSaving( false );
	};

	const activeModule = MODULES.find( ( m ) => m.id === active );

	return (
		<div className="sceu-app__body">
			<nav className="sceu-app__nav" aria-label={ __( 'EU Helper modules', 'surecart-eu-helper' ) }>
				{ MODULES.map( ( m ) => (
					<a
						key={ m.id }
						href={ `#${ m.id }` }
						className={ `sceu-nav__item${ active === m.id ? ' is-active' : '' }` }
						onClick={ ( e ) => {
							e.preventDefault();
							setActive( m.id );
						} }
					>
						{ m.label }
					</a>
				) ) }
				<a
					href="#uninstall"
					className={ `sceu-nav__item${ active === 'uninstall' ? ' is-active' : '' }` }
					onClick={ ( e ) => {
						e.preventDefault();
						setActive( 'uninstall' );
					} }
				>
					{ __( 'Uninstall', 'surecart-eu-helper' ) }
				</a>
			</nav>

			<div className="sceu-app__content">
				<div className="sceu-app__inner">
					{ notice && (
						<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
							{ notice.msg }
						</Notice>
					) }

					{ activeModule && (
						<ModulePanel
							module={ activeModule }
							values={ values[ activeModule.id ] || {} }
							enabled={ enabled[ activeModule.id ] }
							onField={ ( key, val ) => onField( activeModule.id, key, val ) }
							onEnable={ ( on ) => {
								setEnabled( ( prev ) => ( { ...prev, [ activeModule.id ]: on } ) );
								setDirty( true );
							} }
						/>
					) }

					{ 'uninstall' === active && (
						<section className="sceu-panel is-active">
							<div className="sceu-panel__head">
								<h2 className="sceu-panel__title">{ __( 'Uninstall', 'surecart-eu-helper' ) }</h2>
							</div>
							<div className="sceu-card sceu-card--compact">
								<ToggleControl
									label={ __( 'Remove Plugin Data', 'surecart-eu-helper' ) }
									help={ __(
										'Completely remove all plugin data — settings and the withdrawal-request log — when the plugin is deleted. This cannot be undone.',
										'surecart-eu-helper'
									) }
									checked={ removeData }
									onChange={ ( on ) => {
										setRemoveData( on );
										setDirty( true );
									} }
								/>
							</div>
						</section>
					) }

					<div className="sceu-app__actions">
						<Button variant="primary" onClick={ save } disabled={ saving || ! dirty }>
							{ saving && <Spinner /> }
							{ saving ? __( 'Saving…', 'surecart-eu-helper' ) : __( 'Save', 'surecart-eu-helper' ) }
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
}

const root = document.getElementById( 'sceu-settings-root' );
if ( root ) {
	createRoot( root ).render( <SettingsApp /> );
}
