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
		var search  = root.querySelector( '.sceu-excl__search' );
		var results = root.querySelector( '.sceu-excl__results' );
		var chips   = root.querySelector( '.sceu-excl__chips' );
		var tpl     = root.querySelector( '.sceu-excl__template' );
		if ( ! search || ! results || ! chips || ! tpl ) {
			return;
		}

		function selectedIds() {
			return Array.prototype.map.call(
				chips.querySelectorAll( '.sceu-excl__chip' ),
				function ( li ) { return li.getAttribute( 'data-id' ); }
			);
		}

		function clearResults() {
			results.innerHTML = '';
			results.hidden = true;
		}

		function addChip( id, name ) {
			if ( ! id || selectedIds().indexOf( id ) !== -1 ) {
				return;
			}
			var frag = tpl.content.cloneNode( true );
			var li   = frag.querySelector( '.sceu-excl__chip' );
			li.setAttribute( 'data-id', id );
			li.querySelector( '.sceu-excl__chip-label' ).textContent = name;

			var idInput = li.querySelector( '[data-name-ids]' );
			idInput.setAttribute( 'name', idInput.getAttribute( 'data-name-ids' ) );
			idInput.value = id;

			var labelInput = li.querySelector( '[data-name-labels]' );
			labelInput.setAttribute( 'name', labelInput.getAttribute( 'data-name-labels' ) + '[' + id + ']' );
			labelInput.value = name;

			chips.appendChild( li );
		}

		function renderResults( items ) {
			results.innerHTML = '';
			var sel = selectedIds();
			var shown = 0;
			items.forEach( function ( it ) {
				if ( sel.indexOf( it.id ) !== -1 ) {
					return;
				}
				var li = document.createElement( 'li' );
				li.className = 'sceu-excl__result';
				li.textContent = it.name;
				li.setAttribute( 'role', 'button' );
				li.tabIndex = 0;
				li.addEventListener( 'click', function () {
					addChip( it.id, it.name );
					search.value = '';
					clearResults();
					search.focus();
				} );
				li.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); li.click(); }
				} );
				results.appendChild( li );
				shown++;
			} );

			if ( shown === 0 ) {
				var empty = document.createElement( 'li' );
				empty.className = 'sceu-excl__result is-empty';
				empty.textContent = ( cfg.i18n && cfg.i18n.noResults ) || 'No matching products';
				results.appendChild( empty );
			}
			results.hidden = false;
		}

		// Per-query result cache + a handle to the in-flight request, so we don't
		// refetch a query we've already seen and don't let overlapping requests
		// race (an earlier, slower response overwriting a newer one).
		var cache      = {};
		var controller = null;

		var doSearch = debounce( function () {
			var q = search.value.trim();
			if ( q.length < 2 ) { clearResults(); return; }

			var key = q.toLowerCase();
			if ( cache[ key ] ) { renderResults( cache[ key ] ); return; }

			// Supersede any request still in flight for an earlier keystroke.
			if ( controller ) { controller.abort(); }
			controller = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

			fetch( cfg.searchUrl + '?q=' + encodeURIComponent( q ), {
				headers: { 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin',
				signal: controller ? controller.signal : undefined
			} )
				.then( function ( r ) { return r.ok ? r.json() : []; } )
				.then( function ( items ) {
					var list = Array.isArray( items ) ? items : [];
					cache[ key ] = list;
					renderResults( list );
				} )
				.catch( function ( e ) {
					if ( e && 'AbortError' === e.name ) { return; } // superseded — ignore.
					clearResults();
				} );
		}, 350 );

		search.addEventListener( 'input', doSearch );
		// Don't let Enter in the search box submit the settings form.
		search.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); }
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
