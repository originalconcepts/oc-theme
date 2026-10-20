/**
 * Wishlist: hearts, the lists window, the lists page, and "move to
 * favourites" in the cart.
 *
 * The page arrives from a full-page cache, so nothing about the visitor is
 * in the HTML: one request reads their state and the hearts and the header
 * count are filled from it. The last state is kept in sessionStorage so the
 * hearts are right on the next page before that request returns.
 */
( function () {
	'use strict';

	var cfg = window.ocWl;
	if ( ! cfg ) { return; }

	var state = { logged: cfg.logged, lists: [], has: {}, count: 0 };
	var KEY = 'ocWlState:' + cfg.logged;

	/* ---------------------------------------------------------- request */

	function call( op, data ) {
		var body = new FormData();
		body.append( 'action', 'oc_wl' );
		body.append( 'op', op );
		body.append( 't', cfg.t );
		Object.keys( data || {} ).forEach( function ( k ) {
			if ( undefined !== data[ k ] && null !== data[ k ] ) { body.append( k, data[ k ] ); }
		} );
		return fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( ! r || ! r.success ) {
					var err = new Error( ( r && r.data && r.data.code ) || 'failed' );
					throw err;
				}
				return r.data;
			} );
	}

	function take( data ) {
		if ( ! data || ! data.lists ) { return; }
		state.logged = data.logged;
		state.lists = data.lists;
		state.has = data.has || {};
		state.count = data.count || 0;
		try { sessionStorage.setItem( KEY, JSON.stringify( state ) ); } catch ( e ) {}
		paint();
	}

	function listById( id ) {
		for ( var i = 0; i < state.lists.length; i++ ) {
			if ( state.lists[ i ].id === id ) { return state.lists[ i ]; }
		}
		return null;
	}

	function mainList() {
		for ( var i = 0; i < state.lists.length; i++ ) {
			if ( state.lists[ i ].main ) { return state.lists[ i ]; }
		}
		return null;
	}

	/* ------------------------------------------------------------ paint */

	function paint() {
		document.querySelectorAll( '[data-oc-wl]' ).forEach( function ( b ) {
			var on = !! ( state.has[ b.getAttribute( 'data-oc-wl' ) ] || [] ).length;
			b.classList.toggle( 'is-on', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		document.querySelectorAll( '[data-oc-wl-count]' ).forEach( function ( c ) {
			c.textContent = String( state.count );
			c.hidden = ! state.count;
		} );
	}

	// Cards arrive later too — filters, more pages, sliders built by script.
	var pending = false;
	new MutationObserver( function () {
		if ( pending ) { return; }
		pending = true;
		requestAnimationFrame( function () { pending = false; paint(); } );
	} ).observe( document.body, { childList: true, subtree: true } );

	/* ------------------------------------------------------------ toast */

	var toastTimer;

	function toast( title, text, action, onAction, img ) {
		var old = document.querySelector( '.oc-wltoast' );
		if ( old ) { old.remove(); }
		clearTimeout( toastTimer );

		var t = document.createElement( 'div' );
		t.className = 'oc-toast oc-wltoast';
		t.setAttribute( 'role', 'status' );

		if ( img ) {
			var im = document.createElement( 'img' );
			im.src = img;
			im.alt = '';
			t.appendChild( im );
		}

		var box = document.createElement( 'div' );
		box.className = 'oc-toast__txt';
		var s = document.createElement( 'strong' );
		s.textContent = title;
		box.appendChild( s );

		if ( text ) {
			var sp = document.createElement( 'span' );
			sp.textContent = text;
			box.appendChild( sp );
		}

		t.appendChild( box );

		if ( action ) {
			var a = document.createElement( 'button' );
			a.type = 'button';
			a.className = 'oc-wltoast__act';
			a.textContent = action;
			if ( 'login' === onAction ) {
				a.setAttribute( 'data-oc-auth', '1' );
				a.addEventListener( 'click', function () { t.remove(); } );
			} else {
				a.addEventListener( 'click', function () { t.remove(); onAction(); } );
			}
			t.appendChild( a );
		}

		// Docked under the header heart when there is one, as the cart's is.
		var anchor = document.querySelector( '.oc-wl-link' );
		var r = anchor && anchor.offsetParent ? anchor.getBoundingClientRect() : null;
		if ( r && r.width ) {
			t.style.top = Math.round( r.bottom + 10 ) + 'px';
		}

		document.body.appendChild( t );
		requestAnimationFrame( function () { t.classList.add( 'is-in' ); } );
		// Long enough to reach "Change"; held while the pointer or focus is on it.
		function later( ms ) {
			clearTimeout( toastTimer );
			toastTimer = setTimeout( function () {
				t.classList.remove( 'is-in' );
				setTimeout( function () { t.remove(); }, 300 );
			}, ms );
		}
		t.addEventListener( 'mouseenter', function () { clearTimeout( toastTimer ); } );
		t.addEventListener( 'mouseleave', function () { later( 2500 ); } );
		t.addEventListener( 'focusin', function () { clearTimeout( toastTimer ); } );
		later( action ? 7000 : 4000 );
	}

	function fmt( s, v ) { return String( s ).replace( '%s', v ); }

	function cardImage( btn ) {
		var scope = btn.closest( '.product, li, .type-product' ) || document;
		var img = scope.querySelector( '.oc-card-media img, .woocommerce-product-gallery img, img' );
		return img ? ( img.currentSrc || img.src ) : '';
	}

	/* ----------------------------------------------------------- hearts */

	function variationOf( btn ) {
		var form = btn.closest( 'form.cart' ) || document.querySelector( 'form.variations_form' );
		var field = form && form.querySelector( 'input[name="variation_id"]' );
		return field ? ( parseInt( field.value, 10 ) || 0 ) : 0;
	}

	function track( pid ) {
		document.dispatchEvent( new CustomEvent( 'oc:wishlist', { detail: { productId: pid } } ) );
	}

	function add( btn, pid, listId, src ) {
		var vid = 'product' === src ? variationOf( btn ) : 0;
		var img = cardImage( btn );

		// Shown filled at once; the answer confirms it.
		state.has[ pid ] = ( state.has[ pid ] || [] ).concat( [ listId || 0 ] );
		paint();

		return call( 'add', { p: pid, v: vid, l: listId || 0, s: src } )
			.then( function ( data ) {
				take( data );
				track( pid );
				var into = listById( data.list ) || mainList();
				var name = into ? into.name : cfg.main;
				if ( state.logged ) {
					toast( fmt( cfg.added, name ), '', cfg.change, function () { openPop( pid ); }, img );
				} else {
					toast( fmt( cfg.added, name ), cfg.guest, cfg.signIn, 'login', img );
				}
			} )
			.catch( function ( e ) {
				delete state.has[ pid ];
				paint();
				toast( 'full' === e.message ? cfg.full : cfg.failed, '' );
			} );
	}

	function remove( pid, listId ) {
		var list = listById( listId );
		var name = list ? list.name : cfg.main;
		var kept = state.has[ pid ];

		state.has[ pid ] = ( state.has[ pid ] || [] ).filter( function ( id ) { return id !== listId; } );
		paint();

		return call( 'remove', { p: pid, l: listId } )
			.then( function ( data ) {
				take( data );
				toast( fmt( cfg.removed, name ), '', cfg.undo, function () {
					call( 'add', { p: pid, l: listId, s: 'list' } ).then( function ( d ) { take( d ); refreshPage(); } );
				} );
				refreshPage();
			} )
			.catch( function () {
				state.has[ pid ] = kept;
				paint();
				toast( cfg.failed, '' );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-oc-wl]' );
		if ( ! btn ) { return; }
		e.preventDefault();
		e.stopPropagation();

		var pid = btn.getAttribute( 'data-oc-wl' );
		var src = btn.getAttribute( 'data-oc-wl-src' ) || 'card';
		var inLists = state.has[ pid ] || [];

		if ( ! inLists.length ) {
			add( btn, pid, 0, src );
		} else if ( inLists.length === 1 ) {
			remove( pid, inLists[ 0 ] );
		} else {
			openPop( pid );
		}
	}, true );

	/* ------------------------------------------------- the lists window */

	var pop;

	var HEART = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 20.3s-7.6-4.6-9.2-9.4C1.7 7.4 3.9 4 7.4 4c2 0 3.6 1.1 4.6 2.6C13 5.1 14.6 4 16.6 4c3.5 0 5.7 3.4 4.6 6.9-1.6 4.8-9.2 9.4-9.2 9.4z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>';

	var ICONS = {
		share: '<path d="M12 3v12"/><path d="M7.5 7.5 12 3l4.5 4.5"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/>',
		rename: '<path d="M4 20h4L19.3 8.7a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="m14.5 7.5 3 3"/>',
		trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>',
		move: '<path d="M4 8h13"/><path d="m13 4 4 4-4 4"/><path d="M20 16H7"/><path d="m11 12-4 4 4 4"/>',
		plus: '<path d="M12 5v14M5 12h14"/>'
	};

	function icon( name, size ) {
		return '<svg viewBox="0 0 24 24" width="' + ( size || 18 ) + '" height="' + ( size || 18 ) + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICONS[ name ] + '</svg>';
	}

	/* A popup shell: centred on a large screen, a sheet from the bottom on a
	   phone. Returns the panel to fill; showPop() puts it on the page. */
	function makePop( title ) {
		closePop();
		pop = document.createElement( 'div' );
		pop.className = 'oc-wl-pop';
		pop.innerHTML = '<div class="oc-wl-pop__scrim" data-oc-wl-x></div><div class="oc-wl-pop__panel" role="dialog" aria-modal="true"><div class="oc-wl-pop__head"><h2 class="oc-wl-pop__title"></h2><button type="button" class="oc-wl-pop__x" data-oc-wl-x>&times;</button></div></div>';
		pop.querySelector( '.oc-wl-pop__title' ).textContent = title;
		pop.querySelector( '.oc-wl-pop__x' ).setAttribute( 'aria-label', cfg.close );
		pop.querySelector( '[role=dialog]' ).setAttribute( 'aria-label', title );
		pop.addEventListener( 'click', function ( ev ) {
			if ( ev.target.closest( '[data-oc-wl-x]' ) ) { closePop(); }
		} );
		return pop.querySelector( '.oc-wl-pop__panel' );
	}

	function showPop( focus ) {
		document.body.appendChild( pop );
		document.documentElement.classList.add( 'oc-wl-pop-open' );
		( focus || pop.querySelector( '.oc-wl-pop__x' ) ).focus();
	}

	/* A guest's answer wherever lists would be made: sign in first. */
	function guestFoot( foot ) {
		var note = document.createElement( 'p' );
		note.className = 'oc-wl-pop__guest';
		note.textContent = cfg.guestPop;
		var go = document.createElement( 'button' );
		go.type = 'button';
		go.className = 'oc-wl-pop__login';
		go.setAttribute( 'data-oc-auth', '1' );
		go.textContent = cfg.signIn;
		go.addEventListener( 'click', closePop );
		foot.appendChild( note );
		foot.appendChild( go );
	}

	/* "+ New list": the name, a field, Create. */
	function newListSheet( after ) {
		var panel = makePop( cfg.newList );
		var foot = document.createElement( 'div' );
		foot.className = 'oc-wl-pop__foot oc-wl-pop__foot--solo';
		panel.appendChild( foot );

		if ( ! state.logged ) {
			guestFoot( foot );
			showPop();
			return;
		}

		var form = document.createElement( 'form' );
		form.className = 'oc-wl-pop__new oc-wl-pop__new--stack';
		form.innerHTML = '<label class="oc-wl-pop__lab"></label><input type="text" maxlength="60" required><button type="submit"></button>';
		var input = form.querySelector( 'input' );
		input.id = 'oc-wl-new-name';
		form.querySelector( 'label' ).setAttribute( 'for', input.id );
		form.querySelector( 'label' ).textContent = cfg.listName;
		form.querySelector( 'button' ).textContent = cfg.create;
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var name = input.value.trim();
			if ( ! name ) { return; }
			var btn = form.querySelector( 'button' );
			btn.disabled = true;
			call( 'create', { n: name } )
				.then( function ( d ) { take( d ); closePop(); if ( after ) { after( d.list ); } } )
				.catch( function ( er ) { btn.disabled = false; toast( 'full' === er.message ? cfg.full : cfg.failed, '' ); } );
		} );
		foot.appendChild( form );
		showPop( input );
	}

	/* Move one product out of the open list into another (or a new one). */
	function moveSheet( pid, from ) {
		var panel = makePop( cfg.moveTo );
		var ul = document.createElement( 'ul' );
		ul.className = 'oc-wl-pop__lists';
		panel.appendChild( ul );

		function done( to ) {
			var into = listById( to );
			closePop();
			toast( fmt( cfg.movedTo, into ? into.name : '' ), '' );
			loadPage( from );
		}

		var others = state.lists.filter( function ( l ) { return l.id !== from; } );
		others.forEach( function ( list ) {
			var li = document.createElement( 'li' );
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'oc-wl-pop__row';
			var txt = document.createElement( 'span' );
			txt.className = 'oc-wl-pop__txt';
			var n = document.createElement( 'b' );
			n.textContent = list.name;
			var c = document.createElement( 'small' );
			c.textContent = countText( list.count );
			txt.appendChild( n );
			txt.appendChild( c );
			var go = document.createElement( 'span' );
			go.className = 'oc-wl-pop__go';
			go.innerHTML = icon( 'move', 18 );
			b.appendChild( thumbs( list ) );
			b.appendChild( txt );
			b.appendChild( go );
			b.addEventListener( 'click', function () {
				b.disabled = true;
				call( 'move', { p: pid, l: from, to: list.id } )
					.then( function ( d ) { take( d ); done( list.id ); } )
					.catch( function () { b.disabled = false; toast( cfg.failed, '' ); } );
			} );
			li.appendChild( b );
			ul.appendChild( li );
		} );

		if ( ! others.length ) {
			var none = document.createElement( 'p' );
			none.className = 'oc-wl-pop__none';
			none.textContent = cfg.noOther;
			panel.appendChild( none );
		}

		var foot = document.createElement( 'div' );
		foot.className = 'oc-wl-pop__foot';
		var form = document.createElement( 'form' );
		form.className = 'oc-wl-pop__new';
		form.innerHTML = '<input type="text" maxlength="60" required><button type="submit"></button>';
		var input = form.querySelector( 'input' );
		input.placeholder = cfg.listName;
		input.setAttribute( 'aria-label', cfg.listName );
		form.querySelector( 'button' ).textContent = cfg.create;
		form.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			var name = input.value.trim();
			if ( ! name ) { return; }
			var btn = form.querySelector( 'button' );
			btn.disabled = true;
			call( 'create', { n: name } )
				.then( function ( d ) { return call( 'move', { p: pid, l: from, to: d.list } ).then( function ( m ) { take( m ); done( d.list ); } ); } )
				.catch( function ( er ) { btn.disabled = false; toast( 'full' === er.message ? cfg.full : cfg.failed, '' ); } );
		} );
		var lab = document.createElement( 'p' );
		lab.className = 'oc-wl-pop__lab';
		lab.textContent = cfg.newList;
		foot.appendChild( lab );
		foot.appendChild( form );
		panel.appendChild( foot );

		showPop();
	}

	function thumbs( list ) {
		var box = document.createElement( 'span' );
		box.className = 'oc-wl-thumbs';
		( list.thumb || [] ).forEach( function ( src ) {
			var s = document.createElement( 'span' );
			s.className = 'oc-wl-thumbs__img';
			var i = document.createElement( 'img' );
			i.src = src;
			i.alt = '';
			i.loading = 'lazy';
			s.appendChild( i );
			box.appendChild( s );
		} );
		if ( ! ( list.thumb || [] ).length ) {
			// Nothing in it yet: a quiet heart instead of an empty grey box.
			var ph = document.createElement( 'span' );
			ph.className = 'oc-wl-thumbs__ph';
			ph.innerHTML = HEART;
			box.appendChild( ph );
		}
		var more = ( list.count || 0 ) - ( list.thumb || [] ).length;
		if ( more > 0 ) {
			var m = document.createElement( 'span' );
			m.className = 'oc-wl-thumbs__more';
			m.textContent = '+' + more;
			box.appendChild( m );
		}
		return box;
	}

	function countText( n ) {
		if ( ! n ) { return cfg.empty; }
		return 1 === n ? cfg.one : cfg.many.replace( '%d', n );
	}

	function closePop() {
		if ( ! pop ) { return; }
		pop.remove();
		pop = null;
		document.documentElement.classList.remove( 'oc-wl-pop-open' );
	}

	function openPop( pid ) {
		var panel = makePop( cfg.popTitle );
		panel.insertAdjacentHTML( 'beforeend', '<ul class="oc-wl-pop__lists"></ul><div class="oc-wl-pop__foot"></div>' );

		var ul = pop.querySelector( '.oc-wl-pop__lists' );

		function rows() {
			ul.textContent = '';
			var mine = state.has[ pid ] || [];
			state.lists.forEach( function ( list ) {
				var li = document.createElement( 'li' );
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'oc-wl-pop__row' + ( mine.indexOf( list.id ) > -1 ? ' is-in' : '' );
				b.setAttribute( 'aria-pressed', mine.indexOf( list.id ) > -1 ? 'true' : 'false' );

				var txt = document.createElement( 'span' );
				txt.className = 'oc-wl-pop__txt';
				var n = document.createElement( 'b' );
				n.textContent = list.name;
				var c = document.createElement( 'small' );
				c.textContent = countText( list.count );
				txt.appendChild( n );
				txt.appendChild( c );

				var tick = document.createElement( 'span' );
				tick.className = 'oc-wl-pop__tick';
				tick.setAttribute( 'aria-hidden', 'true' );

				b.appendChild( thumbs( list ) );
				b.appendChild( txt );
				b.appendChild( tick );

				b.addEventListener( 'click', function () {
					var inIt = ( state.has[ pid ] || [] ).indexOf( list.id ) > -1;
					b.disabled = true;
					( inIt ? call( 'remove', { p: pid, l: list.id } ) : call( 'add', { p: pid, l: list.id, s: 'popup' } ) )
						.then( function ( d ) { take( d ); if ( ! inIt ) { track( pid ); } rows(); refreshPage(); } )
						.catch( function () { b.disabled = false; toast( cfg.failed, '' ); } );
				} );

				li.appendChild( b );
				ul.appendChild( li );
			} );
		}

		rows();

		var foot = pop.querySelector( '.oc-wl-pop__foot' );

		if ( state.logged ) {
			var form = document.createElement( 'form' );
			form.className = 'oc-wl-pop__new';
			form.innerHTML = '<input type="text" maxlength="60" required><button type="submit"></button>';
			var input = form.querySelector( 'input' );
			input.placeholder = cfg.listName;
			input.setAttribute( 'aria-label', cfg.listName );
			form.querySelector( 'button' ).textContent = cfg.create;
			form.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();
				var name = input.value.trim();
				if ( ! name ) { return; }
				call( 'create', { n: name, p: pid } )
					.then( function ( d ) { take( d ); input.value = ''; rows(); track( pid ); refreshPage(); } )
					.catch( function ( er ) { toast( 'full' === er.message ? cfg.full : cfg.failed, '' ); } );
			} );
			var lab = document.createElement( 'p' );
			lab.className = 'oc-wl-pop__lab';
			lab.textContent = cfg.newList;
			foot.appendChild( lab );
			foot.appendChild( form );
		} else {
			guestFoot( foot );
		}

		showPop();
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && pop ) { closePop(); }
	} );

	/* ---------------------------------------------------- the lists page */

	var page = document.querySelector( '[data-oc-wl-page]' );
	var open = 0;

	function refreshPage() {
		if ( page && ! page.getAttribute( 'data-share' ) ) { loadPage( open ); }
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( undefined !== text ) { n.textContent = text; }
		return n;
	}

	/* The link of a shared list, always in view while the list is shared:
	   copy, WhatsApp, the device's own share sheet, or stop sharing. */
	function shareBox( list ) {
		var box = el( 'div', 'oc-wlp__share' );
		box.appendChild( el( 'p', 'oc-wlp__share-t', cfg.sharedNote ) );

		var row = el( 'div', 'oc-wlp__share-row' );
		var input = el( 'input', 'oc-wlp__share-url' );
		input.type = 'text';
		input.readOnly = true;
		input.dir = 'ltr';
		input.value = list.share;
		input.setAttribute( 'aria-label', cfg.shareLink );
		input.addEventListener( 'focus', function () { input.select(); } );
		row.appendChild( input );

		var copy = el( 'button', 'oc-wlp__tool oc-wlp__tool--main', cfg.copy );
		copy.type = 'button';
		copy.addEventListener( 'click', function () {
			function done() { toast( cfg.copied, '' ); }
			function manual() { input.focus(); input.select(); try { if ( document.execCommand( 'copy' ) ) { done(); } } catch ( er ) {} }
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( list.share ).then( done, manual );
			} else {
				manual();
			}
		} );
		row.appendChild( copy );
		box.appendChild( row );

		var more = el( 'div', 'oc-wlp__share-more' );
		var wa = el( 'a', 'oc-wlp__tool', 'WhatsApp' );
		wa.href = 'https://wa.me/?text=' + encodeURIComponent( list.name + ' ' + list.share );
		wa.target = '_blank';
		wa.rel = 'noopener';
		more.appendChild( wa );

		if ( navigator.share ) {
			var nat = el( 'button', 'oc-wlp__tool', cfg.shareVia );
			nat.type = 'button';
			nat.addEventListener( 'click', function () {
				navigator.share( { title: list.name, url: list.share } ).catch( function () {} );
			} );
			more.appendChild( nat );
		}

		var stop = el( 'button', 'oc-wlp__tool oc-wlp__tool--del', cfg.unshare || 'Stop sharing' );
		stop.type = 'button';
		stop.addEventListener( 'click', function () {
			stop.disabled = true;
			call( 'unshare', { l: list.id } ).then( function ( d ) { take( d ); loadPage( list.id ); } )
				.catch( function () { stop.disabled = false; toast( cfg.failed, '' ); } );
		} );
		more.appendChild( stop );
		box.appendChild( more );

		return box;
	}

	/* Share / rename / delete, as text, icon or both (Settings → Wishlist). */
	function tool( label, ic, extra ) {
		var style = cfg.tools || 'text';
		var b = el( 'button', 'oc-wlp__tool oc-wlp__tool--' + style + ( extra ? ' ' + extra : '' ) );
		b.type = 'button';
		if ( 'text' !== style ) { b.insertAdjacentHTML( 'beforeend', icon( ic, 18 ) ); }
		if ( 'icon' !== style ) {
			b.appendChild( el( 'span', '', label ) );
		} else {
			b.setAttribute( 'aria-label', label );
			b.title = label;
		}
		return b;
	}

	/* Under every card on one's own list: move it elsewhere, or take it out. */
	function cardActions( grid, listId ) {
		grid.querySelectorAll( 'li.product' ).forEach( function ( li ) {
			var heart = li.querySelector( '[data-oc-wl]' );
			var pid = heart ? parseInt( heart.getAttribute( 'data-oc-wl' ), 10 ) : 0;
			if ( ! pid ) { return; }
			var bar = el( 'div', 'oc-wlp__acts' );
			if ( state.logged ) {
				var mv = el( 'button', 'oc-wlp__act' );
				mv.type = 'button';
				mv.innerHTML = icon( 'move', 16 );
				mv.appendChild( el( 'span', '', cfg.move ) );
				mv.setAttribute( 'aria-label', cfg.moveTo );
				mv.addEventListener( 'click', function () { moveSheet( pid, listId ); } );
				bar.appendChild( mv );
			}
			var rm = el( 'button', 'oc-wlp__act' );
			rm.type = 'button';
			rm.innerHTML = icon( 'trash', 16 );
			rm.appendChild( el( 'span', '', cfg.removeIt ) );
			rm.addEventListener( 'click', function () { rm.disabled = true; remove( pid, listId ); } );
			bar.appendChild( rm );
			li.appendChild( bar );
		} );
	}

	function renderOwn( data ) {
		open = data.list;
		page.textContent = '';

		var wrap = el( 'div', 'oc-wlp__wrap' );
		var side = el( 'nav', 'oc-wlp__lists' );
		side.setAttribute( 'aria-label', cfg.popTitle );

		state.lists.forEach( function ( list ) {
			var a = el( 'button', 'oc-wlp__list' + ( list.id === open ? ' is-on' : '' ) );
			a.type = 'button';
			a.setAttribute( 'aria-current', list.id === open ? 'true' : 'false' );
			var t = el( 'span', 'oc-wlp__list-txt' );
			t.appendChild( el( 'b', '', list.name ) );
			t.appendChild( el( 'small', '', countText( list.count ) ) );
			a.appendChild( thumbs( list ) );
			a.appendChild( t );
			a.addEventListener( 'click', function () { loadPage( list.id ); } );
			side.appendChild( a );
		} );

		// The last card of the row (phone, or the "row above" layout): opens
		// a small panel with the name field. The column layout keeps its
		// inline form below the lists.
		var add = el( 'button', 'oc-wlp__list oc-wlp__add' );
		add.type = 'button';
		add.innerHTML = '<span class="oc-wlp__add-ic">' + icon( 'plus', 20 ) + '</span>';
		var addTxt = el( 'span', 'oc-wlp__list-txt' );
		addTxt.appendChild( el( 'b', '', cfg.newList ) );
		add.appendChild( addTxt );
		add.addEventListener( 'click', function () { newListSheet( function ( id ) { loadPage( id ); } ); } );
		side.appendChild( add );

		if ( state.logged ) {
			var nf = el( 'form', 'oc-wlp__new' );
			nf.innerHTML = '<input type="text" maxlength="60" required><button type="submit"></button>';
			var ni = nf.querySelector( 'input' );
			ni.placeholder = cfg.newList;
			ni.setAttribute( 'aria-label', cfg.listName );
			nf.querySelector( 'button' ).textContent = cfg.create;
			nf.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();
				if ( ! ni.value.trim() ) { return; }
				call( 'create', { n: ni.value.trim() } ).then( function ( d ) { take( d ); loadPage( d.list ); } ).catch( function () { toast( cfg.failed, '' ); } );
			} );
			side.appendChild( nf );
		} else {
			var g = el( 'div', 'oc-wlp__guest' );
			g.appendChild( el( 'p', '', cfg.guestPop ) );
			var gb = el( 'button', 'oc-wlp__login', cfg.signIn );
			gb.type = 'button';
			gb.setAttribute( 'data-oc-auth', '1' );
			g.appendChild( gb );
			side.appendChild( g );
		}

		var main = el( 'section', 'oc-wlp__main' );
		var current = listById( open );

		if ( current ) {
			var head = el( 'div', 'oc-wlp__head' );
			var h = el( 'h2', 'oc-wlp__title', current.name );
			head.appendChild( h );
			head.appendChild( el( 'span', 'oc-wlp__count', countText( current.count ) ) );

			if ( state.logged ) {
				var tools = el( 'div', 'oc-wlp__tools' );

				if ( ! current.share ) {
					var share = tool( cfg.share, 'share' );
					share.addEventListener( 'click', function () {
						share.disabled = true;
						call( 'share', { l: current.id } ).then( function ( d ) {
							take( d );
							loadPage( current.id );
							// A phone offers its own sheet straight away; the link box below covers the rest.
							if ( d.url && navigator.share && window.matchMedia( '(pointer: coarse)' ).matches ) {
								navigator.share( { title: current.name, url: d.url } ).catch( function () {} );
							}
						} ).catch( function () { share.disabled = false; toast( cfg.failed, '' ); } );
					} );
					tools.appendChild( share );
				}

				if ( ! current.main ) {
					var ren = tool( cfg.rename, 'rename' );
					ren.addEventListener( 'click', function () {
						var f = el( 'form', 'oc-wlp__rename' );
						f.innerHTML = '<input type="text" maxlength="60" required><button type="submit"></button><button type="button" data-cancel></button>';
						var i = f.querySelector( 'input' );
						i.value = current.name;
						i.setAttribute( 'aria-label', cfg.listName );
						f.querySelector( 'button[type=submit]' ).textContent = cfg.save;
						f.querySelector( '[data-cancel]' ).textContent = cfg.cancel;
						f.querySelector( '[data-cancel]' ).addEventListener( 'click', function () { f.replaceWith( h ); } );
						f.addEventListener( 'submit', function ( ev ) {
							ev.preventDefault();
							call( 'rename', { l: current.id, n: i.value.trim() } ).then( function ( d ) { take( d ); loadPage( current.id ); } );
						} );
						h.replaceWith( f );
						i.focus();
					} );
					tools.appendChild( ren );

					var del = tool( cfg.deleteList, 'trash', 'oc-wlp__tool--del' );
					del.addEventListener( 'click', function () {
						if ( ! window.confirm( cfg.confirmDl ) ) { return; }
						call( 'delete', { l: current.id } ).then( function ( d ) { take( d ); loadPage( 0 ); } );
					} );
					tools.appendChild( del );
				}

				head.appendChild( tools );
			}

			main.appendChild( head );

			if ( state.logged && current.share ) {
				main.appendChild( shareBox( current ) );
			}
		}

		var grid = el( 'div', 'oc-wlp__grid' );
		grid.innerHTML = data.html;
		if ( current ) { cardActions( grid, current.id ); }
		main.appendChild( grid );

		wrap.appendChild( side );
		wrap.appendChild( main );
		page.appendChild( wrap );
		paint();
	}

	function renderShared( data ) {
		page.textContent = '';
		var main = el( 'section', 'oc-wlp__main oc-wlp__main--shared' );
		if ( data.title ) {
			// A visitor sees the list's own name as the page heading, not "My lists".
			var h1 = document.querySelector( '.oc-page-title' );
			if ( h1 ) {
				h1.textContent = data.title;
				document.title = data.title + ( document.title.indexOf( ' - ' ) > -1 ? document.title.slice( document.title.indexOf( ' - ' ) ) : '' );
			} else {
				main.appendChild( el( 'h2', 'oc-wlp__title', data.title ) );
			}
			main.appendChild( el( 'p', 'oc-wlp__by oc-wlp__by--shared', data.by || '' ) );
		}
		var grid = el( 'div', 'oc-wlp__grid' );
		grid.innerHTML = data.html;
		main.appendChild( grid );
		page.appendChild( main );
		paint();
	}

	function loadPage( listId ) {
		if ( ! page ) { return; }
		page.classList.add( 'is-busy' );
		var share = page.getAttribute( 'data-share' );
		call( 'page', share ? { share: share } : { l: listId || 0 } )
			.then( function ( data ) {
				page.classList.remove( 'is-busy' );
				if ( data.state ) { take( data.state ); }
				if ( data.shared ) { renderShared( data ); } else { renderOwn( data ); }
				if ( ! data.shared && history.replaceState ) {
					var u = new URL( window.location.href );
					if ( data.list ) { u.searchParams.set( 'list', data.list ); }
					history.replaceState( null, '', u.toString() );
				}
			} )
			.catch( function () {
				page.classList.remove( 'is-busy' );
				page.textContent = cfg.failed;
			} );
	}

	/* ------------------------------------------------------------- cart */

	document.addEventListener( 'click', function ( e ) {
		var move = e.target.closest( '[data-oc-confirm-move]' );
		if ( ! move ) { return; }
		e.preventDefault();
		var pid = move.getAttribute( 'data-pid' );
		var vid = move.getAttribute( 'data-vid' ) || 0;
		var box = move.closest( '[data-oc-confirm]' );
		var yes = box && box.querySelector( '[data-oc-confirm-yes]' );
		move.disabled = true;
		call( 'add', { p: pid, v: vid, s: 'cart' } )
			.then( function ( data ) {
				take( data );
				track( pid );
				var into = listById( data.list ) || mainList();
				toast( fmt( cfg.added, into ? into.name : cfg.main ), state.logged ? '' : cfg.guest, state.logged ? '' : cfg.signIn, 'login' );
				if ( yes ) { yes.click(); }
			} )
			.catch( function () { move.disabled = false; toast( cfg.failed, '' ); } );
	} );

	/* ----------------------------------------------------- the gallery */

	var tpl = document.querySelector( 'template[data-oc-wl-gallery]' );
	var gal = document.querySelector( '.woocommerce-product-gallery' );
	if ( tpl && gal ) {
		var node = tpl.content.firstElementChild.cloneNode( true );
		node.classList.add( 'oc-wl-heart--img-' + tpl.getAttribute( 'data-oc-wl-gallery' ) );
		gal.classList.add( 'oc-wl-has-heart' );
		gal.appendChild( node );
	}

	/* ------------------------------------------------------------ start */

	try {
		var cached = JSON.parse( sessionStorage.getItem( KEY ) || 'null' );
		if ( cached && cached.lists ) {
			state = cached;
			paint();
		}
	} catch ( e ) {}

	if ( page ) {
		var q = new URL( window.location.href ).searchParams.get( 'list' );
		loadPage( parseInt( q, 10 ) || 0 );
	} else {
		call( 'state' ).then( take ).catch( function () {} );
	}
}() );
