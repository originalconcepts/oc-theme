/* The product order screen: a grid the shop drags into shape.
 *
 * No library. Dragging is pointer events, so a mouse and a finger behave the
 * same, and only the window on screen is ever in the DOM. A drop writes just
 * that window, so a category of two thousand is saved as quickly as one of
 * twenty. */
( function () {
	'use strict';

	var C = window.ocOrder;

	if ( ! C || ! C.rest ) {
		return;
	}

	var T = C.i18n || {};
	var grid = document.getElementById( 'ocord-grid' );
	var pick = document.getElementById( 'ocord-term' );
	var find = document.getElementById( 'ocord-q' );
	var auto = document.getElementById( 'ocord-auto' );
	var more = document.getElementById( 'ocord-more' );
	var said = document.getElementById( 'ocord-said' );
	var note = document.getElementById( 'ocord-note' );
	var tally = document.getElementById( 'ocord-count' );
	var wipe = document.getElementById( 'ocord-clear' );

	var term = 0;
	var total = 0;
	var shown = 0;
	var busy = false;
	var searching = false;

	function say( text, kind ) {
		said.textContent = text || '';
		said.className = 'ocord__said' + ( kind ? ' is-' + kind : '' );

		if ( 'saved' === kind ) {
			setTimeout( function () {
				if ( said.textContent === text ) {
					said.textContent = '';
					said.className = 'ocord__said';
				}
			}, 2000 );
		}
	}

	function ask( path, opts ) {
		opts = opts || {};
		opts.headers = { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' };
		opts.credentials = 'same-origin';

		return fetch( C.rest + path, opts ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( r.status );
			}

			return r.json();
		} );
	}

	/* ---------- the picker ---------- */

	function fillPicker() {
		var shop = document.createElement( 'option' );

		shop.value = '0';
		shop.textContent = T.shop || '';
		pick.appendChild( shop );

		( C.terms || [] ).forEach( function ( t ) {
			var o = document.createElement( 'option' );

			o.value = String( t.id );
			o.textContent = t.name + ' (' + t.count + ')' + ( t.own ? ' •' : '' );
			pick.appendChild( o );
		} );
	}

	/* ---------- the grid ---------- */

	function card( it ) {
		var el = document.createElement( 'div' );

		el.className = 'ocord__card' + ( it.out ? ' is-out' : '' );
		el.setAttribute( 'data-id', it.id );
		el.setAttribute( 'tabindex', '0' );
		el.innerHTML =
			'<span class="ocord__grip" aria-hidden="true"></span>' +
			( it.img ? '<img class="ocord__img" src="' + esc( it.img ) + '" alt="" loading="lazy" width="80" height="80">' : '<span class="ocord__img ocord__img--none"></span>' ) +
			'<span class="ocord__name">' + esc( it.name ) + '</span>' +
			'<span class="ocord__meta"><b>' + esc( it.price ) + '</b>' + ( it.out ? '<em>' + esc( T.nostock || '' ) + '</em>' : '' ) + '</span>' +
			'<span class="ocord__n"></span>';

		return el;
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function renumber() {
		var cards = grid.querySelectorAll( '.ocord__card' );

		Array.prototype.forEach.call( cards, function ( el, i ) {
			el.querySelector( '.ocord__n' ).textContent = String( i + 1 );
		} );
	}

	function load( reset ) {
		if ( busy ) {
			return;
		}

		busy = true;

		if ( reset ) {
			grid.innerHTML = '';
			shown = 0;
		}

		say( T.loading || '' );

		var q = '?term=' + encodeURIComponent( term ) + '&offset=' + shown + '&limit=' + ( C.per || 60 ) +
			( searching ? '&q=' + encodeURIComponent( find.value.trim() ) : '' );

		ask( q ).then( function ( j ) {
			total = j.total || 0;

			( j.items || [] ).forEach( function ( it ) {
				grid.appendChild( card( it ) );
			} );

			shown += ( j.items || [] ).length;
			renumber();
			tally.textContent = ( T.count || '' ).replace( '%1$s', shown ).replace( '%2$s', total );
			more.hidden = shown >= total;

			note.hidden = false;
			note.textContent = searching ? ( T.searchOn || '' ) : ( j.own ? ( T.ownOrder || '' ) : ( T.noOrder || '' ) );
			note.className = 'ocord__note' + ( searching ? ' is-warn' : '' );

			if ( ! shown ) {
				grid.innerHTML = '<p class="ocord__empty">' + esc( T.empty || '' ) + '</p>';
			}

			say( '' );
			busy = false;
		} ).catch( function () {
			say( T.failed || '', 'bad' );
			busy = false;
		} );
	}

	/* ---------- saving ---------- */

	var pending = null;

	function commit() {
		var ids = Array.prototype.map.call( grid.querySelectorAll( '.ocord__card' ), function ( el ) {
			return Number( el.getAttribute( 'data-id' ) );
		} );

		if ( ! ids.length ) {
			return;
		}

		say( T.saving || '' );
		clearTimeout( pending );

		pending = setTimeout( function () {
			ask( '/save', { method: 'POST', body: JSON.stringify( { term: term, from: 0, ids: ids } ) } )
				.then( function () {
					say( T.saved || '', 'saved' );
					note.hidden = false;
					note.textContent = T.ownOrder || '';
					note.className = 'ocord__note';
				} )
				.catch( function () { say( T.failed || '', 'bad' ); } );
		}, 250 );
	}

	/* ---------- dragging ---------- */

	var held = null;
	var ghost = null;
	var startY = 0;
	var startX = 0;
	var moved = false;

	function rtl() {
		return 'rtl' === ( document.documentElement.dir || getComputedStyle( document.documentElement ).direction );
	}

	function cardAt( x, y ) {
		var el = document.elementFromPoint( x, y );

		while ( el && el !== grid ) {
			if ( el.classList && el.classList.contains( 'ocord__card' ) ) {
				return el;
			}

			el = el.parentElement;
		}

		return null;
	}

	grid.addEventListener( 'pointerdown', function ( e ) {
		if ( searching || e.button > 0 ) {
			return;
		}

		var el = cardAt( e.clientX, e.clientY );

		if ( ! el ) {
			return;
		}

		held = el;
		startX = e.clientX;
		startY = e.clientY;
		moved = false;
		grid.setPointerCapture( e.pointerId );
	} );

	grid.addEventListener( 'pointermove', function ( e ) {
		if ( ! held ) {
			return;
		}

		if ( ! moved ) {
			if ( Math.abs( e.clientX - startX ) < 6 && Math.abs( e.clientY - startY ) < 6 ) {
				return;
			}

			moved = true;
			held.classList.add( 'is-held' );
			ghost = held.cloneNode( true );
			ghost.className = 'ocord__card ocord__ghost';
			document.body.appendChild( ghost );
			document.body.classList.add( 'ocord-dragging' );
		}

		e.preventDefault();

		var box = held.getBoundingClientRect();

		ghost.style.width = box.width + 'px';
		ghost.style.left = ( e.clientX - box.width / 2 ) + 'px';
		ghost.style.top = ( e.clientY - 24 ) + 'px';

		var over = cardAt( e.clientX, e.clientY );

		if ( over && over !== held ) {
			var r = over.getBoundingClientRect();
			var midX = r.left + r.width / 2;
			var midY = r.top + r.height / 2;
			var after;

			// Later in reading order, which in Hebrew runs right to left.
			// A clear step up or down settles it; otherwise the side does.
			if ( e.clientY > midY + 6 ) {
				after = true;
			} else if ( e.clientY < midY - 6 ) {
				after = false;
			} else {
				after = rtl() ? e.clientX < midX : e.clientX > midX;
			}

			grid.insertBefore( held, after ? over.nextSibling : over );
			renumber();
		}

		// Near an edge, the page follows.
		var edge = 90;

		if ( e.clientY < edge ) {
			window.scrollBy( 0, -12 );
		} else if ( e.clientY > window.innerHeight - edge ) {
			window.scrollBy( 0, 12 );
		}
	} );

	function letGo() {
		if ( ! held ) {
			return;
		}

		held.classList.remove( 'is-held' );

		if ( ghost ) {
			ghost.remove();
			ghost = null;
		}

		document.body.classList.remove( 'ocord-dragging' );

		if ( moved ) {
			commit();
		}

		held = null;
		moved = false;
	}

	grid.addEventListener( 'pointerup', letGo );
	grid.addEventListener( 'pointercancel', letGo );

	/* ---------- the keyboard, for anyone not using a mouse ---------- */

	grid.addEventListener( 'keydown', function ( e ) {
		if ( searching ) {
			return;
		}

		var el = e.target.closest ? e.target.closest( '.ocord__card' ) : null;

		if ( ! el || ( 'ArrowLeft' !== e.key && 'ArrowRight' !== e.key ) || ! e.altKey ) {
			return;
		}

		e.preventDefault();

		var back = ( 'ArrowRight' === e.key ) === rtl();

		if ( back && el.previousElementSibling ) {
			grid.insertBefore( el, el.previousElementSibling );
		} else if ( ! back && el.nextElementSibling ) {
			grid.insertBefore( el.nextElementSibling, el );
		} else {
			return;
		}

		el.focus();
		renumber();
		commit();
	} );

	/* ---------- the rest of the bar ---------- */

	pick.addEventListener( 'change', function () {
		term = Number( pick.value ) || 0;
		load( true );
	} );

	more.addEventListener( 'click', function () { load( false ); } );

	var typing = null;

	find.addEventListener( 'input', function () {
		clearTimeout( typing );
		typing = setTimeout( function () {
			searching = '' !== find.value.trim();
			load( true );
		}, 300 );
	} );

	auto.addEventListener( 'change', function () {
		var by = auto.value;

		if ( ! by ) {
			return;
		}

		auto.value = '';
		say( T.saving || '' );

		ask( '/auto', { method: 'POST', body: JSON.stringify( { term: term, by: by } ) } )
			.then( function () {
				say( T.saved || '', 'saved' );
				load( true );
			} )
			.catch( function () { say( T.failed || '', 'bad' ); } );
	} );

	wipe.addEventListener( 'click', function () {
		if ( ! window.confirm( T.sure || '' ) ) {
			return;
		}

		ask( '/clear', { method: 'POST', body: JSON.stringify( { term: term } ) } )
			.then( function () {
				say( T.forgot || '', 'saved' );
				load( true );
			} )
			.catch( function () { say( T.failed || '', 'bad' ); } );
	} );

	fillPicker();
	load( true );
}() );
