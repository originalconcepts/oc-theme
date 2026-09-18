/* OC Statistics — the shop's own counter, and its heat maps. */
/* ---------- statistics beacon ----------
 * One small hit per page, product view, add to cart and checkout start,
 * to the shop's own counter. A random session id lives 30 quiet minutes
 * in a cookie; the first hit of a session carries where it came from.
 * Staff, robots and visitors who declined statistics are not counted. */
( function () {
	var S = window.ocStatsHit || null;

	if ( ! S || ! S.url || S.staff || navigator.webdriver ) {
		return;
	}

	function cookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function setCookie( name, value, seconds ) {
		document.cookie = name + '=' + encodeURIComponent( value ) + ';path=/;max-age=' + seconds + ';SameSite=Lax' + ( 'https:' === location.protocol ? ';Secure' : '' );
	}

	function allowed() {
		return ! window.ocPrivacy || window.ocPrivacy.allows( 'analytics' );
	}

	var sid = cookie( 'oc_sid' );
	var first = 0;

	if ( ! /^[a-f0-9]{24}$/.test( sid ) ) {
		var bytes = new Uint8Array( 12 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		sid = Array.prototype.map.call( bytes, function ( b ) { return ( '0' + b.toString( 16 ) ).slice( -2 ); } ).join( '' );
		first = 1;
	}

	setCookie( 'oc_sid', sid, 1800 );

	var queue = [];
	var recent = {};

	function post( d ) {
		var body = JSON.stringify( d );

		try {
			if ( navigator.sendBeacon && navigator.sendBeacon( S.url, new Blob( [ body ], { type: 'application/json' } ) ) ) {
				return;
			}
		} catch ( e ) {}

		fetch( S.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' } ).catch( function () {} );
	}

	function hit( type, obj ) {
		obj = parseInt( obj, 10 ) || 0;

		// The same product twice within a breath is one add, not two.
		var k = type + ':' + obj;
		if ( 'atc' === type && recent[ k ] && Date.now() - recent[ k ] < 1500 ) { return; }
		recent[ k ] = Date.now();

		var d = { _t: S.t, sid: sid, t: type, o: obj };

		if ( first ) {
			d.f = 1;
			d.r = document.referrer || '';
			d.q = location.search.slice( 0, 400 );
			first = 0;
		}

		setCookie( 'oc_sid', sid, 1800 );

		if ( ! allowed() ) {
			queue.push( d );
			return;
		}

		post( d );
	}

	document.addEventListener( 'oc:consent', function () {
		if ( ! allowed() ) { return; }
		var q = queue.splice( 0 );
		q.forEach( post );
	} );

	hit( 'view', S.p || S.c || 0 );
	if ( S.p ) { hit( 'product', S.p ); }
	if ( S.c ) { hit( 'cat', S.c ); }
	if ( S.b ) { hit( 'brand', S.b ); }
	if ( S.co ) { hit( 'checkout', 0 ); }

	document.addEventListener( 'oc:added', function ( e ) { hit( 'atc', e.detail && e.detail.productId ); } );
	document.body.addEventListener( 'oc-added-to-cart', function ( e ) { hit( 'atc', e.detail && e.detail.id ); } );

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', function ( e, fragments, hash, btn ) {
			var b = btn && btn.jquery ? btn : window.jQuery( btn );
			hit( 'atc', b && b.data ? b.data( 'product_id' ) : 0 );
		} );
	}

	// The classic add-to-cart form on a product page submits the page.
	var form = document.querySelector( 'form.cart' );

	if ( form && S.p ) {
		form.addEventListener( 'submit', function () { hit( 'atc', S.p ); } );
	}
}() );
/* ---------- heat map ----------
 * Where people click on a watched page, how far down they get and where
 * they linger. Nothing leaves the browser while the visitor is reading:
 * marks are counted in memory and sent once, as the page goes away. A
 * page that is not watched costs one property read and stops here. */
