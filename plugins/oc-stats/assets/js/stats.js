/* OC Statistics — the shop's own counter. */
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
