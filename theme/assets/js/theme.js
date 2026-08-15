/**
 * Theme front end.
 *
 * Vanilla. No jQuery, no Slick, no Swiper (DECISIONS.md). Block behaviour ships
 * with each block via block.json viewScript, so this file stays small.
 */
( function () {
	'use strict';

	document.documentElement.classList.add( 'oc-ready' );

	var burger = document.querySelector( '.oc-burger' );
	var menu = document.getElementById( 'oc-mobile-menu' );

	if ( ! burger || ! menu ) {
		return;
	}

	burger.addEventListener( 'click', function () {
		var open = burger.getAttribute( 'aria-expanded' ) === 'true';

		burger.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
		menu.hidden = open;
		menu.setAttribute( 'data-open', open ? 'false' : 'true' );
	} );

	// Escape closes the menu and returns focus to the button.
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Escape' || menu.hidden ) {
			return;
		}

		burger.setAttribute( 'aria-expanded', 'false' );
		menu.hidden = true;
		menu.setAttribute( 'data-open', 'false' );
		burger.focus();
	} );
}() );
