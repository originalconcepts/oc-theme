/* Arranging the catalogue from the catalogue.
 *
 * Loaded only for an administrator who asked for it, on the page they are
 * standing on. The products already on the page become draggable; what is
 * saved is that page's window, so page three of a category is arranged
 * without disturbing pages one and two. */
( function () {
	'use strict';

	var C = window.ocOrderFront;

	if ( ! C || ! C.rest ) {
		return;
	}

	var T = C.i18n || {};
	var wrap = document.querySelector( 'ul.products' );

	if ( ! wrap ) {
		return;
	}

	var chosen = [];
	var held = null;
	var ghost = null;
	var startX = 0;
	var startY = 0;
	var moved = false;
	var train = [];
	var said = null;

	function rtl() {
		return 'rtl' === ( document.documentElement.dir || getComputedStyle( document.documentElement ).direction );
	}

	function items() {
		return wrap.querySelectorAll( 'li.product' );
	}

	function idOf( el ) {
		var m = /post-(\d+)/.exec( el.className || '' );

		return m ? Number( m[ 1 ] ) : 0;
	}

	/* ---------- the bar ---------- */

	function bar() {
		var b = document.createElement( 'div' );

		b.className = 'ocsort__bar';
		b.innerHTML =
			'<strong>' + T.title + '</strong>' +
			'<span class="ocsort__help">' + T.help + '</span>' +
			'<span class="ocsort__said" role="status" aria-live="polite"></span>' +
			'<button type="button" class="ocsort__done"></button>';

		b.querySelector( '.ocsort__done' ).textContent = T.done;
		b.querySelector( '.ocsort__done' ).addEventListener( 'click', function () {
			var u = new URL( location.href );

			u.searchParams.delete( 'oc_sort' );
			location.href = u.toString();
		} );

		document.body.appendChild( b );
		document.body.classList.add( 'ocsort-on' );
		said = b.querySelector( '.ocsort__said' );
	}

	function say( text, kind ) {
		said.textContent = text || '';
		said.className = 'ocsort__said' + ( kind ? ' is-' + kind : '' );

		if ( 'saved' === kind ) {
			setTimeout( function () {
				if ( said.textContent === text ) {
					say( '' );
				}
			}, 2000 );
		}
	}

	/* ---------- saving ---------- */

	var pending = null;

	function commit() {
		var ids = Array.prototype.map.call( items(), idOf ).filter( Boolean );

		if ( ! ids.length ) {
			return;
		}

		say( T.saving );
		clearTimeout( pending );

		pending = setTimeout( function () {
			fetch( C.rest + '/save', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify( { term: C.term, from: C.from, ids: ids } )
			} ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}

				say( T.saved, 'saved' );
			} ).catch( function () {
				say( T.failed, 'bad' );
			} );
		}, 300 );
	}

	/* ---------- choosing and dragging ---------- */

	function unpick() {
		chosen = [];

		Array.prototype.forEach.call( items(), function ( el ) {
			el.classList.remove( 'is-picked' );
			el.removeAttribute( 'data-pick' );
		} );

		say( '' );
	}

	function toggle( el ) {
		var i = chosen.indexOf( el );

		if ( i > -1 ) {
			chosen.splice( i, 1 );
			el.classList.remove( 'is-picked' );
			el.removeAttribute( 'data-pick' );
		} else {
			chosen.push( el );
			el.classList.add( 'is-picked' );
		}

		chosen.forEach( function ( n, k ) {
			n.setAttribute( 'data-pick', String( k + 1 ) );
		} );

		say( chosen.length ? T.picked.replace( '%d', chosen.length ) : '' );
	}

	function at( x, y ) {
		var el = document.elementFromPoint( x, y );

		while ( el && el !== wrap ) {
			if ( el.classList && el.classList.contains( 'product' ) && el.parentElement === wrap ) {
				return el;
			}

			el = el.parentElement;
		}

		return null;
	}

	function mine( e ) {
		return e.target && e.target.closest && e.target.closest( '[data-oc-keep]' );
	}

	wrap.addEventListener( 'click', function ( e ) {
		// While arranging, a product is something to move, not to open —
		// unless it is a control that asked to keep its own clicks.
		if ( mine( e ) ) {
			return;
		}

		e.preventDefault();
		e.stopPropagation();
	}, true );

	wrap.addEventListener( 'pointerdown', function ( e ) {
		if ( e.button > 0 || mine( e ) ) {
			return;
		}

		var el = at( e.clientX, e.clientY );

		if ( ! el ) {
			return;
		}

		held = el;
		startX = e.clientX;
		startY = e.clientY;
		moved = false;
		wrap.setPointerCapture( e.pointerId );
	} );

	wrap.addEventListener( 'pointermove', function ( e ) {
		if ( ! held ) {
			return;
		}

		if ( ! moved ) {
			if ( Math.abs( e.clientX - startX ) < 8 && Math.abs( e.clientY - startY ) < 8 ) {
				return;
			}

			moved = true;
			train = chosen.indexOf( held ) > -1 ? chosen.slice() : [];
			held.classList.add( 'is-held' );
			train.forEach( function ( n ) { n.classList.add( 'is-held' ); } );

			var r = held.getBoundingClientRect();

			ghost = held.cloneNode( true );
			ghost.className = 'ocsort__ghost';
			ghost.style.width = r.width + 'px';

			if ( train.length > 1 ) {
				ghost.setAttribute( 'data-many', String( train.length ) );
			}

			document.body.appendChild( ghost );
			document.body.classList.add( 'ocsort-dragging' );
		}

		e.preventDefault();

		var box = held.getBoundingClientRect();

		ghost.style.left = ( e.clientX - box.width / 2 ) + 'px';
		ghost.style.top = ( e.clientY - 30 ) + 'px';

		var over = at( e.clientX, e.clientY );

		if ( over && over !== held && train.indexOf( over ) === -1 ) {
			var o = over.getBoundingClientRect();
			var midX = o.left + o.width / 2;
			var midY = o.top + o.height / 2;
			var after;

			if ( e.clientY > midY + 8 ) {
				after = true;
			} else if ( e.clientY < midY - 8 ) {
				after = false;
			} else {
				after = rtl() ? e.clientX < midX : e.clientX > midX;
			}

			wrap.insertBefore( held, after ? over.nextSibling : over );
		}

		var edge = 100;

		if ( e.clientY < edge ) {
			window.scrollBy( 0, -14 );
		} else if ( e.clientY > window.innerHeight - edge ) {
			window.scrollBy( 0, 14 );
		}
	} );

	function letGo() {
		if ( ! held ) {
			return;
		}

		if ( ghost ) {
			ghost.remove();
			ghost = null;
		}

		document.body.classList.remove( 'ocsort-dragging' );

		if ( moved ) {
			var mark = held;

			train.forEach( function ( n ) {
				if ( n === held ) {
					return;
				}

				wrap.insertBefore( n, mark.nextSibling );
				mark = n;
			} );

			held.classList.remove( 'is-held' );
			train.forEach( function ( n ) { n.classList.remove( 'is-held' ); } );
			unpick();
			commit();
		} else {
			held.classList.remove( 'is-held' );
			toggle( held );
		}

		held = null;
		moved = false;
		train = [];
	}

	wrap.addEventListener( 'pointerup', letGo );
	wrap.addEventListener( 'pointercancel', letGo );

	// Inside the phone preview the bar belongs to the page holding the frame.
	if ( ! C.frame ) {
		bar();
	} else {
		said = document.createElement( 'span' );
	}

	wrap.classList.add( 'ocsort__list' );
}() );
