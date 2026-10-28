/**
 * OC Blocks — front behaviours.
 *
 * One small file for every block: the hero slider, shelf sliders, the
 * entrance engine, the marquee and the parallax. Vanilla, no dependencies,
 * written once and shared — a page of ten sections costs the same as one.
 */
( function () {
	'use strict';

	var reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	window.ocPauseL = window.ocPauseL || ( window.OCB || {} ).pause;

	/* ---------- pause and play: anything that moves by itself ----------
	 * Whatever moves, turns or plays on its own for more than a few seconds
	 * gets a small button that stops it and starts it again (WCAG 2.2.2,
	 * "Pause, Stop, Hide"). The theme and OC Blocks each carry this same kit,
	 * since OC Blocks also runs under other themes; whichever loads first
	 * defines it and the other uses that one. */
	window.ocPause = window.ocPause || ( function () {
		var ICON = '<svg class="ocpp__p" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>'
			+ '<svg class="ocpp__g" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.2v13.6L18.6 12z"/></svg>';
		var watching = false;

		function words() {
			var l = window.ocPauseL || {};

			return {
				mp: l.mp || 'Stop the movement',
				mg: l.mg || 'Resume the movement',
				vp: l.vp || 'Pause the video',
				vg: l.vg || 'Play the video'
			};
		}

		function say( btn, paused ) {
			var w = words();
			var video = 'video' === btn.getAttribute( 'data-ocpp' );

			btn.classList.toggle( 'is-paused', paused );
			btn.setAttribute( 'aria-label', paused ? ( video ? w.vg : w.mg ) : ( video ? w.vp : w.mp ) );
		}

		// A button that calls back with true when it pauses, false when it plays.
		function button( kind, cls, onToggle ) {
			var btn = document.createElement( 'button' );

			btn.type = 'button';
			btn.className = 'ocpp' + ( cls ? ' ' + cls : '' );
			btn.setAttribute( 'data-ocpp', kind );
			btn.innerHTML = ICON;
			say( btn, false );
			btn.addEventListener( 'click', function ( e ) {
				// It often sits on a picture that opens, zooms or links.
				e.preventDefault();
				e.stopPropagation();

				var paused = ! btn.classList.contains( 'is-paused' );

				say( btn, paused );
				onToggle( paused );
			} );

			return btn;
		}

		// A paused video stays paused whatever else asks it to play — a slider
		// turning to it, a card scrolling back into view — until its button
		// plays it again.
		function hold( v ) {
			if ( ! v.ocppHeld ) {
				v.ocppHeld = 1;
				v.play = function () {
					return Promise.resolve();
				};
			}

			HTMLMediaElement.prototype.pause.call( v );
		}

		function release( v ) {
			if ( v.ocppHeld ) {
				v.ocppHeld = 0;
				delete v.play;
			}

			var p = v.play();

			if ( p && p.catch ) {
				p.catch( function () {} );
			}
		}

		// A muted loop that plays by itself. One with its own controls, one a
		// slider already answers for, or a product-page video (the gallery and
		// its lightbox carry the theme's own pause chip) is left alone.
		function video( v ) {
			if ( v.getAttribute( 'data-ocpp-on' ) || v.controls || v.closest( '.oc-vslide, .oc-voverlay' ) ) {
				return;
			}

			var hero = v.closest( '.ocb-hero' );

			if ( hero && ! hero.classList.contains( 'ocb-hero--one' ) ) {
				return;
			}

			v.setAttribute( 'data-ocpp-on', '1' );

			var host = hero || v.parentElement;
			var bg = v.closest( '.ocb__bg' );
			var link = host.closest( 'a, button' );

			// A background video lies under the section's words; the button
			// belongs to the section. Never inside a link or a button — a button
			// there is not allowed, and the click would go to the other one.
			if ( bg && bg.parentElement ) {
				host = bg.parentElement;
			} else if ( link && link.parentElement ) {
				host = link.parentElement;
			}

			// Nor inside a part of the page hidden from screen readers (a card's
			// picture strip): it would be a tab stop nobody can name.
			var mute = host.closest( '[aria-hidden="true"]' );

			if ( mute && mute.parentElement ) {
				host = mute.parentElement;
			}

			if ( 'static' === getComputedStyle( host ).position ) {
				host.style.position = 'relative';
			}

			host.appendChild( button( 'video', 'ocpp--corner' + ( 'LI' === host.tagName ? ' ocpp--top' : '' ), function ( paused ) {
				if ( paused ) {
					hold( v );
				} else {
					release( v );
				}
			} ) );
		}

		function scan( root ) {
			( root || document ).querySelectorAll( 'video[autoplay], video[loop][muted], video[data-oc-vsrc]' ).forEach( video );
		}

		// Videos written in later — a gallery slide, a page of the catalogue
		// fetched by infinite scroll — get theirs as they arrive.
		function watch() {
			if ( watching || ! window.MutationObserver ) {
				return;
			}

			watching = true;

			new MutationObserver( function ( list ) {
				list.forEach( function ( m ) {
					[].forEach.call( m.addedNodes, function ( n ) {
						if ( 1 !== n.nodeType ) {
							return;
						}

						if ( 'VIDEO' === n.tagName ) {
							if ( n.autoplay || ( n.loop && n.muted ) || n.hasAttribute( 'data-oc-vsrc' ) ) {
								video( n );
							}
						} else if ( n.querySelector && n.querySelector( 'video' ) ) {
							scan( n );
						}
					} );
				} );
			} ).observe( document.body, { childList: true, subtree: true } );
		}

		return {
			button: button,
			hold: hold,
			release: release,
			scan: scan,
			watch: watch
		};
	}() );


	/* ---------- entrances: sections step in as they arrive ---------- */

	var seen = new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( entry.isIntersecting ) {
				entry.target.classList.add( 'is-in' );
				seen.unobserve( entry.target );
			}
		} );
	}, { rootMargin: '0px 0px -8% 0px' } );

	// A section already on the screen when this runs is shown whole at
	// once (is-in + no transition) — the page painted with it and must not
	// take it away to fade it back. Only then does the stylesheet's hidden
	// starting state switch on (html.ocb-anim), in the same tick, so nothing
	// visible ever blinks; sections below step in as they arrive.
	var fold = window.innerHeight || document.documentElement.clientHeight || 0;

	document.querySelectorAll( '.ocb--in-fade, .ocb--in-rise, .ocb--in-stagger' ).forEach( function ( el ) {
		if ( reduced ) {
			el.classList.add( 'is-in' );
			return;
		}

		var box = el.getBoundingClientRect();

		if ( box.top < fold && box.bottom > 0 ) {
			el.classList.add( 'is-in', 'ocb--in-now' );
			return;
		}

		// Stagger numbers its children once, here, so the stylesheet only
		// needs one rule.
		if ( el.classList.contains( 'ocb--in-stagger' ) ) {
			var kids = el.querySelectorAll( '.ocb-cat, .ocb-brand, .ocb-post, li.product, .ocb-ico__one, .ocb-words > *' );

			kids.forEach( function ( kid, i ) {
				kid.style.setProperty( '--ocb-i', String( Math.min( i, 11 ) ) );
			} );
		}

		seen.observe( el );
	} );

	if ( ! reduced ) {
		document.documentElement.classList.add( 'ocb-anim' );
	}

	/* ---------- the hero ---------- */

	document.querySelectorAll( '.ocb-hero' ).forEach( function ( hero ) {
		var strip = hero.querySelector( '.ocb-hero__strip' );
		var slides = strip ? strip.children : [];

		if ( ! strip || slides.length < 2 ) {
			return;
		}

		var fade = hero.classList.contains( 'ocb-hero--fade' );
		var sets = hero.querySelectorAll( '[data-ocb-set]' );
		var real = slides.length;
		var at = 0;
		var timer = null;

		function count() {
			return real;
		}

		// The pictures of the slides after the first arrive only once the
		// page has loaded, so they never share the line with the first one
		// — unless the strip is asked to turn before that, when they are
		// handed over at once. The srcset goes first: an img given a src
		// and then a srcset may fetch twice.
		var woken = false;

		function wake() {
			if ( woken ) {
				return;
			}

			woken = true;
			hero.querySelectorAll( '[data-ocb-srcset], [data-ocb-src]' ).forEach( function ( el ) {
				if ( el.dataset.ocbSrcset ) {
					el.setAttribute( 'srcset', el.dataset.ocbSrcset );
					el.removeAttribute( 'data-ocb-srcset' );
				}

				if ( el.dataset.ocbSrc ) {
					el.setAttribute( 'src', el.dataset.ocbSrc );
					el.removeAttribute( 'data-ocb-src' );
				}

				// Once handed over, fetched for real: a lazy picture in a
				// strip waits for the browser's own idea of "near", which
				// a hidden tab never reaches, and the turn would show blank.
				el.removeAttribute( 'loading' );
			} );
		}

		if ( 'complete' === document.readyState ) {
			wake();
		} else {
			window.addEventListener( 'load', wake );
		}

		strip.addEventListener( 'touchstart', wake, { passive: true } );
		strip.addEventListener( 'pointerdown', wake, { passive: true } );

		// The words live outside the strip; the active slide lights its set,
		// and re-adding the class replays the little entrance.
		function paintSets() {
			sets.forEach( function ( set ) {
				set.classList.toggle( 'is-on', Number( set.dataset.ocbSet ) === at );
			} );
		}

		function paintDots() {
			var dots = hero.querySelector( '[data-ocb-dots]' );

			if ( ! dots ) {
				return;
			}

			// The row may also hold the pause button, which outlives a repaint.
			if ( dots.querySelectorAll( '.ocb-dots__dot' ).length !== count() ) {
				dots.querySelectorAll( '.ocb-dots__dot' ).forEach( function ( old ) {
					old.remove();
				} );

				for ( var i = 0; i < count(); i++ ) {
					( function ( n ) {
						var d = document.createElement( 'button' );
						d.type = 'button';
						d.className = 'ocb-dots__dot';
						d.setAttribute( 'aria-label', ( dots.getAttribute( 'data-ocb-dot-label' ) || 'Slide %d' ).replace( '%d', n + 1 ) );
						d.addEventListener( 'click', function () {
							go( n );
						} );
						dots.insertBefore( d, dots.querySelector( '.ocpp' ) );
					}( i ) );
				}
			}

			[].forEach.call( dots.children, function ( d, i ) {
				d.classList.toggle( 'is-on', i === at );
			} );
		}

		function park( slot, smooth ) {
			var rtl = getComputedStyle( strip ).direction === 'rtl';

			strip.scrollTo( { left: ( rtl ? -1 : 1 ) * slot * strip.clientWidth, behavior: smooth ? 'smooth' : 'auto' } );
		}

		function turn( wrapForward, wrapBack ) {
			if ( fade ) {
				// From the first turn on, the arriving slide settles in.
				hero.classList.add( 'is-live' );
				[].forEach.call( slides, function ( sl, i ) {
					sl.classList.toggle( 'is-on', i === at );
				} );
			} else {
				// On a wrap the strip heads for the quiet copy in the SAME
				// direction of travel; the settle watcher jumps to the real
				// slide only once it has truly landed there.
				park( wrapForward ? real + 1 : ( wrapBack ? 0 : at + 1 ), true );
			}

			paintDots();
			paintSets();
		}

		function go( n, instant ) {
			var target = ( n + count() ) % count();

			if ( target === at ) {
				return;
			}

			wake();

			// Walking off either end rides through the quiet copy waiting
			// there, so the loop always continues in the direction of travel.
			var wrapForward = ! fade && 0 === target && at === real - 1 && n > at;
			var wrapBack = ! fade && real - 1 === target && 0 === at && n < at;

			// The words take their leave first — down and away — and only
			// then does the banner itself turn.
			var leaving = hero.querySelector( '.ocb-hero__set.is-on' );

			at = target;

			if ( leaving && ! instant ) {
				leaving.classList.add( 'is-leave' );
				setTimeout( function () {
					leaving.classList.remove( 'is-leave' );
				}, 430 );
				setTimeout( function () {
					turn( wrapForward, wrapBack );
				}, 360 );
			} else {
				turn( wrapForward, wrapBack );
			}
		}

		var held = false;

		function arm() {
			var secs = Number( hero.dataset.ocbAuto || 0 );

			clearInterval( timer );

			if ( ! secs || reduced || held ) {
				return;
			}

			timer = setInterval( function () {
				go( at + 1 );
			}, secs * 1000 );
		}

		if ( fade ) {
			slides[ 0 ].classList.add( 'is-on' );

			// The strip cannot scroll in fade mode, so the finger speaks
			// through a swipe instead.
			var fx0 = null;

			strip.addEventListener( 'touchstart', function ( e ) {
				fx0 = e.touches[ 0 ].clientX;
			}, { passive: true } );

			strip.addEventListener( 'touchend', function ( e ) {
				if ( null === fx0 ) {
					return;
				}

				var fdx = e.changedTouches[ 0 ].clientX - fx0;

				fx0 = null;

				if ( Math.abs( fdx ) > 45 ) {
					go( at + ( fdx < 0 ? 1 : -1 ) );
					arm();
				}
			} );
		} else {
			// A finger's own scroll keeps the dots honest.
			var tick = false;

			// A quiet copy of the last slide stands before the first, and a
			// copy of the first stands after the last: a swipe off either
			// end continues in its own direction, and the strip snaps to
			// the real slide unseen. Every real slide therefore lives one
			// slot further in.
			var head = slides[ real - 1 ].cloneNode( true );
			var tail = slides[ 0 ].cloneNode( true );

			head.setAttribute( 'aria-hidden', 'true' );
			tail.setAttribute( 'aria-hidden', 'true' );
			// A hidden copy must not be a tab stop either.
			[ head, tail ].forEach( function ( ghost ) {
				[].concat( ghost.matches( 'a,button' ) ? [ ghost ] : [], [].slice.call( ghost.querySelectorAll( 'a,button,[tabindex]' ) ) ).forEach( function ( el ) {
					el.setAttribute( 'tabindex', '-1' );
				} );
			} );
			strip.insertBefore( head, strip.firstChild );
			strip.appendChild( tail );

			// The opening position is set by hand — browsers disagree about
			// where a right-to-left strip wakes up.
			( function start() {
				if ( strip.clientWidth > 0 ) {
					park( 1, false );
				} else {
					setTimeout( start, 200 );
				}
			}() );

			var settle = null;

			strip.addEventListener( 'scroll', function () {
				if ( ! tick ) {
				tick = true;
					requestAnimationFrame( function () {
						tick = false;
						var idx = Math.round( Math.abs( strip.scrollLeft ) / Math.max( 1, strip.clientWidth ) );
						var shown = ( idx - 1 + real ) % real;

						if ( shown !== at ) {
							at = shown;
							paintDots();
							paintSets();
							arm();
						}
					} );
				}

				// Once the finger lets go and the snap settles on the copy,
				// jump home without a trace.
				clearTimeout( settle );
				settle = setTimeout( snapHome, 160 );
			}, { passive: true } );

			function snapHome() {
				var pos = Math.abs( strip.scrollLeft );
				var width = Math.max( 1, strip.clientWidth );
				var idx = Math.round( pos / width );
				var onTail = idx >= real + 1;
				var onHead = 0 === idx;

				if ( ! onTail && ! onHead ) {
					return;
				}

				// Wait for the strip to actually park on the copy — jumping
				// mid-flight leaves it stranded between slides. Browsers
				// mid-momentum also quietly drop the jump, so ask again
				// until it truly lands.
				var slot = onTail ? real + 1 : 0;

				if ( Math.abs( pos - slot * width ) > 4 ) {
					clearTimeout( settle );
					settle = setTimeout( snapHome, 90 );
					return;
				}

				park( onTail ? 1 : real, false );
				at = onTail ? 0 : real - 1;
				paintDots();
				paintSets();
				clearTimeout( settle );
				settle = setTimeout( snapHome, 90 );
			}

			if ( 'onscrollend' in strip ) {
				strip.addEventListener( 'scrollend', snapHome );
			}
		}

		hero.addEventListener( 'click', function ( e ) {
			var arr = e.target.closest( '[data-ocb-go]' );

			if ( arr ) {
				go( at + Number( arr.dataset.ocbGo ) );
				arm();
			}
		} );

		paintDots();
		arm();

		// A banner that turns by itself, or plays a video, can be stopped:
		// one button for the turning and every video in it.
		if ( ( Number( hero.dataset.ocbAuto || 0 ) && ! reduced ) || hero.querySelector( 'video' ) ) {
			var heroDots = hero.querySelector( '[data-ocb-dots]' );

			// Beside the dots when there are dots — a corner of the banner
			// is where floating buttons stand.
			( heroDots || hero ).appendChild( window.ocPause.button( 'motion', heroDots ? 'ocb-dots__pp' : 'ocpp--corner', function ( paused ) {
				held = paused;
				arm();
				hero.querySelectorAll( 'video' ).forEach( paused ? window.ocPause.hold : window.ocPause.release );
			} ) );
		}

		hero.addEventListener( 'mouseenter', function () {
			clearInterval( timer );
		} );
		hero.addEventListener( 'mouseleave', arm );
	} );

	/* ---------- shelf sliders: products, categories, brands ---------- */

	function initShelf( shelf ) {
		var row = shelf.querySelector( '.ocb-cats__row, .ocb-brands__row, .ocb-posts--slider, ul.products' );

		if ( ! row ) {
			return;
		}

		// One card and the gap after it, both measured — the gap is a
		// setting (density, small, tight), and a guessed constant drifted
		// the strip a few pixels per click until a card sat half cut.
		function pitch() {
			var item = row.querySelector( ':scope > *' );
			var gap = parseFloat( getComputedStyle( row ).columnGap ) || 0;

			return item ? item.getBoundingClientRect().width + gap : row.clientWidth;
		}

		// A click turns a whole page: as many cards as are fully in view,
		// landing on a card edge so the first one is never a sliver.
		shelf.addEventListener( 'click', function ( e ) {
			var arr = e.target.closest( '[data-ocb-go]' );

			if ( ! arr ) {
				return;
			}

			var cs = getComputedStyle( row );
			var rtl = 'rtl' === cs.direction;
			var p = pitch();
			var gap = parseFloat( cs.columnGap ) || 0;
			var page = Math.max( 1, Math.floor( ( row.clientWidth + gap + 1 ) / p ) );
			var max = row.scrollWidth - row.clientWidth;
			// Distance travelled from the start edge, whichever side that is.
			var gone = Math.abs( row.scrollLeft );
			// The arrows are named by screen side; in RTL the left one advances.
			var dir = Number( arr.dataset.ocbGo ) * ( rtl ? -1 : 1 );
			var to = Math.round( gone / p ) * p + page * p * dir;

			to = Math.min( max, Math.max( 0, to ) );

			// In RTL the browser counts scrollLeft in negatives.
			row.scrollTo( { left: rtl ? -to : to, behavior: 'smooth' } );
		} );

		// Arrows grey out at the ends.
		function truth() {
			var max = row.scrollWidth - row.clientWidth;
			var gone = Math.abs( row.scrollLeft );
			var rtl = getComputedStyle( row ).direction === 'rtl';
			var atStart = gone < 2;
			var atEnd = gone > max - 2;
			var prev = shelf.querySelector( '.ocb-arr--prev' );
			var next = shelf.querySelector( '.ocb-arr--next' );

			if ( prev ) {
				prev.classList.toggle( 'is-off', rtl ? atEnd : atStart );
			}
			if ( next ) {
				next.classList.toggle( 'is-off', rtl ? atStart : atEnd );
			}

			// Nothing to scroll at all: both arrows rest.
			shelf.classList.toggle( 'ocb--still', max < 4 );
		}

		row.addEventListener( 'scroll', function () {
			requestAnimationFrame( truth );
		}, { passive: true } );

		// The arrows sit on the middle of the pictures, not of the whole
		// card — the words under a product would drag them too low.
		function midline() {
			var img = row.querySelector( 'img' );

			if ( ! img ) {
				return;
			}

			var box = shelf.getBoundingClientRect();
			var pic = img.getBoundingClientRect();
			var top = pic.top - box.top + pic.height / 2;

			// A picture that spills out of its cell would put the middle
			// somewhere below the row entirely, and the arrows with it.
			// Anything outside the strip is not a midline at all.
			if ( top <= 20 || top >= box.height ) {
				shelf.style.removeProperty( '--ocb-arr-mid' );

				return;
			}

			shelf.style.setProperty( '--ocb-arr-mid', top.toFixed( 0 ) + 'px' );
		}

		window.addEventListener( 'resize', midline, { passive: true } );

		truth();
		midline();
		setTimeout( function () {
			truth();
			midline();
		}, 600 );
	}

	document.querySelectorAll( '[data-ocb-shelf]' ).forEach( initShelf );

	// Expose so a shelf injected after load (recently-viewed) can be wired up.
	window.OCB = window.OCB || {};
	window.OCB.initShelf = initShelf;

	/* ---------- deferred shelves: filled in after load (cache-safe) ---------- */

	function fillShelf( box ) {
		var cfg;

		if ( box.__ocbFilling ) {
			return;
		}

		box.__ocbFilling = true;

		try {
			cfg = JSON.parse( box.getAttribute( 'data-oc-shelf' ) );
		} catch ( e ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'oc_shelf' );
		Object.keys( cfg ).forEach( function ( k ) {
			body.append( k, cfg[ k ] );
		} );

		var url = ( window.OCB && OCB.ajax ) || '/wp-admin/admin-ajax.php';

		fetch( url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( html ) {
				if ( ! html || ! html.trim() ) {
					// nothing to show (e.g. no viewed products): drop the row.
					if ( box.closest( 'li.oc-block--slider' ) ) {
						box.closest( 'li.oc-block--slider' ).remove();
					}
					return;
				}

				box.innerHTML = html;
				box.removeAttribute( 'data-oc-shelf' );
				box.querySelectorAll( '[data-ocb-shelf]' ).forEach( initShelf );
				box.classList.add( 'is-in' );
			} )
			.catch( function () {} );
	}

	document.querySelectorAll( '[data-oc-shelf]' ).forEach( fillShelf );

	// A catalogue page the theme pulls in while paging brings its blocks
	// along; their deferred shelves are filled the same way.
	window.OCB.fill = function ( root ) {
		( root || document ).querySelectorAll( '[data-oc-shelf]' ).forEach( fillShelf );
	};

	/* ---------- the marquee ---------- */

	document.querySelectorAll( '[data-ocb-mq]' ).forEach( function ( mq ) {
		var track = mq.querySelector( '.ocb-mq__track' );

		if ( ! track ) {
			return;
		}

		// The loop must be at least two viewport-widths long, or a gap
		// slides through; clone until it is.
		var guard = 0;

		while ( track.scrollWidth < mq.clientWidth * 2 && guard < 10 ) {
			track.innerHTML += track.innerHTML;
			guard++;
		}

		if ( reduced ) {
			track.style.animation = 'none';

			return;
		}

		// The strip never stops by itself, so it can be stopped. The button
		// lives in the section: a tilted strip is wider than the screen, and
		// its own corner is off it.
		( mq.closest( '.ocb' ) || mq ).appendChild( window.ocPause.button( 'motion', 'ocpp--corner', function ( paused ) {
			mq.classList.toggle( 'is-paused', paused );
		} ) );
	} );

	/* ---------- shop the look ---------- */

	document.querySelectorAll( '[data-ocb-look]' ).forEach( function ( look ) {
		var spots = [].slice.call( look.querySelectorAll( '[data-ocb-spot]' ) );
		var cards = [].slice.call( look.querySelectorAll( '[data-ocb-card]' ) );
		var scenes = [].slice.call( look.querySelectorAll( '[data-ocb-lscene]' ) );
		var count = look.querySelector( '.ocb-look__count b' );
		var side = look.querySelector( '.ocb-look__side' );
		var cardsWrap = look.querySelector( '.ocb-look__cards' );
		var at = 0;

		if ( ! cards.length ) {
			return;
		}

		function mobile() {
			return window.matchMedia( '(max-width: 782px)' ).matches;
		}

		// A horizontal scroll by a geometric delta, clamped to the strip's
		// real extent — asking for more than the edge (the LAST item aligned
		// to the start) leaves an over-scrolled gap that snaps back at the
		// first touch. Direction-proof: the clamp range covers both the
		// negative-scrollLeft (RTL) and positive conventions.
		function hscroll( el, delta, smooth ) {
			var max = el.scrollWidth - el.clientWidth;

			if ( max <= 0 ) {
				return;
			}

			var target = Math.max( Math.min( 0, -max ), Math.min( Math.max( 0, max ), el.scrollLeft + delta ) );

			el.scrollTo( { left: target, behavior: smooth ? 'smooth' : 'auto' } );
		}

		// The story view brings the shown product to the strip's start. A
		// horizontal scroll only — scrollIntoView also scrolls vertical
		// ancestors (the freshly opened take-over lurched down from it).
		function centreCard( smooth ) {
			if ( ! mobile() || ! look.classList.contains( 'is-open' ) || ! cardsWrap || ! cards[ at ] ) {
				return;
			}

			var r = cards[ at ].getBoundingClientRect();
			var wr = cardsWrap.getBoundingClientRect();

			hscroll( cardsWrap, r.right - ( wr.right - 12 ), smooth );
		}

		// The closed slider parks on the room being looked at.
		function alignScene() {
			var pic = look.querySelector( '.ocb-look__pic' );

			if ( ! pic || ! mobile() || look.classList.contains( 'is-open' ) ) {
				return;
			}

			var s = Number( cards[ at ].dataset.ocbScene || 0 );
			var el = null;

			scenes.forEach( function ( sc ) {
				if ( Number( sc.dataset.ocbLscene ) === s ) {
					el = sc;
				}
			} );

			if ( el ) {
				hscroll( pic, el.getBoundingClientRect().right - pic.getBoundingClientRect().right, false );
			}
		}

		// Going full-screen drops the block from the flow — a placeholder
		// holds its space, so the page never jumps open or shut.
		var ph = null;

		var savedY = null;

		function openLook() {
			if ( ! ph ) {
				ph = document.createElement( 'div' );
				ph.style.height = look.offsetHeight + 'px';
				look.parentNode.insertBefore( ph, look );
			}

			// Remember where the page stood — the scroll lock (iOS above all)
			// may quietly move it, and closing must land exactly back.
			savedY = window.scrollY;

			look.classList.remove( 'is-closing' );
			look.classList.add( 'is-open' );
			document.documentElement.classList.add( 'oc-look-lock' );
			requestAnimationFrame( function () {
				centreCard( false );
			} );
		}

		function closeLook() {
			if ( ! look.classList.contains( 'is-open' ) ) {
				return;
			}

			// The smooth close: a static GHOST of the open view fades out on
			// top, while underneath the real block returns to the page in the
			// same breath — spot, scroll and all. When the ghost clears, the
			// page was already standing exactly as before the open; nothing
			// re-renders in sight. (The old way kept the block itself as the
			// fading overlay, which left a white hole in the page until the
			// animation ended — the "blank page, then the block jumps back".)
			var ghost = look.cloneNode( true );

			ghost.classList.add( 'ocb-look--ghost' );
			ghost.setAttribute( 'aria-hidden', 'true' );
			ghost.removeAttribute( 'data-ocb-look' );
			document.body.appendChild( ghost );

			look.classList.remove( 'is-open' );
			document.documentElement.classList.remove( 'oc-look-lock' );

			if ( null !== savedY ) {
				window.scrollTo( 0, savedY );
				savedY = null;
			}

			if ( ph ) {
				ph.remove();
				ph = null;
			}

			alignScene();

			requestAnimationFrame( function () {
				ghost.classList.add( 'is-fading' );
			} );
			setTimeout( function () {
				ghost.remove();
			}, 340 );
		}

		// The cards (by index) that share a scene.
		function sceneList( scene ) {
			var list = [];

			cards.forEach( function ( c, i ) {
				if ( Number( c.dataset.ocbScene || 0 ) === scene ) {
					list.push( i );
				}
			} );

			return list;
		}

		function show( n ) {
			at = ( n + cards.length ) % cards.length;

			var scene = Number( cards[ at ].dataset.ocbScene || 0 );

			spots.forEach( function ( sp, i ) {
				sp.classList.toggle( 'is-on', i === at );
			} );
			cards.forEach( function ( c, i ) {
				c.classList.toggle( 'is-on', i === at );
				// The story strip shows only this room's products.
				c.classList.toggle( 'in-scene', Number( c.dataset.ocbScene || 0 ) === scene );
			} );
			scenes.forEach( function ( sc ) {
				sc.classList.toggle( 'is-on', Number( sc.dataset.ocbLscene ) === scene );
			} );

			// A lone product owns the strip's full width; several share it.
			if ( cardsWrap ) {
				cardsWrap.classList.toggle( 'is-multi', sceneList( scene ).length > 1 );
			}

			// The count speaks for this room alone: position / its products.
			if ( count ) {
				var list = sceneList( scene );

				count.textContent = String( list.indexOf( at ) + 1 );

				var wrap = count.parentNode;

				if ( wrap && wrap.lastChild && 3 === wrap.lastChild.nodeType ) {
					wrap.lastChild.textContent = ' / ' + list.length;
				}
			}
		}

		// The product arrows walk the current room's products only.
		function prodStep( dir ) {
			var list = sceneList( Number( cards[ at ].dataset.ocbScene || 0 ) );

			if ( list.length < 2 ) {
				return;
			}

			show( list[ ( list.indexOf( at ) + dir + list.length ) % list.length ] );
		}

		// Walking the rooms: the arrow on the picture jumps to the next
		// room's first product.
		function room( dir ) {
			var current = Number( cards[ at ].dataset.ocbScene || 0 );
			var total = scenes.length || 1;
			var next = ( current + dir + total ) % total;

			for ( var i = 0; i < cards.length; i++ ) {
				if ( Number( cards[ i ].dataset.ocbScene || 0 ) === next ) {
					show( i );
					return;
				}
			}
		}

		look.addEventListener( 'click', function ( e ) {
			// A colour swatch on the card: in the story it opens the quick
			// pick for that colour's product; on the desktop it swaps the
			// card's picture (the catalogue's own handler needs a catalogue
			// card and stands down here).
			var swatch = e.target.closest( '.ocb-look__card .oc-colors__item' );

			if ( swatch ) {
				e.preventDefault();

				var pid = swatch.dataset.pid || '';
				var card = swatch.closest( '.ocb-look__card' );

				// The chosen colour is marked on the card either way, so the
				// add button opens the panel with it already selected.
				card.querySelectorAll( '.oc-colors__item' ).forEach( function ( s ) {
					s.classList.toggle( 'is-current', s === swatch );
					s.removeAttribute( 'aria-current' );
				} );
				swatch.setAttribute( 'aria-current', 'true' );

				if ( mobile() && look.classList.contains( 'is-open' ) ) {
					if ( window.__ocQuickPick && pid ) {
						window.__ocQuickPick( pid, swatch.dataset.slug || '' );
					}

					return;
				}

				var img = card.querySelector( '.ocb-look__cimg img' );
				var imgs = [];

				try {
					imgs = JSON.parse( swatch.dataset.imgs || '[]' );
				} catch ( err ) {
					imgs = [];
				}

				if ( img && imgs[ 0 ] ) {
					img.src = imgs[ 0 ];
					img.removeAttribute( 'srcset' );
				}

				// A colour-sibling is its own product — the add button must
				// add (or open) THAT one.
				var cadd = card.querySelector( '.ocb-look__cadd' );

				if ( cadd && pid ) {
					cadd.setAttribute( 'data-product_id', pid );

					if ( cadd.classList.contains( 'ajax_add_to_cart' ) ) {
						cadd.setAttribute( 'href', '?add-to-cart=' + pid );
					}
				}

				// The card's links follow the chosen colour through.
				if ( swatch.dataset.url ) {
					[ '.ocb-look__cimg', '.ocb-look__cname a', '.ocb-look__cgo' ].forEach( function ( sel ) {
						var a = card.querySelector( sel );

						if ( a ) {
							a.href = swatch.dataset.url;
						}
					} );
				}

				return;
			}

			var snav = e.target.closest( '[data-ocb-scene-go]' );

			if ( snav ) {
				room( Number( snav.dataset.ocbSceneGo ) );

				// In the story view the strip now holds the new room's
				// products — start it at the first one.
				if ( mobile() && look.classList.contains( 'is-open' ) ) {
					requestAnimationFrame( function () {
						centreCard( false );
					} );
				}

				return;
			}

			var spot = e.target.closest( '[data-ocb-spot]' );

			if ( spot ) {
				show( Number( spot.dataset.ocbSpot ) );

				// On a phone the spot also opens the story view. Already open,
				// the strip GLIDES to the product — no re-open, no jump.
				if ( mobile() ) {
					if ( look.classList.contains( 'is-open' ) ) {
						centreCard( true );
					} else {
						openLook();
						requestAnimationFrame( function () {
							centreCard( false );
						} );
					}
				}

				return;
			}

			if ( e.target.closest( '[data-ocb-look-open]' ) ) {
				openLook();
				return;
			}

			if ( e.target.closest( '[data-ocb-look-close]' ) ) {
				closeLook();
				return;
			}

			var arr = e.target.closest( '[data-ocb-go]' );

			if ( arr && e.target.closest( '.ocb-look__nav' ) ) {
				prodStep( Number( arr.dataset.ocbGo ) );
			}
		} );

		// The sheet follows the finger down, like the add-to-cart panel.
		if ( side ) {
			var y0 = null;
			var x0 = 0;
			var dy = 0;

			side.addEventListener( 'touchstart', function ( e ) {
				if ( ! e.target.closest( '[data-ocb-look-close]' ) && side.scrollTop > 2 ) {
					return;
				}

				y0 = e.touches[ 0 ].clientY;
				x0 = e.touches[ 0 ].clientX;
				dy = 0;
			}, { passive: true } );

			side.addEventListener( 'touchmove', function ( e ) {
				if ( null === y0 ) {
					return;
				}

				dy = e.touches[ 0 ].clientY - y0;

				// A sideways swipe belongs to the product strip, not to the
				// pull-down-to-close gesture.
				if ( Math.abs( e.touches[ 0 ].clientX - x0 ) > Math.abs( dy ) ) {
					dy = 0;
					return;
				}

				if ( dy > 0 ) {
					side.style.transform = 'translateY(' + dy + 'px)';
					side.style.transition = 'none';
				}
			}, { passive: true } );

			side.addEventListener( 'touchend', function () {
				if ( null === y0 ) {
					return;
				}

				side.style.transform = '';
				side.style.transition = '';

				if ( dy > 90 ) {
					closeLook();
				}

				y0 = null;
			} );
		}

		// Swiping the strip picks the centred product (and its room's picture).
		if ( cardsWrap ) {
			var st = null;

			cardsWrap.addEventListener( 'scroll', function () {
				if ( ! mobile() || ! look.classList.contains( 'is-open' ) ) {
					return;
				}

				clearTimeout( st );
				st = setTimeout( function () {
					// The strip shows one room's products; the most visible
					// card wins (overlap, so reading direction never matters).
					var wr = cardsWrap.getBoundingClientRect();
					var best = at;
					var bestOv = -1;

					cards.forEach( function ( c, i ) {
						if ( ! c.classList.contains( 'in-scene' ) ) {
							return;
						}

						var r = c.getBoundingClientRect();
						var ov = Math.min( r.right, wr.right ) - Math.max( r.left, wr.left );

						if ( ov > bestOv ) {
							bestOv = ov;
							best = i;
						}
					} );

					if ( best !== at ) {
						show( best );
					}
				}, 80 );
			}, { passive: true } );
		}

		// Closed on a phone the rooms are a swipe slider — the most visible
		// room becomes the current one, and its products stand ready.
		( function () {
			var pic = look.querySelector( '.ocb-look__pic' );

			if ( ! pic || scenes.length < 2 ) {
				return;
			}

			var pt = null;

			pic.addEventListener( 'scroll', function () {
				if ( ! mobile() || look.classList.contains( 'is-open' ) ) {
					return;
				}

				clearTimeout( pt );
				pt = setTimeout( function () {
					var pr = pic.getBoundingClientRect();
					var bestScene = null;
					var bestOv = -1;

					scenes.forEach( function ( sc ) {
						var r = sc.getBoundingClientRect();
						var ov = Math.min( r.right, pr.right ) - Math.max( r.left, pr.left );

						if ( ov > bestOv ) {
							bestOv = ov;
							bestScene = Number( sc.dataset.ocbLscene );
						}
					} );

					if ( null !== bestScene && Number( cards[ at ].dataset.ocbScene || 0 ) !== bestScene ) {
						for ( var i = 0; i < cards.length; i++ ) {
							if ( Number( cards[ i ].dataset.ocbScene || 0 ) === bestScene ) {
								show( i );
								break;
							}
						}
					}
				}, 90 );
			}, { passive: true } );
		}() );

		// A tap outside the sheet folds it.
		document.addEventListener( 'click', function ( e ) {
			if ( look.classList.contains( 'is-open' ) && ! look.contains( e.target ) ) {
				closeLook();
			}
		} );

		// After an add, the product's circle wears a count of how many of it
		// went to the cart (like the story viewer's own badge).
		function bump( productId ) {
			var add = look.querySelector( '.ocb-look__cadd[data-product_id="' + String( productId ) + '"]' );
			var n = add && add.querySelector( '.ocb-look__cadd-n' );

			if ( ! n ) {
				return;
			}

			n.textContent = String( ( parseInt( n.textContent, 10 ) || 0 ) + 1 );
			n.hidden = false;
		}

		// The quick-pick panel announces its adds; Woo's own ajax (simple
		// products) speaks jQuery.
		document.addEventListener( 'oc:added', function ( e ) {
			if ( e.detail && e.detail.productId ) {
				bump( e.detail.productId );
			}
		} );

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'added_to_cart', function ( e2, fragments, hash, $button ) {
				var b = $button && $button[ 0 ];

				if ( b && b.classList.contains( 'ocb-look__cadd' ) ) {
					bump( b.getAttribute( 'data-product_id' ) || '' );

					// The tick has its moment, then the button is itself again.
					b.classList.remove( 'loading' );
					b.classList.add( 'added' );
					setTimeout( function () {
						b.classList.remove( 'added' );
					}, 1600 );
				}
			} );
		}

		// Settle the opening state (the count reads per-room from the start).
		show( 0 );
	} );

	/* ---------- the scrolling story ---------- */

	document.querySelectorAll( '[data-ocb-sc]' ).forEach( function ( sc ) {
		var frames = sc.querySelectorAll( '[data-ocb-frame]' );
		var steps = sc.querySelectorAll( '[data-ocb-step]' );
		var stage = sc.querySelector( '.ocb-sc__stage' );
		var pin = sc.querySelector( '.ocb-sc__pin' );
		var wide = window.matchMedia( '(min-width: 783px)' );
		var still = window.matchMedia( '(prefers-reduced-motion: reduce)' );

		if ( ! stage ) {
			return;
		}

		// Everything on a wide screen is measured from the picture: the
		// pin centres it in the view, each chapter is as tall as it, and
		// the road between chapters is set from it. The height is written
		// on the section as a variable the stylesheet reads — now (the
		// stage has its shape before any picture arrives) and again on
		// every change; a tab drawn in the background delivers no resize
		// callbacks, so the first measure cannot wait for one.
		var fit = function () {
			sc.style.setProperty( '--ocb-sc-stage-h', stage.offsetHeight + 'px' );
		};

		fit();

		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( function () {
				fit();
				ask();
			} ).observe( stage );
		} else {
			window.addEventListener( 'resize', fit );
		}

		if ( frames.length < 2 ) {
			return;
		}

		var chapters = [].map.call( steps, function ( step ) {
			return step.querySelector( '.ocb-sc__chapter' ) || step;
		} );
		var on = -1;
		var ticking = false;

		function light( at ) {
			frames.forEach( function ( fr, i ) {
				fr.classList.toggle( 'is-on', i === at );
			} );
			// On a phone every chapter sits in the same grid cell, so the
			// direction it travels has to come from somewhere: behind the
			// current one leaves upwards, ahead of it waits below.
			sc.querySelectorAll( '[data-ocb-mstep]' ).forEach( function ( mt ) {
				var i = Number( mt.dataset.ocbMstep );

				mt.classList.toggle( 'is-on', i === at );
				mt.classList.toggle( 'is-past', i < at );
			} );
			// The dots fill up to where you have read, so going back down
			// the page empties them again.
			sc.querySelectorAll( '[data-ocb-dot]' ).forEach( function ( dot ) {
				dot.classList.toggle( 'is-filled', Number( dot.dataset.ocbDot ) <= at );
			} );
		}

		// Wide screens: the words are lit by how far the scroll has carried
		// the story from one chapter to the next, read off the scroll
		// position itself. Between two chapters the road is one movement in
		// three beats — the first third the words that were level with the
		// picture hold, then they lift and fade out; at the halfway mark the
		// picture turns; and the next words fade in from below to arrive
		// level with it. No moment with two chapters lit, none with none —
		// whatever the shape and height of the picture, and however long
		// the road the stylesheet lays between chapters.
		var HOLD = 0.32;
		var TURN = 0.5;

		function paint() {
			ticking = false;

			if ( ! wide.matches ) {
				return;
			}

			var box = stage.getBoundingClientRect();
			var centre = box.top + box.height / 2;
			var mids = chapters.map( function ( ch ) {
				var b = ch.getBoundingClientRect();

				return b.top + b.height / 2;
			} );
			var last = mids.length - 1;
			var from = 0;

			// The two chapters the picture stands between (before the first
			// and after the last, it stands with that one alone).
			while ( from < last && mids[ from + 1 ] <= centre ) {
				from++;
			}

			var t = 0;

			if ( centre < mids[ 0 ] ) {
				t = 0;
			} else if ( from >= last ) {
				t = 0;
			} else {
				t = ( centre - mids[ from ] ) / Math.max( 1, mids[ from + 1 ] - mids[ from ] );
			}

			var lit = t < TURN || from >= last ? from : from + 1;
			var lift = still.matches ? 0 : 26;

			chapters.forEach( function ( ch, i ) {
				var op = 0;
				var dy = 0;

				if ( i === from && from < last ) {
					// Going: holds, then fades and lifts away before the turn.
					op = t <= HOLD ? 1 : Math.max( 0, 1 - ( t - HOLD ) / ( TURN - HOLD ) );
					dy = -lift * ( 1 - op );
				} else if ( i === from ) {
					op = 1;
				} else if ( i === from + 1 ) {
					// Coming: rises in after the turn, level by the last third.
					op = t <= TURN ? 0 : Math.min( 1, ( t - TURN ) / ( 1 - TURN - HOLD ) );
					dy = lift * ( 1 - op );
				}

				ch.style.opacity = op.toFixed( 3 );
				ch.style.translate = lift ? '0 ' + dy.toFixed( 1 ) + 'px' : '';
			} );

			if ( lit !== on ) {
				on = lit;
				light( lit );
			}
		}

		function ask() {
			// A hidden document gets no animation frames: paint at once, so a
			// page scrolled by a script in the background is right when shown.
			if ( document.hidden ) {
				paint();
				return;
			}

			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( paint );
			}
		}

		window.addEventListener( 'scroll', ask, { passive: true } );
		window.addEventListener( 'resize', ask );
		paint();

		// Phones: the chapters scroll invisibly under the pinned stage, so a
		// chapter takes the stage when it crosses the middle of the view.
		var mid = new IntersectionObserver( function ( entries ) {
			if ( wide.matches ) {
				return;
			}

			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					on = Number( entry.target.dataset.ocbStep );
					light( on );
				}
			} );
		}, { rootMargin: '-46% 0px -46% 0px', threshold: 0 } );

		steps.forEach( function ( step ) {
			mid.observe( step );
		} );

		// Pressing a dot walks to that chapter: the page scrolls until the
		// chapter's words are level with the pinned picture.
		sc.querySelectorAll( '[data-ocb-dot]' ).forEach( function ( dot ) {
			dot.addEventListener( 'click', function () {
				var ch = chapters[ Number( dot.dataset.ocbDot ) ];

				if ( ! ch ) {
					return;
				}

				var b = ch.getBoundingClientRect();
				var top;

				if ( wide.matches ) {
					var pinTop = parseFloat( window.getComputedStyle( pin ).top );

					if ( isNaN( pinTop ) ) {
						pinTop = ( window.innerHeight - stage.offsetHeight ) / 2;
					}

					top = b.top + window.scrollY + b.height / 2 - ( pinTop + stage.offsetHeight / 2 );
				} else {
					top = b.top + window.scrollY - ( window.innerHeight - b.height ) / 2;
				}

				window.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
			} );
		} );
	} );

	/* ---------- icon columns: the phone shows one at a time ---------- */

	document.querySelectorAll( '.ocb-ico--mslider' ).forEach( function ( ico ) {
		if ( ! window.matchMedia( '(max-width: 782px)' ).matches ) {
			return;
		}

		var items = [].slice.call( ico.querySelectorAll( '.ocb-ico__one' ) );

		if ( items.length < 2 ) {
			if ( items[ 0 ] ) {
				items[ 0 ].classList.add( 'is-live' );
			}

			return;
		}

		var dots = document.createElement( 'div' );
		var at = 0;
		var timer = null;

		dots.className = 'ocb-ico__dots';

		items.forEach( function ( one, i ) {
			var d = document.createElement( 'button' );

			d.type = 'button';
			// A dot is a button with nothing written on it — it says which item it turns to.
			d.setAttribute( 'aria-label', ( ico.getAttribute( 'data-ocb-dot-label' ) || 'Item %d' ).replace( '%d', i + 1 ) );
			d.addEventListener( 'click', function () {
				show( i );
				arm();
			} );
			dots.appendChild( d );
		} );

		var held = false;

		// The row turns by itself on a phone, so it can be stopped.
		if ( ! reduced ) {
			dots.appendChild( window.ocPause.button( 'motion', 'ocpp--inline', function ( paused ) {
				held = paused;
				arm();
			} ) );
		}

		ico.appendChild( dots );

		function show( n ) {
			var old = items[ at ];

			at = ( n + items.length ) % items.length;

			var next = items[ at ];

			if ( old !== next ) {
				// First the standing one leaves, all the way; only then
				// does the next rise in.
				old.classList.remove( 'is-live' );
				old.classList.add( 'is-out' );
				setTimeout( function () {
					old.classList.remove( 'is-out' );
				}, 720 );
				setTimeout( function () {
					if ( items[ at ] === next ) {
						next.classList.add( 'is-live' );
					}
				}, 600 );
			} else {
				next.classList.add( 'is-live' );
			}

			[].forEach.call( dots.children, function ( d, i ) {
				d.classList.toggle( 'is-on', i === at );
				if ( i === at ) { d.setAttribute( 'aria-current', 'true' ); } else { d.removeAttribute( 'aria-current' ); }
			} );
		}

		function arm() {
			clearInterval( timer );

			if ( ! reduced && ! held ) {
				timer = setInterval( function () {
					show( at + 1 );
				}, 4400 );
			}
		}

		// A finger walks it too.
		var x0 = null;

		ico.addEventListener( 'touchstart', function ( e ) {
			x0 = e.touches[ 0 ].clientX;
		}, { passive: true } );

		ico.addEventListener( 'touchend', function ( e ) {
			if ( null === x0 ) {
				return;
			}

			var dx = e.changedTouches[ 0 ].clientX - x0;

			x0 = null;

			if ( Math.abs( dx ) > 40 ) {
				show( at + ( dx < 0 ? 1 : -1 ) );
				arm();
			}
		} );

		show( 0 );
		arm();
	} );

	/* ---------- questions & answers ---------- */

	document.querySelectorAll( '[data-ocb-faq]' ).forEach( function ( faq ) {
		faq.addEventListener( 'click', function ( e ) {
			var q = e.target.closest( '.ocb-faq__q' );

			if ( ! q ) {
				return;
			}

			// One at a time: opening a question folds whichever was open.
			var item = q.parentElement;
			var wasOpen = item.classList.contains( 'is-open' );

			faq.querySelectorAll( '.ocb-faq__item.is-open' ).forEach( function ( other ) {
				other.classList.remove( 'is-open' );
				other.querySelector( '.ocb-faq__q' ).setAttribute( 'aria-expanded', 'false' );
			} );

			if ( ! wasOpen ) {
				item.classList.add( 'is-open' );
				q.setAttribute( 'aria-expanded', 'true' );
			}
		} );
	} );

	/* ---------- countdown ---------- */

	document.querySelectorAll( '[data-ocb-cd]' ).forEach( function ( cd ) {
		var end = Number( cd.dataset.ocbCd ) * 1000;
		var cells = {};

		cd.querySelectorAll( '[data-ocb-cd-u]' ).forEach( function ( n ) {
			cells[ n.dataset.ocbCdU ] = n;
		} );

		var timer = 0;

		var tick = function () {
			var left = end - Date.now();

			if ( left <= 0 ) {
				clearInterval( timer );

				var done = cd.dataset.ocbCdDone || '';
				var out = cd.querySelector( '.ocb-cd__done' );

				if ( cd.hasAttribute( 'data-ocb-cd-in' ) ) {
					// A clock riding a banner just steps off it.
					cd.hidden = true;
				} else if ( done && out ) {
					cd.querySelector( '.ocb-cd__cells' ).hidden = true;
					out.textContent = done;
					out.hidden = false;
				} else {
					// Nothing to say: the whole section stands down.
					var section = cd.closest( '.ocb' );

					if ( section ) {
						section.style.display = 'none';
					}
				}

				return;
			}

			var s = Math.floor( left / 1000 );

			var show = {
				d: Math.floor( s / 86400 ),
				h: Math.floor( ( s % 86400 ) / 3600 ),
				m: Math.floor( ( s % 3600 ) / 60 ),
				s: s % 60
			};

			Object.keys( show ).forEach( function ( unit ) {
				if ( cells[ unit ] ) {
					cells[ unit ].textContent = String( show[ unit ] ).padStart( 2, '0' );
				}
			} );
		};

		tick();
		timer = setInterval( tick, 1000 );
	} );

	/* ---------- branches: the map follows the chosen card ---------- */

	document.querySelectorAll( '[data-ocb-br]' ).forEach( function ( br ) {
		var frame = br.querySelector( '.ocb-br__map iframe' );
		var cards = [].slice.call( br.querySelectorAll( '[data-ocb-br-addr]' ) );

		if ( frame ) {
			cards.forEach( function ( card ) {
				card.addEventListener( 'click', function ( e ) {
					// A link inside the card keeps being a link.
					if ( e.target.closest( 'a' ) ) {
						return;
					}

					var addr = card.dataset.ocbBrAddr;

					if ( ! addr || card.classList.contains( 'is-on' ) ) {
						return;
					}

					cards.forEach( function ( on ) {
						on.classList.remove( 'is-on' );
					} );
					card.classList.add( 'is-on' );

					var src = new URL( frame.src );
					src.searchParams.set( 'q', addr );
					frame.src = src.toString();
				} );
			} );
		}

		// The search box hides whichever cards do not carry the words.
		var seek = ( br.parentElement || br ).parentElement.querySelector( '[data-ocb-br-q]' ) || document.querySelector( '[data-ocb-br-q]' );

		if ( seek ) {
			var none = br.querySelector( '.ocb-br__none' );

			seek.addEventListener( 'input', function () {
				var needle = seek.value.trim();
				var first = null;

				cards.forEach( function ( card ) {
					var hit = '' === needle || card.textContent.indexOf( needle ) > -1;

					card.hidden = ! hit;

					if ( hit && ! first ) {
						first = card;
					}
				} );

				var lost = '' !== needle && ! first;

				// Nobody matched: say so kindly and keep the whole list up —
				// an empty screen answers nothing.
				if ( lost ) {
					cards.forEach( function ( card ) {
						card.hidden = false;
					} );
					first = cards[ 0 ];
				}

				if ( none ) {
					none.hidden = ! lost;
				}

				// The map walks to the first match.
				if ( first && frame && first.dataset.ocbBrAddr && ! first.classList.contains( 'is-on' ) ) {
					cards.forEach( function ( on ) {
						on.classList.remove( 'is-on' );
					} );
					first.classList.add( 'is-on' );

					var src = new URL( frame.src );
					src.searchParams.set( 'q', first.dataset.ocbBrAddr );
					frame.src = src.toString();
				}
			} );
		}
	} );

	/* ---------- newsletter ---------- */

	document.querySelectorAll( '[data-ocb-news]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var go = form.querySelector( '.ocb-news__go' );

			go.disabled = true;

			// getAttribute, not .action: the hidden "action" field shadows the
			// form's own property with itself.
			fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ) } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( data && data.success ) {
						form.classList.add( 'is-done' );
						form.querySelector( '.ocb-news__thanks' ).hidden = false;
						document.dispatchEvent( new CustomEvent( 'oc:newsletter', { detail: { eventId: data.data && data.data.event_id } } ) );
					} else {
						go.disabled = false;

						if ( window.ocGuard ) {
							window.ocGuard.reset( form );
						}

						var mail = form.querySelector( '.ocb-news__mail' );
						mail.setCustomValidity( ( data && data.data && data.data.msg ) || '…' );
						mail.reportValidity();
						mail.addEventListener( 'input', function () {
							mail.setCustomValidity( '' );
						}, { once: true } );
					}
				} )
				.catch( function () {
					go.disabled = false;
				} );
		} );
	} );

	/* ---------- contact / leads form ---------- */

	document.querySelectorAll( '[data-ocb-lead]' ).forEach( function ( form ) {
		var msgs = {
			req: form.dataset.errReq || '',
			phone: form.dataset.errPhone || '',
			email: form.dataset.errEmail || '',
			consent: form.dataset.errConsent || ''
		};

		function slotOf( field ) {
			var wrap = field.closest( 'label' );

			return wrap ? wrap.querySelector( '.ocb-lead__err' ) : null;
		}

		function faultOf( field ) {
			var value = ( field.value || '' ).trim();

			if ( 'checkbox' === field.type ) {
				return field.checked ? '' : msgs.consent;
			}

			if ( field.required && '' === value ) {
				return msgs.req;
			}

			if ( 'phone' === field.dataset.kind && '' !== value ) {
				var digits = value.replace( /\D/g, '' );

				if ( 10 !== digits.length ) {
					return msgs.phone;
				}
			}

			if ( 'email' === field.dataset.kind && '' !== value && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value ) ) {
				return msgs.email;
			}

			return '';
		}

		function judge( field ) {
			var slot = slotOf( field );
			var fault = faultOf( field );

			if ( slot ) {
				slot.textContent = fault;
				slot.hidden = '' === fault;
			}

			field.closest( 'label' ).classList.toggle( 'is-bad', '' !== fault );

			return '' === fault;
		}

		var watched = form.querySelectorAll( '.ocb-lead__f input, .ocb-lead__f textarea, .ocb-lead__f select, .ocb-lead__consent input' );

		watched.forEach( function ( field ) {
			field.addEventListener( 'blur', function () {
				judge( field );
			} );
			field.addEventListener( 'input', function () {
				if ( field.closest( 'label' ).classList.contains( 'is-bad' ) ) {
					judge( field );
				}
			} );
			field.addEventListener( 'change', function () {
				judge( field );
			} );
		} );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var first = null;

			watched.forEach( function ( field ) {
				if ( ! judge( field ) && ! first ) {
					first = field;
				}
			} );

			if ( first ) {
				first.focus();
				return;
			}

			var go = form.querySelector( '.ocb-lead__go' );
			var thanks = form.querySelector( '.ocb-lead__thanks' );

			go.disabled = true;

			// getAttribute, not .action: the hidden "action" field shadows
			// the form's own property with itself.
			fetch( form.getAttribute( 'action' ), { method: 'POST', body: new FormData( form ) } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					go.disabled = false;

					var fail = form.querySelector( '.ocb-lead__fail' );

					if ( fail ) {
						fail.hidden = true;
					}

					if ( ! ( data && data.success ) ) {
						// A refusal used the challenge token up; the
						// server's sentence, when it sent one.
						if ( window.ocGuard ) {
							window.ocGuard.reset( form );
						}
						if ( fail && data && data.data && data.data.msg ) {
							fail.textContent = data.data.msg;
							fail.hidden = false;
						}
					}

					if ( data && data.success ) {
						// The form stays, empty and ready; the word of
						// thanks stands under the button.
						form.reset();
						form.querySelectorAll( '.is-bad' ).forEach( function ( bad ) {
							bad.classList.remove( 'is-bad' );
						} );
						form.querySelectorAll( '.ocb-lead__err' ).forEach( function ( slot ) {
							slot.hidden = true;
						} );
						thanks.hidden = false;
					}
				} )
				.catch( function () {
					go.disabled = false;
				} );
		} );
	} );

	/* ---------- parallax: the picture drifts slower than the page ---------- */

	var lax = document.querySelectorAll( '[data-ocb-parallax]' );

	if ( lax.length && ! reduced ) {
		var laxDraw = function () {
		lax.forEach( function ( media ) {
				// The phone's full-strength layer is CSS-fixed; JS keeps
				// its hands off it.
				if ( 'fixed' === getComputedStyle( media ).position ) {
					return;
				}

				var box = media.parentElement.getBoundingClientRect();

				if ( box.height < 4 || box.bottom < 0 || box.top > window.innerHeight ) {
					return;
				}

				// Strength is how much of the page's motion the picture
				// refuses: at 100 it is glued to the viewport — a true
				// fixed-background feel — and in between it lags the page
				// proportionally. Instead of zooming for headroom, the
				// picture is given extra height that blends toward the
				// viewport's own, which covers every position exactly.
				var f = ( parseInt( media.dataset.ocbParallax, 10 ) || 30 ) / 100;
				var H = Math.round( box.height + f * ( window.innerHeight - box.height ) );
				var y = ( -f * box.top ).toFixed( 1 );
				var moving = media.firstElementChild || media;

				if ( moving.__ocbH !== H ) {
					moving.__ocbH = H;
					moving.style.blockSize = H + 'px';
				}

				if ( moving.__ocbY !== y ) {
					moving.__ocbY = y;
					moving.style.transform = 'translate3d(0,' + y + 'px,0)';
				}
			} );
		};

		// Drawn straight from the scroll event, not via requestAnimationFrame:
		// browsers starve rAF inside transform-scaled iframes — exactly where
		// the composer preview lives — and the picture froze there.
		window.addEventListener( 'scroll', laxDraw, { passive: true } );
		window.addEventListener( 'resize', laxDraw, { passive: true } );

		laxDraw();
		setTimeout( laxDraw, 400 );
		setTimeout( laxDraw, 1200 );
	}
}() );

