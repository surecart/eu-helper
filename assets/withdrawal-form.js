/**
 * Public withdrawal form (block + shortcode).
 *
 * Self-contained vanilla JS — builds its UI from the lookup response (items
 * arrive after the email/order-number check), which is exactly the dynamic case
 * the Interactivity API handles poorly here. Two paths: a verified order shows
 * an item picker → review → confirm; an unmatched order shows a free-text box.
 */
( function () {
	var cfg  = window.sceuWF || {};
	var i18n = cfg.i18n || {};
	function t( key, fallback ) { return i18n[ key ] || fallback; }

	/** Tiny DOM helper. Text content only (no innerHTML with server data). */
	function h( tag, props, children ) {
		var node = document.createElement( tag );
		if ( props ) {
			Object.keys( props ).forEach( function ( k ) {
				var v = props[ k ];
				if ( k === 'class' ) { node.className = v; }
				else if ( k === 'text' ) { node.textContent = v; }
				else if ( k.slice( 0, 2 ) === 'on' && typeof v === 'function' ) { node.addEventListener( k.slice( 2 ).toLowerCase(), v ); }
				else if ( v === true ) { node.setAttribute( k, '' ); }
				else if ( v != null && v !== false ) { node.setAttribute( k, v ); }
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c == null ) { return; }
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function postJSON( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( d ) { return { ok: r.ok, status: r.status, data: d }; } )
				.catch( function () { return { ok: r.ok, status: r.status, data: {} }; } );
		} ).catch( function () { return { ok: false, status: 0, data: {} }; } );
	}

	function initForm( root ) {
		var lookupForm = root.querySelector( '.sceu-wf__lookup' );
		var result     = root.querySelector( '[data-sceu-result]' );
		var errorEl    = lookupForm ? lookupForm.querySelector( '.sceu-wf__error' ) : null;
		if ( ! lookupForm || ! result ) { return; }

		var state = { email: '', orderNumber: '', hp: '' };

		// Toggle a button's in-flight state: disable it and show a spinner (the
		// CSS .is-loading::before), keeping its existing label unchanged. aria-busy
		// conveys the in-flight state to assistive tech without changing the label.
		function setLoading( btn, on ) {
			if ( ! btn ) { return; }
			btn.disabled = on;
			btn.classList.toggle( 'is-loading', on );
			btn.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		}

		function showLookupError( msg ) {
			if ( ! errorEl ) { return; }
			errorEl.textContent = msg;
			errorEl.hidden = ! msg;
			// Mark the fields invalid so SR users hear the error when they return to them.
			lookupForm.querySelectorAll( '[name="email"], [name="order_number"]' ).forEach( function ( input ) {
				if ( msg ) { input.setAttribute( 'aria-invalid', 'true' ); }
				else { input.removeAttribute( 'aria-invalid' ); }
			} );
		}

		function clearResult() { result.innerHTML = ''; result.hidden = true; }

		function resetToLookup() {
			clearResult();
			lookupForm.hidden = false;
			setLoading( lookupForm.querySelector( '.sceu-wf__submit' ), false );
			var email = lookupForm.querySelector( '[name="email"]' );
			if ( email ) { email.focus(); }
		}

		// Showing a result replaces the lookup step (so there aren't two forms /
		// two buttons on screen), and offers a way back to look up another order.
		function setResult( node ) {
			result.innerHTML = '';
			result.appendChild( h( 'button', {
				type: 'button',
				class: 'sceu-wf__again',
				text: t( 'searchAgain', 'Look up a different order' ),
				onClick: resetToLookup
			} ) );
			result.appendChild( node );
			result.hidden = false;
			lookupForm.hidden = true;
			showLookupError( '' );
			// Move focus into the new content: the lookup button (now hidden) had
			// focus, and this lets screen-reader + keyboard users continue from the
			// result region rather than losing their place.
			try { result.focus(); } catch ( e ) {}
		}

		function success( data ) {
			data = data || {};
			clearResult();
			lookupForm.hidden = true;
			// On the unverified path no confirmation email is sent, so don't claim
			// one was. role=status announces it; tabindex lets us move focus to it.
			var msg = data.unverified ? ( cfg.successUnverified || cfg.successMessage ) : cfg.successMessage;
			var box = h( 'div', { class: 'sceu-wf__success', role: 'status', tabindex: '-1' }, [
				h( 'p', { text: msg || 'Your withdrawal request has been received.' } )
			] );
			root.appendChild( box );
			try { box.focus(); } catch ( e ) {}
		}

		// ---- Step 1: lookup -------------------------------------------------
		lookupForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			showLookupError( '' );
			state.email       = ( lookupForm.querySelector( '[name="email"]' ) || {} ).value || '';
			state.orderNumber = ( lookupForm.querySelector( '[name="order_number"]' ) || {} ).value || '';
			state.hp          = ( lookupForm.querySelector( '[name="hp"]' ) || {} ).value || '';

			if ( ! state.email || ! state.orderNumber ) {
				showLookupError( t( 'genericError', 'Please enter your email and order number.' ) );
				return;
			}

			var btn = lookupForm.querySelector( '.sceu-wf__submit' );
			setLoading( btn, true );

			postJSON( cfg.lookupUrl, { email: state.email, order_number: state.orderNumber, hp: state.hp } )
				.then( function ( res ) {
					setLoading( btn, false );
					if ( res.status === 429 || res.status === 403 ) {
						showLookupError( ( res.data && res.data.message ) || t( 'genericError', 'Please try again.' ) );
						return;
					}
					if ( res.data && res.data.found && res.data.order ) {
						renderFound( res.data.order );
					} else {
						renderNotFound();
					}
				} );
		} );

		// ---- Step 2a: order found → item picker -----------------------------
		function renderFound( order ) {
			var wrap = h( 'div', { class: 'sceu-wf__order' }, [
				h( 'p', { class: 'sceu-wf__order-head', text: t( 'orderLabel', 'Order' ) + ' ' + ( order.number || '' ) + ( order.total_display ? ' · ' + order.total_display : '' ) } )
			] );

			var rows = [];

			if ( order.whole_order || ! ( order.items && order.items.length ) ) {
				wrap.appendChild( h( 'p', { class: 'sceu-wf__whole', text: t( 'entireOrder', 'Entire order' ) } ) );
			} else {
				wrap.appendChild( h( 'p', { class: 'sceu-items__hint', text: t( 'itemsHint', 'Choose how many of each item to withdraw.' ) } ) );
				var list = h( 'ul', { class: 'sceu-items' }, [] );
				order.items.forEach( function ( item ) {
					var max = Math.max( 1, parseInt( item.max, 10 ) || 1 );
					var qtyInput = h( 'input', { class: 'sceu-item__input', type: 'number', min: '0', step: '1', value: '0', 'aria-label': ( t( 'qtyLabel', 'Quantity to withdraw of %s' ) ).replace( '%s', item.name ) } );
					// Polite live region so screen readers hear the new quantity when
					// the +/- buttons change it.
					var live = h( 'span', { class: 'sceu-sr-only', 'aria-live': 'polite' } );

					function setQty( n ) {
						n = Math.min( max, Math.max( 0, n ) );
						qtyInput.value = String( n );
						live.textContent = String( n );
						dec.disabled = n <= 0;
						inc.disabled = n >= max;
					}
					var dec = h( 'button', { type: 'button', class: 'sceu-step', 'aria-label': ( t( 'decLabel', 'Decrease quantity of %s' ) ).replace( '%s', item.name ), onClick: function () { setQty( ( parseInt( qtyInput.value, 10 ) || 0 ) - 1 ); } }, [ '−' ] );
					var inc = h( 'button', { type: 'button', class: 'sceu-step', 'aria-label': ( t( 'incLabel', 'Increase quantity of %s' ) ).replace( '%s', item.name ), onClick: function () { setQty( ( parseInt( qtyInput.value, 10 ) || 0 ) + 1 ); } }, [ '+' ] );

					qtyInput.addEventListener( 'input', function () { setQty( parseInt( qtyInput.value, 10 ) || 0 ); } );
					var media = item.image ? h( 'img', { class: 'sceu-item__img', src: item.image, alt: item.image_alt || item.name, loading: 'lazy' } ) : null;

					var li = h( 'li', { class: 'sceu-item' }, [
						media,
						h( 'div', { class: 'sceu-item__text' }, [
							h( 'span', { class: 'sceu-item__name', text: item.name } ),
							h( 'span', { class: 'sceu-item__avail', text: ( t( 'available', '%d available' ) ).replace( '%d', max ) } )
						] ),
						h( 'div', { class: 'sceu-item__qty' }, [ dec, qtyInput, inc, live ] )
					] );
					rows.push( { id: item.id, name: item.name, input: qtyInput } );
					list.appendChild( li );
					setQty( 0 );
				} );
				wrap.appendChild( list );
			}

			var err = h( 'p', { class: 'sceu-wf__error', role: 'alert', hidden: true }, [] );
			var cont = h( 'button', { type: 'button', class: 'sceu-wf__submit', text: t( 'continue', 'Continue' ) } );
			cont.addEventListener( 'click', function () {
				err.hidden = true;
				var chosen = [];
				rows.forEach( function ( r ) {
					var q = parseInt( r.input.value, 10 ) || 0;
					if ( q > 0 ) { chosen.push( { line_item_id: r.id, quantity: q, name: r.name } ); }
				} );
				if ( ! order.whole_order && ! chosen.length ) {
					err.textContent = t( 'nothingChosen', 'Please choose at least one item.' );
					err.hidden = false;
					return;
				}
				renderReview( order, chosen );
			} );

			wrap.appendChild( err );
			wrap.appendChild( cont );
			setResult( wrap );
		}

		// ---- Step 2b: review then confirm -----------------------------------
		function renderReview( order, chosen ) {
			var summary = h( 'ul', { class: 'sceu-wf__review' }, [] );
			if ( order.whole_order || ! chosen.length ) {
				summary.appendChild( h( 'li', { text: t( 'entireOrder', 'Entire order' ) } ) );
			} else {
				chosen.forEach( function ( c ) {
					summary.appendChild( h( 'li', { text: c.quantity + ' × ' + c.name } ) );
				} );
			}

			var confirm = h( 'button', { type: 'button', class: 'sceu-wf__submit', text: cfg.confirmLabel || 'Confirm withdrawal' } );
			var back    = h( 'button', { type: 'button', class: 'sceu-wf__back', text: t( 'back', 'Back' ) } );
			var err     = h( 'p', { class: 'sceu-wf__error', role: 'alert', hidden: true }, [] );

			back.addEventListener( 'click', function () { renderFound( order ); } );
			confirm.addEventListener( 'click', function () {
				err.hidden = true;
				setLoading( confirm, true );
				postJSON( cfg.submitUrl, {
					email: state.email,
					order_number: state.orderNumber,
					hp: state.hp,
					items: chosen.map( function ( c ) { return { line_item_id: c.line_item_id, quantity: c.quantity }; } )
				} ).then( function ( res ) {
					setLoading( confirm, false );
					if ( res.ok && res.data && res.data.success ) { success( res.data ); }
					else {
						err.textContent = ( res.data && res.data.message ) || t( 'genericError', 'Something went wrong. Please try again.' );
						err.hidden = false;
					}
				} );
			} );

			setResult( h( 'div', { class: 'sceu-wf__order' }, [
				h( 'p', { class: 'sceu-wf__order-head', text: t( 'withdrawing', 'You are withdrawing:' ) } ),
				summary,
				err,
				h( 'div', { class: 'sceu-wf__actions' }, [ confirm, back ] )
			] ) );
		}

		// ---- Step 2c: not found → free-text fallback ------------------------
		function renderNotFound() {
			var textarea = h( 'textarea', { class: 'sceu-wf__textarea', rows: '4', 'aria-label': t( 'describe', 'Describe what you would like to withdraw from' ) }, [] );
			var send     = h( 'button', { type: 'button', class: 'sceu-wf__submit', text: t( 'sendRequest', 'Send request' ) } );
			var err      = h( 'p', { class: 'sceu-wf__error', role: 'alert', hidden: true }, [] );

			send.addEventListener( 'click', function () {
				err.hidden = true;
				var reason = textarea.value.trim();
				if ( ! reason ) { err.textContent = t( 'describe', 'Please describe what you would like to withdraw from.' ); err.hidden = false; return; }
				setLoading( send, true );
				postJSON( cfg.submitUrl, {
					email: state.email,
					order_number: state.orderNumber,
					hp: state.hp,
					reason: reason
				} ).then( function ( res ) {
					setLoading( send, false );
					if ( res.ok && res.data && res.data.success ) { success( res.data ); }
					else { err.textContent = ( res.data && res.data.message ) || t( 'genericError', 'Something went wrong. Please try again.' ); err.hidden = false; }
				} );
			} );

			setResult( h( 'div', { class: 'sceu-wf__fallback' }, [
				h( 'p', { class: 'sceu-wf__notfound', text: t( 'notFound', "We couldn't match that order. Tell us what you'd like to withdraw from below." ) } ),
				h( 'div', { class: 'sceu-form-control sceu-form-control--has-label' }, [
					h( 'label', { class: 'sceu-form-control__label', text: t( 'describe', 'Describe what you would like to withdraw from' ) } ),
					h( 'div', { class: 'sceu-form-control__input' }, [ textarea ] )
				] ),
				err,
				send
			] ) );
		}
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}
	ready( function () {
		document.querySelectorAll( '[data-sceu-wf]' ).forEach( initForm );
	} );
} )();
