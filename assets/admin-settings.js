/**
 * EU Helper settings shell — left-nav tab switching.
 *
 * Switches the visible module panel without a page reload, updates the active
 * nav item and the header breadcrumb, and reflects the choice in the URL hash.
 * All panels live in one form, so Save still submits everything via the
 * WordPress Settings API.
 */
( function () {
	var app = document.querySelector( '.sceu-app' );
	if ( ! app ) {
		return;
	}

	var navs   = [].slice.call( app.querySelectorAll( '.sceu-nav__item' ) );
	var panels = [].slice.call( app.querySelectorAll( '.sceu-panel' ) );
	var crumb  = app.querySelector( '[data-sceu-crumb]' );

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

	// Open the panel named in the URL hash (e.g. after saving, WordPress keeps it).
	var hash = ( location.hash || '' ).replace( /^#/, '' );
	if ( hash ) {
		activate( hash );
	}
} )();
