/**
 * Right of Withdrawal — front-end behaviour, WordPress Interactivity API store.
 *
 * No build step: this is a native ES module. WordPress core resolves the bare
 * `@wordpress/interactivity` specifier via its import map, so it ships as-is.
 *
 * The markup (form, modal/inline panels, orders, requests, confirmation) is
 * server-rendered in render.php with data-wp-* directives. This module only
 * supplies the reactive state, the event actions, and the accessibility
 * behaviour (focus trap, Escape, focus restore, body scroll lock) that the
 * declarative directives can't express on their own.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

// The trigger that opened the current panel, per block root, so focus can be
// restored on close. Keyed by the interactive root element.
const lastTrigger = new WeakMap();
// Whether each block root's panel was open on the previous watch run, so the
// focus/scroll-lock side effects fire only on open/close transitions.
const wasOpen = new WeakMap();

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Map a REST error payload to a user-facing status string.
 *
 * @param {object} body Parsed JSON error body.
 * @param {object} i18n Localised strings from the Interactivity store.
 * @return {string}
 */
function restErrorMessage( body, i18n ) {
	const code = body && body.code ? body.code : '';
	const map = {
		sceu_not_eligible: i18n.notEligible,
		rest_cookie_invalid_nonce: i18n.invalidNonce,
		sceu_module_disabled: i18n.moduleDisabled,
		sceu_too_many: i18n.tooMany,
		sceu_no_selection: i18n.noSelection,
	};
	if ( map[ code ] ) {
		return map[ code ];
	}
	if ( body && body.message ) {
		return body.message;
	}
	return i18n.error;
}

const { state, actions } = store( 'surecart-eu-helper', {
	state: {
		get isOpen() {
			return getContext().panel !== 'none';
		},
		get isForm() {
			return getContext().panel === 'form';
		},
		get isRequests() {
			return getContext().panel === 'requests';
		},
		get isConfirmation() {
			return getContext().panel === 'confirmation';
		},
		get heading() {
			const ctx = getContext();
			return 'requests' === ctx.panel ? ctx.requestsTitle : ctx.modalTitle;
		},
	},

	actions: {
		openForm() {
			getContext().panel = 'form';
		},
		openRequests() {
			getContext().panel = 'requests';
		},
		close() {
			const ctx = getContext();
			ctx.panel = 'none';
			ctx.status = '';
		},
		onOverlayClick( event ) {
			// Only a click on the overlay itself (not the dialog) closes it.
			if ( event.target === getElement().ref ) {
				actions.close();
			}
		},
		onDialogKeydown( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				actions.close();
				return;
			}
			if ( event.key !== 'Tab' ) {
				return;
			}
			const dialog = getElement().ref;
			const items = Array.prototype.slice
				.call( dialog.querySelectorAll( FOCUSABLE ) )
				.filter( function ( el ) {
					return el.offsetParent !== null;
				} );
			if ( ! items.length ) {
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		},
		toggleOrder( event ) {
			const ctx = getContext();
			const id = event.target.value;
			const set = new Set( ctx.selectedIds );
			if ( event.target.checked ) {
				set.add( id );
			} else {
				set.delete( id );
			}
			ctx.selectedIds = Array.from( set );
		},
		setName( event ) {
			getContext().name = event.target.value;
		},
		setReason( event ) {
			getContext().reason = event.target.value;
		},
		*submit( event ) {
			event.preventDefault();
			const ctx = getContext();
			if ( ! ctx.selectedIds.length ) {
				ctx.status = state.i18n.selectOne;
				return;
			}
			ctx.submitting = true;
			ctx.submitLabel = state.i18n.sending;
			ctx.status = '';
			try {
				const res = yield fetch( state.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': state.nonce,
					},
					body: JSON.stringify( {
						order_ids: ctx.selectedIds,
						name: ctx.name,
						email: ctx.email,
						reason: ctx.reason,
					} ),
				} );
				if ( res.ok ) {
					// Reflect the new request in the UI without a page reload:
					// append its row, drop the submitted orders from the form, and
					// flip the visibility flags the markup binds to.
					let okBody = {};
					try {
						okBody = yield res.json();
					} catch ( parseError ) {
						okBody = {};
					}
					const r = okBody && okBody.request ? okBody.request : null;
					if ( r ) {
						ctx.requests = ctx.requests.concat( [
							{
								id: r.id,
								statusLabel: r.statusLabel,
								ordersText:
									state.i18n.ordersLabel +
									': ' +
									( r.orders || [] ).join( ', ' ),
								dateText: r.date
									? state.i18n.submittedLabel + ': ' + r.date
									: '',
								isReceived: r.status === 'received',
								isResolved: r.status === 'resolved',
								isRejected: r.status === 'rejected',
							},
						] );
						ctx.hasRequests = true;
					}
					const submitted = new Set( ctx.selectedIds );
					ctx.orders = ctx.orders.filter( function ( o ) {
						return ! submitted.has( o.id );
					} );
					ctx.hasOrders = ctx.orders.length > 0;
					ctx.selectedIds = [];
					ctx.panel = 'confirmation';
				} else {
					let body = {};
					try {
						body = yield res.json();
					} catch ( parseError ) {
						body = {};
					}
					ctx.status = restErrorMessage( body, state.i18n );
				}
			} catch ( e ) {
				ctx.status = state.i18n.error;
			} finally {
				ctx.submitting = false;
				ctx.submitLabel = state.i18n.submit;
			}
		},
	},

	callbacks: {
		// Runs on mount and whenever context.panel changes. Handles the focus and
		// scroll-lock side effects that accompany opening/closing a panel.
		onPanelChange() {
			const root = getElement().ref;
			const ctx = getContext();
			const open = ctx.panel !== 'none';
			const was = wasOpen.get( root ) || false;

			if ( ctx.display === 'modal' ) {
				document.body.classList.toggle( 'sceu-modal-open', open );
			}

			if ( open && ! was ) {
				// Remember the trigger so focus can return to it on close.
				lastTrigger.set( root, document.activeElement );
				// Move focus into the now-visible panel.
				const scope = root.querySelector(
					'.sceu-modal:not([hidden]), .sceu-row__panel:not([hidden])'
				);
				if ( scope ) {
					const target = scope.querySelector( FOCUSABLE );
					if ( target ) {
						target.focus();
					}
				}
			} else if ( ! open && was ) {
				const trigger = lastTrigger.get( root );
				if ( trigger && document.body.contains( trigger ) ) {
					trigger.focus();
				}
			}

			wasOpen.set( root, open );
		},
	},
} );
