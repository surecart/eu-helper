/**
 * Product-exclusion picker for the EU Helper settings page.
 *
 * Debounced typeahead against the admin-only product-search REST route, with a
 * chips UI. Selected products post as hidden inputs (ids + display labels), so
 * the saved value is a plain list of product ids the server can check in memory.
 * Plain JS, no build step.
 */
( function () {
	var cfg = window.sceuExclusions || {};

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( ctx, args ); }, ms );
		};
	}

	function initPicker( root ) {
		var search   = root.querySelector( '.sceu-excl__search' );
		var results  = root.querySelector( '.sceu-excl__results' );
		var chips    = root.querySelector( '.sceu-excl__chips' );
		var tpl      = root.querySelector( '.sceu-excl__template' );
		var clearBtn = root.querySelector( '.sceu-excl__clear' );
		if ( ! search || ! results || ! chips || ! tpl ) {
			return;
		}

		function selectedIds() {
			return Array.prototype.map.call(
				chips.querySelectorAll( '.sceu-excl__chip' ),
				function ( li ) { return li.getAttribute( 'data-id' ); }
			);
		}

		var i18n      = cfg.i18n || {};
		var status    = root.querySelector( '[data-sceu-excl-status]' );
		var resultsId = results.id || 'sceu-excl-results';
		var options   = []; // { li, item } for the currently-shown results.
		var active    = -1; // Index of the arrow-key-highlighted option.

		function announce( msg ) {
			if ( status ) { status.textContent = msg || ''; }
		}

		// Toggle the in-input spinner + aria-busy while a request is in flight.
		function setLoading( on ) {
			root.classList.toggle( 'is-loading', !! on );
			if ( on ) {
				search.setAttribute( 'aria-busy', 'true' );
			} else {
				search.removeAttribute( 'aria-busy' );
			}
			syncRightSlot();
		}

		// Right-slot owner: spinner while loading, clear (×) when the field has
		// text and is idle, nothing when empty. CSS hides the clear while loading.
		function syncRightSlot() {
			if ( ! clearBtn ) { return; }
			var loading = root.classList.contains( 'is-loading' );
			clearBtn.hidden = loading || '' === search.value;
		}

		// Distinct from "no results": the request itself failed, so say so rather
		// than implying the search simply found nothing.
		function renderError() {
			results.innerHTML = '';
			options = [];
			active = -1;
			var li = document.createElement( 'li' );
			li.className = 'sceu-excl__result is-error';
			li.setAttribute( 'aria-disabled', 'true' );
			li.textContent = i18n.error || 'Couldn’t search products. Please try again.';
			results.appendChild( li );
			announce( li.textContent );
			results.hidden = false;
			search.setAttribute( 'aria-expanded', 'true' );
		}

		// ARIA combobox: collapse the listbox and reset its state.
		function clearResults() {
			results.innerHTML = '';
			results.hidden = true;
			options = [];
			active = -1;
			search.setAttribute( 'aria-expanded', 'false' );
			search.removeAttribute( 'aria-activedescendant' );
		}

		function addChip( id, name ) {
			if ( ! id || selectedIds().indexOf( id ) !== -1 ) {
				return;
			}
			var frag = tpl.content.cloneNode( true );
			var li   = frag.querySelector( '.sceu-excl__chip' );
			li.setAttribute( 'data-id', id );
			li.querySelector( '.sceu-excl__chip-label' ).textContent = name;

			// Per-chip accessible name so each remove button is distinguishable.
			var removeBtn = li.querySelector( '.sceu-excl__remove' );
			if ( removeBtn && i18n.remove ) {
				removeBtn.setAttribute( 'aria-label', i18n.remove.replace( '%s', name ) );
			}

			var idInput = li.querySelector( '[data-name-ids]' );
			idInput.setAttribute( 'name', idInput.getAttribute( 'data-name-ids' ) );
			idInput.value = id;

			var labelInput = li.querySelector( '[data-name-labels]' );
			labelInput.setAttribute( 'name', labelInput.getAttribute( 'data-name-labels' ) + '[' + id + ']' );
			labelInput.value = name;

			chips.appendChild( li );
		}

		function choose( item ) {
			addChip( item.id, item.name );
			search.value = '';
			clearResults();
			syncRightSlot();
			search.focus();
		}

		// Move the arrow-key highlight (wraps around) and point
		// aria-activedescendant at the highlighted option.
		function setActive( idx ) {
			if ( ! options.length ) { return; }
			if ( idx < 0 ) { idx = options.length - 1; }
			if ( idx >= options.length ) { idx = 0; }
			options.forEach( function ( o, i ) {
				o.li.classList.toggle( 'is-active', i === idx );
				o.li.setAttribute( 'aria-selected', i === idx ? 'true' : 'false' );
			} );
			active = idx;
			search.setAttribute( 'aria-activedescendant', options[ idx ].li.id );
			options[ idx ].li.scrollIntoView( { block: 'nearest' } );
		}

		function renderResults( items ) {
			results.innerHTML = '';
			options = [];
			active = -1;
			var sel = selectedIds();
			items.forEach( function ( it ) {
				if ( sel.indexOf( it.id ) !== -1 ) {
					return;
				}
				var li = document.createElement( 'li' );
				li.className = 'sceu-excl__result';
				li.id = resultsId + '-opt-' + options.length;
				li.textContent = it.name;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				// mousedown would blur the input before click; prevent it so focus stays.
				li.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
				li.addEventListener( 'click', function () { choose( it ); } );
				results.appendChild( li );
				options.push( { li: li, item: it } );
			} );

			if ( ! options.length ) {
				var empty = document.createElement( 'li' );
				empty.className = 'sceu-excl__result is-empty';
				empty.setAttribute( 'aria-disabled', 'true' );
				empty.textContent = i18n.noResults || 'No matching products';
				results.appendChild( empty );
				announce( empty.textContent );
			} else {
				announce( ( i18n.results || '%d' ).replace( '%d', options.length ) );
			}
			results.hidden = false;
			search.setAttribute( 'aria-expanded', 'true' );
		}

		// Per-query result cache + a handle to the in-flight request, so we don't
		// refetch a query we've already seen and don't let overlapping requests
		// race (an earlier, slower response overwriting a newer one).
		var cache      = {};
		var controller = null;

		var doSearch = debounce( function () {
			var q = search.value.trim();
			if ( q.length < 2 ) { setLoading( false ); clearResults(); return; }

			var key = q.toLowerCase();
			if ( cache[ key ] ) { setLoading( false ); renderResults( cache[ key ] ); return; }

			// Supersede any request still in flight for an earlier keystroke.
			if ( controller ) { controller.abort(); }
			controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;
			var mine = controller; // identity check so only the latest request clears state.

			setLoading( true );
			fetch( cfg.searchUrl + '?q=' + encodeURIComponent( q ), {
				headers: { 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin',
				signal: controller ? controller.signal : undefined
			} )
				.then( function ( r ) {
					// A failed route (e.g. 404) is an error, not an empty result —
					// surface it rather than masking it as "No matching products".
					if ( ! r.ok ) { throw new Error( 'http_' + r.status ); }
					return r.json();
				} )
				.then( function ( items ) {
					var list = Array.isArray( items ) ? items : [];
					cache[ key ] = list;
					if ( controller !== mine ) { return; } // a newer request is in flight.
					setLoading( false );
					renderResults( list );
				} )
				.catch( function ( e ) {
					if ( e && 'AbortError' === e.name ) { return; } // superseded — newer request owns the spinner.
					if ( controller !== mine ) { return; }
					setLoading( false );
					renderError();
				} );
		}, 350 );

		search.addEventListener( 'input', doSearch );
		// Update the clear (×) visibility immediately, not on the debounced search.
		search.addEventListener( 'input', syncRightSlot );

		if ( clearBtn ) {
			// mousedown would blur the input first; prevent it so focus stays put.
			clearBtn.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
			clearBtn.addEventListener( 'click', function () {
				if ( controller ) { controller.abort(); }
				search.value = '';
				setLoading( false );
				clearResults();
				syncRightSlot();
				search.focus();
			} );
		}
		// Combobox keyboard model: arrows move the highlight, Enter picks it,
		// Escape closes. Enter also never submits the settings form.
		search.addEventListener( 'keydown', function ( e ) {
			if ( results.hidden || ! options.length ) {
				if ( e.key === 'Enter' ) { e.preventDefault(); }
				return;
			}
			if ( e.key === 'ArrowDown' ) { e.preventDefault(); setActive( active + 1 ); }
			else if ( e.key === 'ArrowUp' ) { e.preventDefault(); setActive( active - 1 ); }
			else if ( e.key === 'Enter' ) {
				e.preventDefault();
				if ( active >= 0 && options[ active ] ) { choose( options[ active ].item ); }
			} else if ( e.key === 'Escape' ) {
				clearResults();
			}
		} );

		chips.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.sceu-excl__remove' );
			if ( btn ) {
				var li = btn.closest( '.sceu-excl__chip' );
				if ( li && li.parentNode ) { li.parentNode.removeChild( li ); }
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! root.contains( e.target ) ) { clearResults(); }
		} );
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		document.querySelectorAll( '[data-sceu-excl]' ).forEach( initPicker );
	} );
} )();
