/**
 * Order picker for the manual "Create invoice" tool.
 *
 * Turns the order field into a search-as-you-type autocomplete: the merchant
 * looks up an order by number / customer / email and clicks one, instead of
 * pasting an internal order ID. The chosen order's id goes into a hidden input
 * that the form submits; a removable chip shows the selection.
 */
( function () {
	var cfg = window.sceuEinv || {};
	if ( ! cfg.searchUrl ) {
		return;
	}

	var pickers = document.querySelectorAll( '[data-sceu-orderpicker]' );
	if ( ! pickers.length ) {
		return;
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	function i18n( key, fallback ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) || fallback;
	}

	pickers.forEach( function ( root ) {
		var input    = root.querySelector( '.sceu-orderpicker__input' );
		var results  = root.querySelector( '.sceu-orderpicker__results' );
		var selected = root.querySelector( '.sceu-orderpicker__selected' );
		var label    = root.querySelector( '.sceu-orderpicker__selected-label' );
		var clearBtn = root.querySelector( '.sceu-orderpicker__clear' );
		var hidden   = root.querySelector( '.sceu-orderpicker__value' );

		if ( ! input || ! results || ! hidden ) {
			return;
		}

		function hideResults() {
			results.hidden = true;
			results.innerHTML = '';
		}

		function message( text ) {
			results.innerHTML = '';
			var li = document.createElement( 'li' );
			li.className = 'sceu-orderpicker__empty';
			li.textContent = text;
			results.appendChild( li );
			results.hidden = false;
		}

		function choose( item ) {
			hidden.value = item.id;
			label.textContent = item.meta ? item.main + '  ·  ' + item.meta : item.main;
			selected.hidden = false;
			input.hidden = true;
			hideResults();
		}

		function render( items ) {
			results.innerHTML = '';
			if ( ! items.length ) {
				message( i18n( 'none', 'No matching orders' ) );
				return;
			}
			items.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.className = 'sceu-orderpicker__option';
				li.setAttribute( 'role', 'option' );
				li.tabIndex = 0;

				var main = document.createElement( 'span' );
				main.className = 'sceu-orderpicker__option-main';
				main.textContent = item.main;

				var meta = document.createElement( 'span' );
				meta.className = 'sceu-orderpicker__option-meta';
				meta.textContent = item.meta || '';

				li.appendChild( main );
				li.appendChild( meta );
				li.addEventListener( 'click', function () {
					choose( item );
				} );
				li.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key || ' ' === e.key ) {
						e.preventDefault();
						choose( item );
					}
				} );
				results.appendChild( li );
			} );
			results.hidden = false;
		}

		var search = debounce( function ( term ) {
			message( i18n( 'searching', 'Searching…' ) );
			var url = cfg.searchUrl + ( cfg.searchUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'q=' + encodeURIComponent( term );
			fetch( url, {
				headers: { 'X-WP-Nonce': cfg.nonce || '' },
				credentials: 'same-origin'
			} )
				.then( function ( r ) {
					return r.ok ? r.json() : [];
				} )
				.then( function ( items ) {
					render( Array.isArray( items ) ? items : [] );
				} )
				.catch( function () {
					hideResults();
				} );
		}, 250 );

		input.addEventListener( 'input', function () {
			var term = input.value.trim();
			if ( term.length < 1 ) {
				hideResults();
				return;
			}
			search( term );
		} );

		// Show recent orders on focus when the field is empty.
		input.addEventListener( 'focus', function () {
			if ( '' === input.value.trim() ) {
				search( '' );
			}
		} );

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				hidden.value = '';
				selected.hidden = true;
				input.hidden = false;
				input.value = '';
				input.focus();
			} );
		}

		// Dismiss the dropdown when clicking elsewhere.
		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) {
				hideResults();
			}
		} );
	} );
} )();