/* ---------- natural height for a banner whose shape the server could not know ----------
 * Height 0 makes the banner take its media's proportions. The server writes
 * them for anything in the library; a film hosted elsewhere, or a picture
 * with no recorded size, arrives without a ratio and would fall back to
 * 16:9 — and be cropped after all. The media itself knows its size the
 * moment its metadata loads, so it is read from there and written back. */
( function () {
	document.querySelectorAll( '.ocb-hero--hauto, .ocb-hero--hmauto' ).forEach( function ( hero ) {
		var have  = hero.style.getPropertyValue( '--ocb-hero-ratio' ).trim();
		var haveM = hero.style.getPropertyValue( '--ocb-hero-ratio-m' ).trim();

		if ( have && haveM ) {
			return;
		}

		var media = hero.querySelector( '.ocb-hero__slide .ocb-hero__media video, .ocb-hero__slide .ocb-hero__media img' );

		if ( ! media ) {
			return;
		}

		function write( w, h ) {
			if ( ! ( w > 0 && h > 0 ) ) {
				return;
			}

			var ratio = w + ' / ' + h;

			if ( ! have ) {
				hero.style.setProperty( '--ocb-hero-ratio', ratio );
			}

			if ( ! haveM ) {
				hero.style.setProperty( '--ocb-hero-ratio-m', ratio );
			}
		}

		if ( 'VIDEO' === media.tagName ) {
			if ( media.readyState >= 1 ) {
				write( media.videoWidth, media.videoHeight );
			} else {
				media.addEventListener( 'loadedmetadata', function () {
					write( media.videoWidth, media.videoHeight );
				}, { once: true } );
			}
		} else if ( media.complete && media.naturalWidth ) {
			write( media.naturalWidth, media.naturalHeight );
		} else {
			media.addEventListener( 'load', function () {
				write( media.naturalWidth, media.naturalHeight );
			}, { once: true } );
		}
	} );
	/* ---------- every muted loop gets its pause button ---------- */

	window.ocPause.scan( document );
	window.ocPause.watch();
}() );
