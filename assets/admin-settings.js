/**
 * EU Helper settings shell — left-nav tab switching.
 *
 * Switches the visible module panel without a page reload, updates the active
 * nav item and the header breadcrumb, and reflects the choice in the URL hash.
 * All panels live in one form, so Save still submits everything via the
 * WordPress Settings API. Because saving reloads the page (and the URL fragment
 * is lost across the redirect), the active tab is also remembered in
 * sessionStorage so you return to the module you were editing — not the first one.
 *
 * It also warns before leaving with unsaved edits (native browser prompt).
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
			// Convey the active item to AT (the .is-active styling is colour-only).
			if ( on ) {
				n.setAttribute( 'aria-current', 'page' );
			} else {
				n.removeAttribute( 'aria-current' );
			}
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

	// Warn before leaving with unsaved edits.
	var form = app.querySelector( '.sceu-settings__form' );
	if ( form ) {
		var dirty = false;
		var markDirty = function () {
			dirty = true;
		};
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! dirty ) {
				return;
			}
			e.preventDefault();
			e.returnValue = ''; // Required for the native prompt to show.
		} );
	}

	// Make the action banners dismissible. They are driven by one-shot query args
	// (resent, updated, …) that the post-action redirect adds, so on dismiss we
	// also strip those args — otherwise a refresh would show the message again.
	function cleanNoticeArgs() {
		if ( ! window.history || ! history.replaceState ) {
			return;
		}
		try {
			var url = new URL( window.location.href );
			[ 'resent', 'updated', 'deleted', 'synced', 'exclusions_synced', 'settings-updated' ].forEach( function ( k ) {
				url.searchParams.delete( k );
			} );
			history.replaceState( null, '', url.toString() );
		} catch ( e ) {}
	}

	// Strip one-shot notice args on load so a refresh won't replay the banner.
	cleanNoticeArgs();

	[].slice.call( app.querySelectorAll( '.sceu-notice' ) ).forEach( function ( notice ) {
		var dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'sceu-notice__dismiss';
		dismiss.setAttribute( 'aria-label', ( window.sceuSettings && window.sceuSettings.i18n && window.sceuSettings.i18n.dismiss ) || 'Dismiss this notice' );
		dismiss.innerHTML = '&times;';
		dismiss.addEventListener( 'click', function () {
			notice.parentNode.removeChild( notice );
			cleanNoticeArgs();
		} );
		notice.appendChild( dismiss );
	} );
} )();
