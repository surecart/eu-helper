/**
 * EU Helper settings shell — left-nav tab switching.
 *
 * Switches the visible module panel without a page reload, updates the active
 * nav item and the header breadcrumb, and reflects the choice in the URL hash.
 * All panels live in one form, so Save still submits everything via the
 * WordPress Settings API. Because saving reloads the page (and the URL fragment
 * is lost across the redirect), the active tab is also remembered in
 * sessionStorage so you return to the module you were editing — not the first one.
 */
( function () {
	var app = document.querySelector( '.sceu-app' );
	if ( ! app ) {
		return;
	}

	var navs   = [].slice.call( app.querySelectorAll( '.sceu-nav__item' ) );
	var panels = [].slice.call( app.querySelectorAll( '.sceu-panel' ) );
	var crumb  = app.querySelector( '[data-sceu-crumb]' );
	var STORE  = 'sceuActiveTab';

	function remember( id ) {
		try {
			window.sessionStorage.setItem( STORE, id );
		} catch ( e ) {}
	}

	function recall() {
		try {
			return window.sessionStorage.getItem( STORE ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function activate( id ) {
		var matched = false;
		navs.forEach( function ( n ) {
			var on = n.getAttribute( 'data-sceu-tab' ) === id;
			n.classList.toggle( 'is-active', on );
			if ( on ) {
				matched = true;
				if ( crumb ) {
					crumb.textContent = ( n.textContent || '' ).trim();
				}
			}
		} );
		panels.forEach( function ( p ) {
			var on = p.getAttribute( 'data-sceu-panel' ) === id;
			p.hidden = ! on;
			p.classList.toggle( 'is-active', on );
		} );
		if ( matched ) {
			remember( id );
		}
		return matched;
	}

	navs.forEach( function ( n ) {
		n.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var id = n.getAttribute( 'data-sceu-tab' );
			activate( id );
			if ( window.history && history.replaceState ) {
				history.replaceState( null, '', '#' + id );
			} else {
				location.hash = id;
			}
		} );
	} );

	// Restore the open panel: the URL hash wins (shareable), else the last tab the
	// user was on (survives the Save reload).
	var hash = ( location.hash || '' ).replace( /^#/, '' );
	if ( hash ) {
		activate( hash );
	} else {
		var saved = recall();
		if ( saved && activate( saved ) && window.history && history.replaceState ) {
			history.replaceState( null, '', '#' + saved );
		}
	}
} )();
