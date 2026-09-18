/* The heat map viewer. Draws the marks over the page itself, in a frame at
 * the width of the device they were recorded on — a click made on a phone
 * sits somewhere else entirely in a desktop layout. Loaded only when an
 * administrator asks for it. */
( function () {
	'use strict';

	var C = window.ocHeatView;

	if ( ! C || ! C.rest ) {
		return;
	}

	var T = C.i18n || {};
	var state = {
		range: C.range || 'd7',
		device: C.device || 'm',
		layer: 'c',
		seg: 'a'
	};
	var data = null;
	var frame = null;
	var canvas = null;
	var stage = null;
	var sideBox = null;
	var headNote = null;

	function el( tag, cls, html ) {
		var n = document.createElement( tag );

		if ( cls ) { n.className = cls; }
		if ( undefined !== html ) { n.innerHTML = html; }

		return n;
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function pills( items, now, pick ) {
		var box = el( 'span', 'ocheat__tabs' );

		items.forEach( function ( it ) {
			var b = el( 'button', '', esc( it[ 1 ] ) );
			b.type = 'button';
			b.setAttribute( 'aria-pressed', it[ 0 ] === now ? 'true' : 'false' );
			b.addEventListener( 'click', function () {
				if ( it[ 0 ] !== now ) { pick( it[ 0 ] ); }
			} );
			box.appendChild( b );
		} );

		return box;
	}

	/* ---------- the shell ---------- */

	function build() {
		var root = el( 'div', 'ocheat' );
		var head = el( 'div', 'ocheat__head' );

		head.appendChild( el( 'strong', 'ocheat__title', esc( T.title || 'Heat map' ) ) );
		headNote = el( 'span', 'ocheat__note', '' );
		head.appendChild( headNote );

		var tools = el( 'div', 'ocheat__tools' );

		tools.appendChild( pills( [ [ 'today', T.today ], [ 'd7', T.d7 ], [ 'd30', T.d30 ], [ 'd90', T.d90 ] ], state.range, function ( v ) {
			state.range = v;
			redraw( true );
		} ) );

		tools.appendChild( pills( [ [ 'm', T.mobile ], [ 't', T.tablet ], [ 'd', T.desktop ] ], state.device, function ( v ) {
			state.device = v;
			reframe();
		} ) );

		tools.appendChild( pills( [ [ 'c', T.clicks ], [ 'd', T.dead ], [ 'r', T.rage ], [ 'a', T.attn ] ], state.layer, function ( v ) {
			state.layer = v;
			paint();
		} ) );

		tools.appendChild( pills( [ [ 'a', T.everyone ], [ 'b', T.buyers ] ], state.seg, function ( v ) {
			state.seg = v;
			redraw( true );
		} ) );

		var close = el( 'button', 'ocheat__close', esc( T.close || 'Close' ) );
		close.type = 'button';
		close.addEventListener( 'click', function () {
			var u = new URL( location.href );
			[ 'oc_heat', 'hp', 'hr', 'hd' ].forEach( function ( k ) { u.searchParams.delete( k ); } );
			location.href = u.toString();
		} );

		head.appendChild( tools );
		head.appendChild( close );
		root.appendChild( head );

		var body = el( 'div', 'ocheat__body' );
		stage = el( 'div', 'ocheat__stage' );
		body.appendChild( stage );

		sideBox = el( 'aside', 'ocheat__side' );
		body.appendChild( sideBox );
		root.appendChild( body );

		document.documentElement.appendChild( root );
		document.documentElement.classList.add( 'ocheat-on' );
	}

	/* ---------- the page, at the device's width ---------- */

	function reframe() {
		var w = ( C.widths && C.widths[ state.device ] ) || 390;

		stage.innerHTML = '';

		var wrap = el( 'div', 'ocheat__wrap' );
		wrap.style.width = w + 'px';

		frame = document.createElement( 'iframe' );
		frame.className = 'ocheat__frame';
		frame.setAttribute( 'scrolling', 'no' );
		frame.width = w;

		var u = new URL( location.href );
		[ 'oc_heat', 'hp', 'hr', 'hd' ].forEach( function ( k ) { u.searchParams.delete( k ); } );
		u.searchParams.set( 'oc_heat_frame', '1' );
		frame.src = u.toString();

		canvas = el( 'canvas', 'ocheat__canvas' );

		wrap.appendChild( frame );
		wrap.appendChild( canvas );
		stage.appendChild( wrap );

		// Fit the chosen width into whatever room the screen has.
		var room = stage.clientWidth - 24;
		var scale = Math.min( 1, room / w );
		wrap.style.transform = 'scale(' + scale + ')';
		wrap.style.transformOrigin = 'top center';

		frame.addEventListener( 'load', function () {
			var h = 800;

			try {
				var d = frame.contentDocument;
				var style = d.createElement( 'style' );
				style.textContent = '#wpadminbar{display:none!important}html{margin-top:0!important}';
				d.head.appendChild( style );
				h = Math.max( d.body.scrollHeight, d.documentElement.scrollHeight );
			} catch ( e ) {}

			frame.style.height = h + 'px';
			wrap.style.height = ( h * scale ) + 'px';
			canvas.width = w;
			canvas.height = h;
			canvas.style.width = w + 'px';
			canvas.style.height = h + 'px';
			paint();
		} );

		redraw( true );
	}

	/* ---------- the numbers ---------- */

	function redraw( fetchAgain ) {
		if ( ! fetchAgain ) {
			paint();
			return;
		}

		var q = C.rest + '?range=' + encodeURIComponent( state.range ) +
			'&device=' + encodeURIComponent( state.device ) +
			'&seg=' + encodeURIComponent( state.seg ) +
			'&page=' + encodeURIComponent( C.page || 0 ) +
			'&path=' + encodeURIComponent( C.path || location.pathname );

		fetch( q, { headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				data = j;
				paint();
			} )
			.catch( function () {} );
	}

	/* ---------- drawing ---------- */

	function ramp( a ) {
		// blue → green → yellow → red, the way a heat map is read.
		if ( a < 0.25 ) { return [ 0, Math.round( 90 + a * 400 ), 255 ]; }
		if ( a < 0.5 ) { return [ 0, 200, Math.round( 255 - ( a - 0.25 ) * 900 ) ]; }
		if ( a < 0.75 ) { return [ Math.round( ( a - 0.5 ) * 1000 ), 230, 0 ]; }

		return [ 255, Math.round( 200 - ( a - 0.75 ) * 700 ), 0 ];
	}

	function paint() {
		if ( ! canvas || ! data ) {
			return;
		}

		var ctx = canvas.getContext( '2d' );
		ctx.clearRect( 0, 0, canvas.width, canvas.height );

		if ( 'a' === state.layer ) {
			bands( ctx );
		} else {
			blobs( ctx );
		}

		side();
	}

	function marksOf( kind ) {
		return ( data.marks || [] ).filter( function ( m ) { return m[ 0 ] === kind; } );
	}

	function blobs( ctx ) {
		var list = marksOf( state.layer );

		if ( ! list.length ) {
			return;
		}

		var top = list.reduce( function ( a, m ) { return Math.max( a, m[ 3 ] ); }, 1 );
		var r = 'd' === state.device ? 44 : 30;

		list.forEach( function ( m ) {
			var x = ( m[ 1 ] / 100 ) * canvas.width;
			var y = m[ 2 ];
			var a = Math.min( 1, 0.25 + ( m[ 3 ] / top ) * 0.75 );
			var g = ctx.createRadialGradient( x, y, 0, x, y, r );

			g.addColorStop( 0, 'rgba(0,0,0,' + a + ')' );
			g.addColorStop( 1, 'rgba(0,0,0,0)' );
			ctx.fillStyle = g;
			ctx.beginPath();
			ctx.arc( x, y, r, 0, Math.PI * 2 );
			ctx.fill();
		} );

		// Alpha became heat; now give it colour.
		var img = ctx.getImageData( 0, 0, canvas.width, canvas.height );
		var px = img.data;

		for ( var i = 0; i < px.length; i += 4 ) {
			var a = px[ i + 3 ] / 255;

			if ( ! a ) { continue; }

			var c = ramp( Math.min( 1, a ) );
			px[ i ] = c[ 0 ];
			px[ i + 1 ] = c[ 1 ];
			px[ i + 2 ] = c[ 2 ];
			px[ i + 3 ] = Math.round( Math.min( 0.72, a ) * 255 );
		}

		ctx.putImageData( img, 0, 0 );
	}

	function bands( ctx ) {
		var list = data.bands || [];

		if ( ! list.length ) {
			return;
		}

		var top = list.reduce( function ( a, b ) { return Math.max( a, b[ 2 ] ); }, 1 );
		var band = canvas.height / 20;

		list.forEach( function ( b ) {
			var a = Math.min( 0.62, 0.06 + ( b[ 2 ] / top ) * 0.56 );
			var c = ramp( b[ 2 ] / top );

			ctx.fillStyle = 'rgba(' + c[ 0 ] + ',' + c[ 1 ] + ',' + c[ 2 ] + ',' + a + ')';
			ctx.fillRect( 0, b[ 0 ] * band, canvas.width, band + 1 );
		} );

		// Where half of them stopped.
		var most = list.length ? list[ 0 ][ 1 ] : 0;
		var half = 0;

		list.forEach( function ( b ) {
			if ( b[ 1 ] >= most / 2 ) { half = b[ 0 ]; }
		} );

		if ( most > 0 ) {
			var y = ( half + 1 ) * band;

			ctx.strokeStyle = '#111';
			ctx.setLineDash( [ 8, 6 ] );
			ctx.lineWidth = 2;
			ctx.beginPath();
			ctx.moveTo( 0, y );
			ctx.lineTo( canvas.width, y );
			ctx.stroke();
			ctx.setLineDash( [] );
			ctx.fillStyle = '#111';
			ctx.font = '13px system-ui, sans-serif';
			ctx.fillText( T.reach || '', 10, y - 8 );
		}
	}

	/* ---------- the list beside it ---------- */

	function side() {
		var views = data.views || 0;
		var clicks = data.clicks || 0;

		headNote.textContent = views.toLocaleString() + ' ' + ( T.views || '' ) + ' · ' + clicks.toLocaleString() + ' ' + ( T.clicks || '' );

		var list = marksOf( 'a' === state.layer ? 'c' : state.layer ).slice().sort( function ( a, b ) { return b[ 3 ] - a[ 3 ]; } ).slice( 0, 12 );
		var total = marksOf( 'c' ).reduce( function ( a, m ) { return a + m[ 3 ]; }, 0 ) || 1;

		var html = '<h3>' + esc( T.spots || '' ) + '</h3>';

		if ( 'd' === state.layer ) {
			html += '<p class="ocheat__hint">' + esc( T.deadNote || '' ) + '</p>';
		}

		if ( ! list.length ) {
			html += '<p class="ocheat__hint">' + esc( T.none || '' ) + '</p>';
		} else {
			html += '<ol class="ocheat__spots">';
			list.forEach( function ( m ) {
				html += '<li><span>' + Math.round( m[ 1 ] ) + '% · ' + m[ 2 ] + 'px</span><b>' + m[ 3 ].toLocaleString() +
					' <i>' + Math.round( ( m[ 3 ] / total ) * 100 ) + '%</i></b></li>';
			} );
			html += '</ol>';
			html += '<p class="ocheat__hint">' + esc( T.ofClicks || '' ) + '</p>';
		}

		sideBox.innerHTML = html;
	}

	build();
	reframe();
	addEventListener( 'resize', function () { reframe(); } );
}() );
