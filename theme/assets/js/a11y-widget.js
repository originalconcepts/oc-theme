/**
 * The theme's own accessibility widget.
 *
 * A floating button opens a panel of adjustments; each one is a class on
 * <html> (the stylesheet says what it does) and the visitor's choice is kept
 * in this browser, so it follows them from page to page. Two of them need a
 * script: the text size, which scales every element from the size it really
 * had, and "stop the movement", which presses every pause button the page's
 * pause kit (window.ocPause) put on its sliders, strips and videos.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-oc-a11yw]' );

	if ( ! root ) {
		return;
	}

	var KEY = 'ocA11y';
	var html = document.documentElement;
	var FLAGS = [ 'contrast', 'mono', 'links', 'font', 'still', 'cursor' ];
	var SIZES = [ 1, 1.1, 1.2, 1.35, 1.5 ];
	var btn = root.querySelector( '.oc-fl--a11y' );
	var panel = root.querySelector( '.oc-a11yw__panel' );
	var out = root.querySelector( '[data-a11y-out]' );
	var st = {};

	try {
		st = JSON.parse( window.localStorage.getItem( KEY ) || '{}' ) || {};
	} catch ( e ) {
		st = {};
	}

	function save() {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( st ) );
		} catch ( e ) {}
	}

	/* ---------- text size ----------
	 * Most of the theme sets its type in pixels, so a root font size would
	 * move little. Each element is given its own size times the factor,
	 * measured once from what it was before anything was scaled. */

	var watcher = null;

	function base( el ) {
		if ( undefined === el.ocA11yFs ) {
			var cs = getComputedStyle( el );

			el.ocA11yFs = parseFloat( cs.fontSize ) || 0;
			el.ocA11yLh = /px$/.test( cs.lineHeight ) ? parseFloat( cs.lineHeight ) : 0;
			el.ocA11yInline = [ el.style.fontSize, el.style.lineHeight ];
		}
	}

	function skip( el ) {
		return el.closest( '[data-oc-a11yw]' ) || /^(SCRIPT|STYLE|SVG|PATH|NOSCRIPT|BR|IMG|VIDEO|IFRAME)$/i.test( el.tagName );
	}

	function scale( list, f ) {
		var els = [].filter.call( list, function ( el ) {
			return ! skip( el );
		} );

		// Every size is read before any is written, or a child would be
		// measured after its parent grew.
		els.forEach( base );
		els.forEach( function ( el ) {
			if ( 1 === f ) {
				el.style.fontSize = el.ocA11yInline[ 0 ];
				el.style.lineHeight = el.ocA11yInline[ 1 ];
				return;
			}

			if ( el.ocA11yFs ) {
				el.style.fontSize = ( el.ocA11yFs * f ).toFixed( 2 ) + 'px';
			}

			if ( el.ocA11yLh ) {
				el.style.lineHeight = ( el.ocA11yLh * f ).toFixed( 2 ) + 'px';
			}
		} );
	}

	function size( level ) {
		var f = SIZES[ level ] || 1;

		scale( document.body.querySelectorAll( '*' ), f );

		if ( watcher ) {
			watcher.disconnect();
			watcher = null;
		}

		// What arrives later — the next page of the catalogue, a drawer's
		// contents — comes in at the same size.
		if ( 1 !== f && window.MutationObserver ) {
			watcher = new MutationObserver( function ( list ) {
				var added = [];

				list.forEach( function ( m ) {
					[].forEach.call( m.addedNodes, function ( n ) {
						if ( 1 === n.nodeType ) {
							added.push( n );
							[].push.apply( added, n.querySelectorAll( '*' ) );
						}
					} );
				} );

				if ( added.length ) {
					scale( added, f );
				}
			} );
			watcher.observe( document.body, { childList: true, subtree: true } );
		}
	}

	/* ---------- high contrast ----------
	 * What had a background of its own keeps one (black); the rest goes
	 * see-through. Measured with the switch off — under it every
	 * background reads as none — and switched back on in the same moment,
	 * so nothing paints in between. */

	var bgWatch = null;
	var bgTimer = 0;
	var bgQueue = [];

	function markBg( list ) {
		var on = html.classList.contains( 'oc-a11y-contrast' );

		html.classList.remove( 'oc-a11y-contrast' );

		[].forEach.call( list, function ( el ) {
			if ( 1 !== el.nodeType || el.closest( '[data-oc-a11yw]' ) ) {
				return;
			}

			var cs = getComputedStyle( el );
			var has = 'none' !== cs.backgroundImage || ! /^(transparent|rgba\(0, 0, 0, 0\))$/.test( cs.backgroundColor );

			if ( has ) {
				el.setAttribute( 'data-oc-a11y-bg', '' );
			} else {
				el.removeAttribute( 'data-oc-a11y-bg' );
			}
		} );

		if ( on ) {
			html.classList.add( 'oc-a11y-contrast' );
		}
	}

	function contrast( on ) {
		if ( bgWatch ) {
			bgWatch.disconnect();
			bgWatch = null;
		}

		if ( ! on ) {
			return;
		}

		markBg( document.body.querySelectorAll( '*' ) );

		// A drawer's contents, the next page of the catalogue: measured in
		// batches as they arrive.
		if ( window.MutationObserver ) {
			bgWatch = new MutationObserver( function ( list ) {
				list.forEach( function ( m ) {
					[].forEach.call( m.addedNodes, function ( n ) {
						if ( 1 === n.nodeType ) {
							bgQueue.push( n );
							[].push.apply( bgQueue, n.querySelectorAll( '*' ) );
						}
					} );
				} );

				clearTimeout( bgTimer );
				bgTimer = setTimeout( function () {
					var batch = bgQueue;

					bgQueue = [];
					markBg( batch );
				}, 150 );
			} );
			bgWatch.observe( document.body, { childList: true, subtree: true } );
		}
	}

	/* ---------- stop the movement ---------- */

	var pressed = [];
	var stillWatch = null;

	function pressAll() {
		document.querySelectorAll( '.ocpp:not(.is-paused)' ).forEach( function ( b ) {
			if ( b.closest( '[data-oc-a11yw]' ) ) {
				return;
			}

			b.click();
			pressed.push( b );
		} );
	}

	function still( on ) {
		if ( on ) {
			pressAll();

			// Videos without a button of their own (the kit leaves alone
			// one that has controls) are paused too.
			document.querySelectorAll( 'video' ).forEach( function ( v ) {
				if ( ! v.paused && ! v.controls ) {
					v.pause();
				}
			} );

			// A pause button written in later is pressed as it arrives.
			if ( ! stillWatch && window.MutationObserver ) {
				stillWatch = new MutationObserver( pressAll );
				stillWatch.observe( document.body, { childList: true, subtree: true } );
			}

			return;
		}

		if ( stillWatch ) {
			stillWatch.disconnect();
			stillWatch = null;
		}

		pressed.forEach( function ( b ) {
			if ( b.isConnected && b.classList.contains( 'is-paused' ) ) {
				b.click();
			}
		} );
		pressed = [];
	}

	/* ---------- the panel ---------- */

	function paint() {
		FLAGS.forEach( function ( f ) {
			html.classList.toggle( 'oc-a11y-' + f, !! st[ f ] );

			var b = root.querySelector( '[data-a11y="' + f + '"]' );

			if ( b ) {
				b.setAttribute( 'aria-pressed', st[ f ] ? 'true' : 'false' );
			}
		} );

		var level = st.size || 0;

		if ( out ) {
			out.textContent = Math.round( SIZES[ level ] * 100 ) + '%';
		}

		root.querySelectorAll( '[data-a11y-size]' ).forEach( function ( b ) {
			var step = Number( b.getAttribute( 'data-a11y-size' ) );

			b.disabled = step < 0 ? level <= 0 : level >= SIZES.length - 1;
		} );
	}

	function open( yes ) {
		panel.hidden = ! yes;
		btn.setAttribute( 'aria-expanded', yes ? 'true' : 'false' );

		if ( yes ) {
			var first = panel.querySelector( 'button' );

			if ( first ) {
				first.focus();
			}
		}
	}

	btn.addEventListener( 'click', function () {
		open( panel.hidden );
	} );

	root.querySelector( '.oc-a11yw__x' ).addEventListener( 'click', function () {
		open( false );
		btn.focus();
	} );

	panel.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			open( false );
			btn.focus();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! panel.hidden && ! root.contains( e.target ) ) {
			open( false );
		}
	} );

	root.querySelectorAll( '[data-a11y]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var f = b.getAttribute( 'data-a11y' );

			st[ f ] = ! st[ f ];
			save();

			if ( 'contrast' === f ) {
				contrast( st[ f ] );
			}

			paint();

			if ( 'still' === f ) {
				still( st[ f ] );
			}
		} );
	} );

	root.querySelectorAll( '[data-a11y-size]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var level = Math.max( 0, Math.min( SIZES.length - 1, ( st.size || 0 ) + Number( b.getAttribute( 'data-a11y-size' ) ) ) );

			st.size = level;
			save();
			paint();
			size( level );
		} );
	} );

	root.querySelector( '[data-a11y-reset]' ).addEventListener( 'click', function () {
		var had = st;

		st = {};
		save();
		paint();

		if ( had.size ) {
			size( 0 );
		}

		if ( had.still ) {
			still( false );
		}

		if ( had.contrast ) {
			contrast( false );
		}
	} );

	paint();

	// What the visitor chose before is in force from the start. The pause
	// buttons are written by deferred scripts, which have all run by the
	// time the document is ready.
	function restore() {
		if ( st.contrast ) {
			contrast( true );
		}

		if ( st.size ) {
			size( st.size );
		}

		if ( st.still ) {
			still( true );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', restore );
	} else {
		restore();
	}
}() );
