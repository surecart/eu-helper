/**
 * Dismissible shell notices.
 *
 * The server-rendered `.sceu-notice` banners (withdrawal log, e-invoicing) are
 * static markup with no close control — unlike the settings app's notices,
 * which are WordPress `@wordpress/components` notices and dismiss themselves.
 * This mirrors that affordance: it injects a close button into every
 * `.sceu-notice` (matching the `.sceu-notice__dismiss` styles the shell
 * stylesheet already ships, including the reserved right padding) so every
 * notice behaves the same. Injecting at runtime keeps the PHP markup untouched
 * and covers any future banner with no extra work.
 */
( function () {
	var cfg   = window.sceuNotice || {};
	var label = cfg.dismissLabel || 'Dismiss this notice';

	/**
	 * Append an accessible close button to a notice (once).
	 *
	 * @param {HTMLElement} notice The `.sceu-notice` banner.
	 */
	function addDismiss( notice ) {
		if ( notice.querySelector( '.sceu-notice__dismiss' ) ) {
			return;
		}

		var button       = document.createElement( 'button' );
		button.type      = 'button';
		button.className = 'sceu-notice__dismiss';
		button.setAttribute( 'aria-label', label );

		// The × is decorative; the button is named by its aria-label.
		var glyph = document.createElement( 'span' );
		glyph.setAttribute( 'aria-hidden', 'true' );
		glyph.textContent = '×';
		button.appendChild( glyph );

		button.addEventListener( 'click', function () {
			if ( notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		} );

		notice.appendChild( button );
	}

	function init() {
		var notices = document.querySelectorAll( '.sceu-notice' );
		Array.prototype.forEach.call( notices, addDismiss );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
