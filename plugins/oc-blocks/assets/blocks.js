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

	/* ---------- entrances: sections step in as they arrive ---------- */

	var seen = new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( entry.isIntersecting ) {
				entry.target.classList.add( 'is-in' );
				seen.unobserve( entry.target );
			}
		} );
	}, { rootMargin: '0px 0px -8% 0px' } );

	document.querySelectorAll( '.ocb--in-fade, .ocb--in-rise, .ocb--in-stagger' ).forEach( function ( el ) {
		if ( reduced ) {
			el.classList.add( 'is-in' );
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

			if ( dots.children.length !== count() ) {
				dots.innerHTML = '';

				for ( var i = 0; i < count(); i++ ) {
					( function ( n ) {
						var d = document.createElement( 'button' );
						d.type = 'button';
						d.className = 'ocb-dots__dot';
						d.addEventListener( 'click', function () {
							go( n );
						} );
						dots.appendChild( d );
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

		function arm() {
			var secs = Number( hero.dataset.ocbAuto || 0 );

			if ( ! secs || reduced ) {
				return;
			}

			clearInterval( timer );
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

		function step() {
			var item = row.querySelector( ':scope > *' );

			return item ? item.getBoundingClientRect().width + 24 : row.clientWidth;
		}

		shelf.addEventListener( 'click', function ( e ) {
			var arr = e.target.closest( '[data-ocb-go]' );

			if ( arr ) {
				row.scrollBy( { left: step() * 2 * Number( arr.dataset.ocbGo ), behavior: 'smooth' } );
			}
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

			var top = img.getBoundingClientRect().top - shelf.getBoundingClientRect().top + img.getBoundingClientRect().height / 2;

			if ( top > 20 ) {
				shelf.style.setProperty( '--ocb-arr-mid', top.toFixed( 0 ) + 'px' );
			}
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

	document.querySelectorAll( '[data-oc-shelf]' ).forEach( function ( box ) {
		var cfg;

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
	} );

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
		}
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

		if ( frames.length < 2 ) {
			return;
		}

		// A chapter takes the stage when it crosses the middle of the view.
		var mid = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}

				var at = Number( entry.target.dataset.ocbStep );

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
			} );
		}, { rootMargin: '-46% 0px -46% 0px', threshold: 0 } );

		steps.forEach( function ( step ) {
			mid.observe( step );
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
			d.addEventListener( 'click', function () {
				show( i );
				arm();
			} );
			dots.appendChild( d );
		} );

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
			} );
		}

		function arm() {
			clearInterval( timer );

			if ( ! reduced ) {
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
					} else {
						go.disabled = false;

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

			if ( 'phone' === field.name && '' !== value ) {
				var digits = value.replace( /\D/g, '' );

				if ( 10 !== digits.length ) {
					return msgs.phone;
				}
			}

			if ( 'email' === field.name && '' !== value && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value ) ) {
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

		var watched = form.querySelectorAll( 'input[name="name"], input[name="phone"], input[name="email"], input[name="consent"], textarea[name="msg"]' );

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
