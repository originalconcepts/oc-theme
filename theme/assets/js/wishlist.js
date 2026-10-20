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
		toastTimer = setTimeout( function () {
			t.classList.remove( 'is-in' );
			setTimeout( function () { t.remove(); }, 300 );
		}, 5000 );
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
		closePop();

		pop = document.createElement( 'div' );
		pop.className = 'oc-wl-pop';
		pop.innerHTML = '<div class="oc-wl-pop__scrim" data-oc-wl-x></div><div class="oc-wl-pop__panel" role="dialog" aria-modal="true"><div class="oc-wl-pop__head"><h2 class="oc-wl-pop__title"></h2><button type="button" class="oc-wl-pop__x" data-oc-wl-x>&times;</button></div><ul class="oc-wl-pop__lists"></ul><div class="oc-wl-pop__foot"></div></div>';
		pop.querySelector( '.oc-wl-pop__title' ).textContent = cfg.popTitle;
		pop.querySelector( '.oc-wl-pop__x' ).setAttribute( 'aria-label', cfg.close );
		pop.querySelector( '[role=dialog]' ).setAttribute( 'aria-label', cfg.popTitle );

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

		pop.addEventListener( 'click', function ( ev ) {
			if ( ev.target.closest( '[data-oc-wl-x]' ) ) { closePop(); }
		} );

		document.body.appendChild( pop );
		document.documentElement.classList.add( 'oc-wl-pop-open' );
		pop.querySelector( '.oc-wl-pop__x' ).focus();
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

				var share = el( 'button', 'oc-wlp__tool', cfg.share || 'Share' );
				share.type = 'button';
				share.addEventListener( 'click', function () {
					call( 'share', { l: current.id } ).then( function ( d ) {
						take( d );
						var url = d.url || '';
						if ( navigator.share ) {
							navigator.share( { title: current.name, url: url } ).catch( function () {} );
						} else if ( navigator.clipboard ) {
							navigator.clipboard.writeText( url ).then( function () { toast( cfg.copied, url ); } );
						} else {
							window.prompt( current.name, url );
						}
					} ).catch( function () { toast( cfg.failed, '' ); } );
				} );
				tools.appendChild( share );

				if ( current.share ) {
					var stop = el( 'button', 'oc-wlp__tool', cfg.unshare || 'Stop sharing' );
					stop.type = 'button';
					stop.addEventListener( 'click', function () {
						call( 'unshare', { l: current.id } ).then( function ( d ) { take( d ); loadPage( current.id ); } );
					} );
					tools.appendChild( stop );
				}

				if ( ! current.main ) {
					var ren = el( 'button', 'oc-wlp__tool', cfg.rename || 'Rename' );
					ren.type = 'button';
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

					var del = el( 'button', 'oc-wlp__tool oc-wlp__tool--del', cfg.deleteList || 'Delete list' );
					del.type = 'button';
					del.addEventListener( 'click', function () {
						if ( ! window.confirm( cfg.confirmDl ) ) { return; }
						call( 'delete', { l: current.id } ).then( function ( d ) { take( d ); loadPage( 0 ); } );
					} );
					tools.appendChild( del );
				}

				head.appendChild( tools );
			}

			main.appendChild( head );
		}

		var grid = el( 'div', 'oc-wlp__grid' );
		grid.innerHTML = data.html;
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
			var head = el( 'div', 'oc-wlp__head' );
			head.appendChild( el( 'p', 'oc-wlp__by', data.by || '' ) );
			head.appendChild( el( 'h2', 'oc-wlp__title', data.title ) );
			main.appendChild( head );
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
