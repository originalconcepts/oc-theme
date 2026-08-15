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

	/* ---------- card gallery: hover arrows drive the scroll-snap strip ---------- */

	document.querySelectorAll( '.oc-card-media--gallery' ).forEach( function ( media ) {
		var strip = media.querySelector( '.oc-card-media__strip' );

		if ( ! strip ) {
			return;
		}

		// In RTL scrollLeft runs negative. Direct assignment + CSS
		// scroll-behavior:smooth animates in real browsers and still moves in
		// environments with a frozen animation pipeline.
		function step( dir ) {
			var rtl = getComputedStyle( strip ).direction === 'rtl';
			strip.scrollLeft += ( rtl ? -dir : dir ) * strip.clientWidth;
		}

		media.querySelectorAll( '.oc-card-media__nav' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				step( btn.classList.contains( 'oc-card-media__nav--next' ) ? 1 : -1 );
			} );
		} );
	} );

	/* ---------- card add-to-cart icon → cart drawer ---------- */

	var drawer = document.querySelector( '[data-oc-cart-drawer]' );

	function openDrawer() {
		if ( ! drawer ) {
			return;
		}
		drawer.hidden = false;
		setTimeout( function () {
			drawer.classList.add( 'is-open' );
		}, 10 );
		document.documentElement.style.overflow = 'hidden';
	}

	function closeDrawer() {
		if ( ! drawer ) {
			return;
		}
		drawer.classList.remove( 'is-open' );
		document.documentElement.style.overflow = '';
		setTimeout( function () {
			drawer.hidden = true;
		}, 220 );
	}

	if ( drawer ) {
		drawer.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-oc-drawer-close]' ) ) {
				closeDrawer();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && ! drawer.hidden ) {
				closeDrawer();
			}
		} );

		// Simple products add through Woo's own ajax handler; open the drawer
		// right away and let cart fragments fill it when the add completes.
		document.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.oc-card-atc.ajax_add_to_cart' );
			if ( ! btn ) {
				return;
			}
			btn.classList.add( 'loading' );
			openDrawer();
			// Woo's add-to-cart JS handles the request itself.
			setTimeout( function () {
				btn.classList.remove( 'loading' );
				btn.classList.add( 'added' );
			}, 900 );
		} );
	}

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

	/* ---------- native product gallery thumbs (no flexslider) ---------- */

	var galleryWrap = document.querySelector( '.woocommerce-product-gallery__wrapper' );
	var galleryBody = document.body;

	if ( galleryWrap && ( galleryBody.classList.contains( 'oc-gallery-thumbs-side' ) ||
		galleryBody.classList.contains( 'oc-gallery-thumbs-under' ) ) ) {

		var slides = Array.prototype.slice.call(
			galleryWrap.querySelectorAll( '.woocommerce-product-gallery__image' )
		);

		if ( slides.length > 1 ) {
			var maxThumbs = parseInt( galleryBody.dataset.ocThumbsMax || '10', 10 );
			var rail = document.createElement( 'ol' );
			rail.className = 'oc-thumbs';

			slides[ 0 ].classList.add( 'is-active' );

			slides.slice( 0, maxThumbs ).forEach( function ( slide, i ) {
				var src = slide.querySelector( 'img' );
				if ( ! src ) {
					return;
				}

				var li = document.createElement( 'li' );
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.setAttribute( 'aria-current', 0 === i ? 'true' : 'false' );

				var thumb = document.createElement( 'img' );
				thumb.src = src.currentSrc || src.src;
				thumb.alt = '';
				thumb.loading = 'lazy';

				btn.appendChild( thumb );
				li.appendChild( btn );
				rail.appendChild( li );

				btn.addEventListener( 'click', function () {
					slides.forEach( function ( other ) {
						other.classList.remove( 'is-active' );
					} );
					slide.classList.add( 'is-active' );
					rail.querySelectorAll( 'button' ).forEach( function ( other ) {
						other.setAttribute( 'aria-current', 'false' );
					} );
					btn.setAttribute( 'aria-current', 'true' );
				} );
			} );

			galleryWrap.parentElement.appendChild( rail );
		}
	}

	/* ---------- gallery: plus circle follows the cursor (asceno-style) ---------- */

	var gallery = document.querySelector( '.woocommerce-product-gallery' );

	if ( gallery && ! document.body.classList.contains( 'oc-no-lightbox' ) &&
		window.matchMedia( '(hover: hover)' ).matches ) {

		var plus = document.createElement( 'span' );
		plus.className = 'oc-cursor-plus';
		plus.setAttribute( 'aria-hidden', 'true' );
		document.body.appendChild( plus );

		gallery.addEventListener( 'mousemove', function ( event ) {
			if ( event.target.closest( '.flex-control-thumbs' ) ) {
				plus.classList.remove( 'is-on' );
				return;
			}
			if ( event.target.closest( '.woocommerce-product-gallery__image' ) ) {
				plus.classList.add( 'is-on' );
				plus.style.insetBlockStart = event.clientY + 'px';
				plus.style.insetInlineStart = event.clientX + 'px';
			} else {
				plus.classList.remove( 'is-on' );
			}
		} );

		gallery.addEventListener( 'mouseleave', function () {
			plus.classList.remove( 'is-on' );
		} );
	}

	/* ---------- sticky add-to-cart ---------- */

	var bar = document.querySelector( '[data-oc-sticky-atc]' );
	var form = document.querySelector( 'form.cart' );

	if ( bar && form ) {
		bar.hidden = false;

		function updateBar() {
			// Show once the buy form has scrolled above the viewport. One rect
			// read and a class toggle — cheap enough to run unthrottled.
			bar.classList.toggle( 'is-visible', form.getBoundingClientRect().bottom < 0 );
		}

		window.addEventListener( 'scroll', updateBar, { passive: true } );
		updateBar();

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
