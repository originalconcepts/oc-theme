/* How big a product is in the catalogue, said on the catalogue.
 *
 * Rides along with arranging mode: every card gets three sizes, a say in
 * whether its picture is shown whole or cropped, and — on a cropped one,
 * where there is something to choose — a pair of arrows to move it. A choice shows at once — the class and the crop are
 * the same ones the theme renders with — and is kept straight away. */
( function () {
	'use strict';

	var C = window.ocTile;

	if ( ! C || ! C.rest ) {
		return;
	}

	var T = C.i18n || {};
	var wrap = document.querySelector( 'ul.products' );

	if ( ! wrap ) {
		return;
	}

	var MOBILE = 'm' === C.dev;

	// Said here rather than on the server: a theme may already be putting a
	// class on <html>, and a second attribute is simply ignored.
	if ( C.frame ) {
		document.documentElement.classList.add( 'ocphone-frame' );
	}

	/* ---------- the phone preview ----------
	 *
	 * A media query answers to the window, not to a box inside it, so a
	 * catalogue squeezed into a narrow column still lays itself out like a
	 * desktop and would lie about what a phone shows. The page is put in a
	 * frame the width of a phone instead, where the real rules apply — and
	 * because the frame loads the same address, the dragging and these very
	 * controls are inside it, working as they always do. */
	function preview() {
		var u = new URL( location.href );

		u.searchParams.set( 'oc_sort', '1' );
		u.searchParams.set( 'oc_dev', 'm' );
		u.searchParams.set( 'oc_frame', '1' );

		var box = document.createElement( 'div' );

		box.className = 'ocphone';
		box.innerHTML = '<div class="ocphone__shell"><iframe class="ocphone__frame" src="' + u.toString().replace( /"/g, '&quot;' ) + '" title="' + ( T.phone || '' ) + '"></iframe></div>';
		document.body.appendChild( box );
		document.documentElement.classList.add( 'ocphone-on' );
	}

	function leavePreview() {
		var u = new URL( location.href );

		u.searchParams.delete( 'oc_dev' );
		location.href = u.toString();
	}

	/* ---------- which of the two sizes is being set ---------- */

	var SIZES = MOBILE
		? [
			[ '', T.same, 'M5 11h14v2H5z' ],
			[ 'plain', T.plain, 'M7 7h10v10H7z' ],
			[ 'wide', T.wide, 'M3 8h18v8H3z' ],
			[ 'big', T.big, 'M4 4h16v16H4z' ]
		]
		: [
			[ '', T.plain, 'M7 7h10v10H7z' ],
			[ 'wide', T.wide, 'M3 8h18v8H3z' ],
			[ 'big', T.big, 'M4 4h16v16H4z' ]
		];

	// Whole or cropped, said on the card. Two buttons rather than one that
	// flips, so the strip reads the same way as the sizes beside it: the one
	// that is true now is the one that is marked.
	var FITS = [
		[ 'contain', T.whole, 'M21 3H3v18h18V3zm-2 16H5V5h14v14zM7.5 7.5h9v9h-9z' ],
		[ 'cover', T.crop, 'M17 15h2V7c0-1.1-.9-2-2-2H9v2h8v8zM7 17V1H5v4H1v2h4v10c0 1.1.9 2 2 2h10v4h2v-4h4v-2H7z' ]
	];

	function idOf( el ) {
		var m = /post-(\d+)/.exec( el.className || '' );

		return m ? Number( m[ 1 ] ) : 0;
	}

	function sizeOf( el ) {
		if ( MOBILE ) {
			if ( el.classList.contains( 'oc-tile--m-big' ) ) {
				return 'big';
			}

			if ( el.classList.contains( 'oc-tile--m-wide' ) ) {
				return 'wide';
			}

			return el.classList.contains( 'oc-tile--m-plain' ) ? 'plain' : '';
		}

		if ( el.classList.contains( 'oc-tile--big' ) ) {
			return 'big';
		}

		return el.classList.contains( 'oc-tile--wide' ) ? 'wide' : '';
	}

	function media( el ) {
		return el.querySelector( '.oc-card-media' );
	}

	function focusOf( el ) {
		var m = media( el );
		var v = m ? ( m.style.getPropertyValue( '--oc-card-focus' ) || '' ).trim() : '';

		return v ? parseInt( v, 10 ) : 50;
	}

	// How this card's picture sits right now: what the product was told, or
	// failing that the verdict the theme reached about the picture itself,
	// which travels on the <img> as a class. A picture nobody has looked at
	// falls to the frame's own rule, which crops.
	function fitOf( el ) {
		if ( el.classList.contains( 'oc-tile--fit-contain' ) ) {
			return 'contain';
		}

		if ( el.classList.contains( 'oc-tile--fit-cover' ) ) {
			return 'cover';
		}

		var img = el.querySelector( '.oc-card-media img' );

		return img && img.classList.contains( 'oc-fit--contain' ) ? 'contain' : 'cover';
	}

	function setFit( el, fit ) {
		el.classList.remove( 'oc-tile--fit-contain', 'oc-tile--fit-cover' );
		el.classList.add( 'oc-tile--fit-' + fit );

		mark( el );
		keep( el, { fit: fit } );
	}

	function say( text, kind ) {
		var box = document.querySelector( '.ocsort__said' );

		if ( ! box ) {
			return;
		}

		box.textContent = text || '';
		box.className = 'ocsort__said' + ( kind ? ' is-' + kind : '' );

		if ( 'saved' === kind ) {
			setTimeout( function () {
				if ( box.textContent === text ) {
					box.textContent = '';
					box.className = 'ocsort__said';
				}
			}, 1600 );
		}
	}

	var waiting = {};

	function keep( el, what ) {
		var id = idOf( el );

		if ( ! id ) {
			return;
		}

		waiting[ id ] = Object.assign( waiting[ id ] || {}, what );
		clearTimeout( keep.timer );

		keep.timer = setTimeout( function () {
			var all = waiting;

			waiting = {};

			Object.keys( all ).forEach( function ( one ) {
				var body = Object.assign( { id: Number( one ) }, all[ one ] );

				fetch( C.rest, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( body )
				} ).then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( r.status );
					}

					say( T.saved, 'saved' );
				} ).catch( function () {
					say( T.failed, 'bad' );
				} );
			} );
		}, 400 );
	}

	/* ---------- the strip on a card ---------- */

	function icon( path ) {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + path + '"/></svg>';
	}

	function mark( el ) {
		var now = sizeOf( el );

		Array.prototype.forEach.call( el.querySelectorAll( '.octile__size' ), function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-size' ) === now ? 'true' : 'false' );
		} );

		var fit = fitOf( el );

		Array.prototype.forEach.call( el.querySelectorAll( '.octile__fit' ), function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-fit' ) === fit ? 'true' : 'false' );
		} );

		// A whole picture has no overflow, so there is no half to keep and
		// nothing for an arrow to do. Offering them there was the control
		// saying it works when it does not: they belong to a crop.
		var moves = 'cover' === fit;

		Array.prototype.forEach.call( el.querySelectorAll( '.octile__move, .octile__at, .octile__gap--end' ), function ( b ) {
			b.hidden = ! moves;
		} );

		var out = el.querySelector( '.octile__at' );

		if ( out ) {
			out.textContent = focusOf( el ) + '%';
		}
	}

	function setSize( el, size ) {
		if ( MOBILE ) {
			el.classList.remove( 'oc-tile--m-plain', 'oc-tile--m-wide', 'oc-tile--m-big' );

			if ( size ) {
				el.classList.add( 'oc-tile--m-' + size );
			}

			mark( el );
			keep( el, { size_m: size } );

			return;
		}

		el.classList.remove( 'oc-tile--wide', 'oc-tile--big' );

		if ( size ) {
			el.classList.add( 'oc-tile--' + size );
		}

		mark( el );
		keep( el, { size: size } );
	}

	function nudge( el, by ) {
		var now = Math.max( 0, Math.min( 100, focusOf( el ) + by ) );
		var m = media( el );

		if ( m ) {
			m.style.setProperty( '--oc-card-focus', now + '%' );
		}

		mark( el );
		keep( el, { focus: now } );
	}

	function strip( el ) {
		var box = document.createElement( 'div' );

		box.className = 'octile';
		box.setAttribute( 'data-oc-keep', '' );
		box.setAttribute( 'role', 'group' );
		box.setAttribute( 'aria-label', T.size || '' );

		var html = '';

		SIZES.forEach( function ( s ) {
			html += '<button type="button" class="octile__size" data-size="' + s[ 0 ] + '" title="' + s[ 1 ] + '" aria-label="' + s[ 1 ] + '" aria-pressed="false">' + icon( s[ 2 ] ) + '</button>';
		} );

		html += '<span class="octile__gap"></span>';

		FITS.forEach( function ( f ) {
			html += '<button type="button" class="octile__fit" data-fit="' + f[ 0 ] + '" title="' + f[ 1 ] + '" aria-label="' + f[ 1 ] + '" aria-pressed="false">' + icon( f[ 2 ] ) + '</button>';
		} );

		html += '<span class="octile__gap octile__gap--end"></span>';
		html += '<button type="button" class="octile__move" data-by="-10" title="' + T.up + '" aria-label="' + T.up + '">' + icon( 'M12 5l7 8h-4v6h-6v-6H5z' ) + '</button>';
		html += '<button type="button" class="octile__at" title="' + T.middle + '">50%</button>';
		html += '<button type="button" class="octile__move" data-by="10" title="' + T.down + '" aria-label="' + T.down + '">' + icon( 'M12 19l-7-8h4V5h6v6h4z' ) + '</button>';

		box.innerHTML = html;

		box.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );

			if ( ! b ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			if ( b.classList.contains( 'octile__size' ) ) {
				setSize( el, b.getAttribute( 'data-size' ) );
			} else if ( b.classList.contains( 'octile__fit' ) ) {
				setFit( el, b.getAttribute( 'data-fit' ) );
			} else if ( b.classList.contains( 'octile__move' ) ) {
				nudge( el, Number( b.getAttribute( 'data-by' ) ) );
			} else {
				var m = media( el );

				if ( m ) {
					m.style.setProperty( '--oc-card-focus', '50%' );
				}

				mark( el );
				keep( el, { focus: 50 } );
			}
		} );

		el.appendChild( box );
		mark( el );
	}

	function deviceSwitch() {
		var bar = document.querySelector( '.ocsort__bar' );

		if ( ! bar ) {
			return;
		}

		var b = document.createElement( 'button' );

		b.type = 'button';
		b.className = 'ocsort__dev';
		b.textContent = MOBILE ? T.leaveP : T.phone;
		b.addEventListener( 'click', MOBILE ? leavePreview : function () {
			var u = new URL( location.href );

			u.searchParams.set( 'oc_dev', 'm' );
			location.href = u.toString();
		} );

		bar.insertBefore( b, bar.querySelector( '.ocsort__done' ) );
	}

	// The page holding the frame draws no catalogue of its own.
	if ( MOBILE && ! C.frame ) {
		preview();
		deviceSwitch();

		return;
	}

	Array.prototype.forEach.call( wrap.querySelectorAll( 'li.product' ), function ( el ) {
		if ( idOf( el ) ) {
			strip( el );
		}
	} );

	wrap.classList.add( 'octile-on' );

	if ( ! C.frame ) {
		deviceSwitch();
	}
}() );
