/* Statistics screen: one fetch per range, drawn with plain SVG. */
( function () {
	'use strict';

	var root = document.getElementById( 'oc-stats' );

	if ( ! root || ! window.ocStats ) {
		return;
	}

	var C = window.ocStats;
	var T = C.i18n || {};
	var cur = { range: 'd30', from: '', to: '' };
	var body = root.querySelector( '[data-body]' );
	var sub = root.querySelector( '[data-sub]' );
	var pickers = root.querySelector( '[data-pickers]' );
	var custom = root.querySelector( '[data-custom]' );

	function el( tag, cls, html ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( html !== undefined ) { e.innerHTML = html; }
		return e;
	}

	function esc( s ) {
		return String( s ).replace( /[&<>"]/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ]; } );
	}

	function fmtInt( n ) {
		return Number( n || 0 ).toLocaleString( 'en-US' );
	}

	function fmtMoney( n ) {
		n = Number( n || 0 );
		var whole = Math.abs( n ) >= 100 || n === Math.round( n );
		return C.currency + n.toLocaleString( 'en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 } );
	}

	function fmt( v, kind ) {
		if ( v === null || v === undefined ) { return '—'; }
		if ( kind === 'money' ) { return fmtMoney( v ); }
		if ( kind === 'pct' ) { return Number( v ).toLocaleString( 'en-US', { maximumFractionDigits: 2 } ) + '%'; }
		return fmtInt( v );
	}

	function delta( d, points ) {
		if ( d === null || d === undefined ) { return '<span class="ocst__delta ocst__delta--flat">—</span>'; }
		var cls = d > 0 ? 'up' : ( d < 0 ? 'down' : 'flat' );
		var txt = ( d > 0 ? '+' : '' ) + Number( d ).toLocaleString( 'en-US', { maximumFractionDigits: points ? 2 : 1 } ) + ( points ? '' : '%' );
		return '<span class="ocst__delta ocst__delta--' + cls + '">' + txt + '</span>';
	}

	/* ---------- pickers ---------- */
	var RANGES = [ 'today', 'yesterday', 'd7', 'd30', 'month', 'lmonth', 'd90', 'custom' ];

	function drawPickers() {
		pickers.innerHTML = '';
		RANGES.forEach( function ( r ) {
			var b = el( 'button', 'ocst__pill', esc( T[ r ] || r ) );
			b.type = 'button';
			b.dataset.range = r;
			b.setAttribute( 'aria-pressed', r === cur.range ? 'true' : 'false' );
			b.addEventListener( 'click', function () {
				if ( r === 'custom' ) {
					custom.hidden = false;
					pickers.querySelectorAll( '.ocst__pill' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' ); } );
					return;
				}
				custom.hidden = true;
				load( r, '', '' );
			} );
			pickers.appendChild( b );
		} );
		var csv = el( 'a', 'ocst__pill ocst__pill--link', esc( T.exportCsv ) );
		csv.href = '#';
		csv.dataset.csv = '1';
		csv.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			location.href = C.csv + '&range=' + encodeURIComponent( cur.range ) + '&from=' + encodeURIComponent( cur.from ) + '&to=' + encodeURIComponent( cur.to );
		} );
		pickers.appendChild( csv );
	}

	custom.querySelector( '[data-go]' ).addEventListener( 'click', function () {
		load( 'custom', custom.querySelector( '[data-from]' ).value, custom.querySelector( '[data-to]' ).value );
	} );

	/* ---------- pieces ---------- */
	function spark( vals ) {
		if ( ! vals.length ) { return ''; }
		var w = 56, h = 18, max = Math.max.apply( null, vals ), min = Math.min.apply( null, vals ), rng = ( max - min ) || 1;
		var pts = vals.map( function ( v, i ) { return [ ( i / Math.max( 1, vals.length - 1 ) ) * ( w - 2 ) + 1, h - 1 - ( ( v - min ) / rng ) * ( h - 3 ) ]; } );
		var d = pts.map( function ( p, i ) { return ( i ? 'L' : 'M' ) + p[ 0 ].toFixed( 1 ) + ' ' + p[ 1 ].toFixed( 1 ); } ).join( ' ' );
		var last = pts[ pts.length - 1 ];
		return '<svg class="ocst__spark" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true"><path d="' + d + '" fill="none" stroke="var(--accent)" stroke-width="1.6" stroke-linejoin="round"/><circle cx="' + last[ 0 ].toFixed( 1 ) + '" cy="' + last[ 1 ].toFixed( 1 ) + '" r="1.9" fill="var(--accent)"/></svg>';
	}

	function kpis( data ) {
		var series = data.series || [];
		var idx = { sales: 1, orders: 2, visits: 3 };
		var box = el( 'div', 'ocst__kpis' );
		data.kpis.forEach( function ( k ) {
			var vals = idx[ k[ 0 ] ] ? series.map( function ( s ) { return s[ idx[ k[ 0 ] ] ]; } ) : [];
			var points = k[ 0 ] === 'conv' || k[ 0 ] === 'returning';
			box.innerHTML += '<div class="ocst__kpi"><div class="ocst__kpi-l">' + esc( T[ k[ 0 ] ] || k[ 0 ] ) + '</div><div class="ocst__kpi-v">' + esc( fmt( k[ 1 ], k[ 2 ] ) ) + '</div><div class="ocst__kpi-f">' + delta( k[ 3 ], points ) + spark( vals ) + '</div></div>';
		} );
		return box;
	}

	function chart( svg, curS, prevS, hourly ) {
		var w = 640, h = 220, padT = 16, padB = 26, padS = 34, padE = 10;
		var cv = curS.map( function ( s ) { return s[ 1 ]; } );
		var pv = prevS.map( function ( s ) { return s[ 1 ]; } );
		var max = Math.max( 1, Math.max.apply( null, cv.concat( pv ) ) ) * 1.08;
		var n = Math.max( curS.length, prevS.length, 2 );
		var X = function ( i ) { return padS + ( i / ( n - 1 ) ) * ( w - padS - padE ); };
		var Y = function ( v ) { return padT + ( 1 - v / max ) * ( h - padT - padB ); };
		var line = function ( arr ) { return arr.map( function ( v, i ) { return ( i ? 'L' : 'M' ) + X( i ).toFixed( 1 ) + ' ' + Y( v ).toFixed( 1 ); } ).join( ' ' ); };
		var g = '';
		for ( var t = 0; t <= 4; t++ ) {
			var v = max / 4 * t, y = Y( v );
			g += '<line x1="' + padS + '" x2="' + ( w - padE ) + '" y1="' + y.toFixed( 1 ) + '" y2="' + y.toFixed( 1 ) + '" stroke="var(--line)" stroke-width="1"/>';
			g += '<text x="' + ( padS - 6 ) + '" y="' + ( y + 3 ).toFixed( 1 ) + '" text-anchor="end" font-size="9.5" font-family="ui-monospace,Menlo,monospace" fill="var(--ink3)">' + ( v >= 1000 ? Math.round( v / 1000 ) + 'K' : Math.round( v ) ) + '</text>';
		}
		// x labels: first, middle, last
		var labels = [ 0, Math.floor( ( curS.length - 1 ) / 2 ), curS.length - 1 ];
		labels.forEach( function ( i ) {
			if ( ! curS[ i ] ) { return; }
			var lab = hourly ? curS[ i ][ 0 ] : curS[ i ][ 0 ].slice( 8, 10 ) + '.' + curS[ i ][ 0 ].slice( 5, 7 );
			g += '<text x="' + X( i ).toFixed( 1 ) + '" y="' + ( h - 8 ) + '" text-anchor="middle" font-size="9.5" font-family="ui-monospace,Menlo,monospace" fill="var(--ink3)">' + lab + '</text>';
		} );
		var area = cv.length ? line( cv ) + ' L ' + X( cv.length - 1 ).toFixed( 1 ) + ' ' + Y( 0 ).toFixed( 1 ) + ' L ' + X( 0 ).toFixed( 1 ) + ' ' + Y( 0 ).toFixed( 1 ) + ' Z' : '';
		var last = cv.length ? '<circle cx="' + X( cv.length - 1 ).toFixed( 1 ) + '" cy="' + Y( cv[ cv.length - 1 ] ).toFixed( 1 ) + '" r="3.4" fill="var(--accent)" stroke="#fff" stroke-width="1.6"/>' : '';
		var dots = cv.map( function ( v, i ) { return '<circle data-i="' + i + '" cx="' + X( i ).toFixed( 1 ) + '" cy="' + Y( v ).toFixed( 1 ) + '" r="9" fill="transparent"/>'; } ).join( '' );
		svg.setAttribute( 'viewBox', '0 0 ' + w + ' ' + h );
		svg.innerHTML = '<defs><linearGradient id="ocstg" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="var(--accent)" stop-opacity="0.2"/><stop offset="100%" stop-color="var(--accent)" stop-opacity="0"/></linearGradient></defs>' + g +
			( area ? '<path d="' + area + '" fill="url(#ocstg)"/>' : '' ) +
			( pv.length ? '<path d="' + line( pv ) + '" fill="none" stroke="var(--ink3)" stroke-width="1.4" stroke-dasharray="3 3" opacity="0.8"/>' : '' ) +
			( cv.length ? '<path d="' + line( cv ) + '" fill="none" stroke="var(--accent)" stroke-width="2.2" stroke-linejoin="round"/>' : '' ) + last + dots;

		var wrap = svg.parentNode, tip = wrap.querySelector( '.ocst__tip' );
		svg.addEventListener( 'mousemove', function ( e ) {
			var c = e.target.closest( 'circle[data-i]' );
			if ( ! c ) { if ( tip ) { tip.hidden = true; } return; }
			var i = +c.dataset.i, s = curS[ i ], p = prevS[ i ];
			if ( ! tip ) { tip = el( 'div', 'ocst__tip' ); wrap.appendChild( tip ); }
			tip.hidden = false;
			tip.innerHTML = esc( hourly ? s[ 0 ] : s[ 0 ].slice( 8, 10 ) + '.' + s[ 0 ].slice( 5, 7 ) ) + ': <b>' + esc( fmtMoney( s[ 1 ] ) ) + '</b> · ' + esc( fmtInt( s[ 2 ] ) ) + ' ' + esc( T.orders ) + ( p ? ' <span style="opacity:.7">(' + esc( fmtMoney( p[ 1 ] ) ) + ')</span>' : '' );
			var r = wrap.getBoundingClientRect(), b = c.getBoundingClientRect();
			tip.style.left = ( b.left + b.width / 2 - r.left ) + 'px';
			tip.style.top = ( b.top - r.top ) + 'px';
		} );
		svg.addEventListener( 'mouseleave', function () { if ( tip ) { tip.hidden = true; } } );
	}

	function funnel( steps ) {
		var box = el( 'div', 'ocst__funnel' );
		var top = steps[ 0 ][ 1 ] || 0, worst = -1, worstI = -1;
		steps.forEach( function ( s, i ) {
			var pct = top > 0 ? Math.max( 6, Math.round( s[ 1 ] / top * 100 ) ) : 0;
			box.appendChild( el( 'div', 'ocst__step', '<span class="ocst__step-fill" style="width:' + pct + '%"></span><span class="ocst__step-t">' + esc( T[ s[ 0 ] ] ) + '</span><span class="ocst__step-n">' + esc( fmtInt( s[ 1 ] ) ) + '</span>' ) );
			if ( i < steps.length - 1 && s[ 1 ] > 0 ) {
				var drop = Math.round( ( 1 - steps[ i + 1 ][ 1 ] / s[ 1 ] ) * 100 );
				if ( drop > worst ) { worst = drop; worstI = i; }
				box.appendChild( el( 'div', 'ocst__drop ' + ( drop >= 70 ? '' : 'ok' ), '<span>' + esc( T.drop ) + '</span><b>−' + drop + '%</b>' ) );
			} else if ( i < steps.length - 1 ) {
				box.appendChild( el( 'div', 'ocst__drop ok', '<span>' + esc( T.drop ) + '</span><b>—</b>' ) );
			}
		} );
		if ( worstI >= 0 && top > 0 ) {
			var name = T[ steps[ worstI ][ 0 ] ] + ' → ' + T[ steps[ worstI + 1 ][ 0 ] ];
			box.appendChild( el( 'p', 'ocst__hint', esc( ( T.biggestDrop || '%s' ).replace( '%s', '' ) ).replace( /^/, '' ) + '<b>' + esc( name ) + '</b>' ) );
			box.lastChild.innerHTML = esc( T.biggestDrop ).replace( '%s', '<b>' + esc( name ) + '</b>' );
		}
		return box;
	}

	function rows( list, labelOf, valueOf, moneyOf, barOf, heads, hrefOf ) {
		var box = el( 'div', 'ocst__rows' );
		var max = Math.max.apply( null, list.map( barOf ).concat( [ 1 ] ) );
		if ( heads && list.length ) {
			box.appendChild( el( 'div', 'ocst__row ocst__row--h', '<span></span><span class="n">' + esc( heads[ 0 ] ) + '</span><span class="m">' + esc( heads[ 1 ] ) + '</span>' ) );
		}
		list.forEach( function ( r ) {
			var label = hrefOf && hrefOf( r ) ? '<a href="' + esc( hrefOf( r ) ) + '">' + esc( labelOf( r ) ) + '</a>' : esc( labelOf( r ) );
			box.appendChild( el( 'div', 'ocst__row', '<span>' + label + '</span><span class="n">' + esc( valueOf( r ) ) + '</span><span class="m">' + esc( moneyOf( r ) ) + '</span><span class="ocst__bar"><span style="width:' + Math.max( 3, Math.round( barOf( r ) / max * 100 ) ) + '%"></span></span>' ) );
		} );
		if ( ! list.length ) { box.appendChild( el( 'p', 'ocst__empty', '—' ) ); }
		return box;
	}

	/* Where they came from + devices: one switch, orders or visits. */
	var pairMode = 'orders';

	function pair( data ) {
		var box = el( 'div', 'ocst__pair' );
		var visitsAll = data.channels.reduce( function ( a, r ) { return a + ( r[ 3 ] || 0 ); }, 0 ) || 1;
		var chan, dev;
		if ( pairMode === 'orders' ) {
			chan = rows( data.channels, function ( r ) { return ( T.ch && T.ch[ r[ 0 ] ] ) || r[ 0 ]; }, function ( r ) { return fmtInt( r[ 1 ] ); }, function ( r ) { return fmtMoney( r[ 2 ] ); }, function ( r ) { return r[ 2 ]; }, [ T.orders, T.sales ] );
			dev = rows( data.devices, function ( r ) { return T[ r[ 0 ] ] || r[ 0 ]; }, function ( r ) { return fmtInt( r[ 1 ] ); }, function ( r ) { return r[ 2 ] === null ? '—' : r[ 2 ] + '%'; }, function ( r ) { return r[ 1 ]; }, [ T.orders, T.convH ] );
		} else {
			var byVisits = data.channels.slice().sort( function ( a, b ) { return ( b[ 3 ] || 0 ) - ( a[ 3 ] || 0 ); } );
			chan = rows( byVisits, function ( r ) { return ( T.ch && T.ch[ r[ 0 ] ] ) || r[ 0 ]; }, function ( r ) { return fmtInt( r[ 3 ] || 0 ); }, function ( r ) { return Math.round( ( r[ 3 ] || 0 ) / visitsAll * 100 ) + '%'; }, function ( r ) { return r[ 3 ] || 0; }, [ T.visits, T.share ] );
			dev = rows( data.devices, function ( r ) { return T[ r[ 0 ] ] || r[ 0 ]; }, function ( r ) { return fmtInt( r[ 4 ] || 0 ); }, function ( r ) { return ( r[ 3 ] || 0 ) + '%'; }, function ( r ) { return r[ 4 ] || 0; }, [ T.visits, T.share ] );
		}
		var scroll = el( 'div', 'ocst__scroll' );
		scroll.appendChild( chan );
		var c1 = card( T.channels, '', scroll );
		var c2 = card( T.devices, '', dev );
		[ c1, c2 ].forEach( function ( c ) {
			var tabs = el( 'span', 'ocst__tabs', '<button type="button" data-mode="orders" aria-pressed="' + ( pairMode === 'orders' ) + '">' + esc( T.orders ) + '</button><button type="button" data-mode="visits" aria-pressed="' + ( pairMode === 'visits' ) + '">' + esc( T.visits ) + '</button>' );
			tabs.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					if ( b.dataset.mode === pairMode ) { return; }
					pairMode = b.dataset.mode;
					var fresh = pair( data );
					box.replaceWith( fresh );
				} );
			} );
			c.querySelector( '.ocst__card-h' ).appendChild( tabs );
		} );
		box.appendChild( c1 );
		box.appendChild( c2 );
		return box;
	}

	function products( list ) {
		var wrap = el( 'div', 'ocst__tblwrap' );
		var html = '<table class="ocst__tbl"><thead><tr><th>' + esc( T.product ) + '</th><th class="n">' + esc( T.views ) + '</th><th class="n">' + esc( T.toCart ) + '</th><th class="n">' + esc( T.orders ) + '</th><th class="n">' + esc( T.sales ) + '</th></tr></thead><tbody>';
		list.forEach( function ( p ) {
			html += '<tr><td><span class="ocst__prod">' + ( p[ 6 ] || '<i></i>' ) + ( p[ 1 ] ? '<a href="' + esc( p[ 1 ] ) + '">' + esc( p[ 0 ] ) + '</a>' : esc( p[ 0 ] ) ) + '</span></td><td class="n">' + fmtInt( p[ 2 ] ) + '</td><td class="n">' + fmtInt( p[ 3 ] ) + '</td><td class="n">' + fmtInt( p[ 4 ] ) + '</td><td class="n">' + esc( fmtMoney( p[ 5 ] ) ) + '</td></tr>';
		} );
		if ( ! list.length ) { html += '<tr><td colspan="5">—</td></tr>'; }
		wrap.innerHTML = html + '</tbody></table>';
		return wrap;
	}

	function insights( list ) {
		var box = el( 'div', 'ocst__ins' );
		if ( ! list.length ) { box.appendChild( el( 'p', 'ocst__empty', esc( T.noInsights ) ) ); return box; }
		list.forEach( function ( x ) {
			var card = el( 'div', 'ocst__insc ocst__insc--' + x.sev );
			var actions = ( x.actions || [] ).map( function ( a, i ) { return '<a class="ocst__btn' + ( i ? ' ocst__btn--ghost' : '' ) + '" href="' + esc( a[ 1 ] ) + '">' + esc( a[ 0 ] ) + '</a>'; } ).join( '' );
			card.innerHTML = '<span class="ocst__chip ocst__chip--' + x.sev + '">' + esc( x.kind ) + '</span><div class="ocst__ins-t">' + esc( x.title ) + '</div><div class="ocst__ins-b">' + esc( x.body ) + '</div><div class="ocst__ins-a">' + actions + '<button type="button" class="ocst__btn ocst__btn--ghost" data-dismiss="' + esc( x.key ) + '">' + esc( T.dismiss ) + '</button></div><details class="ocst__why"><summary>' + esc( T.how ) + '</summary><p>' + esc( x.why ) + '</p></details>';
			card.querySelector( '[data-dismiss]' ).addEventListener( 'click', function () {
				fetch( C.rest + '/dismiss', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce }, body: JSON.stringify( { key: x.key } ) } ).catch( function () {} );
				card.remove();
				if ( ! box.children.length ) { box.appendChild( el( 'p', 'ocst__empty', esc( T.noInsights ) ) ); }
			} );
			box.appendChild( card );
		} );
		return box;
	}

	function breakdown( bd, customers ) {
		var box = el( 'div', 'ocst__bd' );
		bd.forEach( function ( b ) {
			box.appendChild( el( 'div', '', '<span>' + esc( T[ b[ 0 ] ] ) + '</span><b>' + ( b[ 0 ] === 'refunds' ? esc( fmtMoney( b[ 2 ] ) ) : fmtInt( b[ 1 ] ) ) + '</b>' + ( b[ 0 ] === 'refunds' ? '' : '<small>' + esc( fmtMoney( b[ 2 ] ) ) + '</small>' ) ) );
		} );
		return box;
	}

	function card( title, side, node, big ) {
		var c = el( 'div', 'ocst__card' );
		c.innerHTML = '<div class="ocst__card-h"><div><h2>' + esc( title ) + '</h2>' + ( big ? '<div class="ocst__big">' + esc( big ) + '</div>' : '' ) + '</div>' + ( side ? '<span class="ocst__side">' + esc( side ) + '</span>' : '' ) + '</div>';
		c.appendChild( node );
		return c;
	}

	/* ---------- draw ---------- */
	function draw( data ) {
		body.innerHTML = '';
		var hourly = data.granularity === 'hour';
		var total = data.kpis[ 0 ][ 1 ];

		var dateLabel = data.range === 'today' ? T.today : ( data.range === 'yesterday' ? T.yesterday : data.from.split( '-' ).reverse().join( '.' ) + ' – ' + data.to.split( '-' ).reverse().join( '.' ) );
		var vs = { today: T.vsYesterday, month: T.vsMonth, lmonth: T.vsLmonth }[ data.range ] || T.vsPrev;
		sub.textContent = dateLabel + ' · ' + vs + ' · ' + T.updated;

		if ( ! data.tracking ) { body.appendChild( el( 'p', 'ocst__note', esc( T.noVisits ) ) ); }
		else if ( data.since && data.from < data.since ) { body.appendChild( el( 'p', 'ocst__note', esc( T.sinceNote ).replace( '%s', '<b>' + esc( data.since.split( '-' ).reverse().join( '.' ) ) + '</b>' ) ) ); }

		body.appendChild( kpis( data ) );

		var main = el( 'div', 'ocst__grid ocst__grid--main' );
		var cw = el( 'div', 'ocst__chartwrap' );
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'class', 'ocst__chart' );
		cw.appendChild( svg );
		var chartCard = card( hourly ? T.salesByHour : T.salesByDay, T.grossNote, cw, fmtMoney( total ) );
		chartCard.appendChild( el( 'div', 'ocst__legend', '<span><i></i>' + esc( T.cur ) + '</span><span class="prev"><i></i>' + esc( T.prev ) + '</span>' ) );
		main.appendChild( chartCard );
		main.appendChild( card( T.funnel, dateLabel, funnel( data.funnel ) ) );
		body.appendChild( main );
		chart( svg, data.series, data.prev_series, hourly );

		var three = el( 'div', 'ocst__grid ocst__grid--three' );
		three.appendChild( pair( data ) );
		three.appendChild( card( T.brands, T.byGross, rows( data.brands || [], function ( r ) { return r[ 0 ]; }, function ( r ) { return fmtInt( r[ 3 ] ); }, function ( r ) { return fmtMoney( r[ 4 ] ); }, function ( r ) { return r[ 4 ]; }, [ T.units, T.sales ], function ( r ) { return r[ 1 ]; } ) ) );
		three.appendChild( card( T.products, T.byGross, products( data.products ) ) );
		body.appendChild( three );

		var two = el( 'div', 'ocst__grid ocst__grid--two' );
		two.appendChild( card( T.breakdown, '', breakdown( data.breakdown ) ) );
		var cust = el( 'div', 'ocst__bd' );
		cust.innerHTML = '<div><span>' + esc( T.newCust ) + '</span><b>' + fmtInt( data.customers[ 0 ] ) + '</b></div><div><span>' + esc( T.returning ) + '</span><b>' + fmtInt( data.customers[ 1 ] ) + '</b></div>'
			+ ( data.leads ? '<div><span>' + esc( T.leads ) + '</span><b>' + fmtInt( data.leads[ 0 ] ) + '</b><small>' + esc( T.leadsPrev ) + ': ' + fmtInt( data.leads[ 1 ] ) + '</small></div>' : '' );
		two.appendChild( card( T.customers, '', cust ) );
		body.appendChild( two );

		var insBox = el( 'div', 'ocst__grid' );
		insBox.appendChild( card( T.insights, T.insightsSub, insights( data.insights || [] ) ) );
		body.appendChild( insBox );
	}

	function load( range, from, to ) {
		cur = { range: range, from: from || '', to: to || '' };
		pickers.querySelectorAll( '.ocst__pill[data-range]' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', x.dataset.range === range ? 'true' : 'false' ); } );
		body.classList.add( 'is-loading' );
		fetch( C.rest + '?range=' + encodeURIComponent( range ) + '&from=' + encodeURIComponent( cur.from ) + '&to=' + encodeURIComponent( cur.to ), { headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) { body.classList.remove( 'is-loading' ); draw( data ); } )
			.catch( function () { body.classList.remove( 'is-loading' ); body.innerHTML = '<p class="ocst__empty">—</p>'; } );
	}

	drawPickers();
	load( 'd30', '', '' );
}() );
