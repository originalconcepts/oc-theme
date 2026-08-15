/**
 * Theme front end.
 *
 * Vanilla. No jQuery, no Slick, no Swiper (DECISIONS.md). Block behaviour
 * ships with each block via block.json viewScript, so this file stays small:
 * mobile menu, card-gallery dots, tabs→accordion, sticky add-to-cart.
 */
( function () {
	'use strict';

	document.documentElement.classList.add( 'oc-ready' );

	/* ---------- mobile menu ---------- */

	var burger = document.querySelector( '.oc-burger' );
	var menu = document.getElementById( 'oc-mobile-menu' );

	function setMenu( open ) {
		burger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		menu.hidden = ! open;
		menu.setAttribute( 'data-open', open ? 'true' : 'false' );
	}

	if ( burger && menu ) {
		burger.addEventListener( 'click', function () {
			setMenu( burger.getAttribute( 'aria-expanded' ) !== 'true' );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! menu.hidden ) {
				setMenu( false );
				burger.focus();
			}
		} );
	}

	/* ---------- card gallery: active dot follows the scroll ---------- */

	document.querySelectorAll( '.oc-card-media--gallery' ).forEach( function ( media ) {
		var strip = media.querySelector( '.oc-card-media__strip' );
		var dots = media.querySelectorAll( '.oc-card-media__dots i' );

		if ( ! strip || ! dots.length ) {
			return;
		}

		var ticking = false;

		strip.addEventListener( 'scroll', function () {
			if ( ticking ) {
				return;
			}
			ticking = true;

			requestAnimationFrame( function () {
				ticking = false;

				var index = Math.round(
					Math.abs( strip.scrollLeft ) / strip.clientWidth
				);

				dots.forEach( function ( dot, i ) {
					dot.classList.toggle( 'is-on', i === index );
				} );
			} );
		}, { passive: true } );
	} );

	/* ---------- product tabs → accordion ---------- */

	if ( document.body.classList.contains( 'oc-tabs-accordion' ) ) {
		document.querySelectorAll( '.woocommerce-Tabs-panel' ).forEach( function ( panel, i ) {
			var heading = panel.querySelector( 'h2' );
			var title = heading ? heading.textContent : panel.getAttribute( 'aria-labelledby' );
			var open = 0 === i;

			var head = document.createElement( 'button' );
			head.type = 'button';
			head.className = 'oc-acc-head';
			head.textContent = title || '';
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			panel.prepend( head );

			if ( ! open ) {
				panel.setAttribute( 'data-oc-closed', '' );
			}

			head.addEventListener( 'click', function () {
				var closed = panel.hasAttribute( 'data-oc-closed' );

				if ( closed ) {
					panel.removeAttribute( 'data-oc-closed' );
				} else {
					panel.setAttribute( 'data-oc-closed', '' );
				}

				head.setAttribute( 'aria-expanded', closed ? 'true' : 'false' );
			} );
		} );
	}

	/* ---------- sticky add-to-cart ---------- */

	var bar = document.querySelector( '[data-oc-sticky-atc]' );
	var form = document.querySelector( 'form.cart' );

	if ( bar && form && 'IntersectionObserver' in window ) {
		bar.hidden = false;

		new IntersectionObserver(
			function ( entries ) {
				bar.classList.toggle(
					'is-visible',
					! entries[ 0 ].isIntersecting &&
						entries[ 0 ].boundingClientRect.top < 0
				);
			},
			{ threshold: 0 }
		).observe( form );

		var proxy = bar.querySelector( '.oc-sticky-atc__btn' );
		var submit = form.querySelector( '[type="submit"]' );

		if ( proxy ) {
			proxy.addEventListener( 'click', function () {
				// Simple products buy straight away; variable products need the
				// visitor to pick options first, so scroll them to the form.
				if ( submit && ! submit.disabled && ! form.classList.contains( 'variations_form' ) ) {
					submit.click();
					return;
				}

				form.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			} );
		}
	}
}() );