( function () {
	var H = window.ocHeatHit || null;

	if ( ! H || ! H.url || navigator.webdriver ) {
		return;
	}

	var KEEP = 'ocHeat';

	function post( body ) {
		try {
			var blob = new Blob( [ JSON.stringify( body ) ], { type: 'application/json' } );

			if ( navigator.sendBeacon && navigator.sendBeacon( H.url, blob ) ) {
				return;
			}
		} catch ( e ) {}

		try {
			fetch( H.url, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body ) } );
		} catch ( e ) {}
	}

	// The thank-you page: hand over what the visit kept on its way here.
	if ( H.flush ) {
		try {
			var kept = JSON.parse( sessionStorage.getItem( KEEP ) || '[]' );
			sessionStorage.removeItem( KEEP );
			kept.slice( 0, 12 ).forEach( function ( one ) {
				one._t = H.t;
				one.seg = 'b';
				post( one );
			} );
		} catch ( e ) {}

		return;
	}

	var PATH = location.pathname;
	var marks = {};
	var bands = {};
	var seen = {};
	var count = 0;
	var last = { x: 0, y: 0, at: 0, n: 0 };
	var first = true;

	// A logged-in visitor has the toolbar, which pushes the whole document
	// down. The map is drawn without it, so take it off here or every mark
	// lands a bar's height too low.
	function bar() {
		var b = document.getElementById( 'wpadminbar' );

		return b ? ( b.offsetHeight || 0 ) : 0;
	}

	// How tall the page was for this visitor. Sent with the marks so the
	// map can stretch them onto the page as it stands now: a lazy image
	// that had not arrived, or a narrower phone, changes every distance.
	function tall() {
		return Math.max( 1, Math.round( ( document.body ? document.body.scrollHeight : 0 ) || document.documentElement.scrollHeight ) - bar() );
	}

	function add( kind, xp, y, n ) {
		var key = kind + '|' + xp + '|' + y;

		marks[ key ] = ( marks[ key ] || 0 ) + n;
	}

	// Anything a person could reasonably expect to do something.
	function live( el ) {
		for ( var i = 0; el && i < 6; i++ ) {
			var tag = ( el.tagName || '' ).toLowerCase();

			if ( 'a' === tag || 'button' === tag || 'input' === tag || 'select' === tag || 'textarea' === tag || 'label' === tag || 'summary' === tag ) {
				return true;
			}

			if ( el.onclick || ( el.getAttribute && ( el.hasAttribute( 'data-oc-open' ) || 'button' === el.getAttribute( 'role' ) || el.hasAttribute( 'tabindex' ) ) ) ) {
				return true;
			}

			el = el.parentElement;
		}

		return false;
	}

	// A click that is about to take the visitor off this page. The browser
	// does not reliably deliver a beacon queued while it is leaving, so
	// what is held is sent now, while the page is still alive.
	function leaving( el ) {
		for ( var i = 0; el && i < 6; i++ ) {
			if ( 'A' === el.tagName && el.getAttribute( 'href' ) ) {
				var href = el.getAttribute( 'href' );

				return '#' !== href.charAt( 0 ) && 0 !== href.indexOf( 'javascript:' ) && '_blank' !== el.getAttribute( 'target' );
			}

			el = el.parentElement;
		}

		return false;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( count >= ( H.max || 60 ) || ! e.isTrusted ) {
			return;
		}

		var w = document.documentElement.clientWidth || 1;
		var xp = Math.max( 0, Math.min( 100, Math.round( ( e.clientX / w ) * 100 ) ) );
		var y = Math.max( 0, Math.round( e.pageY - bar() ) );
		var now = Date.now();

		++count;
		add( live( e.target ) ? 'c' : 'd', xp, y, 1 );

		// Three quick hits in one spot is not enthusiasm, it is frustration.
		if ( now - last.at < 1000 && Math.abs( y - last.y ) < 30 && Math.abs( xp - last.x ) < 6 ) {
			last.n++;

			if ( 3 === last.n ) {
				add( 'r', xp, y, 1 );
			}
		} else {
			last.n = 1;
		}

		last.x = xp;
		last.y = y;
		last.at = now;

		if ( leaving( e.target ) ) {
			flush();
		}
	}, true );

	// Once a second, which fifth of the page is on screen.
	setInterval( function () {
		if ( document.hidden ) {
			return;
		}

		var high = tall();
		var from = Math.floor( Math.min( 1, Math.max( 0, window.scrollY / high ) ) * 20 );
		var to = Math.min( 19, Math.floor( Math.min( 1, ( window.scrollY + window.innerHeight ) / high ) * 20 ) );

		for ( var b = from; b <= to; b++ ) {
			bands[ b ] = ( bands[ b ] || 0 ) + 1;
		}
	}, 1000 );

	function waiting() {
		var k;

		for ( k in marks ) { return true; }
		for ( k in bands ) { return true; }

		return false;
	}

	function body() {
		var list = [];

		Object.keys( marks ).forEach( function ( k ) {
			var p = k.split( '|' );

			list.push( [ p[ 0 ], Number( p[ 1 ] ), Number( p[ 2 ] ), marks[ k ] ] );
		} );

		var depth = [];
		var fresh = [];

		for ( var b = 0; b < 20; b++ ) {
			depth.push( bands[ b ] || 0 );

			if ( bands[ b ] && ! seen[ b ] ) {
				fresh.push( b );
			}
		}

		return {
			_t: H.t,
			path: PATH,
			kind: H.kind,
			label: H.label || '',
			seg: 'a',
			first: first ? 1 : 0,
			h: tall(),
			marks: list,
			depth: depth,
			fresh: fresh
		};
	}

	// What has happened since the last time, and nothing twice: only the
	// first send of a visit counts as a view, and a band counts as reached
	// the first time it is seen.
	function flush() {
		if ( ! waiting() ) {
			return;
		}

		var b = body();

		b.fresh.forEach( function ( n ) { seen[ n ] = 1; } );
		marks = {};
		bands = {};
		first = false;
		post( b );

		// Kept in case this visit ends in an order; the thank-you page
		// sends it again, and then it counts as a buyer's page.
		try {
			var kept = JSON.parse( sessionStorage.getItem( KEEP ) || '[]' );

			kept.push( b );

			if ( kept.length > 12 ) {
				kept = [ kept[ 0 ] ].concat( kept.slice( -11 ) );
			}

			sessionStorage.setItem( KEEP, JSON.stringify( kept ) );
		} catch ( e ) {}
	}

	// A visit that reads for a while is sent in pieces, so nothing is lost
	// if the browser drops the last beacon on the way out.
	setInterval( flush, 20000 );
	addEventListener( 'pagehide', flush );
	addEventListener( 'visibilitychange', function () { if ( document.hidden ) { flush(); } } );
}() );
