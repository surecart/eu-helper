/**
 * Right of Withdrawal — front-end form behaviour.
 *
 * No build step: vanilla JS. Reads its per-instance config from the JSON
 * <script> the server renders inside each block, then wires an accessible
 * modal or inline form that posts to the plugin's REST endpoint.
 */
( function () {
	'use strict';

	function h( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( key ) {
			if ( key === 'class' ) {
				node.className = attrs[ key ];
			} else if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( key === 'html' ) {
				node.innerHTML = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function applyBrand( node, config ) {
		if ( config.primaryColor ) {
			node.style.setProperty( '--sceu-primary', config.primaryColor );
		}
		if ( config.primaryText ) {
			node.style.setProperty( '--sceu-primary-text', config.primaryText );
		}
	}

	function focusable( root ) {
		return Array.prototype.slice.call(
			root.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		);
	}

	function buildForm( config, onSubmit ) {
		var s = config.strings;

		var nameInput = h( 'input', {
			type: 'text',
			class: 'sceu-field__input',
			id: config.uid + '-name',
			value: config.customer.name || '',
		} );
		var emailInput = h( 'input', {
			type: 'email',
			class: 'sceu-field__input',
			id: config.uid + '-email',
			value: config.customer.email || '',
		} );

		var checks = [];
		var ordersWrap = h( 'div', { class: 'sceu-orders', role: 'group', 'aria-label': s.orders } );
		config.orders.forEach( function ( order, i ) {
			var cb = h( 'input', {
				type: 'checkbox',
				class: 'sceu-orders__cb',
				id: config.uid + '-order-' + i,
				value: order.id,
			} );
			checks.push( cb );
			var label = h( 'label', { class: 'sceu-orders__item', for: config.uid + '-order-' + i }, [
				cb,
				h( 'span', { class: 'sceu-orders__text' }, [
					h( 'span', { class: 'sceu-orders__label', text: order.label } ),
					order.summary ? h( 'span', { class: 'sceu-orders__summary', text: order.summary, title: order.summary } ) : null,
					order.meta ? h( 'span', { class: 'sceu-orders__meta', text: order.meta } ) : null,
				] ),
			] );
			ordersWrap.appendChild( label );
		} );

		var reason = h( 'textarea', {
			class: 'sceu-field__input',
			id: config.uid + '-reason',
			rows: '3',
		} );

		var status = h( 'p', { class: 'sceu-form__status', 'aria-live': 'polite', role: 'status' } );

		var submit = h( 'button', { type: 'submit', class: 'sceu-form__submit wp-element-button', text: s.submit } );
		var cancel = h( 'button', { type: 'button', class: 'sceu-form__cancel', text: s.cancel } );

		var form = h( 'form', { class: 'sceu-form', novalidate: 'novalidate' }, [
			h( 'div', { class: 'sceu-field' }, [
				h( 'label', { class: 'sceu-field__label', for: config.uid + '-name', text: s.name } ),
				nameInput,
			] ),
			h( 'div', { class: 'sceu-field' }, [
				h( 'label', { class: 'sceu-field__label', for: config.uid + '-email', text: s.email } ),
				emailInput,
			] ),
			h( 'div', { class: 'sceu-field' }, [
				h( 'span', { class: 'sceu-field__label', text: s.orders } ),
				ordersWrap,
			] ),
			h( 'div', { class: 'sceu-field' }, [
				h( 'label', { class: 'sceu-field__label', for: config.uid + '-reason', text: s.reason } ),
				reason,
			] ),
			status,
			h( 'div', { class: 'sceu-form__actions' }, [ submit, cancel ] ),
		] );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var ids = checks.filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.value; } );
			if ( ids.length === 0 ) {
				status.textContent = s.selectOne;
				return;
			}
			submit.disabled = true;
			status.textContent = s.sending;

			onSubmit(
				{
					order_ids: ids,
					name: nameInput.value,
					email: emailInput.value,
					reason: reason.value,
				},
				function ( ok ) {
					if ( ! ok ) {
						submit.disabled = false;
						status.textContent = s.error;
					}
				}
			);
		} );

		return { form: form, cancel: cancel, firstField: nameInput };
	}

	function submitRequest( config, payload, done ) {
		fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( payload ),
		} )
			.then( function ( res ) {
				return res.ok ? res.json() : Promise.reject( res );
			} )
			.then( function () {
				done( true );
			} )
			.catch( function () {
				done( false );
			} );
	}

	function showConfirmation( container, message ) {
		var box = h( 'div', { class: 'sceu-row__confirmation', role: 'status', 'aria-live': 'polite', text: message } );
		container.innerHTML = '';
		container.appendChild( box );
	}

	function initBlock( root ) {
		var dataEl = root.querySelector( '.sceu-row__data' );
		if ( ! dataEl ) {
			return;
		}

		var config;
		try {
			config = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}
		config.uid = root.getAttribute( 'data-sceu-uid' ) || 'sceu-row';

		var onSuccess = function () {
			showConfirmation( root, config.confirmation );
		};

		var trigger = root.querySelector( '.sceu-row__trigger' );
		if ( trigger ) {
			if ( config.display === 'inline' ) {
				wireInline( root, trigger, config, onSuccess );
			} else {
				wireModal( root, trigger, config, onSuccess );
			}
		}

		var requestsTrigger = root.querySelector( '.sceu-row__requests-trigger' );
		if ( requestsTrigger ) {
			wireRequests( root, requestsTrigger, config );
		}
	}

	function wireInline( root, trigger, config, onSuccess ) {
		var built = null;
		var panel = h( 'div', { class: 'sceu-row__panel', hidden: 'hidden' } );
		applyBrand( panel, config );
		root.appendChild( panel );

		trigger.addEventListener( 'click', function () {
			if ( ! built ) {
				built = buildForm( config, function ( payload, cb ) {
					submitRequest( config, payload, function ( ok ) {
						if ( ok ) {
							onSuccess();
						} else {
							cb( false );
						}
					} );
				} );
				panel.appendChild( built.form );
				built.cancel.addEventListener( 'click', function () {
					panel.hidden = true;
					trigger.focus();
				} );
			}
			panel.hidden = false;
			built.firstField.focus();
		} );
	}

	function wireModal( root, trigger, config, onSuccess ) {
		trigger.addEventListener( 'click', function () {
			openModal( root, trigger, config, onSuccess );
		} );
	}

	// Generic accessible modal: overlay + dialog, focus trap, Escape, restore
	// focus to the trigger. Returns { close }.
	function presentModal( config, titleText, bodyNode, trigger ) {
		var titleId = config.uid + '-mtitle';
		var title = h( 'h2', { class: 'sceu-modal__title', id: titleId, text: titleText } );
		var closeBtn = h( 'button', {
			type: 'button',
			class: 'sceu-modal__close',
			'aria-label': config.strings.close,
			html: '&times;',
		} );

		var dialog = h(
			'div',
			{
				class: 'sceu-modal__dialog sceu-modal--' + ( config.scheme || 'auto' ),
				role: 'dialog',
				'aria-modal': 'true',
				'aria-labelledby': titleId,
			},
			[ h( 'div', { class: 'sceu-modal__head' }, [ title, closeBtn ] ), bodyNode ]
		);
		var overlay = h( 'div', { class: 'sceu-modal' }, [ dialog ] );
		applyBrand( dialog, config );

		document.body.appendChild( overlay );
		document.body.classList.add( 'sceu-modal-open' );

		closeBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );

		function onKeydown( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				close();
				return;
			}
			if ( e.key === 'Tab' ) {
				var items = focusable( dialog );
				if ( items.length === 0 ) {
					return;
				}
				var first = items[ 0 ];
				var last = items[ items.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		}
		document.addEventListener( 'keydown', onKeydown, true );

		function close() {
			document.removeEventListener( 'keydown', onKeydown, true );
			document.body.classList.remove( 'sceu-modal-open' );
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			if ( trigger && document.body.contains( trigger ) ) {
				trigger.focus();
			}
		}

		var f = focusable( dialog );
		if ( f.length ) {
			f[ 0 ].focus();
		}

		return { close: close };
	}

	function openModal( root, trigger, config, onSuccess ) {
		var modal;
		var built = buildForm( config, function ( payload, cb ) {
			submitRequest( config, payload, function ( ok ) {
				if ( ok ) {
					if ( modal ) {
						modal.close();
					}
					onSuccess();
				} else {
					cb( false );
				}
			} );
		} );

		modal = presentModal( config, config.modalTitle, built.form, trigger );
		built.cancel.addEventListener( 'click', modal.close );
		built.firstField.focus();
	}

	// Read-only list of the customer's withdrawal requests + their statuses.
	function buildRequestsList( config ) {
		var s = config.strings;
		var requests = config.requests || [];
		var wrap = h( 'div', { class: 'sceu-requests' } );

		requests.forEach( function ( r ) {
			var orders = r.orders && r.orders.length ? r.orders.join( ', ' ) : '';
			var head = h( 'div', { class: 'sceu-requests__head' }, [
				h( 'span', { class: 'sceu-requests__orders', text: s.requestOrders + ': ' + orders } ),
				h( 'span', { class: 'sceu-badge sceu-badge--' + ( r.status || 'received' ), text: r.statusLabel } ),
			] );
			var children = [ head ];
			if ( r.date ) {
				children.push( h( 'div', { class: 'sceu-requests__date', text: s.requestDate + ': ' + r.date } ) );
			}
			wrap.appendChild( h( 'div', { class: 'sceu-requests__item' }, children ) );
		} );

		return wrap;
	}

	function wireRequests( root, trigger, config ) {
		if ( config.display === 'inline' ) {
			var panel = h( 'div', { class: 'sceu-row__panel', hidden: 'hidden' } );
			applyBrand( panel, config );
			panel.appendChild( buildRequestsList( config ) );
			var closeBtn = h( 'button', { type: 'button', class: 'sceu-form__cancel', text: config.strings.close } );
			panel.appendChild( h( 'div', { class: 'sceu-form__actions' }, [ closeBtn ] ) );
			root.appendChild( panel );

			trigger.addEventListener( 'click', function () {
				panel.hidden = ! panel.hidden;
				if ( ! panel.hidden ) {
					var f = focusable( panel );
					if ( f.length ) {
						f[ 0 ].focus();
					}
				}
			} );
			closeBtn.addEventListener( 'click', function () {
				panel.hidden = true;
				trigger.focus();
			} );
		} else {
			trigger.addEventListener( 'click', function () {
				presentModal( config, config.requestsTitle, buildRequestsList( config ), trigger );
			} );
		}
	}

	function init() {
		var blocks = document.querySelectorAll( '.sceu-row[data-sceu-uid]' );
		Array.prototype.forEach.call( blocks, initBlock );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
