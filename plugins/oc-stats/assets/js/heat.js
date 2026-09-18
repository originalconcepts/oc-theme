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
	var tip = null;
	var groups = [];
	var spots = [];
	var scale = 1;

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

	function num( n ) {
		return Number( n || 0 ).toLocaleString();
	}

	/* A group of pills. The pressed one is read from the state every time
	 * something changes, so the toolbar always says what is being shown —
	 * and every pill stays clickable, including the one you started on. */
	function pills( items, get, pick ) {
		var box = el( 'span', 'ocheat__tabs' );
		var btns = [];

		items.forEach( function ( it ) {
			var b = el( 'button', '', esc( it[ 1 ] ) );

			b.type = 'button';
			b.setAttribute( 'data-v', it[ 0 ] );
			b.addEventListener( 'click', function () {
				if ( it[ 0 ] === get() ) {
					return;
				}

				pick( it[ 0 ] );
				sync();
			} );
			btns.push( b );
			box.appendChild( b );
		} );

		groups.push( function () {
			btns.forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b.getAttribute( 'data-v' ) === get() ? 'true' : 'false' );
			} );
		} );

		return box;
	}

	function sync() {
		groups.forEach( function ( f ) { f(); } );
	}

	/* ---------- the shell ---------- */

	function build() {
		var root = el( 'div', 'ocheat' );
		var head = el( 'div', 'ocheat__head' );

		head.appendChild( el( 'strong', 'ocheat__title', esc( T.title || 'Heat map' ) ) );
		headNote = el( 'span', 'ocheat__note', '' );
		head.appendChild( headNote );

		// Walk to another page that has a map, without going back first.
		if ( C.pages && C.pages.length > 1 ) {
			var sel = el( 'select', 'ocheat__pick' );

			sel.setAttribute( 'aria-label', T.pick || '' );
			C.pages.forEach( function ( p ) {
				var o = document.createElement( 'option' );

				o.value = p.url;
				o.textContent = p.label;

				if ( p.on ) { o.selected = true; }

				sel.appendChild( o );
			} );
			sel.addEventListener( 'change', function () {
				location.href = sel.value + '&hr=' + encodeURIComponent( state.range ) + '&hd=' + encodeURIComponent( state.device );
			} );
			head.appendChild( sel );
		}

		var tools = el( 'div', 'ocheat__tools' );

		tools.appendChild( pills( [ [ 'today', T.today ], [ 'd7', T.d7 ], [ 'd30', T.d30 ], [ 'd90', T.d90 ] ],
			function () { return state.range; },
			function ( v ) {
				state.range = v;
				redraw( true );
			} ) );

		tools.appendChild( pills( [ [ 'm', T.mobile ], [ 't', T.tablet ], [ 'd', T.desktop ] ],
			function () { return state.device; },
			function ( v ) {
				state.device = v;
				reframe();
			} ) );

		tools.appendChild( pills( [ [ 'c', T.clicks ], [ 'd', T.dead ], [ 'r', T.rage ], [ 'a', T.attn ] ],
			function () { return state.layer; },
			function ( v ) {
				state.layer = v;
				paint();
			} ) );

		tools.appendChild( pills( [ [ 'a', T.everyone ], [ 'b', T.buyers ] ],
			function () { return state.seg; },
			function ( v ) {
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

		tip = el( 'div', 'ocheat__tip' );
		tip.hidden = true;
		root.appendChild( tip );

		document.documentElement.appendChild( root );
		document.documentElement.classList.add( 'ocheat-on' );
		sync();
	}

	/* ---------- the page, at the device's width ---------- */

	/* The frame is a fresh load of the page, so nothing below the fold has
	 * arrived yet and it would measure far too short — every mark would
	 * then sit below the thing it was aimed at. Give it a tall viewport,
	 * turn the lazy images eager, and wait for the height to settle. */
	function ready( d, done ) {
		var was = -1;
		var still = 0;
		var tries = 0;

		function look() {
			var h = Math.max( d.body ? d.body.scrollHeight : 0, d.body ? d.body.offsetHeight : 0 );

			still = h === was ? still + 1 : 0;
			was = h;
			++tries;

			if ( ( still >= 2 && h > 0 ) || tries > 24 ) {
				done( h || 800 );
				return;
			}

			setTimeout( look, 150 );
		}

		look();
	}

	function reframe() {
		var w = ( C.widths && C.widths[ state.device ] ) || 390;

		hideTip();
		stage.innerHTML = '';
		spots = [];

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

		scale = Math.min( 1, room / w );
		wrap.style.transform = 'scale(' + scale + ')';
		wrap.style.transformOrigin = 'top center';

		frame.addEventListener( 'load', function () {
			var d = null;

			try {
				d = frame.contentDocument;
			} catch ( e ) {}

			if ( ! d || ! d.body ) {
				fit( wrap, w, 800 );
				return;
			}

			var style = d.createElement( 'style' );

			style.textContent = '#wpadminbar{display:none!important}html{margin-top:0!important}';
			d.head.appendChild( style );

			// A tall viewport, so nothing counts as below the fold.
			frame.style.height = '30000px';

			Array.prototype.forEach.call( d.querySelectorAll( 'img[loading="lazy"],iframe[loading="lazy"]' ), function ( n ) {
				n.loading = 'eager';
			} );

			// The page inside is a picture, not a way out: a link would
			// carry the map off to a page that has none.
			[ 'click', 'submit', 'keydown' ].forEach( function ( ev ) {
				d.addEventListener( ev, function ( e ) {
					if ( 'keydown' === ev && 'Enter' !== e.key ) {
						return;
					}

					e.preventDefault();
					e.stopPropagation();
				}, true );
			} );

			ready( d, function ( h ) {
				fit( wrap, w, h );
			} );
		} );

		redraw( true );
	}

	function fit( wrap, w, h ) {
		frame.style.height = h + 'px';
		wrap.style.height = ( h * scale ) + 'px';
		canvas.width = w;
		canvas.height = h;
		canvas.style.width = w + 'px';
		canvas.style.height = h + 'px';
		canvas.addEventListener( 'mousemove', look );
		canvas.addEventListener( 'mouseleave', hideTip );
		paint();
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

	/* The page was not exactly this tall when the marks were made — a lazy
	 * image, a narrower phone, a line of text that wrapped. Stretch the
	 * marks onto the page as it stands, within reason. */
	function stretch() {
		var was = data && data.height ? data.height : 0;

		if ( ! was || ! canvas || ! canvas.height ) {
			return 1;
		}

		return Math.max( 0.5, Math.min( 2, canvas.height / was ) );
	}

	function paint() {
		if ( ! canvas || ! data ) {
			return;
		}

		var ctx = canvas.getContext( '2d' );

		ctx.clearRect( 0, 0, canvas.width, canvas.height );
		spots = [];

		if ( 'a' === state.layer ) {
			bands( ctx );
		} else {
			blobs( ctx );
		}

		side();
		hideTip();
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
		var all = list.reduce( function ( a, m ) { return a + m[ 3 ]; }, 0 ) || 1;
		var r = 'd' === state.device ? 44 : 30;
		var ky = stretch();

		list.forEach( function ( m ) {
			var x = ( m[ 1 ] / 100 ) * canvas.width;
			var y = m[ 2 ] * ky;
			var a = Math.min( 1, 0.25 + ( m[ 3 ] / top ) * 0.75 );
			var g = ctx.createRadialGradient( x, y, 0, x, y, r );

			spots.push( { x: x, y: y, n: m[ 3 ], pct: Math.round( ( m[ 3 ] / all ) * 100 ) } );

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

	/* ---------- what is under the pointer ---------- */

	function look( e ) {
		if ( ! spots.length ) {
			hideTip();
			return;
		}

		var rect = canvas.getBoundingClientRect();
		var x = ( e.clientX - rect.left ) * ( canvas.width / rect.width );
		var y = ( e.clientY - rect.top ) * ( canvas.height / rect.height );
		var reach = 'd' === state.device ? 44 : 30;
		var near = null;
		var best = reach * reach;

		spots.forEach( function ( s ) {
			var dx = s.x - x;
			var dy = s.y - y;
			var far = dx * dx + dy * dy;

			if ( far <= best ) {
				best = far;
				near = s;
			}
		} );

		if ( ! near ) {
			hideTip();
			return;
		}

		tip.textContent = num( near.n ) + ' ' + ( T.press || '' ) + ' · ' + near.pct + '%';
		tip.hidden = false;
		tip.style.insetInlineStart = 'auto';
		tip.style.left = Math.round( e.clientX + 14 ) + 'px';
		tip.style.top = Math.round( e.clientY + 14 ) + 'px';
	}

	function hideTip() {
		if ( tip ) {
			tip.hidden = true;
		}
	}

	/* ---------- the list beside it ---------- */

	function side() {
		var views = data.views || 0;
		var clicks = data.clicks || 0;

		headNote.textContent = num( views ) + ' ' + ( T.views || '' ) + ' · ' + num( clicks ) + ' ' + ( T.clicks || '' );

		var list = marksOf( 'a' === state.layer ? 'c' : state.layer ).slice().sort( function ( a, b ) { return b[ 3 ] - a[ 3 ]; } ).slice( 0, 12 );
		var total = marksOf( 'c' ).reduce( function ( a, m ) { return a + m[ 3 ]; }, 0 ) || 1;
		var ky = stretch();

		var html = '<h3>' + esc( T.spots || '' ) + '</h3>';

		if ( 'd' === state.layer ) {
			html += '<p class="ocheat__hint">' + esc( T.deadNote || '' ) + '</p>';
		}

		if ( ! list.length ) {
			html += '<p class="ocheat__hint">' + esc( T.none || '' ) + '</p>';
		} else {
			html += '<ol class="ocheat__spots">';
			list.forEach( function ( m ) {
				html += '<li><span>' + Math.round( m[ 1 ] ) + '% · ' + Math.round( m[ 2 ] * ky ) + 'px</span><b>' + num( m[ 3 ] ) +
					' <i>' + Math.round( ( m[ 3 ] / total ) * 100 ) + '%</i></b></li>';
			} );
			html += '</ol>';
			html += '<p class="ocheat__hint">' + esc( T.ofClicks || '' ) + '</p>';
		}

		html += '<p class="ocheat__hint">' + esc( T.frozen || '' ) + '</p>';
		sideBox.innerHTML = html;
	}

	build();
	reframe();
	addEventListener( 'resize', function () { reframe(); } );
}() );
