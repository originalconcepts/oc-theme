/* The product order screen: a grid the shop drags into shape.
 *
 * No library. Dragging is pointer events, so a mouse and a finger behave the
 * same. Only the window on screen is ever in the DOM, and a drop writes just
 * that window, so a category of two thousand saves as quickly as one of ten. */
( function () {
	'use strict';

	var C = window.ocOrder;

	if ( ! C || ! C.rest ) {
		return;
	}

	var T = C.i18n || {};
	var grid = document.getElementById( 'ocord-grid' );
	var box = document.getElementById( 'ocord-term' );
	var list = document.getElementById( 'ocord-list' );
	var perRow = document.getElementById( 'ocord-row' );
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
	var chosen = [];

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function rtl() {
		return 'rtl' === ( document.documentElement.dir || getComputedStyle( document.documentElement ).direction );
	}

	function say( text, kind ) {
		said.textContent = text || '';
		said.className = 'ocord__said' + ( kind ? ' is-' + kind : '' );

		if ( 'saved' === kind ) {
			setTimeout( function () {
				if ( said.textContent === text ) {
					say( '' );
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

	/* ---------- the category, typed rather than hunted for ---------- */

	var all = [ { id: 0, name: T.shop || '', count: 0, own: 0 } ].concat( C.terms || [] );
	var at = -1;

	function label( t ) {
		return t.name + ( t.id ? ' (' + t.count + ')' : '' ) + ( t.own ? ' •' : '' );
	}

	function offer( text ) {
		var want = String( text || '' ).trim().toLowerCase();
		var hits = all.filter( function ( t ) {
			return '' === want || t.name.toLowerCase().indexOf( want ) > -1;
		} ).slice( 0, 40 );

		list.innerHTML = '';
		at = -1;

		if ( ! hits.length ) {
			list.innerHTML = '<li class="ocord__none">' + esc( T.nocat || '' ) + '</li>';
		}

		hits.forEach( function ( t ) {
			var li = document.createElement( 'li' );

			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'data-id', String( t.id ) );
			li.textContent = label( t );
			li.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				take( t );
			} );
			list.appendChild( li );
		} );

		list.hidden = false;
		box.setAttribute( 'aria-expanded', 'true' );
	}

	function shut() {
		list.hidden = true;
		box.setAttribute( 'aria-expanded', 'false' );
	}

	function take( t ) {
		box.value = label( t );
		term = t.id;
		shut();
		load( true );
	}

	box.addEventListener( 'focus', function () { offer( '' ); box.select(); } );
	box.addEventListener( 'input', function () { offer( box.value ); } );
	box.addEventListener( 'blur', function () { setTimeout( shut, 120 ); } );

	box.addEventListener( 'keydown', function ( e ) {
		var items = list.querySelectorAll( 'li[data-id]' );

		if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
			e.preventDefault();

			if ( list.hidden ) {
				offer( box.value );
				return;
			}

			at = Math.max( 0, Math.min( items.length - 1, at + ( 'ArrowDown' === e.key ? 1 : -1 ) ) );

			Array.prototype.forEach.call( items, function ( n, i ) {
				n.className = i === at ? 'is-on' : '';
			} );

			if ( items[ at ] ) {
				items[ at ].scrollIntoView( { block: 'nearest' } );
			}
		} else if ( 'Enter' === e.key ) {
			e.preventDefault();

			var pickIt = items[ at > -1 ? at : 0 ];

			if ( pickIt ) {
				take( all.filter( function ( t ) { return String( t.id ) === pickIt.getAttribute( 'data-id' ); } )[ 0 ] );
			}
		} else if ( 'Escape' === e.key ) {
			shut();
		}
	} );

	/* ---------- how many fit in a row ---------- */

	function applyRow() {
		var n = Number( perRow.value ) || 0;

		grid.style.gridTemplateColumns = n ? 'repeat(' + n + ', minmax(0, 1fr))' : '';

		try {
			localStorage.setItem( 'ocOrderRow', String( n ) );
		} catch ( e ) {}
	}

	perRow.addEventListener( 'change', applyRow );

	try {
		perRow.value = localStorage.getItem( 'ocOrderRow' ) || '0';
	} catch ( e ) {}

	/* ---------- the grid ---------- */

	function card( it ) {
		var el = document.createElement( 'div' );

		el.className = 'ocord__card' + ( it.out ? ' is-out' : '' );
		el.setAttribute( 'data-id', it.id );
		el.setAttribute( 'tabindex', '0' );
		el.innerHTML =
			'<span class="ocord__n"></span>' +
			'<span class="ocord__tick"></span>' +
			( it.img ? '<img class="ocord__img" src="' + esc( it.img ) + '" alt="" loading="lazy" draggable="false">' : '<span class="ocord__img ocord__img--none"></span>' ) +
			'<span class="ocord__name">' + esc( it.name ) + '</span>' +
			'<span class="ocord__meta"><b>' + esc( it.price ) + '</b>' + ( it.out ? '<em>' + esc( T.nostock || '' ) + '</em>' : '' ) + '</span>';

		return el;
	}

	function cards() {
		return grid.querySelectorAll( '.ocord__card' );
	}

	function renumber() {
		Array.prototype.forEach.call( cards(), function ( el, i ) {
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
			unpick();
		}

		say( T.loading || '' );

		ask( '?term=' + encodeURIComponent( term ) + '&offset=' + shown + '&limit=' + ( C.per || 60 ) ).then( function ( j ) {
			total = j.total || 0;

			( j.items || [] ).forEach( function ( it ) {
				grid.appendChild( card( it ) );
			} );

			shown += ( j.items || [] ).length;
			renumber();
			tally.textContent = ( T.count || '' ).replace( '%1$s', shown ).replace( '%2$s', total );
			more.hidden = shown >= total;

			note.hidden = false;
			note.textContent = j.own ? ( T.ownOrder || '' ) : ( T.noOrder || '' );

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
		var ids = Array.prototype.map.call( cards(), function ( el ) {
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
				} )
				.catch( function () { say( T.failed || '', 'bad' ); } );
		}, 250 );
	}

	/* ---------- choosing several ---------- */

	function unpick() {
		chosen = [];

		Array.prototype.forEach.call( cards(), function ( el ) {
			el.classList.remove( 'is-picked' );
			el.querySelector( '.ocord__tick' ).textContent = '';
		} );

		if ( note && note.classList.contains( 'is-pick' ) ) {
			note.classList.remove( 'is-pick' );
			note.textContent = T.ownOrder || '';
		}
	}

	function toggle( el ) {
		var i = chosen.indexOf( el );

		if ( i > -1 ) {
			chosen.splice( i, 1 );
			el.classList.remove( 'is-picked' );
		} else {
			chosen.push( el );
			el.classList.add( 'is-picked' );
		}

		chosen.forEach( function ( n, k ) {
			n.querySelector( '.ocord__tick' ).textContent = String( k + 1 );
		} );

		if ( ! chosen.length ) {
			unpick();
			return;
		}

		note.hidden = false;
		note.classList.add( 'is-pick' );
		note.textContent = ( T.picked || '' ).replace( '%d', chosen.length );
	}

	/* ---------- dragging ---------- */

	var held = null;
	var ghost = null;
	var startY = 0;
	var startX = 0;
	var moved = false;
	var train = [];

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
		if ( e.button > 0 ) {
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

			// A card that was chosen brings the others it was chosen with,
			// and they land in the order they were chosen in.
			train = chosen.indexOf( held ) > -1 ? chosen.slice() : [];
			held.classList.add( 'is-held' );
			train.forEach( function ( n ) { n.classList.add( 'is-held' ); } );

			ghost = held.cloneNode( true );
			ghost.className = 'ocord__card ocord__ghost';

			if ( train.length > 1 ) {
				ghost.setAttribute( 'data-many', String( train.length ) );
			}

			document.body.appendChild( ghost );
			document.body.classList.add( 'ocord-dragging' );
		}

		e.preventDefault();

		var rect = held.getBoundingClientRect();

		ghost.style.width = rect.width + 'px';
		ghost.style.left = ( e.clientX - rect.width / 2 ) + 'px';
		ghost.style.top = ( e.clientY - 24 ) + 'px';

		var over = cardAt( e.clientX, e.clientY );

		if ( over && over !== held && train.indexOf( over ) === -1 ) {
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

		if ( ghost ) {
			ghost.remove();
			ghost = null;
		}

		document.body.classList.remove( 'ocord-dragging' );

		if ( moved ) {
			// The rest of the train falls in behind the one that was dragged.
			var mark = held;

			train.forEach( function ( n ) {
				if ( n === held ) {
					return;
				}

				grid.insertBefore( n, mark.nextSibling );
				mark = n;
			} );

			held.classList.remove( 'is-held' );
			train.forEach( function ( n ) { n.classList.remove( 'is-held' ); } );
			unpick();
			renumber();
			commit();
		} else {
			held.classList.remove( 'is-held' );
			toggle( held );
		}

		held = null;
		moved = false;
		train = [];
	}

	grid.addEventListener( 'pointerup', letGo );
	grid.addEventListener( 'pointercancel', letGo );

	/* ---------- the keyboard, for anyone not using a mouse ---------- */

	grid.addEventListener( 'keydown', function ( e ) {
		var el = e.target.closest ? e.target.closest( '.ocord__card' ) : null;

		if ( ! el ) {
			return;
		}

		if ( ' ' === e.key ) {
			e.preventDefault();
			toggle( el );
			return;
		}

		if ( ( 'ArrowLeft' !== e.key && 'ArrowRight' !== e.key ) || ! e.altKey ) {
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

	more.addEventListener( 'click', function () { load( false ); } );

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

	box.value = T.shop || '';
	applyRow();
	load( true );
}() );
